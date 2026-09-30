@php
  $adj   = $adjustment ?? null;
  $lines = old('items', $adj
      ? $adj->items->map(fn($i) => ['product_id' => $i->product_id, 'variation_id' => $i->variation_id,
          'quantity' => $adj->type === 'count' ? $i->counted_qty : $i->quantity, 'unit_cost' => $i->unit_cost,
          'variation_label' => $i->variation->sku ?? null])->all()
      : [['product_id' => '', 'variation_id' => '', 'quantity' => '', 'unit_cost' => '']]);
  $type  = old('type', $adj->type ?? request('type', 'opening'));
@endphp

@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<section class="card mb-3">
  <header class="card-header"><h2 class="card-title">{{ $adj ? 'Edit ' . $adj->adj_no : 'New Stock Adjustment' }}</h2></header>
  <div class="card-body">
    <div class="row">
      <div class="col-md-2">
        <label>Date <span class="text-danger">*</span></label>
        <input type="date" name="date" class="form-control" value="{{ old('date', $adj ? \Carbon\Carbon::parse($adj->date)->format('Y-m-d') : date('Y-m-d')) }}" required>
      </div>
      <div class="col-md-3">
        <label>Type <span class="text-danger">*</span></label>
        <select name="type" id="adjType" class="form-control" required>
          @foreach($types as $k => $label)<option value="{{ $k }}" {{ $type === $k ? 'selected' : '' }}>{{ $label }}</option>@endforeach
        </select>
      </div>
      <div class="col-md-3">
        <label>Location <span class="text-danger">*</span></label>
        @include('partials.location-select', ['name' => 'location_id', 'groups' => $locationGroups, 'selected' => old('location_id', $adj->location_id ?? $defaultLocationId)])
      </div>
      <div class="col-md-4">
        <label>Remarks</label>
        <input type="text" name="remarks" class="form-control" value="{{ old('remarks', $adj->remarks ?? '') }}">
      </div>
    </div>
    <div class="alert alert-info mt-3 mb-0 py-2 small" id="typeHelp"></div>
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
    <table class="table table-bordered table-sm">
      <thead class="table-light">
        <tr><th width="15%">Barcode / Code</th><th>Product</th><th width="20%">Variation</th><th width="10%">In System</th>
            <th width="11%" id="qtyHeader">Qty</th><th width="11%" class="cost-col">Unit Cost</th><th width="4%"></th></tr>
      </thead>
      <tbody id="itemBody">
        @foreach($lines as $i => $line)
          <tr>
            <td><input type="text" class="form-control product-code" placeholder="Scan / code"></td>
            <td><select name="items[{{ $i }}][product_id]" class="form-control select2-js product-select" required>
              <option value="">Select Product</option>
              @foreach($products as $p)<option value="{{ $p->id }}" {{ (string) $line['product_id'] === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>@endforeach
            </select></td>
            <td><select name="items[{{ $i }}][variation_id]" class="form-control select2-js variation-select" data-selected="{{ $line['variation_id'] ?? '' }}">
              <option value="">No Variation</option>
              @if(!empty($line['variation_id']))<option value="{{ $line['variation_id'] }}" selected>{{ $line['variation_label'] ?? $line['variation_id'] }}</option>@endif
            </select></td>
            <td class="available text-end text-muted">-</td>
            <td><input type="number" name="items[{{ $i }}][quantity]" class="form-control quantity" step="any" value="{{ $line['quantity'] }}" required></td>
            <td class="cost-col"><input type="number" name="items[{{ $i }}][unit_cost]" class="form-control" step="any" min="0" value="{{ $line['unit_cost'] ?? '' }}" placeholder="auto"></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <button type="button" class="btn btn-success btn-sm" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
  </div>
  <footer class="card-footer text-end">
    <a href="{{ route('stock_adjustments.index') }}" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Save</button>
  </footer>
</section>

<template id="rowTemplate">
  <tr>
    <td><input type="text" class="form-control product-code" placeholder="Scan / code"></td>
    <td><select name="items[__i__][product_id]" class="form-control product-select" required>
      <option value="">Select Product</option>
      @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
    </select></td>
    <td><select name="items[__i__][variation_id]" class="form-control variation-select"><option value="">No Variation</option></select></td>
    <td class="available text-end text-muted">-</td>
    <td><input type="number" name="items[__i__][quantity]" class="form-control quantity" step="any" required></td>
    <td class="cost-col"><input type="number" name="items[__i__][unit_cost]" class="form-control" step="any" min="0" placeholder="auto"></td>
    <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td>
  </tr>
</template>

<script>
  let rowIndex = {{ count($lines) }};
  const HELP = {
    opening: '<b>Opening Stock</b>: enter the quantity you already have at this location on go-live (warehouse, marketplace, or fabric lying at a CMT). Unit cost blank = product cost (CMT + fabric for FG, cost price for fabric). Posts Dr Stock @ location / Cr Opening Stock Equity.',
    count: '<b>Physical Count</b>: enter the quantity you actually counted. The software works out the difference to what it currently shows (column "In System") and posts only the difference as excess / shortage. Use this to fix negative stock after go-live.',
    adjustment: '<b>Manual Adjustment</b>: enter + to add or − to remove (damaged, lost, found). Posts to Stock Adjustment (Shortage / Excess).'
  };
  const QTY_LABEL = { opening: 'Opening Qty', count: 'Counted Qty', adjustment: '+/− Qty' };

  $(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
    $('#itemBody tr').each(function () {
      const pid = $(this).find('.product-select').val();
      if (pid) loadVariations($(this), pid, $(this).find('.variation-select').data('selected'));
    });
    $('#adjType').on('change', applyType); applyType();

    $(document).on('change', '.product-select', function () {
      const row = $(this).closest('tr');
      if ($(this).val()) loadVariations(row, $(this).val(), row.data('preselect') || null);
      row.removeData('preselect');
    });
    $(document).on('change', '.variation-select', function () { showAvailable($(this).closest('tr')); });
    $('#location_id').on('change', () => $('#itemBody tr').each(function () { showAvailable($(this)); }));
    $(document).on('click', '.remove-row', function () { if ($('#itemBody tr').length > 1) $(this).closest('tr').remove(); });
    $(document).on('keydown', '.product-code', function (e) { if (e.which === 13) { e.preventDefault(); scan($(this)); } });
    $(document).on('keypress', '.quantity', function (e) { if (e.which === 13) { e.preventDefault(); emptyOrNewRow().find('.product-code').focus(); } });
  });

  function applyType() {
    const t = $('#adjType').val();
    $('#typeHelp').html(HELP[t]);
    $('#qtyHeader').text(QTY_LABEL[t]);
    $('.cost-col').toggle(t === 'opening');
  }
  function addRow() {
    const $row = $($('#rowTemplate').html().replaceAll('__i__', rowIndex++));
    $('#itemBody').append($row);
    $row.find('select').select2({ width: '100%', dropdownAutoWidth: true });
    applyType();
    return $row;
  }
  function emptyOrNewRow() {
    const $last = $('#itemBody tr').last();
    return $last.find('.product-select').val() ? addRow() : $last;
  }
  function findRow(pid, vid) {
    let m = null;
    $('#itemBody tr').each(function () {
      if (String($(this).find('.product-select').val()) === String(pid) &&
          String($(this).find('.variation-select').val() || '') === String(vid || '')) { m = $(this); return false; }
    });
    return m;
  }
  function setLine(pid, vid, qty, cost) {
    let row = findRow(pid, vid);
    if (row) {
      const $q = row.find('.quantity'); $q.val((parseFloat($q.val()) || 0) + qty);
    } else {
      row = emptyOrNewRow();
      row.data('preselect', vid);
      row.find('.product-select').val(pid).trigger('change');
      row.find('.quantity').val(qty);
    }
    if (cost !== null && cost !== undefined && cost !== '') row.find('input[name$="[unit_cost]"]').val(cost);
  }
  function scan($input) {
    const code = $input.val().trim(); if (!code) return;
    $.get('/get-product-by-code/' + encodeURIComponent(code), function (res) {
      $input.val('');
      if (!res || !res.success) { alert((res && res.message) || 'Not found'); return; }
      setLine(res.type === 'variation' ? res.variation.product_id : res.product.id, res.type === 'variation' ? res.variation.id : null, 1);
      emptyOrNewRow().find('.product-code').focus();
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
    const loc = $('#location_id').val(), pid = row.find('.product-select').val();
    if (!loc || !pid) { row.find('.available').text('-'); return; }
    $.get('{{ route('stock.available') }}', { location_id: loc, product_id: pid, variation_id: row.find('.variation-select').val() || '' },
      r => row.find('.available').text(parseFloat(r.qty).toFixed(2)));
  }
  function downloadTemplate() {
    const ws = XLSX.utils.aoa_to_sheet([['Item Code (Barcode or SKU)', 'Quantity', 'Unit Cost'], ['KRT-00001-M', 25, ''], ['FAB-00001', 1200, 350]]);
    ws['!cols'] = [{ wch: 28 }, { wch: 10 }, { wch: 10 }];
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Items');
    XLSX.writeFile(wb, 'stock_adjustment_template.xlsx');
  }
  function importItems(event) {
    const file = event.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('file', file); fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
    $.ajax({ url: '{{ route('stock_adjustments.import_items') }}', method: 'POST', data: fd, processData: false, contentType: false,
      success: function (res) {
        if (!res.success) { alert(res.message || 'Import failed'); return; }
        res.items.forEach(it => setLine(it.product_id, it.variation_id, parseFloat(it.quantity) || 0, it.price));
        alert('Imported ' + res.items.length + ' line(s).' + (res.errors.length ? '\n\nSkipped:\n' + res.errors.join('\n') : ''));
      },
      error: xhr => alert('Import failed: ' + (xhr.responseJSON?.message || xhr.statusText)),
      complete: () => { event.target.value = ''; } });
  }
</script>
