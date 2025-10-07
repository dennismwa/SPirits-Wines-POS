<?php
/**
 * Enhanced Complete Sale API with Security and Features
 * api/complete-sale.php
 */

require_once '../config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiRespond(false, 'Invalid request method', null, 405);
}

// Validate input
if (!isset($_POST['items']) || empty($_POST['items'])) {
    apiRespond(false, 'No items in cart', null, 400);
}

try {
    $items = json_decode($_POST['items'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid items data format');
    }
    
    $paymentMethod = sanitize($_POST['payment_method']);
    $mpesaReference = isset($_POST['mpesa_reference']) ? sanitize($_POST['mpesa_reference']) : null;
    $subtotal = floatval($_POST['subtotal']);
    $taxAmount = floatval($_POST['tax_amount']);
    $totalAmount = floatval($_POST['total_amount']);
    $amountPaid = floatval($_POST['amount_paid']);
    $changeAmount = floatval($_POST['change_amount']);
    $customerId = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
    $discountAmount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
    $discountType = isset($_POST['discount_type']) ? sanitize($_POST['discount_type']) : null;
    $notes = isset($_POST['notes']) ? sanitize($_POST['notes']) : null;
    
    // Validate payment methods
    $validPaymentMethods = ['cash', 'mpesa', 'mpesa_till', 'card'];
    if (!in_array($paymentMethod, $validPaymentMethods)) {
        throw new Exception('Invalid payment method');
    }
    
    // Validate M-Pesa reference if needed
    if (($paymentMethod === 'mpesa' || $paymentMethod === 'mpesa_till') && empty($mpesaReference)) {
        throw new Exception('M-Pesa reference is required');
    }
    
    // Validate payment
    if ($amountPaid < $totalAmount) {
        throw new Exception('Insufficient payment amount');
    }
    
    // Validate items
    if (empty($items) || !is_array($items)) {
        throw new Exception('Invalid items data');
    }
    
    // Additional validation
    $calculatedSubtotal = 0;
    foreach ($items as $item) {
        if (!isset($item['id'], $item['quantity'], $item['price'])) {
            throw new Exception('Invalid item data structure');
        }
        $calculatedSubtotal += $item['quantity'] * $item['price'];
    }
    
    // Verify totals match (with small tolerance for floating point)
    if (abs($calculatedSubtotal - $subtotal) > 0.01) {
        throw new Exception('Subtotal mismatch detected');
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Generate sale number
        $saleNumber = generateSaleNumber();
        $userId = $_SESSION['user_id'];
        $saleDate = getCurrentDateTime();
        
        // Insert sale using prepared statement
        $stmt = $conn->prepare("INSERT INTO sales 
            (sale_number, user_id, customer_id, subtotal, tax_amount, discount_amount, 
             total_amount, payment_method, mpesa_reference, amount_paid, change_amount, 
             sale_date, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("siidddsssddss", 
            $saleNumber, $userId, $customerId, $subtotal, $taxAmount, $discountAmount,
            $totalAmount, $paymentMethod, $mpesaReference, $amountPaid, $changeAmount,
            $saleDate, $notes
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create sale: ' . $stmt->error);
        }
        
        $saleId = $conn->insert_id;
        $stmt->close();
        
        // Prepare statements for items
        $stmtItem = $conn->prepare("INSERT INTO sale_items 
            (sale_id, product_id, product_name, quantity, unit_price, discount, subtotal) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $stmtStock = $conn->prepare("UPDATE products 
            SET stock_quantity = stock_quantity - ? 
            WHERE id = ? AND stock_quantity >= ?");
        
        $stmtMovement = $conn->prepare("INSERT INTO stock_movements 
            (product_id, user_id, movement_type, quantity, reference_type, reference_id, notes) 
            VALUES (?, ?, 'sale', ?, 'sale', ?, ?)");
        
        $insufficientStock = [];
        
        foreach ($items as $item) {
            $productId = intval($item['id']);
            $quantity = intval($item['quantity']);
            $unitPrice = floatval($item['price']);
            $itemDiscount = isset($item['discount']) ? floatval($item['discount']) : 0;
            $itemSubtotal = ($quantity * $unitPrice) - $itemDiscount;
            $productName = sanitize($item['name']);
            
            // Check stock availability using prepared statement
            $checkStmt = $conn->prepare("SELECT stock_quantity, name FROM products WHERE id = ?");
            $checkStmt->bind_param("i", $productId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows === 0) {
                $checkStmt->close();
                throw new Exception("Product not found: $productName");
            }
            
            $product = $checkResult->fetch_assoc();
            $currentStock = $product['stock_quantity'];
            $checkStmt->close();
            
            if ($currentStock < $quantity) {
                $insufficientStock[] = [
                    'product' => $productName,
                    'available' => $currentStock,
                    'requested' => $quantity
                ];
                continue; // Don't throw yet, collect all issues
            }
            
            // Insert sale item
            $stmtItem->bind_param("iisidd", $saleId, $productId, $productName, 
                                  $quantity, $unitPrice, $itemDiscount, $itemSubtotal);
            
            if (!$stmtItem->execute()) {
                throw new Exception('Failed to add sale item: ' . $stmtItem->error);
            }
            
            // Update product stock
            $stmtStock->bind_param("iii", $quantity, $productId, $quantity);
            
            if (!$stmtStock->execute() || $stmtStock->affected_rows === 0) {
                throw new Exception("Failed to update stock for: $productName");
            }
            
            // Record stock movement
            $movementNotes = "Sale: $saleNumber";
            $stmtMovement->bind_param("iiiis", $productId, $userId, $quantity, $saleId, $movementNotes);
            $stmtMovement->execute();
        }
        
        // If we had insufficient stock, report it
        if (!empty($insufficientStock)) {
            $errorMsg = "Insufficient stock:\n";
            foreach ($insufficientStock as $item) {
                $errorMsg .= "- {$item['product']}: Available {$item['available']}, Requested {$item['requested']}\n";
            }
            throw new Exception($errorMsg);
        }
        
        $stmtItem->close();
        $stmtStock->close();
        $stmtMovement->close();
        
        // Update customer loyalty points if customer is specified
        if ($customerId) {
            $loyaltyPoints = floor($totalAmount / 100); // 1 point per 100 currency units
            $updateCustomer = $conn->prepare("UPDATE customers 
                SET loyalty_points = loyalty_points + ?,
                    total_purchases = total_purchases + ?,
                    last_purchase = ?
                WHERE id = ?");
            $updateCustomer->bind_param("idsi", $loyaltyPoints, $totalAmount, $saleDate, $customerId);
            $updateCustomer->execute();
            $updateCustomer->close();
        }
        
        // Log activity with metadata
        $activityMetadata = [
            'sale_number' => $saleNumber,
            'total_amount' => $totalAmount,
            'payment_method' => $paymentMethod,
            'item_count' => count($items)
        ];
        logActivity('SALE_COMPLETED', "Completed sale $saleNumber", $activityMetadata);
        
        // Check for low stock alerts
        $lowStockProducts = [];
        $checkLowStock = $conn->prepare("SELECT id, name, stock_quantity, reorder_level 
                                         FROM products 
                                         WHERE stock_quantity <= reorder_level 
                                         AND status = 'active'");
        $checkLowStock->execute();
        $lowStockResult = $checkLowStock->get_result();
        
        while ($row = $lowStockResult->fetch_assoc()) {
            $lowStockProducts[] = $row;
        }
        $checkLowStock->close();
        
        // Commit transaction
        $conn->commit();
        
        // Prepare response data
        $responseData = [
            'sale_id' => $saleId,
            'sale_number' => $saleNumber,
            'total' => $totalAmount,
            'change' => $changeAmount,
            'sale_date' => $saleDate,
            'items_count' => count($items)
        ];
        
        // Include low stock warning if any
        if (!empty($lowStockProducts)) {
            $responseData['low_stock_alert'] = [
                'count' => count($lowStockProducts),
                'products' => array_map(function($p) {
                    return [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'current_stock' => $p['stock_quantity'],
                        'reorder_level' => $p['reorder_level']
                    ];
                }, $lowStockProducts)
            ];
        }
        
        // Send notifications if enabled
        $settings = getSettings();
        if (isset($settings['email_notifications_enabled']) && $settings['email_notifications_enabled']) {
            // Queue email notification
            // sendEmailNotification(...);
        }
        
        apiRespond(true, 'Sale completed successfully', $responseData, 201);
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Sale error: " . $e->getMessage());
    apiRespond(false, $e->getMessage(), null, 400);
}