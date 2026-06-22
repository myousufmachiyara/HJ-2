@extends('layouts.app')
@section('title', 'Purchase Return | Edit')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('purchase_return.update', $return->id) }}" method="POST"
          enctype="multipart/form-data">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Edit Purchase Return #{{ $return->return_no }}</h2>
        </header>

        <div class="card-body">

          @if($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
              </ul>
            </div>
          @endif

          {{-- Header --}}
          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Return Date <span class="text-danger">*</span></label>
              <input type="date" name="return_date" class="form-control"
                     value="{{ $return->return_date }}" required>
            </div>
            <div class="col-md-2 mb-3">
              <label>Vendor <span class="text-danger">*</span></label>
              <select name="vendor_id" class="form-control select2-js" id="vendor_select" required>
                <option value="">Select Vendor</option>
                @foreach($vendors as $vendor)
                  <option value="{{ $vendor->id }}"
                    {{ $return->vendor_id == $vendor->id ? 'selected' : '' }}>
                    {{ $vendor->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2 mb-3">
              <label>Bill #</label>
              <input type="text" name="bill_no" class="form-control" value="{{ $return->bill_no }}">
            </div>
            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control" value="{{ $return->ref_no }}">
            </div>
            <div class="col-md-3 mb-3">
              <label>New Attachments</label>
              <input type="file" name="attachments[]" class="form-control" multiple
                     accept=".pdf,.jpg,.jpeg,.png,.zip">
            </div>
            <div class="col-md-4 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2">{{ $return->remarks }}</textarea>
            </div>
          </div>

          {{-- Items Table --}}
          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="returnTable">
              <thead>
                <tr>
                  <th>Item Code</th>
                  <th>Item Name</th>
                  <th>Variation</th>
                  <th>Purchase Invoice</th>
                  <th>Quantity</th>
                  <th>Unit</th>
                  <th>Price</th>
                  <th>Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="ReturnTableBody">
                @foreach($return->items as $key => $item)
                  <tr>
                    <td>
                      <input type="text" name="items[{{ $key }}][item_code]"
                             id="item_cod{{ $key+1 }}"
                             class="form-control product-code"
                             value="{{ $item->product->barcode ?? '' }}">
                    </td>
                    <td>
                      <select name="items[{{ $key }}][item_id]" id="item_name{{ $key+1 }}"
                              class="form-control select2-js product-select"
                              onchange="onItemChange(this)">
                        <option value="">Select Item</option>
                        @foreach($products as $product)
                          <option value="{{ $product->id }}"
                                  data-barcode="{{ $product->barcode }}"
                                  data-unit-id="{{ $product->measurement_unit }}"
                                  {{ $item->item_id == $product->id ? 'selected' : '' }}>
                            {{ $product->name }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $key }}][variation_id]"
                              class="form-control select2-js variation-select">
                        <option value="">Select Variation</option>
                        @foreach($item->product->variations ?? [] as $var)
                          <option value="{{ $var->id }}"
                            {{ $item->variation_id == $var->id ? 'selected' : '' }}>
                            {{ $var->sku }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $key }}][invoice_id]" id="invoice{{ $key+1 }}"
                              class="form-control select2-js invoice-select">
                        <option value="">Select Invoice</option>
                        @if($item->purchaseInvoice)
                          <option value="{{ $item->purchaseInvoice->id }}" selected>
                            #{{ $item->purchaseInvoice->id }}
                          </option>
                        @endif
                      </select>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $key }}][quantity]"
                             id="pur_qty{{ $key+1 }}" class="form-control quantity"
                             value="{{ $item->quantity }}" step="any"
                             onchange="rowTotal({{ $key+1 }})">
                    </td>
                    <td>
                      <select name="items[{{ $key }}][unit]" id="unit{{ $key+1 }}"
                              class="form-control" required>
                        <option value="">-- Select --</option>
                        @foreach($units as $unit)
                          <option value="{{ $unit->id }}"
                            {{ $item->unit == $unit->id ? 'selected' : '' }}>
                            {{ $unit->name }} ({{ $unit->shortcode }})
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $key }}][price]"
                             id="pur_price{{ $key+1 }}" class="form-control"
                             value="{{ $item->price }}" step="any"
                             onchange="rowTotal({{ $key+1 }})">
                    </td>
                    <td>
                      <input type="number" id="amount{{ $key+1 }}" class="form-control"
                             value="{{ $item->quantity * $item->price }}" disabled>
                    </td>
                    <td>
                      <button type="button" class="btn btn-danger btn-sm"
                              onclick="removeRow(this)">
                        <i class="fas fa-times"></i>
                      </button>
                      <input type="hidden" name="items[{{ $key }}][barcode]"
                             id="barcode{{ $key+1 }}"
                             value="{{ $item->product->barcode ?? '' }}">
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary btn-sm"
                    onclick="addNewRow()">
              <i class="fas fa-plus"></i> Add Item
            </button>
          </div>

          {{-- Totals --}}
          <div class="row mb-3">
            <div class="col-md-2">
              <label>Total Amount</label>
              <input type="text" id="totalAmount" class="form-control"
                     value="{{ $return->items->sum(fn($i) => $i->quantity * $i->price) }}" disabled>
              <input type="hidden" name="total_amount" id="total_amount_show">
            </div>
            <div class="col-md-2">
              <label>Total Quantity</label>
              <input type="text" id="total_quantity" class="form-control"
                     value="{{ $return->items->sum('quantity') }}" disabled>
            </div>
            <div class="col-md-2">
              <label>Conveyance Charges</label>
              <input type="number" name="convance_charges" id="convance_charges"
                     class="form-control" value="{{ $return->convance_charges }}"
                     onchange="netTotal()">
            </div>
            <div class="col-md-2">
              <label>Bill Discount</label>
              <input type="number" name="bill_discount" id="bill_discount"
                     class="form-control" value="{{ $return->bill_discount }}"
                     onchange="netTotal()">
            </div>
          </div>

          <div class="row">
            <div class="col text-end">
              <h4>Net Amount: <strong class="text-danger">
                PKR <span id="netTotal">
                  {{ number_format(
                    $return->items->sum(fn($i) => $i->quantity * $i->price)
                    + $return->convance_charges - $return->bill_discount,
                    2
                  ) }}
                </span>
              </strong></h4>
              <input type="hidden" name="net_amount" id="net_amount">
            </div>
          </div>

        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('purchase_return.index') }}" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Update Return
          </button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index    = {{ $return->items->count() + 1 }};

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // Pre-load variations for existing rows
    $('#ReturnTableBody tr').each(function () {
      const row       = $(this);
      const productId = row.find('.product-select').val();
      const varId     = row.find('.variation-select').val();
      if (productId) loadVariations(row, productId, varId);
    });

    tableTotal();

    $(document).on('change', '.product-select', function () {
      const row       = $(this).closest('tr');
      const productId = $(this).val();
      const vendorId  = $('#vendor_select').val();
      if (productId) {
        loadVariations(row, productId);
        if (vendorId) loadInvoices(row, productId, vendorId);
      }
    });

    $(document).on('blur', '.product-code', function () {
      const row     = $(this).closest('tr');
      const barcode = $(this).val().trim();
      if (!barcode) return;

      $.get('/get-product-by-code/' + encodeURIComponent(barcode), function (res) {
        if (!res?.success) { alert(res?.message || 'Not found'); return; }
        if (res.type === 'variation') {
          row.find('.product-select').val(res.variation.product_id).trigger('change.select2');
          row.find('.variation-select')
             .html(`<option value="${res.variation.id}" selected>${res.variation.sku}</option>`)
             .trigger('change');
          row.find('.quantity').focus();
        }
        if (res.type === 'product') {
          row.find('.product-select').val(res.product.id).trigger('change.select2');
          loadVariations(row, res.product.id);
        }
      });
    });

    $(document).on('keypress', '.quantity', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        if ($(this).val().trim()) {
          addNewRow();
          $('#ReturnTableBody tr').last().find('.product-code').focus();
        }
      }
    });
  });

  function onItemChange(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const unitId  = selectedOption.getAttribute('data-unit-id');
    const barcode = selectedOption.getAttribute('data-barcode');
    const i       = selectElement.id.replace('item_name', '');
    document.getElementById(`item_cod${i}`).value = barcode ?? '';
    document.getElementById(`barcode${i}`).value  = barcode ?? '';
    $(`#unit${i}`).val(String(unitId)).trigger('change.select2');
  }

  function removeRow(button) {
    if ($('#ReturnTableBody tr').length > 1) {
      $(button).closest('tr').remove();
      tableTotal();
    }
  }

  function addNewRow() {
    const rowIndex       = index - 1;
    const productOptions = products.map(p =>
      `<option value="${p.id}" data-barcode="${p.barcode ?? ''}"
               data-unit-id="${p.measurement_unit ?? ''}">${p.name}</option>`
    ).join('');

    const unitOptions = `
      @foreach($units as $unit)
        <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->shortcode }})</option>
      @endforeach
    `;

    const row = `
      <tr>
        <td><input type="text" name="items[${rowIndex}][item_code]" id="item_cod${index}"
                   class="form-control product-code"></td>
        <td>
          <select name="items[${rowIndex}][item_id]" id="item_name${index}"
                  class="form-control select2-js product-select" onchange="onItemChange(this)">
            <option value="">Select Item</option>${productOptions}
          </select>
        </td>
        <td>
          <select name="items[${rowIndex}][variation_id]"
                  class="form-control select2-js variation-select">
            <option value="">Select Variation</option>
          </select>
        </td>
        <td>
          <select name="items[${rowIndex}][invoice_id]" id="invoice${index}"
                  class="form-control select2-js invoice-select">
            <option value="">Select Invoice</option>
          </select>
        </td>
        <td><input type="number" name="items[${rowIndex}][quantity]" id="pur_qty${index}"
                   class="form-control quantity" value="0" step="any"
                   onchange="rowTotal(${index})"></td>
        <td>
          <select name="items[${rowIndex}][unit]" id="unit${index}"
                  class="form-control" required>
            <option value="">-- Select --</option>${unitOptions}
          </select>
        </td>
        <td><input type="number" name="items[${rowIndex}][price]" id="pur_price${index}"
                   class="form-control" value="0" step="any"
                   onchange="rowTotal(${index})"></td>
        <td><input type="number" id="amount${index}" class="form-control" value="0" disabled></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
            <i class="fas fa-times"></i>
          </button>
          <input type="hidden" name="items[${rowIndex}][barcode]" id="barcode${index}">
        </td>
      </tr>`;

    $('#ReturnTableBody').append(row);
    $(`#item_name${index}, #invoice${index}, #unit${index}`)
      .select2({ width: '100%', dropdownAutoWidth: true });
    index++;
  }

  function rowTotal(i) {
    const qty   = parseFloat($(`#pur_qty${i}`).val())   || 0;
    const price = parseFloat($(`#pur_price${i}`).val()) || 0;
    $(`#amount${i}`).val((qty * price).toFixed(2));
    tableTotal();
  }

  function tableTotal() {
    let total = 0, qty = 0;
    $('#ReturnTableBody tr').each(function () {
      total += parseFloat($(this).find('input[id^="amount"]').val())   || 0;
      qty   += parseFloat($(this).find('input[id^="pur_qty"]').val())  || 0;
    });
    $('#totalAmount').val(total.toFixed(2));
    $('#total_amount_show').val(total.toFixed(2));
    $('#total_quantity').val(qty.toFixed(2));
    netTotal();
  }

  function netTotal() {
    const total    = parseFloat($('#totalAmount').val())      || 0;
    const conv     = parseFloat($('#convance_charges').val()) || 0;
    const discount = parseFloat($('#bill_discount').val())    || 0;
    const net      = (total + conv - discount).toFixed(2);
    $('#netTotal').text(net.replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    $('#net_amount').val(net);
  }

  function loadVariations(row, productId, preselectId = null) {
    const $var = row.find('.variation-select');
    $var.html('<option>Loading...</option>').prop('disabled', true);
    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">Select Variation</option>';
      (data.variation || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $var.html(opts).prop('disabled', (data.variation || []).length === 0);
      if ($var.hasClass('select2-hidden-accessible')) $var.select2('destroy');
      $var.select2({ width: '100%', dropdownAutoWidth: true });
      if (preselectId) $var.val(String(preselectId)).trigger('change');
    });
  }

  function loadInvoices(row, productId, vendorId) {
    const $inv = row.find('.invoice-select');
    $inv.html('<option value="">Loading...</option>');
    $.get(`/product/${productId}/invoices`, function (data) {
      let opts = '<option value="">Select Invoice</option>';
      (Array.isArray(data) ? data : []).forEach(inv => {
        opts += `<option value="${inv.id}" data-rate="${inv.rate}">${inv.invoice_no}-${inv.vendor}</option>`;
      });
      $inv.html(opts);
      if ($inv.hasClass('select2-hidden-accessible')) $inv.select2('destroy');
      $inv.select2({ width: '100%', dropdownAutoWidth: true });
    });

    $inv.off('change').on('change', function () {
      const rate = $(this).find(':selected').data('rate') || 0;
      const i    = row.find('select[id^="item_name"]').attr('id').replace('item_name', '');
      $(`#pur_price${i}`).val(rate);
      rowTotal(i);
    });
  }
</script>
@endsection