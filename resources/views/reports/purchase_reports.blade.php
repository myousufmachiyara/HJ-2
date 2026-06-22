@extends('layouts.app')
@section('title', 'Purchase Reports')

@section('content')
<div class="tabs">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab=='PUR' ? 'active' : '' }}" data-bs-toggle="tab" href="#PUR">
                <i class="fas fa-shopping-cart me-1"></i> Purchase Register
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='PR' ? 'active' : '' }}" data-bs-toggle="tab" href="#PR">
                <i class="fas fa-undo me-1"></i> Purchase Returns
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab=='VWP' ? 'active' : '' }}" data-bs-toggle="tab" href="#VWP">
                <i class="fas fa-users me-1"></i> Vendor-wise Purchases
            </a>
        </li>
    </ul>

    <div class="tab-content mt-3">

        {{-- ── PURCHASE REGISTER ─────────────────────────────────── --}}
        <div id="PUR" class="tab-pane fade {{ $tab=='PUR' ? 'show active' : '' }}">

            <form method="GET" action="{{ route('reports.purchase') }}">
                <input type="hidden" name="tab" value="PUR">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control"
                               value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control"
                               value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control select2">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}"
                                    {{ request('vendor_id') == $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <a href="{{ route('reports.purchase') }}?tab=PUR" class="btn btn-secondary w-100">
                            Reset
                        </a>
                    </div>
                </div>
            </form>

            @php
                $grandTotal = collect($purchaseRegister)->sum(fn($r) => $r->total);
                $grandQty   = collect($purchaseRegister)->sum(fn($r) => $r->quantity);
            @endphp

            <div class="row mb-3">
                <div class="col text-end">
                    <span class="me-4">Total Qty: <strong class="text-primary">{{ number_format($grandQty, 2) }}</strong></span>
                    <span>Total Purchase: <strong class="text-danger fs-5">{{ number_format($grandTotal, 2) }}</strong></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Invoice No</th>
                            <th>Vendor</th>
                            <th>Item</th>
                            <th>Variation</th>
                            <th>Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchaseRegister as $i => $pur)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ \Carbon\Carbon::parse($pur->date)->format('d-m-Y') }}</td>
                                <td>{{ $pur->invoice_no }}</td>
                                <td>{{ $pur->vendor_name }}</td>
                                <td>{{ $pur->item_name }}</td>
                                <td>{{ $pur->variation !== '-' ? $pur->variation : '' }}</td>
                                <td>{{ $pur->unit }}</td>
                                <td class="text-end">{{ number_format($pur->quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($pur->rate, 2) }}</td>
                                <td class="text-end">{{ number_format($pur->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-3">
                                    No purchase records found for selected criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($purchaseRegister) > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <th colspan="7" class="text-end">Grand Total</th>
                            <th class="text-end">{{ number_format($grandQty, 2) }}</th>
                            <th class="text-end">—</th>
                            <th class="text-end">{{ number_format($grandTotal, 2) }}</th>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── PURCHASE RETURNS ──────────────────────────────────── --}}
        <div id="PR" class="tab-pane fade {{ $tab=='PR' ? 'show active' : '' }}">

            <form method="GET" action="{{ route('reports.purchase') }}">
                <input type="hidden" name="tab" value="PR">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control"
                               value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control"
                               value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control select2">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}"
                                    {{ request('vendor_id') == $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <a href="{{ route('reports.purchase') }}?tab=PR" class="btn btn-secondary w-100">
                            Reset
                        </a>
                    </div>
                </div>
            </form>

            @php
                $returnTotal = collect($purchaseReturns)->sum(fn($r) => $r->total);
                $returnQty   = collect($purchaseReturns)->sum(fn($r) => $r->quantity);
            @endphp

            <div class="row mb-3">
                <div class="col text-end">
                    <span class="me-4">Total Qty Returned: <strong class="text-warning">{{ number_format($returnQty, 2) }}</strong></span>
                    <span>Total Returns: <strong class="text-danger fs-5">{{ number_format($returnTotal, 2) }}</strong></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Return No</th>
                            <th>Vendor</th>
                            <th>Item</th>
                            <th>Variation</th>
                            <th>Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchaseReturns as $i => $pr)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ \Carbon\Carbon::parse($pr->date)->format('d-m-Y') }}</td>
                                <td>{{ $pr->return_no }}</td>
                                <td>{{ $pr->vendor_name }}</td>
                                <td>{{ $pr->item_name }}</td>
                                <td>{{ $pr->variation !== '-' ? $pr->variation : '' }}</td>
                                <td>{{ $pr->unit }}</td>
                                <td class="text-end">{{ number_format($pr->quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($pr->rate, 2) }}</td>
                                <td class="text-end">{{ number_format($pr->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-3">
                                    No purchase return records found for selected criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($purchaseReturns) > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <th colspan="7" class="text-end">Grand Total</th>
                            <th class="text-end">{{ number_format($returnQty, 2) }}</th>
                            <th class="text-end">—</th>
                            <th class="text-end">{{ number_format($returnTotal, 2) }}</th>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── VENDOR-WISE PURCHASES ─────────────────────────────── --}}
        <div id="VWP" class="tab-pane fade {{ $tab=='VWP' ? 'show active' : '' }}">

            <form method="GET" action="{{ route('reports.purchase') }}">
                <input type="hidden" name="tab" value="VWP">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>Vendor</label>
                        <select name="vendor_id" class="form-control select2">
                            <option value="">-- All Vendors --</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}"
                                    {{ request('vendor_id') == $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control"
                               value="{{ request('from_date', $from) }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control"
                               value="{{ request('to_date', $to) }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <a href="{{ route('reports.purchase') }}?tab=VWP" class="btn btn-secondary w-100">
                            Reset
                        </a>
                    </div>
                </div>
            </form>

            @php
                $vendorGrandAmount = collect($vendorWisePurchase)->sum(fn($v) => $v->total_amount);
                $vendorGrandQty    = collect($vendorWisePurchase)->sum(fn($v) => $v->total_qty);
            @endphp

            <div class="row mb-3">
                <div class="col text-end">
                    <span class="me-4">Total Qty: <strong class="text-primary">{{ number_format($vendorGrandQty, 2) }}</strong></span>
                    <span>Total Purchases: <strong class="text-success fs-5">{{ number_format($vendorGrandAmount, 2) }}</strong></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Vendor / Item</th>
                            <th>Date</th>
                            <th>Invoice No</th>
                            <th>Item</th>
                            <th>Variation</th>
                            <th>Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($vendorWisePurchase as $vendorData)
                            {{-- Vendor header row --}}
                            <tr class="table-dark">
                                <td colspan="9">
                                    <i class="fas fa-user me-1"></i>
                                    <strong>{{ $vendorData->vendor_name }}</strong>
                                    <span class="float-end">
                                        Qty: {{ number_format($vendorData->total_qty, 2) }} &nbsp;|&nbsp;
                                        Total: {{ number_format($vendorData->total_amount, 2) }}
                                    </span>
                                </td>
                            </tr>

                            {{-- Item rows --}}
                            @foreach($vendorData->items as $i => $item)
                                <tr>
                                    <td class="ps-4 text-muted">{{ $i + 1 }}</td>
                                    <td>{{ \Carbon\Carbon::parse($item->invoice_date)->format('d-m-Y') }}</td>
                                    <td>{{ $item->invoice_no }}</td>
                                    <td>{{ $item->item_name }}</td>
                                    <td>{{ $item->variation !== '-' ? $item->variation : '' }}</td>
                                    <td>{{ $item->unit }}</td>
                                    <td class="text-end">{{ number_format($item->quantity, 2) }}</td>
                                    <td class="text-end">{{ number_format($item->rate, 2) }}</td>
                                    <td class="text-end">{{ number_format($item->total, 2) }}</td>
                                </tr>
                            @endforeach

                            {{-- Vendor subtotal --}}
                            <tr class="table-secondary fw-bold">
                                <td colspan="6" class="text-end">
                                    {{ $vendorData->vendor_name }} — Subtotal
                                </td>
                                <td class="text-end">{{ number_format($vendorData->total_qty, 2) }}</td>
                                <td class="text-end">—</td>
                                <td class="text-end">{{ number_format($vendorData->total_amount, 2) }}</td>
                            </tr>

                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-3">
                                    No vendor purchase data found for selected criteria.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>

                    @if(count($vendorWisePurchase) > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <th colspan="6" class="text-end">Grand Total</th>
                            <th class="text-end">{{ number_format($vendorGrandQty, 2) }}</th>
                            <th class="text-end">—</th>
                            <th class="text-end">{{ number_format($vendorGrandAmount, 2) }}</th>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

    </div>
</div>
@endsection