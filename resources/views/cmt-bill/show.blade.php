@extends('layouts.app')
@section('title', 'CMT Bill ' . $bill->bill_no)

@section('content')
<div class="row"><div class="col">
  <section class="card">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-warning" style="white-space: pre-line">{{ session('error') }}</div>@endif
    <header class="card-header d-flex justify-content-between">
      <h2 class="card-title">CMT Bill {{ $bill->bill_no }}</h2>
      <div>
        <a href="{{ route('cmt_bills.print', $bill->id) }}" target="_blank" class="btn btn-success"><i class="fas fa-print"></i> Print</a>
        <a href="{{ route('cmt_bills.index') }}" class="btn btn-secondary">Back</a>
      </div>
    </header>
    <div class="card-body">
      <p><b>Vendor:</b> {{ $bill->vendor->name ?? '-' }} &nbsp; <b>Period:</b> {{ \Carbon\Carbon::parse($bill->date_from)->format('d-m-Y') }} → {{ \Carbon\Carbon::parse($bill->date_to)->format('d-m-Y') }} &nbsp; <b>Bill date:</b> {{ \Carbon\Carbon::parse($bill->bill_date)->format('d-m-Y') }}</p>
      @if($bill->remarks)<p><b>Remarks:</b> {{ $bill->remarks }}</p>@endif
      <table class="table table-bordered table-sm">
        <thead class="table-light"><tr><th>Category</th><th>SKUs</th><th>Qty</th><th>Rate</th><th>Amount</th><th>Fabric</th><th>Meters/pc</th><th>Fabric used</th><th>Fabric cost</th></tr></thead>
        <tbody>
        @foreach($bill->lines as $l)
          <tr>
            <td>{{ $l->category->name ?? 'Uncategorised' }}</td>
            <td>{{ $l->sku_count }}</td>
            <td>{{ (float) $l->qty }}</td>
            <td class="text-end">{{ number_format($l->rate, 2) }}</td>
            <td class="text-end">{{ number_format($l->amount, 2) }}</td>
            <td>{{ $l->fabric->name ?? '—' }}</td>
            <td>{{ $l->fabric_id ? (float) $l->meters_per_pc : '—' }}</td>
            <td>{{ $l->fabric_id ? (float) $l->fabric_qty : '—' }}</td>
            <td class="text-end">{{ $l->fabric_id ? number_format($l->fabric_amount, 2) : '—' }}</td>
          </tr>
        @endforeach
        </tbody>
        <tfoot class="table-light"><tr><th colspan="2" class="text-end">Total</th><th>{{ (float) $bill->total_qty }}</th><th></th><th class="text-end">{{ number_format($bill->cmt_amount, 2) }}</th><th colspan="3"></th><th class="text-end">{{ number_format($bill->fabric_amount, 2) }}</th></tr></tfoot>
      </table>
    </div>
  </section>
</div></div>
@endsection
