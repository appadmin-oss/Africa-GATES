/* ══════════════════════════════════════════════════════════════════════════════
   THE SEARCH PALETTE — REFERENCE §7.1 · design/SiteHeader.dc.html (`searchOpen`) ·
   skill §23 (the command palette)
   ══════════════════════════════════════════════════════════════════════════════

   Opened by any `[data-ag-search-open]` (the header's Search control, an anchor to
   Discover that this upgrades), by `/`, and by ⌘K / Ctrl-K — never stealing the key from
   a text field. A modal dialog: focus moves in, Tab is trapped, Esc and the scrim close
   it, and focus goes back to whatever opened it (to the header's Search control when the
   shortcut opened it from the page body).

   ── THE SCRIPT NEVER NAMES A SOURCE ─────────────────────────────────────────

   `GET /search.json?q=&scope=` answers with GROUPS already formed on the server from
   ActivityFeedService::SCOPES. This file draws them in the order they arrive and knows
   nothing of which source belongs to which chip — a second copy of that map here would
   be two lists that drift, visibly as a result under the wrong heading, invisibly as one
   under none. SearchScopeTest sweeps this file for any source key.

   ── THE COMBOBOX, DONE PROPERLY ─────────────────────────────────────────────

   WAI-ARIA 1.2: the input is `role="combobox"` with `aria-expanded`, `aria-controls` and
   `aria-autocomplete="list"`, all set HERE — markup claiming listbox behaviour with no
   script behind it is a lie to a screen reader. The active option moves by
   `aria-activedescendant`, never `focus()`, so the next keystroke still reaches the box.
   ↑ ↓ move (Home/End to the ends), Enter opens the active option, and with none active
   the first result. Options are real anchors, so a middle-click opens a tab. The chips
   are a tablist with one tab stop; the arrows move between them and re-run the search.

   Requests are debounced 220ms, and a sequence number drops any answer that arrives after
   a newer question — otherwise a slow reply for "ka" overwrites the results for "kano".
   One polite live region, always in the document, says what happened: searching, the
   number of results SHOWN, nothing matched, or that the site could not be reached.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])';

  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function typing(el) {
    if (!el) return false;
    var t = el.tagName;
    return t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || el.isContentEditable;
  }

  function boot() {
    var dlg = document.querySelector('[data-ag-search]');
    if (!dlg) return;
    var scrim = document.querySelector('[data-ag-search-scrim]');
    var form = dlg.querySelector('[data-ag-search-form]');
    var input = dlg.querySelector('[data-ag-search-input]');
    var list = dlg.querySelector('[data-ag-search-list]');
    var msg = dlg.querySelector('[data-ag-search-msg]');
    var status = dlg.querySelector('[data-ag-search-status]');
    var chips = all('[data-ag-scope]', dlg);
    if (!input || !list) return;

    var scope = '', seq = 0, timer = null, opener = null, active = -1, options = [], literal = false;

    /* ── Recent searches (10 Nov 2026) ───────────────────────────────────────
       The palette opened on the same blank every time, so somebody checking the same nominee
       every evening of a voting week typed the name every evening. The last six searches
       that led somewhere are offered when the box is empty, with a way to clear them.
       Kept in THIS browser only, never sent anywhere — across visits only if the visitor
       allowed Preferences (`data-ag-keep`), otherwise until the tab closes (CookieRegistry). */
    var RECENT = 'ag-recent-q', MAXR = 6;
    function store() {
      try { return document.documentElement.getAttribute('data-ag-keep') === '1' ? localStorage : sessionStorage; } catch (e) { return null; }
    }
    function recent() {
      try { var v = JSON.parse((store() && store().getItem(RECENT)) || '[]'); return Array.isArray(v) ? v.filter(function (x) { return typeof x === 'string'; }) : []; }
      catch (e) { return []; }
    }
    function remember(q) {
      q = (q || '').trim();
      if (q.length < 2 || /@|\d{7,}/.test(q.replace(/[\s\-()]/g, ''))) return;   // never an email or a phone number
      var l = recent().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); });
      l.unshift(q.slice(0, 80));
      try { if (store()) store().setItem(RECENT, JSON.stringify(l.slice(0, MAXR))); } catch (e) {}
    }
    function forget() { try { if (store()) store().removeItem(RECENT); store().removeItem(VIEWED); } catch (e) {} }

    /* ── Recently viewed ───────────────────────────────────────────────────────
       The nominees and profiles opened lately, offered under the recent searches: in a
       voting week the same nominee is visited every evening. A page says what it is with
       a hidden [data-ag-viewed] marker (name, category); its own address is the link. Same
       storage rule as above, and cleared by the same button. */
    var VIEWED = 'ag-recent-view';
    function viewed() {
      try { var v = JSON.parse((store() && store().getItem(VIEWED)) || '[]'); return Array.isArray(v) ? v.filter(function (x) { return x && typeof x.u === 'string' && typeof x.t === 'string'; }) : []; }
      catch (e) { return []; }
    }
    function noteView() {
      var m = document.querySelector('[data-ag-viewed]');
      if (!m) return;
      var u = location.pathname, t = (m.getAttribute('data-t') || '').trim();
      if (!t || u.charAt(0) !== '/') return;
      var l = viewed().filter(function (x) { return x.u !== u; });
      l.unshift({ u: u, t: t.slice(0, 80), m: (m.getAttribute('data-m') || '').slice(0, 80) });
      try { if (store()) store().setItem(VIEWED, JSON.stringify(l.slice(0, MAXR))); } catch (e) {}
    }
    noteView();
    document.addEventListener('ag:load', noteView);   // a page swapped in place (site-nav.js)

    /* The combobox wiring lives in the script, so it exists only where something answers it. */
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-autocomplete', 'list');

    function say(text) { if (status) status.textContent = text; }
    function words(key, swap) {
      var s = dlg.getAttribute('data-msg-' + key) || '';
      for (var k in (swap || {})) s = s.split(k).join(swap[k]);
      return s;
    }

    function setActive(i) {
      options.forEach(function (o, n) { o.setAttribute('aria-selected', String(n === i)); });
      active = i;
      if (i >= 0 && options[i]) {
        input.setAttribute('aria-activedescendant', options[i].id);
        options[i].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function el(tag, cls, text) {
      var e = document.createElement(tag);
      if (cls) e.className = cls;
      if (text != null) e.textContent = text;
      return e;
    }

    /* Built with textContent throughout: every title here is something a member, a
       nominee or an organisation typed. */
    function draw(data) {
      list.textContent = '';
      options = [];
      var n = 0, groups = ((data && data.groups) || []).slice();
      // On the empty box, the visitor's own recent searches come first.
      if (data && data.landing) {
        var r = recent();
        var v = viewed().filter(function (x) { return x.u !== location.pathname; });
        if (v.length) groups.unshift({ key: 'viewed', label: words('viewed'), recent: true,
          items: v.map(function (x) { return { url: x.u, title: x.t, meta: x.m || '', tile: 'people', ini: x.t.split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase() }; }) });
        if (r.length) groups.unshift({ key: 'recent', label: words('recent'), recent: true,
          items: r.map(function (q) { return { url: '/discover?q=' + encodeURIComponent(q), title: q, tile: 'more' }; }) });
      }

      groups.forEach(function (g, gi) {
        var grp = el('div', 'ag-ss__grp');
        grp.setAttribute('role', 'group');
        var hid = 'ag-ss-g' + gi;
        var h = el('div', 'ag-ss__gh', g.label);
        h.id = hid;
        h.setAttribute('role', 'presentation');
        grp.setAttribute('aria-labelledby', hid);
        grp.appendChild(h);

        (g.items || []).forEach(function (it) {
          var a = el('a', 'ag-ss__opt');
          a.href = it.url;
          a.id = 'ag-ss-o' + (n++);
          a.setAttribute('role', 'option');
          a.setAttribute('aria-selected', 'false');
          a.tabIndex = -1;

          var tile = el('span', 'ag-ss__tile ag-ss__tile--' + (it.tile || 'more'));
          tile.setAttribute('aria-hidden', 'true');
          if (it.ini) tile.textContent = it.ini;
          else if (it.tile === 'pages' || it.tile === 'more') tile.textContent = '→';
          else tile.appendChild(icon(it.tile));
          a.appendChild(tile);

          var txt = el('span', 'ag-ss__txt');
          txt.appendChild(el('span', 'ag-ss__t', it.title));
          if (it.meta) txt.appendChild(el('span', 'ag-ss__m', it.meta));
          a.appendChild(txt);
          if (it.kind) a.appendChild(el('span', 'ag-ss__k', it.kind));

          grp.appendChild(a);
          options.push(a);
        });
        list.appendChild(grp);
      });

      // "Clear recent searches" lives OUTSIDE the listbox (a listbox may hold only options),
      // just above it, and only while recent searches are on show.
      var bar = dlg.querySelector('[data-ag-ss-recentbar]');
      if (!bar) {
        bar = el('p', 'ag-ss__recentbar');
        bar.setAttribute('data-ag-ss-recentbar', '');
        var clr = el('button', 'ag-ss__clear', words('clear'));
        clr.type = 'button';
        clr.addEventListener('click', function () { forget(); run(); input.focus(); });
        bar.appendChild(clr);
        list.parentNode.insertBefore(bar, list);
      }
      bar.hidden = !groups.some(function (g) { return g.recent; });

      setActive(-1);
      input.setAttribute('aria-expanded', String(options.length > 0));
      return n;
    }

    /* The award and event marks, drawn as the header's tiles draw them. */
    function icon(kind) {
      var ns = 'http://www.w3.org/2000/svg';
      var s = document.createElementNS(ns, 'svg');
      s.setAttribute('width', '18'); s.setAttribute('height', '18'); s.setAttribute('viewBox', '0 0 24 24');
      s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2');
      s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round');
      var p = document.createElementNS(ns, 'path');
      p.setAttribute('d', kind === 'events' ? 'M4 5h16v15H4zM4 10h16M9 3v4M15 3v4' : 'M5 16 3 6l5 4 4-6 4 6 5-4-2 10z');
      s.appendChild(p);
      return s;
    }

    // The message sits ABOVE the list: "Showing results for …" is read before the results.
    if (msg && msg.parentNode === list.parentNode) list.parentNode.insertBefore(msg, list);
    function note(text) {
      if (!msg) return;
      msg.classList.remove('ag-ss__msg--fix');
      msg.textContent = text || '';
      msg.hidden = !text;
    }
    function fixNote(c) {
      if (!msg) return;
      msg.classList.add('ag-ss__msg--fix');
      msg.textContent = words('fixed', { '%q%': c.to }) + ' ';
      var b = el('button', 'ag-ss__orig', words('instead', { '%q%': c.from }));
      b.type = 'button';
      b.addEventListener('click', function () { literal = true; run(); input.focus(); });
      msg.appendChild(b);
      msg.hidden = false;
    }

    function run() {
      var q = input.value.trim();
      var mine = ++seq;
      if (q.length >= 2) say(words('searching'));
      var url = '/search.json?q=' + encodeURIComponent(q) + (scope ? '&scope=' + encodeURIComponent(scope) : '') + (literal ? '&literal=1' : '');
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (mine !== seq) return;
          if (!res.ok || !res.d || res.d.ok === false) {
            draw(null);
            var why = (res.d && res.d.error) || words('error');
            note(why); say(why);
            return;
          }
          var shown = draw(res.d);
          if (res.d.landing) {
            note(shown ? '' : words('start'));
            say('');
            return;
          }
          if (!shown) {
            var none = words('none', { '%q%': q });
            note(none); say(none);
            return;
          }
          if (res.d.corrected) {
            // Said, with the original one tap away: a correction nobody can see or undo is
            // the search deciding what they meant.
            fixNote(res.d.corrected);
            say(words('fixed', { '%q%': res.d.corrected.to }));
            return;
          }
          note('');
          say(shown === 1 ? words('one') : words('count', { '%n%': String(shown) }));
        })
        .catch(function () {
          if (mine !== seq) return;
          draw(null);
          note(words('error')); say(words('error'));
        });
    }

    function later() { clearTimeout(timer); timer = setTimeout(run, 220); }

    /* ── Open and close ─────────────────────────────────────────────────── */

    function isOpen() { return !dlg.hidden; }

    function openPalette(from) {
      if (isOpen()) { input.focus(); return; }
      window.dispatchEvent(new CustomEvent('ag:layer', { detail: 'search' }));
      opener = from || null;
      dlg.hidden = false;
      if (scrim) scrim.hidden = false;
      all('[data-ag-search-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
      input.focus();
      input.select();
      run();
    }

    function closePalette() {
      if (!isOpen()) return;
      dlg.hidden = true;
      if (scrim) scrim.hidden = true;
      clearTimeout(timer);
      seq++;
      all('[data-ag-search-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
      input.setAttribute('aria-expanded', 'false');
      /* Back to the opener — and when the shortcut opened it from the page body, to the
         header's Search control if one is showing, so the next Tab starts somewhere
         sensible rather than at the top of the document. */
      var back = opener;
      if (!back || back === document.body || !back.isConnected) {
        back = all('[data-ag-search-open]').filter(function (b) { return b.getClientRects().length; })[0] || null;
      }
      if (back && back.focus) back.focus();
      opener = null;
    }

    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-ag-search-open]') : null;
      if (t) { e.preventDefault(); openPalette(t); return; }
      if (e.target.closest && e.target.closest('[data-ag-search-close]')) closePalette();
    });
    if (scrim) scrim.addEventListener('click', closePalette);

    document.addEventListener('keydown', function (e) {
      if (isOpen()) return;
      if (e.defaultPrevented || e.altKey) return;
      var k = (e.key || '').toLowerCase();
      var palette = (k === 'k' && (e.metaKey || e.ctrlKey));
      if (!palette && (k !== '/' || e.metaKey || e.ctrlKey || typing(e.target))) return;
      if (!palette && document.querySelector('[aria-modal="true"]:not([hidden]):not([inert])')) return;
      e.preventDefault();
      openPalette(document.activeElement);
    });

    /* ── Inside the palette ─────────────────────────────────────────────── */

    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePalette(); return; }

      if (e.key === 'Tab') {
        /* Only what Tab can actually reach: the result options are anchors at tabindex -1
           (the active one is announced, never focused), and counting them as the last stop
           let Tab walk straight out of the dialog from the last chip — measured. */
        var f = all(FOCUSABLE, dlg).filter(function (x) { return x.tabIndex >= 0 && x.getClientRects().length; });
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        return;
      }

      if (e.target === input && options.length) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active < options.length - 1 ? active + 1 : 0); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active > 0 ? active - 1 : options.length - 1); }
        else if (e.key === 'Home' && active >= 0) { e.preventDefault(); setActive(0); }
        else if (e.key === 'End' && active >= 0) { e.preventDefault(); setActive(options.length - 1); }
      }
    });

    input.addEventListener('input', function () { literal = false; later(); });

    if (form) form.addEventListener('submit', function (e) {
      e.preventDefault();
      remember(input.value);
      var go = options[active >= 0 ? active : 0];
      if (go) { location.href = go.href; return; }
      if (input.value.trim().length >= 2) form.submit();
    });
    /* A result chosen with the mouse or a finger remembers what led to it, too. */
    list.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.ag-ss__opt')) remember(input.value);
    });

    /* ── The scope chips: a tablist, one tab stop, arrows move and re-run ── */

    function pick(chip, focus) {
      chips.forEach(function (c) {
        var on = c === chip;
        c.setAttribute('aria-selected', String(on));
        c.tabIndex = on ? 0 : -1;
      });
      scope = chip.getAttribute('data-ag-scope') || '';
      if (focus) chip.focus();
      run();
    }

    chips.forEach(function (c, i) {
      c.addEventListener('click', function () { pick(c, false); input.focus(); });
      c.addEventListener('keydown', function (e) {
        var rtl = document.documentElement.dir === 'rtl';
        var step = e.key === 'ArrowRight' ? (rtl ? -1 : 1) : e.key === 'ArrowLeft' ? (rtl ? 1 : -1) : 0;
        if (e.key === 'Home') { e.preventDefault(); pick(chips[0], true); return; }
        if (e.key === 'End') { e.preventDefault(); pick(chips[chips.length - 1], true); return; }
        if (!step) return;
        e.preventDefault();
        pick(chips[(i + step + chips.length) % chips.length], true);
      });
    });

    window.AGSearch = { open: openPalette, close: closePalette };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
