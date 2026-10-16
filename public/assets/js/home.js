/* Home V5: the hero's rotating recognitions and the "Recognise with Africa GATES" tabs.
 *
 * Progressive: without this file the first slide and the first audience show and every
 * link works. The rotation follows the handoff exactly: a 0.8s crossfade every 5.5s,
 * paused on hover, while the tab is hidden, and never under prefers-reduced-motion.
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

  // ── the audiences ──────────────────────────────────────────────────────────
  var aud = document.querySelector('[data-hm-aud]');
  if (aud) {
    aud.classList.add('is-js');
    var rows = aud.querySelectorAll('[data-hm-aud-row]');
    var tabs = aud.querySelectorAll('[data-hm-aud-tab]');
    var stage = aud.querySelector('[data-hm-aud-stage]');
    var pick = function (n, focus) {
      for (var k = 0; k < rows.length; k++) {
        var on = k === n;
        rows[k].classList.toggle('is-on', on);
        tabs[k].setAttribute('aria-selected', on ? 'true' : 'false');
        tabs[k].tabIndex = on ? 0 : -1;
        if (on && stage) {
          var img = rows[k].querySelector('[data-hm-aud-img]');
          if (img) stage.src = img.getAttribute('data-hm-aud-img');
        }
      }
      if (focus) tabs[n].focus();
    };
    for (var t = 0; t < tabs.length; t++) {
      tabs[t].addEventListener('click', function (e) { pick(parseInt(e.currentTarget.getAttribute('data-hm-aud-tab'), 10) || 0, false); });
      tabs[t].addEventListener('keydown', function (e) {
        var n = parseInt(e.currentTarget.getAttribute('data-hm-aud-tab'), 10) || 0;
        if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); pick((n + 1) % tabs.length, true); }
        if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); pick((n - 1 + tabs.length) % tabs.length, true); }
      });
    }
    pick(0, false);
  }
})();
