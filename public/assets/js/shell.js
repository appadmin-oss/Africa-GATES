/* ══════════════════════════════════════════════════════════════════════════════
   AFRICA GATES — SHELL BEHAVIOUR
   Scroll state · collapsing search · bottom-bar measurement · sheets
   ══════════════════════════════════════════════════════════════════════════════

   A classic script exposing `window.AGShell`, not an ES module. The handoff's
   snippets are written with `export`, and this codebase loads every one of its
   own scripts as `<script defer src>` with a CSP nonce — a module would need
   `type="module"`, which changes the load order and the CSP surface for no gain
   here. The skill's own rule applies: the codebase wins on mechanics.

   Everything below is idempotent and safe to call on a page that has none of the
   markup it looks for.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';

  /* ── Scroll state ─────────────────────────────────────────────────────────
     ONE passive listener per page, and it computes a BOOLEAN. The chrome swaps
     on a threshold; it is never scrubbed per pixel (REFERENCE §6.6, §9.1). The
     early return on an unchanged value is the whole point — without it this runs
     a layout-affecting attribute write on every scroll frame. */
  function watchScroll(main, opts) {
    opts = opts || {};
    var threshold = opts.threshold == null ? 8 : opts.threshold;
    var shell = main.closest('.ag-shell');
    var state = null;

    function tick() {
      var v = main.scrollTop > threshold;
      if (v === state) return;
      state = v;
      if (shell) shell.toggleAttribute('data-scrolled', v);
      var sticky = main.querySelectorAll('.ag-sticky');
      for (var i = 0; i < sticky.length; i++) sticky[i].toggleAttribute('data-scrolled', v);
      if (opts.onChange) opts.onChange(v);
    }

    main.addEventListener('scroll', tick, { passive: true });
    tick();
    return function () { main.removeEventListener('scroll', tick); };
  }

  /* ── Collapsing sticky search · REFERENCE §9.2, states 1–6 ────────────────

     The search row is ALWAYS in the DOM. `display:none` cannot animate and
     cannot be reopened smoothly, so the CSS collapses a grid row instead and
     this only flips the attribute and keeps the accessibility tree honest:
     the hidden copy is `aria-hidden` with `tabindex="-1"`, so a keyboard user
     never lands on a control they cannot see. */
  function collapsingSearch(main, block) {
    var input = block.querySelector('[data-cs-input]');
    var btn   = block.querySelector('[data-cs-btn]');
    var row   = block.querySelector('.ag-cs__row');
    if (!input || !btn || !row) return;

    var open = true, y0 = 0;

    function set(o) {
      open = o;
      block.toggleAttribute('data-collapsed', !o);
      row.setAttribute('aria-hidden', String(!o));
      input.tabIndex = o ? 0 : -1;
      btn.setAttribute('aria-hidden', String(o));
      btn.tabIndex = o ? -1 : 0;
    }

    function onScroll() {
      var y = main.scrollTop, q = input.value.trim();
      if (y <= 8) { if (!open) set(true); return; }   /* states 1 and 6 */
      if (q) return;                                   /* state 5: a query keeps it open */
      if (open && Math.abs(y - y0) > 24) set(false);   /* states 2 and 4 */
    }

    /* State 3: opening records where we were, so the 24px test below measures
       from the tap rather than from the top of the document. */
    btn.addEventListener('click', function () { y0 = main.scrollTop; set(true); input.focus(); });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && main.scrollTop > 8 && !input.value.trim()) { set(false); btn.focus(); }
    });

    main.addEventListener('scroll', onScroll, { passive: true });
    set(main.scrollTop <= 8);
    if (!open) y0 = main.scrollTop;
  }

  /* ── Bottom UI measurement ────────────────────────────────────────────────

     Every fixed bottom element carries [data-bottom-ui]; the tallest VISIBLE one
     sets `--ag-bottom-ui` on <body>, and the Gee launcher and toasts clear it by
     16px. Measured rather than hard-coded because the three documented offsets —
     96 above the tab bar, 108 above an action bar, 40 with neither — are just
     "the bar plus the gap", and a hard-coded number goes stale the first time a
     bar gains a second line of text.

     ── THE VISIBILITY TEST EXCLUDED EVERY BAR IT EXISTS TO MEASURE ──────────

     It was `offsetParent !== null`, described here as the display:none test. It is
     not: `offsetParent` is **null for a `position:fixed` element**, which is what
     every one of these bars is. So the tallest visible bar was always none of
     them, `--ag-bottom-ui` stayed at its 0px default on every page, and the Gee
     launcher and the cookie notice sat flat against the bottom edge — on top of
     the tab bar on a phone. Nothing threw; the number simply never moved off its
     own fallback, which is the shape that survives a review.

     `getClientRects().length` is the test that means what the old comment claimed:
     zero for `display:none`, non-zero for anything laid out, fixed included. A bar
     merely translated off-screen still counts, which is correct while a sheet
     animates. */
  function trackBottomUI() {
    var bars = document.querySelectorAll('[data-bottom-ui]');

    function set() {
      var h = 0;
      var els = document.querySelectorAll('[data-bottom-ui]');
      for (var i = 0; i < els.length; i++) {
        if (els[i].getClientRects().length) h = Math.max(h, els[i].getBoundingClientRect().height);
      }
      document.body.style.setProperty('--ag-bottom-ui', h + 'px');
    }

    if (window.ResizeObserver) {
      var ro = new ResizeObserver(set);
      for (var i = 0; i < bars.length; i++) ro.observe(bars[i]);
    }
    if (window.MutationObserver) {
      new MutationObserver(set).observe(document.body, {
        subtree: true, attributes: true, attributeFilter: ['hidden', 'class', 'data-open', 'style']
      });
    }
    window.addEventListener('resize', set, { passive: true });
    set();
    return set;
  }

  /* ── Sheets and dialogs ───────────────────────────────────────────────────

     Open, trap Tab, close on Esc and on the scrim, and RESTORE FOCUS to the
     trigger. The restore is the part most often missed and the part a keyboard
     user notices: without it, closing a sheet drops focus on <body> and the next
     Tab starts again at the top of the document.

     Back closes the top-most layer first (skill §10), so the caller pushes a
     history entry and calls the returned close() from popstate. */
  function openSheet(sheet, scrim, trigger) {
    sheet.setAttribute('data-open', '');
    sheet.removeAttribute('inert');
    if (scrim) scrim.setAttribute('data-open', '');
    if (trigger) trigger.setAttribute('aria-expanded', 'true');

    /* `offsetParent` is the right test HERE and the wrong one in trackBottomUI,
       and the difference is worth stating: it is null for a fixed element itself,
       and NOT null for that element's children — their offsetParent is the fixed
       ancestor. These are the sheet's children. */
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

  /* ── Boot ─────────────────────────────────────────────────────────────────
     Wires whatever the page happens to have. A page with no .ag-main is an
     unconverted one and simply gets nothing. */
  function boot() {
    var main = document.querySelector('.ag-main');
    if (main) {
      watchScroll(main);
      var blocks = main.querySelectorAll('[data-cs]');
      for (var i = 0; i < blocks.length; i++) collapsingSearch(main, blocks[i]);
    }
    if (document.querySelector('[data-bottom-ui]')) trackBottomUI();
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
