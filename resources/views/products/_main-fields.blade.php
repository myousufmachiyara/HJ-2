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
