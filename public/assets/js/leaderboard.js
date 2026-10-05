/* ══════════════════════════════════════════════════════════════════════════════
   LEADERBOARD — the one behaviour the page adds (Phase 6 · §8.19).
   Choosing a region submits its GET form, so the region select works like the chips beside
   it. Without this script the form keeps its visible "Show" button and posts the same way:
   nothing on the board depends on JavaScript.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var forms = document.querySelectorAll('.lb-region');
  Array.prototype.forEach.call(forms, function (f) {
    var sel = f.querySelector('[data-lb-submit]');
    if (!sel) return;
    f.setAttribute('data-js', '');
    sel.addEventListener('change', function () {
      if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
    });
  });
})();
