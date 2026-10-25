/* ══════════════════════════════════════════════════════════════════════════════
   DISPLAY & READING — THE STORE · REFERENCE §7.5, §10
   ══════════════════════════════════════════════════════════════════════════════

   One store, `localStorage["ag-a11y"]`, behind every surface that offers these settings —
   the header's Aa popover, the Menu's Display & reading sub-view and Quick settings. They
   are doors onto the same seven switches and one size, and they share this store rather
   than each keeping their own: two readers of one setting is how the halves of a feature
   come to disagree about whether it is on.

   ── AND THE MEMBER'S ACCOUNT, WHEN SIGNED IN ────────────────────────────────

   §7.5 says "localStorage plus the member profile when signed in". The layout marks a
   signed-in page with `data-ag-sync` on <html>; every change is then also saved to
   `POST /account/display` (DisplayReadingController), debounced so a run of presses is
   one request. The server writes the saved settings into the first-paint script, so the
   next device paints in them with nothing fetched. `data-ag-sync="empty"` means the
   member has never saved any: this browser's settings are adopted — sent once — rather
   than the account resetting somebody's chosen large text to standard on sign-in.

   A failed save changes nothing on screen: the device store already has it, and the next
   change tries again. Settings must never fail closed.

   ── THE FIRST APPLICATION IS IN <head>, NOT HERE ────────────────────────────

   `partials/a11y-head.twig` applies the classes before anything paints; a linked script
   runs after. That script is a deliberate duplicate of `apply()` and DisplayReadingTest
   compares the two maps key by key.

   ── THE EASY-READ FACE IS FETCHED ONLY FOR SOMEBODY WHO TURNS IT ON ─────────

   The address is the layout's `data-ag-easy-font`. Before this, `ag-easy` named
   Atkinson Hyperlegible and nothing loaded it (GAPS §2), so the "easy-read font" changed
   the letter spacing and silently kept the old face.

   The LANGUAGE is not in here: it is a cookie the server reads (Support\Languages), and a
   second copy in this store would disagree with <html lang> the first time one was
   cleared. Every read and write is wrapped — `localStorage` throws in a Safari private
   window, and a settings store that takes the page down is worse than one that forgets.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var KEY = 'ag-a11y';

  /* The seven switches in DisplayReading's order, and the <html> class each adds.
     `listen` adds a Listen control to articles and profiles; it restyles nothing. */
  var SWITCHES = [
    { k: 'hc',     cls: 'ag-hc' },
    { k: 'easy',   cls: 'ag-easy' },
    { k: 'space',  cls: 'ag-ls' },
    { k: 'ul',     cls: 'ag-ul' },
    { k: 'motion', cls: 'ag-rm' },
    { k: 'saver',  cls: 'ag-saver' },
    { k: 'listen', cls: '' }
  ];

  /* 100 / 125 / 150% of the root. Standard is the absence of a class. */
  var SIZES = ['', 'ag-t125', 'ag-t150'];

  var root = document.documentElement;

  /* Kept between visits only with the visitor's Preferences answer (CookiePrefs, handed
     over by the layout as `data-ag-keep`); otherwise for this tab. The head script has
     already moved any copy into the store the answer allows. Both stores are named at
     each call rather than held in a variable, so CookieRegistryTest's sweep sees every
     write. */
  function keep() { return root.getAttribute('data-ag-keep') === '1'; }

  function read() {
    try {
      var raw = keep() ? localStorage.getItem(KEY) : sessionStorage.getItem(KEY);
      var s = JSON.parse(raw || '{}');
      return (s && typeof s === 'object') ? s : {};
    } catch (e) { return {}; }
  }

  function write(s) {
    try {
      if (keep()) localStorage.setItem(KEY, JSON.stringify(s));
      else sessionStorage.setItem(KEY, JSON.stringify(s));
    } catch (e) {}
  }

  function loadEasyFont() {
    var href = root.getAttribute('data-ag-easy-font');
    if (!href || document.getElementById('ag-easy-font')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet';
    l.id = 'ag-easy-font';
    l.href = href;
    document.head.appendChild(l);
  }

  /* Idempotent: every class this owns is removed first, so applying twice can never
     leave a stale one behind. */
  function apply(s) {
    s = s || read();
    var c = root.classList, i;
    for (i = 1; i < SIZES.length; i++) c.remove(SIZES[i]);
    for (i = 0; i < SWITCHES.length; i++) if (SWITCHES[i].cls) c.remove(SWITCHES[i].cls);

    var size = SIZES[(s.size | 0)];
    if (size) c.add(size);
    for (i = 0; i < SWITCHES.length; i++) {
      if (s[SWITCHES[i].k] && SWITCHES[i].cls) c.add(SWITCHES[i].cls);
    }
    if (s.easy) loadEasyFont();
  }

  /* ── The account half ──────────────────────────────────────────────────── */

  var timer = null;

  function save(s) {
    var mode = root.getAttribute('data-ag-sync');
    if (!mode || !window.fetch) return;
    var meta = document.querySelector('meta[name="ag-csrf"]');
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch('/account/display', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': meta ? meta.getAttribute('content') : '',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ prefs: s })
      }).then(function (r) {
        if (r.ok) root.setAttribute('data-ag-sync', 'saved');
      }).catch(function () {});
    }, 400);
  }

  function set(patch) {
    var s = read();
    for (var k in patch) if (Object.prototype.hasOwnProperty.call(patch, k)) s[k] = patch[k];
    write(s);
    apply(s);
    save(s);
    /* Every mounted surface re-reads rather than keeping its own copy. */
    window.dispatchEvent(new CustomEvent('ag:a11y', { detail: s }));
    return s;
  }

  /* Anything other than the defaults? Used to decide whether a member with nothing saved
     has something on this device worth adopting. */
  function chosen(s) {
    if ((s.size | 0) !== 0) return true;
    for (var i = 0; i < SWITCHES.length; i++) if (s[SWITCHES[i].k]) return true;
    return false;
  }

  /* ── Read pages aloud ───────────────────────────────────────────────────────
     The switch promised "Listen on every article and profile" and nothing read it: the
     Listen pills on the legal pages, blog posts and profiles were there whether it was on
     or off, and every other page had none. Now, while it is on, any page that does not
     carry its own Listen control gets one at the top of <main>, reading the page's own
     text with the browser's speech synthesis — the same engine those pills use, so no
     voice, file or service is added. Off, it is removed and anything speaking stops.

     It reads what a sighted reader reads: headings, paragraphs, list items, table cells
     and captions inside <main>, skipping anything hidden, aria-hidden, or itself a control
     — and skipping the Gee panel and dialogs, which are not the page. */
  var OWN_LISTEN = '[data-doc-listen],[data-blog-listen],[data-pp-listen]';
  var bar = null, speaking = 'idle';
  function listenText() {
    var main = document.getElementById('main');
    if (!main) return [];
    var out = [];
    main.querySelectorAll('h1,h2,h3,h4,p,li,th,td,blockquote,figcaption,dt,dd').forEach(function (el) {
      if (el.closest('[hidden],[aria-hidden="true"],nav,[role="dialog"],.ag-listen,button,select,label')) return;
      if (el.closest('li') && el.tagName !== 'LI') return;
      if (!el.getClientRects().length) return;
      var t = el.textContent.replace(/\s+/g, ' ').trim();
      if (t) out.push(t);
    });
    return out;
  }
  function listenWord(k) { return root.getAttribute('data-ag-listen-' + k) || ({ listen: 'Listen to this page', pause: 'Pause', resume: 'Resume', stop: 'Stop' })[k]; }
  function listenSet(s) {
    speaking = s;
    if (!bar) return;
    var go = bar.querySelector('[data-ag-listen-go]'), stop = bar.querySelector('[data-ag-listen-stop]');
    go.setAttribute('aria-pressed', s === 'speaking' ? 'true' : 'false');
    go.querySelector('span').textContent = listenWord(s === 'speaking' ? 'pause' : s === 'paused' ? 'resume' : 'listen');
    stop.hidden = s === 'idle';
  }
  function listenBar(s) {
    var synth = window.speechSynthesis;
    var want = !!(s || read()).listen && synth && typeof window.SpeechSynthesisUtterance === 'function'
      && document.getElementById('main') && !document.querySelector(OWN_LISTEN);
    if (!want) {
      if (bar) { if (speaking !== 'idle') synth.cancel(); bar.parentNode.removeChild(bar); bar = null; speaking = 'idle'; }
      return;
    }
    if (bar) return;
    bar = document.createElement('div');
    bar.className = 'ag-listen';
    bar.innerHTML = '<button type="button" class="ag-listen__b" data-ag-listen-go aria-pressed="false">'
      + '<svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true"><path d="M3 1.5v11l9-5.5z"/></svg><span></span></button>'
      + '<button type="button" class="ag-listen__b" data-ag-listen-stop hidden><span></span></button>';
    bar.querySelector('[data-ag-listen-stop] span').textContent = listenWord('stop');
    var main = document.getElementById('main');
    main.insertBefore(bar, main.firstChild);
    listenSet('idle');
    bar.querySelector('[data-ag-listen-go]').addEventListener('click', function () {
      if (speaking === 'speaking') { synth.pause(); listenSet('paused'); return; }
      if (speaking === 'paused') { synth.resume(); listenSet('speaking'); return; }
      synth.cancel();
      var list = listenText(), lang = root.getAttribute('lang') || 'en';
      if (!list.length) return;
      list.forEach(function (t, i) {
        var u = new window.SpeechSynthesisUtterance(t);
        u.lang = lang;
        if (i === list.length - 1) u.onend = function () { listenSet('idle'); };
        synth.speak(u);
      });
      listenSet('speaking');
    });
    bar.querySelector('[data-ag-listen-stop]').addEventListener('click', function () {
      synth.cancel(); listenSet('idle');
      bar.querySelector('[data-ag-listen-go]').focus();
    });
  }
  window.addEventListener('ag:a11y', function (e) { listenBar(e.detail); });
  window.addEventListener('pagehide', function () { if (speaking !== 'idle' && window.speechSynthesis) window.speechSynthesis.cancel(); });

  /* ── A control that submits its form when it changes ─────────────────────────
     Seven filters on the public site reload the page the moment a select or checkbox
     changes. Two things went wrong for anybody not using a mouse, both fixed here once
     rather than seven times:

     · ON A KEYBOARD, A CLOSED <select> FIRES `change` ON EVERY ARROW PRESS (Windows
       browsers). So the first ↓ reloaded the page, and nobody on a keyboard could ever
       reach the fifth option (WCAG 3.2.2). A change that came from the keyboard waits for
       Enter, or for focus to leave the control; a pointer pick still applies at once.
     · THE RELOAD DROPPED FOCUS AT THE TOP OF THE PAGE, a whole header away from the next
       filter. The control is named in the URL's fragment (a GET form keeps its action's
       fragment) and focused again on arrival — which also has a screen reader say its new
       value, the confirmation the reload otherwise swallowed. */
  function placeKey(el) {
    var f = el.form, fi = Array.prototype.indexOf.call(document.forms, f);
    var k = String(el.name || el.type || 'x').replace(/[^\w-]/g, '');
    if (el.type === 'checkbox' || el.type === 'radio') k += '-' + String(el.value).replace(/[^\w-]/g, '');
    return 'agf-' + fi + '-' + k;
  }
  function autoSubmit(el, form) {
    form = form || el.form;
    if (!form) return;
    var keyed = false, pending = false;
    function go() {
      pending = false;
      var a = form.getAttribute('action') || location.pathname;
      form.setAttribute('action', a.split('#')[0] + '#' + placeKey(el));
      if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    }
    if (el.tagName === 'SELECT') {
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && pending) { e.preventDefault(); go(); return; }
        if (e.key !== 'Tab' && e.key !== 'Shift') keyed = true;
      });
      el.addEventListener('pointerdown', function () { keyed = false; });
      el.addEventListener('blur', function () { if (pending) go(); });
    }
    el.addEventListener('change', function () {
      if (keyed) { pending = true; keyed = false; return; }
      go();
    });
  }
  (function refocus() {
    var m = /^#(agf-(\d+)-[\w-]+)$/.exec(location.hash);
    if (!m) return;
    var f = document.forms[+m[2]];
    if (!f) return;
    for (var i = 0; i < f.elements.length; i++) {
      if (placeKey(f.elements[i]) === m[1]) { f.elements[i].focus(); return; }
    }
  })();

  window.AGA11y = { KEY: KEY, SWITCHES: SWITCHES, SIZES: SIZES, read: read, apply: apply, set: set, autoSubmit: autoSubmit,
    keepPlace: function (form, el) { var a = form.getAttribute('action') || location.pathname; form.setAttribute('action', a.split('#')[0] + '#' + placeKey(el)); } };

  /* Re-apply in case the head script could not run (a cached document, a CSP failure):
     the difference between a broken setting and one that is merely late. */
  apply();
  listenBar();
  if (root.getAttribute('data-ag-sync') === 'empty' && chosen(read())) save(read());
})();
