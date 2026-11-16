/* The nominee's voice thank-you (templates/nominee/profile.twig, Services\NomineeVoice).
 *
 * The form works without this: a file input that takes an audio file (on a phone, `capture`
 * opens the recorder). Where the browser can record, this adds Record / Stop with a visible
 * count to 30 seconds, plays the take back before anything is sent, and puts it in the form's
 * own file input — so Save is the same plain POST either way, and a refused microphone leaves
 * the upload exactly where it was.
 *
 * A page of its own, outside the shell: site-nav never swaps it, so this runs once per load. */
(function () {
  'use strict';
  var box = document.querySelector('[data-voice]');
  if (!box) return;
  var MAX = parseInt(box.getAttribute('data-max'), 10) || 30;
  var input = box.querySelector('input[type=file]');
  var secs = box.querySelector('input[name=seconds]');
  var btn = box.querySelector('[data-voice-rec]');
  var out = box.querySelector('[data-voice-say]');
  var play = box.querySelector('[data-voice-take]');
  var save = box.querySelector('[data-voice-save]');
  var can = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder && window.DataTransfer);
  if (!can || !btn || !input) return;
  btn.hidden = false;
  var rec = null, timer = 0, t0 = 0, url = '';

  function say(s) { if (out) out.textContent = s; }
  function pick() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
    for (var i = 0; i < types.length; i++) if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    return '';
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
        if (play) { play.src = url; play.hidden = false; }
        btn.textContent = 'Record again';
        btn.setAttribute('aria-pressed', 'false');
        if (save) save.hidden = false;
        say('Recorded ' + n + ' seconds. Play it back, then save it — or record again.');
      };
      chunks = [];
      t0 = Date.now();
      rec.start();
      btn.textContent = 'Stop';
      btn.setAttribute('aria-pressed', 'true');
      say('Recording… 0 of ' + MAX + ' seconds.');
      timer = setInterval(function () {
        var n = Math.round((Date.now() - t0) / 1000);
        say('Recording… ' + Math.min(n, MAX) + ' of ' + MAX + ' seconds.');
        if (n >= MAX) stop();
      }, 1000);
    }).catch(function () {
      say('Your browser did not let this page use the microphone. Allow it in the address bar, or choose a recording from your phone below.');
    });
  });
})();
