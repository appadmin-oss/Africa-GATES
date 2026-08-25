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
