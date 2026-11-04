/* /status (Phase 9, StatusPageV2). Two things, both progressive:

   · A DAY SQUARE says what it was. Hover or tap a square and the row's info line reads
     "21 Sep · Not working"; leaving the strip puts the date range back. The strip itself is
     one role="img" with the whole summary in its label, so a screen reader is told the
     outcome once rather than walked through fourteen squares; this is the sighted half.
   · GET UPDATES opens in place through AGChrome.openSheet (focus in, Tab trapped, Esc, the
     scrim, Back closes it, focus returns to the pill). With no script it is an anchor to
     the sheet's own id, which CSS opens with :target — and the subscribe form's redirect
     lands on that id, so the answer is on screen either way. */
(function () {
  'use strict';
  var root = document.querySelector('.sx');
  if (!root) return;

  /* ── Day squares ─────────────────────────────────────────────────────── */
  root.querySelectorAll('[data-st-row]').forEach(function (row) {
    var info = row.querySelector('[data-st-info]');
    var cells = row.querySelectorAll('[data-st-cell]');
    if (!info || !cells.length) return;
    var dflt = info.getAttribute('data-default') || '';
    function pick(c) {
      cells.forEach(function (x) { x.removeAttribute('data-on'); });
      if (c) { c.setAttribute('data-on', ''); info.textContent = c.getAttribute('data-st-cell'); info.setAttribute('data-on', ''); }
      else { info.textContent = dflt; info.removeAttribute('data-on'); }
    }
    cells.forEach(function (c) {
      c.addEventListener('mouseenter', function () { pick(c); });
      c.addEventListener('click', function () { pick(c); });
    });
    var strip = cells[0].parentNode;
    strip.addEventListener('mouseleave', function () { pick(null); });
  });

  /* ── Get updates ─────────────────────────────────────────────────────── */
  var sheet = document.querySelector('[data-st-sub]');
  var scrim = document.querySelector('[data-st-sub-scrim]');
  var opener = root.querySelector('[data-st-sub-open]');
  if (!sheet || !scrim) return;
  var close = null;
  var arrived = location.hash === '#st-sub';
  // With the script the sheet opens in place; an #st-sub left in the address would
  // otherwise hold it open by :target behind the script's own state.
  if (arrived) { try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {} }
  sheet.setAttribute('inert', '');

  function open(e) {
    if (e) e.preventDefault();
    var o = (window.AGChrome && window.AGChrome.openSheet) || (window.AGShell && window.AGShell.openSheet);
    if (!o) { location.hash = 'st-sub'; return; }
    sheet.removeAttribute('inert');
    if (opener) opener.setAttribute('aria-expanded', 'true');
    close = o(sheet, scrim, opener || undefined);
    var watch = new MutationObserver(function () {
      if (!sheet.hasAttribute('data-open')) {
        sheet.setAttribute('inert', '');
        if (opener) opener.setAttribute('aria-expanded', 'false');
        close = null; watch.disconnect();
      }
    });
    watch.observe(sheet, { attributes: true, attributeFilter: ['data-open'] });
  }
  if (opener) opener.addEventListener('click', open);
  var x = sheet.querySelector('[data-st-sub-close]');
  if (x) x.addEventListener('click', function (e) {
    e.preventDefault();
    if (close) { var c = close; close = null; c(); } else scrim.click();
  });
  if (arrived) open();
})();
