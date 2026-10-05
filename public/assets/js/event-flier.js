/* ══════════════════════════════════════════════════════════════════════════════
   THE "I WILL BE THERE" FLIER — the dialog's behaviour (pages/events/_flier.twig)
   ══════════════════════════════════════════════════════════════════════════════

   Rebuilt from the destroyed page's generator; every rule its 40 tests held is kept:

   · OPENED from `data-ag-do="flier-open"` (the share box, the success screen), a bookmarked
     `#flier`, and `#flier=<token>` from the account area — the fragment never reaches a
     server, and it is removed from the address bar once read so a copied URL does not
     carry somebody's credential.
   · A DIALOG: focus moves in and is trapped, the page behind does not scroll, Escape, the
     scrim, the X and the BACK GESTURE close it (opening pushes a history entry, and
     popstate does the closing so the address bar and the sheet cannot disagree), and focus
     goes back where it came from.
   · A NAME is required only when there is no token — the button, the start guard and the
     request agree, because the server takes the name out of the token when there is one.
   · THE STYLE follows the shape: `tint` re-colours a photo, so the no-photo design cannot
     offer it, and changing the shape re-checks the style. The chips are drawn from this
     event's real colours (the JSON block, never an attribute).
   · GENERATING is its own screen with only Cancel, and Cancel aborts the request.
   · THE REFRAME comes after the first render, opened where the server put the frame
     (X-Flier-Focus); "we framed this on the face" only when a face was found.
   · THE TRANSPORT LADDER: multipart, then base64, then no photo — only on statuses this
     application never emits (405 406 415 501; 413 skips the larger body), never on a 403,
     which is CSRF. The no-photo fallback SAYS the photo was dropped.
   · SHARE is chosen by asking the device (canShare with a real File); where it cannot, Save
     is primary and the caption is copied, with one sentence saying why — never a silent
     fall back to a wa.me link, which cannot carry an image.
   · THE CAPTION goes in the message, not on the image, and opens with a blank first line.
   · A FAILURE keeps everything typed: only the screen changes. */
