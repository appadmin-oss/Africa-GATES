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

   3 · A video ad plays by default, silently, while in view; it waits for play on Save-Data,
       2G or reduced motion, and can always be paused (below).

   The CSRF token is read off the card when anything is sent, never copied at load
   (csrf-fresh.js keeps every data-csrf and _token current).
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  // Page-wide listeners and timers go through agPage (layout/shell.twig), which site-nav.js
  // undoes when this page is swapped away; elsewhere they are plain (SiteNavTest).
  var pg = window.agPage || { on: function (t, y, f, o) { t.addEventListener(y, f, o); }, every: function (f, ms) { return setInterval(f, ms); }, leave: function () {} };

  // An image that does not arrive folds away rather than leaving a square box with a
  // broken-image icon, the largest thing on a phone screen. The link bar still says
  // everything the image did.
  Array.prototype.forEach.call(document.querySelectorAll('.ag-ad__media img'), function (img) {
    function gone() { var m = img.closest('.ag-ad__media'); if (m) m.hidden = true; }
    if (img.complete && img.naturalWidth === 0) gone(); else img.addEventListener('error', gone);
  });

  function post(url, body, el) {
    return fetch(url, {
      method: 'POST', keepalive: true, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': el.getAttribute('data-csrf') || '', 'Accept': 'application/json' },
      body: body
    });
  }

  // 3 · A video ad (Services\Ads\AdVideo). It plays BY DEFAULT, without sound, while at least
  //     half of it is on screen, and pauses when it leaves — how every feed plays video;
  //     `preload="none"` means nothing is fetched before then. Two exceptions, both the site's
  //     own rules rather than a choice about ads: Save-Data or a 2G connection (no autoplay on
  //     a metered phone), and reduced motion asked for. There it waits for play.
  //     Play/pause is always offered (WCAG 2.2.2: moving content longer than five seconds
  //     must be stoppable), and a pause is the reader's until they press play again — the
  //     clip does not restart itself the next time it scrolls into view. Sound is one tap.
  //     A start and a watch-through (the end, or `data-watch` seconds) are each reported
  //     once for a drawn ad; on the advertiser's own previews nothing is.
  var conn = navigator.connection;
  var lowData = !!(conn && (conn.saveData || /(^|-)2g$/.test(conn.effectiveType || '')));
  // Reduced motion is the device's setting OR the site's own switch (html.ag-rm, set by the
  // accessibility panel for somebody whose phone does not offer one).
  var calm = document.documentElement.classList.contains('ag-rm')
    || !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var auto = !lowData && !calm && !!window.IntersectionObserver;
  Array.prototype.forEach.call(document.querySelectorAll('[data-ad-video]'), function (box) {
    var v = box.querySelector('video');
    if (!v) return;
    var playBtn = box.querySelector('[data-ad-play]'), snd = box.querySelector('[data-ad-sound]');
    var card = box.closest('[data-ad]');
    var watch = parseFloat(box.getAttribute('data-watch') || '0') || 0;
    var wanted = auto, seen = false, sentPlay = false, sentDone = false;
    function report(k) {
      if (!card || !window.fetch) return;
      post('/ads/video', 't=' + encodeURIComponent(card.getAttribute('data-ad') || '') + '&k=' + k, card).catch(function () {});
    }
    function state() {
      if (!playBtn) return;
      var on = !v.paused;
      playBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      playBtn.setAttribute('aria-label', playBtn.getAttribute(on ? 'data-pause' : 'data-play') || '');
    }
    function go() {
      var p = v.play();
      // A browser that refuses even muted autoplay leaves the button saying "play".
      if (p && p.catch) p.catch(function () { wanted = false; state(); });
    }
    v.addEventListener('play', state);
    v.addEventListener('pause', state);
    if (playBtn) {
      playBtn.hidden = false;
      state();
      playBtn.addEventListener('click', function () {
        if (v.paused) { wanted = true; go(); } else { wanted = false; v.pause(); }
      });
    }
    if (snd) {
      snd.hidden = false;
      snd.addEventListener('click', function () {
        v.muted = !v.muted;
        snd.setAttribute('aria-pressed', v.muted ? 'false' : 'true');
        snd.setAttribute('aria-label', snd.getAttribute(v.muted ? 'data-on' : 'data-off') || '');
        if (!v.muted && v.paused) { wanted = true; go(); }
      });
    }
    v.addEventListener('playing', function () { if (!sentPlay) { sentPlay = true; report('play'); } });
    v.addEventListener('timeupdate', function () {
      if (sentDone || !v.duration) return;
      var at = watch > 0 ? Math.min(watch, v.duration - 0.3) : v.duration - 0.3;
      if (v.currentTime >= at) { sentDone = true; report('done'); }
    });
    if (window.IntersectionObserver) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          seen = e.isIntersecting && e.intersectionRatio >= 0.5;
          if (seen && wanted && v.paused) go();
          else if (!seen && !v.paused) v.pause();
        });
      }, { threshold: [0, 0.5] }).observe(box);
    }
  });

  var cards = document.querySelectorAll('[data-ad]');
  if (!cards.length || !window.fetch) return;

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

  pg.on(document, 'submit', function (e) {
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
  pg.on(document, 'click', function (e) {
    closeMenus(e.target.closest ? e.target.closest('.ag-ad__menu') : null);
  });
  pg.on(document, 'keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.querySelector('.ag-ad__menu[open]');
    if (m) { m.removeAttribute('open'); var s = m.querySelector('summary'); if (s) s.focus(); }
  });
})();
