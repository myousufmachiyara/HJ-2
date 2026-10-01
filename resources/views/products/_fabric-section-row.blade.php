<tr>
  <td>
    <select name="fabric_articles[{{ $i }}][panna_value_id]" class="form-control fa-panna">
      <option value="">PANNA</option>
      @foreach(($pannaAttr?->values ?? collect()) as $pv)
        <option value="{{ $pv->id }}" {{ (string) ($l['panna_value_id'] ?? '') === (string) $pv->id ? 'selected' : '' }}>{{ $pv->value }}</option>
      @endforeach
    </select>
  </td>
  <td>
    <select name="fabric_articles[{{ $i }}][article_id]" class="form-control fa-article">
      <option value="">Select article</option>
      @foreach($fgArticles as $a)
        <option value="{{ $a->id }}" {{ (string) ($l['article_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->name }} ({{ $a->sku }})</option>
      @endforeach
    </select>
  </td>
  <td>
    <select name="fabric_articles[{{ $i }}][article_variation_id]" class="form-control fa-size">
      <option value="">All sizes</option>
      @foreach((optional($fgArticles->firstWhere('id', $l['article_id'] ?? null))->variations ?? collect()) as $v)
        <option value="{{ $v->id }}" {{ (string) ($l['article_variation_id'] ?? '') === (string) $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
      @endforeach
    </select>
  </td>
  <td><input type="number" step="any" min="0" name="fabric_articles[{{ $i }}][consumption]" class="form-control fa-consumption" value="{{ $l['consumption'] ?? '' }}"></td>
  <td class="text-nowrap">
    <button type="button" class="btn btn-outline-secondary btn-sm fs-copy-line" title="Copy line (e.g. for another PANNA or size)"><i class="fas fa-copy"></i></button>
    <button type="button" class="btn btn-danger btn-sm fs-remove-line"><i class="fas fa-times"></i></button>
  </td>
</tr>
