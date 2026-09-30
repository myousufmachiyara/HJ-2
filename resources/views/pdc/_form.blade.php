@php
  $c        = $cheque ?? null;
  $rep      = $replacing ?? null;
  $src      = $c ?? $rep;
  $existing = old('bills') ? collect(old('bills'))->mapWithKeys(fn($b) => [$b['type'].'-'.$b['id'] => $b['amount']])
            : ($src ? $src->bills->mapWithKeys(fn($b) => [$b->bill_type.'-'.$b->bill_id => (float) $b->amount]) : collect());
@endphp

@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($rep)
  <div class="alert alert-warning">Replacing {{ $rep->statusLabel() }} cheque <b>{{ $rep->cheque_no }}</b> ({{ $rep->pdc_no }}) of PKR {{ number_format($rep->amount, 2) }}.</div>
  <input type="hidden" name="replaces_id" value="{{ $rep->id }}">
@endif

<section class="card mb-3">
  <header class="card-header"><h2 class="card-title">{{ $c ? 'Edit Cheque ' . $c->pdc_no : 'Issue Post-Dated Cheque' }}</h2></header>
  <div class="card-body">
    <div class="row">
      <div class="col-md-3 mb-3"><label>Vendor <span class="text-danger">*</span></label>
        <select name="vendor_id" id="vendor_id" class="form-control select2-js" required>
          <option value="">Select Vendor</option>
          @foreach($vendors as $v)<option value="{{ $v->id }}" {{ (string) old('vendor_id', $src->vendor_id ?? '') === (string) $v->id ? 'selected' : '' }}>{{ $v->name }}</option>@endforeach
        </select></div>
      <div class="col-md-3 mb-3"><label>Bank Account <span class="text-danger">*</span></label>
        <select name="bank_account_id" class="form-control" required>
          <option value="">Select Bank</option>
          @foreach($banks as $b)<option value="{{ $b->id }}" {{ (string) old('bank_account_id', $src->bank_account_id ?? '') === (string) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>@endforeach
        </select></div>
      <div class="col-md-2 mb-3"><label>Cheque No <span class="text-danger">*</span></label>
        <input type="text" name="cheque_no" class="form-control" value="{{ old('cheque_no', $c->cheque_no ?? '') }}" required></div>
      <div class="col-md-2 mb-3"><label>Issue Date <span class="text-danger">*</span></label>
        <input type="date" name="issue_date" class="form-control" value="{{ old('issue_date', $c ? $c->issue_date->format('Y-m-d') : date('Y-m-d')) }}" required></div>
      <div class="col-md-2 mb-3"><label>Cheque Date <span class="text-danger">*</span></label>
        <input type="date" name="cheque_date" class="form-control" value="{{ old('cheque_date', $c ? $c->cheque_date->format('Y-m-d') : '') }}" required></div>
      <div class="col-md-2 mb-3"><label>Amount <span class="text-danger">*</span></label>
        <input type="number" step="any" min="1" name="amount" id="amount" class="form-control" value="{{ old('amount', $src->amount ?? '') }}" required></div>
      <div class="col-md-6 mb-3"><label>Remarks</label>
        <input type="text" name="remarks" class="form-control" value="{{ old('remarks', $c->remarks ?? '') }}"></div>
    </div>
  </div>
</section>

<section class="card">
  <header class="card-header d-flex justify-content-between align-items-center">
    <h2 class="card-title">Against Bills <small class="text-muted">(optional — unallocated amount is an advance)</small></h2>
    <button type="button" class="btn btn-outline-primary btn-sm" onclick="autoAllocate()">Auto-allocate oldest first</button>
  </header>
  <div class="card-body">
    <table class="table table-bordered table-sm">
      <thead class="table-light"><tr><th>Bill</th><th>Date</th><th class="text-end">Bill Amount</th><th class="text-end">Covered by other cheques</th><th class="text-end">Open</th><th width="15%">Pay with this cheque</th></tr></thead>
      <tbody id="billsBody"><tr><td colspan="6" class="text-muted text-center">Select a vendor to load bills.</td></tr></tbody>
      <tfoot><tr><th colspan="5" class="text-end">Allocated</th><th id="allocated">0.00</th></tr></tfoot>
    </table>
  </div>
  <footer class="card-footer text-end">
    <a href="{{ route('pdc_cheques.index') }}" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Save Cheque</button>
  </footer>
</section>

<script>
  const EXISTING = @json($existing);
  const EXCLUDE  = {{ $c->id ?? 'null' }};

  $(function () {
    $('.select2-js').select2({ width: '100%' });
    $('#vendor_id').on('change', loadBills);
    if ($('#vendor_id').val()) loadBills();
    $(document).on('input', '.bill-amount', sumAllocated);
  });

  function loadBills() {
    const vendor = $('#vendor_id').val();
    const $body = $('#billsBody');
    if (!vendor) { $body.html('<tr><td colspan="6" class="text-muted text-center">Select a vendor to load bills.</td></tr>'); return; }
    $.get('{{ url('pdc_cheques/vendor-bills') }}/' + vendor, { exclude: EXCLUDE || '' }, function (rows) {
      if (!rows.length) { $body.html('<tr><td colspan="6" class="text-muted text-center">No bills for this vendor.</td></tr>'); return; }
      let html = '';
      rows.forEach((r, i) => {
        const key = r.type + '-' + r.id;
        const val = EXISTING[key] ?? '';
        if (r.balance <= 0 && !val) return; // fully paid & not on this cheque
        html += `<tr>
          <td>${r.label}<input type="hidden" name="bills[${i}][type]" value="${r.type}"><input type="hidden" name="bills[${i}][id]" value="${r.id}"></td>
          <td>${r.date}</td>
          <td class="text-end">${r.total.toFixed(2)}</td>
          <td class="text-end">${r.covered.toFixed(2)}</td>
          <td class="text-end open">${r.balance.toFixed(2)}</td>
          <td><input type="number" step="any" min="0" max="${r.balance}" name="bills[${i}][amount]" class="form-control form-control-sm bill-amount" value="${val}"></td>
        </tr>`;
      });
      $body.html(html || '<tr><td colspan="6" class="text-muted text-center">All bills of this vendor are already covered.</td></tr>');
      sumAllocated();
    });
  }
  function autoAllocate() {
    let left = parseFloat($('#amount').val()) || 0;
    $('#billsBody tr').each(function () {
      const open = parseFloat($(this).find('.open').text()) || 0;
      const take = Math.max(0, Math.min(open, left));
      $(this).find('.bill-amount').val(take ? take.toFixed(2) : '');
      left -= take;
    });
    sumAllocated();
  }
  function sumAllocated() {
    let t = 0; $('.bill-amount').each(function () { t += parseFloat($(this).val()) || 0; });
    const amt = parseFloat($('#amount').val()) || 0;
    $('#allocated').text(t.toFixed(2)).toggleClass('text-danger', t > amt + 0.009);
  }
</script>
