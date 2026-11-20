/* ══════════════════════════════════════════════════════════════════════════════
   THE AD BUILDER'S LIVE PREVIEW · pages/account/ads-new.twig
   ══════════════════════════════════════════════════════════════════════════════

   Every ad builder shows the ad as you write it (Meta's preview pane), because a headline
   that reads well in a box can wrap badly on the card. Each field marked `data-pv-in` writes
   into the matching `data-pv` slot of the real card; an image or a logo is read from the
   file chosen, on this device only (nothing is uploaded until the form is sent). Counters
   show what is left of each limit, which comes from the field's own maxlength, so the
   number on screen is the number the server enforces.

   With no script the form posts exactly the same and the server validates it.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var form = document.querySelector('[data-ad-builder]');
  if (!form) return;
  var card = form.querySelector('[data-ad-preview]');
  if (!card) return;
  var slot = function (k) { return card.querySelector('[data-pv="' + k + '"]'); };
  var initial = {};
  ['by', 'body', 'headline', 'domain'].forEach(function (k) { var s = slot(k); initial[k] = s ? s.textContent : ''; });

  function initials(name) {
    var w = name.trim().split(/\s+/).filter(function (x) { return x && !/^(of|the|and|&)$/i.test(x); });
    return (w.slice(0, 2).map(function (x) { return x.charAt(0); }).join('') || '?').toUpperCase();
  }
  function domain(u) {
    try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return initial.domain; }
  }

  function render() {
    var get = function (k) { var el = form.querySelector('[data-pv-in="' + k + '"]'); return el ? el.value.trim() : ''; };
    var by = get('by'), s;
    if ((s = slot('by'))) s.textContent = by || initial.by;
    var logo = slot('logo');
    if (logo && !logo.querySelector('img')) logo.textContent = initials(by);
    if ((s = slot('body'))) s.textContent = get('body') || initial.body;
    if ((s = slot('headline'))) s.textContent = get('headline') || initial.headline;
    var cta = form.querySelector('[data-pv-in="cta"]');
    var wa = cta && cta.value === 'whatsapp';
    if ((s = slot('domain'))) s.textContent = wa ? 'WhatsApp' : (get('domain') ? domain(get('domain')) : initial.domain);
    // With the WhatsApp button the link is a phone number (AdRules::whatsappUrl).
    var link = form.querySelector('[data-pv-in="domain"]');
    if (link) link.placeholder = link.getAttribute(wa ? 'data-ph-wa' : 'data-ph-web') || '';
    if ((s = slot('cta')) && cta) s.textContent = cta.options[cta.selectedIndex].text;
    if ((s = slot('offer'))) {
      var offer = get('offer'), code = get('code').toUpperCase();
      s.hidden = offer === '';
      s.textContent = offer;
      if (offer && code) {
        s.appendChild(document.createTextNode(' · '));
        var c = document.createElement('span'); c.className = 'ag-ad__code'; c.textContent = code; s.appendChild(c);
      }
    }
  }

  function count(el) {
    var out = form.querySelector('[data-count-for="' + el.id + '"]');
    var max = parseInt(el.getAttribute('maxlength') || '0', 10);
    if (!out || !max) return;
    out.textContent = el.value.length + '/' + max;
  }

  // The image into its slot — or, while a video is previewing, as that video's poster, which
  // is what the image is on a video ad.
  function picture(target, url) {
    var v = target.querySelector('video');
    if (v && target.getAttribute('data-pv') === 'image') { v.setAttribute('poster', url); return; }
    var img = target.querySelector('img') || document.createElement('img');
    img.alt = ''; img.src = url;
    if (!img.parentNode) { target.textContent = ''; target.appendChild(img); }
  }

  function showFile(input) {
    var target = slot(input.getAttribute('data-pv-file'));
    var f = input.files && input.files[0];
    if (!target || !f || !/^image\//.test(f.type)) return;
    var r = new FileReader();
    r.onload = function () { picture(target, String(r.result)); };
    r.readAsDataURL(f);
  }

  // What the ad is for decides which approvals it needs (AdRules::CATEGORIES). ARCON is
  // always shown; NAFDAC and a licence only for the kinds that need them. With no script
  // every field shows, and the server decides.
  var notes = { need: form.getAttribute('data-note-need') || '', optional: form.getAttribute('data-note-optional') || '' };
  function approvals() {
    var picked = form.querySelector('input[name="category"]:checked');
    var needs = picked ? (picked.getAttribute('data-needs') || '').split(' ').filter(Boolean) : [];
    form.querySelectorAll('[data-approval]').forEach(function (box) {
      var k = box.getAttribute('data-approval');
      var needed = needs.indexOf(k) !== -1;
      box.hidden = k !== 'arcon' && !needed;
      var n = box.querySelector('[data-approval-note]');
      if (n) n.textContent = needed ? notes.need : (k === 'arcon' ? notes.optional : '');
    });
  }

  // THE LIVE PANELS (AdsController::check): the ad's strength and what the budget should
  // reach, rendered by the server from the same partials the page was drawn with, so this
  // script carries no copy of either rule. Only the words and the numbers are sent — never a
  // file, only whether one has been chosen — and only the newest answer is drawn, so a slow
  // reply cannot overwrite a faster later one. The token is read when it is sent
  // (csrf-fresh.js keeps the form's _token current).
  var boxStrength = form.querySelector('[data-ad-strength]');
  var boxForecast = form.querySelector('[data-ad-forecast]');
  var chosen = { image: false, logo: false };
  var timer = 0, seq = 0;
  function check() {
    if (!window.fetch || !(boxStrength || boxForecast)) return;
    var fd = new FormData(form), body = new URLSearchParams();
    fd.forEach(function (v, k) { if (typeof v === 'string' && !/^(image|logo)(_crop)?$/.test(k)) body.append(k, v); });
    body.set('has_image', chosen.image ? '1' : '0');
    body.set('has_logo', chosen.logo ? '1' : '0');
    var tok = form.querySelector('input[name="_token"]');
    var mine = ++seq;
    fetch('/account/ads/check', {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': tok ? tok.value : '' }
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (!j || !j.ok || mine !== seq) return;
      if (boxStrength && typeof j.strength === 'string') boxStrength.innerHTML = j.strength;
      if (boxForecast && typeof j.forecast === 'string') boxForecast.innerHTML = j.forecast;
    }).catch(function () { /* the panels keep what they last said; the form is unaffected */ });
  }
  function later() { clearTimeout(timer); timer = setTimeout(check, 450); }

  form.addEventListener('input', function (e) {
    var t = e.target;
    if (t.hasAttribute && t.hasAttribute('maxlength')) count(t);
    render();
    later();
  });
  // An input with the image editor sends its own preview of the CUT (image-tool.js), which is
  // what will run; reading the raw file would preview a photo that is not the ad.
  form.addEventListener('ag:image', function (e) {
    var t = e.target, target = t && t.getAttribute && slot(t.getAttribute('data-pv-file'));
    if (t && t.getAttribute) { chosen[t.getAttribute('data-pv-file')] = true; later(); }
    if (!target || !e.detail || !e.detail.url) return;
    picture(target, e.detail.url);
  });
  // A video chosen plays in the preview, from this device (a blob: address; nothing is
  // uploaded until the form is sent), with the image chosen as its poster. Removing the file
  // puts the image back.
  var videoUrl = '';
  function showVideo(input) {
    var target = slot('image');
    if (!target) return;
    var f = input.files && input.files[0];
    if (videoUrl) { URL.revokeObjectURL(videoUrl); videoUrl = ''; }
    var old = target.querySelector('video');
    if (!f || !/^video\//.test(f.type)) {
      if (old) { var poster = old.getAttribute('poster'); old.remove(); if (poster) { var img = document.createElement('img'); img.alt = ''; img.src = poster; target.appendChild(img); } }
      return;
    }
    videoUrl = URL.createObjectURL(f);
    var img0 = target.querySelector('img');
    var v = old || document.createElement('video');
    v.muted = true; v.loop = true; v.autoplay = true; v.playsInline = true; v.className = 'ag-ad__vid';
    if (img0) { v.setAttribute('poster', img0.src); img0.remove(); }
    v.src = videoUrl;
    if (!v.parentNode) { target.textContent = ''; target.appendChild(v); }
    var p = v.play(); if (p && p.catch) p.catch(function () {});
  }

  // The ad's shape (AdRules::SHAPES): the image editor follows it by itself (image-tool.js,
  // `data-shape-from`) and cuts again from the original photo; here the preview's box changes
  // to match, so what is shown is what will run, in every shape.
  function reshape(radio) {
    var box = card.querySelector('.ag-ad__media');
    if (box) {
      box.classList.remove('ag-ad__media--square', 'ag-ad__media--portrait', 'ag-ad__media--landscape');
      box.classList.add('ag-ad__media--' + radio.value);
    }
  }

  // The phone's action bar says which kind of campaign the button sends.
  var barKind = form.querySelector('[data-bar-kind]');
  function kind(radio) {
    if (barKind && radio.checked) barKind.textContent = barKind.getAttribute(radio.value === 'paid' ? 'data-paid' : 'data-trial') || barKind.textContent;
  }

  form.addEventListener('change', function (e) {
    if (e.target.name === 'kind') kind(e.target);
    if (e.target.name === 'shape') reshape(e.target);
    if (e.target.hasAttribute && e.target.hasAttribute('data-pv-video')) showVideo(e.target);
    if (e.target.hasAttribute && e.target.hasAttribute('data-pv-file') && !e.target.hasAttribute('data-ag-image')) showFile(e.target);
    if (e.target.name === 'category') approvals();
    if (e.target.hasAttribute && e.target.hasAttribute('data-pv-file')) {
      chosen[e.target.getAttribute('data-pv-file')] = !!(e.target.files && e.target.files.length);
    }
    render();
    later();
  });
  approvals();
  form.querySelectorAll('[maxlength]').forEach(count);
  render();
})();
