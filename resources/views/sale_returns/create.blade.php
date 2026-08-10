@extends('layouts.app')
@section('title', 'Sale Return')

@section('content')
<div class="row">
  <div class="col">
    <div class="card">
      @if ($errors->any())
        <div class="alert alert-danger">
          <ul class="mb-0">
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif
      <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title">New Sale Return</h4>
        <a href="{{ route('sale_return.index') }}" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
      </div>

      <div class="card-body">
        <form action="{{ route('sale_return.store') }}" method="POST" id="saleReturnForm">
          @csrf
          <div class="row mb-3">
            <div class="col-md-3">
              <label for="customer_id">Customer Name</label>
              <select name="customer_id" class="form-control" required>
                <option value="">Select Customer</option>
                @foreach($customers as $cust)
                  <option value="{{ $cust->id }}">{{ $cust->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label for="return_date">Date</label>
              <input type="date" name="return_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-2">
              <label for="sale_invoice_no">Sale Inv #</label>
              <input type="text" name="sale_invoice_no" class="form-control">
            </div>
          </div>

          <table class="table table-bordered" id="itemsTable">
            <thead>
              <tr>
                <th width="15%">Item Code</th>
                <th>Product</th>
                <th>Variation</th>
                <th width="8%">Qty</th>
                <th width="10%">Price</th>
                <th width="12%">Total</th>
                <th width="5%">
                  <button type="button" class="btn btn-sm btn-success" id="addRow"><i class="fas fa-plus"></i></button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><input type="text" class="form-control product-code" placeholder="Scan/Enter Code"></td>
                <td>
                  <select name="items[0][product_id]" class="form-control product-select" required>
                    <option value="">Select Product</option>
                    @foreach($products as $prod)
                      <option value="{{ $prod->id }}" data-price="{{ $prod->selling_price }}">{{ $prod->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[0][variation_id]" class="form-control variation-select">
                    <option value="">Select Variation</option>
                  </select>
                </td>
                <td><input type="number" name="items[0][qty]" class="form-control quantity" value="1" min="1"></td>
                <td><input type="number" name="items[0][price]" class="form-control sale-price" step="any" required></td>
                <td><input type="number" name="items[0][total]" class="form-control row-total" readonly></td>
                <td><button type="button" class="btn btn-sm btn-danger removeRow"><i class="fas fa-trash"></i></button></td>
              </tr>
            </tbody>
          </table>

          <div class="row mt-3">
            <div class="col-md-6">
              <label for="remarks">Remarks</label>
              <textarea name="remarks" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-2 offset-md-4">
              <label for="net_amount">Net Amount</label>
              <input type="number" name="net_amount" id="net_amount" class="form-control" readonly>
            </div>
          </div>

          {{-- Refund Section --}}
          <div class="card border-success mt-3">
            <div class="card-header bg-success text-white d-flex justify-content-between">
              <h6 class="mb-0"><i class="fas fa-undo-alt me-1"></i> Refund (Optional)</h6>
              <small>Leave blank to credit the customer's account instead</small>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-3">
                  <label>Refund From Account</label>
                  <select name="refund_account_id" id="refund_account_id" class="form-control">
                    <option value="">-- No Refund --</option>
                    @foreach($refundAccounts ?? [] as $acc)
                      <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-md-2">
                  <label>Refund Amount</label>
                  <input type="number" name="refund_amount" id="refund_amount"
                         class="form-control" value="0" step="any" min="0">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-success btn-sm w-100" id="refundFullBtn">
                    Refund Full
                  </button>
                </div>
                <div class="col-md-5 d-flex align-items-end">
                  <small class="text-muted">
                    Any unrefunded portion of the return amount is credited to the customer's account balance.
                  </small>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-3">
            <button type="submit" class="btn btn-primary">Save Return</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  $(document).ready(function () {
      let rowIndex = 1;
      const SCAN_INCREMENT = 1;

      // ✅ Initialize Select2
      $('.product-select, .variation-select').select2({ width: '100%', dropdownAutoWidth: true });

      // ✅ Add new row
      $("#addRow").click(function () {
          let newRow = `<tr>
              <td><input type="text" class="form-control product-code" placeholder="Scan/Enter Code"></td>
              <td>
                <select name="items[${rowIndex}][product_id]" class="form-control product-select" required>
                  <option value="">Select Product</option>
                  @foreach($products as $prod)
                    <option value="{{ $prod->id }}" data-price="{{ $prod->selling_price }}">{{ $prod->name }}</option>
                  @endforeach
                </select>
              </td>
              <td>
                <select name="items[${rowIndex}][variation_id]" class="form-control variation-select">
                  <option value="">Select Variation</option>
                </select>
              </td>
              <td><input type="number" name="items[${rowIndex}][qty]" class="form-control quantity" value="1" min="1"></td>
              <td><input type="number" name="items[${rowIndex}][price]" class="form-control sale-price" step="any" required></td>
              <td><input type="number" name="items[${rowIndex}][total]" class="form-control row-total" readonly></td>
              <td><button type="button" class="btn btn-sm btn-danger removeRow"><i class="fas fa-trash"></i></button></td>
            </tr>`;
          $("#itemsTable tbody").append(newRow);
          $('#itemsTable tbody tr:last .product-select, #itemsTable tbody tr:last .variation-select').select2({ width: '100%', dropdownAutoWidth: true });
          rowIndex++;
      });

      // ✅ Remove row
      $(document).on("click", ".removeRow", function () {
          $(this).closest("tr").remove();
          calculateNetAmount();
      });

      // ✅ Product change → load variations + set price
      $(document).on("change", ".product-select", function () {
          let row = $(this).closest("tr");
          let productId = $(this).val();
          let $variationSelect = row.find(".variation-select");

          let productPrice = $(this).find(":selected").data("price") || 0;
          row.find(".sale-price").val(productPrice);
          calcRowTotal(row);

          if (productId) {
              loadVariations(row, productId);
          } else {
              $variationSelect.html('<option value="">Select Variation</option>').trigger('change');
          }
      });

      // ✅ Variation change → update price
      $(document).on("change", ".variation-select", function () {
          let row = $(this).closest("tr");
          let price = $(this).find(":selected").data("price") || 0;
          row.find(".sale-price").val(price);
          calcRowTotal(row);
      });

      // ✅ Qty/Price input → recalc row total
      $(document).on("input", ".sale-price, .quantity", function () {
          let row = $(this).closest("tr");
          calcRowTotal(row);
      });

      // 🔹 Hardware scanner Enter → intercept + treat as scan complete
      $(document).on('keydown', '.product-code', function (e) {
          if (e.which === 13) {
              e.preventDefault();
              $(this).trigger('scan:submit');
          }
      });

      // 🔹 Barcode scan handler (repeat-increments + auto-next-line)
      $(document).on('scan:submit', '.product-code', function () {
          const $input = $(this);
          const row = $input.closest("tr");
          const barcode = $input.val().trim();
          if (!barcode) return;

          $.ajax({
              url: '/get-product-by-code/' + encodeURIComponent(barcode),
              method: 'GET',
              success: function (res) {
                  if (!res || !res.success) {
                      alert((res && res.message) || 'Product not found');
                      $input.val('').focus();
                      return;
                  }

                  if (res.type === 'variation' && res.variation) {
                      const v = Array.isArray(res.variation) ? res.variation[0] : res.variation;
                      handleScannedVariation(row, v.product_id, v.id, v.sku, v.price);
                  } else if (res.type === 'product' && res.product) {
                      handleScannedProduct(row, res.product);
                  } else {
                      alert('Invalid response. Barcode not matched.');
                      $input.val('').focus();
                  }
              },
              error: function () {
                  alert('Error fetching product/variation.');
                  $input.val('').focus();
              }
          });
      });

      // Manual typing + tab-out: only if product not chosen yet
      $(document).on("blur", ".product-code", function () {
          const barcode = $(this).val().trim();
          if (barcode && !$(this).closest("tr").find('.product-select').val()) {
              $(this).trigger('scan:submit');
          }
      });

      // ── Duplicate-detection helpers ──
      function findRowByVariation(productId, variationId) {
          let match = null;
          $('#itemsTable tbody tr').each(function () {
              const $r  = $(this);
              const pid = $r.find('.product-select').val();
              const vid = $r.find('.variation-select').val() || '';
              const wantV = (variationId ?? '') + '';
              if (String(pid) === String(productId) && String(vid) === wantV) {
                  match = $r;
                  return false;
              }
          });
          return match;
      }

      function rowIsEmpty($row) {
          return !$row.find('.product-select').val();
      }

      function flashRow($row) {
          $row.addClass('table-success');
          setTimeout(() => $row.removeClass('table-success'), 600);
      }

      function focusLastScanBox() {
          $('#itemsTable tbody tr:last .product-code').focus();
      }

      function openNextScanRow() {
          const $last = $('#itemsTable tbody tr:last');
          if (!rowIsEmpty($last)) {
              $("#addRow").trigger("click");
          }
          $('#itemsTable tbody tr:last .product-code').focus();
      }

      // ── Scan handlers ──
      function handleScannedVariation(scanRow, productId, variationId, sku, price) {
          const existing = findRowByVariation(productId, variationId);

          if (existing) {
              const $qty = existing.find('.quantity');
              const cur  = parseFloat($qty.val()) || 0;
              $qty.val(cur + SCAN_INCREMENT);
              calcRowTotal(existing);
              flashRow(existing);
              if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
              else focusLastScanBox();
              return;
          }

          let targetRow;
          if (rowIsEmpty(scanRow)) {
              targetRow = scanRow;
          } else {
              $("#addRow").trigger("click");
              targetRow = $('#itemsTable tbody tr:last');
          }

          targetRow.find('.product-select').val(productId).trigger('change.select2');
          loadVariations(targetRow, productId, variationId);

          let usePrice = price;
          if (!usePrice) {
              usePrice = targetRow.find(`.product-select option[value="${productId}"]`).data('price') || 0;
          }
          targetRow.find('.sale-price').val(usePrice);
          targetRow.find('.quantity').val(SCAN_INCREMENT);
          calcRowTotal(targetRow);

          targetRow.find('.product-code').val('');
          openNextScanRow();
      }

      function handleScannedProduct(scanRow, product) {
          const existing = findRowByVariation(product.id, null);

          if (existing) {
              const $qty = existing.find('.quantity');
              const cur  = parseFloat($qty.val()) || 0;
              $qty.val(cur + SCAN_INCREMENT);
              calcRowTotal(existing);
              flashRow(existing);
              if (rowIsEmpty(scanRow)) scanRow.find('.product-code').val('').focus();
              else focusLastScanBox();
              return;
          }

          let targetRow;
          if (rowIsEmpty(scanRow)) {
              targetRow = scanRow;
          } else {
              $("#addRow").trigger("click");
              targetRow = $('#itemsTable tbody tr:last');
          }

          targetRow.find('.product-select').val(product.id).trigger('change.select2');
          const opt = targetRow.find(`.product-select option[value="${product.id}"]`);
          targetRow.find('.sale-price').val(product.selling_price || opt.data('price') || 0);
          loadVariations(targetRow, product.id);
          targetRow.find('.quantity').val(SCAN_INCREMENT);
          calcRowTotal(targetRow);

          targetRow.find('.product-code').val('');
          openNextScanRow();
      }

      // ✅ Helpers
      function calcRowTotal(row) {
          let price = parseFloat(row.find('.sale-price').val()) || 0;
          let qty = parseFloat(row.find('.quantity').val()) || 1;
          row.find('.row-total').val((qty * price).toFixed(2));
          calculateNetAmount();
      }

      function calculateNetAmount() {
          let net = 0;
          $(".row-total").each(function () {
              net += parseFloat($(this).val()) || 0;
          });
          $("#net_amount").val(net.toFixed(2));
          clampRefundAmount();
      }

      // 🔹 Load variations with optional preselect
      function loadVariations(row, productId, preselectVariationId = null) {
          let $variationSelect = row.find('.variation-select');
          $variationSelect.html('<option value="">Loading...</option>');
          $.get(`/product/${productId}/variations`, function (data) {
              let options = '<option value="">Select Variation</option>';
              (data.variation || []).forEach(function (v) {
                  options += `<option value="${v.id}" data-price="${v.price || 0}">${v.sku}</option>`;
              });
              $variationSelect.html(options).trigger('change');

              if (preselectVariationId) {
                  $variationSelect.val(preselectVariationId).trigger('change');
              }
          });
      }

      // ✅ Refund helpers
      function clampRefundAmount() {
          let net = parseFloat($("#net_amount").val()) || 0;
          let refund = parseFloat($("#refund_amount").val()) || 0;
          if (refund > net) {
              $("#refund_amount").val(net.toFixed(2));
          }
      }

      $("#refundFullBtn").click(function () {
          let net = parseFloat($("#net_amount").val()) || 0;
          $("#refund_amount").val(net.toFixed(2));
      });

      $("#refund_amount").on('input', clampRefundAmount);

      // Require a refund account if a refund amount is entered
      $("#saleReturnForm").on('submit', function (e) {
          let refund = parseFloat($("#refund_amount").val()) || 0;
          let account = $("#refund_account_id").val();
          if (refund > 0 && !account) {
              e.preventDefault();
              alert('Please select a refund account, or set the refund amount to 0 to credit the customer\'s account instead.');
              $("#refund_account_id").focus();
          }
      });
  });
</script>

@endsection