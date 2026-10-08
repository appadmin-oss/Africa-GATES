/* ══════════════════════════════════════════════════════════════════════════════
   console-ux.js — how the admin console BEHAVES, on every page at once
   ══════════════════════════════════════════════════════════════════════════════

   The console rebuild of 4 Oct 2026 changed how every page LOOKS and left how every
   page WORKS exactly as it was: 39 pages draw a table and 4 of them can be searched,
   none can be sorted, a saved row reloads the page at the top, a second press of Save
   sends a second request, an edited form loses its edits to a stray click, and on a
   phone a table is a strip you scroll sideways past its own names.

   Rather than 113 templates each learning these, this file finds what is already on
   the page and gives it the behaviour. Nothing here is required: every page works with
   it absent, every enhancement can be refused by the page (`data-ux="off"` on a table
   or form), and nothing is sent anywhere — sorting and finding work on the rows the
   server already drew.

     LISTS    · find-in-list box with a live count, where the page has no server search
              · sortable columns (numbers, money, dates and words each sorted as what
                they are), the choice announced on the header
              · a whole row opens its record, and j / k / Enter walk the list
              · on a phone each row becomes a labelled card instead of a sideways strip
     FILTERS  · a GET filter form applies as soon as a choice changes; "Clear" appears
                when anything is set; on a phone the form folds behind one button
     FORMS    · one press sends once (the button says it is working)
              · leaving a form with unsaved edits asks first
              · ⌘S / Ctrl+S saves the form you are in, Ctrl+Enter sends from a textarea
              · after a save the page comes back where you were, not at the top
     KEYS     · ? lists every shortcut; / finds in the page

   Storage: one sessionStorage key, `cn-ux:scroll` (declared in Support\CookieRegistry),
   holding the scroll position across a save for at most a minute.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var main = document.querySelector('.cn-main');
  if (!main) return;
  var phone = window.matchMedia('(max-width: 768px)');

  function txt(el) { return (el.textContent || '').replace(/\s+/g, ' ').trim(); }
  function typing(el) {
    return !!el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable);
  }
  function say(live, msg) { if (live) live.textContent = msg; }

  // ══ LISTS ══════════════════════════════════════════════════════════════════════

  var lists = [];

  /** The value a cell sorts by: an explicit data-sort-value, else what the text IS. */
  function key(td) {
    if (!td) return { t: 2, v: '' };
    var raw = td.getAttribute('data-sort-value');
    var s = raw != null ? raw : txt(td);
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return { t: 0, v: s.slice(0, 19) };
    var n = s.replace(/[₦$€£,\s%]/g, '');
    if (/^-?\d+(\.\d+)?$/.test(n)) return { t: 1, v: parseFloat(n) };
    return { t: 2, v: s.toLowerCase() };
  }

  /** The link a row stands for: its main link, else the first plain link in its first two cells. */
  function primary(tr) {
    var a = tr.querySelector('a.cn-table__main[href], [data-row-link][href]');
    if (a) return a;
    var cells = tr.children;
    for (var i = 0; i < Math.min(2, cells.length); i++) {
      var c = cells[i].querySelector('a[href]:not([target="_blank"]):not([href^="#"])');
      if (c && !c.closest('.col-actions, form')) return c;
    }
    // Many lists name the record in plain text and open it from the actions cell. Only a
    // link that plainly OPENS it counts — never one that changes something.
    var acts = tr.querySelectorAll('td a[href]:not([target="_blank"]):not([href^="#"])');
    for (var j = 0; j < acts.length; j++) {
      if (!acts[j].closest('form') && /^(edit|open|view|manage|review|details?)\b/i.test(txt(acts[j]))) return acts[j];
    }
    return null;
  }

  function enhanceTable(table, n) {
    if (table.closest('[data-ux="off"]') || table.getAttribute('data-ux') === 'off') return;
    var head = table.tHead && table.tHead.rows[0];
    var body = table.tBodies[0];
    if (!head || !body) return;
    var ths = Array.prototype.slice.call(head.cells);
    var rows = Array.prototype.slice.call(body.rows);
    if (!rows.length) return;
    // A table that groups its rows (a spanning heading row, a cut line, rowspans) has an
    // order that IS its content — the shortlist cut, a category's runners. Never re-sort it.
    var grouped = rows.some(function (r) {
      return r.classList.contains('ad-cut') || [].some.call(r.cells, function (c) { return c.colSpan > 1 || c.rowSpan > 1; });
    });

    // ── phone: each row a labelled card. The labels are the table's own headers.
    if (ths.length && ths.length <= 9 && table.getAttribute('data-stack') !== 'off') {
      rows.forEach(function (r) {
        [].forEach.call(r.cells, function (c, i) {
          var h = ths[i] ? txt(ths[i]) : '';
          if (h && !c.hasAttribute('data-label')) c.setAttribute('data-label', h);
        });
      });
      table.setAttribute('data-stack', '');
    }

    // ── which cell names the row: the one holding its main link, else the first one that
    // is not a hidden-until-needed control (the merge tick) — the card's heading on a phone.
    rows.forEach(function (r) {
      var cells = [].slice.call(r.cells);
      var pa = primary(r), pc = pa && cells.filter(function (c) { return c.contains(pa) && !c.classList.contains('col-actions'); })[0];
      pc = pc || cells.filter(function (c) { return !c.classList.contains('ux-when-on') && !c.querySelector('input[type=checkbox],input[type=radio]'); })[0];
      if (pc) pc.setAttribute('data-cell', 'name');
    });

    // ── a row opens its record
    rows.forEach(function (r) {
      var a = primary(r);
      if (!a) return;
      r.setAttribute('data-row-href', a.getAttribute('href'));
      r.classList.add('cn-row-go');
    });

    var wrap = table.closest('.ad-table-wrap, .cn-split__list') || table;
    var list = { table: table, body: body, rows: rows, at: -1 };
    lists.push(list);

    // ── sorting
    if (!grouped && rows.length > 1) {
      ths.forEach(function (th, i) {
        var label = txt(th);
        if (!label || th.querySelector('input,button,select,a') || /^(actions?|merge)$/i.test(label) || th.classList.contains('col-actions')) return;
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'cn-sort';
        b.innerHTML = '<span></span><span class="cn-sort__i" aria-hidden="true"></span>';
        b.firstChild.textContent = label;
        th.textContent = ''; th.appendChild(b);
        th.setAttribute('aria-sort', 'none');
        b.addEventListener('click', function () {
          var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
          ths.forEach(function (o) { if (o.hasAttribute('aria-sort')) o.setAttribute('aria-sort', 'none'); });
          th.setAttribute('aria-sort', dir);
          var sign = dir === 'ascending' ? 1 : -1;
          var cur = Array.prototype.slice.call(body.rows);
          cur.sort(function (x, y) {
            var a = key(x.cells[i]), c = key(y.cells[i]);
            if (a.t !== c.t) return (a.t - c.t) * sign;
            if (a.t === 2) return a.v.localeCompare(c.v) * sign;
            return (a.v < c.v ? -1 : a.v > c.v ? 1 : 0) * sign;
          });
          cur.forEach(function (r) { body.appendChild(r); });
          list.rows = cur;
          say(live, label + ', ' + (dir === 'ascending' ? 'ascending' : 'descending'));
        });
      });
    }

    // ── find in this list, unless the page already searches on the server
    var live = document.createElement('p');
    live.className = 'sr-only'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
    var serverSearch = main.querySelector('form[method="get" i] input[type="search"], form[method="get" i] input[name="q"], form[method="get" i] input[name="search"], form[method="get" i] input[type="text"]');
    if (rows.length >= 8 && !serverSearch) {
      var bar = document.createElement('div');
      bar.className = 'cn-find';
      var id = 'cnFind' + n;
      bar.innerHTML = '<label class="sr-only" for="' + id + '">Find in this list</label>'
        + '<input class="cn-find__in" id="' + id + '" type="search" placeholder="Find in this list" autocomplete="off" enterkeyhint="search" data-ux-find>'
        + '<span class="cn-find__n" aria-hidden="true"></span>';
      wrap.parentNode.insertBefore(bar, wrap);
      var input = bar.querySelector('input'), count = bar.querySelector('.cn-find__n');
      var empty = document.createElement('p');
      empty.className = 'cn-find__empty'; empty.hidden = true;
      wrap.parentNode.insertBefore(empty, wrap.nextSibling);
      var run = function () {
        var q = input.value.trim().toLowerCase(), shown = 0;
        list.rows.forEach(function (r) {
          var hit = !q || txt(r).toLowerCase().indexOf(q) !== -1;
          r.hidden = !hit; if (hit) shown++;
        });
        count.textContent = q ? shown + ' of ' + list.rows.length : '';
        empty.hidden = !(q && shown === 0);
        empty.textContent = q && !shown ? 'Nothing in this list matches “' + input.value.trim() + '”.' : '';
        say(live, q ? shown + ' of ' + list.rows.length + ' rows' : '');
      };
      input.addEventListener('input', run);
      input.addEventListener('keydown', function (e) { if (e.key === 'Escape' && input.value) { e.stopPropagation(); input.value = ''; run(); } });
    }
    wrap.parentNode.insertBefore(live, wrap);
  }

  var tables = main.querySelectorAll('table');
  for (var t = 0; t < tables.length; t++) enhanceTable(tables[t], t);

  // A click anywhere on a row that is not itself a control opens the row's record.
  main.addEventListener('click', function (e) {
    var tr = e.target.closest && e.target.closest('tr[data-row-href]');
    if (!tr || e.target.closest('a,button,input,select,textarea,label,summary,form,[contenteditable],.col-actions')) return;
    if (window.getSelection && String(window.getSelection())) return;   // they were selecting text
    var href = tr.getAttribute('data-row-href');
    if (e.metaKey || e.ctrlKey) window.open(href, '_blank'); else location.href = href;
  });

  function visibleRows(l) { return l.rows.filter(function (r) { return !r.hidden; }); }
  function cursor(l, step) {
    var rs = visibleRows(l);
    if (!rs.length) return;
    rs.forEach(function (r) { r.classList.remove('is-cursor'); });
    l.at = Math.max(0, Math.min(rs.length - 1, (l.at < 0 ? (step > 0 ? -1 : rs.length) : l.at) + step));
    var r = rs[l.at];
    r.classList.add('is-cursor');
    r.scrollIntoView({ block: 'nearest' });
  }

  // ══ FILTERS ════════════════════════════════════════════════════════════════════

  [].forEach.call(main.querySelectorAll('form[method="get" i]'), function (f) {
    if (f.getAttribute('data-ux') === 'off' || f.closest('.cn-find')) return;
    var fields = f.querySelectorAll('select, input:not([type=hidden]):not([type=submit]):not([type=button])');
    if (!fields.length) return;

    // Apply on change — a choice from a list is a decision, and making somebody find the
    // button afterwards is how a filter gets set and never applied. Typed text still
    // waits for Enter (or the button), so a half-typed name sends nothing.
    // `data-ux-show-when="name=value"`: a group shown only while that field has that value
    // (From/To under "Custom range"). Choosing the revealing value does not apply the form —
    // it opens the group and waits; a field inside a group waits for Enter or the button.
    var groups = [].slice.call(f.querySelectorAll('[data-ux-show-when]')).map(function (g) {
      var kv = g.getAttribute('data-ux-show-when').split('=');
      return { g: g, name: kv[0], value: kv[1] };
    });
    function syncGroups() {
      groups.forEach(function (x) {
        var el = f.elements[x.name];
        var on = !!el && el.value === x.value;
        x.g.hidden = !on && !x.g.hasAttribute('data-ux-keep');
        if (on) x.g.removeAttribute('data-ux-keep');
      });
    }
    syncGroups();
    var timer;
    f.addEventListener('change', function (e) {
      syncGroups();
      if (groups.some(function (x) { return x.name === e.target.name && x.value === e.target.value; })) {
        var first = groups.filter(function (x) { return x.name === e.target.name; })[0].g.querySelector('input,select');
        if (first) first.focus();
        return;
      }
      if (e.target.closest('[data-ux-show-when]')) return;
      if (!e.target.matches('select, input[type=date], input[type=checkbox], input[type=radio], input[type=month]')) return;
      clearTimeout(timer);
      timer = setTimeout(function () { f.requestSubmit ? f.requestSubmit() : f.submit(); }, 150);
    });

    // What is set, and one way to clear all of it.
    var params = new URLSearchParams(location.search), set = 0;
    [].forEach.call(fields, function (el) {
      var v = el.name ? params.get(el.name) : null;
      if (!v) return;
      // "All cycles", "All time": a select's first choice is the unfiltered one, not a filter.
      if (el.tagName === 'SELECT' && el.options.length && v === el.options[0].value) return;
      if (v === 'all') return;
      set++;
    });
    var ownClear = [].some.call(f.querySelectorAll('a'), function (a) { return /^clear\b/i.test(txt(a)); });
    if (set && !ownClear && !f.querySelector('[data-ux-clear]')) {
      var keep = new URLSearchParams();
      [].forEach.call(f.querySelectorAll('input[type=hidden][name]'), function (h) { keep.set(h.name, h.value); });
      var a = document.createElement('a');
      a.className = 'cn-btn cn-filter-clear'; a.setAttribute('data-ux-clear', '');
      a.href = (f.getAttribute('action') || location.pathname) + (keep.toString() ? '?' + keep : '');
      a.textContent = set === 1 ? 'Clear filter' : 'Clear ' + set + ' filters';
      f.appendChild(a);
    }

    // Phone: a form of three or more fields folds behind one button, so the list is the
    // first thing on the screen and not the fourth.
    if (fields.length >= 3 && phone.matches && !f.hasAttribute('data-ux-fold-off')) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'cn-btn cn-filter-toggle';
      b.setAttribute('aria-expanded', 'false');
      b.textContent = set ? 'Filters · ' + set + ' on' : 'Filters';
      f.parentNode.insertBefore(b, f);
      f.classList.add('cn-folded');
      b.addEventListener('click', function () {
        var open = f.classList.toggle('cn-folded') === false;
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { var first = f.querySelector('select, input:not([type=hidden])'); if (first) first.focus(); }
      });
    }
  });

  // ══ ON DEMAND, AND MENUS ═══════════════════════════════════════════════════════

  // `data-ux-reveal="#a, #b"` on a button: press it and `.ux-when-on` inside those turns
  // on. For controls only in the way until somebody starts a task (the merge columns).
  // The button is `hidden` in the markup, so a browser with no script shows the controls
  // and never a button that does nothing.
  [].forEach.call(main.querySelectorAll('[data-ux-reveal]'), function (b) {
    var targets = document.querySelectorAll(b.getAttribute('data-ux-reveal'));
    if (!targets.length) return;
    b.hidden = false;
    var label = b.textContent;
    b.addEventListener('click', function () {
      var on = !targets[0].classList.contains('is-ux-on');
      [].forEach.call(targets, function (t) { t.classList.toggle('is-ux-on', on); });
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
      b.textContent = on ? 'Done' : label;
    });
  });

  // `details.cn-more`: one open at a time, closed by Escape or a press anywhere else.
  function closeMenus(except) {
    [].forEach.call(document.querySelectorAll('details.cn-more[open]'), function (d) { if (d !== except) d.open = false; });
  }
  document.addEventListener('click', function (e) {
    var d = e.target.closest && e.target.closest('details.cn-more');
    closeMenus(d);
  });
  // Pinned to the viewport when it opens: inside a scrolling table an absolute menu is
  // clipped by the table's own box, and the last rows' menus open into nothing.
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (!d.matches || !d.matches('details.cn-more') || !d.open) return;
    var m = d.querySelector('.cn-more__menu'), sum = d.querySelector('summary');
    if (!m || !sum) return;
    var r = sum.getBoundingClientRect();
    m.style.position = 'fixed';
    m.style.right = 'auto';
    var w = m.offsetWidth, h = m.offsetHeight;
    var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w));
    var top = r.bottom + 4 + h > window.innerHeight - 8 ? Math.max(8, r.top - 4 - h) : r.bottom + 4;
    m.style.left = left + 'px';
    m.style.top = top + 'px';
  }, true);
  document.addEventListener('scroll', function () { closeMenus(null); }, { capture: true, passive: true });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var d = document.querySelector('details.cn-more[open]');
    if (d) { d.open = false; var s = d.querySelector('summary'); if (s) s.focus(); }
  });

  // ══ FORMS ══════════════════════════════════════════════════════════════════════

  var SCROLL = 'cn-ux:scroll';
  var dirty = new Set();
  var leaving = false;

  function editable(f) {
    if (f.getAttribute('data-ux') === 'off' || !main.contains(f)) return false;
    if ((f.getAttribute('method') || '').toLowerCase() !== 'post') return false;
    var ed = f.querySelectorAll('textarea, select, input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio])');
    return ed.length >= 3 || !!f.querySelector('textarea');
  }

  document.addEventListener('input', function (e) {
    var f = e.target.form || (e.target.closest && e.target.closest('form'));
    if (f && editable(f) && !e.target.closest('.cn-find')) dirty.add(f);
  }, true);

  // Bubble phase, last: if any handler (the confirm-with-reason dialog, a viewer's
  // read-only refusal, a page's own validation) prevented this submit, it never happened.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !main.contains(f)) return;
    if (f.hasAttribute('data-ux-sending')) { e.preventDefault(); return; }   // the second press
    if ((f.getAttribute('method') || '').toLowerCase() !== 'post') return;
    if ((f.getAttribute('target') || (e.submitter && e.submitter.getAttribute('formtarget')) || '') === '_blank') return;
    f.setAttribute('data-ux-sending', '');
    dirty.delete(f);
    leaving = true;
    var b = e.submitter;
    if (b && b.tagName === 'BUTTON' && !b.hasAttribute('data-ux-keep-label')) {
      b.setAttribute('data-ux-label', b.innerHTML);
      b.setAttribute('aria-busy', 'true');
      // Not `disabled`: a disabled submitter is dropped from the form data, and several
      // forms here decide what they do by the name of the button that sent them.
      b.classList.add('is-busy');
      b.textContent = b.getAttribute('data-busy') || (/delete|remove|revoke/i.test(txt(b)) ? 'Working…' : 'Saving…');
    }
    // A form that answers with a DOWNLOAD never leaves the page, so nothing would ever
    // reset it: after eight seconds still here, it is usable again.
    setTimeout(function () { restore(f); }, 8000);
    try {
      sessionStorage.setItem(SCROLL, JSON.stringify({ p: location.pathname + location.search, y: main.scrollTop, at: Date.now() }));
    } catch (_) {}
  });

  function restore(scope) {
    leaving = false;
    if (scope.hasAttribute && scope.hasAttribute('data-ux-sending')) scope.removeAttribute('data-ux-sending');
    [].forEach.call((scope.querySelectorAll ? scope : document).querySelectorAll('form[data-ux-sending]'), function (f) { f.removeAttribute('data-ux-sending'); });
    [].forEach.call(document.querySelectorAll('button[data-ux-label]'), function (b) {
      if (scope !== document && b.form !== scope && !scope.contains(b)) return;
      b.innerHTML = b.getAttribute('data-ux-label'); b.removeAttribute('data-ux-label');
      b.removeAttribute('aria-busy'); b.classList.remove('is-busy');
    });
  }

  // Back to a page restored from the bfcache: the buttons must work again.
  window.addEventListener('pageshow', function (e) { if (e.persisted) restore(document); });

  window.addEventListener('beforeunload', function (e) {
    if (leaving || !dirty.size) return;
    e.preventDefault(); e.returnValue = '';
  });

  // After a save, come back to where you were — the row you just changed, not the top.
  try {
    var s = JSON.parse(sessionStorage.getItem(SCROLL) || 'null');
    sessionStorage.removeItem(SCROLL);
    if (s && s.p === location.pathname + location.search && Date.now() - s.at < 60000 && s.y > 0) {
      requestAnimationFrame(function () { main.scrollTop = s.y; });
    }
  } catch (_) {}

  function submitFrom(el) {
    var f = el && el.closest ? el.closest('form') : null;
    if (!f) {
      // Nothing focused: the one form with edits in it, if there is exactly one.
      if (dirty.size !== 1) return false;
      f = dirty.values().next().value;
    }
    if (!f || (f.getAttribute('method') || '').toLowerCase() !== 'post' || !main.contains(f)) return false;
    var b = f.querySelector('button[type=submit]:not([formaction]), button:not([type]):not([formaction])');
    f.requestSubmit ? f.requestSubmit(b || undefined) : f.submit();
    return true;
  }

  // ══ KEYS ═══════════════════════════════════════════════════════════════════════

  var help = null;
  function showHelp() {
    if (!help) {
      help = document.createElement('dialog');
      help.className = 'cn-keys';
      help.setAttribute('aria-labelledby', 'cnKeysT');
      var mod = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘' : 'Ctrl';
      var rowsHtml = [
        [mod + ' K', 'Search the console'], ['/', 'Find on this page'], ['j  k', 'Next and previous row'],
        ['Enter', 'Open the row'], [mod + ' S', 'Save the form you are in'], ['Ctrl Enter', 'Send from a text box'],
        ['Esc', 'Close, or clear the find box'], ['?', 'This list'],
      ].map(function (r) { return '<div><dt><kbd>' + r[0] + '</kbd></dt><dd>' + r[1] + '</dd></div>'; }).join('');
      help.innerHTML = '<h2 class="cn-keys__t" id="cnKeysT">Keyboard shortcuts</h2><dl class="cn-keys__l">' + rowsHtml
        + '</dl><form method="dialog"><button class="cn-btn" type="submit">Close</button></form>';
      document.body.appendChild(help);
      help.addEventListener('click', function (e) { if (e.target === help) help.close(); });
    }
    if (!help.open) help.showModal();
  }

  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented) return;
    var k = e.key;
    if ((e.metaKey || e.ctrlKey) && (k === 's' || k === 'S')) {
      if (submitFrom(document.activeElement)) e.preventDefault();
      return;
    }
    if ((e.metaKey || e.ctrlKey) && k === 'Enter' && document.activeElement && document.activeElement.tagName === 'TEXTAREA') {
      if (submitFrom(document.activeElement)) e.preventDefault();
      return;
    }
    if (e.metaKey || e.ctrlKey || e.altKey || typing(document.activeElement)) return;
    if (document.querySelector('dialog[open]:not(.cn-keys)')) return;
    if (k === '?') { e.preventDefault(); showHelp(); return; }
    if (k === '/') {
      var box = main.querySelector('[data-ux-find], input[type=search], form[method="get" i] input[type=text]');
      if (box) { e.preventDefault(); box.focus(); box.select && box.select(); }
      return;
    }
    var l = lists[0];
    if (!l) return;
    if (k === 'j') { e.preventDefault(); cursor(l, 1); }
    else if (k === 'k') { e.preventDefault(); cursor(l, -1); }
    else if (k === 'Enter' && l.at >= 0) {
      var r = visibleRows(l)[l.at];
      if (r && r.getAttribute('data-row-href')) { e.preventDefault(); location.href = r.getAttribute('data-row-href'); }
    }
  });
})();
