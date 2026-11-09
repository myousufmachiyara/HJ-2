@extends('layouts.app')
@section('title', 'LAAM Sales')

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
        <h2 class="card-title">LAAM Sales (Weekly Reports)</h2>
        @can('laam_invoices.create')
          <a href="{{ route('laam_invoices.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New LAAM Sale</a>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover">
            <thead class="table-light">
              <tr>
                <th>#</th><th>Doc #</th><th>Date</th><th>Report period</th><th>LAAM location</th>
                <th class="text-end">Pieces</th><th class="text-end">Realized</th>
                <th class="text-end">Fee + taxes</th><th class="text-end">Payable by LAAM</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @forelse($invoices as $i => $inv)
                <tr>
                  <td>{{ $i + 1 }}</td>
                  <td>{{ $inv->invoice_no }}</td>
                  <td>{{ $inv->date->format('d-M-Y') }}</td>
                  <td>{{ $inv->period_from?->format('d-M') }}{{ $inv->period_to ? ' – ' . $inv->period_to->format('d-M-Y') : '' }}</td>
                  <td>{{ $inv->location->name ?? '-' }}</td>
                  <td class="text-end">{{ $inv->items_count }}</td>
                  <td class="text-end">{{ number_format($inv->total_realized, 2) }}</td>
                  <td class="text-end text-danger">{{ number_format($inv->total_fee + $inv->total_pra_tax + $inv->total_sales_tax + $inv->total_income_tax, 2) }}</td>
                  <td class="text-end fw-bold text-success">{{ number_format($inv->total_payable, 2) }}</td>
                  <td>
                    <a href="{{ route('laam_invoices.show', $inv->id) }}" class="text-primary" title="View"><i class="fas fa-eye"></i></a>
                    @can('laam_invoices.delete')
                      <form action="{{ route('laam_invoices.destroy', $inv->id) }}" method="POST" style="display:inline;">
                        @csrf @method('DELETE')
                        <button class="btn btn-link p-0 ms-1 text-danger" onclick="return confirm('Delete this LAAM sale? Stock returns to LAAM and the ledger entries are removed.')">
                          <i class="fas fa-trash-alt"></i>
                        </button>
                      </form>
                    @endcan
                  </td>
                </tr>
              @empty
                <tr><td colspan="10" class="text-center text-muted">No LAAM sales yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>
@endsection
