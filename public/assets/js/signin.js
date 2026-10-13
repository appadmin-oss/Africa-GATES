/* ══════════════════════════════════════════════════════════════════════════════
   SIGN IN · JOIN — the enhancements over forms that already work (Phase 8)
   layout/auth.twig · design/SignIn.dc.html
   ══════════════════════════════════════════════════════════════════════════════

   Every screen here posts and works with this file blocked. What it adds:

   1. THE PHONE · EMAIL SWITCH IN PLACE. The two options are links to the page with the
      other tab; here they become a radio group (arrow keys move and select, as the ARIA
      pattern says) that swaps the panel without a round trip and keeps the URL in step, so
      Back and a refresh land on the tab that was showing.

   2. THE SIX BOXES. One real input takes the code (`autocomplete="one-time-code"`
      autofills ONE field). This paints the six boxes the DC draws, lays the real field
      over them, and only THEN marks the code `data-painted` — the stylesheet hides the
      real field only under that mark, so a script that never ran leaves the real field
      visible rather than six empty boxes over an invisible one.

   3. THE RESEND CLOCK. "Resend in 0:42" counts down from the seconds the server computed
      off the live code, and at zero shows the button the server will now honour. It never
      decides anything: the controller refuses a resend inside the window regardless.

   4. "CONTINUE WITH 2 AREAS". The interests button says how many are chosen (the DC's
      `finishLabel`), with the singular for one.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* 1 ── the switch ─────────────────────────────────────────────────────────── */
  function initVia() {
    var group = document.querySelector('[data-si-via]');
    if (!group) return;
    var tabs = Array.prototype.slice.call(group.querySelectorAll('[data-si-tab]'));

    function pick(tab, focus) {
      var which = tab.getAttribute('data-si-tab');
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-checked', on ? 'true' : 'false');
        t.setAttribute('tabindex', on ? '0' : '-1');
      });
      Array.prototype.forEach.call(document.querySelectorAll('[data-si-panel]'), function (p) {
        p.hidden = p.getAttribute('data-si-panel') !== which;
      });
      try { history.replaceState(null, '', tab.getAttribute('href')); } catch (e) { /* a sandboxed frame */ }
      if (focus) tab.focus();
    }

    tabs.forEach(function (t, i) {
      t.addEventListener('click', function (e) { e.preventDefault(); pick(t, false); });
      t.addEventListener('keydown', function (e) {
        var k = e.key, n = tabs.length, j = -1;
        var rtl = document.documentElement.dir === 'rtl';
        if (k === 'ArrowRight' || k === 'ArrowDown') j = (i + (rtl && k === 'ArrowRight' ? -1 : 1) + n) % n;
        if (k === 'ArrowLeft' || k === 'ArrowUp') j = (i + (rtl && k === 'ArrowLeft' ? 1 : -1) + n) % n;
        if (k === ' ' || k === 'Enter') { e.preventDefault(); pick(t, false); return; }
        if (j >= 0) { e.preventDefault(); pick(tabs[j], true); }
      });
    });
  }

  /* 2 ── the boxes ──────────────────────────────────────────────────────────── */
  function initCode() {
    var wrap = document.querySelector('[data-si-code]');
    if (!wrap) return;
    var real = wrap.querySelector('.si-code__real');
    var boxes = wrap.querySelectorAll('.si-code__box');
    if (!real || boxes.length !== 6) return;

    function paint() {
      /* The field keeps only digits and at most six — the same rule its `pattern` and
         `maxlength` state, so the boxes can never show something the field would not send. */
      var v = (real.value || '').replace(/\D/g, '').slice(0, 6);
      if (v !== real.value) real.value = v;
      for (var i = 0; i < 6; i++) {
        boxes[i].textContent = v.charAt(i) || '';
        if (i === Math.min(v.length, 5)) boxes[i].setAttribute('data-next', '');
        else boxes[i].removeAttribute('data-next');
      }
    }
    real.addEventListener('input', paint);
    real.addEventListener('focus', function () { wrap.setAttribute('data-focus', ''); });
    real.addEventListener('blur', function () { wrap.removeAttribute('data-focus'); });
    paint();
    wrap.setAttribute('data-painted', '');
    if (document.activeElement === real) wrap.setAttribute('data-focus', '');
  }

  /* 3 ── the clock ──────────────────────────────────────────────────────────── */
  function initWait() {
    var wait = document.querySelector('[data-si-wait]');
    var btn = document.querySelector('[data-si-resend]');
    if (!wait) return;
    var left = parseInt(wait.getAttribute('data-si-wait') || '0', 10);
    /* The sentence is the server's, translated; only the m:ss inside it moves. */
    var tpl = wait.textContent;
    var shown = tpl.match(/\d+:\d{2}/);
    function fmt(s) { return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
    var t = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(t);
        wait.textContent = wait.getAttribute('data-si-done') || tpl.replace(/\s*Resend in\s*\d+:\d{2},?\s*or\s*$/, '').trim() || tpl;
        if (btn) btn.hidden = false;
        return;
      }
      if (shown) wait.textContent = tpl.replace(shown[0], fmt(left));
    }, 1000);
  }

  /* 4 ── the interests count ────────────────────────────────────────────────── */
  function initInterests() {
    var form = document.querySelector('[data-si-interests]');
    if (!form) return;
    var go = form.querySelector('[data-si-finish]');
    if (!go) return;
    var none = go.getAttribute('data-none'), one = go.getAttribute('data-one'), many = go.getAttribute('data-many');
    function count() {
      var n = form.querySelectorAll('input[type=checkbox]:checked').length;
      go.textContent = n === 0 ? none : (n === 1 ? one : many.replace('%n%', String(n)));
    }
    form.addEventListener('change', count);
    count();
  }

  function init() { initVia(); initCode(); initWait(); initInterests(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
