/* ══════════════════════════════════════════════════════════════════════════════
   "SEEN BY" · partials/views-badge.twig · Services\PageViews
   ══════════════════════════════════════════════════════════════════════════════

   1 · An ENGAGED view. The page counts as seen once it has been VISIBLE for
       data-pv-after seconds (a background tab's time does not count, and leaving resets
       nothing: the seconds add up). Then one beacon, once; the server decides whether it
       is a person it has not counted today. A bounce, a hover prefetch, a crawler and a
       page with no script are never counted: the number understates, never overstates.

   2 · The chip's panel closes on Escape and on a tap anywhere else, as every menu does.

   Page-wide listeners and timers go through agPage, which site-nav.js undoes when the page
   is swapped away (SiteNavTest). The CSRF token is read when the beacon is sent, never
   copied at load (csrf-fresh.js keeps every data-csrf current).
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var pg = window.agPage || { on: function (t, y, f, o) { t.addEventListener(y, f, o); }, every: function (f, ms) { return setInterval(f, ms); }, leave: function () {} };

  var mark = document.querySelector('[data-pv]');
  if (mark && window.fetch) {
    var need = (parseFloat(mark.getAttribute('data-pv-after') || '5') || 5) * 1000;
    var shown = 0, since = document.visibilityState === 'visible' ? Date.now() : 0, sent = false;
    var check = function () {
      if (sent) return;
      var now = Date.now();
      if (since) { shown += now - since; since = now; }
      if (shown < need) return;
      sent = true;
      fetch('/views', {
        method: 'POST', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': mark.getAttribute('data-csrf') || '', 'Accept': 'application/json' },
        body: 't=' + encodeURIComponent(mark.getAttribute('data-pv') || '')
      }).catch(function () {});
    };
    pg.on(document, 'visibilitychange', function () {
      if (document.visibilityState === 'visible') { since = Date.now(); }
      else { check(); since = 0; }
    });
    pg.every(check, 1000);
  }

  function closeAll(except) {
    var open = document.querySelectorAll('.ag-views[open]');
    for (var i = 0; i < open.length; i++) if (open[i] !== except) open[i].removeAttribute('open');
  }
  pg.on(document, 'click', function (e) {
    closeAll(e.target && e.target.closest ? e.target.closest('.ag-views') : null);
  });
  pg.on(document, 'keydown', function (e) {
    if (e.key !== 'Escape') return;
    var o = document.querySelector('.ag-views[open]');
    if (o) { o.removeAttribute('open'); var s = o.querySelector('summary'); if (s) s.focus(); }
  });
})();
