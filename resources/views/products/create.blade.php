@extends('layouts.app')

@section('title', 'Products | Create')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
      @csrf
      @if ($errors->any())
        <div class="alert alert-danger">
          <ul class="mb-0">
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">New Product</h2>
        </header>
        <div class="card-body">
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
      <input type="text" name="barcode" class="form-control" value="{{ $val('barcode') }}" placeholder="Optional">
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
              <input type="file" name="prod_att[]" multiple class="form-control" id="imageUpload">
              <div id="previewContainer" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;"></div>
            </div>
          </div>

          <div id="genericVariations">
          {{-- Attribute Selection --}}
          <div class="row mt-4">
            <div class="col-md-12">
              <h2 class="card-title">Product Variations</h2>
              <small class="text-muted d-block mb-2">Finished goods: generate size / age variations. (For a fabric choose Product Type = Raw / Fabric — the PANNA &amp; Articles section appears instead.)</small>
              <div class="row">
                @foreach($attributes->where('slug', '!=', 'panna') as $attribute)
                  <div class="col-md-6">
                    <label>{{ $attribute->name }}</label>
                    <select name="attributes[{{ $attribute->id }}][]" multiple class="form-control select2-js variation-select" data-attribute="{{ $attribute->id }}">
                      @foreach($attribute->values as $value)
                        <option value="{{ $value->id }}">{{ $value->value }}</option>
                      @endforeach
                    </select>
                  </div>
                @endforeach
              </div>
            </div>
          </div>

          <div class="col-md-12 mt-4">
            <button type="button" class="btn btn-success mb-3" id="generateVariationsBtn">
              <i class="fa fa-plus"></i> Generate Variations
            </button>
            <div class="table-responsive">
              <table class="table table-bordered" id="variationsTable">
                <thead>
                  <tr>
                    <th>Variation</th>
                    <th>Stock</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
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
          <button type="submit" class="btn btn-primary">Create Product</button>
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

  $('#generateVariationsBtn').click(function () {
    let attributes = {!! $attributes->toJson() !!};
    let selectedMap = {};

    attributes.forEach(attr => {
      let selected = $(`select[name="attributes[${attr.id}][]"]`).val();
      if (selected && selected.length > 0) {
        selectedMap[attr.name] = selected.map(valId => {
          let text = $(`select[name="attributes[${attr.id}][]"] option[value="${valId}"]`).text();
          return { id: valId, text: text };
        });
      }
    });

    let combos = buildCombinations(Object.entries(selectedMap));
    let tbody = $('#variationsTable tbody');
    tbody.empty();
    let mainSku = $('#sku').val() || $('#sku').attr('data-preview') || '';

    combos.forEach((combo, index) => {
      let label = combo.map(c => c.text).join('-');
      let inputs = combo.map((c, i) => `
        <input type="hidden" name="variations[${index}][attributes][${i}][attribute_value_id]" value="${c.id}">
      `).join('');

      tbody.append(`
        <tr>
          <td>${label}${inputs}</td>
          <td><input type="number" name="variations[${index}][stock_quantity]" step="any" class="form-control" value="0"></td>
          <td><input type="text" name="variations[${index}][sku]" class="form-control" value="${mainSku}-${label}"></td>
          <td><input type="text" name="variations[${index}][barcode]" class="form-control" placeholder="Manual barcode"></td>
          <td><button type="button" class="btn btn-sm btn-danger remove-variation">X</button></td>
        </tr>
      `);
    });
  });

  $(document).on('click', '.remove-variation', function () {
    $(this).closest('tr').remove();
  });

  function buildCombinations(arr, index = 0) {
    if (index === arr.length) return [[]];
    let [key, values] = arr[index];
    let rest = buildCombinations(arr, index + 1);
    return values.flatMap(v => rest.map(r => [v, ...r]));
  }

  $('select[name="category_id"]').on('change', function () {
    let categoryId = $(this).val();
    if (categoryId) {
      $.get("{{ route('products.next-sku', ':id') }}".replace(':id', categoryId), function (res) {
        $('#sku').attr('placeholder', 'Auto: ' + res.sku).attr('data-preview', res.sku);
      });
    }
    let subCategorySelect = $('#subcategory_id');
    subCategorySelect.empty().append('<option value="">Loading...</option>');
    if (categoryId) {
      $.ajax({
        url: "{{ route('products.getSubcategories', ':id') }}".replace(':id', categoryId),
        type: "GET",
        success: function (data) {
          subCategorySelect.empty().append('<option value="">Select Sub Category</option>');
          $.each(data, function (key, subcat) {
            subCategorySelect.append(`<option value="${subcat.id}">${subcat.name}</option>`);
          });
        }
      });
    } else {
      subCategorySelect.empty().append('<option value="">Select Sub Category</option>');
    }
  });

  document.getElementById("imageUpload").addEventListener("change", function(event) {
    const files = event.target.files;
    const previewContainer = document.getElementById("previewContainer");
    Array.from(files).forEach((file) => {
      if (file && file.type.startsWith("image/")) {
        const reader = new FileReader();
        reader.onload = function(e) {
          const wrapper = document.createElement("div");
          wrapper.style.position = "relative";
          wrapper.style.display = "inline-block";
          const img = document.createElement("img");
          img.src = e.target.result;
          img.style.maxWidth = "150px"; img.style.maxHeight = "150px";
          img.style.border = "1px solid #ddd"; img.style.borderRadius = "5px"; img.style.padding = "5px";
          const removeBtn = document.createElement("span");
          removeBtn.innerHTML = "&times;";
          removeBtn.style.cssText = "position:absolute;top:2px;right:6px;cursor:pointer;color:red;font-size:20px;font-weight:bold;";
          removeBtn.addEventListener("click", function() {
            wrapper.remove();
            if (previewContainer.children.length === 0) document.getElementById("imageUpload").value = "";
          });
          wrapper.appendChild(img);
          wrapper.appendChild(removeBtn);
          previewContainer.appendChild(wrapper);
        };
        reader.readAsDataURL(file);
      }
    });
  });
});
</script>
@endsection