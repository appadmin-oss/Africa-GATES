/* ════════════════════════════════════════════════════════════════
   Africa GATES — "We are Africa" globe band
   Dotted orthographic globe, shown in full, with the 54 African nations
   outlined (brighter where there is activity) and the verification nodes
   plus their ballot origins marked. Interaction lives on the globe:
   drag to rotate, click a country for its card. No zoom.

   Requires d3 (v7, includes d3-geo) and topojson-client, loaded before
   this file — the VENDORED, version-pinned copies:

     /assets/js/vendor/d3-7.9.0.min.js
     /assets/js/vendor/topojson-client-3.1.0.min.js

   Geometry: Natural Earth countries-110m (public domain), served from
   /assets/geo/countries-110m.json. Both the scripts and the geometry are
   self-hosted rather than fetched from a CDN — see
   public/assets/js/vendor/PROVENANCE.md for why that is not optional here.

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

  /* Fallback city set — replace by passing data-cities from the controller.
     Only `primary` entries are plotted; the rest stay available for the
     country cards and for a denser variant of this band. */
  var FALLBACK = [
    {id:'lagos',   name:'Lagos',         country:'Nigeria',        lon:3.39,  lat:6.52,   hub:true, ballots:41280, verify_seconds:1.1, primary:true},
    {id:'nairobi', name:'Nairobi',       country:'Kenya',          lon:36.82, lat:-1.29,  hub:true, ballots:33940, verify_seconds:1.3, primary:true},
    {id:'dakar',   name:'Dakar',         country:'Senegal',        lon:-17.45,lat:14.69,  to:'lagos',   ballots:6420,  verify_seconds:1.5, primary:true},
    {id:'kin',     name:'Kinshasa',      country:'Dem. Rep. Congo',lon:15.31, lat:-4.32,  to:'lagos',   ballots:4960,  verify_seconds:1.9, primary:true},
    {id:'addis',   name:'Addis Ababa',   country:'Ethiopia',       lon:38.75, lat:9.03,   to:'nairobi', ballots:6740,  verify_seconds:1.6, primary:true},
    {id:'jnb',     name:'Johannesburg',  country:'South Africa',   lon:28.05, lat:-26.20, to:'nairobi', ballots:18730, verify_seconds:1.2, primary:true},
    {id:'abidjan', name:'Abidjan',       country:'Côte d\'Ivoire', lon:-4.02, lat:5.32,   to:'lagos',   ballots:5310,  verify_seconds:1.4},
    {id:'accra',   name:'Accra',         country:'Ghana',          lon:-0.19, lat:5.60,   to:'lagos',   ballots:7880,  verify_seconds:1.2},
    {id:'douala',  name:'Douala',        country:'Cameroon',       lon:9.71,  lat:4.05,   to:'lagos',   ballots:3120,  verify_seconds:2.1},
    {id:'cairo',   name:'Cairo',         country:'Egypt',          lon:31.24, lat:30.04,  to:'nairobi', ballots:21160, verify_seconds:1.4},
    {id:'kampala', name:'Kampala',       country:'Uganda',         lon:32.58, lat:0.35,   to:'nairobi', ballots:4480,  verify_seconds:1.5},
    {id:'dar',     name:'Dar es Salaam', country:'Tanzania',       lon:39.28, lat:-6.79,  to:'nairobi', ballots:5230,  verify_seconds:1.7},
    {id:'casa',    name:'Casablanca',    country:'Morocco',        lon:-7.59, lat:33.57,  to:'lagos',   ballots:5090,  verify_seconds:1.8},
    {id:'harare',  name:'Harare',        country:'Zimbabwe',       lon:31.05, lat:-17.83, to:'nairobi', ballots:3640,  verify_seconds:1.8},
    {id:'lusaka',  name:'Lusaka',        country:'Zambia',         lon:28.28, lat:-15.41, to:'nairobi', ballots:2980,  verify_seconds:1.9},
    {id:'kigali',  name:'Kigali',        country:'Rwanda',         lon:30.06, lat:-1.94,  to:'nairobi', ballots:2410,  verify_seconds:1.6}
  ];

  var cities;
  try { cities = stage.dataset.cities ? JSON.parse(stage.dataset.cities) : FALLBACK; }
  catch (e) { cities = FALLBACK; }
  cities.forEach(function(c){ c.ll = [+c.lon, +c.lat]; if (c.hub) c.primary = true; });

  var byId = {}; cities.forEach(function(c){ byId[c.id] = c; });
  var ACTIVE = new Set(cities.map(function(c){ return c.country; }));
  var FLOWS  = cities.filter(function(c){ return c.to && byId[c.to]; });

  var projection = d3.geoOrthographic().rotate([-19,-4,0]).precision(0.5),
      path = d3.geoPath(projection, ctx),
      fmt  = d3.format(','),
      W=0,H=0,dots=[],afr=[],ready=false,t0=performance.now(),
      spin=!reduced, base=1, drag=null, sel=null, live=true,
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

  /* label side per city, so hub labels never collide as the globe turns */
  var LB_SIDE = { lagos:'above', kin:'left', dakar:'left', addis:'right', nairobi:'right', jnb:'above' };

  var CHECK = '<svg viewBox="0 0 20 20" fill="none" stroke="#237b22" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 2.8 16 5v5c0 3.2-2.3 5.9-6 7-3.7-1.1-6-3.8-6-7V5z"/><path d="M7.5 10 9.4 11.8 12.7 8.4"/></svg>';
  var els = {};
  cities.filter(function(c){ return c.primary; }).forEach(function(c){
    var el = document.createElement('button');
    el.type = 'button';
    el.className = 'node ' + (c.hub ? 'node--h' : 'node--v');
    el.style.opacity = '0';
    el.setAttribute('aria-label', c.name + (c.hub ? ' verification node' : ' ballot origin'));
    if (LB_SIDE[c.id]) el.classList.add('node--lb-' + LB_SIDE[c.id]);
    el.innerHTML = (c.hub ? CHECK : '') + '<span class="node__lb">' + c.name + '</span>';
    el.addEventListener('click', function(e){ e.stopPropagation(); openCountry(c.country); });
    layer.appendChild(el);
    els[c.id] = el;
  });

  function q(sel){ return card.querySelector(sel); }
  function openCountry(name){
    var list = cities.filter(function(c){ return c.country === name; });
    var ballots = list.reduce(function(s,c){ return s + (+c.ballots || 0); }, 0);
    var node = list.filter(function(c){ return c.hub; })[0] || (list[0] && byId[list[0].to]);
    q('[data-globe-name]').textContent   = name;
    q('[data-globe-cities]').textContent = list.length ? list.map(function(c){ return c.name; }).join(' · ') : 'No activity yet';
    q('[data-globe-ballots]').textContent= ballots ? fmt(ballots) : '—';
    q('[data-globe-node]').textContent   = node ? node.name : '—';
    q('[data-globe-verify]').textContent = list.length ? d3.mean(list, function(c){ return +c.verify_seconds; }).toFixed(1) + ' s' : '—';
    sel = name; card.classList.add('is-in'); spin = false;
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
    if (e.target.closest('.ccard')) return;
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
    new IntersectionObserver(function(en){ live = en[0].isIntersecting; }, { threshold:0.02 }).observe(stage);
  }

  function visible(ll){ return Math.abs(ROT(ll)[0]) <= 90; }

  var last = performance.now();
  function frame(now){
    requestAnimationFrame(frame);
    if (!ready || !live) return;
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

    afr.forEach(function(f){
      var on = ACTIVE.has(f.properties.name), hi = sel === f.properties.name;
      ctx.beginPath(); path(f);
      ctx.lineWidth   = hi ? 1.6 : (on ? 1.15 : 0.85);
      ctx.strokeStyle = hi ? '#1a6118' : (on ? 'rgba(35,123,34,.62)' : 'rgba(16,41,44,.16)');
      ctx.stroke();
      if (hi){ ctx.fillStyle = 'rgba(35,123,34,.07)'; ctx.fill(); }
    });

    ctx.globalAlpha = 0.5; ctx.strokeStyle = 'rgba(35,123,34,.4)'; ctx.lineWidth = 1;
    FLOWS.forEach(function(c){
      if (!c.primary) return;
      var interp = d3.geoInterpolate(c.ll, byId[c.to].ll), pen = false;
      ctx.beginPath();
      for (var s=0;s<=26;s++){
        var ll = interp(s/26);
        if (!visible(ll)){ pen = false; continue; }
        var p = projection(ll);
        if (!p){ pen = false; continue; }
        pen ? ctx.lineTo(p[0],p[1]) : (ctx.moveTo(p[0],p[1]), pen = true);
      }
      ctx.stroke();
    });
    ctx.globalAlpha = 1;

    ctx.strokeStyle = 'rgba(16,41,44,.07)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(cx,cy,R,0,Math.PI*2); ctx.stroke();

    cities.forEach(function(c){
      var el = els[c.id];
      /* ── ONLY PLOTTED CITIES HAVE AN ELEMENT ──────────────────────────
         `els` is built from `cities.filter(primary)`, so every non-primary
         city — 10 of the 16 in the fallback set — has no element here. Without
         this guard `el.style` threw on each of them, EVERY FRAME: ~600
         exceptions a second at 60fps.

         It hid because the throw happens inside this forEach, which aborts the
         rest of the frame's marker pass and nothing else. The six primaries are
         first in the array, so they were already positioned by the time it
         threw, the globe kept animating, and the band looked correct — the only
         symptom was a console filling up. Anything added to frame() after this
         loop would silently never have run. */
      if (!el) return;
      /* hide near the limb: markers there foreshorten into each other */
      var p = (c.primary && Math.abs(ROT(c.ll)[0]) <= 68) ? projection(c.ll) : null;
      if (!p){ el.style.opacity = '0'; el.style.pointerEvents = 'none'; return; }
      el.style.opacity = String(build);
      el.style.pointerEvents = 'auto';
      el.classList.toggle('show-lb', !!c.hub);   /* hubs always labelled; origins on hover */
      el.style.left = p[0] + 'px';
      el.style.top  = p[1] + 'px';
    });
  }

  d3.json(stage.dataset.topojson).then(function(topo){
    var features = topojson.feature(topo, topo.objects.countries).features;
    afr  = features.filter(function(f){ return AFRICA.has(f.properties.name); });
    dots = buildDots(features);
    resize(); ready = true; t0 = performance.now(); last = performance.now();
  });
  requestAnimationFrame(frame);
})();
