{{--
  Fabric (raw) setup inside the product form:
    1. PANNA list  → one variation per PANNA
    2. per PANNA: articles made from it + consumption per piece
    3. (edit) where this fabric is now, PANNA-wise
  Shown only when Item Type = Raw.
--}}
@php
  $pannaValues   = $pannaAttr?->values ?? collect();
  $selPannas     = collect(old('fabric_pannas', $selectedPannas ?? []))->map(fn($v) => (string) $v)->all();
  $articleLines  = old('fabric_articles', $fabricRows ?? []);
  if (empty($articleLines)) $articleLines = [['panna_value_id' => '', 'article_id' => '', 'article_variation_id' => '', 'consumption' => '']];
@endphp

<div id="fabricSection" class="mt-4" style="display:none">
  <input type="hidden" name="fabric_setup_present" value="1">
  <div class="border rounded p-3" style="border-color:#c9a227 !important">
    <h2 class="card-title mb-1"><i class="fas fa-scroll me-1"></i> Fabric: PANNA &amp; Articles</h2>
    <small class="text-muted d-block mb-3">
      1) Select the PANNA this fabric comes in — one variation is created per PANNA (e.g. {{ $sku ?? 'FAB-00001' }}-44).
      2) For each PANNA, list the articles made from it and the fabric used per piece. FG Receiving deducts
      pieces × consumption from the CMT that holds this PANNA.
    </small>

    <div class="row mb-3">
      <div class="col-md-8">
        <label>PANNA <span class="text-danger">*</span></label>
        @if($pannaValues->isEmpty())
          <div class="alert alert-warning py-2 mb-0">No PANNA values yet — add them under <a href="{{ route('attributes.index') }}" target="_blank">Products → Attributes → PANNA</a> (e.g. 36", 44", 58"), then reload this page.</div>
        @else
          <select name="fabric_pannas[]" id="fabricPannas" class="form-control select2-js" multiple>
            @foreach($pannaValues as $pv)
              <option value="{{ $pv->id }}" {{ in_array((string) $pv->id, $selPannas, true) ? 'selected' : '' }}>{{ $pv->value }}</option>
            @endforeach
          </select>
        @endif
      </div>
    </div>

    <label class="fw-bold">Articles made from this fabric (consumption per piece)</label>
    <table class="table table-bordered table-sm mb-2">
      <thead class="table-light">
        <tr><th width="16%">PANNA</th><th>Article (finished good)</th><th width="22%">Size</th><th width="14%">Consumption / pc</th><th width="9%"></th></tr>
      </thead>
      <tbody id="fabricArticleRows">
        @foreach($articleLines as $i => $l)
          @include('products._fabric-section-row', ['i' => $i, 'l' => $l])
        @endforeach
      </tbody>
    </table>
    <button type="button" class="btn btn-outline-success btn-sm" id="addFabricArticle"><i class="fas fa-plus"></i> Add article</button>
    @isset($product)
      <a href="{{ route('products.fabric-articles', $product->id) }}" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-file-excel"></i> Bulk / Excel import</a>
    @endisset

    @if(!empty($fabricStock) && count($fabricStock['rows']))
      <hr>
      <label class="fw-bold">Where this fabric is now (PANNA-wise)</label>
      <div class="table-responsive">
        <table class="table table-bordered table-sm mb-1">
          <thead class="table-light"><tr><th>PANNA</th>
            @foreach($fabricStock['locations'] as $loc)<th class="text-end">{{ $loc }}</th>@endforeach
            <th class="text-end">Total</th></tr></thead>
          <tbody>
            @foreach($fabricStock['rows'] as $r)
              <tr><td>{{ $r['panna'] }}</td>
                @foreach($fabricStock['locations'] as $locId => $loc)
                  @php $q = $r['qty'][$locId] ?? 0; @endphp
                  <td class="text-end {{ $q < 0 ? 'text-danger fw-bold' : '' }}">{{ $q ? number_format($q, 2) : '—' }}</td>
                @endforeach
                <td class="text-end fw-bold">{{ number_format(array_sum($r['qty']), 2) }}</td></tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <small class="text-muted">At a CMT = given − (pieces received × consumption). Details per vendor:
        <a href="{{ route('reports.purchase', ['tab' => 'CMT']) }}" target="_blank">Purchase reports → CMT Fabric Summary</a>.</small>
    @endif
  </div>
</div>

<template id="fabricArticleTemplate">
  @include('products._fabric-section-row', ['i' => '__i__', 'l' => ['panna_value_id' => '', 'article_id' => '', 'article_variation_id' => '', 'consumption' => '']])
</template>

<script>
  (function () {
    const SIZES = @json($fgArticles->mapWithKeys(fn($a) => [$a->id => $a->variations->map(fn($v) => ['id' => $v->id, 'sku' => $v->sku])->values()]));
    let idx = {{ count($articleLines) }};

    function isRaw() { return $('input[name="item_type"]:checked').val() === 'raw'; }

    function toggle() {
      const raw = isRaw();
      $('#fabricSection').toggle(raw);
      $('#fabricSection').find('input, select').prop('disabled', !raw);
      // the generic SIZE/AGE variation generator is for finished goods only (create form)
      const fg = $('input[name="item_type"]:checked').val() === 'fg';
      $('#genericVariations').toggle(fg).find('input, select, button').prop('disabled', !fg);
      if (raw) limitPannas();
    }

    // PANNA dropdowns on article lines offer only the PANNA selected above
    function limitPannas() {
      const chosen = ($('#fabricPannas').val() || []).map(String);
      $('#fabricArticleRows .fa-panna').each(function () {
        const cur = $(this).val();
        $(this).find('option').each(function () {
          if (!this.value) return;
          $(this).prop('disabled', chosen.length > 0 && !chosen.includes(this.value) && this.value !== cur);
        });
      });
    }

    function fillSizes($row) {
      const id = $row.find('.fa-article').val();
      let opts = '<option value="">All sizes</option>';
      (SIZES[id] || []).forEach(v => { opts += `<option value="${v.id}">${v.sku}</option>`; });
      $row.find('.fa-size').html(opts).trigger('change.select2');
    }

    $(function () {
      $('#fabricArticleRows select').select2({ width: '100%' });
      $(document).on('product-type-changed', toggle);
      $('#fabricPannas').on('change', limitPannas);
      $(document).on('change', '.fa-article', function () { fillSizes($(this).closest('tr')); });
      $(document).on('click', '.fs-remove-line', function () { $(this).closest('tr').remove(); });
      $(document).on('click', '.fs-copy-line', function () {
        const $src = $(this).closest('tr');
        const $row = $($('#fabricArticleTemplate').html().replaceAll('__i__', idx++));
        $src.after($row);
        $row.find('select').select2({ width: '100%' });
        $row.find('.fa-article').val($src.find('.fa-article').val()).trigger('change.select2');
        fillSizes($row);
        $row.find('.fa-consumption').val($src.find('.fa-consumption').val());
        limitPannas();
      });
      $('#addFabricArticle').on('click', function () {
        const $row = $($('#fabricArticleTemplate').html().replaceAll('__i__', idx++));
        $('#fabricArticleRows').append($row);
        $row.find('select').select2({ width: '100%' });
        limitPannas();
      });
      toggle();
    });
  })();
</script>
