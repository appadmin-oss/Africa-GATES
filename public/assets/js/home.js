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

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── the hero ───────────────────────────────────────────────────────────────
  var hero = document.querySelector('[data-hm-rotate]');
  if (hero) {
    var slides = hero.querySelectorAll('[data-hm-slide]');
    var cards = hero.querySelectorAll('[data-hm-card]');
    var dots = hero.querySelectorAll('[data-hm-dot]');
    var i = 0, timer = null, hover = false;

    var show = function (n) {
      i = (n + slides.length) % slides.length;
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
      if (reduce || hover || document.hidden) return;
      timer = setInterval(function () { show(i + 1); }, 5500);
    };

    for (var d = 0; d < dots.length; d++) {
      dots[d].addEventListener('click', function (e) { show(parseInt(e.currentTarget.getAttribute('data-hm-dot'), 10) || 0); start(); });
    }
    hero.addEventListener('mouseenter', function () { hover = true; stop(); });
    hero.addEventListener('mouseleave', function () { hover = false; start(); });
    hero.addEventListener('focusin', function () { hover = true; stop(); });
    hero.addEventListener('focusout', function () { hover = false; start(); });
    document.addEventListener('visibilitychange', start);
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
