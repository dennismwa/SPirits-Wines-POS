<?php
require_once 'config.php';
requireAuth();

$page_title = 'Point of Sale';
$settings = getSettings();
$isOwner = $_SESSION['role'] === 'owner';

// Get categories ordered by total sales (best selling first)
$categories = $conn->query("SELECT c.*, 
                            COALESCE(SUM(si.quantity), 0) as total_sold,
                            COUNT(DISTINCT s.id) as sales_count
                            FROM categories c
                            LEFT JOIN products p ON c.id = p.category_id
                            LEFT JOIN sale_items si ON p.id = si.product_id
                            LEFT JOIN sales s ON si.sale_id = s.id
                            WHERE c.status = 'active'
                            GROUP BY c.id
                            ORDER BY total_sold DESC, sales_count DESC, c.name ASC");

// Get products ordered by sales within each category
$products = $conn->query("SELECT p.*, 
                         c.name as category_name,
                         COALESCE(SUM(si.quantity), 0) as total_sold
                         FROM products p 
                         LEFT JOIN categories c ON p.category_id = c.id
                         LEFT JOIN sale_items si ON p.id = si.product_id
                         WHERE p.status = 'active' AND p.stock_quantity > 0
                         GROUP BY p.id
                         ORDER BY total_sold DESC, p.name ASC");

// Check if seller should default to fullscreen
$defaultFullscreen = !$isOwner;

include 'header.php';
?>

<style>
/* ==================== MODERN POS DESIGN ==================== */

* {
    box-sizing: border-box;
}

/* Base POS Container */
.pos-container {
    display: grid;
    gap: 0;
    height: calc(100vh - 140px);
    background: #f8fafc;
}

/* Desktop Layout */
@media (min-width: 1024px) {
    .pos-container {
        grid-template-columns: 1fr 420px;
    }
}

/* Tablet/Mobile Layout */
@media (max-width: 1023px) {
    .pos-container {
        grid-template-rows: 1fr auto;
        height: calc(100vh - 80px);
    }
}

/* Fullscreen Mode */
.pos-fullscreen {
    position: fixed;
    inset: 0;
    z-index: 100;
    background: white;
}

.pos-fullscreen .pos-container {
    height: 100vh;
}

/* ==================== PRODUCTS SECTION ==================== */
.products-section {
    display: flex;
    flex-direction: column;
    background: #f8fafc;
    overflow: hidden;
}

/* Search Bar */
.search-bar {
    padding: 1rem;
    background: linear-gradient(135deg, <?php echo $settings['primary_color']; ?> 0%, <?php echo $settings['primary_color']; ?>dd 100%);
    flex-shrink: 0;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

/* Category Tabs */
.category-tabs {
    display: flex;
    gap: 0.5rem;
    overflow-x: auto;
    padding: 0.75rem 1rem;
    background: white;
    border-bottom: 2px solid #e2e8f0;
    flex-shrink: 0;
    -webkit-overflow-scrolling: touch;
}

.category-tabs::-webkit-scrollbar {
    height: 4px;
}

.category-tabs::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.category-tabs::-webkit-scrollbar-thumb {
    background: <?php echo $settings['primary_color']; ?>;
    border-radius: 3px;
}

.category-tab {
    padding: 0.625rem 1rem;
    border-radius: 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.2s;
    background: #f1f5f9;
    border: 2px solid transparent;
    color: #475569;
    flex-shrink: 0;
}

.category-tab.active {
    background: <?php echo $settings['primary_color']; ?>;
    color: white;
    box-shadow: 0 4px 6px -1px rgba(234, 88, 12, 0.3);
}

.category-tab:hover:not(.active) {
    background: #e2e8f0;
    border-color: <?php echo $settings['primary_color']; ?>40;
}

/* Products Grid */
.products-grid {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 0.875rem;
    align-content: start;
    -webkit-overflow-scrolling: touch;
}

@media (min-width: 1024px) {
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 1rem;
        padding: 1.25rem;
    }
}

.products-grid::-webkit-scrollbar {
    width: 6px;
}

.products-grid::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.products-grid::-webkit-scrollbar-thumb {
    background: <?php echo $settings['primary_color']; ?>;
    border-radius: 4px;
}

/* Product Card */
.product-card {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 1rem;
    padding: 0.75rem;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
}

.product-card:hover {
    border-color: <?php echo $settings['primary_color']; ?>;
    box-shadow: 0 10px 15px -3px rgba(234, 88, 12, 0.2);
    transform: translateY(-2px);
}

.product-card:active {
    transform: translateY(0);
}

.product-icon {
    width: 3rem;
    height: 3rem;
    background: linear-gradient(135deg, <?php echo $settings['primary_color']; ?> 0%, <?php echo $settings['primary_color']; ?>dd 100%);
    border-radius: 0.875rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.625rem;
    box-shadow: 0 4px 6px -1px rgba(234, 88, 12, 0.3);
}

.product-icon i {
    font-size: 1.25rem;
    color: white;
}

.product-name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.3;
    margin-bottom: 0.5rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.2em;
}

.product-price {
    font-size: 1.125rem;
    font-weight: 700;
    color: <?php echo $settings['primary_color']; ?>;
    margin-bottom: 0.375rem;
}

.product-stock {
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 0.25rem 0.625rem;
    border-radius: 0.5rem;
}

/* ==================== CART SECTION ==================== */
.cart-section {
    display: flex;
    flex-direction: column;
    background: white;
    border-left: 2px solid #e2e8f0;
    box-shadow: -4px 0 6px -1px rgba(0, 0, 0, 0.1);
    max-height: 100%;
    overflow: hidden;
}

@media (max-width: 1023px) {
    .cart-section {
        border-left: none;
        border-top: 3px solid <?php echo $settings['primary_color']; ?>;
        max-height: 55vh;
        box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
    }
}

.cart-header {
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, <?php echo $settings['primary_color']; ?> 0%, <?php echo $settings['primary_color']; ?>dd 100%);
    color: white;
    flex-shrink: 0;
}

