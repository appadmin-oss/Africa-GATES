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
    agConfirm(form.getAttribute('data-confirm') || 'Are you sure?', () => { form.dataset.ok = '1'; form.submit(); });
  }, true);

  // Standalone links / buttons with data-confirm (not inside a confirming form).
  document.addEventListener('click', e => {
    const el = e.target.closest('a[data-confirm], button[data-confirm]');
    if (!el || el.closest('form[data-confirm]')) return;
    e.preventDefault();
    agConfirm(el.getAttribute('data-confirm') || 'Are you sure?', () => {
      if (el.tagName === 'A' && el.href) location.href = el.href;
      else if (el.form) { el.form.dataset.ok = '1'; el.form.submit(); }
    });
  }, true);

  // NProgress-style top loading bar on form submit
  if (window.NProgress) {
    document.addEventListener('submit', () => window.NProgress.start());
  }

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
