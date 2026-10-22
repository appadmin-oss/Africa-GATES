/* ══════════════════════════════════════════════════════════════════════════════
   THE TICKET — "Manage this ticket" only (§8.11). The ticket itself needs no script.
   ══════════════════════════════════════════════════════════════════════════════

   One handler for the self-service endpoints, because the reply shape is identical; every
   outcome lands in ONE live region. The change fields appear only once a code is on its
   way, and the refund figure is fetched when the panel OPENS — so it is on screen before
   anybody reaches the button, never after the irreversible press — and repeated in the
   confirm, the moment somebody actually reads. CSRF on every write: these change a ticket. */
(function () {
  'use strict';
  var box = document.querySelector('[data-tk-manage]');
  if (!box) return;
  var base = box.getAttribute('data-base'), csrf = box.getAttribute('data-csrf');
  var said = document.getElementById('tkSaid'), change = document.getElementById('tkChange');
  var T = function (k) { return box.getAttribute('data-t-' + k) || ''; };
  var val = function (id) { return (document.getElementById(id) || {}).value || ''; };

  function say(ok, msg) { said.className = 'tk-said ' + (ok ? 'is-ok' : 'is-no'); said.textContent = msg || ''; }

  async function post(path, payload, btn) {
    var label = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; }
    say(true, T('working'));
    try {
      var r = await fetch(base + path, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': box.getAttribute('data-csrf') || csrf },
        body: JSON.stringify(payload || {})
      });
      var j = await r.json();
      say(!!j.success, j.message || '');
      return j;
    } catch (e) { say(false, T('net')); return null; }
    finally { if (btn) { btn.disabled = false; btn.innerHTML = label; } }
  }

  var quoted = false;
  box.addEventListener('toggle', async function () {
    if (!box.open || quoted) return;
    quoted = true;
    var why = document.getElementById('tkCancelWhy'), go = document.getElementById('tkCancelGo');
    try {
      var r = await fetch(base + 'cancel-quote', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': box.getAttribute('data-csrf') || csrf }, body: '{}' });
      var q = await r.json();
      why.textContent = (q.message || '') + (q.policy_text ? ' ' + q.policy_text : '')
        + (!q.success && q.contact ? ' ' + T('contact').replace('%c%', q.contact) : '');
      if (q.success) { go.hidden = false; go.setAttribute('data-owed', String(q.naira || 0)); }
    } catch (e) { why.textContent = T('checking'); }
  });

  box.addEventListener('click', async function (e) {
    var b = e.target.closest('[data-tk]');
    if (!b) return;
    e.preventDefault();
    var what = b.getAttribute('data-tk'), code = val('tkCode');
    if (what === 'resend') { await post('resend', {}, b); return; }
    if (what === 'code') {
      var j = await post('code', {}, b);
      if (j && j.success) { change.hidden = false; document.getElementById('tkCode').focus(); }
      return;
    }
    if (what === 'rename') { await post('rename', { code: code, name: val('tkName') }, b); return; }
    if (what === 'cancel') {
      var owed = b.getAttribute('data-owed') || '0';
      var line = owed === '0' ? T('cancel0') : T('cancel').replace('%n%', Number(owed).toLocaleString('en'));
      if (!window.confirm(line)) return;
      var c = await post('cancel', { code: code }, b);
      if (c && c.success) { b.hidden = true; setTimeout(function () { location.reload(); }, 3000); }
      return;
    }
    if (what === 'transfer') {
      var nm = val('tkTName');
      if (!window.confirm(T('transfer').replace('%name%', nm || '…'))) return;
      var t = await post('transfer', { code: code, name: nm, email: val('tkTMail') }, b);
      if (t && t.success) setTimeout(function () { location.reload(); }, 2500);
    }
  });
})();
