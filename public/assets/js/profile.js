/* ══════════════════════════════════════════════════════════════════════════════
   PROFILE — Listen (Phase 6 · ProfilePage.dc.html · §8.8).
   Reads the profile aloud in the browser with speech synthesis: the name, the About and
   each recognition, a block at a time, so pause and the speed control act between blocks.
   Started by a press, never autoplayed. Where the browser has no speech synthesis the
   Listen controls stay hidden — a button that cannot work is not drawn.
   Speed is 1× → 1.5× → 2×, applied from the next block.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var root = document.querySelector('[data-pp]');
  var synth = window.speechSynthesis;
  if (!root || !synth || typeof window.SpeechSynthesisUtterance !== 'function') return;

  var btns = root.querySelectorAll('[data-pp-listen]');
  var player = root.querySelector('[data-pp-player]');
  var toggle = root.querySelector('[data-pp-toggle]');
  var speedBtn = root.querySelector('[data-pp-speed]');
  var bar = root.querySelector('[data-pp-progress]');
  var pct = root.querySelector('[data-pp-progress-n]');
  var voiceEl = root.querySelector('[data-pp-voice]');
  var lang = document.documentElement.lang || 'en';
  var RATES = [1, 1.5, 2];
  var rate = 0, i = 0, state = 'idle', blocks = [];

  Array.prototype.forEach.call(btns, function (b) { b.hidden = false; });
  if (voiceEl) {
    try { voiceEl.textContent = new Intl.DisplayNames([lang], { type: 'language' }).of(lang.split('-')[0]) || lang; }
    catch (e) { voiceEl.textContent = lang; }
  }

  function collect() {
    var out = [root.getAttribute('data-name') || ''];
    var about = root.querySelector('[data-pp-read]');
    if (about && about.textContent.trim()) out.push(about.textContent.trim());
    Array.prototype.forEach.call(root.querySelectorAll('[data-pp-read-rec]'), function (r) {
      out.push(r.getAttribute('data-pp-read-rec'));
    });
    return out.filter(function (t) { return t && t.trim(); });
  }
  function label(s) {
    var key = s === 'playing' ? 'data-t-pause' : (s === 'paused' ? 'data-t-resume' : 'data-t-listen');
    Array.prototype.forEach.call(root.querySelectorAll('[data-pp-listen-label]'), function (l) { l.textContent = root.getAttribute(key); });
    if (toggle) toggle.setAttribute('aria-label', root.getAttribute(s === 'playing' ? 'data-t-pause' : 'data-t-resume'));
    Array.prototype.forEach.call(btns, function (b) { b.setAttribute('aria-pressed', s === 'playing' ? 'true' : 'false'); });
  }
  function progress() {
    var p = blocks.length ? Math.round(i / blocks.length * 100) : 0;
    if (bar) bar.style.width = p + '%';
    if (pct) pct.textContent = p + '%';
  }
  function speak() {
    if (i >= blocks.length) { state = 'idle'; i = 0; label(state); progress(); return; }
    var u = new window.SpeechSynthesisUtterance(blocks[i]);
    u.lang = lang; u.rate = RATES[rate];
    u.onend = function () { if (state !== 'playing') return; i++; progress(); speak(); };
    synth.speak(u);
  }
  function play() {
    if (state === 'idle') { blocks = collect(); i = 0; }
    if (player) player.hidden = false;
    state = 'playing'; label(state); progress(); synth.cancel(); speak();
  }
  function pause() { state = 'paused'; synth.cancel(); label(state); }
  function press() { if (state === 'playing') pause(); else play(); }

  Array.prototype.forEach.call(btns, function (b) { b.addEventListener('click', press); });
  if (toggle) toggle.addEventListener('click', press);
  if (speedBtn) speedBtn.addEventListener('click', function () {
    rate = (rate + 1) % RATES.length;
    speedBtn.textContent = RATES[rate] + '×';
  });
  window.addEventListener('pagehide', function () { synth.cancel(); });
})();
