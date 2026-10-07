/* ══════════════════════════════════════════════════════════════════════════════
   GEE — the guide and the help desk (client)
   Phase 3 · REFERENCE §7.7, §8.22 · design/Gee.dc.html · partials/gee.twig
   ══════════════════════════════════════════════════════════════════════════════

   Destroyed and written again on 3 Oct 2026 (inventory: docs/handoff/inventory/
   _scripts.md, "Phase 3 destroy — Gee"). A classic deferred script under the nonce,
   exposing `window.AGGee`:

     AGGee.open({mode:'guide'|'support', q})   open in that mode, `q` as a draft
     AGGee.close()

   ── ONE ASSISTANT, TWO MODES, ONE BRAIN ───────────────────────────────────────
   GUIDE talks to /api/guide, which routes a stuck person to the support agent on its
   own (GuideController). SUPPORT is the help desk that replaced /support/assistant: it
   keeps that page's `supportDesk()` store — the transcript that survives a reload, the
   reference it remembers (and forgets on request), `?q=`/`?ref=`/`?ask=` arriving from
   another page, "ask, do not file" — and talks to /api/support/chat, /escalate and
   /desk. The mode is chosen by whoever opens Gee; a reply never flips it under a reader.

   ── THE WORK CARD IS NOT A PERFORMANCE ────────────────────────────────────────
   While a repair is out the card shows the first step active and the rest pending —
   true, nothing has come back. When it answers, the card shows exactly the steps the
   server says RAN (Services\SupportWork): done, or did not complete; a step that never
   happened is not drawn. No timers. The design file's 700/1500/2300 ms are a demo.

   ── EVERY WORD IS HANDED OVER ─────────────────────────────────────────────────
   Sentences arrive as `data-msg-*` on the root, each through `|trans`. Nothing a person
   reads is typed here except the route labels the linkifier uses (kept from the old
   widget, read by GeeSupportsTest).

   ── SECURITY INVARIANT (kept from the old widget) ─────────────────────────────
   format() escapes FIRST; every tag added afterwards is a constant or an <a> whose href
   comes from the fixed ROUTES whitelist, the narrow HELP_RE slug class, or a markdown
   link to a site-relative path of the same narrow class. Nothing untrusted reaches
   innerHTML unescaped. Everything else is built with textContent.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var root = document.querySelector('[data-gee]');
  if (!root) return;

  function q(sel) { return root.querySelector(sel); }
  function qa(sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

  var layer   = q('[data-gee-layer]');
  var panel   = q('.gee__panel');
  var fab     = q('[data-gee-fab]');
  var log     = q('[data-gee-log]');
  var thread  = q('[data-gee-thread]');
  var note    = q('[data-gee-note]');
  var form    = q('[data-gee-form]');
  var input   = q('[data-gee-input]');
  var sendBtn = q('[data-gee-send]');
  var fileIn  = q('[data-gee-filein]');
  var errLine = q('[data-gee-err]');
  if (!layer || !panel || !fab || !form || !input) return;

  var SIGNED = root.getAttribute('data-gee-signed') === '1';
  var TITLE  = root.getAttribute('data-gee-title') || document.title || 'Africa GATES';
  var PATH   = location.pathname || '/';

  var PRIV       = 'ag-gee-privacy';
  var MAX_SAVED  = 40;
  var MAX_Q      = 300;                         // a pasted essay is not a question
  var REF_SHAPE  = /^[A-Za-z0-9_\-]{6,120}$/;   // a reference as it can arrive in a URL
  var OURS       = /\bAFG-[A-Za-z0-9]{2,}(?:-[A-Za-z0-9]{4,})?/i;
  var SHOT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  var SHOT_MAX   = 5 * 1024 * 1024;

  /* ── Words ─────────────────────────────────────────────────────────────── */
  function M(name, vars) {
    var s = root.getAttribute('data-msg-' + name) || '';
    if (vars) Object.keys(vars).forEach(function (k) { s = s.split('%' + k + '%').join(String(vars[k])); });
    return s;
  }
  function span(mins) {
    if (mins < 1) return M('t-now');
    if (mins < 60) return M('t-min', { n: mins });
    var h = Math.round(mins / 60);
    if (h < 24) return h === 1 ? M('t-hour') : M('t-hours', { n: h });
    var d = Math.round(h / 24);
    return d === 1 ? M('t-day') : M('t-days', { n: d });
  }

  /* ── Routes a reply mentions, linked (kept from the old widget) ─────────── */
  var ROUTES = {
    '/vote': 'Vote', '/nominate': 'Nominate', '/registry': 'the Registry',
    '/leaderboard': 'the Leaderboard', '/awards': 'Awards', '/integrity': 'how it works',
    '/methodology': 'how it works', '/shop': 'the Shop', '/events': 'Events',
    '/partner': 'Partner with us', '/register': 'Register', '/help': 'the Help Centre',
    '/support': 'Support', '/community': 'the Community', '/donate': 'Giving'
  };
  /* `(?![\w/-])` rather than `\b`: with \b, "/help/paid-but-no-votes" matched the bare
     "/help" prefix and rendered a link to the wrong page beside an orphaned fragment. */
  var ROUTE_RE = /(^|[\s(])(\/(?:vote|nominate|registry|leaderboard|awards|integrity|methodology|shop|events|partner|register|help|support|community|donate))(?![\w/-])/g;
  /* Articles FIRST, so the route list never sees them. The slug class cannot contain a
     quote, an angle bracket or a colon, so it cannot leave the href. Do not widen it. */
  var HELP_RE = /(^|[\s(])(\/help\/[a-z0-9](?:[a-z0-9-]{1,60}[a-z0-9])?)/g;
  /* `[label](/path)`, which the support agent writes. Site-relative and narrow: an
     absolute URL from a model is a phishing vector; a bad path is at worst a 404 here. */
  var MD_LINK = /\[([^\]\n]{1,80})\]\((\/[a-z0-9\/-]{1,80})\)/g;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function format(text) {
    return String(text || '').trim().split(/\n{2,}/).map(function (block) {
      var html = esc(block).replace(/\n/g, '<br>');
      html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      html = html.replace(MD_LINK, function (_m, label, path) { return '<a href="' + path + '">' + label + '</a>'; });
      html = html.replace(HELP_RE, function (_m, lead, path) {
        return lead + '<a href="' + path + '">' + esc(path) + '</a>';
      });
      html = html.replace(ROUTE_RE, function (_m, lead, path) {
        return lead + '<a href="' + path + '">' + esc(ROUTES[path] || path) + '</a>';
      });
      return '<p>' + html + '</p>';
    }).join('');
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function svg(paths, size, stroke) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('width', size); s.setAttribute('height', size); s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', stroke || '2'); s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    paths.forEach(function (d) { var p = document.createElementNS(ns, 'path'); p.setAttribute('d', d); s.appendChild(p); });
    return s;
  }

  /* ══ State ═════════════════════════════════════════════════════════════════
     `supportDesk()` in the retired page was an Alpine store; this is the same store,
     without Alpine (the shell does not load it): one history per mode, the remembered
     reference, the busy flag, the attached screenshot, the desk's own facts. */
  var state = {
    mode: 'guide', busy: false, ref: '', file: null, desk: null,
    history: { guide: [], support: [] }
  };

  /* Where the transcript is kept across a reload: this tab only. A payment problem is
     not something to leave on a shared machine after the tab closes. */
  function chatKey() { return 'ag-gee-chat:' + state.mode; }

  function save() {
    try {
      sessionStorage.setItem(chatKey(), JSON.stringify({
        ref: state.mode === 'support' ? state.ref : '',
        h: state.history[state.mode].slice(-MAX_SAVED)
      }));
    } catch (e) { /* private mode or the quota: the conversation still works */ }
  }
  function restore(mode) {
    try {
      var raw = sessionStorage.getItem('ag-gee-chat:' + mode);
      var s = raw ? JSON.parse(raw) : null;
      if (s && Array.isArray(s.h)) {
        state.history[mode] = s.h.slice(-MAX_SAVED);
        if (mode === 'support' && typeof s.ref === 'string') state.ref = s.ref;
      }
    } catch (e) {}
  }

  /* The privacy note's dismissal is a Preferences fact (CookieRegistry): kept on the
     device only when Preferences is allowed — the layout hands that answer over as
     `data-ag-keep` — and otherwise for this tab. Both stores spelled at each call. */
  function keep() { return document.documentElement.getAttribute('data-ag-keep') === '1'; }
  function noteDismissed() {
    try { return (keep() ? localStorage.getItem(PRIV) : sessionStorage.getItem(PRIV)) === '1'; }
    catch (e) { return false; }
  }
  function dismissNote() {
    try {
      if (keep()) localStorage.setItem(PRIV, '1');
      else sessionStorage.setItem(PRIV, '1');
    } catch (e) {}
    note.hidden = true;
    input.focus();
  }

  /* ══ Mode ══════════════════════════════════════════════════════════════════ */
  function setMode(mode) {
    mode = mode === 'support' ? 'support' : 'guide';
    var changed = mode !== state.mode;
    state.mode = mode;
    root.setAttribute('data-mode', mode);
    var name = q('[data-gee-name]');
    if (name) name.textContent = M('name-' + mode);
    status();
    panel.setAttribute('aria-label', M('dialog-' + mode));
    var label = q('[data-gee-fab-label]');
    if (label) label.textContent = M('fab-' + mode);
    fab.setAttribute('aria-label', M('fab-aria-' + mode));
    input.placeholder = M('input-' + mode);
    input.setAttribute('aria-label', M('input-' + mode));
    input.maxLength = mode === 'support' ? 1500 : 1000;
    /* The privacy note: second under the header in guide (§7.7), last of the empty
       state in the desk (§8.22). Moved, not copied — one note, one dismissal. */
    var empty = q('[data-gee-empty="support"]');
    if (mode === 'support' && empty) empty.appendChild(note);
    else log.insertBefore(note, log.firstChild);
    if (changed) paint();
  }

  function status() {
    var s = q('[data-gee-status]');
    if (!s) return;
    /* ai_on: with no model the desk still answers from the written help and a person
       still gets the message, so it says that — never a flat "Offline". */
    s.textContent = state.mode === 'support'
      ? (state.desk && state.desk.ai_on === false ? M('status-support-off') : M('status-support'))
      : M('status-guide');
  }

  /* ══ Painting the conversation ═════════════════════════════════════════════ */
  function isEmpty() { return state.history[state.mode].length === 0; }

  function paint() {
    thread.textContent = '';
    state.history[state.mode].forEach(function (m) { draw(m); });
    qa('[data-gee-empty]').forEach(function (e) { e.hidden = !isEmpty(); });
    note.hidden = noteDismissed();
    pending();
    if (isEmpty()) log.scrollTop = 0; else scrollDown();
  }

  function draw(m) {
    switch (m.kind) {
      case 'me':     thread.appendChild(bubble(true, m.text)); break;
      case 'bot':    thread.appendChild(bubble(false, m.text, true));
                     if (m.actions && m.actions.length) thread.appendChild(actions(m.actions));
                     if (m.links && m.links.length) thread.appendChild(links(m.links)); break;
      case 'work':   drawWork(m); break;
      case 'yes':    thread.appendChild(bubble(false, m.text)); break;
      case 'hand':   thread.appendChild(handCard(m)); break;
    }
  }

  function bubble(mine, text, rich) {
    var row = el('div', 'gee__msg ' + (mine ? 'gee__msg--me' : 'gee__msg--bot'));
    var b = el('div', 'gee__bubble');
    if (rich) b.innerHTML = format(text); else b.appendChild(el('p', null, text));
    row.appendChild(b);
    return row;
  }

  /* 5 · Help-link chips: the Help Centre answers the server sent with the reply. */
  function links(arts) {
    var box = el('div', 'gee__links');
    arts.slice(0, 3).forEach(function (a) {
      if (!a || !a.url || !/^\/help\/[a-z0-9-]+$/.test(String(a.url))) return;
      var l = el('a', 'gee__link', (a.title || '') + ' ');
      l.href = a.url;
      l.appendChild(el('span', null, '↗')).setAttribute('aria-hidden', 'true');
      box.appendChild(l);
    });
    return box;
  }

  /* 6 · What Gee can DO: a button to the page that does the thing. Held to a path on
     this site again here — the server validated it, and a link this widget draws must
     never be able to leave the site whatever the server sent. */
  function actions(list) {
    var box = el('div', 'gee__acts');
    list.slice(0, 2).forEach(function (a) {
      if (!a || !a.url || !/^\/(?!\/)[A-Za-z0-9\/_\-.~%?=&+]*$/.test(String(a.url)) || !a.label) return;
      var l = el('a', 'gee__act', String(a.label));
      l.href = a.url;
      box.appendChild(l);
    });
    return box;
  }

  function scrollDown() { log.scrollTop = log.scrollHeight; }

  /* ══ While Gee works ═══════════════════════════════════════════════════════
     Three bouncing dots used to sit here for as long as the server took — which, for
     an answer that looks things up, is several seconds of watching nothing happen.
     It is one line of words now: it says Gee is on it, and if the wait runs on it
     says so plainly instead of animating harder. The words change on TIME only and
     never claim a step that has not happened. `remove()` stops the clock. */
  function typing() {
    var row = el('div', 'gee__msg gee__msg--bot');
    row.setAttribute('data-gee-typing', '');
    var t = el('p', 'gee__status', M('working-1'));
    t.setAttribute('role', 'status');
    row.appendChild(t);
    thread.appendChild(row);
    scrollDown();
    var timers = [
      setTimeout(function () { t.textContent = M('working-2'); }, 4000),
      setTimeout(function () { t.textContent = M('working-3'); }, 12000)
    ];
    var drop = row.remove.bind(row);
    row.remove = function () { timers.forEach(clearTimeout); drop(); };
    return row;
  }

  function push(m) {
    state.history[state.mode].push(m);
    if (m.kind === 'me' || m.kind === 'bot') noteRef(m.text);
    save();
    qa('[data-gee-empty]').forEach(function (e) { e.hidden = true; });
    draw(m);
    scrollDown();
  }

  /* ── The remembered reference ──────────────────────────────────────────────
     Pulled from whatever either side has said, so the reader need not paste it again
     when the conversation moves on to the receipt. Only OUR shape: a bank's own number
     is real but useless as a pin. The × forgets it (kept from the retired desk). */
  function noteRef(text) {
    if (state.mode !== 'support') return;
    var m = String(text || '').match(OURS);
    if (m) { state.ref = m[0]; pending(); }
  }
  function pending() {
    var chip = q('[data-gee-refchip]'), val = q('[data-gee-refval]');
    var fchip = q('[data-gee-filechip]'), fval = q('[data-gee-fileval]');
    var showRef = state.mode === 'support' && !!state.ref;
    var showFile = state.mode === 'support' && !!state.file;
    if (chip) { chip.hidden = !showRef; if (val) val.textContent = state.ref; }
    if (fchip) { fchip.hidden = !showFile; if (fval) fval.textContent = state.file ? state.file.name : ''; }
    var box = q('[data-gee-pending]');
    if (box) box.hidden = !showRef && !showFile;
  }

  function error(msg) {
    if (!errLine) return;
    errLine.textContent = msg || '';
    errLine.hidden = !msg;
  }

  /* What goes back to the server as context: roles and words, nothing else. */
  function priorTurns() {
    return state.history[state.mode].filter(function (m) {
      return (m.kind === 'me' && !m.ask) || m.kind === 'bot' || m.kind === 'work';
    }).slice(-12).map(function (m) {
      var words = m.kind === 'work' ? m.reply : m.text;
      return { role: m.kind === 'me' ? 'user' : 'assistant', content: words, text: words };
    });
  }

  /* ══ Talking ═══════════════════════════════════════════════════════════════ */
  function busy(on) {
    state.busy = on;
    syncSend();
  }
  function syncSend() { sendBtn.disabled = state.busy || input.value.trim() === ''; }

  function post(url, body, json) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: json
        ? { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        : (body instanceof FormData ? { 'X-Requested-With': 'XMLHttpRequest' }
                                    : { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }),
      body: json ? JSON.stringify(body) : body
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, d: d || {} }; });
    });
  }

  function send(text) {
    text = String(text || '').trim();
    if (!text || state.busy) return;
    var prior = priorTurns();
    push({ kind: 'me', text: text });
    input.value = '';
    error('');
    busy(true);
    var dots = typing();

    var req = state.mode === 'support'
      ? post('/api/support/chat', new URLSearchParams({ message: text, history: JSON.stringify(prior) }), false)
      : post('/api/guide', { message: text, history: prior.map(function (p) { return { role: p.role, text: p.text }; }),
                             page: { title: TITLE, path: PATH } }, true);

    req.then(function (res) {
      dots.remove();
      var d = res.d;
      if (d.work) { finishWork(startWork(d.work.reference || state.ref, null), d); return; }
      push({ kind: 'bot', text: d.reply || M('snag'), links: d.articles || [], actions: d.actions || [] });
      unread();
    }).catch(function () {
      dots.remove();
      push({ kind: 'bot', text: M('no-server') });
    }).then(function () { busy(false); if (isOpen()) input.focus(); });
  }

  /* ══ The repair: Check now / Check / "Check a payment" ═════════════════════ */
  function runCheck(ref, provider) {
    ref = String(ref || '').trim();
    if (!ref || state.busy) return;
    if (!REF_SHAPE.test(ref)) { push({ kind: 'bot', text: M('ask-ref') }); return; }
    state.ref = ref; pending();
    push({ kind: 'me', text: M('check-payment', { ref: ref }) });
    busy(true);
    var card = startWork(ref, provider);
    post('/api/support/chat', new URLSearchParams({ check: ref, history: JSON.stringify(priorTurns()) }), false)
      .then(function (res) { finishWork(card, res.d); })
      .catch(function () { card.remove(); push({ kind: 'bot', text: M('no-server') }); })
      .then(function () { busy(false); });
  }

  function stepLabel(key, ref, provider) {
    if (key === 'order') return M('step-order', { ref: ref });
    if (key === 'provider') return M('step-provider', { provider: provider || M('the-provider') });
    return M('step-votes');
  }

  function stepRow(key, st, ref, provider) {
    var row = el('div', 'gee__step');
    row.setAttribute('data-state', st);
    var ring = el('span', 'gee__ring');
    ring.setAttribute('aria-hidden', 'true');
    var ok = svg(['M4 10.5 8.5 15 16 5.5'], 10, '3'); ok.setAttribute('viewBox', '0 0 20 20'); ok.setAttribute('class', 'gee__ring-ok');
    var no = svg(['M10 5v6M10 14.5v.5'], 10, '3'); no.setAttribute('viewBox', '0 0 20 20'); no.setAttribute('class', 'gee__ring-no');
    ring.appendChild(ok); ring.appendChild(no);
    row.appendChild(ring);
    row.appendChild(document.createTextNode(stepLabel(key, ref, provider)));
    if (st === 'failed') row.appendChild(el('span', 'ag-sr', ' — ' + M('step-failed')));
    return row;
  }

  /* While the request is out: the first step active, the others pending. True —
     the server starts by reading the order, and nothing has come back. */
  function startWork(ref, provider) {
    var card = el('div', 'gee__work');
    card.setAttribute('role', 'status');
    ['order', 'provider', 'votes'].forEach(function (k, i) {
      card.appendChild(stepRow(k, i === 0 ? 'active' : 'pending', ref, provider));
    });
    thread.appendChild(card);
    scrollDown();
    return card;
  }

  /* When it answers: exactly the steps that ran. No card at all if none did. */
  function finishWork(card, d) {
    card.remove();
    var w = d && d.work;
    var entry = { kind: 'work', work: w || null, reply: (d && d.reply) || M('snag'), answered: null };
    push(entry);
    unread();
  }

  function drawWork(m) {
    var w = m.work;
    if (w && w.steps && w.steps.length) {
      var card = el('div', 'gee__work');
      card.setAttribute('role', 'status');
      w.steps.forEach(function (s) { card.appendChild(stepRow(s.key, s.state, w.reference, w.provider)); });
      thread.appendChild(card);
    }
    if (w && w.fixed && w.result) {
      thread.appendChild(resultCard(w));
      if (m.answered === null) thread.appendChild(fixAsk(m));
      return;
    }
    thread.appendChild(bubble(false, m.reply, true));
  }

  function resultCard(w) {
    var r = w.result;
    var box = el('div', 'gee__result');
    var t = el('span', 'gee__result-t');
    var ns = 'http://www.w3.org/2000/svg';
    var check = document.createElementNS(ns, 'svg');
    check.setAttribute('width', '18'); check.setAttribute('height', '18'); check.setAttribute('viewBox', '0 0 20 20');
    check.setAttribute('aria-hidden', 'true');
    var disc = document.createElementNS(ns, 'circle');
    disc.setAttribute('cx', '10'); disc.setAttribute('cy', '10'); disc.setAttribute('r', '9'); disc.setAttribute('fill', 'currentColor');
    var tick = document.createElementNS(ns, 'path');
    tick.setAttribute('d', 'M6 10.5 8.8 13.3 14 7.5'); tick.setAttribute('fill', 'none'); tick.setAttribute('stroke-width', '2.2');
    tick.setAttribute('stroke-linecap', 'round'); tick.setAttribute('stroke-linejoin', 'round');
    tick.setAttribute('class', 'gee__result-tick');
    check.appendChild(disc); check.appendChild(tick);
    t.appendChild(check);
    var who = r.nominee && r.nominee.name;
    var line = r.votes > 0
      ? (who ? (r.votes === 1 ? M('fixed-vote', { nominee: who }) : M('fixed-votes', { n: r.votes.toLocaleString(), nominee: who }))
             : M('fixed-votes-only', { n: r.votes.toLocaleString() }))
      : M('fixed-paid');
    t.appendChild(document.createTextNode(line));
    box.appendChild(t);

    var tbl = el('div', 'gee__rcpt');
    [[M('r-amount'), r.amount], [M('r-paid'), r.paid],
     [M('r-receipt'), r.receipt_to ? M('r-sent-to', { email: r.receipt_to }) : M('r-sent-payment')]]
      .forEach(function (kv) {
        var row = el('div', 'gee__rcpt-r');
        row.appendChild(el('span', 'gee__rcpt-k', kv[0]));
        row.appendChild(el('b', 'gee__rcpt-v', kv[1]));
        tbl.appendChild(row);
      });
    box.appendChild(tbl);

    var go = el('div', 'gee__result-go');
    if (r.nominee && r.nominee.url) { var a = el('a', 'gee__pill', M('see-race')); a.href = r.nominee.url; go.appendChild(a); }
    if (r.receipt_url) { var v = el('a', 'gee__pill', M('view-receipt')); v.href = r.receipt_url; go.appendChild(v); }
    box.appendChild(go);
    return box;
  }

  function fixAsk(m) {
    var row = el('div', 'gee__ask');
    row.appendChild(el('span', 'gee__ask-q', M('fix-ask')));
    var b = el('span', 'gee__ask-b');
    var yes = el('button', 'gee__yn', M('yes')); yes.type = 'button';
    var no = el('button', 'gee__yn', M('no')); no.type = 'button';
    yes.addEventListener('click', function () {
      m.answered = 'yes'; row.remove(); save();
      var to = m.work && m.work.result && m.work.result.receipt_to;
      push({ kind: 'yes', text: to ? M('yes-reply', { email: to }) : M('yes-reply-payment') });
    });
    no.addEventListener('click', function () { m.answered = 'no'; row.remove(); save(); openHandoff(); });
    b.appendChild(yes); b.appendChild(no); row.appendChild(b);
    return row;
  }

  /* ══ The handoff card: "A person can take it from here" ════════════════════
     The way to a person, at any time, from the footer; and after "No". It files the
     PERSON's last words, never Gee's paragraph — and with nothing said it asks first
     rather than opening an empty ticket (a ticket with no content is a promise to
     reply to nothing; SupportConversationFaultsTest). */
  function openHandoff(asked) {
    if (asked) push({ kind: 'me', text: M('person-ask'), ask: true });
    var waiting = state.history[state.mode].some(function (m) { return m.kind === 'hand' && !m.ticket; });
    if (!waiting) push({ kind: 'hand', ticket: null, email: null });
    var all = thread.querySelectorAll('[data-gee-hand] .gee__hand-b');
    if (all.length) all[all.length - 1].focus();
  }

  function sla() {
    var h = state.desk && state.desk.sla_hours;
    if (!h) return '';
    return h === 1 ? M('handoff-sla-one') : M('handoff-sla', { n: h });
  }

  function handCard(m) {
    var box = el('div', 'gee__hand');
    box.setAttribute('data-gee-hand', '');
    var h = el('span', 'gee__hand-h');
    var ic = el('span', 'gee__hand-i');
    ic.appendChild(svg(['M16 11a4 4 0 1 0-8 0M3 21a9 9 0 0 1 18 0'], 17));
    h.appendChild(ic);
    var tt = el('span', 'gee__hand-tt');
    tt.appendChild(el('b', 'gee__hand-t', m.ticket ? M('handoff-opened', { ref: m.ticket }) : M('handoff-title')));
    var s = el('span', 'gee__hand-s', sla());
    s.setAttribute('data-gee-sla', '');
    tt.appendChild(s);
    h.appendChild(tt);
    box.appendChild(h);

    if (m.ticket) {
      box.appendChild(handOpened(m));
      return box;
    }

    var email = null;
    if (!SIGNED) {
      var id = 'gee-hand-e-' + Date.now();
      var l = el('label', 'gee__hand-l', M('handoff-email')); l.htmlFor = id;
      email = el('input', 'gee__hand-e'); email.type = 'email'; email.id = id;
      email.setAttribute('autocomplete', 'email'); email.setAttribute('inputmode', 'email');
      box.appendChild(l); box.appendChild(email);
    }
    var pass = el('button', 'gee__hand-b', M('handoff-pass')); pass.type = 'button';
    pass.addEventListener('click', function () { escalate(m, pass, email); });
    box.appendChild(pass);
    return box;
  }

  function handOpened(m) {
    var p = el('p', 'gee__hand-p');
    if (!m.email) { p.textContent = M('handoff-no-email'); return p; }
    var tpl = SIGNED ? M('handoff-attached') : M('handoff-attached-guest');
    var parts = tpl.split('%email%');
    p.appendChild(document.createTextNode(parts[0]));
    p.appendChild(el('b', null, m.email));
    var rest = (parts[1] || '').split('%link%');
    p.appendChild(document.createTextNode(rest[0]));
    if (rest.length > 1) {
      var a = el('a', null, M('handoff-link'));
      a.href = '/support/tickets?ref=' + encodeURIComponent(m.ticket);
      p.appendChild(a);
      p.appendChild(document.createTextNode(rest[1]));
    }
    if (m.attachNote) p.appendChild(document.createTextNode(' ' + m.attachNote));
    return p;
  }

  function escalate(m, btn, emailInput) {
    var mine = state.history[state.mode].filter(function (x) { return x.kind === 'me' && !x.ask; });
    var problem = mine.length ? mine[mine.length - 1].text : '';
    if (!problem) {
      push({ kind: 'bot', text: M('handoff-first') });
      input.focus();
      return;
    }
    btn.disabled = true;
    btn.textContent = M('handoff-passing');
    var fd = new FormData();
    fd.append('message', problem);
    fd.append('history', JSON.stringify(priorTurns()));
    fd.append('page_url', location.href);
    if (emailInput && emailInput.value.trim()) fd.append('email', emailInput.value.trim());
    if (state.file) fd.append('files[]', state.file, state.file.name);

    post('/api/support/escalate', fd, false).then(function (res) {
      var d = res.d;
      if (!d.ok || !d.ticket) {
        btn.disabled = false; btn.textContent = M('handoff-pass');
        push({ kind: 'bot', text: d.message || M('handoff-failed') });
        return;
      }
      m.ticket = d.ticket;
      m.email = d.email || (state.desk && state.desk.email) || null;
      var a = d.attached || {};
      if (a.problems && a.problems.length) m.attachNote = a.problems.join(' ');
      else if (a.stored) m.attachNote = M('attach-kept');
      if (a.stored || (a.problems && a.problems.length)) { state.file = null; pending(); }
      save();
      paint();
    }).catch(function () {
      btn.disabled = false; btn.textContent = M('handoff-pass');
      push({ kind: 'bot', text: M('handoff-failed') });
    });
  }

  /* ══ The desk's own facts: GET /api/support/desk ═══════════════════════════
     Asked when the panel opens, never on a page view that does not open Gee. */
  function loadDesk() {
    return fetch('/api/support/desk', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        state.desk = d;
        status();
        /* The live dot is colour; the words beside it say the same thing. */
        qa('[data-gee-replied]').forEach(function (n) { n.hidden = !d.replied; });
        qa('[data-gee-sla]').forEach(function (s) { s.textContent = sla(); });
        mine(d);
      })
      .catch(function () {});
  }

  /* "From your account": up to two rows in one card (§8.22). */
  function mine(d) {
    var box = q('[data-gee-mine]'), rows = q('[data-gee-mine-rows]');
    if (!box || !rows) return;
    rows.textContent = '';
    if (d.payment) {
      var p = d.payment;
      var row = el('div', 'gee__pay');
      var tile = el('span', 'gee__tile gee__tile--pay'); tile.setAttribute('aria-hidden', 'true');
      tile.appendChild(svg(['M3 7h18v10H3zM3 11h18'], 18));
      row.appendChild(tile);
      var t = el('span', 'gee__pay-t');
      t.appendChild(el('b', 'gee__pay-b', p.provider ? p.amount + ' · ' + p.provider : p.amount));
      t.appendChild(el('span', 'gee__pay-wait', M('pending-for', { t: span(p.minutes) })));
      t.appendChild(el('span', 'gee__pay-ref', p.reference));
      row.appendChild(t);
      var c = el('button', 'gee__check', M('check-now')); c.type = 'button';
      c.addEventListener('click', function () { runCheck(p.reference, p.provider); });
      row.appendChild(c);
      rows.appendChild(row);
    }
    if (d.ticket) {
      var k = d.ticket;
      var a = el('a', 'gee__tkt'); a.href = k.url;
      var ti = el('span', 'gee__tile'); ti.setAttribute('aria-hidden', 'true');
      ti.appendChild(svg(['M4 5h16v10H9l-5 4z'], 18));
      a.appendChild(ti);
      var tt = el('span', 'gee__tkt-t');
      tt.appendChild(el('b', 'gee__tkt-b', M('ticket-row', { ref: k.reference, subject: k.subject })));
      tt.appendChild(el('span', 'gee__tkt-s', k.replied ? M('replied-ago', { t: span(k.minutes) }) : M('waiting')));
      a.appendChild(tt);
      var ch = svg(['m9 6 6 6-6 6'], 16, '2.2'); ch.setAttribute('class', 'gee__tkt-c ag-ico-dir');
      a.appendChild(ch);
      rows.appendChild(a);
    }
    box.hidden = !d.payment && !d.ticket;
  }

  /* ══ Open and close ════════════════════════════════════════════════════════
     Through the site's one sheet implementation: AGChrome pushes one history entry
     (Back closes) over AGShell (focus in, Tab trapped, Esc closes, focus BACK to the
     trigger). The layer is the sheet; the launcher is drawn again from the layer's own
     `data-open`, before that focus lands. */
  var closer = null;

  function isOpen() { return layer.hasAttribute('data-open'); }

  function open(opts) {
    opts = opts || {};
    if (opts.mode) setMode(opts.mode);
    if (!isOpen()) {
      var trigger = opts.trigger || fab;
      if (window.AGChrome && window.AGChrome.openSheet) closer = window.AGChrome.openSheet(layer, null, trigger);
      else if (window.AGShell) closer = window.AGShell.openSheet(layer, null, trigger);
      fab.setAttribute('aria-expanded', 'true');
      clearUnread();
      loadDesk();
    }
    if (typeof opts.q === 'string' && opts.q) {
      input.value = opts.q.replace(/\s+/g, ' ').trim().slice(0, MAX_Q);
      syncSend();
    }
    /* An empty panel opens at its top — the greeting is what it is for; a conversation
       opens at its latest turn. */
    if (isEmpty()) log.scrollTop = 0; else scrollDown();
    /* The composer takes focus where a keyboard is attached. On a touch screen that
       would raise the on-screen keyboard over the panel before anybody chose to type,
       so the sheet's own first focus (the header) stands; with a question handed over
       (`q`) the composer is where the reader is going next either way. */
    if (opts.q || window.matchMedia('(pointer: fine)').matches) setTimeout(function () { input.focus(); }, 0);
  }

  function close() {
    if (closer) { var c = closer; closer = null; c(); }
    else if (isOpen()) { layer.removeAttribute('data-open'); layer.setAttribute('inert', ''); fab.focus(); }
  }

  /* Esc, the browser's Back and AGChrome's handover all close through the sheet; this
     keeps the launcher's state honest whichever way it went. */
  if (window.MutationObserver) {
    new MutationObserver(function () {
      if (!isOpen()) { fab.setAttribute('aria-expanded', 'false'); closer = null; }
    }).observe(layer, { attributes: true, attributeFilter: ['data-open'] });
  }

  /* ── Unread replies, on the tab: only Gee knows whether its own panel was open ── */
  var unreadN = 0;
  function unread() {
    if (isOpen() && !document.hidden) return;
    unreadN++;
    if (window.agFavicon) window.agFavicon.unread(unreadN);
  }
  function clearUnread() {
    if (!unreadN) return;
    unreadN = 0;
    if (window.agFavicon) window.agFavicon.unread(0);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && isOpen()) clearUnread(); });

  function newConversation() {
    state.history[state.mode] = [];
    if (state.mode === 'support') { state.ref = ''; state.file = null; }
    try { sessionStorage.removeItem(chatKey()); } catch (e) {}
    error('');
    paint();
    input.focus();
  }

  /* ══ Arriving with the problem already stated ══════════════════════════════
     `?gee=support` on any URL opens the desk (`/support/assistant` 301s to
     `/help?gee=support`, keeping q, ref, topic and ask). The pages that link here know
     the reference and what went wrong; making the person retype forty characters is
     where a digit gets dropped. `ref` is held to the shape a reference has, because it
     becomes a repair; `q` is free text, so it is only ever a DRAFT, bound as text and
     capped; `ask=1` with a reference RUNS the repair (the proof page's one action).
     Read only beside `gee=support`: a search page's own `?q=` is not a question to Gee.
     Then the parameters are taken off the address, so a reload does not repair again. */
  function fromLink() {
    var p = new URLSearchParams(location.search);
    if (p.get('gee') !== 'support') return;
    var ref = (p.get('ref') || '').trim().slice(0, 120);
    if (ref && !REF_SHAPE.test(ref)) ref = '';
    var topic = p.get('topic') || '';
    var text = (p.get('q') || '').replace(/\s+/g, ' ').trim().slice(0, MAX_Q);
    var ask = p.get('ask') === '1';

    ['gee', 'q', 'ref', 'topic', 'ask'].forEach(function (k) { p.delete(k); });
    try {
      var rest = p.toString();
      history.replaceState(history.state, '', location.pathname + (rest ? '?' + rest : '') + location.hash);
    } catch (e) {}

    if (!text && !ref && (topic === 'payment' || topic === 'votes')) {
      text = topic === 'votes' ? M('topic-votes') : M('topic-payment');
    }
    open({ mode: 'support', q: ref && ask ? '' : text });
    if (ref) {
      state.ref = ref; pending(); save();
      if (ask) runCheck(ref);
    }
  }

  /* ══ Wiring ════════════════════════════════════════════════════════════════ */
  fab.addEventListener('click', function () { open({ trigger: fab }); });
  q('[data-gee-close]').addEventListener('click', close);
  q('[data-gee-reset]').addEventListener('click', newConversation);
  q('[data-gee-note-x]').addEventListener('click', dismissNote);
  qa('[data-gee-person]').forEach(function (b) { b.addEventListener('click', function () { openHandoff(true); }); });

  form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
  input.addEventListener('input', syncSend);

  qa('[data-gee-ask]').forEach(function (b) {
    b.addEventListener('click', function () {
      var fix = b.getAttribute('data-gee-fix');
      if (fix === 'check') {
        var known = state.ref || (state.desk && state.desk.payment && state.desk.payment.reference) || '';
        if (known) { runCheck(known, state.desk && state.desk.payment && state.desk.payment.reference === known ? state.desk.payment.provider : null); return; }
        var refIn = q('[data-gee-ref]');
        if (refIn) { refIn.focus(); return; }
        push({ kind: 'bot', text: M('ask-ref') });
        input.focus();
        return;
      }
      if (fix === 'receipt' && state.ref) { send(M('fix-receipt-ref', { ref: state.ref })); return; }
      send(b.getAttribute('data-gee-ask'));
    });
  });

  var refForm = q('[data-gee-refform]');
  if (refForm) {
    var refIn = q('[data-gee-ref]');
    /* Auto-uppercase the VALUE (never with text-transform: no capitals in CSS), keeping
       the caret where the person is typing. */
    refIn.addEventListener('input', function () {
      var s = refIn.selectionStart, e2 = refIn.selectionEnd;
      var up = refIn.value.toUpperCase();
      if (up !== refIn.value) { refIn.value = up; try { refIn.setSelectionRange(s, e2); } catch (x) {} }
    });
    refForm.addEventListener('submit', function (e) { e.preventDefault(); runCheck(refIn.value); });
    var signin = q('[data-gee-signin]');
    if (signin) {
      var back = new URLSearchParams(location.search); back.set('gee', 'support');
      signin.href = '/account/login?next=' + encodeURIComponent(location.pathname + '?' + back.toString());
    }
  }

  q('[data-gee-forget]').addEventListener('click', function () { state.ref = ''; pending(); save(); input.focus(); });

  /* Attach a screenshot: images only, 5MB. Checked here as a courtesy; the server
     decides on the stored bytes (SupportAttachmentService, screenshot profile). It goes
     to a person with the conversation, so it travels with the handoff. */
  q('[data-gee-attach]').addEventListener('click', function () { if (fileIn) fileIn.click(); });
  if (fileIn) fileIn.addEventListener('change', function () {
    var f = fileIn.files && fileIn.files[0];
    fileIn.value = '';
    if (!f) return;
    if (SHOT_TYPES.indexOf(f.type) < 0) { error(M('attach-bad-type')); return; }
    if (f.size > SHOT_MAX) { error(M('attach-too-big')); return; }
    error('');
    state.file = f;
    pending();
    var fv = q('[data-gee-fileval]');
    if (fv) fv.setAttribute('title', M('attach-note'));
  });
  q('[data-gee-unattach]').addEventListener('click', function () { state.file = null; pending(); input.focus(); });

  /* Any control on any page: `data-ag-do="open-gee"` (+ `data-gee-mode`, `data-gee-q`). */
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-ag-do="open-gee"]') : null;
    if (!t) return;
    e.preventDefault();
    open({ mode: t.getAttribute('data-gee-mode') || 'guide', q: t.getAttribute('data-gee-q') || '', trigger: t });
  });

  window.AGGee = {
    open: function (o) { o = o || {}; open({ mode: o.mode, q: o.q, trigger: o.trigger }); },
    close: close
  };

  /* ══ Boot ══════════════════════════════════════════════════════════════════ */
  restore('guide');
  restore('support');
  /* A page may default to the help desk's face (the Help Centre: data-gee-default). */
  setMode(root.getAttribute('data-gee-default') === 'support' ? 'support' : 'guide');
  paint();
  syncSend();
  fab.hidden = false;
  fromLink();
})();
