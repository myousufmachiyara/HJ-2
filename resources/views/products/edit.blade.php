@extends('layouts.app')

@section('title', 'Product | Edit')

@section('content')
<div class="row">
  <div class="col">
    <form id="productForm" action="{{ route('products.update', $product->id) }}" method="POST" enctype="multipart/form-data">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Edit Product</h2>
        </header>
        <div class="card-body">
          @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
          @if ($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
          @endif

{{-- ═════ Main fields (driven by Product Type) ═════ --}}
{{--
  Product form — main fields, driven by Product Type.
  1) choose the type  2) only the fields for that type are shown (and submitted).
  Field wrappers carry data-for="fg raw service"; hidden ones are disabled by JS.
--}}
@php
  $p    = $product ?? null;
  $type = old('item_type', $p->item_type ?? '');
  $val  = fn ($field, $default = '') => old($field, $p ? $p->{$field} : $default);
@endphp

{{-- ── 1. Product type ─────────────────────────────────────────── --}}
<div class="mb-4">
  <label class="fw-bold d-block mb-2">Product Type <span class="text-danger">*</span></label>
  <div class="btn-group flex-wrap" role="group" id="productTypeGroup">
    <input type="radio" class="btn-check" name="item_type" id="type_fg" value="fg" {{ $type === 'fg' ? 'checked' : '' }} required>
    <label class="btn btn-outline-primary px-4" for="type_fg"><i class="fas fa-tshirt me-1"></i> Finished Good</label>
    <input type="radio" class="btn-check" name="item_type" id="type_raw" value="raw" {{ $type === 'raw' ? 'checked' : '' }}>
    <label class="btn btn-outline-warning px-4" for="type_raw"><i class="fas fa-scroll me-1"></i> Raw / Fabric</label>
    <input type="radio" class="btn-check" name="item_type" id="type_service" value="service" {{ $type === 'service' ? 'checked' : '' }}>
    <label class="btn btn-outline-secondary px-4" for="type_service"><i class="fas fa-concierge-bell me-1"></i> Service</label>
  </div>
  @error('item_type')<div class="text-danger">{{ $message }}</div>@enderror
  <div id="typeHint" class="text-muted small mt-2">Select the product type to continue.</div>
</div>

