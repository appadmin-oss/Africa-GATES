/* Home V7: the hero's rotating recognitions and the honours counter's count-up.
 *
 * Progressive: without this file the first slide shows, the counter shows its figure and
 * every link works. The count-up runs once, when the counter scrolls into view, and not at
 * all under prefers-reduced-motion (the figure is already in the markup). The rotation
 * follows the handoff exactly: a 0.8s crossfade every 5.5s, paused on hover, while the tab
 * is hidden, and never under prefers-reduced-motion.
 */
(function () {
  'use strict';
  // Page-wide listeners and timers go through agPage (layout/shell.twig), which site-nav.js
  // undoes when this page is swapped away; elsewhere they are plain (SiteNavTest).
  var pg = window.agPage || { on: function (t, y, f, o) { t.addEventListener(y, f, o); }, every: function (f, ms) { return setInterval(f, ms); }, leave: function () {} };

  // The site's own switch as well as the system's: html.ag-rm is set by the accessibility
  // panel's reduce-motion option (a11y.js, applied in the head), for somebody whose device does not offer the setting
  // or who wants it here only. Reading the media query alone, the hero rotated and the
  // figures counted up for the people who had asked this site, in so many words, not to.
  var reduce = document.documentElement.classList.contains('ag-rm') ||
    !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  // ── the hero ───────────────────────────────────────────────────────────────
  var hero = document.querySelector('[data-hm-rotate]');
  if (hero) {
    var slides = hero.querySelectorAll('[data-hm-slide]');
    var cards = hero.querySelectorAll('[data-hm-card]');
    var dots = hero.querySelectorAll('[data-hm-dot]');
    var pause = hero.querySelector('[data-hm-pause]');
    var live = hero.querySelector('[data-hm-live]');
    var i = 0, timer = null, hover = false, held = false;

    // A slide's image is fetched just before it is shown (home.twig), and the next one with it,
    // so a crossfade never fades in an empty frame.
    var wake = function (n) {
      var im = slides[(n + slides.length) % slides.length].querySelector('img[data-hm-src]');
      if (!im) return;
      if (im.getAttribute('data-hm-srcset')) im.srcset = im.getAttribute('data-hm-srcset');
      im.src = im.getAttribute('data-hm-src');
      im.removeAttribute('data-hm-src'); im.removeAttribute('data-hm-srcset');
    };
    var show = function (n) {
      i = (n + slides.length) % slides.length;
      wake(i); wake(i + 1);
      for (var k = 0; k < slides.length; k++) {
        var on = k === i;
        slides[k].classList.toggle('is-on', on);
        slides[k].setAttribute('aria-hidden', on ? 'false' : 'true');
        if (cards[k]) cards[k].hidden = !on;
        if (dots[k]) {
          dots[k].classList.toggle('is-on', on);
          if (on) dots[k].setAttribute('aria-current', 'true'); else dots[k].removeAttribute('aria-current');
        }
      }
    };
    var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
    var start = function () {
      stop();
      if (reduce || hover || held || document.hidden) return;
      timer = pg.every(function () { quiet(true); show(i + 1); }, 5500);
    };
    // The card announces a slide somebody CHOSE, never one the timer turned to.
    var quiet = function (q) { if (live) live.setAttribute('aria-live', q ? 'off' : 'polite'); };

    for (var d = 0; d < dots.length; d++) {
      dots[d].addEventListener('click', function (e) { quiet(false); show(parseInt(e.currentTarget.getAttribute('data-hm-dot'), 10) || 0); start(); });
    }
    // WCAG 2.2.2 — a stop the person keeps. Pausing is held until they press it again; hover
    // and focus only pause for as long as they last.
    if (pause && !reduce) {
      pause.hidden = false;
      pause.addEventListener('click', function () {
        held = !held;
        pause.classList.toggle('is-paused', held);
        pause.setAttribute('aria-label', pause.getAttribute(held ? 'data-hm-play-label' : 'data-hm-pause-label'));
        if (held) stop(); else start();
      });
    }
    hero.addEventListener('mouseenter', function () { hover = true; stop(); });
    hero.addEventListener('mouseleave', function () { hover = false; start(); });
    hero.addEventListener('focusin', function () { hover = true; stop(); });
    hero.addEventListener('focusout', function () { hover = false; start(); });
    pg.on(document, 'visibilitychange', start);
    // The second slide once the page has finished loading, so the first turn is ready.
    // After the page has loaded — which, arriving by an in-place navigation, it already has.
    var later = function () { setTimeout(function () { wake(1); }, 1500); };
    if (!reduce) { if (document.readyState === 'complete') later(); else pg.on(window, 'load', later); }
    start();
  }

  // ── the honours counter ────────────────────────────────────────────────────
  var ctr = document.querySelector('[data-hm-count]');
  if (ctr && !reduce && 'IntersectionObserver' in window) {
    var to = parseInt(ctr.getAttribute('data-hm-count'), 10) || 0;
    var fmt = function (v) { return Math.round(v).toLocaleString('en-GB'); };
    var io = new IntersectionObserver(function (es) {
      if (!es[0].isIntersecting) return;
      io.disconnect();
      var t0 = null;
      var tick = function (t) {
        if (t0 === null) t0 = t;
        var p = Math.min(1, (t - t0) / 1400), e = 1 - Math.pow(1 - p, 3);
        ctr.textContent = fmt(to * e);
        if (p < 1) window.requestAnimationFrame(tick);
      };
      window.requestAnimationFrame(tick);
    }, { threshold: 0.4 });
    io.observe(ctr);
  }
})();
