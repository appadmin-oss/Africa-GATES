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
  // history: {role:'user'|'assistant', text, extra?:{used,articles,support,ticket}}
  var state = { open: false, busy: false, mode: 'guide', history: [] };

  // ── Known routes → friendly labels (used to linkify replies) ────────
  var ROUTES = {
    '/vote': 'Vote', '/nominate': 'Nominate', '/registry': 'the Registry',
    '/leaderboard': 'the Leaderboard', '/awards': 'Awards', '/integrity': 'how it works',
    '/methodology': 'how it works', '/shop': 'the Shop', '/events': 'Events',
    '/partner': 'Partner with us', '/register': 'Register', '/help': 'the Help Center',
    '/support': 'Support', '/community': 'the Community', '/donate': 'Donate'
  };
  /* `(?![\w/-])` rather than `\b`. With \b, "/help/paid-but-no-votes" matched the
     bare "/help" prefix — because "/" is a non-word character, so \b succeeds
     right before it — and rendered as a link labelled "the Help Center" followed
     by the orphaned text "/paid-but-no-votes". Harmless-looking and badly wrong:
     the reader saw a link that went to the wrong page and a fragment of a URL.
     It only surfaced once Gee started quoting Help Centre article URLs. */
  var ROUTE_RE = /(^|[\s(])(\/(?:vote|nominate|registry|leaderboard|awards|integrity|methodology|shop|events|partner|register|help|support|community|donate))(?![\w/-])/g;

  /* Help Centre articles get their own rule, run FIRST so the route list above
     never sees them. The slug is constrained to [a-z0-9-] by this pattern, which
     is what keeps the SECURITY INVARIANT below true: the capture group cannot
     contain a quote, an angle bracket or a colon, so it cannot break out of the
     href it is interpolated into. Do not widen this character class. */
  var HELP_RE = /(^|[\s(])(\/help\/[a-z0-9](?:[a-z0-9-]{1,60}[a-z0-9])?)/g;

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
      // Articles before routes. The href this produces is quoted with '"', and
      // the slug pattern excludes '"', so the ROUTE_RE pass below cannot match
      // inside it either (the character before "/help" there is a quote, not
      // whitespace or an open bracket).
      html = html.replace(HELP_RE, function (_m, lead, path) {
        return lead + '<a class="gee-link" href="' + path + '">the Help Centre answer</a>';
      });
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

  /* Render one assistant turn: the reply, then what it was built from, then the
     articles worth reading. `extra` is {used, articles, support, ticket} — all
     optional, so an ordinary guide reply renders exactly as it always did. */
  /* ORDER MATTERS. The way out to a person comes BEFORE the reading, because
     somebody whose money is missing wants an action and not a reading list —
     and in a 540px panel whatever goes last goes off-screen. Measured in
     Chromium: with three cards ahead of it, the handoff button was not visible
     at all. */
  function addBot(text, extra) {
    var wrap = bubble('assistant', format(text));
    extra = extra || {};
    if (extra.used && extra.used.length)         wrap.appendChild(usedRow(extra.used));
    if (extra.support && !extra.ticket)          wrap.appendChild(handoffRow());
    if (extra.articles && extra.articles.length) wrap.appendChild(articleStrip(extra.articles));
    // Land on the TOP of the new turn, not the bottom of its attachments — the
    // answer is what they came for, and scrolling to the end of a 300px card
    // strip hides it above the fold.
    revealTop(wrap);
  }

  /* ── What the answer was built from ────────────────────────────────────
     A support bot that says where its answer came from is one people can
     sanity-check. "I re-checked your payment" is a claim the reader can weigh;
     an unsourced paragraph about their money is one they can only believe or
     not. The labels are deliberately plain-English — a tool name means nothing
     to the person reading it. */
  var TOOL_LABEL = {
    fix_payment: 're-checked the payment with the bank',
    resend_receipt: 'sent the receipt again',
    lookup_reference: 'looked up the reference',
    my_transactions: 'checked your payments',
    my_votes: 'checked your votes',
    // SUPPORT tickets, and the word has to say so. Gee floats on every public page,
    // including an event page where "your tickets" is the thing the reader just bought
    // and is holding a code for. Same label, opposite meaning, one line apart from the
    // page content.
    my_tickets: 'checked your support tickets',
    my_nominations: 'checked your nominations',
    refund_status: 'checked the refund',
    help_article: 'read the help answer',
    help_search: 'searched the site',
    site_state: 'checked the current cycle',
    platform_health: 'checked whether anything is down',
    pricing: 'checked vote pricing',
    gateway_status: 'checked the payment provider',
    delivery_health: 'checked vote delivery across the platform',
    when_did_i_vote: 'checked when you voted',
    nominee_tally: 'checked the tally',
    find_nominee: 'found the nominee',
    category_state: 'checked the category',
    check_email_domain: 'checked the email address',
    convert_currency: 'converted the amount'
  };

  function usedRow(used) {
    var row = document.createElement('div');
    row.className = 'gee-used';
    var seen = {};
    used.forEach(function (t) {
      var label = TOOL_LABEL[t];
      if (!label || seen[label]) return;   // unknown tool names are not shown raw
      seen[label] = 1;
      var s = document.createElement('span');
      s.className = 'gee-used__c';
      s.textContent = label;
      row.appendChild(s);
    });
    if (!row.children.length) row.className = 'gee-used gee-used--empty';
    return row;
  }

  /* ── Article preview cards ─────────────────────────────────────────────
     A link inside a paragraph is a bare blue string: no title, no sense of
     what is behind it, nothing to weigh against the effort of leaving the
     conversation. A card with a title and a one-line summary is a decision
     somebody can make at a glance. Same destination, several times the clicks.

     `cited` means the assistant actually read it, which is a different claim
     from "you might also want" and should not look identical. */
  /* TWO cards, not three. The server sends up to three because the support desk
     is a full page with room for them; this is a 380×540 floating panel, and
     three cards measured 300px — more than half the panel, pushing the answer
     itself above the fold. The third card is the one nobody was going to read. */
  var MAX_CARDS = 2;

  function articleStrip(arts) {
    var box = document.createElement('div');
    box.className = 'gee-arts';

    var anyCited = arts.some(function (a) { return a.cited; });
    var h = document.createElement('p');
    h.className = 'gee-arts__h';
    h.textContent = anyCited ? 'From the Help Centre' : 'This might help';
    box.appendChild(h);

    arts.slice(0, MAX_CARDS).forEach(function (a) {
      var card = document.createElement('a');
      card.className = 'gee-art';
      card.href = a.url || '#';
      var tag = document.createElement('span');
      tag.className = 'gee-art__tag';
      tag.textContent = a.category || 'Help';
      // The category's own colour, as a label rather than a filled pill. A pill
      // per card added a row of coloured blocks that read as three buttons, and
      // in this panel the vertical space it cost was the reply.
      if (a.fg) tag.style.color = a.fg;
      var t = document.createElement('strong');
      t.className = 'gee-art__t';
      t.textContent = a.title || 'Help article';
      var s = document.createElement('span');
      s.className = 'gee-art__s';
      s.textContent = a.summary || '';
      card.appendChild(tag);
      card.appendChild(t);
      card.appendChild(s);
      box.appendChild(card);
    });
    return box;
  }

  /* ── The escape hatch ──────────────────────────────────────────────────
     Offered on every support answer, not hidden until Gee has failed twice.
     Making somebody negotiate with a robot to reach a person is the single
     most resented pattern in support software, and this path touches no model
     at all — so it works in exactly the conditions where the assistant does
     not. It reaches the same queue as /support. */
  function handoffRow() {
    var row = document.createElement('div');
    row.className = 'gee-hand';

    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'gee-hand__b';
    b.textContent = 'Pass this to a person';
    b.addEventListener('click', function () { handoff(b); });

    var n = document.createElement('span');
    n.className = 'gee-hand__n';
    n.textContent = 'They reply by email, usually within a working day.';

    row.appendChild(b);
    row.appendChild(n);
    return row;
  }

  function handoff(btn) {
    // The problem is the last thing the PERSON said, not the last thing Gee
    // said — a ticket whose subject is the robot's own paragraph is useless in
    // a queue. Falls back to the whole conversation if we cannot find one.
    var mine = state.history.filter(function (m) { return m.role === 'user'; });
    var problem = mine.length ? mine[mine.length - 1].text : '';
    if (!problem) return;

    btn.disabled = true;
    btn.textContent = 'Passing it on…';

    fetch('/api/support/escalate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        message: problem,
        history: JSON.stringify(state.history.slice(-12).map(function (m) {
          return { role: m.role, content: m.text };
        })),
        page_url: location.href
      })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        var row = btn.parentNode;
        if (row) row.remove();
        addBot((d && d.message) ? d.message
          : 'I could not reach the queue just now. /support will take it, or email the team.',
          { articles: [] });
        if (d && d.ok) { state.history.push({ role: 'assistant', text: d.message }); save(); }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Pass this to a person';
        addBot("I couldn't reach the team just now — /support has the form, and it goes to the same place.");
      });
  }

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

  /* Bring the top of a turn to the top of the log — unless the whole turn fits,
     in which case the ordinary bottom-scroll reads better (a short reply pinned
     to the top of a tall panel looks like a rendering fault). */
  function revealTop(el) {
    var h = el.getBoundingClientRect().height;
    if (h + 24 < log.clientHeight) { scrollDown(); return; }
    log.scrollTop = el.offsetTop - log.offsetTop - 4;
  }

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
      // The mode comes from the LAST assistant turn, not from whether any turn
      // was ever support — a conversation that started with a payment problem and
      // moved on must not still be labelled the support desk after a reload.
      var lastMode = 'guide';
      state.history.forEach(function (m) {
        if (m.role === 'user') { addUser(m.text); return; }
        addBot(m.text, m.extra);
        lastMode = (m.extra && m.extra.support) ? 'support' : 'guide';
      });
      setMode(lastMode);
      clearChips();
    } else {
      addBot(profile().greet);
      showChips(profile().chips);
    }
    scrollDown();
  }

  /* Guide or support desk. Only the label changes — the composer, the history
     and the endpoint are the same, because from the reader's side this is one
     conversation with one assistant, and it should not feel like a transfer. */
  /* TWO chips, kept short. Measured on a 390px sheet: three long chips wrapped
     to three rows and ate ~200px of a 483px panel, which pushed the handoff
     button and the article cards below the fold — so the suggestions were
     crowding out the actions. There is no "speak to someone" chip because the
     handoff button in the thread already is one, and better. */
  var SUPPORT_CHIPS = ['My votes are missing', 'No receipt came'];

  function setMode(mode) {
    state.mode = mode;
    root.dataset.mode = mode;
    var s = document.getElementById('geeStatus');
    if (!s) return;
    s.textContent = mode === 'support'
      ? 'Support — I can check a payment'
      : 'Your Africa GATES guide';
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
        var d = res.data || {};
        var reply = d.reply || "I hit a snag just now. In the meantime, /help and /support have you covered.";
        var extra = {
          used: d.used || [], articles: d.articles || [],
          support: !!d.support, ticket: d.ticket || null
        };
        addBot(reply, extra);
        // The header sub-label follows the LAST turn, both ways. It has to reset:
        // measured in Chromium, asking "how do I nominate someone?" after a
        // payment problem left the header reading "Support — I can check a
        // payment" over an answer about nominations.
        setMode(d.support ? 'support' : 'guide');
        // Only remember successful exchanges so a transient error doesn't poison context.
        if (res.status >= 200 && res.status < 300 && d.ok) {
          state.history.push({ role: 'assistant', text: reply, extra: extra });
          save();
        }
        // Follow-ups that fit the conversation, not the page. Somebody mid-repair
        // does not want "How does the CPI work?" as their next suggested question.
        if (d.support) showChips(SUPPORT_CHIPS);
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
    setMode('guide');   // a new chat is not still mid-repair
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
