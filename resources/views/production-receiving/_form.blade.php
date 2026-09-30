{{--
  Finished Goods Receiving form (create + edit).
  Only receiving details + item / variation / qty. CMT cost and fabric
  consumption are applied automatically from the product on save.
--}}
@php
  $rec   = $receiving ?? null;
  $lines = old('item_details', $rec
      ? $rec->details->map(fn($d) => ['product_id' => $d->product_id, 'variation_id' => $d->variation_id, 'received_qty' => $d->received_qty,
                                      'variation_label' => $d->variation->sku ?? null])->all()
      : [['product_id' => '', 'variation_id' => '', 'received_qty' => '']]);
@endphp

@if($errors->any())
  <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<section class="card mb-3">
  <header class="card-header"><h2 class="card-title">{{ $rec ? 'Edit Receiving ' . $rec->grn_no : 'New Finished Goods Receiving' }}</h2></header>
  <div class="card-body">
    <div class="row">
      <div class="col-md-2">
        <label>GRN #</label>
        <input type="text" class="form-control" value="{{ $rec->grn_no ?? '' }}" placeholder="Auto-generated" readonly>
      </div>
      <div class="col-md-2">
        <label>Receiving Date <span class="text-danger">*</span></label>
        <input type="date" name="rec_date" class="form-control" value="{{ old('rec_date', $rec ? \Carbon\Carbon::parse($rec->rec_date)->format('Y-m-d') : date('Y-m-d')) }}" required>
      </div>
      <div class="col-md-4">
        <label>CMT Vendor <span class="text-danger">*</span></label>
        <select name="vendor_id" class="form-control select2-js" required>
          <option value="">Select Vendor</option>
          @foreach($accounts as $vendor)
            <option value="{{ $vendor->id }}" {{ (string) old('vendor_id', $rec->vendor_id ?? '') === (string) $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>
</section>

<section class="card">
  <header class="card-header d-flex justify-content-between align-items-center">
    <h2 class="card-title">Received Items</h2>
    <button type="button" class="btn btn-success btn-sm" id="addRowBtn"><i class="fas fa-plus me-1"></i> Add Row</button>
  </header>
  <div class="card-body">
    <table class="table table-bordered table-sm" id="itemTable">
      <thead class="table-light">
        <tr>
          <th width="18%">Barcode / Code</th>
          <th>Item</th>
          <th width="25%">Variation</th>
          <th width="12%">Qty</th>
          <th width="5%"></th>
        </tr>
      </thead>
      <tbody id="receivingBody">
        @foreach($lines as $i => $line)
          <tr>
            <td><input type="text" class="form-control product-code" placeholder="Scan barcode"></td>
            <td>
              <select name="item_details[{{ $i }}][product_id]" class="form-control select2-js product-select" required>
                <option value="">Select Item</option>
                @foreach($products as $item)
                  <option value="{{ $item->id }}" {{ (string) $line['product_id'] === (string) $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                @endforeach
              </select>
            </td>
            <td>
              <select name="item_details[{{ $i }}][variation_id]" class="form-control select2-js variation-select" data-selected="{{ $line['variation_id'] ?? '' }}">
                <option value="">No Variation</option>
                @if(!empty($line['variation_id']))
                  <option value="{{ $line['variation_id'] }}" selected>{{ $line['variation_label'] ?? $line['variation_id'] }}</option>
                @endif
              </select>
            </td>
            <td><input type="number" name="item_details[{{ $i }}][received_qty]" class="form-control received-qty" step="any" min="0" value="{{ $line['received_qty'] }}" required></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row-btn"><i class="fas fa-times"></i></button></td>
          </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr><th colspan="3" class="text-end">Total Pcs</th><th id="total_pcs">0</th><th></th></tr>
      </tfoot>
    </table>
  </div>
  <footer class="card-footer text-end">
    <a href="{{ route('production_receiving.index') }}" class="btn btn-danger">Discard</a>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Receiving</button>
  </footer>
</section>

<template id="rowTemplate">
  <tr>
    <td><input type="text" class="form-control product-code" placeholder="Scan barcode"></td>
    <td>
      <select name="item_details[__i__][product_id]" class="form-control product-select" required>
        <option value="">Select Item</option>
        @foreach($products as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach
      </select>
    </td>
    <td><select name="item_details[__i__][variation_id]" class="form-control variation-select"><option value="">No Variation</option></select></td>
    <td><input type="number" name="item_details[__i__][received_qty]" class="form-control received-qty" step="any" min="0" required></td>
    <td><button type="button" class="btn btn-danger btn-sm remove-row-btn"><i class="fas fa-times"></i></button></td>
  </tr>
</template>

<script>
  let rowIndex = {{ count($lines) }};

  $(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // existing rows: load their variation lists (keeping the selected one)
    $('#receivingBody tr').each(function () {
      const pid = $(this).find('.product-select').val();
      if (pid) loadVariations($(this), pid, $(this).find('.variation-select').data('selected'));
    });
    recalc();

    $(document).on('change', '.product-select', function () {
      const row = $(this).closest('tr');
      if ($(this).val()) loadVariations(row, $(this).val(), row.data('preselect') || null);
      row.removeData('preselect');
    });
    $(document).on('input', '.received-qty', recalc);
    $(document).on('click', '.remove-row-btn', function () {
      if ($('#receivingBody tr').length > 1) { $(this).closest('tr').remove(); recalc(); }
    });
    $('#addRowBtn').on('click', addRow);

    // barcode scanner: Enter = scan complete; repeat scan adds +1
    $(document).on('keydown', '.product-code', function (e) {
      if (e.which === 13) { e.preventDefault(); scan($(this)); }
    });
    $(document).on('keypress', '.received-qty', function (e) {
      if (e.which === 13) { e.preventDefault(); nextScanRow(); }
    });
  });

  function addRow() {
    const html = $('#rowTemplate').html().replaceAll('__i__', rowIndex++);
    const $row = $(html);
    $('#receivingBody').append($row);
    $row.find('select').select2({ width: '100%', dropdownAutoWidth: true });
    return $row;
  }

  function nextScanRow() {
    const $last = $('#receivingBody tr').last();
    const $row = $last.find('.product-select').val() ? addRow() : $last;
    $row.find('.product-code').focus();
  }

  function findRow(pid, vid) {
    let match = null;
    $('#receivingBody tr').each(function () {
      if (String($(this).find('.product-select').val()) === String(pid) &&
          String($(this).find('.variation-select').val() || '') === String(vid || '')) { match = $(this); return false; }
    });
    return match;
  }

  function scan($input) {
    const code = $input.val().trim();
    if (!code) return;
    const row = $input.closest('tr');
    $.get('/get-product-by-code/' + encodeURIComponent(code), function (res) {
      if (!res || !res.success) { alert((res && res.message) || 'Product not found'); $input.val('').focus(); return; }
      const pid = res.type === 'variation' ? res.variation.product_id : res.product.id;
      const vid = res.type === 'variation' ? res.variation.id : null;
      const existing = findRow(pid, vid);
      if (existing) {
        const $q = existing.find('.received-qty');
        $q.val((parseFloat($q.val()) || 0) + 1);
        existing.addClass('table-success'); setTimeout(() => existing.removeClass('table-success'), 500);
        $input.val(''); recalc(); $input.focus();
        return;
      }
      const target = row.find('.product-select').val() ? addRow() : row;
      if (target.find(`.product-select option[value="${pid}"]`).length === 0) {
        alert('This item is not a finished good (item type FG).'); $input.val(''); return;
      }
      target.data('preselect', vid);
      target.find('.product-select').val(pid).trigger('change');
      target.find('.received-qty').val(1);
      $input.val('');
      recalc();
      nextScanRow();
    }).fail(() => alert('Error fetching product.'));
  }

  function loadVariations(row, productId, preselectId = null) {
    const $var = row.find('.variation-select');
    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $var.html(opts);
      if (preselectId) $var.val(String(preselectId));
      $var.trigger('change.select2');
    });
  }

  function recalc() {
    let t = 0;
    $('.received-qty').each(function () { t += parseFloat($(this).val()) || 0; });
    $('#total_pcs').text(t.toFixed(2));
  }
</script>
