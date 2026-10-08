/* ══════════════════════════════════════════════════════════════════════════════
   DISCOVER — `/discover` · Phase 4 · design/DiscoverPage.dc.html, skill §5, §6b
   ══════════════════════════════════════════════════════════════════════════════

   EVERYTHING HERE IS AN UPGRADE. The page is whole without this file: every tab, kind
   chip, filter, "remove filter" and "Show older updates" is a link or a GET form, and the
   search field is a form that submits. If this script never arrives — routine on a
   low-end phone on a dropped connection — nothing is missing, only slower.

   What it adds:
   · TABS switch in place (one state, two copies of the tablist), with arrow keys, and the
     URL follows (pushState) so Back restores the tab. The Live tab and its way back to
     All fetch the timeline as HTML from the same partial the server draws.
   · THE DOCK (skill §6b): CSS swaps the copies on `.ag-sticky[data-scrolled]`; this keeps
     the accessibility tree honest on the same threshold — the hidden copy `aria-hidden`,
     its tabs `tabindex="-1"` — and moves focus across if it was on the copy that hid.
   · THE LIVE COMBOBOX on the Live tab (activity.twig's rules, kept): `aria-activedescendant`
     so focus never leaves the field, the polite status line is the ONLY live region (the
     list is never one), Enter with nothing highlighted still submits, the first Escape
     closes and only a second clears, 220ms debounce, a stale answer is dropped.
   · FILTERS as a dialog (AGShell.openSheet: focus in, trapped, Esc, scrim, focus back),
     with a live "Show N results" (GET /discover/count) and a Status that can be unchosen.
   · KIND CHIPS and "Show older updates" fetch in place; older rows keep focus on the
     first new row.

   Names no source and no kind: those are the server's (Services\Discover).
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var root = document.querySelector('[data-dv]');
  if (!root || !window.fetch || !window.URLSearchParams) return;

  var main  = document.querySelector('.ag-main');
  var bar   = root.querySelector('[data-dv-bar]');
  var form  = root.querySelector('[data-dv-form]');
  var input = root.querySelector('[data-dv-q]');
  var panel = root.querySelector('[data-dv-panel]');
  var MIN   = parseInt(root.getAttribute('data-min'), 10) || 2;

  /* ── State lives in the URL (REFERENCE §10) ─────────────────────────────── */
  function params() { return new URLSearchParams(location.search); }
  function tab() { return root.getAttribute('data-tab') || 'all'; }
  function urlWith(set) {
    var p = params();
    Object.keys(set).forEach(function (k) {
      if (set[k] === null || set[k] === '' || (k === 'tab' && set[k] === 'all') || (k === 'page' && set[k] === 1)) p.delete(k);
      else p.set(k, set[k]);
    });
    var s = p.toString();
    return location.pathname + (s ? '?' + s : '');
  }

  function plural(el, n, oneAttr, manyAttr) {
    var one = el.getAttribute(oneAttr || 'data-one'), many = el.getAttribute(manyAttr || 'data-many');
    return n === 1 ? one : many.replace('%n%', n.toLocaleString('en'));
  }

  /* ── Tabs ───────────────────────────────────────────────────────────────── */
  function tabs() { return Array.prototype.slice.call(root.querySelectorAll('[role="tab"][data-dv-tab]')); }
  function primaryTabs() { return tabs().filter(function (t) { return !!t.id; }); }
  function dockTabs() { return tabs().filter(function (t) { return !t.id; }); }
  function docked() { return bar && bar.hasAttribute('data-scrolled'); }

  /* Only the exposed copy is in the tab order, and in it only the selected tab (roving). */
  function syncTabStops() {
    var dock = docked();
    tabs().forEach(function (t) {
      var exposed = dock ? !t.id : !!t.id;
      t.tabIndex = exposed && t.getAttribute('aria-selected') === 'true' ? 0 : -1;
    });
    var row = root.querySelector('[data-dv-tabrow]');
    var dk  = root.querySelector('[data-dv-dock]');
    if (row) row.setAttribute('aria-hidden', dock ? 'true' : 'false');
    if (dk) dk.setAttribute('aria-hidden', dock ? 'false' : 'true');
  }

  function setTab(k, opts) {
    opts = opts || {};
    var was = tab();
    root.setAttribute('data-tab', k);
    tabs().forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-dv-tab') === k ? 'true' : 'false'); });
    syncTabStops();
    if (panel) panel.setAttribute('aria-labelledby', 'dvTab-' + k);
    Array.prototype.forEach.call(document.querySelectorAll('[data-dv-tabfield]'), function (f) { f.value = k; });
    var count = root.querySelector('[data-dv-count]');
    var n = parseInt(root.getAttribute('data-count-' + k), 10);
    if (count && !isNaN(n)) count.textContent = plural(root, n);
    if (!opts.fromHistory) history.pushState({ dvTab: k }, '', urlWith({ tab: k, page: 1 }));
    // The timeline is a different list on Live (everything, paged, by kind) than on All
    // (the four newest), so crossing between them asks the server for it.
    if ((was === 'live') !== (k === 'live')) loadLive(urlWith({ tab: k, page: 1 }));
    combobox(k === 'live');
  }

  root.addEventListener('click', function (e) {
    var t = e.target.closest('[data-dv-tab]');
    if (!t || e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) return;
    e.preventDefault();
    setTab(t.getAttribute('data-dv-tab'));
    // "See everything" is a link, not a tab: move focus to the tab it selected.
    if (t.getAttribute('role') !== 'tab') {
      var to = tabs().filter(function (x) { return x.tabIndex === 0; })[0];
      if (to) to.focus();
    }
  });

  /* Arrow keys move along the tablist and select (automatic activation — switching is
     immediate); Home and End reach the ends. Mirrored in RTL. */
  root.addEventListener('keydown', function (e) {
    var t = e.target.closest && e.target.closest('[role="tab"][data-dv-tab]');
    if (!t) return;
    var list = tabs().filter(function (x) { return !!x.id === !!t.id; });
    var i = list.indexOf(t), rtl = document.documentElement.dir === 'rtl', to = -1;
    if (e.key === 'ArrowRight') to = rtl ? i - 1 : i + 1;
    else if (e.key === 'ArrowLeft') to = rtl ? i + 1 : i - 1;
    else if (e.key === 'Home') to = 0;
    else if (e.key === 'End') to = list.length - 1;
    else return;
    e.preventDefault();
    to = (to + list.length) % list.length;
    setTab(list[to].getAttribute('data-dv-tab'));
    list[to].focus();
  });

  window.addEventListener('popstate', function () {
    var k = params().get('tab') || 'all';
    if (k !== tab()) setTab(k, { fromHistory: true });
  });

  /* ── The dock (skill §6b) ───────────────────────────────────────────────── */
  if (main && window.AGShell) {
    window.AGShell.watchScroll(main, {
      onChange: function () {
        var had = document.activeElement && document.activeElement.closest && document.activeElement.closest('[role="tab"][data-dv-tab]');
        syncTabStops();
        // Focus never stays on the copy that just hid.
        if (had && had.tabIndex === -1) {
          var to = tabs().filter(function (x) { return x.tabIndex === 0; })[0];
          if (to) to.focus({ preventScroll: true });
        }
      }
    });
  }
  syncTabStops();

  /* ── The timeline, fetched as HTML from the server's own partial ─────────── */
  var seq = 0;
  function live() { return root.querySelector('[data-dv-live]'); }

  function loadLive(url, then) {
    var mine = ++seq, sec = live();
    if (sec) sec.setAttribute('aria-busy', 'true');
    var u = url + (url.indexOf('?') < 0 ? '?' : '&') + 'fragment=live';
    return fetch(u, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
      .then(function (html) {
        if (mine !== seq) return;           // a newer request owns the list
        swapLive(html);
        if (then) then();
      })
      .catch(function () {
        if (mine !== seq) return;
        var sec2 = live(), st = sec2 && sec2.querySelector('[data-dv-status]');
        if (sec2) sec2.removeAttribute('aria-busy');
        if (st) st.textContent = st.getAttribute('data-offline');
      });
  }

  /* The status line is UPDATED, never replaced: a live region swapped out with its
     content is often not announced. Everything else is replaced whole. */
  function swapLive(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var next = doc.querySelector('[data-dv-live]'), sec = live();
    if (!next || !sec) return;
    var st = sec.querySelector('[data-dv-status]'), nst = next.querySelector('[data-dv-status]');
    Array.prototype.slice.call(sec.children).forEach(function (child) {
      if (!child.hasAttribute('data-dv-status')) child.remove();
    });
    var before = st;
    Array.prototype.forEach.call(next.children, function (child) {
      if (child.hasAttribute('data-dv-status')) return;
      var c = document.importNode(child, true);
      if (child.compareDocumentPosition(nst) & Node.DOCUMENT_POSITION_FOLLOWING) sec.insertBefore(c, before);
      else sec.appendChild(c);
    });
    sec.removeAttribute('aria-busy');
    if (st && nst) st.textContent = nst.textContent;
    // The bar's count follows the list it counts on the Live tab (the server's figure
    // was for the page as first drawn).
    if (tab() === 'live') {
      var n = sec.querySelectorAll('.dv-row').length, count = root.querySelector('[data-dv-count]');
      root.setAttribute('data-count-live', String(n));
      if (count) count.textContent = plural(root, n);
    }
    bindLive();
  }

  /* Kind chips and "Show older updates": the same requests their links/forms make. */
  function bindLive() {
    var sec = live();
    if (!sec) return;
    var kinds = sec.querySelector('[data-dv-kinds]');
    if (kinds && !kinds._dv) {
      kinds._dv = true;
      kinds.addEventListener('submit', function (e) {
        e.preventDefault();
        var k = e.submitter ? e.submitter.value : 'all';
        var url = urlWith({ kind: k === 'all' ? null : k, page: 1 });
        history.pushState({ dvTab: 'live' }, '', url);
        loadLive(url, function () {
          var b = live().querySelector('[data-dv-kinds] [value="' + k + '"]');
          if (b) b.focus();
        });
      });
    }
    var older = sec.querySelector('[data-dv-older-go]');
    if (older && !older._dv) {
      older._dv = true;
      older.addEventListener('click', function (e) {
        e.preventDefault();
        var href = older.getAttribute('href'), anchor = href.split('#')[1];
        var url = href.split('#')[0];
        history.replaceState({ dvTab: 'live' }, '', url);
        loadLive(url, function () {
          var first = anchor && document.getElementById(anchor);
          if (first) first.focus();
          else if (input) input.focus();
        });
      });
    }
    // The pressed kind is scrolled into the row's view: on a phone the row scrolls, and
    // a chosen "Recognitions" off the edge reads as nothing chosen.
    var pressed = sec.querySelector('.dv-kind[aria-pressed="true"]');
    if (pressed && pressed.scrollIntoView) pressed.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    markOptions();
  }

  /* ── The live combobox (Live tab only) ──────────────────────────────────── */
  var active = -1, timer = null, armed = false, lastQuery = input ? input.value.trim() : '';

  function list() { return root.querySelector('[data-dv-list]'); }
  function options() { var l = list(); return l ? Array.prototype.slice.call(l.querySelectorAll('[role="option"]')) : []; }

  /* Server-drawn rows become options at once, so a page loaded with ?q= is navigable
     before the first keystroke. */
  function markOptions() {
    var l = list();
    if (!l || !armed) return;
    l.setAttribute('role', 'listbox');
    l.setAttribute('aria-label', l.getAttribute('data-label'));
    Array.prototype.forEach.call(l.querySelectorAll('.dv-row'), function (a, i) {
      a.setAttribute('role', 'option');
      a.setAttribute('aria-selected', 'false');
      a.tabIndex = -1;
      if (a.parentElement) a.parentElement.setAttribute('role', 'presentation');
    });
    var empty = l.querySelector('.dv-list__empty');
    if (empty) empty.setAttribute('role', 'presentation');
    active = -1;
    input.removeAttribute('aria-activedescendant');
    input.setAttribute('aria-expanded', options().length ? 'true' : 'false');
  }

  /* The contract is declared HERE, never in the markup: without this script the field is
     a plain search field, and markup claiming to be a combobox would be a lie. */
  function combobox(on) {
    if (!input) return;
    armed = on;
    if (on) {
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-controls', 'dvLiveList');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      markOptions();
    } else {
      ['role', 'aria-controls', 'aria-autocomplete', 'aria-expanded', 'aria-activedescendant']
        .forEach(function (a) { input.removeAttribute(a); });
      var l = list();
      if (l) {
        l.removeAttribute('role'); l.removeAttribute('aria-label');
        Array.prototype.forEach.call(l.querySelectorAll('.dv-row'), function (a) {
          a.removeAttribute('role'); a.removeAttribute('aria-selected'); a.removeAttribute('tabindex');
          if (a.parentElement) { a.parentElement.removeAttribute('role'); a.parentElement.removeAttribute('data-active'); }
        });
      }
    }
  }

  function setActive(i) {
    var opts = options();
    if (!opts.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
    if (i < 0) i = opts.length - 1;
    if (i >= opts.length) i = 0;
    active = i;
    opts.forEach(function (o, n) {
      var on = n === i;
      o.setAttribute('aria-selected', on ? 'true' : 'false');
      if (o.parentElement) o.parentElement.setAttribute('data-active', on ? 'true' : 'false');
      if (on) {
        input.setAttribute('aria-activedescendant', o.id);
        if (o.scrollIntoView) o.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  function clearActive() {
    active = -1;
    input.removeAttribute('aria-activedescendant');
    options().forEach(function (o) {
      o.setAttribute('aria-selected', 'false');
      if (o.parentElement) o.parentElement.removeAttribute('data-active');
    });
  }

  if (input) {
    input.addEventListener('input', function () {
      if (!armed) return;
      var q = input.value.trim();
      if (q === lastQuery) return;
      lastQuery = q;
      clearTimeout(timer);
      var st = root.querySelector('[data-dv-status]');
      if (q.length && q.length < MIN) {
        if (st) st.textContent = st.getAttribute('data-min-text');
        return;
      }
      /* 220ms: every query that gets through runs every timeline source uncached. */
      timer = setTimeout(function () {
        var url = urlWith({ q: q || null, literal: null, page: 1 });
        history.replaceState({ dvTab: 'live' }, '', url);
        loadLive(url);
      }, 220);
    });

    input.addEventListener('keydown', function (e) {
      if (!armed) return;
      var opts = options();
      switch (e.key) {
        case 'ArrowDown': if (opts.length) { e.preventDefault(); setActive(active + 1); } break;
        case 'ArrowUp':   if (opts.length) { e.preventDefault(); setActive(active - 1); } break;
        case 'Home':      if (opts.length && active >= 0) { e.preventDefault(); setActive(0); } break;
        case 'End':       if (opts.length && active >= 0) { e.preventDefault(); setActive(opts.length - 1); } break;
        case 'Enter':
          /* Only when an option is active. Otherwise Enter SUBMITS — the no-script path,
             and what somebody who simply types and presses Enter expects. */
          if (active >= 0 && opts[active]) { e.preventDefault(); opts[active].click(); }
          break;
        case 'Escape':
          /* First Escape closes the list; only a second clears what was typed. */
          if (active >= 0 || input.getAttribute('aria-expanded') === 'true') {
            e.preventDefault();
            clearActive();
            input.setAttribute('aria-expanded', 'false');
          } else if (input.value !== '') {
            e.preventDefault();
            input.value = ''; lastQuery = '';
            var url = urlWith({ q: null, literal: null, page: 1 });
            history.replaceState({ dvTab: 'live' }, '', url);
            loadLive(url);
          }
          break;
      }
    });

    input.addEventListener('blur', function () {
      if (!armed) return;
      setTimeout(function () {
        var l = list();
        if (!l || !l.contains(document.activeElement)) { clearActive(); input.setAttribute('aria-expanded', 'false'); }
      }, 120);
    });
  }

  /* ── Filters ────────────────────────────────────────────────────────────── */
  var sheet  = document.querySelector('[data-dv-facets]');
  var scrim  = document.querySelector('[data-dv-scrim]');
  var opener = root.querySelector('[data-dv-open]');
  var close  = null;

  if (sheet && opener && window.AGShell) {
    var fform = sheet.querySelector('[data-dv-facetform]');
    var show  = sheet.querySelector('[data-dv-show]');
    sheet.setAttribute('inert', '');
    if (location.hash === '#dvFacets') history.replaceState(history.state, '', location.pathname + location.search);

    var shut = function () { if (close) { var c = close; close = null; c(); } };

    opener.addEventListener('click', function (e) {
      e.preventDefault();
      close = window.AGShell.openSheet(sheet, scrim, opener);
      // Back closes the sheet first (skill §10): one history entry while it is open.
      history.pushState({ dvSheet: 1, dvTab: tab() }, '', location.pathname + location.search + '#filters');
    });
    window.addEventListener('popstate', function () { if (close) shut(); });
    sheet.querySelector('[data-dv-close]').addEventListener('click', function (e) {
      e.preventDefault();
      if (location.hash === '#filters') history.back(); else shut();
    });
    // Escape and the scrim close through openSheet's own close(); keep history level.
    new MutationObserver(function () {
      if (!sheet.hasAttribute('data-open') && close) { close = null; if (location.hash === '#filters') history.back(); }
    }).observe(sheet, { attributes: true, attributeFilter: ['data-open'] });

    /* The live count — what "Show N results" will actually show (skill §5). */
    var cseq = 0;
    var recount = function () {
      var mine = ++cseq;
      var q = new URLSearchParams(new FormData(fform)).toString();
      fetch('/discover/count?' + q, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (mine === cseq && j && j.ok) show.textContent = plural(show, j.count); })
        .catch(function () {});
    };
    fform.addEventListener('change', recount);

    /* A Status chip pressed again is unchosen — a radio cannot do that on its own. The
       state BEFORE the press is read on pointerdown / Space, because by `click` the
       browser has already checked it. */
    Array.prototype.forEach.call(sheet.querySelectorAll('[data-dv-toggle]'), function (r) {
      var lab = r.closest('label') || r;
      lab.addEventListener('pointerdown', function () { r._dvWas = r.checked; });
      r.addEventListener('keydown', function (e) { if (e.key === ' ') r._dvWas = r.checked; });
      r.addEventListener('click', function () {
        if (r._dvWas) { r.checked = false; recount(); }
        r._dvWas = false;
      });
    });

    /* "Clear all" clears the choices in the sheet; nothing applies until Show. */
    sheet.querySelector('[data-dv-clear]').addEventListener('click', function (e) {
      e.preventDefault();
      Array.prototype.forEach.call(fform.querySelectorAll('input[type=checkbox],input[type=radio]'), function (i) {
        i.checked = i.type === 'radio' && i.name === 'where' && i.value === '';
      });
      recount();
    });
  }

  /* ── Boot ───────────────────────────────────────────────────────────────── */
  history.replaceState({ dvTab: tab() }, '', location.href);
  combobox(tab() === 'live');
  bindLive();
})();