.cart-items {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    background: #f8fafc;
    min-height: 0;
    -webkit-overflow-scrolling: touch;
}

.cart-items::-webkit-scrollbar {
    width: 6px;
}

.cart-items::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.cart-items::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.cart-item {
    background: white;
    border-radius: 0.875rem;
    padding: 0.875rem;
    margin-bottom: 0.75rem;
    border: 2px solid #e2e8f0;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
    animation: slideInCart 0.3s ease-out;
}

@keyframes slideInCart {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.cart-item-header {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.cart-item-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.625rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.cart-item-info {
    flex: 1;
    min-width: 0;
}

.cart-item-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.25rem;
    line-height: 1.3;
}

.cart-item-price {
    font-size: 0.75rem;
    color: #64748b;
}

.cart-item-remove {
    flex-shrink: 0;
    color: #ef4444;
    cursor: pointer;
    transition: all 0.2s;
    padding: 0.25rem;
}

.cart-item-remove:hover {
    color: #dc2626;
    transform: scale(1.1);
}

.cart-item-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
    border-radius: 0.625rem;
    padding: 0.625rem;
}

.cart-item-quantity {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.qty-btn {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.875rem;
}

.qty-btn:active {
    transform: scale(0.95);
}

.qty-value {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    min-width: 2rem;
    text-align: center;
}

.cart-item-total {
    font-size: 1rem;
    font-weight: 700;
    color: <?php echo $settings['primary_color']; ?>;
}

.cart-summary {
    padding: 1rem 1.25rem;
    border-top: 2px solid #e2e8f0;
    background: white;
    flex-shrink: 0;
}

/* Barcode Scanner */
.barcode-scanner {
    padding: 0.875rem 1rem;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
}

/* Payment Modal */
.payment-modal-content {
    max-height: 90vh;
    overflow-y: auto;
}

.payment-method-btn {
    padding: 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    background: white;
}

.payment-method-btn:hover {
    border-color: <?php echo $settings['primary_color']; ?>60;
    background: #fafafa;
}

.payment-method-btn.active {
    border-color: <?php echo $settings['primary_color']; ?>;
    background-color: <?php echo $settings['primary_color']; ?>08;
    box-shadow: 0 4px 6px -1px rgba(234, 88, 12, 0.2);
}

/* Draft Badge */
.draft-badge {
    position: fixed;
    top: 0.75rem;
    right: 0.75rem;
    z-index: 102;
    padding: 0.625rem 1.125rem;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border-radius: 2rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
    font-size: 0.875rem;
}

.draft-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(245, 158, 11, 0.5);
}

