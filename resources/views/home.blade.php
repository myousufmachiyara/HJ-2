@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="dashboard-wrap">

  {{-- ── Date Header ── --}}
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2 class="mb-0 fw-bold text-dark" id="currentDate"></h2>
    </div>	
  </div>

  {{-- ── KPI Cards ── --}}
  <div class="row g-3 mb-4">

    {{-- Today's Sales --}}
    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-green">
        <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Today's Sales</div>
          <div class="kpi-value">{{ number_format($todaySales->total ?? 0, 0) }}</div>
          <div class="kpi-sub">{{ $todaySales->count ?? 0 }} invoices
            · Collected: {{ number_format($todaySales->collected ?? 0, 0) }}</div>
        </div>
      </div>
    </div>

    {{-- Monthly Sales --}}
    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-blue">
        <div class="kpi-icon"><i class="fas fa-calendar-alt"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Monthly Sales</div>
          <div class="kpi-value">{{ number_format($monthlySales->total ?? 0, 0) }}</div>
          <div class="kpi-sub">{{ $monthlySales->count ?? 0 }} invoices
            · {{ now()->format('M Y') }}</div>
        </div>
      </div>
    </div>

    {{-- Receivables --}}
    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-orange">
        <div class="kpi-icon"><i class="fas fa-hand-holding-usd"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Receivables</div>
          <div class="kpi-value">{{ number_format($totalReceivables, 0) }}</div>
          <div class="kpi-sub">Outstanding balance due</div>
        </div>
      </div>
    </div>

    {{-- Payables --}}
    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-red">
        <div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Payables</div>
          <div class="kpi-value">{{ number_format(max(0, $totalPayables), 0) }}</div>
          <div class="kpi-sub">Vendor outstanding</div>
        </div>
      </div>
    </div>

  </div>

  {{-- ── Production & Today Receiving KPIs ── --}}
  <div class="row g-3 mb-4">

    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-purple">
        <div class="kpi-icon"><i class="fas fa-industry"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Pending Production</div>
          <div class="kpi-value">{{ $pendingCount }}</div>
          <div class="kpi-sub">Orders not yet received</div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-teal">
        <div class="kpi-icon"><i class="fas fa-cogs"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">In Process</div>
          <div class="kpi-value">{{ $inProcessCount }}</div>
          <div class="kpi-sub">Partially received</div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-green">
        <div class="kpi-icon"><i class="fas fa-boxes"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Today Received</div>
          <div class="kpi-value">{{ number_format($todayReceivedPcs, 0) }} pcs</div>
          <div class="kpi-sub">PKR {{ number_format($todayReceivedValue, 0) }}</div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="kpi-card kpi-red">
        <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="kpi-body">
          <div class="kpi-label">Low Stock</div>
          <div class="kpi-value">{{ $lowStockProducts->count() }}</div>
          <div class="kpi-sub">Below reorder level</div>
        </div>
      </div>
    </div>

  </div>

  {{-- ── Main Content Row ── --}}
  <div class="row g-3 mb-4">

    {{-- Sales Chart --}}
    <div class="col-md-8">
      <section class="card h-100">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title mb-0">Sales vs Purchases (Last 6 Months)</h2>
        </header>
        <div class="card-body">
          <canvas id="salesChart" height="100"></canvas>
        </div>
      </section>
    </div>

    {{-- Cash & Bank Positions --}}
    <div class="col-md-4">
      <section class="card h-100">
        <header class="card-header">
          <h2 class="card-title mb-0">Cash & Bank</h2>
        </header>
        <div class="card-body p-0">
          @foreach($cashBankAccounts as $acc)
            <div class="d-flex justify-content-between align-items-center px-3 py-3 border-bottom">
              <div>
                <div class="fw-600 text-dark" style="font-size:13px;">{{ $acc->name }}</div>
                <small class="text-muted text-uppercase" style="font-size:10px;">
                  {{ $acc->account_type }}
                </small>
              </div>
              <div class="text-end">
                <div class="fw-bold {{ $acc->balance >= 0 ? 'text-success' : 'text-danger' }}"
                     style="font-size:14px;">
                  PKR {{ number_format($acc->balance, 0) }}
                </div>
              </div>
            </div>
          @endforeach
          @if($cashBankAccounts->isEmpty())
            <div class="p-3 text-center text-muted">No accounts found</div>
          @endif
        </div>
      </section>
    </div>

  </div>

  {{-- ── Bottom Row ── --}}
  <div class="row g-3 mb-4">

    {{-- Pending Production Orders --}}
    <div class="col-md-6">
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title mb-0">
            Pending Production
            <span class="badge bg-danger ms-1">{{ $pendingCount }}</span>
          </h2>
          <a href="{{ route('production.index') }}" class="btn btn-xs btn-outline-primary btn-sm">
            View All
          </a>
        </header>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>#</th>
                  <th>Vendor</th>
                  <th>Type</th>
                  <th class="text-end">Raw Qty</th>
                  <th class="text-end">Days</th>
                </tr>
              </thead>
              <tbody>
                @forelse($pendingProductions as $prod)
                  <tr>
                    <td>
                      <a href="{{ route('production.show', $prod['id']) }}" class="text-primary fw-bold">
                        #{{ $prod['id'] }}
                      </a>
                    </td>
                    <td style="font-size:12px;">{{ $prod['vendor'] }}</td>
                    <td>
                      <span class="badge {{ $prod['type'] == 'CMT' ? 'bg-primary' : 'bg-warning text-dark' }}"
                            style="font-size:10px;">
                        {{ $prod['type'] }}
                      </span>
                    </td>
                    <td class="text-end" style="font-size:12px;">
                      {{ number_format($prod['raw_qty'], 2) }}
                    </td>
                    <td class="text-end">
                      <span class="badge {{ $prod['days_ago'] > 30 ? 'bg-danger' : ($prod['days_ago'] > 14 ? 'bg-warning text-dark' : 'bg-success') }}"
                            style="font-size:10px;">
                        {{ $prod['days_ago'] }}d
                      </span>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="5" class="text-center text-muted py-3">
                      <i class="fas fa-check-circle text-success me-1"></i>
                      All orders received
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    {{-- Low Stock Products --}}
    <div class="col-md-6">
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title mb-0">
            Low Stock Alert
            <span class="badge bg-warning text-dark ms-1">{{ $lowStockProducts->count() }}</span>
          </h2>
          <a href="{{ route('products.index') }}" class="btn btn-xs btn-outline-primary btn-sm">
            View All
          </a>
        </header>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>Product</th>
                  <th>Category</th>
                  <th class="text-end">Stock</th>
                  <th class="text-end">Reorder</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                @forelse($lowStockProducts as $p)
                  <tr>
                    <td style="font-size:12px;">
                      <div class="fw-600">{{ $p->name }}</div>
                      <small class="text-muted">{{ $p->sku }}</small>
                    </td>
                    <td style="font-size:11px;color:#6b7280;">{{ $p->category->name ?? '-' }}</td>
                    <td class="text-end">
                      <span class="{{ $p->current_stock <= 0 ? 'text-danger fw-bold' : 'text-warning fw-bold' }}"
                            style="font-size:13px;">
                        {{ number_format($p->current_stock, 0) }}
                      </span>
                    </td>
                    <td class="text-end" style="font-size:12px;color:#6b7280;">
                      {{ number_format($p->reorder_level, 0) }}
                    </td>
                    <td>
                      @if($p->current_stock <= 0)
                        <span class="badge bg-danger" style="font-size:10px;">Out of Stock</span>
                      @else
                        <span class="badge bg-warning text-dark" style="font-size:10px;">Low</span>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="5" class="text-center text-muted py-3">
                      <i class="fas fa-check-circle text-success me-1"></i>
                      All stock levels OK
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

  </div>

  {{-- ── Today's Receiving + Recent Sales ── --}}
  <div class="row g-3">

    {{-- Today's Production Receiving --}}
    <div class="col-md-5">
      <section class="card">
        <header class="card-header">
          <h2 class="card-title mb-0">
            Today's Receiving
            <span class="badge bg-success ms-1">{{ $todayReceivingList->count() }}</span>
          </h2>
        </header>
        <div class="card-body p-0">
          @forelse($todayReceivingList as $rec)
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
              <div>
                <div class="fw-600" style="font-size:13px;">{{ $rec['grn_no'] }}</div>
                <small class="text-muted">{{ $rec['vendor'] }}
                  · {{ $rec['items'] }} items</small>
              </div>
              <div class="text-end">
                <div class="text-success fw-bold" style="font-size:13px;">
                  {{ number_format($rec['qty'], 0) }} pcs
                </div>
                <small class="text-muted">PKR {{ number_format($rec['value'], 0) }}</small>
              </div>
            </div>
          @empty
            <div class="p-4 text-center text-muted">
              <i class="fas fa-inbox me-1"></i> No receiving today
            </div>
          @endforelse
        </div>
      </section>
    </div>

    {{-- Recent Sales --}}
    <div class="col-md-7">
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title mb-0">Recent Sale Invoices</h2>
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-xs btn-outline-primary btn-sm">
            View All
          </a>
        </header>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Invoice</th>
                  <th>Date</th>
                  <th>Customer</th>
                  <th class="text-end">Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                @forelse($recentSales as $inv)
                  <tr>
                    <td>
                      <a href="{{ route('sale_invoices.show', $inv->id) }}"
                         class="text-primary fw-bold" style="font-size:12px;">
                        {{ $inv->invoice_no }}
                      </a>
                    </td>
                    <td style="font-size:12px;">
                      {{ \Carbon\Carbon::parse($inv->date)->format('d-M') }}
                    </td>
                    <td style="font-size:12px;">{{ $inv->account->name ?? 'Walk-in' }}</td>
                    <td class="text-end fw-bold" style="font-size:12px;">
                      {{ number_format($inv->net_amount, 0) }}
                    </td>
                    <td>
                      @php
                        $cls = match($inv->payment_status) {
                          'paid'    => 'bg-success',
                          'partial' => 'bg-warning text-dark',
                          default   => 'bg-danger',
                        };
                      @endphp
                      <span class="badge {{ $cls }}" style="font-size:10px;">
                        {{ ucfirst($inv->payment_status) }}
                      </span>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="5" class="text-center text-muted py-3">No recent sales</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

  </div>

</div>

{{-- Chart.js --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<style>
  .dashboard-wrap { padding: 4px 0; }

  .kpi-card {
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    color: #fff;
    box-shadow: 0 2px 12px rgba(0,0,0,.1);
  }
  .kpi-icon {
    width: 48px; height: 48px;
    background: rgba(255,255,255,.2);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
  }
  .kpi-label { font-size: 16px; opacity: .85; text-transform: uppercase; letter-spacing: .5px; }
  .kpi-value { font-size: 30px; font-weight: 800; line-height: 1.1; }
  .kpi-sub   { font-size: 15px; opacity: .8; margin-top: 2px; }

  .kpi-green  { background: linear-gradient(135deg, #38a169, #276749); }
  .kpi-blue   { background: linear-gradient(135deg, #3182ce, #2b6cb0); }
  .kpi-orange { background: linear-gradient(135deg, #dd6b20, #c05621); }
  .kpi-red    { background: linear-gradient(135deg, #e53e3e, #c53030); }
  .kpi-purple { background: linear-gradient(135deg, #805ad5, #6b46c1); }
  .kpi-teal   { background: linear-gradient(135deg, #319795, #2c7a7b); }

  .fw-600 { font-weight: 600; }
  .sticky-top { position: sticky; top: 0; z-index: 1; }

  @media (max-width: 576px) {
    .kpi-value { font-size: 17px; }
    .kpi-icon  { width: 36px; height: 36px; font-size: 16px; }
    .kpi-card  { padding: 12px; gap: 10px; }
  }
</style>

<script>
  // ── Date display ──────────────────────────────────────────────────
  $(document).ready(function () {
    const now = new Date();
    function getDaySuffix(d) {
      if (d >= 11 && d <= 13) return d + 'th';
      switch (d % 10) {
        case 1: return d + 'st';
        case 2: return d + 'nd';
        case 3: return d + 'rd';
        default: return d + 'th';
      }
    }
    const day = getDaySuffix(now.getDate());
    const month = now.toLocaleString('en-GB', { month: 'long' });
    const weekday = now.toLocaleString('en-GB', { weekday: 'long' });
    document.getElementById('currentDate').innerText =
      `${weekday}, ${day} ${month} ${now.getFullYear()}`;
  });

  // ── Sales Chart ───────────────────────────────────────────────────
  const chartData = @json($chartData);

  const ctx = document.getElementById('salesChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: chartData.map(d => d.month),
      datasets: [
        {
          label: 'Sales (PKR)',
          data: chartData.map(d => d.sales),
          backgroundColor: 'rgba(56, 161, 105, 0.8)',
          borderColor: '#38a169',
          borderWidth: 1,
          borderRadius: 6,
        },
        {
          label: 'Purchases (PKR)',
          data: chartData.map(d => d.purchases),
          backgroundColor: 'rgba(229, 62, 62, 0.7)',
          borderColor: '#e53e3e',
          borderWidth: 1,
          borderRadius: 6,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top', labels: { font: { size: 12 } } },
        tooltip: {
          callbacks: {
            label: ctx => ' PKR ' + ctx.parsed.y.toLocaleString(),
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: v => 'PKR ' + (v >= 1000 ? (v/1000).toFixed(0) + 'K' : v),
            font: { size: 11 },
          },
          grid: { color: '#f3f4f6' },
        },
        x: {
          ticks: { font: { size: 11 } },
          grid: { display: false },
        },
      },
    },
  });
</script>
@endsection