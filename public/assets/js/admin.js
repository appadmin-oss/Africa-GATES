// ═══ Admin shell behaviours ═══════════════════════════════════════
// Light helpers — relies on Alpine.js for rich interactivity.

(function () {
  'use strict';

  // Mobile sidebar toggle
  const toggle = document.getElementById('adMobileToggle');
  const side   = document.getElementById('adSide');
  if (toggle && side) {
    toggle.addEventListener('click', () => side.classList.toggle('is-open'));
    document.addEventListener('click', (e) => {
      if (window.innerWidth > 880) return;
      if (side.classList.contains('is-open') && !side.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
        side.classList.remove('is-open');
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

  // Auto-confirm delete buttons
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
      const msg = btn.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

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
