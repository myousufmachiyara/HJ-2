<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>POS — Jild</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #f0f2f5;
      height: 100vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    /* ── Top Bar ── */
    .topbar {
      height: 48px;
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      flex-shrink: 0;
      z-index: 100;
    }
    .topbar-logo {
      font-weight: 700;
      font-size: 16px;
      color: #1a1a2e;
    }
    .topbar-right {
      display: flex;
      align-items: center;
      gap: 16px;
      font-size: 13px;
      color: #6b7280;
    }
    .topbar-right a {
      color: #6b7280;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 4px;
      transition: color .2s;
    }
    .topbar-right a:hover { color: #111; }

    /* ── Main Layout ── */
    .pos-body {
      display: flex;
      flex: 1;
      overflow: hidden;
    }

    /* ── Left: Product Panel ── */
    .product-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: #f0f2f5;
      padding: 12px;
      gap: 10px;
    }

    /* Search */
    .search-wrap {
      position: relative;
    }
    .search-wrap i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #9ca3af;
      font-size: 15px;
    }
    .search-input {
      width: 100%;
      padding: 12px 14px 12px 42px;
      border: 1.5px solid #e5e7eb;
      border-radius: 10px;
      font-size: 14px;
      background: #fff;
      outline: none;
      transition: border-color .2s;
    }
    .search-input:focus { border-color: #e53e3e; }

    /* Categories */
    .categories {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .cat-btn {
      padding: 6px 16px;
      border-radius: 20px;
      border: 1.5px solid #e5e7eb;
      background: #fff;
      font-size: 13px;
      cursor: pointer;
      transition: all .2s;
      color: #374151;
      white-space: nowrap;
    }
    .cat-btn:hover { border-color: #e53e3e; color: #e53e3e; }
    .cat-btn.active { background: #e53e3e; color: #fff; border-color: #e53e3e; }

    /* Product Grid */
    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 10px;
      overflow-y: auto;
      flex: 1;
      padding-bottom: 4px;
      align-content: start;        /* ← ADD THIS — cards won't stretch when row is sparse */
    }

    .product-grid::-webkit-scrollbar { width: 4px; }
    .product-grid::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

    .product-card {
      background: #fff;
      border-radius: 10px;
      padding: 12px 10px;
      cursor: pointer;
      border: 1.5px solid transparent;
      transition: all .2s;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      user-select: none;
    }
    .product-card:hover { border-color: #e53e3e; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
    .product-card:active { transform: scale(.97); }

    .product-icon {
      width: 44px;
      height: 44px;
      background: #fef2f2;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
    }
    .product-name {
      font-size: 12px;
      font-weight: 600;
      color: #111827;
      text-align: center;
      line-height: 1.3;
    }
    .product-price {
      font-size: 13px;
      font-weight: 700;
      color: #e53e3e;
    }
    .product-sku {
      font-size: 10px;
      color: #9ca3af;
    }
    .has-variations::after {
      content: '▾';
      font-size: 10px;
      color: #9ca3af;
    }

    /* ── Right: Cart Panel ── */
    .cart-panel {
      width: 340px;
      background: #fff;
      display: flex;
      flex-direction: column;
      border-left: 1px solid #e5e7eb;
      flex-shrink: 0;
    }

    /* Cart Header */
    .cart-header {
      padding: 12px 16px;
      border-bottom: 1px solid #f3f4f6;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .cart-title {
      font-weight: 700;
      font-size: 15px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .cart-count {
      background: #e53e3e;
      color: #fff;
      border-radius: 50%;
      width: 22px;
      height: 22px;
      font-size: 11px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .cart-clear {
      background: none;
      border: none;
      cursor: pointer;
      color: #ef4444;
      font-size: 16px;
      padding: 4px;
      border-radius: 6px;
      transition: background .2s;
    }
    .cart-clear:hover { background: #fef2f2; }

    /* Customer Select */
    .customer-wrap {
      padding: 8px 12px;
      border-bottom: 1px solid #f3f4f6;
    }
    .customer-wrap select {
      width: 100%;
      padding: 8px 10px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      font-size: 13px;
      color: #374151;
      outline: none;
      background: #fff;
    }

    /* Cart Column Headers */
    .cart-col-header {
      display: grid;
      grid-template-columns: 1fr 60px 70px 70px;
      padding: 6px 12px;
      font-size: 11px;
      font-weight: 600;
      color: #6b7280;
      text-transform: uppercase;
      border-bottom: 1px solid #f3f4f6;
    }

    /* Cart Items */
    .cart-items {
      flex: 1;
      overflow-y: auto;
      padding: 4px 0;
    }
    .cart-items::-webkit-scrollbar { width: 4px; }
    .cart-items::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }

    .cart-empty {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      color: #9ca3af;
      gap: 8px;
    }
    .cart-empty i { font-size: 36px; }
    .cart-empty span { font-size: 13px; }

    .cart-item {
      display: grid;
      grid-template-columns: 1fr 60px 70px 70px;
      align-items: center;
      padding: 8px 12px;
      border-bottom: 1px solid #f9fafb;
      gap: 4px;
    }
    .cart-item:hover { background: #fafafa; }
    .cart-item-name {
      font-size: 12px;
      font-weight: 600;
      color: #111827;
      line-height: 1.3;
    }
    .cart-item-sub {
      font-size: 10px;
      color: #9ca3af;
    }
    .cart-item-remove {
      color: #ef4444;
      cursor: pointer;
      font-size: 10px;
    }

    .qty-ctrl {
      display: flex;
      align-items: center;
      gap: 2px;
    }
    .qty-btn {
      width: 20px;
      height: 20px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }
    .qty-btn:hover { background: #e53e3e; color: #fff; border-color: #e53e3e; }
    .qty-input {
      width: 28px;
      text-align: center;
      border: none;
      font-size: 12px;
      font-weight: 600;
      background: transparent;
      outline: none;
    }

    .price-cell {
      font-size: 12px;
      text-align: right;
      color: #374151;
    }
    .total-cell {
      font-size: 12px;
      font-weight: 700;
      text-align: right;
      color: #111;
    }

    /* Cart Summary */
    .cart-summary {
      padding: 10px 16px;
      border-top: 1px solid #f3f4f6;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .summary-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      color: #374151;
    }
    .discount-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
      color: #374151;
    }
    .discount-input {
      width: 80px;
      text-align: right;
      padding: 3px 6px;
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      font-size: 12px;
      outline: none;
    }
    .discount-input:focus { border-color: #e53e3e; }
    .discount-suffix {
      font-size: 11px;
      color: #9ca3af;
      margin-left: 4px;
    }

    .total-row {
      display: flex;
      justify-content: space-between;
      font-size: 16px;
      font-weight: 700;
      color: #111;
      margin-top: 4px;
      padding-top: 6px;
      border-top: 1px solid #f3f4f6;
    }
    .total-amount { color: #e53e3e; }

    /* Cart Actions */
    .cart-actions {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      border-top: 1px solid #f3f4f6;
    }
    .action-btn {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 10px 4px;
      border: none;
      background: none;
      cursor: pointer;
      font-size: 10px;
      color: #6b7280;
      gap: 4px;
      transition: all .2s;
      border-right: 1px solid #f3f4f6;
    }
    .action-btn:last-child { border-right: none; }
    .action-btn:hover { background: #f9fafb; color: #111; }
    .action-btn i { font-size: 16px; }

    /* Payment Button */
    .payment-btn {
      background: #e53e3e;
      color: #fff;
      border: none;
      padding: 16px;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background .2s;
      letter-spacing: .5px;
    }
    .payment-btn:hover { background: #c53030; }
    .payment-btn:disabled { background: #d1d5db; cursor: not-allowed; }

    /* ── Modals ── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.5);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
      background: #fff;
      border-radius: 14px;
      padding: 24px;
      width: 420px;
      max-width: 95vw;
      box-shadow: 0 20px 60px rgba(0,0,0,.2);
    }
    .modal-title {
      font-size: 17px;
      font-weight: 700;
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .modal-close {
      background: none;
      border: none;
      font-size: 20px;
      cursor: pointer;
      color: #6b7280;
    }

    /* Payment Modal */
    .payment-summary {
      background: #f9fafb;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 16px;
    }
    .payment-summary-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      margin-bottom: 4px;
    }
    .payment-summary-total {
      display: flex;
      justify-content: space-between;
      font-size: 18px;
      font-weight: 700;
      margin-top: 6px;
      padding-top: 6px;
      border-top: 1px solid #e5e7eb;
      color: #e53e3e;
    }

    .payment-methods {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin-bottom: 14px;
    }
    .pay-method-btn {
      padding: 10px 6px;
      border: 2px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      transition: all .2s;
      color: #374151;
    }
    .pay-method-btn i { font-size: 18px; }
    .pay-method-btn.selected { border-color: #e53e3e; background: #fef2f2; color: #e53e3e; }

    .form-group { margin-bottom: 12px; }
    .form-label { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; display: block; }
    .form-control {
      width: 100%;
      padding: 9px 12px;
      border: 1.5px solid #e5e7eb;
      border-radius: 8px;
      font-size: 14px;
      outline: none;
      transition: border-color .2s;
    }
    .form-control:focus { border-color: #e53e3e; }

    .change-display {
      background: #f0fdf4;
      border: 1.5px solid #86efac;
      border-radius: 8px;
      padding: 10px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 14px;
    }
    .change-label { font-size: 13px; color: #166534; font-weight: 600; }
    .change-amount { font-size: 18px; font-weight: 700; color: #16a34a; }

    .btn-confirm {
      width: 100%;
      padding: 13px;
      background: #e53e3e;
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: background .2s;
    }
    .btn-confirm:hover { background: #c53030; }
    .btn-confirm:disabled { background: #d1d5db; cursor: not-allowed; }

    /* Variation Modal */
    .variation-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin-top: 12px;
    }
    .variation-btn {
      padding: 10px;
      border: 1.5px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      text-align: center;
      transition: all .2s;
    }
    .variation-btn:hover { border-color: #e53e3e; color: #e53e3e; }

    /* Held Orders Modal */
    .held-list { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
    .held-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 14px;
      border: 1.5px solid #e5e7eb;
      border-radius: 8px;
      cursor: pointer;
      transition: all .2s;
    }
    .held-item:hover { border-color: #e53e3e; background: #fef2f2; }
    .held-label { font-weight: 600; font-size: 13px; }
    .held-total { font-size: 13px; color: #e53e3e; font-weight: 700; }

    /* Z-Report Modal */
    .z-section { margin-bottom: 12px; }
    .z-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      padding: 4px 0;
      border-bottom: 1px solid #f3f4f6;
    }
    .z-row:last-child { border-bottom: none; }

    /* Success screen */
    .success-screen {
      text-align: center;
      padding: 10px 0;
    }
    .success-icon {
      width: 60px;
      height: 60px;
      background: #f0fdf4;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 12px;
      font-size: 26px;
      color: #16a34a;
    }
    .success-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
    .success-sub { font-size: 13px; color: #6b7280; margin-bottom: 16px; }
    .success-change {
      font-size: 24px;
      font-weight: 800;
      color: #16a34a;
      margin-bottom: 16px;
    }
    .btn-row { display: flex; gap: 8px; }
    .btn-outline {
      flex: 1;
      padding: 10px;
      border: 1.5px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      transition: all .2s;
    }
    .btn-outline:hover { border-color: #111; }
    .btn-primary-sm {
      flex: 1;
      padding: 10px;
      background: #e53e3e;
      color: #fff;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 700;
      transition: background .2s;
    }
    .btn-primary-sm:hover { background: #c53030; }

    /* Notification */
    .notif {
      position: fixed;
      top: 58px;
      right: 16px;
      background: #111;
      color: #fff;
      padding: 10px 16px;
      border-radius: 8px;
      font-size: 13px;
      z-index: 2000;
      opacity: 0;
      transform: translateY(-8px);
      transition: all .3s;
      pointer-events: none;
    }
    .notif.show { opacity: 1; transform: translateY(0); }

    @media (max-width: 768px) {
      .cart-panel { width: 100%; }
      .product-panel { display: none; }
    }
  </style>
</head>
<body>

{{-- Top Bar --}}
<div class="topbar">
  <div class="topbar-logo">🏷️ Jild POS</div>
  <div class="topbar-right">
    <span><i class="fas fa-user"></i> {{ auth()->user()->name }}</span>
    <a href="{{ route('dashboard') }}"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div class="pos-body">

  {{-- LEFT: Product Panel --}}
  <div class="product-panel">

    {{-- Search --}}
    <div class="search-wrap">
      <i class="fas fa-barcode"></i>
      <input type="text" class="search-input" id="searchInput"
             placeholder="Scan barcode or type to search... (F1 to focus)"
             autocomplete="off">
    </div>

    {{-- Categories --}}
    <div class="categories" id="categoryBar">
      <button class="cat-btn active" data-cat="all">All</button>
      @foreach($categories as $cat)
        <button class="cat-btn" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
      @endforeach
    </div>

    {{-- Product Grid --}}
    <div class="product-grid" id="productGrid">
      @foreach($productsRaw as $p)
        <div class="product-card {{ $p->variations->count() ? 'has-variations' : '' }}"
            data-id="{{ $p->id }}"
            data-cat="{{ $p->category_id }}"
            data-name="{{ strtolower($p->name) }}"
            data-barcode="{{ strtolower($p->barcode ?? '') }}"
            data-price="{{ $p->selling_price }}"
            data-unit="{{ $p->measurement_unit }}"
            data-variations="{{ $p->variations->count() }}"
            onclick="handleProductClick({{ $p->id }})">
          <div class="product-icon">🛍️</div>
          <div class="product-name">{{ $p->name }}</div>
          <div class="product-price">{{ number_format($p->selling_price, 0) }}</div>
          <div class="product-sku">{{ $p->sku }}</div>
        </div>
      @endforeach
    </div>

  </div>

  {{-- RIGHT: Cart Panel --}}
  <div class="cart-panel">

    {{-- Header --}}
    <div class="cart-header">
      <div class="cart-title">
        <i class="fas fa-shopping-cart"></i>
        Cart
        <span class="cart-count" id="cartCount">0</span>
      </div>
      <button class="cart-clear" onclick="clearCart()" title="Clear cart">
        <i class="fas fa-trash"></i>
      </button>
    </div>

    {{-- Customer --}}
    <div class="customer-wrap">
      <select data-plugin-selecttwo class="form-control select2-js" id="customerSelect">
        <option value="">Walk-in Customer</option>
        @foreach($customers as $c)
          <option value="{{ $c->id }}">{{ $c->name }}</option>
        @endforeach
      </select>
    </div>

    {{-- Column Headers --}}
    <div class="cart-col-header">
      <span>Item</span>
      <span style="text-align:center">Qty</span>
      <span style="text-align:right">Price</span>
      <span style="text-align:right">Total</span>
    </div>

    {{-- Cart Items --}}
    <div class="cart-items" id="cartItems">
      <div class="cart-empty">
        <i class="fas fa-shopping-cart"></i>
        <span>Cart is empty</span>
      </div>
    </div>

    {{-- Summary --}}
    <div class="cart-summary">
      <div class="summary-row">
        <span>Subtotal</span>
        <span id="subTotalDisplay">0.00</span>
      </div>
      <div class="discount-row">
        <span>Discount</span>
        <div style="display:flex;align-items:center">
          <input type="number" class="discount-input" id="discountInput"
                 value="0" min="0" oninput="updateTotals()">
          <span class="discount-suffix">PKR</span>
        </div>
      </div>
      <div class="summary-row" style="color:#6b7280;font-size:12px;">
        <span>Tax</span>
        <span>0.00</span>
      </div>
      <div class="total-row">
        <span>TOTAL</span>
        <span class="total-amount">PKR <span id="totalDisplay">0.00</span></span>
      </div>
    </div>

    {{-- Action Buttons --}}
    <div class="cart-actions">
      <button class="action-btn" onclick="holdOrder()" title="Hold [F3]">
        <i class="fas fa-pause"></i>
        <span>Hold [F3]</span>
      </button>
      <button class="action-btn" onclick="openRecallModal()">
        <i class="fas fa-play"></i>
        <span>Recall</span>
      </button>
      <button class="action-btn" onclick="openZReport()">
        <i class="fas fa-chart-bar"></i>
        <span>Z-Report</span>
      </button>
      <button class="action-btn" onclick="clearCart()">
        <i class="fas fa-times"></i>
        <span>Cancel</span>
      </button>
    </div>

    {{-- Payment Button --}}
    <button class="payment-btn" id="payBtn" onclick="openPaymentModal()" disabled>
      <i class="fas fa-credit-card"></i> PAYMENT [F2]
    </button>

  </div>
</div>

{{-- ── Variation Modal ── --}}
<div class="modal-overlay" id="variationModal">
  <div class="modal-box">
    <div class="modal-title">
      <span id="varModalTitle">Select Variation</span>
      <button class="modal-close" onclick="closeModal('variationModal')">×</button>
    </div>
    <p style="font-size:13px;color:#6b7280;">Choose a variation to add to cart</p>
    <div class="variation-grid" id="variationGrid"></div>
  </div>
</div>

{{-- ── Payment Modal ── --}}
<div class="modal-overlay" id="paymentModal">
  <div class="modal-box">
    <div id="paymentContent">
      <div class="modal-title">
        <span>Payment</span>
        <button class="modal-close" onclick="closeModal('paymentModal')">×</button>
      </div>

      <div class="payment-summary">
        <div class="payment-summary-row"><span>Subtotal</span><span id="pmSubtotal">0.00</span></div>
        <div class="payment-summary-row"><span>Discount</span><span id="pmDiscount">0.00</span></div>
        <div class="payment-summary-total">
          <span>Total</span><span id="pmTotal">PKR 0.00</span>
        </div>
      </div>

      <div class="payment-methods">
        <button class="pay-method-btn selected" data-method="cash" onclick="selectPayMethod('cash')">
          <i class="fas fa-money-bill"></i>Cash
        </button>
        <button class="pay-method-btn" data-method="card" onclick="selectPayMethod('card')">
          <i class="fas fa-credit-card"></i>Card
        </button>
        <button class="pay-method-btn" data-method="bank" onclick="selectPayMethod('bank')">
          <i class="fas fa-university"></i>Bank
        </button>
      </div>

      <div class="form-group">
        <label class="form-label">Receive Into Account</label>
        <select data-plugin-selecttwo class="form-control select2-js" id="payAccountSelect">
          @foreach($accounts as $acc)
            <option value="{{ $acc->id }}">{{ $acc->name }}</option>
          @endforeach
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Amount Tendered</label>
        <input type="number" class="form-control" id="tenderedInput"
               placeholder="Enter amount" oninput="calcChange()">
      </div>

      <div class="change-display" id="changeDisplay" style="display:none;">
        <span class="change-label">Change</span>
        <span class="change-amount" id="changeAmount">PKR 0.00</span>
      </div>

      <button class="btn-confirm" id="confirmPayBtn" onclick="processPayment()">
        <i class="fas fa-check me-1"></i> Confirm Payment
      </button>
    </div>

    {{-- Success Screen --}}
    <div id="successContent" style="display:none;">
      <div class="success-screen">
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <div class="success-title">Payment Successful!</div>
        <div class="success-sub" id="successInvoiceNo"></div>
        <div class="success-change" id="successChange"></div>
        <div class="btn-row">
          <button class="btn-outline" onclick="printReceipt()">
            <i class="fas fa-print"></i> Print Receipt
          </button>
          <button class="btn-primary-sm" onclick="newSale()">
            <i class="fas fa-plus"></i> New Sale
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- ── Recall Modal ── --}}
<div class="modal-overlay" id="recallModal">
  <div class="modal-box">
    <div class="modal-title">
      <span>Held Orders</span>
      <button class="modal-close" onclick="closeModal('recallModal')">×</button>
    </div>
    <div class="held-list" id="heldList">
      @forelse($heldOrders as $held)
        <div class="held-item" onclick="recallOrder({{ $held->id }})">
          <div>
            <div class="held-label">{{ $held->label }}</div>
            <div style="font-size:11px;color:#9ca3af;">
              {{ $held->created_at->format('d-M H:i') }} · {{ json_decode($held->cart, true) ? count(json_decode($held->cart, true)) : 0 }} items
            </div>
          </div>
          <div class="held-total">PKR {{ number_format($held->total, 0) }}</div>
        </div>
      @empty
        <div style="text-align:center;color:#9ca3af;font-size:13px;padding:20px 0;">
          No held orders
        </div>
      @endforelse
    </div>
  </div>
</div>

{{-- ── Z-Report Modal ── --}}
<div class="modal-overlay" id="zReportModal">
  <div class="modal-box" style="width:480px;">
    <div class="modal-title">
      <span>Z-Report — <span id="zDate"></span></span>
      <button class="modal-close" onclick="closeModal('zReportModal')">×</button>
    </div>
    <div id="zReportContent">
      <div style="text-align:center;color:#9ca3af;padding:20px;">Loading...</div>
    </div>
  </div>
</div>

{{-- Notification --}}
<div class="notif" id="notif"></div>

{{-- Hidden data --}}
<script>
  const PRODUCTS = @json($productsJs);
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;

// ── State ──
let cart         = [];
let currentDisc  = 0;
let payMethod    = 'cash';
let lastInvoiceId = null;
let heldOrderId  = null;

// ── Cart Operations ──────────────────────────────────────────────────

function handleProductClick(productId) {
  const p = PRODUCTS.find(x => x.id === productId);
  if (!p) return;

  if (p.variations.length > 0) {
    openVariationModal(p);
  } else {
    addToCart(p, null);
  }
}

function addToCart(product, variation) {
  const key = `${product.id}_${variation?.id ?? 'null'}`;
  const existing = cart.find(i => i.key === key);
  const price = variation?.price ?? product.price;

  if (existing) {
    existing.qty += 1;
  } else {
    cart.push({
      key,
      product_id:   product.id,
      variation_id: variation?.id ?? null,
      name:         product.name,
      sku:          variation ? variation.sku : product.sku,
      price,
      unit_id:      product.unit_id,
      unit:         product.unit,
      qty:          1,
      discount:     0,
    });
  }

  renderCart();
  notify(`Added: ${product.name}${variation ? ' — ' + variation.sku : ''}`);
}

function updateQty(key, delta) {
  const item = cart.find(i => i.key === key);
  if (!item) return;
  item.qty = Math.max(0, item.qty + delta);
  if (item.qty === 0) cart = cart.filter(i => i.key !== key);
  renderCart();
}

function setQty(key, val) {
  const item = cart.find(i => i.key === key);
  if (!item) return;
  const qty = parseFloat(val) || 0;
  if (qty <= 0) {
    cart = cart.filter(i => i.key !== key);
  } else {
    item.qty = qty;
  }
  renderCart();
}

function removeFromCart(key) {
  cart = cart.filter(i => i.key !== key);
  renderCart();
}

function clearCart() {
  if (cart.length === 0) return;
  if (!confirm('Clear cart?')) return;
  cart = [];
  heldOrderId = null;
  document.getElementById('customerSelect').value = '';
  document.getElementById('discountInput').value = 0;
  renderCart();
}

// ── Render Cart ──────────────────────────────────────────────────────

function renderCart() {
  const el = document.getElementById('cartItems');
  const count = cart.reduce((s, i) => s + i.qty, 0);
  document.getElementById('cartCount').textContent = cart.length;

  if (cart.length === 0) {
    el.innerHTML = `<div class="cart-empty">
      <i class="fas fa-shopping-cart"></i><span>Cart is empty</span>
    </div>`;
    document.getElementById('payBtn').disabled = true;
    updateTotals();
    return;
  }

  el.innerHTML = cart.map(item => {
    const lineTotal = (item.price * (1 - item.discount / 100) * item.qty).toFixed(2);
    return `
      <div class="cart-item">
        <div>
          <div class="cart-item-name">${item.name}</div>
          <div class="cart-item-sub">
            ${item.sku}
            <span class="cart-item-remove" onclick="removeFromCart('${item.key}')">
              &nbsp;✕ remove
            </span>
          </div>
        </div>
        <div class="qty-ctrl">
          <button class="qty-btn" onclick="updateQty('${item.key}', -1)">−</button>
          <input class="qty-input" type="number" value="${item.qty}" min="0.01" step="any"
                 onchange="setQty('${item.key}', this.value)">
          <button class="qty-btn" onclick="updateQty('${item.key}', 1)">+</button>
        </div>
        <div class="price-cell">${parseFloat(item.price).toFixed(0)}</div>
        <div class="total-cell">${lineTotal}</div>
      </div>`;
  }).join('');

  document.getElementById('payBtn').disabled = false;
  updateTotals();
}

function updateTotals() {
  const sub  = cart.reduce((s, i) => s + i.price * (1 - i.discount / 100) * i.qty, 0);
  const disc = parseFloat(document.getElementById('discountInput').value) || 0;
  const net  = Math.max(0, sub - disc);

  document.getElementById('subTotalDisplay').textContent = sub.toFixed(2);
  document.getElementById('totalDisplay').textContent    = net.toFixed(2);
}

// ── Barcode / Search ─────────────────────────────────────────────────

document.getElementById('searchInput').addEventListener('input', function () {
  const q    = this.value.toLowerCase().trim();
  const cards = document.querySelectorAll('.product-card');
  const activeCat = document.querySelector('.cat-btn.active')?.dataset.cat ?? 'all';

  cards.forEach(card => {
    const matchSearch = !q || card.dataset.name.includes(q) || card.dataset.barcode.includes(q);
    const matchCat    = activeCat === 'all' || card.dataset.cat === activeCat;
    card.style.display = matchSearch && matchCat ? '' : 'none';
  });

  // Auto-add if exact barcode match
  if (q.length >= 5) {
    const exact = PRODUCTS.find(p => p.barcode && p.barcode.toLowerCase() === q);
    if (exact) {
      this.value = '';
      handleProductClick(exact.id);
      cards.forEach(c => c.style.display = '');
    }
  }
});

// Category filter
document.getElementById('categoryBar').addEventListener('click', function (e) {
  if (!e.target.classList.contains('cat-btn')) return;
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
  e.target.classList.add('active');

  const cat   = e.target.dataset.cat;
  const cards = document.querySelectorAll('.product-card');
  cards.forEach(card => {
    card.style.display = cat === 'all' || card.dataset.cat === cat ? '' : 'none';
  });
});

// Keyboard shortcuts
document.addEventListener('keydown', function (e) {
  if (e.key === 'F1') { e.preventDefault(); document.getElementById('searchInput').focus(); }
  if (e.key === 'F2') { e.preventDefault(); if (cart.length) openPaymentModal(); }
  if (e.key === 'F3') { e.preventDefault(); holdOrder(); }
  if (e.key === 'Escape') closeAllModals();
});

// ── Variation Modal ──────────────────────────────────────────────────

let pendingProduct = null;

function openVariationModal(product) {
  pendingProduct = product;
  document.getElementById('varModalTitle').textContent = product.name;
  const grid = document.getElementById('variationGrid');

  grid.innerHTML = product.variations.map((v, idx) => `
    <button class="variation-btn" data-var-idx="${idx}">
      ${v.sku}<br>
      <small style="color:#9ca3af;font-weight:400;">${parseFloat(v.price).toFixed(0)}</small>
    </button>`
  ).join('');

  // Attach listeners after rendering — avoids JSON.stringify escaping issues
  grid.querySelectorAll('.variation-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const v = pendingProduct.variations[parseInt(this.dataset.varIdx)];
      addToCart(pendingProduct, v);
      closeModal('variationModal');
    });
  });

  openModal('variationModal');
}

// ── Payment Modal ────────────────────────────────────────────────────

function openPaymentModal() {
  if (cart.length === 0) return;
  const sub  = cart.reduce((s, i) => s + i.price * (1 - i.discount / 100) * i.qty, 0);
  const disc = parseFloat(document.getElementById('discountInput').value) || 0;
  const net  = Math.max(0, sub - disc);

  document.getElementById('pmSubtotal').textContent  = sub.toFixed(2);
  document.getElementById('pmDiscount').textContent  = disc.toFixed(2);
  document.getElementById('pmTotal').textContent     = 'PKR ' + net.toFixed(2);
  document.getElementById('tenderedInput').value     = net.toFixed(2);
  document.getElementById('changeDisplay').style.display = 'none';
  document.getElementById('paymentContent').style.display = '';
  document.getElementById('successContent').style.display = 'none';

  calcChange();
  openModal('paymentModal');
  setTimeout(() => document.getElementById('tenderedInput').focus(), 200);
}

function selectPayMethod(method) {
  payMethod = method;
  document.querySelectorAll('.pay-method-btn').forEach(b => {
    b.classList.toggle('selected', b.dataset.method === method);
  });
}

function calcChange() {
  const net      = parseFloat(document.getElementById('pmTotal').textContent.replace('PKR ', '')) || 0;
  const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;
  const change   = tendered - net;

  if (tendered > 0) {
    document.getElementById('changeDisplay').style.display = 'flex';
    document.getElementById('changeAmount').textContent = 'PKR ' + Math.max(0, change).toFixed(2);
  }
}

async function processPayment() {
  const btn = document.getElementById('confirmPayBtn');
  btn.disabled = true;
  btn.textContent = 'Processing...';

  const sub      = cart.reduce((s, i) => s + i.price * (1 - i.discount / 100) * i.qty, 0);
  const disc     = parseFloat(document.getElementById('discountInput').value) || 0;
  const net      = Math.max(0, sub - disc);
  const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;
  const accountId = document.getElementById('payAccountSelect').value;
  const customerId = document.getElementById('customerSelect').value;

  if (!accountId) { notify('Select a payment account'); btn.disabled = false; btn.textContent = 'Confirm Payment'; return; }
  if (tendered <= 0) { notify('Enter payment amount'); btn.disabled = false; btn.textContent = 'Confirm Payment'; return; }

  const payload = {
    customer_id:        customerId || null,
    items:              cart.map(i => ({
      product_id:   i.product_id,
      variation_id: i.variation_id,
      unit_id:      i.unit_id,
      quantity:     i.qty,
      price:        i.price,
      discount:     i.discount,
    })),
    discount:           disc,
    payment_account_id: accountId,
    payment_amount:     Math.min(tendered, net),
    payment_type:       payMethod,
    held_order_id:      heldOrderId,
  };

  try {
    const res  = await fetch('{{ route("pos.checkout") }}', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();

    if (data.success) {
      lastInvoiceId = data.invoice_id;
      const change  = tendered - net;

      document.getElementById('paymentContent').style.display = 'none';
      document.getElementById('successContent').style.display = '';
      document.getElementById('successInvoiceNo').textContent = 'Invoice: ' + data.invoice_no;
      document.getElementById('successChange').textContent    =
        change > 0 ? 'Change: PKR ' + change.toFixed(2) : 'Paid: PKR ' + tendered.toFixed(2);
    } else {
      notify(data.message || 'Payment failed');
    }
  } catch (e) {
    notify('Network error. Try again.');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-check me-1"></i> Confirm Payment';
}

function printReceipt() {
  if (lastInvoiceId) {
    window.open(`/pos/receipt/${lastInvoiceId}`, '_blank');
  }
}

function newSale() {
  cart        = [];
  heldOrderId = null;
  document.getElementById('customerSelect').value = '';
  document.getElementById('discountInput').value = 0;
  renderCart();
  closeModal('paymentModal');
  document.getElementById('searchInput').focus();
}

// ── Hold / Recall ────────────────────────────────────────────────────

async function holdOrder() {
  if (cart.length === 0) { notify('Cart is empty'); return; }

  const label      = 'Hold #' + Date.now().toString().slice(-4);
  const customerId = document.getElementById('customerSelect').value;
  const sub        = cart.reduce((s, i) => s + i.price * i.qty, 0);

  const res  = await fetch('{{ route("pos.hold") }}', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body:    JSON.stringify({ cart, total: sub, customer_id: customerId || null, label }),
  });
  const data = await res.json();

  if (data.success) {
    cart        = [];
    heldOrderId = null;
    document.getElementById('discountInput').value = 0;
    renderCart();
    notify('Order held: ' + data.label);

    // Add to held list
    const list = document.getElementById('heldList');
    const div  = document.createElement('div');
    div.className = 'held-item';
    div.onclick   = () => recallOrder(data.id);
    div.innerHTML = `
      <div><div class="held-label">${data.label}</div></div>
      <div class="held-total">PKR ${sub.toFixed(0)}</div>`;
    if (list.querySelector('.cart-empty')) list.innerHTML = '';
    list.prepend(div);
  }
}

async function openRecallModal() {
  openModal('recallModal');
}

async function recallOrder(id) {
  const res  = await fetch(`/pos/recall/${id}`);
  const data = await res.json();
  if (!data.success) return;

  cart        = data.cart;
  heldOrderId = data.id;
  if (data.customer_id) {
    document.getElementById('customerSelect').value = data.customer_id;
  }

  renderCart();
  closeModal('recallModal');
  notify('Order recalled: ' + data.label);
}

// ── Z-Report ─────────────────────────────────────────────────────────

async function openZReport() {
  openModal('zReportModal');
  document.getElementById('zReportContent').innerHTML =
    '<div style="text-align:center;color:#9ca3af;padding:20px;">Loading...</div>';

  const res  = await fetch('{{ route("pos.zreport") }}');
  const data = await res.json();

  document.getElementById('zDate').textContent = data.date;

  let html = `
    <div class="z-section">
      <div class="z-row"><span>Invoices</span><strong>${data.invoice_count}</strong></div>
      <div class="z-row"><span>Total Sales</span><strong>PKR ${parseFloat(data.total_sales).toFixed(2)}</strong></div>
      <div class="z-row"><span>Total Received</span><strong>PKR ${parseFloat(data.total_received).toFixed(2)}</strong></div>
      <div class="z-row"><span>Total Discount</span><strong>PKR ${parseFloat(data.total_discount).toFixed(2)}</strong></div>
    </div>
    <h6 style="font-size:12px;font-weight:700;text-transform:uppercase;color:#6b7280;margin:12px 0 6px;">
      Payment Breakdown
    </h6>
    <div class="z-section">`;

  for (const [acc, amt] of Object.entries(data.payment_breakdown || {})) {
    html += `<div class="z-row"><span>${acc}</span><strong>PKR ${parseFloat(amt).toFixed(2)}</strong></div>`;
  }

  html += `</div>
    <button onclick="window.print()" class="btn-outline" style="width:100%;margin-top:10px;">
      <i class="fas fa-print"></i> Print Z-Report
    </button>`;

  document.getElementById('zReportContent').innerHTML = html;
}

// ── Modal Helpers ────────────────────────────────────────────────────

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function closeAllModals() {
  document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', function (e) {
    if (e.target === this) closeAllModals();
  });
});

// ── Notification ─────────────────────────────────────────────────────

function notify(msg) {
  const el = document.getElementById('notif');
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(el._timer);
  el._timer = setTimeout(() => el.classList.remove('show'), 2500);
}

// ── Init ─────────────────────────────────────────────────────────────

renderCart();
document.getElementById('searchInput').focus();
</script>

</body>
</html> 