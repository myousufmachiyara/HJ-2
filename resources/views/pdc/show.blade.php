@extends('layouts.app')
@section('title', 'PDC | ' . $cheque->pdc_no)

@section('content')
<div class="row">
  <div class="col-md-8">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <section class="card mb-3">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">{{ $cheque->pdc_no }} — Cheque {{ $cheque->cheque_no }} <span class="badge {{ $cheque->statusBadge() }}">{{ $cheque->statusLabel() }}</span></h2>
        <div>
          <a href="{{ route('pdc_cheques.print', $cheque->id) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="fas fa-print"></i> Print</a>
          @if($cheque->status === 'issued')
            @can('pdc_cheques.edit')<a href="{{ route('pdc_cheques.edit', $cheque->id) }}" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>@endcan
            @can('pdc_cheques.delete')
              <form action="{{ route('pdc_cheques.destroy', $cheque->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this cheque entry?')">
                @csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
              </form>
            @endcan
          @endif
        </div>
      </header>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th width="30%">Vendor</th><td>{{ $cheque->vendor->name ?? '-' }}</td></tr>
          <tr><th>Bank</th><td>{{ $cheque->bankAccount->name ?? '-' }}</td></tr>
          <tr><th>Amount</th><td><b>PKR {{ number_format($cheque->amount, 2) }}</b></td></tr>
          <tr><th>Issue Date</th><td>{{ $cheque->issue_date->format('d-M-Y') }}</td></tr>
          <tr><th>Cheque Date</th><td>{{ $cheque->cheque_date->format('d-M-Y') }} @if($cheque->isOverdue())<span class="badge bg-danger">date passed</span>@endif</td></tr>
          <tr><th>Remarks</th><td>{{ $cheque->remarks }}</td></tr>
          @if($cheque->replaces)<tr><th>Replaces</th><td><a href="{{ route('pdc_cheques.show', $cheque->replaces->id) }}">{{ $cheque->replaces->pdc_no }} / {{ $cheque->replaces->cheque_no }}</a></td></tr>@endif
          @if($cheque->replacedBy)<tr><th>Replaced by</th><td><a href="{{ route('pdc_cheques.show', $cheque->replacedBy->id) }}">{{ $cheque->replacedBy->pdc_no }} / {{ $cheque->replacedBy->cheque_no }}</a></td></tr>@endif
        </table>
      </div>
    </section>

    <section class="card mb-3">
      <header class="card-header"><h2 class="card-title">Against Bills</h2></header>
      <div class="card-body">
        <table class="table table-sm table-bordered mb-0">
          <thead><tr><th>Bill</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
            @forelse($cheque->bills as $b)<tr><td>{{ $b->billLabel() }}</td><td class="text-end">{{ number_format($b->amount, 2) }}</td></tr>
            @empty<tr><td colspan="2" class="text-muted">Not allocated to bills (advance / on-account).</td></tr>@endforelse
          </tbody>
          <tfoot><tr><th>Unallocated</th><th class="text-end">{{ number_format($cheque->amount - $cheque->bills->sum('amount'), 2) }}</th></tr></tfoot>
        </table>
      </div>
    </section>
  </div>

  <div class="col-md-4">
    @can('pdc_cheques.edit')
    @if(count($flow))
    <section class="card mb-3">
      <header class="card-header"><h2 class="card-title">Update Status</h2></header>
      <div class="card-body">
        <form action="{{ route('pdc_cheques.status', $cheque->id) }}" method="POST">
          @csrf
          <div class="mb-2"><label>New status</label>
            <select name="status" class="form-control" required>
              @foreach($flow as $st)<option value="{{ $st }}">{{ $statuses[$st]['label'] }}{{ $st === 'replaced' ? ' (issue new cheque)' : '' }}</option>@endforeach
            </select></div>
          <div class="mb-2"><label>Date</label><input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required></div>
          <div class="mb-2"><label>Remarks</label><input type="text" name="remarks" class="form-control" placeholder="e.g. bounce reason"></div>
          <button class="btn btn-primary w-100">Update</button>
        </form>
        <small class="text-muted d-block mt-2">Cleared → bank is credited. Bounced / Cancelled → the vendor is owed again.</small>
      </div>
    </section>
    @endif
    @endcan

    <section class="card">
      <header class="card-header"><h2 class="card-title">History</h2></header>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          @foreach($cheque->logs as $log)
            <li class="list-group-item small">
              <b>{{ $log->date->format('d-M-Y') }}</b> — {{ $statuses[$log->from_status]['label'] ?? 'New' }} → <b>{{ $statuses[$log->to_status]['label'] ?? $log->to_status }}</b>
              @if($log->remarks)<br><span class="text-muted">{{ $log->remarks }}</span>@endif
              <br><span class="text-muted">by {{ $log->user->name ?? '-' }}</span>
            </li>
          @endforeach
        </ul>
      </div>
    </section>
  </div>
</div>
@endsection
