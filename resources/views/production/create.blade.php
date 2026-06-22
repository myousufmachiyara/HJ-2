@extends('layouts.app')
@section('title', 'Production | New Order')

@section('content')
<div class="row">
  <form id="productionForm" action="{{ route('production.store') }}" method="POST" enctype="multipart/form-data">
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

    @if(session('error'))
      <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row">

      {{-- Header --}}
      <div class="col-12 mb-3">
        <section class="card">
          <header class="card-header">
            <h2 class="card-title">New Production Order</h2>
          </header>
          <div class="card-body">
            <div class="row">

              <div class="col-md-2 mb-3">
                <label>Category</label>
                <select data-plugin-selecttwo class="form-control select2-js" name="category_id">
                  <option value="">Select Category</option>
                  @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-2 mb-3">
                <label>Vendor <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="vendor_id" id="vendor_name" required>
                  <option value="" disabled selected>Select Vendor</option>
                  @foreach($vendors as $v)
                    <option value="{{ $v->id }}">{{ $v->name }}</option>
                  @endforeach
                </select>
              </div>

              <div class="col-md-2 mb-3">
                <label>Production Type <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="production_type" id="production_type" required>
                  <option value="" disabled selected>Select Type</option>
                  <option value="cmt">CMT</option>
                  <option value="sale_leather">Sale Raw</option>
                </select>
              </div>

              <div class="col-md-2 mb-3">
                <label>Order Date <span class="text-danger">*</span></label>
                <input type="date" name="order_date" class="form-control" id="order_date"
                       value="{{ date('Y-m-d') }}" required>
              </div>

              <div class="col-md-4 mb-3">
                <label>Remarks</label>
                <input type="text" name="remarks" class="form-control" placeholder="Optional remarks">
              </div>

            </div>
          </div>
        </section>
      </div>

      {{-- Raw Material Details --}}
      <div class="col-12 mb-3">
        <section class="card">
          <header class="card-header">
            <h2 class="card-title">Raw Material Details</h2>
          </header>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-bordered table-sm" id="myTable">
                <thead class="table-light">
                  <tr>
                    <th>Raw Material</th>
                    <th>Variation</th>
                    <th>Purchase Invoice</th>
                    <th>Description</th>
                    <th width="9%">Rate</th>
                    <th width="9%">Qty</th>
                    <th width="10%">Unit</th>
                    <th width="9%">Total</th>
                    <th width="6%"></th>
                  </tr>
                </thead>
                <tbody id="PurPOTbleBody">
                  <tr class="item-row">
                    <td>
                      <select name="item_details[0][product_id]" id="productSelect_0"
                              data-plugin-selecttwo class="form-control select2-js" onchange="onItemChange(this)" required>
                        <option value="" disabled selected>Select Raw</option>
                        @foreach($allProducts as $product)
                          <option value="{{ $product->id }}" data-unit="{{ $product->unit }}">
                            {{ $product->name }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="item_details[0][variation_id]" id="variationSelect_0"
                              data-plugin-selecttwo class="form-control select2-js">
                        <option value="">No Variation</option>
                      </select>
                    </td>
                    <td>
                      <select name="item_details[0][invoice_id]" id="invoiceSelect_0"
                              data-plugin-selecttwo class="form-control select2-js" onchange="onInvoiceChange(this)">
                        <option value="">Select Invoice</option>
                      </select>
                    </td>
                    <td>
                      <input type="text" name="item_details[0][desc]" id="item_desc_0"
                             class="form-control" placeholder="Description">
                    </td>
                    <td>
                      <input type="number" name="item_details[0][item_rate]" id="item_rate_0"
                             class="form-control" step="any" value="0"
                             onchange="rowTotal(0)" required>
                    </td>
                    <td>
                      <input type="number" name="item_details[0][qty]" id="item_qty_0"
                             class="form-control" step="any" value="0"
                             onchange="rowTotal(0)" required>
                    </td>
                    <td>
                      <select name="item_details[0][item_unit]" id="item_unit_0"
                              data-plugin-selecttwo class="form-control select2-js" required>
                        <option value="" disabled selected>Unit</option>
                        @foreach($units as $unit)
                          <option value="{{ $unit->id }}">{{ $unit->shortcode }}</option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <input type="number" id="item_total_0" class="form-control" disabled value="0">
                    </td>
                    <td>
                      <button type="button" onclick="removeRow(this)"
                              class="btn btn-danger btn-sm"><i class="fas fa-times"></i></button>
                      <button type="button" onclick="addNewRow()"
                              class="btn btn-primary btn-sm"><i class="fas fa-plus"></i></button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>

      {{-- Summary --}}
      <div class="col-12">
        <section class="card">
          <header class="card-header">
            <h2 class="card-title">Summary</h2>
          </header>
          <div class="card-body">
            <div class="row align-items-end">
              <div class="col-md-2">
                <label>Total Qty</label>
                <input type="number" class="form-control" id="total_fab" disabled>
              </div>
              <div class="col-md-2">
                <label>Total Amount</label>
                <input type="number" class="form-control" id="total_fab_amt" disabled>
              </div>
              <div class="col-md-4">
                <label>Attachments</label>
                <input type="file" class="form-control" name="att[]" multiple
                       accept="image/png,image/jpeg,image/jpg,image/webp,application/pdf">
              </div>
              <div class="col-md-4 text-end">
                <h5 class="mb-0 text-primary">
                  Net Amount: <strong class="text-danger fs-4">
                    PKR <span id="netTotal">0.00</span>
                  </strong>
                </h5>
                <input type="hidden" name="total_amount" id="net_amount">
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <a class="btn btn-danger" href="{{ route('production.index') }}">Discard</a>
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save me-1"></i> Create Production
            </button>
          </footer>
        </section>
      </div>

    </div>
  </form>
</div>

<script>
  var rowIndex = 1;
  const allProducts = @json($allProducts);

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });
  });

  // ── Add / Remove rows ─────────────────────────────────────────────

  function removeRow(button) {
    if ($('#PurPOTbleBody tr').length > 1) {
      $(button).closest('tr').remove();
      tableTotal();
    }
  }

  function addNewRow() {
    const options = allProducts.map(p =>
      `<option value="${p.id}" data-unit="${p.unit ?? ''}">${p.name}</option>`
    ).join('');

    const unitOptions = `
      @foreach($units as $unit)
        <option value="{{ $unit->id }}">{{ $unit->shortcode }}</option>
      @endforeach
    `;

    const newRow = `
      <tr class="item-row">
        <td>
          <select name="item_details[${rowIndex}][product_id]" id="productSelect_${rowIndex}"
                  data-plugin-selecttwo class="form-control select2-js" onchange="onItemChange(this)" required>
            <option value="" disabled selected>Select Raw</option>
            ${options}
          </select>
        </td>
        <td>
          <select name="item_details[${rowIndex}][variation_id]" id="variationSelect_${rowIndex}"
                  data-plugin-selecttwo class="form-control select2-js">
            <option value="">No Variation</option>
          </select>
        </td>
        <td>
          <select name="item_details[${rowIndex}][invoice_id]" id="invoiceSelect_${rowIndex}"
                  data-plugin-selecttwo class="form-control select2-js" onchange="onInvoiceChange(this)">
            <option value="">Select Invoice</option>
          </select>
        </td>
        <td>
          <input type="text" name="item_details[${rowIndex}][desc]" id="item_desc_${rowIndex}"
                 class="form-control" placeholder="Description">
        </td>
        <td>
          <input type="number" name="item_details[${rowIndex}][item_rate]" id="item_rate_${rowIndex}"
                 class="form-control" step="any" value="0" onchange="rowTotal(${rowIndex})" required>
        </td>
        <td>
          <input type="number" name="item_details[${rowIndex}][qty]" id="item_qty_${rowIndex}"
                 class="form-control" step="any" value="0" onchange="rowTotal(${rowIndex})" required>
        </td>
        <td>
          <select name="item_details[${rowIndex}][item_unit]" id="item_unit_${rowIndex}"
                  data-plugin-selecttwo class="form-control select2-js" required>
            <option value="" disabled selected>Unit</option>
            ${unitOptions}
          </select>
        </td>
        <td>
          <input type="number" id="item_total_${rowIndex}" class="form-control" disabled value="0">
        </td>
        <td>
          <button type="button" onclick="removeRow(this)" class="btn btn-danger btn-sm">
            <i class="fas fa-times"></i>
          </button>
          <button type="button" onclick="addNewRow()" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i>
          </button>
        </td>
      </tr>`;

    $('#PurPOTbleBody').append(newRow);
    $(`#productSelect_${rowIndex}, #variationSelect_${rowIndex}, #invoiceSelect_${rowIndex}`)
      .select2({ width: '100%', dropdownAutoWidth: true });
    rowIndex++;
  }

  // ── Calculations ──────────────────────────────────────────────────

  function rowTotal(i) {
    const rate  = parseFloat($(`#item_rate_${i}`).val()) || 0;
    const qty   = parseFloat($(`#item_qty_${i}`).val())  || 0;
    $(`#item_total_${i}`).val((rate * qty).toFixed(2));
    tableTotal();
  }

  function tableTotal() {
    let totalQty = 0, totalAmt = 0;
    $('#PurPOTbleBody tr').each(function () {
      totalQty += parseFloat($(this).find('input[id^="item_qty_"]').val())   || 0;
      totalAmt += parseFloat($(this).find('input[id^="item_total_"]').val()) || 0;
    });
    $('#total_fab').val(totalQty.toFixed(2));
    $('#total_fab_amt').val(totalAmt.toFixed(2));
    $('#netTotal').text(totalAmt.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    $('#net_amount').val(totalAmt.toFixed(2));
  }

  // ── Product / Variation / Invoice change handlers ─────────────────

  function onItemChange(select) {
    const row    = $(select).closest('tr');
    const itemId = select.value;
    if (!itemId) return;

    const i = select.id.replace('productSelect_', '');

    // Reset dependent fields
    $(`#item_qty_${i}, #item_rate_${i}, #item_total_${i}`).val('0');

    // Auto-fill unit
    const unitId = select.options[select.selectedIndex].getAttribute('data-unit');
    if (unitId) $(`#item_unit_${i}`).val(unitId).trigger('change');

    // Load variations
    const $varSel = $(`#variationSelect_${i}`);
    $varSel.html('<option value="">Loading...</option>');
    $.get(`/product/${itemId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => {
        opts += `<option value="${v.id}">${v.sku}</option>`;
      });
      $varSel.html(opts);
      if ($varSel.hasClass('select2-hidden-accessible')) $varSel.select2('destroy');
      $varSel.select2({ width: '100%', dropdownAutoWidth: true });
    });

    // Load invoices
    fetchInvoices(itemId, row);
  }

  function fetchInvoices(productId, row) {
    const i         = row.find('select[id^="productSelect_"]').attr('id').replace('productSelect_', '');
    const $invSel   = $(`#invoiceSelect_${i}`);
    $invSel.html('<option value="">Loading...</option>');

    $.get(`/product/${productId}/invoices`, function (data) {
      let opts = '<option value="">Select Invoice</option>';
      if (Array.isArray(data) && data.length) {
        data.forEach(inv => {
          const billPart = inv.bill_no ? ` | Bill: ${inv.bill_no}` : '';
          opts += `<option value="${inv.id}" data-rate="${inv.rate}">${inv.invoice_no}${billPart} — ${inv.vendor}</option>`;
        });
      } else {
        opts = '<option value="">No Invoices Found</option>';
      }
      $invSel.html(opts);
      if ($invSel.hasClass('select2-hidden-accessible')) $invSel.select2('destroy');
      $invSel.select2({ width: '100%', dropdownAutoWidth: true });
    });
  }

  function onInvoiceChange(select) {
    const row  = $(select).closest('tr');
    const rate = $(select).find(':selected').data('rate') || 0;
    const i    = select.id.replace('invoiceSelect_', '');
    $(`#item_rate_${i}`).val(rate);
    rowTotal(i);
  }
</script>
@endsection