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
    // The Apply button is clipped away once the form submits itself (legacy.css). Clipped is
    // not gone: it stayed in the tab order as a 1px stop the keyboard landed on and nobody
    // could see, and a screen reader announced a button that does nothing new.
    Array.prototype.forEach.call(f.querySelectorAll('.lv-apply'), function (b) {
      b.setAttribute('tabindex', '-1');
      b.setAttribute('aria-hidden', 'true');
    });
    // Through the shared helper (a11y.js): keyboard-safe on a select, and focus comes back
    // to the control after the reload its change causes.
    Array.prototype.forEach.call(f.querySelectorAll('[data-lv-auto]'), function (el) {
      if (window.AGA11y && window.AGA11y.autoSubmit) { window.AGA11y.autoSubmit(el, f); return; }
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
