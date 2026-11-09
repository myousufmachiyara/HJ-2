@extends('layouts.app')
@section('title', 'Push Prices to Shopify')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Push Prices to Shopify</h2>
        <a href="{{ route('shopify.settings') }}" class="btn btn-outline-secondary btn-sm">Shopify Stores / Sync History</a>
      </header>

      <div class="card-body">
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

        <p class="text-muted mb-3">
          Send the <strong>selling price</strong> and <strong>discount (compare-at) price</strong> saved in the software to Shopify,
          for the SKUs / barcodes you choose. Only prices are changed — nothing else on the Shopify product.
          A size with no compare-at price in the software has its compare-at price <u>cleared</u> on Shopify (the sale ends).
        </p>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">SKUs / barcodes <small class="text-muted">(one per line, or separated by commas)</small></label>
            <textarea id="codes" class="form-control" rows="6" placeholder="HAS-OWWS--5-6Y&#10;444092924915&#10;..."></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label">…or upload a sheet</label>
            <input type="file" id="file" class="form-control" accept=".xlsx,.xls,.csv">
            <small class="text-muted">
              The Excel from <a href="{{ route('bulk-price.index') }}">Bulk Price Update</a> works as it is (column "Variant SKU").
              Any other sheet: use a column named SKU / Barcode, or put codes in the first column.
            </small>
            <div class="mt-3"><button type="button" class="btn btn-primary" id="checkBtn"><i class="fas fa-search"></i> Check items</button></div>
          </div>
        </div>

        <div id="loading" class="alert alert-info d-none">Checking…</div>
        <div id="problems" class="alert alert-warning d-none"></div>

        <form method="POST" action="{{ route('shopify.prices.push') }}" id="pushForm" class="d-none">
          @csrf
          <input type="hidden" name="payload" id="payload">

          <div class="mb-2 d-flex justify-content-between">
            <div><strong id="selCount">0</strong> of <span id="allCount">0</span> ticked</div>
            <input type="text" id="search" class="form-control form-control-sm w-25" placeholder="Search…">
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm align-middle" id="priceTable">
              <thead class="table-light">
                <tr>
                  <th width="4%"><input type="checkbox" id="checkAll" checked></th>
                  <th>SKU</th><th>Product</th>
                  <th class="text-end">Selling price → Shopify "Price"</th>
                  <th class="text-end">Discount (compare-at) → Shopify "Compare-at"</th>
                  <th>Shopify store</th><th>Status</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>

          <div class="text-end">
            <button type="submit" class="btn btn-success"><i class="fab fa-shopify"></i> Send prices to Shopify</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const money = n => n === null || n === undefined ? '—' : Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const rows = () => Array.from(document.querySelectorAll('#priceTable tbody tr'));
  const count = () => { $('selCount').textContent = rows().filter(r => r.querySelector('.rc').checked).length; };

  function problems(list) {
    const b = $('problems');
    if (!list.length) { b.classList.add('d-none'); return; }
    b.innerHTML = '<strong>' + list.length + ' code(s) not found:</strong><ul class="mb-0">' + list.slice(0, 30).map(x => '<li>' + esc(x) + '</li>').join('') + (list.length > 30 ? '<li>…</li>' : '') + '</ul>';
    b.classList.remove('d-none');
  }

  $('checkBtn').addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('codes', $('codes').value);
    if ($('file').files[0]) fd.append('file', $('file').files[0]);
    fd.append('_token', document.querySelector('input[name=_token]').value);

    $('pushForm').classList.add('d-none'); $('problems').classList.add('d-none'); $('loading').classList.remove('d-none');
    try {
      const res = await fetch(@json(route('shopify.prices.check')), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
      const d = await res.json();
      $('loading').classList.add('d-none');
      if (!res.ok || !d.success) { problems([d.message || 'Could not read the list.']); return; }
      render(d);
    } catch (e) { $('loading').classList.add('d-none'); problems(['Failed: ' + e.message]); }
  });

  function render(d) {
    $('priceTable').querySelector('tbody').innerHTML = d.rows.map(r => `
      <tr class="${r.ok ? '' : 'table-secondary'}" data-product="${r.product_id}" data-variation="${r.variation_id ?? ''}">
        <td><input type="checkbox" class="rc" ${r.ok ? 'checked' : 'disabled'}></td>
        <td class="sku">${esc(r.sku)}</td><td class="nm">${esc(r.name)}</td>
        <td class="text-end">${money(r.price)}</td><td class="text-end">${money(r.compare_at)}</td>
        <td>${esc(r.store ?? '—')}</td>
        <td>${r.ok ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">' + esc(r.note) + '</span>'}</td>
      </tr>`).join('');
    $('allCount').textContent = d.rows.length;
    problems(d.errors);
    $('pushForm').classList.toggle('d-none', !d.rows.length);
    $('checkAll').checked = true;
    count();
  }

  $('checkAll').addEventListener('change', e => { rows().filter(r => r.style.display !== 'none').forEach(r => { const c = r.querySelector('.rc'); if (!c.disabled) c.checked = e.target.checked; }); count(); });
  document.addEventListener('change', e => { if (e.target.classList.contains('rc')) count(); });
  $('search').addEventListener('keyup', function () {
    const f = this.value.toLowerCase();
    rows().forEach(r => r.style.display = (r.querySelector('.sku').textContent + ' ' + r.querySelector('.nm').textContent).toLowerCase().includes(f) ? '' : 'none');
  });

  $('pushForm').addEventListener('submit', e => {
    const items = rows().filter(r => r.querySelector('.rc').checked).map(r => ({ product_id: r.dataset.product, variation_id: r.dataset.variation || null }));
    if (!items.length) { e.preventDefault(); alert('Tick at least one item.'); return; }
    if (!confirm('Send selling + compare-at prices of ' + items.length + ' item(s) to Shopify? This changes the live store prices.')) { e.preventDefault(); return; }
    $('payload').value = JSON.stringify(items);
  });
})();
</script>
@endsection
