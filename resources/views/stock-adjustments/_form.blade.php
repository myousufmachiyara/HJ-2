@php
  $adj   = $adjustment ?? null;
  $lines = old('items', $adj
      ? $adj->items->map(fn($i) => ['product_id' => $i->product_id, 'variation_id' => $i->variation_id,
          'quantity' => $adj->type === 'count' ? $i->counted_qty : $i->quantity, 'unit_cost' => $i->unit_cost,
          'variation_label' => $i->variation->sku ?? null])->all()
      : [['product_id' => '', 'variation_id' => '', 'quantity' => '', 'unit_cost' => '']]);
  $type  = old('type', $adj->type ?? request('type', 'opening'));
  // lines already chosen are shown as light rows (text + hidden ids) — no dropdowns,
  // so even thousands of lines open quickly
  $productNames  = $products->pluck('name', 'id');
  $variationSkus = \App\Models\ProductVariation::withTrashed()
      ->whereIn('id', collect($lines)->pluck('variation_id')->filter()->unique())->pluck('sku', 'id');
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
        @include('partials.location-select', ['name' => 'location_id', 'groups' => $locationGroups, 'selected' => old('location_id', $adj->location_id ?? request('location_id', $defaultLocationId))])
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
    <h2 class="card-title">Items <small class="text-muted" id="lineCount"></small></h2>
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
          @if(!empty($line['product_id']))
          <tr class="light-row" data-key="{{ $line['product_id'] }}-{{ $line['variation_id'] ?? '' }}">
            <td class="text-muted small">{{ $variationSkus[$line['variation_id'] ?? 0] ?? '' }}</td>
            <td>{{ $productNames[$line['product_id']] ?? ('#' . $line['product_id']) }}<input type="hidden" name="items[{{ $i }}][product_id]" class="product-select" value="{{ $line['product_id'] }}"></td>
            <td>{{ $variationSkus[$line['variation_id'] ?? 0] ?? ($line['variation_label'] ?? '—') }}<input type="hidden" name="items[{{ $i }}][variation_id]" class="variation-select" value="{{ $line['variation_id'] ?? '' }}"></td>
            <td class="available text-end text-muted">-</td>
            <td><input type="number" name="items[{{ $i }}][quantity]" class="form-control form-control-sm quantity" step="any" value="{{ $line['quantity'] }}" required></td>
            <td class="cost-col"><input type="number" name="items[{{ $i }}][unit_cost]" class="form-control form-control-sm" step="any" min="0" value="{{ $line['unit_cost'] ?? '' }}" placeholder="auto"></td>
            <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td>
          </tr>
          @else
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
          @endif
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

  // quick lookup "productId-variationId" → light row (keeps big imports fast)
  const lineIndex = new Map();
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  $(function () {
    $('#itemBody select.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
    $('#itemBody tr').each(function () {
      if ($(this).hasClass('light-row')) { lineIndex.set(String($(this).data('key')), $(this)); return; }
      const pid = $(this).find('.product-select').val();
      if (pid) loadVariations($(this), pid, $(this).find('.variation-select').data('selected'));
    });
    refreshAvailableAll();
    countLines();
    $('#adjType').on('change', applyType); applyType();

    $(document).on('change', '.product-select', function () {
      const row = $(this).closest('tr');
      if ($(this).val()) loadVariations(row, $(this).val(), row.data('preselect') || null);
      row.removeData('preselect');
    });
    $(document).on('change', '.variation-select', function () { showAvailable($(this).closest('tr')); });
    $('#location_id').on('change', refreshAvailableAll);
    $(document).on('click', '.remove-row', function () {
      const $tr = $(this).closest('tr');
      if ($('#itemBody tr').length <= 1) return;
      if ($tr.hasClass('light-row')) lineIndex.delete(String($tr.data('key')));
      $tr.remove(); countLines();
    });

    // hundreds of lines → send them as one field (servers accept only ~1000 form fields)
    $('#itemBody').closest('form').on('submit', function () {
      const $rows = $('#itemBody tr');
      if ($rows.length <= 100) return true;
      const items = [];
      $rows.each(function () {
        const pid = $(this).find('.product-select').val();
        if (!pid) return;
        items.push({ product_id: pid, variation_id: $(this).find('.variation-select').val() || null,
                     quantity: $(this).find('.quantity').val(), unit_cost: $(this).find('input[name$="[unit_cost]"]').val() || null });
      });
      $(this).find('[name^="items["]').prop('disabled', true);
      $(this).find('input[name="items_json"]').remove();
      $('<input type="hidden" name="items_json">').val(JSON.stringify(items)).appendTo(this);
      return true;
    });
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
    const hit = lineIndex.get(pid + '-' + (vid || ''));
    if (hit) return hit;
    let m = null;
    $('#itemBody tr:not(.light-row)').each(function () {
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
  // Add many imported lines at once as light rows (one DOM insert, no dropdowns, no per-row calls)
  function addLines(items) {
    // merge repeated codes in the sheet first
    const merged = new Map();
    items.forEach(it => {
      const key = it.product_id + '-' + (it.variation_id || '');
      const m = merged.get(key);
      if (m) { m.qty += parseFloat(it.quantity) || 0; if (it.price !== null && it.price !== undefined && it.price !== '') m.cost = it.price; }
      else merged.set(key, { it, qty: parseFloat(it.quantity) || 0, cost: it.price });
    });
    const html = [];
    merged.forEach(({ it, qty, cost }, key) => {
      const pid = it.product_id, vid = it.variation_id || '';
      const $have = lineIndex.get(key);
      if ($have) {
        const $q = $have.find('.quantity'); $q.val((parseFloat($q.val()) || 0) + qty);
        if (cost !== null && cost !== undefined && cost !== '') $have.find('input[name$="[unit_cost]"]').val(cost);
        return;
      }
      const i = rowIndex++;
      html.push(`<tr class="light-row" data-key="${key}">
        <td class="text-muted small">${esc(it.sku)}</td>
        <td>${esc(it.name)}<input type="hidden" name="items[${i}][product_id]" class="product-select" value="${pid}"></td>
        <td>${vid ? esc(it.sku) : '—'}<input type="hidden" name="items[${i}][variation_id]" class="variation-select" value="${vid}"></td>
        <td class="available text-end text-muted">-</td>
        <td><input type="number" name="items[${i}][quantity]" class="form-control form-control-sm quantity" step="any" value="${qty}" required></td>
        <td class="cost-col"><input type="number" name="items[${i}][unit_cost]" class="form-control form-control-sm" step="any" min="0" value="${cost ?? ''}" placeholder="auto"></td>
        <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td></tr>`);
    });
    // drop the empty starter row
    $('#itemBody tr:not(.light-row)').each(function () { if (!$(this).find('.product-select').val()) { $(this).find('select').each(function () { if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy'); }); $(this).remove(); } });
    if (html.length) $('#itemBody').append(html.join(''));
    $('#itemBody tr.light-row').each(function () { const k = String($(this).data('key')); if (!lineIndex.has(k)) lineIndex.set(k, $(this)); });
    if (!$('#itemBody tr').length) addRow();
    applyType(); countLines(); refreshAvailableAll();
  }

  // "In System" for every line in one request
  function refreshAvailableAll() {
    const loc = $('#location_id').val();
    const keys = [];
    $('#itemBody tr').each(function () {
      const pid = $(this).find('.product-select').val();
      if (pid) keys.push(pid + '-' + ($(this).find('.variation-select').val() || ''));
    });
    if (!loc || !keys.length) return;
    // sent as JSON — a normal form post is cut off by the server after ~1000 fields
    $.ajax({ url: '{{ route('stock.available.bulk') }}', method: 'POST', contentType: 'application/json',
             headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
             data: JSON.stringify({ location_id: loc, keys: keys }) }).done(function (map) {
      $('#itemBody tr').each(function () {
        const pid = $(this).find('.product-select').val(); if (!pid) return;
        const q = map[pid + '-' + ($(this).find('.variation-select').val() || '')];
        $(this).find('.available').text(q === undefined ? '-' : parseFloat(q).toFixed(2));
      });
    });
  }

  function countLines() {
    const n = $('#itemBody tr').filter(function () { return !!$(this).find('.product-select').val(); }).length;
    $('#lineCount').text(n ? '(' + n + ' lines)' : '');
  }

  function importItems(event) {
    const file = event.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('file', file); fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
    $.ajax({ url: '{{ route('stock_adjustments.import_items') }}', method: 'POST', data: fd, processData: false, contentType: false,
      success: function (res) {
        if (!res.success) { alert(res.message || 'Import failed'); return; }
        addLines(res.items);
        const skipped = res.errors || [];
        alert('Imported ' + res.items.length + ' line(s).' + (skipped.length ? '\n\nSkipped ' + skipped.length + ':\n' + skipped.slice(0, 30).join('\n') + (skipped.length > 30 ? '\n… and ' + (skipped.length - 30) + ' more' : '') : ''));
      },
      error: xhr => alert('Import failed: ' + (xhr.responseJSON?.message || xhr.statusText)),
      complete: () => { event.target.value = ''; } });
  }
</script>
