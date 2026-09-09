/* ════════════════════════════════════════════════════════════════
   Africa GATES — "We are Africa" globe band
   Dotted orthographic globe, shown in full, with the 54 African nations
   outlined (brighter where a nominee is standing) and a marker on each
   nation that is live. Interaction lives on the globe: drag to rotate,
   click a country for its card. No zoom.

   Requires d3 (v7, includes d3-geo) and topojson-client, loaded before
   this file — the VENDORED, version-pinned copies:

     /assets/js/vendor/d3-7.9.0.min.js
     /assets/js/vendor/topojson-client-3.1.0.min.js

   Geometry: Natural Earth countries-110m (public domain), served from
   /assets/geo/countries-110m.json. Both the scripts and the geometry are
   self-hosted rather than fetched from a CDN — see
   public/assets/js/vendor/PROVENANCE.md for why that is not optional here.

   ── WHERE THE MARKERS COME FROM ─────────────────────────────────────────

   `data-countries` on the stage, written by `GlobeBand::countries()`: one
   entry per nation with an approved nominee in a live award, carrying that
   nation's nominee count and vote total. There is NO fallback set.

   The handoff's script shipped sixteen cities with invented ballot counts and
   sub-second verification latencies, and drew arcs between "verification
   nodes". This platform has no such nodes and records no per-ballot timing, so
   every one of those numbers was unfalsifiable decoration on the homepage. If
   the attribute is absent or empty the globe draws with no markers, which is
   what a site with no approved nominee looks like.

   ── AND WHY NO MARKER CARRIES A STORED COORDINATE ───────────────────────

   A marker sits at `d3.geoCentroid()` of the country's own polygon in the file
   above. Nothing is typed, so a marker cannot drift from the outline it sits
   inside, and adding a nation needs no coordinates. The price is that the join
   is by Natural Earth's NAME (`geo` in the payload, not the display name — it
   calls the DRC "Dem. Rep. Congo"), and a name that does not match produces no
   error at all: the marker is simply absent from a band that still looks
   finished. `GlobeBandTest` pins every name in the map against this file and
   against the AFRICA set below, because nothing at runtime can.

   No-ops when #agGlobeStage, d3 or topojson is absent, so it is safe to
   load on a page that has no band.
   ════════════════════════════════════════════════════════════════ */
