@extends('layouts.app')

@section('title', 'Product | All Product')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif
      @if ($errors->any())
        <div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>
      @endif

      <header class="card-header">
        <div style="display: flex;justify-content: space-between;">
          <h2 class="card-title">All Products</h2>
          <div>
            <!-- Export button -->
            <a href="{{ route('products.bulk-export') }}" class="btn btn-warning me-2"><i class="fas fa-download"></i> Export</a>
            <a href="#bulkImportModal" class="modal-with-form btn btn-success me-2"><i class="fas fa-file-import"></i> Bulk Import</a>
            <a href="{{ route('products.barcode.selection') }}" class="btn btn-danger"><i class="fas fa-barcode"></i> Barcodes</a>
            <a href="{{ route('products.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Products</a>
          </div>
        </div>
      </header>

      <div class="card-body">

        @if(($formulaProducts ?? 0) > 0)
          <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
            <span><b>{{ $formulaProducts }}</b> product(s) were imported with Excel <b>formulas</b> (e.g. SKU "=LOWER(…)") instead of values.</span>
            @can('products.delete')
              <form method="POST" action="{{ route('products.cleanup-formula') }}" onsubmit="return confirm('Remove the {{ $formulaProducts }} product(s) imported with formulas?')">
                @csrf
                <button class="btn btn-sm btn-danger"><i class="fas fa-broom"></i> Remove them</button>
              </form>
            @endcan
            <span class="text-muted small">Then upload the sheet again — formulas are now read as their values.</span>
          </div>
        @endif

        @if($lastImport && in_array($lastImport->status, ['completed', 'failed'], true) && $lastImport->updated_at->gt(now()->subMinutes(30)))
          <div class="alert {{ $lastImport->status === 'failed' ? 'alert-danger' : 'alert-info' }}">
            <b>Last import</b> ({{ $lastImport->original_name }}, {{ $lastImport->updated_at->diffForHumans() }}):
            {{ $lastImport->message ?: $lastImport->status }}
          </div>
        @endif

        <!-- Live import progress (hidden until an import is running) -->
        <div id="importProgress" class="mb-3" style="display:none;">
          <div class="progress" style="height:22px;">
            <div id="importBar"
                 class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                 role="progressbar" style="width:0%">0%</div>
          </div>
          <small id="importMsg" class="text-muted"></small>
        </div>

        @if($shopifyStores->isNotEmpty())
          {{-- Push ticked products to Shopify (new products only, created as Draft, no stock) --}}
          <form id="shopifyPushForm" action="{{ route('shopify.push.selected') }}" method="POST" class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 border rounded bg-light">
            @csrf
            <i class="fab fa-shopify text-success fa-lg"></i>
            <strong>Shopify:</strong>
            <span><span id="pushCount">0</span> ticked</span>
            <input type="hidden" name="product_ids_csv" id="pushIds">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="pushAllMatching" title="Tick every product that can be pushed and matches the search">Tick all matching</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="pushClear">Clear</button>
            @if($shopifyStores->count() > 1)
              <select name="store_id" class="form-select form-select-sm" style="width:auto">
                @foreach($shopifyStores as $st)<option value="{{ $st->id }}">{{ $st->shop_name }}</option>@endforeach
              </select>
            @else
              <input type="hidden" name="store_id" value="{{ $shopifyStores->first()->id }}">
              <span class="text-muted">→ {{ $shopifyStores->first()->shop_name }}</span>
            @endif
            <button type="submit" class="btn btn-sm btn-success" id="pushBtn" disabled><i class="fas fa-upload"></i> Push to Shopify</button>
            <small class="text-muted">Only finished goods not yet on Shopify can be ticked. Created as <b>Draft</b>; stock is not sent.</small>
          </form>
        @endif

        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="cust-datatable-default">
            <thead>
              <tr>
                @if($shopifyStores->isNotEmpty())<th width="3%"><input type="checkbox" id="pushAll" title="Tick all that can be pushed"></th>@endif
                <th>S.No</th>
                <th>Image</th>
                <th>Item Name</th>
                <th>Brand</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Shopify</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Bulk Import Modal -->
    <div id="bulkImportModal" class="modal-block mfp-hide">
      <section class="card">
        <form action="{{ route('products.bulk-import') }}" method="POST" enctype="multipart/form-data">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Bulk Import / Update Products</h2>
          </header>

          <div class="card-body">

            <div class="mb-3">
              <label for="file_import" class="form-label">Choose edited export file</label>
              <input type="file" name="file" id="file_import" class="form-control" accept=".csv,.xlsx" required>
              <small class="text-danger">Upload exported file after editing. Allowed: CSV, XLSX</small><br>
              <small class="text-muted">Category: use the <b>Category Code</b> column ({{ $categories->pluck('code')->filter()->implode(', ') }}). An unknown code stops the import and lists the rows.</small>
            </div>

            <div class="mt-2 mb-2">
              <input type="checkbox" class="perm-checkbox index-checkbox" name="delete_missing" value="1" id="delete_missing">
              <label class="form-check-label" for="delete_missing">
                Delete products and variations not present in this file
              </label>
            </div>

            <a href="{{ route('products.bulk-upload.template') }}" class="btn btn-primary mb-3">
              <i class="fas fa-download"></i> Download Template
            </a>
          </div>

          <footer class="card-footer text-end">
            <button class="btn btn-default modal-dismiss">Cancel</button>
            <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Import</button>
          </footer>
        </form>
      </section>
    </div>

  </div>
</div>

