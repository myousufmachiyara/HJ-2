@php
  $grouped = $vouchers->groupBy('voucher_type');

  $moduleConfig = [
    'purchase'          => ['label' => 'Purchase',           'icon' => 'fa-shopping-cart',    'badge' => 'bg-warning text-dark'],
    'purchase_return'   => ['label' => 'Purchase Return',    'icon' => 'fa-undo',             'badge' => 'bg-danger'],
    'sale'              => ['label' => 'Sale',               'icon' => 'fa-cash-register',    'badge' => 'bg-success'],
    'sale_return'       => ['label' => 'Sale Return',        'icon' => 'fa-undo-alt',         'badge' => 'bg-secondary'],
    'production'        => ['label' => 'Production',         'icon' => 'fa-industry',         'badge' => 'bg-info text-dark'],
    'production_return' => ['label' => 'Production Return',  'icon' => 'fa-recycle',          'badge' => 'bg-dark'],
  ];

  // Only show tabs that have data OR are known modules
  $availableTypes = collect($moduleConfig)->keys()
      ->merge($grouped->keys())
      ->unique();
@endphp

{{-- Inner Module Tabs --}}
<ul class="nav nav-tabs nav-tabs-simple mb-3" id="systemTabs">
  @foreach($availableTypes as $index => $type)
    @php
      $config  = $moduleConfig[$type] ?? ['label' => ucwords(str_replace('_', ' ', $type)), 'icon' => 'fa-circle', 'badge' => 'bg-primary'];
      $count   = $grouped->get($type, collect())->count();
      $isFirst = $index === 0;
    @endphp
    <li class="nav-item">
      <a class="nav-link {{ $isFirst ? 'active' : '' }}"
         href="#sys-tab-{{ $type }}"
         data-bs-toggle="tab">
        <i class="fas {{ $config['icon'] }} me-1"></i>
        {{ $config['label'] }}
        <span class="badge {{ $count > 0 ? $config['badge'] : 'bg-light text-dark' }} ms-1">
          {{ $count }}
        </span>
      </a>
    </li>
  @endforeach
</ul>

{{-- Inner Tab Content --}}
<div class="tab-content">
  @foreach($availableTypes as $index => $type)
    @php
      $config   = $moduleConfig[$type] ?? ['label' => ucwords(str_replace('_', ' ', $type)), 'icon' => 'fa-circle', 'badge' => 'bg-primary'];
      $rows     = $grouped->get($type, collect());
      $isFirst  = $index === 0;
    @endphp
    <div class="tab-pane {{ $isFirst ? 'active' : '' }}" id="sys-tab-{{ $type }}">

      {{-- Summary bar --}}
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small">
          <span class="badge {{ $config['badge'] }}">{{ $config['label'] }}</span>
          {{ $rows->count() }} entr{{ $rows->count() === 1 ? 'y' : 'ies' }}
        </span>
        @if($rows->isNotEmpty())
          <strong class="text-end">
            Total: {{ number_format($rows->sum('amount'), 0, '.', ',') }}
          </strong>
        @endif
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>Vch#</th>
              <th>Date</th>
              <th>Debit Account</th>
              <th>Credit Account</th>
              <th>Remarks</th>
              <th class="text-end">Amount</th>
              <th>Print</th>
            </tr>
          </thead>
          <tbody>
            @forelse($rows as $row)
              <tr>
                <td>{{ $row->id }}</td>
                <td>{{ \Carbon\Carbon::parse($row->date)->format('d-m-Y') }}</td>
                <td>{{ $row->debitAccount->name  ?? 'N/A' }}</td>
                <td>{{ $row->creditAccount->name ?? 'N/A' }}</td>
                <td><small>{{ Str::limit($row->remarks, 60) }}</small></td>
                <td class="text-end">
                  <strong>{{ number_format($row->amount, 0, '.', ',') }}</strong>
                </td>
                <td>
                  <a class="text-success"
                     href="{{ route('vouchers.print', ['type' => $row->voucher_type, 'id' => $row->id]) }}"
                     title="Print">
                    <i class="fas fa-print"></i>
                  </a>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="text-center text-muted py-3">
                  <i class="fas {{ $config['icon'] }} me-1"></i>
                  No {{ $config['label'] }} entries yet.
                </td>
              </tr>
            @endforelse
          </tbody>
          @if($rows->isNotEmpty())
            <tfoot class="table-light">
              <tr>
                <td colspan="5" class="text-end fw-bold">Total</td>
                <td class="text-end fw-bold">
                  {{ number_format($rows->sum('amount'), 0, '.', ',') }}
                </td>
                <td></td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>

    </div>
  @endforeach
</div>