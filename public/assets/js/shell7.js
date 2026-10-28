/* The v7 shell's small behaviours (docs/handoff/consoles-v7/README §2.4). Everything works
   without this file: forms post, links are links. It adds these on top:
     · a toast leaves on its own after a few seconds (and stays while hovered or focused);
     · a button with data-sh7-confirm asks first, since a dropped shift or leaving is hard to undo;
     · Escape closes the phone's More sheet and returns focus to its button;
     · Find (⌘K): matches as you type from the list the page carries; Enter on nothing chosen
       submits the search, which is what happens without this file too;
     · Ask (⌘J): the console's assistant in a side panel; without this file Ask is a link to it.
   The CSRF token is read from the page when a request is SENT, never copied at load: the
   token is refreshed under an open page (csrf-fresh), and a copy would go stale. */
(function () {
  'use strict';
  var toast = document.querySelector('[data-sh7-toast]');
  if (toast) {
    var held = false;
    toast.addEventListener('mouseenter', function () { held = true; });
    toast.addEventListener('mouseleave', function () { held = false; });
    setTimeout(function hide() {
      if (held) { setTimeout(hide, 1500); return; }
      toast.classList.add('is-gone');
      setTimeout(function () { toast.remove(); }, 400);
    }, 5200);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sh7-confirm]');
    if (b && !window.confirm(b.getAttribute('data-sh7-confirm'))) e.preventDefault();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.sh7-more[open]');
    if (open) { open.removeAttribute('open'); var s = open.querySelector('summary'); if (s) s.focus(); }
  });
  // ── Find ─────────────────────────────────────────────────────────────────────────
  var q = document.getElementById('sh7FindQ'), list = document.getElementById('sh7FindList'), data = document.getElementById('sh7FindData');
  if (q && list && data) {
    var items = [];
    try { items = JSON.parse(data.textContent || '[]'); } catch (err) { items = []; }
    var active = -1;
    var hits = function () { return list.querySelectorAll('.sh7-find__hit'); };
    var close = function () { list.hidden = true; list.textContent = ''; q.setAttribute('aria-expanded', 'false'); q.removeAttribute('aria-activedescendant'); active = -1; };
    var show = function () {
      var v = q.value.trim().toLowerCase();
      if (!v) { close(); return; }
      var words = v.split(/\s+/);
      var found = items.filter(function (i) {
        var hay = (i.t + ' ' + (i.sub || '') + ' ' + i.kind).toLowerCase();
        return words.every(function (w) { return hay.indexOf(w) > -1; });
      }).slice(0, 8);
      list.textContent = '';
      found.forEach(function (i, n) {
        var a = document.createElement('a');
        a.className = 'sh7-find__hit'; a.href = i.href; a.id = 'sh7f' + n; a.setAttribute('role', 'option');
        var w = document.createElement('span'); w.className = 'sh7-find__words';
        var t = document.createElement('span'); t.className = 'sh7-find__t'; t.textContent = i.t; w.appendChild(t);
        if (i.sub) { var sb = document.createElement('span'); sb.className = 'sh7-find__sub'; sb.textContent = i.sub; w.appendChild(sb); }
        var k = document.createElement('span'); k.className = 'sh7-find__kind'; k.textContent = i.kind;
        a.appendChild(w); a.appendChild(k); list.appendChild(a);
      });
      if (!found.length) {
        var p = document.createElement('p'); p.className = 'sh7-find__none';
        p.textContent = 'Nothing here matches. Press Enter to search everything.'; list.appendChild(p);
      }
      list.hidden = false; q.setAttribute('aria-expanded', 'true'); active = -1; q.removeAttribute('aria-activedescendant');
    };
    var move = function (d) {
      var h = hits(); if (!h.length) return;
      if (active > -1) h[active].classList.remove('is-on');
      active = (active + d + h.length) % h.length;
      h[active].classList.add('is-on'); q.setAttribute('aria-activedescendant', h[active].id);
    };
    q.addEventListener('input', show);
    q.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) show(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter' && active > -1) { e.preventDefault(); window.location.href = hits()[active].href; }
      else if (e.key === 'Escape') { if (!list.hidden) { e.stopPropagation(); close(); } else { q.blur(); } }
    });
    q.addEventListener('blur', function () { setTimeout(close, 150); });
    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); q.focus(); q.select(); }
    });
  }

  // ── Ask ──────────────────────────────────────────────────────────────────────────
  var dlg = document.getElementById('sh7Ask'), askBtn = document.querySelector('[data-sh7-ask]');
  if (dlg && askBtn && typeof dlg.showModal === 'function') {
    var log = document.getElementById('sh7AskLog'), form = document.getElementById('sh7AskForm'), box = document.getElementById('sh7AskQ');
    var thread = 0, opener = null;
    var token = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; };
    var open = function () { opener = document.activeElement; dlg.showModal(); box.focus(); };
    var shut = function () { dlg.close(); if (opener && opener.focus) opener.focus(); };
    var bubble = function (cls, text) { var d = document.createElement('div'); d.className = cls; d.textContent = text; log.appendChild(d); log.scrollTop = log.scrollHeight; return d; };
    var ask = function (text) {
      text = text.trim(); if (!text) return;
      bubble('sh7-ask__q', text);
      var wait = bubble('sh7-ask__a', 'Looking…');
      box.value = ''; box.disabled = true;
      fetch(dlg.getAttribute('data-post'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token(), 'X-Requested-With': 'fetch' },
        body: JSON.stringify({ message: text, thread_id: thread, page: window.location.pathname + window.location.search })
      }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The assistant did not answer. Try again.' }; }); },
              function () { return { ok: false, error: 'No connection. Your question was not sent.' }; })
        .then(function (res) {
          if (res.ok && res.message) {
            if (res.thread && res.thread.id) thread = res.thread.id;
            // The server's own rendering of its own markdown (ChatMarkdown::html escapes first).
            wait.innerHTML = res.message.html || '';
          } else {
            wait.textContent = res.error || 'The assistant could not answer that.';
          }
        }).then(function () { box.disabled = false; box.focus(); log.scrollTop = log.scrollHeight; });
    };
    askBtn.addEventListener('click', function (e) { e.preventDefault(); open(); });
    dlg.querySelector('[data-sh7-ask-close]').addEventListener('click', shut);
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); shut(); });
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(box.value); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(box.value); } });
    log.addEventListener('click', function (e) { var b = e.target.closest('[data-sh7-starter]'); if (b) ask(b.textContent); });
    document.addEventListener('keydown', function (e) {
      if ((e.metaKey || e.ctrlKey) && (e.key === 'j' || e.key === 'J')) { e.preventDefault(); if (dlg.open) shut(); else open(); }
    });
  }
})();
