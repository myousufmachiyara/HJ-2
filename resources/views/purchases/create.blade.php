@extends('layouts.app')

@section('title', 'Purchase | New Invoice')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('purchase_invoices.store') }}" method="POST" enctype="multipart/form-data">
      @csrf
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">New Purchase Invoice</h2>
        </header>

        <div class="card-body">
          <div class="row">
            <input type="hidden" id="itemCount" name="items" value="1">

            <div class="col-md-2 mb-3">
              <label>Invoice Date</label>
              <input type="date" name="invoice_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>

            <div class="col-md-2 mb-3">
              <label>Vendor</label>
              <select name="vendor_id" class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach ($vendors as $vendor)
                  <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2 mb-3">
              <label>Payment Terms</label>
              <input type="text" name="payment_terms" class="form-control">
            </div>

            <div class="col-md-1 mb-3">
              <label>Bill #</label>
              <input type="text" name="bill_no" class="form-control">
            </div>

            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control">
            </div>

            <div class="col-md-3 mb-3">
              <label>Attachments</label>
              <input type="file" name="attachments[]" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.zip">
            </div>

            <div class="col-md-4 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="3"></textarea>
            </div>
          </div>
          <div class="d-flex justify-content-end mb-2">
            <div class="btn-group">
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadImportTemplate()">
                <i class="fas fa-file-download"></i> Download Import Template
              </button>
              <button type="button" class="btn btn-outline-success btn-sm" onclick="document.getElementById('excelImportInput').click()">
                <i class="fas fa-file-upload"></i> Import from Excel
              </button>
              <input type="file" id="excelImportInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="handleExcelImport(event)">
            </div>
          </div>
          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="purchaseTable">
              <thead>
                <tr>
                  <th>Item Code</th>
                  <th>Item Name</th>
                  <th>Variation</th>
                  <th>Quantity</th>
                  <th>Unit</th>
                  <th>Price</th>
                  <th>Amount</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="Purchase1Table">
                <tr>
                  <td><input type="text" name="items[0][item_code]" id="item_cod1" class="form-control product-code"></td>

                  <td>
                    <select name="items[0][item_id]" id="item_name1" class="form-control select2-js product-select" onchange="onItemNameChange(this)">
                      <option value="">Select Item</option>
                      @foreach ($products as $product)
                        {{-- In create.blade.php @foreach --}}
                        <option value="{{ $product->id }}" 
                                data-barcode="{{ $product->barcode }}" 
                                data-unit-id="{{ $product->measurement_unit }}"
                                data-cost-price="{{ $product->cost_price ?? 0 }}">
                          {{ $product->name }}
                        </option>
                      @endforeach
                    </select>
                  </td>

                  <td>
                    <select name="items[0][variation_id]" class="form-control select2-js variation-select">
                      <option value="">Select Variation</option>
                    </select>
                  </td>                  

                  <td><input type="number" name="items[0][quantity]" id="pur_qty1" class="form-control quantity" value="0" step="any" onchange="rowTotal(1)"></td>

                  <td>
                    <select name="items[0][unit]" id="unit1" class="form-control" required>
                      <option value="">-- Select --</option>
                      @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->shortcode }})</option>
                      @endforeach
                    </select>
                  </td>

                  <td><input type="number" name="items[0][price]" id="pur_price1" class="form-control" value="0" step="any" onchange="rowTotal(1)"></td>
                  <td><input type="number" id="amount1" class="form-control" value="0" step="any" disabled></td>
                  <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
                    <input type="hidden" name="items[0][barcode]" id="barcode1">
                  </td>
                </tr>
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary" onclick="addNewRow_btn()"><i class="fas fa-plus"></i> Add Item</button>
          </div>

          <div class="row mb-3">
            <div class="col-md-2">
              <label>Total Amount</label>
              <input type="text" id="totalAmount" class="form-control" disabled>
              <input type="hidden" name="total_amount" id="total_amount_show">
            </div>
            <div class="col-md-2">
              <label>Total Quantity</label>
              <input type="text" id="total_quantity" class="form-control" disabled>
              <input type="hidden" name="total_quantity" id="total_quantity_show">
            </div>
            <div class="col-md-2">
              <label>Convance Charges</label>
              <input type="number" name="convance_charges" id="convance_charges" class="form-control" value="0" onchange="netTotal()">
            </div>
            <div class="col-md-2">
              <label>Labour Charges</label>
              <input type="number" name="labour_charges" id="labour_charges" class="form-control" value="0" onchange="netTotal()">
            </div>
            <div class="col-md-2">
              <label>Bill Discount</label>
              <input type="number" name="bill_discount" id="bill_discount" class="form-control" value="0" onchange="netTotal()">
            </div>
          </div>

          <div class="row">
            <div class="col text-end">
              <h4>Net Amount: <strong class="text-danger">PKR <span id="netTotal">0.00</span></strong></h4>
              <input type="hidden" name="net_amount" id="net_amount">
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"> <i class="fas fa-save"></i> Save Invoice</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index = 2;

  // 🔹 Download a blank Excel template
  function downloadImportTemplate() {
    const headers = [['Item Code (Barcode)', 'Quantity', 'Unit (Shortcode)', 'Price']];
    const example = [['ITM-0001', 5, 'PCS', 120.50]];
    const ws = XLSX.utils.aoa_to_sheet(headers.concat(example));
    ws['!cols'] = [{ wch: 22 }, { wch: 12 }, { wch: 16 }, { wch: 12 }];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Items');
    XLSX.writeFile(wb, 'purchase_items_import_template.xlsx');
  }

  // 🔹 Read the uploaded file
  function handleExcelImport(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
      try {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, { type: 'array' });
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });

        const dataRows = rows.slice(1).filter(r => r.length && String(r[0]).trim() !== '');
        if (!dataRows.length) {
          alert('No item rows found in the uploaded file.');
          return;
        }
        importRowsSequentially(dataRows, 0);
      } catch (err) {
        console.error(err);
        alert('Could not read the uploaded file. Please make sure it matches the template format.');
      } finally {
        event.target.value = '';
      }
    };
    reader.readAsArrayBuffer(file);
  }

  // 🔹 Append rows one at a time, resolving each barcode via your existing endpoint
  function importRowsSequentially(rows, i) {
    if (i >= rows.length) return;

    const [barcodeRaw, qtyRaw, unitRaw, priceRaw] = rows[i];
    const barcode = String(barcodeRaw ?? '').trim();
    const qty     = parseFloat(qtyRaw) || 0;
    const unitTxt = String(unitRaw ?? '').trim().toLowerCase();
    const price   = parseFloat(priceRaw) || 0;

    if (!barcode) { importRowsSequentially(rows, i + 1); return; }

    addNewRow();
    const $newRow = $('#Purchase1Table tbody tr').last();
    const rowIdx  = index - 1; // id suffix used by addNewRow() for this row

    $.ajax({
      url: '/get-product-by-code/' + encodeURIComponent(barcode),
      method: 'GET',
      success: function (res) {
        if (res && res.success) {
          const $productSelect   = $newRow.find('.product-select');
          const $variationSelect = $newRow.find('.variation-select');

          if (res.type === 'variation') {
            const v = res.variation;
            $productSelect.val(v.product_id).trigger('change.select2');
            $variationSelect.html(`<option value="${v.id}" selected>${v.sku}</option>`)
                            .prop('disabled', false).trigger('change');
            $newRow.find('.product-code').val(v.barcode);
            $newRow.find('input[name*="[barcode]"]').val(v.barcode);
          } else if (res.type === 'product') {
            const p = res.product;
            $productSelect.val(p.id).trigger('change.select2');
            $newRow.find('.product-code').val(p.barcode);
            $newRow.find('input[name*="[barcode]"]').val(p.barcode);
            loadVariations($newRow, p.id);
          }

          if (unitTxt) {
            const matched = units.find(u =>
              String(u.shortcode).toLowerCase() === unitTxt ||
              String(u.name).toLowerCase() === unitTxt
            );
            if (matched) $(`#unit${rowIdx}`).val(String(matched.id)).trigger('change.select2');
          }

          $(`#pur_qty${rowIdx}`).val(qty);
          $(`#pur_price${rowIdx}`).val(price);
          rowTotal(rowIdx);
        } else {
          console.warn('Import: product not found for barcode', barcode);
        }
      },
      error: function () {
        console.warn('Import: lookup failed for barcode', barcode);
      },
      complete: function () {
        importRowsSequentially(rows, i + 1);
      }
    });
  }
  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // 🔹 Manual Product selection flow
    $(document).on('change', '.product-select', function () {
      const row = $(this).closest('tr');
      const productId = $(this).val();
      const preselectVariationId = $(this).data('preselectVariationId') || null;
      $(this).removeData('preselectVariationId');

      if (productId) {
        loadVariations(row, productId, preselectVariationId);
      } else {
        row.find('.variation-select')
          .html('<option value="">Select Variation</option>')
          .prop('disabled', false)
          .trigger('change');
      }
    });

    // 🔹 Barcode scanning flow
    $(document).on('blur', '.product-code', function () {
      const row = $(this).closest('tr');
      const barcode = $(this).val().trim();
      if (!barcode) return;

      $.ajax({
        url: '/get-product-by-code/' + encodeURIComponent(barcode),
        method: 'GET',
        success: function (res) {
          if (!res || !res.success) {
            alert(res.message || 'Product not found');
            row.find('.product-code').val('').focus();
            row.find('.product-select').val('').trigger('change.select2');
            row.find('.variation-select').html('<option value="">Select Variation</option>')
               .prop('disabled', false)
               .trigger('change');
            return;
          }

          const $productSelect = row.find('.product-select');
          const $variationSelect = row.find('.variation-select');

          if (res.type === 'variation') {
            const variation = res.variation;

            // ✅ Set product
            $productSelect.val(variation.product_id).trigger('change.select2');

            // ✅ Directly set variation
            $variationSelect.html(`<option value="${variation.id}" selected>${variation.sku}</option>`)
                            .prop('disabled', false)
                            .trigger('change');

            // ✅ Update barcode fields
            row.find('.product-code').val(variation.barcode);
            row.find('input[name*="[barcode]"]').val(variation.barcode);

            // ✅ Focus Qty field
            row.find('.quantity').focus();
          }

          if (res.type === 'product') {
            const product = res.product;

            // ✅ Only select if it exists in dropdown
            if ($productSelect.find(`option[value="${product.id}"]`).length) {
              $productSelect.val(product.id).trigger('change.select2');

              // ✅ Update barcode fields
              row.find('.product-code').val(product.barcode);
              row.find('input[name*="[barcode]"]').val(product.barcode);

              // ✅ Load variations normally
              loadVariations(row, product.id);

              // focus on variation after loading
              setTimeout(() => {
                $variationSelect.select2('open');
              }, 300);
            } else {
              alert("Product found but not in dropdown list.");
              row.find('.product-code').val('').focus();
              row.find('.product-select').val('').trigger('change.select2');
              row.find('.variation-select').html('<option value="">Select Variation</option>')
                 .prop('disabled', false)
                 .trigger('change');
            }
          }
        },
        error: function () {
          alert('Error fetching product details.');
        }
      });
    });

    // 🔹 POS: Auto-add row when user presses Enter on Qty
    $(document).on('keypress', '.quantity', function (e) {
      if (e.which === 13) { // Enter key
        e.preventDefault();
        const row = $(this).closest('tr');
        const qty = $(this).val().trim();

        if (qty !== '') {
          // Add new row
          addNewRow();

          // Focus on new row's barcode
          const $newRow = $('#Purchase1Table tbody tr').last();
          $newRow.find('.product-code').focus();
        } else {
          alert("Please enter quantity first.");
          $(this).focus();
        }
      }
    });
  });

  // 🔹 Keep all your existing functions exactly as they are
  function onItemNameChange(selectElement) {
      const row = selectElement.closest('tr');
      const selectedOption = selectElement.options[selectElement.selectedIndex];

      const unitId     = selectedOption.getAttribute('data-unit-id');
      const barcode    = selectedOption.getAttribute('data-barcode');
      const costPrice  = selectedOption.getAttribute('data-cost-price') || '0';

      const idMatch = selectElement.id.match(/\d+$/);
      if (!idMatch) return;
      const index = idMatch[0];

      document.getElementById(`item_cod${index}`).value  = barcode;
      document.getElementById(`barcode${index}`).value   = barcode;

      // ← Set cost price into price field
      document.getElementById(`pur_price${index}`).value = parseFloat(costPrice).toFixed(2);

      const unitSelector = $(`#unit${index}`);
      unitSelector.val(String(unitId)).trigger('change.select2');

      // Recalculate row total
      rowTotal(index);
  }

  function removeRow(button) {
    let rows = $('#Purchase1Table tr').length;
    if (rows > 1) {
      $(button).closest('tr').remove();
      $('#itemCount').val(--rows);
      tableTotal();
    }
  }

  function addNewRow_btn() {
    addNewRow();
    $('#item_cod' + (index - 1)).focus();
  }

  function addNewRow() {
    let table = $('#Purchase1Table');
    let rowIndex = index - 1;

    let newRow = `
      <tr>
        <td><input type="text" name="items[${rowIndex}][item_code]" id="item_cod${index}" class="form-control product-code"></td>

        <td>
          <select name="items[${rowIndex}][item_id]" id="item_name${index}" class="form-control select2-js product-select" onchange="onItemNameChange(this)">
            <option value="">Select Item</option>
            // In addNewRow() JS function — the products.map() line:
            ${products.map(product => 
              `<option value="${product.id}" 
                      data-barcode="${product.barcode}" 
                      data-unit-id="${product.measurement_unit}"
                      data-cost-price="${product.cost_price ?? 0}">
                ${product.name}
              </option>`).join('')}
          </select>
        </td>

        <td>
          <select name="items[${rowIndex}][variation_id]" class="form-control select2-js variation-select">
            <option value="">Select Variation</option>
          </select>
        </td>

        <td><input type="number" name="items[${rowIndex}][quantity]" id="pur_qty${index}" class="form-control quantity" value="0" step="any" onchange="rowTotal(${index})"></td>

        <td>
          <select name="items[${rowIndex}][unit]" id="unit${index}" class="form-control" required>
            <option value="">-- Select --</option>
            @foreach ($units as $unit)
              <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->shortcode }})</option>
            @endforeach
          </select>
        </td>

        <td><input type="number" name="items[${rowIndex}][price]" id="pur_price${index}" class="form-control" value="0" step="any" onchange="rowTotal(${index})"></td>
        <td><input type="number" id="amount${index}" class="form-control" value="0" step="any" disabled></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
          <input type="hidden" name="items[${rowIndex}][barcode]" id="barcode${index}">
        </td>
      </tr>
    `;
    table.append(newRow);
    $('#itemCount').val(index);
    $(`#item_name${index}`).select2();
    $(`#unit${index}`).select2();
    index++;
  }

  function rowTotal(row) {
    let quantity = parseFloat($('#pur_qty' + row).val()) || 0;
    let price = parseFloat($('#pur_price' + row).val()) || 0;
    $('#amount' + row).val((quantity * price).toFixed(2));
    tableTotal();
  }

  function tableTotal() {
    let total = 0, qty = 0;
    $('#Purchase1Table tr').each(function () {
      total += parseFloat($(this).find('input[id^="amount"]').val()) || 0;
      qty += parseFloat($(this).find('input[name="quantity[]"]').val()) || 0;
    });
    $('#totalAmount').val(total.toFixed(2));
    $('#total_amount_show').val(total.toFixed(2));
    $('#total_quantity').val(qty.toFixed(2));
    $('#total_quantity_show').val(qty.toFixed(2));
    netTotal();
  }

  function netTotal() {
    let total = parseFloat($('#totalAmount').val()) || 0;
    let conv = parseFloat($('#convance_charges').val()) || 0;
    let labour = parseFloat($('#labour_charges').val()) || 0;
    let discount = parseFloat($('#bill_discount').val()) || 0;
    let net = (total + conv + labour - discount).toFixed(2);
    $('#netTotal').text(formatNumberWithCommas(net));
    $('#net_amount').val(net);
  }

  function formatNumberWithCommas(x) {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  // 🔹 Load Variations
  function loadVariations(row, productId, preselectVariationId = null) {
    const $variationSelect = row.find('.variation-select');
    $variationSelect.html('<option>Loading...</option>').prop('disabled', true);

    $.get(`/product/${productId}/variations`, function (data) {
      let options = '<option value="" disabled selected>Select Variation</option>';

      if ((data.variation || []).length > 0) {
        data.variation.forEach(v => {
          options += `<option value="${v.id}">${v.sku}</option>`;
        });
        $variationSelect.prop('disabled', false);
      } else {
        // ✅ Only placeholder, stays disabled
        options = '<option value="" disabled selected>No Variations Available</option>';
        $variationSelect.prop('disabled', true);
      }

      $variationSelect.html(options);

      if ($variationSelect.hasClass('select2-hidden-accessible')) {
        $variationSelect.select2('destroy');
      }
      $variationSelect.select2({ width: '100%', dropdownAutoWidth: true });

      if (preselectVariationId) {
        $variationSelect.val(String(preselectVariationId)).trigger('change');
      }
    });
  }

</script>

@endsection
