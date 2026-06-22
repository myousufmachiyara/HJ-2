@extends('layouts.app')
@section('title', 'Production Return | Edit')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('production_return.update', $return->id) }}" method="POST">
      @csrf
      @method('PUT')

      @if($errors->any())
        <div class="alert alert-danger">
          <ul class="mb-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
          </ul>
        </div>
      @endif

      @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Edit Production Return #{{ $return->id }}</h2>
        </header>

        <div class="card-body">

          {{-- Header --}}
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Vendor (Production Unit) <span class="text-danger">*</span></label>
              <select name="vendor_id" data-plugin-selecttwo class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach($vendors as $vendor)
                  <option value="{{ $vendor->id }}"
                    {{ $return->vendor_id == $vendor->id ? 'selected' : '' }}>
                    {{ $vendor->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Return Date <span class="text-danger">*</span></label>
              <input type="date" name="return_date" class="form-control"
                     value="{{ \Carbon\Carbon::parse($return->return_date)->toDateString() }}" required>
            </div>
            <div class="col-md-5">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="1">{{ $return->remarks }}</textarea>
            </div>
          </div>

          {{-- Items --}}
          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="returnTable">
              <thead class="table-light">
                <tr>
                  <th width="10%">Barcode</th>
                  <th>Item</th>
                  <th>Variation</th>
                  <th>Production #</th>
                  <th width="8%">Qty</th>
                  <th width="10%">Unit</th>
                  <th width="8%">Rate</th>
                  <th width="9%">Amount</th>
                  <th width="5%"></th>
                </tr>
              </thead>
              <tbody id="ReturnTableBody">
                @foreach($return->items as $i => $item)
                  <tr>
                    <td>
                      <input type="text" name="items[{{ $i }}][barcode]"
                             class="form-control product-code"
                             value="{{ $item->product->barcode ?? '' }}"
                             placeholder="Scan">
                    </td>
                    <td>
                      <select name="items[{{ $i }}][item_id]"
                              data-plugin-selecttwo class="form-control select2-js product-select"
                              onchange="onReturnItemChange(this)" required>
                        <option value="">Select Item</option>
                        @foreach($products as $product)
                          <option value="{{ $product->id }}"
                                  data-barcode="{{ $product->barcode }}"
                                  data-unit="{{ $product->measurement_unit }}"
                                  {{ $item->product_id == $product->id ? 'selected' : '' }}>
                            {{ $product->name }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $i }}][variation_id]"
                              data-plugin-selecttwo class="form-control select2-js variation-select">
                        <option value="">No Variation</option>
                        @foreach($item->product->variations ?? [] as $var)
                          <option value="{{ $var->id }}"
                                  {{ $item->variation_id == $var->id ? 'selected' : '' }}>
                            {{ $var->sku }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $i }}][production_id]"
                              data-plugin-selecttwo class="form-control select2-js production-select">
                        <option value="">Select Production</option>
                        @if($item->production_id)
                          <option value="{{ $item->production_id }}" selected>
                            #{{ $item->production_id }}
                          </option>
                        @endif
                      </select>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $i }}][quantity]"
                             class="form-control quantity" step="any"
                             value="{{ $item->quantity }}"
                             onchange="rowTotal(this)" required>
                    </td>
                    <td>
                      <select name="items[{{ $i }}][unit]"
                              data-plugin-selecttwo class="form-control select2-js unit-select" required>
                        <option value="">Unit</option>
                        @foreach($units as $unit)
                          <option value="{{ $unit->id }}"
                                  {{ $item->unit_id == $unit->id ? 'selected' : '' }}>
                            {{ $unit->shortcode }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $i }}][price]"
                             class="form-control price" step="any"
                             value="{{ $item->price }}"
                             onchange="rowTotal(this)">
                    </td>
                    <td>
                      <input type="number" name="items[{{ $i }}][amount]"
                             class="form-control amount" step="any"
                             value="{{ $item->quantity * $item->price }}" readonly>
                    </td>
                    <td>
                      <button type="button" class="btn btn-danger btn-sm"
                              onclick="removeRow(this)">
                        <i class="fas fa-times"></i>
                      </button>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary btn-sm"
                    onclick="addReturnRow()">
              <i class="fas fa-plus me-1"></i> Add Item
            </button>
          </div>

          {{-- Totals --}}
          <div class="row">
            <div class="col-md-4 offset-md-8 text-end">
              <h5 class="text-primary">
                Total: <strong class="text-danger" id="totalDisplay">
                  {{ number_format($return->items->sum(fn($i) => $i->quantity * $i->price), 2) }}
                </strong>
              </h5>
              <input type="hidden" name="total_amount" id="total_amount_hidden"
                     value="{{ $return->items->sum(fn($i) => $i->quantity * $i->price) }}">
            </div>
          </div>

        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('production_return.index') }}" class="btn btn-danger">Cancel</a>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save me-1"></i> Update Return
          </button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var units    = @json($units);
  var retIdx   = {{ $return->items->count() }};

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // Reload productions for existing rows
    $('#ReturnTableBody tr').each(function () {
      const row       = $(this);
      const productId = row.find('.product-select').val();
      if (productId) loadProductions(row, productId);
    });

    updateTotal();

    // Variation change → reload productions
    $(document).on('change', '.variation-select', function () {
      const row       = $(this).closest('tr');
      const productId = row.find('.product-select').val();
      if (productId) loadProductions(row, productId);
    });

    // Production change → fill rate
    $(document).on('change', '.production-select', function () {
      const rate = $(this).find(':selected').data('rate') || 0;
      const row  = $(this).closest('tr');
      row.find('.price').val(rate);
      rowTotal(row.find('.price')[0]);
    });
  });

  function addReturnRow() {
    const productOpts = products.map(p =>
      `<option value="${p.id}" data-barcode="${p.barcode ?? ''}"
               data-unit="${p.measurement_unit ?? ''}">${p.name}</option>`
    ).join('');

    const unitOpts = units.map(u =>
      `<option value="${u.id}">${u.shortcode}</option>`
    ).join('');

    const row = `
      <tr>
        <td><input type="text" name="items[${retIdx}][barcode]"
                   class="form-control product-code" placeholder="Scan"></td>
        <td>
          <select name="items[${retIdx}][item_id]"
                  data-plugin-selecttwo class="form-control product-select select2-js"
                  onchange="onReturnItemChange(this)" required>
            <option value="">Select Item</option>
            ${productOpts}
          </select>
        </td>
        <td>
          <select name="items[${retIdx}][variation_id]"
                  data-plugin-selecttwo  class="form-control select2-js variation-select">
            <option value="">No Variation</option>
          </select>
        </td>
        <td>
          <select name="items[${retIdx}][production_id]"
                  data-plugin-selecttwo class="form-control select2-js production-select">
            <option value="">Select Production</option>
          </select>
        </td>
        <td><input type="number" name="items[${retIdx}][quantity]"
                   class="form-control quantity" step="any" value="0"
                   onchange="rowTotal(this)" required></td>
        <td>
          <select name="items[${retIdx}][unit]" data-plugin-selecttwo class="form-control select2-js unit-select" required>
            <option value="">Unit</option>
            ${unitOpts}
          </select>
        </td>
        <td><input type="number" name="items[${retIdx}][price]"
                   class="form-control price" step="any" value="0"
                   onchange="rowTotal(this)"></td>
        <td><input type="number" name="items[${retIdx}][amount]"
                   class="form-control amount" step="any" value="0" readonly></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
            <i class="fas fa-times"></i>
          </button>
        </td>
      </tr>`;

    $('#ReturnTableBody').append(row);
    $('#ReturnTableBody tr:last .select2-js').select2({ width: '100%', dropdownAutoWidth: true });
    retIdx++;
  }

  function onReturnItemChange(select) {
    const row    = $(select).closest('tr');
    const prodId = $(select).val();
    const barcode = $(select).find(':selected').data('barcode');
    const unitId  = $(select).find(':selected').data('unit');

    row.find('.product-code').val(barcode);
    if (unitId) row.find('.unit-select').val(unitId);

    loadVariations(row, prodId, function (hasVariations) {
      if (!hasVariations) loadProductions(row, prodId);
    });
  }

  function loadVariations(row, productId, callback) {
    const $var = row.find('.variation-select');
    $var.html('<option value="">Loading...</option>').prop('disabled', true);

    $.get(`/product/${productId}/variations`, function (data) {
      const variations = data.variation || [];
      if (variations.length) {
        let opts = '<option value="">Select Variation</option>';
        variations.forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
        $var.html(opts).prop('disabled', false);
        if (typeof callback === 'function') callback(true);
      } else {
        $var.html('<option value="">No Variations</option>').prop('disabled', true);
        if (typeof callback === 'function') callback(false);
      }
      if ($var.hasClass('select2-hidden-accessible')) $var.select2('destroy');
      $var.select2({ width: '100%', dropdownAutoWidth: true });
    }).fail(() => {
      $var.html('<option value="">Error</option>').prop('disabled', true);
      if (typeof callback === 'function') callback(false);
    });
  }

  function loadProductions(row, productId) {
    const $prod = row.find('.production-select');
    const varId = row.find('.variation-select').val() || null;
    $prod.html('<option value="">Loading...</option>');

    $.get(`/product/${productId}/productions`, { variation_id: varId }, function (data) {
      let opts     = '<option value="">Select Production</option>';
      const prods  = Array.isArray(data) ? data : (data.productions || []);
      prods.forEach(p => { opts += `<option value="${p.id}" data-rate="${p.rate}">#${p.id}</option>`; });
      $prod.html(opts);
      if ($prod.hasClass('select2-hidden-accessible')) $prod.select2('destroy');
      $prod.select2({ width: '100%', dropdownAutoWidth: true });
    }).fail(() => {
      $prod.html('<option value="">Failed to load</option>');
    });
  }

  function rowTotal(el) {
    const row   = $(el).closest('tr');
    const qty   = parseFloat(row.find('.quantity').val()) || 0;
    const price = parseFloat(row.find('.price').val())    || 0;
    row.find('.amount').val((qty * price).toFixed(2));
    updateTotal();
  }

  function updateTotal() {
    let total = 0;
    $('#ReturnTableBody tr').each(function () {
      total += parseFloat($(this).find('.amount').val()) || 0;
    });
    $('#totalDisplay').text(total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    $('#total_amount_hidden').val(total.toFixed(2));
  }

  function removeRow(button) {
    if ($('#ReturnTableBody tr').length > 1) {
      $(button).closest('tr').remove();
      updateTotal();
    }
  }
</script>
@endsection