/* ══════════════════════════════════════════════════════════════════════════════
   COUNTING A VIEW · partials/ad-slot.twig · Services\Ads\AdDelivery
   ══════════════════════════════════════════════════════════════════════════════

   An ad drawn into a page is not a view. It is counted once at least half of it has been on
   screen for a second, which is the industry's viewable-impression rule and what an
   advertiser pays for. Each card carries a single-use token; the server counts each token
   once, so this script reporting twice costs nobody anything.

   The CSRF token is read off the card when the report is sent, never copied at load
   (csrf-fresh.js keeps every data-csrf current). With no IntersectionObserver nothing is reported: a view that
   cannot be measured is not billed.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (!window.IntersectionObserver || !window.fetch) return;
  var cards = document.querySelectorAll('[data-ad]');
  if (!cards.length) return;

  function report(el) {
    var t = el.getAttribute('data-ad') || '';
    try {
      fetch('/ads/seen', {
        method: 'POST', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': el.getAttribute('data-csrf') || '' },
        body: 't=' + encodeURIComponent(t)
      }).catch(function () {});
    } catch (e) {}
  }

  var timers = new WeakMap();
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      var el = e.target;
      if (e.isIntersecting && e.intersectionRatio >= 0.5) {
        if (!timers.has(el)) {
          timers.set(el, setTimeout(function () {
            io.unobserve(el);
            report(el);
          }, 1000));
        }
      } else if (timers.has(el)) {
        clearTimeout(timers.get(el));
        timers.delete(el);
      }
    });
  }, { threshold: [0, 0.5, 1] });

  for (var i = 0; i < cards.length; i++) io.observe(cards[i]);
})();
