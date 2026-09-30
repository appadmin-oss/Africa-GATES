/* ══════════════════════════════════════════════════════════════════════════════
   AFRICA GATES — THE SHARED CHROME
   Menu sheet · Quick settings · Display & reading controls · language prompt
   REFERENCE §7.2, §7.3, §7.4, §7.5
   ══════════════════════════════════════════════════════════════════════════════

   A classic script, for the reason shell.js gives. It owns no state of its own:
   every setting lives in `AGA11y`'s store and every language choice is a link the
   server acts on, so this file only opens things, closes things, and keeps what is
   on screen agreeing with what is stored.

   ── WHAT IT DELIBERATELY DOES NOT DO ────────────────────────────────────────

   It does not navigate for a language. Every language control on the site is an
   `<a href="?lang=xx">` and `LanguageMiddleware` is the one thing that turns that
   into a stored preference — so all of them work with scripting off, which for the
   control that decides whether somebody can read the site at all is the whole
   point. The one exception is the Display & reading `<select>`, which cannot be a
   link; it sits in a real GET form with a visible Change button, and the only thing
   this file does is submit on change and hide the button it made redundant.

   It does not keep a copy of any switch's state. `ag:a11y` fires on every write and
   every mounted surface re-reads — three doors onto one store, never three stores.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* ════════════════════════════════════════════════════════════════════════
     DISPLAY & READING — binding whatever copies of the controls are mounted
     ════════════════════════════════════════════════════════════════════════ */

  /* Reads the store and writes it onto the controls. Runs on load, after every
     `ag:a11y`, and after a sheet opens — a sheet can be built before a setting is
     changed in another copy, so re-syncing on open is what stops the Menu showing
     a switch off that Quick settings turned on a second earlier. */
  function syncA11y() {
    if (!window.AGA11y) return;
    var s = window.AGA11y.read(), i;

    var sizes = all('[data-ag-size]');
    for (i = 0; i < sizes.length; i++) {
      var on = (s.size | 0) === parseInt(sizes[i].getAttribute('data-ag-size'), 10);
      sizes[i].setAttribute('aria-checked', String(on));
      /* Roving tabindex: a radiogroup is ONE tab stop, and Tab through three
         identical "A" buttons is the pattern the standards call out. */
      sizes[i].tabIndex = on ? 0 : -1;
    }
    /* Nothing checked yet (a store written by an older build) would leave the group
       unreachable by Tab, so the first option takes the stop. */
    if (sizes.length && !sizes.filter(function (b) { return b.tabIndex === 0; }).length) sizes[0].tabIndex = 0;

    var togs = all('[data-ag-toggle]');
    for (i = 0; i < togs.length; i++) {
      togs[i].setAttribute('aria-checked', String(!!s[togs[i].getAttribute('data-ag-toggle')]));
    }

    var labels = all('[data-ag-size-label]');
    for (i = 0; i < labels.length; i++) labels[i].textContent = window.AGA11y.sizeLabel();
  }

  /* One delegated listener for every copy of every control, so a sheet built later
     needs no rebinding. */
  function bindA11y() {
    document.addEventListener('click', function (e) {
      var size = e.target.closest ? e.target.closest('[data-ag-size]') : null;
      if (size) { window.AGA11y.set({ size: parseInt(size.getAttribute('data-ag-size'), 10) }); return; }

      var tog = e.target.closest ? e.target.closest('[data-ag-toggle]') : null;
      if (tog) {
        var k = tog.getAttribute('data-ag-toggle'), p = {};
        p[k] = tog.getAttribute('aria-checked') !== 'true';
        window.AGA11y.set(p);
      }
    });

    /* Arrow keys inside the size group. A radiogroup that only responds to Tab and
       Space is a radiogroup in name; ← → is how one is actually operated. */
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
      var here = document.activeElement;
      if (!here || !here.hasAttribute || !here.hasAttribute('data-ag-size')) return;
      var group = all('[data-ag-size]', here.closest('[role="radiogroup"]') || document);
      var i = group.indexOf(here);
      if (i < 0) return;
      var step = (e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1;
      var next = group[(i + step + group.length) % group.length];
      e.preventDefault();
      window.AGA11y.set({ size: parseInt(next.getAttribute('data-ag-size'), 10) });
      next.focus();
    });

    window.addEventListener('ag:a11y', syncA11y);
    syncA11y();
  }

  /* The Display & reading language form: submit on change, and hide the Change
     button that only existed for a browser that could not. Both happen HERE rather
     than in the template, so a page whose script fails keeps the button. */
  function bindLangForm() {
    var forms = all('[data-ag-langform]');
    for (var i = 0; i < forms.length; i++) {
      (function (form) {
        var sel = form.querySelector('[data-ag-lang]');
        var go  = form.querySelector('.ag-dr__go');
        if (!sel) return;
        if (go) go.hidden = true;
        sel.addEventListener('change', function () { form.submit(); });
      })(forms[i]);
    }
  }

  /* ════════════════════════════════════════════════════════════════════════
     SHEETS
     ════════════════════════════════════════════════════════════════════════ */

  /* One open sheet at a time, and the back gesture closes it before it leaves the
     page. `history.pushState` on open + `popstate` on close is what makes the
     phone's own back do the thing every native sheet does; without it, dismissing a
     menu takes somebody off the page they were reading. */
  var live = null;

  /* ONE history entry for however many sheets hand over to each other. Quick
     settings' "All display & reading settings" closes itself and opens the Menu, and
     pushing an entry per sheet would make the back gesture need two presses to leave
     a single interaction — which reads as the button being stuck. */
  var pushed = false;

  function open(sheet, scrim, trigger, onOpen) {
    if (!sheet) return;
    if (live) live.close('handover');

    var closeSheet = window.AGShell.openSheet(sheet, scrim, trigger);

    if (!pushed) { try { history.pushState({ agSheet: 1 }, ''); pushed = true; } catch (e) {} }

    /* `why` is 'pop' when the browser already took the entry away — going back again
       would leave the page — and 'handover' when another sheet is about to take over
       and should keep the entry. Anything else is a real dismissal. */
    function close(why) {
      if (live !== rec) return;
      live = null;
      closeSheet();
      document.body.classList.remove('ag-sheet-open');
      if (why === 'handover') return;
      if (why === 'pop') { pushed = false; return; }
      if (pushed) { pushed = false; try { history.back(); } catch (e) {} }
    }

    var rec = { sheet: sheet, close: close };
    live = rec;
    document.body.classList.add('ag-sheet-open');

    /* The scrim and Esc are handled inside openSheet, which calls its own close —
       so watch the attribute rather than duplicating those listeners here, or the
       record would go stale and back would then leave the page. */
    if (window.MutationObserver) {
      var mo = new MutationObserver(function () {
        if (!sheet.hasAttribute('data-open')) { mo.disconnect(); close(); }
      });
      mo.observe(sheet, { attributes: true, attributeFilter: ['data-open'] });
    }

    if (onOpen) onOpen();
    syncA11y();
    return close;
  }

  window.addEventListener('popstate', function () { if (live) live.close('pop'); else pushed = false; });

  /* ── The Menu, and its pushed sub-views ────────────────────────────────── */

  function menuView(sheet, name) {
    var views = all('[data-ag-menu-view]', sheet);
    var main  = name === 'main';
    for (var i = 0; i < views.length; i++) {
      views[i].hidden = views[i].getAttribute('data-ag-menu-view') !== name;
    }
    var back  = sheet.querySelector('[data-ag-menu-back]');
    var title = sheet.querySelector('[data-ag-menu-title]');
    if (back) back.hidden = main;
    if (title) {
      title.textContent = main ? 'Menu'
        : (name === 'display' ? 'Display & reading' : 'Language');
    }
    /* Focus moves to the sub-view's heading rather than staying on a row that is no
       longer on screen — a push that leaves focus behind is a push a screen-reader
       user cannot follow. */
    var v = sheet.querySelector('[data-ag-menu-view="' + name + '"]');
    if (v) {
      var f = v.querySelector('a[href],button:not([disabled]),select,input:not([disabled])');
      if (f) f.focus();
    }
    /* A pushed view starts at the top; keeping the parent's scroll position shows
       somebody the middle of a list they have not seen. */
    var body = sheet.querySelector('.ag-sheet__body');
    if (body) body.scrollTop = 0;
  }

  function bindMenu() {
    var sheet = document.querySelector('[data-ag-menu-sheet]');
    var scrim = document.querySelector('[data-ag-menu-scrim]');
    if (!sheet) return;

    function openMenu(trigger, view) {
      open(sheet, scrim, trigger, function () { menuView(sheet, view || 'main'); });
    }

    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-ag-menu]') : null;
      if (t) { e.preventDefault(); openMenu(t); return; }

      if (e.target.closest && e.target.closest('[data-ag-menu-close]')) {
        if (live) live.close();
        return;
      }
      if (e.target.closest && e.target.closest('[data-ag-menu-back]')) {
        menuView(sheet, 'main');
        return;
      }
      var to = e.target.closest ? e.target.closest('[data-ag-menu-to]') : null;
      if (to) { menuView(sheet, to.getAttribute('data-ag-menu-to')); }
    });

    window.AGChrome.openMenu = openMenu;
  }

  /* ── Quick settings ────────────────────────────────────────────────────── */

  function bindQuick() {
    var sheet = document.querySelector('[data-ag-quick-sheet]');
    var scrim = document.querySelector('[data-ag-quick-scrim]');
    if (!sheet) return;

    var opener = null;

    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-ag-quick]') : null;
      if (t) { e.preventDefault(); opener = t; open(sheet, scrim, t); return; }

      if (e.target.closest && e.target.closest('[data-ag-quick-close]')) {
        if (live) live.close();
        return;
      }

      /* "All display & reading settings" hands over to the Menu's sub-view rather
         than navigating: there is no separate settings PAGE, and inventing one
         would be a second surface for the same seven switches. */
      if (e.target.closest && e.target.closest('[data-ag-quick-all]')) {
        /* Focus returns to the avatar that started this, not to the row that was
           pressed — that row is inside the sheet being closed. */
        if (live) live.close('handover');
        if (window.AGChrome.openMenu) window.AGChrome.openMenu(opener, 'display');
      }
    });
  }

  /* ── Share ─────────────────────────────────────────────────────────────── */

  /* `navigator.share` where it exists, the clipboard where it does not, and a
     visible confirmation either way — a share button that appears to do nothing is
     pressed again. It is only rendered on the child app bar, so there is always a
     URL worth sharing. */
  function bindShare() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-ag-share]') : null;
      if (!b) return;
      var data = {
        title: document.title,
        url: (document.querySelector('link[rel="canonical"]') || {}).href || location.href
      };
      if (navigator.share) { navigator.share(data).catch(function () {}); return; }
      if (navigator.clipboard) {
        navigator.clipboard.writeText(data.url).then(function () {
          var said = b.getAttribute('aria-label');
          b.setAttribute('data-copied', '');
          b.setAttribute('aria-label', 'Link copied');
          setTimeout(function () { b.removeAttribute('data-copied'); b.setAttribute('aria-label', said); }, 1600);
        }, function () {});
      }
    });
  }

  /* ── The first-visit language prompt ───────────────────────────────────── */

  /* The server has already decided nobody has answered — the block is only in the
     document at all when `lang_ask()` was true. All that is left is the half only a
     browser can answer: is the language this person's browser asks for FIRST one we
     offer, and not English.

     `[0]` and not a scan of the whole list, on purpose. Somebody whose browser says
     "English, then French" reads English; offering them French because it appears
     anywhere in the list is how a prompt becomes an interruption.

     It hides on scroll and does not return, per §7.2: a question worth asking once
     is worth dropping the moment somebody has started doing something else. */
  function bindLangAsk() {
    var box = document.querySelector('[data-ag-langask]');
    if (!box) return;

    var want = (navigator.languages && navigator.languages[0]) || navigator.language || '';
    want = String(want).toLowerCase().split('-')[0];
    if (!want || want === 'en') return;

    var row = box.querySelector('[data-ag-langask-for="' + want + '"]');
    if (!row) return;

    row.hidden = false;
    box.hidden = false;

    var main = document.querySelector('.ag-main');
    if (!main) return;
    function go() { box.hidden = true; main.removeEventListener('scroll', go); }
    main.addEventListener('scroll', go, { passive: true, once: true });
  }

  /* ════════════════════════════════════════════════════════════════════════ */

  window.AGChrome = { sync: syncA11y, openSheet: open };

  function boot() {
    if (window.AGA11y) bindA11y();
    bindLangForm();
    bindMenu();
    bindQuick();
    bindShare();
    bindLangAsk();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
