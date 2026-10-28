/* ══════════════════════════════════════════════════════════════════════════════
   AN AD ON THE PAGE · partials/ad-slot.twig · Services\Ads\AdDelivery
   ══════════════════════════════════════════════════════════════════════════════

   1 · Counting a view. An ad drawn into a page is not a view. It is counted once at least
       half of it has been on screen for a second, the industry's viewable-impression rule
       and what an advertiser pays for. Each card carries a single-use token; the server
       counts each token once, so this script reporting twice costs nobody anything. With
       no IntersectionObserver nothing is reported: a view that cannot be measured is not
       billed.

   2 · The ⋯ menu. "Hide ad" and "Report ad" are plain forms that work with no script; here
       they answer in place instead of reloading, and the card folds to one line. The menu
       closes on Escape and on a tap anywhere else, as every menu does.

   The CSRF token is read off the card when anything is sent, never copied at load
   (csrf-fresh.js keeps every data-csrf and _token current).
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // An image that does not arrive folds away rather than leaving a square box with a
  // broken-image icon, the largest thing on a phone screen. The link bar still says
  // everything the image did.
  Array.prototype.forEach.call(document.querySelectorAll('.ag-ad__media img'), function (img) {
    function gone() { var m = img.closest('.ag-ad__media'); if (m) m.hidden = true; }
    if (img.complete && img.naturalWidth === 0) gone(); else img.addEventListener('error', gone);
  });

  var cards = document.querySelectorAll('[data-ad]');
  if (!cards.length || !window.fetch) return;

  function post(url, body, el) {
    return fetch(url, {
      method: 'POST', keepalive: true, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': el.getAttribute('data-csrf') || '', 'Accept': 'application/json' },
      body: body
    });
  }

  if (window.IntersectionObserver) {
    var timers = new WeakMap();
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        var el = e.target;
        if (e.isIntersecting && e.intersectionRatio >= 0.5) {
          if (!timers.has(el)) {
            timers.set(el, setTimeout(function () {
              io.unobserve(el);
              post('/ads/seen', 't=' + encodeURIComponent(el.getAttribute('data-ad') || ''), el).catch(function () {});
            }, 1000));
          }
        } else if (timers.has(el)) {
          clearTimeout(timers.get(el));
          timers.delete(el);
        }
      });
    }, { threshold: [0, 0.5, 1] });
    for (var i = 0; i < cards.length; i++) io.observe(cards[i]);
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    var act = f && f.getAttribute && f.getAttribute('data-ad-act');
    if (!act) return;
    var card = f.closest('[data-ad]');
    if (!card) return;
    e.preventDefault();
    post(f.action, new URLSearchParams(new FormData(f)).toString(), card)
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok) { f.submit(); return; }
        card.className = 'ag-ad ag-ad--done';
        card.removeAttribute('data-ad');
        card.setAttribute('role', 'status');
        card.textContent = d.message || '';
      })
      .catch(function () { f.submit(); });
  });

  function closeMenus(except) {
    var open = document.querySelectorAll('.ag-ad__menu[open]');
    for (var j = 0; j < open.length; j++) if (open[j] !== except) open[j].removeAttribute('open');
  }
  document.addEventListener('click', function (e) {
    closeMenus(e.target.closest ? e.target.closest('.ag-ad__menu') : null);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.querySelector('.ag-ad__menu[open]');
    if (m) { m.removeAttribute('open'); var s = m.querySelector('summary'); if (s) s.focus(); }
  });
})();
