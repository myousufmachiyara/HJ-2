@extends('layouts.app')

@section('title', 'Sales Reports')

@section('content')
@php
  $tabs = ['SR' => 'Sales Register', 'SRET' => 'Sales Returns', 'CW' => 'Customer / Marketplace Wise', 'IW' => 'Item Wise', 'LW' => 'Location Wise'];
  $q = ['from_date' => $from, 'to_date' => $to, 'customer_id' => $customerId, 'location_id' => $locationId];
@endphp
<div class="tabs">
  <ul class="nav nav-tabs flex-wrap">
    @foreach($tabs as $k => $label)
      <li class="nav-item"><a class="nav-link {{ $tab === $k ? 'active' : '' }}" href="{{ route('reports.sale', ['tab' => $k] + $q) }}">{{ $label }}</a></li>
    @endforeach
  </ul>

  <div class="tab-content mt-3">
    <form method="GET" action="{{ route('reports.sale') }}" class="row g-2 mb-3 align-items-end">
      <input type="hidden" name="tab" value="{{ $tab }}">
      <div class="col-md-2"><label>From</label><input type="date" class="form-control" name="from_date" value="{{ $from }}"></div>
      <div class="col-md-2"><label>To</label><input type="date" class="form-control" name="to_date" value="{{ $to }}"></div>
      <div class="col-md-3"><label>Customer / Marketplace</label>
        <select name="customer_id" class="form-control select2-js">
          <option value="">All</option>
          @foreach($customers as $c)<option value="{{ $c->id }}" {{ $customerId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
        </select></div>
      <div class="col-md-3"><label>Dispatch / Return Location</label>
        <select name="location_id" class="form-control select2-js">
          <option value="">All</option>
          @foreach($locations as $l)<option value="{{ $l->id }}" {{ $locationId == $l->id ? 'selected' : '' }}>{{ $l->name }}</option>@endforeach
        </select></div>
      <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Filter</button></div>
    </form>

    @php $n = fn($v) => number_format((float) $v, 2); @endphp

    @if($tab === 'SR')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Dispatch From</th><th class="text-end">Qty</th><th class="text-end">Net Amount</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th><th class="text-end">Received</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr>
              <td data-order="{{ $r->date }}">{{ \Carbon\Carbon::parse($r->date)->format('d-M-Y') }}</td>
              <td><a href="{{ route('sale_invoices.show', $r->id) }}">{{ $r->invoice }}</a></td>
              <td>{{ $r->customer }}</td><td>{{ $r->location }}</td>
              <td class="text-end">{{ $n($r->qty) }}</td><td class="text-end">{{ $n($r->total) }}</td><td class="text-end">{{ $n($r->cost) }}</td>
              <td class="text-end {{ $r->profit < 0 ? 'text-danger' : 'text-success' }}">{{ $n($r->profit) }}</td>
              <td class="text-end">{{ $n($r->paid) }}</td><td class="text-end">{{ $n($r->balance) }}</td><td>{{ ucfirst($r->status) }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="4" class="text-end">Total</td>
          <td class="text-end">{{ $n($rows->sum('qty')) }}</td><td class="text-end">{{ $n($rows->sum('total')) }}</td><td class="text-end">{{ $n($rows->sum('cost')) }}</td>
          <td class="text-end">{{ $n($rows->sum('profit')) }}</td><td class="text-end">{{ $n($rows->sum('paid')) }}</td><td class="text-end">{{ $n($rows->sum('balance')) }}</td><td></td></tr></tfoot>
      </table>
    @endif

    @if($tab === 'SRET')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Date</th><th>Return #</th><th>Against Inv</th><th>Customer</th><th>Returned To</th><th class="text-end">Qty</th><th class="text-end">Return Value</th><th class="text-end">Cash Refund</th><th class="text-end">Cost Reversed</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr><td data-order="{{ $r->date }}">{{ \Carbon\Carbon::parse($r->date)->format('d-M-Y') }}</td><td>{{ $r->invoice }}</td><td>{{ $r->ref ?? '-' }}</td><td>{{ $r->customer }}</td><td>{{ $r->location }}</td>
              <td class="text-end">{{ $n($r->qty) }}</td><td class="text-end">{{ $n($r->total) }}</td><td class="text-end">{{ $n($r->refund) }}</td><td class="text-end">{{ $n($r->cost) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="5" class="text-end">Total</td><td class="text-end">{{ $n($rows->sum('qty')) }}</td><td class="text-end">{{ $n($rows->sum('total')) }}</td><td class="text-end">{{ $n($rows->sum('refund')) }}</td><td class="text-end">{{ $n($rows->sum('cost')) }}</td></tr></tfoot>
      </table>
    @endif

    @if($tab === 'CW' || $tab === 'LW')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>{{ $tab === 'CW' ? 'Customer / Marketplace' : 'Location' }}</th><th class="text-end">Invoices</th><th class="text-end">Net Qty</th><th class="text-end">Sales</th><th class="text-end">Returns</th><th class="text-end">Net Sales</th><th class="text-end">Received on Invoices</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr><td>{{ $r->name }}</td><td class="text-end">{{ $r->count }}</td><td class="text-end">{{ $n($r->qty) }}</td><td class="text-end">{{ $n($r->sales) }}</td><td class="text-end">{{ $n($r->returns) }}</td>
              <td class="text-end fw-bold">{{ $n($r->net) }}</td><td class="text-end">{{ $n($r->received) }}</td><td class="text-end">{{ $n($r->cost) }}</td>
              <td class="text-end {{ $r->profit < 0 ? 'text-danger' : 'text-success' }}">{{ $n($r->profit) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td>Total</td><td class="text-end">{{ $rows->sum('count') }}</td><td class="text-end">{{ $n($rows->sum('qty')) }}</td><td class="text-end">{{ $n($rows->sum('sales')) }}</td><td class="text-end">{{ $n($rows->sum('returns')) }}</td><td class="text-end">{{ $n($rows->sum('net')) }}</td><td class="text-end">{{ $n($rows->sum('received')) }}</td><td class="text-end">{{ $n($rows->sum('cost')) }}</td><td class="text-end">{{ $n($rows->sum('profit')) }}</td></tr></tfoot>
      </table>
      <small class="text-muted">Payments received through Receipt vouchers (not against a specific invoice) show in the customer's ledger, not in "Received on Invoices".</small>
    @endif

    @if($tab === 'IW')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Product</th><th>Variation</th><th class="text-end">Sold</th><th class="text-end">Returned</th><th class="text-end">Net Qty</th><th class="text-end">Net Sales</th><th class="text-end">Cost</th><th class="text-end">Gross Profit</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr><td>{{ $r->product }}</td><td>{{ $r->variation ?? '—' }}</td><td class="text-end">{{ $n($r->qty) }}</td><td class="text-end">{{ $n($r->ret_qty) }}</td><td class="text-end fw-bold">{{ $n($r->net_qty) }}</td>
              <td class="text-end">{{ $n($r->net) }}</td><td class="text-end">{{ $n($r->cost) }}</td><td class="text-end {{ $r->profit < 0 ? 'text-danger' : 'text-success' }}">{{ $n($r->profit) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="2" class="text-end">Total</td><td class="text-end">{{ $n($rows->sum('qty')) }}</td><td class="text-end">{{ $n($rows->sum('ret_qty')) }}</td><td class="text-end">{{ $n($rows->sum('net_qty')) }}</td><td class="text-end">{{ $n($rows->sum('net')) }}</td><td class="text-end">{{ $n($rows->sum('cost')) }}</td><td class="text-end">{{ $n($rows->sum('profit')) }}</td></tr></tfoot>
      </table>
      <small class="text-muted">Item-wise sales are line values before bill-level discount; returns without a matching sale in the period are not listed.</small>
    @endif
  </div>
</div>
<script>
  $(function () {
    $('.select2-js').select2({ width: '100%' });
    $('#repTable').DataTable({ pageLength: 100, order: [] });
  });
</script>
@endsection
