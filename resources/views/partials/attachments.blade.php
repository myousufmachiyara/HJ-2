{{--
  Attachments box for any form (invoice, return, receiving, voucher …).
    @include('partials.attachments', [
        'name'       => 'attachments[]',          // file input name
        'existing'   => [['id'=>1,'name'=>'bill.pdf','url'=>'…'], …],   // already saved (edit)
        'removeName' => 'remove_attachments[]',   // ticked-for-removal ids are posted here
    ])
  - picked files are listed with a × to drop one before saving (more can be added later)
  - saved files open in a new tab and can be marked "remove" (undo possible until Save)
--}}
@php
  $existing   = $existing ?? [];
  $removeName = $removeName ?? 'remove_attachments[]';
@endphp
<div class="att-box" data-remove-name="{{ $removeName }}">
  <label>{{ $label ?? 'Attachments' }}</label>
  <ul class="list-unstyled mb-1 small att-existing">
    @foreach($existing as $f)
      <li class="d-flex align-items-center gap-2 mb-1">
        <a href="{{ $f['url'] }}" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> {{ $f['name'] }}</a>
        <button type="button" class="btn btn-link btn-sm text-danger p-0 att-remove-existing" data-id="{{ $f['id'] }}">remove</button>
      </li>
    @endforeach
  </ul>
  <input type="file" class="form-control att-input" name="{{ $name ?? 'attachments[]' }}" multiple
         accept=".pdf,.jpg,.jpeg,.png,.webp,.zip,.xlsx,.xls,.csv,.doc,.docx">
  <ul class="list-unstyled small mt-1 mb-0 att-new"></ul>
  <small class="text-muted">PDF, images, Excel / Word, ZIP — up to 5 MB each.</small>
</div>

@once
<script>
(function () {
  // keep the picked files per input, so one can be dropped and more can be added
  function rebuild(input) {
    const dt = new DataTransfer();
    (input._files || []).forEach(f => dt.items.add(f));
    input.files = dt.files;
    const list = input.closest('.att-box').querySelector('.att-new');
    list.innerHTML = '';
    (input._files || []).forEach((f, i) => {
      const li = document.createElement('li');
      li.className = 'd-flex align-items-center gap-2';
      li.innerHTML = '<i class="fas fa-file"></i><span></span><small class="text-muted"></small>'
        + '<button type="button" class="btn btn-link btn-sm text-danger p-0 att-x" title="Remove this file">&times;</button>';
      li.querySelector('span').textContent = f.name;
      li.querySelector('small').textContent = '(' + Math.max(1, Math.round(f.size / 1024)) + ' KB)';
      li.querySelector('.att-x').dataset.i = i;
      list.appendChild(li);
    });
  }

  document.addEventListener('change', function (e) {
    const input = e.target;
    if (!input.classList || !input.classList.contains('att-input')) return;
    const have = input._files || [];
    const picked = Array.from(input.files || []);
    // a new pick ADDS to the earlier ones (skip exact duplicates)
    picked.forEach(f => { if (!have.some(h => h.name === f.name && h.size === f.size)) have.push(f); });
    input._files = have;
    rebuild(input);
  });

  document.addEventListener('click', function (e) {
    const x = e.target.closest('.att-x');
    if (x) {
      const input = x.closest('.att-box').querySelector('.att-input');
      input._files.splice(+x.dataset.i, 1);
      rebuild(input);
      return;
    }
    const rm = e.target.closest('.att-remove-existing');
    if (rm) {
      const box = rm.closest('.att-box');
      const li  = rm.closest('li');
      const hidden = li.querySelector('input[type=hidden]');
      if (hidden) {                       // undo
        hidden.remove();
        li.querySelector('a').style.textDecoration = '';
        rm.textContent = 'remove';
      } else {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = box.dataset.removeName; h.value = rm.dataset.id;
        li.appendChild(h);
        li.querySelector('a').style.textDecoration = 'line-through';
        rm.textContent = 'undo';
      }
    }
  });

  // for forms filled by JavaScript (e.g. the voucher edit popup)
  window.attSetExisting = function (box, files) {
    const ul = box.querySelector('.att-existing');
    ul.innerHTML = '';
    (files || []).forEach(f => {
      const li = document.createElement('li');
      li.className = 'd-flex align-items-center gap-2 mb-1';
      li.innerHTML = '<a target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <span></span></a>'
        + '<button type="button" class="btn btn-link btn-sm text-danger p-0 att-remove-existing">remove</button>';
      li.querySelector('a').href = f.url;
      li.querySelector('span').textContent = f.name;
      li.querySelector('button').dataset.id = f.id;
      ul.appendChild(li);
    });
    const input = box.querySelector('.att-input');
    input._files = []; rebuild(input);
  };
})();
</script>
@endonce
