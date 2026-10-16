/* ══════════════════════════════════════════════════════════════════════════════
   AWARDS AND VOTING — the two small behaviours the Phase 5 pages share.
   ══════════════════════════════════════════════════════════════════════════════

   ── AWARD COUNTDOWN ─────────────────────────────────────────────────────────
   Decrements a server-supplied remaining-seconds value (`data-tick-left`). It never
   reads the system clock: a phone whose clock is wrong — common on cheap Android
   after a flat battery — would otherwise be shown a confident wrong answer about
   when voting closes or nominations open (VoteCountdownTest holds both tickers to
   this). One interval for every clock on the page, stopping at zero.

   ── EDITION PICKER ──────────────────────────────────────────────────────────
   The edition <select> is a GET form with a Show button, so it works with no
   script. With script, changing it submits and the button is hidden.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var pad = function (n) { return (n < 10 ? '0' : '') + n; };

  function clocks() {
    var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-tick][data-tick-left]'));
    if (!nodes.length) return;
    var list = nodes.map(function (el) {
      return {
        left: Math.max(0, parseInt(el.getAttribute('data-tick-left'), 10) || 0),
        d: el.querySelector('[data-tick-d]'), h: el.querySelector('[data-tick-h]'),
        m: el.querySelector('[data-tick-m]'), s: el.querySelector('[data-tick-s]')
      };
    });
    function paint(c) {
      var t = c.left;
      if (c.d) c.d.textContent = pad(Math.floor(t / 86400));
      if (c.h) c.h.textContent = pad(Math.floor((t % 86400) / 3600));
      if (c.m) c.m.textContent = pad(Math.floor((t % 3600) / 60));
      if (c.s) c.s.textContent = pad(t % 60);
    }
    var timer = setInterval(function () {
      var live = 0;
      list.forEach(function (c) { if (c.left > 0) { c.left--; paint(c); if (c.left > 0) live++; } });
      if (!live) clearInterval(timer);
    }, 1000);
  }

  function pickers() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-aw-autosubmit]'), function (f) {
      var go = f.querySelector('[data-aw-nojs]');
      if (go) go.hidden = true;
      var sel = f.querySelector('select');
      if (sel) sel.addEventListener('change', function () { f.submit(); });
    });
  }

  function boot() { clocks(); pickers(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
