@extends('layouts.app')
@section('title', 'Sale Invoice | Edit')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_invoices.update', $invoice->id) }}" method="POST">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Edit Sale Invoice #{{ $invoice->invoice_no }}</h2>
          <div>
            <a href="{{ route('sale_invoices.show', $invoice->id) }}" class="btn btn-info btn-sm">
              <i class="fas fa-eye me-1"></i> View Payments
            </a>
          </div>
        </header>

        <div class="card-body">

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

          {{-- Payment Status Banner --}}
          @php
            $badgeClass = match($invoice->payment_status) {
              'paid'    => 'alert-success',
              'partial' => 'alert-warning',
              default   => 'alert-danger',
            };
          @endphp
          <div class="alert {{ $badgeClass }} d-flex justify-content-between align-items-center py-2 mb-3">
            <div>
              <strong>Payment Status: {{ ucfirst($invoice->payment_status) }}</strong>
              &nbsp;|&nbsp;
              Net: <strong>PKR {{ number_format($invoice->net_amount, 2) }}</strong>
              &nbsp;|&nbsp;
              Paid: <strong>PKR {{ number_format($invoice->paid_amount, 2) }}</strong>
              &nbsp;|&nbsp;
              Balance: <strong>PKR {{ number_format($invoice->balance, 2) }}</strong>
            </div>
            <a href="{{ route('sale_invoices.show', $invoice->id) }}" class="btn btn-sm btn-dark">
              Manage Payments
            </a>
          </div>

          {{-- Header Fields --}}
          <div class="row">
            <div class="col-md-2 mb-3">
              <label>Invoice #</label>
              <input type="text" class="form-control" value="{{ $invoice->invoice_no }}" readonly>
            </div>
            <div class="col-md-2 mb-3">
              <label>Date <span class="text-danger">*</span></label>
              <input type="date" name="date" class="form-control"
                     value="{{ $invoice->date }}" required>
            </div>
            <div class="col-md-3 mb-3">
              <label>Customer</label>
              <select name="account_id" data-plugin-selecttwo class="form-control select2-js">
                <option value="">Walk-in Customer</option>
                @foreach($customers as $c)
                  <option value="{{ $c->id }}"
                    {{ $invoice->account_id == $c->id ? 'selected' : '' }}>
                    {{ $c->name }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2 mb-3">
              <label>Type <span class="text-danger">*</span></label>
              <select name="type" data-plugin-selecttwo class="form-control select2-js" required>
                <option value="credit" {{ $invoice->type === 'credit' ? 'selected' : '' }}>Credit</option>
                <option value="cash"   {{ $invoice->type === 'cash'   ? 'selected' : '' }}>Cash</option>
              </select>
            </div>
            <div class="col-md-2 mb-3">
              <label>Payment Terms</label>
              <input type="text" name="payment_terms" class="form-control"
                     value="{{ $invoice->payment_terms }}">
            </div>
            <div class="col-md-2 mb-3">
              <label>Ref.</label>
              <input type="text" name="ref_no" class="form-control"
                     value="{{ $invoice->ref_no }}">
            </div>
            <div class="col-md-4 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2">{{ $invoice->remarks }}</textarea>
            </div>
          </div>

          {{-- Items Table --}}
          <div class="table-responsive mb-3">
            <table class="table table-bordered table-sm" id="saleTable">
              <thead class="table-light">
                <tr>
                  <th>Barcode</th>
                  <th>Item</th>
                  <th>Variation</th>
                  <th width="9%">Qty</th>
                  <th width="10%">Unit</th>
                  <th width="10%">Price</th>
                  <th width="8%">Disc %</th>
                  <th width="10%">Amount</th>
                  <th width="5%"></th>
                </tr>
              </thead>
              <tbody id="SaleTableBody">
                @foreach($invoice->items as $key => $item)
                  @php
                    $rowNum = $key + 1;
                    $lineTotal = $item->getLineTotal();
                  @endphp
                  <tr>
                    <td>
                      <input type="text" name="items[{{ $key }}][barcode]"
                             id="barcode_{{ $rowNum }}"
                             class="form-control product-code"
                             value="{{ $item->product->barcode ?? '' }}">
                    </td>
                    <td>
                      <select name="items[{{ $key }}][product_id]"
                              id="product_{{ $rowNum }}"
                              data-plugin-selecttwo class="form-control select2-js product-select"
                              onchange="onProductChange(this)" required>
                        <option value="">Select Item</option>
                        @foreach($products as $p)
                          <option value="{{ $p->id }}"
                                  data-barcode="{{ $p->barcode }}"
                                  data-price="{{ $p->selling_price }}"
                                  data-unit="{{ $p->measurement_unit }}"
                                  {{ $item->product_id == $p->id ? 'selected' : '' }}>
                            {{ $p->name }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <select name="items[{{ $key }}][variation_id]"
                              id="variation_{{ $rowNum }}"
                              data-plugin-selecttwo  class="form-control select2-js variation-select">
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
                      <input type="number" name="items[{{ $key }}][quantity]"
                             id="qty_{{ $rowNum }}"
                             class="form-control quantity"
                             value="{{ $item->quantity }}" step="any"
                             onchange="rowTotal({{ $rowNum }})" required>
                    </td>
                    <td>
                      <select name="items[{{ $key }}][unit]"
                              id="unit_{{ $rowNum }}"
                              data-plugin-selecttwo class="form-control select2-js" required>
                        <option value="">Unit</option>
                        @foreach($units as $unit)
                          <option value="{{ $unit->id }}"
                            {{ $item->unit == $unit->id ? 'selected' : '' }}>
                            {{ $unit->shortcode }}
                          </option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $key }}][sale_price]"
                             id="price_{{ $rowNum }}"
                             class="form-control"
                             value="{{ $item->sale_price }}" step="any"
                             onchange="rowTotal({{ $rowNum }})" required>
                    </td>
                    <td>
                      <input type="number" name="items[{{ $key }}][discount]"
                             id="disc_{{ $rowNum }}"
                             class="form-control"
                             value="{{ $item->discount ?? 0 }}" step="any"
                             min="0" max="100"
                             onchange="rowTotal({{ $rowNum }})">
                    </td>
                    <td>
                      <input type="number" id="amount_{{ $rowNum }}"
                             class="form-control"
                             value="{{ number_format($lineTotal, 2, '.', '') }}" disabled>
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
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRow()">
              <i class="fas fa-plus"></i> Add Item
            </button>
          </div>

          {{-- Totals --}}
          <div class="row mb-3">
            <div class="col-md-2">
              <label>Sub Total</label>
              <input type="text" id="subTotal" class="form-control"
                     value="{{ number_format($invoice->sub_total, 2, '.', '') }}" disabled>
            </div>
            <div class="col-md-2">
              <label>Bill Discount (PKR)</label>
              <input type="number" name="discount" id="bill_discount"
                     class="form-control" step="any"
                     value="{{ $invoice->discount }}" onchange="calcNet()">
            </div>
            <div class="col-md-2">
              <label>Conveyance</label>
              <input type="number" name="convance_charges" id="conveyance"
                     class="form-control" step="any"
                     value="{{ $invoice->convance_charges }}" onchange="calcNet()">
            </div>
            <div class="col-md-6 text-end">
              <h4 class="text-primary mb-0">Net:
                <strong class="text-danger">
                  PKR <span id="netDisplay">
                    {{ number_format($invoice->net_amount, 2) }}
                  </span>
                </strong>
              </h4>
              <input type="hidden" name="net_amount" id="net_amount"
                     value="{{ $invoice->net_amount }}">
            </div>
          </div>

          {{-- Payment Summary (read-only, manage from show page) --}}
          <div class="card border-info">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
              <h6 class="mb-0"><i class="fas fa-money-bill-wave me-1"></i> Payment Summary</h6>
              <a href="{{ route('sale_invoices.show', $invoice->id) }}"
                 class="btn btn-sm btn-light">
                <i class="fas fa-plus me-1"></i> Add / Edit Payments
              </a>
            </div>
            <div class="card-body">
              @if($invoice->payments->count())
                <div class="table-responsive">
                  <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                      <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      @foreach($invoice->payments as $payment)
                        <tr>
                          <td>{{ \Carbon\Carbon::parse($payment->payment_date)->format('d-M-Y') }}</td>
                          <td>{{ $payment->account->name ?? '-' }}</td>
                          <td>{{ $payment->reference ?? '-' }}</td>
                          <td class="text-end text-success fw-bold">
                            {{ number_format($payment->amount, 2) }}
                          </td>
                        </tr>
                      @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold">
                      <tr>
                        <td colspan="3" class="text-end">Total Paid</td>
                        <td class="text-end text-success">
                          {{ number_format($invoice->paid_amount, 2) }}
                        </td>
                      </tr>
                      <tr>
                        <td colspan="3" class="text-end">Balance Due</td>
                        <td class="text-end {{ $invoice->balance > 0 ? 'text-danger' : 'text-success' }}">
                          {{ number_format($invoice->balance, 2) }}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              @else
                <p class="text-muted mb-0">
                  No payments recorded.
                  <a href="{{ route('sale_invoices.show', $invoice->id) }}">Add payment →</a>
                </p>
              @endif
            </div>
          </div>

        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-secondary">Cancel</a>
          <a href="{{ route('sale_invoices.show', $invoice->id) }}" class="btn btn-info">
            <i class="fas fa-eye me-1"></i> View Invoice
          </a>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Update Invoice
          </button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var rowIdx   = {{ $invoice->items->count() + 1 }};

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%', dropdownAutoWidth: true });

    // Reload variations for existing rows
    $('#SaleTableBody tr').each(function () {
      const row       = $(this);
      const productId = row.find('.product-select').val();
      const varId     = row.find('.variation-select').val();
      if (productId) loadVariations(row, productId, varId);
    });

    calcNet();

    // Product change
    $(document).on('change', '.product-select', function () {
      const row       = $(this).closest('tr');
      const productId = $(this).val();
      if (productId) loadVariations(row, productId);
    });

    // Barcode scan
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
          if (res.variation.price) {
            row.find('input[id^="price_"]').val(res.variation.price);
          }
          row.find('.quantity').focus();
        }

        if (res.type === 'product') {
          const opt = row.find(`.product-select option[value="${res.product.id}"]`);
          row.find('.product-select').val(res.product.id).trigger('change.select2');
          if (opt.data('price')) row.find('input[id^="price_"]').val(opt.data('price'));
          loadVariations(row, res.product.id);
        }
      }).fail(() => alert('Error fetching product.'));
    });

    // Enter on qty → add row
    $(document).on('keypress', '.quantity', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        if ($(this).val().trim()) addRow();
      }
    });
  });

  function onProductChange(selectEl) {
    const opt   = selectEl.options[selectEl.selectedIndex];
    const i     = selectEl.id.replace('product_', '');
    const price = opt.getAttribute('data-price') || 0;
    const unit  = opt.getAttribute('data-unit')  || '';
    document.getElementById(`price_${i}`).value = price;
    const $unit = document.getElementById(`unit_${i}`);
    if ($unit) $unit.value = unit;
    rowTotal(i);
  }

  function removeRow(button) {
    if ($('#SaleTableBody tr').length > 1) {
      $(button).closest('tr').remove();
      calcNet();
    }
  }

  function addRow() {
    const i          = rowIdx;
    const rowKey     = i - 1;

    const productOpts = products.map(p =>
      `<option value="${p.id}"
               data-barcode="${p.barcode ?? ''}"
               data-price="${p.selling_price ?? 0}"
               data-unit="${p.measurement_unit ?? ''}">${p.name}</option>`
    ).join('');

    const unitOpts = `
      @foreach($units as $unit)
        <option value="{{ $unit->id }}">{{ $unit->shortcode }}</option>
      @endforeach
    `;

    const row = `
      <tr>
        <td><input type="text" name="items[${rowKey}][barcode]" id="barcode_${i}"
                   class="form-control product-code"></td>
        <td>
          <select name="items[${rowKey}][product_id]" id="product_${i}"
                  data-plugin-selecttwo class="form-control select2-js product-select"
                  onchange="onProductChange(this)" required>
            <option value="">Select Item</option>${productOpts}
          </select>
        </td>
        <td>
          <select name="items[${rowKey}][variation_id]" id="variation_${i}"
                  data-plugin-selecttwo class="form-control select2-js variation-select">
            <option value="">No Variation</option>
          </select>
        </td>
        <td><input type="number" name="items[${rowKey}][quantity]" id="qty_${i}"
                   class="form-control quantity" value="0" step="any"
                   onchange="rowTotal(${i})" required></td>
        <td>
          <select name="items[${rowKey}][unit]" id="unit_${i}"
                  data-plugin-selecttwo class="form-control select2-js" required>
            <option value="">Unit</option>${unitOpts}
          </select>
        </td>
        <td><input type="number" name="items[${rowKey}][sale_price]" id="price_${i}"
                   class="form-control" value="0" step="any"
                   onchange="rowTotal(${i})" required></td>
        <td><input type="number" name="items[${rowKey}][discount]" id="disc_${i}"
                   class="form-control" value="0" step="any" min="0" max="100"
                   onchange="rowTotal(${i})"></td>
        <td><input type="number" id="amount_${i}"
                   class="form-control" value="0" disabled></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
            <i class="fas fa-times"></i>
          </button>
        </td>
      </tr>`;

    $('#SaleTableBody').append(row);
    $(`#product_${i}, #variation_${i}, #unit_${i}`)
      .select2({ width: '100%', dropdownAutoWidth: true });
    rowIdx++;
  }

  function rowTotal(i) {
    const price = parseFloat($(`#price_${i}`).val()) || 0;
    const qty   = parseFloat($(`#qty_${i}`).val())   || 0;
    const disc  = parseFloat($(`#disc_${i}`).val())  || 0;
    const amt   = (price - (price * disc / 100)) * qty;
    $(`#amount_${i}`).val(amt.toFixed(2));
    calcNet();
  }

  function calcNet() {
    let sub = 0;
    $('input[id^="amount_"]').each(function () {
      sub += parseFloat($(this).val()) || 0;
    });
    const disc = parseFloat($('#bill_discount').val()) || 0;
    const conv = parseFloat($('#conveyance').val())    || 0;
    const net  = sub - disc + conv;
    $('#subTotal').val(sub.toFixed(2));
    $('#netDisplay').text(net.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    $('#net_amount').val(net.toFixed(2));
  }

  function loadVariations(row, productId, preselectId = null) {
    const $var = row.find('.variation-select');
    $var.html('<option value="">Loading...</option>').prop('disabled', true);
    $.get(`/product/${productId}/variations`, function (data) {
      let opts = '<option value="">No Variation</option>';
      (data.variation || []).forEach(v => {
        opts += `<option value="${v.id}">${v.sku}</option>`;
      });
      $var.html(opts).prop('disabled', false);
      if ($var.hasClass('select2-hidden-accessible')) $var.select2('destroy');
      $var.select2({ width: '100%', dropdownAutoWidth: true });
      if (preselectId) $var.val(String(preselectId)).trigger('change');
    });
  }
</script>
@endsection