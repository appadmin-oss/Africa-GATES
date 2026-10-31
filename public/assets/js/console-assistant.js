/* The console assistant — the drawer (every console page) and the expanded page
 * (/admin/assistant) are one component, mounted on each [data-as] root.
 *
 * Plain JS, no framework, and a SHELL script (data-nav="shell"): the drawer outlives every
 * page swap, and the expanded page's root is mounted again on `cn:load` when console-nav
 * swaps it in. Listeners are on the roots themselves, so a swapped-out page takes its own
 * with it; the two document listeners below (Esc, the swap hook) look up live roots.
 *
 * The conversation is the SERVER's (Admin\Services\AssistantThreads). The only thing kept in
 * the browser is which conversation this tab was in, so the drawer and the page continue it.
 *
 * An answer's HTML comes from Support\ChatMarkdown, which escapes everything and writes its
 * own few tags; everything else here is built with textContent.
 */
(function () {
  'use strict';

  var SVGNS = 'http://www.w3.org/2000/svg';

  function current() {
    try { return parseInt(sessionStorage.getItem('ag-asst-thread') || '0', 10) || 0; } catch (e) { return 0; }
  }
  function remember(id) {
    try {
      if (id) sessionStorage.setItem('ag-asst-thread', String(id));
      else sessionStorage.removeItem('ag-asst-thread');
    } catch (e) {}
  }
  /* Read when sending, never copied at load: the token is refreshed under an open page. */
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"], meta[name="ag-csrf"]');
    return m ? m.content : '';
  }
  function api(url, body) {
    var opts = body === undefined
      ? { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }
      : { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'fetch' },
          body: JSON.stringify(body) };
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'The console did not answer. Try again.' }; });
    }, function () { return { ok: false, error: 'No connection. Your message is still in the box.' }; });
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function icon(name) {
    var s = document.createElementNS(SVGNS, 'svg');
    s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
    var u = document.createElementNS(SVGNS, 'use'); u.setAttribute('href', '#ic-' + name);
    s.appendChild(u);
    return s;
  }
  function parse(json, fallback) { try { return JSON.parse(json || ''); } catch (e) { return fallback; } }
  function secs(ms) { return ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : Math.max(1, Math.round(ms)) + ' ms'; }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  // ══════════════════════════════════════════════════════════════════════════
  function Assistant(root) {
    this.root = root;
    this.mode = root.getAttribute('data-as');              // 'page' | 'drawer'
    this.log = root.querySelector('[data-as-log]');
    this.form = root.querySelector('[data-as-form]');
    this.input = root.querySelector('[data-as-in]');
    this.send = root.querySelector('[data-as-send]');
    this.pop = root.querySelector('[data-as-pop]');
    this.help = root.querySelector('[data-as-shortcuts]');
    this.tagBox = root.querySelector('[data-as-tags]');
    this.titleEl = root.querySelector('[data-as-title]');
    this.list = root.querySelector('[data-as-list]');
    this.panel = root.querySelector('[data-as-panel]');
    this.panelBody = root.querySelector('[data-as-panel-body]');
    this.panelTitle = root.querySelector('[data-as-panel-title]');
    this.panelToggle = root.querySelector('[data-as-panel-toggle]');
    this.dest = parse(root.getAttribute('data-as-dest'), []);
    this.suggest = parse(root.getAttribute('data-as-suggest'), null) || [
      'What should I do first this morning?', 'Why are payments stuck?',
      'Which results are ready to release?', 'Anything suspicious in the nomination queue?'];
    this.thread = 0; this.busy = false; this.tags = []; this.lastEvidence = null; this.popAt = 0; this.popItems = [];
    this.bind();
    var open = parseInt(root.getAttribute('data-as-open') || '0', 10) || 0;
    this.open(open || current());
    if (this.list) this.loadList('');
  }

  Assistant.prototype.bind = function () {
    var self = this;
    if (this.form) this.form.addEventListener('submit', function (e) { e.preventDefault(); self.submit(); });
    if (this.input) {
      this.input.addEventListener('input', function () { self.autosize(); self.sync(); self.mention(); });
      this.input.addEventListener('keydown', function (e) { self.key(e); });
    }
    this.root.addEventListener('click', function (e) {
      var t = e.target.closest('button, a');
      if (!t || !self.root.contains(t)) return;
      if (t.hasAttribute('data-as-new')) { e.preventDefault(); self.fresh(); return; }
      if (t.hasAttribute('data-as-help')) { e.preventDefault(); self.toggleHelp(); return; }
      if (t.hasAttribute('data-as-side')) { e.preventDefault(); self.root.classList.toggle('as--side'); return; }
      if (t.hasAttribute('data-as-panel-close')) { e.preventDefault(); self.showPanel(false); return; }
      if (t.hasAttribute('data-as-panel-toggle')) { e.preventDefault(); self.showPanel(self.panel.hidden); return; }
      if (t.hasAttribute('data-as-q')) { e.preventDefault(); self.ask(t.getAttribute('data-as-q')); return; }
      if (t.hasAttribute('data-as-thread')) { e.preventDefault(); self.open(parseInt(t.getAttribute('data-as-thread'), 10)); self.root.classList.remove('as--side'); return; }
      if (t.hasAttribute('data-as-del')) { e.preventDefault(); self.remove(parseInt(t.getAttribute('data-as-del'), 10)); return; }
      if (t.hasAttribute('data-as-untag')) { e.preventDefault(); self.untag(t.getAttribute('data-as-untag')); return; }
      if (t.hasAttribute('data-as-pick')) { e.preventDefault(); self.pick(parseInt(t.getAttribute('data-as-pick'), 10)); return; }
    });
    var find = this.root.querySelector('[data-as-search]');
    if (find) {
      var timer = 0;
      find.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { self.loadList(find.value); }, 250);
      });
    }
  };

  // ── conversations ──────────────────────────────────────────────────────────

  Assistant.prototype.open = function (id) {
    var self = this;
    this.thread = 0; this.lastEvidence = null;
    this.log.textContent = '';
    this.setTitle('');
    this.showPanel(false);
    if (!id) { this.empty(); return; }
    var wait = el('p', 'as-wait', 'Opening the conversation…');
    this.log.appendChild(wait);
    api('/admin/assistant/threads/' + id).then(function (j) {
      wait.remove();
      if (!j || !j.ok) { remember(0); self.empty(); return; }
      self.thread = j.thread.id; remember(self.thread);
      self.setTitle(j.thread.title);
      (j.messages || []).forEach(function (m) { self.draw(m); });
      if (!(j.messages || []).length) self.empty();
      self.mark();
      self.scroll();
    });
  };

  Assistant.prototype.fresh = function () {
    remember(0);
    this.open(0);
    this.mark();
    if (this.input) this.input.focus();
  };

  Assistant.prototype.remove = function (id) {
    var self = this;
    if (!window.confirm('Delete this conversation and the check results in it? This cannot be undone.')) return;
    api('/admin/assistant/threads/' + id + '/delete', {}).then(function (j) {
      if (j && j.ok && id === self.thread) self.fresh();
      self.loadList('');
    });
  };

  Assistant.prototype.loadList = function (q) {
    var self = this;
    if (!this.list) return;
    api('/admin/assistant/threads/feed' + (q ? '?q=' + encodeURIComponent(q) : '')).then(function (j) {
      self.list.textContent = '';
      var rows = (j && j.threads) || [];
      if (!rows.length) {
        self.list.appendChild(el('p', 'as-side__none', q ? 'Nothing you asked matches.' : 'Your conversations will be listed here.'));
        return;
      }
      var group = null, ul = null;
      rows.forEach(function (t) {
        if (t.group !== group) {
          group = t.group;
          self.list.appendChild(el('span', 'as-side__h', group));
          ul = el('ul', 'as-side__ul'); self.list.appendChild(ul);
        }
        var li = el('li', 'as-side__li');
        var a = el('a', 'as-side__a', t.title);
        a.href = '/admin/assistant?t=' + t.id; a.setAttribute('data-as-thread', String(t.id));
        var del = el('button', 'as-side__del'); del.type = 'button';
        del.setAttribute('data-as-del', String(t.id)); del.setAttribute('aria-label', 'Delete “' + t.title + '”');
        del.appendChild(icon('trash'));
        li.appendChild(a); li.appendChild(del); ul.appendChild(li);
      });
      self.mark();
    });
  };

  /* The open conversation, marked in the list. */
  Assistant.prototype.mark = function () {
    if (!this.list) return;
    var id = String(this.thread);
    this.list.querySelectorAll('[data-as-thread]').forEach(function (a) {
      if (a.getAttribute('data-as-thread') === id) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    if (this.mode === 'page' && window.history && window.history.replaceState) {
      try { history.replaceState(history.state, '', this.thread ? '/admin/assistant?t=' + this.thread : '/admin/assistant'); } catch (e) {}
    }
  };

  Assistant.prototype.setTitle = function (t) {
    if (this.titleEl) this.titleEl.textContent = t || (this.mode === 'drawer' ? 'Assistant' : 'New conversation');
    var x = this.root.querySelector('[data-as-expand]');
    if (x) x.href = this.thread ? '/admin/assistant?t=' + this.thread : '/admin/assistant';
  };

  // ── drawing ────────────────────────────────────────────────────────────────

  Assistant.prototype.empty = function () {
    var box = el('div', 'as-empty');
    box.appendChild(el('p', 'as-empty__t', 'What do you need to know?'));
    box.appendChild(el('p', 'as-empty__m', 'It runs the platform’s own checks before it answers, and shows you what each one found.'));
    var qs = el('div', 'as-empty__qs');
    var off = !this.input || this.input.disabled;
    this.suggest.forEach(function (q) {
      var b = el('button', 'as-empty__q', q); b.type = 'button'; b.setAttribute('data-as-q', q);
      b.disabled = off;                            // no provider: nothing to send it to
      qs.appendChild(b);
    });
    box.appendChild(qs);
    this.log.appendChild(box);
  };

  Assistant.prototype.draw = function (m) {
    var empty = this.log.querySelector('.as-empty'); if (empty) empty.remove();
    if (m.role === 'user') {
      var u = el('div', 'as-msg as-msg--me');
      u.appendChild(el('p', 'as-msg__me', m.text));
      this.log.appendChild(u);
      return u;
    }
    var self = this;
    var a = el('article', 'as-msg as-msg--ai');

    // The checks it ran, as steps: the trail from the answer back to its evidence.
    var ran = m.ran || [];
    if (ran.length) {
      var d = el('details', 'as-steps');
      var total = ran.reduce(function (s, r) { return s + (r.ms || 0); }, 0);
      d.appendChild(el('summary', 'as-steps__s', 'Ran ' + plural(ran.length, 'check', 'checks') + (total ? ' · ' + secs(total) : '')));
      var ul = el('ul', 'as-steps__ul');
      ran.forEach(function (r) {
        var li = el('li', 'as-steps__li' + (r.ok === false ? ' as-steps__li--bad' : ''));
        li.appendChild(icon(r.ok === false ? 'x' : 'check'));
        li.appendChild(el('span', 'as-steps__name', typeof r === 'string' ? r : r.title));
        if (r.ms) li.appendChild(el('span', 'as-steps__time', secs(r.ms)));
        ul.appendChild(li);
      });
      d.appendChild(ul);
      a.appendChild(d);
    }

    var body = el('div', 'as-msg__body');
    body.innerHTML = m.html || '';                 // Support\ChatMarkdown: escaped, then its own tags
    if (!m.html) body.textContent = m.text || '';
    a.appendChild(body);

    // One card per check result, opening its evidence beside the answer.
    (m.evidence || []).forEach(function (ev) {
      var card = el('div', 'as-card');
      var ic = el('span', 'as-card__ic'); ic.appendChild(icon(ev.kind === 'table' ? 'table' : 'doc'));
      var txt = el('span', 'as-card__txt');
      txt.appendChild(el('strong', 'as-card__t', ev.title));
      txt.appendChild(el('span', 'as-card__m', ev.ok === false ? 'The check reported a problem'
        : ev.kind === 'table' ? plural(ev.total || ev.rows.length, 'row', 'rows')
        : ev.kind === 'figures' ? plural(ev.figures.length, 'figure', 'figures') : 'Report'));
      var view = el('button', 'as-card__btn', 'View'); view.type = 'button';
      view.addEventListener('click', function () { self.evidence(ev, card); });
      card.appendChild(ic); card.appendChild(txt); card.appendChild(view);
      a.appendChild(card);
    });

    if ((m.actions || []).length) {
      var acts = el('div', 'as-acts');
      m.actions.forEach(function (x) {
        if (!/^\/admin(?:[\/?#]|$)/.test(String(x.url || ''))) return;
        var l = el('a', 'as-act', x.label); l.href = x.url; acts.appendChild(l);
      });
      a.appendChild(acts);
    }

    var fb = el('div', 'as-fb');
    if (m.id) {
      [[1, 'thumb-up', 'Helpful'], [-1, 'thumb-down', 'Not helpful']].forEach(function (v) {
        var b = el('button', 'as-fb__b'); b.type = 'button';
        b.setAttribute('aria-label', v[2]); b.title = v[2];
        b.setAttribute('aria-pressed', m.rating === v[0] ? 'true' : 'false');
        b.appendChild(icon(v[1]));
        b.addEventListener('click', function () {
          var on = b.getAttribute('aria-pressed') !== 'true';
          fb.querySelectorAll('[aria-pressed]').forEach(function (o) { o.setAttribute('aria-pressed', 'false'); });
          b.setAttribute('aria-pressed', on ? 'true' : 'false');
          api('/admin/assistant/messages/' + m.id + '/rate', { value: on ? v[0] : 0 });
        });
        fb.appendChild(b);
      });
    }
    var cp = el('button', 'as-fb__b'); cp.type = 'button'; cp.setAttribute('aria-label', 'Copy the answer'); cp.title = 'Copy';
    cp.appendChild(icon('copy'));
    cp.addEventListener('click', function () {
      var done = function () { cp.classList.add('as-fb__b--done'); setTimeout(function () { cp.classList.remove('as-fb__b--done'); }, 1400); };
      if (navigator.clipboard) navigator.clipboard.writeText(m.text || body.textContent || '').then(done, function () {});
    });
    fb.appendChild(cp);
    a.appendChild(fb);

    this.log.appendChild(a);
    if ((m.evidence || []).length) {
      this.lastEvidence = m.evidence;
      if (this.panelToggle) this.panelToggle.hidden = false;
    }
    return a;
  };

  // ── evidence ───────────────────────────────────────────────────────────────

  /* The page shows it beside the conversation; the drawer, under its card. */
  Assistant.prototype.evidence = function (ev, card) {
    if (this.mode === 'page' && this.panel) {
      this.panelBody.textContent = '';
      this.panelTitle.textContent = ev.title;
      this.panelBody.appendChild(this.renderEvidence(ev));
      this.showPanel(true);
      return;
    }
    var next = card.nextElementSibling;
    if (next && next.classList.contains('as-ev')) { next.remove(); return; }
    card.after(this.renderEvidence(ev));
  };

  Assistant.prototype.renderEvidence = function (ev) {
    var box = el('div', 'as-ev');
    var meta = el('p', 'as-ev__meta');
    meta.appendChild(el('span', 'as-pill as-pill--' + (ev.ok === false ? 'bad' : 'ok'), ev.ok === false ? 'Reported a problem' : 'Ran'));
    if (ev.ms) meta.appendChild(el('span', 'as-ev__time', secs(ev.ms)));
    if (ev.kind === 'table' && ev.total > ev.rows.length) meta.appendChild(el('span', null, 'Showing ' + ev.rows.length + ' of ' + ev.total));
    box.appendChild(meta);
    if (ev.figures && ev.figures.length) {
      var dl = el('dl', 'as-ev__figs');
      ev.figures.forEach(function (f) { dl.appendChild(el('dt', null, f.k)); dl.appendChild(el('dd', 'as-ev__v', f.v)); });
      box.appendChild(dl);
    }
    if (ev.kind === 'table') {
      var wrap = el('div', 'as-ev__scroll'); wrap.tabIndex = 0; wrap.setAttribute('role', 'region'); wrap.setAttribute('aria-label', ev.title);
      var t = el('table', 'as-ev__table');
      var hr = el('tr');
      ev.columns.forEach(function (c) { hr.appendChild(el('th', null, c)); });
      var th = el('thead'); th.appendChild(hr); t.appendChild(th);
      var tb = el('tbody');
      ev.rows.forEach(function (r) { var tr = el('tr'); r.forEach(function (c) { tr.appendChild(el('td', null, c)); }); tb.appendChild(tr); });
      t.appendChild(tb); wrap.appendChild(t); box.appendChild(wrap);
    }
    if (ev.kind === 'text' && ev.text) box.appendChild(el('pre', 'as-ev__code', ev.text));
    var more = el('a', 'as-ev__more', 'Run it again on Operations scripts');
    more.href = '/admin/assistant/scripts#' + encodeURIComponent(ev.key || '');
    box.appendChild(more);
    return box;
  };

  Assistant.prototype.showPanel = function (on) {
    if (!this.panel) return;
    if (on && !this.panelBody.firstChild && this.lastEvidence && this.lastEvidence.length) {
      this.panelTitle.textContent = this.lastEvidence[0].title;
      this.panelBody.appendChild(this.renderEvidence(this.lastEvidence[0]));
    }
    this.panel.hidden = !on;
    this.root.classList.toggle('as--panel', !!on);
    if (this.panelToggle) this.panelToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
  };

  // ── asking ─────────────────────────────────────────────────────────────────

  Assistant.prototype.ask = function (q) {
    if (!this.input || this.input.disabled) return;
    this.input.value = q;
    this.sync();
    this.submit();
  };

  Assistant.prototype.submit = function () {
    var self = this;
    var text = (this.input.value || '').trim();
    if (!text || this.busy) return;
    this.closePops();
    this.busy = true; this.sync();
    this.draw({ role: 'user', text: text });
    this.input.value = ''; this.autosize();
    var tags = this.tags.map(function (t) { return t.href; });
    this.tags = []; this.drawTags();

    // A wait that says what is happening, and how long it has been.
    var wait = el('div', 'as-msg as-msg--ai as-busy');
    var line = el('p', 'as-busy__t', 'Running the platform’s checks');
    var clock = el('span', 'as-busy__time', '');
    wait.appendChild(line); wait.appendChild(clock);
    this.log.appendChild(wait); this.scroll();
    var t0 = Date.now();
    var tick = setInterval(function () { clock.textContent = Math.round((Date.now() - t0) / 1000) + ' s'; }, 1000);

    api('/admin/assistant/chat', {
      message: text, thread_id: this.thread || 0, tags: tags,
      // The page under the drawer is the context; on the expanded page there is none.
      page: this.mode === 'drawer' ? location.pathname + location.search : ''
    }).then(function (j) {
      clearInterval(tick); wait.remove();
      self.busy = false; self.sync();
      if (j && j.ok && j.message) {
        if (j.thread && j.thread.id) {
          var first = !self.thread;
          self.thread = j.thread.id; remember(self.thread); self.setTitle(j.thread.title);
          if (first && self.list) self.loadList('');
          self.mark();
        }
        self.draw(j.message);
      } else {
        self.fail((j && j.error) || 'The assistant did not answer. Try again.', text);
      }
      self.scroll();
    });
  };

  Assistant.prototype.fail = function (msg, text) {
    var self = this;
    var e = el('div', 'as-msg as-msg--ai as-fail');
    e.setAttribute('role', 'alert');
    e.appendChild(el('p', 'as-fail__t', msg));
    var again = el('button', 'as-card__btn', 'Put my question back'); again.type = 'button';
    again.addEventListener('click', function () { e.remove(); self.input.value = text; self.sync(); self.autosize(); self.input.focus(); });
    e.appendChild(again);
    this.log.appendChild(e);
  };

  Assistant.prototype.scroll = function () {
    var log = this.log;
    requestAnimationFrame(function () { log.scrollTop = log.scrollHeight; });
  };

  // ── the composer ───────────────────────────────────────────────────────────

  Assistant.prototype.sync = function () {
    if (this.send) this.send.disabled = this.busy || !this.input || this.input.disabled || !this.input.value.trim();
  };

  Assistant.prototype.autosize = function () {
    var i = this.input; if (!i) return;
    i.style.height = 'auto';
    i.style.height = Math.min(i.scrollHeight, 180) + 'px';
  };

  Assistant.prototype.key = function (e) {
    var popOpen = this.pop && !this.pop.hidden;
    if (popOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      this.popAt = (this.popAt + (e.key === 'ArrowDown' ? 1 : -1) + this.popItems.length) % Math.max(1, this.popItems.length);
      this.paintPop(); return;
    }
    if (popOpen && (e.key === 'Enter' || e.key === 'Tab') && this.popItems.length) { e.preventDefault(); this.pick(this.popAt); return; }
    if (e.key === 'Escape' && ((popOpen) || (this.help && !this.help.hidden))) { e.preventDefault(); e.stopPropagation(); this.closePops(); return; }
    if (e.key === '?' && !this.input.value) { e.preventDefault(); this.toggleHelp(true); return; }
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); this.submit(); }
  };

  /* "@pay" → the console pages whose name starts a word with "pay". */
  Assistant.prototype.mention = function () {
    if (!this.pop) return;
    var v = this.input.value.slice(0, this.input.selectionStart || 0);
    var m = /(?:^|\s)@([^\s@]{0,30})$/.exec(v);
    if (!m) { this.pop.hidden = true; return; }
    var q = m[1].toLowerCase();
    var chosen = this.tags.map(function (t) { return t.href; });
    this.popItems = this.dest.filter(function (d) {
      if (chosen.indexOf(d.href) >= 0) return false;
      return !q || (' ' + d.label.toLowerCase()).indexOf(' ' + q) >= 0 || d.group.toLowerCase().indexOf(q) === 0;
    }).slice(0, 8);
    this.popAt = 0;
    this.paintPop();
  };

  Assistant.prototype.paintPop = function () {
    var self = this;
    this.pop.textContent = '';
    if (!this.popItems.length) { this.pop.hidden = true; return; }
    this.popItems.forEach(function (d, i) {
      var b = el('button', 'as-pop__i'); b.type = 'button';
      b.setAttribute('role', 'option'); b.setAttribute('data-as-pick', String(i));
      b.setAttribute('aria-selected', i === self.popAt ? 'true' : 'false');
      b.appendChild(el('span', 'as-pop__l', d.label));
      b.appendChild(el('span', 'as-pop__g', d.group));
      self.pop.appendChild(b);
    });
    this.pop.hidden = false;
  };

  Assistant.prototype.pick = function (i) {
    var d = this.popItems[i]; if (!d) return;
    var pos = this.input.selectionStart || 0;
    var before = this.input.value.slice(0, pos).replace(/@([^\s@]{0,30})$/, '@' + d.label + ' ');
    this.input.value = before + this.input.value.slice(pos);
    this.tags.push({ label: d.label, href: d.href });
    this.drawTags();
    this.pop.hidden = true;
    this.input.focus(); this.sync();
  };

  Assistant.prototype.untag = function (href) {
    this.tags = this.tags.filter(function (t) { return t.href !== href; });
    this.drawTags();
  };

  Assistant.prototype.drawTags = function () {
    if (!this.tagBox) return;
    this.tagBox.textContent = '';
    this.tags.forEach(function (t) {
      var c = el('span', 'as-tag', t.label);
      var x = el('button', 'as-tag__x'); x.type = 'button';
      x.setAttribute('data-as-untag', t.href); x.setAttribute('aria-label', 'Remove ' + t.label);
      x.appendChild(icon('x'));
      c.appendChild(x); this.tagBox.appendChild(c);
    }, this);
    this.tagBox.hidden = !this.tags.length;
  };

  Assistant.prototype.toggleHelp = function (force) {
    if (!this.help) return;
    var on = force === true ? true : this.help.hidden;
    this.help.hidden = !on;
    var b = this.root.querySelector('[data-as-help]'); if (b) b.setAttribute('aria-expanded', on ? 'true' : 'false');
  };

  Assistant.prototype.closePops = function () {
    if (this.pop) this.pop.hidden = true;
    this.toggleHelp(false);
    if (this.help) this.help.hidden = true;
  };

  // ══════════════════════════════════════════════════════════════════════════
  function mount() {
    document.querySelectorAll('[data-as]').forEach(function (root) {
      if (!root._as) root._as = new Assistant(root);
    });
  }

  /* admin.js opens the drawer and hands it a question through this. */
  window.cnAssist = {
    drawer: function () { var d = document.getElementById('cnAssist'); return d ? d._as : null; },
    ask: function (q) { var a = this.drawer(); if (a && q) a.ask(q); },
    focus: function () { var a = this.drawer(); if (a && a.input) a.input.focus(); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
  document.addEventListener('cn:load', mount);
})();
