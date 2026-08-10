@extends('layouts.app')
@section('title', 'Production | Order Receiving')

@section('content')
<div class="row">
  <form action="{{ route('production_receiving.store') }}" method="POST" enctype="multipart/form-data">
    @csrf

    @if($errors->any())
      <div class="col-12">
        <div class="alert alert-danger">
          <ul class="mb-0">
            @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      </div>
    @endif

    @if(session('error'))
      <div class="col-12">
        <div class="alert alert-danger">{{ session('error') }}</div>
      </div>
    @endif

    {{-- Header --}}
    <div class="col-12 mb-4">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">New Production Receiving</h2>
        </header>
        <div class="card-body">
          <div class="row">
            <div class="col-md-2">
              <label>GRN #</label>
              <input type="text" class="form-control" placeholder="Auto-generated" readonly>
            </div>
            <div class="col-md-2">
              <label>Receiving Date <span class="text-danger">*</span></label>
              <input type="date" name="rec_date" class="form-control"
                     value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3">
              <label>Production Order</label>
              <select name="production_id" data-plugin-selecttwo class="form-control select2-js">
                <option value="" {{ empty($selectedProductionId) ? 'selected' : '' }}>
                  -- No Production Order --
                </option>
                @foreach($productions as $prod)
                  <option value="{{ $prod->id }}"
                    {{ $selectedProductionId == $prod->id ? 'selected' : '' }}>
                    #{{ $prod->id }} — {{ $prod->vendor->name ?? '' }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>Vendor <span class="text-danger">*</span></label>
              <select name="vendor_id" data-plugin-selecttwo class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach($accounts as $vendor)
                  <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" placeholder="Optional">
            </div>
          </div>
        </div>
      </section>
    </div>

    {{-- Items --}}
    <div class="col-12 mb-4">
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Received Items</h2>
          <button type="button" class="btn btn-success btn-sm" id="addRowBtn">
            <i class="fas fa-plus me-1"></i> Add Row
          </button>
        </header>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-sm" id="itemTable">
              <thead class="table-light">
                <tr>
                  <th width="12%">Barcode / Code</th>
                  <th>Item</th>
                  <th>Variation</th>
                  <th width="10%">CMT Cost</th>
                  <th width="10%">Received Qty</th>
                  <th>Remarks</th>
                  <th width="10%">Total</th>
                  <th width="5%"></th>
                </tr>
              </thead>
              <tbody id="receivingBody">
                <tr>
                  <td><input type="text" class="form-control product-code" placeholder="Scan barcode"></td>
                  <td>
                    <select name="item_details[0][product_id]"
                    data-plugin-selecttwo class="form-control select2-js product-select" required>
                      <option value="">Select Item</option>
                      @foreach($products as $item)
                        <option value="{{ $item->id }}"
                                data-cmt-cost="{{ $item->cmt_cost }}"
                                data-barcode="{{ $item->barcode }}">
                          {{ $item->name }}
                        </option>
                      @endforeach
                    </select>
                  </td>
                  <td>
                    <select name="item_details[0][variation_id]"
                            data-plugin-selecttwo class="form-control select2-js variation-select">
                      <option value="">No Variation</option>
                    </select>
                  </td>
                  <td>
                    <input type="number" name="item_details[0][manufacturing_cost]"
                           class="form-control manufacturing_cost" step="any" value="0">
                  </td>
                  <td>
                    <input type="number" name="item_details[0][received_qty]"
                           class="form-control received-qty" step="any" value="0" required>
                  </td>
                  <td>
                    <input type="text" name="item_details[0][remarks]" class="form-control">
                  </td>
                  <td>
                    <input type="number" class="form-control row-total" step="any" value="0" readonly>
                  </td>
                  <td>
                    <button type="button" class="btn btn-danger btn-sm remove-row-btn">
                      <i class="fas fa-times"></i>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <hr>
          <div class="row align-items-end">
            <div class="col-md-2">
              <label>Total Pcs</label>
              <input type="text" class="form-control" id="total_pcs" disabled>
              <input type="hidden" name="total_pcs" id="total_pcs_val">
            </div>
            <div class="col-md-2">
              <label>Sub Total</label>
              <input type="text" class="form-control" id="total_amt" disabled>
            </div>
            <div class="col-md-2">
              <label>Conveyance</label>
              <input type="number" name="convance_charges" id="convance_charges"
                     class="form-control" value="0" step="any" oninput="recalcSummary()">
            </div>
            <div class="col-md-2">
              <label>Discount</label>
              <input type="number" name="bill_discount" id="bill_discount"
                     class="form-control" value="0" step="any" oninput="recalcSummary()">
            </div>
            <div class="col-md-4 text-end">
              <h5 class="text-primary mb-0">
                Net Amount: <strong class="text-danger fs-4">
                  PKR <span id="netAmountText">0.00</span>
                </strong>
              </h5>
            </div>
          </div>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('production_receiving.index') }}" class="btn btn-danger">Discard</a>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Save Receiving
          </button>
        </footer>
      </section>
    </div>

  </form>
