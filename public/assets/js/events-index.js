/* ══════════════════════════════════════════════════════════════════════════════
   EVENTS INDEX — `/events` · EVENTS-INDEX (handoff 5 Oct 2026) · EventsPage.dc.html
   ══════════════════════════════════════════════════════════════════════════════

   EVERYTHING HERE IS AN UPGRADE. Without it the page is whole: the first spotlight slide
   shows, every chip, tile and "See all" is a link, and the search submits. What it adds:

   · THE SPOTLIGHT (WCAG 2.2.2). The progress bar's own CSS animation is the clock —
     `animationend` advances — so the bar and the slide can never disagree about time.
     Advancing stops while the pointer or focus is inside, when Pause is pressed, and
     always under prefers-reduced-motion (no auto-advance at all then). The live region is
     "off" while it runs and "polite" when it is still, so a screen reader is not read a
     new slide every 6.5 seconds. A slide that leaves is `hidden` + `aria-hidden`; CSS
     keeps it painted for the 600ms fade and `visibility` takes it out of the tab order.
   · ROW ARROWS, 600 and up: scroll the track by 90% of its width.
   · PHONE DOTS follow the spotlight row's scroll. Decorative; the row is the control.
   · PLACE submits its form on change.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── the spotlight ─────────────────────────────────────────────────────────
  var spot = document.querySelector('[data-ei-spot]');
  var ctl  = spot && spot.querySelector('[data-ei-ctl]');
  if (spot && ctl) {
    var stage  = spot.querySelector('[data-ei-stage]');
    var slides = Array.prototype.slice.call(spot.querySelectorAll('[data-ei-slide]'));
    var bars   = Array.prototype.slice.call(spot.querySelectorAll('[data-ei-bar]'));
    var count  = spot.querySelector('[data-ei-count]');
    var toggle = spot.querySelector('[data-ei-toggle]');
    var n = slides.length, at = 0, stopped = reduce, held = false;

    var show = function (i) {
      at = (i + n) % n;
      slides.forEach(function (s, k) {
        var on = k === at;
        s.hidden = !on;
        s.setAttribute('aria-hidden', on ? 'false' : 'true');
        if (on) s.setAttribute('data-on', ''); else s.removeAttribute('data-on');
      });
      bars.forEach(function (b, k) {
        b.setAttribute('aria-selected', k === at ? 'true' : 'false');
        if (k < at) b.setAttribute('data-done', ''); else b.removeAttribute('data-done');
        // Restart the selected bar's animation: re-inserting the fill restarts it cleanly.
        var i2 = b.querySelector('i');
        if (k === at && i2) { var c = i2.cloneNode(); i2.parentNode.replaceChild(c, i2); }
      });
      if (count) count.textContent = (at + 1) + ' / ' + n;
    };
    var running = function () {
      var on = !stopped && !held;
      if (on) spot.setAttribute('data-running', ''); else spot.removeAttribute('data-running');
      stage.setAttribute('aria-live', on ? 'off' : 'polite');
      if (toggle) toggle.setAttribute('aria-label', toggle.getAttribute(on ? 'data-pause-label' : 'data-play-label'));
    };

    ctl.hidden = false;
    spot.addEventListener('animationend', function (e) {
      if (e.animationName === 'ei-prog') show(at + 1);
    });
    bars.forEach(function (b, k) { b.addEventListener('click', function () { show(k); }); });
    spot.querySelector('[data-ei-prev]').addEventListener('click', function () { show(at - 1); });
    spot.querySelector('[data-ei-next]').addEventListener('click', function () { show(at + 1); });
    // Under reduced motion nothing ever advances, so a Play button would claim a state the
    // page cannot enter: it is not offered. Previous, Next and the bars still move by hand.
    if (toggle && reduce) toggle.hidden = true;
    if (toggle && !reduce) toggle.addEventListener('click', function () { stopped = !stopped; running(); });
    // Arrow keys move between the bars, as a tablist's should.
    spot.querySelector('[role="tablist"]').addEventListener('keydown', function (e) {
      var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
      if (!d) return;
      if (document.documentElement.dir === 'rtl') d = -d;
      e.preventDefault(); show(at + d); bars[at].focus();
    });
    var hold = function () { held = true; running(); };
    var free = function (e) {
      if (e && e.relatedTarget && spot.contains(e.relatedTarget)) return;
      if (spot.contains(document.activeElement) && e && e.type === 'mouseleave') return;
      held = false; running();
    };
    spot.addEventListener('mouseenter', hold);
    spot.addEventListener('mouseleave', free);
    spot.addEventListener('focusin', hold);
    spot.addEventListener('focusout', free);
    show(0);
    running();
  }

  // ── row arrows ────────────────────────────────────────────────────────────
  Array.prototype.forEach.call(document.querySelectorAll('[data-ei-scroll]'), function (b) {
    var sec = b.closest('section'), track = sec && sec.querySelector('[data-ei-track]');
    if (!track) return;
    b.hidden = false;
    b.addEventListener('click', function () {
      var dir = parseInt(b.getAttribute('data-ei-scroll'), 10) || 1;
      if (document.documentElement.dir === 'rtl') dir = -dir;
      track.scrollBy({ left: dir * track.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' });
    });
  });

  // ── phone dots ────────────────────────────────────────────────────────────
  var ph = document.querySelector('[data-ei-phtrack]');
  var dots = ph && ph.parentNode.querySelectorAll('.ei-spotph__dots span');
  if (ph && dots && dots.length) {
    ph.addEventListener('scroll', function () {
      var first = ph.firstElementChild, w = first ? first.getBoundingClientRect().width + 12 : 1;
      var i = Math.round(Math.abs(ph.scrollLeft) / w);
      Array.prototype.forEach.call(dots, function (d, k) {
        if (k === i) d.setAttribute('data-on', ''); else d.removeAttribute('data-on');
      });
    }, { passive: true });
  }

  // ── Place submits its form ────────────────────────────────────────────────
  Array.prototype.forEach.call(document.querySelectorAll('[data-ei-autosubmit]'), function (s) {
    s.addEventListener('change', function () {
      if (s.form && s.form.requestSubmit) s.form.requestSubmit(); else if (s.form) s.form.submit();
    });
  });
})();
