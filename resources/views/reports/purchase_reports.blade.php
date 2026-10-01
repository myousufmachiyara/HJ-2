@extends('layouts.app')
@section('title', 'Purchase Reports')

@section('content')
@php
  $tabs = ['PUR' => 'Purchase Register', 'PR' => 'Purchase Returns', 'GRN' => 'CMT / FG Receiving', 'CMT' => 'CMT Fabric Summary', 'VWP' => 'Vendor Summary', 'PDC' => 'PDC Cheques'];
  $q = ['from_date' => $from, 'to_date' => $to, 'vendor_id' => $vendorId];
  $n = fn($v) => number_format((float) $v, 2);
@endphp
<div class="tabs">
  <ul class="nav nav-tabs flex-wrap">
    @foreach($tabs as $k => $label)
      <li class="nav-item"><a class="nav-link {{ $tab === $k ? 'active' : '' }}" href="{{ route('reports.purchase', ['tab' => $k] + $q) }}">{{ $label }}</a></li>
    @endforeach
  </ul>

  <div class="tab-content mt-3">
    <form method="GET" action="{{ route('reports.purchase') }}" class="row g-2 mb-3 align-items-end">
      <input type="hidden" name="tab" value="{{ $tab }}">
      <div class="col-md-2"><label>{{ $tab === 'PDC' ? 'Cheque date from' : 'From' }}</label><input type="date" name="from_date" class="form-control" value="{{ $from }}"></div>
      <div class="col-md-2"><label>To</label><input type="date" name="to_date" class="form-control" value="{{ $to }}"></div>
      <div class="col-md-3"><label>Vendor</label>
        <select name="vendor_id" class="form-control select2-js"><option value="">All Vendors</option>
          @foreach($vendors as $v)<option value="{{ $v->id }}" {{ $vendorId == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>@endforeach
        </select></div>
      @if($tab === 'PUR')
        <div class="col-md-3"><label>Drop-off Location</label>
          <select name="location_id" class="form-control select2-js"><option value="">All</option>
            @foreach($locations as $l)<option value="{{ $l->id }}" {{ $locId == $l->id ? 'selected' : '' }}>{{ $l->name }}</option>@endforeach
          </select></div>
      @endif
      @if($tab === 'PDC')
        <div class="col-md-2"><label>Status</label>
          <select name="status" class="form-control"><option value="">All</option>
            @foreach(\App\Models\PdcCheque::STATUSES as $k => $st)<option value="{{ $k }}" {{ request('status') === $k ? 'selected' : '' }}>{{ $st['label'] }}</option>@endforeach
          </select></div>
      @endif
      <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button></div>
    </form>

    @if($tab === 'PUR')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Date</th><th>Invoice</th><th>Bill #</th><th>Vendor</th><th>Drop-off</th><th>Item</th><th>Variation</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Total</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr><td data-order="{{ $r->date }}">{{ \Carbon\Carbon::parse($r->date)->format('d-M-Y') }}</td><td>{{ $r->invoice_no }}</td><td>{{ $r->bill_no }}</td><td>{{ $r->vendor_name }}</td><td>{{ $r->dropoff }}</td>
              <td>{{ $r->item_name }}</td><td>{{ $r->variation ?? '—' }}</td><td>{{ $r->unit }}</td><td class="text-end">{{ $n($r->quantity) }}</td><td class="text-end">{{ $n($r->rate) }}</td><td class="text-end">{{ $n($r->total) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="8" class="text-end">Total (before conveyance / labour / discount)</td><td class="text-end">{{ $n($rows->sum('quantity')) }}</td><td></td><td class="text-end">{{ $n($rows->sum('total')) }}</td></tr></tfoot>
      </table>
    @endif

    @if($tab === 'PR')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Date</th><th>Return #</th><th>Vendor</th><th>Item</th><th>Variation</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Total</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr><td data-order="{{ $r->date }}">{{ \Carbon\Carbon::parse($r->date)->format('d-M-Y') }}</td><td>{{ $r->return_no }}</td><td>{{ $r->vendor_name }}</td><td>{{ $r->item_name }}</td><td>{{ $r->variation ?? '—' }}</td><td>{{ $r->unit }}</td>
              <td class="text-end">{{ $n($r->quantity) }}</td><td class="text-end">{{ $n($r->rate) }}</td><td class="text-end">{{ $n($r->total) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="6" class="text-end">Total</td><td class="text-end">{{ $n($rows->sum('quantity')) }}</td><td></td><td class="text-end">{{ $n($rows->sum('total')) }}</td></tr></tfoot>
      </table>
    @endif

    @if($tab === 'GRN')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Date</th><th>GRN #</th><th>CMT Vendor</th><th class="text-end">Pieces</th><th class="text-end">CMT Bill</th><th>Fabric</th><th class="text-end">Fabric Consumed</th><th class="text-end">Fabric Value</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr class="{{ $r->missing_cost ? 'table-warning' : '' }}">
              <td data-order="{{ $r->date }}">{{ \Carbon\Carbon::parse($r->date)->format('d-M-Y') }}</td>
              <td><a href="{{ route('production_receiving.print', $r->id) }}" target="_blank">{{ $r->grn_no }}</a></td>
              <td>{{ $r->vendor_name }}</td><td class="text-end">{{ $n($r->pcs) }}</td>
              <td class="text-end">{{ $n($r->cmt_bill) }} @if($r->missing_cost)<i class="fas fa-exclamation-triangle text-warning" title="Some items have no CMT cost"></i>@endif</td>
              <td>{{ $r->fabric ?: '—' }}</td><td class="text-end">{{ $r->fabric_qty > 0 ? number_format($r->fabric_qty, 3) : '—' }}</td><td class="text-end">{{ $n($r->fabric_value) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="3" class="text-end">Total</td><td class="text-end">{{ $n($rows->sum('pcs')) }}</td><td class="text-end">{{ $n($rows->sum('cmt_bill')) }}</td><td></td><td class="text-end">{{ number_format($rows->sum('fabric_qty'), 3) }}</td><td class="text-end">{{ $n($rows->sum('fabric_value')) }}</td></tr></tfoot>
      </table>
      <small class="text-muted">Highlighted GRNs contain products without a CMT cost — set it on the product and re-save the GRN.</small>
    @endif

    @if($tab === 'CMT')
      <div class="alert alert-info py-2">
        <i class="fas fa-info-circle me-1"></i>
        Fabric at each CMT vendor: <strong>given</strong> (purchases dropped there, stock moved in, opening) −
        <strong>consumed</strong> by finished goods received (pieces × consumption from the fabric's article setup) −
        moved back = <strong>should remain</strong> with the vendor on {{ \Carbon\Carbon::parse($to)->format('d-M-Y') }}.
        <em>Can still make</em> is per article if the whole remaining fabric is used for that one article.
        Red = vendor used more than was given → check consumption or post a Physical Count.
      </div>
      @forelse($rows as $v)
        <section class="card mb-3">
          <header class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title mb-0"><i class="fas fa-user-tie me-1"></i> {{ $v->vendor }}</h2>
            @can('stock_adjustments.create')
              <a href="{{ route('stock_adjustments.create', ['type' => 'count', 'location_id' => $v->location->id]) }}" class="btn btn-outline-secondary btn-sm">Post physical count</a>
            @endcan
          </header>
          <div class="card-body p-0">
            <table class="table table-bordered table-sm mb-0">
              <thead class="table-light"><tr><th>Fabric</th><th>Panna</th><th class="text-end">Opening</th><th class="text-end">Given</th><th class="text-end">Consumed</th><th class="text-end">Moved back / adj.</th><th class="text-end">Should remain</th><th class="text-end">Value</th></tr></thead>
              <tbody>
                @foreach($v->fabrics as $f)
                  <tr class="{{ $f->remaining < 0 ? 'table-danger' : '' }}">
                    <td class="fw-bold">{{ $f->fabric }}</td><td>{{ $f->panna ?? '—' }}</td>
                    <td class="text-end">{{ number_format($f->opening, 2) }}</td><td class="text-end">{{ number_format($f->given, 2) }}</td>
                    <td class="text-end">{{ number_format($f->consumed, 2) }}</td><td class="text-end">{{ number_format($f->other_out, 2) }}</td>
                    <td class="text-end fw-bold {{ $f->remaining < 0 ? 'text-danger' : 'text-primary' }}">{{ number_format($f->remaining, 2) }}</td>
                    <td class="text-end">{{ $n($f->value) }}</td>
                  </tr>
                  @if($f->articles->isNotEmpty() || $f->can_make->isNotEmpty())
                    <tr><td></td><td colspan="7" class="small">
                      @if($f->articles->isNotEmpty())
                        <div><strong>Received in period:</strong>
                          @foreach($f->articles as $a)<span class="me-3">{{ $a->article }}: <b>{{ number_format($a->pcs, 0) }} pcs</b> ({{ number_format($a->fabric, 2) }})</span>@endforeach
                        </div>
                      @endif
                      @if($f->can_make->isNotEmpty() && $f->remaining > 0)
                        <div class="text-muted"><strong>Can still make (either / or):</strong>
                          @foreach($f->can_make as $c)<span class="me-3">{{ $c->article }} ≈ <b>{{ number_format($c->pcs, 0) }}</b></span>@endforeach
                        </div>
                      @endif
                    </td></tr>
                  @endif
                @endforeach
              </tbody>
            </table>
          </div>
        </section>
      @empty
        <div class="alert alert-secondary">No fabric at any CMT vendor for the selected period.</div>
      @endforelse
    @endif

    @if($tab === 'VWP')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Vendor</th><th class="text-end">Purchases</th><th class="text-end">CMT Bills</th><th class="text-end">Returns</th><th class="text-end">Net Billed</th><th class="text-end">PDC Issued (period)</th><th class="text-end">PDC Not Yet Cleared</th><th class="text-end">Balance Payable (as of {{ \Carbon\Carbon::parse($to)->format('d-M-Y') }})</th></tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr><td>{{ $r->vendor }}</td><td class="text-end">{{ $n($r->purchases) }}</td><td class="text-end">{{ $n($r->cmt) }}</td><td class="text-end">{{ $n($r->returns) }}</td><td class="text-end fw-bold">{{ $n($r->net) }}</td>
              <td class="text-end">{{ $n($r->pdc_issued) }}</td><td class="text-end">{{ $n($r->pdc_pending) }}</td><td class="text-end fw-bold {{ $r->balance < 0 ? 'text-success' : 'text-danger' }}">{{ $n($r->balance) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td>Total</td><td class="text-end">{{ $n($rows->sum('purchases')) }}</td><td class="text-end">{{ $n($rows->sum('cmt')) }}</td><td class="text-end">{{ $n($rows->sum('returns')) }}</td><td class="text-end">{{ $n($rows->sum('net')) }}</td><td class="text-end">{{ $n($rows->sum('pdc_issued')) }}</td><td class="text-end">{{ $n($rows->sum('pdc_pending')) }}</td><td class="text-end">{{ $n($rows->sum('balance')) }}</td></tr></tfoot>
      </table>
      <small class="text-muted">Balance payable = opening balance + all bills − payments − cheques issued (a cheque reduces the vendor balance when issued; bounced/cancelled cheques add it back). Negative = advance / vendor owes us.</small>
    @endif

    @if($tab === 'PDC')
      <table class="table table-bordered table-striped table-sm" id="repTable">
        <thead class="table-light"><tr><th>Cheque Date</th><th>PDC #</th><th>Cheque #</th><th>Vendor</th><th>Bank</th><th>Issued</th><th class="text-end">Amount</th><th>Against Bills</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($rows as $c)
            <tr class="{{ $c->isOverdue() ? 'table-warning' : '' }}">
              <td data-order="{{ $c->cheque_date->format('Y-m-d') }}">{{ $c->cheque_date->format('d-M-Y') }}</td>
              <td><a href="{{ route('pdc_cheques.show', $c->id) }}">{{ $c->pdc_no }}</a></td><td>{{ $c->cheque_no }}</td><td>{{ $c->vendor->name ?? '-' }}</td><td>{{ $c->bankAccount->name ?? '-' }}</td>
              <td>{{ $c->issue_date->format('d-M-Y') }}</td><td class="text-end">{{ $n($c->amount) }}</td>
              <td><small>{{ $c->bills->map(fn($b) => $b->billLabel())->implode(', ') ?: 'Advance' }}</small></td>
              <td><span class="badge {{ $c->statusBadge() }}">{{ $c->statusLabel() }}</span></td></tr>
          @endforeach
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="6" class="text-end">Total</td><td class="text-end">{{ $n($rows->sum('amount')) }}</td><td colspan="2"></td></tr></tfoot>
      </table>
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
