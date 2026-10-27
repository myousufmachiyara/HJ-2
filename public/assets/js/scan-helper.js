/*
 * Barcode scanning helpers, used on every form with an item-code box
 * (sale invoice, returns, purchases, stock adjustment / transfer, CMT receiving / return, POS).
 *
 * 1. Scanners that do NOT send Enter: a burst of fast keystrokes (a scanner types a
 *    code in a few milliseconds, people cannot) followed by a short pause counts as a
 *    finished scan and presses Enter for it. Typing by hand is not affected.
 * 2. HJScan.nextLine(): after a good scan, put the cursor in an empty line's code box
 *    (adding a line when needed) so the next item can be scanned straight away.
 */
(function () {
  var SELECTOR = '.product-code, [data-scan]';
  var FAST_MS  = 45;    // max gap between scanner keystrokes
  var MIN_FAST = 4;     // fast keystrokes in a row before it counts as a scanner
  var IDLE_MS  = 150;   // quiet time after the last keystroke = scan finished
  var state = new WeakMap();

  function isScanBox(el) {
    return el && el.matches && el.matches(SELECTOR);
  }

  function pressEnter(el) {
    if (!document.body.contains(el) || !String(el.value || '').trim()) return;
    var ev = new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true, cancelable: true });
    // jQuery handlers read e.which / e.keyCode
    try {
      Object.defineProperty(ev, 'keyCode', { get: function () { return 13; } });
      Object.defineProperty(ev, 'which',   { get: function () { return 13; } });
    } catch (e) { /* older browsers */ }
    ev.hjAutoEnter = true;
    el.dispatchEvent(ev);
  }

  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if (!isScanBox(el) || e.hjAutoEnter) return;
    var s = state.get(el) || { last: 0, fast: 0, timer: null };

    if (e.key === 'Enter') {            // the scanner sent Enter itself
      clearTimeout(s.timer); s.fast = 0; s.last = 0;
      state.set(el, s);
      return;
    }
    if (!e.key || e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return;

    var now = (window.performance && performance.now) ? performance.now() : Date.now();
    s.fast = (s.last && now - s.last <= FAST_MS) ? s.fast + 1 : 0;
    s.last = now;
    clearTimeout(s.timer);
    if (s.fast + 1 >= MIN_FAST) {
      s.timer = setTimeout(function () { s.fast = 0; s.last = 0; pressEnter(el); }, IDLE_MS);
    }
    state.set(el, s);
  }, true);

  window.HJScan = {
    /** Row of the same item (product + variation), other than exceptRow. */
    findLine: function (tbody, productId, variationId, exceptRow) {
      var hit = null;
      jQuery(tbody).find('tr').each(function () {
        var $r = jQuery(this);
        if (exceptRow && $r.is(exceptRow)) return;
        if (String($r.find('.product-select').val() || '') === String(productId) &&
            String($r.find('.variation-select').val() || '') === String(variationId || '')) { hit = $r; return false; }
      });
      return hit;
    },

    /** +1 on a line's quantity (scan of an item that is already on the form). */
    bump: function ($row, qtySelector) {
      var $q = $row.find(qtySelector || '.quantity').first();
      $q.val((parseFloat($q.val()) || 0) + 1).trigger('input').trigger('change');
      $row.addClass('table-success');
      setTimeout(function () { $row.removeClass('table-success'); }, 600);
    },

    /** Cursor to an empty line's code box; addRow() is called when the last line is used. */
    nextLine: function (tbody, addRow) {
      var $last = jQuery(tbody).find('tr').last();
      if (!$last.length || $last.find('.product-select').val()) addRow();
      var $row = jQuery(tbody).find('tr').last();
      var box = $row.find('.product-code').val('')[0];
      if (box) setTimeout(function () { box.focus(); }, 0);
      return $row;
    }
  };
})();
