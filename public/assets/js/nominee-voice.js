/* The nominee's voice thank-you (templates/nominee/profile.twig, Services\NomineeVoice).
 *
 * Without this the form works on its own: an upload control and Save. Where the browser can
 * record, this draws the recorder — one round button, a count to the limit and a bar — plays
 * the take back before anything is sent, and puts it in the form's own file input, so Save is
 * the same plain POST either way. Save is offered only once there is something to save. A
 * refused microphone leaves the upload exactly where it was, and says so.
 *
 * A page of its own, outside the shell: site-nav never swaps it, so this runs once per load. */
(function () {
  'use strict';
  var box = document.querySelector('[data-voice]');
  if (!box) return;
  var MAX = parseInt(box.getAttribute('data-max'), 10) || 30;
  var q = function (s) { return box.querySelector(s); };
  var input = q('input[type=file]'), secs = q('input[name=seconds]'), panel = q('[data-voice-panel]');
  var btn = q('[data-voice-rec]'), state = q('[data-voice-state]'), time = q('[data-voice-time]'), bar = q('[data-voice-bar]');
  var take = q('[data-voice-take]'), save = q('[data-voice-save]'), say = q('[data-voice-say]'), upT = q('[data-voice-up-t]');
  var upWords = upT ? upT.textContent : '';

  function ready(on) { if (save) save.hidden = !on; }
  function clock(n) { return Math.floor(n / 60) + ':' + ('0' + (n % 60)).slice(-2); }
  function tell(s) { if (say) say.textContent = s; }

  // A file chosen by hand is ready to save, and says which file it is.
  if (input) input.addEventListener('change', function () {
    var f = input.files && input.files[0];
    if (!f) return;
    if (take) { take.hidden = true; take.removeAttribute('src'); }
    if (upT) upT.textContent = 'Chosen: ' + f.name + ' · choose another';
    if (secs) secs.value = '0';
    tell('');
    ready(true);
  });

  // With script running, Save waits for something to save — a take or a chosen file.
  ready(false);
  var can = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder && window.DataTransfer);
  if (!can || !panel || !btn) return;
  panel.hidden = false;
  box.classList.add('nm-vr--live');
  if (upT) { upWords = 'Or upload a recording you already have'; upT.textContent = upWords; }

  var rec = null, timer = 0, t0 = 0, url = '';
  function pick() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
    for (var i = 0; i < types.length; i++) if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    return '';
  }
  function show(n) {
    if (time) time.textContent = clock(Math.min(n, MAX));
    if (bar) bar.style.transform = 'scaleX(' + Math.min(1, n / MAX) + ')';
  }
  function stop() { if (rec && rec.state === 'recording') rec.stop(); }

  btn.addEventListener('click', function () {
    if (rec && rec.state === 'recording') { stop(); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var type = pick(), chunks = [];
      rec = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
      rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      rec.onstop = function () {
        clearInterval(timer);
        stream.getTracks().forEach(function (t) { t.stop(); });
        var n = Math.min(MAX, Math.round((Date.now() - t0) / 1000));
        var mime = (rec.mimeType || type || 'audio/webm').split(';')[0];
        var ext = mime.indexOf('mp4') >= 0 ? 'm4a' : (mime.indexOf('ogg') >= 0 ? 'ogg' : 'webm');
        var blob = new Blob(chunks, { type: mime });
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'thank-you.' + ext, { type: mime }));
        input.files = dt.files;
        if (secs) secs.value = String(n);
        if (url) URL.revokeObjectURL(url);
        url = URL.createObjectURL(blob);
        if (take) { take.src = url; take.hidden = false; }
        if (upT) upT.textContent = upWords;
        btn.setAttribute('aria-pressed', 'false');
        btn.setAttribute('aria-label', 'Record again');
        box.classList.remove('nm-vr--rec');
        if (state) state.textContent = 'Listen back, then save it — or tap to record again';
        show(n);
        tell(n < 2 ? 'That was very short. Record again if it was cut off.' : '');
        ready(true);
      };
      t0 = Date.now();
      rec.start();
      btn.setAttribute('aria-pressed', 'true');
      btn.setAttribute('aria-label', 'Stop recording');
      box.classList.add('nm-vr--rec');
      if (state) state.textContent = 'Recording… tap to stop';
      if (take) take.hidden = true;
      ready(false);
      tell('');
      show(0);
      timer = setInterval(function () {
        var n = Math.round((Date.now() - t0) / 1000);
        show(n);
        if (n >= MAX) stop();
      }, 250);
    }).catch(function () {
      tell('This page was not allowed to use the microphone. Allow it from the padlock beside the address, or upload a recording instead.');
    });
  });
})();
