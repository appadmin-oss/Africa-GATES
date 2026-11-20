/* ══════════════════════════════════════════════════════════════════════════════
   GIVING — the checkout's live recap, and an organisation's click-to-load video
   ══════════════════════════════════════════════════════════════════════════════
   The form works without this: presets are radios, "Other amount" is its own field, and
   the server recomputes every figure (PlatformTip, the processing cover). This keeps what
   the donor SEES true while they choose — the recap and the button must agree, because a
   total that first appears on the gateway's own screen is the moment a donor abandons —
   and says what is wrong before a round trip, never instead of the server.
   Classic deferred script, no inline handlers (the CSP has no 'unsafe-inline'). */
(function () {
  'use strict';
  // Page-wide listeners and timers go through agPage (layout/shell.twig), which site-nav.js
  // undoes when this page is swapped away; elsewhere they are plain (SiteNavTest).
  var pg = window.agPage || { on: function (t, y, f, o) { t.addEventListener(y, f, o); }, every: function (f, ms) { return setInterval(f, ms); }, leave: function () {} };

  var form = document.querySelector('[data-gv-form]');
  if (form) checkout(form);

  function checkout(f) {
    var min = +f.getAttribute('data-min') || 0, max = +f.getAttribute('data-max') || Infinity;
    var orgBps = +f.getAttribute('data-org-fee') || 0;
    // The gateway's schedule, from the same four settings the server charges by
    // (GatewayFee): a percentage, plus a flat fee from a threshold, capped.
    var fs = { pct: parseFloat(f.getAttribute('data-fee-pct')) || 0, flat: +f.getAttribute('data-fee-flat') || 0,
               from: +f.getAttribute('data-fee-from') || 0, cap: +f.getAttribute('data-fee-cap') || 0 };
    function gwFee(c) {
      if (c <= 0) return 0;
      var x = Math.ceil(c * fs.pct / 100 + (c >= fs.from ? fs.flat : 0));
      return fs.cap > 0 ? Math.min(fs.cap, x) : x;
    }
    // GatewayFee::cover(): the smallest c with c >= fee(gift + tip + c).
    function coverFor(a, also) {
      if (a <= 0) return 0;
      var c = 0;
      for (var i = 0; i < 12; i++) { var need = gwFee(a + also + c); if (need <= c) return c; c = need; }
      return c;
    }
    var other = f.querySelector('[data-gv-other]'), amts = f.querySelector('.gv-amts');
    // The phone's action bar lives outside the form (form="give"), so the figures and the
    // buttons are looked up in the document, and every copy is kept in step.
    var gos = document.querySelectorAll('[data-gv-go]'), err = f.querySelector('[data-gv-err]');
    var $ = function (s) { return f.querySelector(s); };
    var all = function (sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); };
    var ngn = function (n) { return '₦' + Math.round(n).toLocaleString('en-NG'); };

    function amount() {
      var typed = other ? other.value.replace(/[^0-9]/g, '') : '';
      if (typed) return parseInt(typed, 10) || 0;
      var r = f.querySelector('[data-gv-preset]:checked');
      return r ? parseInt(r.value, 10) || 0 : 0;
    }
    function monthly() { var r = f.querySelector('[data-gv-freq]:checked'); return !!(r && r.value === '1'); }
    function tipPct() { var r = f.querySelector('[data-gv-tip]:checked'); return r ? parseFloat(r.value) || 0 : 0; }

    function paint() {
      var a = amount(), cover = $('[data-gv-cover]'), mon = monthly();
      // The server's arithmetic, in its order (DonationController::start, PlatformTip,
      // GatewayFee): the tip is a share of the gift, rounded down; the cover is then the
      // fee on the whole charge. A recap that rounds differently is a recap that disagrees
      // with the bank statement by a naira.
      var tip = tipPct() > 0 ? Math.floor(a * tipPct() / 100) : 0;
      var offer = coverFor(a, tip);
      var fee = cover && cover.checked ? offer : 0;
      var total = a + fee + tip;
      var set = function (sel, v) { all(sel, function (el) { el.textContent = v; }); };
      set('[data-gv-amt]', ngn(a));
      set('[data-gv-fee]', ngn(fee));
      set('[data-gv-cover-amt]', ngn(offer));
      var feeRow = $('[data-gv-fee-row]'); if (feeRow) feeRow.hidden = fee === 0;
      set('[data-gv-tipv]', ngn(tip));
      var tipRow = $('[data-gv-tip-row]'); if (tipRow) tipRow.hidden = tip === 0;
      set('[data-gv-total]', ngn(total));
      set('[data-gv-orgfee]', ngn(Math.floor(a * orgBps / 10000)));
      all('[data-gv-freq-word]', function (w) { w.textContent = w.getAttribute(mon ? 'data-monthly' : 'data-once'); });
      var note = $('[data-gv-monthly-note]'); if (note) note.hidden = !mon;
      var label = f.getAttribute(mon ? 'data-w-monthly' : 'data-w-once').replace('%a%', ngn(total));
      Array.prototype.forEach.call(gos, function (b) { b.textContent = b.closest('.gv-bar') ? f.getAttribute('data-w-short') : label; });
      // A typed amount is the answer; the tiles stop looking chosen so two amounts never
      // appear selected at once.
      if (amts) { if (other && other.value.trim()) amts.setAttribute('data-other', ''); else amts.removeAttribute('data-other'); }
    }

    f.addEventListener('change', paint);
    f.addEventListener('input', function (e) {
      if (e.target === other) {
        var digits = other.value.replace(/[^0-9]/g, '');
        if (digits !== other.value) other.value = digits;
        other.removeAttribute('aria-invalid');
        say('');
      }
      paint();
    });
    // Choosing a tile again after typing means the tile: clear the typed figure.
    f.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.gv-amt') && other && other.value) { other.value = ''; paint(); }
    });

    function say(t) { if (!err) return; err.textContent = t; err.hidden = !t; }

    f.addEventListener('submit', function (e) {
      var a = amount();
      var bad = a < min ? f.getAttribute('data-e-min') : (a > max ? f.getAttribute('data-e-max') : '');
      if (bad) {
        e.preventDefault();
        say(bad);
        if (other) {
          other.setAttribute('aria-invalid', 'true');
          // The sentence belongs to the box it is about, beside the range it states.
          if (err && err.id && (' ' + (other.getAttribute('aria-describedby') || '') + ' ').indexOf(' ' + err.id + ' ') < 0) {
            other.setAttribute('aria-describedby', ((other.getAttribute('aria-describedby') || '') + ' ' + err.id).trim());
          }
          other.focus();
        }
        return;
      }
      if (other) other.removeAttribute('aria-invalid');
      say('');
      // Busy, and stays busy: the next thing is the gateway's page. A second press would
      // start a second checkout. Decided AFTER every submit listener has run: the shared
      // validator (data-ag-validate) may refuse the email, and a button reading "Opening the
      // secure checkout…" over a form that is going nowhere is a page that has hung.
      setTimeout(function () {
        if (e.defaultPrevented) return;
        Array.prototype.forEach.call(gos, function (b) { b.setAttribute('aria-busy', 'true'); b.textContent = f.getAttribute('data-w-busy'); });
      }, 0);
    });
    // Back from the gateway restores this page from the cache, still busy. Undo that.
    pg.on(window, 'pageshow', function () { Array.prototype.forEach.call(gos, function (b) { b.removeAttribute('aria-busy'); }); paint(); });

    paint();
  }

  /* ══ CLICK-TO-LOAD VIDEO (partials/org-page.twig) ══════════════════════════
     Nothing reaches YouTube or Vimeo until this runs, which is the point: an iframe in the
     markup sends the visitor's address to a third party on page view. The src was built by
     OrgBrand::embedUrl() from a fixed prefix and a validated id; it is checked again here,
     at the point of use, so no later edit can turn this into an arbitrary-iframe injector. */
  pg.on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-ob-play]');
    if (!btn) return;
    var box = btn.closest('[data-ob-video]');
    var src = box ? box.getAttribute('data-ob-src') || '' : '';
    if (!/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(src)) return;
    var frame = document.createElement('iframe');
    frame.src = src + (src.indexOf('?') < 0 ? '?' : '&') + 'autoplay=1';
    frame.title = box.getAttribute('data-ob-name') || '';
    frame.allow = 'autoplay; fullscreen; picture-in-picture';
    frame.allowFullscreen = true;
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    btn.replaceWith(frame);
    frame.focus();
  });
})();

/* GivingPage V2: the story folds to 230px with a fade, behind one "Read more" button, only
   when it is actually longer than that — a short story never grows a button that reveals
   nothing. Without this script the whole story simply shows. */
(function () {
  'use strict';
  var prose = document.querySelector('[data-gv-fold]');
  var btn = document.querySelector('[data-gv-fold-btn]');
  if (!prose || !btn || prose.scrollHeight <= 260) return;
  prose.classList.add('is-folded');
  btn.hidden = false;
  btn.addEventListener('click', function () {
    var open = prose.classList.toggle('is-folded') === false;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? btn.getAttribute('data-less') : btn.getAttribute('data-more');
  });
})();
