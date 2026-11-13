{{-- Stock Movement form (create + edit): any location → any location --}}
@php
  $t = $transfer ?? null;
  $json = old('items_json');
  if ($json && is_array($dec = json_decode($json, true))) {
      $lines = $dec;                                   // after a validation error: what was on screen
  } else {
      $lines = $t
          ? $t->details->map(fn($d) => ['product_id' => $d->product_id, 'variation_id' => $d->variation_id, 'quantity' => $d->quantity + 0,
                                        'name' => $d->product->name ?? ('#' . $d->product_id), 'sku' => $d->variation->sku ?? ''])->all()
          : [];
  }
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
    <h2 class="card-title">Items <small class="text-muted">(<span id="lineCount">0</span> lines)</small></h2>
    <div class="btn-group">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadTemplate()"><i class="fas fa-file-download"></i> Template</button>
      <button type="button" class="btn btn-outline-success btn-sm" onclick="$('#importFile').click()"><i class="fas fa-file-upload"></i> Import Excel</button>
      <input type="file" id="importFile" accept=".xlsx,.xls,.csv" style="display:none" onchange="importItems(event)">
    </div>
  </header>
  <div class="card-body">
    <div class="row mb-2">
      <div class="col-md-4">
        <label class="mb-1">Scan barcode / enter code</label>
        <input type="text" id="scanBox" class="form-control" placeholder="Scan here — each scan adds / increases a line" autocomplete="off" autofocus>
      </div>
      <div class="col-md-5">
        <label class="mb-1">Or search a product / size</label>
        <select id="itemSearch" class="form-control" data-placeholder="Type a name or SKU…"></select>
      </div>
      <div class="col-md-3 pt-4"><span id="scanMsg" class="small"></span></div>
    </div>

    <input type="hidden" name="items_json" id="itemsJson">
    <div class="table-responsive" style="max-height:65vh;overflow:auto">
    <table class="table table-bordered table-sm mb-0" id="itemTable">
      <thead class="table-light" style="position:sticky;top:0;z-index:1">
        <tr><th width="4%">#</th><th>Product</th><th width="22%">Size / SKU</th><th width="10%">Available</th><th width="11%">Qty</th><th width="4%"></th></tr>
      </thead>
      <tbody id="itemBody"></tbody>
      <tfoot><tr><th colspan="4" class="text-end">Total Qty</th><th id="totalQty">0</th><th></th></tr></tfoot>
    </table>
    </div>
    <small class="text-muted">The newest scan is shown at the top. Scanning the same item again adds 1 to its quantity.</small>
  </div>
  <footer class="card-footer text-end">
    <a href="{{ route('stock_transfer.index') }}" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary" id="saveBtn">Save Movement</button>
  </footer>
</section>

