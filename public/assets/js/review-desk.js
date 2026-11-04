// The review desk (templates/admin/nominations/desk.twig). Everything here makes a working
// page quicker; nothing here is needed for it to work. Without it a queue row and Skip are
// links, and the decision form posts and comes back on the next nomination.
//
// With it:
//   · moving (a queue row, J/K, S) swaps the two working panes in place, from the desk's
//     fragment endpoint, and keeps the address on the nomination shown;
//   · a decision posts over fetch, the row leaves the queue, the session log gains a line
//     and the desk moves to the next one — or says it is done;
//   · A approves and moves on; M and R choose Ask or Reject, then Enter confirms; 1–4 pick
//     a reject reason; J/K move; S skips; Esc goes back to the list; ? shows the keys.
//
// Swapped in by console-nav (data-nav="rerun"), so the one document listener goes through
// cnPage and leaves with the page — A pressed on the next page must approve nothing.
(function () {
  var root = document.querySelector('[data-dk]');
  if (!root) return;
  var work = root.querySelector('[data-dk-work]');
  var qbase = root.getAttribute('data-dk-base') || '/admin/nominations/desk?';
  var listHref = root.getAttribute('data-dk-list') || '/admin/nominations/review';
  var doneEl = root.querySelector('[data-dk-done]');
  var leftEl = root.querySelector('[data-dk-left]');
  var fill = root.querySelector('[data-dk-fill]');
  var log = root.querySelector('[data-dk-log]');
  var logList = root.querySelector('[data-dk-loglist]');
  var allDone = root.querySelector('[data-dk-alldone]');
  var keys = root.querySelector('.dk-keys');
  var order = [].map.call(root.querySelectorAll('[data-dk-q]'), function (a) { return a.getAttribute('data-dk-q'); });
  var decided = {};
  var done = 0;
  var busy = false;

  function csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }
  function main() { return work ? work.querySelector('[data-dk-main]') : null; }
  function currentId() { var m = main(); return m ? m.getAttribute('data-dk-id') : null; }
  function left() { return order.filter(function (id) { return !decided[id]; }); }
  function say(msg, bad) {
    if (window.agToast) window.agToast(msg, !!bad); else if (bad) window.alert(msg);
  }

  function progress() {
    var l = left().length;
    if (doneEl) doneEl.textContent = String(done);
    if (leftEl) leftEl.textContent = String(l);
    if (fill) fill.style.width = (done + l ? Math.round(done / (done + l) * 100) : 0) + '%';
  }

  function mark(id) {
    [].forEach.call(root.querySelectorAll('[data-dk-q]'), function (a) {
      if (a.getAttribute('data-dk-q') === String(id)) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
    });
  }

  // The nomination `step` places from the current one among those still waiting, wrapping.
  function neighbour(step) {
    var l = left();
    if (!l.length) return null;
    var cur = currentId();
    var i = order.indexOf(cur);
    for (var n = 1; n <= order.length; n++) {
      var id = order[(i + step * n + order.length * n) % order.length];
      if (!decided[id] && id !== cur) return id;
    }
    return decided[cur] ? null : cur;
  }

  function show(id) {
    if (!id || busy || !work) return;
    busy = true;
    work.setAttribute('aria-busy', 'true');
    var url = qbase.replace('/admin/nominations/desk?', '/admin/nominations/review/next?') + 'item=' + encodeURIComponent(id);
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(function (j) {
        if (j.done) { finished(); return; }
        work.innerHTML = j.html;
        mark(j.id);
        try { history.replaceState(history.state, '', qbase + 'item=' + j.id); } catch (e) {}
        var h = work.querySelector('[data-dk-focus]');
        if (h) h.focus({ preventScroll: true });
        if (work.scrollIntoView && window.matchMedia('(max-width: 759px)').matches) work.scrollIntoView({ block: 'start' });
      })
      .catch(function () { location.href = qbase + 'item=' + encodeURIComponent(id); })   // the plain link always works
      .then(function () { busy = false; work.removeAttribute('aria-busy'); });
  }

  function finished() {
    var body = root.querySelector('.dk-body');
    if (body) body.hidden = true;
    if (allDone) allDone.hidden = false;
  }

  function logLine(id, name, word) {
    if (!logList) return;
    var li = document.createElement('li');
    li.className = 'dk-log__i';
    var a = document.createElement('a');
    a.href = '/admin/nominations/' + id;
    a.textContent = name;
    var w = document.createElement('span');
    w.className = 'dk-log__w';
    w.textContent = word;
    li.appendChild(a); li.appendChild(w);
    logList.insertBefore(li, logList.firstChild);
    if (log) log.hidden = false;
  }

  // ── keeping the button honest ────────────────────────────────────────────
  function choice() { var c = work && work.querySelector('input[name="choice"]:checked'); return c ? c.value : ''; }
  function sync() {
    var go = work && work.querySelector('[data-dk-go]');
    if (!go) return;
    var c = choice();
    var ok = c === 'approve' || (c === 'ask' && (work.querySelector('[name="asked_message"]') || {}).value.trim() !== '')
          || (c === 'reject' && !!work.querySelector('input[name="reject_reason"]:checked'));
    go.disabled = !ok;
  }
  if (work) {
    work.addEventListener('change', sync);
    work.addEventListener('input', sync);
  }

  // ── deciding ─────────────────────────────────────────────────────────────
  var WORDS = { approve: 'Approved', ask: 'Asked for more', reject: 'Rejected', merge: 'Merged' };
  function decide(form, submitter) {
    if (busy) return;
    var m = main();
    var id = currentId();
    var name = m ? m.getAttribute('data-dk-name') : '';
    var kind = form.getAttribute('data-dk-form');
    var fd = new FormData(form);
    if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
    fd.set('_token', csrf());
    var word = kind === 'decide' ? (WORDS[fd.get('choice')] || 'Decided') : (WORDS[kind] || '');
    busy = true;
    work.setAttribute('aria-busy', 'true');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
      .then(function (res) {
        busy = false;
        work.removeAttribute('aria-busy');
        if (!res.ok) { say((res.j && res.j.error) || 'That did not go through. Nothing changed.', true); return; }
        if (kind === 'apart') { say(res.j.message || 'Marked as a different person.'); show(id); return; }
        decided[id] = true;
        done++;
        var row = root.querySelector('[data-dk-q="' + id + '"]');
        if (row && row.parentNode) row.parentNode.remove();
        var count = root.querySelector('[data-dk-qcount]');
        if (count) count.textContent = String(left().length);
        progress();
        logLine(id, name, word);
        say(res.j.message || word);
        var next = neighbour(1);
        if (next) show(next); else finished();
      })
      .catch(function () {
        busy = false;
        work.removeAttribute('aria-busy');
        say('The connection dropped. Nothing changed.', true);
      });
  }

  if (work) {
    work.addEventListener('submit', function (e) {
      var form = e.target.closest('form[data-dk-form]');
      if (!form) return;
      e.preventDefault();
      if (form.getAttribute('data-dk-form') === 'decide') {
        var c = choice();
        if (c === 'reject' && !form.querySelector('input[name="reject_reason"]:checked')) { say('Pick the reason the nominator will see.', true); return; }
        if (c === 'ask' && (form.querySelector('[name="asked_message"]') || {}).value.trim() === '') { say('Write what is missing first.', true); return; }
      }
      decide(form, e.submitter);
    });
  }

  // ── moving ───────────────────────────────────────────────────────────────
  root.addEventListener('click', function (e) {
    var q = e.target.closest('[data-dk-q]');
    if (q) { e.preventDefault(); show(q.getAttribute('data-dk-q')); return; }
    var st = e.target.closest('[data-dk-step]');
    if (st) { e.preventDefault(); show(neighbour(parseInt(st.getAttribute('data-dk-step'), 10))); return; }
    if (e.target.closest('[data-dk-skip]')) { e.preventDefault(); show(neighbour(1)); return; }
    if (e.target.closest('[data-dk-keep]')) { var f = e.target.closest('.dk-flag'); if (f) f.classList.add('is-kept'); return; }
    var mv = e.target.closest('[data-dk-move]');
    if (mv) {
      var sel = work.querySelector('[data-dk-cat]');
      if (sel) sel.value = mv.getAttribute('data-dk-move');
      var fl = mv.closest('.dk-flag'); if (fl) fl.classList.add('is-kept');
      say('Moved. Approve to file it there.');
    }
  });

  function pick(value) {
    var r = work && work.querySelector('input[name="choice"][value="' + value + '"]');
    if (!r || r.disabled) return false;
    r.checked = true;
    sync();
    return true;
  }
  function submitDecision() {
    var form = work && work.querySelector('form[data-dk-form="decide"]');
    var go = work && work.querySelector('.dk-go');
    if (!form || !go || go.disabled) return;
    form.requestSubmit ? form.requestSubmit(go) : decide(form, go);
  }

  cnPage.on(document, 'keydown', function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey || busy) return;
    if (document.querySelector('dialog[open]')) return;
    var t = e.target || {};
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || '') || t.isContentEditable;
    if (e.key === 'Escape') {
      if (keys && keys.open) { keys.open = false; return; }
      if (typing) { t.blur(); return; }
      if (window.cnNav) window.cnNav.go(listHref); else location.href = listHref;
      return;
    }
    if (typing && !(t.type === 'radio' || t.type === 'checkbox')) return;
    var k = (e.key || '').toLowerCase();
    if (k === 'a') { e.preventDefault(); if (pick('approve')) submitDecision(); }
    else if (k === 'm') { e.preventDefault(); pick('ask'); }
    else if (k === 'r') { e.preventDefault(); pick('reject'); }
    else if (k === 'enter' && t.tagName !== 'BUTTON' && t.tagName !== 'A') { if (choice()) { e.preventDefault(); submitDecision(); } }
    else if (choice() === 'reject' && /^[1-4]$/.test(k)) {
      var rs = work.querySelectorAll('input[name="reject_reason"]');
      if (rs[+k - 1]) { e.preventDefault(); rs[+k - 1].checked = true; sync(); }
    }
    else if (k === 'j') { e.preventDefault(); show(neighbour(1)); }
    else if (k === 'k') { e.preventDefault(); show(neighbour(-1)); }
    else if (k === 's') { e.preventDefault(); show(neighbour(1)); }
    else if (e.key === '?') { if (keys) { e.preventDefault(); keys.open = !keys.open; } }
  });

  progress();
  sync();
})();
