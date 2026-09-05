/* ============================================================================
   Africa GATES — page motion
   ----------------------------------------------------------------------------
   Orchestration only. Every visual decision is in motion.css and the durations
   and easings come from tokens.motion.css; this file decides WHEN, never how it
   looks. That split is the reason a designer can retune the whole site by
   editing two custom properties.

   ── IT OPTS THE PAGE IN, RATHER THAN THE STYLESHEET HIDING THINGS ─────────

   motion.css hides nothing on its own: every rule is scoped under `.ag-motion`
   on <html>, and this script adds that class. So if the file 404s, a CSP change
   blocks it, the browser is ancient, or a parse error takes it out, the page
   renders fully visible and simply is not animated.

   That is the opposite of how most scroll-reveal code fails. The usual shape —
   stylesheet hides, script reveals — turns any script failure into a blank page,
   which on this site would mean an award ballot nobody can read.

   ── NO DEPENDENCY ─────────────────────────────────────────────────────────

   GSAP and ScrollTrigger are already vendored here for other work, and neither
   is needed for reveals, staggers and a counter. IntersectionObserver plus CSS
   transitions is a few hundred bytes, cannot conflict with another library's
   ScrollTrigger instance, and stays on the compositor.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;

  // No observer, no motion — and crucially, no hidden content either.
  if (!('IntersectionObserver' in window)) return;

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Opt in. Everything in motion.css is scoped under this class.
  root.classList.add('ag-motion');

  /* ── Index children so the stylesheet can stagger them ─────────────────
     The delay is `calc(var(--i) * --motion-stagger)`, so the only thing JS has
     to contribute is the ordinal. Capped: past a dozen items the tail is just
     somebody waiting, and a roll of honour with two hundred names would take
     twelve seconds to finish arriving. */
  function index(el, cap) {
    var kids = el.children, n = Math.min(kids.length, cap || 12);
    for (var i = 0; i < kids.length; i++) {
      kids[i].style.setProperty('--i', String(Math.min(i, n)));
    }
  }

  document.querySelectorAll('[data-ag-cascade]').forEach(function (el) { index(el, 12); });
  document.querySelectorAll('[data-ag-seal]').forEach(function (el) { index(el, 20); });

  /* ── Counting ──────────────────────────────────────────────────────────
     Reads the number out of the element's own text, so the markup stays the
     source of truth and the figure is correct with JS off. Anything that is not
     a digit — a percent sign, a slash, "45 / 55" — is preserved and only the
     numerals move.

     Deliberately eased and short. A counter that spins for two seconds is a
     slot machine; the job here is to draw the eye to a figure, not to perform. */
  function count(el) {
    var tpl = el.textContent || '';                 // keeps "%", "/", spacing

    /* ONE NUMBER, OR NOTHING.
       Stripping every non-digit and parsing the remainder turns "45 / 55" into
       4555, and since only the FIRST group is substituted back the element
       counts through "2733 / 55" before snapping to the right answer at the
       end. The final state is correct, which is precisely why a test that only
       checks the final state misses it.
       A composite figure is not a quantity to animate — it is two quantities
       and a separator. Left alone. */
    var groups = tpl.match(/[\d][\d,]*(\.\d+)?/g) || [];
    if (groups.length !== 1) return;

    var target = parseFloat(groups[0].replace(/,/g, ''));
    if (!isFinite(target) || target <= 0) return;   // nothing to count toward

    var decimals = (String(target).split('.')[1] || '').length;
    var start = null, dur = 900;

    function frame(now) {
      if (start === null) start = now;
      var t = Math.min(1, (now - start) / dur);
      var eased = 1 - Math.pow(1 - t, 3);           // ease-out cubic
      var v = (target * eased).toFixed(decimals);
      el.textContent = tpl.replace(/[\d.,]+/, Number(v).toLocaleString(undefined, {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      }));
      if (t < 1) requestAnimationFrame(frame);
      else el.textContent = tpl;                    // land exactly on the truth
    }
    requestAnimationFrame(frame);
  }

  /* ── One observer for everything ───────────────────────────────────────
     rootMargin pulls the trigger line up from the very bottom of the viewport:
     an element that animates the instant its first pixel appears is still
     moving when the reader gets to it. -12% means it has settled by the time it
     is worth looking at.

     Unobserved after firing. These are entrances, not scroll-linked effects —
     re-running them when somebody scrolls back up would make the page feel
     unstable rather than alive. */
  /* A counter runs when its SECTION arrives, not when the digits do.
     Observing the number directly looks right and is wrong: a figure inside a
     section that is still at opacity 0 is intersecting perfectly well, so it
     counted from nought to its value behind a transparent parent and had long
     finished by the time anybody could see it. The tick existed and was never
     once watched.
     So a counter with an animated ancestor is driven BY that ancestor, and only
     a standalone one is observed on its own. */
  function runCounters(scope) {
    if (reduced) return;
    var list = scope.hasAttribute && scope.hasAttribute('data-ag-count') ? [scope] : [];
    list = list.concat([].slice.call(scope.querySelectorAll ? scope.querySelectorAll('[data-ag-count]') : []));
    list.forEach(function (n) {
      if (n.dataset.agCounted) return;
      n.dataset.agCounted = '1';
      count(n);
    });
  }

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      var el = e.target;
      el.classList.add('is-in');
      runCounters(el);
      io.unobserve(el);
    });
  }, { rootMargin: '0px 0px -12% 0px', threshold: 0.01 });

  document
    .querySelectorAll('[data-ag-reveal], [data-ag-cascade], [data-ag-fill], [data-ag-seal]')
    .forEach(function (el) { io.observe(el); });

  // Standalone figures — no animated ancestor to wait for.
  document.querySelectorAll('[data-ag-count]').forEach(function (el) {
    if (!el.closest('[data-ag-reveal], [data-ag-cascade], [data-ag-fill], [data-ag-seal]')) io.observe(el);
  });
})();