(function(){
  var stage = document.getElementById('agGlobeStage');
  if (!stage || typeof d3 === 'undefined' || typeof topojson === 'undefined') return;

  var canvas = document.getElementById('agGlobe'),
      ctx    = canvas.getContext('2d'),
      layer  = document.getElementById('agGlobeNodes'),
      card   = document.getElementById('agGlobeCard'),
      reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* The 54 nations, as Natural Earth names them (plus the two it splits out). */
  var AFRICA = new Set(['Algeria','Angola','Benin','Botswana','Burkina Faso','Burundi','Cameroon',
    'Central African Rep.','Chad','Côte d\'Ivoire','Dem. Rep. Congo','Congo','Djibouti','Egypt',
    'Eq. Guinea','Eritrea','Ethiopia','Gabon','Gambia','Ghana','Guinea','Guinea-Bissau','Kenya',
    'Lesotho','Liberia','Libya','Madagascar','Malawi','Mali','Mauritania','Morocco','Mozambique',
    'Namibia','Niger','Nigeria','Rwanda','Senegal','Sierra Leone','Somalia','Somaliland',
    'South Africa','S. Sudan','Sudan','Swaziland','eSwatini','Tanzania','Togo','Tunisia','Uganda',
    'W. Sahara','Zambia','Zimbabwe','Cabo Verde','Comoros','Mauritius','Seychelles',
    'São Tomé and Principe']);

  var live = [];
  try { live = stage.dataset.countries ? (JSON.parse(stage.dataset.countries) || []) : []; }
  catch (e) { live = []; }
  if (!Array.isArray(live)) live = [];

  /* Keyed by the geometry name, which is what the hit-test and the card look up. */
  var byGeo = {};
  live.forEach(function(c){ if (c && c.geo) byGeo[c.geo] = c; });

  var projection = d3.geoOrthographic().rotate([-19,-4,0]).precision(0.5),
      path = d3.geoPath(projection, ctx),
      fmt  = d3.format(','),
      W=0,H=0,dots=[],afr=[],marks=[],busiest=null,anyDecided=false,ready=false,t0=performance.now(),
      spin=!reduced, base=1, drag=null, sel=null, onScreen=true,
      ROT = d3.geoRotation(projection.rotate());

  function resize(){
    var r = stage.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
    W = r.width; H = r.height;
    canvas.width = W*dpr; canvas.height = H*dpr;
    ctx.setTransform(dpr,0,0,dpr,0,0);
    /* radius is bound by whichever of width/height is tighter; H*0.43 with a
       0.47 centre keeps the whole sphere inside the stage at every viewport */
    base = Math.min(W*0.30, H*0.43);
    projection.translate([W*0.5, H*0.47]).scale(base);
  }
  window.addEventListener('resize', resize);
  /* the stage may lay out after load (background tab, prerender) — without this
     the canvas stays 0×0 and the globe never paints */
  if ('ResizeObserver' in window) new ResizeObserver(function(){ if (ready) resize(); }).observe(stage);

  /* Land dots: rasterise the land once in equirectangular, then sample a
     Fibonacci sphere against it — no per-point polygon tests. */
  function buildDots(features){
    var w=1024, h=512, off=document.createElement('canvas');
    off.width=w; off.height=h;
    var c=off.getContext('2d'), p=d3.geoEquirectangular().translate([w/2,h/2]).scale(w/(2*Math.PI));
    c.fillStyle='#000'; c.beginPath(); d3.geoPath(p,c)({type:'FeatureCollection',features:features}); c.fill();
    var data=c.getImageData(0,0,w,h).data;
    function isLand(lo,la){
      var x=Math.floor((lo+180)/360*w), y=Math.floor((90-la)/180*h);
      return x>=0 && y>=0 && x<w && y<h && data[(y*w+x)*4+3]>128;
    }
    var N=15000, out=[], g=Math.PI*(3-Math.sqrt(5));
    for (var i=0;i<N;i++){
      var y=1-(i/(N-1))*2, r=Math.sqrt(Math.max(0,1-y*y)), th=g*i;
      var la=Math.asin(y)*180/Math.PI, lo=Math.atan2(Math.sin(th)*r, Math.cos(th)*r)*180/Math.PI;
      if (isLand(lo,la)) out.push([lo,la]);
    }
    return out;
  }

  var CHECK = '<svg viewBox="0 0 20 20" fill="none" stroke="#237b22" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 2.8 16 5v5c0 3.2-2.3 5.9-6 7-3.7-1.1-6-3.8-6-7V5z"/><path d="M7.5 10 9.4 11.8 12.7 8.4"/></svg>';

  /* Markers cannot be built until the geometry is in: their position IS the
     polygon's centroid, so there is nothing to place them at before then. */
  function buildMarks(features){
    var out = [];
    features.forEach(function(f){
      var c = byGeo[f.properties.name];
      if (!c) return;
      var el = document.createElement('button');
      el.type = 'button';
      /* ── THE RING IS RARE, AND THAT IS THE WHOLE OF ITS JOB ──────────
         The reference design carries four plain dots and two ringed markers,
         and the ring is what the eye lands on. Mapping it onto "this nation
         has any votes" — true of nearly every nation the moment an award
         opens — produced five rings and one dot: the hierarchy inverted, and
         a band that read as noise rather than as a map with a point of
         interest. A marker almost everything qualifies for is a background.
         So the ring means an award has been DECIDED here. */
      el.className = 'node ' + (c.decided ? 'node--h' : 'node--v');
      el.style.opacity = '0';
      /* The whole fact, in the accessible name: a screen reader gets the same
         two figures the card shows, without having to open it. */
      el.setAttribute('aria-label', c.name + ' — ' + fmt(c.nominees || 0)
        + (c.nominees === 1 ? ' nominee' : ' nominees') + ' standing, '
        + fmt(c.votes || 0) + (c.votes === 1 ? ' vote' : ' votes') + ' cast'
        + (c.decided ? ', award decided' : ''));
      el.innerHTML = (c.decided ? CHECK : '') + '<span class="node__lb">' + c.name + '</span>';
      el.addEventListener('click', function(e){ e.stopPropagation(); openCountry(f.properties.name); });
      out.push({ el: el, ll: d3.geoCentroid(f), c: c });
    });
    /* Busiest last, so it paints over a neighbour when two centroids are close. */
    out.sort(function(a,b){ return (a.c.votes||0) - (b.c.votes||0); });
    out.forEach(function(m){ layer.appendChild(m.el); });
    busiest = out.length ? out[out.length - 1] : null;
    anyDecided = out.some(function(m){ return !!m.c.decided; });
    return out;
  }

  function q(sel){ return card.querySelector(sel); }
  function openCountry(geoName){
    var c = byGeo[geoName], has = !!c;
    q('[data-globe-name]').textContent = has ? c.name : geoName;
    /* Hidden rather than emptied for a country with no entry: an empty <p> with
       the code line's margins leaves a gap that reads as a missing value. */
    var code = q('[data-globe-code]');
    code.textContent = has ? c.code : '';
    code.hidden = !has;
    q('[data-globe-nominees]').textContent = has ? fmt(c.nominees || 0) : '—';
    q('[data-globe-votes]').textContent    = has ? fmt(c.votes || 0)    : '—';
    /* Rows OR the sentence, never both: two zeros in a card read as a load
       failure, and the reason they are zero is worth a sentence. */
    q('.ccard__rows').hidden  = !has;
    q('[data-globe-none]').hidden = has;
    sel = geoName; card.classList.add('is-in'); spin = false;
  }
  function closeCard(){ sel = null; card.classList.remove('is-in'); spin = !reduced; }
  q('[data-globe-close]').addEventListener('click', closeCard);
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeCard(); });

  canvas.addEventListener('click', function(e){
    var r = canvas.getBoundingClientRect(),
        p = projection.invert([e.clientX - r.left, e.clientY - r.top]);
    if (!p) return closeCard();
    var hit = afr.filter(function(f){ return d3.geoContains(f, p); })[0];
    hit ? openCountry(hit.properties.name) : closeCard();
  });

  stage.addEventListener('pointerdown', function(e){
    /* ── A MARKER IS A BUTTON, AND POINTER CAPTURE WAS EATING ITS CLICK ──
       `setPointerCapture` on the stage retargets the following `click` to the
       CAPTURING element, so a marker's own listener never ran: every marker on
       this band was unclickable with a mouse or a finger, while Enter on a
       focused one opened its card perfectly. Nothing throws, nothing logs — the
       card just never appears, which reads as "the globe is decorative".

       So a press that starts on a marker (or on the card) starts no drag. There
       is nothing to rotate by grabbing an 11px button, and the rest of the
       sphere — all of it — still drags. */
    if (e.target.closest('.ccard') || e.target.closest('.node')) return;
    drag = { x:e.clientX, y:e.clientY, r:projection.rotate() };
    stage.classList.add('is-drag');
    stage.setPointerCapture(e.pointerId);
  });
  stage.addEventListener('pointermove', function(e){
    if (!drag) return;
    var k = 0.24;
    projection.rotate([
      drag.r[0] + (e.clientX - drag.x) * k,
      Math.max(-56, Math.min(56, drag.r[1] - (e.clientY - drag.y) * k)),
      0
    ]);
  });
  ['pointerup','pointercancel','pointerleave'].forEach(function(ev){
    stage.addEventListener(ev, function(){ if (drag){ drag = null; stage.classList.remove('is-drag'); } });
  });
  if ('IntersectionObserver' in window){
    new IntersectionObserver(function(en){ onScreen = en[0].isIntersecting; }, { threshold:0.02 }).observe(stage);
  }

  var last = performance.now();
  function frame(now){
    requestAnimationFrame(frame);
    if (!ready || !onScreen) return;
    if (!W || !canvas.width) resize();
    var dt = Math.min(0.05, (now - last)/1000); last = now;
    var t = (now - t0)/1000;

    /* gentle sway around the Africa-facing view — never rotates the continent out of frame */
    if (spin && !drag){
      var r = projection.rotate(), target = -19 + 13*Math.sin(t/13);
      projection.rotate([
        r[0] + (target - r[0]) * Math.min(1, dt*2.2),
        r[1] + (-4 - r[1]) * Math.min(1, dt*1.4),
        0
      ]);
    }
    ROT = d3.geoRotation(projection.rotate());

    ctx.clearRect(0,0,W,H);
    var tr = projection.translate(), cx = tr[0], cy = tr[1], R = projection.scale();
    var build = Math.min(1, t/1.1), DEG = Math.PI/180, rr = 1.05;

    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(cx,cy,R,0,Math.PI*2); ctx.fill();

    var a = -1;
    for (var i=0;i<dots.length;i++){
      var qq = ROT(dots[i]);
      if (Math.abs(qq[0]) > 90) continue;
      var la = qq[1]*DEG, lo = qq[0]*DEG, shade = Math.cos(lo), alpha = build*(0.10 + 0.30*shade);
      if (alpha !== a){ ctx.globalAlpha = alpha; a = alpha; }
      ctx.fillStyle = '#8fa39b';
      ctx.beginPath();
      ctx.arc(cx + R*Math.cos(la)*Math.sin(lo), cy - R*Math.sin(la), rr, 0, Math.PI*2);
      ctx.fill();
    }
    ctx.globalAlpha = 1;

    /* ── ONLY THE SELECTED COUNTRY IS OUTLINED ────────────────────────────
       This used to stroke all 54 nations every frame — 0.85px at 16% ink for
       the field and 1.15px of green for any nation with activity — which is
       not in the design at all, and it is what made the band look rough: the
       land dots ARE the drawing, and fifty-four outlines over them turn a
       quiet map into a diagram competing with itself. The dots are unchanged
       from the reference (15,000 samples, `#8fa39b`, alpha 0.10–0.40 by
       longitude); they only looked sparse because the outlines shouted.

       Africa is still fully clickable — the hit test is `d3.geoContains` over
       the same features, not over anything drawn. */
    if (sel){
      var hit = null;
      for (var k = 0; k < afr.length; k++){
        if (afr[k].properties.name === sel){ hit = afr[k]; break; }
      }
      if (hit){
        ctx.beginPath(); path(hit);
        ctx.fillStyle = 'rgba(35,123,34,.10)'; ctx.fill();
        ctx.lineWidth = 1.2; ctx.strokeStyle = 'rgba(35,123,34,.55)'; ctx.stroke();
      }
    }

    ctx.strokeStyle = 'rgba(16,41,44,.07)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(cx,cy,R,0,Math.PI*2); ctx.stroke();

    marks.forEach(function(m){
      /* hide near the limb: markers there foreshorten into each other */
      var p = Math.abs(ROT(m.ll)[0]) <= 68 ? projection(m.ll) : null;
      if (!p){ m.el.style.opacity = '0'; m.el.style.pointerEvents = 'none'; return; }
      m.el.style.opacity = String(build);
      m.el.style.pointerEvents = 'auto';
      /* ── TWO LABELS, LIKE THE REFERENCE, AND FOR THE SAME REASON ────────
         The design labels exactly two markers and leaves the rest to hover.
         Standing labels went on every voted nation here, which at 390px — the
         radius is bound by WIDTH, so the sphere is ~230px across — put Ghana's
         label completely behind Nigeria's. Overlapping type is worse than none.

         A decided award carries its label; where nothing is decided yet the
         busiest nation carries one, so a new edition is not a globe of
         unlabelled dots. Narrow stages keep only that one. Keyed off the
         STAGE's width, because that is what sets the radius. */
      var labelled = m.c.decided || (!anyDecided && m === busiest);
      m.el.classList.toggle('show-lb', labelled && (W > 560 || m === busiest));
      /* Label side derived from where the marker actually IS, not from a table
         keyed by name: a hardcoded side is wrong the moment the globe turns, and
         wrong for every nation nobody thought to add to it. */
      var nearLeft = p[0] < cx - R*0.55, nearRight = p[0] > cx + R*0.55, low = p[1] > cy + R*0.7;
      m.el.classList.toggle('node--lb-right', nearLeft);
      m.el.classList.toggle('node--lb-left',  nearRight);
      m.el.classList.toggle('node--lb-above', low && !nearLeft && !nearRight);
      m.el.style.left = p[0] + 'px';
      m.el.style.top  = p[1] + 'px';
    });
  }

  d3.json(stage.dataset.topojson).then(function(topo){
    var features = topojson.feature(topo, topo.objects.countries).features;
    afr   = features.filter(function(f){ return AFRICA.has(f.properties.name); });
    marks = buildMarks(afr);
    dots  = buildDots(features);
    resize(); ready = true; t0 = performance.now(); last = performance.now();
  });
  requestAnimationFrame(frame);
})();
