{{--
  Grouped stock-location dropdown (Warehouse / Customer-Marketplace / Vendor-CMT).
  @include('partials.location-select', ['name' => 'location_id', 'groups' => $locationGroups, 'selected' => $id, 'id' => 'location_id', 'only' => ['warehouse','vendor']])
--}}
@php
  $only     = $only ?? null;
  $selected = (string) ($selected ?? '');
@endphp
<select name="{{ $name }}" id="{{ $id ?? $name }}" class="form-control select2-js {{ $class ?? '' }}" {{ ($required ?? true) ? 'required' : '' }}>
  <option value="">{{ $placeholder ?? 'Select Location' }}</option>
  @foreach($groups as $groupLabel => $locs)
    @php $locs = $only ? $locs->filter(fn($l) => in_array($l->type, $only)) : $locs; @endphp
    @if($locs->count())
      <optgroup label="{{ $groupLabel }}">
        @foreach($locs as $loc)
          <option value="{{ $loc->id }}" data-type="{{ $loc->type }}" data-account="{{ $loc->chart_of_account_id }}"
                  {{ $selected === (string) $loc->id ? 'selected' : '' }}>
            {{ $loc->name }}{{ $loc->is_default ? ' (Default)' : '' }}
          </option>
        @endforeach
      </optgroup>
    @endif
  @endforeach
</select>
