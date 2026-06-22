@extends('layouts.app')
@section('title', 'Sale Invoices')

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
        <h2 class="card-title">All Sale Invoices</h2>
        <a href="{{ route('sale_invoices.create') }}" class="btn btn-primary">
          <i class="fas fa-plus"></i> New Sale Invoice
        </a>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover" id="saleTable">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Type</th>
                <th class="text-end">Net Amount</th>
                <th class="text-end">Paid</th>
                <th class="text-end">Balance</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoices as $index => $invoice)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td>{{ $invoice->invoice_no }}</td>
                  <td>{{ \Carbon\Carbon::parse($invoice->date)->format('d-M-Y') }}</td>
                  <td>{{ $invoice->account->name ?? 'Walk-in' }}</td>
                  <td>
                    <span class="badge {{ $invoice->type === 'credit' ? 'bg-warning text-dark' : 'bg-success' }}">
                      {{ ucfirst($invoice->type) }}
                    </span>
                  </td>
                  <td class="text-end">{{ number_format($invoice->net_amount, 2) }}</td>
                  <td class="text-end text-success">{{ number_format($invoice->paid_amount, 2) }}</td>
                  <td class="text-end {{ $invoice->balance > 0 ? 'text-danger fw-bold' : '' }}">
                    {{ number_format($invoice->balance, 2) }}
                  </td>
                  <td>
                    @php
                      $badge = match($invoice->payment_status) {
                        'paid'    => 'bg-success',
                        'partial' => 'bg-warning text-dark',
                        default   => 'bg-danger',
                      };
                    @endphp
                    <span class="badge {{ $badge }}">{{ ucfirst($invoice->payment_status) }}</span>
                  </td>
                  <td>
                    <a href="{{ route('sale_invoices.edit', $invoice->id) }}"
                       class="text-primary ms-1" title="Edit"><i class="fas fa-edit"></i></a>
                    <a href="{{ route('sale_invoices.print', $invoice->id) }}"
                       target="_blank" class="text-success ms-1" title="Print"><i class="fas fa-print"></i></a>
                    <form action="{{ route('sale_invoices.destroy', $invoice->id) }}" method="POST"
                          style="display:inline;">
                      @csrf @method('DELETE')
                      <button class="btn btn-link p-0 ms-1 text-danger"
                              onclick="return confirm('Delete this invoice?')">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </form>
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
    $('#saleTable').DataTable({ pageLength: 50, order: [[0, 'desc']] });
  });
</script>
@endsection