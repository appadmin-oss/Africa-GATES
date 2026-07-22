/**
 * Africa GATES — Enterprise Feature Layer
 * Device fingerprinting · Funnel tracking · Draft auto-save · Live stats
 */
(function (AFG) {
  'use strict';

  /* ── Session ID (persists for browser session) ──────────────── */
  AFG.sessionId = (() => {
    const k = 'afg_sid';
    let id = sessionStorage.getItem(k);
    if (!id) {
      // Crypto-random (128-bit) so derived keys (e.g. the nomination draft key) are
      // unguessable; fall back to Math.random only on very old browsers.
      id = (window.crypto && crypto.getRandomValues)
        ? Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('')
        : (Math.random().toString(36).slice(2) + Date.now().toString(36));
      sessionStorage.setItem(k, id);
    }
    return id;
  })();

  /* ── Device fingerprint (lightweight, no external lib) ───────── */
  AFG.deviceHash = (() => {
    try {
      const parts = [
        navigator.userAgent,
        navigator.language,
        screen.colorDepth,
        screen.width + 'x' + screen.height,
        new Date().getTimezoneOffset(),
        navigator.hardwareConcurrency || 0,
        navigator.deviceMemory || 0,
        !!window.chrome,
        !!window.safari,
      ].join('|');

      // Canvas fingerprint
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      if (ctx) {
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillStyle = '#338632';
        ctx.fillText('AfgFp\u00ae', 2, 2);
        parts += '|' + canvas.toDataURL().slice(-80);
      }

      // FNV-1a 32-bit hash → base36 string
      let h1 = 2166136261, h2 = (2166136261 ^ 0x5bd1e995) >>> 0;
      for (let i = 0; i < parts.length; i++) {
        const c = parts.charCodeAt(i);
        h1 = Math.imul(h1 ^ c, 16777619) >>> 0;
        h2 = Math.imul(h2 ^ c, 16777643) >>> 0;
      }
      return h1.toString(36).padStart(7, '0') + h2.toString(36).padStart(7, '0');
    } catch { return 'unknown'; }
  })();

  window.AFG_DEVICE = AFG.deviceHash;
  window.AFG_SESSION = AFG.sessionId;

  /* ── Funnel event tracker ────────────────────────────────────── */
  AFG.trackFunnel = function (step, meta) {
    if (!step) return;
    fetch('/api/funnel', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        step,
        session_id: AFG.sessionId,
        device_hash: AFG.deviceHash,
        nominee_id: meta?.nominee_id || 0,
        award_id:   meta?.award_id   || 0,
        meta,
      }),
      keepalive: true,
    }).catch(() => {});
  };

  /* ── Auto-track nominee page views ──────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    const nomineeId = document.body.dataset.nomineeId;
    const awardId   = document.body.dataset.awardId;
    if (nomineeId) {
      AFG.trackFunnel('nominee_view', { nominee_id: +nomineeId, award_id: +awardId || 0 });
    }

    // Track vote button clicks
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('[data-vote-btn]');
      if (btn) {
        AFG.trackFunnel('vote_button_click', {
          nominee_id: +btn.dataset.nomineeId || 0,
          award_id:   +btn.dataset.awardId   || 0,
        });
      }
    });

    // Track share clicks
    document.addEventListener('click', function (e) {
      const shareLink = e.target.closest('[data-share-platform]');
      if (shareLink) {
        AFG.trackFunnel('vote_shared', { platform: shareLink.dataset.sharePlatform });
        fetch('/api/events/share', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ platform: shareLink.dataset.sharePlatform, device_hash: AFG.deviceHash }),
          keepalive: true,
        }).catch(() => {});
      }
    });
  });

  /* ── Nomination draft auto-save ──────────────────────────────── */
  AFG.initDraftSave = function (formEl, sessionKey) {
    if (!formEl) return;

    const DRAFT_KEY = 'afg_draft_' + sessionKey;

    function collectPayload() {
      const data = {};
      formEl.querySelectorAll('input,select,textarea').forEach(el => {
        if (el.name && el.type !== 'hidden' && el.type !== 'submit') {
          data[el.name] = el.value;
        }
      });
      return data;
    }

    // Restore from localStorage immediately
    try {
      const saved = localStorage.getItem(DRAFT_KEY);
      if (saved) {
        const payload = JSON.parse(saved);
        Object.entries(payload).forEach(([name, val]) => {
          const el = formEl.querySelector(`[name="${name}"]`);
          if (el && !el.value) el.value = val;
        });
        const banner = document.getElementById('draftBanner');
        if (banner) banner.hidden = false;
      }
    } catch {}

    // Save to localStorage on change (instant, always works)
    function save() {
      try { localStorage.setItem(DRAFT_KEY, JSON.stringify(collectPayload())); } catch {}
    }

    // Debounced server sync
    let syncTimer;
    function syncToServer() {
      clearTimeout(syncTimer);
      syncTimer = setTimeout(() => {
        fetch('/api/nominations/draft', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ session_key: sessionKey, payload: collectPayload() }),
        }).catch(() => {});
      }, 2000);
    }

    formEl.addEventListener('input',  () => { save(); syncToServer(); });
    formEl.addEventListener('change', () => { save(); syncToServer(); });

    // Clear draft on successful submit
    formEl.addEventListener('submit', () => {
      try { localStorage.removeItem(DRAFT_KEY); } catch {}
    });

    // Clear button
    const clearBtn = document.getElementById('draftClear');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        try { localStorage.removeItem(DRAFT_KEY); } catch {}
        formEl.reset();
        const banner = document.getElementById('draftBanner');
        if (banner) banner.hidden = true;
      });
    }
  };

  /* ── Completion percentage indicator ────────────────────────── */
  AFG.initCompletionRing = function (formEl, ringEl) {
    if (!formEl || !ringEl) return;

    const requiredFields = Array.from(formEl.querySelectorAll('[required]'));
    const circle = ringEl.querySelector('.completion-circle');
    const pctEl  = ringEl.querySelector('.completion-ring__pct');
    const hintEl = ringEl.querySelector('.completion-ring__hint');

    const C  = 2 * Math.PI * 26; // circumference for r=26

    function update() {
      const filled = requiredFields.filter(el => el.value.trim() !== '').length;
      const pct    = Math.round((filled / requiredFields.length) * 100);

      if (circle) {
        circle.style.strokeDashoffset = C - (pct / 100) * C;
        circle.style.stroke = pct === 100 ? '#22c55e' : (pct >= 50 ? '#f59e0b' : '#e5e7eb');
      }
      if (pctEl)  pctEl.textContent  = pct + '%';
      if (hintEl) {
        hintEl.textContent = pct === 100 ? 'All required fields complete ✓'
          : `${requiredFields.length - filled} required field${requiredFields.length - filled !== 1 ? 's' : ''} left`;
      }
    }

    formEl.addEventListener('input',  update);
    formEl.addEventListener('change', update);
    update();
  };

  /* ── Live vote count update (polls every 30s on vote page) ───── */
  AFG.startVotePoller = function (nomineeId, countEl) {
    if (!nomineeId || !countEl) return;
    setInterval(async () => {
      try {
        const r = await fetch(`/api/nominees?id=${nomineeId}`);
        const d = await r.json();
        const count = d.nominees?.[0]?.vote_count;
        if (count !== undefined) {
          countEl.textContent = (+count).toLocaleString();
        }
      } catch {}
    }, 30000);
  };

})(window.AFG = window.AFG || {});
