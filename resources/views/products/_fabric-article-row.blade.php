<tr>
  @if($pannas->isNotEmpty())
    <td>
      <select name="rows[{{ $i }}][fabric_variation_id]" class="form-control panna-select" required>
        <option value="">Select</option>
        @foreach($pannas as $p)
          <option value="{{ $p->id }}" {{ (string) ($l['fabric_variation_id'] ?? '') === (string) $p->id ? 'selected' : '' }}>{{ $p->sku }}</option>
        @endforeach
      </select>
    </td>
  @endif
  <td>
    <select name="rows[{{ $i }}][article_id]" class="form-control article-select" required>
      <option value="">Select article</option>
      @foreach($articles as $a)
        <option value="{{ $a->id }}" {{ (string) ($l['article_id'] ?? '') === (string) $a->id ? 'selected' : '' }}>{{ $a->name }} ({{ $a->sku }})</option>
      @endforeach
    </select>
  </td>
  <td>
    <select name="rows[{{ $i }}][article_variation_id]" class="form-control size-select">
      <option value="">All sizes</option>
      @php $sizes = optional($articles->firstWhere('id', $l['article_id'] ?? null))->variations ?? collect(); @endphp
      @foreach($sizes as $v)
        <option value="{{ $v->id }}" {{ (string) ($l['article_variation_id'] ?? '') === (string) $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
      @endforeach
    </select>
  </td>
  <td><input type="number" name="rows[{{ $i }}][consumption]" class="form-control consumption" step="any" min="0" value="{{ $l['consumption'] ?? '' }}" required></td>
  <td class="text-nowrap">
    <button type="button" class="btn btn-outline-secondary btn-sm copy-row" title="Copy line"><i class="fas fa-copy"></i></button>
    <button type="button" class="btn btn-danger btn-sm remove-row"><i class="fas fa-times"></i></button>
  </td>
</tr>
