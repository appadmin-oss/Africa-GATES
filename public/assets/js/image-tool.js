/* ══════════════════════════════════════════════════════════════════════════════
   THE IMAGE EDITOR · any <input type="file" data-ag-image="<profile>">
   Support\ImageProfiles names the shape; UploadService::uploadProcessed() applies it.
   ══════════════════════════════════════════════════════════════════════════════

   Every upload used to keep whatever shape arrived and be cropped by CSS at the centre, on
   every screen differently — an ad's square cut from the middle of a landscape photo, the
   product at its edge, and nobody uploading it could see it happen. Here the person chooses:

   · DRAG the photo in the frame (one finger, or a mouse), PINCH or use the slider and the
     − / + buttons to zoom, TURN it a quarter at a time, RESET. Every drag has a control that
     does the same thing (WCAG 2.5.7), and the frame takes the arrow keys and + / − too.
   · It says what the cut is in real pixels, and says plainly when it is too small for the
     profile — the server refuses the same cut with the same numbers.
   · THE PHOTO IS MADE SMALL HERE, before it is sent. A phone photo is 3–12MB; the profile's
     result is a few hundred KB. On the connections this site is mostly read on, that is the
     difference between an upload that finishes and one that times out. The chosen square is
     drawn into a canvas at the profile's size (never larger than the cut itself, so nothing
     is upscaled) and put back into the same file input, so the form posts it as if it had
     been chosen that way.
   · Where a browser cannot replace a file input's file, the ORIGINAL is sent with the cut as
     fractions in `<name>_crop`, and the server makes the same cut.

   With no script the input is an ordinary file field, and the server cuts the centre.
   Fires `ag:image` on the input with {url} — a small preview of the result — for a live
   preview (ads-builder.js).
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var MAX_SRC = 4096;       // longest side kept in memory: a 48MP photo must not exhaust a phone
  var canSwap = (function () { try { return typeof DataTransfer === 'function' && !!new DataTransfer().items; } catch (e) { return false; } })();

  function el(tag, cls, attrs) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (attrs) for (var k in attrs) n.setAttribute(k, attrs[k]);
    return n;
  }
  function t(input, key, fallback) { return input.getAttribute('data-t-' + key) || fallback; }

  function setup(input) {
    if (input._agImage) return;
    input._agImage = true;
    var aspect = parseFloat(input.getAttribute('data-aspect') || '1') || 1;
    var min = parseInt(input.getAttribute('data-min') || '0', 10) || 0;
    var out = parseInt(input.getAttribute('data-out') || '1080', 10) || 1080;
    var round = input.hasAttribute('data-round');

    var crop = input.form && input.form.querySelector('input[type="hidden"][name="' + input.name + '_crop"]');
    if (!crop) {
      crop = el('input', '', { type: 'hidden', name: input.name + '_crop' });
      input.insertAdjacentElement('afterend', crop);
    }

    var box = el('div', 'ag-imgtool', { role: 'group', 'aria-label': t(input, 'group', 'Adjust your image'), hidden: '' });
    var stage = el('div', 'ag-imgtool__stage' + (round ? ' ag-imgtool__stage--round' : ''), {
      tabindex: '0', 'aria-describedby': '', style: 'aspect-ratio:' + aspect
    });
    var canvas = el('canvas', 'ag-imgtool__cv', { 'aria-hidden': 'true' });
    stage.appendChild(canvas);
    var help = el('p', 'ag-imgtool__help', { id: (input.id || input.name) + '-imgtool-help' });
    help.textContent = t(input, 'help', 'Drag to move, pinch or use the slider to zoom. Arrow keys move it too.');
    stage.setAttribute('aria-describedby', help.id);
    stage.setAttribute('aria-label', t(input, 'stage', 'The part of your photo that will be used'));

    var bar = el('div', 'ag-imgtool__bar');
    function btn(label, svg) {
      var b = el('button', 'ag-imgtool__btn', { type: 'button', 'aria-label': label });
      b.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + svg + '</svg>';
      return b;
    }
    var bOut = btn(t(input, 'zoom-out', 'Zoom out'), '<path d="M5 12h14"/>');
    var range = el('input', 'ag-imgtool__zoom', { type: 'range', min: '1', max: '4', step: '0.01', value: '1', 'aria-label': t(input, 'zoom', 'Zoom') });
    var bIn = btn(t(input, 'zoom-in', 'Zoom in'), '<path d="M12 5v14M5 12h14"/>');
    var bTurn = btn(t(input, 'turn', 'Turn a quarter'), '<path d="M20 11a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>');
    var bReset = btn(t(input, 'reset', 'Start again'), '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>');
    bar.appendChild(bOut); bar.appendChild(range); bar.appendChild(bIn); bar.appendChild(bTurn); bar.appendChild(bReset);
    var info = el('p', 'ag-imgtool__info', { 'aria-live': 'polite' });
    box.appendChild(stage); box.appendChild(bar); box.appendChild(help); box.appendChild(info);
    (crop.nextSibling ? crop.parentNode.insertBefore(box, crop.nextSibling) : crop.parentNode.appendChild(box));

    var S = null;           // { src: canvas, scale: natural/src, nw, nh } for the turned photo
    var orig = null;        // the HTMLImageElement, oriented by the browser
    var name = 'image';
    var turn = 0, zoom = 1, cx = 0.5, cy = 0.5;
    var swapping = false, outTimer = 0;

    function build() {
      var w = orig.naturalWidth, h = orig.naturalHeight;
      var nw = turn % 180 ? h : w, nh = turn % 180 ? w : h;
      var k = Math.min(1, MAX_SRC / Math.max(nw, nh));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(nw * k)); c.height = Math.max(1, Math.round(nh * k));
      var g = c.getContext('2d');
      g.translate(c.width / 2, c.height / 2);
      g.rotate(turn * Math.PI / 180);
      var dw = (turn % 180 ? c.height : c.width), dh = (turn % 180 ? c.width : c.height);
      g.drawImage(orig, -dw / 2, -dh / 2, dw, dh);
      S = { src: c, nw: nw, nh: nh };
    }

    // The cut, in fractions of the turned photo.
    function area() {
      var pa = S.nw / S.nh, fw, fh;
      if (pa > aspect) { fh = 1 / zoom; fw = fh * aspect / pa; } else { fw = 1 / zoom; fh = fw * pa / aspect; }
      cx = Math.min(1 - fw / 2, Math.max(fw / 2, cx));
      cy = Math.min(1 - fh / 2, Math.max(fh / 2, cy));
      return { x: cx - fw / 2, y: cy - fh / 2, w: fw, h: fh };
    }

    function draw() {
      if (!S) return;
      var r = stage.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
      canvas.width = Math.max(1, Math.round(r.width * dpr)); canvas.height = Math.max(1, Math.round(r.height * dpr));
      var a = area(), g = canvas.getContext('2d');
      g.imageSmoothingQuality = 'high';
      g.drawImage(S.src, a.x * S.src.width, a.y * S.src.height, a.w * S.src.width, a.h * S.src.height, 0, 0, canvas.width, canvas.height);
      var pw = Math.round(a.w * S.nw), ph = Math.round(a.h * S.nh);
      var small = min && Math.min(pw, ph) < min;
      info.textContent = small
        ? t(input, 'small', 'Too small: this part is %w% × %h% px and needs at least %m% px on each side. Zoom out, or choose a larger photo.')
            .replace('%w%', pw).replace('%h%', ph).replace('%m%', min)
        : t(input, 'size', 'Your photo is %W% × %H% px; this part is %w% × %h% px.')
            .replace('%W%', S.nw).replace('%H%', S.nh).replace('%w%', pw).replace('%h%', ph);
      info.classList.toggle('ag-imgtool__info--bad', !!small);
      stage.classList.toggle('ag-imgtool__stage--bad', !!small);
      input.setCustomValidity(small ? info.textContent : '');
      range.value = String(zoom);
      clearTimeout(outTimer);
      outTimer = setTimeout(output, 220);
    }

    function output() {
      var a = area();
      var f = [a.x, a.y, a.w, a.h].map(function (v) { return v.toFixed(5); }).join(',') + (turn ? ',' + turn : '');
      var pw = a.w * S.nw, ow = Math.max(1, Math.round(Math.min(out, pw))), oh = Math.max(1, Math.round(ow / aspect));
      var c = document.createElement('canvas'); c.width = ow; c.height = oh;
      var g = c.getContext('2d'); g.imageSmoothingQuality = 'high';
      g.drawImage(S.src, a.x * S.src.width, a.y * S.src.height, a.w * S.src.width, a.h * S.src.height, 0, 0, ow, oh);
      var p = document.createElement('canvas'), pk = Math.min(1, 600 / ow);
      p.width = Math.round(ow * pk); p.height = Math.round(oh * pk);
      p.getContext('2d').drawImage(c, 0, 0, p.width, p.height);
      input.dispatchEvent(new CustomEvent('ag:image', { bubbles: true, detail: { url: p.toDataURL('image/jpeg', 0.85) } }));
      if (!canSwap || !c.toBlob) { crop.value = f; return; }
      c.toBlob(function (blob) {
        if (!blob) { crop.value = f; return; }
        try {
          var dt = new DataTransfer();
          dt.items.add(new File([blob], name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
          swapping = true; input.files = dt.files; swapping = false;
          crop.value = '';           // the file IS the cut now
        } catch (e) { swapping = false; crop.value = f; }
      }, 'image/jpeg', 0.9);
    }

    function load(file) {
      if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) { box.hidden = true; return; }
      name = file.name || 'image';
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        orig = img; turn = 0; zoom = 1; cx = cy = 0.5;
        build(); box.hidden = false; draw();
      };
      img.onerror = function () { URL.revokeObjectURL(url); box.hidden = true; };
      img.src = url;
    }

    input.addEventListener('change', function () { if (!swapping) load(input.files && input.files[0]); });

    // ── moving it ──────────────────────────────────────────────────────────
    var pts = {}, last = null, pinch = null;
    function n(o) { return Object.keys(o).length; }
    stage.addEventListener('pointerdown', function (e) {
      if (!S) return;
      e.preventDefault();          // or a drag past the frame selects the page's text
      stage.focus({ preventScroll: true });
      stage.setPointerCapture(e.pointerId);
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      last = { x: e.clientX, y: e.clientY };
      if (n(pts) === 2) { var p = Object.values(pts); pinch = { d: Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y), z: zoom }; }
    });
    stage.addEventListener('pointermove', function (e) {
      if (!S || !pts[e.pointerId]) return;
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      if (n(pts) === 2 && pinch) {
        var p = Object.values(pts), d = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
        zoom = Math.min(4, Math.max(1, pinch.z * d / Math.max(1, pinch.d))); draw(); return;
      }
      var r = stage.getBoundingClientRect(), a = area();
      cx -= (e.clientX - last.x) / r.width * a.w; cy -= (e.clientY - last.y) / r.height * a.h;
      last = { x: e.clientX, y: e.clientY };
      draw();
    });
    function up(e) { delete pts[e.pointerId]; if (n(pts) < 2) pinch = null; var p = Object.values(pts)[0]; last = p ? { x: p.x, y: p.y } : null; }
    stage.addEventListener('pointerup', up);
    stage.addEventListener('pointercancel', up);
    stage.addEventListener('keydown', function (e) {
      if (!S) return;
      var a = area(), step = e.shiftKey ? 0.1 : 0.025, used = true;
      if (e.key === 'ArrowLeft') cx -= a.w * step * 4;
      else if (e.key === 'ArrowRight') cx += a.w * step * 4;
      else if (e.key === 'ArrowUp') cy -= a.h * step * 4;
      else if (e.key === 'ArrowDown') cy += a.h * step * 4;
      else if (e.key === '+' || e.key === '=') zoom = Math.min(4, zoom + 0.1);
      else if (e.key === '-' || e.key === '_') zoom = Math.max(1, zoom - 0.1);
      else used = false;
      if (used) { e.preventDefault(); draw(); }
    });
    range.addEventListener('input', function () { zoom = parseFloat(range.value) || 1; draw(); });
    bIn.addEventListener('click', function () { zoom = Math.min(4, zoom + 0.25); draw(); });
    bOut.addEventListener('click', function () { zoom = Math.max(1, zoom - 0.25); draw(); });
    bTurn.addEventListener('click', function () { if (!orig) return; turn = (turn + 90) % 360; cx = cy = 0.5; build(); draw(); });
    bReset.addEventListener('click', function () { if (!orig) return; turn = 0; zoom = 1; cx = cy = 0.5; build(); draw(); });
    if (window.ResizeObserver) new ResizeObserver(function () { if (S && !box.hidden) draw(); }).observe(stage);
  }

  function scan(root) { Array.prototype.forEach.call((root || document).querySelectorAll('input[type="file"][data-ag-image]'), setup); }
  scan();
  window.AGImageTool = { scan: scan };
})();
