@extends('layouts.app')
@section('title', 'LAAM Sale | ' . $invoice->invoice_no)

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
        <h2 class="card-title">LAAM Sale {{ $invoice->invoice_no }}</h2>
        <a href="{{ route('laam_invoices.index') }}" class="btn btn-outline-secondary btn-sm">Back</a>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-2"><small class="text-muted">Date</small><div>{{ $invoice->date->format('d-M-Y') }}</div></div>
          <div class="col-md-3"><small class="text-muted">Report period</small>
            <div>{{ $invoice->period_from?->format('d-M-Y') }} – {{ $invoice->period_to?->format('d-M-Y') }}</div></div>
          <div class="col-md-3"><small class="text-muted">LAAM location</small><div>{{ $invoice->location->name ?? '-' }}</div></div>
          <div class="col-md-2"><small class="text-muted">Receivable account</small><div>{{ $invoice->account->name ?? '-' }}</div></div>
          <div class="col-md-2"><small class="text-muted">File</small><div>{{ $invoice->file_name }}</div></div>
        </div>

        <div class="row mb-3 text-center">
          @foreach([
            ['Pieces', $invoice->items->count(), ''],
            ['Realized sales', number_format($invoice->total_realized, 2), ''],
            ['Platform fee', number_format($invoice->total_fee, 2), 'text-danger'],
            ['PRA tax', number_format($invoice->total_pra_tax, 2), 'text-danger'],
            ['Sales tax', number_format($invoice->total_sales_tax, 2), 'text-danger'],
            ['Income tax', number_format($invoice->total_income_tax, 2), 'text-danger'],
            ['Adjustment', number_format($invoice->total_adjustment, 2), ''],
            ['LAAM owes us', number_format($invoice->total_payable, 2), 'text-success'],
          ] as [$l, $v, $c])
            <div class="col"><div class="border rounded p-2"><small class="text-muted">{{ $l }}</small><div class="fw-bold {{ $c }}">{{ $v }}</div></div></div>
          @endforeach
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-sm">
            <thead class="table-light">
              <tr>
                <th>#</th><th>Item Id</th><th>Order</th><th>Item</th><th>SKU</th><th>Brand</th><th>Realized on</th>
                <th class="text-end">Retail</th><th class="text-end">Realized</th><th class="text-end">Fee</th>
                <th class="text-end">PRA</th><th class="text-end">Sales tax</th><th class="text-end">Income tax</th>
                <th class="text-end">Payable</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoice->items as $i => $it)
                <tr>
                  <td>{{ $i + 1 }}</td>
                  <td>{{ $it->item_id }}</td>
                  <td>{{ $it->shopify_order_number ?: $it->order_number }}</td>
                  <td>{{ $it->item_name }}</td>
                  <td>{{ $it->sku }}</td>
                  <td>{{ $it->brand }}</td>
                  <td>{{ $it->realization_date?->format('d-M') }}</td>
                  <td class="text-end">{{ number_format($it->retail_price, 2) }}</td>
                  <td class="text-end">{{ number_format($it->realized_sales, 2) }}</td>
                  <td class="text-end">{{ number_format($it->net_platform_fee, 2) }}</td>
                  <td class="text-end">{{ number_format($it->pra_tax, 2) }}</td>
                  <td class="text-end">{{ number_format($it->sales_tax, 2) }}</td>
                  <td class="text-end">{{ number_format($it->income_tax, 2) }}</td>
                  <td class="text-end fw-bold">{{ number_format($it->seller_payable, 2) }}</td>
                </tr>
              @endforeach
            </tbody>
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="7" class="text-end">Total</td>
                <td class="text-end">{{ number_format($invoice->total_retail, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->total_realized, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->total_fee, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->total_pra_tax, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->total_sales_tax, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->total_income_tax, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->total_payable, 2) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        @if($invoice->remarks)<p class="mt-2"><strong>Remarks:</strong> {{ $invoice->remarks }}</p>@endif
      </div>
    </section>
  </div>
</div>
@endsection