/* Touch-friendly */
@media (hover: none) {
    .product-card,
    .category-tab,
    button {
        -webkit-tap-highlight-color: transparent;
    }
}

/* Empty state */
.empty-cart {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    text-align: center;
}

.empty-cart i {
    font-size: 3rem;
    color: #cbd5e1;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}
</style>

<div id="posContainer" class="<?php echo $defaultFullscreen ? 'pos-fullscreen' : ''; ?>">
    <!-- Draft Orders Badge -->
    <div id="draftBadge" class="draft-badge no-print hidden" onclick="showDraftOrders()">
        <i class="fas fa-file-invoice mr-2"></i>
        <span id="draftCount">0</span> Drafts
    </div>

    <div class="pos-container">
        <!-- Products Section -->
        <div class="products-section">
            <!-- Search Bar -->
            <div class="search-bar">
                <div class="flex gap-2 items-center">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-white"></i>
                        <input type="text" id="searchProduct" placeholder="Search products..." 
                               class="w-full pl-10 pr-4 py-2 bg-white/20 text-white placeholder-white/75 rounded-lg focus:ring-2 focus:ring-white/50 focus:bg-white/30 backdrop-blur-sm border-0 outline-none text-sm"
                               autofocus>
                    </div>
                    <button onclick="toggleBarcodeScanner()" id="barcodeScannerBtn" 
                            class="px-3 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg transition backdrop-blur-sm" 
                            title="Barcode Scanner">
                        <i class="fas fa-barcode text-lg"></i>
                    </button>
                    <button onclick="toggleFullscreen()" 
                            class="px-3 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg transition backdrop-blur-sm" 
                            title="Toggle Fullscreen">
                        <i class="fas fa-expand text-lg" id="fullscreenIconTop"></i>
                    </button>
                </div>
            </div>

            <!-- Barcode Scanner -->
            <div id="barcodeScanner" class="hidden barcode-scanner">
                <div class="flex gap-2 items-center">
                    <input type="text" id="barcodeInput" placeholder="Scan or enter barcode..." 
                           class="flex-1 px-3 py-2 border-2 border-white/30 bg-white/20 text-white placeholder-white/75 rounded-lg focus:ring-2 focus:ring-white focus:border-white backdrop-blur-sm text-sm">
                    <button onclick="searchByBarcode()" 
                            class="px-4 py-2 bg-white hover:bg-white/90 text-blue-600 rounded-lg font-semibold transition text-sm">
                        <i class="fas fa-search mr-1"></i>Search
                    </button>
                    <button onclick="toggleBarcodeScanner()" 
                            class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold transition">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Category Tabs -->
            <div class="category-tabs">
                <button class="category-tab active" onclick="filterByCategory('all')" data-category="all">
                    <i class="fas fa-th mr-1"></i>All
                </button>
                <?php while ($cat = $categories->fetch_assoc()): ?>
                <button class="category-tab" onclick="filterByCategory('<?php echo $cat['id']; ?>')" data-category="<?php echo $cat['id']; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                    <?php if ($cat['total_sold'] > 0): ?>
                    <span class="ml-1 px-1.5 py-0.5 bg-white/20 rounded text-xs"><?php echo $cat['total_sold']; ?></span>
                    <?php endif; ?>
                </button>
                <?php endwhile; ?>
            </div>

            <!-- Products Grid -->
            <div class="products-grid" id="productsGrid">
                <?php while ($product = $products->fetch_assoc()): 
                    $stockStatus = getStockStatus($product['stock_quantity'], $product['reorder_level']);
                ?>
                <div class="product-card" 
                     data-id="<?php echo $product['id']; ?>"
                     data-category="<?php echo $product['category_id']; ?>"
                     data-name="<?php echo strtolower($product['name']); ?>"
                     data-barcode="<?php echo strtolower($product['barcode']); ?>"
                     data-sku="<?php echo strtolower($product['sku']); ?>"
                     data-price="<?php echo $product['selling_price']; ?>"
                     data-stock="<?php echo $product['stock_quantity']; ?>"
                     onclick='addToCart(<?php echo json_encode([
                         "id" => $product["id"],
                         "name" => $product["name"],
                         "selling_price" => $product["selling_price"],
                         "stock_quantity" => $product["stock_quantity"]
                     ]); ?>)'>
                    <div class="product-icon">
                        <i class="fas fa-wine-bottle text-white"></i>
                    </div>
                    <div class="text-center">
                        <div class="product-name">
                            <?php echo htmlspecialchars($product['name']); ?>
                        </div>
                        <div class="product-price">
                            <?php echo $settings['currency']; ?> <?php echo number_format($product['selling_price'], 0); ?>
                        </div>
                        <div class="product-stock <?php echo $stockStatus['color'] === 'green' ? 'bg-green-100 text-green-800' : ($stockStatus['color'] === 'orange' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800'); ?>">
                            <i class="fas fa-boxes mr-1"></i><?php echo $product['stock_quantity']; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- Cart Section -->
        <div class="cart-section">
            <!-- Cart Header -->
            <div class="cart-header">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold">Current Sale</h2>
                        <p class="text-white/80 text-xs" id="cartItemCount">0 items</p>
                    </div>
                    <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-shopping-cart text-lg"></i>
                    </div>
                </div>
            </div>

            <!-- Cart Items -->
            <div class="cart-items" id="cartItems">
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart"></i>
                    <p class="font-semibold text-sm text-gray-500">Cart is empty</p>
                    <p class="text-xs text-gray-400">Start adding products</p>
                </div>
            </div>

            <!-- Cart Summary -->
            <div class="cart-summary">
                <div class="space-y-2">
                    <div class="flex justify-between text-xs text-gray-600">
                        <span class="font-medium">Subtotal:</span>
                        <span class="font-bold text-gray-900" id="cartSubtotal"><?php echo $settings['currency']; ?> 0.00</span>
                    </div>
                    <div class="flex justify-between text-xs text-gray-600">
                        <span class="font-medium">Tax (<span id="taxRate"><?php echo $settings['tax_rate']; ?></span>%):</span>
                        <span class="font-bold text-gray-900" id="cartTax"><?php echo $settings['currency']; ?> 0.00</span>
                    </div>
                    <div class="flex justify-between text-base font-bold pt-2 border-t-2 border-gray-200">
                        <span>Total:</span>
                        <span style="color: <?php echo $settings['primary_color']; ?>" id="cartTotal"><?php echo $settings['currency']; ?> 0.00</span>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <button onclick="saveDraft()" class="px-3 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-semibold transition text-xs">
                        <i class="fas fa-save mr-1"></i>Save
                    </button>
                    <button onclick="clearCart()" class="px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold transition text-xs">
                        <i class="fas fa-trash mr-1"></i>Clear
                    </button>
                </div>
                
                <button onclick="showPaymentModal()" id="checkoutBtn" disabled 
                        class="w-full mt-2 px-4 py-3 text-white rounded-lg font-bold transition disabled:opacity-50 disabled:cursor-not-allowed hover:opacity-90 text-sm"
                        style="background: linear-gradient(135deg, <?php echo $settings['primary_color']; ?> 0%, <?php echo $settings['primary_color']; ?>dd 100%)">
                    <i class="fas fa-check-circle mr-2"></i>Checkout
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4" style="backdrop-filter: blur(4px);">
    <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl payment-modal-content">
        <div class="p-4 rounded-t-2xl text-white" style="background: linear-gradient(135deg, <?php echo $settings['primary_color']; ?> 0%, <?php echo $settings['primary_color']; ?>dd 100%)">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold">Complete Payment</h3>
                    <p class="text-white/80 text-xs">Choose payment method</p>
                </div>
                <button onclick="closePaymentModal()" class="text-white/80 hover:text-white transition">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>

        <div class="p-4">
            <div class="bg-gray-50 rounded-xl p-3 mb-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600 font-medium text-sm">Total Amount:</span>
                    <span class="text-2xl font-bold" style="color: <?php echo $settings['primary_color']; ?>" id="modalTotal"><?php echo $settings['currency']; ?> 0.00</span>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-700 mb-2">Payment Method</label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="selectPaymentMethod('cash')" class="payment-method-btn active">
                        <i class="fas fa-money-bill-wave text-2xl mb-1 text-green-600"></i>
                        <div class="text-xs font-semibold">Cash</div>
                    </button>
                    <button type="button" onclick="selectPaymentMethod('mpesa')" class="payment-method-btn">
                        <i class="fas fa-mobile-alt text-2xl mb-1 text-green-600"></i>
                        <div class="text-xs font-semibold">M-Pesa</div>
                    </button>
                </div>
            </div>

            <div id="mpesaRefField" class="mb-3 hidden">
                <label class="block text-xs font-semibold text-gray-700 mb-2">M-Pesa Reference</label>
                <input type="text" id="mpesaReference" class="w-full px-3 py-2 border-2 rounded-xl focus:ring-2 focus:border-green-500 text-sm" placeholder="e.g., QA12BC34DE">
            </div>

            <div class="mb-3">
                <label class="block text-xs font-semibold text-gray-700 mb-2">Amount Paid</label>
                <input type="number" id="amountPaid" class="w-full px-3 py-2 border-2 rounded-xl focus:ring-2 text-base font-semibold" 
                       style="border-color: <?php echo $settings['primary_color']; ?>33; focus:border-color: <?php echo $settings['primary_color']; ?>" 
                       step="0.01" min="0">
            </div>

            <div id="changeDisplay" class="bg-green-50 border-2 border-green-200 rounded-xl p-3 mb-3 hidden">
                <div class="flex justify-between items-center">
                    <span class="text-gray-700 font-semibold text-sm">Change:</span>
                    <span class="text-xl font-bold text-green-600" id="changeAmount"><?php echo $settings['currency']; ?> 0.00</span>
                </div>
            </div>

            <button onclick="completeSale()" id="completeSaleBtn" class="w-full px-4 py-3 text-white rounded-xl font-bold text-sm transition hover:opacity-90 shadow-lg"
                    style="background: linear-gradient(135deg, <?php echo $settings['primary_color']; ?> 0%, <?php echo $settings['primary_color']; ?>dd 100%)">
                <i class="fas fa-check-circle mr-2"></i>Complete Sale
            </button>
        </div>
    </div>
