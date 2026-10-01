/* ══════════════════════════════════════════════════════════════════════════════
   THE PROMO BAND — the index, and nothing else
   ══════════════════════════════════════════════════════════════════════════════

   The motion is CSS. This script moves a number and sets attributes; it never writes
   a style beyond the one custom property the track reads. So with JavaScript off the
   band is the first slide, readable, with a working link — rather than a dead frame
   or a stack of five.

   WHAT 2.2.2 ACTUALLY ASKS FOR, AND THE FOUR THINGS THAT STOP THE TIMER

   Anything that moves automatically for more than five seconds needs a way to pause.
   Four things stop it here and each is a different person:

     · the pause button        — somebody who wants it still
     · hover                   — somebody reading with a mouse on it
     · focus-within            — somebody reading with a keyboard inside it. The comp
                                 does NOT do this and §4 requires it: tabbing into a
                                 slide that then slides away loses what you were
                                 reading with no way back.
     · prefers-reduced-motion  — somebody for whom the movement itself is the problem.
                                 Checked LIVE rather than once at load, because the
                                 setting can change while the page is open.

   AND THE PART THAT IS EASY TO GET WRONG

   An off-screen slide must leave the tab order. Without `inert` a keyboard user tabs
   into a slide nobody can see and the viewport scrolls sideways to nothing — the
   commonest carousel fault there is. The server renders the first slide active and
   this keeps it true as the index moves.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var EVERY = 5000;

  function setup(band) {
    if (band.hasAttribute('data-pb-ready')) return;
    band.setAttribute('data-pb-ready', '');

    var track  = band.querySelector('[data-pb-track]');
    var slides = Array.prototype.slice.call(band.querySelectorAll('.pb__slide'));
    var dots   = Array.prototype.slice.call(band.querySelectorAll('[data-pb-go]'));
    var pause  = band.querySelector('[data-pb-pause]');
    var n      = slides.length;

    if (!track || n < 2) return;   // one slide does not tick and has no dots

    var at = 0, stopped = false, hovering = false, timer = null;

    var reduced = function () {
      /* Read each time. Somebody turning the setting on mid-session should not have to
         reload to be taken seriously. */
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    };

    var running = function () {
      return !stopped && !hovering && !band.contains(document.activeElement) && !reduced();
    };

    function show(i) {
      at = ((i % n) + n) % n;
      track.style.setProperty('--pb-at', String(at));

      slides.forEach(function (s, k) {
        var on = k === at;
        s.toggleAttribute('aria-hidden', !on);
        /* `inert` takes the slide and everything in it out of the tab order and the
           accessibility tree in one attribute. The tabindex fallback is for browsers
           that have not shipped it. */
        s.toggleAttribute('inert', !on);
        var cta = s.querySelector('.pb__cta');
        if (cta) cta.setAttribute('tabindex', on ? '0' : '-1');
      });

      dots.forEach(function (d, k) {
        d.setAttribute('aria-current', k === at ? 'true' : 'false');
      });

      /* Restart the dot's 5s fill on every change, so it always describes the time
         left on THIS slide rather than drifting out of step with the timer. */
      band.setAttribute('data-pb-tick', String(Date.now()));
    }

    function tick() {
      if (running()) show(at + 1);
    }

    function arm() {
      if (timer) window.clearInterval(timer);
      timer = window.setInterval(tick, EVERY);
    }

    function paint() {
      var go = running();
      band.toggleAttribute('data-pb-paused', !go);
      if (pause) {
        /* The label says what pressing it DOES, which is the thing a screen reader
           reads. An icon that swaps with no label change is a button that says the
           same thing in both states. */
        pause.setAttribute('aria-label', stopped ? 'Play slides' : 'Pause slides');
        pause.setAttribute('aria-pressed', stopped ? 'true' : 'false');
      }
    }

    dots.forEach(function (d) {
      d.addEventListener('click', function () {
        show(parseInt(d.getAttribute('data-pb-go'), 10) || 0);
        arm();     // a deliberate move restarts the clock rather than being cut short
      });
    });

    if (pause) {
      pause.addEventListener('click', function () {
        stopped = !stopped;
        paint();
        if (!stopped) arm();
      });
    }

    band.addEventListener('mouseenter', function () { hovering = true;  paint(); });
    band.addEventListener('mouseleave', function () { hovering = false; paint(); });
    band.addEventListener('focusin',  paint);
    band.addEventListener('focusout', function () {
      /* `focusout` fires before the new element has focus, so the check has to wait a
         tick or it always reads as "nothing focused" and resumes while the person is
         still inside the band. */
      window.setTimeout(paint, 0);
    });

    /* A band scrolled out of view should not be advancing: it burns a wake-up every
       five seconds on a phone to move something nobody is looking at. */
    if (window.IntersectionObserver) {
      new IntersectionObserver(function (es) {
        es.forEach(function (e) {
          band.toggleAttribute('data-pb-off', !e.isIntersecting);
          if (e.isIntersecting) arm();
        });
      }, { threshold: 0.1 }).observe(band);
    }

    /* Keyboard: left and right move between slides when focus is inside the band,
       which is what somebody expects of a thing announced as a carousel. */
    band.addEventListener('keydown', function (ev) {
      if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
      ev.preventDefault();
      show(at + (ev.key === 'ArrowRight' ? 1 : -1));
      arm();
    });

    show(0);
    paint();
    arm();
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ag-promos]'), setup);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
