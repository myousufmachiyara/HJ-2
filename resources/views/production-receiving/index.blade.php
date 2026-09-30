@extends('layouts.app')

@section('title', 'Production Receiving | All Entries')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Finished Goods Receivings</h2>
        <div>
          <a href="{{ route('production_receiving.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Receiving</a>
        </div>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped" id="datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>GRN No</th>
                <th>Receiving Date</th>
                <th>Vendor</th>
                <th>Total Qty</th>
                @can('reports.accounts')<th>CMT Bill</th>@endcan
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach($receivings as $rec)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $rec->grn_no }}</td>
                <td>{{ \Carbon\Carbon::parse($rec->rec_date)->format('d-m-Y') }}</td>
                <td>{{ $rec->vendor->name ?? '-' }}</td>
                <td>{{ $rec->details->sum('received_qty') }}</td>
                @can('reports.accounts')<td>{{ number_format($rec->total_amount, 2) }}</td>@endcan
                <td>
                  <a href="{{ route('production_receiving.print', $rec->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                  <a href="{{ route('production_receiving.edit', $rec->id) }}" class="text-primary"><i class="fa fa-edit"></i></a>
                  @can('production_receiving.delete')
                  <form action="{{ route('production_receiving.destroy', $rec->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this receiving?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 text-danger"><i class="fas fa-trash-alt"></i></button>
                  </form>
                  @endcan
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
  $(document).ready(() => {
    $('#datatable').DataTable({ pageLength: 100 });
  });
</script>
@endsection
