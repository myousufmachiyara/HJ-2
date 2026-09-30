{{-- Stock Movement form (create + edit): any location → any location --}}
@php
  $t     = $transfer ?? null;
  $lines = old('items', $t
      ? $t->details->map(fn($d) => ['product_id' => $d->product_id, 'variation_id' => $d->variation_id, 'quantity' => $d->quantity,
                                    'variation_label' => $d->variation->sku ?? null])->all()
      : [['product_id' => '', 'variation_id' => '', 'quantity' => '']]);
@endphp

@if ($errors->any())
  <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<section class="card mb-3">
  <header class="card-header"><h2 class="card-title">{{ $t ? 'Edit Stock Movement #' . $t->id : 'New Stock Movement' }}</h2></header>
  <div class="card-body">
    <div class="row">
      <div class="col-md-2">
        <label>Date <span class="text-danger">*</span></label>
        <input type="date" name="date" class="form-control" value="{{ old('date', $t->date ?? date('Y-m-d')) }}" required>
      </div>
      <div class="col-md-3">
        <label>From <span class="text-danger">*</span></label>
        @include('partials.location-select', ['name' => 'from_location_id', 'groups' => $locationGroups, 'selected' => old('from_location_id', $t->from_location_id ?? $defaultLocationId)])
      </div>
      <div class="col-md-3">
        <label>To <span class="text-danger">*</span></label>
        @include('partials.location-select', ['name' => 'to_location_id', 'groups' => $locationGroups, 'selected' => old('to_location_id', $t->to_location_id ?? '')])
      </div>
      <div class="col-md-4">
        <label>Remarks</label>
        <input type="text" name="remarks" class="form-control" value="{{ old('remarks', $t->remarks ?? '') }}">
      </div>
    </div>
    <small class="text-muted d-block mt-2">
      Warehouse → Marketplace = Delivery Challan · Marketplace → Warehouse = Return DC · Warehouse → CMT (e.g. fabric) = Transfer.
      Stock value moves between the two locations' stock accounts at average cost.
    </small>
  </div>
</section>

