/* ══════════════════════════════════════════════════════════════════════════════
   SUPPORT TICKETS — tickets.js · Phase 9 · /support/tickets and /support/t/{token}
   ══════════════════════════════════════════════════════════════════════════════
   Posts the server-drawn forms and reloads, so a new turn is SHOWN rather than drawn
   optimistically. Files make the body multipart and the browser sets its own boundary
   (a hand-written multipart Content-Type has none, and the server parses nothing). The
   link page's reply goes to {this path}/reply — the token is read from the address bar,
   never printed — with the CSRF token from the layout's one meta tag. The status filter
   hides server-drawn rows in place and says so when a view is empty. */
(function () {
  'use strict';
  var root = document.querySelector('[data-st]');
  if (!root) return;
  var meta = document.querySelector('meta[name="ag-csrf"]');

  root.querySelectorAll('[data-st-form]').forEach(function (form) {
    var kind = form.getAttribute('data-st-form');
    var note = form.querySelector('[data-st-note]');
    if (kind === 'link') {
      form.hidden = false;
      var ns = root.querySelector('[data-st-noscript]'); if (ns) ns.hidden = true;
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = form.elements.body;
      if (!body || !body.value.trim()) { if (body) body.focus(); return; }
      var btn = form.querySelector('[type="submit"]');
      var url = kind === 'link' ? location.pathname.replace(/\/+$/, '') + '/reply' : form.getAttribute('action');
      var fd = new FormData(form);
      if (kind === 'create') fd.append('page_url', location.href);
      if (meta && !fd.has('_token')) fd.append('_token', meta.getAttribute('content'));
      var files = form.querySelector('input[type="file"]');
      if (files && (!files.files || files.files.length === 0)) fd.delete('files[]');
      if (btn) btn.setAttribute('aria-busy', 'true');
      fetch(url, { method: 'POST', credentials: 'same-origin', body: fd,
                   headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
                              'X-CSRF-Token': meta ? meta.getAttribute('content') : '' } })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          if (note) { note.textContent = d.message || ''; note.classList.toggle('st-note--err', !d.ok); }
          if (d.code === 'SIGN_IN' && d.login_url) { setTimeout(function () { location.href = d.login_url; }, 1400); return; }
          if (d.ok) setTimeout(function () { location.reload(); }, 900);
        })
        .catch(function () { if (note) { note.textContent = note.getAttribute('data-offline') || 'No connection. Your words are still in the box — try again in a moment.'; note.classList.add('st-note--err'); } })
        .then(function () { if (btn) btn.removeAttribute('aria-busy'); });
    });
  });

  var filter = root.querySelector('[data-st-filter]');
  var list = root.querySelector('[data-st-list]');
  var empty = root.querySelector('[data-st-empty]');
  if (filter && list) {
    filter.hidden = false;
    filter.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-st-show]') : null;
      if (!b) return;
      var want = b.getAttribute('data-st-show'), any = false;
      filter.querySelectorAll('[data-st-show]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      list.querySelectorAll('[data-st-status]').forEach(function (li) {
        var on = want === 'all' || li.getAttribute('data-st-status') === want;
        li.hidden = !on; if (on) any = true;
      });
      if (empty) empty.textContent = any ? '' : filter.getAttribute('data-none');
    });
  }
})();
