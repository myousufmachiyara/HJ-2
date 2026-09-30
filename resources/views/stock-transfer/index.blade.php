@extends('layouts.app')

@section('title', 'Stock Movements')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">All Stock Movements</h2>
        <a href="{{ route('stock_transfer.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Movement</a>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover datatable">
            <thead class="thead-dark">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Type</th>
                <th>From Location</th>
                <th>To Location</th>
                <th>Total Qty</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            @foreach ($transfers as $transfer)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ \Carbon\Carbon::parse($transfer->date)->format('d-M-Y') }}</td>
                <td>{{ $transfer->typeLabel() }}</td>
                <td>{{ $transfer->fromLocation->name ?? '-' }}</td>
                <td>{{ $transfer->toLocation->name ?? '-' }}</td>
                <td>{{ $transfer->details->sum('quantity') }}</td>
                <td>
                  <a href="{{ route('stock_transfer.edit', $transfer->id) }}" class="text-primary"><i class="fas fa-edit"></i></a>
                  <a href="{{ route('stock_transfer.print', $transfer->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                  <form action="{{ route('stock_transfer.destroy', $transfer->id) }}" method="POST" style="display:inline;">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-link p-0 m-0 text-danger" onclick="return confirm('Are you sure?')"><i class="fas fa-trash-alt"></i></button>
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
    $('.datatable').DataTable();
  });
</script>
@endsection
