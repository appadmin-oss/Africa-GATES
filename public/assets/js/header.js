/* ══════════════════════════════════════════════════════════════════════════════
   THE SITE HEADER'S BEHAVIOUR — mega panels · Aa and language popovers · toolbar ·
   keyboard shortcuts
   REFERENCE §7.1 · design/SiteHeader.dc.html · skill §23
   ══════════════════════════════════════════════════════════════════════════════

   The `data-ag-mega*` controller the phase file says to keep, written again with the
   popovers and the toolbar in one place, because "opening one panel closes the other
   panels and popovers" (§7.1) is a rule about all of them at once: three controllers each
   closing their own is how two things end up open together.

   ── THE RULES ────────────────────────────────────────────────────────────────

   · Click toggles; opening anything closes everything else. Esc and the transparent
     scrim under a panel close it; so does a click anywhere outside the header.
   · Focus RETURNS to the trigger when a layer closes by Esc, and moves into a panel or a
     popover when it was opened from the keyboard — a menu opened with Enter whose first
     item is not focused has to be found by tabbing through the rest of the page.
   · Inside a panel or the language menu, the arrows move between items (Home/End to the
     ends); Tab leaves and closes.
   · The toolbar is `role="toolbar"`, which is a claim about the arrow keys: ONE tab stop,
     and ← → between Search, Aa and the language (mirrored in Arabic). A toolbar that only
     answers Tab tells a screen-reader user to press keys that do nothing.
   · `?` opens the shortcuts dialog and `G` then H/V/D/E/A goes to a section — never
     while somebody is typing, never with a modifier held (skill §23). `/` is the search
     palette's, in search.js.

   Hover-to-open is deliberately absent: the DC and §7.1 say click toggles, and a panel
   that opens when a pointer crosses a link on its way to the toolbar is a panel that
   covers what somebody was reaching for.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function rtl() { return document.documentElement.dir === 'rtl'; }
  function typing(el) {
    if (!el) return false;
    var t = el.tagName;
    return t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || el.isContentEditable;
  }

  var head = null;

  /* One record per layer: {trigger, panel, items()}. */
  var layers = [];
  var openLayer = null;

  function close(layer, returnFocus) {
    if (!layer || openLayer !== layer) return;
    layer.panel.hidden = true;
    layer.trigger.setAttribute('aria-expanded', 'false');
    openLayer = null;
    if (layer.mega) {
      var scrim = head.querySelector('[data-ag-mega-scrim]');
      if (scrim) scrim.hidden = true;
      head.classList.remove('is-mega');
    }
    if (returnFocus) layer.trigger.focus();
  }

  function closeAll(returnFocus) { if (openLayer) close(openLayer, returnFocus); }

  function open(layer, fromKeyboard) {
    if (openLayer && openLayer !== layer) close(openLayer, false);
    layer.panel.hidden = false;
    layer.trigger.setAttribute('aria-expanded', 'true');
    openLayer = layer;
    if (layer.mega) {
      var scrim = head.querySelector('[data-ag-mega-scrim]');
      if (scrim) scrim.hidden = false;
      head.classList.add('is-mega');
    }
    if (fromKeyboard) {
      var items = layer.items();
      var current = items.filter(function (i) { return i.getAttribute('aria-checked') === 'true'; })[0];
      var first = current || items[0];
      if (first) first.focus();
    }
  }

  /* Arrow keys inside a panel or a menu: sequential, wrapping, Home/End. */
  function roam(e, items) {
    var i = items.indexOf(document.activeElement);
    if (i < 0) return false;
    var k = e.key, step = 0;
    if (k === 'ArrowDown') step = 1;
    else if (k === 'ArrowUp') step = -1;
    else if (k === 'ArrowRight') step = rtl() ? -1 : 1;
    else if (k === 'ArrowLeft') step = rtl() ? 1 : -1;
    else if (k === 'Home') { items[0].focus(); return true; }
    else if (k === 'End') { items[items.length - 1].focus(); return true; }
    if (!step) return false;
    items[(i + step + items.length) % items.length].focus();
    return true;
  }

  function bindLayers() {
    all('[data-ag-mega-trigger]', head).forEach(function (btn) {
      var key = btn.getAttribute('data-ag-mega-trigger');
      var panel = head.querySelector('[data-ag-mega-panel="' + key + '"]');
      if (!panel) return;
      layers.push({ trigger: btn, panel: panel, mega: true,
        items: function () { return all('[role="menuitem"]', panel); } });
    });
    all('[data-ag-pop]', head).forEach(function (btn) {
      var key = btn.getAttribute('data-ag-pop');
      var panel = head.querySelector('[data-ag-pop-panel="' + key + '"]');
      if (!panel) return;
      layers.push({ trigger: btn, panel: panel, mega: false, menu: panel.getAttribute('role') === 'menu',
        items: function () {
          return panel.getAttribute('role') === 'menu'
            ? all('[role^="menuitem"]', panel)
            : all('button:not([disabled]),a[href],select,input:not([disabled])', panel);
        } });
    });

    layers.forEach(function (layer) {
      layer.trigger.addEventListener('click', function (e) {
        e.preventDefault();
        if (openLayer === layer) close(layer, false);
        else open(layer, e.detail === 0);
      });
      layer.trigger.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && (layer.mega || layer.menu)) {
          e.preventDefault();
          open(layer, true);
        }
      });
      layer.panel.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(layer, true); return; }
        if (e.key === 'Tab' && (layer.mega || layer.menu)) {
          /* A menu is left by Tab: close it and let the Tab carry on from the trigger. */
          close(layer, true);
          return;
        }
        if ((layer.mega || layer.menu) && roam(e, layer.items())) e.preventDefault();
      });
    });

    var scrim = head.querySelector('[data-ag-mega-scrim]');
    if (scrim) scrim.addEventListener('click', function () { closeAll(false); });

    /* Outside the header, or on the bar itself away from a control, closes everything. */
    document.addEventListener('click', function (e) {
      if (!openLayer) return;
      if (openLayer.panel.contains(e.target) || openLayer.trigger.contains(e.target)) return;
      closeAll(false);
    });

    /* A popover that focus has left (Tab out of Aa) closes, so nothing stays open
       behind wherever the keyboard went. */
    document.addEventListener('focusin', function (e) {
      if (!openLayer || openLayer.mega) return;
      if (openLayer.panel.contains(e.target) || openLayer.trigger.contains(e.target)) return;
      close(openLayer, false);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && openLayer) { close(openLayer, true); }
    });

    /* Something else opening (the search palette) closes the header's layers. */
    window.addEventListener('ag:layer', function () { closeAll(false); });
  }

  /* ── The toolbar: one tab stop, ← → between its three controls ────────── */

  function bindToolbar() {
    var bar = head.querySelector('[data-ag-toolbar]');
    if (!bar) return;
    var items = all('.ag-tools__b', bar);
    items.forEach(function (b, i) { b.tabIndex = i === 0 ? 0 : -1; });
    bar.addEventListener('focusin', function (e) {
      if (items.indexOf(e.target) < 0) return;
      items.forEach(function (b) { b.tabIndex = b === e.target ? 0 : -1; });
    });
    bar.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'Home' && e.key !== 'End') return;
      if (roam(e, items)) e.preventDefault();
    });
  }

  /* ── The shortcuts dialog and the go-to chords ─────────────────────────── */

  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';

  function bindShortcuts() {
    var dlg = document.querySelector('[data-ag-kb]');
    var scrim = document.querySelector('[data-ag-kb-scrim]');
    var back = null, g = 0;

    function shut() {
      if (!dlg || dlg.hidden) return;
      dlg.hidden = true;
      if (scrim) scrim.hidden = true;
      if (back && back.focus) back.focus();
      back = null;
    }
    function show() {
      if (!dlg) return;
      window.dispatchEvent(new CustomEvent('ag:layer', { detail: 'shortcuts' }));
      back = document.activeElement;
      dlg.hidden = false;
      if (scrim) scrim.hidden = false;
      var x = dlg.querySelector('[data-ag-kb-close]');
      if (x) x.focus();
    }

    if (dlg) {
      dlg.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); shut(); return; }
        if (e.key !== 'Tab') return;
        var f = all(FOCUSABLE, dlg);
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      });
      dlg.addEventListener('click', function (e) { if (e.target.closest('[data-ag-kb-close]')) shut(); });
      if (scrim) scrim.addEventListener('click', shut);
    }

    var GO = { h: '/', v: '/vote', d: '/discover', e: '/events', a: '/awards' };

    document.addEventListener('keydown', function (e) {
      if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) return;
      if (typing(e.target)) return;
      /* No shortcut fires under an open dialog or sheet: the keys belong to it. */
      if (document.querySelector('[aria-modal="true"]:not([hidden]):not([inert])')) return;
      if (e.key === '?') { e.preventDefault(); show(); return; }
      if (g && Date.now() - g < 1200) {
        g = 0;
        var to = GO[e.key.toLowerCase()];
        if (to) { e.preventDefault(); location.href = to; }
        return;
      }
      if (e.key === 'g' || e.key === 'G') g = Date.now();
    });

    window.AGHeader = { shortcuts: show };
  }

  function boot() {
    head = document.querySelector('[data-ag-head]');
    if (head) { bindLayers(); bindToolbar(); }
    bindShortcuts();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
