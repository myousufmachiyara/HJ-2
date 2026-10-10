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
            <tbody>
              @foreach($products as $index => $product)
              @php $canPush = $product->item_type === 'fg' && $product->is_active && !$product->shopify_product_id; @endphp
              <tr>
                @if($shopifyStores->isNotEmpty())
                  <td>@if($canPush)<input type="checkbox" class="push-check" value="{{ $product->id }}">@endif</td>
                @endif
                <td>{{ $index + 1 }}</td>
                <td>
                  @if($product->images->first())
                    <img src="{{ asset('storage/' . $product->images->first()->image_path) }}" width="60" height="60" style="object-fit:cover;border-radius:5px;">
                  @else
                    <span class="text-muted">No Image</span>
                  @endif
                </td>
                <td>{{ $product->name }}</td>
                <td>{{ $product->brand ?? '-' }}</td>
                <td>{{ $product->sku }}</td>
                <td>{{ $product->category->name ?? '-' }}</td>
                <td>
                  @if($product->shopify_product_id)
                    <span class="badge bg-success" title="{{ $product->shopifyStore?->shop_name }}">On Shopify</span>
                  @elseif($canPush)
                    <span class="badge bg-light text-dark border">Not pushed</span>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td>
                  <a href="{{ route('products.edit', $product->id) }}" class="text-primary"><i class="fa fa-edit"></i></a>
                  @if($product->item_type === 'raw')
                    <a href="{{ route('products.fabric-articles', $product->id) }}" class="text-success" title="Articles made from this fabric & consumption"><i class="fas fa-sitemap"></i></a>
                  @endif
                  <form method="POST" action="{{ route('products.destroy', $product->id) }}" style="display:inline-block">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-link p-0 m-0 text-danger" onclick="return confirm('Delete this product?')" title="Delete"><i class="fa fa-trash-alt"></i></button>
                  </form>
                </td>
              </tr>
              @endforeach
            </tbody>
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
              <small class="text-danger">Upload exported file after editing. Allowed: CSV, XLSX</small>
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
    const productTable = $('#cust-datatable-default').DataTable({
      "pageLength": 100
    });

    // ── Push to Shopify: ticks on every page of the table count ──
    function pushTicked() { return productTable.$('input.push-check:checked'); }
    function refreshPush() {
      const n = pushTicked().length;
      $('#pushCount').text(n);
      $('#pushBtn').prop('disabled', n === 0);
    }
    $(document).on('change', 'input.push-check', refreshPush);
    $('#pushAll').on('change', function () {
      productTable.$('input.push-check').prop('checked', this.checked);
      refreshPush();
    });
    $('#shopifyPushForm').on('submit', function () {
      const ids = pushTicked().map(function () { return this.value; }).get();
      if (!ids.length) return false;
      if (!confirm('Push ' + ids.length + ' product(s) to Shopify as Draft?')) return false;
      $(this).find('input[name="product_ids[]"]').remove();
      ids.forEach(id => $(this).append(`<input type="hidden" name="product_ids[]" value="${id}">`));
      return true;
    });

    // Auto-start progress tracking after a bulk import was queued.
    @if(session('import_id'))
      trackImport({{ session('import_id') }});
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