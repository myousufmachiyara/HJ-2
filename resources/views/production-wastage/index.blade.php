@extends('layouts.app')
@section('title', 'Raw Material Wastage Returns')

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
        <h2 class="card-title mb-0">Raw Material Wastage Returns</h2>
        <a href="{{ route('production_wastage.create') }}" class="btn btn-primary btn-sm">
          <i class="fas fa-plus me-1"></i> New Wastage Return
        </a>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-sm" id="wastageTable">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>WRN #</th>
                <th>Date</th>
                <th>Production #</th>
                <th>Vendor</th>
                <th class="text-end">Total Qty</th>
                <th>Remarks</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($wastages as $w)
                <tr>
                  <td>{{ $loop->iteration }}</td>
                  <td><strong class="text-primary">{{ $w->grn_no }}</strong></td>
                  <td>{{ \Carbon\Carbon::parse($w->rec_date)->format('d-M-Y') }}</td>
                  <td>
                    @if($w->production_id)
                      <a href="{{ route('production.edit', $w->production_id) }}">
                        PO-{{ $w->production_id }}
                      </a>
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td>{{ $w->vendor->name ?? '-' }}</td>
                  <td class="text-end">{{ number_format($w->details->sum('quantity'), 3) }}</td>
                  <td><small class="text-muted">{{ $w->remarks ?? '-' }}</small></td>
                  <td>
                    <div class="d-flex gap-1">
                      <a href="{{ route('production_wastage.print', $w->id) }}"
                         target="_blank" class="btn btn-xs btn-outline-success" title="Print">
                        <i class="fas fa-print"></i>
                      </a>
                      <a href="{{ route('production_wastage.edit', $w->id) }}"
                         class="btn btn-xs btn-outline-primary" title="Edit">
                        <i class="fas fa-edit"></i>
                      </a>
                      <form action="{{ route('production_wastage.destroy', $w->id) }}"
                            method="POST" style="display:inline"
                            onsubmit="return confirm('Delete {{ $w->grn_no }}?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-xs btn-outline-danger" title="Delete">
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
    $('#wastageTable').DataTable({ pageLength: 50, order: [[0, 'desc']] });
  });
</script>
@endsection