</div>

<!-- Draft Orders Modal -->
<div id="draftModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4" style="backdrop-filter: blur(4px);">
    <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white p-4 border-b flex items-center justify-between z-10">
            <h3 class="text-lg font-bold text-gray-900">Draft Orders</h3>
            <button onclick="closeDraftModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div id="draftList" class="p-4"></div>
    </div>
</div>

<script>
let cart = [];
let selectedPaymentMethod = 'cash';
let draftOrders = JSON.parse(localStorage.getItem('draftOrders') || '[]');
let currentDraftId = null;
const settings = <?php echo json_encode($settings); ?>;
const isFullscreen = <?php echo $defaultFullscreen ? 'true' : 'false'; ?>;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    if (isFullscreen) {
        document.getElementById('posContainer').classList.add('pos-fullscreen');
        document.getElementById('fullscreenIconTop').className = 'fas fa-compress text-lg';
    }
    updateDraftBadge();
});

function toggleFullscreen() {
    const container = document.getElementById('posContainer');
    const iconTop = document.getElementById('fullscreenIconTop');
    
    container.classList.toggle('pos-fullscreen');
    
    if (container.classList.contains('pos-fullscreen')) {
        iconTop.className = 'fas fa-compress text-lg';
    } else {
        iconTop.className = 'fas fa-expand text-lg';
    }
}

