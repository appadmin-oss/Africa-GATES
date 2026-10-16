/* ══════════════════════════════════════════════════════════════════════════════
   "WE ARE AFRICA" — the homepage globe (Phase 4 · design/WeAreAfrica.dc.html)
   Pairs with templates/partials/we-are-africa.twig and components/home.css (`.waa`).
   Guide: docs/GLOBE-BAND.md.
   ══════════════════════════════════════════════════════════════════════════════

   The sphere is drawn the DC's way — its own orthographic projection on a canvas, from
   the shipped Natural Earth 110m TopoJSON — so no vendored library is needed. What the DC
   did NOT have, and this must (CLAUDE.md, "the homepage globe band"; inventory
   _scripts.md, globe-band.js), is a map of something real:

   · MARKERS ARE THE SERVER'S. One per `[data-geo]` button the partial rendered from
     GlobeBand::countries() — no fallback set, no invented cities, no timer popping
     "X just joined". Each sits at the centroid of its own country's polygon, matched by
     Natural Earth's NAME (`data-geo`), so nothing here is typed and `AFRICA` below must
     contain every name GlobeBand::GEOMETRY maps (GlobeBandTest holds it).
   · ONLY THE SELECTED COUNTRY IS OUTLINED. Fifty-four strokes turn the land into a
     diagram competing with itself; the reference outlines one, while its card is open.
   · A PRESS ON A MARKER STARTS NO DRAG. The canvas captures the pointer for a drag, and
     a captured pointer's `click` goes to the capturing element — so if a marker's press
     ever reached the capture, its own listener would never run while Enter still worked,
     which an accessibility pass signs off. The markers are siblings of the canvas, and
     only the canvas listens for `pointerdown`.
   · THE PAGE STILL SCROLLS. `touch-action: pan-y` (home.css): the vertical axis is the
     browser's; a finger drags only round the equator. Up/down are the arrow keys.
   · KEYBOARD (WCAG 2.5.7, 2.4.7): ← → ↑ ↓ on the focused globe turn it; focusing a marker
     that is on the far side, or outside the band, turns the globe to it — a focused
     control nobody can see is the fault the old band shipped and fixed.
   · REDUCED MOTION: turning snaps instead of easing, and the band does not grow with the
     scroll (it is drawn at its final, full-bleed state).

   Colour: every value is a Support\Accent token read off :root and mixed here. The DC's
   #1f4a4f / #0f2a2e / #4f9a86 / #24494d are not palette colours (owner Q1); each mix is
   documented where it is made, and no hex is typed in this file.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var AFRICA = new Set(["Algeria","Angola","Benin","Botswana","Burkina Faso","Burundi","Cameroon",
    "Central African Rep.","Chad","Congo","Côte d'Ivoire","Dem. Rep. Congo","Djibouti","Egypt",
    "Eq. Guinea","Eritrea","eSwatini","Ethiopia","Gabon","Gambia","Ghana","Guinea","Guinea-Bissau",
    "Kenya","Lesotho","Liberia","Libya","Madagascar","Malawi","Mali","Mauritania","Morocco",
    "Mozambique","Namibia","Niger","Nigeria","Rwanda","S. Sudan","Senegal","Sierra Leone","Somalia",
    "Somaliland","South Africa","Sudan","Tanzania","Togo","Tunisia","Uganda","W. Sahara","Zambia",
    "Zimbabwe"]);
  var RAD = Math.PI / 180;

  function reduced() {
    return document.documentElement.classList.contains('ag-rm') ||
      (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  /* ── colour: tokens, mixed ─────────────────────────────────────────────── */
  function hex(name) {
    var v = getComputedStyle(document.documentElement).getPropertyValue('--ag-' + name).trim();
    var m = /^#?([0-9a-f]{6})$/i.exec(v);
    if (!m) return [16, 41, 44];
    var n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  /* mix([['ink', .7], ['info', .3]], alpha) → "rgba(…)" */
  function mix(parts, alpha) {
    var c = [0, 0, 0];
    parts.forEach(function (p) { var h = hex(p[0]); for (var i = 0; i < 3; i++) c[i] += h[i] * p[1]; });
    return 'rgba(' + Math.round(c[0]) + ',' + Math.round(c[1]) + ',' + Math.round(c[2]) + ',' + (alpha == null ? 1 : alpha) + ')';
  }
  function palette() {
    return {
      // The DC's sphere runs #1f4a4f → #0f2a2e: the ink, lifted toward the two cool
      // accents at its lit edge, and the ink itself at the rim.
      seaLit:  mix([['ink', .8], ['green-light', .1], ['info', .1]]),
      sea:     mix([['ink', 1]]),
      grid:    mix([['ground', 1]], .06),
      // #4f9a86 (Africa) and #24494d (the rest of the world).
      africa:  mix([['green-light', .5], ['info', .2], ['ink', .3]]),
      world:   mix([['ink', .7], ['green-light', .15], ['info', .15]]),
      border:  mix([['ink', 1]]),
      rim:     mix([['green-light', 1]], .25),
      pickFill: mix([['green-light', 1]], .35),
      pickLine: mix([['green-light', 1]])
    };
  }

  /* ── geometry ──────────────────────────────────────────────────────────── */
  function decode(topo) {
    var t = topo.transform, sx = t ? t.scale[0] : 1, sy = t ? t.scale[1] : 1,
        tx = t ? t.translate[0] : 0, ty = t ? t.translate[1] : 0;
    var arcs = topo.arcs.map(function (a) {
      var x = 0, y = 0;
      return a.map(function (p) {
        if (t) { x += p[0]; y += p[1]; } else { x = p[0]; y = p[1]; }
        return [(x * sx + tx) * RAD, (y * sy + ty) * RAD];
      });
    });
    function arc(i) { return i < 0 ? arcs[~i].slice().reverse() : arcs[i]; }
    function ring(r) {
      var out = [];
      r.forEach(function (i, k) { var pts = arc(i); out = out.concat(k ? pts.slice(1) : pts); });
      return out;
    }
    return topo.objects.countries.geometries
      .filter(function (g) { return g.properties.name !== 'Antarctica'; })
      .map(function (g) {
        var polys = (g.type === 'Polygon' ? [g.arcs] : g.arcs).map(function (p) { return p.map(ring); });
        return { name: g.properties.name, af: AFRICA.has(g.properties.name), polys: polys };
      });
  }

  /* The centroid of the country's LARGEST outer ring, as the mean of its points on the
     unit sphere — so a marker sits inside its own outline and nothing is typed. */
  function centroid(shape) {
    var best = null, bestN = -1;
    shape.polys.forEach(function (p) { if (p[0].length > bestN) { bestN = p[0].length; best = p[0]; } });
    var x = 0, y = 0, z = 0;
    best.forEach(function (pt) {
      var cl = Math.cos(pt[1]);
      x += cl * Math.cos(pt[0]); y += cl * Math.sin(pt[0]); z += Math.sin(pt[1]);
    });
    return [Math.atan2(y, x), Math.atan2(z, Math.sqrt(x * x + y * y))];
  }

  function boot(band) {
    var canvas = band.querySelector('[data-waa-canvas]');
    var pins = Array.prototype.slice.call(band.querySelectorAll('.waa__m'));
    var card = band.querySelector('[data-waa-card]');
    if (!canvas || !canvas.getContext) return;

    var S = { lam: 20, phi: 4, w: 0, h: 0, shapes: null, byName: {}, pick: null, opener: null };
    var colours = palette();
    var drag = null, raf = 0, anim = 0;

    card.querySelector('[data-waa-l-nominees]').textContent = band.dataset.lNominees || '';
    card.querySelector('[data-waa-l-votes]').textContent = band.dataset.lVotes || '';

    function phone() { return S.w < 600; }
    /* The DC's geometry: a sphere larger than the band, centred below its middle, so the
       continent fills it; on a phone it is smaller and nearer whole. */
    function geom() {
      var R = phone() ? Math.max(S.w * 0.72, 260) : Math.max(S.w * 0.62, 520);
      return { R: R, cx: S.w / 2, cy: S.h * 0.5 + R * 0.12 };
    }
    function proj(lon, lat) {
      var l0 = S.lam * RAD, p0 = S.phi * RAD, cp = Math.cos(lat), dl = lon - l0;
      var cosc = Math.sin(p0) * Math.sin(lat) + Math.cos(p0) * cp * Math.cos(dl);
      return [cp * Math.sin(dl), Math.cos(p0) * Math.sin(lat) - Math.sin(p0) * cp * Math.cos(dl), cosc];
    }
    function tracePath(g, s, G) {
      g.beginPath();
      s.polys.forEach(function (poly) {
        poly.forEach(function (ring) {
          ring.forEach(function (pt, i) {
            var p = proj(pt[0], pt[1]), x = p[0], y = p[1];
            if (p[2] < 0) { var m = Math.hypot(x, y) || 1; x /= m; y /= m; }
            var X = G.cx + x * G.R, Y = G.cy - y * G.R;
            if (i) g.lineTo(X, Y); else g.moveTo(X, Y);
          });
          g.closePath();
        });
      });
    }

    function draw() {
      raf = 0;
      var dpr = window.devicePixelRatio || 1;
      if (!S.w || !S.h) return;
      if (canvas.width !== Math.round(S.w * dpr) || canvas.height !== Math.round(S.h * dpr)) {
        canvas.width = Math.round(S.w * dpr); canvas.height = Math.round(S.h * dpr);
      }
      var g = canvas.getContext('2d'), G = geom();
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
      g.clearRect(0, 0, S.w, S.h);

      var grad = g.createRadialGradient(G.cx - G.R * 0.3, G.cy - G.R * 0.35, G.R * 0.1, G.cx, G.cy, G.R);
      grad.addColorStop(0, colours.seaLit); grad.addColorStop(1, colours.sea);
      g.beginPath(); g.arc(G.cx, G.cy, G.R, 0, Math.PI * 2); g.fillStyle = grad; g.fill();

      g.strokeStyle = colours.grid; g.lineWidth = 1;
      for (var lat = -60; lat <= 60; lat += 30) {
        g.beginPath(); var on = false;
        for (var lon = -180; lon <= 180; lon += 4) {
          var p = proj(lon * RAD, lat * RAD);
          if (p[2] > 0) { var X = G.cx + p[0] * G.R, Y = G.cy - p[1] * G.R; if (on) g.lineTo(X, Y); else g.moveTo(X, Y); on = true; }
          else on = false;
        }
        g.stroke();
      }

      if (S.shapes) {
        S.shapes.forEach(function (s) {
          tracePath(g, s, G);
          g.fillStyle = s.af ? colours.africa : colours.world; g.fill('evenodd');
          g.strokeStyle = colours.border; g.lineWidth = s.af ? 0.9 : 0.6; g.stroke();
        });
        var sel = S.pick && S.byName[S.pick.dataset.geo];
        if (sel) {
          tracePath(g, sel, G);
          g.fillStyle = colours.pickFill; g.fill('evenodd');
          g.strokeStyle = colours.pickLine; g.lineWidth = 1.6; g.stroke();
        }
      }

      g.beginPath(); g.arc(G.cx, G.cy, G.R, 0, Math.PI * 2);
      g.strokeStyle = colours.rim; g.lineWidth = 1.5; g.stroke();

      place(G);
    }
    function redraw() { if (!raf) raf = requestAnimationFrame(draw); }

    /* A marker at its country's centroid; `is-far` past the limb or outside the band,
       where it stays focusable — and focusing it turns the globe to it. */
    function place(G) {
      pins.forEach(function (b) {
        var c = b._ll; if (!c) return;
        var p = proj(c[0], c[1]), X = G.cx + p[0] * G.R, Y = G.cy - p[1] * G.R;
        var far = p[2] < 0.15 || X < 8 || X > S.w - 8 || Y < 8 || Y > S.h - 8;
        b.style.setProperty('--waa-x', X.toFixed(1) + 'px');
        b.style.setProperty('--waa-y', Y.toFixed(1) + 'px');
        b.classList.toggle('is-far', far);
        b._far = far;
      });
    }

    function turnTo(ll) {
      var toLam = ll[0] / RAD, toPhi = Math.max(-50, Math.min(50, ll[1] / RAD));
      cancelAnimationFrame(anim);
      // The short way round.
      var dLam = ((toLam - S.lam + 540) % 360) - 180;
      if (reduced()) { S.lam += dLam; S.phi = toPhi; redraw(); return; }
      var fromLam = S.lam, fromPhi = S.phi, t0 = performance.now(), D = 420;
      (function step(now) {
        var k = Math.min(1, (now - t0) / D), e = 1 - Math.pow(1 - k, 3);
        S.lam = fromLam + dLam * e; S.phi = fromPhi + (toPhi - fromPhi) * e;
        draw();
        if (k < 1) anim = requestAnimationFrame(step);
      })(t0);
    }

    function open(b) {
      if (S.pick && S.pick !== b) S.pick.setAttribute('aria-expanded', 'false');
      S.pick = b; S.opener = b;
      b.setAttribute('aria-expanded', 'true');
      card.querySelector('[data-waa-name]').textContent = b.dataset.name;
      card.querySelector('[data-waa-nominees]').textContent = b.dataset.nominees;
      card.querySelector('[data-waa-votes]').textContent = b.dataset.votes;
      var won = card.querySelector('[data-waa-won]');
      won.hidden = b.dataset.decided !== '1';
      won.textContent = band.dataset.lDecided || '';
      card.hidden = false;
      if (b._far && b._ll) turnTo(b._ll);
      redraw();
    }
    function close(back) {
      card.hidden = true;
      if (S.pick) S.pick.setAttribute('aria-expanded', 'false');
      var o = S.opener; S.pick = null; S.opener = null;
      redraw();
      if (back && o) o.focus();
    }

    pins.forEach(function (b) {
      b.addEventListener('click', function () {
        if (S.pick === b && !card.hidden) close(false); else open(b);
      });
      b.addEventListener('focus', function () { if (b._far && b._ll) turnTo(b._ll); });
    });
    card.querySelector('[data-waa-close]').addEventListener('click', function () { close(true); });
    band.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !card.hidden) { e.preventDefault(); close(true); }
    });

    /* Drag to turn — on the canvas only (see the header on pointer capture). */
    canvas.addEventListener('pointerdown', function (e) {
      drag = { x: e.clientX, y: e.clientY, lam: S.lam, phi: S.phi, touch: e.pointerType === 'touch', moved: 0 };
      if (!drag.touch) { try { canvas.setPointerCapture(e.pointerId); } catch (err) {} }
      canvas.classList.add('is-grabbing');
    });
    canvas.addEventListener('pointermove', function (e) {
      if (!drag) return;
      var G = geom(), k = 0.22 * (700 / Math.max(G.R, 300));
      drag.moved = Math.max(drag.moved, Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y));
      S.lam = drag.lam - (e.clientX - drag.x) * k;
      if (!drag.touch) S.phi = Math.max(-50, Math.min(50, drag.phi + (e.clientY - drag.y) * k));
      redraw();
    });
    function end(e) {
      if (!drag) return;
      var d = drag; drag = null; canvas.classList.remove('is-grabbing');
      if (e.type === 'pointerup' && d.moved < 5) hit(e);
    }
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);

    /* A click on a marked country's land opens its card, as a click on its marker does. */
    function hit(e) {
      if (!S.shapes) return;
      var r = canvas.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top;
      var g = canvas.getContext('2d'), G = geom();
      g.setTransform(1, 0, 0, 1, 0, 0);
      for (var i = 0; i < pins.length; i++) {
        var s = S.byName[pins[i].dataset.geo];
        if (!s || pins[i]._far) continue;
        tracePath(g, s, G);
        if (g.isPointInPath(x, y, 'evenodd')) { open(pins[i]); return; }
      }
    }

    canvas.addEventListener('keydown', function (e) {
      var step = { ArrowLeft: [10, 0], ArrowRight: [-10, 0], ArrowUp: [0, -8], ArrowDown: [0, 8] }[e.key];
      if (!step) return;
      e.preventDefault();
      if (document.documentElement.dir === 'rtl') step[0] = -step[0];
      S.lam += step[0]; S.phi = Math.max(-50, Math.min(50, S.phi + step[1]));
      redraw();
    });

    /* Size from the canvas's own box (it is the band's), not the window. */
    function measure() {
      var r = canvas.getBoundingClientRect();
      S.w = Math.round(r.width); S.h = Math.round(r.height);
      redraw();
    }
    if (window.ResizeObserver) new ResizeObserver(measure).observe(canvas);
    else window.addEventListener('resize', measure);
    measure();

    /* The band widens to the edges as it reaches the middle of the scroller — the DC's
       `p`, set on a frame rather than per scroll event, and not at all under reduced
       motion (the final, full-bleed state is the CSS default). */
    var scroller = band.closest('.ag-main');
    function grow() {
      if (reduced() || !scroller) { band.style.removeProperty('--waa-p'); return; }
      var vr = scroller.getBoundingClientRect(), r = band.getBoundingClientRect();
      var d = Math.abs((r.top - vr.top + r.height / 2) - vr.height / 2) / vr.height;
      var p = Math.max(0, Math.min(1, 1 - (d - 0.08) * 1.3));
      band.style.setProperty('--waa-p', p.toFixed(3));
    }
    var gq = 0;
    if (scroller) scroller.addEventListener('scroll', function () {
      if (!gq) gq = requestAnimationFrame(function () { gq = 0; grow(); });
    }, { passive: true });
    grow();

    var url = band.dataset.topo;
    if (url && window.fetch) {
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (topo) {
        S.shapes = decode(topo);
        S.shapes.forEach(function (s) { S.byName[s.name] = s; });
        pins.forEach(function (b) {
          var s = S.byName[b.dataset.geo];
          if (!s) return;          // GlobeBandTest pins every name; nothing to place otherwise
          b._ll = centroid(s);
          b.hidden = false;
        });
        redraw();
      }).catch(function () { /* the heading and the note still say what the band is */ });
    }
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-waa]'), boot);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
