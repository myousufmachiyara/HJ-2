@extends('layouts.app')

@section('title', 'Shopify | Push Check')

@section('content')
@php
  $sizes = fn ($p) => $p->variations->count() ?: 1;
  $sections = [
    ['create',     'Will be created on Shopify (Draft, no stock)', 'success', 'Not on Shopify yet — none of their SKUs / barcodes were found there.'],
    ['link',       'Already on Shopify — only linked, nothing created', 'info', 'A size with the same SKU or barcode is already on Shopify. The software just remembers the link so it is never pushed again.'],
    ['conflict',   'Not sent — SKUs found on several Shopify products', 'danger', 'Sizes of one software product are on different Shopify products. Check these by hand.'],
    ['otherStore', 'Not sent — brand belongs to another store', 'secondary', ''],
    ['noBrand',    'Not sent — brand not set for any store', 'warning', 'Add the brand to a store (Shopify Stores → Brands) if these should be pushed.'],
  ];
@endphp
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="card-title mb-0"><i class="fab fa-shopify text-success"></i> Push check — {{ $store->shop_name }}</h2>
        <div class="d-flex gap-2">
          <a href="{{ route('shopify.store.push-preview', [$store->id, 'download' => 1]) }}" class="btn btn-outline-secondary btn-sm"><i class="fas fa-download"></i> Download list</a>
          <a href="{{ route('shopify.settings') }}" class="btn btn-default btn-sm">Back</a>
        </div>
      </header>
      <div class="card-body">
        <p class="mb-2">
          Brands for this store: <b>{{ str_replace("\n", ', ', (string) $store->brands) ?: 'all (only store)' }}</b>.
          Shopify has <b>{{ $plan->shopifyProducts }}</b> product(s) / <b>{{ count($plan->catalog) }}</b> SKUs &amp; barcodes — every product below was checked against them.
        </p>

        <div class="d-flex flex-wrap gap-2 mb-3">
          @foreach($sections as [$key, $title, $color])
            <a href="#sec-{{ $key }}" class="btn btn-sm btn-outline-{{ $color }}">{{ $title }}: <b>{{ $plan->$key->count() }}</b></a>
          @endforeach
        </div>

        @if($plan->create->count() || $plan->link->count())
          <form method="POST" action="{{ route('shopify.store.push', $store->id) }}" class="alert alert-success d-flex flex-wrap align-items-center gap-2"
                onsubmit="this.querySelector('button').disabled=true; return confirm('Create {{ $plan->create->count() }} product(s) on {{ addslashes($store->shop_name) }} as Draft and link {{ $plan->link->count() }}?')">
            @csrf
            <span>Create <b>{{ $plan->create->count() }}</b> product(s) ({{ $plan->create->sum(fn ($x) => $sizes($x->product)) }} sizes) as <b>Draft</b>, link <b>{{ $plan->link->count() }}</b>. Stock is not sent; nothing on Shopify is changed or deleted.</span>
            <button class="btn btn-success btn-sm"><i class="fas fa-upload"></i> Push now</button>
          </form>
        @else
          <div class="alert alert-info">Nothing to create — every product of these brands is already on Shopify.</div>
        @endif

        @foreach($sections as [$key, $title, $color, $help])
          @continue($plan->$key->isEmpty())
          <h5 id="sec-{{ $key }}" class="mt-4 text-{{ $color === 'secondary' ? 'muted' : $color }}">{{ $title }} ({{ $plan->$key->count() }})</h5>
          @if($help)<p class="text-muted small mb-1">{{ $help }}</p>@endif
          <div class="table-responsive" style="max-height:420px;overflow:auto">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light"><tr><th>#</th><th>Product</th><th>SKU</th><th>Brand</th><th>Sizes</th><th>Note</th></tr></thead>
              <tbody>
                @foreach($plan->$key as $i => $x)
                  <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $x->product->name }}</td>
                    <td>{{ $x->product->sku }}</td>
                    <td>{{ $x->product->brand ?: '—' }}</td>
                    <td class="small">{{ $x->product->variations->pluck('sku')->implode(', ') ?: '—' }}</td>
                    <td class="small">
                      @if($key === 'link') Shopify #{{ $x->shopify_id }}@if($x->missing) — <span class="text-danger">{{ $x->missing }} size(s) not on Shopify</span>@endif
                      @elseif($key === 'conflict') Shopify #{{ implode(', #', $x->shopify_ids) }}
                      @elseif($key === 'otherStore') {{ $x->store }}
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endforeach
      </div>
    </section>
  </div>
</div>
@endsection