function addToCart(product) {
    const existingItem = cart.find(item => item.id === product.id);
    
    if (existingItem) {
        if (existingItem.quantity < product.stock_quantity) {
            existingItem.quantity++;
            showNotification(`Added another ${product.name}`, 'success');
        } else {
            showNotification('Insufficient stock', 'error');
            return;
        }
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            price: parseFloat(product.selling_price),
            quantity: 1,
            stock: product.stock_quantity
        });
        showNotification(`Added ${product.name} to cart`, 'success');
    }
    
    updateCart();
}

function updateCart() {
    const cartItemsDiv = document.getElementById('cartItems');
    const checkoutBtn = document.getElementById('checkoutBtn');
    const cartItemCount = document.getElementById('cartItemCount');
    
    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
    cartItemCount.textContent = `${totalItems} item${totalItems !== 1 ? 's' : ''}`;
    
    if (cart.length === 0) {
        cartItemsDiv.innerHTML = `
            <div class="empty-cart">
                <i class="fas fa-shopping-cart"></i>
                <p class="font-semibold text-sm text-gray-500">Cart is empty</p>
                <p class="text-xs text-gray-400">Start adding products</p>
            </div>
        `;
        checkoutBtn.disabled = true;
    } else {
        cartItemsDiv.innerHTML = cart.map(item => `
            <div class="cart-item">
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background: linear-gradient(135deg, ${settings.primary_color} 0%, ${settings.primary_color}dd 100%)">
                            <i class="fas fa-wine-bottle text-white text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-xs text-gray-900 mb-1 truncate">${item.name}</h4>
                            <p class="text-xs text-gray-600">${settings.currency} ${item.price.toFixed(0)} each</p>
                        </div>
                    </div>
                    <button onclick="removeFromCart(${item.id}); event.stopPropagation();" class="text-red-500 hover:text-red-700 transition ml-2 flex-shrink-0">
                        <i class="fas fa-times-circle text-base"></i>
                    </button>
                </div>
                <div class="flex items-center justify-between bg-gray-50 rounded-lg p-2">
                    <div class="flex items-center gap-2">
                        <button onclick="updateQuantity(${item.id}, -1); event.stopPropagation();" class="qty-btn bg-red-500 hover:bg-red-600 text-white">
                            <i class="fas fa-minus"></i>
                        </button>
                        <span class="qty-value">${item.quantity}</span>
                        <button onclick="updateQuantity(${item.id}, 1); event.stopPropagation();" class="qty-btn bg-green-500 hover:bg-green-600 text-white">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <div class="text-right">
                        <p class="cart-item-total">${settings.currency} ${(item.price * item.quantity).toFixed(0)}</p>
                    </div>
                </div>
            </div>
        `).join('');
        checkoutBtn.disabled = false;
    }
    
    updateTotals();
}

