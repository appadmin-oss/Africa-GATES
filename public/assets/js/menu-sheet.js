/* ══════════════════════════════════════════════════════════════════════════════
   THE MENU SHEET — detents, drag, pushed sub-views and the most-used tiles
   REFERENCE §7.4 · MobileMenu.dc.html · skill §4, §10 · docs/handoff/MENU-SHEET.md
   ══════════════════════════════════════════════════════════════════════════════

   Destroyed with `bindMenu()` in chrome.js and written here whole on 4 Oct 2026, for the
   owner's "you cannot currently drag down to close" (GAPS §8e). Every number in the gesture
   half is MENU-SHEET.md §2's, with its source there; the ranking half types none of its own
   (below).

   ── WHAT THIS FILE OWNS, AND WHAT IT BORROWS ─────────────────────────────────

   Owns: where the sheet is (closed · medium · full), every vertical touch on it, the
   sub-views, the grabber control, and counting which destinations are opened from it.
   Borrows: opening and closing — `AGChrome.openSheet` (chrome.js: one history entry, Back
   closes) over `AGShell.openSheet` (shell.js: focus in, Tab trapped, Esc and the scrim
   close, focus BACK to the trigger). A drag that dismisses calls that same close, and the
   close however it came — Esc, scrim, Back, the button, a hand-over — is animated from
   wherever the sheet stands by watching `data-open` go.

   ── ONE OWNER FOR VERTICAL TOUCH ─────────────────────────────────────────────

   The sheet is `touch-action: pan-x pinch-zoom`, so the browser never starts a vertical
   pan on it and this script moves both the sheet and the list's `scrollTop`. That is the
   price of the same-gesture hand-over (scenario 4): once a browser has begun a native
   scroll, its `touchmove`s are uncancelable, so a list that scrolls natively can only give
   the pull to the sheet on the NEXT touch. Wheel and keyboard scrolling stay native.

   Pointer Events, listened on the document for the life of one gesture — never
   `setPointerCapture`, which re-targets the following click to the capturing element and
   killed every marker on the homepage globe (CLAUDE.md).

   ── THE RANKING HAS ONE SET OF NUMBERS ──────────────────────────────────────

   A guest's most-used tiles are ranked here from `localStorage["ag-menu-use"]` — written
   only when the visitor allowed Preferences (`data-ag-keep`, CookiePrefs). The half-life,
   the threshold, the warm-up, the slots, the defaults and which destinations this visitor
   may open all arrive in `data-ag-menu-params` from `Services\MenuShortcuts`, and
   `MenuSheetTest` runs `Rank` below under Node against the PHP on sampled histories. Under
   Node this file exports `Rank` and touches nothing else.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  /* ════════════════════════════════════════════════════════════════════════
     RANK — the frecency rule, mirrored from Services\MenuShortcuts
     ════════════════════════════════════════════════════════════════════════ */

  var Rank = {
    normalise: function (doc, now, p) {
      var out = { n: 0, d: {} };
      if (!doc || typeof doc !== 'object' || Array.isArray(doc)) return out;
      var n = Number(doc.n);
      out.n = isFinite(n) ? Math.max(0, Math.min(1000000, Math.trunc(n))) : 0;
      var d = doc.d;
      if (!d || typeof d !== 'object') return out;
      p.all.forEach(function (k) {
        var r = d[k];
        if (!Array.isArray(r)) return;
        var s = Number(r[0]), t = Math.trunc(Number(r[1]));
        if (!isFinite(s) || !isFinite(t) || s <= 0 || t <= 0) return;
        out.d[k] = [Rank.round4(Math.min(s, 10000)), Math.min(t, now)];
      });
      return out;
    },
    round4: function (x) { return Math.round(x * 1e4) / 1e4; },
    decayed: function (s, t, now, p) { return s * Math.pow(2, -Math.max(0, now - t) / p.half); },
    record: function (h, key, now, p) {
      h = Rank.normalise(h, now, p);
      if (p.all.indexOf(key) < 0) return h;
      var r = h.d[key] || [0, now];
      h.d[key] = [Rank.round4(Math.min(Rank.decayed(r[0], r[1], now, p) + 1, 10000)), now];
      h.n++;
      return h;
    },
    rank: function (h, now, p) {
      h = Rank.normalise(h, now, p);
      var earned = [];
      if (h.n >= p.warmup) {
        Object.keys(h.d).forEach(function (k) {
          if (p.order.indexOf(k) < 0) return;
          var s = Rank.decayed(h.d[k][0], h.d[k][1], now, p);
          if (s >= p.qualify) earned.push([k, s, h.d[k][1]]);
        });
        earned.sort(function (a, b) {
          return (b[1] - a[1]) || (b[2] - a[2]) || (p.all.indexOf(a[0]) - p.all.indexOf(b[0]));
        });
      }
      var keys = earned.slice(0, p.slots).map(function (e) { return e[0]; });
      var personal = keys.length > 0;
      p.defaults.forEach(function (k) {
        if (keys.length < p.slots && keys.indexOf(k) < 0 && p.order.indexOf(k) >= 0) keys.push(k);
      });
      return { keys: keys, personal: personal };
    },
    displaced: function (keys, p) {
      return p.defaults.filter(function (k) { return keys.indexOf(k) < 0 && p.order.indexOf(k) >= 0; });
    }
  };

  if (typeof module === 'object' && module.exports) { module.exports = Rank; return; }

  /* ════════════════════════════════════════════════════════════════════════
     THE SHEET
     ════════════════════════════════════════════════════════════════════════ */

  var LOW      = 0.45;   /* the open height never under 45% of the visual viewport … */
  var HIGH     = 0.70;   /* … nor over 70% (owner, 4 Oct 2026: "the open height varies") */
  var BREATH   = 12;     /* px shown past the last whole block, so the edge reads as an edge */
  var SLOP     = 8;      /* px before anything moves; the axis is decided then */
  var FLICK    = 0.5;    /* px/ms — Material's 500 px/s */
  var CLOSE    = 0.25;   /* of the medium height, below medium — vaul */
  var PROJECT  = 99;     /* Apple's r/(1−r) at r = 0.99: px of projection per px/ms */
  var RUBBER   = 0.55;   /* UIScrollView */
  var DECAY    = 0.998;  /* list momentum, per ms — UIScrollView normal */
  var STILL    = 0.02;   /* px/ms: momentum stops */
  var WINDOW   = 100;    /* ms of samples a release velocity is read over */
  var STORE    = 'ag-menu-use';

  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function boot() {
    var sheet = document.querySelector('[data-ag-menu-sheet]');
    var scrim = document.querySelector('[data-ag-menu-scrim]');
    if (!sheet || !window.AGChrome || !window.AGChrome.openSheet) return;
    var body   = sheet.querySelector('[data-ag-menu-body]');
    var handle = sheet.querySelector('[data-ag-menu-handle]');
    var grab   = sheet.querySelector('[data-ag-menu-grab]');
    var root   = document.documentElement;

    var closeFn = null;     /* chrome.js's close for the live interaction */
    var from    = null;     /* the row that pushed the current sub-view */
    var detent  = 'closed';
    var y       = 0;        /* the transform now: 0 = full height showing */
    var geo     = { H: 0, full: 0, medium: 0, mediumH: 1, closed: 0 };
    var timer   = 0, mom = 0, swallow = false, swallowTimer = 0;

    function reduced() {
      return root.classList.contains('ag-rm') ||
        (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    /* The house sheet duration and the iOS sheet curve, read from tokens.css. */
    function dur() {
      if (reduced()) return 0;
      var v = parseFloat(getComputedStyle(root).getPropertyValue('--ag-dur-2'));
      return isNaN(v) ? 260 : v;
    }
    function ease() {
      return getComputedStyle(root).getPropertyValue('--ag-ease-sheet').trim() || 'ease-out';
    }

    /* ── Geometry ────────────────────────────────────────────────────────── */

    /* ── THE OPEN HEIGHT FOLLOWS THE CONTENT ─────────────────────────────────
       Owner, 4 Oct 2026: Meta's sheets do not open at one fixed fraction. The medium
       detent is tall enough to show the head, the account or join card and the four
       squares WHOLE, and it ends on the bottom of a whole block or row — never through
       one — inside 45–70% of the visual viewport. Shorter content opens at its own height.
       Read from the layout on every open, resize and sub-view, so 150% text, a join card
       twice the height of a profile card, and a 640px phone each get their own answer.

       `boundaries()` are the bottoms of every block of the visible view and of every list
       row in it, in sheet coordinates (the transform moves sheet and child alike, and the
       list's own scroll is added back). */
    function boundaries(view) {
      var top = sheet.getBoundingClientRect().top, sc = body ? body.scrollTop : 0, box = [];
      all(':scope > *, .ag-list__row', view).forEach(function (el) {
        if (el.hidden || !el.getClientRects().length || el.tagName === 'TEMPLATE') return;
        var r = el.getBoundingClientRect();
        box.push([r.top - top + sc, r.bottom - top + sc]);
      });
      /* An edge is a bottom plus the breath — but never past the TOP of whatever comes next:
         the rows of a list touch, and 12px past one row is 12px into the next (measured). */
      return box.map(function (b) {
        var room = BREATH;
        box.forEach(function (o) { if (o[0] >= b[1] - 0.5) room = Math.min(room, o[0] - b[1]); });
        return Math.round(b[1] + Math.max(0, room));
      }).sort(function (x, y) { return x - y; });
    }

    function openHeight(V, fullH) {
      var lo = Math.round(V * LOW), hi = Math.round(V * HIGH);
      if (fullH <= lo) return fullH;
      var view = sheet.querySelector('[data-ag-menu-view]:not([hidden])');
      var edges = view ? boundaries(view) : [];
      var tiles = view && view.querySelector('[data-ag-menu-tiles]');
      var need = tiles ? tiles.getBoundingClientRect().bottom - sheet.getBoundingClientRect().top + (body ? body.scrollTop : 0) : 0;
      var fits = edges.filter(function (x) { return x >= lo && x <= hi; });
      var past = fits.filter(function (x) { return x >= need; });
      var want;
      if (past.length) want = past[0];                          /* the squares whole, ending on the next edge */
      else if (fits.length) want = fits[fits.length - 1];       /* the squares cannot fit: the most that fits whole */
      else want = clamp(need, lo, hi);                          /* no whole edge in range: a block taller than the band */
      return Math.min(fullH, want);
    }

    function measure() {
      var vv = window.visualViewport;
      var V = vv ? vv.height : window.innerHeight;
      var H = sheet.offsetHeight;
      var content = (handle ? handle.offsetHeight : 0) + (body ? body.scrollHeight : 0);
      var fullH = Math.min(H, content);
      var medH = openHeight(V, fullH);
      geo.H = H;
      geo.full = H - fullH;
      geo.medium = H - medH;
      geo.mediumH = Math.max(1, medH);
      geo.closed = H + 24;   /* clear of the shadow */
    }

    function place(to, ms) {
      y = to;
      var e = ease();
      var closing = to >= geo.closed;
      sheet.style.transition = ms
        ? 'transform ' + ms + 'ms ' + e + ', visibility 0s linear ' + (closing ? ms : 0) + 'ms'
        : 'none';
      sheet.style.transform = 'translate3d(0,' + to + 'px,0)';
      if (scrim) {
        scrim.style.transition = ms ? 'opacity ' + ms + 'ms ' + e : 'none';
        scrim.style.opacity = String(clamp((geo.H - to) / geo.mediumH, 0, 1));
      }
    }

    function label() {
      if (!grab) return;
      var full = detent === 'full' || geo.full === geo.medium;
      grab.setAttribute('aria-label', sheet.getAttribute(full ? 'data-label-collapse' : 'data-label-expand') || '');
      grab.hidden = geo.full === geo.medium;   /* one height: nothing to toggle */
    }

    function settle(name, ms) {
      detent = name;
      place(geo[name], ms == null ? dur() : ms);
      label();
    }

    /* ── Sub-views ───────────────────────────────────────────────────────── */

    function view(name, focusTo) {
      all('[data-ag-menu-view]', sheet).forEach(function (v) {
        v.hidden = v.getAttribute('data-ag-menu-view') !== name;
      });
      var back = sheet.querySelector('[data-ag-menu-back]');
      if (back) back.hidden = name === 'main';
      var title = sheet.querySelector('[data-ag-menu-title]');
      if (title) title.textContent = sheet.getAttribute('data-title-' + name) || title.textContent;
      stopMomentum();
      if (body) body.scrollTop = 0;

      /* A push that leaves focus on a row no longer on screen is a push a screen-reader
         user cannot follow: focus moves to the first control of the new view, and back to
         the row that opened it on the way out. */
      var target = focusTo;
      if (!target) {
        var v = sheet.querySelector('[data-ag-menu-view="' + name + '"]');
        target = v ? v.querySelector('a[href],button:not([disabled]),select,input:not([disabled])') : null;
      }

      /* A sub-view taller than the detent opens the sheet to full; back keeps the detent. */
      if (detent !== 'closed') {
        measure();
        if (detent === 'medium' && name !== 'main' && geo.full < geo.medium) settle('full');
        else settle(detent);
      }
      if (target) target.focus({ preventScroll: true });
    }

    /* ── Open and close ──────────────────────────────────────────────────── */

    function openMenu(trigger, name) {
      from = null;
      name = name || 'main';
      all('[data-ag-menu-view]', sheet).forEach(function (v) { v.hidden = v.getAttribute('data-ag-menu-view') !== name; });
      measure();
      detent = 'closed';
      place(geo.closed, 0);           /* before data-open, so the base CSS never shows it full */
      closeFn = window.AGChrome.openSheet(sheet, scrim, trigger, function () { view(name); });
      void sheet.offsetHeight;        /* commit the closed position, then move from it */
      measure();
      settle(name !== 'main' && geo.full < geo.medium ? 'full' : 'medium');
    }

    function dismiss() { if (closeFn) closeFn(); }

    /* However the sheet was closed, it leaves from where it stands. */
    if (window.MutationObserver) {
      new MutationObserver(function () {
        if (sheet.hasAttribute('data-open')) return;
        if (detent === 'closed' && !sheet.style.transform) return;
        stopMomentum();
        closeFn = null;
        var ms = dur();
        settle('closed', ms);
        clearTimeout(timer);
        timer = setTimeout(function () {
          if (sheet.hasAttribute('data-open')) return;
          sheet.style.transition = sheet.style.transform = '';
          if (scrim) scrim.style.transition = scrim.style.opacity = '';
        }, ms + 40);
      }).observe(sheet, { attributes: true, attributeFilter: ['data-open'] });
    }

    document.addEventListener('click', function (e) {
      if (swallow && sheet.contains(e.target)) {
        e.preventDefault(); e.stopPropagation(); swallow = false; return;
      }
      var t = e.target && e.target.closest ? e.target : null;
      if (!t) return;
      if (t.closest('[data-ag-menu]')) { e.preventDefault(); openMenu(t.closest('[data-ag-menu]')); return; }
      if (!sheet.contains(t)) return;
      if (t.closest('[data-ag-menu-close]')) { dismiss(); return; }
      if (t.closest('[data-ag-menu-grab]')) { settle(detent === 'full' ? 'medium' : 'full'); return; }
      if (t.closest('[data-ag-menu-back]')) { view('main', from); from = null; return; }
      var to = t.closest('[data-ag-menu-to]');
      if (to) { from = to; view(to.getAttribute('data-ag-menu-to')); return; }
      var dest = t.closest('[data-ag-dest]');
      if (dest && !e.defaultPrevented) count(dest.getAttribute('data-ag-dest'));
    }, true);

    /* A form control, or anything focused below the visible edge, wants the full height. */
    sheet.addEventListener('focusin', function (e) {
      if (detent !== 'medium') return;
      var t = e.target;
      var vv = window.visualViewport;
      var bottom = vv ? vv.height + vv.offsetTop : window.innerHeight;
      if (/^(INPUT|SELECT|TEXTAREA)$/.test(t.tagName) || t.getBoundingClientRect().bottom > bottom) settle('full');
    });

    /* A wheel turned towards more content at medium expands first, as a swipe does. */
    sheet.addEventListener('wheel', function (e) {
      if (detent === 'medium' && e.deltaY > 0 && geo.full < geo.medium) { e.preventDefault(); settle('full'); }
    }, { passive: false });

    /* Rotation, a resize, the on-screen keyboard: the detents move, the name is kept. */
    function remeasure() {
      if (detent === 'closed') return;
      measure();
      settle(detent === 'full' || geo.full === geo.medium ? 'full' : 'medium', 0);
    }
    window.addEventListener('resize', remeasure);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', remeasure);

    /* ── The gesture ─────────────────────────────────────────────────────── */

    var g = null;

    function stopMomentum() {
      if (!mom) return false;
      cancelAnimationFrame(mom); mom = 0;
      return true;
    }

    function swallowNextClick() {
      swallow = true;
      clearTimeout(swallowTimer);
      swallowTimer = setTimeout(function () { swallow = false; }, 400);
    }

    function rubber(x) { return (1 - 1 / (x * RUBBER / geo.H + 1)) * geo.H; }

    function shown(raw) { return raw < geo.full ? geo.full - rubber(geo.full - raw) : raw; }

    sheet.addEventListener('pointerdown', function (e) {
      if (detent === 'closed' || !e.isPrimary || g) return;
      var onHandle = !!(handle && handle.contains(e.target));
      if (e.pointerType === 'mouse' && (e.button !== 0 || !onHandle)) return;
      /* A touch that lands while the list is coasting stops it, and is not a tap. */
      if (stopMomentum()) swallowNextClick();
      g = { id: e.pointerId, x0: e.clientX, y0: e.clientY, last: e.clientY, raw: y,
            started: false, list: !onHandle, to: 'sheet', s: [[e.timeStamp, e.clientY]] };
      document.addEventListener('pointermove', move, { passive: false });
      document.addEventListener('pointerup', end);
      document.addEventListener('pointercancel', end);
    });

    function move(e) {
      if (!g || e.pointerId !== g.id) return;
      if (!g.started) {
        var dx = e.clientX - g.x0, dy = e.clientY - g.y0;
        if (Math.abs(dx) < SLOP && Math.abs(dy) < SLOP) return;
        if (Math.abs(dx) > Math.abs(dy)) { quit(); return; }   /* horizontal: never the sheet's */
        g.started = true;
        /* Track from the slop's edge, not from where it was crossed: the sheet trails the
           finger by the 8px that decided the gesture, never jumps by them. */
        g.last = g.y0 + (dy > 0 ? SLOP : -SLOP);
        sheet.setAttribute('data-dragging', '');
        swallowNextClick();
      }
      e.preventDefault();
      var d = e.clientY - g.last;
      g.last = e.clientY;
      g.s.push([e.timeStamp, e.clientY]);
      if (g.s.length > 20) g.s.shift();
      apply(d);
    }

    /* Finger down (d > 0): undo any stretch, then the list back to its top, then the sheet.
       Finger up: the sheet to full first — expand before scroll — then the list, then the
       stretch. On the handle the list is never involved. */
    function apply(d) {
      if (d > 0) {
        if (g.raw < geo.full) { var u = Math.min(d, geo.full - g.raw); g.raw += u; d -= u; }
        if (d > 0 && g.list && g.raw <= geo.full && body.scrollTop > 0) {
          var take = Math.min(d, body.scrollTop); body.scrollTop -= take; d -= take; g.to = 'list';
        }
        if (d > 0) { g.raw += d; g.to = 'sheet'; }
      } else if (d < 0) {
        var up = -d;
        if (g.raw > geo.full) { var t = Math.min(up, g.raw - geo.full); g.raw -= t; up -= t; g.to = 'sheet'; }
        if (up > 0 && g.list) {
          var max = body.scrollHeight - body.clientHeight;
          if (body.scrollTop < max) { var t2 = Math.min(up, max - body.scrollTop); body.scrollTop += t2; up -= t2; g.to = 'list'; }
        }
        if (up > 0) { g.raw -= up; g.to = 'sheet'; }
      }
      place(shown(g.raw), 0);
    }

    function velocity(now) {
      var s = g.s, last = s[s.length - 1], first = last;
      for (var i = s.length - 1; i >= 0 && now - s[i][0] <= WINDOW; i--) first = s[i];
      var dt = last[0] - first[0];
      if (now - last[0] > WINDOW || dt <= 0) return 0;
      return (last[1] - first[1]) / dt;   /* px/ms, positive = down */
    }

    function quit() {
      document.removeEventListener('pointermove', move, { passive: false });
      document.removeEventListener('pointerup', end);
      document.removeEventListener('pointercancel', end);
      sheet.removeAttribute('data-dragging');
      g = null;
    }

    function end(e) {
      if (!g || (e && e.pointerId !== g.id)) return;
      var ges = g;
      var v = e && e.type === 'pointerup' && ges.started ? velocity(e.timeStamp) : 0;
      quit();
      if (!ges.started) return;

      /* The list was moving at the end, and the sheet is at full: the list coasts. */
      if (ges.to === 'list' && ges.raw === geo.full) { fling(-v); settle('full', 0); return; }

      var at = shown(ges.raw);
      var name = target(at, v);
      if (name === 'closed') dismiss();
      else settle(name);
    }

    /* MENU-SHEET.md §3 scenario 7: the nearest detent to the PROJECTED position; closed
       only past the close line, so a flick from full stops at medium unless it would have
       carried well past it. */
    function target(at, v) {
      var line = geo.medium + CLOSE * geo.mediumH;
      var proj = at + v * PROJECT;
      if (proj >= line) return 'closed';
      if (v >= FLICK) return at < geo.medium - 1 ? 'medium' : 'closed';
      if (v <= -FLICK) return 'full';
      return Math.abs(proj - geo.full) <= Math.abs(proj - geo.medium) ? 'full' : 'medium';
    }

    function fling(v0) {
      stopMomentum();
      if (Math.abs(v0) < STILL) return;
      var v = v0, last = 0;
      function step(now) {
        if (!last) { last = now; mom = requestAnimationFrame(step); return; }
        var dt = Math.min(64, now - last); last = now;
        var before = body.scrollTop;
        body.scrollTop = before + v * dt;
        v *= Math.pow(DECAY, dt);
        if (Math.abs(v) < STILL || body.scrollTop === before) { mom = 0; return; }
        mom = requestAnimationFrame(step);
      }
      mom = requestAnimationFrame(step);
    }

    /* ════════════════════════════════════════════════════════════════════════
       MOST USED — counting an open, and a guest's tiles
       ════════════════════════════════════════════════════════════════════════ */

    var who = sheet.getAttribute('data-ag-menu-who');
    var p = null;
    try { p = JSON.parse(sheet.getAttribute('data-ag-menu-params') || 'null'); } catch (err) { p = null; }
    if (p) p.all = p.order.slice();   /* a guest's history holds only what they may open */
    var keep = root.getAttribute('data-ag-keep') === '1';

    function now() { return Math.floor(Date.now() / 1000); }

    function readGuest() {
      try { return JSON.parse(window.localStorage.getItem(STORE) || 'null'); } catch (err) { return null; }
    }

    /* Never holds the navigation: a beacon is queued by the browser and outlives the page;
       a guest's count is one synchronous write, and only with Preferences allowed. */
    function count(key) {
      if (!p || p.order.indexOf(key) < 0) return;
      if (who === 'member') {
        var meta = document.querySelector('meta[name="ag-csrf"]');
        if (!meta) return;
        var fd = new FormData();
        fd.append('_token', meta.getAttribute('content') || '');
        fd.append('d', key);
        try {
          if (!(navigator.sendBeacon && navigator.sendBeacon('/account/menu-use', fd))) {
            fetch('/account/menu-use', { method: 'POST', body: fd, keepalive: true, credentials: 'same-origin' }).catch(function () {});
          }
        } catch (err) {}
        return;
      }
      if (!keep) return;
      try { window.localStorage.setItem(STORE, JSON.stringify(Rank.record(readGuest(), key, now(), p))); } catch (err) {}
    }

    function paintGuest() {
      if (who !== 'guest' || !keep || !p) return;
      var tpl = sheet.querySelector('[data-ag-menu-tpl]');
      var nav = sheet.querySelector('[data-ag-menu-tiles]');
      var explore = sheet.querySelector('[data-ag-menu-explore]');
      if (!tpl || !nav || !explore || !tpl.content) return;
      var r = Rank.rank(readGuest(), now(), p);
      if (!r.personal) return;

      var src = {};
      all('[data-for]', tpl.content).forEach(function (d) { src[d.getAttribute('data-for')] = d; });
      nav.textContent = '';
      r.keys.forEach(function (k) {
        var t = src[k] && src[k].querySelector('.ag-menu__tile');
        if (t) nav.appendChild(t.cloneNode(true));
      });
      nav.setAttribute('aria-label', nav.getAttribute('data-label-personal') || nav.getAttribute('aria-label'));

      all('[data-ag-dest]', explore).forEach(function (row) {
        if (p.defaults.indexOf(row.getAttribute('data-ag-dest')) >= 0) row.parentNode.removeChild(row);
      });
      Rank.displaced(r.keys, p).reverse().forEach(function (k) {
        var row = src[k] && src[k].querySelector('.ag-list__row');
        if (row) explore.insertBefore(row.cloneNode(true), explore.firstChild);
      });
    }

    paintGuest();
    window.AGChrome.openMenu = openMenu;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
