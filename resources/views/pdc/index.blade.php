@extends('layouts.app')
@section('title', 'PDC | Cheques Issued')

@section('content')
<div class="row">
  <div class="col">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="row mb-3">
      <div class="col-md-3"><div class="card card-body py-2"><small class="text-muted">Pending (issued + presented)</small><h4 class="mb-0">PKR {{ number_format($summary['pending_amount'], 0) }}</h4><small>{{ $summary['pending_count'] }} cheque(s)</small></div></div>
      <div class="col-md-3"><div class="card card-body py-2"><small class="text-muted">Due in next 7 days</small><h4 class="mb-0 text-warning">PKR {{ number_format($summary['due_7_amount'], 0) }}</h4></div></div>
      <div class="col-md-3"><div class="card card-body py-2"><small class="text-muted">Past cheque date, not cleared</small><h4 class="mb-0 text-danger">PKR {{ number_format($summary['overdue_amount'], 0) }}</h4></div></div>
      <div class="col-md-3"><div class="card card-body py-2"><small class="text-muted">Bounced (to replace)</small><h4 class="mb-0 text-danger">{{ $summary['bounced_count'] }}</h4></div></div>
    </div>

    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Post-Dated Cheques to Vendors</h2>
        @can('pdc_cheques.create')<a href="{{ route('pdc_cheques.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Issue Cheque</a>@endcan
      </header>
      <div class="card-body">
        <form method="GET" class="row g-2 mb-3 align-items-end">
          <div class="col-md-2"><label>Status</label>
            <select name="status" class="form-control"><option value="">All</option>
              @foreach($statuses as $k => $s)<option value="{{ $k }}" {{ request('status') === $k ? 'selected' : '' }}>{{ $s['label'] }}</option>@endforeach
            </select></div>
          <div class="col-md-3"><label>Vendor</label>
            <select name="vendor_id" class="form-control select2-js"><option value="">All</option>
              @foreach($vendors as $v)<option value="{{ $v->id }}" {{ request('vendor_id') == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>@endforeach
            </select></div>
          <div class="col-md-2"><label>Bank</label>
            <select name="bank_account_id" class="form-control"><option value="">All</option>
              @foreach($banks as $b)<option value="{{ $b->id }}" {{ request('bank_account_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>@endforeach
            </select></div>
          <div class="col-md-2"><label>Cheque date from</label><input type="date" name="from" class="form-control" value="{{ request('from') }}"></div>
          <div class="col-md-2"><label>to</label><input type="date" name="to" class="form-control" value="{{ request('to') }}"></div>
          <div class="col-md-1"><button class="btn btn-primary w-100">Filter</button></div>
        </form>

        <table class="table table-bordered table-striped table-sm" id="pdcTable">
          <thead><tr><th>PDC #</th><th>Cheque #</th><th>Vendor</th><th>Bank</th><th>Issued</th><th>Cheque Date</th><th class="text-end">Amount</th><th>Bills</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            @foreach($cheques as $c)
              <tr class="{{ $c->isOverdue() ? 'table-warning' : '' }}">
                <td>{{ $c->pdc_no }}</td>
                <td>{{ $c->cheque_no }}</td>
                <td>{{ $c->vendor->name ?? '-' }}</td>
                <td>{{ $c->bankAccount->name ?? '-' }}</td>
                <td data-order="{{ $c->issue_date->format('Y-m-d') }}">{{ $c->issue_date->format('d-M-Y') }}</td>
                <td data-order="{{ $c->cheque_date->format('Y-m-d') }}">{{ $c->cheque_date->format('d-M-Y') }}@if($c->isOverdue()) <i class="fas fa-exclamation-circle text-danger" title="Cheque date passed"></i>@endif</td>
                <td class="text-end">{{ number_format($c->amount, 2) }}</td>
                <td>{{ $c->bills->count() }}</td>
                <td><span class="badge {{ $c->statusBadge() }}">{{ $c->statusLabel() }}</span></td>
                <td class="text-nowrap">
                  <a href="{{ route('pdc_cheques.show', $c->id) }}" class="text-primary" title="Open / change status"><i class="fas fa-eye"></i></a>
                  <a href="{{ route('pdc_cheques.print', $c->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                </td>
              </tr>
            @endforeach
          </tbody>
          <tfoot><tr><th colspan="6" class="text-end">Total</th><th class="text-end">{{ number_format($cheques->sum('amount'), 2) }}</th><th colspan="3"></th></tr></tfoot>
        </table>
      </div>
    </section>
  </div>
</div>
<script>$(function () { $('.select2-js').select2({ width: '100%' }); $('#pdcTable').DataTable({ pageLength: 50, order: [[5, 'asc']] }); });</script>
@endsection
