/* ════════════════════════════════════════════════════════════════════
   Gee — the Africa GATES guide (client)
   A page-aware assistant. Greets with context for the current page,
   talks to /api/guide (the real AI agent when a key is set, a scripted
   guide otherwise), linkifies the routes it mentions, persists the
   conversation for the session, and resizes — a docked panel on desktop,
   a draggable bottom-sheet on mobile.
   Vanilla JS, no framework dependency. Loaded with `defer`.
   ════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var root = document.getElementById('gee');
  if (!root) return;

  var panel    = document.getElementById('geePanel');
  var fab      = document.getElementById('geeFab');
  var log      = document.getElementById('geeLog');
  var form     = document.getElementById('geeForm');
  var input    = document.getElementById('geeInput');
  var sendBtn  = document.getElementById('geeSend');
  var suggest  = document.getElementById('geeSuggest');
  var closeBtn = document.getElementById('geeClose');
  var clearBtn = document.getElementById('geeClear');
  var scrim    = root.querySelector('.gee-scrim');
  var grip     = document.getElementById('geeResize');

  var PAGE_TITLE = root.dataset.geeTitle || document.title || 'Africa GATES';
  var PAGE_PATH  = location.pathname || '/';

  var SS_MSGS  = 'gee.msgs.v1';
  var SS_SIZE  = 'gee.size.v1';   // desktop {w,h}
  var SS_SHEET = 'gee.sheet.v1';  // mobile sheet height fraction
  var SS_SEEN  = 'gee.seen.v1';

  var mqMobile = window.matchMedia('(max-width:560px)');
  var state = { open: false, busy: false, history: [] }; // history: {role:'user'|'assistant', text}

  // ── Known routes → friendly labels (used to linkify replies) ────────
  var ROUTES = {
    '/vote': 'Vote', '/nominate': 'Nominate', '/registry': 'the Registry',
    '/leaderboard': 'the Leaderboard', '/awards': 'Awards', '/integrity': 'how it works',
    '/methodology': 'how it works', '/shop': 'the Shop', '/events': 'Events',
    '/partner': 'Partner with us', '/register': 'Register', '/help': 'the Help Center',
    '/support': 'Support', '/community': 'the Community', '/donate': 'Donate'
  };
  var ROUTE_RE = /(^|[\s(])(\/(?:vote|nominate|registry|leaderboard|awards|integrity|methodology|shop|events|partner|register|help|support|community|donate))\b/g;

  // ── Page-aware greetings + suggested questions ──────────────────────
  var PROFILES = {
    home: { greet: "Hi, I'm **Gee** 👋 your guide to Africa GATES — the continental Cultural Power Index. Ask me how the CPI works, how to vote or nominate, or where to find anything.",
      chips: ['How does the CPI score work?', 'How do I nominate someone?', 'Take me to the leaderboard'] },
    vote: { greet: "You're on the voting page. I can explain how verified voting works, why it's OTP-confirmed, or how votes feed the CPI.",
      chips: ['How do I cast a vote?', 'Why do I have to verify?', 'How much do votes count?'] },
    nominate: { greet: "Nominating someone? I can walk you through the steps, what makes a strong nomination, or what happens next.",
      chips: ['What do I need to nominate?', 'What makes a strong nomination?', 'What happens after I submit?'] },
    registry: { greet: "This is the verified registry. I can help you search by name, country or field — or explain what the CPI scores and badges mean.",
      chips: ['How do I search the registry?', 'What does the CPI score mean?', 'How do I get listed?'] },
    profile: { greet: "You're viewing a profile. I can explain the CPI breakdown, how to vote for them, or how the ranking is calculated.",
      chips: ['How is this CPI score calculated?', 'How do I vote for them?', 'What do the criteria mean?'] },
    leaderboard: { greet: "These are the live CPI rankings. Ask me how the score is built, how often it updates, or what counts toward it.",
      chips: ['How often does this update?', 'What moves a ranking?', 'How does the CPI work?'] },
    awards: { greet: "Welcome to the award programmes. I can explain each programme, how winners are chosen, or how to get involved.",
      chips: ['How are winners chosen?', 'How do I get involved?', 'What are the programmes?'] },
    integrity: { greet: "This is how scoring, voting and audits actually work. Ask me anything about the method and I'll point you to the detail.",
      chips: ['How is the CPI calculated?', 'How do you stop vote fraud?', 'Who are the judges?'] },
    shop: { greet: "Browsing the shop? Proceeds fund child leadership programmes. I can help with checkout, what your purchase supports, or tracking an order.",
      chips: ['How does checkout work?', 'What do proceeds fund?', 'How do I track my order?'] },
    events: { greet: "Here are events and RSVPs. I can help you find an event, RSVP, or learn about the awards gala.",
      chips: ['How do I RSVP?', 'When is the next event?', 'Tell me about the gala'] },
    partner: { greet: "Thinking about partnering with Africa GATES? I can outline the options and how the enquiry works.",
      chips: ['What partnership options are there?', 'Who is the audience?', 'How do I enquire?'] },
    donate: { greet: "Thinking about giving? Donations fund child leadership programmes — mentorship, scholarships and grassroots education, and every gift is receipted. I can explain where gifts go or how giving works.",
      chips: ['Where does my gift go?', 'Is my donation secure?', 'Do donations affect scores?'] },
    register: { greet: "Creating a verified profile? It's free and takes about a minute. I can explain verification and what a profile gets you.",
      chips: ['What do I need to register?', 'Why verify with an OTP?', 'What does a profile get me?'] },
    help: { greet: "You're in the Help Center. Tell me what you're trying to do and I'll point you straight to it.",
      chips: ['How does voting work?', 'I have an account problem', 'How do I nominate?'] },
    support: { greet: "Need a hand? I can guide you on appeals, account issues, or where to reach a human.",
      chips: ['How do I appeal a decision?', "I can't access my account", 'How do I contact the team?'] },
    community: { greet: "Welcome to the community. I can help you find channels, start a thread, or understand the guidelines.",
      chips: ['How do I start a thread?', 'What are the channels?', 'What are the rules?'] },
    'default': { greet: "Hi, I'm **Gee** 👋 your guide to Africa GATES. Ask me about voting, nominations, the CPI score, the shop or partnerships — I'll point you to the right place.",
      chips: ['How does the CPI work?', 'How do I nominate?', 'Take me to the leaderboard'] }
  };

  function profile() {
    var p = PAGE_PATH;
    if (/^\/registry\/[^/]+/.test(p)) return PROFILES.profile;
    var map = [
      ['/vote', 'vote'], ['/nominate', 'nominate'], ['/registry', 'registry'],
      ['/leaderboard', 'leaderboard'], ['/awards', 'awards'], ['/integrity', 'integrity'],
      ['/methodology', 'integrity'], ['/shop', 'shop'], ['/events', 'events'],
      ['/partner', 'partner'], ['/register', 'register'], ['/help', 'help'],
      ['/support', 'support'], ['/community', 'community'], ['/donate', 'donate']
    ];
    for (var i = 0; i < map.length; i++) {
      var pre = map[i][0];
      if (p === pre || p.indexOf(pre + '/') === 0) return PROFILES[map[i][1]];
    }
    if (p === '/' || p === '') return PROFILES.home;
    return PROFILES['default'];
  }

  // ── Rendering ───────────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Render a reply to safe HTML. SECURITY INVARIANT: esc() runs FIRST, so the
  // text is fully neutralised before any markup is introduced; every tag added
  // afterwards is a constant (<br>, <strong>) or an <a> whose href + label come
  // from the fixed ROUTES whitelist (ROUTE_RE only ever matches known literals).
  // No untrusted capture group is ever interpolated unescaped — keep it that way.
  function format(text) {
    var blocks = String(text).trim().split(/\n{2,}/);
    return blocks.map(function (block) {
      var html = esc(block).replace(/\n/g, '<br>');
      html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      html = html.replace(ROUTE_RE, function (_m, lead, path) {
        var label = ROUTES[path] || path;
        return lead + '<a class="gee-link" href="' + path + '">' + esc(label) + '</a>';
      });
      return '<p>' + html + '</p>';
    }).join('');
  }

  var AV_BOT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 4.6L18.5 8l-4.6 1.9L12 14l-1.9-4.1L5.5 8l4.6-1.4z"/><path d="M18 14l.9 2.3L21 17l-2.1.7L18 20l-.9-2.3L15 17l2.1-.7z"/></svg>';

  function bubble(role, html) {
    var isUser = role === 'user';
    var wrap = document.createElement('div');
    wrap.className = 'gee-msg gee-msg--' + (isUser ? 'me' : 'bot');
    // Only the assistant carries an avatar — the user's own messages read clearly
    // from their side and colour, so dropping that avatar keeps the thread clean.
    if (!isUser) {
      var av = document.createElement('span');
      av.className = 'gee-msg__av';
      av.setAttribute('aria-hidden', 'true');
      av.innerHTML = AV_BOT;
      wrap.appendChild(av);
    }
    var b = document.createElement('div');
    b.className = 'gee-bubble gee-bubble--' + (isUser ? 'me' : 'bot');
    b.innerHTML = html;
    wrap.appendChild(b);
    log.appendChild(wrap);
    return wrap;
  }

  function addUser(text) { bubble('user', '<p>' + esc(text).replace(/\n/g, '<br>') + '</p>'); scrollDown(); }
  function addBot(text)  { bubble('assistant', format(text)); scrollDown(); }

  function typing() {
    var wrap = document.createElement('div');
    wrap.className = 'gee-msg gee-msg--bot';
    wrap.id = 'geeTyping';
    wrap.innerHTML = '<span class="gee-msg__av" aria-hidden="true">' + AV_BOT + '</span>' +
      '<div class="gee-bubble gee-bubble--bot gee-typing" aria-label="Gee is typing"><span></span><span></span><span></span></div>';
    log.appendChild(wrap);
    scrollDown();
  }
  function untyping() { var t = document.getElementById('geeTyping'); if (t) t.remove(); }

  function scrollDown() { log.scrollTop = log.scrollHeight; }

  // ── Suggested-question chips ────────────────────────────────────────
  function showChips(list) {
    suggest.innerHTML = '';
    (list || []).forEach(function (q) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'gee-chip';
      b.textContent = q;
      b.addEventListener('click', function () { send(q); });
      suggest.appendChild(b);
    });
  }
  function clearChips() { suggest.innerHTML = ''; }

  // ── Persistence ─────────────────────────────────────────────────────
  function save() { try { sessionStorage.setItem(SS_MSGS, JSON.stringify(state.history.slice(-40))); } catch (e) {} }
  function load() { try { var r = sessionStorage.getItem(SS_MSGS); return r ? JSON.parse(r) : []; } catch (e) { return []; } }

  function paintInitial() {
    log.innerHTML = '';
    state.history = load();
    if (state.history.length) {
      state.history.forEach(function (m) { m.role === 'user' ? addUser(m.text) : addBot(m.text); });
      clearChips();
    } else {
      addBot(profile().greet);
      showChips(profile().chips);
    }
    scrollDown();
  }

  // ── Talk to the guide ───────────────────────────────────────────────
  function send(text) {
    text = (text || '').trim();
    if (!text || state.busy) return;

    var priorHistory = state.history.slice(-10); // turns before this message
    addUser(text);
    state.history.push({ role: 'user', text: text });
    save();
    clearChips();
    input.value = '';
    autoGrow();

    state.busy = true;
    sendBtn.disabled = true;
    typing();

    fetch('/api/guide', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ message: text, history: priorHistory, page: { title: PAGE_TITLE, path: PAGE_PATH } })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        untyping();
        var reply = (res.data && res.data.reply) ? res.data.reply :
          "I hit a snag just now. In the meantime, /help and /support have you covered.";
        addBot(reply);
        // Only remember successful exchanges so a transient error doesn't poison context.
        if (res.status >= 200 && res.status < 300 && res.data && res.data.ok) {
          state.history.push({ role: 'assistant', text: reply });
          save();
        }
      })
      .catch(function () {
        untyping();
        addBot("I couldn't reach the server. Check your connection — or head to /help and /support in the meantime.");
      })
      .then(function () {
        state.busy = false;
        syncSend();
        if (state.open) input.focus();
      });
  }

  // ── Open / close ────────────────────────────────────────────────────
  function open() {
    state.open = true;
    root.dataset.open = '1';
    panel.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    fab.removeAttribute('data-attention');
    try { sessionStorage.setItem(SS_SEEN, '1'); } catch (e) {}
    applySize();
    if (mqMobile.matches) document.documentElement.classList.add('gee-locked');
    setTimeout(function () { input.focus(); scrollDown(); }, 60);
  }
  function close() {
    state.open = false;
    root.removeAttribute('data-open');
    panel.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('gee-locked');
    fab.focus();
  }
  function toggle() { state.open ? close() : open(); }

  // Public hooks — other surfaces (e.g. the Help/Support "Ask Gee" buttons,
  // the mobile drawer) open Gee directly without going through the launcher.
  window.openGee = open;
  window.closeGee = close;
  window.toggleGee = toggle;

  function newChat() {
    state.history = [];
    try { sessionStorage.removeItem(SS_MSGS); } catch (e) {}
    paintInitial();
    input.focus();
  }

  // ── Composer behaviour ──────────────────────────────────────────────
  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }
  // Send is actionable only when there's text to send (and Gee isn't busy).
  function syncSend() { sendBtn.disabled = state.busy || input.value.trim() === ''; }

  // ── Resize (desktop corner drag + mobile sheet drag) ────────────────
  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  function applySize() {
    if (mqMobile.matches) {
      var f = parseFloat(sessionStorage.getItem(SS_SHEET) || '');
      if (f > 0) panel.style.height = clamp(f, 0.42, 0.92) * window.innerHeight + 'px';
      else panel.style.height = '';
      panel.style.width = '';
    } else {
      panel.style.height = '';
      try {
        var s = JSON.parse(sessionStorage.getItem(SS_SIZE) || 'null');
        if (s && s.w && s.h) {
          panel.style.width  = clamp(s.w, 320, Math.min(560, window.innerWidth - 48)) + 'px';
          panel.style.height = clamp(s.h, 380, window.innerHeight - 48) + 'px';
        } else { panel.style.width = ''; }
      } catch (e) { panel.style.width = ''; }
    }
  }

  (function bindResize() {
    if (!grip) return;
    var dragging = false, mobile = false, sx = 0, sy = 0, sw = 0, sh = 0;

    grip.addEventListener('pointerdown', function (e) {
      dragging = true;
      mobile = mqMobile.matches;
      sx = e.clientX; sy = e.clientY;
      sw = panel.offsetWidth; sh = panel.offsetHeight;
      grip.setPointerCapture(e.pointerId);
      e.preventDefault();
    });

    grip.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      if (mobile) {
        var nh = clamp(sh + (sy - e.clientY), window.innerHeight * 0.30, window.innerHeight * 0.92);
        panel.style.height = nh + 'px';
      } else {
        // panel is anchored bottom-right; dragging the top-left corner grows it up/left
        var w = clamp(sw + (sx - e.clientX), 320, Math.min(560, window.innerWidth - 48));
        var h = clamp(sh + (sy - e.clientY), 380, window.innerHeight - 48);
        panel.style.width = w + 'px';
        panel.style.height = h + 'px';
      }
    });

    function endDrag(e) {
      if (!dragging) return;
      dragging = false;
      try { grip.releasePointerCapture(e.pointerId); } catch (_) {}
      if (mobile) {
        // dragged well below the minimum → treat as dismiss
        if (panel.offsetHeight < window.innerHeight * 0.34) { close(); panel.style.height = ''; return; }
        try { sessionStorage.setItem(SS_SHEET, (panel.offsetHeight / window.innerHeight).toFixed(3)); } catch (_) {}
      } else {
        try { sessionStorage.setItem(SS_SIZE, JSON.stringify({ w: panel.offsetWidth, h: panel.offsetHeight })); } catch (_) {}
      }
    }
    grip.addEventListener('pointerup', endDrag);
    grip.addEventListener('pointercancel', endDrag);
  })();

  // ── Wire up ─────────────────────────────────────────────────────────
  fab.addEventListener('click', toggle);
  closeBtn.addEventListener('click', close);
  if (clearBtn) clearBtn.addEventListener('click', newChat);
  if (scrim) scrim.addEventListener('click', close);

  form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
  input.addEventListener('input', function () { autoGrow(); syncSend(); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.open) close(); });

  // keep the sheet height sane across rotation / resize
  window.addEventListener('resize', function () { if (state.open) applySize(); });

  // first-visit gentle attention pulse (once per session)
  try { if (!sessionStorage.getItem(SS_SEEN)) fab.setAttribute('data-attention', '1'); } catch (e) {}

  paintInitial();
  syncSend();
})();
