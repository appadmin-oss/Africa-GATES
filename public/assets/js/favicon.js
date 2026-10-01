/* ════════════════════════════════════════════════════════════════════════════
   Africa GATES — the dynamic favicon.

   One <link rel="icon" id="agFavicon">, redrawn on a 64px canvas. Nothing else on
   the site may touch the favicon; everything that wants to goes through this API:

     agFavicon.set('busy')    a vote, payment or nomination request is in flight
     agFavicon.set('idle')    it finished — and a success also clears an error
     agFavicon.set('error')   it failed; clears itself after ERROR_MS
     agFavicon.set('live')    this page is a live vote (usually declared instead:
                              <body data-favicon="live">)
     agFavicon.unread(n)      unread items — Gee's replies
     agFavicon.track(p)       busy for the life of a fetch promise, then idle or error

   ── WHY THE STATES ARE FLAGS AND NOT ONE VARIABLE ───────────────────────────
   The handoff's draft kept a single `state`, so whatever was set last won. On an
   open vote page that is exactly wrong: pressing Vote set 'busy' over 'live', and
   the successful 'idle' afterwards left the tab with no live dot on a page that
   was still live. The table it was written to says "priority: error > live >
   unread > busy > idle", which is a statement about several things being true at
   once. So each is held separately and the picture is DERIVED.

   ── RULES FROM THE HANDOFF, AND WHERE EACH IS KEPT ──────────────────────────
   · Nothing animates while the tab is hidden — the loop stops, but the current
     state is still drawn ONCE, because a hidden tab's icon is the only part of
     this a person in another tab can see.
   · Reduced motion gives static marks: the ring stops at a fixed arc and the live
     dot stops pulsing.
   · Never flashes faster than 2Hz: the live pulse is ~0.6Hz (a 1.63s period) and the
     ring is 0.9s a turn.
   · "(n) " in the title only while hidden, only when the count went UP, and removed
     on return.

   Colours are read from the design tokens, never typed: a favicon is one more
   surface where a fifth gold would otherwise appear.
   ════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var link = document.getElementById('agFavicon');
  if (!link || !document.createElement('canvas').getContext) return;

  var ERROR_MS = 30000;  // the handoff's "cleared after 30s"
  var FRAME_MS = 80;     // ~12 writes a second. Plenty for a 16px mark; a rel=icon
                         // write per animation frame is sixty image decodes a second.
  var SIZE = 64;

  var IDLE = link.getAttribute('href');
  var css = getComputedStyle(document.documentElement);
  function token(name, fallback) {
    var v = css.getPropertyValue(name).trim();
    return v || fallback;
  }
  var C = {
    error:  token('--ag-error', '#b42318'),
    live:   token('--ag-live', '#e0245e'),
    badge:  token('--ag-green', '#237b22'),
    ring:   token('--ag-green-light', '#7fc87c'),
    // In a dark scheme the SVG swaps its colours, so the disc the ring sweeps over IS
    // the light green: the handoff's single ring colour vanished into it there. The
    // deep green holds ~4.6:1 against that disc, as the light ring does against the
    // dark one.
    ringOnLight: token('--ag-green-deep', '#1a6118'),
    halo:   token('--ag-surface', '#ffffff')
  };

  var mm = window.matchMedia ? window.matchMedia.bind(window) : function () { return { matches: false }; };
  var reduce = mm('(prefers-reduced-motion: reduce)');
  var dark   = mm('(prefers-color-scheme: dark)');

  var flags = { error: false, live: false, busy: false };
  var count = 0, errorTimer = 0, raf = 0, t0 = 0, lastWrite = 0;

  var cv = document.createElement('canvas');
  cv.width = cv.height = SIZE;
  var x = cv.getContext('2d');

  var base = new Image();
  var ready = false;
  base.onload = function () { ready = true; render(); };
  base.src = IDLE;

  /* The page's own title, without a count we added. Read live rather than once, so a
     page that changes its title later is not reverted to the one it loaded with. */
  function bareTitle() { return document.title.replace(/^\(\d+\+?\) /, ''); }

  /* The one rule: error > live > unread > busy > idle. */
  function current() {
    if (flags.error) return 'error';
    if (flags.live)  return 'live';
    if (count > 0)   return 'unread';
    if (flags.busy)  return 'busy';
    return 'idle';
  }
  function animated(s) { return (s === 'busy' || s === 'live') && !reduce.matches; }

  function dot(color, r) {
    x.beginPath(); x.arc(50, 14, r, 0, Math.PI * 2);
    x.fillStyle = color; x.fill();
    x.lineWidth = 4; x.strokeStyle = C.halo; x.stroke();
  }

  function draw(s, now) {
    if (s === 'idle') {
      // The file itself, not a canvas copy of it: the SVG follows the OS colour
      // scheme on its own, and a rasterised snapshot would freeze whichever one was
      // active when it was drawn.
      if (link.getAttribute('href') !== IDLE) link.setAttribute('href', IDLE);
      return;
    }
    if (!ready) return;

    x.clearRect(0, 0, SIZE, SIZE);
    x.drawImage(base, 0, 0, SIZE, SIZE);

    if (s === 'busy') {
      var a = reduce.matches ? -Math.PI / 2 : ((now - t0) / 900) * Math.PI * 2;
      x.beginPath(); x.arc(32, 32, 29, a, a + Math.PI * 1.2);
      x.lineWidth = 5; x.lineCap = 'round'; x.strokeStyle = dark.matches ? C.ringOnLight : C.ring; x.stroke();
    } else if (s === 'error') {
      dot(C.error, 12);
    } else if (s === 'live') {
      var p = reduce.matches ? 1 : 0.75 + 0.25 * Math.sin((now - t0) / 260);
      dot(C.live, 10 + 2 * p);
    } else if (s === 'unread') {
      x.beginPath(); x.arc(46, 18, 16, 0, Math.PI * 2);
      x.fillStyle = C.badge; x.fill();
      x.lineWidth = 4; x.strokeStyle = C.halo; x.stroke();
      x.fillStyle = C.halo;
      x.font = '700 ' + (count > 9 ? 17 : 21) + 'px "DM Sans", system-ui, sans-serif';
      x.textAlign = 'center'; x.textBaseline = 'middle';
      x.fillText(count > 9 ? '9+' : String(count), 46, 19);
    }
    link.setAttribute('href', cv.toDataURL('image/png'));
  }

  function loop(now) {
    raf = 0;
    var s = current();
    if (now - lastWrite >= FRAME_MS) { draw(s, now); lastWrite = now; }
    if (animated(s) && !document.hidden) raf = requestAnimationFrame(loop);
  }

  /* Draw the current state now, and keep animating only if it moves and is seen. */
  function render() {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    var now = performance.now();
    draw(current(), now);
    lastWrite = now;
    if (animated(current()) && !document.hidden) raf = requestAnimationFrame(loop);
  }

  function set(s) {
    switch (s) {
      case 'busy':  flags.busy = true; break;
      case 'live':  flags.live = true; break;
      case 'error':
        flags.busy = false; flags.error = true;
        clearTimeout(errorTimer);
        errorTimer = setTimeout(function () { flags.error = false; render(); }, ERROR_MS);
        break;
      default:
        // 'idle' is "the request finished". A success clears the error too — the
        // handoff's "cleared on the next success" — but never `live`, which is a fact
        // about the PAGE and is not something one request can end.
        flags.busy = false; flags.error = false;
        clearTimeout(errorTimer);
    }
    t0 = performance.now();
    render();
  }

  function unread(n) {
    var was = count;
    count = Math.max(0, n | 0);
    var t = bareTitle();
    document.title = (count && document.hidden && count > was ? '(' + (count > 9 ? '9+' : count) + ') ' : '') + t;
    render();
  }

  /* Busy for the life of a request; a non-2xx response is a failure too, which is
     the case `fetch` itself does not reject on. Returns the original promise. */
  function track(promise) {
    set('busy');
    promise.then(function (r) { set(r && r.ok === false ? 'error' : 'idle'); },
                 function ()  { set('error'); });
    return promise;
  }

  window.agFavicon = { set: set, unread: unread, track: track, state: current };

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) document.title = bareTitle();
    render();
  });
  if (reduce.addEventListener) reduce.addEventListener('change', render);
  // The SVG re-themes itself; the canvas copy has to be redrawn from it to follow.
  if (dark.addEventListener) dark.addEventListener('change', function () {
    ready = false; base.src = IDLE + (IDLE.indexOf('?') < 0 ? '?' : '&') + 's=' + (dark.matches ? 'd' : 'l');
  });

  /* A page declares its own standing state; a submit button declares that pressing it
     starts one of the requests this is about. Both are attributes, so a page needs no
     script of its own to take part. */
  var declared = document.body && document.body.getAttribute('data-favicon');
  if (declared === 'live') set('live');

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.hasAttribute || !f.hasAttribute('data-favicon-busy')) return;
    // After every other handler: a form a validator stopped is not a request.
    setTimeout(function () { if (!e.defaultPrevented) set('busy'); }, 0);
  });
  // Back/forward cache restores the page with the ring still turning from the submit
  // that navigated away. The request it described belongs to another page now.
  window.addEventListener('pageshow', function (e) { if (e.persisted) set('idle'); });
})();