<section class="card">
  <header class="card-header d-flex justify-content-between align-items-center">
    <h2 class="card-title">Items</h2>
    <div class="btn-group">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadTemplate()"><i class="fas fa-file-download"></i> Template</button>
      <button type="button" class="btn btn-outline-success btn-sm" onclick="$('#importFile').click()"><i class="fas fa-file-upload"></i> Import Excel</button>
      <input type="file" id="importFile" accept=".xlsx,.xls,.csv" style="display:none" onchange="importItems(event)">
    </div>
  </header>
  <div class="card-body">
    <table class="table table-bordered table-sm" id="itemTable">
      <thead class="table-light">
        <tr><th width="16%">Barcode / Code</th><th>Product</th><th width="22%">Variation</th><th width="10%">Available</th><th width="11%">Qty</th><th width="4%"></th></tr>
      </thead>
      <tbody id="itemBody">
        @foreach($lines as $i => $line)
          <tr>
            <td><input type="text" class="form-control product-code" placeholder="Scan / enter code"></td>
            <td>
              <select name="items[{{ $i }}][product_id]" class="form-control select2-js product-select" required>
                <option value="">Select Product</option>
                @foreach($products as $p)
                  <option value="{{ $p->id }}" {{ (string) $line['product_id'] === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
              </select>
            </td>
            <td>
              <select name="items[{{ $i }}][variation_id]" class="form-control select2-js variation-select" data-selected="{{ $line['variation_id'] ?? '' }}">
                <option value="">No Variation</option>
                @if(!empty($line['variation_id']))<option value="{{ $line['variation_id'] }}" selected>{{ $line['variation_label'] ?? $line['variation_id'] }}</option>@endif
              </select>
            </td>
            <td class="available text-end text-muted">-</td>
            <td><input type="number" name="items[{{ $i }}][quantity]" class="form-control quantity" step="any" min="0" value="{{ $line['quantity'] }}" required></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td>
          </tr>
        @endforeach
      </tbody>
      <tfoot><tr><th colspan="4" class="text-end">Total Qty</th><th id="totalQty">0</th><th></th></tr></tfoot>
    </table>
    <button type="button" class="btn btn-success btn-sm" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
  </div>
  <footer class="card-footer text-end">
    <a href="{{ route('stock_transfer.index') }}" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Save Movement</button>
  </footer>
</section>

<template id="rowTemplate">
  <tr>
    <td><input type="text" class="form-control product-code" placeholder="Scan / enter code"></td>
    <td><select name="items[__i__][product_id]" class="form-control product-select" required>
      <option value="">Select Product</option>
      @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
    </select></td>
    <td><select name="items[__i__][variation_id]" class="form-control variation-select"><option value="">No Variation</option></select></td>
    <td class="available text-end text-muted">-</td>
    <td><input type="number" name="items[__i__][quantity]" class="form-control quantity" step="any" min="0" required></td>
    <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td>
  </tr>
</template>

<script>
  let rowIndex = {{ count($lines) }};

  $(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
    $('#itemBody tr').each(function () {
      const pid = $(this).find('.product-select').val();
      if (pid) loadVariations($(this), pid, $(this).find('.variation-select').data('selected'));
    });
    recalc();

    $(document).on('change', '.product-select', function () {
      const row = $(this).closest('tr');
      if ($(this).val()) loadVariations(row, $(this).val(), row.data('preselect') || null);
      row.removeData('preselect');
    });
    $(document).on('change', '.variation-select', function () { showAvailable($(this).closest('tr')); });
    $('#from_location_id').on('change', function () { $('#itemBody tr').each(function () { showAvailable($(this)); }); });
    $(document).on('input', '.quantity', recalc);
    $(document).on('click', '.remove-row', function () { if ($('#itemBody tr').length > 1) { $(this).closest('tr').remove(); recalc(); } });
    $(document).on('keydown', '.product-code', function (e) { if (e.which === 13) { e.preventDefault(); scan($(this)); } });
    $(document).on('keypress', '.quantity', function (e) { if (e.which === 13) { e.preventDefault(); nextScanRow(); } });
  });

  function addRow() {
    const $row = $($('#rowTemplate').html().replaceAll('__i__', rowIndex++));
    $('#itemBody').append($row);
    $row.find('select').select2({ width: '100%', dropdownAutoWidth: true });
    return $row;
  }
  function emptyOrNewRow() {
    const $last = $('#itemBody tr').last();
    return $last.find('.product-select').val() ? addRow() : $last;
  }
  function nextScanRow() { emptyOrNewRow().find('.product-code').focus(); }
  function findRow(pid, vid) {
    let m = null;
    $('#itemBody tr').each(function () {
      if (String($(this).find('.product-select').val()) === String(pid) &&
          String($(this).find('.variation-select').val() || '') === String(vid || '')) { m = $(this); return false; }
    });
    return m;
  }
  function setLine(pid, vid, qty, add = true) {
    const existing = findRow(pid, vid);
    if (existing) {
      const $q = existing.find('.quantity');
      $q.val((add ? (parseFloat($q.val()) || 0) : 0) + qty);
      existing.addClass('table-success'); setTimeout(() => existing.removeClass('table-success'), 500);
      recalc();
      return existing;
    }
    const row = emptyOrNewRow();
    row.data('preselect', vid);
    row.find('.product-select').val(pid).trigger('change');
    row.find('.quantity').val(qty);
    recalc();
    return row;
  }
  function scan($input) {
    const code = $input.val().trim();
    if (!code) return;
    $.get('/get-product-by-code/' + encodeURIComponent(code), function (res) {
      $input.val('');
      if (!res || !res.success) { alert((res && res.message) || 'Not found'); return; }
      const pid = res.type === 'variation' ? res.variation.product_id : res.product.id;
      const vid = res.type === 'variation' ? res.variation.id : null;
      setLine(pid, vid, 1);
      nextScanRow();
    });
  }
  function loadVariations(row, productId, preselectId = null) {
    const $var = row.find('.variation-select');
    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $var.html(opts);
      if (preselectId) $var.val(String(preselectId));
      $var.trigger('change.select2');
      showAvailable(row);
    });
  }
  function showAvailable(row) {
    const loc = $('#from_location_id').val(), pid = row.find('.product-select').val();
    const $cell = row.find('.available');
    if (!loc || !pid) { $cell.text('-'); return; }
    $.get('{{ route('stock.available') }}', { location_id: loc, product_id: pid, variation_id: row.find('.variation-select').val() || '' }, function (r) {
      $cell.text(parseFloat(r.qty).toFixed(2)).toggleClass('text-danger', r.qty <= 0);
    });
  }
  function recalc() {
    let t = 0; $('.quantity').each(function () { t += parseFloat($(this).val()) || 0; });
    $('#totalQty').text(t.toFixed(2));
  }

  // ── Excel import: Item Code (barcode or SKU) | Quantity ──
  function downloadTemplate() {
    const ws = XLSX.utils.aoa_to_sheet([['Item Code (Barcode or SKU)', 'Quantity'], ['KRT-00001-M', 5]]);
    ws['!cols'] = [{ wch: 28 }, { wch: 10 }];
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Items');
    XLSX.writeFile(wb, 'stock_movement_import_template.xlsx');
  }
  function importItems(event) {
    const file = event.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('file', file); fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
    $.ajax({ url: '{{ route('stock_transfer.import_items') }}', method: 'POST', data: fd, processData: false, contentType: false,
      success: function (res) {
        if (!res.success) { alert(res.message || 'Import failed'); return; }
        res.items.forEach(it => setLine(it.product_id, it.variation_id, parseFloat(it.quantity) || 0));
        alert('Imported ' + res.items.length + ' line(s).' + (res.errors.length ? '\n\nSkipped:\n' + res.errors.join('\n') : ''));
      },
      error: xhr => alert('Import failed: ' + (xhr.responseJSON?.message || xhr.statusText)),
      complete: () => { event.target.value = ''; } });
  }
</script>
