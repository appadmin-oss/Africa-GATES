/* A nominee's photographs on their page: a scroll-snap row in the portrait's slot.
 *
 * The row works with no script (swipe, or the dots as anchors). This only makes the dots
 * move the row smoothly instead of jumping the whole page to an anchor, and keeps the
 * count and the current dot in step with what is showing. Re-run on every visit
 * (data-nav="rerun"), so it binds nothing outside its own elements. */
(function () {
  'use strict';
  var box = document.querySelector('[data-vn-photos]');
  if (!box || box.hasAttribute('data-vn-ready')) return;
  box.setAttribute('data-vn-ready', '');
  var track = box.querySelector('[data-vn-track]');
  var dots = Array.prototype.slice.call(box.querySelectorAll('[data-vn-dot]'));
  var at = box.querySelector('[data-vn-at]');
  if (!track || !dots.length) return;

  /* In a right-to-left page scrollLeft runs negative, so positions are measured by size. */
  var dir = window.getComputedStyle(track).direction === 'rtl' ? -1 : 1;
  function current() {
    var w = track.clientWidth || 1;
    return Math.max(0, Math.min(dots.length - 1, Math.round(Math.abs(track.scrollLeft) / w)));
  }
  function go(i, smooth) { track.scrollTo({ left: dir * i * track.clientWidth, behavior: smooth ? 'smooth' : 'auto' }); mark(i); }
  function mark(i) {
    dots.forEach(function (d, k) { if (k === i) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current'); });
    if (at) at.textContent = String(i + 1);
  }
  dots.forEach(function (d, i) {
    d.addEventListener('click', function (e) {
      e.preventDefault();
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches || document.documentElement.classList.contains('ag-rm');
      go(i, !reduce);
    });
  });
  var raf = 0;
  track.addEventListener('scroll', function () {
    if (raf) return;
    raf = window.requestAnimationFrame(function () { raf = 0; mark(current()); });
  }, { passive: true });
  /* Arrow keys on the focused row, for a keyboard. */
  track.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    e.preventDefault();
    var i = current() + ((e.key === 'ArrowRight') === (dir === 1) ? 1 : -1);
    if (i < 0 || i >= dots.length) return;
    go(i, false);
  });
})();
