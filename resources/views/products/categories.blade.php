@extends('layouts.app')

@section('title', 'Products | Categories')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header">
        <div style="display: flex; justify-content: space-between;">
          <h2 class="card-title">All Categories</h2>
          <div>
            <button type="button" class="modal-with-form btn btn-primary" href="#addCategoryModal">
              <i class="fas fa-plus"></i> Add Category
            </button>
          </div>
        </div>
        @if(session('success'))
          <div class="alert alert-success mt-2 mb-0">{{ session('success') }}</div>
        @endif
        @if(session('error'))
          <div class="alert alert-danger mt-2 mb-0">{{ session('error') }}</div>
        @endif
      </header>

      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="datatable-categories">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Code</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach($categories as $category)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $category->name }}</td>
                <td>{{ $category->code }}</td>
                <td>
                  <a class="text-primary modal-with-form" href="#editCategoryModal{{ $category->id }}">
                    <i class="fa fa-edit"></i>
                  </a>
                  <form action="{{ route('product_categories.destroy', $category->id) }}"
                        method="POST" class="d-inline"
                        onsubmit="return confirm('Delete this category?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 m-0 text-danger">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </form>
                </td>
              </tr>

              {{-- Edit Modal --}}
              <div id="editCategoryModal{{ $category->id }}" class="modal-block modal-block-warning mfp-hide">
                <section class="card">
                  <form method="POST" action="{{ route('product_categories.update', $category->id) }}">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                      <h2 class="card-title">Edit Category</h2>
                    </header>
                    <div class="card-body">
                      <div class="form-group mb-3">
                        <label>Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name"
                               value="{{ $category->name }}" required
                               oninput="syncCode('edit_code_{{ $category->id }}', this.value)">
                      </div>
                      <div class="form-group mb-3">
                        <label>Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code"
                               id="edit_code_{{ $category->id }}"
                               value="{{ $category->code }}" required>
                        <small class="text-muted">Auto-generated from name. You can customise it.</small>
                      </div>
                    </div>
                    <footer class="card-footer text-end">
                      <button type="submit" class="btn btn-warning">Update</button>
                      <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
                    </footer>
                  </form>
                </section>
              </div>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{-- Add Modal --}}
    <div id="addCategoryModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('product_categories.store') }}">
          @csrf
          <header class="card-header">
            <h2 class="card-title">New Category</h2>
          </header>
          <div class="card-body">
            <div class="form-group mb-3">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" id="add_name"
                     required oninput="syncCode('add_code', this.value)">
            </div>
            <div class="form-group mb-3">
              <label>Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="code" id="add_code" required>
              <small class="text-muted">Auto-generated from name. You can customise it.</small>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Create</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

  </div>
</div>

<script>
function syncCode(targetId, nameValue) {
  const codeField = document.getElementById(targetId);
  if (!codeField) return;
  // Only auto-sync if user hasn't manually edited the code field
  if (!codeField.dataset.manuallyEdited) {
    codeField.value = nameValue
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-');
  }
}

// Mark code field as manually edited if user types in it directly
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('input[name="code"]').forEach(function (el) {
    el.addEventListener('input', function () {
      this.dataset.manuallyEdited = 'true';
    });
  });
});
</script>
@endsection