function updateQuantity(productId, change) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;
    
    const newQuantity = item.quantity + change;
    
    if (newQuantity <= 0) {
        removeFromCart(productId);
    } else if (newQuantity <= item.stock) {
        item.quantity = newQuantity;
        updateCart();
    } else {
        showNotification('Insufficient stock', 'error');
    }
}

function removeFromCart(productId) {
    cart = cart.filter(item => item.id !== productId);
    updateCart();
    showNotification('Item removed', 'info');
}

function clearCart() {
    if (cart.length === 0) return;
    if (confirm('Clear all items from cart?')) {
        cart = [];
        updateCart();
        showNotification('Cart cleared', 'info');
    }
}

function updateTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const taxRate = parseFloat(settings.tax_rate) || 0;
    const tax = subtotal * (taxRate / 100);
    const total = subtotal + tax;
    
    document.getElementById('cartSubtotal').textContent = `${settings.currency} ${subtotal.toFixed(2)}`;
    document.getElementById('cartTax').textContent = `${settings.currency} ${tax.toFixed(2)}`;
    document.getElementById('cartTotal').textContent = `${settings.currency} ${total.toFixed(2)}`;
}

function filterByCategory(categoryId) {
    const products = document.querySelectorAll('.product-card');
    const buttons = document.querySelectorAll('.category-tab');
    
    buttons.forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-category="${categoryId}"]`).classList.add('active');
    
    products.forEach(product => {
        if (categoryId === 'all' || product.dataset.category === categoryId) {
            product.style.display = 'flex';
        } else {
            product.style.display = 'none';
        }
    });
}

// Search functionality
document.getElementById('searchProduct').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase().trim();
    const products = document.querySelectorAll('.product-card');
    
    if (searchTerm === '') {
        products.forEach(p => p.style.display = 'flex');
        return;
    }
    
    products.forEach(product => {
        const productName = product.dataset.name;
        const barcode = product.dataset.barcode;
        const sku = product.dataset.sku;
        
        if (productName.includes(searchTerm) || 
            barcode.includes(searchTerm) || 
            sku.includes(searchTerm)) {
            product.style.display = 'flex';
        } else {
            product.style.display = 'none';
        }
    });
});

function toggleBarcodeScanner() {
    const scanner = document.getElementById('barcodeScanner');
    scanner.classList.toggle('hidden');
    if (!scanner.classList.contains('hidden')) {
        document.getElementById('barcodeInput').focus();
    }
}

function searchByBarcode() {
    const input = document.getElementById('barcodeInput').value.trim().toLowerCase();
    if (input.length < 3) {
        showNotification('Enter at least 3 characters', 'error');
        return;
    }
    
    const products = document.querySelectorAll('.product-card');
    const matches = [];
    
    products.forEach(product => {
        const barcode = product.dataset.barcode;
        const sku = product.dataset.sku;
        const name = product.dataset.name;
        if (barcode.includes(input) || sku.includes(input) || name.includes(input)) {
            matches.push(product);
        }
    });
    
    if (matches.length === 0) {
        showNotification('No products found', 'error');
    } else if (matches.length === 1) {
        const product = matches[0];
        addToCart({
            id: parseInt(product.dataset.id),
            name: product.querySelector('.product-name').textContent.trim(),
            selling_price: product.dataset.price,
            stock_quantity: parseInt(product.dataset.stock)
        });
        document.getElementById('barcodeInput').value = '';
        toggleBarcodeScanner();
    } else {
        showNotification(`Found ${matches.length} products. Please refine search.`, 'info');
    }
}

// Draft Orders
function saveDraft() {
    if (cart.length === 0) {
        showNotification('Cart is empty', 'error');
        return;
    }
    
    const draftName = prompt('Enter draft name (optional):') || `Draft ${new Date().toLocaleString()}`;
    
    const draft = {
        id: Date.now(),
        name: draftName,
        items: [...cart],
        timestamp: new Date().toISOString()
    };
    
    draftOrders.push(draft);
    localStorage.setItem('draftOrders', JSON.stringify(draftOrders));
    
    cart = [];
    updateCart();
    updateDraftBadge();
    showNotification('Draft saved successfully', 'success');
}

function updateDraftBadge() {
    const badge = document.getElementById('draftBadge');
    const count = document.getElementById('draftCount');
    
    if (draftOrders.length > 0) {
        badge.classList.remove('hidden');
        count.textContent = draftOrders.length;
    } else {
        badge.classList.add('hidden');
    }
}

function showDraftOrders() {
    const modal = document.getElementById('draftModal');
    const list = document.getElementById('draftList');
    
    if (draftOrders.length === 0) {
        list.innerHTML = `
            <div class="text-center py-12">
                <i class="fas fa-file-invoice text-6xl text-gray-300 mb-4"></i>
                <p class="text-xl text-gray-500 font-semibold">No draft orders</p>
            </div>
        `;
    } else {
        list.innerHTML = draftOrders.map(draft => `
            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h4 class="font-bold text-gray-900">${draft.name}</h4>
                        <p class="text-sm text-gray-500">${new Date(draft.timestamp).toLocaleString()}</p>
                        <p class="text-sm font-semibold text-blue-600 mt-1">${draft.items.length} items</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="loadDraft(${draft.id})" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg font-semibold transition text-sm">
                            <i class="fas fa-upload mr-2"></i>Load
                        </button>
                        <button onclick="if(confirm('Delete this draft?')) deleteDraft(${draft.id})" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold transition">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="space-y-1">
                    ${draft.items.map(item => `
                        <div class="text-sm text-gray-700 flex justify-between">
                            <span>${item.name} x${item.quantity}</span>
                            <span class="font-semibold">${settings.currency} ${(item.price * item.quantity).toFixed(2)}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `).join('');
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeDraftModal() {
    document.getElementById('draftModal').classList.add('hidden');
    document.getElementById('draftModal').classList.remove('flex');
}

function loadDraft(draftId) {
    const draft = draftOrders.find(d => d.id === draftId);
    if (!draft) return;
    
    currentDraftId = draftId;
    cart = [...draft.items];
    updateCart();
    closeDraftModal();
    showNotification('Draft loaded successfully', 'success');
}

function deleteDraft(draftId, showMessage = true) {
    draftOrders = draftOrders.filter(d => d.id !== draftId);
    localStorage.setItem('draftOrders', JSON.stringify(draftOrders));
    updateDraftBadge();
    if (showMessage) {
        showDraftOrders();
        showNotification('Draft deleted', 'info');
    }
}

// Payment
function showPaymentModal() {
    if (cart.length === 0) return;
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const taxRate = parseFloat(settings.tax_rate) || 0;
    const tax = subtotal * (taxRate / 100);
    const total = subtotal + tax;
    
    document.getElementById('modalTotal').textContent = `${settings.currency} ${total.toFixed(2)}`;
    document.getElementById('amountPaid').value = total.toFixed(2);
    document.getElementById('paymentModal').classList.remove('hidden');
    document.getElementById('paymentModal').classList.add('flex');
    
    selectPaymentMethod('cash');
    calculateChange();
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.add('hidden');
    document.getElementById('paymentModal').classList.remove('flex');
}

function selectPaymentMethod(method) {
    selectedPaymentMethod = method;
    
    const buttons = document.querySelectorAll('.payment-method-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    
    event.target.closest('.payment-method-btn').classList.add('active');
    
    document.getElementById('mpesaRefField').classList.add('hidden');
    
    if (method === 'mpesa') {
        document.getElementById('mpesaRefField').classList.remove('hidden');
    }
}

document.getElementById('amountPaid').addEventListener('input', calculateChange);

function calculateChange() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const taxRate = parseFloat(settings.tax_rate) || 0;
    const tax = subtotal * (taxRate / 100);
    const total = subtotal + tax;
    
    const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
    const change = amountPaid - total;
    
    const changeDisplay = document.getElementById('changeDisplay');
    const changeAmount = document.getElementById('changeAmount');
    
    if (change >= 0 && amountPaid > 0) {
        changeAmount.textContent = `${settings.currency} ${change.toFixed(2)}`;
        changeDisplay.classList.remove('hidden');
    } else {
        changeDisplay.classList.add('hidden');
    }
}

function completeSale() {
    if (cart.length === 0) {
        showNotification('Cart is empty', 'error');
        return;
    }
    
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const taxRate = parseFloat(settings.tax_rate) || 0;
    const tax = subtotal * (taxRate / 100);
    const total = subtotal + tax;
    const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
    
    if (amountPaid < total) {
        showNotification('Insufficient payment amount', 'error');
        return;
    }
    
    if (selectedPaymentMethod === 'mpesa' && !document.getElementById('mpesaReference').value) {
        showNotification('Please enter M-Pesa reference', 'error');
        return;
    }
    
    const btn = document.getElementById('completeSaleBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
    
    const formData = new FormData();
    formData.append('items', JSON.stringify(cart));
    formData.append('subtotal', subtotal.toFixed(2));
    formData.append('tax_amount', tax.toFixed(2));
    formData.append('total_amount', total.toFixed(2));
    formData.append('payment_method', selectedPaymentMethod);
    formData.append('amount_paid', amountPaid.toFixed(2));
    formData.append('change_amount', (amountPaid - total).toFixed(2));
    
    if (selectedPaymentMethod === 'mpesa') {
        formData.append('mpesa_reference', document.getElementById('mpesaReference').value);
    }
    
    fetch('/api/complete-sale.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification('Sale completed successfully!', 'success');
            cart = [];
            updateCart();
            closePaymentModal();
            
            // Remove from drafts if it was loaded from one
            if (currentDraftId) {
                deleteDraft(currentDraftId, false);
                currentDraftId = null;
            }
            
            if (confirm(`Sale completed!\n\nSale #${data.data.sale_number}\nTotal: ${settings.currency} ${data.data.total.toFixed(2)}\n\nPrint receipt?`)) {
                window.open(`/receipt.php?id=${data.data.sale_id}`, '_blank');
            }
        } else {
            showNotification(data.message || 'Failed to complete sale', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Complete Sale';
        }
    })
    .catch(err => {
        showNotification('Connection error. Please try again.', 'error');
        console.error(err);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Complete Sale';
    });
}

function showNotification(message, type = 'info') {
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        info: 'bg-blue-500'
    };
    
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-4 py-2 rounded-lg shadow-lg z-[200] transform transition-all duration-300 text-sm`;
    notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'} mr-2"></i>${message}`;
    
    document.body.appendChild(notification);
    
    setTimeout(() => notification.remove(), 3000);
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        document.getElementById('searchProduct').focus();
    }
    
    if (e.key === 'F4' && cart.length > 0) {
        e.preventDefault();
        showPaymentModal();
    }
    
    if (e.key === 'Escape') {
        closePaymentModal();
        closeDraftModal();
        if (!document.getElementById('barcodeScanner').classList.contains('hidden')) {
            toggleBarcodeScanner();
        }
    }
    
    if (e.key === 'F3') {
        e.preventDefault();
        toggleBarcodeScanner();
    }
    
    if (e.key === 'F11') {
        e.preventDefault();
        toggleFullscreen();
    }
});

document.getElementById('barcodeInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        searchByBarcode();
    }
});
</script>

<?php include 'footer.php'; ?>