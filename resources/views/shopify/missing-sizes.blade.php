@extends('layouts.app')
@section('title', 'Missing Sizes on Shopify')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Missing Sizes / SKUs on Shopify</h2>
        <a href="{{ route('shopify.settings') }}" class="btn btn-outline-secondary btn-sm">Shopify Stores / Sync History</a>
      </header>

      <div class="card-body">
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($scanError)<div class="alert alert-danger">{{ $scanError }}</div>@endif

        <p class="text-muted">
          Finds sizes that are in Billtrix but not on the product's Shopify page (for example sizes that came from LAAM).
          A size counts as already there if its SKU or barcode is on that Shopify product, or Shopify already has the same size.
          Only the missing sizes are added (with price, compare-at price, SKU and barcode); nothing else on Shopify changes, and stock is not sent.
        </p>

        <form method="GET" class="row g-2 align-items-end mb-4">
          <div class="col-md-3">
            <label class="form-label">Shopify store</label>
            <select name="store_id" class="form-control" required>
              <option value="">Select store</option>
              @foreach($stores as $s)<option value="{{ $s->id }}" @selected($store && $store->id === $s->id)>{{ $s->shop_name }}</option>@endforeach
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Name / SKU contains</label>
            <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="all products">
          </div>
          <div class="col-md-2">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-control">
              <option value="">All</option>
              @foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Max products to check</label>
            <input type="number" name="limit" min="1" max="1000" value="{{ request('limit', 200) }}" class="form-control">
          </div>
          <div class="col-md-2"><button class="btn btn-primary w-100" onclick="this.innerText='Checking Shopify…'"><i class="fa fa-search"></i> Check Shopify</button></div>
        </form>

        @if($store && $result !== null)
          @php
            $withMissing = collect($result)->filter(fn ($r) => count($r['missing']));
            $notes       = collect($result)->filter(fn ($r) => $r['note']);
            $total       = $withMissing->sum(fn ($r) => count($r['missing']));
          @endphp

          <div class="alert alert-info">
            Checked <strong>{{ $scanned }}</strong> product(s) on <strong>{{ $store->shop_name }}</strong>:
            <strong>{{ $total }}</strong> missing size(s) in <strong>{{ $withMissing->count() }}</strong> product(s).
            @if($notes->count()) {{ $notes->count() }} product(s) could not be compared (see below). @endif
          </div>

          @if($withMissing->isNotEmpty())
          <form method="POST" action="{{ route('shopify.missing-sizes.push', $store->id) }}" onsubmit="return confirm('Add the ticked sizes to Shopify?')">
            @csrf
            <div class="mb-2">
              <label class="me-3"><input type="checkbox" id="checkAll" checked> Select all</label>
              <button class="btn btn-success"><i class="fa fa-upload"></i> Add ticked sizes to Shopify (<span id="n">{{ $total }}</span>)</button>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle">
                <thead class="table-light"><tr><th>Product</th><th>Missing on Shopify</th><th>Price</th></tr></thead>
                <tbody>
                @foreach($withMissing as $r)
                  <tr>
                    <td><strong>{{ $r['product']->name }}</strong><br><small class="text-muted">{{ $r['product']->sku }}</small>
                      @if($r['same_size_other_sku'])<br><small class="text-warning">{{ $r['same_size_other_sku'] }} size(s) are on Shopify with a different SKU — use Fix SKUs</small>@endif
                      @if($r['note'])<br><small class="text-danger">{{ $r['note'] }}</small>@endif
                      @if(!empty($r['duplicates']))<br><small class="text-danger">Same size twice in the software — only the first is added. Not added: {{ implode(', ', $r['duplicates']) }}. Merge or delete the duplicate in Billtrix.</small>@endif
                    </td>
                    <td>
                      @foreach($r['missing'] as $m)
                        <label class="d-block">
                          <input type="checkbox" class="pick" name="items[{{ $r['product']->id }}][]" value="{{ $m['variation']->id }}" checked>
                          {{ implode(' / ', $m['values']) }} <small class="text-muted">— {{ $m['variation']->sku }}</small>
                        </label>
                      @endforeach
                    </td>
                    <td>
                      @foreach($r['missing'] as $m)
                        <div>{{ number_format($m['variation']->salePrice(), 2) }}@if($m['variation']->comparePrice()) <small class="text-muted"><s>{{ number_format($m['variation']->comparePrice(), 2) }}</s></small>@endif</div>
                      @endforeach
                    </td>
                  </tr>
                @endforeach
                </tbody>
              </table>
            </div>
          </form>
          <script>
            (function () {
              const picks = () => Array.from(document.querySelectorAll('.pick'));
              const upd = () => { document.getElementById('n').textContent = picks().filter(c => c.checked).length; };
              document.getElementById('checkAll').addEventListener('change', e => { picks().forEach(c => c.checked = e.target.checked); upd(); });
              document.addEventListener('change', e => { if (e.target.classList.contains('pick')) upd(); });
            })();
          </script>
          @endif

          @if($notes->isNotEmpty())
            <h6 class="mt-4">Could not compare</h6>
            <ul class="small">
              @foreach($notes as $r)<li><strong>{{ $r['product']->sku }}</strong> {{ $r['product']->name }} — {{ $r['note'] }}</li>@endforeach
            </ul>
          @endif
        @endif
      </div>
    </section>
  </div>
</div>
@endsection
