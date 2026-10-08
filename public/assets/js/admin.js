// ═══ The admin console — shell, overlays, and the page behaviours ══════════════
//
// Destroyed and rebuilt 4 Oct 2026 with the console shell (admin handoff README §2, §7,
// §8). What the old file did, rule by rule: docs/handoff/inventory/_admin.md. The page
// behaviours below the shell section are carried over unchanged in substance — the
// tier swatch, the size boxes, the door's voice preview — because they are pinned by
// their own tests and are not the shell's to redesign.
//
// The admin CSP has no 'unsafe-inline', so there is no inline handler anywhere: every
// control is delegated here on `data-ag-do`. Every shell control also works with this
// file absent — the pin, unpin and sidebar toggles are forms that post, the rail is
// links, the assistant has its own page.

(function () {
  'use strict';

  var body = document.body;
  var readOnly = body.hasAttribute('data-readonly');
  // Read at the moment of use: csrf-fresh.js rewrites the meta tag when a page that has been
  // open a while is checked, and a copy taken at load would go on posting the dead token.
  function csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }
  var phone = window.matchMedia ? window.matchMedia('(max-width: 768px)') : { matches: false };

  // ── TOAST (§7): a black pill at the bottom centre, 5.2 s, optional Undo ────────
  var toastMs = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--cn-toast-ms'), 10) || 5200;
  var toasts = document.getElementById('cnToasts');
  function agToast(msg, undo) {
    if (!toasts) return;
    var t = document.createElement('div');
    t.className = 'cn-toast';
    t.setAttribute('role', 'status');
    var s = document.createElement('span');
    s.textContent = msg;
    t.appendChild(s);
    if (typeof undo === 'function') {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Undo';
      b.addEventListener('click', function () { t.remove(); undo(); });
      t.appendChild(b);
    }
    toasts.appendChild(t);
    setTimeout(function () { t.remove(); }, toastMs);
  }
  window.agToast = agToast;
  // A flash from the previous request arrives as a toast already in the markup.
  if (toasts) toasts.querySelectorAll('.cn-toast').forEach(function (t) { setTimeout(function () { t.remove(); }, toastMs); });

  // ── A VIEWER READS AND CHANGES NOTHING (rule 8) ─────────────────────────────
  //
  // Every write control is drawn at 45% (console.css); pressing one shows this toast and
  // posts nothing. The server refuses it regardless (AdminAuthMiddleware) — this is the
  // explanation, not the enforcement. `data-cn-safe` forms change nothing on the platform.
  var READ_ONLY_SAY = 'Viewers can read but not change anything';
  function isWriteForm(form) {
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-cn-safe')) return false;
    var m = (form.getAttribute('method') || 'get').toLowerCase();
    return m !== 'get' && m !== 'dialog';
  }
  if (readOnly) {
    document.addEventListener('submit', function (e) {
      if (!isWriteForm(e.target)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      agToast(READ_ONLY_SAY);
    }, true);
    document.addEventListener('click', function (e) {
      var w = e.target.closest('[data-write]');
      if (!w) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      agToast(READ_ONLY_SAY);
    }, true);
  }

  // ── THE SIDEBAR: toggle (persisted), fold, phone overlay ─────────────────────
  var side = document.getElementById('cnSide');
  var scrim = document.querySelector('.cn-scrim');
  function setOverlay(open) {
    if (open) { body.setAttribute('data-side-open', ''); if (scrim) scrim.hidden = false; }
    else { body.removeAttribute('data-side-open'); if (scrim) scrim.hidden = true; }
    var t = document.querySelector('[data-ag-do="side-toggle"]');
    if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-ag-do="side-toggle"]');
    if (t) {
      e.preventDefault();
      if (phone.matches) { setOverlay(!body.hasAttribute('data-side-open')); return; }
      var closing = body.getAttribute('data-side') !== 'closed';
      if (closing) body.setAttribute('data-side', 'closed'); else body.removeAttribute('data-side');
      t.setAttribute('aria-expanded', closing ? 'false' : 'true');
      var f = t.form;
      if (f) {
        f.querySelector('[name="closed"]').value = closing ? '1' : '0';
        fetch(f.action, { method: 'POST', credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
          body: new FormData(f) }).catch(function () {});
        f.querySelector('[name="closed"]').value = closing ? '0' : '1';
      }
      return;
    }
    if (e.target.closest('[data-ag-do="side-close"]')) { setOverlay(false); return; }
    var more = e.target.closest('[data-ag-do="nav-more"]');
    if (more) {
      var g = more.closest('[data-fold]');
      var open = !g.hasAttribute('data-open');
      if (open) g.setAttribute('data-open', ''); else g.removeAttribute('data-open');
      more.setAttribute('aria-expanded', open ? 'true' : 'false');
      more.querySelector('.cn-nav__more-l').textContent = open ? more.getAttribute('data-less') : more.getAttribute('data-more');
    }
  });

  // ── THE ACCOUNT MENU ─────────────────────────────────────────────────────────
  var acctBtn = document.querySelector('[data-ag-do="acct-toggle"]');
  var acct = document.getElementById('cnAcct');
  function closeAcct() { if (acct && !acct.hidden) { acct.hidden = true; acctBtn.setAttribute('aria-expanded', 'false'); } }
  if (acctBtn && acct) {
    acctBtn.addEventListener('click', function () {
      var open = acct.hidden;
      acct.hidden = !open;
      acctBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var first = acct.querySelector('[role="menuitem"]'); if (first) first.focus(); }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.cn-acct')) closeAcct(); });
  }

  // ── PIN / UNPIN WITHOUT A RELOAD, AND UNDO ───────────────────────────────────
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement)) return;
    var kind = f.getAttribute('data-ag-do');
    if (kind !== 'pin' && kind !== 'unpin') return;
    e.preventDefault();
    fetch(f.action, { method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }, body: new FormData(f) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { agToast((d && d.why) || 'That did not work.'); return; }
        if (kind === 'pin') { agToast('Pinned to the sidebar'); setTimeout(function () { location.reload(); }, 600); return; }
        var p = d.pin;
        agToast('Unpinned', function () {
          var fd = new FormData();
          fd.append('_token', csrf()); fd.append('href', p.href); fd.append('label', p.label);
          fetch('/admin/me/pins', { method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() }, body: fd })
            .then(function () { location.reload(); });
        });
        setTimeout(function () { location.reload(); }, toastMs);
      })
      .catch(function () { f.submit(); });
  });

  // ── CONFIRM WITH A REASON (§7, rule 5) ───────────────────────────────────────
  //
  // `agConfirm(message, onYes, opts)` is the one confirm. For a form that POSTS, a reason
  // is REQUIRED — the confirm stays disabled at 45% until it holds a non-space character
  // — and it travels as `_reason`, which AuditService::record() attaches to whatever the
  // action writes to the audit log. A link has nowhere to carry a reason, so asking for
  // one would be collecting words that go nowhere; it gets the same dialog without it.
  var dlg = document.getElementById('cnConfirm');
  function agConfirm(message, onYes, opts) {
    opts = opts || {};
    if (!dlg || typeof dlg.showModal !== 'function') { if (window.confirm(message)) onYes(''); return; }
    var title = dlg.querySelector('#cnConfirmT'), bodyEl = dlg.querySelector('#cnConfirmB');
    var wrap = dlg.querySelector('[data-cn-reason]'), input = wrap.querySelector('input');
    var ok = dlg.querySelector('[data-cn-ok]'), no = dlg.querySelector('[data-cn-cancel]');
    var needReason = !!opts.reason;
    title.textContent = opts.title || message;
    bodyEl.textContent = opts.title ? message : (opts.body || '');
    ok.textContent = opts.yesText || 'Confirm';
    ok.classList.toggle('cn-btn--danger-fill', opts.danger !== false);
    ok.classList.toggle('cn-btn--primary', opts.danger === false);
    wrap.hidden = !needReason;
    input.value = '';
    ok.disabled = needReason;
    input.oninput = function () { ok.disabled = needReason && input.value.trim() === ''; };
    no.onclick = function () { dlg.close('cancel'); };
    dlg.onclose = function () {
      if (dlg.returnValue === 'ok' && (!needReason || input.value.trim() !== '')) onYes(input.value.trim());
    };
    dlg.returnValue = '';
    dlg.showModal();
    (needReason ? input : ok).focus();
  }
  window.agConfirm = agConfirm;

  function carryReason(form, why) {
    if (!why) return;
    var h = form.querySelector('input[name="_reason"]');
    if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = '_reason'; form.appendChild(h); }
    h.value = why;
  }

  // Forms that opt into confirmation via data-confirm (e.g. delete forms).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm') || form.dataset.ok === '1') return;
    e.preventDefault();
    // Captured now: the confirm is answered later, when the event is long finished.
    var by = e.submitter || undefined;
    agConfirm(form.getAttribute('data-confirm') || 'Are you sure?', function (why) {
      carryReason(form, why);
      form.dataset.ok = '1';
      // requestSubmit(submitter), not submit(): submit() posts to the form's OWN action
      // and drops the pressed button's formaction — a confirmed Delete sharing a form
      // with Save would save (NestedFormTest).
      if (typeof form.requestSubmit === 'function') form.requestSubmit(by);
      else form.submit();
    }, { reason: isWriteForm(form), title: form.getAttribute('data-confirm-title') || null });
  }, true);

  // Standalone links / buttons with data-confirm (not inside a confirming form).
  document.addEventListener('click', function (e) {
    var el = e.target.closest('a[data-confirm], button[data-confirm]');
    if (!el || el.closest('form[data-confirm]')) return;
    if (readOnly && el.form && isWriteForm(el.form)) return;   // the read-only toast answers
    e.preventDefault();
    var post = !!(el.form && isWriteForm(el.form));
    agConfirm(el.getAttribute('data-confirm') || 'Are you sure?', function (why) {
      if (el.tagName === 'A' && el.href) { location.href = el.href; return; }
      if (el.form) {
        carryReason(el.form, why);
        el.form.dataset.ok = '1';
        // Same reason as above: this button may carry its own formaction.
        if (typeof el.form.requestSubmit === 'function') el.form.requestSubmit(el);
        else el.form.submit();
      }
    }, { reason: post });
  }, true);

  // ── THE ASSISTANT DRAWER (§7) ────────────────────────────────────────────────
  var drawer = document.getElementById('cnAssist');
  var dScrim = document.querySelector('.cn-drawer-scrim');
  var lastFocus = null;
  function chat() { return drawer && window.Alpine ? window.Alpine.$data(drawer) : null; }
  function openAssist(q) {
    if (!drawer) { location.href = '/admin/assistant'; return; }
    lastFocus = document.activeElement;
    drawer.hidden = false; if (dScrim) dScrim.hidden = false;
    var c = chat();
    if (q && c) { c.draft = q; c.send(); }
    var inp = document.getElementById('cnAssistIn'); if (inp) inp.focus();
  }
  function closeAssist() {
    if (!drawer || drawer.hidden) return;
    drawer.hidden = true; if (dScrim) dScrim.hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-ag-do="assist-open"]')) { e.preventDefault(); openAssist(); return; }
    if (e.target.closest('[data-ag-do="assist-close"]')) { e.preventDefault(); closeAssist(); return; }
    var ask = e.target.closest('[data-ag-do="assist-ask"]');
    if (ask) { e.preventDefault(); if (pal && pal.open) pal.close(); openAssist(ask.getAttribute('data-q')); }
  });
  // The hero's ask box on Home is a real form to the assistant's page; with the drawer
  // here it opens the drawer with the question instead.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement) || f.getAttribute('data-ag-do') !== 'hero-ask' || !drawer) return;
    e.preventDefault();
    var q = (f.querySelector('input[name="q"]') || {}).value || '';
    if (q.trim()) openAssist(q.trim()); else openAssist();
  });
  document.querySelectorAll('[data-ag-do="hero-ask"] input[name="q"]').forEach(function (inp) {
    var send = inp.form.querySelector('.cn-send');
    var sync = function () { if (send) send.disabled = inp.value.trim() === ''; };
    inp.addEventListener('input', sync); sync();
  });

  // ── THE COMMAND PALETTE (§7): ⌘K / Ctrl+K ────────────────────────────────────
  //
  // Plain JS, no framework: the control that has to work when something else on the page
  // has broken. Substring matching over a page's name and its group, so "money" finds
  // every payment screen. An empty query shows Recent first; two or more words add an
  // "Ask the assistant" row; a few phrases add a filter row.
  var pal = document.getElementById('cnPal');
  var palIn = document.getElementById('cnPalIn');
  var palNone = document.getElementById('cnPalNone');
  var shown = [], at = 0;
  if (!/Mac|iPhone|iPad/.test(navigator.platform || '')) {
    document.querySelectorAll('[data-cn-kbd]').forEach(function (k) { k.textContent = 'Ctrl K'; });
  }
  function rows() { return Array.prototype.slice.call(pal.querySelectorAll('.cn-pal__list > li')); }
  function mark() {
    shown.forEach(function (li, i) {
      li.setAttribute('aria-selected', i === at ? 'true' : 'false');
      if (i === at) li.scrollIntoView({ block: 'nearest' });
    });
  }
  function filter() {
    var q = palIn.value.trim().toLowerCase();
    var words = q.split(/\s+/).filter(Boolean);
    shown = [];
    rows().forEach(function (li) {
      var a = li.querySelector('a');
      var cmd = a.getAttribute('data-cmd') || '';
      var hit;
      if (li.hasAttribute('data-recent') || li.hasAttribute('data-first')) hit = q === '';
      else if (li.hasAttribute('data-filter')) hit = q !== '' && words.some(function (w) { return w.length > 2 && cmd.indexOf(w) !== -1; });
      else if (li.hasAttribute('data-ask')) {
        hit = words.length >= 2;
        if (hit) li.querySelector('[data-ask-label]').textContent = 'Ask the assistant: “' + palIn.value.trim() + '”';
      } else hit = q === '' || cmd.indexOf(q) !== -1;
      li.hidden = !hit;
      if (hit) shown.push(li);
    });
    // Filters and the question first, as the HTML orders them.
    shown.sort(function (x, y) { return rank(x) - rank(y); });
    shown = shown.slice(0, 10);
    rows().forEach(function (li) { if (shown.indexOf(li) === -1) li.hidden = true; });
    at = 0;
    if (palNone) palNone.hidden = shown.length > 0;
    mark();
  }
  function rank(li) {
    if (li.hasAttribute('data-filter')) return 0;
    if (li.hasAttribute('data-recent')) return 1;
    if (li.hasAttribute('data-ask')) return 3;
    return 2;
  }
  function openPal() {
    if (!pal || typeof pal.showModal !== 'function') return;
    closeAcct();
    palIn.value = '';
    filter();
    pal.showModal();
    palIn.focus();
  }
  if (pal) {
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-ag-do="palette-open"]')) { e.preventDefault(); openPal(); return; }
      var pa = e.target.closest('[data-ag-do="palette-ask"]');
      if (pa) { e.preventDefault(); var q = palIn.value.trim(); pal.close(); openAssist(q); }
    });
    pal.addEventListener('click', function (e) { if (e.target === pal) pal.close(); });
    palIn.addEventListener('input', filter);
  }

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      if (!pal) return;
      e.preventDefault();
      if (pal.open) pal.close(); else openPal();
      return;
    }
    if (e.key === 'Escape') {
      // A search input eats the first Escape to clear itself; the palette closes anyway.
      if (pal && pal.open) { e.preventDefault(); pal.close(); }
      closeAcct();
      closeAssist();
      if (body.hasAttribute('data-side-open')) setOverlay(false);
      return;
    }
    if (!pal || !pal.open) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!shown.length) return;
      at = (at + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length;
      mark();
    } else if (e.key === 'Enter') {
      // The form is method="dialog": without this, Enter would close the palette and go
      // nowhere, which reads as the palette ignoring you.
      var a = shown[at] && shown[at].querySelector('a');
      if (a) { e.preventDefault(); a.click(); }
    }
  });

  // ══ the page behaviours, carried over (inventory: docs/handoff/inventory/_admin.md) ══

  // File-zone previews
  document.querySelectorAll('[data-file-zone]').forEach(zone => {
    const input = zone.querySelector('input[type=file]');
    const preview = zone.parentElement.querySelector('[data-file-preview]');
    if (!input) return;
    zone.addEventListener('click', () => input.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor = 'var(--cn-ink)'; });
    zone.addEventListener('dragleave', () => { zone.style.borderColor = ''; });
    zone.addEventListener('drop', e => {
      e.preventDefault(); zone.style.borderColor = '';
      input.files = e.dataTransfer.files;
      renderPreviews();
    });
    input.addEventListener('change', renderPreviews);
    function renderPreviews() {
      if (!preview) return;
      preview.innerHTML = '';
      Array.from(input.files || []).forEach(f => {
        if (!f.type.startsWith('image/')) return;
        const img = document.createElement('img');
        img.src = URL.createObjectURL(f);
        img.alt = f.name;
        preview.appendChild(img);
      });
    }
  });

  // NProgress-style top loading bar on form submit
  if (window.NProgress) {
    document.addEventListener('submit', () => window.NProgress.start());
  }

  // Keyboard access to horizontally scrolling tables (WCAG 2.1.1)
  //
  // .ad-table-wrap is `overflow-x:auto`, and 26 admin screens use it. A scroll
  // container that cannot take focus cannot be scrolled from the keyboard: Firefox
  // makes them focusable itself, Chrome and Safari do not, so on the wider tables
  // — registrations, finance, the nominee list — the right-hand columns were simply
  // unreachable without a mouse.
  //
  // Conditional rather than a `tabindex="0"` typed into 26 templates, because whether
  // a table overflows is a function of viewport width, not of the table: a static
  // attribute is wrong in one direction on a phone and the other on a wide monitor.
  // A region that does not scroll gets no tab stop; one that starts scrolling on
  // resize gains one. The name comes from the table's own caption or the card title
  // above it, so the announcement is "Judges, region" rather than "region".
  const scrollers = document.querySelectorAll('.ad-table-wrap');
  if (scrollers.length) {
    const sync = (el) => {
      const scrolls = el.scrollWidth > el.clientWidth + 1;
      if (scrolls === el.hasAttribute('tabindex')) return;
      if (scrolls) {
        el.setAttribute('tabindex', '0');
        el.setAttribute('role', 'region');
        if (!el.hasAttribute('aria-label')) {
          const cap = el.querySelector('caption');
          const title = el.closest('.ad-card')?.querySelector('.ad-card__title');
          const name = (cap?.textContent || title?.textContent || '').trim().split('\n')[0];
          if (name) el.setAttribute('aria-label', name);
        }
      } else {
        el.removeAttribute('tabindex');
        el.removeAttribute('role');
      }
    };
    scrollers.forEach(sync);
    if (window.ResizeObserver) {
      const ro = new ResizeObserver(entries => entries.forEach(e => sync(e.target)));
      scrollers.forEach(el => ro.observe(el));
    } else {
      window.addEventListener('resize', () => scrollers.forEach(sync));
    }
  }

  // ── THE TIER COLOUR DOT ─────────────────────────────────────────────────
  //
  // The swatch beside the tier-colour select on the event editor, kept in step with it.
  // Delegated and keyed on `data-ag-do` for the same reason as everything else on this
  // screen: the admin CSP has no 'unsafe-inline', so an inline `onchange` would silently
  // never run and CspTest would fail the build over it.
  //
  // The hex comes off the chosen <option>, never from a table duplicated here. The options
  // are rendered from EventTierPalette resolved against this event's own accent, so a copy
  // in JS would be a second palette that drifts the first time somebody changes the accent
  // — which is precisely the failure the slot column exists to prevent.
  function agTierDot(sel) {
    const wrap = sel.closest('label');
    const dot = wrap && wrap.querySelector('[data-tier-dot]');
    if (!dot) return;
    const opt = sel.options[sel.selectedIndex];
    const fill = opt && opt.getAttribute('data-fill');
    const edge = opt && opt.getAttribute('data-edge');
    dot.style.background = fill || 'transparent';
    dot.style.borderColor = edge || 'rgba(16,41,44,.25)';
  }

  document.addEventListener('change', function (e) {
    const sel = e.target.closest('[data-ag-do="tier-colour"]');
    if (sel) agTierDot(sel);
  });

  // Alpine renders the tier rows from an x-for template, so these selects do not exist at
  // DOMContentLoaded and are replaced whenever a row is added or removed. Bound once at
  // load, every row added afterwards would have a dead swatch — so the list is repainted
  // when the DOM changes, coalesced to one frame because Alpine rewrites the whole
  // repeater on a single keystroke.
  (function () {
    const paint = function () {
      document.querySelectorAll('[data-ag-do="tier-colour"]').forEach(agTierDot);
    };
    paint();
    if (!window.MutationObserver) return;
    let pending = 0;
    new MutationObserver(function () {
      if (pending) return;
      pending = requestAnimationFrame(function () { pending = 0; paint(); });
    }).observe(document.body, { childList: true, subtree: true });
  })();

  // A select that submits its own form on choice — the cycle switcher on the shortlist
  // screen, and anything after it that wants the same.
  //
  // Delegated on `data-ag-do`, NOT an inline `onchange`: the admin CSP has no
  // 'unsafe-inline' in script-src, so an inline handler is not merely discouraged here —
  // it silently never runs, and CspTest fails the build over it. Every such form also
  // keeps a visible submit button, so choosing a cycle works with this file absent.
  document.addEventListener('change', function (e) {
    const el = e.target.closest('[data-ag-do="submit-form"]');
    if (el && el.form) el.form.submit();
  });

  // ── THE CUSTOM SIZE BOXES FOLLOW THE SIZE SELECT ──────────────────────────
  //
  // On the vendor-stands screen a stand type's size comes from a select of stock sizes
  // OR from a pair of metre boxes, and the server honours the select whenever it names a
  // stock size. The boxes used to sit live and pre-filled beside a select reading
  // "Standard gazebo", with small print saying they were ignored — so somebody typing
  // 6 × 6 into them got a 3 × 3 pitch, silently, and a stand's size is a published term.
  //
  // Disabling them makes the form behave the way it reads: a disabled input is not
  // submitted, which is precisely "ignored". With this file absent the boxes stay live
  // and the server still prefers the select, so the outcome is unchanged — only the
  // screen is less honest, which is the right way round for a progressive enhancement.
  const agSizeSync = function (sel) {
    if (!sel || !sel.form) return;
    const custom = sel.value === 'custom';
    sel.form.querySelectorAll('[data-ag-size-custom]').forEach(function (box) {
      box.disabled = !custom;
    });
  };
  document.addEventListener('change', function (e) {
    const sel = e.target.closest('[data-ag-do="stand-size"]');
    if (sel) agSizeSync(sel);
  });
  document.querySelectorAll('[data-ag-do="stand-size"]').forEach(agSizeSync);

  // ── HEAR ONE NAME IN THE DOOR'S VOICE ─────────────────────────────────────
  //
  // The pronunciation list on the settings screen is the only fix for a name Azure says
  // wrongly, and without this it is written blind: the operator finds out whether their
  // correction worked when a guest hears their own name mangled at their own event.
  //
  // Three things here are not decoration:
  //
  //   THE AUDIO COMES FROM A URL, NEVER A DATA URI. `media-src` in Csp.php is 'self'
  //   plus two video hosts — no data:, no blob:. An inline data URI would be blocked by
  //   the browser with nothing an operator would ever see: a button that does nothing,
  //   permanently. The server answers with a same-origin path for that reason.
  //
  //   ENTER IN THE BOX PREVIEWS, IT DOES NOT SAVE. A lone text input inside a <form>
  //   submits it on Enter, and this box sits inside the settings form — so typing a name
  //   and pressing Enter would have saved the whole configuration screen instead of
  //   speaking. It is the obvious thing to press.
  //
  //   THE BUTTON SAYS WHAT IT IS DOING. This is the one request in the console that waits
  //   on a third party, and a couple of seconds of nothing reads as broken.
  //
  // With this file absent, the button is inert and the pronunciation list still saves and
  // still works — the preview is the enhancement, never the feature.
  (function () {
    var busy = false;

    /**
     * Which of the three lines the door can say, and at whose door.
     *
     * The kind and the event are read from their own controls rather than assumed,
     * because the line genuinely differs: a guest of honour hears a sentence naming why
     * they are there, a name the voice cannot manage falls to the generic line, and the
     * greeting prefix comes from the EVENT's start in the event's own timezone. A preview
     * that assumed "ticket holder, no event" — which is what this used to send — showed a
     * sentence the door never says.
     */
    function agVoiceTry(btn) {
      if (busy || !btn) return;
      var pick = function (attr) { return document.getElementById(btn.getAttribute(attr) || ''); };
      var box  = pick('data-target');
      var out  = document.getElementById(btn.getAttribute('data-out') || '');
      var lineEl = document.getElementById('voice-try-line');
      var form = btn.closest('form');
      var kindEl = pick('data-kind'), evEl = pick('data-event'), roleEl = pick('data-role');
      var kind = kindEl ? kindEl.value : 'guest';
      var name = box ? box.value.trim() : '';
      var say  = function (t) { if (out) out.textContent = t; };
      var line = function (t) { if (lineEl) lineEl.textContent = t; };

      // The generic line has no name in it, so asking for one would be asking for
      // something the answer does not use.
      if (kind !== 'generic' && name === '') {
        say('Type a first name first.'); if (box) box.focus(); return;
      }

      busy = true;
      btn.disabled = true;
      line('');
      say('Asking for it\u2026');

      var tok = form && form.querySelector('input[name="_token"]');

      fetch('/admin/settings/voice-preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': tok ? tok.value : '',
        },
        body: 'name=' + encodeURIComponent(name)
            + '&kind=' + encodeURIComponent(kind)
            + '&event=' + encodeURIComponent(evEl ? evEl.value : '0')
            + '&role=' + encodeURIComponent(roleEl ? roleEl.value.trim() : ''),
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (d) {
          if (!d || !d.ok) {
            say(d && d.why ? d.why : 'That could not be spoken. Check the key and the region.');
            return;
          }
          // THE WORDING ALWAYS. "What will the door say" and "can this deployment say it"
          // are two questions, and only the second needs a key — so a failure to speak
          // now reports itself beside the line rather than instead of it.
          line('\u201C' + d.line + '\u201D');
          if (!d.url) { say(d.why || 'The wording only \u2014 no voice is configured.'); return; }

          say('');
          var a = new Audio(d.url);
          // A play() rejection is the ordinary case on a browser that wants a gesture it
          // did not see, not a fault in the voice — so it says so rather than blaming the
          // configuration the operator just set.
          var pl = a.play();
          if (pl && pl.catch) pl.catch(function () { say('Ready, but this browser would not play it.'); });
        })
        .catch(function () { say('The request did not get through.'); })
        .then(function () { busy = false; btn.disabled = false; });
    }

    /**
     * Put what is IN THE ROWS into the pronunciation box.
     *
     * Read live from the inputs rather than from a copy the server rendered, because the
     * rows are editable and the whole point of the list is that somebody presses Hear,
     * decides the guess is wrong and fixes it. A button that pasted the original
     * suggestions would quietly discard exactly the work this screen exists to collect.
     *
     * INTO THE BOX, never into the database. Every suggestion is a rule's guess over bare
     * letters, and most Nigerian names carry no tone marks to tell the rule which name it
     * is looking at — so a person reads them, fixes what is wrong and presses save. A
     * confident wrong pronunciation said out loud at somebody's own door is worse than an
     * English one.
     *
     * Names already in the box are left exactly as they are: an operator who has corrected
     * Ngozi by hand must not have it replaced by the machine's opinion of Ngozi.
     */
    /**
     * Show only the boxes the chosen line actually uses.
     *
     * `hidden` and not a class: the admin stylesheet sets `display` on `.row > div` in
     * places, and any author display beats the UA stylesheet's `[hidden]` — so the
     * elements are toggled directly rather than through a class that might lose.
     */
    function agVoiceKind(sel) {
      var kind = sel.value;
      var name = document.getElementById('f-voice_try');
      var role = document.getElementById('f-voice_role');
      if (name) name.hidden = (kind === 'generic');
      if (role) role.hidden = (kind !== 'honour');
      var lineEl = document.getElementById('voice-try-line');
      if (lineEl) lineEl.textContent = '';
    }

    function agVoiceFill(btn) {
      var box = document.getElementById(btn.getAttribute('data-target') || '');
      if (!box) return;

      var rows = document.querySelectorAll('.' + (btn.getAttribute('data-rows') || 'vp-say'));
      if (!rows.length) return;

      var have = {};
      box.value.split(/\r?\n/).forEach(function (l) {
        var at = l.indexOf('=');
        if (at > 0) have[l.slice(0, at).trim().toLowerCase()] = true;
      });

      var add = [];
      Array.prototype.forEach.call(rows, function (el) {
        var name = (el.getAttribute('data-name') || '').trim();
        var say  = (el.value || '').trim();
        // A row nobody filled in is a row nobody has an answer for yet — skipping it is
        // the honest outcome, and it stays in the list to come back to.
        if (!name || !say || have[name.toLowerCase()]) return;
        add.push(name + ' = ' + say);
      });

      if (!add.length) { btn.textContent = 'Nothing new to add'; return; }

      var cur = box.value.replace(/\s+$/, '');
      box.value = (cur ? cur + '\n' : '') + add.join('\n') + '\n';

      // So the unsaved-changes dot appears and the operator knows there is something to save.
      box.dispatchEvent(new Event('input', { bubbles: true }));
      box.focus();
      box.setSelectionRange(box.value.length, box.value.length);
      btn.textContent = 'Added ' + add.length + ' \u2014 read them before saving';
    }

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ag-do="voice-try"]');
      if (btn) { e.preventDefault(); agVoiceTry(btn); return; }

      var fill = e.target.closest('[data-ag-do="voice-fill"]');
      if (fill) { e.preventDefault(); agVoiceFill(fill); }
    });

    // A `change` on a <select> does not reach the click listener above — it is a
    // different event, and a handler registered on the wrong one is a control that
    // renders, looks right and silently does nothing.
    document.addEventListener('change', function (e) {
      var kindSel = e.target.closest && e.target.closest('[data-ag-do="voice-kind"]');
      if (kindSel) agVoiceKind(kindSel);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var box = e.target.closest('input[type="text"]');
      if (!box || !box.id) return;
      var btn = document.querySelector('[data-ag-do="voice-try"][data-target="' + box.id + '"]');
      if (!btn) return;
      e.preventDefault();
      agVoiceTry(btn);
    });
  })();

  // Tippy.js tooltips
  if (window.tippy) {
    window.tippy('[data-tip]', {
      content: el => el.getAttribute('data-tip'),
      delay: [400, 80],
      animation: 'shift-away-subtle',
      theme: 'gates',
    });
  }
})();
