@extends('layouts.app')
@section('title', 'Sale Invoice | New')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_invoices.store') }}" method="POST">
      @csrf
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">New Sale Invoice</h2>
        </header>

        <div class="card-body">

          @if($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
              </ul>
            </div>
          @endif

          {{-- Header --}}
          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Date <span class="text-danger">*</span></label>
              <input type="date" name="date" class="form-control"
                     value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3 mb-3">
              <label>Customer</label>
              <select name="account_id" data-plugin-selecttwo class="form-control select2-js" required>
                <option disabled>Select Customer</option>
                @foreach($customers as $c)
                  <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2 mb-3">
              <label>Type <span class="text-danger">*</span></label>
              <select name="type" class="form-control" id="invoice_type" required>
                <option value="credit">Credit</option>
                <option value="cash">Cash</option>
              </select>
            </div>
            <div class="col-md-2 mb-3">
              <label>Payment Terms</label>
              <input type="text" name="payment_terms" class="form-control">
            </div>
            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control">
            </div>
            <div class="col-md-4 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2"></textarea>
            </div>
          </div>

          {{-- Items --}}
          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="saleTable">
              <thead class="table-light">
                <tr>
                  <th>Barcode</th>
                  <th>Item</th>
                  <th>Variation</th>
                  <th width="9%">Qty</th>
                  <th width="10%">Unit</th>
                  <th width="10%">Price</th>
                  <th width="8%">Disc %</th>
                  <th width="10%">Amount</th>
                  <th width="5%"></th>
                </tr>
              </thead>
              <tbody id="SaleTableBody">
                <tr>
                  <td><input type="text" name="items[0][barcode]" id="barcode_1"
                             class="form-control product-code"></td>
                  <td>
                    <select name="items[0][product_id]" id="product_1"
                            data-plugin-selecttwo class="form-control select2-js product-select"
                            onchange="onProductChange(this)">
                      <option value="">Select Item</option>
                      @foreach($products as $p)
                        <option value="{{ $p->id }}"
                                data-barcode="{{ $p->barcode }}"
                                data-price="{{ $p->selling_price }}"
                                data-unit="{{ $p->measurement_unit }}">
                          {{ $p->name }}
                        </option>
                      @endforeach
                    </select>
                  </td>
                  <td>
                    <select name="items[0][variation_id]" id="variation_1"
                            data-plugin-selecttwo class="form-control select2-js variation-select">
                      <option value="">No Variation</option>
                    </select>
                  </td>
                  <td><input type="number" name="items[0][quantity]" id="qty_1"
                             class="form-control quantity" value="0" step="any"
                             onchange="rowTotal(1)"></td>
                  <td>
                    <select name="items[0][unit]" id="unit_1" data-plugin-selecttwo class="form-control select2-js" required>
                      <option value="">Unit</option>
                      @foreach($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->shortcode }}</option>
                      @endforeach
                    </select>
                  </td>
                  <td><input type="number" name="items[0][sale_price]" id="price_1"
                             class="form-control" value="0" step="any"
                             onchange="rowTotal(1)"></td>
                  <td><input type="number" name="items[0][discount]" id="disc_1"
                             class="form-control" value="0" step="any" min="0" max="100"
                             onchange="rowTotal(1)"></td>
                  <td><input type="number" id="amount_1" class="form-control" value="0" disabled></td>
                  <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                      <i class="fas fa-times"></i>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRow()">
              <i class="fas fa-plus"></i> Add Item
            </button>
          </div>

          {{-- Totals --}}
          <div class="row mb-3">
            <div class="col-md-2">
              <label>Sub Total</label>
              <input type="text" id="subTotal" class="form-control" disabled>
            </div>
            <div class="col-md-2">
              <label>Bill Discount (PKR)</label>
              <input type="number" name="discount" id="bill_discount"
                     class="form-control" value="0" step="any" onchange="calcNet()">
            </div>
            <div class="col-md-2">
              <label>Conveyance</label>
              <input type="number" name="convance_charges" id="conveyance"
                     class="form-control" value="0" step="any" onchange="calcNet()">
            </div>
            <div class="col-md-6 text-end">
              <h4 class="text-primary">Net: <strong class="text-danger">
                PKR <span id="netDisplay">0.00</span>
              </strong></h4>
              <input type="hidden" name="net_amount" id="net_amount">
            </div>
          </div>

          {{-- Payment Section --}}
          <div class="card border-success mt-2" id="payment_section">
            <div class="card-header bg-success text-white d-flex justify-content-between">
              <h6 class="mb-0"><i class="fas fa-money-bill-wave me-1"></i> Payment (Optional)</h6>
              <small>Leave blank to record as unpaid</small>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-3">
                  <label>Receive Into Account</label>
                  <select name="payment_account_id" data-plugin-selecttwo class="form-control select2-js">
                    <option value="">-- No Payment --</option>
                    @foreach($accounts as $acc)
                      <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-md-2">
                  <label>Payment Date</label>
                  <input type="date" name="payment_date" class="form-control"
                         value="{{ date('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                  <label>Amount</label>
                  <input type="number" name="payment_amount" id="payment_amount"
                         class="form-control" value="0" step="any">
                </div>
                <div class="col-md-2">
                  <label>Reference</label>
                  <input type="text" name="payment_reference" class="form-control">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-success btn-sm w-100"
                          onclick="payFull()">Pay Full</button>
                </div>
              </div>
            </div>
          </div>

        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Save Invoice
          </button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var rowIdx   = 2;

  // Scan step: qty added each time the same code is (re)scanned.
  const SCAN_INCREMENT = 1;

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    $(document).on('change', '.product-select', function () {
      const row       = $(this).closest('tr');
      const productId = $(this).val();
      if (productId) loadVariations(row, productId);
    });

    // 🔹 Hardware scanner Enter → intercept + treat as scan complete
    $(document).on('keydown', '.product-code', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        $(this).trigger('scan:submit');
      }
    });

    // 🔹 Barcode scan handler (repeat-increments + auto-next-line)
    $(document).on('scan:submit', '.product-code', function () {
      const $input  = $(this);
      const row     = $input.closest('tr');
      const barcode = $input.val().trim();
      if (!barcode) return;

      $.get('/get-product-by-code/' + encodeURIComponent(barcode), function (res) {
        if (!res || !res.success) { alert((res && res.message) || 'Not found'); $input.val('').focus(); return; }

        if (res.type === 'variation') {
          handleScannedVariation(row, res.variation.product_id, res.variation.id, res.variation.sku, res.variation.price);
        } else if (res.type === 'product') {
          handleScannedProduct(row, res.product);
        }
      }).fail(() => alert('Error fetching product.'));
    });

    // Manual typing + tab-out: same path, only if product not yet chosen
    $(document).on('blur', '.product-code', function () {
      const barcode = $(this).val().trim();
      if (barcode && !$(this).closest('tr').find('.product-select').val()) {
        $(this).trigger('scan:submit');
      }
    });

    // Enter on qty → advance to next scan box (no empty-row pile-up)
    $(document).on('keypress', '.quantity', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        const $last = $('#SaleTableBody tr').last();
        if (!rowIsEmpty($last)) addRow();
        focusLastScanBox();
      }
    });
  });

  // ── Row index helper (rows use id suffixes: product_1, qty_1, …) ──
  function rowIndexOf($row) {
    const id = $row.find('.product-select').attr('id') || '';
    return id.replace('product_', '');
  }

  // ── Duplicate-detection helpers ──────────────────────────────────────
  function findRowByVariation(productId, variationId) {
    let match = null;
    $('#SaleTableBody tr').each(function () {
      const $r  = $(this);
      const pid = $r.find('.product-select').val();
      const vid = $r.find('.variation-select').val() || '';
      const wantV = (variationId ?? '') + '';
      if (String(pid) === String(productId) && String(vid) === wantV) {
        match = $r;
        return false;
      }
    });
    return match;
  }

  function rowIsEmpty($row) {
    return !$row.find('.product-select').val();
  }

  function flashRow($row) {
    $row.addClass('table-success');
    setTimeout(() => $row.removeClass('table-success'), 600);
  }

  function focusLastScanBox() {
    $('#SaleTableBody tr').last().find('.product-code').focus();
  }

  function appendAndGetRow() {
    addRow();
    return $('#SaleTableBody tr').last();
  }

  function openNextScanRow() {
    const $last = $('#SaleTableBody tr').last();
    const $next = rowIsEmpty($last) ? $last : appendAndGetRow();
    $next.find('.product-code').focus();
  }

  // ── Scan handlers ────────────────────────────────────────────────────
  function handleScannedVariation(scanRow, productId, variationId, sku, price) {
    const existing = findRowByVariation(productId, variationId);

    if (existing) {
      const i    = rowIndexOf(existing);
      const $qty = existing.find('.quantity');
      const cur  = parseFloat($qty.val()) || 0;
      $qty.val(cur + SCAN_INCREMENT);
      rowTotal(i);
      flashRow(existing);
      if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
      else focusLastScanBox();
      return;
    }

    const targetRow = rowIsEmpty(scanRow) ? scanRow : appendAndGetRow();
    const i = rowIndexOf(targetRow);

    targetRow.find('.product-select').val(productId).trigger('change.select2');
    targetRow.find('.variation-select')
      .html(`<option value="${variationId}" selected>${sku}</option>`)
      .prop('disabled', false)
      .trigger('change');

    // Price: prefer variation price from endpoint, else the product option's data-price.
    let usePrice = price;
    if (!usePrice) {
      const opt = targetRow.find(`.product-select option[value="${productId}"]`);
      usePrice = opt.data('price') || 0;
    }
    if (usePrice) $(`#price_${i}`).val(usePrice);

    // Unit: pull from the product option's data-unit if present.
    const optU = targetRow.find(`.product-select option[value="${productId}"]`);
    const unit = optU.data('unit');
    if (unit) $(`#unit_${i}`).val(String(unit)).trigger('change.select2');

    $(`#qty_${i}`).val(SCAN_INCREMENT);
    rowTotal(i);

    targetRow.find('.product-code').val('');
    openNextScanRow();
  }

  function handleScannedProduct(scanRow, product) {
    const existing = findRowByVariation(product.id, null);

    if (existing) {
      const i    = rowIndexOf(existing);
      const $qty = existing.find('.quantity');
      const cur  = parseFloat($qty.val()) || 0;
      $qty.val(cur + SCAN_INCREMENT);
      rowTotal(i);
      flashRow(existing);
      if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
      else focusLastScanBox();
      return;
    }

    const targetRow = rowIsEmpty(scanRow) ? scanRow : appendAndGetRow();
    const i = rowIndexOf(targetRow);

    targetRow.find('.product-select').val(product.id).trigger('change.select2');

    const opt = targetRow.find(`.product-select option[value="${product.id}"]`);
    if (opt.data('price')) $(`#price_${i}`).val(opt.data('price'));
    if (opt.data('unit'))  $(`#unit_${i}`).val(String(opt.data('unit'))).trigger('change.select2');

    loadVariations(targetRow, product.id);
    $(`#qty_${i}`).val(SCAN_INCREMENT);
    rowTotal(i);

    targetRow.find('.product-code').val('');
    openNextScanRow();
  }

  function onProductChange(selectEl) {
    const row  = selectEl.closest('tr');
    const opt  = selectEl.options[selectEl.selectedIndex];
    const i    = selectEl.id.replace('product_', '');
    const price = opt.getAttribute('data-price') || 0;
    const unit  = opt.getAttribute('data-unit')  || '';
    document.getElementById(`price_${i}`).value = price;
    $(`#unit_${i}`).val(String(unit)).trigger('change.select2');
    rowTotal(i);
  }

  function addRow() {
    const i = rowIdx;
    const productOpts = products.map(p =>
      `<option value="${p.id}" data-barcode="${p.barcode ?? ''}"
               data-price="${p.selling_price ?? 0}"
               data-unit="${p.measurement_unit ?? ''}">${p.name}</option>`
    ).join('');

    const unitOpts = `
      @foreach($units as $unit)
        <option value="{{ $unit->id }}">{{ $unit->shortcode }}</option>
      @endforeach
    `;

    const row = `
      <tr>
        <td><input type="text" name="items[${i-1}][barcode]" id="barcode_${i}"
                   class="form-control product-code"></td>
        <td>
          <select name="items[${i-1}][product_id]" id="product_${i}"
                  data-plugin-selecttwo class="form-control select2-js product-select"
                  onchange="onProductChange(this)">
            <option value="">Select Item</option>${productOpts}
          </select>
        </td>
        <td>
          <select name="items[${i-1}][variation_id]" id="variation_${i}"
                  data-plugin-selecttwo class="form-control select2-js variation-select">
            <option value="">No Variation</option>
          </select>
        </td>
        <td><input type="number" name="items[${i-1}][quantity]" id="qty_${i}"
                   class="form-control quantity" value="0" step="any"
                   onchange="rowTotal(${i})"></td>
        <td>
          <select name="items[${i-1}][unit]" id="unit_${i}" data-plugin-selecttwo class="form-control select2-js" required>
            <option value="">Unit</option>${unitOpts}
          </select>
        </td>
        <td><input type="number" name="items[${i-1}][sale_price]" id="price_${i}"
                   class="form-control" value="0" step="any" onchange="rowTotal(${i})"></td>
        <td><input type="number" name="items[${i-1}][discount]" id="disc_${i}"
                   class="form-control" value="0" step="any" min="0" max="100"
                   onchange="rowTotal(${i})"></td>
        <td><input type="number" id="amount_${i}" class="form-control" value="0" disabled></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
            <i class="fas fa-times"></i>
          </button>
        </td>
      </tr>`;

    $('#SaleTableBody').append(row);
    $(`#product_${i}, #variation_${i}, #unit_${i}`)
      .select2({ width: '100%', dropdownAutoWidth: true });
    rowIdx++;
  }

  function removeRow(button) {
    if ($('#SaleTableBody tr').length > 1) {
      $(button).closest('tr').remove();
      calcNet();
    }
  }

  function rowTotal(i) {
    const price = parseFloat($(`#price_${i}`).val()) || 0;
    const qty   = parseFloat($(`#qty_${i}`).val())   || 0;
    const disc  = parseFloat($(`#disc_${i}`).val())  || 0;
    const amt   = (price - (price * disc / 100)) * qty;
    $(`#amount_${i}`).val(amt.toFixed(2));
    calcNet();
  }

  function calcNet() {
    let sub = 0;
    $('input[id^="amount_"]').each(function () {
      sub += parseFloat($(this).val()) || 0;
    });
    const disc = parseFloat($('#bill_discount').val()) || 0;
    const conv = parseFloat($('#conveyance').val())    || 0;
    const net  = sub - disc + conv;
    $('#subTotal').val(sub.toFixed(2));
    $('#netDisplay').text(net.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    $('#net_amount').val(net.toFixed(2));
  }

  function payFull() {
    const net = parseFloat($('#net_amount').val()) || 0;
    $('#payment_amount').val(net.toFixed(2));
  }

  function loadVariations(row, productId, preselectId = null) {
    const $var = row.find('.variation-select');
    $var.html('<option value="">Loading...</option>').prop('disabled', true);
    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $var.html(opts).prop('disabled', false);
      if ($var.hasClass('select2-hidden-accessible')) $var.select2('destroy');
      $var.select2({ width: '100%', dropdownAutoWidth: true });
      if (preselectId) $var.val(String(preselectId)).trigger('change');
    });
  }
</script>
@endsection