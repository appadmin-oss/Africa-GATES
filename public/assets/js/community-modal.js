/* ════════════════════════════════════════════════════════════════
 * Africa GATES — "Join our community" modal controller.
 *
 * Shown ONCE PER BROWSER SESSION after a successful vote or nomination.
 * Public API:  window.AGCommunity.open(context) / .close()
 *
 * Accessibility: moves focus into the dialog, traps Tab within it,
 * restores focus on close, closes on ESC / backdrop / "Maybe later",
 * and locks body scroll while open.
 * ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // Flip to a per-page/per-action key (or remove the guard) to prompt every time.
  var SESSION_KEY = 'ag_community_prompted';

  var modal = null, dialog = null, lastFocused = null;

  function focusables() {
    return Array.prototype.slice.call(
      dialog.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')
    );
  }

  function onKeydown(e) {
    if (e.key === 'Escape') { close(); return; }
    if (e.key !== 'Tab') return;
    var f = focusables();
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function open(context) {
    modal = modal || document.getElementById('agCommunityModal');
    if (!modal) return;
    if (modal.classList.contains('is-open')) return;
    try { if (sessionStorage.getItem(SESSION_KEY)) return; } catch (e) { /* private mode — show anyway */ }

    dialog = modal.querySelector('.agcm__dialog');
    lastFocused = document.activeElement;

    modal.hidden = false;
    void modal.offsetWidth;            // force reflow so the open transition runs
    modal.classList.add('is-open');
    document.documentElement.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeydown);

    var cta = dialog.querySelector('[data-agcm-join]') || dialog.querySelector('button');
    if (cta) cta.focus();

    try { sessionStorage.setItem(SESSION_KEY, '1'); } catch (e) {}
    if (window.AFG && AFG.trackFunnel) {
      try { AFG.trackFunnel('community_prompt_shown', { context: context || '' }); } catch (e) {}
    }
  }

  function close() {
    if (!modal || !modal.classList.contains('is-open')) return;
    modal.classList.remove('is-open');
    document.documentElement.style.overflow = '';
    document.removeEventListener('keydown', onKeydown);

    var el = modal;
    var hide = function () { el.hidden = true; el.removeEventListener('transitionend', hide); };
    el.addEventListener('transitionend', hide);
    setTimeout(hide, 400);             // failsafe if transitionend never fires

    if (lastFocused && lastFocused.focus) { try { lastFocused.focus(); } catch (e) {} }
  }

  // Backdrop / close buttons, and a courteous auto-close after the user taps "Join".
  document.addEventListener('click', function (e) {
    if (!e.target || !e.target.closest) return;
    if (e.target.closest('[data-agcm-close]')) { e.preventDefault(); close(); return; }
    if (e.target.closest('[data-agcm-join]')) { setTimeout(close, 150); }
  });

  window.AGCommunity = { open: open, close: close };
})();
