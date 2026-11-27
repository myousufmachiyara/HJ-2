@extends('layouts.app')
@section('title', 'CMT Bills')

@section('content')
<div class="row"><div class="col">
  <section class="card">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-warning" style="white-space: pre-line">{{ session('error') }}</div>@endif
    <header class="card-header d-flex justify-content-between">
      <h2 class="card-title">CMT Bills</h2>
      <a href="{{ route('cmt_bills.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New CMT Bill</a>
    </header>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-striped" id="datatable">
          <thead><tr><th>#</th><th>Bill No</th><th>Bill Date</th><th>Vendor</th><th>Period</th><th>Qty</th><th>CMT Amount</th><th>Fabric Cost</th><th>Action</th></tr></thead>
          <tbody>
          @foreach($bills as $b)
            <tr>
              <td>{{ $loop->iteration }}</td>
              <td>{{ $b->bill_no }}</td>
              <td>{{ \Carbon\Carbon::parse($b->bill_date)->format('d-m-Y') }}</td>
              <td>{{ $b->vendor->name ?? '-' }}</td>
              <td>{{ \Carbon\Carbon::parse($b->date_from)->format('d-m-Y') }} → {{ \Carbon\Carbon::parse($b->date_to)->format('d-m-Y') }}</td>
              <td>{{ (float) $b->total_qty }}</td>
              <td class="text-end">{{ number_format($b->cmt_amount, 2) }}</td>
              <td class="text-end">{{ number_format($b->fabric_amount, 2) }}</td>
              <td>
                <a href="{{ route('cmt_bills.show', $b->id) }}" class="text-primary"><i class="fas fa-eye"></i></a>
                <a href="{{ route('cmt_bills.print', $b->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                @can('production_receiving.delete')
                <form action="{{ route('cmt_bills.destroy', $b->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this CMT Bill? Its receivings become unbilled again.')">
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
</div></div>
<script>$(function () { $('#datatable').DataTable({ pageLength: 100, order: [[1, 'desc']] }); });</script>
@endsection
