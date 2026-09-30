/* ══════════════════════════════════════════════════════════════════════════════
   DISPLAY & READING — the store
   REFERENCE §7.5 · §10
   ══════════════════════════════════════════════════════════════════════════════

   One store, `localStorage["ag-a11y"]`, read by three surfaces: the full Display &
   reading screen, the phone Quick settings sheet, and the Menu's pushed sub-view.
   They are three ways into the same seven switches and one size, so they share a
   store rather than each keeping their own — two readers of one setting is how the
   halves of a feature come to disagree about whether it is on.

   ── THE CLASSES ARE APPLIED IN <head>, NOT HERE ──────────────────────────────

   `AGA11y.apply()` exists so a change takes effect without a reload, but the FIRST
   application happens in an inline nonce'd script in the document head, before any
   stylesheet paints. A linked file cannot do that job: it is fetched and executed
   after the first paint, so somebody who asked for 150% text would watch the page
   start at 100% and jump. The head script is a deliberate duplicate of `apply()`
   and is kept to six lines for that reason.

   ── THE LANGUAGE IS NOT IN HERE, AND THAT IS THE POINT ───────────────────────

   A language is a COOKIE (`Support\Languages::COOKIE`), because the server has to
   know it: `lang` and `dir` are on <html> in the markup that arrives, and the day a
   string catalogue exists the copy is chosen server-side too. Keeping a second copy
   in this store would be two stores for one value — the exact shape this file's own
   docblock argues against one paragraph up — and they would disagree the first time
   somebody opened the site in a browser whose storage survived a cookie clear, with
   <html lang> saying one thing and the store another.

   So the language controls on all three surfaces write the cookie and reload. This
   store holds the eight things a reload does not need.

   ── AND WHY THE READ IS WRAPPED ─────────────────────────────────────────────

   `localStorage` throws rather than returning null in a Safari private window and
   wherever site data is blocked, and this runs on every page. A settings store that
   takes the page down when storage is unavailable is worse than one that forgets.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var KEY = 'ag-a11y';

  /* The seven switches, in the order DisplayReading draws them, with the copy the
     design fixed. `cls` is the <html> class each one adds. */
  var SWITCHES = [
    { k: 'hc',     cls: 'ag-hc',    label: 'High contrast',      help: 'Darker text and borders' },
    { k: 'easy',   cls: 'ag-easy',  label: 'Easy-read font',     help: 'Atkinson Hyperlegible, more letter spacing' },
    { k: 'space',  cls: 'ag-ls',    label: 'More line spacing',  help: 'Easier to track lines' },
    { k: 'ul',     cls: 'ag-ul',    label: 'Underline all links',help: 'Links don’t rely on colour' },
    { k: 'motion', cls: 'ag-rm',    label: 'Reduce motion',      help: 'No animation or auto-play' },
    { k: 'saver',  cls: 'ag-saver', label: 'Data saver',         help: 'Photos and video load when you tap' },
    { k: 'listen', cls: '',         label: 'Read pages aloud',   help: 'Adds Listen to every article and profile' }
  ];

  /* Three steps, not a slider: 100 / 125 / 150% of the root size. The specimen
     letter is drawn at 15/19/23px so the three buttons differ visibly. */
  var SIZES = [
    { name: 'Standard text', fs: '15px', cls: '' },
    { name: 'Large text',    fs: '19px', cls: 'ag-t125' },
    { name: 'Largest text',  fs: '23px', cls: 'ag-t150' }
  ];

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
    catch (e) { return {}; }
  }

  function write(s) {
    try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {}
  }

  /* Applies the whole state. Idempotent: every class it owns is removed first, so
     calling it twice cannot leave a stale one behind. */
  function apply(s) {
    s = s || read();
    var c = document.documentElement.classList;
    var i;

    c.remove('ag-t125', 'ag-t150');
    for (i = 0; i < SWITCHES.length; i++) if (SWITCHES[i].cls) c.remove(SWITCHES[i].cls);

    var size = SIZES[s.size | 0];
    if (size && size.cls) c.add(size.cls);
    for (i = 0; i < SWITCHES.length; i++) {
      if (s[SWITCHES[i].k] && SWITCHES[i].cls) c.add(SWITCHES[i].cls);
    }

  }

  function set(patch) {
    var s = read();
    for (var k in patch) if (Object.prototype.hasOwnProperty.call(patch, k)) s[k] = patch[k];
    write(s);
    apply(s);
    /* So every open surface re-reads rather than each keeping its own copy — the
       Quick settings sheet and the Menu's sub-view can both be mounted at once. */
    window.dispatchEvent(new CustomEvent('ag:a11y', { detail: s }));
    return s;
  }

  window.AGA11y = {
    KEY: KEY, SWITCHES: SWITCHES, SIZES: SIZES,
    read: read, apply: apply, set: set,
    /* The label the Menu row shows on the right ("Standard", "Large", "Largest"). */
    sizeLabel: function () { return (SIZES[read().size | 0] || SIZES[0]).name.replace(' text', ''); }
  };

  /* Re-apply on load in case the head script could not run (CSP failure, an old
     cached document). Cheap, and it is the difference between a broken setting and
     a setting that is merely late. */
  apply();
})();
