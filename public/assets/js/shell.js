/* ══════════════════════════════════════════════════════════════════════════════
   AFRICA GATES — SHELL BEHAVIOUR
   Phase 1 of design_handoff_africa_gates · REFERENCE §6.6, §9.1, §9.2, §13
   Scroll state · collapsing sticky search · bottom-bar height · sheets
   ══════════════════════════════════════════════════════════════════════════════

   A CLASSIC SCRIPT, exposing `window.AGShell`. The handoff's snippets are ES
   modules (`export function`); every script in this codebase is a classic
   `<script defer src>` under a nonce, and `type="module"` would change both the
   execution order against the other deferred scripts and the CSP surface for
   nothing gained. The snippets are the spec for BEHAVIOUR; the repo decides
   mechanics.

   ── THE CONTRACT OTHER CODE READS (keep it, or rewrite them in the same change) ─
     window.AGShell.watchScroll(main, {threshold, onChange}) → stop()
     window.AGShell.collapsingSearch(main, block)
     window.AGShell.trackBottomUI()                → re-measure()
     window.AGShell.openSheet(sheet, scrim, trigger) → close()   (chrome.js)
     [data-scrolled]   on .ag-shell and every .ag-sticky inside <main>
     --ag-bottom-ui    on <body>, the tallest visible [data-bottom-ui]
     [data-cs] [data-cs-input] [data-cs-btn] .ag-cs__row .ag-cs__btn  (§9.2 markup)

   Everything is idempotent and does nothing on a page without the markup it looks
   for — which is every page still on layout/gates.twig, since none has `.ag-main`.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var THRESHOLD = 8;     /* §6.6 / §9.1: scrolled = scrollTop > 8 */
  var DRIFT     = 24;    /* §9.2 state 4: an opened, empty search closes after 24px */
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),' +
                  'textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  /* ── ONE passive listener per scroller ────────────────────────────────────
     §9.1: "one passive scroll listener per page". The collapsing search needs the
     raw offset on every event as well as the shared boolean, so it SUBSCRIBES to
     the scroller's single listener instead of adding a second one. Subscribers are
     kept per element, so a page with two scrollers (a sheet body, say) still gets
     exactly one listener on each. */
  var subs = (typeof WeakMap === 'function') ? new WeakMap() : null;

  function listen(main, fn) {
    var list = subs && subs.get(main);
    if (!list) {
      list = [];
      if (subs) subs.set(main, list);
      main.addEventListener('scroll', function () {
        var y = main.scrollTop;
        for (var i = 0; i < list.length; i++) list[i](y);
      }, { passive: true });
    }
    list.push(fn);
    return function () {
      var at = list.indexOf(fn);
      if (at > -1) list.splice(at, 1);
    };
  }

  /* ── Scroll state ─────────────────────────────────────────────────────────
     Computes a BOOLEAN and writes only when it changes. The chrome swaps on a
     threshold and is never scrubbed per pixel; without the early return this would
     write a layout-affecting attribute on every scroll frame. */
  function watchScroll(main, opts) {
    opts = opts || {};
    var threshold = opts.threshold == null ? THRESHOLD : opts.threshold;
    var shell = main.closest('.ag-shell');
    var state = null;

    function tick(y) {
      var v = y > threshold;
      if (v === state) return;
      state = v;
      if (shell) shell.toggleAttribute('data-scrolled', v);
      var sticky = main.querySelectorAll('.ag-sticky');
      for (var i = 0; i < sticky.length; i++) sticky[i].toggleAttribute('data-scrolled', v);
      if (opts.onChange) opts.onChange(v);
    }

    var stop = listen(main, tick);
    tick(main.scrollTop);
    return stop;
  }

  /* ── Collapsing sticky search · REFERENCE §9.2, states 1–6 ────────────────

       1  scrollTop ≤ 8                         open, button hidden
       2  scrolled with an empty query          collapsed, button shown
       3  the button opens the row              and records y0
       4  open + empty, moved > 24px from y0    collapses
       5  a non-empty query                     keeps it open
       6  back at the top                       resets to state 1

     The row is never `display:none` (the CSS collapses a grid track), so the
     accessibility tree has to be kept honest by hand: whichever of the two is not
     showing is `aria-hidden` and out of the tab order. And if the row closes while
     focus is inside it, focus MOVES to the button — otherwise a keyboard user is
     left on a control that is hidden from everybody, including their screen reader.
     Reduced motion needs nothing here: the swap is the same attribute, and shell.css
     takes the transitions to zero. */
  function collapsingSearch(main, block) {
    var input = block.querySelector('[data-cs-input]');
    var btn   = block.querySelector('[data-cs-btn]');
    var row   = block.querySelector('.ag-cs__row');
    if (!input || !btn || !row) return;
    /* AwardsPage.dc.html hides the button's WRAPPER (it animates the width) and
       takes the button itself out of the tab order; a bare button does both. */
    var wrap = btn.closest('.ag-cs__btn') || btn;

    var open = null, y0 = 0, settleUntil = 0;

    /* ── SCROLL ANCHORING MOVES THE SCROLLER WHEN THE ROW OPENS ─────────────
       Opening the row grows the sticky block, which pushes the content under it
       down — and Chromium's scroll anchoring answers by raising `scrollTop` by the
       same amount, so the content does not appear to move. That adjustment arrives
       as an ordinary scroll event, about 60px from y0, and state 4 then closed the
       row the instant it opened, sending focus straight back to the button. The
       snippet has this fault; measured on /_dev/ui at 390.

       So for as long as the row is growing, a scroll event re-bases y0 instead of
       counting as drift: the growth is the toolbar's, not the person's. The window
       is the expand duration read from the token, plus a frame or two. Safari has no
       scroll anchoring, nothing moves there, and the window simply passes. */
    function settleMs() {
      var v = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--ag-dur-2'));
      return (isNaN(v) ? 260 : v) + 120;
    }

    function set(o) {
      if (o === open) return;
      /* Whichever control is about to be hidden, if it holds focus, hands it to the
         one appearing — in both directions, so focus is never left on something
         aria-hidden (the row closing under a typing thumb, or the button vanishing
         at the top of the page). */
      var fromRow = !o && row.contains(document.activeElement);
      var fromBtn = o && open === false && document.activeElement === btn;
      open = o;
      block.toggleAttribute('data-collapsed', !o);
      row.setAttribute('aria-hidden', String(!o));
      input.tabIndex = o ? 0 : -1;
      wrap.setAttribute('aria-hidden', String(o));
      btn.tabIndex = o ? -1 : 0;
      if (fromRow) btn.focus({ preventScroll: true });
      if (fromBtn) input.focus({ preventScroll: true });
    }

    function onScroll(y) {
      if (y <= THRESHOLD) { set(true); return; }          /* states 1 and 6 */
      if (input.value.trim()) return;                      /* state 5 */
      if (open && Date.now() < settleUntil) { y0 = y; return; }
      if (open && Math.abs(y - y0) > DRIFT) set(false);    /* states 2 and 4 */
    }

    btn.addEventListener('click', function () {            /* state 3 */
      y0 = main.scrollTop;
      settleUntil = Date.now() + settleMs();
      set(true);
      input.focus({ preventScroll: true });
    });

    /* Esc collapses the row and gives focus back to the button. At the top the row
       is state 1 and has nowhere to collapse to, and a typed query is state 5 — Esc
       does nothing in either rather than throwing away what somebody wrote. */
    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (main.scrollTop <= THRESHOLD || input.value.trim()) return;
      e.preventDefault();
      set(false);
      btn.focus({ preventScroll: true });
    });

    listen(main, onScroll);
    y0 = main.scrollTop;
    set(main.scrollTop <= THRESHOLD || !!input.value.trim());
  }

  /* ── Bottom UI height ─────────────────────────────────────────────────────

     Every fixed bottom element carries [data-bottom-ui]; while one is on screen it
     sets `--ag-bottom-ui` on <body>, and when it goes the figure goes with it. Gee,
     toasts and the page's own clearance read that and nothing else (REFERENCE §7.7:
     "each fixed bar sets --ag-bottom-ui on <body> when it mounts and clears it when it
     unmounts"). Measured, never typed: the documented offsets (96 above a tab bar, 108
     above an action bar) are only "the bar plus 16", and a typed number goes stale the
     first time a bar wraps to a second line.

     ── THE FIGURE IS HOW FAR UP THE SCREEN THE BOTTOM UI REACHES ─────────────
     Not the tallest bar's HEIGHT. Bars stack: on a phone the cookie notice sits 12px
     above the tab bar, and from 600 it floats 28px up from the bottom edge; a height
     says "80" for the first and "200" for the second, and a launcher placed by either
     lands on top of the notice. The distance from the viewport's bottom edge to the
     highest top edge among them is the one number that clears all of them at once,
     and for a single bar flush with the bottom it IS its height (Phase 3, Gee).

     The VISIBILITY test is `getClientRects().length`, NOT the snippet's
     `offsetParent !== null`. `offsetParent` is null for a `position:fixed`
     element, which is what every one of these bars is on a page that scrolls the
     document — so the snippet's test excluded every bar it exists to measure, the
     variable sat at its 0px default, and the launcher landed on top of the tab bar.
     `getClientRects()` is empty for `display:none` and only then.

     MOUNT AND UNMOUNT are both seen: a bar added to the page later (a flow's sticky
     bar appearing once a choice is made) is a childList change, and it is observed for
     size from then on; one removed, hidden or re-classed re-measures without it.

     It writes only when the figure changes. It observes `style` attributes under
     <body>, and its own write IS a style attribute on <body>: an unconditional
     write would be its own trigger. */
  function trackBottomUI() {
    var last = null;
    var ro = window.ResizeObserver ? new ResizeObserver(set) : null;
    var watched = [];

    function set() {
      var h = 0;
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var els = document.querySelectorAll('[data-bottom-ui]');
      for (var i = 0; i < els.length; i++) {
        if (ro && watched.indexOf(els[i]) < 0) { ro.observe(els[i]); watched.push(els[i]); }
        if (!els[i].getClientRects().length) continue;
        var r = els[i].getBoundingClientRect();
        if (r.height <= 0) continue;
        h = Math.max(h, Math.round(vh - r.top));
      }
      var v = Math.max(0, h) + 'px';
      if (v === last) return;
      last = v;
      document.body.style.setProperty('--ag-bottom-ui', v);
    }

    if (window.MutationObserver) {
      new MutationObserver(set).observe(document.body, {
        subtree: true, childList: true,
        attributes: true, attributeFilter: ['hidden', 'class', 'data-open', 'style', 'data-bottom-ui']
      });
    }
    window.addEventListener('resize', set, { passive: true });
    set();
    return set;
  }

  /* ── Sheets and dialogs ───────────────────────────────────────────────────
     Open, move focus in, trap Tab, close on Esc and on the scrim, and give focus
     BACK to the trigger — the part most often missed and the part a keyboard user
     notices, because without it the next Tab starts again at the top of the
     document. A closed sheet is `inert`, so nothing in it is focusable or announced.
     The back gesture (skill §10) is the caller's: chrome.js pushes one history
     entry and calls the returned close() from popstate. */
  function openSheet(sheet, scrim, trigger) {
    sheet.setAttribute('data-open', '');
    sheet.removeAttribute('inert');
    if (scrim) scrim.setAttribute('data-open', '');
    if (trigger) trigger.setAttribute('aria-expanded', 'true');

    /* `offsetParent` is right HERE: it is null for a fixed element itself, but not
       for that element's children, whose offsetParent is the fixed ancestor. */
    function els() {
      return Array.prototype.filter.call(
        sheet.querySelectorAll(FOCUSABLE),
        function (e) { return e.offsetParent !== null; }
      );
    }
    (els()[0] || sheet).focus();

    function key(e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab') return;
      var f = els();
      if (!f.length) return;
      var a = f[0], z = f[f.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
      else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    }

    function close() {
      sheet.removeAttribute('data-open');
      sheet.setAttribute('inert', '');
      if (scrim) { scrim.removeAttribute('data-open'); scrim.removeEventListener('click', close); }
      document.removeEventListener('keydown', key);
      if (trigger) { trigger.setAttribute('aria-expanded', 'false'); trigger.focus(); }
    }

    document.addEventListener('keydown', key);
    if (scrim) scrim.addEventListener('click', close);
    return close;
  }

  /* ── Boot ─────────────────────────────────────────────────────────────────── */
  function boot() {
    var main = document.querySelector('.ag-main');
    if (main) {
      watchScroll(main);
      var blocks = main.querySelectorAll('[data-cs]');
      for (var i = 0; i < blocks.length; i++) collapsingSearch(main, blocks[i]);
    }
    /* Always: a page with no bar yet may mount one later, and the figure has to be
       cleared when the last one goes. */
    trackBottomUI();
  }

  window.AGShell = {
    watchScroll: watchScroll,
    collapsingSearch: collapsingSearch,
    trackBottomUI: trackBottomUI,
    openSheet: openSheet
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
