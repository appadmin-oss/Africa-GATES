/* ══════════════════════════════════════════════════════════════════════════════
   THE SEASONAL GREETING — partials/holiday-banner.twig · HOLIDAY-THEMES (5 Oct 2026)
   ══════════════════════════════════════════════════════════════════════════════

   An upgrade: without it the dismiss is a form that posts and comes back. With it, the
   post goes in the background (asking for JSON) and the banner and its line go without a
   reload; focus moves to the page's heading so it is not left on a button that no longer
   exists. A failed post leaves the banner where it is — the plain form still works.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var form = document.querySelector('[data-hb-dismiss]');
  if (!form || !window.fetch || !window.FormData) return;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
                         headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok) { form.submit(); return; }
        Array.prototype.forEach.call(document.querySelectorAll('[data-hb-part]'), function (el) { el.hidden = true; });
        var h = document.querySelector('.acx-title__h, h1');
        if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
      })
      .catch(function () { form.submit(); });
  });
})();
