@extends('layouts.app')
@section('title', 'Stock Adjustments')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
      @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Stock Adjustments / Opening Stock</h2>
        @can('stock_adjustments.create')
          <a href="{{ route('stock_adjustments.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Adjustment</a>
        @endcan
      </header>
      <div class="card-body">
        <table class="table table-bordered table-striped" id="adjTable">
          <thead>
            <tr><th>Adj #</th><th>Date</th><th>Type</th><th>Location</th><th class="text-end">Qty In</th><th class="text-end">Qty Out</th><th class="text-end">Net Value</th><th>Remarks</th><th>Action</th></tr>
          </thead>
          <tbody>
            @foreach($adjustments as $a)
              <tr>
                <td>{{ $a->adj_no }}</td>
                <td>{{ \Carbon\Carbon::parse($a->date)->format('d-M-Y') }}</td>
                <td>{{ $a->typeLabel() }}</td>
                <td>{{ $a->location->name ?? '-' }}</td>
                <td class="text-end">{{ number_format($a->items->where('quantity', '>', 0)->sum('quantity'), 2) }}</td>
                <td class="text-end">{{ number_format(abs($a->items->where('quantity', '<', 0)->sum('quantity')), 2) }}</td>
                <td class="text-end">{{ number_format($a->items->sum(fn($i) => $i->quantity * $i->unit_cost), 2) }}</td>
                <td>{{ $a->remarks }}</td>
                <td class="text-nowrap">
                  <a href="{{ route('stock_adjustments.print', $a->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                  @can('stock_adjustments.edit')<a href="{{ route('stock_adjustments.edit', $a->id) }}" class="text-primary"><i class="fas fa-edit"></i></a>@endcan
                  @can('stock_adjustments.delete')
                    <form action="{{ route('stock_adjustments.destroy', $a->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete {{ $a->adj_no }}?')">
                      @csrf @method('DELETE')
                      <button class="btn btn-link p-0 text-danger"><i class="fas fa-trash-alt"></i></button>
                    </form>
                  @endcan
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
<script>$(function () { $('#adjTable').DataTable({ pageLength: 50, order: [] }); });</script>
@endsection
