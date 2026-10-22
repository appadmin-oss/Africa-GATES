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
  /* Every [data-wl-eye] is the same control: the phone's app-bar icon and the desktop's
     labelled pill both toggle one state, and both say which way they will go next. */
  var wl = document.querySelector('[data-wl]');
  var eyes = Array.prototype.slice.call(document.querySelectorAll('[data-wl-eye]'));
  if (wl && eyes.length) {
    var s = store();
    var set = function (hide) {
      wl.classList.toggle('wl--hide', hide);
      eyes.forEach(function (eye) {
        eye.classList.toggle('wl--hide', hide);
        eye.setAttribute('aria-pressed', hide ? 'true' : 'false');
        var l = eye.querySelector('[data-wl-eye-l]');
        if (l) l.textContent = hide ? 'Show balance' : 'Hide balance';
      });
    };
    try { set(!!(s && s.getItem(KEY) === '1')); } catch (e) { set(false); }
    eyes.forEach(function (eye) {
      eye.hidden = false;
      eye.addEventListener('click', function () {
        var hide = !wl.classList.contains('wl--hide');
        set(hide);
        try { if (s) { if (hide) s.setItem(KEY, '1'); else s.removeItem(KEY); } } catch (e) { /* nothing kept */ }
      });
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
            var tt = toast.querySelector('[data-wl-toast-t]'), go = toast.querySelector('[data-wl-toast-go]');
            if (tt) tt.textContent = (d.credits.length > 1 ? d.credits.length + ' new: ' : '+') + fmt(c.amount_kobo, c.unit) + ' just arrived · ' + c.title;
            if (go) go.href = c.href;
            toast.hidden = false;
          }
        })
        .catch(function () { busy = false; });
    };
    var x = toast && toast.querySelector('[data-wl-toast-x]');
    if (x) x.addEventListener('click', function () { toast.hidden = true; });
    window.setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }

  /* ── the withdraw form: the fee and what arrives, as the amount is typed ─────
     The bands are the server's (WalletFee::bands()); the review step's figure is the
     authority and this is a preview. Changing pocket moves the ceiling, the All button,
     the quick amounts and whether the amount can be typed at all (referral earnings go
     whole). A complete account number is asked of the bank for its name. */
  var form = document.querySelector('[data-wl-fees]');
  if (form) {
    var bands = [];
    try { bands = JSON.parse(form.getAttribute('data-wl-fees') || '[]'); } catch (e) { bands = []; }
    var max = parseInt(form.getAttribute('data-wl-max'), 10) || 0;
    var partial = true;
    var input = form.querySelector('[data-wl-amount]');
    var out = form.querySelector('[data-wl-fee]');
    var all = form.querySelector('[data-wl-all]');
    var quick = form.querySelector('[data-wl-quick]');
    var avail = form.querySelector('[data-wl-avail]');
    var whole = form.querySelector('[data-wl-whole]');
    var nets = form.querySelectorAll('[data-wl-net]');
    var sumAmt = form.querySelector('[data-wl-sum-amt]'), sumFee = form.querySelector('[data-wl-sum-fee]');
    var feeFor = function (k) {
      var f = 0;
      for (var i = 0; i < bands.length; i++) if (k >= bands[i][0]) f = bands[i][1];
      return Math.min(f, k);
    };
    var show = function () {
      var typed = input && partial ? input.value.replace(/[₦,\s]/g, '') : '';
      var k = typed === '' ? max : Math.round(parseFloat(typed) * 100);
      var bad = !isFinite(k) || k <= 0 || k > max;
      var f = bad ? 0 : feeFor(k);
      if (out) out.textContent = bad
        ? (k > max ? 'That is more than is available.' : 'Type the amount in naira, like 2500.')
        : 'You receive ' + naira(Math.max(0, k - f)) + ' after the ' + naira(f) + ' service fee.';
      if (out) out.classList.toggle('wl-form__fee--bad', bad);
      Array.prototype.forEach.call(nets, function (n) { n.textContent = bad ? '—' : naira(Math.max(0, k - f)); });
      if (sumAmt) sumAmt.textContent = bad ? '—' : naira(k);
      if (sumFee) sumFee.textContent = bad ? '—' : '−' + naira(f);
    };
    var quickAmounts = function () {
      if (!quick) return;
      quick.textContent = '';
      if (!partial) { quick.hidden = true; return; }
      var picks = [100000, 200000, 500000, 1000000, 2000000].filter(function (v) { return v < max; }).slice(-2);
      picks.push(max);
      picks.forEach(function (v) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'wl-quickamt__b';
        b.textContent = v === max ? 'All · ' + naira(v) : naira(v).replace('.00', '');
        b.addEventListener('click', function () { input.value = (v / 100).toFixed(2); show(); input.focus(); });
        quick.appendChild(b);
      });
      quick.hidden = false;
    };
    var pick = function (r) {
      max = parseInt(r.getAttribute('data-max'), 10) || 0;
      partial = r.getAttribute('data-partial') === '1';
      if (avail) avail.textContent = naira(max);
      if (input) {
        input.readOnly = !partial;
        input.placeholder = (max / 100).toFixed(2);
        if (!partial) input.value = (max / 100).toFixed(2);
      }
      if (all) all.hidden = !partial;
      if (whole) whole.hidden = partial;
      quickAmounts();
      show();
    };
    Array.prototype.forEach.call(form.querySelectorAll('[data-wl-pocket]'), function (r) {
      r.addEventListener('change', function () { if (r.checked) pick(r); });
      if (r.checked) pick(r);
    });
    if (input) input.addEventListener('input', show);
    if (all && input) {
      all.addEventListener('click', function () { input.value = (max / 100).toFixed(2); show(); input.focus(); });
    }
    show();

    var bank = form.querySelector('[data-wl-bank]'), nuban = form.querySelector('[data-wl-nuban]');
    var resolved = form.querySelector('[data-wl-resolved]'), resolvedN = form.querySelector('[data-wl-resolved-n]');
    var nameIn = form.querySelector('input[name="account_name"]');
    var asked = '';
    var resolve = function () {
      if (!bank || !nuban || !resolved || !window.fetch) return;
      var num = nuban.value.replace(/\D+/g, ''), code = bank.value;
      if (num.length !== 10 || code === '') { resolved.hidden = true; return; }
      var key = code + ':' + num;
      if (key === asked) return;
      asked = key;
      fetch('/account/wallet/resolve.json?bank_code=' + encodeURIComponent(code) + '&account_number=' + encodeURIComponent(num),
        { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (asked !== key || !d || !d.ok || !d.name) { resolved.hidden = true; return; }
          resolvedN.textContent = d.name;
          resolved.hidden = false;
          if (nameIn && nameIn.value.trim() === '') nameIn.value = d.name;
        })
        .catch(function () { resolved.hidden = true; });
    };
    if (bank) bank.addEventListener('change', resolve);
    if (nuban) nuban.addEventListener('input', resolve);
  }

  /* ── the history's selects apply themselves; the Show button is for no script ── */
  var find = document.querySelector('[data-wl-find]');
  if (find) {
    var go = find.querySelector('.wl-find__go');
    if (go) go.hidden = true;
    Array.prototype.forEach.call(find.querySelectorAll('select'), function (sel) {
      sel.addEventListener('change', function () { if (find.requestSubmit) find.requestSubmit(); else find.submit(); });
    });
  }

  /* ── share a receipt: the PNG itself where the phone can share files, its words otherwise ── */
  var share = document.querySelector('[data-wl-share]');
  if (share && navigator.share) {
    share.hidden = false;
    share.addEventListener('click', function () {
      var text = share.getAttribute('data-wl-share-text') || '';
      var png = share.getAttribute('data-wl-share-png');
      var plain = function () { navigator.share({ title: 'Africa GATES receipt', text: text }).catch(function () {}); };
      if (!png || !window.fetch || !window.File) { plain(); return; }
      fetch(png, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.blob() : null; }).then(function (b) {
        if (!b) { plain(); return; }
        var f = new File([b], png.split('/').pop(), { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [f] })) {
          navigator.share({ files: [f], title: 'Africa GATES receipt', text: text }).catch(function () {});
        } else { plain(); }
      }).catch(plain);
    });
  }
})();
