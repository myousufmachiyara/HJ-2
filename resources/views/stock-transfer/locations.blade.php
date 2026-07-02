@extends('layouts.app')

@section('title', 'Stock Transfer | Locations')

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
        <div style="display: flex; justify-content: space-between;">
          <h2 class="card-title">All Locations</h2>
          <div>
            <button type="button" class="modal-with-form btn btn-primary" href="#addLocationModal">
              <i class="fas fa-plus"></i> Add Location
            </button>
          </div>
        </div>
        @if ($errors->has('error'))
          <strong class="text-danger">{{ $errors->first('error') }}</strong>
        @endif
      </header>

      <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
          <i class="fas fa-info-circle me-1"></i>
          The <strong>Default</strong> warehouse holds all stock that hasn't been transferred out
          (opening stock, purchases, production receipts). Exactly one warehouse can be default.
          Customer stock locations are managed automatically from the customer account and are not shown here.
        </div>

        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="datatable-locations">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Code</th>
                <th>Default</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($locations as $location)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $location->name }}</td>
                <td>{{ $location->code }}</td>
                <td>
                  @if($location->is_default)
                    <span class="badge bg-success"><i class="fas fa-star me-1"></i>Default</span>
                  @else
                    <form action="{{ route('locations.set-default', $location->id) }}" method="POST" class="d-inline">
                      @csrf
                      <button type="submit" class="btn btn-outline-secondary btn-sm">Set Default</button>
                    </form>
                  @endif
                </td>
                <td>
                  <a class="text-primary modal-with-form" href="#editLocationModal{{ $location->id }}">
                    <i class="fa fa-edit"></i>
                  </a>
                  @if($location->is_default)
                    <span class="text-muted ms-1" title="The default warehouse cannot be deleted">
                      <i class="fas fa-trash-alt"></i>
                    </span>
                  @else
                    <form action="{{ route('locations.destroy', $location->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn btn-link p-0 m-0 text-danger">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </form>
                  @endif
                </td>
              </tr>

              <!-- Edit Modal -->
              <div id="editLocationModal{{ $location->id }}" class="modal-block modal-block-warning mfp-hide">
                <section class="card">
                  <form method="post" action="{{ route('locations.update', $location) }}">
                    @csrf
                    @method('PUT')
                    <header class="card-header">
                      <h2 class="card-title">Edit Location</h2>
                    </header>
                    <div class="card-body">
                      <div class="form-group mb-3">
                        <label>Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="{{ old('name', $location->name) }}" required>
                      </div>
                      <div class="form-group mb-3">
                        <label>Code</label>
                        <input type="text" class="form-control" name="code" value="{{ old('code', $location->code) }}">
                      </div>
                    </div>
                    <footer class="card-footer">
                      <div class="row">
                        <div class="col-md-12 text-end">
                          <button type="submit" class="btn btn-warning">Update</button>
                          <button class="btn btn-default modal-dismiss">Cancel</button>
                        </div>
                      </div>
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

    <!-- Add Modal -->
    <div id="addLocationModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="post" action="{{ route('locations.store') }}">
          @csrf
          <header class="card-header">
            <h2 class="card-title">New Location</h2>
          </header>
          <div class="card-body">
            <div class="form-group mb-3">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="name" value="{{ old('name') }}" required>
            </div>
            <div class="form-group mb-3">
              <label>Code</label>
              <input type="text" class="form-control" name="code" value="{{ old('code') }}">
            </div>
          </div>
          <footer class="card-footer">
            <div class="row">
              <div class="col-md-12 text-end">
                <button type="submit" class="btn btn-primary">Create</button>
                <button class="btn btn-default modal-dismiss">Cancel</button>
              </div>
            </div>
          </footer>
        </form>
      </section>
    </div>
  </div>
</div>
@endsection