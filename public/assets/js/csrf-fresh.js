/* ══════════════════════════════════════════════════════════════════════════════
   AN OPEN PAGE'S TOKEN, KEPT CURRENT · partials/csrf-fresh.twig
   ══════════════════════════════════════════════════════════════════════════════

   A page carries the CSRF token it was drawn with, and a page can outlive it: a tab left
   open over lunch, a page a phone restored from memory, a tab opened before the person
   signed in somewhere else (sign-in issues a new token). Each of those used to post a dead
   token and get "CSRF validation failed" back as a line of JSON, with nothing to press.

   So the page asks /session/token.json for the session's current token —
     · when it comes back into view, or is restored from the back-forward cache;
     · every ten minutes while it is on screen (which also keeps the session alive);
     · and, the one that guarantees it, just before a form is sent if the token has not
       been checked for five minutes: the submit waits for the answer, then goes.
   — and writes it everywhere a token lives: hidden `_token` fields, the two meta tags and
   any `data-csrf` attribute.

   And once, on the page a refused form was sent back to, it puts back what the person had
   typed (Support\FormReplay): only into the form that posted it, never into a password,
   file or hidden field, and never over something already filled in.

   Nothing here is required. With no script, or a failed fetch, the form posts exactly as
   it did before, and a stale token is refused the recoverable way (CsrfMiddleware).
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (!window.fetch) return;

  var TOKEN_URL = '/session/token.json';
  var STALE = 5 * 60 * 1000, BEAT = 10 * 60 * 1000;
  var checked = Date.now(), pending = null, passing = false;

  function apply(t) {
    var i, els = document.querySelectorAll('input[name="_token"]');
    for (i = 0; i < els.length; i++) els[i].value = t;
    els = document.querySelectorAll('meta[name="ag-csrf"], meta[name="csrf-token"]');
    for (i = 0; i < els.length; i++) els[i].setAttribute('content', t);
    els = document.querySelectorAll('[data-csrf]');
    for (i = 0; i < els.length; i++) els[i].setAttribute('data-csrf', t);
  }

  function refresh() {
    if (pending) return pending;
    pending = fetch(TOKEN_URL, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && typeof d.token === 'string' && d.token) { apply(d.token); checked = Date.now(); } })
      .catch(function () {})
      .then(function () { pending = null; });
    return pending;
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && Date.now() - checked > 60 * 1000) refresh();
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) refresh(); });
  setInterval(function () { if (!document.hidden) refresh(); }, BEAT);

  // Before a stale form goes: hold it, refresh, then send it again with the same button.
  // Capture on the window runs before every other submit handler, and stopping it here
  // means they run once, on the resubmission, not twice.
  window.addEventListener('submit', function (e) {
    var f = e.target;
    if (passing || !f || !f.querySelector || !f.querySelector('input[name="_token"]')) return;
    if (Date.now() - checked < STALE || typeof f.requestSubmit !== 'function') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var by = e.submitter || null;
    refresh().then(function () {
      checked = Date.now();       // even if the fetch failed: never hold the same form twice
      passing = true;
      try { by && by.form === f ? f.requestSubmit(by) : f.requestSubmit(); } finally { passing = false; }
    });
  }, true);

  // ── put back what a refused form had in it ──
  function restore() {
    var m = document.querySelector('meta[name="ag-form-replay"]');
    if (!m) return;
    var data; try { data = JSON.parse(m.getAttribute('content') || ''); } catch (err) { return; }
    if (!data || !data.action || !data.fields || !data.fields.length) return;
    var form = null, forms = document.forms;
    for (var i = 0; i < forms.length; i++) {
      // The ATTRIBUTES, not `form.action`/`form.method`: a field named "action" shadows
      // the property, and the form is then matched against an input element.
      var a; try { a = new window.URL(forms[i].getAttribute('action') || location.href, location.href).pathname; } catch (err) { continue; }
      if (a === data.action && (forms[i].getAttribute('method') || '').toLowerCase() === 'post') { form = forms[i]; break; }
    }
    if (!form) return;
    var filled = [];
    data.fields.forEach(function (p) {
      var name = p[0], val = p[1];
      var els = form.querySelectorAll('[name="' + name.replace(/["\\]/g, '\\$&') + '"]');
      for (var j = 0; j < els.length; j++) {
        var el = els[j], type = (el.type || '').toLowerCase();
        if (type === 'password' || type === 'file' || type === 'hidden' || type === 'submit' || type === 'button') continue;
        if (type === 'checkbox' || type === 'radio') {
          if (el.value === val && !el.checked) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); }
          continue;
        }
        if (filled.indexOf(el) !== -1) continue;     // `a[]`: the next value goes in the next box
        filled.push(el);
        if (el.tagName === 'SELECT' || !el.value) {
          el.value = val;
          el.dispatchEvent(new Event('input', { bubbles: true }));
        }
        break;
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restore); else restore();
})();
