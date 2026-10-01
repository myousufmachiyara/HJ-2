@extends('layouts.app')

@section('title', 'Products | Create')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
      @csrf
      @if ($errors->any())
        <div class="alert alert-danger">
          <ul class="mb-0">
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">New Product</h2>
        </header>
        <div class="card-body">
          @include('products._main-fields')

          <div class="row" id="imagesBlock">
            <div class="col-md-6 mt-3">
              <label>Product Images</label>
              <input type="file" name="prod_att[]" multiple class="form-control" id="imageUpload">
              <div id="previewContainer" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;"></div>
            </div>
          </div>

          <div id="genericVariations">
          {{-- Attribute Selection --}}
          <div class="row mt-4">
            <div class="col-md-12">
              <h2 class="card-title">Product Variations</h2>
              <small class="text-muted d-block mb-2">Finished goods: generate size / age variations. (For a fabric choose Product Type = Raw / Fabric — the PANNA &amp; Articles section appears instead.)</small>
              <div class="row">
                @foreach($attributes->where('slug', '!=', 'panna') as $attribute)
                  <div class="col-md-6">
                    <label>{{ $attribute->name }}</label>
                    <select name="attributes[{{ $attribute->id }}][]" multiple class="form-control select2-js variation-select" data-attribute="{{ $attribute->id }}">
                      @foreach($attribute->values as $value)
                        <option value="{{ $value->id }}">{{ $value->value }}</option>
                      @endforeach
                    </select>
                  </div>
                @endforeach
              </div>
            </div>
          </div>

          <div class="col-md-12 mt-4">
            <button type="button" class="btn btn-success mb-3" id="generateVariationsBtn">
              <i class="fa fa-plus"></i> Generate Variations
            </button>
            <div class="table-responsive">
              <table class="table table-bordered" id="variationsTable">
                <thead>
                  <tr>
                    <th>Variation</th>
                    <th>Stock</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          </div>

          @include('products._fabric-section')
        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('products.index') }}" class="btn btn-danger">Cancel</a>
          <button type="submit" class="btn btn-primary">Create Product</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
$(document).ready(function () {
  $('.select2-js').select2();

  $('#generateVariationsBtn').click(function () {
    let attributes = {!! $attributes->toJson() !!};
    let selectedMap = {};

    attributes.forEach(attr => {
      let selected = $(`select[name="attributes[${attr.id}][]"]`).val();
      if (selected && selected.length > 0) {
        selectedMap[attr.name] = selected.map(valId => {
          let text = $(`select[name="attributes[${attr.id}][]"] option[value="${valId}"]`).text();
          return { id: valId, text: text };
        });
      }
    });

    let combos = buildCombinations(Object.entries(selectedMap));
    let tbody = $('#variationsTable tbody');
    tbody.empty();
    let mainSku = $('#sku').val() || $('#sku').attr('data-preview') || '';

    combos.forEach((combo, index) => {
      let label = combo.map(c => c.text).join('-');
      let inputs = combo.map((c, i) => `
        <input type="hidden" name="variations[${index}][attributes][${i}][attribute_value_id]" value="${c.id}">
      `).join('');

      tbody.append(`
        <tr>
          <td>${label}${inputs}</td>
          <td><input type="number" name="variations[${index}][stock_quantity]" step="any" class="form-control" value="0"></td>
          <td><input type="text" name="variations[${index}][sku]" class="form-control" value="${mainSku}-${label}"></td>
          <td><input type="text" name="variations[${index}][barcode]" class="form-control" placeholder="Manual barcode"></td>
          <td><button type="button" class="btn btn-sm btn-danger remove-variation">X</button></td>
        </tr>
      `);
    });
  });

  $(document).on('click', '.remove-variation', function () {
    $(this).closest('tr').remove();
  });

  function buildCombinations(arr, index = 0) {
    if (index === arr.length) return [[]];
    let [key, values] = arr[index];
    let rest = buildCombinations(arr, index + 1);
    return values.flatMap(v => rest.map(r => [v, ...r]));
  }

  $('select[name="category_id"]').on('change', function () {
    let categoryId = $(this).val();
    if (categoryId) {
      $.get("{{ route('products.next-sku', ':id') }}".replace(':id', categoryId), function (res) {
        $('#sku').attr('placeholder', 'Auto: ' + res.sku).attr('data-preview', res.sku);
      });
    }
    let subCategorySelect = $('#subcategory_id');
    subCategorySelect.empty().append('<option value="">Loading...</option>');
    if (categoryId) {
      $.ajax({
        url: "{{ route('products.getSubcategories', ':id') }}".replace(':id', categoryId),
        type: "GET",
        success: function (data) {
          subCategorySelect.empty().append('<option value="">Select Sub Category</option>');
          $.each(data, function (key, subcat) {
            subCategorySelect.append(`<option value="${subcat.id}">${subcat.name}</option>`);
          });
        }
      });
    } else {
      subCategorySelect.empty().append('<option value="">Select Sub Category</option>');
    }
  });

  document.getElementById("imageUpload").addEventListener("change", function(event) {
    const files = event.target.files;
    const previewContainer = document.getElementById("previewContainer");
    Array.from(files).forEach((file) => {
      if (file && file.type.startsWith("image/")) {
        const reader = new FileReader();
        reader.onload = function(e) {
          const wrapper = document.createElement("div");
          wrapper.style.position = "relative";
          wrapper.style.display = "inline-block";
          const img = document.createElement("img");
          img.src = e.target.result;
          img.style.maxWidth = "150px"; img.style.maxHeight = "150px";
          img.style.border = "1px solid #ddd"; img.style.borderRadius = "5px"; img.style.padding = "5px";
          const removeBtn = document.createElement("span");
          removeBtn.innerHTML = "&times;";
          removeBtn.style.cssText = "position:absolute;top:2px;right:6px;cursor:pointer;color:red;font-size:20px;font-weight:bold;";
          removeBtn.addEventListener("click", function() {
            wrapper.remove();
            if (previewContainer.children.length === 0) document.getElementById("imageUpload").value = "";
          });
          wrapper.appendChild(img);
          wrapper.appendChild(removeBtn);
          previewContainer.appendChild(wrapper);
        };
        reader.readAsDataURL(file);
      }
    });
  });
});
</script>
@endsection