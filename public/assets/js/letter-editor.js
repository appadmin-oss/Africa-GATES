/* ══════════════════════════════════════════════════════════════════════════════
   THE VOTING LETTER EDITOR · admin/programmes/letter.twig · Services\CycleLetter
   ══════════════════════════════════════════════════════════════════════════════

   Turns the page's two plain fields into the letter itself, typed on the sheet:

   · Fields are chips. "{" opens a picker at the cursor (type to filter, arrows, Enter);
     "Add a detail" inserts where the cursor last was. A {word} the letter does not know
     is drawn red, and the server refuses to publish it.
   · Autosave: 1.2s after the last keystroke the draft is saved (never published), and
     the answer says whether the letter still fits one page at A4 and at US Letter —
     measured by the server with the PDF's own layout, so the meter cannot disagree with
     the paper. The status pill at the foot of the page shows it.
   · ⌘/Ctrl+S saves now; ⌘/Ctrl+Enter publishes. Paste arrives as plain text, a blank line
     in it becoming a new paragraph. Enter in the headline moves to the letter.
   · Leaving with an unsaved change asks first.

   The plain input and textarea stay in the form as mirrors, so Publish and Save draft post
   the words whether or not this file runs. Bound through cnPage (console-nav), so nothing
   here outlives the page.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var root = document.querySelector('[data-le]');
  if (!root || root.hasAttribute('data-le-on')) return;
  var form = root.querySelector('#le-form');
  var srcH = root.querySelector('[data-le-src="headline"]');
  var srcB = root.querySelector('[data-le-src="body"]');
  var edH = root.querySelector('[data-le-ed="headline"]');
  var edB = root.querySelector('[data-le-ed="body"]');
  if (!form || !srcH || !srcB || !edH || !edB || !window.fetch) return;

  var fields = [];
  try { fields = JSON.parse(root.getAttribute('data-le-fields') || '[]'); } catch (e) {}
  // Twig's map over a keyed list keeps the keys, so this can arrive as an object.
  if (!Array.isArray(fields)) fields = Object.keys(fields).map(function (k) { return fields[k]; });
  var byKey = {};
  fields.forEach(function (f) { byKey[f.k] = f; });
  var maxH = +root.getAttribute('data-le-max-h') || 120;
  var maxB = +root.getAttribute('data-le-max-b') || 2400;
  var stateEl = root.querySelector('[data-le-state]');
  var savedEl = root.querySelector('[data-le-saved]');
  var countEl = root.querySelector('[data-le-count]');
  var checksEl = root.querySelector('[data-le-checks]');
  var pdf = root.querySelector('[data-le-pdf]');
  var pdfSrc = root.getAttribute('data-le-pdf-src') || '';
  var timer = null, pdfTimer = null, dirty = false, busy = false, again = false, lastRange = null, pop = null;

  // ── text ⇄ sheet ─────────────────────────────────────────────────────────
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function chip(k) {
    var f = byKey[k];
    return '<span class="le-f' + (f ? '' : ' le-f--bad') + '" contenteditable="false" data-f="' + esc(k) + '">' + esc(f ? f.label : '{' + k + '}') + '</span>';
  }
  function toHtml(text) {
    return esc(text).replace(/\{([a-z_]+)\}/g, function (m, k) { return chip(k); });
  }
  function serialize(el) {
    var out = '';
    el.childNodes.forEach(function (n) {
      if (n.nodeType === 3) out += n.nodeValue;
      else if (n.nodeType === 1 && n.hasAttribute('data-f')) out += '{' + n.getAttribute('data-f') + '}';
      else if (n.nodeType === 1 && n.tagName === 'BR') out += ' ';
      else if (n.nodeType === 1) out += serialize(n);
    });
    return out.replace(/ /g, ' ');
  }
  function bodyText() {
    var paras = [];
    edB.childNodes.forEach(function (n) {
      var t = (n.nodeType === 3 ? n.nodeValue : (n.nodeType === 1 ? serialize(n) : '')).replace(/\s+/g, ' ').trim();
      if (t) paras.push(t);
    });
    return paras.join('\n\n');
  }
  function paintBody(text) {
    var paras = String(text).split(/\n\s*\n/).map(function (p) { return p.replace(/\s*\n\s*/g, ' ').trim(); }).filter(Boolean);
    edB.innerHTML = (paras.length ? paras : ['']).map(function (p) { return '<p>' + (p ? toHtml(p) : '<br>') + '</p>'; }).join('');
  }

  // ── enhance ──────────────────────────────────────────────────────────────
  edH.innerHTML = toHtml(srcH.value);
  paintBody(srcB.value);
  [edH, edB].forEach(function (el) {
    el.hidden = false;
    el.contentEditable = 'true';
    el.spellcheck = true;
    el.setAttribute('role', 'textbox');
  });
  edH.setAttribute('aria-label', 'Title');
  edH.setAttribute('data-placeholder', 'Type a title for the letter');
  edB.setAttribute('aria-label', 'The letter');
  edB.setAttribute('aria-multiline', 'true');
  edB.setAttribute('data-placeholder', 'Write to the nominee…');
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
  root.setAttribute('data-le-on', '');

  function sync() {
    srcH.value = serialize(edH).replace(/\s+/g, ' ').trim();
    srcB.value = bodyText();
    edH.setAttribute('data-empty', srcH.value === '' ? '1' : '0');
    edB.setAttribute('data-empty', srcB.value === '' ? '1' : '0');
    local();
  }

  // ── what the rail says, as you type ──────────────────────────────────────
  function local() {
    var b = srcB.value;
    var words = b.replace(/\{[a-z_]+\}/g, 'x').split(/\s+/).filter(Boolean).length;
    var mins = Math.max(1, Math.round(words / 200));
    if (countEl) countEl.textContent = words + ' words · ' + mins + ' min read' + (b.length > maxB * 0.85 ? ' · ' + b.length + ' of ' + maxB + ' characters' : '');
    var notes = [];
    if (srcH.value.length > maxH) notes.push('The title is a little long: remove ' + (srcH.value.length - maxH) + ' letters.');
    if (b.length > maxB) notes.push('The letter is too long for one page: remove about ' + Math.ceil((b.length - maxB) / 6) + ' words.');
    var unknown = (srcH.value + ' ' + b).match(/\{([a-z_]+)\}/g) || [];
    unknown.forEach(function (t) { var k = t.slice(1, -1); if (!byKey[k]) notes.push(t + ' is not a detail we can fill in. Use “Add a detail” instead.'); });
    if (!/\{(first_name|name)\}/.test(srcH.value + b)) notes.push('Tip: letters feel warmer with the person’s name. Add it from “Add a detail”.');
    if (!/\{closes\}/.test(b)) notes.push('Tip: say when voting closes (“Voting closes”), so supporters know how long they have.');
    checks(notes);
  }
  function checks(list) {
    if (!checksEl) return;
    // A tip is a suggestion, not a fault: drawn quietly, never in the colour of an error.
    checksEl.innerHTML = list.map(function (n) { return '<li' + (/^Tip:/.test(n) ? ' class="le-tip"' : '') + '>' + esc(n) + '</li>'; }).join('');
  }
  function fit(f) {
    if (!f) return;
    Object.keys(f).forEach(function (paper) {
      var el = root.querySelector('[data-le-paper="' + paper + '"]');
      if (!el) return;
      var ok = f[paper].fits;
      el.className = 'le-fit le-fit--' + (ok ? 'ok' : 'over');
      el.querySelector('b').textContent = ok ? 'yes' : 'too long';
    });
  }
  function state(kind, text, saved) {
    if (stateEl) { stateEl.className = 'le-state le-state--' + kind; stateEl.textContent = text; }
    if (savedEl && saved !== undefined) savedEl.textContent = saved;
  }

  // ── saving ───────────────────────────────────────────────────────────────
  function csrf() { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ''; }
  function save() {
    clearTimeout(timer);
    if (busy) { again = true; return; }
    busy = true;
    state('saving', 'Saving…');
    var fd = new FormData(form);
    fd.set('action', 'draft');
    fd.set('_token', csrf() || fd.get('_token'));
    // getAttribute, never form.action: the buttons are named "action", and a control named
    // that shadows the property, so form.action is the button list and the post goes nowhere.
    fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf() } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
      .then(function (res) {
        busy = false;
        if (!res.ok) { state('error', 'Not saved', 'Your words are still here. Keep typing and we will try again.'); return; }
        dirty = false;
        var t = new Date();
        state('draft', 'Not in use yet', 'Saved ' + t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        fit(res.j.fit);
        if (res.j.checks && res.j.checks.length) checks(res.j.checks);
        clearTimeout(pdfTimer);
        pdfTimer = setTimeout(function () { if (pdf) pdf.src = pdfSrc + '&t=' + Date.now(); }, 600);
        if (again) { again = false; save(); }
      })
      .catch(function () { busy = false; state('error', 'No connection', 'It saves as soon as you type again.'); });
  }
  function changed() {
    sync();
    dirty = true;
    state('draft', 'Not in use yet', 'Saving…');
    clearTimeout(timer);
    timer = setTimeout(save, 1200);
  }
  edH.addEventListener('input', changed);
  edB.addEventListener('input', changed);

  // ── keys, paste ──────────────────────────────────────────────────────────
  function insertHtml(html) {
    document.execCommand('insertHTML', false, html);
  }
  function onKey(e) {
    var mod = e.metaKey || e.ctrlKey;
    if (pop && handlePop(e)) return;
    if (mod && (e.key === 's' || e.key === 'S')) { e.preventDefault(); sync(); save(); return; }
    if (mod && e.key === 'Enter') { e.preventDefault(); publish(); return; }
    if (e.key === '{' && !mod) { e.preventDefault(); openPop(); return; }
    if (e.target === edH && e.key === 'Enter') { e.preventDefault(); edB.focus(); return; }
    if (e.target === edB && e.key === 'Enter' && e.shiftKey) { e.preventDefault(); insertHtml('</p><p>'); }
  }
  edH.addEventListener('keydown', onKey);
  edB.addEventListener('keydown', onKey);
  function onPaste(e) {
    var t = (e.clipboardData || window.clipboardData).getData('text/plain');
    if (t === undefined) return;
    e.preventDefault();
    if (e.currentTarget === edH) { insertHtml(toHtml(t.replace(/\s+/g, ' '))); return; }
    var paras = t.split(/\n\s*\n/).map(function (p) { return toHtml(p.replace(/\s*\n\s*/g, ' ').trim()); }).filter(Boolean);
    insertHtml(paras.join('</p><p>'));
  }
  edH.addEventListener('paste', onPaste);
  edB.addEventListener('paste', onPaste);

  // Remember where the cursor was, so a detail lands there and not at the top.
  window.cnPage.on(document, 'selectionchange', function () {
    var s = window.getSelection();
    if (s && s.rangeCount && (edH.contains(s.anchorNode) || edB.contains(s.anchorNode))) lastRange = s.getRangeAt(0).cloneRange();
  });
  function placeCaret() {
    var s = window.getSelection();
    if (lastRange && s) { s.removeAllRanges(); s.addRange(lastRange); return; }
    edB.focus();
    var r = document.createRange();
    r.selectNodeContents(edB.lastChild || edB); r.collapse(false);
    s.removeAllRanges(); s.addRange(r);
  }
  function closeMenus(except) {
    root.querySelectorAll('details.le-menu[open]').forEach(function (d) { if (d !== except) d.open = false; });
  }
  function insertField(k) {
    closeMenus();
    placeCaret();
    insertHtml(chip(k) + ' ');
    changed();
  }
  root.querySelectorAll('[data-le-insert]').forEach(function (b) {
    b.addEventListener('click', function () { insertField(b.getAttribute('data-le-insert')); });
  });

  // ── the "{" picker ───────────────────────────────────────────────────────
  var q = '', sel = 0;
  function openPop() {
    closePop();
    var s = window.getSelection();
    if (s && s.rangeCount) lastRange = s.getRangeAt(0).cloneRange();
    var rect = lastRange ? lastRange.getBoundingClientRect() : root.getBoundingClientRect();
    pop = document.createElement('div');
    pop.className = 'le-pop';
    pop.setAttribute('role', 'listbox');
    pop.setAttribute('aria-label', 'Add a detail');
    pop.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 240)) + 'px';
    pop.style.top = (rect.bottom + 6) + 'px';
    root.appendChild(pop);
    q = ''; sel = 0;
    drawPop();
  }
  function matches() {
    return fields.filter(function (f) { return !q || f.label.toLowerCase().indexOf(q) >= 0 || f.k.indexOf(q) >= 0; });
  }
  function drawPop() {
    var m = matches();
    if (sel >= m.length) sel = Math.max(0, m.length - 1);
    pop.innerHTML = m.length ? m.map(function (f, i) {
      return '<button type="button" role="option" aria-selected="' + (i === sel) + '" data-k="' + esc(f.k) + '">' + esc(f.label) + '<span>' + esc(f.sample) + '</span></button>';
    }).join('') : '<button type="button" disabled>No detail called “' + esc(q) + '”</button>';
    pop.querySelectorAll('[data-k]').forEach(function (b) {
      b.addEventListener('mousedown', function (ev) { ev.preventDefault(); var k = b.getAttribute('data-k'); closePop(); insertField(k); });
    });
  }
  function handlePop(e) {
    var m = matches();
    if (e.key === 'Escape') { e.preventDefault(); closePop(); return true; }
    if (e.key === 'ArrowDown') { e.preventDefault(); sel = (sel + 1) % Math.max(1, m.length); drawPop(); return true; }
    if (e.key === 'ArrowUp') { e.preventDefault(); sel = (sel - 1 + m.length) % Math.max(1, m.length); drawPop(); return true; }
    if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); var f = m[sel]; closePop(); if (f) insertField(f.k); return true; }
    if (e.key === 'Backspace') { e.preventDefault(); if (q === '') closePop(); else { q = q.slice(0, -1); drawPop(); } return true; }
    if (e.key.length === 1 && /[a-z_ ]/i.test(e.key)) { e.preventDefault(); q += e.key.toLowerCase(); drawPop(); return true; }
    closePop();
    return false;
  }
  function closePop() { if (pop) { pop.remove(); pop = null; } }
  window.cnPage.on(document, 'mousedown', function (e) {
    if (pop && !pop.contains(e.target)) closePop();
    // The bar's menus are <details>: one open at a time, and a click elsewhere closes them.
    var d = e.target.closest ? e.target.closest('details.le-menu') : null;
    closeMenus(d);
  });
  // Keep the caret where it was when "Add a detail" is pressed: a mousedown on its options
  // would otherwise move focus off the page before the click inserts.
  root.querySelectorAll('[data-le-insert]').forEach(function (b) {
    b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
  });

  // ── publish, and leaving ─────────────────────────────────────────────────
  function publish() {
    sync();
    var b = root.querySelector('.le-publish');
    dirty = false;
    if (b && form.requestSubmit) form.requestSubmit(b); else if (b) b.click();
  }
  form.addEventListener('submit', function () { sync(); dirty = false; clearTimeout(timer); });
  window.cnPage.on(window, 'beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });
  window.cnPage.leave(function () { clearTimeout(timer); clearTimeout(pdfTimer); closePop(); });

  sync();
})();
