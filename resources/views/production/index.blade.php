@extends('layouts.app')
@section('title', 'Productions | All Orders')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">

      @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Production Orders</h2>
        <div class="d-flex gap-2">
          {{-- <a href="{{ route('production.consumption.pdf') }}" target="_blank"
             class="btn btn-outline-danger btn-sm">
            <i class="fas fa-file-pdf me-1"></i> Consumption Report
          </a> --}}
          <a href="{{ route('production.create') }}" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> New Production
          </a>
        </div>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover table-sm"
                 id="productionTable">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Order #</th>
                <th>Date</th>
                <th>Vendor</th>
                <th>Category</th>
                <th>Type</th>
                <th class="text-end">Raw Qty</th>
                <th class="text-end">Raw Cost</th>
                <th class="text-end">FG Received</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($productions as $prod)
                @php
                  $totalRaw   = $prod->details->sum('qty');
                  $totalCost  = $prod->details->sum(fn($d) => $d->qty * $d->rate);
                  $totalFG    = $prod->receivings->flatMap->details->sum('received_qty');
                  $hasReceiving = $prod->receivings->count() > 0;
                  $status = !$hasReceiving ? 'pending'
                      : ($totalFG > 0 && $prod->receivings->count() >= 1 ? 'received' : 'partial');
                @endphp
                <tr>
                  <td>{{ $loop->iteration }}</td>
                  <td>
                    <strong class="text-primary">PO-{{ $prod->id }}</strong>
                  </td>
                  <td>{{ \Carbon\Carbon::parse($prod->order_date)->format('d-M-Y') }}</td>
                  <td>{{ $prod->vendor->name ?? '-' }}</td>
                  <td>
                    <span class="text-muted" style="font-size:12px;">
                      {{ $prod->category->name ?? '-' }}
                    </span>
                  </td>
                  <td>
                    <span class="badge {{ $prod->production_type == 'cmt' ? 'bg-primary' : 'bg-warning text-dark' }}"
                          style="font-size:10px;">
                      {{ ucfirst(str_replace('_', ' ', $prod->production_type)) }}
                    </span>
                  </td>
                  <td class="text-end">{{ number_format($totalRaw, 2) }}</td>
                  <td class="text-end">{{ number_format($totalCost, 0) }}</td>
                  <td class="text-end">
                    {{ $totalFG > 0 ? number_format($totalFG, 2) : '—' }}
                  </td>
                  <td>
                    @if($status === 'pending')
                      <span class="badge bg-danger" style="font-size:10px;">Pending</span>
                    @elseif($status === 'partial')
                      <span class="badge bg-warning text-dark" style="font-size:10px;">Partial</span>
                    @else
                      <span class="badge bg-success" style="font-size:10px;">Received</span>
                    @endif
                  </td>
                  <td>
                    <div class="d-flex gap-1 flex-wrap">
                      {{-- Summary / Costing PDF --}}
                      <a href="{{ route('production.summary', $prod->id) }}"
                         target="_blank"
                         class="btn btn-xs btn-outline-dark"
                         title="Costing Summary">
                        <i class="fas fa-calculator"></i>
                      </a>
                      {{-- Gate Pass --}}
                      <a href="{{ route('production.gatepass', $prod->id) }}"
                         target="_blank"
                         class="btn btn-xs btn-outline-secondary"
                         title="Gate Pass">
                        <i class="fas fa-file-export"></i>
                      </a>
                      {{-- Print Order --}}
                      <a href="{{ route('production.print', $prod->id) }}"
                         target="_blank"
                         class="btn btn-xs btn-outline-success"
                         title="Print Order">
                        <i class="fas fa-print"></i>
                      </a>
                      {{-- Edit --}}
                      <a href="{{ route('production.edit', $prod->id) }}"
                         class="btn btn-xs btn-outline-primary"
                         title="Edit">
                        <i class="fas fa-edit"></i>
                      </a>
                      {{-- Receive --}}
                      <a href="{{ route('production_receiving.create', ['id' => $prod->id]) }}"
                         class="btn btn-xs btn-outline-warning"
                         title="Receive FG">
                        <i class="fas fa-box-open"></i>
                      </a>
                      {{-- Wastage Return --}}
                      <a href="{{ route('production_wastage.create', ['id' => $prod->id]) }}"
                        class="btn btn-xs btn-outline-info"
                        title="Raw Material Wastage Return">
                        <i class="fas fa-recycle"></i>
                      </a>
                      {{-- Delete --}}
                      <form action="{{ route('production.destroy', $prod->id) }}"
                            method="POST" style="display:inline;"
                            onsubmit="return confirm('Delete Production PO-{{ $prod->id }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="btn btn-xs btn-outline-danger"
                                title="Delete">
                          <i class="fas fa-trash-alt"></i>
                        </button>
                      </form>
                    </div>
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
  $(document).ready(function () {
    $('#productionTable').DataTable({
      pageLength: 50,
      order: [[0, 'desc']],
      columnDefs: [{ orderable: false, targets: 10 }],
    });
  });
</script>
@endsection