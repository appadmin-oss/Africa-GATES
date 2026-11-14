/* /my-work/{token}: the questionnaire as one question at a time, dictation into the field it
 * was started from, and the optional spoken introduction (templates/pages/my-work.twig).
 *
 * Progressive: without this file the page is the whole form and it works. With it, each
 * fieldset is a step. Steps are HIDDEN, never removed, so every answer is still in the form
 * when it is posted. Next is the primary control; the map jumps to any step.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-mw-form]');
  var token = form ? form.getAttribute('data-token') : '';
  var csrf = function () { var i = document.querySelector('input[name="_token"]'); return i ? i.value : ''; };

  // ── steps ──────────────────────────────────────────────────────────────────
  if (form) {
    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-mw-step]'));
    var map = form.querySelector('[data-mw-map]');
    var dots = map ? map.querySelectorAll('[data-mw-goto]') : [];
    var at = 0;
    // A missing-answer link (#q-slug) opens on that question rather than the first.
    var hash = location.hash.replace('#', '');
    steps.forEach(function (s, i) { if (s.id && s.id === hash) at = i; });
    var show = function (n, focus) {
      at = Math.max(0, Math.min(steps.length - 1, n));
      steps.forEach(function (s, i) { s.hidden = i !== at; });
      for (var d = 0; d < dots.length; d++) {
        var f = steps[d] && steps[d].querySelector('textarea, input:not([type=hidden]), select');
        dots[d].classList.toggle('is-done', !!(f && f.value.trim()));
        if (d === at) dots[d].setAttribute('aria-current', 'step'); else dots[d].removeAttribute('aria-current');
      }
      if (focus) { var l = steps[at].querySelector('legend'); if (l) { l.tabIndex = -1; l.focus(); } }
    };
    form.querySelectorAll('[data-mw-nav]').forEach(function (n) { n.hidden = false; });
    if (map) map.hidden = false;
    form.addEventListener('click', function (e) {
      var b = e.target.closest('[data-mw-next],[data-mw-back],[data-mw-goto]');
      if (!b) return;
      if (b.hasAttribute('data-mw-next')) show(at + 1, true);
      else if (b.hasAttribute('data-mw-back')) show(at - 1, true);
      else show(parseInt(b.getAttribute('data-mw-goto'), 10) || 0, true);
    });
    show(at, false);
  }

  // ── recording (dictation and the introduction share it) ────────────────────
  var canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  function record(btn, onDone, maxSeconds) {
    if (btn._rec) { btn._rec.stop(); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var chunks = [], rec = new MediaRecorder(stream);
      btn._rec = rec; btn.setAttribute('aria-pressed', 'true'); btn._label = btn.textContent; btn.textContent = 'Stop';
      rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
      rec.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        btn._rec = null; btn.setAttribute('aria-pressed', 'false'); btn.textContent = btn._label;
        onDone(new Blob(chunks, { type: rec.mimeType || 'audio/webm' }));
      };
      rec.start();
      if (maxSeconds) setTimeout(function () { if (btn._rec === rec) rec.stop(); }, maxSeconds * 1000);
    }).catch(function () {
      btn.textContent = 'Your phone may be asking for permission — allow it, then try again';
    });
  }
  function post(path, fields, blob) {
    var fd = new FormData();
    fd.append('_token', csrf());
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    if (blob) fd.append('audio', blob, 'answer.webm');
    return fetch('/my-work/' + token + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  if (form && canRecord) {
    form.addEventListener('click', function (e) {
      var b = e.target.closest('[data-mw-dictate]');
      if (!b) return;
      var field = document.getElementById(b.getAttribute('data-mw-dictate'));
      record(b, function (blob) {
        b.disabled = true;
        post('/listen', {}, blob).then(function (r) {
          b.disabled = false;
          if (r && r.ok && r.text && field) field.value = (field.value ? field.value.replace(/\s*$/, ' ') : '') + r.text;
          else if (r && r.message) b.textContent = r.message;
        }).catch(function () { b.disabled = false; });
      }, 120);
    });
  } else if (form) {
    form.querySelectorAll('[data-mw-dictate]').forEach(function (b) { b.hidden = true; });
  }

  var intro = document.querySelector('[data-mw-intro]');
  if (intro) {
    var state = intro.querySelector('[data-mw-intro-state]');
    var max = parseInt(intro.getAttribute('data-max'), 10) || 60;
    var done = function (r) { if (r && r.message && state) state.textContent = r.message; if (r && r.ok) setTimeout(function () { location.reload(); }, 900); };
    var rec = intro.querySelector('[data-mw-intro-rec]');
    if (rec && canRecord) rec.addEventListener('click', function () { record(rec, function (blob) { post('/intro', { action: 'record' }, blob).then(done); }, max); });
    else if (rec) rec.hidden = true;
    intro.querySelectorAll('[data-mw-intro-act]').forEach(function (b) {
      b.addEventListener('click', function () { post('/intro', { action: b.getAttribute('data-mw-intro-act') }).then(done); });
    });
  }
})();
