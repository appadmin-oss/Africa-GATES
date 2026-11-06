/* Home V7: the hero's rotating recognitions and the honours counter's count-up.
 *
 * Progressive: without this file the first slide shows, the counter shows its figure and
 * every link works. The count-up runs once, when the counter scrolls into view, and not at
 * all under prefers-reduced-motion (the figure is already in the markup). Multiple real
 * slides rotate every 5.5s with an explicit Pause/Play control, paused on hover or focus,
 * while the tab is hidden, and never under reduced motion.
 */
(function () {
  'use strict';
  // Page-wide listeners and timers go through agPage (layout/shell.twig), which site-nav.js
  // undoes when this page is swapped away; elsewhere they are plain (SiteNavTest).
  var pg = window.agPage || { on: function (t, y, f, o) { t.addEventListener(y, f, o); }, every: function (f, ms) { return setInterval(f, ms); }, leave: function () {} };

  var motion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  var reduced = function () { return !!(motion && motion.matches) || document.documentElement.classList.contains('ag-rm'); };

  // A daily fallback is static. Only multiple genuine recognition slides can rotate.
  var hero = document.querySelector('[data-hm-rotate]');
  if (hero) {
    var slides = hero.querySelectorAll('[data-hm-slide]');
    var cards = hero.querySelectorAll('[data-hm-card]');
    var dots = hero.querySelectorAll('[data-hm-dot]');
    var controls = hero.querySelector('[data-hm-controls]');
    var play = hero.querySelector('[data-hm-pause]');
    var live = hero.querySelector('.hm-rec-box');
    var i = 0, timer = null, hover = false, focused = false, userPaused = false;

    var wake = function (n) {
      var im = slides[(n + slides.length) % slides.length].querySelector('img[data-hm-src]');
      if (!im) return;
      if (im.getAttribute('data-hm-srcset')) im.srcset = im.getAttribute('data-hm-srcset');
      im.src = im.getAttribute('data-hm-src');
      im.removeAttribute('data-hm-src'); im.removeAttribute('data-hm-srcset');
    };
    var updateControl = function () {
      if (play) {
        play.hidden = reduced();
        play.textContent = userPaused ? play.dataset.hmPlay : play.dataset.hmStop;
      }
      hero.dataset.hmPaused = String(userPaused || reduced());
    };
    var show = function (n) {
      i = (n + slides.length) % slides.length;
      wake(i); wake(i + 1);
      for (var k = 0; k < slides.length; k++) {
        var on = k === i;
        slides[k].classList.toggle('is-on', on);
        slides[k].hidden = !on;
        slides[k].setAttribute('aria-hidden', String(!on));
        if (cards[k]) cards[k].hidden = !on;
        if (dots[k]) {
          dots[k].classList.toggle('is-on', on);
          if (on) dots[k].setAttribute('aria-current', 'true'); else dots[k].removeAttribute('aria-current');
        }
      }
    };
    var stop = function () {
      if (timer) { clearInterval(timer); timer = null; }
      if (live) live.setAttribute('aria-live', 'polite');
    };
    var start = function () {
      stop();
      updateControl();
      if (slides.length < 2 || reduced() || userPaused || hover || focused || document.hidden) return;
      if (live) live.setAttribute('aria-live', 'off');
      timer = pg.every(function () { show(i + 1); }, 5500);
    };
    for (var d = 0; d < dots.length; d++) {
      dots[d].addEventListener('click', function (event) {
        userPaused = true;
        stop();
        show(parseInt(event.currentTarget.getAttribute('data-hm-dot'), 10) || 0);
        updateControl();
      });
    }
    if (play) play.addEventListener('click', function () {
      userPaused = !userPaused;
      // Explicit Play resumes; subsequent focus or pointer interactions pause again.
      if (!userPaused) { hover = false; focused = false; }
      start();
    });
    hero.addEventListener('mouseenter', function () { hover = true; stop(); });
    hero.addEventListener('mouseleave', function () { hover = false; start(); });
    hero.addEventListener('focusin', function () { focused = true; stop(); });
    hero.addEventListener('focusout', function (event) {
      if (!hero.contains(event.relatedTarget)) { focused = false; start(); }
    });
    pg.on(document, 'visibilitychange', start);
    pg.on(document, 'ag:a11y', start);
    if (motion && motion.addEventListener) pg.on(motion, 'change', start);
    if (controls) controls.hidden = false;
    pg.leave(stop);
    start();
  }

  // ── the honours counter ────────────────────────────────────────────────────
  var ctr = document.querySelector('[data-hm-count]');
  if (ctr && !reduced() && 'IntersectionObserver' in window) {
    var to = parseInt(ctr.getAttribute('data-hm-count'), 10) || 0;
    var fmt = function (v) { return Math.round(v).toLocaleString('en-GB'); };
    var io = new IntersectionObserver(function (es) {
      if (!es[0].isIntersecting) return;
      io.disconnect();
      var t0 = null;
      var tick = function (t) {
        if (reduced()) { ctr.textContent = fmt(to); return; }
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
