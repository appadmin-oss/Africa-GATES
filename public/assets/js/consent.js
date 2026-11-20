/* ══════════════════════════════════════════════════════════════════════════════
   COOKIE CONSENT — progressive enhancement only
   REFERENCE §7.9 · design/CookieConsent.dc.html · partials/cookie-consent.twig
   ══════════════════════════════════════════════════════════════════════════════

   Every answer on the notice and in the preferences sheet is a plain form that posts
   to /cookies/choice, and without this file the sheet opens on `#ag-consent` (:target).
   This script only makes the same things happen IN PLACE: "Choose", the "saved" line's
   "Change" and the Menu's Cookies row (`data-ag-do="consent-open"`, the hook Phase 2
   left) open the sheet through the one sheet implementation on the site —
   `AGChrome.openSheet` (chrome.js: one history entry, back closes) over
   `AGShell.openSheet` (shell.js: focus in, Tab trapped, Esc and the scrim close, focus
   BACK to the trigger). It stores nothing and reads no cookie; the server decides.

   ── WHERE FOCUS GOES BACK TO FROM THE MENU ─────────────────────────────────

   The Menu's Cookies row is inside the Menu sheet, which closes as this one opens. Focus
   returning to a row in a closed, inert sheet goes nowhere, so the trigger handed on is
   the control that opened the Menu — the same rule chrome.js applies when Quick settings
   hands over to the Menu.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  function closest(e, sel) { return e.target && e.target.closest ? e.target.closest(sel) : null; }

  function boot() {
    var layer = document.getElementById('ag-consent');
    if (!layer) return;
    var sheet = layer.querySelector('[data-ag-consent-sheet]');
    var scrim = layer.querySelector('[data-ag-consent-scrim]');
    if (!sheet) return;

    /* At rest a sheet is inert, like every other sheet here — the markup cannot carry it,
       because a browser without this script could not lift it. header.js and search.js
       stand their shortcuts down while any modal is not inert, so this matters. */
    sheet.setAttribute('inert', '');

    function returnTo(trigger) {
      var inSheet = trigger && trigger.closest('.ag-sheet');
      if (!inSheet || inSheet === sheet) return trigger;
      return document.querySelector('[data-ag-menu][aria-expanded="true"]') ||
             document.querySelector('[data-ag-menu]') || null;
    }

    function open(trigger) {
      var back = returnTo(trigger);
      if (window.AGChrome && window.AGChrome.openSheet) window.AGChrome.openSheet(sheet, scrim, back);
      else if (window.AGShell) window.AGShell.openSheet(sheet, scrim, back);
    }

    document.addEventListener('click', function (e) {
      var t = closest(e, '[data-ag-do="consent-open"]');
      if (!t) return;
      e.preventDefault();
      open(t);
    });

    /* Arrived on `#ag-consent` (the no-script "Choose", or a link): open it properly so
       the focus trap and Esc work, and drop the fragment so `:target` no longer holds the
       sheet open after it is closed. */
    if (location.hash === '#ag-consent') {
      try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) {}
      open(document.querySelector('[data-ag-consent-bar] [data-ag-do="consent-open"]'));
    }

    /* The notice floats from 600px; the scroller keeps exactly its height as scroll room
       (consent.css reads `--ag-consent-h`, with a fallback for no script). */
    var bar = document.querySelector('[data-ag-consent-bar]');
    var shell = document.querySelector('.ag-shell');
    if (bar && shell) {
      var measure = function () { shell.style.setProperty('--ag-consent-h', bar.getBoundingClientRect().height + 'px'); };
      measure();
      if (window.ResizeObserver) new ResizeObserver(measure).observe(bar);
    }

    /* "Saved" has said its piece once it has been read; it steps aside after a while
       rather than sitting over the page. "Change" opens the sheet either way. */
    var saved = document.querySelector('[data-ag-consent-saved]');
    if (saved) setTimeout(function () { if (!saved.contains(document.activeElement)) saved.hidden = true; }, 8000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
