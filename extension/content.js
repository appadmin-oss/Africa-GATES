/**
 * THE PANEL INSIDE THE CALL.
 *
 * Runs in the Meet tab. It does two things: shows the panel the question pack with the
 * criterion each question tests, and reads Meet's live captions so the transcript writes
 * itself.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS CAN AND CANNOT DO, STATED ONCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It can READ the call and WRITE to the interviewer's screen. It cannot speak: putting audio
 * into a Meet call means occupying a participant seat through Google's Meet Media API, which
 * needs a persistent media process. So the model's next question appears here and a human
 * says it out loud.
 *
 * It also cannot turn captions on. Google requires a human to press the CC button, every
 * call. The panel says so, at the top, until captions actually start arriving.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT ASSUMES GOOGLE WILL BREAK IT — AND GIVES THE OPERATOR A WAY OUT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Captions are read out of a page whose markup Google owns and renames without notice. Any
 * selector written here has a shelf life. So there are three strategies in order:
 *
 *   1. Known caption containers, by role and aria-label — the most durable, because those
 *      are accessibility contracts rather than styling.
 *   2. Known class and jsname selectors, which are fast and will eventually rot.
 *   3. THE OPERATOR POINTS AT IT. "Captions not found? Click here, then click the caption
 *      text." The chosen element's path is remembered, and the extension keeps working
 *      through a Google redesign with nobody waiting for an update.
 *
 * And when all three find nothing, the panel says so in red rather than sitting there
 * looking busy. A capture that silently stopped is how a panel discovers, afterwards, that
 * the interview was never written down.
 */

