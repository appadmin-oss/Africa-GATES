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

  window.AGA11y = { KEY: KEY, SWITCHES: SWITCHES, SIZES: SIZES, read: read, apply: apply, set: set };

  /* Re-apply in case the head script could not run (a cached document, a CSP failure):
     the difference between a broken setting and one that is merely late. */
  apply();
  if (root.getAttribute('data-ag-sync') === 'empty' && chosen(read())) save(read());
})();
