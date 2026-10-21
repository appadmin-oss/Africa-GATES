// ═══ The nomination review list — AdminConsole v2 (README §10b, screenshot 02) ══════════
//
// Everything on this page works without this file: tabs, views, facets, sort and pages are
// links; a row opens its peek with `?item=`; the bulk bar appears by `:has(:checked)`; the
// decisions are forms. This makes it quicker, and nothing here decides anything:
//
//   · a row click swaps the peek in place (`?part=peek`) instead of reloading the list;
//   · J/K (or ↓/↑) step through the page with the peek open, Esc closes, A approves,
//     R opens the rejection, D opens the review desk;
//   · the Filter's "Show N" asks the server for its count as boxes are ticked — the server
//     is the only thing that counts, so the number cannot disagree with the list it opens;
//   · the bulk bar says how many are selected, and exports just those;
//   · saved views (`ac2.views`), density (`ac2.dens`) and hidden columns (`ac2.hide`) live in
//     this browser — declared in Support\CookieRegistry, like every key this site writes;
//   · a heartbeat keeps "N others here" and "… is viewing" honest while the page is open.
//
// The admin CSP has no 'unsafe-inline': no inline handlers, everything is delegated here.

(function () {
  'use strict';

  var wrap = document.querySelector('[data-rq]');
  if (!wrap) return;
  var list = wrap.querySelector('.rq');
  var peek = wrap.querySelector('[data-rq-peek]');
  var scrim = wrap.querySelector('.rq-scrim');
  var page = wrap.getAttribute('data-rq-page') || location.pathname + location.search;

  function csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }

  // ── Bound to this page, and unbound when it goes ─────────────────────────────
  // console-nav.js swaps this page in and out without a reload, so a listener on the
  // document or a heartbeat would otherwise outlive the list it belongs to — and A on the
  // next page would approve a nomination from this one. Every binding outside the list's
  // own elements goes through on(), and all of them end at `cn:unload`.
  var offs = [];
  function on(target, type, fn) { target.addEventListener(type, fn); offs.push(function () { target.removeEventListener(type, fn); }); }
  document.addEventListener('cn:unload', function bye() {
    offs.forEach(function (f) { f(); });
    offs = [];
    document.removeEventListener('cn:unload', bye);
  });
  function go(href) { if (window.cnNav) window.cnNav.go(href); else location.href = href; }
  // The address changes as the peek opens and closes; the entry keeps console-nav's state.
  function setUrl(href) { history.replaceState(history.state, '', href); }
  function toast(m) { if (window.agToast) window.agToast(m); }
  function typing(el) {
    if (!el) return false;
    var t = el.tagName;
    return t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || el.isContentEditable;
  }
  // Reads go through one helper; every WRITE names its key in place, so the cookie
  // policy's sweep (CookieRegistryTest) can read which key each line writes.
  function read(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } }

  // ── the peek ────────────────────────────────────────────────────────────────
  function rows() { return [].slice.call(wrap.querySelectorAll('[data-rq-row]')); }
  function openId() { var b = peek && peek.querySelector('[data-rq-peek-id]'); return b ? b.getAttribute('data-rq-peek-id') : null; }
  var lastFocus = null;

  function mark(id) {
    rows().forEach(function (r) {
      var on = r.getAttribute('data-rq-row') === String(id);
      r.classList.toggle('is-open', on);
      var a = r.querySelector('[data-rq-open]');
      if (a) { if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); }
    });
  }
  function position(id) {
    var all = rows(), i = all.findIndex(function (r) { return r.getAttribute('data-rq-row') === String(id); });
    var pos = peek.querySelector('[data-rq-pos]');
    var foot = wrap.querySelector('.rq-foot span');
    if (!pos || i < 0) return;
    var from = foot ? parseInt(foot.textContent.replace(/[^0-9–]/g, '').split('–')[0], 10) || 1 : 1;
    var total = foot ? (foot.textContent.split(' of ')[1] || '').trim() : String(all.length);
    pos.textContent = (from + i) + ' of ' + total;
  }

  function show(href, id, focus) {
    var url = href + (href.indexOf('?') < 0 ? '?' : '&') + 'part=peek';
    peek.setAttribute('aria-busy', 'true');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
      .then(function (html) {
        peek.innerHTML = html;
        peek.hidden = false;
        if (scrim) scrim.hidden = false;
        peek.removeAttribute('aria-busy');
        wrap.classList.add('rq-wrap--peek');
        mark(id);
        position(id);
        setUrl(href);
        page = href;
        if (focus !== false) {
          var h = peek.querySelector('.rq-peek__name');
          if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
        }
        beat();
      })
      .catch(function () { location.href = href; });   // the plain link always works
  }

  function close() {
    if (!peek || peek.hidden) return false;
    var c = peek.querySelector('a[data-rq-close]');
    var href = c ? c.getAttribute('href') : location.pathname;
    peek.hidden = true;
    peek.innerHTML = '';
    if (scrim) scrim.hidden = true;
    wrap.classList.remove('rq-wrap--peek');
    mark(null);
    setUrl(href);
    page = href;
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    beat();
    return true;
  }

  function step(d) {
    var all = rows(), cur = openId();
    var i = all.findIndex(function (r) { return r.getAttribute('data-rq-row') === cur; });
    var n = all[Math.max(0, Math.min(all.length - 1, (i < 0 ? -1 : i) + d))];
    if (!n || n.getAttribute('data-rq-row') === cur) return;
    var a = n.querySelector('[data-rq-open]');
    show(a.getAttribute('href'), n.getAttribute('data-rq-row'), false);
    n.scrollIntoView({ block: 'nearest' });
  }

  wrap.addEventListener('click', function (e) {
    var a = e.target.closest('[data-rq-open]');
    if (a && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
      e.preventDefault();
      lastFocus = a;
      show(a.getAttribute('href'), a.getAttribute('data-rq-open'));
      return;
    }
    if (e.target.closest('a[data-rq-close]') && !e.metaKey && !e.ctrlKey) { e.preventDefault(); close(); return; }
    var s = e.target.closest('[data-rq-step]');
    if (s) { step(parseInt(s.getAttribute('data-rq-step'), 10)); return; }
    if (e.target.closest('[data-rq-open-reject]')) { openReject(); return; }
    var ap = e.target.closest('[data-rq-approve][aria-disabled="true"]');
    if (ap) { e.preventDefault(); toast('This one cannot be approved yet — the checks above say why.'); }
  });

  function openReject() {
    var d = peek && peek.querySelector('[data-rq-reject]');
    if (!d) return;
    d.open = true;
    var t = d.querySelector('textarea');
    if (t) { t.scrollIntoView({ block: 'nearest' }); t.focus(); }
  }

  // ── keys ────────────────────────────────────────────────────────────────────
  on(document, 'keydown', function (e) {
    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) return;
    if (document.querySelector('dialog[open]')) return;
    var k = e.key;
    if (k === 'Escape') {
      var pop = wrap.querySelector('details.rq-pop[open]');
      if (pop) { pop.open = false; var s = pop.querySelector('summary'); if (s) s.focus(); e.preventDefault(); return; }
      if (!typing(document.activeElement) && close()) e.preventDefault();
      return;
    }
    if (typing(document.activeElement)) return;
    var isOpen = peek && !peek.hidden;
    if (isOpen && (k === 'j' || k === 'ArrowDown')) { e.preventDefault(); step(1); return; }
    if (isOpen && (k === 'k' || k === 'ArrowUp')) { e.preventDefault(); step(-1); return; }
    if (!isOpen && (k === 'j' || k === 'k')) {
      var first = rows()[k === 'j' ? 0 : rows().length - 1];
      if (first) { e.preventDefault(); lastFocus = first.querySelector('[data-rq-open]'); show(lastFocus.getAttribute('href'), first.getAttribute('data-rq-row')); }
      return;
    }
    if (isOpen && (k === 'a' || k === 'A')) {
      var b = peek.querySelector('[data-rq-approve]');
      if (!b) return;
      e.preventDefault();
      if (b.getAttribute('aria-disabled') === 'true') { toast('This one cannot be approved yet — the checks say why.'); return; }
      b.form.requestSubmit(b);
      return;
    }
    if (isOpen && (k === 'r' || k === 'R')) { e.preventDefault(); openReject(); return; }
    if (k === 'd' || k === 'D') {
      var desk = document.querySelector('[data-rq-desk]');
      if (desk) { e.preventDefault(); go(desk.getAttribute('href')); }
    }
  });

  // ── selection and the bulk bar ──────────────────────────────────────────────
  var boxes = function () { return [].slice.call(wrap.querySelectorAll('[data-rq-cb]')); };
  var all = wrap.querySelector('[data-rq-all]');
  function sync() {
    var on = boxes().filter(function (b) { return b.checked; });
    var n = wrap.querySelector('[data-rq-sel]');
    if (n) n.textContent = on.length + ' selected';
    if (all) { all.checked = on.length > 0 && on.length === boxes().length; all.indeterminate = on.length > 0 && !all.checked; }
  }
  wrap.addEventListener('change', function (e) {
    if (e.target === all) boxes().forEach(function (b) { b.checked = all.checked; });
    if (e.target === all || e.target.hasAttribute('data-rq-cb')) sync();
  });
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('[data-rq-clear-sel]')) { boxes().forEach(function (b) { b.checked = false; }); sync(); }
    var ex = e.target.closest('[data-rq-export-sel]');
    if (ex) {
      var ids = boxes().filter(function (b) { return b.checked; }).map(function (b) { return 'ids%5B%5D=' + encodeURIComponent(b.value); });
      var base = ex.getAttribute('data-export');
      location.href = base + (base.indexOf('?') < 0 ? '?' : '&') + ids.join('&');
    }
  });
  sync();

  // ── the Filter: live "Show N", one popover at a time, the phone sheet ───────
  var filter = wrap.querySelector('[data-rq-filter]');
  var form = wrap.querySelector('[data-rq-filter-form]');
  var showBtn = wrap.querySelector('[data-rq-show]');
  var countUrl = wrap.getAttribute('data-rq-count');
  var timer = null;
  function recount() {
    if (!form || !showBtn) return;
    clearTimeout(timer);
    timer = setTimeout(function () {
      var qs = new URLSearchParams(new FormData(form)).toString();
      fetch(countUrl + '?' + qs, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) showBtn.textContent = 'Show ' + Number(j.n).toLocaleString(); })
        .catch(function () { showBtn.textContent = 'Show results'; });
    }, 180);
  }
  if (form) { form.addEventListener('change', recount); form.addEventListener('input', function (e) { if (e.target.type === 'search') recount(); }); }
  wrap.querySelectorAll('details.rq-pop').forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;
      wrap.querySelectorAll('details.rq-pop[open]').forEach(function (o) { if (o !== d) o.open = false; });
    });
  });
  on(document, 'click', function (e) {
    wrap.querySelectorAll('details.rq-pop[open]').forEach(function (d) {
      if (!d.contains(e.target) && !e.target.closest('[data-rq-open-filter]')) d.open = false;
    });
  });
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('[data-rq-open-filter]') && filter) {
      filter.open = true;
      var f = filter.querySelector('input[type=search], input[type=checkbox]');
      if (f) f.focus();
    }
    if (e.target.closest('[data-rq-close-filter]') && filter) filter.open = false;
  });

  // The programme select applies on change, like every console select (its form opts out
  // of console-ux.js so that script does not also add a second "Clear" beside our chips).
  var scope = wrap.querySelector('[data-rq-scope]');
  if (scope) scope.addEventListener('change', function () { scope.requestSubmit(); });

  // "Filter instead": Enter on the search that produced the suggestion applies the first.
  var q = wrap.querySelector('.rq-bar input[type=search]');
  var instead = wrap.querySelector('[data-rq-instead]');
  if (q && instead) {
    var asked = q.value;
    q.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && q.value === asked) { e.preventDefault(); go(instead.getAttribute('href')); }
    });
  }

  // ── the Display menu: density and columns, kept in this browser ─────────────
  var dens = read('ac2.dens') || {};
  var hide = read('ac2.hide') || {};
  function applyDisplay() {
    var compact = dens.review === 'compact';
    list.classList.toggle('rq--compact', compact);
    wrap.querySelectorAll('[data-rq-dens]').forEach(function (b) {
      b.setAttribute('aria-checked', String((b.getAttribute('data-rq-dens') === 'compact') === compact));
    });
    var h = hide.review || [];
    list.setAttribute('data-hide', h.join(' '));
    wrap.querySelectorAll('[data-rq-col]').forEach(function (c) { c.checked = h.indexOf(c.getAttribute('data-rq-col')) < 0; });
  }
  wrap.addEventListener('click', function (e) {
    var d = e.target.closest('[data-rq-dens]');
    if (!d) return;
    dens.review = d.getAttribute('data-rq-dens');
    try { localStorage.setItem('ac2.dens', JSON.stringify(dens)); } catch (err) {}
    applyDisplay();
  });
  wrap.addEventListener('change', function (e) {
    var c = e.target.closest('[data-rq-col]');
    if (!c) return;
    var h = (hide.review || []).filter(function (x) { return x !== c.getAttribute('data-rq-col'); });
    if (!c.checked) h.push(c.getAttribute('data-rq-col'));
    hide.review = h;
    try { localStorage.setItem('ac2.hide', JSON.stringify(hide)); } catch (err) {}
    applyDisplay();
  });
  applyDisplay();

  // ── saved views ─────────────────────────────────────────────────────────────
  var saved = wrap.querySelector('[data-rq-saved]');
  function views() { var v = read('ac2.views') || {}; return Array.isArray(v.review) ? v.review : []; }
  function keep(all) { try { localStorage.setItem('ac2.views', JSON.stringify(all)); } catch (err) {} }
  function clean(href) { return href.replace(/([?&])(item|open|page)=[^&]*/g, '$1').replace(/[?&]+$/, '').replace(/\?&/, '?'); }
  function drawViews() {
    if (!saved) return;
    var here = clean(location.pathname + location.search);
    saved.innerHTML = '';
    views().forEach(function (v, i) {
      var c = document.createElement('span');
      c.className = 'rq-saved__c';
      if (clean(v.href) === here) c.setAttribute('aria-current', 'true');
      var a = document.createElement('a');
      a.className = 'rq-saved__go'; a.href = v.href; a.textContent = v.name;
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'rq-saved__x'; x.setAttribute('aria-label', 'Delete saved view ' + v.name);
      x.innerHTML = '<svg aria-hidden="true" focusable="false"><use href="#ic-x"/></svg>';
      x.addEventListener('click', function () {
        var all = read('ac2.views') || {};
        all.review = views().filter(function (_, j) { return j !== i; });
        keep(all);
        drawViews();
      });
      c.appendChild(a); c.appendChild(x);
      saved.appendChild(c);
    });
    saved.hidden = views().length === 0;
  }
  wrap.addEventListener('click', function (e) {
    if (!e.target.closest('[data-rq-save-view]')) return;
    var name = [].slice.call(wrap.querySelectorAll('.rq-applied')).map(function (c) {
      return c.textContent.replace(/\s+/g, ' ').trim();
    }).join(' · ').slice(0, 48) || 'Saved view';
    var all = read('ac2.views') || {};
    var href = clean(location.pathname + location.search);
    all.review = views().filter(function (v) { return clean(v.href) !== href; }).concat([{ name: name, href: href }]).slice(-8);
    keep(all);
    drawViews();
    toast('View saved — it is beside the quick views in this browser.');
  });
  drawViews();

  // ── presence: a heartbeat while the page is open and visible ────────────────
  function beat() {
    if (document.hidden) return;
    var fd = new FormData();
    fd.append('_token', csrf());
    fd.append('target', openId() || '');
    fetch('/admin/nominations/review/presence', { method: 'POST', credentials: 'same-origin', body: fd,
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        var cur = openId();
        var here = (j.others || []).filter(function (o) { return cur && String(o.target) === cur; });
        var head = peek && peek.querySelector('.rq-peek__head');
        if (!head) return;
        head.querySelectorAll('.rq-viewing').forEach(function (n) { n.remove(); });
        var pos = head.querySelector('[data-rq-pos]');
        here.forEach(function (o) {
          var s = document.createElement('span');
          s.className = 'rq-viewing';
          s.innerHTML = '<span class="rq-dot rq-dot--green" aria-hidden="true"></span>';
          s.appendChild(document.createTextNode(o.short + ' is viewing'));
          pos.after(s);
        });
      })
      .catch(function () {});
  }
  var beating = setInterval(beat, 45000);
  offs.push(function () { clearInterval(beating); });
  on(document, 'visibilitychange', function () { if (!document.hidden) beat(); });
  if (openId()) position(openId());
})();