<div id="productFields" style="{{ $type ? '' : 'display:none' }}">
  {{-- ── 2. Basic (all types) ─────────────────────────────────── --}}
  <h2 class="card-title mb-2">Basic Details</h2>
  <div class="row pb-2">
    <div class="col-md-3 mb-3">
      <label>Product Name *</label>
      <input type="text" name="name" class="form-control" required value="{{ $val('name') }}">
      @error('name')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3 mb-3">
      <label>Category *</label>
      <select name="category_id" id="category_id" class="form-control select2-js" required>
        <option value="" {{ $val('category_id') ? '' : 'selected' }} disabled>Select Category</option>
        @foreach($categories as $cat)
          <option value="{{ $cat->id }}" data-code="{{ strtoupper($cat->code) }}" {{ (string) $val('category_id') === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
        @endforeach
      </select>
      @error('category_id')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2 mb-3">
      <label>Sub Category</label>
      <select name="subcategory_id" id="subcategory_id" class="form-control">
        <option value="">-- None --</option>
        @foreach($subcategories->where('category_id', $val('category_id')) as $subcat)
          <option value="{{ $subcat->id }}" {{ (string) $val('subcategory_id') === (string) $subcat->id ? 'selected' : '' }}>{{ $subcat->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="col-md-2 mb-3">
      <label>SKU</label>
      <input type="text" name="sku" id="sku" class="form-control" value="{{ $val('sku') }}" placeholder="{{ $p ? '' : 'Auto: select category' }}">
      @unless($p)<small class="text-muted">Blank = auto (CATEGORY-00001)</small>@endunless
      @error('sku')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2 mb-3">
      <label>Barcode</label>
      <input type="text" name="barcode" class="form-control" value="{{ $val('barcode') }}" placeholder="{{ $p ? 'Optional' : 'Blank = same as SKU' }}">
      @error('barcode')<div class="text-danger">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2 mb-3">
      <label>Measurement Unit *</label>
      <select name="measurement_unit" id="unit_id" class="form-control" required>
        <option value="">-- Select Unit --</option>
        @foreach($units as $unit)
          <option value="{{ $unit->id }}" data-short="{{ $unit->shortcode }}" {{ (string) $val('measurement_unit') === (string) $unit->id ? 'selected' : '' }}>{{ $unit->name }} ({{ $unit->shortcode }})</option>
        @endforeach
      </select>
    </div>
    <div class="col-md-2 mb-3">
      <label>Status</label>
      <select name="is_active" class="form-control">
        <option value="1" {{ (string) $val('is_active', 1) === '1' ? 'selected' : '' }}>Active</option>
        <option value="0" {{ (string) $val('is_active', 1) === '0' ? 'selected' : '' }}>Inactive</option>
      </select>
    </div>
  </div>

  {{-- ── 3a. Finished good ────────────────────────────────────── --}}
  <div data-for="fg">
    <h2 class="card-title mb-2">Finished Good — Making &amp; Selling</h2>
    <div class="row pb-2">
      <div class="col-md-2 mb-3">
        <label>CMT Cost <small class="text-muted">(per pc)</small></label>
        <input type="number" step="any" min="0" name="cmt_cost" class="form-control" value="{{ $val('cmt_cost', '0') }}">
        <small class="text-muted">Billed to CMT on receiving</small>
        @error('cmt_cost')<div class="text-danger">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-2 mb-3">
        <label>Selling Price</label>
        <input type="number" step="any" min="0" name="selling_price" class="form-control" value="{{ $val('selling_price', '0') }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Compare At Price</label>
        <input type="number" step="any" min="0" name="compare_at_price" class="form-control" value="{{ $val('compare_at_price') }}" placeholder="Before discount">
      </div>
      <div class="col-md-2 mb-3">
        <label>Brand</label>
        <input type="text" name="brand" class="form-control" value="{{ $val('brand') }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Default CMT Vendor</label>
        <select name="vendor_id" class="form-control select2-js">
          <option value="">-- None --</option>
          @foreach($vendors as $v)
            <option value="{{ $v->id }}" {{ (string) $val('vendor_id') === (string) $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2 mb-3">
        <label>SKU Opening Date</label>
        <input type="date" name="sku_opening_date" class="form-control" value="{{ old('sku_opening_date', $p && $p->sku_opening_date ? $p->sku_opening_date->format('Y-m-d') : date('Y-m-d')) }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Weight</label>
        <input type="number" step="any" min="0" name="weight" class="form-control" value="{{ $val('weight') }}" placeholder="kg">
      </div>
      <div class="col-md-2 mb-3">
        <label>Reorder Level <small class="text-muted">(pcs)</small></label>
        <input type="number" step="any" min="0" name="reorder_level" class="form-control" value="{{ $val('reorder_level', '0') }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Max Stock Level</label>
        <input type="number" step="any" min="0" name="max_stock_level" class="form-control" value="{{ $val('max_stock_level', '0') }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Min Order Qty</label>
        <input type="number" step="any" min="0" name="minimum_order_qty" class="form-control" value="{{ $val('minimum_order_qty', '0') }}">
      </div>
    </div>
  </div>

  {{-- ── 3b. Raw / fabric ─────────────────────────────────────── --}}
  <div data-for="raw">
    <h2 class="card-title mb-2">Raw / Fabric — Purchasing</h2>
    <div class="row pb-2">
      <div class="col-md-3 mb-3">
        <label>Main Supplier</label>
        <select name="vendor_id" class="form-control select2-js">
          <option value="">-- None --</option>
          @foreach($vendors as $v)
            <option value="{{ $v->id }}" {{ (string) $val('vendor_id') === (string) $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2 mb-3">
        <label>Purchase Rate <small class="text-muted">(per unit)</small></label>
        <input type="number" step="any" min="0" name="cost_price" class="form-control" value="{{ $val('cost_price', '0') }}">
        <small class="text-muted">Used until real purchases exist</small>
      </div>
      <div class="col-md-2 mb-3">
        <label>Reorder Level</label>
        <input type="number" step="any" min="0" name="reorder_level" class="form-control" value="{{ $val('reorder_level', '0') }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Min Order Qty</label>
        <input type="number" step="any" min="0" name="minimum_order_qty" class="form-control" value="{{ $val('minimum_order_qty', '0') }}">
      </div>
    </div>
  </div>

  {{-- ── 3c. Service ──────────────────────────────────────────── --}}
  <div data-for="service">
    <h2 class="card-title mb-2">Service — Pricing</h2>
    <div class="row pb-2">
      <div class="col-md-2 mb-3">
        <label>Cost Price</label>
        <input type="number" step="any" min="0" name="cost_price" class="form-control" value="{{ $val('cost_price', '0') }}">
      </div>
      <div class="col-md-2 mb-3">
        <label>Selling Price</label>
        <input type="number" step="any" min="0" name="selling_price" class="form-control" value="{{ $val('selling_price', '0') }}">
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-md-6 mb-3">
      <label>Description</label>
      <textarea name="description" class="form-control" rows="2">{{ $val('description') }}</textarea>
    </div>
  </div>
</div>

<style>#productFields .select2-container, #fabricSection .select2-container { width: 100% !important; }</style>
<script>
  // Show only the fields of the selected product type (hidden ones are disabled so they are not submitted).
  window.productTypeChanged = function () {
    const type = $('input[name="item_type"]:checked').val() || '';
    $('#productFields, #imagesBlock').toggle(!!type);
    $('#typeHint').toggle(!type);
    $('[data-for]').each(function () {
      const on = ($(this).data('for') + '').split(' ').includes(type);
      $(this).toggle(on).find('input, select, textarea').prop('disabled', !on);
    });

    // sensible defaults for a new product
    const $unit = $('#unit_id');
    if (!$unit.val()) {
      const want = type === 'raw' ? 'm' : (type === 'fg' ? 'pcs' : '');
      const $opt = $unit.find('option').filter(function () { return $(this).data('short') === want; }).first();
      if ($opt.length) $unit.val($opt.val());
    }
    const $cat = $('#category_id');
    if (type === 'raw' && !$cat.val()) {
      const $fab = $cat.find('option[data-code="FAB"]');
      if ($fab.length) $cat.val($fab.val()).trigger('change');
    }

    $(document).trigger('product-type-changed', [type]);
  };

  $(function () {
    $('input[name="item_type"]').on('change', window.productTypeChanged);
    window.productTypeChanged();
  });
</script>
{{-- ═════ end main fields ═════ --}}

          <div class="row" id="imagesBlock">
            <div class="col-md-6 mt-3">
              <label>Product Images</label>
              <input type="file" id="imageUpload" name="prod_att[]" multiple class="form-control">
              <small class="text-danger">Leave empty if you don't want to update images.</small>
              <div id="existingImages" class="mt-2 d-flex flex-wrap">
                @foreach($product->images as $img)
                  <div class="existing-image-wrapper position-relative me-2 mb-2">
                    <img src="{{ asset('storage/' . $img->image_path) }}" width="120" height="120" style="object-fit:cover;border-radius:5px;" class="img-thumbnail">
                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 remove-existing-image" data-id="{{ $img->id }}">&times;</button>
                    <input type="hidden" name="keep_images[]" value="{{ $img->id }}">
                  </div>
                @endforeach
              </div>
              <div id="previewContainer" class="mt-2 d-flex flex-wrap"></div>
            </div>
          </div>

          <div class="row mt-4">
            <div class="col-md-12">
              <h2 class="card-title">Existing Variations</h2>
              <div id="variation-section">
                @foreach($product->variations as $i => $variation)
                  <div class="variation-block border p-2 mb-3 existing-variation">
                    <input type="hidden" name="variations[{{ $i }}][id]" value="{{ $variation->id }}">
                    <div class="row">
                      <div class="col-md-3">
                        <label>SKU</label>
                        <input type="text" name="variations[{{ $i }}][sku]" class="form-control sku-field" value="{{ $variation->sku }}">
                      </div>
                      <div class="col-md-2">
                        <label>Barcode</label>
                        <input type="text" name="variations[{{ $i }}][barcode]" class="form-control" value="{{ $variation->barcode }}" placeholder="Manual barcode">
                      </div>
                      <div class="col-md-2">
                        <label>Stock</label>
                        <input type="number" step="any" name="variations[{{ $i }}][stock_quantity]" class="form-control" value="{{ $variation->stock_quantity }}">
                      </div>
                      <div class="col-md-4">
                        <label>Attributes</label>
                        <select name="variations[{{ $i }}][attributes][]" multiple class="form-control select2-js variation-attributes">
                          @foreach($attributes as $attribute)
                            @foreach($attribute->values as $value)
                              <option value="{{ $value->id }}" {{ $variation->attributeValues->pluck('id')->contains($value->id) ? 'selected' : '' }}>
                                {{ $attribute->name }} - {{ $value->value }}
                              </option>
                            @endforeach
                          @endforeach
                        </select>
                      </div>
                      <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-danger remove-existing-variation" data-id="{{ $variation->id }}">X</button>
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>

              <div class="col-md-12 mt-3">
                <h2 class="card-title">Add New Variations</h2>
                <div id="new-variation-section"></div>
                <button type="button" class="btn btn-sm btn-secondary mt-2" id="addNewVariationBtn">Add Variation</button>
              </div>
            </div>
          </div>
{{-- ═════ Fabric: PANNA & Articles (Raw only) ═════ --}}
{{--
  Fabric (raw) setup inside the product form:
    1. PANNA list  → one variation per PANNA
    2. per PANNA: articles made from it + consumption per piece
    3. (edit) where this fabric is now, PANNA-wise
  Shown only when Item Type = Raw.
--}}
@php
  $pannaValues   = $pannaAttr?->values ?? collect();
  $selPannas     = collect(old('fabric_pannas', $selectedPannas ?? []))->map(fn($v) => (string) $v)->all();
  $articleLines  = old('fabric_articles', $fabricRows ?? []);
  if (empty($articleLines)) $articleLines = [['panna_value_id' => '', 'article_id' => '', 'article_variation_id' => '', 'consumption' => '']];
@endphp

<div id="fabricSection" class="mt-4" style="display:none">
  <input type="hidden" name="fabric_setup_present" value="1">
  <div class="border rounded p-3" style="border-color:#c9a227 !important">
    <h2 class="card-title mb-1"><i class="fas fa-scroll me-1"></i> Fabric: PANNA &amp; Articles</h2>
    <small class="text-muted d-block mb-3">
      1) Select the PANNA this fabric comes in — one variation is created per PANNA (e.g. {{ $sku ?? 'FAB-00001' }}-44).
      2) For each PANNA, list the articles made from it and the fabric used per piece. FG Receiving deducts
      pieces × consumption from the CMT that holds this PANNA.
    </small>

    <div class="row mb-3">
      <div class="col-md-8">
        <label>PANNA <span class="text-danger">*</span></label>
        @if($pannaValues->isEmpty())
          <div class="alert alert-warning py-2 mb-0">No PANNA values yet — add them under <a href="{{ route('attributes.index') }}" target="_blank">Products → Attributes → PANNA</a> (e.g. 36", 44", 58"), then reload this page.</div>
        @else
          <select name="fabric_pannas[]" id="fabricPannas" class="form-control select2-js" multiple>
            @foreach($pannaValues as $pv)
              <option value="{{ $pv->id }}" {{ in_array((string) $pv->id, $selPannas, true) ? 'selected' : '' }}>{{ $pv->value }}</option>
            @endforeach
          </select>
        @endif
      </div>
    </div>

    <label class="fw-bold">Articles made from this fabric (consumption per piece)</label>
    <table class="table table-bordered table-sm mb-2">
      <thead class="table-light">
        <tr><th width="16%">PANNA</th><th>Article (finished good)</th><th width="22%">Size</th><th width="14%">Consumption / pc</th><th width="9%"></th></tr>
      </thead>
      <tbody id="fabricArticleRows">
        @foreach($articleLines as $i => $l)
          <tr>
            <td>
              <select name="fabric_articles[{{ $i }}][panna_value_id]" class="form-control fa-panna">
                <option value="">PANNA</option>
                @foreach(($pannaAttr?->values ?? collect()) as $pv)
                  <option value="{{ $pv->id }}" {{ (string) ($l['panna_value_id'] ?? '') === (string) $pv->id ? 'selected' : '' }}>{{ $pv->value }}</option>
                @endforeach
              </select>
            </td>
            <td>
              <select name="fabric_articles[{{ $i }}][article_id]" class="form-control fa-article">
                <option value="">Select article</option>
                @foreach($fgArticles as $a)
                  <option value="{{ $a->id }}" {{ (string) ($l['article_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->name }} ({{ $a->sku }})</option>
                @endforeach
              </select>
            </td>
            <td>
              <select name="fabric_articles[{{ $i }}][article_variation_id]" class="form-control fa-size">
                <option value="">All sizes</option>
                @foreach((optional($fgArticles->firstWhere('id', $l['article_id'] ?? null))->variations ?? collect()) as $v)
                  <option value="{{ $v->id }}" {{ (string) ($l['article_variation_id'] ?? '') === (string) $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
                @endforeach
              </select>
            </td>
            <td><input type="number" step="any" min="0" name="fabric_articles[{{ $i }}][consumption]" class="form-control fa-consumption" value="{{ $l['consumption'] ?? '' }}"></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-outline-secondary btn-sm fs-copy-line" title="Copy line (e.g. for another PANNA or size)"><i class="fas fa-copy"></i></button>
              <button type="button" class="btn btn-danger btn-sm fs-remove-line"><i class="fas fa-times"></i></button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <button type="button" class="btn btn-outline-success btn-sm" id="addFabricArticle"><i class="fas fa-plus"></i> Add article</button>
    @isset($product)
      <a href="{{ route('products.fabric-articles', $product->id) }}" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-file-excel"></i> Bulk / Excel import</a>
    @endisset

    @if(!empty($fabricStock) && count($fabricStock['rows']))
      <hr>
      <label class="fw-bold">Where this fabric is now (PANNA-wise)</label>
      <div class="table-responsive">
        <table class="table table-bordered table-sm mb-1">
          <thead class="table-light"><tr><th>PANNA</th>
            @foreach($fabricStock['locations'] as $loc)<th class="text-end">{{ $loc }}</th>@endforeach
            <th class="text-end">Total</th></tr></thead>
          <tbody>
            @foreach($fabricStock['rows'] as $r)
              <tr><td>{{ $r['panna'] }}</td>
                @foreach($fabricStock['locations'] as $locId => $loc)
                  @php $q = $r['qty'][$locId] ?? 0; @endphp
                  <td class="text-end {{ $q < 0 ? 'text-danger fw-bold' : '' }}">{{ $q ? number_format($q, 2) : '—' }}</td>
                @endforeach
                <td class="text-end fw-bold">{{ number_format(array_sum($r['qty']), 2) }}</td></tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <small class="text-muted">At a CMT = given − (pieces received × consumption). Details per vendor:
        <a href="{{ route('reports.purchase', ['tab' => 'CMT']) }}" target="_blank">Purchase reports → CMT Fabric Summary</a>.</small>
    @endif
  </div>
</div>

<template id="fabricArticleTemplate">
  @php $i = '__i__'; $l = ['panna_value_id' => '', 'article_id' => '', 'article_variation_id' => '', 'consumption' => '']; @endphp
  <tr>
    <td>
      <select name="fabric_articles[{{ $i }}][panna_value_id]" class="form-control fa-panna">
        <option value="">PANNA</option>
        @foreach(($pannaAttr?->values ?? collect()) as $pv)
          <option value="{{ $pv->id }}" {{ (string) ($l['panna_value_id'] ?? '') === (string) $pv->id ? 'selected' : '' }}>{{ $pv->value }}</option>
        @endforeach
      </select>
    </td>
    <td>
      <select name="fabric_articles[{{ $i }}][article_id]" class="form-control fa-article">
        <option value="">Select article</option>
        @foreach($fgArticles as $a)
          <option value="{{ $a->id }}" {{ (string) ($l['article_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->name }} ({{ $a->sku }})</option>
        @endforeach
      </select>
    </td>
    <td>
      <select name="fabric_articles[{{ $i }}][article_variation_id]" class="form-control fa-size">
        <option value="">All sizes</option>
        @foreach((optional($fgArticles->firstWhere('id', $l['article_id'] ?? null))->variations ?? collect()) as $v)
          <option value="{{ $v->id }}" {{ (string) ($l['article_variation_id'] ?? '') === (string) $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
        @endforeach
      </select>
    </td>
    <td><input type="number" step="any" min="0" name="fabric_articles[{{ $i }}][consumption]" class="form-control fa-consumption" value="{{ $l['consumption'] ?? '' }}"></td>
    <td class="text-nowrap">
      <button type="button" class="btn btn-outline-secondary btn-sm fs-copy-line" title="Copy line (e.g. for another PANNA or size)"><i class="fas fa-copy"></i></button>
      <button type="button" class="btn btn-danger btn-sm fs-remove-line"><i class="fas fa-times"></i></button>
    </td>
  </tr>
</template>

<script>
  (function () {
    const SIZES = @json($fgArticles->mapWithKeys(fn($a) => [$a->id => $a->variations->map(fn($v) => ['id' => $v->id, 'sku' => $v->sku])->values()]));
    let idx = {{ count($articleLines) }};

    function isRaw() { return $('input[name="item_type"]:checked').val() === 'raw'; }

    function toggle() {
      const raw = isRaw();
      $('#fabricSection').toggle(raw);
      $('#fabricSection').find('input, select').prop('disabled', !raw);
      // the generic SIZE/AGE variation generator is for finished goods only (create form)
      const fg = $('input[name="item_type"]:checked').val() === 'fg';
      $('#genericVariations').toggle(fg).find('input, select, button').prop('disabled', !fg);
      if (raw) limitPannas();
    }

    // PANNA dropdowns on article lines offer only the PANNA selected above
    function limitPannas() {
      const chosen = ($('#fabricPannas').val() || []).map(String);
      $('#fabricArticleRows .fa-panna').each(function () {
        const cur = $(this).val();
        $(this).find('option').each(function () {
          if (!this.value) return;
          $(this).prop('disabled', chosen.length > 0 && !chosen.includes(this.value) && this.value !== cur);
        });
      });
    }

    function fillSizes($row) {
      const id = $row.find('.fa-article').val();
      let opts = '<option value="">All sizes</option>';
      (SIZES[id] || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $row.find('.fa-size').html(opts).trigger('change.select2');
    }

    $(function () {
      $('#fabricArticleRows select').select2({ width: '100%' });
      $(document).on('product-type-changed', toggle);
      $('#fabricPannas').on('change', limitPannas);
      $(document).on('change', '.fa-article', function () { fillSizes($(this).closest('tr')); });
      $(document).on('click', '.fs-remove-line', function () { $(this).closest('tr').remove(); });
      $(document).on('click', '.fs-copy-line', function () {
        const $src = $(this).closest('tr');
        const $row = $($('#fabricArticleTemplate').html().replaceAll('__i__', idx++));
        $src.after($row);
        $row.find('select').select2({ width: '100%' });
        $row.find('.fa-article').val($src.find('.fa-article').val()).trigger('change.select2');
        fillSizes($row);
        $row.find('.fa-consumption').val($src.find('.fa-consumption').val());
        limitPannas();
      });
      $('#addFabricArticle').on('click', function () {
        const $row = $($('#fabricArticleTemplate').html().replaceAll('__i__', idx++));
        $('#fabricArticleRows').append($row);
        $row.find('select').select2({ width: '100%' });
        limitPannas();
      });
      toggle();
    });
  })();
</script>
{{-- ═════ end fabric section ═════ --}}
        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('products.index') }}" class="btn btn-danger">Cancel</a>
          <button type="submit" class="btn btn-primary">Update Product</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
$(document).ready(function () {
  $('.select2-js').select2();

  // Searchable dropdowns: when one opens, put the cursor in ITS search box.
  // (The shared select2.js used to focus the first search box on the page,
  //  which made the page jump to another dropdown.)
  $(document).off('select2:open', '.select2-js').on('select2:open', '.select2-js', function () {
    const s2 = $(this).data('select2');
    setTimeout(function () {
      if (!s2) return;
      const box = s2.$dropdown.find('.select2-search__field')[0] || s2.$container.find('.select2-search__field')[0];
      if (box) box.focus();
    }, 100);
  });

  $(document).on('change', '.variation-attributes', function () {
    const block = $(this).closest('.variation-block');
    const attrTexts = [];
    $(this).find('option:selected').each(function () {
      attrTexts.push($(this).text().split('-')[1]?.trim());
    });
    block.find('.sku-field').val($('#sku').val() + '-' + attrTexts.join('-'));
  });

  let newVariationIndex = 0;
  $('#addNewVariationBtn').click(function () {
    newVariationIndex++;
    const html = `
      <div class="variation-block border p-2 mb-3">
        <div class="row">
          <div class="col-md-3">
            <label>SKU</label>
            <input type="text" name="new_variations[${newVariationIndex}][sku]" class="form-control sku-field">
          </div>
          <div class="col-md-2">
            <label>Barcode</label>
            <input type="text" name="new_variations[${newVariationIndex}][barcode]" class="form-control" placeholder="Blank = same as SKU">
          </div>
          <div class="col-md-2">
            <label>Stock</label>
            <input type="number" step="any" name="new_variations[${newVariationIndex}][stock_quantity]" value="0" class="form-control">
          </div>
          <div class="col-md-4">
            <label>Attributes</label>
            <select name="new_variations[${newVariationIndex}][attributes][]" multiple class="form-control select2-js variation-attributes">
              @foreach($attributes as $attribute)
                @foreach($attribute->values as $value)
                  <option value="{{ $value->id }}">{{ $attribute->name }}-{{ $value->value }}</option>
                @endforeach
              @endforeach
            </select>
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <button type="button" class="btn btn-sm btn-danger remove-new-variation">X</button>
          </div>
        </div>
      </div>
    `;
    $('#new-variation-section').append(html);
    $('#new-variation-section .variation-block:last .select2-js').select2();
  });

  $(document).on('click', '.remove-new-variation', function () {
    $(this).closest('.variation-block').remove();
  });

  $(document).on('click', '.remove-existing-variation', function () {
    const block = $(this).closest('.variation-block');
    const variationId = $(this).data('id');
    if (confirm('Are you sure you want to remove this variation?')) {
      block.find('input, select, textarea').prop('disabled', true);
      block.hide();
      block.append(`<input type="hidden" name="removed_variations[]" value="${variationId}" class="removed-variation-flag">`);
      block.after(`<div class="undo-variation-alert alert alert-warning mb-3" data-id="${variationId}">
        Variation removed. <button type="button" class="btn btn-sm btn-link p-0 undo-remove-variation">Undo</button>
      </div>`);
    }
  });

  $(document).on('click', '.undo-remove-variation', function () {
    const alertBox = $(this).closest('.undo-variation-alert');
    const variationId = alertBox.data('id');
    const block = $('.variation-block').has(`input[value="${variationId}"].removed-variation-flag`);
    block.find('.removed-variation-flag').remove();
    block.find('input, select, textarea').prop('disabled', false);
    block.show();
    alertBox.remove();
  });

  document.getElementById("imageUpload").addEventListener("change", function(event) {
    const previewContainer = document.getElementById("previewContainer");
    previewContainer.innerHTML = "";
    Array.from(event.target.files).forEach((file) => {
      if (!file.type.startsWith("image/")) return;
      const reader = new FileReader();
      reader.onload = function(e) {
        const wrapper = document.createElement("div");
        wrapper.classList.add("position-relative", "me-2", "mb-2");
        const img = document.createElement("img");
        img.src = e.target.result;
        img.classList.add("img-thumbnail");
        img.style.cssText = "width:120px;height:120px;object-fit:cover;";
        const removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.classList.add("btn", "btn-sm", "btn-danger", "position-absolute", "top-0", "end-0");
        removeBtn.innerHTML = "&times;";
        removeBtn.onclick = () => wrapper.remove();
        wrapper.appendChild(img);
        wrapper.appendChild(removeBtn);
        previewContainer.appendChild(wrapper);
      };
      reader.readAsDataURL(file);
    });
  });

  document.getElementById("existingImages").addEventListener("click", function(e) {
    if (!e.target.classList.contains("remove-existing-image")) return;
    const btn = e.target;
    const wrapper = btn.closest(".existing-image-wrapper");
    wrapper.style.display = "none";
    const hiddenKeep = wrapper.querySelector('input[name="keep_images[]"]');
    if (hiddenKeep) hiddenKeep.remove();
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "removed_images[]";
    input.value = btn.dataset.id;
    document.getElementById("productForm").appendChild(input);
  });

  $('select[name="category_id"]').on('change', function () {
    let categoryId = $(this).val();
    let subCategorySelect = $('select[name="subcategory_id"]');
    subCategorySelect.empty().append('<option value="">Loading...</option>');
    if (categoryId) {
      $.ajax({
        url: "{{ route('products.getSubcategories', ':id') }}".replace(':id', categoryId),
        type: "GET",
        success: function (data) {
          subCategorySelect.empty().append('<option value="">-- None --</option>');
          $.each(data, function (key, subcat) {
            subCategorySelect.append(`<option value="${subcat.id}">${subcat.name}</option>`);
          });
        }
      });
    } else {
      subCategorySelect.empty().append('<option value="">-- None --</option>');
    }
  });
});
</script>
@endsection