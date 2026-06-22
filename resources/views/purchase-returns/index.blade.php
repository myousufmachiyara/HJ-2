@extends('layouts.app')
@section('title', 'Purchase Returns')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">

      @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">All Purchase Returns</h2>
        <a href="{{ route('purchase_return.create') }}" class="btn btn-primary">
          <i class="fas fa-plus"></i> New Return
        </a>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped" id="returnTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Return #</th>
                <th>Return Date</th>
                <th>Vendor</th>
                <th>Bill No</th>
                <th>Ref No</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($returns as $index => $return)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td>{{ $return->return_no ?? '-' }}</td>
                  <td>{{ Carbon\Carbon::parse($return->return_date)->format('d-M-Y') }}</td>
                  <td>{{ $return->vendor->name ?? 'N/A' }}</td>
                  <td>{{ $return->bill_no ?? '-' }}</td>
                  <td>{{ $return->ref_no ?? '-' }}</td>
                  <td>
                    <a href="{{ route('purchase_return.edit', $return->id) }}" class="text-primary">
                      <i class="fas fa-edit"></i>
                    </a>
                    <a href="{{ route('purchase_return.print', $return->id) }}" target="_blank" class="text-success">
                      <i class="fas fa-print"></i>
                    </a>
                    <form action="{{ route('purchase_return.destroy', $return->id) }}" method="POST" style="display:inline;">
                      @csrf
                      @method('DELETE')
                      <button class="btn btn-link p-0 m-0 text-danger"
                              onclick="return confirm('Delete this return?')">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </form>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
  $(document).ready(function () {
    $('#returnTable').DataTable({ pageLength: 50, order: [[0, 'desc']] });
  });
</script>
@endsection