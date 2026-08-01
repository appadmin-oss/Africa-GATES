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

  function render(res) {
    items = res.items || [];
    if (!items.length) {
      list.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      status.textContent = input.value.trim() ? 'No matches for “' + input.value.trim() + '”.' : '';
      return;
    }

    var html = '';
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      html += '<li class="ags__item" role="presentation">'
        + '<a class="ags__link" role="option" id="agsOpt' + i + '" aria-selected="false" href="' + esc(it.url) + '">'
        + '<span class="ags__kind">' + esc(it.label || it.kind) + '</span>'
        + '<span class="ags__t">' + esc(it.title) + '</span>'
        + (it.detail ? '<span class="ags__d">' + esc(it.detail) + '</span>' : '')
        + (it.at_label ? '<span class="ags__at">' + esc(it.at_label) + '</span>' : '')
        + '</a></li>';
    }
    list.innerHTML = html;
    input.setAttribute('aria-expanded', 'true');
    active = -1;
    input.removeAttribute('aria-activedescendant');

    // Announced on the always-present live region. Counting is the useful part —
    // a screen-reader user cannot see the list grow.
    status.textContent = items.length + (items.length === 1 ? ' result' : ' results')
      + (res.understood && res.understood.summary ? '. Read as: ' + res.understood.summary : '');
  }

  // ── querying ──────────────────────────────────────────────────────────────

  function run() {
    var q = input.value.trim();
    if (q.length < 2) { clear(); return; }

    var mine = ++seq;
    // Announced before the request, because on a slow connection the gap between
    // typing and results is exactly where a non-sighted user has no idea whether
    // anything is happening.
    status.textContent = 'Searching…';

    fetch('/activity/search?limit=12&q=' + encodeURIComponent(q), {
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
