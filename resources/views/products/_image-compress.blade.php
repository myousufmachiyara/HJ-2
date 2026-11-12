{{--
  Product pictures are shrunk in the browser right before the form is sent.
  Phone photos are 2–5 MB each; over a normal connection that upload (not the server) was most of the
  time "Create / Update product" took. Pictures are scaled to max 1600 px (sharp enough for the shop
  and for Shopify) and saved as JPEG ~82% — typically 10–15x smaller. A picture that cannot be read
  or would not get smaller is sent as it is. The Save button is also locked after one click, so a
  slow save can no longer create the product twice.
--}}
<script>
(function () {
  var MAX = 1600, QUALITY = 0.82, MIN_BYTES = 400 * 1024, OK = /^image\/(jpeg|png|webp)$/;

  function shrink(file) {
    return new Promise(function (resolve) {
      if (!OK.test(file.type) || file.size < MIN_BYTES) return resolve(file);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var s = Math.min(1, MAX / Math.max(img.naturalWidth, img.naturalHeight));
        var w = Math.max(1, Math.round(img.naturalWidth * s)), h = Math.max(1, Math.round(img.naturalHeight * s));
        var c = document.createElement('canvas'); c.width = w; c.height = h;
        var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); g.drawImage(img, 0, 0, w, h);
        c.toBlob(function (b) {
          if (!b || b.size >= file.size) return resolve(file);
          resolve(new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('imageUpload');
    if (!input || !input.form) return;
    var form = input.form, busy = false;

    form.addEventListener('submit', function (e) {
      if (busy) { e.preventDefault(); return; }          // second click while saving → ignored
      busy = true;
      var btn = form.querySelector('footer button[type="submit"], button[type="submit"]');
      var label = btn ? btn.innerHTML : '';
      var files = Array.prototype.slice.call(input.files || []);
      var big = files.some(function (f) { return OK.test(f.type) && f.size >= MIN_BYTES; });

      if (!big) {                                         // nothing to shrink: normal submit
        if (btn) { btn.disabled = true; btn.innerHTML = 'Saving…'; }
        return;
      }
      e.preventDefault();
      if (btn) { btn.disabled = true; btn.innerHTML = 'Preparing pictures…'; }
      Promise.all(files.map(shrink)).then(function (out) {
        try {
          var dt = new DataTransfer();
          out.forEach(function (f) { dt.items.add(f); });
          input.files = dt.files;
        } catch (err) { /* browser cannot replace the files: send the originals */ }
        if (btn) btn.innerHTML = 'Saving…';
        form.submit();
      }).catch(function () { form.submit(); });
    });
  });
})();
</script>
