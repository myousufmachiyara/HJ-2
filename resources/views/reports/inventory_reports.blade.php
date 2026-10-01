@extends('layouts.app')
@section('title', 'Inventory Reports')

@section('content')
<div class="tabs">

  <ul class="nav nav-tabs flex-wrap">
    <li class="nav-item">
      <a class="nav-link {{ $tab=='IL'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=IL&from_date={{ $from }}&to_date={{ $to }}">
        <i class="fas fa-list me-1"></i> Item Ledger
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link {{ $tab=='SR'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=SR">
        <i class="fas fa-boxes me-1"></i> Stock In Hand
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link {{ $tab=='LOC'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=LOC">
        <i class="fas fa-map-marker-alt me-1"></i> Stock by Location
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link {{ $tab=='WST'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=WST&from_date={{ $from }}&to_date={{ $to }}">
        <i class="fas fa-trash-alt me-1"></i> Wastage Stock
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link {{ $tab=='STR'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=STR&from_date={{ $from }}&to_date={{ $to }}">
        <i class="fas fa-exchange-alt me-1"></i> Stock Movement
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link {{ $tab=='NMI'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=NMI">
        <i class="fas fa-clock me-1"></i> Non-Moving Items
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link {{ $tab=='ROL'?'active':'' }}"
         href="{{ route('reports.inventory') }}?tab=ROL">
        <i class="fas fa-exclamation-triangle me-1"></i> Reorder Level
      </a>
    </li>
  </ul>

  <div class="tab-content mt-3">

    {{-- ── 1. ITEM LEDGER (stock ledger) ────────────────────────── --}}
    @if($tab === 'IL')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="IL">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label>Product / Variation</label>
            <select name="item_id" class="form-control select2-js">
              <option value="">-- Select a product --</option>
              @foreach($products as $product)
                <option value="{{ $product->id }}" {{ request('item_id') == $product->id ? 'selected' : '' }}>{{ $product->name }} ({{ $product->sku }}) — all sizes</option>
                @foreach($product->variations as $var)
                  <option value="{{ $product->id }}-{{ $var->id }}" {{ request('item_id') == $product->id.'-'.$var->id ? 'selected' : '' }}>&nbsp;&nbsp;↳ {{ $var->sku }}</option>
                @endforeach
              @endforeach
            </select>
          </div>
          <div class="col-md-3">
            <label>Location</label>
            <select name="location_id" class="form-control select2-js">
              <option value="">-- All locations --</option>
              @foreach($locations->groupBy(fn($l) => $l->typeLabel()) as $group => $locs)
                <optgroup label="{{ $group }}">
                  @foreach($locs as $loc)
                    <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                  @endforeach
                </optgroup>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label>From</label>
            <input type="date" name="from_date" value="{{ $from }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label>To</label>
            <input type="date" name="to_date" value="{{ $to }}" class="form-control">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
          </div>
        </div>
      </form>

      @if($itemLedger->count())
        @php
          $opening  = $itemLedger->firstWhere('type', 'Opening Balance');
          $movement = $itemLedger->where('type', '!=', 'Opening Balance');
          $totalIn  = $movement->sum('qty_in');
          $totalOut = $movement->sum('qty_out');
          $closing  = $itemLedger->last()['balance'];
          $badges = [
            'Opening Balance' => 'bg-info text-dark', 'Purchase' => 'bg-success', 'Purchase Return' => 'bg-warning text-dark',
            'FG Receiving' => 'bg-primary', 'Fabric Consumed' => 'bg-secondary', 'Movement In' => 'bg-info text-dark',
            'Movement Out' => 'bg-info text-dark', 'Sale' => 'bg-danger', 'POS Sale' => 'bg-danger', 'Sale Return' => 'bg-success',
            'Opening Stock' => 'bg-dark', 'Stock Count' => 'bg-dark', 'Adjustment' => 'bg-dark',
          ];
        @endphp

        <div class="d-flex justify-content-end flex-wrap gap-3 mb-2">
          <span>Opening: <strong>{{ number_format($opening['balance'] ?? 0, 2) }}</strong></span>
          <span>In: <strong class="text-success">{{ number_format($totalIn, 2) }}</strong></span>
          <span>Out: <strong class="text-danger">{{ number_format($totalOut, 2) }}</strong></span>
          <span>Closing: <strong class="text-primary">{{ number_format($closing, 2) }}</strong></span>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-striped table-sm" id="ilTable">
            <thead class="table-light">
              <tr>
                <th>Date</th><th>Type</th><th>Document</th><th>Location</th><th>Variation</th>
                <th class="text-end">Qty In</th><th class="text-end">Qty Out</th><th class="text-end">Rate</th><th class="text-end">Balance</th>
              </tr>
            </thead>
            <tbody>
              @foreach($itemLedger as $row)
                <tr class="{{ $row['type'] === 'Opening Balance' ? 'table-secondary fw-bold' : '' }}">
                  <td data-order="{{ $row['date'] }}">{{ \Carbon\Carbon::parse($row['date'])->format('d-M-Y') }}</td>
                  <td><span class="badge {{ $badges[$row['type']] ?? 'bg-secondary' }}">{{ $row['type'] }}</span></td>
                  <td>{{ $row['document'] }}</td>
                  <td>{{ $row['location'] }}</td>
                  <td>{{ $row['variation'] ?? '—' }}</td>
                  <td class="text-end text-success">{{ $row['qty_in'] > 0 ? number_format($row['qty_in'], 2) : '—' }}</td>
                  <td class="text-end text-danger">{{ $row['qty_out'] > 0 ? number_format($row['qty_out'], 2) : '—' }}</td>
                  <td class="text-end">{{ $row['rate'] > 0 ? number_format($row['rate'], 2) : '—' }}</td>
                  <td class="text-end fw-bold {{ $row['balance'] < 0 ? 'text-danger' : '' }}">{{ number_format($row['balance'], 2) }}</td>
                </tr>
              @endforeach
            </tbody>
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="5" class="text-end">Totals (period)</td>
                <td class="text-end text-success">{{ number_format($totalIn, 2) }}</td>
                <td class="text-end text-danger">{{ number_format($totalOut, 2) }}</td>
                <td class="text-end">Closing</td>
                <td class="text-end text-primary">{{ number_format($closing, 2) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      @elseif(request('item_id'))
        <div class="alert alert-warning">No stock movements for this item in the selected period / location.</div>
      @else
        <div class="alert alert-info"><i class="fas fa-info-circle me-1"></i> Select a product and click Filter to view its stock ledger.</div>
      @endif
    @endif

    {{-- ── 2. STOCK IN HAND (stock ledger) ──────────────────────── --}}
    @if($tab === 'SR')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="SR">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label>Product / Variation</label>
            <select name="item_id" class="form-control select2-js">
              <option value="">-- All Products --</option>
              @foreach($products as $product)
                <option value="{{ $product->id }}" {{ request('item_id') == $product->id ? 'selected' : '' }}>{{ $product->name }} ({{ $product->sku }}) — all sizes</option>
                @foreach($product->variations as $var)
                  <option value="{{ $product->id }}-{{ $var->id }}" {{ request('item_id') == $product->id.'-'.$var->id ? 'selected' : '' }}>&nbsp;&nbsp;↳ {{ $var->sku }}</option>
                @endforeach
              @endforeach
            </select>
          </div>
          <div class="col-md-3">
            <label>Location</label>
            <select name="location_id" class="form-control select2-js">
              <option value="">-- All locations --</option>
              @foreach($locations->groupBy(fn($l) => $l->typeLabel()) as $group => $locs)
                <optgroup label="{{ $group }}">
                  @foreach($locs as $loc)
                    <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                  @endforeach
                </optgroup>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label>Item Type</label>
            <select name="item_type" class="form-control">
              <option value="">All</option>
              <option value="fg" {{ request('item_type') === 'fg' ? 'selected' : '' }}>Finished Goods</option>
              <option value="raw" {{ request('item_type') === 'raw' ? 'selected' : '' }}>Raw / Fabric</option>
              <option value="service" {{ request('item_type') === 'service' ? 'selected' : '' }}>Service</option>
            </select>
          </div>
          <div class="col-md-2">
            <label>As of</label>
            <input type="date" name="to_date" value="{{ $to }}" class="form-control">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
          </div>
        </div>
      </form>

      <div class="alert alert-info mb-3">
        <i class="fas fa-info-circle me-1"></i>
        Quantities from the stock ledger ({{ request('location_id') ? 'selected location' : 'all locations: warehouses, marketplaces and fabric at CMTs' }}),
        valued at weighted-average cost. For finished goods, <em>Mfg cost</em> is the CMT charge and <em>Raw cost</em> the fabric part.
      </div>

      @php
        $grandTotal = $stockInHand->sum('total');
        $grandQty   = $stockInHand->sum('quantity');
      @endphp

      <div class="row mb-3">
        <div class="col text-end">
          @if(($atCustomersTotal ?? 0) != 0)
            <span class="me-3">Of which at marketplaces / customers: <strong class="text-warning">{{ number_format($atCustomersTotal, 2) }}</strong></span>
          @endif
          @if(($atVendorsTotal ?? 0) != 0)
            <span class="me-3">at vendors / CMT: <strong class="text-warning">{{ number_format($atVendorsTotal, 2) }}</strong></span>
          @endif
          @if(($atCustomersTotal ?? 0) != 0 || ($atVendorsTotal ?? 0) != 0)
            <a href="{{ route('reports.inventory') }}?tab=LOC" class="small me-3">(by location →)</a>
          @endif
          <span class="me-3">Total Qty: <strong>{{ number_format($grandQty, 2) }}</strong></span>
          <span>Total Value: <strong class="text-danger fs-5">PKR {{ number_format($grandTotal, 0) }}</strong></span>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="srTable">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Variation</th>
              <th class="text-end">Qty</th>
              <th class="text-end">Raw Cost/unit</th>
              <th class="text-end">Mfg Cost/unit</th>
              <th class="text-end">Total Cost/unit</th>
              <th class="text-end">Total Value</th>
            </tr>
          </thead>
          <tbody>
            @forelse($stockInHand as $stock)
              <tr class="{{ $stock['quantity'] < 0 ? 'table-danger' : '' }}">
                <td>{{ $stock['product'] }}</td>
                <td>{{ $stock['variation'] ?? '—' }}</td>
                <td class="text-end {{ $stock['quantity'] < 0 ? 'text-danger fw-bold' : '' }}">
                  {{ number_format($stock['quantity'], 2) }}
                </td>
                <td class="text-end">{{ number_format($stock['raw_cost'], 2) }}</td>
                <td class="text-end">{{ number_format($stock['mfg_cost'], 2) }}</td>
                <td class="text-end fw-bold">{{ number_format($stock['price'], 2) }}</td>
                <td class="text-end">{{ number_format($stock['total'], 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted">No stock data found.</td></tr>
            @endforelse
          </tbody>
          @if($stockInHand->count())
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="2" class="text-end">Grand Total</td>
                <td class="text-end">{{ number_format($grandQty, 2) }}</td>
                <td colspan="3" class="text-end">—</td>
                <td class="text-end text-danger">{{ number_format($grandTotal, 2) }}</td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── LOCATION STOCK (from stock ledger) ─────────────────── --}}
    @if($tab === 'LOC')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="LOC">
        <div class="row g-2 align-items-end">
          <div class="col-md-5">
            <label>Location (Warehouse / Customer / Vendor)</label>
            <select name="stock_location_id" class="form-control select2-js" onchange="this.form.submit()">
              @foreach($locations->groupBy(fn($l) => $l->typeLabel()) as $group => $locs)
                <optgroup label="{{ $group }}">
                  @foreach($locs as $loc)
                    <option value="{{ $loc->id }}" {{ $selectedLocationId == $loc->id ? 'selected' : '' }}>
                      {{ $loc->name }}{{ $loc->is_default ? ' (Default)' : '' }}
                    </option>
                  @endforeach
                </optgroup>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label>As of</label>
            <input type="date" name="to_date" class="form-control" value="{{ $to }}">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">View</button>
          </div>
        </div>
      </form>

      @php
        $selLoc      = $locations->firstWhere('id', $selectedLocationId);
        $locTotalQty = $locationStock->sum('quantity');
        $locTotalVal = $locationStock->sum('value');
      @endphp

      <div class="alert alert-info mb-3">
        <i class="fas fa-map-marker-alt me-1"></i>
        @if($selLoc)
          Stock at <strong>{{ $selLoc->name }}</strong> ({{ $selLoc->typeLabel() }}) as of {{ \Carbon\Carbon::parse($to)->format('d-M-Y') }}:
          purchases dropped here, FG received, fabric consumed, stock movements in/out, sales & returns at this location and stock adjustments.
          Negative rows mean more went out than the software knows came in — fix with a Stock Adjustment (Physical Count).
        @else
          Select a location to view its stock.
        @endif
      </div>

      @if($selLoc)
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-sm" id="locTable">
            <thead class="table-light">
              <tr>
                <th>Product</th>
                <th>Variation</th>
                <th class="text-end">Quantity</th>
                <th class="text-end">Avg Cost</th>
                <th class="text-end">Value</th>
              </tr>
            </thead>
            <tbody>
              @forelse($locationStock as $row)
                <tr class="{{ $row['quantity'] < 0 ? 'table-danger' : '' }}">
                  <td>{{ $row['product'] }}</td>
                  <td>{{ $row['variation'] ?? '—' }}</td>
                  <td class="text-end fw-bold {{ $row['quantity'] < 0 ? 'text-danger' : '' }}">{{ number_format($row['quantity'], 2) }}</td>
                  <td class="text-end">{{ number_format($row['unit_cost'] ?? 0, 2) }}</td>
                  <td class="text-end">{{ number_format($row['value'] ?? 0, 2) }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No stock at this location.</td></tr>
              @endforelse
            </tbody>
            @if($locationStock->count())
              <tfoot class="table-light fw-bold">
                <tr>
                  <td colspan="2" class="text-end">Total</td>
                  <td class="text-end {{ $locTotalQty < 0 ? 'text-danger' : 'text-primary' }}">{{ number_format($locTotalQty, 2) }}</td>
                  <td></td>
                  <td class="text-end">{{ number_format($locTotalVal, 2) }}</td>
                </tr>
              </tfoot>
            @endif
          </table>
        </div>
      @endif
    @endif

    {{-- ── 3. WASTAGE STOCK ────────────────────────────────────── --}}
    @if($tab === 'WST')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="WST">
        <div class="row g-2 align-items-end">
          <div class="col-md-2">
            <label>From</label>
            <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label>To</label>
            <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
          </div>
          <div class="col-md-3">
            <label>Costing Method <small class="text-muted">(for estimated loss value)</small></label>
            <select name="costing_method" class="form-control select2-js">
              <option value="avg"    {{ request('costing_method','avg') == 'avg'    ? 'selected':'' }}>Average Rate</option>
              <option value="max"    {{ request('costing_method','avg') == 'max'    ? 'selected':'' }}>Max Rate</option>
              <option value="min"    {{ request('costing_method','avg') == 'min'    ? 'selected':'' }}>Min Rate</option>
              <option value="latest" {{ request('costing_method','avg') == 'latest' ? 'selected':'' }}>Latest Rate</option>
            </select>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">
              <i class="fas fa-filter me-1"></i> Filter
            </button>
          </div>
        </div>
      </form>

      <div class="alert alert-danger mb-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <strong>Wastage Stock Register</strong> — Raw material written off as production wastage.
        These quantities are <strong>NOT</strong> counted in real inventory stock.
        Estimated loss value is based on the purchase cost using the selected costing method.
      </div>

      @php
        $totalWstQty  = $wastageStock->sum('total_qty');
        $totalWstCost = $wastageStock->sum('total_cost');
      @endphp

      <div class="row mb-3 text-center g-2">
        <div class="col-md-3">
          <div class="border rounded p-3 bg-danger bg-opacity-10">
            <small class="text-light d-block">Products Written Off</small>
            <strong class="text-light fs-4">{{ $wastageStock->count() }}</strong>
            <small class="text-light d-block">product(s)</small>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 bg-danger bg-opacity-10">
            <small class="text-light d-block">Total Wastage Qty</small>
            <strong class="text-light fs-4">{{ number_format($totalWstQty, 3) }}</strong>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 bg-warning bg-opacity-10">
            <small class="text-light d-block">Estimated Loss Value</small>
            <strong class="text-light fs-4">PKR {{ number_format($totalWstCost, 0) }}</strong>
            <small class="text-light d-block">based on purchase rate</small>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded p-3 bg-secondary bg-opacity-10">
            <small class="text-light d-block">Period</small>
            <strong class="text-light">{{ \Carbon\Carbon::parse($from)->format('d-M-Y') }}</strong>
            <small class="text-light d-block">to {{ \Carbon\Carbon::parse($to)->format('d-M-Y') }}</small>
          </div>
        </div>
      </div>

      @forelse($wastageStock as $item)
        <div class="card mb-3 border-danger">
          <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <i class="fas fa-trash-alt text-danger me-1"></i>
              <strong class="text-danger">{{ $item->product }}</strong>
              @if($item->variation)
                <span class="badge bg-secondary ms-2">{{ $item->variation }}</span>
              @endif
              <span class="ms-2 text-muted small">{{ $item->unit }}</span>
            </div>
            <div class="d-flex gap-3 align-items-center flex-wrap">
              <div class="text-center">
                <small class="text-muted d-block">Total Written Off</small>
                <strong class="text-danger">{{ number_format($item->total_qty, 3) }} {{ $item->unit }}</strong>
              </div>
              <div class="text-center">
                <small class="text-muted d-block">Cost / Unit</small>
                <strong>PKR {{ number_format($item->cost_per_unit, 2) }}</strong>
              </div>
              <div class="text-center">
                <small class="text-muted d-block">Est. Loss</small>
                <strong class="text-warning">PKR {{ number_format($item->total_cost, 0) }}</strong>
              </div>
            </div>
          </div>

          <div class="card-body p-0">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>WRN #</th>
                  <th>Vendor</th>
                  <th>Production #</th>
                  <th class="text-end">Qty Written Off</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
                @foreach($item->entries as $entry)
                  <tr>
                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('d-M-Y') }}</td>
                    <td>
                      <strong class="text-danger">{{ $entry['wrn_no'] }}</strong>
                    </td>
                    <td>{{ $entry['vendor'] }}</td>
                    <td>
                      @if($entry['production_id'])
                        <a href="{{ route('production.edit', $entry['production_id']) }}">
                          PO-{{ $entry['production_id'] }}
                        </a>
                      @else
                        <span class="text-muted">—</span>
                      @endif
                    </td>
                    <td class="text-end text-danger fw-bold">
                      {{ number_format($entry['qty'], 3) }}
                    </td>
                    <td><small class="text-muted">{{ $entry['remarks'] !== '-' ? $entry['remarks'] : '—' }}</small></td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot class="table-light fw-bold">
                <tr>
                  <td colspan="4" class="text-end">Total Written Off</td>
                  <td class="text-end text-danger">{{ number_format($item->total_qty, 3) }}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      @empty
        <div class="text-center py-5">
          <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
          <p class="text-success fw-bold">No wastage write-offs found in this period.</p>
          <small class="text-muted">All raw returns in this period were marked as "Extra" (back to stock).</small>
        </div>
      @endforelse
    @endif

    {{-- ── 4. STOCK TRANSFER ───────────────────────────────────── --}}
    @if($tab === 'STR')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="STR">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label>From Location</label>
            <select name="from_location_id" class="form-control select2-js">
              <option value="">-- All --</option>
              @foreach($locations as $loc)
                <option value="{{ $loc->id }}" {{ request('from_location_id') == $loc->id ? 'selected' : '' }}>
                  {{ $loc->name }}
                </option>
              @endforeach
            </select>
          </div>
          <div class="col-md-3">
            <label>To Location</label>
            <select name="to_location_id" class="form-control select2-js">
              <option value="">-- All --</option>
              @foreach($locations as $loc)
                <option value="{{ $loc->id }}" {{ request('to_location_id') == $loc->id ? 'selected' : '' }}>
                  {{ $loc->name }}
                </option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label>From</label>
            <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label>To</label>
            <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="strTable">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Reference</th>
              <th>Type</th>
              <th>Product</th>
              <th>Variation</th>
              <th>From</th>
              <th>To</th>
              <th class="text-end">Qty</th>
              <th class="text-end">Value</th>
            </tr>
          </thead>
          <tbody>
            @forelse($stockTransfers as $st)
              @php
                $typeBadge = match($st['type'] ?? 'transfer') {
                  'dc'        => ['bg-primary', 'Delivery Challan'],
                  'return_dc' => ['bg-warning text-dark', 'Return DC'],
                  default     => ['bg-secondary', 'Transfer'],
                };
              @endphp
              <tr>
                <td>{{ \Carbon\Carbon::parse($st['date'])->format('d-M-Y') }}</td>
                <td>
                  <a href="{{ url('/stock_transfer/'.$st['reference'].'/print') }}" target="_blank">
                    ST#{{ $st['reference'] }}
                  </a>
                </td>
                <td><span class="badge {{ $typeBadge[0] }}">{{ $typeBadge[1] }}</span></td>
                <td>{{ $st['product'] }}</td>
                <td>{{ $st['variation'] ?? '—' }}</td>
                <td>{{ $st['from'] }}</td>
                <td>{{ $st['to'] }}</td>
                <td class="text-end">{{ number_format($st['quantity'], 2) }}</td>
                <td class="text-end">{{ number_format($st['value'] ?? 0, 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="9" class="text-center text-muted">No stock movements found.</td></tr>
            @endforelse
          </tbody>
          @if(count($stockTransfers))
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="7" class="text-end">Total Moved</td>
                <td class="text-end">{{ number_format(collect($stockTransfers)->sum('quantity'), 2) }}</td>
                <td class="text-end">{{ number_format(collect($stockTransfers)->sum('value'), 2) }}</td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 5. NON-MOVING ITEMS ─────────────────────────────────── --}}
    @if($tab === 'NMI')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="NMI">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label>Not sold in last (months)</label>
            <input type="number" name="months" value="{{ request('months', 3) }}" min="1" max="60" class="form-control">
          </div>
          <div class="col-md-3">
            <label>Location</label>
            <select name="location_id" class="form-control select2-js">
              <option value="">-- All locations --</option>
              @foreach($locations->groupBy(fn($l) => $l->typeLabel()) as $group => $locs)
                <optgroup label="{{ $group }}">
                  @foreach($locs as $loc)
                    <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                  @endforeach
                </optgroup>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label>Item Type</label>
            <select name="item_type" class="form-control">
              <option value="">All</option>
              <option value="fg" {{ request('item_type') === 'fg' ? 'selected' : '' }}>Finished Goods</option>
              <option value="raw" {{ request('item_type') === 'raw' ? 'selected' : '' }}>Raw / Fabric</option>
              <option value="service" {{ request('item_type') === 'service' ? 'selected' : '' }}>Service</option>
            </select>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
          </div>
        </div>
      </form>

      <div class="alert alert-warning mb-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        Items with stock on hand that have <strong>not been sold</strong> in the last
        <strong>{{ request('months', 3) }} months</strong> (stock movements between locations don't count as a sale).
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="nmiTable">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Variation</th>
              <th class="text-end">Stock Qty</th>
              <th>Last Sale</th>
              <th class="text-end">Days Inactive</th>
            </tr>
          </thead>
          <tbody>
            @forelse($nonMovingItems as $nmi)
              @php $critical = is_numeric($nmi['days_inactive']) && $nmi['days_inactive'] > 180; @endphp
              <tr class="{{ $critical ? 'table-danger' : 'table-warning' }}">
                <td>{{ $nmi['product'] }}</td>
                <td>{{ $nmi['variation'] ?? '—' }}</td>
                <td class="text-end">{{ number_format($nmi['stock_qty'], 2) }}</td>
                <td>
                  @if($nmi['last_date'] === 'Never')
                    <span class="badge bg-danger">Never Sold</span>
                  @else
                    {{ \Carbon\Carbon::parse($nmi['last_date'])->format('d-M-Y') }}
                  @endif
                </td>
                <td class="text-end fw-bold">
                  {{ is_numeric($nmi['days_inactive']) ? $nmi['days_inactive'].' days' : '∞' }}
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="text-center text-success py-3">
                  <i class="fas fa-check-circle me-1"></i>
                  All items have recent movement.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    @endif

    {{-- ── 6. REORDER LEVEL ────────────────────────────────────── --}}
    @if($tab === 'ROL')
      <form method="GET" action="{{ route('reports.inventory') }}" class="mb-3">
        <input type="hidden" name="tab" value="ROL">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label>Location</label>
            <select name="location_id" class="form-control select2-js">
              <option value="">-- All locations --</option>
              @foreach($locations->groupBy(fn($l) => $l->typeLabel()) as $group => $locs)
                <optgroup label="{{ $group }}">
                  @foreach($locs as $loc)
                    <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                  @endforeach
                </optgroup>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label>Item Type</label>
            <select name="item_type" class="form-control">
              <option value="">All</option>
              <option value="fg" {{ request('item_type') === 'fg' ? 'selected' : '' }}>Finished Goods</option>
              <option value="raw" {{ request('item_type') === 'raw' ? 'selected' : '' }}>Raw / Fabric</option>
              <option value="service" {{ request('item_type') === 'service' ? 'selected' : '' }}>Service</option>
            </select>
          </div>
          <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Filter</button></div>
        </div>
      </form>
      <div class="alert alert-danger mb-3">
        <i class="fas fa-exclamation-circle me-1"></i>
        Items where <strong>stock ({{ request('location_id') ? 'at the selected location' : 'at all locations' }}) ≤ reorder level</strong>.
        Only products with a reorder level configured are shown.
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm" id="rolTable">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Variation</th>
              <th class="text-end">Stock In Hand</th>
              <th class="text-end">Reorder Level</th>
              <th class="text-end">Shortage</th>
              <th class="text-end">Min Order Qty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reorderLevel as $rl)
              <tr class="{{ $rl['stock_inhand'] <= 0 ? 'table-danger' : 'table-warning' }}">
                <td>{{ $rl['product'] }}</td>
                <td>{{ $rl['variation'] ?? '—' }}</td>
                <td class="text-end {{ $rl['stock_inhand'] <= 0 ? 'text-danger fw-bold' : '' }}">
                  {{ number_format($rl['stock_inhand'], 2) }}
                </td>
                <td class="text-end">{{ number_format($rl['reorder_level'], 2) }}</td>
                <td class="text-end text-danger fw-bold">{{ number_format($rl['shortage'], 2) }}</td>
                <td class="text-end">{{ number_format($rl['min_order_qty'], 2) }}</td>
                <td>
                  @if($rl['stock_inhand'] <= 0)
                    <span class="badge bg-danger">Out of Stock</span>
                  @else
                    <span class="badge bg-warning text-dark">Reorder Now</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="text-center text-success py-3">
                  <i class="fas fa-check-circle me-1"></i>
                  All stock levels are above reorder points.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    @endif

  </div>
</div>

<script>
  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    $('#ilTable, #srTable, #locTable, #strTable, #nmiTable, #rolTable').DataTable({
      pageLength: 100,
      order: [],
    });
  });
</script>
@endsection