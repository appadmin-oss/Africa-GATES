/*! Africa GATES: celebration engine (v2). Vanilla JS, no dependencies, ~6 KB.
 *  const fx = AGCelebrate.mount(el, {kind:'win'|'vote'|'nominate'|'give'|'ticket', size:'full'|'inline', layout:'phone'|'desktop', seenKey:'win-kcea11-achieng'});
 *  fx.replay(); fx.destroy(); fx.calm (true = seen before or reduced motion; skip count-up)
 *  AGCelebrate.countUp(rootEl) animates every [data-agc-count="18402"] inside rootEl.
 *  Pair with celebration.css. Spec: skills/app-ux-standards/SKILL.md §24. The DC (Celebration.dc.html) runs this exact file. */
(function (w, d) {
  'use strict';
  var KIND = {
    vote:     { ring: '#237b22', ring2: '#7fc87c', disc: '#effaf0' },
    win:      { ring: '#f3b416', ring2: '#fbd46a', disc: '#fff8df' },
    nominate: { ring: '#237b22', ring2: '#7fc87c', disc: '#effaf0' },
    give:     { ring: '#e0245e', ring2: '#f4789c', disc: '#fdecef' },
    ticket:   { ring: '#1f6fa3', ring2: '#7fb6d9', disc: '#e8f1f7' }
  };
  var GOLD = ['#f3b416', '#fbd46a', '#e8a800', '#fff3c4', '#d9c7a3'];
  var P_STAR = 'M12 2l2.9 6.3 6.9.7-5.2 4.7 1.5 6.8L12 17l-6.1 3.5 1.5-6.8L2.2 9l6.9-.7z';
  var P_HEART = 'M12 21 3.2 12.4a5.5 5.5 0 0 1 7.8-7.8l1 1 1-1a5.5 5.5 0 0 1 7.8 7.8z';
  var ICON = {
    win: '<svg viewBox="0 0 24 24" fill="#f3b416" stroke="#7a5600" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16 3 6.5l5 3.8L12 4l4 6.3 5-3.8L19.5 16z"/><rect x="5" y="17.5" width="14" height="2.6" rx="1"/></svg>',
    vote: '<svg viewBox="0 0 24 24" fill="none" stroke="#237b22" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="agc-draw" d="M5 12.5 10 17.5 19 7" stroke-dasharray="30"/></svg>',
    nominate: '<svg viewBox="0 0 24 24" fill="#f3b416" stroke="#7a5600" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><path d="' + P_STAR + '"/></svg>',
    give: '<svg viewBox="0 0 24 24" fill="#e0245e" aria-hidden="true"><path d="' + P_HEART + '"/></svg>',
    ticket: '<svg viewBox="0 0 24 24" fill="none" stroke="#1f6fa3" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4z"/><path d="M15 6v12" stroke-dasharray="2 2.2"/></svg>'
  };
  var SPARK = '<svg viewBox="0 0 24 24" fill="#fbd46a"><path d="M12 1.5 13.8 10.2 22.5 12 13.8 13.8 12 22.5 10.2 13.8 1.5 12 10.2 10.2z"/></svg>';

  function rng(seed) { var s = seed; return function () { s = (s * 9301 + 49297) % 233280; return s / 233280; }; }
  function px(n) { return Math.round(n) + 'px'; }
  function reduced() { try { return w.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; } }
  function seenOnce(key) { if (!key) return false; try { var k = 'ag-cel-' + key; if (w.localStorage.getItem(k)) return true; w.localStorage.setItem(k, '1'); } catch (e) {} return false; }
  function mk(parent, st, html) {
    var e = d.createElement('span'); e.className = 'agc-p'; e.setAttribute('aria-hidden', 'true');
    for (var k in st) { if (k.charAt(0) === '-') e.style.setProperty(k, st[k]); else e.style[k] = st[k]; }
    if (html) e.innerHTML = html; parent.appendChild(e); return e;
  }
  function dims(o) {
    if (o.size === 'inline') return { w: 88, h: 88, b: 56, n: 0.4, d: 0.5, inl: true };
    if (o.layout === 'desktop') return { w: 360, h: 320, b: 128, n: 1.3, d: 1.25 };
    return { w: 320, h: 210, b: 104, n: 1, d: 1 };
  }
  function cnt(base, D, min) { return Math.max(min || 4, Math.round(base * D.n)); }
  function ring(fx, D, delay, c) { var r = mk(fx, { width: px(D.b), height: px(D.b), border: '2px solid ' + c, animationDelay: delay + 's' }); r.className += ' agc-ring'; }
  function ringLoop(amb, D, delay, c) { var r = mk(amb, { width: px(D.b), height: px(D.b), border: '2px solid ' + c, animationDelay: delay + 's' }); r.className += ' agc-ring-loop'; }
  function confetti(fx, R, n, spread, pal, D) {
    for (var i = 0; i < n; i++) {
      var a = -Math.PI / 2 + (R() - 0.5) * Math.PI * 1.35, dist = spread * (0.55 + R() * 0.6), sh = i % 3;
      mk(fx, { left: '50%', top: '50%', width: px(sh === 0 ? 7 + R() * 5 : sh === 1 ? 7 : 4), height: px(sh === 0 ? 11 + R() * 7 : sh === 1 ? 7 : 14),
        borderRadius: sh === 1 ? '50%' : '2px', background: pal[i % pal.length],
        '--dx': px(Math.cos(a) * dist), '--dy': px(Math.sin(a) * dist * 0.9), '--fy': px(Math.max(D.h, 160) * 0.9 + R() * 80),
        '--r1': Math.round(R() * 360 - 180) + 'deg', '--r2': Math.round(R() * 900 - 450) + 'deg',
        animation: 'agcConf ' + (1.6 + R() * 1.1).toFixed(2) + 's cubic-bezier(.15,.7,.35,1) ' + (0.1 + R() * 0.18).toFixed(2) + 's both' });
    }
  }
  function drift(amb, R, n, pal, D) { // slow ambient confetti that never stops
    for (var i = 0; i < n; i++) mk(amb, { left: (6 + R() * 88) + '%', top: '0', width: '5px', height: px(10 + R() * 8), borderRadius: '2px', background: pal[i % pal.length], opacity: '0',
      '--dx': px((R() - 0.5) * 50), '--fy': px(Math.max(D.h, 160) + 40), '--r2': Math.round((R() - 0.5) * 720) + 'deg',
      animation: 'agcFall ' + (5.5 + R() * 3).toFixed(2) + 's cubic-bezier(.3,.1,.5,1) ' + (1 + R() * 6).toFixed(2) + 's infinite' });
  }
  function badge(stage, D, K, icon, burst, loop) {
    var b = d.createElement('span'); b.className = 'agc-badge'; b.setAttribute('aria-hidden', 'true');
    b.style.width = b.style.height = px(D.b); b.style.marginLeft = b.style.marginTop = px(-D.b / 2);
    b.style.background = K.disc; b.style.boxShadow = '0 0 0 2px ' + K.ring + ', 0 16px 34px -12px ' + K.ring + '99';
    b.innerHTML = icon + '<span class="agc-glint"></span>';
    var a = []; if (burst) a.push('agcPop .9s cubic-bezier(.2,.9,.3,1.2) .05s both'); if (loop) a.push(loop);
    b.style.animation = a.join(', ');
    var draw = b.querySelector('.agc-draw'); if (draw && burst) draw.style.animation = 'agcDraw .45s cubic-bezier(.4,0,.2,1) .55s both';
    stage.appendChild(b); return b;
  }

  function inlineFx(s, amb, fx, D, R, K, burst, kind) {
    ringLoop(amb, D, 1.8, K.ring2);
    if (kind === 'win') { var m = 'radial-gradient(circle, transparent 38%, #000 39%, #000 58%, transparent 70%)'; mk(amb, { left: '50%', top: '50%', width: px(D.b * 1.9), height: px(D.b * 1.9), borderRadius: '50%', background: 'repeating-conic-gradient(from 0deg, rgba(243,180,22,.3) 0 8deg, transparent 8deg 22deg)', webkitMask: m, mask: m, animation: 'agcSpin 16s linear infinite' }); }
    if (kind === 'give') for (var i = 0; i < 3; i++) mk(amb, { left: (30 + i * 20) + '%', top: px(D.h - 14), width: '10px', height: '10px', opacity: '0', '--sx': px((R() - 0.5) * 16), '--dy': '-58px', animation: 'agcHeart ' + (3.2 + i * 0.4).toFixed(2) + 's ease-in-out ' + (1 + i * 1.1).toFixed(2) + 's infinite' }, '<svg viewBox="0 0 24 24" fill="' + ['#e0245e', '#f4789c', '#fbd46a'][i] + '"><path d="' + P_HEART + '"/></svg>');
    if (kind === 'nominate') { var orbit = mk(amb, { left: '50%', top: '50%', width: '0', height: '0', animation: 'agcOrbit 40s linear infinite' }); for (var j = 0; j < 5; j++) { var a = j / 5 * Math.PI * 2 - Math.PI / 2; mk(orbit, { left: '0', top: '0', width: '9px', height: '9px', transform: 'translate(-50%,-50%) translate(' + px(Math.cos(a) * 40) + ',' + px(Math.sin(a) * 40) + ')' }, '<svg viewBox="0 0 24 24" fill="' + (j % 2 ? '#7fc87c' : '#f3b416') + '" style="animation:agcTwinkle ' + (1.5 + j * 0.2).toFixed(2) + 's ease-in-out ' + (j * 0.3).toFixed(2) + 's infinite"><path d="' + P_STAR + '"/></svg>'); } }
    if (burst) { ring(fx, D, 0.15, K.ring); var pal = kind === 'win' ? GOLD : kind === 'give' ? ['#e0245e', '#f4789c', '#fbd46a'] : kind === 'ticket' ? ['#1f6fa3', '#7fb6d9', '#f3b416'] : ['#237b22', '#7fc87c', '#f3b416'];
      for (var k = 0; k < 12; k++) { var b = k / 12 * Math.PI * 2, dist = 34 + R() * 8, c = pal[k % pal.length]; mk(fx, { left: '50%', top: '50%', width: '4px', height: '4px', borderRadius: '50%', background: c, '--dx': px(Math.cos(b) * dist), '--dy': px(Math.sin(b) * dist), animation: 'agcSpark .8s cubic-bezier(.1,.6,.3,1) .3s both' }); } }
    badge(s, D, K, ICON[kind], burst, kind === 'give' ? 'agcBeat 2.8s ease-in-out 1.2s infinite' : '');
  }

  var BUILD = {
    win: function (s, amb, fx, D, R, K, burst) {
      if (!D.inl) mk(amb, { left: '50%', top: '-10%', width: px(D.b * 2.6), height: '120%', background: 'linear-gradient(180deg,rgba(251,212,106,.55),rgba(251,212,106,0) 85%)',
        clipPath: 'polygon(42% 0,58% 0,100% 100%,0 100%)', transformOrigin: 'top center', transform: 'translateX(-50%)', opacity: '.7',
        animation: (burst ? 'agcBeam 1.2s cubic-bezier(.2,0,0,1) both, ' : '') + 'agcBreath 5s ease-in-out 1.4s infinite' });
      var m = 'radial-gradient(circle, transparent 30%, #000 31%, #000 56%, transparent 70%)';
      mk(amb, { left: '50%', top: '50%', width: px(D.b * 2.5), height: px(D.b * 2.5), borderRadius: '50%', background: 'repeating-conic-gradient(from 0deg, rgba(243,180,22,.28) 0 7deg, transparent 7deg 20deg)', webkitMask: m, mask: m, animation: 'agcSpin 16s linear infinite' });
      for (var i = 0, n = D.inl ? 4 : 7; i < n; i++) { var a = i / n * Math.PI * 2 + R(), r = D.b * (0.78 + R() * 0.45), z = 8 + R() * 7;
        mk(amb, { left: 'calc(50% + ' + px(Math.cos(a) * r) + ')', top: 'calc(50% + ' + px(Math.sin(a) * r) + ')', width: px(z), height: px(z), marginLeft: px(-z / 2), marginTop: px(-z / 2),
          animation: 'agcTwinkle ' + (1.6 + R() * 1.4).toFixed(2) + 's ease-in-out ' + (R() * 2).toFixed(2) + 's infinite' }, SPARK); }
      var loops = D.inl ? [[0.5, 0.5, 3.2]] : [[0.18, 0.3, 2.6], [0.82, 0.24, 6.1]];
      loops.forEach(function (L, b) { for (var i = 0, n = D.inl ? 8 : 12; i < n; i++) { var a = i / n * Math.PI * 2, dist = (D.inl ? 44 : 50 * D.d) * (0.7 + R() * 0.5), c = GOLD[(i + b) % 5];
        mk(amb, { left: (L[0] * 100) + '%', top: (L[1] * 100) + '%', width: '4px', height: '4px', borderRadius: '50%', background: c, boxShadow: '0 0 6px ' + c, opacity: '0',
          '--dx': px(Math.cos(a) * dist), '--dy': px(Math.sin(a) * dist), animation: 'agcSparkLoop 7s cubic-bezier(.1,.6,.3,1) ' + L[2] + 's infinite' }); } });
      drift(amb, R, D.inl ? 3 : 6, GOLD, D);
      if (burst) {
        var bursts = D.inl ? [[0.5, 0.5, 0.35]] : [[0.22, 0.28, 0.5], [0.8, 0.22, 0.85], [0.5, 0.08, 1.25]];
        bursts.forEach(function (B, b) { for (var i = 0, n = cnt(18, D, 10); i < n; i++) { var a = i / n * Math.PI * 2, dist = (D.inl ? 64 : 56 * D.d) * (0.7 + R() * 0.5), pal = b === 1 ? GOLD : GOLD.concat(['#e0245e', '#7fc87c']), c = pal[i % pal.length];
          mk(fx, { left: (B[0] * 100) + '%', top: (B[1] * 100) + '%', width: '4px', height: '4px', borderRadius: '50%', background: c, boxShadow: '0 0 6px ' + c,
            '--dx': px(Math.cos(a) * dist), '--dy': px(Math.sin(a) * dist), animation: 'agcSpark 1.15s cubic-bezier(.1,.6,.3,1) ' + B[2] + 's both' }); } });
        for (var j = 0, m2 = cnt(16, D, 6); j < m2; j++) mk(fx, { left: (5 + R() * 90) + '%', top: '0', width: '5px', height: px(14 + R() * 10), borderRadius: '2px', background: GOLD[j % 5],
          '--dx': px((R() - 0.5) * 60), '--fy': px(Math.max(D.h, 160) + 40), '--r2': Math.round((R() - 0.5) * 720) + 'deg',
          animation: 'agcFall ' + (2.2 + R() * 1.2).toFixed(2) + 's cubic-bezier(.3,.1,.5,1) ' + (0.3 + R() * 0.9).toFixed(2) + 's both' });
        ring(fx, D, 0.1, K.ring); ring(fx, D, 0.4, K.ring2);
      }
      badge(s, D, K, ICON.win, burst);
    },
    vote: function (s, amb, fx, D, R, K, burst) {
      ringLoop(amb, D, 1.6, K.ring2);
      var rise = function (layer, n, loop) { for (var i = 0; i < n; i++) { var c = ['#237b22', '#7fc87c', '#f3b416'][i % 3], z = 3 + R() * 4;
        mk(layer, { left: 'calc(50% + ' + px((R() - 0.5) * D.b * 1.2) + ')', top: px(D.h / 2 + D.b * 0.42), width: px(z), height: px(z), borderRadius: i % 2 ? '50%' : '1px', background: c, opacity: loop ? '0' : '',
          '--dx': px((R() - 0.5) * Math.max(D.w, 200) * 0.7), '--dy': px(-(Math.max(D.h, 140) * (0.45 + R() * 0.35))),
          animation: 'agcRiseS ' + (loop ? (2.8 + R()).toFixed(2) : (1.1 + R() * 0.9).toFixed(2)) + 's cubic-bezier(.2,.7,.3,1) ' + (loop ? (0.9 + R() * 3.2) : (0.35 + R() * 0.5)).toFixed(2) + 's ' + (loop ? 'infinite' : 'both') }); } };
      rise(amb, D.inl ? 4 : 7, true);
      if (burst) { ring(fx, D, 0.25, K.ring); ring(fx, D, 0.5, K.ring2); rise(fx, cnt(22, D, 8), false); }
      badge(s, D, K, ICON.vote, burst);
    },
    nominate: function (s, amb, fx, D, R, K, burst) {
      var orbit = mk(amb, { left: '50%', top: '50%', width: '0', height: '0', animation: 'agcOrbit 48s linear infinite' });
      for (var i = 0, n = D.inl ? 6 : (D.n > 1 ? 12 : 10); i < n; i++) { var a = i / n * Math.PI * 2 - Math.PI / 2, r = D.b * (0.9 + (i % 2) * 0.35), z = i % 2 ? 12 : 18, c = i % 3 === 0 ? '#f3b416' : '#7fc87c';
        mk(orbit, { left: '0', top: '0', width: px(D.inl ? z * 0.7 : z), height: px(D.inl ? z * 0.7 : z), '--dx': px(Math.cos(a) * r), '--dy': px(Math.sin(a) * r),
          transform: 'translate(-50%,-50%) translate(var(--dx),var(--dy))', animation: burst ? 'agcStarOut .9s cubic-bezier(.2,.9,.3,1.2) ' + (0.2 + i * 0.05).toFixed(2) + 's both' : '' },
          '<svg viewBox="0 0 24 24" fill="' + c + '" style="animation:agcTwinkle ' + (1.4 + R()).toFixed(2) + 's ease-in-out ' + (1 + R()).toFixed(2) + 's infinite"><path d="' + P_STAR + '"/></svg>'); }
      drift(amb, R, D.inl ? 2 : 4, ['#237b22', '#7fc87c', '#f3b416', '#d9c7a3'], D);
      if (burst) { ring(fx, D, 0.15, K.ring); confetti(fx, R, cnt(28, D, 10), 140 * D.d, ['#237b22', '#7fc87c', '#f3b416', '#d9c7a3'], D); }
      badge(s, D, K, ICON.nominate, burst);
    },
    give: function (s, amb, fx, D, R, K, burst) {
      var hearts = function (layer, n, loop) { for (var i = 0; i < n; i++) { var c = ['#e0245e', '#f4789c', '#f3b416', '#fbd46a'][i % 4], z = 10 + R() * 14;
        mk(layer, { left: (10 + R() * 80) + '%', top: px(D.h - 10), width: px(z), height: px(z), opacity: loop ? '0' : '',
          '--sx': px((R() - 0.5) * 50), '--dy': px(-(Math.max(D.h, 160) * (0.6 + R() * 0.5))),
          animation: 'agcHeart ' + (loop ? 3.4 + R() * 1.2 : 2 + R() * 1.4).toFixed(2) + 's ease-in-out ' + (loop ? 0.8 + R() * 4.5 : 0.2 + R() * 1.2).toFixed(2) + 's ' + (loop ? 'infinite' : 'both') },
          '<svg viewBox="0 0 24 24" fill="' + c + '"><path d="' + P_HEART + '"/></svg>'); } };
      hearts(amb, D.inl ? 4 : 7, true);
      if (burst) { hearts(fx, cnt(16, D, 6), false); ring(fx, D, 0.15, K.ring); ring(fx, D, 0.45, K.ring2); }
      badge(s, D, K, ICON.give, burst, 'agcBeat 2.8s ease-in-out 1.2s infinite');
    },
    ticket: function (s, amb, fx, D, R, K, burst, o) {
      var pal = ['#1f6fa3', '#7fb6d9', '#f3b416', '#237b22'];
      drift(amb, R, D.inl ? 3 : 5, pal, D);
      if (burst) confetti(fx, R, cnt(28, D, 10), (D.inl ? 90 : 150 * D.d), pal, D);
      if (D.inl) { badge(s, D, K, ICON.ticket, burst); return; }
      var tw = D.n > 1 ? 240 : 200, th = Math.round(tw * 0.5), mask = 'radial-gradient(circle at 66% 0, transparent 9px, #000 9.5px) top/100% 51% no-repeat, radial-gradient(circle at 66% 100%, transparent 9px, #000 9.5px) bottom/100% 51% no-repeat';
      var t = d.createElement('div'); t.setAttribute('aria-hidden', 'true');
      t.style.cssText = 'position:absolute;left:50%;top:50%;z-index:2;display:grid;grid-template-columns:1fr 34%;background:#fff;border-radius:14px;box-shadow:0 18px 36px -14px rgba(31,111,163,.55),0 0 0 1px #d8e6f0;font-family:inherit;transform:translate(-50%,-50%) rotate(-3deg)';
      t.style.width = px(tw); t.style.height = px(th); t.style.webkitMask = mask; t.style.mask = mask;
      t.style.animation = (burst ? 'agcTicket .9s cubic-bezier(.2,.9,.3,1.1) .05s both, ' : '') + 'agcBob 4.5s ease-in-out 1.2s infinite';
      t.innerHTML = '<div style="display:flex;flex-direction:column;justify-content:center;gap:4px;padding:0 14px;border-right:2px dashed #c9dbe8;text-align:start">' +
        '<span style="font-size:11px;font-weight:600;color:#1f6fa3">Admit one</span>' +
        '<span style="font-family:\'Playfair Display\',Georgia,serif;font-weight:700;font-size:' + px(tw / 11) + ';line-height:1.1;color:#10292c">' + (o.ticketLabel || 'KCEA Ceremony') + '</span>' +
        '<span style="font-size:11px;color:#626a6e">' + (o.ticketWhen || 'Sat 6 Dec · 18:00') + '</span></div>' +
        '<div style="display:flex;align-items:center;justify-content:center"><span style="width:52%;aspect-ratio:1/1;border-radius:6px;background:repeating-conic-gradient(#10292c 0 25%,#fff 0 50%) 0 0/8px 8px"></span></div>' +
        '<span style="position:absolute;left:44%;top:58%;padding:5px 9px;border:2.5px solid #237b22;border-radius:8px;color:#237b22;font-weight:700;font-size:12px;letter-spacing:.08em;background:rgba(255,255,255,.88);transform:translate(-50%,-50%) rotate(-14deg);' +
        (burst ? 'animation:agcStamp .5s cubic-bezier(.3,1.4,.5,1) .85s both' : '') + '">CONFIRMED</span>';
      s.appendChild(t);
    }
  };

  function mount(host, opts) {
    var o = { kind: 'vote', size: 'full', layout: 'phone', seenKey: '', haptics: true };
    for (var k in (opts || {})) if (opts[k] != null && opts[k] !== '') o[k] = opts[k];
    if (!BUILD[o.kind]) o.kind = 'vote';
    var K = KIND[o.kind], D = dims(o), rm = reduced(), seen = seenOnce(o.seenKey), calm = seen || rm, runs = 0, cleanT;
    host.classList.add('agc'); host.textContent = '';
    var stage = d.createElement('div'); stage.className = 'agc-stage'; stage.style.width = px(D.w); stage.style.height = px(D.h); host.appendChild(stage);
    function layer(c) { var e = d.createElement('div'); e.className = c; stage.appendChild(e); return e; }
    function build(burst) {
      runs++; clearTimeout(cleanT); stage.textContent = '';
      var amb = layer('agc-amb'), fx = layer('agc-fx');
      if (D.inl) inlineFx(stage, amb, fx, D, rng(11 + runs * 17), K, burst, o.kind); else BUILD[o.kind](stage, amb, fx, D, rng(11 + runs * 17), K, burst, o);
      if (burst) {
        if (o.haptics) { try { if (navigator.vibrate) navigator.vibrate(o.kind === 'win' ? [18, 60, 18, 60, 40] : [14, 40, 22]); } catch (e) {} }
        cleanT = setTimeout(function () { fx.textContent = ''; }, 5600); // after the longest burst piece (≤5.2s) ends
      }
    }
    build(!calm);
    var io = null; if ('IntersectionObserver' in w) { io = new IntersectionObserver(function (es) { host.classList.toggle('agc-paused', !es[0].isIntersecting); }); io.observe(host); }
    function vis() { host.classList.toggle('agc-hidden', d.hidden); } d.addEventListener('visibilitychange', vis);
    return {
      calm: calm, seen: seen, reduced: rm,
      replay: function () { if (!reduced()) build(true); },
      destroy: function () { clearTimeout(cleanT); if (io) io.disconnect(); d.removeEventListener('visibilitychange', vis); host.textContent = ''; host.classList.remove('agc', 'agc-paused', 'agc-hidden'); }
    };
  }

  function fmt(target, p) {
    var m = String(target).match(/^([^\d]*)([\d.,]+)(.*)$/); if (!m) return target;
    var v = parseFloat(m[2].replace(/,/g, '')), dec = (m[2].split('.')[1] || '').length, cur = v * p;
    return m[1] + (dec ? cur.toFixed(dec) : Math.round(cur).toLocaleString('en')) + m[3];
  }
  function countUp(root, calm) {
    var els = (root || d).querySelectorAll('[data-agc-count]'); if (!els.length) return;
    var set = function (p) { for (var i = 0; i < els.length; i++) els[i].textContent = fmt(els[i].getAttribute('data-agc-count'), p); };
    if (calm || reduced()) { set(1); return; }
    var t0 = performance.now(), ease = function (t) { return 1 - Math.pow(1 - t, 3); };
    set(0); (function step(t) { var p = Math.min(1, Math.max(0, (t - t0 - 500) / 1300)); set(ease(p)); if (p < 1) w.requestAnimationFrame(step); })(t0);
  }

  w.AGCelebrate = { mount: mount, countUp: countUp, format: fmt };
})(window, document);
