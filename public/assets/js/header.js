/* ══════════════════════════════════════════════════════════════════════════════
   AFRICA GATES — THE SITE HEADER
   Toolbar · the Aa and language popovers · the basket badge
   REFERENCE §7.1
   ══════════════════════════════════════════════════════════════════════════════

   The mega panels are NOT here. Their controller stays inline in
   `layout/nav.twig`, because phase §7.1 says to keep it and it carries three
   fixes that are invisible until they are missing. This file is everything the
   redesign added beside it.

   Classic script, `defer`, no module — the reason shell.js gives.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var head = document.querySelector('[data-ag-head]');
  if (!head) return;

  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* ════════════════════════════════════════════════════════════════════════
     THE TWO POPOVERS
     ════════════════════════════════════════════════════════════════════════ */

  /* Open one and everything else closes — the megas, the other popover, the
     search palette. §7.1 states it as a rule and it is the difference between a
     header and a pile of panels: two things hanging off a 64px bar at once
     overlap, and the one underneath is unreachable by pointer.

     These are NOT modal. There is no scrim in the DC, the page behind stays
     live, and a focus trap on something that is not modal traps somebody inside
     a panel they did not think they had entered. Esc closes, a click outside
     closes, and focus goes back to the trigger — which is the part most often
     skipped and the part a keyboard user notices, because without it the next
     Tab restarts at the top of the document. */
  var openPop = null;

  function closePop(restoreFocus) {
    if (!openPop) return;
    var p = openPop;
    openPop = null;
    p.panel.removeAttribute('data-open');
    p.panel.setAttribute('inert', '');
    p.trigger.setAttribute('aria-expanded', 'false');
    if (restoreFocus) p.trigger.focus();
  }

  function showPop(name, trigger) {
    var panel = head.querySelector('[data-ag-pop-panel="' + name + '"]');
    if (!panel) return;

    if (openPop && openPop.name === name) { closePop(true); return; }
    closePop(false);
    closeEverythingElse();

    panel.removeAttribute('inert');
    panel.setAttribute('data-open', '');
    trigger.setAttribute('aria-expanded', 'true');
    openPop = { name: name, panel: panel, trigger: trigger };

    var first = panel.querySelector('a[href],button:not([disabled]),select,input:not([disabled])');
    if (first) first.focus();
  }

  /* Closing the megas is done through their own trigger rather than by reaching
     into their classes: the inline controller owns that state, and two owners of
     one open/closed flag is how a panel ends up visually shut with
     `aria-expanded="true"` still on its button. */
  function closeEverythingElse() {
    all('.ag-mega-wrap.is-open [data-ag-mega-trigger]').forEach(function (b) { b.click(); });
  }

  head.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-ag-pop]') : null;
    if (!t) return;
    e.preventDefault();
    e.stopPropagation();
    showPop(t.getAttribute('data-ag-pop'), t);
  });

  document.addEventListener('click', function (e) {
    if (!openPop) return;
    if (e.target.closest && (e.target.closest('[data-ag-pop-panel]') || e.target.closest('[data-ag-pop]'))) return;
    closePop(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && openPop) closePop(true);
  });

  /* A mega opening must close a popover too — the rule runs both ways, and the
     inline controller knows nothing about these. */
  all('[data-ag-mega-trigger]').forEach(function (b) {
    b.addEventListener('click', function () { closePop(false); }, true);
  });

  /* ════════════════════════════════════════════════════════════════════════
     THE TOOLBAR
     ════════════════════════════════════════════════════════════════════════ */

  /* `role="toolbar"` is a CLAIM: one Tab stop, and the arrow keys move between
     the controls inside it. A toolbar that only answers Tab is a toolbar in
     name, and the markup would then be telling a screen-reader user to press
     keys that do nothing. Set from script for the same reason `ag-search.js`
     sets its combobox roles from script: if this file fails, the three buttons
     are three ordinary Tab stops, which is worse than the toolbar and much
     better than a lie. */
  var bar = head.querySelector('[role="toolbar"]');
  if (bar) {
    var btns = all('.ag-tools__b', bar);
    btns.forEach(function (b, i) { b.tabIndex = i === 0 ? 0 : -1; });

    bar.addEventListener('keydown', function (e) {
      var i = btns.indexOf(document.activeElement);
      if (i < 0) return;
      var step = 0;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') step = 1;
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') step = -1;
      else if (e.key === 'Home') step = -i;
      else if (e.key === 'End') step = btns.length - 1 - i;
      else return;

      e.preventDefault();
      var next = btns[(i + step + btns.length) % btns.length];
      btns.forEach(function (b) { b.tabIndex = -1; });
      next.tabIndex = 0;
      next.focus();
    });
  }

  /* ════════════════════════════════════════════════════════════════════════
     THE BASKET
     ════════════════════════════════════════════════════════════════════════ */

  /* The basket is in THIS browser's storage (`afg_cart`), so the server cannot
     know whether it is empty — which is why the link ships hidden everywhere
     except the shop, and this reveals it. The alternative arrangements are both
     wrong in a way somebody notices: render it always and most visitors carry a
     basket icon that has never held anything, or render it never and a full
     basket is invisible from every page but one.

     Wrapped, because `localStorage` THROWS in a Safari private window rather
     than returning null. No badge is the right failure: a basket with no count
     is a link, and a header that 500s is not. */
  function basket() {
    var link = head.querySelector('[data-ag-cart]');
    var badge = head.querySelector('[data-ag-cart-n]');
    if (!link || !badge) return;

    var n = 0;
    try {
      var cart = JSON.parse(localStorage.getItem('afg_cart') || '{}') || {};
      for (var k in cart) {
        if (!Object.prototype.hasOwnProperty.call(cart, k)) continue;
        var q = cart[k];
        n += typeof q === 'number' ? q : ((q && q.qty) | 0);
      }
    } catch (e) { return; }

    if (n <= 0) return;

    link.hidden = false;
    badge.hidden = false;
    /* The number is in the badge for sighted readers and in the label for
       everybody else, because a bare "12" beside a basket does not say what it
       counts. */
    badge.textContent = n > 99 ? '99+' : String(n);
    link.setAttribute('aria-label', 'Your basket, ' + n + (n === 1 ? ' item' : ' items'));
  }

  basket();
  /* The shop writes the basket on the same page this reads it, so the badge
     would otherwise be one navigation behind every add. */
  window.addEventListener('ag:cart', basket);
  window.addEventListener('storage', function (e) { if (!e.key || e.key === 'afg_cart') basket(); });
})();