<script>
  $(document).ready(function () {
    const hasPush  = {{ $shopifyStores->isNotEmpty() ? 'true' : 'false' }};
    const csrf     = @json(csrf_token());
    const dataUrl  = @json(route('products.data'));
    const panna    = @json(route('panna-consumption.index'));
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Ticks live in this set, so they survive paging / searching / sorting.
    const ticked = new Set();

    const columns = [];
    if (hasPush) columns.push({ data: null, orderable: false, searchable: false,
      render: (d, t, r) => r.can_push ? `<input type="checkbox" class="push-check" value="${r.id}">` : '' });
    columns.push(
      { data: null, orderable: false, searchable: false,
        render: (d, t, r, m) => m.settings._iDisplayStart + m.row + 1 },
      { data: 'image', orderable: false, render: (d) => d
          ? `<img src="${esc(d)}" width="60" height="60" loading="lazy" style="object-fit:cover;border-radius:5px;" alt=""
                  onerror="this.replaceWith(Object.assign(document.createElement('span'), {className: 'text-muted small', textContent: 'Image missing'}))">`
          : '<span class="text-muted">No Image</span>' },
      { data: 'name',     render: d => esc(d) },
      { data: 'brand',    render: d => esc(d || '-') },
      { data: 'sku',      render: d => esc(d) },
      { data: 'category', render: d => esc(d || '-') },
      { data: 'shopify',  render: (d, t, r) => d === 'on'
          ? `<span class="badge bg-success" title="${esc(r.store)}">On Shopify</span>`
          : d === 'pending' ? '<span class="badge bg-light text-dark border">Not pushed</span>' : '<span class="text-muted">—</span>' },
      { data: null, orderable: false, searchable: false, render: (d, t, r) => `
          <a href="${esc(r.edit_url)}" class="text-primary"><i class="fa fa-edit"></i></a>
          ${r.is_raw ? `<a href="${esc(panna)}" class="text-success" title="Fabric consumption per category & PANNA"><i class="fas fa-sitemap"></i></a>` : ''}
          <form method="POST" action="${esc(r.delete_url)}" style="display:inline-block">
            <input type="hidden" name="_token" value="${esc(csrf)}"><input type="hidden" name="_method" value="DELETE">
            <button class="btn btn-link p-0 m-0 text-danger" onclick="return confirm('Delete this product?')" title="Delete"><i class="fa fa-trash-alt"></i></button>
          </form>` }
    );

    const productTable = $('#cust-datatable-default').DataTable({
      serverSide: true,
      processing: true,
      ajax: dataUrl,
      columns: columns,
      order: [],                      // oldest first until a column header is clicked
      pageLength: 100,
      lengthMenu: [25, 50, 100, 200],
      searchDelay: 400,
      language: { search: 'Search name / SKU / brand / barcode:' },
      drawCallback: function () {
        $('input.push-check').each(function () { this.checked = ticked.has(this.value); });
        syncHeader();
      }
    });

    // ── Push to Shopify: ticks are kept across pages ──
    function refreshPush() {
      $('#pushCount').text(ticked.size);
      $('#pushBtn').prop('disabled', ticked.size === 0);
    }
    function syncHeader() {
      const boxes = $('input.push-check');
      $('#pushAll').prop('checked', boxes.length > 0 && boxes.filter(':checked').length === boxes.length);
      refreshPush();
    }
    $(document).on('change', 'input.push-check', function () {
      this.checked ? ticked.add(this.value) : ticked.delete(this.value);
      syncHeader();
    });
    // header box = every pushable product on THIS page
    $('#pushAll').on('change', function () {
      const on = this.checked;
      $('input.push-check').each(function () { this.checked = on; on ? ticked.add(this.value) : ticked.delete(this.value); });
      refreshPush();
    });
    // every pushable product matching the current search, across all pages
    $('#pushAllMatching').on('click', function () {
      const btn = $(this).prop('disabled', true);
      $.get(dataUrl, { ids_only: 1, search: { value: productTable.search() } })
        .done(r => { (r.ids || []).forEach(id => ticked.add(String(id))); $('input.push-check').each(function () { this.checked = ticked.has(this.value); }); syncHeader(); })
        .always(() => btn.prop('disabled', false));
    });
    $('#pushClear').on('click', function () { ticked.clear(); $('input.push-check').prop('checked', false); syncHeader(); });

    $('#shopifyPushForm').on('submit', function () {
      if (!ticked.size) return false;
      if (!confirm('Push ' + ticked.size + ' product(s) to Shopify as Draft?')) return false;
      $('#pushIds').val(Array.from(ticked).join(','));
      return true;
    });

    // Auto-start progress tracking after a bulk import was queued.
    @if(session('import_id'))
      trackImport({{ session('import_id') }});
    @elseif($lastImport && in_array($lastImport->status, ['queued', 'preparing', 'processing'], true))
      trackImport({{ $lastImport->id }});   // page reloaded while an import is still running
    @endif
  });

  function trackImport(id) {
    const box = document.getElementById('importProgress');
    const bar = document.getElementById('importBar');
    const msg = document.getElementById('importMsg');
    box.style.display = 'block';

    const url = "{{ url('products/import-status') }}/" + id;
    const poll = setInterval(async () => {
      try {
        const r = await fetch(url);
        const d = await r.json();

        bar.style.width = d.progress + '%';
        bar.textContent = d.progress + '%';
        msg.textContent = d.message || d.status;

        if (d.status === 'completed' || d.status === 'failed') {
          clearInterval(poll);
          bar.classList.remove('progress-bar-animated');
          if (d.status === 'failed') {
            bar.classList.remove('bg-success');
            bar.classList.add('bg-danger');
          }
          setTimeout(() => location.reload(), 1500);
        }
      } catch (e) {
        // transient network/permission hiccup — keep polling
      }
    }, 2000);
  }
</script>
@endsection