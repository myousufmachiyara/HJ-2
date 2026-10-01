@extends('layouts.app')
@section('title', 'Fabric Articles | ' . $fabric->name)

@section('content')
@php
  $unit   = $fabric->measurementUnit->shortcode ?? 'm';
  $pannas = $fabric->variations;
  $lines  = old('rows', $rows->map(fn($r) => [
      'fabric_variation_id' => $r->fabric_variation_id, 'article_id' => $r->article_id,
      'article_variation_id' => $r->article_variation_id, 'consumption' => $r->consumption,
  ])->all());
@endphp
<div class="row">
  <div class="col">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-warning" style="white-space:pre-line">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <section class="card mb-3">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Articles made from {{ $fabric->name }} <small class="text-muted">({{ $fabric->sku }})</small></h2>
        <a href="{{ route('products.edit', $fabric->id) }}" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit"></i> Edit fabric / PANNA</a>
      </header>
      <div class="card-body">
        <p class="mb-2">For each <strong>PANNA</strong> of this fabric, list the articles (finished goods) made from it and how much fabric
          (<strong>{{ $unit }}</strong>) one piece uses. Use <em>All sizes</em> when every size uses the same, or add a line per size.
          FG Receiving uses this to deduct fabric from the CMT vendor, and the CMT Fabric report uses it to show what should remain.</p>
        @if($pannas->isEmpty())
          <div class="alert alert-warning mb-0">This fabric has no PANNA variations yet. If it comes in different widths, open
            <a href="{{ route('products.edit', $fabric->id) }}">Edit</a> and add variations using the <strong>PANNA</strong> attribute
            (add the PANNA values first under Products → Attributes). Otherwise the consumption below applies to the fabric as a whole.</div>
        @else
          <div>PANNA: @foreach($pannas as $p)<span class="badge bg-secondary me-1">{{ $p->sku }}</span>@endforeach</div>
        @endif
      </div>
    </section>

    <form method="POST" action="{{ route('products.fabric-articles.store', $fabric->id) }}">
      @csrf
      <section class="card">
        <header class="card-header d-flex justify-content-between align-items-center">
          <h2 class="card-title">Article Consumption</h2>
          <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadTemplate()"><i class="fas fa-file-download"></i> Template</button>
            <button type="button" class="btn btn-outline-success btn-sm" onclick="$('#importForm input[type=file]').click()"><i class="fas fa-file-upload"></i> Import Excel</button>
          </div>
        </header>
        <div class="card-body">
          <table class="table table-bordered table-sm">
            <thead class="table-light">
              <tr>
                @if($pannas->isNotEmpty())<th width="18%">PANNA</th>@endif
                <th>Article (finished good)</th><th width="22%">Size</th><th width="14%">Consumption ({{ $unit }}/pc)</th><th width="8%"></th>
              </tr>
            </thead>
            <tbody id="rowsBody">
              @foreach($lines as $i => $l)
                @include('products._fabric-article-row', ['i' => $i, 'l' => $l])
              @endforeach
            </tbody>
          </table>
          <button type="button" class="btn btn-success btn-sm" onclick="addRow()"><i class="fas fa-plus"></i> Add line</button>
        </div>
        <footer class="card-footer text-end">
          <a href="{{ route('products.index') }}" class="btn btn-secondary">Back</a>
          <button type="submit" class="btn btn-primary">Save</button>
        </footer>
      </section>
    </form>

    <form id="importForm" method="POST" action="{{ route('products.fabric-articles.import', $fabric->id) }}" enctype="multipart/form-data" style="display:none">
      @csrf
      <input type="file" name="file" accept=".xlsx,.xls,.csv" onchange="this.form.submit()">
    </form>
  </div>
</div>

<template id="rowTemplate">
  @include('products._fabric-article-row', ['i' => '__i__', 'l' => ['fabric_variation_id' => '', 'article_id' => '', 'article_variation_id' => '', 'consumption' => '']])
</template>

<script>
  const ARTICLE_SIZES = @json($articles->mapWithKeys(fn($a) => [$a->id => $a->variations->map(fn($v) => ['id' => $v->id, 'sku' => $v->sku])->values()]));
  let rowIndex = {{ count($lines) }};

  $(function () {
    $('#rowsBody select').select2({ width: '100%' });
    $(document).on('change', '.article-select', function () { fillSizes($(this).closest('tr'), null); });
    $(document).on('click', '.remove-row', function () { $(this).closest('tr').remove(); });
    $(document).on('click', '.copy-row', function () {
      const $src = $(this).closest('tr');
      const $row = addRow();
      $row.find('.panna-select').val($src.find('.panna-select').val()).trigger('change.select2');
      $row.find('.article-select').val($src.find('.article-select').val()).trigger('change.select2');
      fillSizes($row, null);
      $row.find('.consumption').val($src.find('.consumption').val());
    });
  });

  function fillSizes($row, selected) {
    const id = $row.find('.article-select').val();
    let opts = '<option value="">All sizes</option>';
    (ARTICLE_SIZES[id] || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
    $row.find('.size-select').html(opts).val(selected || '').trigger('change.select2');
  }

  function addRow() {
    const $row = $($('#rowTemplate').html().replaceAll('__i__', rowIndex++));
    $('#rowsBody').append($row);
    $row.find('select').select2({ width: '100%' });
    return $row;
  }

  function downloadTemplate() {
    const rows = [['PANNA', 'Article Code (product or variation SKU)', 'Consumption ({{ $unit }}/pc)']];
    @foreach($pannas->take(2) as $p)
      rows.push([@json($p->attributeValues->first()->value ?? $p->sku), 'ARTICLE-SKU-M', 2.5]);
    @endforeach
    @if($pannas->isEmpty()) rows.push(['', 'ARTICLE-SKU', 2.5]); @endif
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{ wch: 14 }, { wch: 38 }, { wch: 20 }];
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Articles');
    XLSX.writeFile(wb, 'fabric_articles_{{ $fabric->sku }}.xlsx');
  }
</script>
@endsection
