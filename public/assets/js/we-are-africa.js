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
    // A dot-matrix globe on the plain page (owner, 22 Oct 2026: "modern and simple, after
    // Cloudflare"). Land is dots and nothing else — no borders, no grid, no shading —
    // Africa's dots in the green, the rest of the world's in a quiet grey, and the one
    // selected country in the deep green. The sphere is a hairline, so the eye reads the
    // shape of the land and lands on the markers.
    return {
      africa:  mix([['green', .5], ['green-edge', .5]]),
      world:   mix([['ink', .22], ['surface', .78]]),
      pick:    mix([['green', 1]]),
      pickLine: mix([['green', 1]], .7),
      disc:    mix([['surface', 1]]),
      rim:     mix([['line', 1]])
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

  /* The land as dots: an even grid on the sphere (rows every 1.5°, the step along a row
     widened by 1/cos(latitude) so dots stay equally spaced), each kept if it falls inside
     a country's polygon. Done once, after the geometry loads; a bounding box per country
     keeps it to the few polygons that could contain a point. */
  function sample(shapes) {
    var step = 1.5 * RAD, out = [];
    var boxes = shapes.map(function (s) {
      var b = [Infinity, Infinity, -Infinity, -Infinity];
      s.polys.forEach(function (p) { p[0].forEach(function (pt) {
        if (pt[0] < b[0]) b[0] = pt[0]; if (pt[1] < b[1]) b[1] = pt[1];
        if (pt[0] > b[2]) b[2] = pt[0]; if (pt[1] > b[3]) b[3] = pt[1];
      }); });
      return b;
    });
    function inside(s, lon, lat) {
      var hit = false;
      s.polys.forEach(function (p) { p.forEach(function (r) {
        for (var i = 0, j = r.length - 1; i < r.length; j = i++) {
          var yi = r[i][1], yj = r[j][1];
          if ((yi > lat) !== (yj > lat) && lon < (r[j][0] - r[i][0]) * (lat - yi) / (yj - yi) + r[i][0]) hit = !hit;
        }
      }); });
      return hit;
    }
    for (var lat = -56 * RAD; lat <= 78 * RAD; lat += step) {
      var dl = step / Math.max(0.2, Math.cos(lat));
      for (var lon = -Math.PI; lon < Math.PI; lon += dl) {
        for (var k = 0; k < shapes.length; k++) {
          var b = boxes[k];
          if (lon < b[0] || lon > b[2] || lat < b[1] || lat > b[3]) continue;
          if (inside(shapes[k], lon, lat)) { out.push([lon, lat, k]); break; }
        }
      }
    }
    return out;
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

    var S = { lam: 20, phi: 4, w: 0, h: 0, shapes: null, dots: null, byName: {}, pick: null, opener: null };
    var colours = palette();
    var drag = null, raf = 0, anim = 0;

    card.querySelector('[data-waa-l-nominees]').textContent = band.dataset.lNominees || '';
    card.querySelector('[data-waa-l-votes]').textContent = band.dataset.lVotes || '';

    function phone() { return S.w < 600; }
    /* The DC's geometry: a sphere larger than the band, centred below its middle, so the
       continent fills it; on a phone it is smaller and nearer whole. */
    function geom() {
      // A phone shows Africa large with the limb at the sides; wider, the whole sphere
      // rises into the band, so it reads as a globe and not as a flat map.
      if (phone()) { var r = Math.max(S.w * 0.72, 260); return { R: r, cx: S.w / 2, cy: S.h * 0.5 + r * 0.12 }; }
      var R = Math.max(S.h * 0.6, 360);
      return { R: R, cx: S.w / 2, cy: S.h * 0.56 };
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

      // The sphere: a white disc and a hairline, nothing more.
      g.beginPath(); g.arc(G.cx, G.cy, G.R, 0, Math.PI * 2);
      g.fillStyle = colours.disc; g.fill();
      g.strokeStyle = colours.rim; g.lineWidth = 1; g.stroke();

      if (S.dots) {
        var sel = S.pick && S.byName[S.pick.dataset.geo];
        var selIdx = sel ? S.shapes.indexOf(sel) : -1;
        var base = Math.max(1.3, G.R / 230);
        // Batched by colour and by depth, so a frame is a handful of fills however many
        // dots there are: three depth bands fade the land towards the limb.
        var bins = {};
        S.dots.forEach(function (d) {
          var p = proj(d[0], d[1]);
          if (p[2] <= 0.02) return;
          var c = d[2] === selIdx ? 'pick' : (S.shapes[d[2]].af ? 'africa' : 'world');
          var band = p[2] > 0.55 ? 2 : (p[2] > 0.25 ? 1 : 0);
          var key = c + band;
          (bins[key] = bins[key] || { c: c, band: band, pts: [] }).pts.push(G.cx + p[0] * G.R, G.cy - p[1] * G.R);
        });
        Object.keys(bins).forEach(function (k) {
          var bin = bins[k], r = base * (0.7 + bin.band * 0.18), pts = bin.pts;
          g.globalAlpha = [0.28, 0.5, 0.75][bin.band];
          g.fillStyle = colours[bin.c];
          g.beginPath();
          for (var i = 0; i < pts.length; i += 2) { g.moveTo(pts[i] + r, pts[i + 1]); g.arc(pts[i], pts[i + 1], r, 0, Math.PI * 2); }
          g.fill();
        });
        g.globalAlpha = 1;
        // The one outline on the globe: the selected country, while its card is open.
        if (sel) { tracePath(g, sel, G); g.strokeStyle = colours.pickLine; g.lineWidth = 1.2; g.stroke(); }
      }

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

    var url = band.dataset.topo;
    if (url && window.fetch) {
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (topo) {
        S.shapes = decode(topo);
        S.shapes.forEach(function (s) { S.byName[s.name] = s; });
        S.dots = sample(S.shapes);
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
