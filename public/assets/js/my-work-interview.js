/* /my-work/{token} as an interview (templates/pages/my-work/interview.twig).
 *
 * The page arrives fully drawn from the server's state. Every endpoint here answers with that
 * same whole state, so this file redraws from it rather than patching — a turn, a retry and a
 * refresh cannot disagree about what has been recorded.
 *
 * What it adds: one part at a time (talk · show · read back), the composer, changing or
 * removing your own turn, Listen on the interviewer's turns and dictation into the composer
 * where voice is configured. Dictated words land in the box for the nominee to read before
 * sending, never straight into the record: an answer stored against somebody's name has to be
 * one they approved. The way out to the form is a plain form post and needs none of this.
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
  var status = root.querySelector('[data-iv-status]');
  var csrf = function () { var i = document.querySelector('input[name="_token"]'); return i ? i.value : ''; };

  function post(path, fields, blob) {
    var fd = new FormData();
    fd.append('_token', csrf());
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    if (blob) fd.append('audio', blob, 'answer.webm');
    return fetch('/my-work/' + token + path, { method: 'POST', body: fd, credentials: 'same-origin' });
  }
  var json = function (r) { return r.json(); };
  var say_ = function (t) { if (status) status.textContent = t || ''; };

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
    if (remember) post('/interview/phase', { phase: phase }).catch(function () {});
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function (e) { e.preventDefault(); show(t.getAttribute('data-iv-tab'), true); });
  });
  if (panes.length) show(location.hash.replace('#iv-', '') || root.getAttribute('data-phase'), false);

  // ── the transcript ───────────────────────────────────────────────────────
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function btn(label, attr, val) { var b = el('button', 'ag-btn ag-btn--secondary iv__mini', label); b.type = 'button'; b.setAttribute(attr, val); return b; }
  function draw(turns) {
    if (!log) return;
    log.textContent = '';
    turns.forEach(function (t) {
      var me = t.role === 'nominee';
      var li = el('li', 'iv__turn iv__turn--' + (me ? 'me' : 'them'));
      li.setAttribute('data-i', t.i);
      li.appendChild(el('p', 'iv__who', (me ? 'You' : who) + (t.amended ? ' · changed' : '')));
      li.appendChild(el('p', 'iv__text', t.text));
      var act = el('div', 'iv__acts');
      if (me) act.appendChild(btn('Change', 'data-iv-amend', t.i));
      else if (voice) act.appendChild(btn('Listen', 'data-iv-speak', t.i));
      if (act.childNodes.length) li.appendChild(act);
      log.appendChild(li);
    });
  }
  function progress(p) {
    var box_ = root.querySelector('[data-iv-prog]');
    if (!box_ || !p) return;
    box_.querySelector('.mw__ready').textContent = 'About ' + p.met + ' of ' + p.total
      + ' things the panel asks about are covered so far. This is approximate.';
    var pc = p.total ? Math.round(100 * p.met / p.total) : 0;
    box_.querySelector('.iv__fill').style.width = pc + '%';
    box_.querySelector('[data-iv-pc]').textContent = pc + '% covered';
  }
  function apply(st) {
    if (!st) return;
    if (st.turns) draw(st.turns);
    progress(st.progress);
    // A conversation that has stopped is a different screen (the way out, in the service's
    // own words), and the server draws it — so go and get it rather than imitate it here.
    if (['no_ai', 'ceiling', 'off', 'sent', 'turns'].indexOf(st.degraded) !== -1 && st.degraded !== root._was) location.reload();
  }
  root._was = (document.querySelector('[data-iv-say]') ? '' : 'turns');

  if (log) {
    // The server drew the turns without their buttons; draw them again from what is on screen
    // only after the first state arrives, so the first paint never waits on a request.
    post('/interview', {}).then(json).then(apply).catch(function () {});
    log.addEventListener('click', function (e) {
      var a = e.target.closest('[data-iv-amend]');
      var s = e.target.closest('[data-iv-speak]');
      if (a) amend(a.closest('li'), a.getAttribute('data-iv-amend'));
      if (s) speak(s, s.getAttribute('data-iv-speak'));
    });
  }

  // ── sending a turn ───────────────────────────────────────────────────────
  if (say && box) {
    say.hidden = false;
    say.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = box.value.trim();
      if (!text) { box.focus(); return; }
      var go = say.querySelector('button[type=submit]');
      go.disabled = true; say_('Sending…');
      var mine = el('li', 'iv__turn iv__turn--me is-pending');
      mine.appendChild(el('p', 'iv__who', 'You'));
      mine.appendChild(el('p', 'iv__text', text));
      log.appendChild(mine);
      post('/interview', { say: text }).then(json).then(function (r) {
        go.disabled = false;
        if (r.ok) { box.value = ''; say_(''); } else { mine.remove(); say_(r.message || 'That did not get through. Your answer is still here — try again.'); }
        apply(r);
        box.focus();
      }).catch(function () {
        go.disabled = false; mine.remove();
        say_('No connection. Your answer is still here — send it again when you are back online.');
      });
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); say.requestSubmit(); }
    });
  }

  // ── changing a turn of your own ──────────────────────────────────────────
  function amend(li, i) {
    if (!li || li.querySelector('textarea')) return;
    var text = li.querySelector('.iv__text');
    var ed = el('textarea', 'mw__in'); ed.rows = 4; ed.value = text.textContent;
    ed.setAttribute('aria-label', 'Change what you said');
    var row = el('div', 'mw__do');
    var save = el('button', 'ag-btn ag-btn--primary', 'Save the change'); save.type = 'button';
    var rm = el('button', 'ag-btn ag-btn--secondary', 'Remove it'); rm.type = 'button';
    var no = el('button', 'ag-btn ag-btn--secondary', 'Cancel'); no.type = 'button';
    row.append(save, rm, no);
    text.hidden = true; li.append(ed, row); ed.focus();
    var done = function () { ed.remove(); row.remove(); text.hidden = false; };
    var send = function (how) {
      post('/interview/amend', { turn: i, how: how, text: ed.value }).then(json).then(function (r) {
        if (!r.ok && r.message) say_(r.message);
        apply(r);
      }).catch(function () { say_('No connection. Nothing was changed.'); done(); });
    };
    no.addEventListener('click', done);
    save.addEventListener('click', function () { send('edit'); });
    rm.addEventListener('click', function () { send('remove'); });
  }

  // ── read back: correcting our summary ────────────────────────────────────
  var ledger = root.querySelector('[data-iv-ledger]');
  if (ledger) {
    ledger.querySelectorAll('[data-iv-out-act]').forEach(function (n) { n.hidden = false; });
    ledger.addEventListener('click', function (e) {
      var c = e.target.closest('[data-iv-correct]'), d = e.target.closest('[data-iv-drop]');
      if (d) {
        post('/interview/outcome', { slug: d.getAttribute('data-iv-drop'), action: 'drop' })
          .then(function () { location.hash = '#iv-review'; location.reload(); });
      }
      if (c) {
        var li = c.closest('li'); if (li.querySelector('textarea')) return;
        var ed = el('textarea', 'mw__in'); ed.rows = 3; ed.value = li.querySelector('.iv__text').textContent;
        ed.setAttribute('aria-label', 'Correct the summary');
        var ok = el('button', 'ag-btn ag-btn--primary', 'Save the correction'); ok.type = 'button';
        li.append(ed, ok); ed.focus();
        ok.addEventListener('click', function () {
          post('/interview/outcome', { slug: c.getAttribute('data-iv-correct'), action: 'correct', text: ed.value })
            .then(function () { location.hash = '#iv-review'; location.reload(); });
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
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder)) { mic.hidden = true; return; }
    var rec = null;
    mic.addEventListener('click', function () {
      if (rec) { rec.stop(); return; }
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        var chunks = [];
        rec = new MediaRecorder(stream);
        mic.setAttribute('aria-pressed', 'true'); mic.textContent = 'Stop'; say_('Listening…');
        rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
        rec.onstop = function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          mic.setAttribute('aria-pressed', 'false'); mic.textContent = 'Say it instead of typing';
          var blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' }); rec = null;
          say_('Writing down what you said…');
          post('/listen', {}, blob).then(json).then(function (r) {
            if (r.ok && r.text) { box.value = (box.value ? box.value.replace(/\s*$/, ' ') : '') + r.text; say_('Read it, change anything, then press Send.'); box.focus(); }
            else say_(r.message || 'We could not hear that. Type it instead.');
          }).catch(function () { say_('No connection. Type it instead.'); });
        };
        rec.start();
        setTimeout(function () { if (rec) rec.stop(); }, 120000);
      }).catch(function () { say_('Your phone may be asking for permission — allow it, then try again.'); });
    });
  }
})();
