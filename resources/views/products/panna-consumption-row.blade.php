<tr>
  <td>
    <select name="sizes[{{ $i }}][category_id]" class="form-control form-control-sm">
      <option value="">Category</option>
      @foreach($categories as $c)<option value="{{ $c->id }}" {{ (string) ($l['category_id'] ?? '') === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
    </select>
  </td>
  <td>
    <select name="sizes[{{ $i }}][panna_value_id]" class="form-control form-control-sm">
      <option value="">PANNA</option>
      @foreach($pannas as $p)<option value="{{ $p->id }}" {{ (string) ($l['panna_value_id'] ?? '') === (string) $p->id ? 'selected' : '' }}>{{ $p->value }}</option>@endforeach
    </select>
  </td>
  <td>
    <select name="sizes[{{ $i }}][size_value_id]" class="form-control form-control-sm">
      <option value="">Size / Age</option>
      @foreach($sizes->groupBy(fn ($s) => $s->attribute->name ?? '') as $attr => $vals)
        <optgroup label="{{ $attr }}">
          @foreach($vals as $s)<option value="{{ $s->id }}" {{ (string) ($l['size_value_id'] ?? '') === (string) $s->id ? 'selected' : '' }}>{{ $s->value }}</option>@endforeach
        </optgroup>
      @endforeach
    </select>
  </td>
  <td><input type="number" step="any" min="0" name="sizes[{{ $i }}][consumption]" class="form-control form-control-sm text-end" value="{{ $l['consumption'] ?? '' }}"></td>
  <td class="text-center"><button type="button" class="btn btn-sm btn-danger rule-remove"><i class="fas fa-times"></i></button></td>
</tr>
