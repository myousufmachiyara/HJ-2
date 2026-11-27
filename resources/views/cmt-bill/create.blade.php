@extends('layouts.app')
@section('title', 'CMT Bill | New')

@section('content')
<div class="row"><div class="col-12">
  @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">New CMT Bill</h2></header>
    <form method="GET" action="{{ route('cmt_bills.create') }}" class="card-body">
      <div class="row align-items-end">
        <div class="col-md-4">
          <label>CMT Vendor <span class="text-danger">*</span></label>
          <select name="vendor_id" class="form-control select2-js" required>
            <option value="">Select Vendor</option>
            @foreach($vendors as $v)<option value="{{ $v->id }}" {{ (int) $vendorId === $v->id ? 'selected' : '' }}>{{ $v->name }}</option>@endforeach
          </select>
        </div>
        <div class="col-md-2"><label>From <span class="text-danger">*</span></label><input type="date" name="date_from" class="form-control" value="{{ $from }}" required></div>
        <div class="col-md-2"><label>To <span class="text-danger">*</span></label><input type="date" name="date_to" class="form-control" value="{{ $to }}" required></div>
        <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fas fa-search"></i> Load Receivings</button></div>
      </div>
    </form>
  </section>

  @if($vendorId && $from && $to)
  <form method="POST" action="{{ route('cmt_bills.store') }}">
    @csrf
    <input type="hidden" name="vendor_id" value="{{ $vendorId }}">
    <input type="hidden" name="date_from" value="{{ $from }}">
    <input type="hidden" name="date_to" value="{{ $to }}">

    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Unbilled FG received in this period</h2>
        <div class="d-flex align-items-center gap-2">
          <label class="mb-0">Bill date</label>
          <input type="date" name="bill_date" class="form-control" style="width:auto" value="{{ old('bill_date', date('Y-m-d')) }}" required>
        </div>
      </header>
      <div class="card-body">
        @if($groups->isEmpty())
          <div class="alert alert-info mb-0">Nothing to bill: no unbilled receivings from this vendor in this period.</div>
        @else
        <table class="table table-bordered table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Category</th><th width="7%">SKUs</th><th width="9%">Qty</th><th width="11%">Rate / pc *</th><th width="12%">Amount</th>
              <th width="22%">Fabric used <small class="text-muted">(optional)</small></th><th width="10%">Meters / pc</th>
            </tr>
          </thead>
          <tbody>
          @foreach($groups as $i => $g)
            <tr class="cat-row" data-qty="{{ (float) $g->qty }}">
              <td>
                {{ $g->name }} <small class="text-muted">({{ $g->grns }} GRN)</small>
                <input type="hidden" name="lines[{{ $i }}][category_id]" value="{{ $g->category_id }}">
              </td>
              <td>{{ $g->sku_count }}</td>
              <td>{{ (float) $g->qty }}</td>
              <td><input type="number" step="0.01" min="0.01" name="lines[{{ $i }}][rate]" class="form-control form-control-sm rate" value="{{ old("lines.$i.rate", $g->rate) }}" required></td>
              <td class="text-end amount">0.00</td>
              <td>
                <select name="lines[{{ $i }}][fabric_choice]" class="form-control form-control-sm">
                  <option value="">— none yet —</option>
                  @foreach($fabrics as $f)<option value="{{ $f->value }}" {{ old("lines.$i.fabric_choice") === $f->value ? 'selected' : '' }}>{{ $f->label }}</option>@endforeach
                </select>
              </td>
              <td><input type="number" step="0.001" min="0" name="lines[{{ $i }}][meters]" class="form-control form-control-sm" value="{{ old("lines.$i.meters") }}" placeholder="0"></td>
            </tr>
          @endforeach
          </tbody>
          <tfoot class="table-light">
            <tr><th class="text-end">Total</th><th>{{ $groups->sum('sku_count') }}</th><th>{{ (float) $total }}</th><th></th><th class="text-end" id="grand">0.00</th><th colspan="2"></th></tr>
          </tfoot>
        </table>
        <div class="row"><div class="col-md-6"><label>Remarks</label><input type="text" name="remarks" class="form-control" maxlength="500" value="{{ old('remarks') }}"></div></div>
        @endif
      </div>
      @if($groups->isNotEmpty())
      <footer class="card-footer text-end">
        <a href="{{ route('cmt_bills.index') }}" class="btn btn-danger">Discard</a>
        <button type="submit" class="btn btn-primary" onclick="return confirm('Post this CMT Bill? The vendor will be credited.')"><i class="fas fa-save me-1"></i> Save &amp; Post</button>
      </footer>
      @endif
    </section>
  </form>
  @endif
</div></div>

<script>
  $(function () {
    $('.select2-js').select2({ width: '100%' });
    function recalc() {
      let g = 0;
      $('.cat-row').each(function () {
        const a = (parseFloat($(this).data('qty')) || 0) * (parseFloat($(this).find('.rate').val()) || 0);
        $(this).find('.amount').text(a.toFixed(2)); g += a;
      });
      $('#grand').text(g.toFixed(2));
    }
    $(document).on('input', '.rate', recalc);
    recalc();
  });
</script>
@endsection
