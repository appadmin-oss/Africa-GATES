/* The 9:16 share video (handoff 16 Oct 2026): Hook → Story → Proof → Action, ~18.5s.
 *
 * Drawn on a 1080×1920 canvas from the record's own story (Services\ShareVideo) and saved
 * with MediaRecorder, so the file is a real video made in the visitor's browser: this host
 * has no headless browser to render one. Colours are read from the palette's own custom
 * properties (Support\Accent), never typed here. The Africa GATES lockup is small at the
 * top left throughout and centred at the end, as the design specifies.
 *
 * A figure the server left out (LOW-DATA-RULES) is simply not drawn: with no proof, the
 * proof beat becomes more story rather than a zero.
 */
(function () {
  'use strict';

  var cv = document.querySelector('[data-sv-canvas]');
  if (!cv || !cv.getContext) return;
  var S;
  try { S = JSON.parse(cv.getAttribute('data-story') || '{}'); } catch (e) { return; }
  var ctx = cv.getContext('2d');
  var W = 1080, H = 1920, DUR = 18.5;
  var CUE = { story: 4.5, proof: 9.5, action: 13.5, outro: 17.3 };

  var css = getComputedStyle(document.documentElement);
  var tok = function (n) { return css.getPropertyValue('--ag-' + n).trim(); };
  var C = { ink: tok('ink'), soft: tok('soft'), ground: tok('ground'), surface: tok('surface'), line: tok('line'),
            green: tok('green'), greenWash: tok('green-wash'), gold: tok('gold'), goldInk: tok('gold-ink'),
            goldWash: tok('gold-wash'), info: tok('info'), infoWash: tok('info-wash') };
  var ACC = S.kind === 'award' ? { fill: C.goldInk, wash: C.goldWash, ring: C.gold }
          : S.kind === 'event' ? { fill: C.info, wash: C.infoWash, ring: C.info }
          : { fill: C.green, wash: C.greenWash, ring: C.green };
  var SERIF = "700 {s}px 'Playfair Display', Georgia, serif";
  var SANS = "{w} {s}px 'DM Sans', system-ui, sans-serif";
  var font = function (tpl, s, w) { return tpl.replace('{s}', s).replace('{w}', w || 700); };

  // ── helpers ───────────────────────────────────────────────────────────────
  var clamp = function (x) { return x < 0 ? 0 : x > 1 ? 1 : x; };
  var ease = function (x) { x = clamp(x); return 1 - Math.pow(1 - x, 3); };
  var inout = function (x) { x = clamp(x); return x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; };
  var span = function (t, a, b) { return clamp((t - a) / (b - a)); };
  var L = function (a, b, p) { return a + (b - a) * p; };

  function rr(x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function wrap(text, x, y, maxW, lh, maxLines) {
    var words = String(text || '').split(/\s+/), line = '', lines = [];
    for (var i = 0; i < words.length; i++) {
      var test = line ? line + ' ' + words[i] : words[i];
      if (ctx.measureText(test).width > maxW && line) { lines.push(line); line = words[i]; } else { line = test; }
    }
    if (line) lines.push(line);
    if (maxLines && lines.length > maxLines) { lines = lines.slice(0, maxLines); lines[maxLines - 1] += '…'; }
    for (var j = 0; j < lines.length; j++) ctx.fillText(lines[j], x, y + j * lh);
    return lines.length * lh;
  }

  var photo = null;
  if (S.photo) {
    var im = new Image(); im.crossOrigin = 'anonymous';
    im.onload = function () { photo = im; draw(posterAt); };
    im.src = S.photo;
  }
  function cover(x, y, w, h, zoom) {
    if (photo) {
      var r = Math.max(w / photo.width, h / photo.height) * (zoom || 1);
      var pw = photo.width * r, ph = photo.height * r;
      ctx.drawImage(photo, x + (w - pw) / 2, y + (h - ph) / 2, pw, ph);
    } else {
      // No photo: the record's own colour field and its initials — DefaultCover's rule.
      ctx.fillStyle = ACC.wash; ctx.fillRect(x, y, w, h);
      ctx.fillStyle = ACC.fill; ctx.globalAlpha = .9; ctx.font = font(SERIF, Math.round(Math.min(w, h) * .32));
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      var ini = String(S.name || S.title || 'A').split(/\s+/).map(function (p) { return p.charAt(0); }).join('').slice(0, 2).toUpperCase();
      ctx.fillText(ini, x + w / 2, y + h / 2); ctx.globalAlpha = 1; ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    }
  }
  function lockup(t) {
    var centre = ease(span(t, CUE.outro, CUE.outro + .8));
    var x = L(64, W / 2 - 150, centre), y = L(84, H - 260, centre), s = L(1, 1.6, centre);
    ctx.save(); ctx.translate(x, y); ctx.scale(s, s);
    ctx.fillStyle = C.surface; rr(0, 0, 56, 56, 14); ctx.fill();
    ctx.fillStyle = C.green; ctx.font = font(SERIF, 30); ctx.fillText('A', 16, 40);
    ctx.fillStyle = t < CUE.story ? C.surface : C.ink; ctx.font = font(SANS, 30, 700); ctx.fillText('Africa GATES', 72, 38);
    ctx.restore();
  }
  function chip(text, x, y, fg, bg) {
    ctx.font = font(SANS, 30, 700);
    var w = ctx.measureText(text).width + 70;
    ctx.fillStyle = bg; rr(x, y, w, 64, 32); ctx.fill();
    ctx.fillStyle = fg; ctx.beginPath(); ctx.arc(x + 30, y + 32, 7, 0, Math.PI * 2); ctx.fill();
    ctx.fillText(text, x + 48, y + 43);
  }
  function verified(x, y) {
    ctx.fillStyle = C.green; ctx.beginPath(); ctx.arc(x + 18, y + 18, 18, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = C.surface; ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.beginPath(); ctx.moveTo(x + 10, y + 19); ctx.lineTo(x + 16, y + 25); ctx.lineTo(x + 27, y + 12); ctx.stroke();
  }
  function button(label, y, t, at) {
    var p = ease(span(t, at, at + .5)), tap = span(t, at + 1.4, at + 1.6), up = span(t, at + 1.6, at + 1.8);
    var s = 1 - .05 * tap * (1 - up);
    ctx.save(); ctx.globalAlpha = p; ctx.translate(W / 2, y + 60); ctx.scale(s, s);
    ctx.fillStyle = C.green; rr(-300, -60, 600, 120, 60); ctx.fill();
    ctx.fillStyle = C.surface; ctx.font = font(SANS, 44, 700); ctx.textAlign = 'center'; ctx.fillText(label, 0, 16);
    ctx.restore(); ctx.textAlign = 'left';
  }

  // ── the beats ─────────────────────────────────────────────────────────────
  function hook(t) {
    var p = span(t, 0, CUE.story);
    cover(0, 0, W, H, L(1.08, 1, p));
    var g = ctx.createLinearGradient(0, H * .35, 0, H);
    g.addColorStop(0, 'transparent'); g.addColorStop(1, C.ink);
    ctx.fillStyle = g; ctx.globalAlpha = .92; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1;
    ctx.globalAlpha = ease(span(t, .4, 1)); chip(S.kicker, 72, H - 620, ACC.fill, ACC.wash); ctx.globalAlpha = 1;
    var r = ease(span(t, .8, 1.6));
    ctx.globalAlpha = r; ctx.fillStyle = C.surface; ctx.font = font(SERIF, 84);
    wrap(S.hook, 72, H - 470 + (1 - r) * 40, W - 144, 100, 4); ctx.globalAlpha = 1;
  }
  function card(t, h) {
    var p = inout(span(t, CUE.story, CUE.story + .9));
    var x = L(0, 72, p), y = L(0, 180, p), w = L(W, W - 144, p), hh = L(H, h, p);
    ctx.save(); rr(x, y, w, hh, L(0, 40, p)); ctx.clip(); cover(x, y, w, hh, 1); ctx.restore();
    return y + hh;
  }
  function storyText(t, top) {
    var a = ease(span(t, CUE.story + .6, CUE.story + 1.3));
    ctx.globalAlpha = a;
    chip(S.chip, 72, top + 56, ACC.fill, ACC.wash);
    ctx.fillStyle = C.ink; ctx.font = font(SERIF, 70);
    var used = wrap(S.title, 72, top + 210, W - 144, 82, 3);
    verified(72, top + 250 + used); ctx.fillStyle = C.ink; ctx.font = font(SANS, 34, 700);
    ctx.fillText(S.org, 124, top + 280 + used);
    ctx.fillStyle = C.soft; ctx.font = font(SANS, 30, 500); ctx.fillText(S.orgType, 124, top + 322 + used);
    ctx.globalAlpha = 1;
    return top + 360 + used;
  }
  function proof(t, top) {
    var a = ease(span(t, CUE.proof, CUE.proof + .5));
    if (!S.proof) {
      if (S.sub) { ctx.globalAlpha = a; ctx.fillStyle = C.ink; ctx.font = font(SANS, 40, 600); wrap(S.sub, 72, top + 80, W - 144, 52, 2); ctx.globalAlpha = 1; }
      return;
    }
    var P = S.proof; ctx.globalAlpha = a;
    if (P.type === 'giving') {
      var f = inout(span(t, CUE.proof + .4, CUE.proof + 2.4)) * P.pct / 100, cx = 200, cy = top + 160, R = 110;
      ctx.lineWidth = 26; ctx.strokeStyle = C.line; ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.stroke();
      ctx.strokeStyle = C.green; ctx.lineCap = 'round'; ctx.beginPath(); ctx.arc(cx, cy, R, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * f); ctx.stroke();
      ctx.fillStyle = C.ink; ctx.font = font(SANS, 48, 700); ctx.textAlign = 'center'; ctx.fillText(Math.round(f * 100) + '%', cx, cy + 16); ctx.textAlign = 'left';
      ctx.font = font(SANS, 54, 700); ctx.fillText(P.raised + ' raised', 360, top + 140);
      ctx.fillStyle = C.soft; ctx.font = font(SANS, 34, 500);
      var meta = 'of ' + P.goal + (P.gifts ? ' · ' + P.gifts.toLocaleString() + ' gifts' : '') + (P.days !== null && P.days !== undefined ? ' · ' + P.days + (P.days === 1 ? ' day left' : ' days left') : '');
      wrap(meta, 360, top + 200, W - 432, 44, 2);
    } else if (P.type === 'votes') {
      var n = Math.round(inout(span(t, CUE.proof + .3, CUE.proof + 2.6)) * P.votes);
      ctx.fillStyle = ACC.fill; ctx.font = font(SERIF, 150); ctx.fillText(n.toLocaleString(), 72, top + 190);
      ctx.fillStyle = C.ink; ctx.font = font(SANS, 40, 600); ctx.fillText('votes so far', 72, top + 260);
    } else if (P.type === 'event') {
      ctx.fillStyle = C.ink; ctx.font = font(SANS, 40, 600); ctx.fillText('Tickets from', 72, top + 90);
      ctx.fillStyle = ACC.fill; ctx.font = font(SERIF, 130); ctx.fillText(P.from, 72, top + 230);
      if (S.sub) { ctx.fillStyle = C.soft; ctx.font = font(SANS, 34, 500); wrap(S.sub, 72, top + 300, W - 144, 44, 2); }
    }
    ctx.globalAlpha = 1;
  }
  function action(t) {
    var a = ease(span(t, CUE.action, CUE.action + .6));
    ctx.globalAlpha = a; ctx.fillStyle = C.ground; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1;
    if (a < .05) return;
    ctx.globalAlpha = a;
    var top = 360;
    if (S.kind === 'award') {
      var cx = W / 2, cy = top + 230, R = 210, ring = inout(span(t, CUE.action + .4, CUE.action + 1.6));
      ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.clip(); cover(cx - R, cy - R, R * 2, R * 2, 1); ctx.restore();
      ctx.lineWidth = 14; ctx.strokeStyle = ACC.ring; ctx.lineCap = 'round';
      ctx.beginPath(); ctx.arc(cx, cy, R + 18, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * ring); ctx.stroke();
      ctx.textAlign = 'center'; ctx.fillStyle = C.ink; ctx.font = font(SERIF, 68);
      wrap('Vote for ' + S.name, cx, cy + R + 140, W - 160, 80, 2);
      ctx.fillStyle = C.soft; ctx.font = font(SANS, 36, 600); ctx.fillText(S.title, cx, cy + R + 320); ctx.fillText(S.org, cx, cy + R + 370);
    } else if (S.kind === 'giving') {
      ctx.save(); rr(120, top, W - 240, 560, 36); ctx.clip(); cover(120, top, W - 240, 560, 1); ctx.restore();
      ctx.fillStyle = C.ink; ctx.font = font(SERIF, 60); var used = wrap(S.title, 120, top + 660, W - 240, 72, 3);
      if (S.proof) {
        var f = inout(span(t, CUE.action + .5, CUE.action + 1.8)) * S.proof.pct / 100;
        ctx.fillStyle = C.line; rr(120, top + 700 + used, W - 240, 18, 9); ctx.fill();
        ctx.fillStyle = C.green; rr(120, top + 700 + used, Math.max(18, (W - 240) * f), 18, 9); ctx.fill();
        ctx.fillStyle = C.soft; ctx.font = font(SANS, 34, 600); ctx.fillText(S.proof.raised + ' raised of ' + S.proof.goal, 120, top + 780 + used);
      }
    } else {
      ctx.fillStyle = C.surface; rr(120, top, W - 240, 900, 36); ctx.fill();
      ctx.save(); rr(120, top, W - 240, 440, 36); ctx.clip(); cover(120, top, W - 240, 440, 1); ctx.restore();
      ctx.fillStyle = C.ground; ctx.beginPath(); ctx.arc(120, top + 600, 30, 0, Math.PI * 2); ctx.arc(W - 120, top + 600, 30, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = C.line; ctx.setLineDash([16, 14]); ctx.lineWidth = 3; ctx.beginPath(); ctx.moveTo(170, top + 600); ctx.lineTo(W - 170, top + 600); ctx.stroke(); ctx.setLineDash([]);
      ctx.fillStyle = C.ink; ctx.font = font(SERIF, 56); wrap(S.title, 170, top + 520, W - 340, 64, 1);
      ctx.fillStyle = C.soft; ctx.font = font(SANS, 32, 600); if (S.sub) wrap(S.sub, 170, top + 700, W - 340, 42, 2);
      if (S.proof) { ctx.fillStyle = C.ink; ctx.font = font(SANS, 40, 700); ctx.fillText('From ' + S.proof.from, 170, top + 820); }
    }
    ctx.textAlign = 'left'; ctx.globalAlpha = 1;
    button(S.cta, H - 520, t, CUE.action + .8);
    ctx.globalAlpha = a; ctx.fillStyle = C.soft; ctx.font = font(SANS, 30, 500); ctx.textAlign = 'center';
    ctx.fillText(String(S.url || '').replace(/^https?:\/\//, ''), W / 2, H - 340); ctx.textAlign = 'left'; ctx.globalAlpha = 1;
  }

  function draw(t) {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = C.ground; ctx.fillRect(0, 0, W, H);
    if (t < CUE.story + .9) hook(t);
    if (t >= CUE.story) {
      var bottom = card(t, 720);
      var after = storyText(t, bottom);
      if (t >= CUE.proof) proof(t, after);
    }
    if (t >= CUE.action) action(t);
    lockup(t);
  }

  // ── play, save the video, save a picture ─────────────────────────────────
  var posterAt = 6.2, raf = 0;
  var status = document.querySelector('[data-sv-status]');
  var dl = document.querySelector('[data-sv-dl]');
  var say = function (m) { if (status) status.textContent = m; };

  function run(onEnd) {
    cancelAnimationFrame(raf);
    var t0 = performance.now();
    var step = function (now) {
      var t = (now - t0) / 1000;
      draw(Math.min(t, DUR));
      if (t < DUR) raf = requestAnimationFrame(step); else if (onEnd) onEnd();
    };
    raf = requestAnimationFrame(step);
  }

  var fontsReady = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
  fontsReady.then(function () { draw(posterAt); });

  var play = document.querySelector('[data-sv-play]');
  if (play) play.addEventListener('click', function () { run(null); });

  var make = document.querySelector('[data-sv-make]');
  if (make) make.addEventListener('click', function () {
    if (!cv.captureStream || typeof MediaRecorder === 'undefined') { say('This browser cannot record video. Use “Save a picture” instead.'); return; }
    var types = ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
    var type = types.filter(function (x) { return MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(x); })[0] || '';
    var stream, rec, chunks = [];
    try { stream = cv.captureStream(30); rec = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: 6000000 } : undefined); }
    catch (e) { say('The photo on this record cannot be recorded from here. Use “Save a picture” instead.'); return; }
    rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
    rec.onstop = function () {
      var blob = new Blob(chunks, { type: type || 'video/webm' });
      var ext = /mp4/.test(type) ? 'mp4' : 'webm';
      dl.href = URL.createObjectURL(blob); dl.download = 'africa-gates-' + S.kind + '.' + ext;
      dl.textContent = 'Download the video (' + ext + ')'; dl.hidden = false; dl.click();
      say('Your video is ready.'); make.disabled = false;
    };
    make.disabled = true; dl.hidden = true; say('Recording… about 19 seconds.');
    rec.start(250);
    run(function () { setTimeout(function () { rec.stop(); }, 200); });
  });

  var still = document.querySelector('[data-sv-still]');
  if (still) still.addEventListener('click', function () {
    draw(DUR - 0.2);
    try {
      cv.toBlob(function (b) {
        if (!b) { say('That picture could not be made here.'); return; }
        dl.href = URL.createObjectURL(b); dl.download = 'africa-gates-' + S.kind + '.png';
        dl.textContent = 'Download the picture'; dl.hidden = false; dl.click(); say('Your picture is ready.');
      }, 'image/png');
    } catch (e) { say('The photo on this record cannot be saved from here.'); }
  });
})();
