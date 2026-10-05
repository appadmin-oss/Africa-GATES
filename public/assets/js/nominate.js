/* ══════════════════════════════════════════════════════════════════════════════
   THE NOMINATION FLOW — the steps over one working form (Phase 8 §8.16)
   pages/nominate-award.twig · design/NominationFlow.dc.html
   ══════════════════════════════════════════════════════════════════════════════

   The page is one long form that posts with this file blocked; the server's
   NominationRules is the authority for this door and the API's. What this adds:

   · ONE STEP AT A TIME, five of them, with the rail, the phone's bars and a bar at the
     foot: Back · Continue, and on the last step the real submit. A step is checked on the
     way OUT and its problem is said in the bar (`role=alert`) — Continue is never
     disabled, because a disabled button with no reason beside it is a dead end.
   · THE REASON COUNTER, live, the one exception to "validate on blur" (REFERENCE §9.5):
     "N/40", "N more characters needed" until 40 then "Good — N characters" in green. The
     40 is `data-min` from NominationRules::MIN_REASON — no number is typed here.
     Characters, not bytes, as the server counts (mb_strlen): Array.from() so a Yorùbá
     or Arabic reason is counted the way it is read.
   · THE CATEGORY CAP: at the maximum the unchosen boxes are disabled and a sentence says
     why; an unchosen category's reason box is DISABLED so it cannot post — the server
     ignores an empty reason anyway (NominationRules::categories()), but a half-typed one
     in a category somebody unticked must not file itself.
   · The name label and hint follow the kind (a person's full name, an organisation's
     registered name), "Why {name} for {category}?" follows the name, and the summary on
     the last step reads what was typed.
   · Evidence: "Add another link" reveals the spare link fields; chosen files are listed
     with their size, and one over the size limit or past the count is said at once.

   It survives being wrong: any exception and the form simply posts.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var form = document.querySelector('[data-nf]');
  if (!form) return;

  try {
    var D = form.dataset;
    var MIN = parseInt(D.min || '40', 10);
    var MINC = parseInt(D.minCats || '2', 10);
    var MAXC = parseInt(D.maxCats || '3', 10);
    var MAXE = parseInt(D.maxEvidence || '5', 10);
    var MAXB = parseInt(D.maxMb || '10', 10) * 1048576;
    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-nf-step]'));
    var bars = form.querySelectorAll('[data-nf-bar]');
    var railLinks = form.querySelectorAll('[data-nf-go]');
    var back = form.querySelector('[data-nf-back]');
    var next = form.querySelector('[data-nf-next]');
    var err = form.querySelector('[data-nf-err]');
    var cur = 1, reached = 1;
    var len = function (s) { return Array.from(String(s || '').trim()).length; };
    var fill = function (tpl, map) { return String(tpl).replace(/%(\w+)%/g, function (m, k) { return k in map ? map[k] : m; }); };

    form.setAttribute('data-nf-on', '');

    /* ── the name, the kind ─────────────────────────────────────────────────── */
    var nameIn = form.querySelector('#nominee_name');
    function nominee() { return (nameIn && nameIn.value.trim()) || ''; }
    function onKind() {
      var k = form.querySelector('[data-nf-kind]:checked');
      if (!k) return;
      var l = form.querySelector('[data-nf-name-label]'), h = form.querySelector('[data-nf-name-hint]');
      if (l) l.textContent = k.getAttribute('data-name-label');
      if (h) h.textContent = k.getAttribute('data-name-hint');
    }
    Array.prototype.forEach.call(form.querySelectorAll('[data-nf-kind]'), function (r) { r.addEventListener('change', onKind); });

    /* ── categories ─────────────────────────────────────────────────────────── */
    var cats = Array.prototype.slice.call(form.querySelectorAll('[data-nf-cat]'));
    function chosen() { return cats.filter(function (c) { return c.querySelector('[data-nf-pick]').checked; }); }
    function paintCat(c) {
      var on = c.querySelector('[data-nf-pick]').checked;
      var ta = c.querySelector('[data-nf-reason]');
      var n = len(ta.value);
      c.toggleAttribute('data-on', on);
      ta.disabled = !on;
      ta.required = on;
      c.querySelector('[data-nf-len]').textContent = n + '/' + MIN;
      var msg = c.querySelector('[data-nf-msg]');
      var ok = n >= MIN;
      c.toggleAttribute('data-ok', on && ok);
      msg.textContent = on ? (ok ? fill(D.lOk, { n: n }) : fill(D.lMore, { n: MIN - n })) : '';
      var q = c.querySelector('[data-nf-q]');
      if (q) q.textContent = String(q.getAttribute('data-nf-q')).replace('%name%', nominee() || 'them');
    }
    function paintCats() {
      var ch = chosen();
      cats.forEach(function (c) {
        var box = c.querySelector('[data-nf-pick]');
        box.disabled = !box.checked && ch.length >= MAXC;
        var o = c.querySelector('[data-nf-order]');
        o.textContent = box.checked ? '#' + (ch.indexOf(c) + 1) : '';
        paintCat(c);
      });
      var maxed = form.querySelector('[data-nf-maxed]');
      if (maxed) maxed.hidden = ch.length < MAXC;
      var pill = form.querySelector('[data-nf-selpill]');
      if (pill) { pill.textContent = fill(D.lSel, { n: ch.length }); pill.toggleAttribute('data-ok', ch.length >= MINC); }
    }
    cats.forEach(function (c) {
      c.querySelector('[data-nf-pick]').addEventListener('change', function () {
        paintCats();
        if (this.checked) c.querySelector('[data-nf-reason]').focus();
      });
      c.querySelector('[data-nf-reason]').addEventListener('input', function () { paintCat(c); summary(); });
    });
    var catQ = form.querySelector('[data-nf-catq]');
    if (catQ) catQ.addEventListener('input', function () {
      var q = catQ.value.trim().toLowerCase();
      cats.forEach(function (c) { c.hidden = q !== '' && c.getAttribute('data-title').indexOf(q) < 0 && !c.querySelector('[data-nf-pick]').checked; });
    });
    if (nameIn) nameIn.addEventListener('input', function () { cats.forEach(paintCat); summary(); });

    /* ── evidence ───────────────────────────────────────────────────────────── */
    var spares = Array.prototype.slice.call(form.querySelectorAll('[data-nf-spare]'));
    var addLink = form.querySelector('[data-nf-addlink]');
    spares.forEach(function (s) { s.hidden = true; });
    if (addLink && spares.length) {
      addLink.hidden = false;
      addLink.addEventListener('click', function () {
        var s = spares.shift();
        if (s) { s.hidden = false; s.querySelector('input').focus(); }
        if (!spares.length) addLink.hidden = true;
      });
    }
    var files = form.querySelector('[data-nf-files]');
    var list = form.querySelector('[data-nf-filelist]');
    function evidenceCount() {
      var links = Array.prototype.filter.call(form.querySelectorAll('input[name="evidence_links[]"]'), function (i) { return i.value.trim() !== ''; }).length;
      return links + (files && files.files ? files.files.length : 0);
    }
    function fileProblem() {
      if (!files || !files.files) return '';
      for (var i = 0; i < files.files.length; i++) {
        if (files.files[i].size > MAXB) return files.files[i].name + ' — ' + Math.round(MAXB / 1048576) + ' MB at most.';
      }
      if (evidenceCount() > MAXE) return 'Up to ' + MAXE + ' pieces of evidence. Remove one to add another.';
      return '';
    }
    if (files) files.addEventListener('change', function () {
      list.textContent = '';
      Array.prototype.forEach.call(files.files || [], function (f) {
        var li = document.createElement('li');
        li.className = 'nf-file';
        var kind = /pdf$/i.test(f.name) ? 'PDF' : 'IMG';
        li.innerHTML = '<span class="nf-file__k" aria-hidden="true"></span><span class="nf-file__b"><b></b><span></span></span>';
        li.querySelector('.nf-file__k').textContent = kind;
        li.querySelector('b').textContent = f.name;
        li.querySelector('.nf-file__b span').textContent = (f.size / 1048576).toFixed(1) + ' MB';
        list.appendChild(li);
      });
      summary();
    });

    /* ── the summary on the last step, and the rail's lines ─────────────────── */
    function summary() {
      var s = function (k, v) { var el = form.querySelector('[data-nf-s="' + k + '"]'); if (el) el.textContent = v; };
      var titles = chosen().map(function (c) { return c.querySelector('.nf-cat__t').textContent; });
      s('nominee', nominee() || '—');
      s('categories', titles.length ? titles.join(' · ') : '—');
      var n = evidenceCount();
      s('evidence', n ? n + (n === 1 ? ' evidence item' : ' evidence items') : 'None');
      var r = function (k, v) { var el = form.querySelector('[data-nf-sum="' + k + '"]'); if (el) el.textContent = v; };
      r('nominee', nominee() || '—');
      r('categories', titles.length ? titles.join(', ') : '—');
      if (n) r('evidence', n + (n === 1 ? ' item' : ' items'));
    }

    /* ── the steps ──────────────────────────────────────────────────────────── */
    function problem(n) {
      if (n === 1) {
        var cc = form.querySelector('#country_code');
        var contact = form.querySelector('#nominee_contact');
        if (!nominee() || !cc.value || !contact.value.trim()) return D.errWho;
      }
      if (n === 3) {
        var ch = chosen();
        if (ch.length < MINC || ch.length > MAXC) return D.errCat;
        for (var i = 0; i < ch.length; i++) if (len(ch[i].querySelector('[data-nf-reason]').value) < MIN) return D.shortReason;
      }
      if (n === 4) return fileProblem();
      if (n === 5) {
        var rel = form.querySelector('[data-nf-rel]:checked');
        var consent = form.querySelector('input[name="consent"]');
        var need = Array.prototype.filter.call(form.querySelectorAll('#nf-you input[required]:not([type=radio]):not([type=checkbox])'),
          function (i) { return !i.value.trim(); });
        if (!rel || !consent.checked || need.length) return D.errYou;
      }
      return '';
    }
    function show(n, focus) {
      cur = n; reached = Math.max(reached, n);
      steps.forEach(function (s) { s.hidden = parseInt(s.getAttribute('data-nf-step'), 10) !== n; });
      Array.prototype.forEach.call(bars, function (b) { b.toggleAttribute('data-on', parseInt(b.getAttribute('data-nf-bar'), 10) <= n); });
      Array.prototype.forEach.call(railLinks, function (a) {
        var i = parseInt(a.getAttribute('data-nf-go'), 10);
        a.setAttribute('aria-current', i === n ? 'step' : 'false');
        a.toggleAttribute('data-done', i < n);
      });
      back.hidden = n === 1;
      next.textContent = n === steps.length ? D.lSubmit : D.lNext + ' →';
      err.hidden = true;
      if (focus) {
        var h = steps[n - 1].querySelector('.nf-step__h');
        if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
      }
      summary();
    }
    next.addEventListener('click', function (e) {
      var p = problem(cur);
      if (p) { e.preventDefault(); err.textContent = p; err.hidden = false; return; }
      if (cur < steps.length) { e.preventDefault(); show(cur + 1, true); }
      /* on the last step the click is the form's real submit */
    });
    back.addEventListener('click', function () { if (cur > 1) show(cur - 1, true); });
    Array.prototype.forEach.call(railLinks, function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var i = parseInt(a.getAttribute('data-nf-go'), 10);
        /* Forward only through steps already passed, each re-checked on the way. */
        if (i <= cur) { show(i, true); return; }
        for (var k = cur; k < i; k++) { var p = problem(k); if (p) { show(k, true); err.textContent = p; err.hidden = false; return; } }
        show(i, true);
      });
    });

    onKind(); paintCats(); summary();
    /* A bounced post comes back with everything typed: open the first step with a
       problem, or the last one, where the server's refusal is about the whole. */
    var start = 1;
    if (form.querySelector('.nf__err')) {
      start = steps.length;
      for (var s = 1; s <= steps.length; s++) if (problem(s)) { start = s; break; }
    }
    show(start, false);
  } catch (e) {
    /* Rule: a script that fails must not be why a nomination cannot be sent. */
    form.removeAttribute('data-nf-on');
    Array.prototype.forEach.call(form.querySelectorAll('[data-nf-step]'), function (s) { s.hidden = false; });
  }
})();
