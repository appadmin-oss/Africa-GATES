/* ══════════════════════════════════════════════════════════════════════════════
   THE PHONE CHROME — the sheet helper · Quick settings · Display & reading controls ·
   the first-visit language prompt · share
   REFERENCE §7.2–§7.5 · MobileMenu.dc.html · AppBar.dc.html · skill §3, §4, §10
   ══════════════════════════════════════════════════════════════════════════════

   A classic `defer` script, like every script here. It owns no state: every setting is
   `AGA11y`'s store and every language is a link the server acts on, so this file opens
   things, closes things, and keeps what is on screen agreeing with what is stored. The
   desktop header's controller is `header.js`; the palette is `search.js`; the Menu sheet
   — its detents, its drag and its most-used tiles — is `menu-sheet.js`, which opens through
   `AGChrome.openSheet` below and publishes `AGChrome.openMenu` for Quick settings' hand-over.

   ── SHEETS CLOSE ON BACK ────────────────────────────────────────────────────

   Skill §10: the system back gesture closes the top-most layer first. One history entry
   is pushed when a sheet opens and the `popstate` it produces closes the sheet — ONE entry
   however many sheets hand over to each other (Quick settings → the Menu's Display
   sub-view), or the back gesture would take two presses to leave a single interaction.
   Opening, the focus trap, Esc, the scrim and focus RETURNING to the trigger are
   `AGShell.openSheet()`'s (shell.js) — one implementation for every sheet on the site.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function closest(e, sel) { return e.target && e.target.closest ? e.target.closest(sel) : null; }

  /* ════════════════════════════════════════════════════════════════════════
     DISPLAY & READING — every mounted copy of the controls
     ════════════════════════════════════════════════════════════════════════ */

  /* Store → controls. On load, after every `ag:a11y`, and when a sheet opens. */
  function sync() {
    if (!window.AGA11y) return;
    var s = window.AGA11y.read(), size = s.size | 0;

    /* Each size group is a radiogroup with ONE tab stop on the chosen option. */
    all('[role="radiogroup"]').forEach(function (g) {
      var opts = all('[data-ag-size]', g);
      opts.forEach(function (b) {
        var on = parseInt(b.getAttribute('data-ag-size'), 10) === size;
        b.setAttribute('aria-checked', String(on));
        b.tabIndex = on ? 0 : -1;
      });
    });

    all('[data-ag-toggle]').forEach(function (t) {
      t.setAttribute('aria-checked', String(!!s[t.getAttribute('data-ag-toggle')]));
    });

    /* The Menu row's value ("Standard", "Large", "Largest"), in the page's language:
       the three words are rendered into the row by the template. */
    all('[data-ag-size-label]').forEach(function (l) {
      var w = l.getAttribute('data-l' + size);
      if (w) l.textContent = w;
    });
  }

  function bindA11y() {
    document.addEventListener('click', function (e) {
      var size = closest(e, '[data-ag-size]');
      if (size) { window.AGA11y.set({ size: parseInt(size.getAttribute('data-ag-size'), 10) }); return; }
      var tog = closest(e, '[data-ag-toggle]');
      if (tog) {
        var p = {};
        p[tog.getAttribute('data-ag-toggle')] = tog.getAttribute('aria-checked') !== 'true';
        window.AGA11y.set(p);
      }
    });

    /* Arrow keys move the size choice: a radiogroup that only answers Tab and Space is a
       radiogroup in name. Left and right follow the reading direction. */
    document.addEventListener('keydown', function (e) {
      var k = e.key;
      if (k !== 'ArrowLeft' && k !== 'ArrowRight' && k !== 'ArrowUp' && k !== 'ArrowDown') return;
      var here = document.activeElement;
      if (!here || !here.hasAttribute || !here.hasAttribute('data-ag-size')) return;
      var group = all('[data-ag-size]', here.closest('[role="radiogroup"]') || document);
      var i = group.indexOf(here);
      if (i < 0) return;
      var rtl = document.documentElement.dir === 'rtl';
      var back = k === 'ArrowUp' || (k === 'ArrowLeft' && !rtl) || (k === 'ArrowRight' && rtl);
      var next = group[(i + (back ? -1 : 1) + group.length) % group.length];
      e.preventDefault();
      window.AGA11y.set({ size: parseInt(next.getAttribute('data-ag-size'), 10) });
      next.focus();
    });

    window.addEventListener('ag:a11y', sync);
    sync();
  }

  /* The Display & reading language form: submit on change, and hide the button that
     only a browser without this script needs. Done HERE rather than in the template, so
     a page whose script failed keeps the button. */
  function bindLangForm() {
    all('[data-ag-langform]').forEach(function (form) {
      var sel = form.querySelector('[data-ag-lang]');
      var go = form.querySelector('.ag-dr__go');
      if (!sel) return;
      if (go) go.hidden = true;
      sel.addEventListener('change', function () { form.submit(); });
    });
  }

  /* ════════════════════════════════════════════════════════════════════════
     SHEETS
     ════════════════════════════════════════════════════════════════════════ */

  var live = null;
  var pushed = false;

  function open(sheet, scrim, trigger, onOpen) {
    if (!sheet || !window.AGShell) return null;
    if (live) live.close('handover');

    var closeSheet = window.AGShell.openSheet(sheet, scrim, trigger);
    if (!pushed) { try { history.pushState({ agSheet: 1 }, ''); pushed = true; } catch (e) {} }

    var rec = { sheet: sheet, close: close };

    /* `why`: 'pop' — the browser already took the history entry; 'handover' — another
       sheet is taking over and keeps it; anything else is a real dismissal. */
    function close(why) {
      if (live !== rec) return;
      live = null;
      if (sheet.hasAttribute('data-open')) closeSheet();
      document.body.classList.remove('ag-sheet-open');
      if (why === 'handover') return;
      if (why === 'pop') { pushed = false; return; }
      if (pushed) { pushed = false; try { history.back(); } catch (e) {} }
    }

    live = rec;
    document.body.classList.add('ag-sheet-open');

    /* The scrim and Esc close through openSheet's own close(); watching the attribute
       keeps this record honest without a second set of listeners. */
    if (window.MutationObserver) {
      var mo = new MutationObserver(function () {
        if (!sheet.hasAttribute('data-open')) { mo.disconnect(); close(); }
      });
      mo.observe(sheet, { attributes: true, attributeFilter: ['data-open'] });
    }

    if (onOpen) onOpen();
    sync();
    return close;
  }

  window.addEventListener('popstate', function () { if (live) live.close('pop'); else pushed = false; });

  /* ── Quick settings ────────────────────────────────────────────────────── */

  function bindQuick() {
    var sheet = document.querySelector('[data-ag-quick-sheet]');
    var scrim = document.querySelector('[data-ag-quick-scrim]');
    if (!sheet) return;
    var opener = null;

    document.addEventListener('click', function (e) {
      var t = closest(e, '[data-ag-quick]');
      if (t) { e.preventDefault(); opener = t; open(sheet, scrim, t); return; }
      if (closest(e, '[data-ag-quick-close]')) { if (live) live.close(); return; }
      /* "All display & reading settings" hands over to the Menu's sub-view: there is no
         settings PAGE, and inventing one would be a fourth surface for seven switches.
         Focus comes back to the avatar that started this, not to a row in a closed sheet. */
      if (closest(e, '[data-ag-quick-all]')) {
        if (live) live.close('handover');
        if (window.AGChrome.openMenu) window.AGChrome.openMenu(opener, 'display');
      }
    });
  }

  /* ── Share (the child app bar) ─────────────────────────────────────────── */

  /* The system sheet where there is one, the clipboard where there is not, and a
     visible, announced confirmation either way — a share button that appears to do
     nothing is pressed again. */
  function bindShare() {
    document.addEventListener('click', function (e) {
      var b = closest(e, '[data-ag-share]');
      if (!b) return;
      var canon = document.querySelector('link[rel="canonical"]');
      /* `data-ag-share-url`: a button sharing something other than this page — the
         nomination done screen's rally link (Phase 8). */
      var data = { title: document.title, url: b.getAttribute('data-ag-share-url') || (canon ? canon.href : location.href) };
      if (navigator.share) { navigator.share(data).catch(function () {}); return; }
      if (!navigator.clipboard) return;
      navigator.clipboard.writeText(data.url).then(function () {
        var said = b.getAttribute('aria-label');
        b.setAttribute('aria-label', b.getAttribute('data-ag-copied') || said);
        setTimeout(function () { b.setAttribute('aria-label', said); }, 1600);
      }, function () {});
    });
  }

  /* ── The first-visit language prompt ───────────────────────────────────── */

  /* The server already decided nobody has answered — the block is only in the document
     when `lang_ask()` was true. This answers the half only a browser can: is the language
     this person's browser asks for FIRST one we offer, and not English. `[0]` and not a
     scan of the list: "English, then French" reads English. It hides on the first scroll
     of <main> and does not come back on this page (§7.2). */
  function bindLangAsk() {
    var box = document.querySelector('[data-ag-langask]');
    if (!box) return;
    var want = String((navigator.languages && navigator.languages[0]) || navigator.language || '')
      .toLowerCase().split('-')[0];
    if (!want || want === 'en') return;
    var row = box.querySelector('[data-ag-langask-for="' + want + '"]');
    if (!row) return;

    row.hidden = false;
    box.hidden = false;

    var main = document.querySelector('.ag-main');
    if (!main || !window.AGShell) return;
    var stop = null;
    stop = window.AGShell.watchScroll(main, {
      onChange: function (scrolled) {
        if (!scrolled) return;
        box.hidden = true;
        if (stop) stop();
      }
    });
  }

  /* ════════════════════════════════════════════════════════════════════════ */

  window.AGChrome = { sync: sync, openSheet: open };

  function boot() {
    if (window.AGA11y) bindA11y();
    bindLangForm();
    bindQuick();
    bindShare();
    bindLangAsk();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
