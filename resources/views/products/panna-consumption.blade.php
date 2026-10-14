@extends('layouts.app')

@section('title', 'Products | PANNA Consumption')

@section('content')
<div class="row">
  <div class="col">
    <form method="POST" action="{{ route('panna-consumption.store') }}">
      @csrf
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title mb-0"><i class="fas fa-scroll me-1"></i> PANNA Consumption by Category</h2>
          @can('products.edit')<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>@endcan
        </header>
        <div class="card-body">
          @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
          @if($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif

          <p class="text-muted mb-3">
            Meters of fabric used for <b>one piece</b> of each product category when cut from each PANNA.
            When finished goods are received from the CMT, the vendor tells which fabric &amp; PANNA was used and
            <b>pieces × this consumption</b> is deducted from the fabric at that CMT. Leave a box empty if that
            category is never made from that PANNA.
          </p>

          @if($pannas->isEmpty())
            <div class="alert alert-warning">No PANNA values yet — add them under <a href="{{ route('attributes.index') }}">Products → Attributes → PANNA</a>.</div>
          @else
            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="min-width:200px">Category</th>
                    @foreach($pannas as $p)<th class="text-center" style="min-width:110px">PANNA {{ $p->value }}</th>@endforeach
                  </tr>
                </thead>
                <tbody>
                  @foreach($categories as $cat)
                    <tr>
                      <td class="fw-600">{{ $cat->name }} <small class="text-muted">{{ $cat->code }}</small></td>
                      @foreach($pannas as $p)
                        @php $v = old("cons.{$cat->id}.{$p->id}", $matrix[$cat->id . '-' . $p->id] ?? null); @endphp
                        <td><input type="number" step="any" min="0" class="form-control form-control-sm text-end"
                                   name="cons[{{ $cat->id }}][{{ $p->id }}]" value="{{ $v !== null ? $v + 0 : '' }}" placeholder="—"></td>
                      @endforeach
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            <h2 class="card-title mt-4 mb-1">Size-wise exceptions <small class="text-muted">(optional)</small></h2>
            <p class="text-muted small mb-2">Only where one size / age uses a different amount than the category above (e.g. 3pc Kurta Trouser, PANNA 44", size XL = 3.10).</p>
            <table class="table table-bordered table-sm" id="sizeRules">
              <thead class="table-light"><tr><th>Category</th><th width="16%">PANNA</th><th width="22%">Size / Age</th><th width="14%">Consumption / pc</th><th width="5%"></th></tr></thead>
              <tbody>
                @php $lines = old('sizes', $bySize->map(fn ($r) => ['category_id' => $r->category_id, 'panna_value_id' => $r->panna_value_id, 'size_value_id' => $r->size_value_id, 'consumption' => $r->consumption + 0])->all()); @endphp
                @foreach($lines as $i => $l)
                  @include('products.panna-consumption-row', ['i' => $i, 'l' => $l])
                @endforeach
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-success btn-sm" id="addSizeRule"><i class="fas fa-plus"></i> Add size exception</button>
          @endif
        </div>
        <footer class="card-footer text-end">
          @can('products.edit')<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>@endcan
        </footer>
      </section>
    </form>
  </div>
</div>

<template id="sizeRuleTpl">
  @include('products.panna-consumption-row', ['i' => '__i__', 'l' => []])
</template>

<script>
  $(function () {
    let idx = {{ count($lines ?? []) }};
    $('#addSizeRule').on('click', function () {
      $('#sizeRules tbody').append($('#sizeRuleTpl').html().replaceAll('__i__', idx++));
    });
    $(document).on('click', '.rule-remove', function () { $(this).closest('tr').remove(); });
  });
</script>
@endsection
