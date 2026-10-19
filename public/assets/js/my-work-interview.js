/* /my-work/{token} as an interview (templates/pages/my-work/interview.twig).
 *
 * The page arrives fully drawn from the server's state. Every endpoint answers with that same
 * whole state, so this file redraws from it rather than patching — a turn, a retry and a
 * refresh cannot disagree about what has been recorded.
 *
 * What it adds:
 *   · one part at a time (talk · show · read back), and only the conversation scrolls;
 *   · the composer: quick replies the interviewer offered, attach, speak, send;
 *   · the interviewer's upload buttons posting in place, "I can't share this", and the
 *     interviewer answering either (`/interview/react`) without the nominee typing;
 *   · changing or removing your own turn, Listen on the interviewer's turns.
 *
 * Dictated words land in the box for the nominee to read before sending, never straight into
 * the record: an answer stored against somebody's name has to be one they approved. The way
 * out to the form is a plain form post and needs none of this.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-iv]');
  if (!root) return;
  var token = root.getAttribute('data-token');
  var who = root.getAttribute('data-who') || 'Interviewer';
  var voice = root.hasAttribute('data-voice');
  var log = root.querySelector('[data-iv-log]');
  var say = root.querySelector('[data-iv-say]');
  var box = say ? say.querySelector('textarea') : null;
  var go = say ? say.querySelector('.iv__send') : null;
  var chipsBox = root.querySelector('[data-iv-chips]');
  var status = root.querySelector('[data-iv-status]');
  var busy = false;
  var csrf = function () { var i = document.querySelector('input[name="_token"]'); return i ? i.value : ''; };
  var tell = function (t) { if (status) status.textContent = t || ''; };

  function post(path, fields, file) {
    var fd = new FormData();
    fd.append('_token', csrf());
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    if (file) fd.append(file.name, file.blob, file.filename);
    return fetch('/my-work/' + token + path, {
      method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' }
    });
  }
  var json = function (r) { return r.json(); };
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function button(label, cls, attr, val) { var b = el('button', cls, label); b.type = 'button'; if (attr) b.setAttribute(attr, val); return b; }

  // ── parts ────────────────────────────────────────────────────────────────
  var panes = root.querySelectorAll('[data-iv-pane]');
  var tabs = root.querySelectorAll('[data-iv-tab]');
  function show(phase, remember) {
    var known = false;
    panes.forEach(function (p) { if (p.getAttribute('data-iv-pane') === phase) known = true; });
    if (!known) phase = 'talk';
    panes.forEach(function (p) { p.hidden = p.getAttribute('data-iv-pane') !== phase; });
    tabs.forEach(function (t) {
      if (t.getAttribute('data-iv-tab') === phase) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
    });
    if (phase === 'talk') toBottom(true);
    if (remember) post('/interview/phase', { phase: phase }).catch(function () {});
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function (e) { e.preventDefault(); show(t.getAttribute('data-iv-tab'), true); });
  });

  // ── the log: stays at the newest message unless the nominee has scrolled up to read ──
  var pinned = true;
  if (log) log.addEventListener('scroll', function () { pinned = log.scrollHeight - log.scrollTop - log.clientHeight < 48; });
  function toBottom(force) { if (log && (pinned || force)) { log.scrollTop = log.scrollHeight; pinned = true; } }

  var accept = { document: 'application/pdf', image: 'image/*', any: 'application/pdf,image/*' };
  function askCard(r) {
    var li = el('li', 'iv__ask'); li.setAttribute('data-request', r.id);
    li.appendChild(el('p', 'iv__who', 'Asked for'));
    li.appendChild(el('p', 'iv__ask-t', r.title));
    if (r.why) li.appendChild(el('p', 'iv__fine', r.why));
    if (r.status !== 'open') {
      li.appendChild(el('p', 'iv__fine', r.status === 'done' ? 'Attached — thank you.' : 'Not shared. That is fine.'));
      return li;
    }
    var row = el('div', 'iv__ask-do');
    var pick = el('label', 'ag-btn ag-btn--primary iv__pick', 'Choose a file');
    var input = document.createElement('input'); input.type = 'file'; input.accept = accept[r.accept] || accept.any;
    input.addEventListener('change', function () { if (input.files[0]) upload(input.files[0], r.id, pick); });
    pick.appendChild(input);
    row.appendChild(pick);
    row.appendChild(button('I can’t share this', 'ag-btn ag-btn--secondary', 'data-iv-decline', r.id));
    li.appendChild(row);
    return li;
  }

  // The intro card (title, deadline, the promise) heads the log and is kept across redraws.
  var intro = log ? log.querySelector('[data-iv-intro]') : null;
  function draw(turns) {
    if (!log) return;
    log.textContent = '';
    if (intro) log.appendChild(intro);
    var last = null;
    turns.forEach(function (t) {
      if (t.role === 'event') { log.appendChild(el('li', 'iv__event', t.text)); return; }
      var me = t.role === 'nominee';
      var li = el('li', 'iv__turn iv__turn--' + (me ? 'me' : 'them'));
      li.setAttribute('data-i', t.i);
      li.appendChild(el('p', 'iv__who', (me ? 'You' : who) + (t.amended ? ' · changed' : '')));
      li.appendChild(el('p', 'iv__text', t.text));
      var act = el('div', 'iv__acts');
      if (me) act.appendChild(button('Change', 'ag-btn ag-btn--secondary iv__mini', 'data-iv-amend', t.i));
      else if (voice) act.appendChild(button('Listen', 'ag-btn ag-btn--secondary iv__mini', 'data-iv-speak', t.i));
      if (act.childNodes.length) li.appendChild(act);
      log.appendChild(li);
      (t.requests || []).forEach(function (r) { log.appendChild(askCard(r)); });
      last = t;
    });
    chips(last && last.role === 'interviewer' ? last.chips || [] : []);
    toBottom(true);
  }
  function chips(list) {
    if (!chipsBox) return;
    chipsBox.textContent = '';
    list.forEach(function (c) {
      var b = button(c, 'iv__chip'); b.addEventListener('click', function () { send(c); }); chipsBox.appendChild(b);
    });
  }
  function progress(p) {
    var box_ = root.querySelector('[data-iv-prog]');
    if (!box_ || !p) return;
    var pc = p.total ? Math.round(100 * p.met / p.total) : 0;
    box_.querySelector('.iv__fill').style.width = pc + '%';
    box_.querySelector('[data-iv-pc]').textContent = 'About ' + pc + '% covered — approximate';
    var n = root.querySelector('[data-iv-count]');
    if (n) n.textContent = p.met + '/' + p.total;
  }
  function works(list) {
    var ul = root.querySelector('[data-iv-works]');
    if (!ul || !list) return;
    ul.textContent = '';
    if (!list.length) { ul.appendChild(el('li', 'mw__help', 'Nothing yet.')); return; }
    list.forEach(function (w) {
      var li = el('li'); li.appendChild(el('b', null, w.title || w.file_name || 'Untitled'));
      if (w.file_name) li.appendChild(document.createTextNode(' · file attached'));
      if (w.link) { li.appendChild(document.createTextNode(' · ')); var a = el('a', null, 'link'); a.href = w.link; a.rel = 'noopener nofollow'; a.target = '_blank'; li.appendChild(a); }
      if (w.from === 'conversation') li.appendChild(document.createTextNode(' · from the conversation'));
      ul.appendChild(li);
    });
  }
  var stopped = ['no_ai', 'ceiling', 'off', 'sent'];
  function apply(st) {
    if (!st) return st;
    if (st.turns) draw(st.turns);
    progress(st.progress);
    if (st.works) works(st.works);
    // A conversation that has stopped is a different screen (the way out, in the service's own
    // words), and the server draws it — so go and get it rather than imitate it here.
    if (stopped.indexOf(st.degraded) !== -1 || (st.degraded === 'turns' && say)) location.reload();
    return st;
  }

  // ── the interviewer, thinking ────────────────────────────────────────────
  var typing = null;
  function thinking(on) {
    if (typing) { typing.remove(); typing = null; }
    if (on && log) {
      typing = el('li', 'iv__turn iv__turn--them is-typing');
      typing.appendChild(el('p', 'iv__who', who));
      typing.appendChild(el('p', 'iv__text', 'Writing…'));
      log.appendChild(typing); toBottom(true);
    }
  }
  function lock(on) { busy = on; if (go) go.disabled = on; }

  function lastIsEvent(st) { var t = st && st.turns && st.turns[st.turns.length - 1]; return !!(t && t.role === 'event'); }
  function react() {
    thinking(true); lock(true);
    return post('/interview/react', {}).then(json).then(function (r) {
      thinking(false); lock(false); apply(r);
      if (!r.ok && r.message) tell(r.message);
    }).catch(function () { thinking(false); lock(false); });
  }

  // ── sending a turn ───────────────────────────────────────────────────────
  function send(text) {
    text = (text || '').trim();
    if (!text || busy) { if (box && !text) box.focus(); return; }
    lock(true); tell(''); chips([]);
    var mine = el('li', 'iv__turn iv__turn--me is-pending');
    mine.appendChild(el('p', 'iv__who', 'You'));
    mine.appendChild(el('p', 'iv__text', text));
    log.appendChild(mine);
    if (box && box.value.trim() === text) { box.value = ''; grow(); }
    thinking(true);
    post('/interview', { say: text }).then(json).then(function (r) {
      thinking(false); lock(false);
      if (!r.ok) {
        mine.remove();
        if (box && !box.value) { box.value = text; grow(); }
        tell(r.message || 'That did not get through. Your answer is still here — try again.');
      }
      apply(r);
      if (box && window.matchMedia('(pointer:fine)').matches) box.focus();
    }).catch(function () {
      thinking(false); lock(false); mine.remove();
      if (box && !box.value) { box.value = text; grow(); }
      tell('No connection. Your answer is still here — send it again when you are back online.');
    });
  }
  function grow() { if (!box) return; box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 152) + 'px'; }

  if (say && box) {
    say.hidden = false;
    say.addEventListener('submit', function (e) { e.preventDefault(); send(box.value); });
    box.addEventListener('input', grow);
    box.addEventListener('keydown', function (e) {
      // Enter sends on a keyboard; on a phone Enter is a new line and the button sends.
      if (e.key !== 'Enter' || e.shiftKey || e.isComposing) return;
      if (e.ctrlKey || e.metaKey || window.matchMedia('(pointer:fine)').matches) { e.preventDefault(); send(box.value); }
    });
  }

  // ── files ────────────────────────────────────────────────────────────────
  function upload(file, requestId, control) {
    if (busy) return;
    if (control) control.classList.add('is-busy');
    tell('Uploading ' + file.name + '…'); lock(true);
    var fields = requestId ? { request: requestId } : {};
    return post('/upload', fields, { name: 'file', blob: file, filename: file.name }).then(json).then(function (r) {
      lock(false); tell(r.ok ? '' : (r.message || 'That file could not be attached.'));
      apply(r);
      if (r.ok && lastIsEvent(r)) return react();
    }).catch(function () { lock(false); tell('No connection. The file was not sent — try again.'); });
  }
  var attach = root.querySelector('[data-iv-attach]');
  if (attach) attach.addEventListener('change', function () { if (attach.files[0]) upload(attach.files[0], '', null); attach.value = ''; });

  // The server-drawn upload forms (an asked-for file, and "Show the work") post in place.
  root.querySelectorAll('[data-iv-upload]').forEach(function (f) {
    f.querySelectorAll('[data-iv-nojs]').forEach(function (b) { b.hidden = true; });
    f.querySelectorAll('[data-iv-decline]').forEach(function (b) { b.hidden = false; });
    var input = f.querySelector('input[type=file]');
    var rid = f.querySelector('input[name=request]');
    var run = function () { if (input.files[0]) upload(input.files[0], rid ? rid.value : '', null).then(function () { input.value = ''; }); };
    if (rid) input.addEventListener('change', run);
    f.addEventListener('submit', function (e) { e.preventDefault(); run(); });
  });

  if (log) {
    log.addEventListener('click', function (e) {
      var a = e.target.closest('[data-iv-amend]');
      var s = e.target.closest('[data-iv-speak]');
      var d = e.target.closest('[data-iv-decline]');
      if (a) amend(a.closest('li'), a.getAttribute('data-iv-amend'));
      if (s) speak(s, s.getAttribute('data-iv-speak'));
      if (d && !busy) {
        lock(true);
        post('/interview/decline', { request: d.getAttribute('data-iv-decline') }).then(json).then(function (r) {
          lock(false); apply(r); if (r.ok) return react();
        }).catch(function () { lock(false); });
      }
    });
  }

  // ── changing a turn of your own ──────────────────────────────────────────
  function amend(li, i) {
    if (!li || li.querySelector('textarea')) return;
    var text = li.querySelector('.iv__text');
    var ed = el('textarea', 'mw__in'); ed.rows = 4; ed.value = text.textContent;
    ed.setAttribute('aria-label', 'Change what you said');
    var row = el('div', 'mw__do');
    var save = button('Save the change', 'ag-btn ag-btn--primary');
    var rm = button('Remove it', 'ag-btn ag-btn--secondary');
    var no = button('Cancel', 'ag-btn ag-btn--secondary');
    row.append(save, rm, no);
    text.hidden = true; li.append(ed, row); ed.focus();
    var done = function () { ed.remove(); row.remove(); text.hidden = false; };
    var commit = function (how) {
      post('/interview/amend', { turn: i, how: how, text: ed.value }).then(json).then(function (r) {
        if (!r.ok && r.message) tell(r.message);
        apply(r);
      }).catch(function () { tell('No connection. Nothing was changed.'); done(); });
    };
    no.addEventListener('click', done);
    save.addEventListener('click', function () { commit('edit'); });
    rm.addEventListener('click', function () { commit('remove'); });
  }

  // ── read back: correcting our summary ────────────────────────────────────
  var ledger = root.querySelector('[data-iv-ledger]');
  if (ledger) {
    ledger.querySelectorAll('[data-iv-out-act]').forEach(function (n) { n.hidden = false; });
    var reload = function () { location.hash = '#iv-review'; location.reload(); };
    ledger.addEventListener('click', function (e) {
      var c = e.target.closest('[data-iv-correct]'), d = e.target.closest('[data-iv-drop]');
      if (d) post('/interview/outcome', { slug: d.getAttribute('data-iv-drop'), action: 'drop' }).then(reload);
      if (c) {
        var li = c.closest('li'); if (li.querySelector('textarea')) return;
        var ed = el('textarea', 'mw__in'); ed.rows = 3; ed.value = li.querySelector('.iv__text').textContent;
        ed.setAttribute('aria-label', 'Correct the summary');
        var ok = button('Save the correction', 'ag-btn ag-btn--primary');
        li.append(ed, ok); ed.focus();
        ok.addEventListener('click', function () {
          post('/interview/outcome', { slug: c.getAttribute('data-iv-correct'), action: 'correct', text: ed.value }).then(reload);
        });
      }
    });
  }

  // ── voice ────────────────────────────────────────────────────────────────
  function speak(b, i) {
    b.disabled = true;
    post('/speak', { turn: i }).then(function (r) {
      if (!r.ok) throw new Error('speak');
      return r.blob();
    }).then(function (blob) {
      var a = new Audio(URL.createObjectURL(blob));
      a.onended = function () { b.disabled = false; };
      return a.play();
    }).catch(function () { b.disabled = false; b.textContent = 'Could not play'; });
  }

  var mic = root.querySelector('[data-iv-mic]');
  if (mic && box) {
    var micLabel = mic.querySelector('[data-iv-mic-t]');
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder)) mic.hidden = true;
    var rec = null;
    mic.addEventListener('click', function () {
      if (rec) { rec.stop(); return; }
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        var parts = [];
        rec = new MediaRecorder(stream);
        mic.setAttribute('aria-pressed', 'true'); if (micLabel) micLabel.textContent = 'Stop'; tell('Listening… press the microphone again to stop.');
        rec.ondataavailable = function (e) { if (e.data.size) parts.push(e.data); };
        rec.onstop = function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          mic.setAttribute('aria-pressed', 'false'); if (micLabel) micLabel.textContent = 'Speak instead of typing';
          var blob = new Blob(parts, { type: rec.mimeType || 'audio/webm' }); rec = null;
          tell('Writing down what you said…');
          post('/listen', {}, { name: 'audio', blob: blob, filename: 'answer.webm' }).then(json).then(function (r) {
            if (r.ok && r.text) { box.value = (box.value ? box.value.replace(/\s*$/, ' ') : '') + r.text; grow(); tell('Read it, change anything, then press Send.'); box.focus(); }
            else tell(r.message || 'We could not hear that. Type it instead.');
          }).catch(function () { tell('No connection. Type it instead.'); });
        };
        rec.start();
        setTimeout(function () { if (rec) rec.stop(); }, 120000);
      }).catch(function () { tell('Your phone may be asking for permission — allow it, then try again.'); });
    });
  }

  // ── first paint ──────────────────────────────────────────────────────────
  if (panes.length) show(location.hash.replace('#iv-', '') || root.getAttribute('data-phase'), false);
  if (log) {
    toBottom(true);
    // Draw again from live state (adds the buttons the server render leaves to the script),
    // and if the last thing on the transcript is a notice nobody has answered — a file sent
    // from a browser with the script off — let the interviewer answer it now.
    post('/interview', {}).then(json).then(function (st) { apply(st); if (lastIsEvent(st)) react(); }).catch(function () {});
  }
})();
