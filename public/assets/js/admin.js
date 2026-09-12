// ═══ Admin shell behaviours ═══════════════════════════════════════
// Light helpers — relies on Alpine.js for rich interactivity.

(function () {
  'use strict';

  // Mobile sidebar toggle
  const toggle = document.getElementById('adMobileToggle');
  const side   = document.getElementById('adSide');
  if (toggle && side) {
    const syncExpanded = () => toggle.setAttribute('aria-expanded', side.classList.contains('is-open') ? 'true' : 'false');
    toggle.addEventListener('click', () => { side.classList.toggle('is-open'); syncExpanded(); });
    document.addEventListener('click', (e) => {
      if (window.innerWidth > 880) return;
      if (side.classList.contains('is-open') && !side.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
        side.classList.remove('is-open');
        syncExpanded();
      }
    });
  }

  // File-zone previews
  document.querySelectorAll('[data-file-zone]').forEach(zone => {
    const input = zone.querySelector('input[type=file]');
    const preview = zone.parentElement.querySelector('[data-file-preview]');
    if (!input) return;
    zone.addEventListener('click', () => input.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor = 'var(--ad-primary)'; });
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

  // ── Non-blocking confirm dialog (replaces native confirm()) ──
  function agConfirm(message, onYes, opts) {
    opts = opts || {};
    let ov = document.getElementById('agConfirm');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'agConfirm';
      ov.style.cssText = 'position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(8,18,20,.55);padding:20px';
      ov.innerHTML = '<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;max-width:400px;width:100%;padding:24px;box-shadow:0 24px 60px -20px rgba(0,0,0,.5)">'
        + '<p data-msg style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#10292c"></p>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end">'
        + '<button type="button" data-no style="border:1px solid rgba(16,41,44,.16);background:#fff;color:#5a6d6f;font:600 14px/1 inherit;padding:10px 18px;border-radius:999px;cursor:pointer">Cancel</button>'
        + '<button type="button" data-yes style="border:none;color:#fff;font:600 14px/1 inherit;padding:10px 18px;border-radius:999px;cursor:pointer">Confirm</button>'
        + '</div></div>';
      document.body.appendChild(ov);
    }
    const msg = ov.querySelector('[data-msg]'), yes = ov.querySelector('[data-yes]'), no = ov.querySelector('[data-no]');
    msg.textContent = message;
    yes.textContent = opts.yesText || 'Confirm';
    yes.style.background = opts.danger === false ? '#237b22' : '#b42318';
    const close = () => { ov.style.display = 'none'; document.removeEventListener('keydown', onKey); };
    const onKey = e => { if (e.key === 'Escape') close(); };
    yes.onclick = () => { close(); onYes(); };
    no.onclick = close;
    ov.onclick = e => { if (e.target === ov) close(); };
    document.addEventListener('keydown', onKey);
    ov.style.display = 'flex';
    yes.focus();
  }
  window.agConfirm = agConfirm;

  // Forms that opt into confirmation via data-confirm (e.g. delete forms).
  document.addEventListener('submit', e => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm') || form.dataset.ok === '1') return;
    e.preventDefault();
    // Captured now rather than read inside the callback: the confirm is answered a second
    // or a minute later, and the event is long finished by then.
    const by = e.submitter || undefined;
    // requestSubmit(submitter), not submit(): form.submit() posts to the form's OWN
    // action and drops the pressed button's formaction and its name/value. A confirmed
    // "Delete" that shares a form with "Save" would then save. Falls back where
    // requestSubmit is missing, which is the pre-2021 browser this admin does not target.
    agConfirm(form.getAttribute('data-confirm') || 'Are you sure?', () => {
      form.dataset.ok = '1';
      if (typeof form.requestSubmit === 'function') form.requestSubmit(by);
      else form.submit();
    });
  }, true);

  // Standalone links / buttons with data-confirm (not inside a confirming form).
  document.addEventListener('click', e => {
    const el = e.target.closest('a[data-confirm], button[data-confirm]');
    if (!el || el.closest('form[data-confirm]')) return;
    e.preventDefault();
    agConfirm(el.getAttribute('data-confirm') || 'Are you sure?', () => {
      if (el.tagName === 'A' && el.href) location.href = el.href;
      else if (el.form) {
        el.form.dataset.ok = '1';
        // Same reason as above: this button may carry its own formaction, and it is
        // exactly the destructive buttons that do — a delete sharing a form with a save.
        if (typeof el.form.requestSubmit === 'function') el.form.requestSubmit(el);
        else el.form.submit();
      }
    });
  }, true);

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