(function () {
  'use strict';

  var dlg = document.querySelector('[data-flo]');
  var root = document.querySelector('[data-ed]');
  if (!dlg || !root) return;
  var slug = root.getAttribute('data-slug'), csrf = root.getAttribute('data-csrf');
  var data = {};
  try { data = JSON.parse((document.getElementById('ed-flier-data') || {}).textContent || '{}'); } catch (e) { data = {}; }
  var styles = data.styles || {}, defaults = data.defaults || {};

  function q(sel) { return dlg.querySelector(sel); }
  function qa(sel) { return Array.prototype.slice.call(dlg.querySelectorAll(sel)); }

  var S = { stage: 'entry', fmt: 'plain', style: defaults.plain || 'paper', token: '', file: null, photoUrl: '',
            fx: null, fy: null, faced: false, blob: null, pngUrl: '', ctrl: null, pushed: false, last: null };

  var nameIn = q('[data-flo-name]'), cap = q('[data-flo-cap]'), photo = q('[data-flo-photo]');
  cap.value = '\n' + (data.title || '') + '\n' + (data.date || '') + '\n' + location.origin + '/events/' + slug;

  var canShareFile = false;
  try {
    var probe = new File([new Uint8Array([137, 80, 78, 71])], 'f.png', { type: 'image/png' });
    canShareFile = !!(navigator.canShare && navigator.canShare({ files: [probe] }));
  } catch (e) { canShareFile = false; }

  function stage(s) {
    S.stage = s;
    qa('[data-flo-stage]').forEach(function (el) { el.hidden = el.getAttribute('data-flo-stage') !== s; });
    if (s === 'ready') {
      q('[data-flo-share-row]').hidden = !canShareFile;
      q('[data-flo-two-step]').hidden = canShareFile;
      q('[data-flo-move]').hidden = !(S.file && S.fmt !== 'plain');
    }
    if (s === 'frame') {
      var p = q('[data-flo-frame-copy]');
      p.textContent = p.getAttribute(S.faced ? 'data-t-faced' : 'data-t-plain');
      q('[data-flo-frame]').classList.toggle('flo__frame--sq', S.fmt === 'square');
      q('[data-flo-frame-img]').src = S.photoUrl;
      frameImg();
    }
  }
  function frameImg() {
    var x = S.fx === null ? 0.5 : S.fx, y = S.fy === null ? 0.3 : S.fy;
    q('[data-flo-frame-img]').style.objectPosition = (x * 100) + '% ' + (y * 100) + '%';
    q('[data-flo-y]').value = Math.round(y * 100);
  }
  function makeOk() { q('[data-flo-make]').disabled = !S.token && !nameIn.value.trim(); }
  nameIn.addEventListener('input', makeOk);

  /* ── shape and style ── */
  function list() { return styles[S.fmt] || []; }
  function drawStyles() {
    var box = q('[data-flo-styles]');
    box.innerHTML = '';
    if (!list().some(function (s) { return s.key === S.style; })) S.style = defaults[S.fmt] || 'paper';
    list().forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'flo__opt'; b.setAttribute('role', 'radio');
      b.setAttribute('data-fl-style', s.key);
      b.setAttribute('aria-checked', s.key === S.style ? 'true' : 'false');
      b.tabIndex = s.key === S.style ? 0 : -1;
      var sw = document.createElement('span'); sw.className = 'flo__sw'; sw.setAttribute('aria-hidden', 'true');
      sw.style.background = s.ground;
      var i1 = document.createElement('i'), i2 = document.createElement('i'), bb = document.createElement('b');
      i1.style.background = s.ink; i2.style.background = s.ink; bb.style.background = s.accent;
      sw.appendChild(i1); sw.appendChild(i2); sw.appendChild(bb);
      var n = document.createElement('span'); n.textContent = s.label;
      var sm = document.createElement('small'); sm.textContent = s.note;
      b.appendChild(sw); b.appendChild(n); b.appendChild(sm);
      box.appendChild(b);
    });
  }
  function setFmt(f) {
    S.fmt = f;
    qa('[data-fl-fmt]').forEach(function (b) { var on = b.getAttribute('data-fl-fmt') === f; b.setAttribute('aria-checked', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
    q('[data-flo-photo-wrap]').hidden = f === 'plain';
    drawStyles();
  }
  function radioKeys(box, attr, apply) {
    box.addEventListener('keydown', function (e) {
      var els = Array.prototype.slice.call(box.querySelectorAll('[' + attr + ']'));
      var at = els.indexOf(document.activeElement), d = 0;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') d = 1;
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') d = -1;
      if (!d || at < 0) return;
      e.preventDefault();
      var nx = els[(at + d + els.length) % els.length];
      apply(nx.getAttribute(attr));
      var again = box.querySelector('[' + attr + '="' + nx.getAttribute(attr) + '"]');
      if (again) again.focus();
    });
  }
  radioKeys(q('[data-flo-fmts]'), 'data-fl-fmt', setFmt);
  radioKeys(q('[data-flo-styles]'), 'data-fl-style', function (k) { S.style = k; drawStyles(); });
  q('[data-flo-fmts]').addEventListener('click', function (e) { var b = e.target.closest('[data-fl-fmt]'); if (b) setFmt(b.getAttribute('data-fl-fmt')); });
  q('[data-flo-styles]').addEventListener('click', function (e) { var b = e.target.closest('[data-fl-style]'); if (b) { S.style = b.getAttribute('data-fl-style'); drawStyles(); } });

  photo.addEventListener('change', function () {
    var f = photo.files && photo.files[0];
    if (S.photoUrl) URL.revokeObjectURL(S.photoUrl);
    S.file = f || null; S.photoUrl = f ? URL.createObjectURL(f) : '';
    // A new photo makes the old framing meaningless: back to null so the server finds the face.
    S.fx = null; S.fy = null; S.faced = false;
  });

  /* ── the frame ── */
  var frame = q('[data-flo-frame]');
  frame.addEventListener('pointerdown', function (e) {
    var box = frame.getBoundingClientRect();
    var move = function (ev) {
      S.fx = Math.min(1, Math.max(0, (ev.clientX - box.left) / box.width));
      S.fy = Math.min(1, Math.max(0, (ev.clientY - box.top) / box.height));
      frameImg();
    };
    move(e);
    var up = function () { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  });
  q('[data-flo-y]').addEventListener('input', function (e) { S.fy = e.target.value / 100; if (S.fx === null) S.fx = 0.5; frameImg(); });

  /* ── open / close ── */
  function open() {
    if (!dlg.hidden) return;
    S.last = document.activeElement;
    dlg.hidden = false;
    document.documentElement.style.overflow = 'hidden';
    try { history.pushState({ agFlier: 1 }, '', '#flier'); S.pushed = true; } catch (e) { S.pushed = false; }
    makeOk();
    (S.stage === 'entry' ? nameIn : q('[data-flo-closer]')).focus();
  }
  function shut() {
    dlg.hidden = true;
    document.documentElement.style.overflow = '';
    if (S.ctrl) { S.ctrl.abort(); S.ctrl = null; }
    if (S.last && S.last.focus) { try { S.last.focus(); } catch (e) {} }
  }
  function close() {
    if (dlg.hidden) return;
    if (S.pushed) { S.pushed = false; try { history.back(); return; } catch (e) {} }
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
    shut();
  }
  window.addEventListener('popstate', function () { S.pushed = false; if (!dlg.hidden) shut(); });
  document.addEventListener('keydown', function (e) {
    if (dlg.hidden) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key !== 'Tab') return;
    var f = Array.prototype.filter.call(dlg.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), textarea, select, [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetWidth > 0 || el.offsetHeight > 0; });
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
    else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
  });
  window.addEventListener('ag:flier-token', function (e) {
    S.token = (e.detail && e.detail.token) || '';
    if (e.detail && e.detail.name) nameIn.value = e.detail.name;
    q('[data-flo-open-copy]').hidden = !!S.token;
    q('[data-flo-token-copy]').hidden = !S.token;
  });

  /* ── render: the transport ladder ── */
  function filtered(c) { return c === 405 || c === 406 || c === 415 || c === 501; }
  function fields() {
    var o = { _token: csrf, fmt: S.fmt, style: S.style };
    if (S.token) o.t = S.token; else o.name = nameIn.value.trim();
    if (S.fx !== null) o.focus_x = String(S.fx);
    if (S.fy !== null) o.focus_y = String(S.fy);
    return o;
  }
  function b64(file) {
    return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(String(r.result || '')); }; r.onerror = function () { rej(r.error); }; r.readAsDataURL(file); });
  }
  function send(kind) {
    var url = '/events/' + encodeURIComponent(slug) + '/flier', f = fields();
    if (kind === 'multipart') {
      var fd = new FormData();
      Object.keys(f).forEach(function (k) { fd.append(k, f[k]); });
      if (S.file) fd.append('photo', S.file);
      return fetch(url, { method: 'POST', body: fd, signal: S.ctrl.signal, credentials: 'same-origin' });
    }
    var go = function (extra) {
      var body = new URLSearchParams();
      Object.keys(f).forEach(function (k) { body.append(k, f[k]); });
      if (extra) body.append('photo_b64', extra);
      return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                          body: body.toString(), signal: S.ctrl.signal, credentials: 'same-origin' });
    };
    return kind === 'b64' && S.file ? b64(S.file).then(go) : go('');
  }
  function fail(msg) {
    var p = q('[data-flo-fail]');
    p.textContent = msg || p.getAttribute('data-t-default');
    stage('failed');
  }
  async function render() {
    q('[data-flo-dropped]').hidden = true;
    stage('busy');
    S.ctrl = new AbortController();
    var plan = (S.fmt !== 'plain' && S.file) ? ['multipart', 'b64', 'none'] : ['urlencoded'];
    try {
      var r = null, dropped = false;
      for (var i = 0; i < plan.length; i++) {
        r = await send(plan[i] === 'none' ? 'urlencoded' : plan[i]);
        if (r.ok) { dropped = plan[i] === 'none'; break; }
        if (!filtered(r.status) && r.status !== 413) break;
        if (r.status === 413 && plan[i] === 'multipart') i++;
      }
      if (!r.ok) {
        var d = await r.json().catch(function () { return {}; });
        fail(d.message || '');
        return;
      }
      if (S.pngUrl) URL.revokeObjectURL(S.pngUrl);
      S.blob = await r.blob();
      S.pngUrl = URL.createObjectURL(S.blob);
      S.token = r.headers.get('X-Flier-Token') || S.token;
      var st = r.headers.get('X-Flier-Style'); if (st) S.style = st;
      var fp = (r.headers.get('X-Flier-Focus') || '').split(',');
      if (fp.length === 2 && fp[0] !== '' && fp[1] !== '') {
        var own = S.fx !== null || S.fy !== null;
        S.fx = parseFloat(fp[0]); S.fy = parseFloat(fp[1]); S.faced = !own;
      } else { S.faced = false; }
      q('[data-flo-prev]').src = S.pngUrl;
      q('[data-flo-dropped]').hidden = !dropped;
      stage('ready');
    } catch (e) {
      if (e && e.name === 'AbortError') { stage('entry'); return; }
      fail('');
    } finally { S.ctrl = null; }
  }

  function save() {
    if (!S.pngUrl) return;
    var a = document.createElement('a');
    a.href = S.pngUrl; a.download = 'i-will-be-there-' + slug + '.png';
    document.body.appendChild(a); a.click(); a.remove();
    if (!canShareFile) copy();
  }
  function copy() {
    var b = q('[data-ag-do="flier-copy"]');
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(cap.value).then(function () {
      var l = b.textContent; b.textContent = b.getAttribute('data-t-done'); setTimeout(function () { b.textContent = l; }, 2400);
    }, function () {});
  }
  async function share() {
    if (!S.blob) return;
    try { await navigator.share({ files: [new File([S.blob], 'i-will-be-there-' + slug + '.png', { type: 'image/png' })], text: cap.value }); }
    catch (e) { if (e && e.name === 'AbortError') return; }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ag-do]');
    if (!b) return;
    switch (b.getAttribute('data-ag-do')) {
      case 'flier-open':   open(); break;
      case 'flier-close':  close(); break;
      case 'flier-make':
        if (!S.token && !nameIn.value.trim()) { q('[data-flo-err]').textContent = ''; return; }
        render(); break;
      case 'flier-render': render(); break;
      case 'flier-cancel': if (S.ctrl) S.ctrl.abort(); stage('entry'); break;
      case 'flier-back':   stage(S.stage === 'frame' && S.pngUrl ? 'ready' : 'entry'); break;
      case 'flier-frame':  stage('frame'); break;
      case 'flier-save':   save(); break;
      case 'flier-copy':   copy(); break;
      case 'flier-share':  share(); break;
    }
  });

  drawStyles();
  makeOk();
  var m = (location.hash || '').match(/flier=([^&]+)/);
  if (m) {
    S.token = decodeURIComponent(m[1]);
    q('[data-flo-open-copy]').hidden = true; q('[data-flo-token-copy]').hidden = false;
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
    open();
  } else if (location.hash === '#flier') {
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
    open();
  }
})();