(() => {
  'use strict';

  /**
   * ── WHY THE MEETING CODE IS RE-READ AND NOT CAPTURED ONCE ────────────────
   *
   * This used to be `const CODE = …; if (!CODE) return;` — and that single early return
   * is why the extension could not be triggered.
   *
   * A content script is injected ONCE per document load. Meet is a single-page app: the
   * operator opens meet.google.com, sees their meeting in the list, and clicks it. That is
   * a history navigation, not a page load, so the script had already run — at a moment
   * when the path was `/` and there was no code in it. It returned, permanently, and no
   * later event could bring it back. The panel never appeared, in the tab it was needed in,
   * with nothing anywhere to say why. Pasting the key again did nothing; reinstalling did
   * nothing. Only opening the call URL directly in a fresh tab worked, and nothing on any
   * screen said that.
   *
   * So the code is read live, the panel is mounted when there is a call to mount it into,
   * and a move from one call to another in the same tab reconnects rather than reporting
   * the previous sitting's questions.
   */
  const codeInUrl = () => (location.pathname.match(/([a-z]{3}-[a-z]{4}-[a-z]{3})/) || [])[1] || '';

  let CODE = codeInUrl();

  const SEND_EVERY_MS   = 4000;            // one request per few seconds, never per line
  const NO_CAPTION_WARN = 25000;           // how long before we call it broken

  let state = {
    connected: false,
    capture: false,
    reason: '',
    questions: [],
    i: 0,
    nominee: '',
    followup: '',
    notice: '',                            // a result the operator must not lose to a repaint
    lines: 0,
    lastCaptionAt: 0,
    picking: false,
    customSelector: '',
    strategy: '',
  };

  const pending = [];                      // caption lines waiting to be sent
  const seen = new Map();                  // block id → the text we last queued for it

  // ── the panel ──────────────────────────────────────────────────────────────

  const el = document.createElement('div');
  el.className = 'agx';
  el.innerHTML = `
    <div class="agx__bar" data-agx="bar" title="Drag to move">
      <span class="agx__dot" data-agx="dot"></span>
      <strong data-agx="who">Africa GATES</strong>
      <span class="agx__grow"></span>
      <button class="agx__icon" data-agx="min" title="Hide">–</button>
    </div>
    <div class="agx__body" data-agx="body">
      <div class="agx__msg" data-agx="msg">Connecting…</div>
      <div class="agx__q" data-agx="crit"></div>
      <div class="agx__qt" data-agx="qt"></div>
      <div class="agx__why" data-agx="why"></div>
      <div class="agx__probe" data-agx="probe"></div>
      <div class="agx__fu" data-agx="fu"></div>
      <div class="agx__nav">
        <button class="agx__btn" data-agx="prev">← Back</button>
        <button class="agx__btn agx__btn--go" data-agx="next">Next question →</button>
      </div>
      <div class="agx__foot">
        <span data-agx="count">0 lines captured</span>
        <button class="agx__link" data-agx="pick">Captions not found?</button>
        <button class="agx__link" data-agx="finish">Finish &amp; save transcript</button>
      </div>
    </div>`;
  /**
   * Attach the panel. Deferred until the tab is actually in a call: on the Meet landing
   * page a floating "Connecting…" card over somebody's meeting list is an extension that
   * looks broken before it has had anything to do.
   */
  let mounted = false;
  function mount() {
    if (mounted) return;
    mounted = true;
    document.documentElement.appendChild(el);
    // AFTER the append: place() measures offsetWidth to keep the panel on screen, and a
    // detached element measures zero — so restoring the remembered position before
    // mounting used to park it against the right-hand edge.
    chrome.storage.local.get(['pos']).then((p) => {
      if (p.pos && typeof p.pos.x === 'number') place(p.pos.x, p.pos.y);
    });
  }

  const $ = (k) => el.querySelector(`[data-agx="${k}"]`);
  const on = (k, fn) => $(k).addEventListener('click', fn);

  // ── draggable, because this panel lives in somebody else's application ─────
  //
  // It began pinned bottom-left, which is exactly where Meet renders the captions — the one
  // thing on screen it exists to read. Rather than guess at a corner Google will not use
  // next year, the operator can move it, and where they put it is remembered.
  function place(x, y) {
    const maxX = Math.max(0, window.innerWidth - el.offsetWidth - 8);
    const maxY = Math.max(0, window.innerHeight - 60);
    el.style.left = Math.min(Math.max(0, x), maxX) + 'px';
    el.style.top  = Math.min(Math.max(0, y), maxY) + 'px';
    el.style.bottom = 'auto';
  }

  $('bar').addEventListener('pointerdown', (e) => {
    if (e.target.closest('.agx__icon')) return;          // the hide button is not a handle
    const box = el.getBoundingClientRect();
    const dx = e.clientX - box.left;
    const dy = e.clientY - box.top;
    const move = (ev) => place(ev.clientX - dx, ev.clientY - dy);
    const up = () => {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      const b = el.getBoundingClientRect();
      chrome.storage.local.set({ pos: { x: b.left, y: b.top } });
    };
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
    e.preventDefault();
  });

  on('min', () => el.classList.toggle('agx--min'));
  on('prev', () => { if (state.i > 0) { state.i--; state.followup = ''; state.notice = ''; paint(); } });
  on('next', () => {
    if (state.i < state.questions.length - 1) {
      state.i++; state.followup = ''; state.notice = ''; paint();
    }
  });
  on('pick', () => {
    state.picking = !state.picking;
    document.body.classList.toggle('agx-picking', state.picking);
    paint();
  });
  on('finish', async () => {
    if (!confirm('Save the captured captions as this interview\'s transcript?\n\n'
               + 'It goes into the judges\' dossier, and you can correct it afterwards on the '
               + 'interview screen.')) return;
    await flush();
    const r = await send({ type: 'finish' });
    // A STICKY notice, not state.reason. paint() recomputes the ordinary message from live
    // state every few seconds, so the answer to the one irreversible button on this panel
    // was being wiped within a tick — the operator pressed save and the panel went back to
    // "waiting for captions" as though nothing had happened.
    state.notice = (r.ok ? '✔ ' : '✖ ') + (r.message || 'Done.');
    paint();
  });

  function paint() {
    const q = state.questions[state.i];
    $('who').textContent = state.nominee ? 'Interview: ' + state.nominee : 'Africa GATES';
    $('dot').className = 'agx__dot' + (state.capture && fresh() ? ' agx__dot--live'
                        : state.capture ? ' agx__dot--wait' : ' agx__dot--off');

    let msg = '';
    if (state.notice) msg = state.notice;
    else if (!state.connected) msg = state.reason || 'Connecting…';
    else if (!state.capture) msg = state.reason;
    else if (state.picking) msg = 'Click on the caption text in the page. Nothing else will be clicked.';
    else if (!state.lastCaptionAt) msg = 'Waiting for captions — press CC in Meet to turn them on.';
    else if (!fresh()) msg = 'No captions for a while. Are they still on?';
    $('msg').textContent = msg;
    const bad = state.notice ? state.notice.startsWith('✖') : !state.capture;
    $('msg').className = 'agx__msg' + (msg ? (bad ? ' agx__msg--bad' : '') : ' agx__msg--none');

    if (!state.notice && state.strategy === 'none' && state.capture && Date.now() - startedAt > NO_CAPTION_WARN) {
      $('msg').textContent = 'Captions are on but this extension cannot find them in the page — '
        + 'Google has changed it. Use "Captions not found?" to point at them, or paste the '
        + 'transcript on the interview screen afterwards.';
      $('msg').className = 'agx__msg agx__msg--bad';
    }

    $('crit').textContent = q ? (q.criterion + (q.source === 'claim' ? ' · from the record' : ''))
                              : '';
    $('qt').textContent = q ? q.q : (state.connected ? 'No questions were prepared.' : '');
    $('why').textContent = q ? (q.why || '') : '';
    $('probe').innerHTML = '';
    (q && q.probe || []).forEach((p) => {
      const s = document.createElement('span');
      s.className = 'agx__chip';
      s.textContent = p;
      $('probe').appendChild(s);
    });

    $('fu').textContent = state.followup ? '↳ ' + state.followup : '';
    $('fu').className = 'agx__fu' + (state.followup ? ' agx__fu--on' : '');
    $('count').textContent = state.lines + ' lines captured'
      + (state.questions.length ? ' · question ' + (state.i + 1) + '/' + state.questions.length : '');
    $('pick').textContent = state.picking ? 'Cancel pointing' : 'Captions not found?';
  }

  const fresh = () => state.lastCaptionAt > 0 && Date.now() - state.lastCaptionAt < 20000;
  const startedAt = Date.now();

  function send(msg) {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage(msg, (r) => resolve(r || { ok: false, message: 'No answer from the extension.' }));
      } catch (e) {
        resolve({ ok: false, message: 'The extension was reloaded — refresh this tab.' });
      }
    });
  }

  // ── 1. connect ─────────────────────────────────────────────────────────────

  async function connect() {
    const s = await chrome.storage.local.get(['selector']);
    state.customSelector = s.selector || '';

    const r = await send({ type: 'hello', meetCode: CODE });
    state.connected = !!r.ok;
    state.capture   = !!r.capture;
    state.reason    = r.warning || r.reason || r.message || '';
    state.questions = r.questions || [];
    state.nominee   = r.nominee || '';
    state.lines     = r.lines || 0;
    state.i         = 0;
    state.followup  = '';
    state.notice    = '';
    paint();
    if (state.connected) hunt();
  }

  // ── 2. find the captions ───────────────────────────────────────────────────

  /**
   * Candidate containers, most durable first.
   *
   * The aria selectors are an accessibility contract and outlive class names; the class and
   * jsname ones are what Meet uses today and will rot. Both are tried because when the
   * first stops matching the second may still work, and vice versa.
   */
  const CANDIDATES = [
    '[role="region"][aria-label*="aption" i]',
    '[aria-label*="aption" i][aria-live]',
    'div[jsname="dsyhDe"]',
    'div[jsname="tgaKEf"]',
    '.iOzk7',
    '.a4cQT',
  ];

  let watched = null;

  function findContainer() {
    if (state.customSelector) {
      const c = document.querySelector(state.customSelector);
      if (c) { state.strategy = 'picked'; return c; }
    }
    for (const sel of CANDIDATES) {
      const c = document.querySelector(sel);
      if (c) { state.strategy = 'known'; return c; }
    }
    // Last resort: any live region carrying real text. Broad on purpose — an aria-live
    // region with a sentence in it, inside a video call, is a caption.
    for (const c of document.querySelectorAll('[aria-live="polite"],[aria-live="assertive"]')) {
      if ((c.innerText || '').trim().length > 20) { state.strategy = 'live-region'; return c; }
    }
    state.strategy = 'none';
    return null;
  }

  /**
   * Keep looking: captions do not exist in the DOM until somebody presses CC.
   *
   * Guarded because hunt() re-schedules itself and connect() may now run more than once
   * — moving between calls in one tab would otherwise leave two loops racing to observe
   * the same container, and every caption line would be queued twice.
   */
  let hunting = false;
  function hunt() {
    if (hunting) return;
    hunting = true;
    tick();
  }

  function tick() {
    const c = findContainer();
    if (c && c !== watched) {
      watched = c;
      observe(c);
    }
    paint();
    setTimeout(tick, 3000);
  }

  let observer = null;

  function observe(container) {
    // The previous one is disconnected first. Meet replaces the caption container when
    // captions are toggled, and now that a tab can move between calls this runs more than
    // once per document — two live observers on one feed queue every line twice, and the
    // duplicate arrives as a revision of a line that was already right.
    if (observer) observer.disconnect();
    observer = new MutationObserver(() => scrape(container));
    observer.observe(container, { childList: true, subtree: true, characterData: true });
    scrape(container);
  }

  /**
   * Pull speaker + text out of whatever the caption container currently looks like.
   *
   * ── THE BLOCK IS THE UNIT, NOT THE LEAF ──────────────────────────────────
   *
   * The first version of this walked every leaf <div> and queued each one. Run against a
   * live caption feed it produced this:
   *
   *     [Dr Femi\nHow many]  "Dr Femi"
   *     [Dr Femi]            "How many"
   *     [Video would be here.] "Dr Femi"
   *
   * — the speaker's NAME captured as a spoken line, unrelated page text captured as a
   * speaker, and every revision kept as its own line because the interleaved name lines
   * broke the server's "same speaker as the previous line" collapse.
   *
   * So a caption BLOCK — one direct child of the container — is one utterance. Inside it,
   * the short child is the name and the long one is the words, which is the shape every
   * version of Meet has used and owes nothing to a class name. Nothing is read from ABOVE
   * the block, which is what let "Video would be here." in.
   *
   * ── AND EACH BLOCK CARRIES AN ID ─────────────────────────────────────────
   *
   * Stamped by us, so a revision REPLACES its earlier version on the server instead of
   * relying on adjacency. A recogniser that revises one speaker's line while another is
   * talking no longer leaves both halves in the transcript.
   */
  let blockSeq = 0;

  function scrape(container) {
    if (!state.capture) return;

    const blocks = Array.from(container.children).filter((b) => (b.innerText || '').trim().length > 1);

    // Nothing structural found — take the container's own text as one line, under a fixed
    // id so it revises rather than repeating. Crude, and better than losing the interview.
    if (blocks.length === 0) {
      const whole = (container.innerText || '').trim();
      if (whole.length > 2) queue('whole', '', whole);
      return;
    }

    blocks.forEach((block) => {
      let id = block.getAttribute('data-agx-id');
      if (!id) {
        id = 'b' + (++blockSeq);
        block.setAttribute('data-agx-id', id);
      }
      const { speaker, text } = split(block);
      if (text.length < 2) return;
      queue(id, speaker, text);
    });
  }

  /**
   * A block's speaker and words.
   *
   * The speaker is a child of at most four words that is not the longest child; everything
   * else is what was said. A block with one child has no speaker, which is correct rather
   * than a failure — some layouts caption without naming.
   */
  function split(block) {
    const kids = Array.from(block.children)
      .map((k) => ({ el: k, t: (k.innerText || '').trim() }))
      .filter((k) => k.t.length > 0);

    if (kids.length < 2) {
      return { speaker: '', text: (block.innerText || '').trim() };
    }

    let longest = kids[0];
    kids.forEach((k) => { if (k.t.length > longest.t.length) longest = k; });

    const name = kids.find((k) => k !== longest && k.t.length <= 40 && k.t.split(/\s+/).length <= 4);
    const words = kids.filter((k) => k !== name).map((k) => k.t).join(' ');

    return { speaker: name ? name.t : '', text: words };
  }

  /**
   * Queue one utterance.
   *
   * Keyed on the BLOCK, so the fourth revision of a sentence replaces the third in the
   * outgoing batch rather than travelling beside it.
   */
  function queue(id, speaker, text) {
    const prev = seen.get(id);
    if (prev === text) return;                        // nothing changed
    seen.set(id, text);
    // Bounded: a two-hour call creates a block every few seconds and this map would grow
    // without limit in a tab nobody reloads.
    if (seen.size > 4000) seen.clear();

    // Replace this block's earlier entry if it has not been sent yet.
    const waiting = pending.findIndex((p) => p.id === id);
    if (waiting >= 0) pending[waiting] = { id, speaker, text };
    else pending.push({ id, speaker, text });

    state.lastCaptionAt = Date.now();
  }

  // ── 3. send, on a timer ────────────────────────────────────────────────────

  async function flush() {
    if (!state.connected || !state.capture || pending.length === 0) return;
    const batch = pending.splice(0, 200);
    const q = state.questions[state.i];
    const r = await send({ type: 'say', lines: batch, questionKey: q ? q.key : '' });
    if (r && r.ok) {
      state.lines = r.lines || state.lines;
      if (r.followup && r.followup.q) state.followup = r.followup.q;
      if (r.capture === false) { state.capture = false; state.reason = r.message || ''; }
    } else if (r) {
      // Put them back: a dropped batch must not be a hole in the transcript.
      pending.unshift(...batch);
      state.reason = r.message || 'Could not send the captions.';
    }
    paint();
  }
  setInterval(flush, SEND_EVERY_MS);

  // ── 4. the operator points at the captions ─────────────────────────────────

  document.addEventListener('click', async (e) => {
    if (!state.picking) return;
    if (el.contains(e.target)) return;
    e.preventDefault();
    e.stopPropagation();

    const sel = pathTo(e.target);
    state.customSelector = sel;
    state.picking = false;
    document.body.classList.remove('agx-picking');
    await chrome.storage.local.set({ selector: sel });
    watched = null;                                  // re-observe from the new element
    state.notice = '✔ Captions area saved. It will be used in every call from now on.';
    paint();
  }, true);

  /**
   * A selector for the clicked element's caption CONTAINER.
   *
   * Walks up to something with a stable-looking hook (an id, a jsname, an aria-label) and
   * uses that, because an nth-child path through Meet's tree is broken by the next
   * re-render. Falls back to the path only when there is no hook at all.
   */
  function pathTo(node) {
    let n = node;
    for (let up = 0; n && up < 6; up++, n = n.parentElement) {
      if (n.id) return '#' + CSS.escape(n.id);
      const jsname = n.getAttribute && n.getAttribute('jsname');
      if (jsname) return 'div[jsname="' + jsname + '"]';
      const label = n.getAttribute && n.getAttribute('aria-label');
      if (label && label.length < 60) return '[aria-label="' + CSS.escape(label) + '"]';
    }
    // No hook anywhere: an nth-child path, which is fragile and honest about it.
    const parts = [];
    let p = node;
    while (p && p !== document.body && parts.length < 8) {
      const i = Array.prototype.indexOf.call(p.parentElement.children, p) + 1;
      parts.unshift(p.tagName.toLowerCase() + ':nth-child(' + i + ')');
      p = p.parentElement;
    }
    return 'body > ' + parts.join(' > ');
  }

  // ── 5. boot, and keep booting ───────────────────────────────────────────────
  //
  // The whole reason the extension could not be triggered. Meet is a single-page app and
  // this script is injected once, at document_idle — which for anybody who reaches a call
  // by clicking it in the Meet list is a moment when the URL has no meeting code in it.
  //
  // Polling rather than a history hook: Meet navigates with pushState, and patching
  // History.prototype from a content script reaches only the isolated world's copy, so the
  // page's own navigations would not fire it. `popstate` misses pushState entirely.
  // A one-second string comparison is the version that actually works, and it costs
  // nothing measurable next to a video call.
  let lastCode = '';

  function beat() {
    const now = codeInUrl();
    if (now === lastCode) return;
    lastCode = now;

    if (!now) return;             // left the call, or still on the landing page

    // A different call in the same tab. Reconnecting rather than keeping the old sitting
    // matters: the panel would otherwise show the previous nominee's questions, and the
    // captions of this conversation would be filed against that interview.
    CODE = now;
    watched = null;
    // Anything still buffered belongs to the call that was left. Sending it after the
    // reconnect would file one conversation's captions against another interview.
    pending.length = 0;
    seen.clear();
    mount();
    connect();
  }

  beat();
  setInterval(beat, 1000);
})();
