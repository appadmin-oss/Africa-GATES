/* ══════════════════════════════════════════════════════════════════════════════
   ONE STORY — blog.js · Phase 9 · design/BlogPage.dc.html (`view=post`) · §8.14
   ══════════════════════════════════════════════════════════════════════════════
   The reading-progress bar (one passive listener on <main>, aria-valuenow kept true), and
   Listen: the post's own recording through the DC's floating player when it has one —
   started by the press, never autoplayed — otherwise speech synthesis, a block at a time
   (one long utterance is cut off after ~15s in Chrome). The button stays hidden until one
   of the two exists. */
(function () {
  'use strict';
  var root = document.querySelector('[data-blog]');
  if (!root) return;
  var main = document.querySelector('.ag-main') || document.scrollingElement;

  var bar = document.querySelector('[data-blog-progress]');
  var fill = document.querySelector('[data-blog-fill]');
  var tick = false;
  function measure() {
    tick = false;
    var max = main.scrollHeight - main.clientHeight;
    var p = max > 0 ? Math.min(1, Math.max(0, main.scrollTop / max)) : 0;
    if (fill) fill.style.transform = 'scaleX(' + p.toFixed(3) + ')';
    if (bar) bar.setAttribute('aria-valuenow', String(Math.round(p * 100)));
  }
  main.addEventListener('scroll', function () { if (!tick) { tick = true; requestAnimationFrame(measure); } }, { passive: true });
  measure();

  var btn = root.querySelector('[data-blog-listen]');
  if (!btn) return;
  var label = btn.querySelector('[data-blog-listen-label]');
  var src = btn.getAttribute('data-audio');
  function show(state) {   // idle · playing · paused
    btn.setAttribute('aria-pressed', state === 'playing' ? 'true' : 'false');
    if (label) label.textContent = btn.getAttribute(state === 'playing' ? 'data-pause' : state === 'paused' ? 'data-resume' : 'data-listen');
  }

  if (src) {
    var player = document.querySelector('[data-blog-player]');
    var pp = player && player.querySelector('[data-blog-pp]');
    var time = player && player.querySelector('[data-blog-time]');
    var pfill = player && player.querySelector('[data-blog-pfill]');
    var speed = player && player.querySelector('[data-blog-speed]');
    var audio = new Audio();
    audio.preload = 'none';
    audio.src = src;
    var SPEEDS = [1, 1.25, 1.5, 0.75], sp = 0;
    var mm = function (s) { s = Math.max(0, Math.floor(s || 0)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
    var sync = function () {
      var on = !audio.paused;
      show(on ? 'playing' : (audio.currentTime > 0 ? 'paused' : 'idle'));
      if (pp) { pp.classList.toggle('is-playing', on); pp.setAttribute('aria-label', pp.getAttribute(on ? 'data-pause' : 'data-play')); }
      if (time) time.textContent = mm(audio.currentTime) + (isFinite(audio.duration) ? ' / ' + mm(audio.duration) : '');
      if (pfill && isFinite(audio.duration) && audio.duration > 0) pfill.style.transform = 'scaleX(' + (audio.currentTime / audio.duration).toFixed(3) + ')';
    };
    ['play', 'pause', 'timeupdate', 'loadedmetadata', 'ended'].forEach(function (ev) { audio.addEventListener(ev, sync); });
    var toggle = function () {
      if (player) player.hidden = false;
      if (audio.paused) audio.play().catch(function () { sync(); }); else audio.pause();
    };
    btn.hidden = false;
    btn.addEventListener('click', toggle);
    if (pp) pp.addEventListener('click', toggle);
    if (speed) speed.addEventListener('click', function () {
      sp = (sp + 1) % SPEEDS.length; audio.playbackRate = SPEEDS[sp]; speed.textContent = String(SPEEDS[sp]).replace(/^0\./, '0.') + '×';
    });
    var x = player && player.querySelector('[data-blog-close]');
    if (x) x.addEventListener('click', function () { audio.pause(); player.hidden = true; btn.focus(); });
    return;
  }

  var synth = window.speechSynthesis;
  if (!synth || typeof window.SpeechSynthesisUtterance !== 'function') return;
  btn.hidden = false;
  var state = 'idle';
  btn.addEventListener('click', function () {
    if (state === 'playing') { synth.pause(); state = 'paused'; show(state); return; }
    if (state === 'paused') { synth.resume(); state = 'playing'; show(state); return; }
    synth.cancel();
    var parts = [];
    var h = root.querySelector('.bp__h1'); if (h) parts.push(h.textContent);
    var read = root.querySelector('[data-blog-read]');
    if (read) read.querySelectorAll('h2, h3, p, li, blockquote').forEach(function (el) {
      if (el.closest('li') && el.tagName !== 'LI') return;
      var t = el.textContent.replace(/\s+/g, ' ').trim(); if (t) parts.push(t);
    });
    var lang = document.documentElement.getAttribute('lang') || 'en';
    parts.forEach(function (t, i) {
      var u = new window.SpeechSynthesisUtterance(t); u.lang = lang;
      if (i === parts.length - 1) u.onend = function () { state = 'idle'; show(state); };
      u.onerror = function () { state = 'idle'; show(state); };
      synth.speak(u);
    });
    state = 'playing'; show(state);
  });
  window.addEventListener('pagehide', function () { synth.cancel(); });
})();
