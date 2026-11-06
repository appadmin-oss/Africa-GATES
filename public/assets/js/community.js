/*
 * A community thread's member controls (templates/pages/community/thread.twig).
 *
 * Loaded only for a signed-in member: a guest is offered sign-in links instead of buttons
 * the server would refuse. Cheer, follow and save are the live /api/v1/community/*
 * endpoints, each a toggle whose answer sets `aria-pressed` and the button's words; the
 * report opens a <dialog> asking why, and the reason travels with it. Replying is a plain
 * form and needs nothing here. CSP: delegated listeners only, no inline handlers.
 */
(function (w, d) {
  'use strict';

  function token() {
    var t = d.querySelector('input[name="_token"]');
    var m = d.querySelector('meta[name="ag-csrf"]');
    return (t && t.value) || (m && m.content) || '';
  }

  function post(path, body) {
    var fd = new URLSearchParams();
    Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
    return fetch('/api/v1/community/' + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token(),
                 'Content-Type': 'application/x-www-form-urlencoded' },
      body: fd.toString()
    }).then(function (r) { return r.json(); });
  }

  function setPressed(btn, on) {
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    var l = btn.querySelector('[data-cm-l]');
    if (l) l.textContent = on ? btn.getAttribute('data-on') : btn.getAttribute('data-off');
  }

  var reportTarget = null;

  d.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t) return;
    var box = t.closest('[data-cm-thread]');
    var id = box ? box.getAttribute('data-cm-thread') : null;

    var cheer = t.closest('[data-cm-cheer]');
    if (cheer && id) {
      e.preventDefault();
      cheer.setAttribute('aria-busy', 'true');
      post('cheer', { target_type: 'thread', target_id: id, kind: 'cheer' }).then(function (r) {
        cheer.removeAttribute('aria-busy');
        if (!r || !r.success) return;
        setPressed(cheer, r.kind === 'cheer');
        var n = cheer.querySelector('[data-cm-n]'); if (n) n.textContent = String(r.count || 0);
      }).catch(function () { cheer.removeAttribute('aria-busy'); });
      return;
    }

    var tog = t.closest('[data-cm-toggle]');
    if (tog && id) {
      e.preventDefault();
      var what = tog.getAttribute('data-cm-toggle');
      var req = what === 'follow'
        ? post('follow', { target_type: 'thread', target_id: id })
        : post('bookmark', { thread_id: id });
      req.then(function (r) {
        if (!r || !r.ok) return;
        setPressed(tog, what === 'follow' ? !!r.following : !!r.bookmarked);
      }).catch(function () {});
      return;
    }

    var rep = t.closest('[data-cm-report]');
    var dlg = d.querySelector('[data-cm-dlg]');
    if (rep && dlg && dlg.showModal) {
      e.preventDefault();
      reportTarget = { type: rep.getAttribute('data-cm-report'), id: rep.getAttribute('data-id'), btn: rep };
      var say = dlg.querySelector('[data-cm-dlg-say]'); if (say) say.textContent = '';
      dlg.showModal();
      return;
    }

    var send = t.closest('[data-cm-dlg-send]');
    if (send && dlg && reportTarget) {
      e.preventDefault();
      var why = dlg.querySelector('[data-cm-why]');
      var out = dlg.querySelector('[data-cm-dlg-say]');
      post('report', { target_type: reportTarget.type, target_id: reportTarget.id, reason: why ? why.value : '' })
        .then(function (r) {
          var ok = r && (r.ok || r.success);
          if (out) out.textContent = ok ? out.getAttribute('data-done') : out.getAttribute('data-fail');
          if (ok) {
            reportTarget.btn.disabled = true;
            var back = reportTarget.btn;
            setTimeout(function () { dlg.close(); back.focus(); }, 1200);
          }
        })
        .catch(function () { if (out) out.textContent = out.getAttribute('data-fail'); });
    }
  });
})(window, document);
