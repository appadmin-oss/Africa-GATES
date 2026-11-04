/* The nominee's 9:16 share video, drawn to match the share card (Services\ShareCardSpec).
 *
 * Four beats, 15s: Hook (the face, the name) → Card (the same layout as the Status card)
 * → Proof (the real standing, or the nominee's own line when there is none) → Action
 * (vote for them, the link). Tones are the site's five cover tones; the award picks the
 * default and the nominee may switch, exactly as on the card.
 *
 * Pure: SV2.draw(ctx, t, S, assets) paints frame t (seconds) of story S. No DOM, no
 * timers — so a browser can record it with MediaRecorder and a headless run can render
 * it frame by frame, and both produce the same pictures.
 */
(function (root) {
  'use strict';
  var W = 1080, H = 1920, DUR = 15;
  var CUE = { card: 3.6, cardIn: 4.6, proof: 8.0, action: 11.2 };
  var SAFE_TOP = 180, SAFE_BOTTOM = 220;

  // The colours arrive with the story (ShareCardSpec::videoPalette, from Accent): this
  // script types no palette of its own, so the video cannot drift from the card.
  var INK, MUTED, WHITE, SUB, SCRIM;
  var SERIF = "'Playfair Display', Georgia, serif", SANS = "'DM Sans', system-ui, sans-serif";

  var clamp = function (x) { return x < 0 ? 0 : x > 1 ? 1 : x; };
  var out3 = function (x) { x = clamp(x); return 1 - Math.pow(1 - x, 3); };
  var io3 = function (x) { x = clamp(x); return x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; };
  var back = function (x) { x = clamp(x); var c = 1.6; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
  var span = function (t, a, b) { return clamp((t - a) / (b - a)); };
  var L = function (a, b, p) { return a + (b - a) * p; };
  function rgba(hex, a) { var n = parseInt(hex.slice(1), 16); return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')'; }
  function mix(a, b, p) {
    var x = parseInt(a.slice(1), 16), y = parseInt(b.slice(1), 16);
    var r = Math.round(L(x >> 16 & 255, y >> 16 & 255, p)), g = Math.round(L(x >> 8 & 255, y >> 8 & 255, p)), bl = Math.round(L(x & 255, y & 255, p));
    return 'rgb(' + r + ',' + g + ',' + bl + ')';
  }

  function draw(ctx, t, S, A) {
    var T = S.palette || {};
    INK = T.text; MUTED = T.muted; WHITE = T.white; SUB = T.sub; SCRIM = T.scrim;
    var f = function (w, s, fam) { ctx.font = w + ' ' + s + 'px ' + fam; };

    function rr(x, y, w, h, r) {
      r = Math.min(r, w / 2, h / 2);
      ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
    }
    function lines(text, maxW, max) {
      var words = String(text || '').split(/\s+/), cur = '', out = [];
      for (var i = 0; i < words.length; i++) {
        var test = cur ? cur + ' ' + words[i] : words[i];
        if (ctx.measureText(test).width > maxW && cur) { out.push(cur); cur = words[i]; } else cur = test;
      }
      if (cur) out.push(cur);
      if (max && out.length > max) { out = out.slice(0, max); out[max - 1] += '…'; }
      return out;
    }
    // Text that rises into place from behind a mask, one line at a time.
    function reveal(ls, x, y, lh, t0, stagger, align) {
      ctx.textAlign = align || 'left';
      for (var i = 0; i < ls.length; i++) {
        var p = out3(span(t, t0 + i * stagger, t0 + i * stagger + .7));
        if (p <= 0) continue;
        ctx.save(); ctx.beginPath(); ctx.rect(0, y + i * lh - lh * .95, W, lh * 1.25); ctx.clip();
        ctx.globalAlpha *= p; ctx.fillText(ls[i], x, y + i * lh + (1 - p) * lh * .8); ctx.restore();
      }
      ctx.textAlign = 'left';
      return ls.length * lh;
    }
    function nameSize(base) {
      var bare = String(S.name).normalize('NFD').replace(/[\u0300-\u036f]/g, '').length;
      return Math.round(base * (bare <= 14 ? 1 : bare <= 20 ? .88 : bare <= 28 ? .76 : bare <= 36 ? .66 : .58));
    }
    function initials() {
      var w = String(S.name).trim().split(/\s+/).filter(function (x) { return /^\p{L}/u.test(x); });
      return (w.length === 0 ? '?' : w.length === 1 ? w[0].charAt(0) : w[0].charAt(0) + w[w.length - 1].charAt(0)).toUpperCase();
    }
    // The photo, or the tone pattern and initials when there is none (the card's own rule).
    function picture(x, y, w, h, zoom, light, focus) {
      ctx.save(); ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
      if (A.photo) {
        var r = Math.max(w / A.photo.width, h / A.photo.height) * (zoom || 1);
        var pw = A.photo.width * r, ph = A.photo.height * r;
        ctx.drawImage(A.photo, x + (w - pw) / 2, y + (h - ph) * (focus === undefined ? .3 : focus), pw, ph);
      } else {
        ctx.fillStyle = light ? T.edge : mix(T.deep, WHITE, .06); ctx.fillRect(x, y, w, h);
        var cx = x + w / 2, cy = y + Math.min(h * .56, 760);
        ctx.strokeStyle = rgba(light ? T.line : T.label, light ? .32 : .26); ctx.lineWidth = 2;
        for (var k = 40; k < Math.max(w, h); k += 40) { ctx.beginPath(); ctx.arc(cx, cy, k * (zoom || 1), 0, Math.PI * 2); ctx.stroke(); }
        var R = Math.min(w, h, 1100) * .16;
        ctx.fillStyle = light ? WHITE : rgba(WHITE, .1); ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = light ? T.ink : T.label; f(700, Math.round(R * .78), SERIF); ctx.textAlign = 'center';
        ctx.fillText(initials(), cx, cy + R * .28); ctx.textAlign = 'left';
      }
      ctx.restore();
    }
    function lockup(x, y, colour, alpha, centre) {
      ctx.save(); ctx.globalAlpha *= alpha;
      f(700, 22, SANS); if ('letterSpacing' in ctx) ctx.letterSpacing = '3px';
      var label = String(S.award).toUpperCase(), tw = ctx.measureText(label).width;
      var x0 = centre ? x - (64 + 16 + tw) / 2 : x;
      ctx.fillStyle = WHITE; ctx.beginPath(); ctx.arc(x0 + 32, y + 32, 32, 0, Math.PI * 2); ctx.fill();
      if (A.logo) { var lh = 46, lw = A.logo.width * lh / A.logo.height; ctx.drawImage(A.logo, x0 + 32 - lw / 2, y + 32 - lh / 2, lw, lh); }
      ctx.fillStyle = colour; ctx.fillText(label, x0 + 80, y + 40);
      if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
      ctx.restore();
    }
    function chip(x, y, s, a) {
      if (!(S.field >= 2)) return;
      ctx.save(); ctx.globalAlpha *= a; ctx.translate(x, y); ctx.scale(s, s);
      f(700, 40, SERIF); var n = '#' + S.rank, nw = ctx.measureText(n).width;
      f(600, 24, SANS); var o = 'of ' + S.field, ow = ctx.measureText(o).width;
      var w = 44 + nw + 10 + ow;
      ctx.fillStyle = WHITE; rr(-w, 0, w, 72, 36); ctx.fill();
      ctx.fillStyle = INK; f(700, 40, SERIF); ctx.fillText(n, -w + 22, 50);
      ctx.fillStyle = SUB; f(600, 24, SANS); ctx.fillText(o, -w + 22 + nw + 10, 50);
      ctx.restore();
    }
    var standing = S.field >= 2 ? (S.rank === 1 ? 'Leading the field' : S.gap + ' vote' + (S.gap === 1 ? '' : 's') + ' from #' + (S.rank - 1)) : '';
    var pad = 80;

    // ── ground ──
    ctx.fillStyle = T.bg; ctx.fillRect(0, 0, W, H);

    // ── panel geometry across the beats ──
    var toCard = io3(span(t, CUE.card, CUE.cardIn));
    var toProof = io3(span(t, CUE.proof, CUE.proof + .8));
    var panelH = L(H, 1150, toCard);
    panelH = L(panelH, 760, toProof);
    var zoom = L(1.12, 1, out3(span(t, 0, CUE.card + .8)));

    if (t < CUE.action + .8) {
      picture(0, 0, W, panelH, zoom, true);
      // Hook scrim, fading out as the panel becomes a card.
      var hookA = 1 - toCard;
      if (hookA > 0 && A.photo) {
        var g = ctx.createLinearGradient(0, H * .38, 0, H);
        g.addColorStop(0, rgba(SCRIM, 0)); g.addColorStop(.55, rgba(SCRIM, .62)); g.addColorStop(1, rgba(SCRIM, .9));
        ctx.globalAlpha = hookA; ctx.fillStyle = g; ctx.fillRect(0, 0, W, panelH); ctx.globalAlpha = 1;
      }
      var top = ctx.createLinearGradient(0, 0, 0, 420);
      top.addColorStop(0, rgba(SCRIM, A.photo ? .6 : 0)); top.addColorStop(1, rgba(SCRIM, 0));
      ctx.fillStyle = top; ctx.fillRect(0, 0, W, Math.min(420, panelH));
      lockup(pad, SAFE_TOP, A.photo ? WHITE : T.ink, out3(span(t, .2, .8)), false);

      // HOOK text — the category, then the name, rising from the dark.
      if (hookA > 0) {
        ctx.save(); ctx.globalAlpha = hookA;
        var ns = nameSize(118); f(700, ns, SERIF); var nl = lines(S.name, W - pad * 2, 3);
        var nameTop = H - SAFE_BOTTOM - 120 - (nl.length - 1) * ns * 1.04;
        ctx.fillStyle = A.photo ? T.label : T.ink; f(700, 26, SANS); if ('letterSpacing' in ctx) ctx.letterSpacing = '4px';
        ctx.globalAlpha = hookA * out3(span(t, .6, 1.1));
        ctx.fillText(('Nominated · ' + S.category).toUpperCase(), pad, nameTop - ns * .95 - 28);
        if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
        ctx.globalAlpha = hookA; ctx.fillStyle = A.photo ? WHITE : INK; f(700, ns, SERIF);
        reveal(nl, pad, nameTop, ns * 1.04, .9, .18);
        ctx.fillStyle = A.photo ? rgba(WHITE, .86) : MUTED; f(400, 32, SANS); ctx.globalAlpha = hookA * out3(span(t, 1.8, 2.4));
        ctx.fillText(S.award, pad, nameTop + (nl.length - 1) * ns * 1.04 + 70);
        ctx.restore();
      }

      // CARD — the Status card's own layout, built in front of you.
      var cardA = out3(span(t, CUE.card + .5, CUE.cardIn + .3));
      if (cardA > 0 && t < CUE.action + .8) {
        chip(W - pad, SAFE_TOP - 4, L(.8, 1, back(span(t, CUE.cardIn, CUE.cardIn + .5))), out3(span(t, CUE.cardIn, CUE.cardIn + .3)));
        var y = panelH + 96;
        ctx.save(); ctx.globalAlpha = cardA;
        ctx.fillStyle = T.ink; f(700, 24, SANS); if ('letterSpacing' in ctx) ctx.letterSpacing = '3px';
        ctx.fillText(String(S.category).toUpperCase(), pad, y);
        if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
        var ns2 = nameSize(100); f(700, ns2, SERIF); ctx.fillStyle = INK;
        var nl2 = lines(S.name, W - pad * 2, 2);
        var used = reveal(nl2, pad, y + 30 + ns2 * .9, ns2 * 1.04, CUE.card + .7, .12);
        var my = y + 30 + used + 40;
        f(400, 30, SANS); ctx.fillStyle = MUTED;
        var meta = S.org || '';
        if (meta) ctx.fillText(meta, pad, my);
        if (standing) {
          var sx = meta ? pad + ctx.measureText(meta).width + 22 : pad;
          if (meta) { ctx.fillStyle = rgba(INK, .3); ctx.beginPath(); ctx.arc(sx - 4, my - 10, 4, 0, Math.PI * 2); ctx.fill(); sx += 14; }
          ctx.fillStyle = T.ink; f(700, 30, SANS); ctx.fillText(standing, sx, my);
        }
        ctx.restore();

        // The card's footer: the ask and the link, present from the card beat on.
        var fy = H - SAFE_BOTTOM - 120;
        ctx.save(); ctx.globalAlpha = out3(span(t, CUE.cardIn, CUE.cardIn + .6)) * (1 - out3(span(t, CUE.action, CUE.action + .6)));
        ctx.strokeStyle = rgba(INK, .16); ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(pad, fy - 40); ctx.lineTo(W - pad, fy - 40); ctx.stroke();
        ctx.fillStyle = T.ink; f(700, 20, SANS); if ('letterSpacing' in ctx) ctx.letterSpacing = '3px'; ctx.fillText('VOTE NOW', pad, fy);
        if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
        ctx.fillStyle = INK; f(700, 40, SANS); ctx.fillText(S.display || S.url, pad, fy + 54);
        ctx.fillStyle = MUTED; f(400, 20, SANS); ctx.fillText('Public votes are one part of the score. An independent jury decides the award.', pad, fy + 92);
        ctx.restore();

        // PROOF — the real standing, told with one number and one bar.
        var pA = out3(span(t, CUE.proof + .5, CUE.proof + 1.1));
        if (pA > 0) {
          var py = my + 110;
          ctx.save(); ctx.globalAlpha = pA * (1 - out3(span(t, CUE.action, CUE.action + .6)));
          ctx.strokeStyle = rgba(INK, .14); ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(pad, py - 60); ctx.lineTo(W - pad, py - 60); ctx.stroke();
          if (S.field >= 2) {
            var shown = Math.max(1, Math.round(L(S.field, S.rank, io3(span(t, CUE.proof + .6, CUE.proof + 1.8)))));
            ctx.fillStyle = T.ink; f(700, 220, SERIF); ctx.fillText('#' + shown, pad - 6, py + 170);
            var bw = ctx.measureText('#' + shown).width;
            f(600, 34, SANS); ctx.fillStyle = INK; ctx.fillText('of ' + S.field + ' in', pad + bw + 30, py + 98);
            ctx.fillText(S.category, pad + bw + 30, py + 144);
            // The gap to the place above, as a bar that fills.
            var by = py + 250, bwid = W - pad * 2;
            ctx.fillStyle = rgba(INK, .1); rr(pad, by, bwid, 20, 10); ctx.fill();
            var share = S.rank === 1 ? 1 : Math.max(.08, Math.min(.94, S.votes / (S.votes + S.gap)));
            ctx.fillStyle = T.line; rr(pad, by, Math.max(20, bwid * share * io3(span(t, CUE.proof + 1.2, CUE.proof + 2.4))), 20, 10); ctx.fill();
            f(700, 30, SANS); ctx.fillStyle = INK;
            ctx.fillText(S.rank === 1 ? 'Out in front. Help keep it that way.' : 'Just ' + S.gap + ' vote' + (S.gap === 1 ? '' : 's') + ' to move up to #' + (S.rank - 1) + '.', pad, by + 76);
          } else if (S.rally) {
            ctx.fillStyle = INK; f(700, 58, SERIF);
            reveal(lines('“' + S.rally + '”', W - pad * 2, 4), pad, py + 40, 76, CUE.proof + .5, .14);
          }
          ctx.restore();
        }
      }
    }

    // ACTION — the deep ground, the face in a ring, the ask, the link.
    var aA = io3(span(t, CUE.action, CUE.action + .8));
    if (aA > 0) {
      ctx.save(); ctx.globalAlpha = aA; ctx.fillStyle = T.deep; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = rgba(T.label, .14); ctx.lineWidth = 2;
      for (var k = 120; k < 1400; k += 64) { ctx.beginPath(); ctx.arc(W, 0, k, 0, Math.PI * 2); ctx.stroke(); }
      lockup(W / 2, SAFE_TOP, T.label, 1, true);
      var cx = W / 2, cy = 640, R = 190;
      var pin = out3(span(t, CUE.action + .3, CUE.action + 1));
      ctx.save(); ctx.translate(cx, cy); ctx.scale(L(.86, 1, pin), L(.86, 1, pin)); ctx.translate(-cx, -cy);
      ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.clip(); if (A.photo) picture(cx - R, cy - R, R * 2, R * 2, 1.9, false, .12);
      else { ctx.fillStyle = rgba(WHITE, .1); ctx.fillRect(cx - R, cy - R, R * 2, R * 2); ctx.fillStyle = T.label; f(700, 150, SERIF); ctx.textAlign = 'center'; ctx.fillText(initials(), cx, cy + 52); ctx.textAlign = 'left'; }
      ctx.restore();
      var ring = io3(span(t, CUE.action + .6, CUE.action + 1.8));
      ctx.strokeStyle = T.label; ctx.lineWidth = 8; ctx.lineCap = 'round';
      ctx.beginPath(); ctx.arc(cx, cy, R + 22, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * ring); ctx.stroke();

      ctx.fillStyle = T.label; f(700, 26, SANS); ctx.textAlign = 'center'; if ('letterSpacing' in ctx) ctx.letterSpacing = '5px';
      ctx.globalAlpha = aA * out3(span(t, CUE.action + .8, CUE.action + 1.2)); ctx.fillText('VOTE FOR', cx, cy + R + 120);
      if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
      ctx.globalAlpha = aA; ctx.fillStyle = WHITE; var ns3 = nameSize(96); f(700, ns3, SERIF);
      var nl3 = lines(S.name, W - 200, 2);
      var u3 = reveal(nl3, cx, cy + R + 140 + ns3 * .9, ns3 * 1.04, CUE.action + .9, .14, 'center');
      ctx.textAlign = 'center'; ctx.fillStyle = rgba(WHITE, .72); f(400, 32, SANS);
      ctx.globalAlpha = aA * out3(span(t, CUE.action + 1.3, CUE.action + 1.8));
      ctx.fillText(S.category, cx, cy + R + 140 + u3 + 40);

      // The button, which is tapped once.
      var by2 = H - SAFE_BOTTOM - 330, bp = out3(span(t, CUE.action + 1.6, CUE.action + 2.1));
      var tap = span(t, CUE.action + 2.6, CUE.action + 2.75), rel = span(t, CUE.action + 2.75, CUE.action + 2.95);
      var bs = L(.92, 1, back(bp)) * (1 - .05 * tap * (1 - rel));
      ctx.globalAlpha = aA * bp; ctx.save(); ctx.translate(cx, by2 + 60); ctx.scale(bs, bs);
      ctx.fillStyle = T.label; rr(-290, -60, 580, 120, 60); ctx.fill();
      ctx.fillStyle = T.deep; f(700, 46, SANS); ctx.fillText('Vote now', 0, 16); ctx.restore();
      ctx.globalAlpha = aA * out3(span(t, CUE.action + 2.0, CUE.action + 2.5));
      ctx.fillStyle = WHITE; f(700, 36, SANS); ctx.fillText(S.display || S.url, cx, by2 + 200);
      ctx.fillStyle = rgba(WHITE, .6); f(400, 24, SANS);
      ctx.fillText('Public votes are one part of the score. An independent jury decides the award.', cx, by2 + 252);
      ctx.textAlign = 'left'; ctx.restore();
    }
  }

  root.SV2 = { draw: draw, W: W, H: H, DUR: DUR, CUE: CUE };
})(typeof window !== 'undefined' ? window : this);

/* ── The page: play it, save it as a video, save a picture ─────────────────────
 * The story comes from Services\ShareVideo::forNominee. Recording is MediaRecorder on the
 * canvas, in the visitor's own browser, because this host has no headless browser. */
(function () {
  'use strict';
  var cv = document.querySelector('[data-sv-canvas]');
  if (!cv || !cv.getContext || !window.SV2) return;
  var R;
  try { R = JSON.parse(cv.getAttribute('data-story') || '{}'); } catch (e) { return; }
  var S = { name: R.name || '', category: R.category || R.title || '', award: R.award || '', org: R.organisation || '',
            rank: R.rank | 0, field: R.field | 0, gap: R.gap | 0, votes: R.votes | 0,
            url: R.url || '', display: String(R.display || R.url || '').replace(/^https?:\/\//, ''),
            tone: R.tone || 'green', palette: R.palette || null, rally: R.line || '' };
  if (!S.palette || !S.palette.bg) return;   // a story without its colours is not one this script can draw
  var ctx = cv.getContext('2d'), A = { photo: null, logo: null }, DUR = SV2.DUR, posterAt = 5.8, raf = 0, rec = null;
  // Moving to another page in place (site-nav.js) must end the playback and any recording:
  // the canvas is gone, and a recorder left running would hand the next page a download.
  var pg = window.agPage || { leave: function () {} };
  pg.leave(function () {
    cancelAnimationFrame(raf);
    if (rec && rec.state === 'recording') { rec.onstop = null; try { rec.stop(); } catch (e) {} }
  });
  var load = function (src, key, cross) {
    if (!src) return Promise.resolve();
    return new Promise(function (res) {
      var im = new Image(); if (cross) im.crossOrigin = 'anonymous';
      im.onload = function () { A[key] = im; res(); }; im.onerror = function () { res(); }; im.src = src;
    });
  };
  var draw = function (t) { SV2.draw(ctx, t, S, A); };
  // Ask for the faces by name: `fonts.ready` resolves at once for a face the page has not
  // used yet, and a canvas drawn before then is set in the fallback.
  var fonts = document.fonts && document.fonts.load
    ? Promise.all(["700 40px 'Playfair Display'", "400 30px 'DM Sans'", "700 30px 'DM Sans'"].map(function (f) {
        return document.fonts.load(f).catch(function () {});
      }))
    : Promise.resolve();
  Promise.all([fonts, load(R.photo, 'photo', true), load('/assets/img/logo-africa-gates-alpha.png', 'logo', false)])
    .then(function () { draw(posterAt); });

  var status = document.querySelector('[data-sv-status]'), dl = document.querySelector('[data-sv-dl]');
  var say = function (m) { if (status) status.textContent = m; };
  function run(onEnd) {
    cancelAnimationFrame(raf);
    var t0 = performance.now();
    var step = function (now) {
      var t = (now - t0) / 1000; draw(Math.min(t, DUR));
      if (t < DUR) raf = requestAnimationFrame(step); else if (onEnd) onEnd();
    };
    raf = requestAnimationFrame(step);
  }
  var play = document.querySelector('[data-sv-play]');
  if (play) play.addEventListener('click', function () { run(null); });

  var make = document.querySelector('[data-sv-make]');
  if (make) make.addEventListener('click', function () {
    if (!cv.captureStream || typeof MediaRecorder === 'undefined') { say('This browser cannot record video. Use “Save a picture” instead.'); return; }
    var types = ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'];
    var type = types.filter(function (x) { return MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(x); })[0] || '';
    var chunks = [];
    try { rec = new MediaRecorder(cv.captureStream(30), type ? { mimeType: type, videoBitsPerSecond: 6000000 } : undefined); }
    catch (e) { say('The photo on this record cannot be recorded from here. Use “Save a picture” instead.'); return; }
    rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
    rec.onstop = function () {
      var ext = /mp4/.test(type) ? 'mp4' : 'webm';
      dl.href = URL.createObjectURL(new Blob(chunks, { type: type || 'video/webm' }));
      dl.download = 'vote-' + (S.name || 'nominee').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.' + ext;
      dl.textContent = 'Download the video (' + ext + ')'; dl.hidden = false; dl.click();
      say('Your video is ready.'); make.disabled = false;
    };
    make.disabled = true; dl.hidden = true; say('Recording… about 15 seconds.');
    rec.start(250);
    run(function () { setTimeout(function () { rec.stop(); }, 200); });
  });

  var still = document.querySelector('[data-sv-still]');
  if (still) still.addEventListener('click', function () {
    draw(DUR - 0.2);
    try {
      cv.toBlob(function (b) {
        if (!b) { say('That picture could not be made here.'); return; }
        dl.href = URL.createObjectURL(b); dl.download = 'vote-card.png';
        dl.textContent = 'Download the picture'; dl.hidden = false; dl.click(); say('Your picture is ready.');
      }, 'image/png');
    } catch (e) { say('The photo on this record cannot be saved from here.'); }
  });
})();
