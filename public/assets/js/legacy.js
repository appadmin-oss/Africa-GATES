/* ══════════════════════════════════════════════════════════════════════════════
   LEGACY VAULT — the two behaviours the pages add (Phase 6 · §8.18).
   · A region checkbox, the region select or the sort select submits its GET form on change,
     so the filters behave like the year links beside them. Without this script every such
     form keeps a visible button and posts the same way.
   · "Show all N winners" reveals the rest of an edition's category winners in place. The
     list is all in the markup; without script the extra rows stay reachable through "The
     full result" link below it.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-lv-form]'), function (f) {
    f.setAttribute('data-lv-js', '');
    Array.prototype.forEach.call(f.querySelectorAll('[data-lv-auto]'), function (el) {
      el.addEventListener('change', function () {
        if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
      });
    });
  });

  var all = document.querySelector('[data-lve-all]');
  if (all) {
    var list = document.getElementById(all.getAttribute('aria-controls'));
    all.hidden = false;
    all.setAttribute('aria-expanded', 'false');
    all.addEventListener('click', function () {
      if (!list) return;
      list.classList.add('is-all');
      all.setAttribute('aria-expanded', 'true');
      all.hidden = true;
      var next = list.querySelector('.lve-wins__xp a, .lve-wins__x a');
      if (next) next.focus();
    });
  }
})();
