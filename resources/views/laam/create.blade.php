@extends('layouts.app')
@section('title', 'LAAM Sale | New')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('laam_invoices.store') }}" method="POST" id="laamForm">
      @csrf
      <input type="hidden" name="token" id="token">
      <input type="hidden" name="file_name" id="file_name">

      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">New LAAM Sale (Weekly Report)</h2>
          <a href="{{ route('laam_invoices.index') }}" class="btn btn-outline-secondary btn-sm">All LAAM Sales</a>
        </header>

        <div class="card-body">
          @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
          @endif
          @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
          @endif

          {{-- Header --}}
          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Date <span class="text-danger">*</span></label>
              <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
            </div>
            <div class="col-md-4 mb-3">
              <label>LAAM Location <span class="text-danger">*</span></label>
              @include('partials.location-select', ['name' => 'location_id', 'groups' => $locationGroups, 'selected' => old('location_id', $defaultLocationId), 'only' => ['customer']])
              <small class="text-muted">Stock is deducted from here; the receivable goes to this location's LAAM account.</small>
            </div>
            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control" value="{{ old('ref_no') }}">
            </div>
            <div class="col-md-4 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2">{{ old('remarks') }}</textarea>
            </div>
          </div>

          {{-- Upload --}}
          <div class="border rounded p-3 mb-3 bg-light d-flex align-items-center justify-content-between flex-wrap">
            <div>
              <strong><i class="fas fa-file-upload me-1"></i> LAAM Ledger export</strong><br>
              <small class="text-muted">Upload the weekly "Ledgers" file exactly as LAAM gives it (.xlsx or .csv). Nothing is saved until you press Save.</small>
            </div>
            <div>
              <input type="file" id="laamFile" accept=".xlsx,.xls,.csv" style="display:none">
              <button type="button" class="btn btn-success" id="pickBtn"><i class="fas fa-file-excel"></i> Choose LAAM file</button>
              <span id="fileLabel" class="ms-2 text-muted"></span>
            </div>
          </div>

          <div id="loading" class="alert alert-info d-none">Reading file…</div>
          <div id="problems" class="alert alert-danger d-none"></div>

          {{-- Review --}}
          <div id="review" class="d-none">
            <div class="row mb-3 text-center" id="totals"></div>

            <div class="table-responsive mb-3">
              <table class="table table-bordered table-sm" id="laamTable">
                <thead class="table-light">
                  <tr>
                    <th>#</th><th>Item Id</th><th>Order</th><th>Item</th><th>SKU in software</th>
                    <th class="text-end">Realized</th><th class="text-end">Fee</th>
                    <th class="text-end">PRA</th><th class="text-end">Sales tax</th><th class="text-end">Income tax</th>
                    <th class="text-end">Payable</th><th>Status</th>
                  </tr>
                </thead>
                <tbody></tbody>
                <tfoot class="table-light fw-bold"></tfoot>
              </table>
            </div>

            <div id="skippedBox" class="alert alert-warning d-none"></div>

            <div class="text-end">
              <button type="submit" class="btn btn-primary" id="saveBtn" disabled>
                <i class="fas fa-save"></i> Save &amp; Post to LAAM Ledger
              </button>
            </div>
          </div>
        </div>
      </section>
    </form>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const money = n => Number(n || 0).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  $('pickBtn').addEventListener('click', () => $('laamFile').click());

  $('laamFile').addEventListener('change', async e => {
    const file = e.target.files[0];
    if (!file) return;
    $('fileLabel').textContent = file.name;
    $('review').classList.add('d-none'); $('problems').classList.add('d-none'); $('saveBtn').disabled = true;
    $('loading').classList.remove('d-none');

    const fd = new FormData();
    fd.append('file', file);
    fd.append('_token', document.querySelector('input[name=_token]').value);

    try {
      const res  = await fetch(@json(route('laam_invoices.parse')), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      $('loading').classList.add('d-none');
      if (!res.ok || !data.success) {
        showProblems([data.message || 'Could not read the file.']);
        return;
      }
      render(data);
    } catch (err) {
      $('loading').classList.add('d-none');
      showProblems(['Upload failed: ' + err.message]);
    }
  });

  function showProblems(list) {
    const box = $('problems');
    box.innerHTML = '<ul class="mb-0">' + list.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>';
    box.classList.remove('d-none');
  }

  function render(d) {
    $('token').value = d.token;
    $('file_name').value = d.file_name;

    const t = d.totals;
    const box = (label, val, cls = '') => `<div class="col"><div class="border rounded p-2"><small class="text-muted">${label}</small><div class="fw-bold ${cls}">${val}</div></div></div>`;
    $('totals').innerHTML =
      box('Pieces', t.count) + box('Realized sales', money(t.realized)) + box('Platform fee', money(t.fee), 'text-danger') +
      box('PRA tax', money(t.pra_tax), 'text-danger') + box('Sales tax', money(t.sales_tax), 'text-danger') +
      box('Income tax', money(t.income_tax), 'text-danger') + box('LAAM owes us (Seller Payable)', money(t.payable), 'text-success');

    let bad = 0, dup = 0;
    $('laamTable').querySelector('tbody').innerHTML = d.rows.map((r, i) => {
      let status = '<span class="badge bg-success">OK</span>';
      let cls = '';
      if (r.duplicate)        { status = '<span class="badge bg-danger">Already imported</span>'; cls = 'table-danger'; dup++; }
      else if (!r.product_id) { status = '<span class="badge bg-danger" title="' + esc(r.match_error) + '">Not found</span><div class="small text-danger">' + esc(r.match_error) + '</div>'; cls = 'table-danger'; bad++; }
      else if (Math.abs(r.adjustment) > 0.009) { status += ' <span class="badge bg-warning text-dark" title="Seller Payable differs from Realized − fee − taxes">adj ' + money(r.adjustment) + '</span>'; }
      return `<tr class="${cls}">
        <td>${i + 1}</td><td>${esc(r.item_id)}</td><td>${esc(r.shopify_order || r.order_number)}</td>
        <td>${esc(r.name)}</td><td>${esc(r.matched_sku || r.sku)}</td>
        <td class="text-end">${money(r.realized)}</td><td class="text-end">${money(r.net_fee)}</td>
        <td class="text-end">${money(r.pra_tax)}</td><td class="text-end">${money(r.sales_tax)}</td><td class="text-end">${money(r.income_tax)}</td>
        <td class="text-end">${money(r.seller_payable)}</td><td>${status}</td></tr>`;
    }).join('');

    $('laamTable').querySelector('tfoot').innerHTML =
      `<tr><td colspan="5" class="text-end">Total</td><td class="text-end">${money(t.realized)}</td><td class="text-end">${money(t.fee)}</td>
       <td class="text-end">${money(t.pra_tax)}</td><td class="text-end">${money(t.sales_tax)}</td><td class="text-end">${money(t.income_tax)}</td>
       <td class="text-end">${money(t.payable)}</td><td></td></tr>`;

    const sk = $('skippedBox');
    if (d.skipped.length) {
      sk.innerHTML = '<strong>' + d.skipped.length + ' row(s) not posted:</strong><ul class="mb-0">' +
        d.skipped.map(s => `<li>Row ${s.row} · Item ${esc(s.item_id)} · ${esc(s.sku)} — ${esc(s.reason)}</li>`).join('') + '</ul>';
      sk.classList.remove('d-none');
    } else sk.classList.add('d-none');

    if (d.errors.length) showProblems(d.errors);

    $('review').classList.remove('d-none');
    $('saveBtn').disabled = !!(bad || dup || d.errors.length || !d.rows.length);
    if (bad || dup) showProblems([
      (bad ? bad + ' row(s) have no matching product in the software' : ''),
      (dup ? dup + ' row(s) were already imported earlier' : ''),
      'Fix the products (or remove the old settlement), then choose the file again.'
    ].filter(Boolean));
  }

  $('laamForm').addEventListener('submit', e => {
    if (!confirm('Post this LAAM sale? Stock will be deducted from LAAM and the LAAM receivable ledger will be updated.')) e.preventDefault();
  });
})();
</script>
@endsection
