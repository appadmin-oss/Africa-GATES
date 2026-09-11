/* ════════════════════════════════════════════════════════════════════════════
   Africa GATES — the winner's moment

   One choreographed celebration, used wherever this platform tells somebody
   they have won. Particles come from canvas-confetti (ISC, vendored); the
   timing is here.

   The index tick is ag-motion.js's `agCount`, driven here on a beat rather than
   on an observer. This file owns the choreography and the particles; it owns no
   second copy of anything the motion system already does.

   ── WHAT MAKES THIS A CELEBRATION AND NOT A CONFETTI CALL ───────────────────
   A single burst on page load reads as decoration. What reads as an occasion is
   a SEQUENCE with anticipation in it: the index counts up while nothing else
   moves, the field opens from the lower corners as it lands, and a last shimmer
   falls from the badge a beat later. Three beats, ~1.6s, then it is gone and the
   page is a document again.

   ── FIVE THINGS IT MUST NOT DO ──────────────────────────────────────────────

   1. IT MUST NOT BE THE REASON THE NAME IS ON SCREEN. Nothing here hides
      content and reveals it — the winner's name and their index are rendered by
      the server and are complete before this file loads. If it never loads, or
      throws, or is blocked, the page is exactly the page. This codebase has
      shipped a dead camera, a mute door and a stop-link nobody was handed;
      every one of them looked available. A result page may not join them.

   2. IT MUST NOT FIRE TWICE. A celebration that replays on every visit is not a
      celebration, it is a page that will not let you read it. Once per result
      per browser, remembered in localStorage — which can throw (private
      windows, blocked site data), so a failure to remember means it simply
      plays again rather than not at all.

   3. IT MUST NOT PLAY A SOUND. Not a taste call: `Permissions-Policy` on this
      site denies `autoplay`, and both mobile browsers gate audible playback on
      a user gesture the page never had. An award page that tries would fail
      silently on every device — which is how the door's greeting went unheard
      for months here.

   4. IT MUST HONOUR `prefers-reduced-motion`. Somebody who has asked for less
      movement gets the result and no particles — not a degraded animation, none.

   5. IT MUST NOT COVER THE NAME. The canvas is fixed, pointer-transparent and
      aria-hidden; the count-up runs in place and never reflows the line.
   ════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var GOLD = '#f3b416', EMERALD = '#237b22', LEAF = '#7fc87c', PAPER = '#fffdf5', INK = '#10292c';

  function reduced() {
    try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    catch (e) { return false; }
  }

  /** localStorage, which is allowed to be missing. A forgotten play is not a failure. */
  function seen(key) {
    try { return window.localStorage.getItem('ag-celebrated:' + key) === '1'; }
    catch (e) { return false; }
  }
  function remember(key) {
    try { window.localStorage.setItem('ag-celebrated:' + key, '1'); } catch (e) { /* fine */ }
  }

  /** Our own canvas, so nothing is appended to <body> that outlives the moment. */
  function stage() {
    var c = document.createElement('canvas');
    c.setAttribute('aria-hidden', 'true');
    c.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:60';
    document.body.appendChild(c);
    return c;
  }

  var AG = window.agCelebrate = function (opts) {
    opts = opts || {};
    var key = opts.key || location.pathname;
    if (opts.once !== false && seen(key)) return;
    remember(key);

    var figure = opts.figure || null;
    var quiet  = reduced();

    // The count-up is motion too, so it goes with the particles — and it is
    // ag-motion.js's counter, not a second one. That one already knows a thousands
    // separator has to survive the animation and that a composite figure is not a
    // quantity to animate; a copy here would be the screen that counts through
    // "2733 / 55". It is a no-op under reduced motion on its own account.
    if (figure && typeof window.agCount === 'function') window.agCount(figure);
    if (quiet || typeof window.confetti !== 'function') return;

    var canvas = stage();
    // NO WORKER. canvas-confetti's worker is built from a Blob URL, and this site's CSP
    // has no `worker-src` — which falls back to `script-src`, and that is `'self'` plus a
    // nonce. A blob: worker is refused there, and the library's own fallback then does the
    // work on the main thread anyway, having first logged a CSP violation to the console
    // on every award page. Asking for the fallback directly gets the same rendering with
    // nothing in the console. 110 particles for three seconds is not worth a policy change.
    var fire = window.confetti.create(canvas, { resize: true, useWorker: false });
    var opened = Date.now();

    // BEAT ONE — the field opens from the lower corners, angled inward, while the
    // number is still climbing. Two cannons rather than one centre burst: a single
    // source reads as a party popper, two reads as a room.
    setTimeout(function () {
      [{ x: 0.06, angle: 62 }, { x: 0.94, angle: 118 }].forEach(function (s) {
        fire({
          particleCount: 62, angle: s.angle, spread: 58, startVelocity: 52,
          origin: { x: s.x, y: 0.96 }, ticks: 240, scalar: 1.05,
          colors: [GOLD, EMERALD, LEAF, PAPER],
          disableForReducedMotion: true
        });
      });
    }, 240);

    // BEAT TWO — a slower, wider fall from above the badge as the number lands.
    setTimeout(function () {
      var box = opts.anchor ? opts.anchor.getBoundingClientRect() : null;
      var ox = box ? (box.left + box.width / 2) / window.innerWidth : 0.5;
      // ABOVE the badge, not on it. Spawning at the anchor's own top edge put a dense
      // clump directly over the word it was celebrating — the one piece of the page a
      // photograph of this moment is of.
      var oy = box ? Math.max(0, (box.top / window.innerHeight) - 0.06) : 0.2;
      fire({
        particleCount: 42, spread: 150, startVelocity: 18, gravity: 0.62, decay: 0.93,
        origin: { x: ox, y: oy }, ticks: 320, scalar: 0.85,
        colors: [GOLD, PAPER, LEAF, INK],
        disableForReducedMotion: true
      });
    }, 720);

    // Take the canvas away once the last particle can no longer be on it. Left in
    // place it is a full-screen fixed element over every page the visitor scrolls
    // to next — pointer-transparent, so the symptom would be a phone getting warm
    // rather than anything anybody could see.
    setTimeout(function () {
      try { fire.reset(); } catch (e) { /* already gone */ }
      if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
    }, Math.max(3200, 3200 - (Date.now() - opened)));
  };

  // ── Declarative mount ──────────────────────────────────────────────────────
  // A `data-celebrate` element is the whole integration: no page needs to know
  // the beat timings, and there is one implementation to correct.
  function boot() {
    var host = document.querySelector('[data-celebrate]');
    if (!host) return;
    AG({
      key: host.getAttribute('data-celebrate') || location.pathname,
      figure: host.querySelector('[data-celebrate-figure]'),
      anchor: host.querySelector('[data-celebrate-anchor]') || host
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
