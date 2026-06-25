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
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="cust-datatable-default">
            <thead>
              <tr>
                <th>S.No</th>
                <th>Image</th>
                <th>Item Name</th>
                <th>Brand</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach($products as $index => $product)
              <tr>
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
                  <a href="{{ route('products.edit', $product->id) }}" class="text-primary"><i class="fa fa-edit"></i></a>
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
    $('#cust-datatable-default').DataTable({
      "pageLength": 100
    });
  });

</script>
@endsection
