@extends('layouts.app')
@section('title', 'Production | Edit Wastage Return')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('production_wastage.update', $wastage->id) }}" method="POST">
      @csrf @method('PUT')

      @if($errors->any())
        <div class="alert alert-danger">
          <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
      @endif

      {{-- Header --}}
      <section class="card mb-3">
        <header class="card-header">
          <h2 class="card-title">Edit Wastage Return — {{ $wastage->grn_no }}</h2>
        </header>
        <div class="card-body">
          <div class="row">
            <div class="col-md-2">
              <label>WRN #</label>
              <input type="text" class="form-control" value="{{ $wastage->grn_no }}" readonly>
            </div>
            <div class="col-md-2">
              <label>Return Date <span class="text-danger">*</span></label>
              <input type="date" name="rec_date" class="form-control"
                     value="{{ $wastage->rec_date }}" required>
            </div>
            <div class="col-md-3">
              <label>Production Order</label>
              <select name="production_id" data-plugin-selecttwo class="form-control select2-js">
                <option value="">-- No Production Order --</option>
                @foreach($productions as $prod)
                  <option value="{{ $prod->id }}"
                    {{ $wastage->production_id == $prod->id ? 'selected' : '' }}>
                    PO-{{ $prod->id }} — {{ $prod->vendor->name ?? '' }}
                    ({{ \Carbon\Carbon::parse($prod->order_date)->format('d-M-Y') }})
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>Vendor <span class="text-danger">*</span></label>
              <select name="vendor_id" data-plugin-selecttwo class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach($vendors as $v)
                  <option value="{{ $v->id }}" {{ $wastage->vendor_id == $v->id ? 'selected' : '' }}>
                    {{ $v->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control"
                     value="{{ $wastage->remarks }}" placeholder="Optional">
            </div>
          </div>
        </div>
      </section>

      {{-- Items --}}
      <section class="card mb-3">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Raw Material Items Returned</h2>
          <button type="button" class="btn btn-success btn-sm" id="addRowBtn">
            <i class="fas fa-plus me-1"></i> Add Row
          </button>
        </header>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-sm">
              <thead class="table-light">
                <tr>
                  <th>Raw Material</th>
                  <th>Variation</th>
                  <th width="12%">Return Type</th>   {{-- ← add --}}
                  <th width="12%">Unit</th>
                  <th width="12%">Quantity</th>
                  <th>Remarks</th>
                  <th width="5%"></th>
                </tr>
              </thead>
              <tbody id="wastageBody">
                @foreach($wastage->details as $i => $detail)
                  <tr>
                    <td>
                      <select name="items[{{ $i }}][product_id]"
                              data-plugin-selecttwo class="form-control select2-js product-select" required>
                        <option value="">Select Raw Material</option>
                        @foreach($products as $p)
                          <option value="{{ $p->id }}" data-unit="{{ $p->measurement_unit }}"
                            {{ $detail->product_id == $p->id ? 'selected' : '' }}>
                            {{ $p->name }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $i }}][variation_id]"
                              data-plugin-selecttwo class="form-control select2-js variation-select">
                        <option value="">No Variation</option>
                        @if($detail->product)
                          @foreach($detail->product->variations as $v)
                            <option value="{{ $v->id }}" {{ $detail->variation_id == $v->id ? 'selected' : '' }}>
                              {{ $v->sku }}
                            </option>
                          @endforeach
                        @endif
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $i }}][return_type]"
                              data-plugin-selecttwo class="form-control select2-js return-type-select" required>
                        <option value="extra"   {{ ($detail->return_type ?? 'extra') == 'extra'   ? 'selected' : '' }}>
                          Extra (Back to Stock)
                        </option>
                        <option value="wastage" {{ ($detail->return_type ?? 'extra') == 'wastage' ? 'selected' : '' }}>
                          Wastage (Write-off)
                        </option>
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $i }}][unit_id]"
                              data-plugin-selecttwo class="form-control select2-js unit-select" required>
                        <option value="">Unit</option>
                        @foreach($units as $unit)
                          <option value="{{ $unit->id }}"
                            {{ $detail->unit_id == $unit->id ? 'selected' : '' }}>
                            {{ $unit->shortcode }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $i }}][quantity]"
                             class="form-control qty-input" step="any"
                             value="{{ $detail->quantity }}" required>
                    </td>
                    <td>
                      <input type="text" name="items[{{ $i }}][remarks]"
                             class="form-control" value="{{ $detail->remarks }}">
                    </td>
                    <td>
                      <button type="button" class="btn btn-danger btn-sm remove-row">
                        <i class="fas fa-times"></i>
                      </button>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          <div class="row mt-3">
            <div class="col-md-2">
              <label>Total Qty Returned</label>
              <input type="text" class="form-control" id="totalQty"
                     value="{{ number_format($wastage->details->sum('quantity'), 3) }}" disabled>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('production_wastage.index') }}" class="btn btn-danger">Discard</a>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Update Wastage Return
          </button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var rowCount = {{ $wastage->details->count() }};

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
    $(document).on('change', '.product-select', function () {
      const row    = $(this).closest('tr');
      const unitId = $(this).find(':selected').data('unit');
      const prodId = $(this).val();
      if (unitId) row.find('.unit-select').val(unitId).trigger('change');
      if (prodId) loadVariations(row, prodId);
    });
    $(document).on('input', '.qty-input', recalcTotal);
    $(document).on('click', '.remove-row', function () {
      if ($('#wastageBody tr').length > 1) {
        $(this).closest('tr').remove();
        recalcTotal();
      }
    });
    $('#addRowBtn').on('click', addRow);
  });

  function addRow() {
    const productOpts = `
      <option value="">Select Raw Material</option>
      @foreach($products as $p)
        <option value="{{ $p->id }}" data-unit="{{ $p->measurement_unit }}">{{ $p->name }}</option>
      @endforeach
    `;
    const unitOpts = `
      <option value="">Unit</option>
      @foreach($units as $unit)
        <option value="{{ $unit->id }}">{{ $unit->shortcode }}</option>
      @endforeach
    `;
    const $row = $(`
      <tr>
        <td><select name="items[${rowCount}][product_id]" data-plugin-selecttwo class="form-control select2-js product-select" required>${productOpts}</select></td>
        <td><select name="items[${rowCount}][variation_id]" data-plugin-selecttwo class="form-control select2-js variation-select"><option value="">No Variation</option></select></td>
        <td>
          <select name="items[${rowCount}][return_type]" data-plugin-selecttwo class="form-control select2-js return-type-select" required>
            <option value="extra">Extra (Back to Stock)</option>
            <option value="wastage">Wastage (Write-off)</option>
          </select>
        </td>
        <td><select name="items[${rowCount}][unit_id]" data-plugin-selecttwo class="form-control select2-js unit-select" required>${unitOpts}</select></td>
        <td><input type="number" name="items[${rowCount}][quantity]" class="form-control qty-input" step="any" value="0" required></td>
        <td><input type="text" name="items[${rowCount}][remarks]" class="form-control" placeholder="Optional"></td>
        <td><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button></td>
      </tr>
    `);
    $('#wastageBody').append($row);
    $row.find('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
    rowCount++;
  }

  function loadVariations(row, productId) {
    const $var = row.find('.variation-select');
    $var.html('<option value="">Loading...</option>');
    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $var.html(opts);
      if ($var.hasClass('select2-hidden-accessible')) $var.select2('destroy');
      $var.select2({ width: '100%', dropdownAutoWidth: true });
    });
  }

  function recalcTotal() {
    let total = 0;
    $('.qty-input').each(function () { total += parseFloat($(this).val()) || 0; });
    $('#totalQty').val(total.toFixed(3));
  }
</script>
@endsection