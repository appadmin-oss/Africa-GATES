/* ══════════════════════════════════════════════════════════════════════════════
   THE WALLET — pages/account/wallet*.twig

   Four small things, each a convenience over a page that works without it:

   - the eye: hides every figure on the page (`wl--hide`), remembered on this device in
     `ag-hide-bal` — local storage when Preferences are allowed (`data-ag-keep`), this tab
     otherwise. It is a screen-glance convenience, never a privacy control.
   - life: while the page is visible it asks /account/wallet.json for anything newer than
     the moment it was drawn. Money or points that arrive say so in the toast, and the
     balance counts up to its new figure — at once under reduced motion.
   - the fee: on the withdraw form, the fee and what arrives are shown as the amount is
     typed, from the same bands the server charges (`data-wl-fees`, WalletFee::bands()).
     The review step's figure is the authority; this is a preview.
   - share: the receipt's one line, through the phone's own share sheet where there is one.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var KEY = 'ag-hide-bal';
  var root = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function store() {
    try { return root.getAttribute('data-ag-keep') === '1' ? window.localStorage : window.sessionStorage; }
    catch (e) { return null; }
  }
  function naira(k) {
    return '₦' + (k / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmt(v, unit) { return unit === 'pts' ? Math.round(v).toLocaleString('en-NG') + ' pts' : naira(v); }

  /* ── the eye ─────────────────────────────────────────────────────────────── */
  var wl = document.querySelector('[data-wl]');
  var eye = document.querySelector('[data-wl-eye]');
  if (wl && eye) {
    var label = eye.querySelector('.ag-sr');
    var set = function (hide) {
      wl.classList.toggle('wl--hide', hide);
      eye.setAttribute('aria-pressed', hide ? 'true' : 'false');
      if (label) label.textContent = hide ? 'Show balance' : 'Hide balance';
    };
    var s = store();
    try { set(!!(s && s.getItem(KEY) === '1')); } catch (e) { set(false); }
    eye.hidden = false;
    eye.addEventListener('click', function () {
      var hide = !wl.classList.contains('wl--hide');
      set(hide);
      try { if (s) { if (hide) s.setItem(KEY, '1'); else s.removeItem(KEY); } } catch (e) { /* nothing kept */ }
    });
  }

  /* ── count-up ────────────────────────────────────────────────────────────── */
  function countTo(el, to) {
    var unit = el.getAttribute('data-wl-unit') || 'naira';
    var from = parseInt(el.getAttribute('data-wl-amt'), 10) || 0;
    var v = el.querySelector('.wl-amt__v');
    el.setAttribute('data-wl-amt', String(to));
    if (!v) return;
    if (reduce || from === to) { v.textContent = fmt(to, unit); return; }
    var t0 = null, dur = 900;
    function step(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      v.textContent = fmt(from + (to - from) * e, unit);
      if (p < 1) window.requestAnimationFrame(step);
    }
    window.requestAnimationFrame(step);
  }

  /* ── life ────────────────────────────────────────────────────────────────── */
  if (wl && window.fetch) {
    var since = wl.getAttribute('data-wl-since') || '';
    var toast = document.querySelector('[data-wl-toast]');
    var main = document.querySelector('[data-wl-main] .wl-amt');
    var chip = document.querySelector('.wl-chip .wl-amt[data-wl-unit="pts"]');
    var busy = false;
    var poll = function () {
      if (busy || document.hidden) return;
      busy = true;
      fetch('/account/wallet.json?since=' + encodeURIComponent(since), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          busy = false;
          if (!d || !d.ok) return;
          since = d.now || since;
          if (main) countTo(main, d.available_kobo);
          if (chip && typeof d.points === 'number') countTo(chip, d.points);
          if (toast && d.credits && d.credits.length) {
            var c = d.credits[0];
            toast.textContent = '';
            var b = document.createElement('span');
            b.textContent = (d.credits.length > 1 ? d.credits.length + ' new: ' : '') + fmt(c.amount_kobo, c.unit) + ' — ' + c.title + ' (' + c.status_label + ')';
            var a = document.createElement('a');
            a.href = c.href; a.textContent = 'View';
            toast.appendChild(b); toast.appendChild(a);
            toast.hidden = false;
          }
        })
        .catch(function () { busy = false; });
    };
    window.setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }

  /* ── the fee, as the amount is typed ─────────────────────────────────────── */
  var form = document.querySelector('[data-wl-fees]');
  if (form) {
    var bands = [];
    try { bands = JSON.parse(form.getAttribute('data-wl-fees') || '[]'); } catch (e) { bands = []; }
    var max = parseInt(form.getAttribute('data-wl-max'), 10) || 0;
    var input = form.querySelector('[data-wl-amount]');
    var out = form.querySelector('[data-wl-fee]');
    var all = form.querySelector('[data-wl-all]');
    var feeFor = function (k) {
      var f = 0;
      for (var i = 0; i < bands.length; i++) if (k >= bands[i][0]) f = bands[i][1];
      return Math.min(f, k);
    };
    var show = function () {
      if (!out) return;
      var typed = input ? input.value.replace(/[₦,\s]/g, '') : '';
      var k = typed === '' ? max : Math.round(parseFloat(typed) * 100);
      if (!isFinite(k) || k <= 0) return;
      var f = feeFor(k);
      out.textContent = 'Fee ' + naira(f) + ' · you receive ' + naira(Math.max(0, k - f)) + (k > max ? ' — more than is available' : '');
    };
    if (input) input.addEventListener('input', show);
    if (all && input) {
      all.hidden = false;
      all.addEventListener('click', function () { input.value = (max / 100).toFixed(2); show(); input.focus(); });
    }
    show();
  }

  /* ── share a receipt ─────────────────────────────────────────────────────── */
  var share = document.querySelector('[data-wl-share]');
  if (share && navigator.share) {
    share.hidden = false;
    share.addEventListener('click', function () {
      navigator.share({ title: 'Africa GATES receipt', text: share.getAttribute('data-wl-share-text') || '' }).catch(function () {});
    });
  }
})();