</div>

<script>
  const SCAN_INCREMENT = 1;

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // Product select change → auto-fill CMT cost from product (same for all variations)
    $(document).on('change', '.product-select', function () {
      const row       = $(this).closest('tr');
      const productId = $(this).val();
      const cmtCost   = $(this).find(':selected').data('cmt-cost') || 0;
      row.find('.manufacturing_cost').val(parseFloat(cmtCost).toFixed(2));
      if (productId) loadVariations(row, productId);
      recalcRow(row);
      recalcSummary();
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
        if (!res || !res.success) {
          alert((res && res.message) || 'Product not found');
          $input.val('').focus();
          return;
        }

        if (res.type === 'variation') {
          const v = res.variation;
          handleScannedVariation(row, v.product_id, v.id, v.sku, v.cmt_cost);
        } else if (res.type === 'product') {
          handleScannedProduct(row, res.product);
        }
      }).fail(() => alert('Error fetching product.'));
    });

    // Manual typing + tab-out: only if product not chosen yet
    $(document).on('blur', '.product-code', function () {
      const barcode = $(this).val().trim();
      if (barcode && !$(this).closest('tr').find('.product-select').val()) {
        $(this).trigger('scan:submit');
      }
    });

    // Qty or cost change → recalc
    $(document).on('input', '.received-qty, .manufacturing_cost', function () {
      recalcRow($(this).closest('tr'));
      recalcSummary();
    });

    // Enter on qty → advance to next scan box (no empty-row pile-up)
    $(document).on('keypress', '.received-qty', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        const $last = $('#receivingBody tr').last();
        if (!rowIsEmpty($last)) addRow();
        focusLastScanBox();
      }
    });

    // Remove row
    $(document).on('click', '.remove-row-btn', function () {
      if ($('#receivingBody tr').length > 1) {
        $(this).closest('tr').remove();
        recalcSummary();
      }
    });

    // Add row button
    $('#addRowBtn').on('click', addRow);
  });

  // ── Duplicate-detection helpers ──────────────────────────────────────
  function findRowByVariation(productId, variationId) {
    let match = null;
    $('#receivingBody tr').each(function () {
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
    $('#receivingBody tr').last().find('.product-code').focus();
  }

  function appendAndGetRow() {
    addRow();
    return $('#receivingBody tr').last();
  }

  function openNextScanRow() {
    const $last = $('#receivingBody tr').last();
    const $next = rowIsEmpty($last) ? $last : appendAndGetRow();
    $next.find('.product-code').focus();
  }

  // ── Scan handlers ────────────────────────────────────────────────────
  function handleScannedVariation(scanRow, productId, variationId, sku, cmtCost) {
    const existing = findRowByVariation(productId, variationId);

    if (existing) {
      // Repeat scan → bump received-qty (CMT cost already set on this row)
      const $qty = existing.find('.received-qty');
      const cur  = parseFloat($qty.val()) || 0;
      $qty.val(cur + SCAN_INCREMENT);
      recalcRow(existing);
      recalcSummary();
      flashRow(existing);
      if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
      else focusLastScanBox();
      return;
    }

    const targetRow = rowIsEmpty(scanRow) ? scanRow : appendAndGetRow();

    targetRow.find('.product-select').val(productId).trigger('change.select2');
    loadVariations(targetRow, productId, variationId);

    // CMT cost: prefer value from endpoint, else product option's data-cmt-cost.
    let useCost = cmtCost;
    if (useCost === undefined || useCost === null) {
      useCost = targetRow.find(`.product-select option[value="${productId}"]`).data('cmt-cost') || 0;
    }
    targetRow.find('.manufacturing_cost').val(parseFloat(useCost).toFixed(2));

    targetRow.find('.received-qty').val(SCAN_INCREMENT);
    recalcRow(targetRow);
    recalcSummary();

    targetRow.find('.product-code').val('');
    openNextScanRow();
  }

  function handleScannedProduct(scanRow, product) {
    const existing = findRowByVariation(product.id, null);

    if (existing) {
      const $qty = existing.find('.received-qty');
      const cur  = parseFloat($qty.val()) || 0;
      $qty.val(cur + SCAN_INCREMENT);
      recalcRow(existing);
      recalcSummary();
      flashRow(existing);
      if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
      else focusLastScanBox();
      return;
    }

    const targetRow = rowIsEmpty(scanRow) ? scanRow : appendAndGetRow();

    targetRow.find('.product-select').val(product.id).trigger('change.select2');
    loadVariations(targetRow, product.id);

    let useCost = product.cmt_cost;
    if (useCost === undefined || useCost === null) {
      useCost = targetRow.find(`.product-select option[value="${product.id}"]`).data('cmt-cost') || 0;
    }
    targetRow.find('.manufacturing_cost').val(parseFloat(useCost).toFixed(2));

    targetRow.find('.received-qty').val(SCAN_INCREMENT);
    recalcRow(targetRow);
    recalcSummary();

    targetRow.find('.product-code').val('');
    openNextScanRow();
  }

  function addRow() {
    const count = $('#receivingBody tr').length;

    const productOpts = `
      <option value="">Select Item</option>
      @foreach($products as $item)
        <option value="{{ $item->id }}"
                data-cmt-cost="{{ $item->cmt_cost }}"
                data-barcode="{{ $item->barcode }}">
          {{ $item->name }}
        </option>
      @endforeach
    `;

    const $row = $(`
      <tr>
        <td><input type="text" class="form-control product-code" placeholder="Scan barcode"></td>
        <td>
          <select name="item_details[${count}][product_id]"
                  data-plugin-selecttwo class="form-control select2-js product-select" required>
            ${productOpts}
          </select>
        </td>
        <td>
          <select name="item_details[${count}][variation_id]"
                  data-plugin-selecttwo class="form-control select2-js variation-select">
            <option value="">No Variation</option>
          </select>
        </td>
        <td><input type="number" name="item_details[${count}][manufacturing_cost]"
                   class="form-control manufacturing_cost" step="any" value="0"></td>
        <td><input type="number" name="item_details[${count}][received_qty]"
                   class="form-control received-qty" step="any" value="0" required></td>
        <td><input type="text" name="item_details[${count}][remarks]" class="form-control"></td>
        <td><input type="number" class="form-control row-total" step="any" value="0" readonly></td>
        <td><button type="button" class="btn btn-danger btn-sm remove-row-btn">
          <i class="fas fa-times"></i>
        </button></td>
      </tr>
    `);

    $('#receivingBody').append($row);
    $row.find('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
  }

  function loadVariations(row, productId, preselectId = null) {
    const $var = row.find('.variation-select');
    const $mc  = row.find('.manufacturing_cost');
    $var.html('<option value="">Loading...</option>');

    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => {
        opts += `<option value="${v.id}">${v.sku}</option>`;
      });
      $var.html(opts);
      if ($var.hasClass('select2-hidden-accessible')) $var.select2('destroy');
      $var.select2({ width: '100%', dropdownAutoWidth: true });

      // CMT cost is product-wise — same for all variations of this product
      if (data.product?.cmt_cost !== undefined) {
        $mc.val(parseFloat(data.product.cmt_cost).toFixed(2));
      }

      if (preselectId) $var.val(String(preselectId)).trigger('change');

      recalcRow(row);
      recalcSummary();
    });
  }

  function recalcRow(row) {
    const qty  = parseFloat(row.find('.received-qty').val())       || 0;
    const cost = parseFloat(row.find('.manufacturing_cost').val())  || 0;
    row.find('.row-total').val((qty * cost).toFixed(2));
  }

  function recalcSummary() {
    let totalPcs = 0, totalAmt = 0;
    $('#receivingBody tr').each(function () {
      totalPcs += parseFloat($(this).find('.received-qty').val()) || 0;
      totalAmt += parseFloat($(this).find('.row-total').val())    || 0;
    });
    $('#total_pcs').val(totalPcs.toFixed(2));
    $('#total_pcs_val').val(totalPcs.toFixed(2));
    $('#total_amt').val(totalAmt.toFixed(2));

    const conv = parseFloat($('#convance_charges').val()) || 0;
    const disc = parseFloat($('#bill_discount').val())    || 0;
    $('#netAmountText').text((totalAmt + conv - disc).toFixed(2)
      .replace(/\B(?=(\d{3})+(?!\d))/g, ','));
  }
</script>
@endsection