@extends('layouts.app')

@section('title', 'Stock Transfer | Create')

@section('content')
<div class="row">
  <form action="{{ route('stock_transfer.store') }}" method="POST">
    @csrf

    <div class="col-12 mb-2">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Create Stock Transfer</h2>
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif
        </header>
        <div class="card-body">
          <div class="row mb-2">
            <div class="col-md-3">
              <label>Type</label>
              <select name="type" id="transferType" class="form-control select2-js" required>
                <option value="transfer">Internal Transfer</option>
                <option value="dc">Delivery Challan (to Customer)</option>
                <option value="return_dc">Return DC (from Customer)</option>
              </select>
            </div>
            <div class="col-md-3">
              <label>Transfer Date</label>
              <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required />
            </div>
            <div class="col-md-3">
              <label>From Location</label>
              <select name="from_location_id" class="form-control select2-js" required>
                <option value="">Select From Location</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}" data-customer="{{ $loc->chart_of_account_id ? 1 : 0 }}">
                    {{ $loc->name }}@if($loc->chart_of_account_id) (Customer)@endif
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>To Location</label>
              <select name="to_location_id" class="form-control select2-js" required>
                <option value="">Select To Location</option>
                @foreach($locations as $loc)
                  <option value="{{ $loc->id }}" data-customer="{{ $loc->chart_of_account_id ? 1 : 0 }}">
                    {{ $loc->name }}@if($loc->chart_of_account_id) (Customer)@endif
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3 mt-2">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="col-12">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Transfer Items</h2>
        </header>
        <div class="card-body">
          <table class="table table-bordered" id="itemTable">
            <thead>
              <tr>
                <th width="15%">Item Code</th>
                <th>Product</th>
                <th>Variation</th>
                <th width="12%">Qty</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><input type="text" class="form-control product-code" placeholder="Scan/Enter Code"></td>
                <td>
                  <select name="items[0][product_id]" class="form-control select2-js product-select" required>
                    <option value="">Select Product</option>
                    @foreach($products as $product)
                      <option value="{{ $product->id }}" data-price="{{ $product->selling_price }}">{{ $product->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[0][variation_id]" class="form-control select2-js variation-select">
                    <option value="">Select Variation</option>
                  </select>
                </td>
                <td><input type="number" name="items[0][quantity]" class="form-control quantity" step="any" required></td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
              </tr>
            </tbody>
          </table>
          <button type="button" class="btn btn-success btn-sm" onclick="addRow()">+ Add Item</button>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('stock_transfer.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">Save Transfer</button>
        </footer>
      </section>
    </div>
  </form>
</div>

<script>
  let rowIndex = $('#itemTable tbody tr').length || 1;

  // Scan step: qty added each time the same code is (re)scanned.
  const SCAN_INCREMENT = 1;

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // 🔹 Manual Product selection flow
    $(document).on('change', '.product-select', function () {
      const row = $(this).closest('tr');
      const productId = $(this).val();
      const preselectVariationId = $(this).data('preselectVariationId') || null;
      $(this).removeData('preselectVariationId');

      if (productId) {
        loadVariations(row, productId, preselectVariationId);
      } else {
        row.find('.variation-select')
          .html('<option value="">Select Variation</option>')
          .prop('disabled', false)
          .trigger('change');
      }
    });

    // 🔹 Hardware scanner sends Enter after the code — intercept so it doesn't
    //    submit the form, and treat it as "scan complete".
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

      $.ajax({
        url: '/get-product-by-code/' + encodeURIComponent(barcode),
        method: 'GET',
        success: function (res) {
          if (!res || !res.success) {
            alert((res && res.message) || 'Not found');
            $input.val('').focus();
            return;
          }

          if (res.type === 'variation') {
            handleScannedVariation(row, res.variation.product_id, res.variation.id, res.variation.sku);
          } else if (res.type === 'product') {
            handleScannedProduct(row, res.product);
          }
        },
        error: function () {
          alert('Error fetching product details.');
        }
      });
    });

    // Manual typing + tab-out: route through the same path, but only if the row
    // hasn't already been filled (prevents double-processing after Enter).
    $(document).on('blur', '.product-code', function () {
      const barcode = $(this).val().trim();
      if (barcode && !$(this).closest('tr').find('.product-select').val()) {
        $(this).trigger('scan:submit');
      }
    });

    // 🔹 Enter on Qty → advance to next scan box (no empty-row pile-up)
    $(document).on('keypress', '.quantity', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        const $last = $('#itemTable tbody tr').last();
        if (!rowIsEmpty($last)) addRow();
        focusLastScanBox();
      }
    });
  });

  // ── Duplicate-detection helpers ──────────────────────────────────────
  function findRowByVariation(productId, variationId) {
    let match = null;
    $('#itemTable tbody tr').each(function () {
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
    $('#itemTable tbody tr').last().find('.product-code').focus();
  }

  function appendAndGetRow() {
    addRow();
    return $('#itemTable tbody tr').last();
  }

  function openNextScanRow() {
    const $last = $('#itemTable tbody tr').last();
    const $next = rowIsEmpty($last) ? $last : appendAndGetRow();
    $next.find('.product-code').focus();
  }

  // ── Scan handlers ────────────────────────────────────────────────────
  function handleScannedVariation(scanRow, productId, variationId, sku) {
    const existing = findRowByVariation(productId, variationId);

    if (existing) {
      const $qty = existing.find('.quantity');
      const cur  = parseFloat($qty.val()) || 0;
      $qty.val(cur + SCAN_INCREMENT).trigger('change');
      flashRow(existing);
      if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
      else focusLastScanBox();
      return;
    }

    const targetRow = rowIsEmpty(scanRow) ? scanRow : appendAndGetRow();
    targetRow.find('.product-select').val(productId).trigger('change.select2');
    targetRow.find('.variation-select')
      .html(`<option value="${variationId}" selected>${sku}</option>`)
      .prop('disabled', false)
      .trigger('change');
    targetRow.find('.quantity').val(SCAN_INCREMENT).trigger('change');

    // clear scan box on the row we just filled (if it was the fresh one)
    targetRow.find('.product-code').val('');
    openNextScanRow();
  }

  function handleScannedProduct(scanRow, product) {
    const existing = findRowByVariation(product.id, null);

    if (existing) {
      const $qty = existing.find('.quantity');
      const cur  = parseFloat($qty.val()) || 0;
      $qty.val(cur + SCAN_INCREMENT).trigger('change');
      flashRow(existing);
      if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
      else focusLastScanBox();
      return;
    }

    const targetRow = rowIsEmpty(scanRow) ? scanRow : appendAndGetRow();
    targetRow.find('.product-select').val(product.id).trigger('change.select2');
    loadVariations(targetRow, product.id);
    targetRow.find('.quantity').val(SCAN_INCREMENT).trigger('change');
    targetRow.find('.product-code').val('');
    openNextScanRow();
  }

  // 🔹 Add Row
  function addRow() {
    const idx = rowIndex++;
    const rowHtml = `
      <tr>
        <td><input type="text" class="form-control product-code" placeholder="Scan/Enter Code"></td>
        <td>
          <select name="items[${idx}][product_id]" class="form-control select2-js product-select" required>
            <option value="">Select Product</option>
            @foreach($products as $product)
              <option value="{{ $product->id }}" data-price="{{ $product->selling_price }}">{{ $product->name }}</option>
            @endforeach
          </select>
        </td>
        <td>
          <select name="items[${idx}][variation_id]" class="form-control select2-js variation-select">
            <option value="">Select Variation</option>
          </select>
        </td>
        <td><input type="number" name="items[${idx}][quantity]" class="form-control quantity" step="any" required></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
      </tr>
    `;
    $('#itemTable tbody').append(rowHtml);
    const $newRow = $('#itemTable tbody tr').last();
    $newRow.find('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
  }

  // 🔹 Remove Row
  function removeRow(btn) {
    $(btn).closest('tr').remove();
  }

  // 🔹 Load Variations
  function loadVariations(row, productId, preselectVariationId = null) {
    const $variationSelect = row.find('.variation-select');
    $variationSelect.html('<option value="">Loading...</option>').prop('disabled', false);

    $.get(`/product/${productId}/variations`, function (data) {
      let options = '<option value="">Select Variation</option>';
      (data.variation || []).forEach(v => {
        options += `<option value="${v.id}">${v.sku}</option>`;
      });
      $variationSelect.html(options).prop('disabled', false);

      if ($variationSelect.hasClass('select2-hidden-accessible')) {
        $variationSelect.select2('destroy');
      }
      $variationSelect.select2({ width: '100%', dropdownAutoWidth: true });

      if (preselectVariationId) {
        $variationSelect.val(String(preselectVariationId)).trigger('change');
      }
    });
  }
</script>


@endsection