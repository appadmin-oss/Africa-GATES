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
    if ((s = slot('domain'))) s.textContent = get('domain') ? domain(get('domain')) : initial.domain;
    var cta = form.querySelector('[data-pv-in="cta"]');
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

  function showFile(input) {
    var target = slot(input.getAttribute('data-pv-file'));
    var f = input.files && input.files[0];
    if (!target || !f || !/^image\//.test(f.type)) return;
    var r = new FileReader();
    r.onload = function () {
      var img = target.querySelector('img') || document.createElement('img');
      img.alt = ''; img.src = String(r.result);
      if (!img.parentNode) { target.textContent = ''; target.appendChild(img); }
    };
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

  form.addEventListener('input', function (e) {
    var t = e.target;
    if (t.hasAttribute && t.hasAttribute('maxlength')) count(t);
    render();
  });
  form.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-pv-file')) showFile(e.target);
    if (e.target.name === 'category') approvals();
    render();
  });
  approvals();
  form.querySelectorAll('[maxlength]').forEach(count);
  render();
})();
