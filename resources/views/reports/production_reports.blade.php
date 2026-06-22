@extends('layouts.app')
@section('title', 'Production Reports')

@section('content')
<div class="tabs">

  <ul class="nav nav-tabs flex-wrap">
    @foreach([
      'RMI' => ['fa-industry',      'Production Orders'],
      'PR'  => ['fa-box-open',      'Production Receiving'],
      'WST' => ['fa-recycle',       'Wastage Returns'],
      'CR'  => ['fa-calculator',    'Product Costing'],
      'VRB' => ['fa-warehouse',     'Vendor Raw Balance'],
      'RTN' => ['fa-undo',          'Production Returns'],
      'DLV' => ['fa-clock',         'Delivery Tracking'],
      'SUM' => ['fa-chart-bar',     'Vendor Summary'],
    ] as $key => [$icon, $label])
      <li class="nav-item">
        <a class="nav-link {{ $tab == $key ? 'active' : '' }}"
           href="{{ route('reports.production') }}?tab={{ $key }}&from_date={{ $from }}&to_date={{ $to }}">
          <i class="fas {{ $icon }} me-1"></i> {{ $label }}
        </a>
      </li>
    @endforeach
  </ul>

  <div class="tab-content mt-3">

    {{-- ── 1. PRODUCTION ORDERS ─────────────────────────────────── --}}
    @if($tab === 'RMI')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="RMI">
        @include('reports._filter', ['showVendor' => false])
      </form>

      @forelse($rawIssued as $order)
        @php
          $hasAlert   = $order->variance_alert ?? false;
          $isCritical = $order->variance_critical ?? false;
          $hdrClass   = $isCritical ? 'bg-danger text-white' : ($hasAlert ? 'bg-warning' : 'bg-light');
        @endphp
        <div class="card mb-3 {{ $isCritical ? 'border-danger' : ($hasAlert ? 'border-warning' : '') }}">
          <div class="card-header d-flex justify-content-between align-items-center {{ $hdrClass }}">
            <div>
              <strong>PO-{{ $order->id }}</strong>
              <span class="ms-2 badge bg-secondary">{{ $order->type }}</span>
              <span class="ms-2 small">{{ \Carbon\Carbon::parse($order->date)->format('d-M-Y') }}</span>
            </div>
            <div>
              <strong>{{ $order->vendor }}</strong>
              @if($isCritical)
                <span class="badge bg-danger ms-2">⚠ Critical Consumption</span>
              @elseif($hasAlert)
                <span class="badge bg-warning text-dark ms-2">⚠ High Consumption</span>
              @endif
            </div>
          </div>

          <div class="card-body">
            {{-- Summary Stats --}}
            <div class="row mb-3 text-center g-2">
              <div class="col-md-2 col-6">
                <div class="border rounded p-2">
                  <small class="text-muted d-block">Raw Given</small>
                  <strong>{{ number_format($order->total_raw_given, 2) }}</strong>
                </div>
              </div>
              <div class="col-md-2 col-6">
                <div class="border rounded p-2">
                  <small class="text-muted d-block">Raw Cost</small>
                  <strong>PKR {{ number_format($order->total_raw_cost, 0) }}</strong>
                </div>
              </div>
              <div class="col-md-2 col-6">
                <div class="border rounded p-2">
                  <small class="text-muted d-block">FG Received</small>
                  <strong class="text-success">{{ number_format($order->total_fg_received, 2) }}</strong>
                </div>
              </div>
              <div class="col-md-2 col-6">
                <div class="border rounded p-2">
                  <small class="text-muted d-block">Expected Consumed</small>
                  <strong class="text-primary">{{ number_format($order->expected_consumed, 4) }}</strong>
                </div>
              </div>
              <div class="col-md-2 col-6">
                <div class="border rounded p-2">
                  <small class="text-muted d-block">Extra Returned</small>
                  <strong class="text-success">{{ number_format($order->extra_returned, 3) }}</strong>
                  <small class="text-muted d-block" style="font-size:10px;">Back to Stock</small>
                </div>
              </div>
              <div class="col-md-2 col-6">
                <div class="border rounded p-2">
                  <small class="text-muted d-block">Wastage Returned</small>
                  <strong class="text-danger">{{ number_format($order->wastage_returned, 3) }}</strong>
                  <small class="text-muted d-block" style="font-size:10px;">Write-off</small>
                </div>
              </div>
            </div>

            <div class="row mb-3 text-center g-2">
              <div class="col-md-3 col-6">
                <div class="border rounded p-2 bg-light">
                  <small class="text-muted d-block">Raw at Vendor</small>
                  <strong class="{{ $order->raw_at_vendor > 0 ? 'text-warning' : 'text-success' }}">
                    {{ number_format($order->raw_at_vendor, 4) }}
                  </strong>
                </div>
              </div>
              @if($order->total_fg_received > 0)
                <div class="col-md-3 col-6">
                  <div class="border rounded p-2 bg-light">
                    <small class="text-muted d-block">Actual Con/pc</small>
                    <strong>{{ number_format($order->actual_con_per_pc, 4) }}</strong>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="border rounded p-2 bg-light">
                    <small class="text-muted d-block">Avg CMT Cost/pc</small>
                    <strong class="text-primary">PKR {{ number_format($order->avg_cmt_cost, 2) }}</strong>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="border rounded p-2 {{ $hasAlert ? 'bg-warning' : 'bg-light' }}">
                    <small class="text-muted d-block">Avg Product Cost/pc</small>
                    <strong class="text-success">PKR {{ number_format($order->avg_product_cost, 2) }}</strong>
                  </div>
                </div>
              @endif
            </div>

            @if($order->total_fg_received > 0 && $order->variance_pct !== null)
              <div class="alert {{ $isCritical ? 'alert-danger' : ($hasAlert ? 'alert-warning' : 'alert-success') }} py-2 mb-3">
                <i class="fas fa-chart-line me-1"></i>
                Consumption Variance:
                <strong>{{ $order->variance_pct > 0 ? '+' : '' }}{{ $order->variance_pct }}%</strong>
                <small class="ms-2 text-muted">
                  ({{ $order->variance_pct > 0 ? 'Over-consumed' : 'Under-consumed' }})
                </small>
              </div>
            @endif

            {{-- Raw Material Breakdown --}}
            <h6 class="text-muted mt-3">Raw Material Issued</h6>
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light">
                <tr>
                  <th>Item</th>
                  <th class="text-end">Qty</th>
                  <th class="text-end">Rate</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                @foreach($order->raw_details as $d)
                  <tr>
                    <td>{{ $d->product->name ?? '-' }}</td>
                    <td class="text-end">{{ number_format($d->qty, 2) }}</td>
                    <td class="text-end">{{ number_format($d->rate, 2) }}</td>
                    <td class="text-end">{{ number_format($d->qty * $d->rate, 2) }}</td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot class="table-light fw-bold">
                <tr>
                  <td colspan="3" class="text-end">Total Raw Cost</td>
                  <td class="text-end">{{ number_format($order->total_raw_cost, 2) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      @empty
        <div class="text-center text-muted py-4">No production orders found in this period.</div>
      @endforelse
    @endif

    {{-- ── 2. PRODUCTION RECEIVING ──────────────────────────────── --}}
    @if($tab === 'PR')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="PR">
        @include('reports._filter', ['showVendor' => false])
      </form>

      @php $prTotal = $produced->sum('total'); $prQty = $produced->sum('qty'); @endphp
      <div class="row mb-2">
        <div class="col text-end">
          <span class="me-3">Total Qty: <strong>{{ number_format($prQty, 2) }}</strong></span>
          <span>Total Cost: <strong class="text-danger">PKR {{ number_format($prTotal, 0) }}</strong></span>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="prTable">
          <thead class="table-light">
            <tr>
              <th>Date</th><th>GRN #</th><th>Vendor</th><th>Production #</th>
              <th>Item</th><th>Variation</th><th>Unit</th>
              <th class="text-end">Qty</th><th class="text-end">Mfg. Cost</th><th class="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            @forelse($produced as $row)
              <tr>
                <td>{{ \Carbon\Carbon::parse($row->date)->format('d-M-Y') }}</td>
                <td>{{ $row->grn_no }}</td>
                <td>{{ $row->vendor }}</td>
                <td>
                  @if($row->production_id !== '-')
                    <a href="{{ route('production.edit', $row->production_id) }}">PO-{{ $row->production_id }}</a>
                  @else —
                  @endif
                </td>
                <td>{{ $row->item_name }}</td>
                <td>{{ $row->variation !== '-' ? $row->variation : '—' }}</td>
                <td>{{ $row->unit }}</td>
                <td class="text-end">{{ number_format($row->qty, 2) }}</td>
                <td class="text-end">{{ number_format($row->mfg_cost, 2) }}</td>
                <td class="text-end">{{ number_format($row->total, 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted">No production receiving found.</td></tr>
            @endforelse
          </tbody>
          @if($produced->count())
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="7" class="text-end">Total</td>
                <td class="text-end">{{ number_format($prQty, 2) }}</td>
                <td></td>
                <td class="text-end">{{ number_format($prTotal, 2) }}</td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 3. WASTAGE RETURNS ───────────────────────────────────── --}}
    @if($tab === 'WST')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="WST">
        @include('reports._filter', ['showVendor' => false])
      </form>

      @php
        $wstExtra   = $wastageReport->where('return_type', 'extra')->sum('qty');
        $wstWastage = $wastageReport->where('return_type', 'wastage')->sum('qty');
      @endphp
      <div class="row mb-3 text-center g-2">
        <div class="col-md-3">
          <div class="border rounded p-2 bg-success bg-opacity-10">
            <small class="text-muted d-block">Extra Returned (Back to Stock)</small>
            <strong class="text-success">{{ number_format($wstExtra, 3) }}</strong>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-2 bg-danger bg-opacity-10">
            <small class="text-muted d-block">Wastage Returned (Write-off)</small>
            <strong class="text-danger">{{ number_format($wstWastage, 3) }}</strong>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-2">
            <small class="text-muted d-block">Total Returned</small>
            <strong>{{ number_format($wstExtra + $wstWastage, 3) }}</strong>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="wstTable">
          <thead class="table-light">
            <tr>
              <th>Date</th><th>WRN #</th><th>Vendor</th><th>Production #</th>
              <th>Raw Material</th><th>Variation</th>
              <th>Type</th>
              <th class="text-end">Qty</th><th>Unit</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            @forelse($wastageReport as $w)
              <tr class="{{ $w->return_type === 'wastage' ? 'table-danger' : '' }}">
                <td>{{ \Carbon\Carbon::parse($w->date)->format('d-M-Y') }}</td>
                <td><strong class="text-primary">{{ $w->wrn_no }}</strong></td>
                <td>{{ $w->vendor }}</td>
                <td>
                  @if($w->production_id !== '-')
                    <a href="{{ route('production.edit', $w->production_id) }}">PO-{{ $w->production_id }}</a>
                  @else <span class="text-muted">—</span>
                  @endif
                </td>
                <td>{{ $w->item_name }}</td>
                <td>{{ $w->variation !== '-' ? $w->variation : '—' }}</td>
                <td>
                  @if($w->return_type === 'extra')
                    <span class="badge bg-success">Extra</span>
                    <small class="text-muted d-block" style="font-size:10px;">Back to Stock</small>
                  @else
                    <span class="badge bg-danger">Wastage</span>
                    <small class="text-muted d-block" style="font-size:10px;">Write-off</small>
                  @endif
                </td>
                <td class="text-end">{{ number_format($w->qty, 3) }}</td>
                <td>{{ $w->unit }}</td>
                <td><small class="text-muted">{{ $w->remarks ?? '—' }}</small></td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-3">No wastage returns in this period.</td></tr>
            @endforelse
          </tbody>
          @if($wastageReport->count())
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="7" class="text-end">Total:</td>
                <td class="text-end">{{ number_format($wstExtra + $wstWastage, 3) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 4. PRODUCT COSTING ───────────────────────────────────── --}}
    @if($tab === 'CR')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="CR">
        @include('reports._filter', ['showVendor' => false])
      </form>

      <div class="alert alert-info">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Avg Total Cost/pc</strong> = Avg Raw Cost/pc + Avg Mfg Cost/pc.
        CMT Cost (Set) is the default from the product master record.
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="crTable">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th class="text-end">Total Qty Received</th>
              <th class="text-end">Avg Raw Cost/pc</th>
              <th class="text-end">Avg Mfg Cost/pc</th>
              <th class="text-end">CMT Cost (Set)</th>
              <th class="text-end">Avg Total Cost/pc</th>
              <th class="text-end">Total Cost</th>
            </tr>
          </thead>
          <tbody>
            @forelse($costings as $row)
              @php $mfgVariance = $row->avg_mfg_cost - $row->cmt_cost_set; @endphp
              <tr class="{{ $mfgVariance > 0 ? 'table-warning' : '' }}">
                <td>{{ $row->product_name }}</td>
                <td class="text-end">{{ number_format($row->total_qty, 2) }}</td>
                <td class="text-end">{{ number_format($row->avg_raw_cost, 2) }}</td>
                <td class="text-end">{{ number_format($row->avg_mfg_cost, 2) }}</td>
                <td class="text-end text-muted">
                  {{ $row->cmt_cost_set > 0 ? number_format($row->cmt_cost_set, 2) : '—' }}
                  @if($row->cmt_cost_set > 0)
                    <br><small class="{{ $mfgVariance > 0 ? 'text-danger' : 'text-success' }}">
                      {{ $mfgVariance > 0 ? '+' : '' }}{{ number_format($mfgVariance, 2) }}
                    </small>
                  @endif
                </td>
                <td class="text-end fw-bold text-primary">{{ number_format($row->avg_total_cost, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_cost, 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted">No costing data found.</td></tr>
            @endforelse
          </tbody>
          @if($costings->count())
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="6" class="text-end">Grand Total Cost</td>
                <td class="text-end">{{ number_format($costings->sum('total_cost'), 2) }}</td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 5. VENDOR RAW BALANCE ────────────────────────────────── --}}
    @if($tab === 'VRB')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="VRB">
        @include('reports._filter', ['showVendor' => false])
      </form>

      <div class="alert alert-info mb-3">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Extra Returned</strong> = unused raw returned to our stock.
        <strong>Wastage Returned</strong> = actual scraps (write-off, does not return to stock).
        <strong>Remaining (Expected)</strong> = Sent − Expected Consumed − Extra − Wastage.
        Alert fires when consumption deviates &gt;10%.
      </div>

      @forelse($vendorRawBalance as $vb)
        @php
          $hasAlert = $vb->balance->contains(fn($b) => $b->alert);
          $hasCrit  = $vb->balance->contains(fn($b) => $b->critical);
        @endphp
        <div class="card mb-3 {{ $hasCrit ? 'border-danger' : ($hasAlert ? 'border-warning' : '') }}">
          <div class="card-header d-flex justify-content-between align-items-center
                       {{ $hasCrit ? 'bg-danger text-white' : ($hasAlert ? 'bg-warning' : 'bg-light') }}">
            <strong><i class="fas fa-user me-1"></i> {{ $vb->vendor }}</strong>
            <div>
              @if($hasCrit)<span class="badge bg-danger me-1">⚠ Critical</span>
              @elseif($hasAlert)<span class="badge bg-warning text-dark me-1">⚠ Alert</span>
              @endif
              <span class="badge bg-secondary">{{ $vb->total }} item(s)</span>
            </div>
          </div>
          <div class="card-body p-0">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light">
                <tr>
                  <th>Raw Material</th>
                  <th class="text-end">Sent</th>
                  <th class="text-end">Exp. Consumed</th>
                  <th class="text-end">Act. Consumed</th>
                  <th class="text-end">Extra Returned<br><small class="text-success">Back to Stock</small></th>
                  <th class="text-end">Wastage<br><small class="text-danger">Write-off</small></th>
                  <th class="text-end">Remaining (Exp)</th>
                  <th class="text-end">Remaining (Act)</th>
                  <th class="text-end">Variance %</th>
                </tr>
              </thead>
              <tbody>
                @foreach($vb->balance as $b)
                  <tr class="{{ $b->critical ? 'table-danger' : ($b->alert ? 'table-warning' : '') }}">
                    <td>{{ $b->product }}</td>
                    <td class="text-end">{{ number_format($b->sent, 2) }} <small class="text-muted">{{ $b->unit }}</small></td>
                    <td class="text-end text-success">{{ number_format($b->expected_consumed, 4) }}</td>
                    <td class="text-end text-primary">{{ number_format($b->actual_consumed, 4) }}</td>
                    <td class="text-end text-success fw-bold">{{ number_format($b->extra_returned, 3) }}</td>
                    <td class="text-end text-danger">{{ number_format($b->wastage_returned, 3) }}</td>
                    <td class="text-end fw-bold {{ $b->remaining_expected > 0 ? 'text-warning' : 'text-success' }}">
                      {{ number_format($b->remaining_expected, 4) }}
                    </td>
                    <td class="text-end text-muted">{{ number_format($b->remaining_actual, 4) }}</td>
                    <td class="text-end">
                      @if($b->variance_pct !== null)
                        <span class="{{ $b->critical ? 'text-danger fw-bold' : ($b->alert ? 'text-warning fw-bold' : 'text-success') }}">
                          {{ $b->variance_pct > 0 ? '+' : '' }}{{ $b->variance_pct }}%
                          @if($b->critical)<i class="fas fa-exclamation-circle"></i>
                          @elseif($b->alert)<i class="fas fa-exclamation-triangle"></i>
                          @endif
                        </span>
                      @else
                        <span class="badge bg-secondary">No Baseline</span>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      @empty
        <div class="text-center text-muted py-4">No raw material balance found at vendors.</div>
      @endforelse
    @endif

    {{-- ── 6. PRODUCTION RETURNS ────────────────────────────────── --}}
    @if($tab === 'RTN')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="RTN">
        @include('reports._filter', ['showVendor' => false])
      </form>

      @php $rtnTotal = $returnReport->sum('total'); $rtnQty = $returnReport->sum('qty'); @endphp
      <div class="row mb-2">
        <div class="col text-end">
          <span class="me-3">Total Qty: <strong>{{ number_format($rtnQty, 2) }}</strong></span>
          <span>Total Value: <strong class="text-danger">PKR {{ number_format($rtnTotal, 0) }}</strong></span>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="rtnTable">
          <thead class="table-light">
            <tr>
              <th>Date</th><th>Return #</th><th>Vendor</th><th>Item</th>
              <th>Variation</th><th>Production #</th>
              <th class="text-end">Qty</th><th>Unit</th>
              <th class="text-end">Rate</th><th class="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            @forelse($returnReport as $row)
              <tr>
                <td>{{ \Carbon\Carbon::parse($row->date)->format('d-M-Y') }}</td>
                <td>#{{ $row->return_id }}</td>
                <td>{{ $row->vendor }}</td>
                <td>{{ $row->item_name }}</td>
                <td>{{ $row->variation !== '-' ? $row->variation : '—' }}</td>
                <td>
                  @if($row->production_id !== '-')
                    <a href="{{ route('production.edit', $row->production_id) }}">PO-{{ $row->production_id }}</a>
                  @else —
                  @endif
                </td>
                <td class="text-end">{{ number_format($row->qty, 2) }}</td>
                <td>{{ $row->unit }}</td>
                <td class="text-end">{{ number_format($row->rate, 2) }}</td>
                <td class="text-end">{{ number_format($row->total, 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted">No production returns found.</td></tr>
            @endforelse
          </tbody>
          @if($returnReport->count())
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="6" class="text-end">Total</td>
                <td class="text-end">{{ number_format($rtnQty, 2) }}</td>
                <td></td><td></td>
                <td class="text-end">{{ number_format($rtnTotal, 2) }}</td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 7. DELIVERY TRACKING ─────────────────────────────────── --}}
    @if($tab === 'DLV')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="DLV">
        @include('reports._filter', ['showVendor' => false])
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="dlvTable">
          <thead class="table-light">
            <tr>
              <th>Production #</th><th>Vendor</th><th>Order Date</th>
              <th>First Receiving</th><th>Last Receiving</th>
              <th class="text-end">Days to First</th><th class="text-end">Days to Last</th>
              <th class="text-end">Raw Sent</th><th class="text-end">FG Received</th>
              <th class="text-end">Extra Ret.</th><th class="text-end">Wastage</th>
              <th class="text-end">Receivings</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse($deliveryReport as $row)
              <tr class="{{ $row->status === 'Pending' ? 'table-warning' : '' }}">
                <td><a href="{{ route('production.edit', $row->production_id) }}">PO-{{ $row->production_id }}</a></td>
                <td>{{ $row->vendor }}</td>
                <td>{{ \Carbon\Carbon::parse($row->order_date)->format('d-M-Y') }}</td>
                <td>
                  {{ $row->first_receiving !== 'Pending'
                    ? \Carbon\Carbon::parse($row->first_receiving)->format('d-M-Y') : '—' }}
                </td>
                <td>
                  {{ $row->last_receiving !== 'Pending'
                    ? \Carbon\Carbon::parse($row->last_receiving)->format('d-M-Y') : '—' }}
                </td>
                <td class="text-end">
                  @if($row->days_to_first !== null)
                    <span class="{{ $row->days_to_first > 30 ? 'text-danger fw-bold' : '' }}">
                      {{ $row->days_to_first }}d
                    </span>
                  @else <span class="text-muted">—</span>
                  @endif
                </td>
                <td class="text-end">{{ $row->days_to_last !== null ? $row->days_to_last.'d' : '—' }}</td>
                <td class="text-end">{{ number_format($row->total_raw, 2) }}</td>
                <td class="text-end">{{ number_format($row->total_fg, 2) }}</td>
                <td class="text-end text-success">{{ number_format($row->extra_returned, 3) }}</td>
                <td class="text-end text-danger">{{ number_format($row->wastage, 3) }}</td>
                <td class="text-end">{{ $row->receiving_count }}</td>
                <td>
                  @if($row->status === 'Pending')
                    <span class="badge bg-warning text-dark">Pending</span>
                  @elseif($row->status === 'Partial')
                    <span class="badge bg-info">Partial</span>
                  @else
                    <span class="badge bg-success">Received</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="13" class="text-center text-muted">No data found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    @endif

    {{-- ── 8. VENDOR SUMMARY ────────────────────────────────────── --}}
    @if($tab === 'SUM')
      <form method="GET" action="{{ route('reports.production') }}" class="mb-3">
        <input type="hidden" name="tab" value="SUM">
        @include('reports._filter', ['showVendor' => false])
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="sumTable">
          <thead class="table-light">
            <tr>
              <th>Vendor</th>
              <th class="text-end">Orders</th>
              <th class="text-end">Raw Sent</th>
              <th class="text-end">Raw Cost</th>
              <th class="text-end">FG Received</th>
              <th class="text-end">Mfg. Cost</th>
              <th class="text-end">Extra Ret.<br><small class="text-success">Stock</small></th>
              <th class="text-end">Wastage<br><small class="text-danger">Write-off</small></th>
              <th class="text-end">Avg Raw/pc</th>
              <th class="text-end">Avg CMT/pc</th>
              <th class="text-end">Avg Total/pc</th>
              <th class="text-end">Con (Actual)</th>
              <th class="text-end">Con (Exp)</th>
              <th class="text-end">Variance</th>
            </tr>
          </thead>
          <tbody>
            @forelse($orderSummary as $row)
              @php $isAlert = $row->variance_alert ?? false; $isCrit = $row->variance_critical ?? false; @endphp
              <tr class="{{ $isCrit ? 'table-danger' : ($isAlert ? 'table-warning' : '') }}">
                <td><strong>{{ $row->vendor }}</strong></td>
                <td class="text-end">{{ $row->orders }}</td>
                <td class="text-end">{{ number_format($row->total_raw, 2) }}</td>
                <td class="text-end">{{ number_format($row->raw_cost, 0) }}</td>
                <td class="text-end">{{ number_format($row->total_fg, 2) }}</td>
                <td class="text-end">{{ number_format($row->mfg_cost, 0) }}</td>
                <td class="text-end text-success fw-bold">{{ number_format($row->total_extra, 3) }}</td>
                <td class="text-end text-danger">{{ number_format($row->total_wastage, 3) }}</td>
                <td class="text-end">{{ number_format($row->avg_raw_cost_pc, 2) }}</td>
                <td class="text-end">{{ number_format($row->avg_mfg_cost_pc, 2) }}</td>
                <td class="text-end fw-bold text-primary">{{ number_format($row->avg_total_cost_pc, 2) }}</td>
                <td class="text-end">{{ $row->avg_con_actual }}</td>
                <td class="text-end">{{ $row->avg_con_expected ?: '—' }}</td>
                <td class="text-end">
                  @if($row->variance_pct !== null)
                    <span class="{{ $isCrit ? 'text-danger fw-bold' : ($isAlert ? 'text-warning fw-bold' : 'text-success') }}">
                      {{ $row->variance_pct > 0 ? '+' : '' }}{{ $row->variance_pct }}%
                    </span>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="14" class="text-center text-muted">No vendor data found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    @endif

  </div>
</div>

<script>
  $(document).ready(function () {
    $('#prTable, #wstTable, #crTable, #rtnTable, #dlvTable, #sumTable').DataTable({
      pageLength: 50,
      order: [[0, 'desc']],
    });
  });
</script>
@endsection