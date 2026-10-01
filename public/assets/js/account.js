/* ══ /account — the enhancement layer ═══════════════════════════════════════════
   ═══════════════════════════════════════════════════════════════════════════════

   EVERYTHING ON THIS PAGE WORKS WITHOUT THIS FILE. The rail rows are real
   `<a href="#me-…">` and `:target` reveals the section; the forms post; the links
   navigate. What this adds is the rail highlight, the search filter, the three table
   filters, the balance toggle, copy buttons and the phone search row.

   That is deliberate and it is the fix for a real failure: the rail used to depend on a
   delegated click handler, so anything that stopped the script — a 404 after a deploy, a
   CSP mismatch, an extension, an error thrown earlier in the bundle — left every tab dead
   while the URL still changed. "The tabs do nothing" is impossible to diagnose from the
   outside. With the browser doing the navigation, the worst case is a stale highlight.

   NO `preventDefault` ANYWHERE IN THE SECTION HANDLER. If you find yourself adding one,
   the mechanism has been inverted again. */

(function () {
  'use strict';

  var root = document.documentElement;
  var page = document.getElementById('me');
  if (!page) return;

  var find = document.getElementById('meFind');
  var note = document.getElementById('meFindNote');

  // ── sections ───────────────────────────────────────────────────────────────
  //
  // The head script already chose one before first paint. This only keeps the rail
  // highlight in step with clicks and the back button; the stylesheet does the showing.
  var IDS  = (function () {
    var out = [];
    var rows = page.querySelectorAll('[data-me-title]');
    for (var i = 0; i < rows.length; i++) out.push(rows[i].getAttribute('data-me-title'));
    return out;
  })();

  var rail = document.getElementById('meRail');

  function paint(id) {
    if (IDS.indexOf(id) < 0) id = 'overview';
    root.setAttribute('data-me', id);
    if (!rail) return;
    var items = rail.querySelectorAll('[data-me-go]');
    for (var i = 0; i < items.length; i++) {
      if (items[i].getAttribute('data-me-go') === id) items[i].setAttribute('aria-current', 'page');
      else items[i].removeAttribute('aria-current');
    }
  }
  function fromHash() { return (location.hash || '').replace('#', '').replace(/^me-/, ''); }
  paint(fromHash());

  page.addEventListener('click', function (e) {
    var go = e.target.closest ? e.target.closest('[data-me-go]') : null;
    if (!go) return;
    if (find && find.value) { find.value = ''; runFind(); }
    paint(go.getAttribute('data-me-go'));
  });

  // The hash also changes without a click here — Back, Forward, a pasted link.
  // `hashchange` covers all of them; `popstate` alone did not.
  window.addEventListener('hashchange', function () { paint(fromHash()); });
  window.addEventListener('popstate',   function () { paint(fromHash()); });

  // Arrow keys along the rail, which is what a set of related navigation controls is
  // expected to do.
  if (rail) rail.addEventListener('keydown', function (e) {
    if (['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft'].indexOf(e.key) < 0) return;
    var items = Array.prototype.slice.call(rail.querySelectorAll('[data-me-go]'));
    var at = items.indexOf(document.activeElement);
    if (at < 0) return;
    e.preventDefault();
    var step = (e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : -1;
    items[(at + step + items.length) % items.length].focus();
  });

  // ── the search ─────────────────────────────────────────────────────────────
  //
  // Filters `[data-find]` rows across EVERY section at once, because "where is that
  // order" does not come with a section attached. While a query is running the rail is
  // irrelevant — results come from everywhere — so every section is revealed and the
  // empty ones are hidden by the filter itself.
  var rows  = Array.prototype.slice.call(page.querySelectorAll('[data-find]'));
  var timer = null;

  function show(el, on) { el.hidden = !on; }

  function runFind() {
    var q = (find.value || '').trim().toLowerCase();
    var secs = page.querySelectorAll('.me-sec');
    var i;

    if (!q) {
      root.classList.remove('me-searching');
      page.classList.remove('me-searching');
      for (i = 0; i < rows.length; i++) show(rows[i], true);
      for (i = 0; i < secs.length; i++) show(secs[i], true);
      if (note) note.textContent = '';
      return;
    }

    root.classList.add('me-searching');
    page.classList.add('me-searching');

    var hits = 0;
    for (i = 0; i < rows.length; i++) {
      var hay = (rows[i].getAttribute('data-find') + ' ' + rows[i].textContent).toLowerCase();
      var on  = hay.indexOf(q) >= 0;
      show(rows[i], on);
      if (on) hits++;
    }

    // A section whose every findable row is hidden is a heading over nothing, so it goes
    // too — otherwise the results read "Purchases: (nothing) Activity: (nothing)".
    for (i = 0; i < secs.length; i++) {
      var mine = secs[i].querySelectorAll('[data-find]');
      var any  = false;
      for (var j = 0; j < mine.length; j++) if (!mine[j].hidden) { any = true; break; }
      show(secs[i], mine.length > 0 && any);
    }

    if (note) {
      note.textContent = hits === 0
        ? 'Nothing matches “' + find.value.trim() + '”.'
        : hits + (hits === 1 ? ' match' : ' matches') + ' for “' + find.value.trim() + '”.';
    }
  }

  if (find) {
    find.addEventListener('input', function () {
      clearTimeout(timer);
      // 120ms: long enough not to filter on every keystroke, short enough to feel like
      // it is keeping up.
      timer = setTimeout(runFind, 120);
    });
    find.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { find.value = ''; runFind(); }
    });
  }

  // ── the phone search row ───────────────────────────────────────────────────
  //
  // It REVEALS the one field rather than owning a second one: what is typed, what is
  // announced and what is filtered all stay with `#meFind`.
  var phFind = document.getElementById('mePhFind');
  if (phFind && find) {
    phFind.addEventListener('click', function () {
      root.classList.add('me-finding');
      phFind.setAttribute('aria-expanded', 'true');
      find.focus();
    });
    find.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && root.classList.contains('me-finding')) {
        root.classList.remove('me-finding');
        phFind.setAttribute('aria-expanded', 'false');
        phFind.focus();
      }
    });
    // An empty field on blur puts the greeting row back: a search row left open over a
    // page nobody is searching is 60px of the first fold spent on nothing.
    find.addEventListener('blur', function () {
      if (!find.value && root.classList.contains('me-finding')) {
        root.classList.remove('me-finding');
        phFind.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ── the eye ────────────────────────────────────────────────────────────────
  //
  // Revealed here rather than rendered visible, because the answer lives in
  // `localStorage` and a control that forgets what you told it is worse than no control:
  // you hide the number, open Points, and it is back. No storage, no button.
  var eye = document.getElementById('meEye');
  if (eye) {
    var store = null;
    try {
      // The probe WRITES, because reading is not what fails: in a private window
      // `getItem` answers null quite happily and `setItem` throws, so a read-only probe
      // reveals a button whose answer is discarded the moment it is given.
      //
      // It writes the REAL key — its own current value — rather than a scratch one. A
      // throwaway probe key is still a key this site puts on somebody's device, and
      // `/cookies` has to declare every one of those by name.
      localStorage.setItem('ag-hide-bal', localStorage.getItem('ag-hide-bal') === '1' ? '1' : '0');
      store = localStorage;
    } catch (e) { store = null; }

    if (store) {
      eye.hidden = false;
      var paintEye = function () {
        var off = root.getAttribute('data-bal') === 'hidden';
        eye.setAttribute('aria-pressed', off ? 'true' : 'false');
        // The LABEL is the ACTION, not the state: a button announcing "Balances hidden"
        // tells a reader where they are and not what pressing it does.
        eye.setAttribute('aria-label', off ? 'Show balances' : 'Hide balances');
      };
      paintEye();
      eye.addEventListener('click', function () {
        var off = root.getAttribute('data-bal') === 'hidden';
        if (off) root.removeAttribute('data-bal'); else root.setAttribute('data-bal', 'hidden');
        try { store.setItem('ag-hide-bal', off ? '0' : '1'); } catch (e) {}
        paintEye();
        if (window.agAnnounce) window.agAnnounce(off ? 'Balances shown' : 'Balances hidden');
      });
    }
  }

  // ── the three table filters ────────────────────────────────────────────────
  //
  // One implementation, three tables. They differ only in which attribute holds a row's
  // group, which is why the chips carry the attribute NAME: three near-identical
  // handlers is how one of them comes to behave differently from the others.
  [
    { chip: 'data-me-filter', row: 'data-me-sign',     table: 'meLedger', none: 'meLedgerNone' },
    { chip: 'data-me-buy',    row: 'data-me-kind',     table: 'meBuys',   none: 'meBuysNone'   },
    { chip: 'data-me-nom',    row: 'data-me-nomstate', table: 'meNoms',   none: 'meNomsNone'   }
  ].forEach(function (f) {
    var table = document.getElementById(f.table);
    if (!table) return;

    page.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[' + f.chip + ']') : null;
      if (!b) return;

      var want = b.getAttribute(f.chip);
      var all  = page.querySelectorAll('[' + f.chip + ']');
      for (var i = 0; i < all.length; i++) {
        all[i].setAttribute('aria-pressed', all[i] === b ? 'true' : 'false');
      }

      var items = table.querySelectorAll('[' + f.row + ']');
      var shown = 0;
      for (var j = 0; j < items.length; j++) {
        var on = want === 'all' || items[j].getAttribute(f.row) === want;
        items[j].hidden = !on;
        if (on) shown++;
      }

      // The month bands belong to the rows under them, so a band whose rows are all
      // filtered away is a heading over nothing.
      var bands = table.querySelectorAll('.me-tbl__g');
      for (var k = 0; k < bands.length; k++) {
        var live = false, n = bands[k].nextElementSibling;
        while (n && !n.classList.contains('me-tbl__g')) {
          if (!n.hidden && n.hasAttribute(f.row)) { live = true; break; }
          n = n.nextElementSibling;
        }
        bands[k].hidden = !live;
      }

      var none = document.getElementById(f.none);
      if (none) none.hidden = shown > 0;
    });
  });

  // ── copy ───────────────────────────────────────────────────────────────────
  //
  // A visual tick AND an announcement: the icon swap is invisible to a screen reader and
  // the label change is invisible to everyone else.
  function copied(btn, original) {
    var was = btn.getAttribute('aria-label');
    btn.textContent = 'Copied';
    btn.setAttribute('aria-label', 'Copied');
    if (window.agAnnounce) window.agAnnounce('Copied');
    setTimeout(function () {
      btn.textContent = original;
      if (was) btn.setAttribute('aria-label', was); else btn.removeAttribute('aria-label');
    }, 1800);
  }

  function write(text, ok, fail) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(ok, fail);
      return;
    }
    // Older Safari, and any context where the async API is unavailable.
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var done = false;
    try { done = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    (done ? ok : fail)();
  }

  page.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-me-copy]') : null;
    if (!b) return;
    var original = b.textContent;
    write(b.getAttribute('data-me-copy'),
      function () { copied(b, original); },
      function () { b.textContent = 'Press Ctrl+C'; setTimeout(function () { b.textContent = original; }, 2200); });
  });

  page.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-rf-copy]') : null;
    if (!b) return;
    var field = document.getElementById(b.getAttribute('data-rf-copy'));
    if (!field) return;
    var original = b.textContent;
    field.select();
    write(field.value,
      function () { copied(b, original); },
      function () { b.textContent = 'Press Ctrl+C'; setTimeout(function () { b.textContent = original; }, 2200); });
  });

  // ── adding a passkey ───────────────────────────────────────────────────────
  //
  // Revealed only when this browser can actually run the ceremony, and EVERY OUTCOME IS
  // SHOWN: a cancelled prompt rejects a promise, and a page that catches and drops it is
  // a button that does nothing — the shape of the camera and autoplay faults this
  // codebase has already paid for.
  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('meKeyAdd');
    var btn  = document.getElementById('meKeyBtn');
    var kn   = document.getElementById('meKeyNote');
    if (!wrap || !btn || !window.agPasskeys || !window.agPasskeys.supported()) return;

    wrap.hidden = false;

    function say(msg, bad) {
      kn.textContent = msg;
      kn.hidden = false;
      kn.setAttribute('role', bad ? 'alert' : 'status');
    }

    btn.addEventListener('click', function () {
      btn.disabled = true;
      say('Follow the prompt from your device…', false);
      // The device name is the member's, and the platform is the only thing we can
      // honestly suggest — a browser cannot tell us it is "Ada's phone".
      var guess = (navigator.userAgentData && navigator.userAgentData.platform)
        || navigator.platform || 'This device';
      window.agPasskeys.enrol(guess).then(function () {
        say('Added. Reloading so you can see it…', false);
        window.location.href = '/account#me-security';
        window.location.reload();
      }).catch(function (err) {
        btn.disabled = false;
        say(window.agPasskeys.say(err), true);
      });
    });
  });
})();
