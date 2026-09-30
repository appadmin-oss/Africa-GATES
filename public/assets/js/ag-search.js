/*!
 * ag-search.js — the site-wide search overlay.
 *
 * Progressive enhancement over a real GET form: with this file absent (or
 * broken) the box still submits to /activity, which renders the same results
 * server-side. Nothing here is required to find anything; it makes finding it
 * faster.
 *
 * The accessibility notes live next to the code that implements them, because
 * that is where they get read when someone changes it.
 */
(function (w, d) {
  'use strict';

  var root = d.querySelector('[data-ag-search]');
  if (!root) return;

  var input   = d.getElementById('agsInput');
  var list    = d.getElementById('agsResults');
  var status  = d.getElementById('agsStatus');
  var form    = root.querySelector('form');
  if (!input || !list || !status) return;

  var open = false, active = -1, items = [], seq = 0, timer = null, opener = null;

  /* The chip in force, and the map that says what it asks for. The MAP IS NOT
     DECLARED HERE: it arrives with every response, because it is decided in
     `ActivityFeedService::SCOPES` and a second copy in this file is two lists
     that drift — visibly as a result filed under the wrong heading, and
     invisibly as one filed under none. Empty until the first response, which is
     also the first moment there is anything to group. */
  var scope = '', scopeMap = null;

  var SCOPE_LABEL = { people: 'People', awards: 'Awards', events: 'Events', pages: 'Pages' };

  // ── ARIA the input must carry as a combobox ───────────────────────────────
  // Set from script, not markup: an input advertising role="combobox" with no
  // listbox behaviour is a lie to a screen reader, and that is exactly what the
  // markup would be if this file failed to load.
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', 'agsResults');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-haspopup', 'listbox');
  // Same reason: a list that claims to hold options when nothing will ever add
  // any is the other half of the same lie. The pair is set together, here.
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', 'Search results');

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // ── open / close ──────────────────────────────────────────────────────────

  function show(from) {
    if (open) return;
    open = true;

    // Where focus goes when this closes. The keyboard shortcut fires with nothing
    // focused, so `from` is <body> — which is not focusable, and "returning" focus
    // there drops a keyboard user back at the top of the document. In that case
    // hand focus to the header's search button instead: it is visible, it is what
    // they would have pressed, and it leaves them next to the thing they used.
    opener = from && from !== d.body && from !== d.documentElement ? from : null;
    if (!opener) opener = d.querySelector('[data-ag-search-open]');

    root.hidden = false;
    d.documentElement.style.overflow = 'hidden';   // the page must not scroll behind
    // rAF because an element revealed in the same frame is not yet focusable.
    requestAnimationFrame(function () { input.focus(); input.select(); });

    // An empty panel is a dead end: somebody who opened this to browse has
    // nothing to read and no clue what the box reaches. It fills with the latest
    // feed, grouped under the same headings a query produces, so the first thing
    // they see is the shape of the answer.
    run();
  }

  function hide() {
    if (!open) return;
    open = false;
    root.hidden = true;
    d.documentElement.style.overflow = '';
    clear();
    // Focus RETURNS to whatever opened this. Without it focus falls back to
    // <body> and a keyboard user restarts their tab journey from the top of the
    // page every time they close the search.
    if (opener && d.contains(opener)) opener.focus();
    opener = null;
  }

  function clear() {
    list.innerHTML = '';
    items = []; active = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    status.textContent = '';
  }

  // ── the active option ─────────────────────────────────────────────────────
  // Moved with aria-activedescendant, NEVER with focus(). Real focus in the list
  // takes it out of the input, so the next keystroke goes somewhere the user is
  // not looking — type "vote", arrow down, type "r", and the "r" is lost.
  function setActive(i) {
    var opts = list.querySelectorAll('[role="option"]');
    if (!opts.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }

    active = (i + opts.length) % opts.length;
    for (var k = 0; k < opts.length; k++) {
      var on = k === active;
      opts[k].setAttribute('aria-selected', on ? 'true' : 'false');
      opts[k].classList.toggle('is-active', on);
      if (on) {
        input.setAttribute('aria-activedescendant', opts[k].id);
        if (opts[k].scrollIntoView) opts[k].scrollIntoView({ block: 'nearest' });
      }
    }
  }

  /* Which heading an item belongs under, from the delivered map. A kind the map
     does not mention goes under "More" rather than being dropped: a result that
     exists and appears nowhere is the worst of the three outcomes, and it is the
     one a silent `continue` produces. */
  function groupOf(kind) {
    if (!scopeMap) return 'more';
    for (var k in scopeMap) {
      if (!Object.prototype.hasOwnProperty.call(scopeMap, k)) continue;
      if (scopeMap[k].indexOf(kind) >= 0) return k;
    }
    return 'more';
  }

  /* ≤6 per group — §7.1. The cap is per GROUP and not overall, so one crowded
     kind cannot push every other heading off the panel: a search for a common
     first name used to return twelve nominees and nothing else, with the award
     and the page that matched it below the fold of a list nobody scrolls. */
  var PER_GROUP = 6;

  function render(res) {
    if (res.scopes) scopeMap = res.scopes;

    var all = res.items || [];
    if (!all.length) {
      items = [];
      list.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      /* Name the CHIP when one is pressed. "No matches for award" over a panel
         filtered to People reads as the search being broken; "no people match" says
         which control to move. The chip is the only thing the person changed. */
      var q = input.value.trim();
      var where = scope && SCOPE_LABEL[scope] ? ' in ' + SCOPE_LABEL[scope].toLowerCase() : '';
      status.textContent = q ? 'No matches' + where + ' for \u201c' + q + '\u201d.' : '';
      return;
    }

    // Bucket in the order the chips are drawn, so the panel and the chip row
    // read top to bottom the same way.
    var order = ['people', 'awards', 'events', 'pages', 'more'];
    var buckets = {}, i;
    for (i = 0; i < all.length; i++) {
      var g = groupOf(all[i].kind);
      (buckets[g] = buckets[g] || []).push(all[i]);
    }

    // `items` is rebuilt to match the DRAWN order, because the arrow keys walk
    // it by index — leaving it as the server's order makes Down highlight one
    // row and Enter open another.
    items = [];
    var html = '', n = 0;
    for (var o = 0; o < order.length; o++) {
      var key = order[o], rows = buckets[key];
      if (!rows || !rows.length) continue;
      rows = rows.slice(0, PER_GROUP);

      var label = SCOPE_LABEL[key] || 'More';
      html += '<li class="ags__grp" role="group" aria-label="' + esc(label) + '">'
            + '<span class="ags__grph" aria-hidden="true">' + esc(label) + '</span>';
      for (i = 0; i < rows.length; i++) {
        var it = rows[i];
        items.push(it);
        html += '<a class="ags__link" role="option" id="agsOpt' + n + '" aria-selected="false" href="' + esc(it.url) + '">'
          + '<span class="ags__kind">' + esc(it.label || it.kind) + '</span>'
          + '<span class="ags__t">' + esc(it.title) + '</span>'
          + (it.detail ? '<span class="ags__d">' + esc(it.detail) + '</span>' : '')
          + (it.at_label ? '<span class="ags__at">' + esc(it.at_label) + '</span>' : '')
          + '</a>';
        n++;
      }
      html += '</li>';
    }

    list.innerHTML = html;
    input.setAttribute('aria-expanded', 'true');
    active = -1;
    input.removeAttribute('aria-activedescendant');

    // Announced on the always-present live region. Counting is the useful part —
    // a screen-reader user cannot see the list grow. The count is what is SHOWN,
    // not what arrived: saying "12 results" over a panel holding eight is a
    // number somebody then goes looking for.
    var shown = items.length;
    if (!input.value.trim()) {
      // Not "12 results" — nobody searched for anything. Saying "results" over an
      // unasked question is the same class of untruth as a count that is not the
      // count being shown.
      status.textContent = shown + ' recent ' + (shown === 1 ? 'item' : 'items');
      return;
    }
    status.textContent = shown + (shown === 1 ? ' result' : ' results')
      + (shown < all.length ? ', the closest in each group' : '')
      + (res.understood && res.understood.summary ? '. Read as: ' + res.understood.summary : '');
  }

  // ── querying ──────────────────────────────────────────────────────────────

  function run() {
    var q = input.value.trim();

    /* An empty box asks for the latest feed rather than showing nothing. §7.1
       asks for "trending, open-now and coming-soon"; there is no trending signal
       anywhere in this codebase — no view counts, no per-item reads — and
       inventing one is the fault the homepage globe was built to undo, where
       sixteen cities arrived with ballot counts and a median latency this
       platform has never recorded. So the empty state is what is TRUE: what has
       just happened, grouped under the same headings. GAPS.md §9.11.

       The endpoint already answers an empty query with the latest feed, so this
       is one parameter rather than a second code path. */
    var mine = ++seq;
    // Announced before the request, because on a slow connection the gap between
    // typing and results is exactly where a non-sighted user has no idea whether
    // anything is happening.
    status.textContent = 'Searching…';

    fetch('/activity/search?limit=24&q=' + encodeURIComponent(q)
          + (scope ? '&scope=' + encodeURIComponent(scope) : ''), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        // A slow earlier request must not overwrite a newer one's results.
        if (mine !== seq) return;
        if (!j || !j.ok) throw 0;
        render(j);
      })
      .catch(function () {
        if (mine !== seq) return;
        status.textContent = 'Search is unavailable right now. Press Enter for the full search page.';
      });
  }

  // ── wiring ────────────────────────────────────────────────────────────────

  Array.prototype.forEach.call(d.querySelectorAll('[data-ag-search-open]'), function (b) {
    b.addEventListener('click', function () { show(b); });
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-ag-search-close]'), function (b) {
    b.addEventListener('click', hide);
  });

  /* A chip re-runs the query it is filtering, and moves the tab stop with it:
     `role="tablist"` is one Tab stop and arrow keys between the tabs, and a tab
     row that only answers Tab tells a screen-reader user to press keys that do
     nothing. */
  var chips = Array.prototype.slice.call(root.querySelectorAll('[data-ags-scope]'));
  function pickScope(btn) {
    scope = btn.getAttribute('data-ags-scope') || '';
    chips.forEach(function (c) {
      var on = c === btn;
      c.setAttribute('aria-selected', on ? 'true' : 'false');
      c.tabIndex = on ? 0 : -1;
    });
    run();
    // Focus goes back to the box: the chip narrows what is being typed about,
    // and leaving focus on it means the next keystroke lands nowhere.
    input.focus();
  }
  chips.forEach(function (c, i) {
    c.tabIndex = i === 0 ? 0 : -1;
    c.addEventListener('click', function () { pickScope(c); });
    c.addEventListener('keydown', function (e) {
      var step = (e.key === 'ArrowRight') ? 1 : (e.key === 'ArrowLeft') ? -1 : 0;
      if (!step) return;
      e.preventDefault();
      var next = chips[(i + step + chips.length) % chips.length];
      next.focus();
      pickScope(next);
    });
  });

  input.addEventListener('input', function () {
    clearTimeout(timer);
    // 220ms: long enough that a normal typist fires one request per word rather
    // than one per letter, short enough to still feel live.
    timer = setTimeout(run, 220);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(active - 1); return; }
    if (e.key === 'Home' && items.length) { e.preventDefault(); setActive(0); return; }
    if (e.key === 'End'  && items.length) { e.preventDefault(); setActive(items.length - 1); return; }
    if (e.key === 'Escape') { e.preventDefault(); hide(); return; }
    if (e.key === 'Enter') {
      var opts = list.querySelectorAll('[role="option"]');
      if (active >= 0 && opts[active]) {
        e.preventDefault();
        w.location.href = opts[active].getAttribute('href');
      }
      // Otherwise the form submits to /activity — so Enter always goes somewhere.
    }
  });

  // Clicking a result is the same navigation as Enter; the anchor handles it.
  // Keeping them real anchors is what makes middle-click and "open in new tab"
  // work, which a div with a click handler silently breaks.

  root.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab' || !open) return;
    // Focus trap. Everything focusable inside the panel, in DOM order.
    var f = root.querySelectorAll('a[href], button, input, [tabindex]:not([tabindex="-1"])');
    var vis = Array.prototype.filter.call(f, function (el) { return el.offsetParent !== null; });
    if (!vis.length) return;
    var first = vis[0], last = vis[vis.length - 1];
    if (e.shiftKey && d.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && d.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  if (form) form.addEventListener('submit', function () { /* let it navigate */ });

  // ── the shortcut ──────────────────────────────────────────────────────────
  d.addEventListener('keydown', function (e) {
    if (open) return;
    var t = e.target, tag = (t && t.tagName) || '';
    // Never steal a keystroke from somewhere text is being written. Without this
    // check, "/" is unusable in the Pulse composer and in every comment box.
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;

    if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey) { e.preventDefault(); show(t); }
    else if (e.key === 'k' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); show(t); }
  });
})(window, document);
