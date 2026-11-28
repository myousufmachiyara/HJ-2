@extends('layouts.app')
@section('title', 'Bulk Category Fix')

@section('content')
<div class="card shadow-sm">
    <div class="card-header">
        <h4 class="card-title mb-0">Bulk Fix Product Categories</h4>
        <small class="text-muted">Change the category of many products at once from a sheet. Only the category changes — SKU, barcode and the Shopify / LAAM links stay as they are.</small>
    </div>

    <div class="card-body">
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <h6>1. Get the sheet</h6>
                <a href="{{ route('bulk-category.export') }}" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-download"></i> Download all products (CSV)</a>
                <p class="small text-muted mt-2 mb-0">
                    Columns: <strong>Product SKU</strong>, Product Name, Current Category Code, <strong>New Category Code</strong>.
                    Fill <em>New Category Code</em> only for the products you want to change; leave it blank to keep a product as it is.
                    A sheet with a <em>Category Code</em> column and one row per size also works.
                </p>
            </div>
            <div class="col-lg-6">
                <h6>2. Upload it</h6>
                <div class="input-group">
                    <input type="file" id="file" class="form-control" accept=".csv,.xlsx,.xls,.txt">
                    <button type="button" class="btn btn-primary" id="checkBtn"><i class="fas fa-search"></i> Check</button>
                </div>
            </div>
        </div>

        <details class="mb-3">
            <summary class="small">Category codes and how many products each has now</summary>
            <table class="table table-sm table-bordered w-auto mt-2 mb-0">
                <thead class="table-light"><tr><th>Code</th><th>Category</th><th class="text-end">Products</th></tr></thead>
                <tbody>
                @foreach($categories as $c)
                    <tr><td><strong>{{ $c->code }}</strong></td><td>{{ $c->name }}</td><td class="text-end">{{ $counts[$c->id] ?? 0 }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </details>

        <div id="loading" class="alert alert-info d-none">Checking…</div>
        <div id="problems" class="alert alert-warning d-none"></div>

        <form method="POST" action="{{ route('bulk-category.apply') }}" id="applyForm" class="d-none">
            @csrf
            <input type="hidden" name="payload" id="payload">
            <h6>3. Review</h6>
            <div id="summary" class="mb-2"></div>
            <input type="text" id="search" class="form-control form-control-sm w-25 mb-2" placeholder="Search SKU / name…">
            <div class="table-responsive" style="max-height: 55vh; overflow: auto;">
                <table class="table table-bordered table-sm mb-0" id="changeTable">
                    <thead class="table-light" style="position: sticky; top: 0;"><tr><th>SKU</th><th>Product</th><th>Now</th><th>Will become</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-success" id="applyBtn"><i class="fas fa-check"></i> Change categories</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  let changes = [];

  function problems(list) {
    const b = $('problems');
    if (!list.length) { b.classList.add('d-none'); return; }
    b.innerHTML = list.join('<br>'); b.classList.remove('d-none');
  }

  $('checkBtn').addEventListener('click', async () => {
    const f = $('file').files[0];
    if (!f) { problems(['Choose the file first.']); return; }
    const fd = new FormData(); fd.append('file', f); fd.append('_token', document.querySelector('input[name=_token]').value);
    $('applyForm').classList.add('d-none'); $('problems').classList.add('d-none'); $('loading').classList.remove('d-none');
    try {
      const res = await fetch(@json(route('bulk-category.preview')), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
      const d = await res.json();
      $('loading').classList.add('d-none');
      if (!res.ok || !d.success) { problems([d.message || 'Could not read the file.']); return; }
      show(d);
    } catch (e) { $('loading').classList.add('d-none'); problems(['Failed: ' + e.message]); }
  });

  function show(d) {
    changes = d.changes;
    const msgs = [];
    if (d.unknown_sku_n) msgs.push('<strong>' + d.unknown_sku_n + ' SKU(s) not found</strong> (skipped): ' + d.unknown_sku.slice(0, 10).map(esc).join(', ') + (d.unknown_sku_n > 10 ? ' …' : ''));
    const uc = Object.entries(d.unknown_code);
    if (uc.length) msgs.push('<strong>Unknown category code</strong> (skipped): ' + uc.map(([c, n]) => esc(c) + ' (' + n + ')').join(', '));
    if (d.conflicts.length) msgs.push('<strong>Same product with two different codes</strong> (the last one is used): ' + d.conflicts.slice(0, 10).map(esc).join(', '));
    problems(msgs);

    // "KS → WS : 327"
    const pairs = {};
    changes.forEach(c => { const k = c.from + ' → ' + c.to; pairs[k] = (pairs[k] || 0) + 1; });
    $('summary').innerHTML = '<strong>' + changes.length + '</strong> product(s) will change, ' + d.same + ' already have that category.<br>' +
      Object.entries(pairs).sort((a, b) => b[1] - a[1]).map(([k, n]) => '<span class="badge bg-secondary me-1">' + esc(k) + ' : ' + n + '</span>').join('');

    $('changeTable').querySelector('tbody').innerHTML = changes.map(c =>
      '<tr><td class="s">' + esc(c.sku) + '</td><td class="n">' + esc(c.name) + '</td><td>' + esc(c.from) + '</td><td><strong>' + esc(c.to) + '</strong></td></tr>').join('');
    $('applyForm').classList.toggle('d-none', !changes.length);
    if (!changes.length) problems(msgs.concat(['Nothing to change.']));
  }

  $('search').addEventListener('keyup', function () {
    const f = this.value.toLowerCase();
    document.querySelectorAll('#changeTable tbody tr').forEach(r => r.style.display = (r.querySelector('.s').textContent + ' ' + r.querySelector('.n').textContent).toLowerCase().includes(f) ? '' : 'none');
  });

  $('applyForm').addEventListener('submit', e => {
    if (!confirm('Change the category of ' + changes.length + ' product(s)? SKU and barcode are not changed.')) { e.preventDefault(); return; }
    $('payload').value = JSON.stringify(changes.map(c => ({ product_id: c.product_id, category_id: c.category_id })));
    $('applyBtn').disabled = true;
  });
})();
</script>
@endsection