<script>
  const INITIAL_LINES = @json($lines);
  const SCAN_IDLE_MS = 120, SCAN_KEY_MS = 50;   // a scanner types each character < 50 ms apart; a person is slower
  const rows = new Map();                        // "pid-vid" -> <tr>
  let scanQueue = [], scanRunning = false, burst = [];

  $(function () {
    $('#from_location_id, #to_location_id').select2({ width: '100%' });

    // ── draw the saved lines in one go (plain HTML, no per-line widgets / requests)
    const html = [];
    INITIAL_LINES.forEach(l => html.push(rowHtml(l.product_id, l.variation_id, l.name, l.sku, l.quantity)));
    document.getElementById('itemBody').innerHTML = html.join('');
    $('#itemBody tr').each(function () { rows.set(this.dataset.key, this); });
    recalc(); refreshAvailable();

    // ── manual search (one remote select, not a dropdown per line)
    $('#itemSearch').select2({
      width: '100%', allowClear: true, placeholder: 'Type a name or SKU…', minimumInputLength: 2,
      ajax: { url: @json(route('products.item-search')), dataType: 'json', delay: 250,
              data: p => ({ q: p.term, page: p.page || 1 }),
              processResults: (d, p) => ({ results: d.results, pagination: d.pagination }) },
      templateSelection: o => o.full || o.text,
    }).on('select2:select', function (e) {
      const d = e.params.data, parts = String(d.id).split('-');
      const name = (d.full || d.text || '').split(' — ')[0].replace(/ \(.*\)$/, '');
      addLine(parts[0], parts[1] || null, name, parts[1] ? String(d.text).replace('↳ ', '') : '', 1);
      $(this).val(null).trigger('change');
      $('#scanBox').focus();
    });

    // ── scan box: Enter, or automatically at the end of a scanner burst
    $('#scanBox').on('keydown', function (e) {
      if (e.which === 13) { e.preventDefault(); takeCode(); }
    }).on('input', function (e) {
      // event.timeStamp is when the key really arrived, so a busy page cannot make a scanner look slow
      const ts = e.originalEvent && e.originalEvent.timeStamp ? e.originalEvent.timeStamp : performance.now();
      if (!this.value) { burst = []; return; }
      burst.push(ts);
      clearTimeout(window._idle);
      window._idle = setTimeout(function () {
        const v = $('#scanBox').val() || '';
        const avg = burst.length > 1 ? (burst[burst.length - 1] - burst[0]) / (burst.length - 1) : 999;
        if (v.length >= 4 && avg < SCAN_KEY_MS) takeCode(); else burst = [];
      }, SCAN_IDLE_MS);
    });
    $('#scanBox').focus();

    $(document).on('input', '.quantity', function () { recalc(); });
    $(document).on('keydown', '.quantity', function (e) { if (e.which === 13) { e.preventDefault(); $('#scanBox').focus(); } });
    $(document).on('click', '.remove-row', function () {
      const tr = $(this).closest('tr'); rows.delete(tr.data('key')); tr.remove(); recalc();
    });
    $('#from_location_id').on('change', refreshAvailable);

    // ── save: send the lines as ONE field (a few hundred lines would exceed the server's field limit otherwise)
    $('#itemTable').closest('form').on('submit', function (e) {
      const items = [];
      $('#itemBody tr').each(function () {
        const q = parseFloat($(this).find('.quantity').val());
        if (q > 0) items.push({ product_id: this.dataset.pid, variation_id: this.dataset.vid || null, quantity: q });
      });
      if (!items.length) { e.preventDefault(); alert('Add at least one item with a quantity.'); return; }
      $('#itemsJson').val(JSON.stringify(items));
      $('#saveBtn').prop('disabled', true).text('Saving…');
    });
  });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function rowHtml(pid, vid, name, sku, qty) {
    return '<tr data-key="' + pid + '-' + (vid || '') + '" data-pid="' + pid + '" data-vid="' + (vid || '') + '">' +
      '<td class="text-muted rn"></td><td>' + esc(name) + '</td><td>' + esc(sku) + '</td>' +
      '<td class="available text-end text-muted">-</td>' +
      '<td><input type="number" class="form-control form-control-sm quantity" step="any" min="0" value="' + (qty || '') + '"></td>' +
      '<td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td></tr>';
  }
  /** Add one line (newest on top) or add to the quantity of the same item already listed. */
  function addLine(pid, vid, name, sku, qty) {
    const key = pid + '-' + (vid || '');
    let tr = rows.get(key);
    if (tr) {
      const $q = $(tr).find('.quantity');
      $q.val((parseFloat($q.val()) || 0) + qty);
      $(tr).addClass('table-success'); setTimeout(() => $(tr).removeClass('table-success'), 600);
    } else {
      const $tr = $(rowHtml(pid, vid, name, sku, qty));
      $('#itemBody').prepend($tr); tr = $tr[0]; rows.set(key, tr);
      fetchAvailable([key]);
    }
    recalc();
    return tr;
  }

  // ── scanning: codes wait in a queue, so scanning fast never drops one
  function takeCode() {
    const code = ($('#scanBox').val() || '').trim();
    $('#scanBox').val(''); burst = []; clearTimeout(window._idle);
    if (!code) return;
    scanQueue.push(code); runQueue();
  }
  function runQueue() {
    if (scanRunning || !scanQueue.length) return;
    scanRunning = true;
    const code = scanQueue.shift();
    $.get('/get-product-by-code/' + encodeURIComponent(code), function (res) {
      if (!res || !res.success) return msg('Not found: ' + code, true);
      if (res.type === 'variation') addLine(res.variation.product_id, res.variation.id, res.variation.name, res.variation.sku, 1);
      else addLine(res.product.id, null, res.product.name, '', 1);
      msg('Added ' + code, false);
    }).fail(function () { msg('Lookup failed for ' + code + ' — scan it again', true); })
      .always(function () { scanRunning = false; runQueue(); $('#scanBox').focus(); });
  }
  function msg(t, bad) { $('#scanMsg').text(t).toggleClass('text-danger', !!bad).toggleClass('text-success', !bad); }

  // ── stock available at the FROM location: one request for the whole list
  function fetchAvailable(keys) {
    const loc = $('#from_location_id').val();
    if (!loc || !keys.length) return;
    $.post(@json(route('stock.available.bulk')), { _token: $('meta[name="csrf-token"]').attr('content'), location_id: loc, keys: keys }, function (map) {
      keys.forEach(k => {
        const tr = rows.get(k); if (!tr) return;
        const q = parseFloat(map[k]) || 0;
        $(tr).find('.available').text(q.toFixed(2)).toggleClass('text-danger', q <= 0);
      });
    });
  }
  function refreshAvailable() { fetchAvailable(Array.from(rows.keys())); }

  function recalc() {
    let t = 0, n = 0;
    $('#itemBody .quantity').each(function () { t += parseFloat(this.value) || 0; n++; });
    $('#totalQty').text(t.toFixed(2)); $('#lineCount').text(n);
  }

  // ── Excel import: Item Code (barcode or SKU) | Quantity
  function downloadTemplate() {
    const ws = XLSX.utils.aoa_to_sheet([['Item Code (Barcode or SKU)', 'Quantity'], ['KRT-00001-M', 5]]);
    ws['!cols'] = [{ wch: 28 }, { wch: 10 }];
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Items');
    XLSX.writeFile(wb, 'stock_movement_import_template.xlsx');
  }
  function importItems(event) {
    const file = event.target.files[0]; if (!file) return;
    const fd = new FormData(); fd.append('file', file); fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
    $.ajax({ url: @json(route('stock_transfer.import_items')), method: 'POST', data: fd, processData: false, contentType: false,
      success: function (res) {
        if (!res.success) { alert(res.message || 'Import failed'); return; }
        res.items.forEach(it => addLine(it.product_id, it.variation_id, it.name, it.variation_id ? it.sku : '', parseFloat(it.quantity) || 0));
        refreshAvailable();
        alert('Imported ' + res.items.length + ' line(s).' + (res.errors.length ? '\n\nSkipped:\n' + res.errors.join('\n') : ''));
      },
      error: xhr => alert('Import failed: ' + (xhr.responseJSON?.message || xhr.statusText)),
      complete: () => { event.target.value = ''; } });
  }
</script>
