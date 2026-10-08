/* ══════════════════════════════════════════════════════════════════════════════
   VOTING — the hub, an edition's vote page, the nominee ballot · Phase 5
   ══════════════════════════════════════════════════════════════════════════════

   One classic script for the three pages, each part waking only where its markup is.
   No framework: the shell does not load Alpine, and every control here is a real form
   or link that the server renders working first.

   ── THIS DEVICE'S BALLOT (`ag-vote:ballot:{programmeId}`) ──────────────────────
   Votes are confirmed by an email code with no account, so the server cannot tell a
   visitor which categories they have voted in. What it CAN say is what this device saw
   confirmed: `record()` writes only after /api/vote answered success (or points were
   redeemed), never on a click. Kept in localStorage when Preferences are allowed
   (`<html data-ag-keep="1">`), else sessionStorage — declared in CookieRegistry.

   ── THE BALLOT ─────────────────────────────────────────────────────────────────
   Request a code (/api/otp/request) → enter it (/api/vote) → reload with the server's
   confirmation in the session, so the `vote` celebration is drawn by the SERVER as
   confirmed (Services\Celebration). Turnstile gets a fresh token for every request:
   a token is spent by the verification, refused or not, and replaying a spent one is
   the "timeout-or-duplicate forever" fault the old ballot fixed.

   ── LIVE TALLIES ───────────────────────────────────────────────────────────────
   /vote/{slug}/tallies (cached server-side for 5s) every 10s while the tab is visible,
   patching numbers the page already rendered — never re-deriving ranks here.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var fmt = function (n) { try { return Number(n).toLocaleString(); } catch (e) { return String(n); } };
  var fill = function (t, map) { return String(t || '').replace(/%(\w+)%/g, function (_, k) { return map[k] != null ? map[k] : ''; }); };

  /* ── storage ─────────────────────────────────────────────────────────────── */
  function store() {
    try { return document.documentElement.getAttribute('data-ag-keep') === '1' ? window.localStorage : window.sessionStorage; }
    catch (e) { return null; }
  }
  /* Every key carries the `ag-vote:` prefix, spelled here once as a literal so the cookie
     sweep can read it (CookieRegistryTest) and /cookies declares it (CookieRegistry). */
  function get(rest) { var s = store(); if (!s) return null; try { return s.getItem('ag-vote:' + rest); } catch (e) { return null; } }
  function put(rest, v) { var s = store(); if (!s) return; try { s.setItem('ag-vote:' + rest, v); } catch (e) {} }
  function ballot(pid) { try { return JSON.parse(get('ballot:' + pid) || '{}') || {}; } catch (e) { return {}; } }
  function record(pid, cid, entry) {
    var b = ballot(pid); b[cid] = entry; put('ballot:' + pid, JSON.stringify(b));
  }
  window.AGVote = { ballot: ballot, record: record };

  /* ── the hub ─────────────────────────────────────────────────────────────── */
  function hub(root) {
    var total = 0, of = parseInt(root.getAttribute('data-open-categories'), 10) || 0;
    $$('[data-ballot-prog]', root).forEach(function (li) {
      var cats = parseInt(li.getAttribute('data-cats'), 10) || 0;
      var n = Math.min(cats, Object.keys(ballot(li.getAttribute('data-ballot-prog'))).length);
      total += n;
      var lab = $('[data-n]', li); if (lab) lab.textContent = fill(lab.getAttribute('data-of'), { n: n, m: cats });
      var f = $('[data-fill]', li); if (f) f.style.width = (cats ? Math.round(n / cats * 100) : 0) + '%';
    });
    var pct = of ? Math.round(total / of * 100) : 0;
    var ring = $('[data-ballot-ring]', root); if (ring) ring.style.setProperty('--ring', pct + '%');
    var ratio = $('[data-ballot-ratio]', root); if (ratio) ratio.textContent = total + '/' + of;
    var line = $('[data-ballot-line]', root);
    if (line && total > 0) {
      line.hidden = false;
      var w = $('[data-ballot-words]', line); if (w) w.textContent = fill(w.getAttribute('data-of'), { n: total, m: of });
      var f2 = $('[data-ballot-fill]', line); if (f2) f2.style.width = pct + '%';
    }
  }

  /* ── an edition's vote page ──────────────────────────────────────────────── */
  function votePage(root) {
    var pid = root.getAttribute('data-programme'), cats = parseInt(root.getAttribute('data-categories'), 10) || 0;
    var b = ballot(pid), ids = Object.keys(b), n = Math.min(cats, ids.length), pct = cats ? Math.round(n / cats * 100) : 0;
    $$('[data-cat-link]').forEach(function (a) {
      var t = $('.vp-done', a); if (t) t.hidden = !b[a.getAttribute('data-cat-link')];
    });
    $$('[data-ballot-fill]').forEach(function (f) { f.style.width = pct + '%'; });
    $$('[data-ballot-ratio]').forEach(function (r) { r.textContent = n + '/' + cats; });
    var bar = $('[data-ballot-bar]'); if (bar) bar.setAttribute('aria-valuenow', String(n));
    $$('[data-ballot-words]').forEach(function (w) {
      if (w.hasAttribute('data-of')) { w.textContent = fill(w.getAttribute('data-of'), { n: n, m: cats }); return; }
      w.textContent = n === 0 ? w.getAttribute('data-none') : (n >= cats ? w.getAttribute('data-all') : fill(w.getAttribute('data-some'), { n: cats - n }));
    });

    var dlg = $('[data-review]'), list = $('[data-review-list]'), open = $('[data-review-open]');
    if (open) {
      open.disabled = n === 0;
      open.addEventListener('click', function () {
        if (!dlg || !list) return;
        list.innerHTML = '';
        ids.forEach(function (cid) {
          var e = b[cid] || {}, li = document.createElement('li'), a = document.createElement('a');
          a.href = e.url || '#';
          var t = document.createElement('b'); t.textContent = e.name || '';
          var s = document.createElement('span'); s.textContent = e.cat || '';
          a.appendChild(t); a.appendChild(s); li.appendChild(a); list.appendChild(li);
        });
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
      });
    }
    var close = $('[data-review-close]'); if (close && dlg) close.addEventListener('click', function () { dlg.close ? dlg.close() : dlg.removeAttribute('open'); });

    var url = root.getAttribute('data-tallies');
    if (url && window.fetch) {
      var poll = function () {
        if (document.hidden) return;
        fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
          if (!d || !d.ok || !d.categories) return;
          d.categories.forEach(function (c) {
            (c.nominees || []).forEach(function (x) {
              var row = $('[data-nominee="' + x.id + '"]'); if (!row) return;
              $$('[data-votes]', row).forEach(function (v) { v.textContent = fmt(x.votes); });
              var r = $('[data-rank]', row); if (r) r.textContent = '#' + x.rank;
              var bb = $('[data-bar]', row); if (bb) bb.style.width = x.pct + '%';
            });
          });
        }).catch(function () {});
      };
      setInterval(poll, 10000);
    }
  }

  /* ── the nominee ballot ──────────────────────────────────────────────────── */
  function api(path, body) {
    return fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }); });
  }
  function busy(btn, on) {
    if (!btn) return;
    if (on) { btn.setAttribute('aria-busy', 'true'); btn._label = btn.innerHTML; btn.textContent = btn.getAttribute('data-busy') || btn.textContent; }
    else { btn.removeAttribute('aria-busy'); if (btn._label != null) btn.innerHTML = btn._label; }
  }
  function say(el, msg) { if (!el) return; el.textContent = msg || ''; el.hidden = !msg; }

  function ballotForm(sec) {
    if (!sec.offsetParent && sec.getClientRects().length === 0) return; // the copy not displayed at this width
    var form = $('[data-free-form]', sec);
    var pid = sec.getAttribute('data-award'), cid = sec.getAttribute('data-category');
    var entry = { n: sec.getAttribute('data-nominee'), name: sec.getAttribute('data-name'), url: sec.getAttribute('data-url'), cat: sec.getAttribute('data-category-title'), at: 0 };

    // Turnstile: rendered into the visible ballot only; a fresh token per request.
    var tsKey = sec.getAttribute('data-ts-key'), tsSlot = $('[data-ts-slot]', sec), tsWidget = null, tsToken = '';
    function tsBoot() {
      if (!tsKey || !tsSlot || tsWidget !== null || !window.turnstile) return tsWidget !== null || !tsKey;
      tsWidget = window.turnstile.render(tsSlot, { sitekey: tsKey, theme: 'light',
        callback: function (t) { tsToken = t; }, 'expired-callback': function () { tsToken = ''; }, 'error-callback': function () { tsToken = ''; } });
      return true;
    }
    if (tsKey && !tsBoot()) { var tries = 0, iv = setInterval(function () { if (tsBoot() || ++tries > 150) clearInterval(iv); }, 100); }
    function tsReset() { tsToken = ''; try { if (tsWidget !== null && window.turnstile) window.turnstile.reset(tsWidget); } catch (e) {} }
    function tsWait() {
      return new Promise(function (resolve) {
        if (!tsKey) return resolve('');
        var i = 0, t = setInterval(function () { if (tsToken || ++i > 80) { clearInterval(t); resolve(tsToken || null); } }, 100);
      });
    }

    if (form) {
      var go = $('[data-request]', form), agree = $('[data-agree]', form), err = $('[data-err]', form), errOtp = $('[data-err-otp]', form);
      var stepForm = $('[data-step="form"]', form), stepOtp = $('[data-step="otp"]', form);
      var sync = function () { if (go) go.disabled = !(agree && agree.checked); };
      if (agree) agree.addEventListener('change', sync); sync();
      var val = function (n) { var el = form.elements[n]; return el ? String(el.value || '').trim() : ''; };

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        say(err, '');
        if (!/\S+\s+\S+/.test(val('name'))) { say(err, form.getAttribute('data-e-name') || 'Enter your full name (first and last).'); return; }
        if (val('phone').replace(/\D/g, '').length < 7) { say(err, 'Enter a valid phone number.'); return; }
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(val('email'))) { say(err, 'Enter a valid email address.'); return; }
        busy(go, true);
        tsWait().then(function (token) {
          if (token === null) { busy(go, false); say(err, 'The security check has not finished loading. Give it a moment, then try again.'); return; }
          return api('/api/otp/request', { email: val('email'), nominee_id: +entry.n, award_id: +pid, turnstile_token: token, accept_terms: agree && agree.checked ? 1 : 0 })
            .then(function (d) {
              if (d && d.success) {
                stepForm.hidden = true; stepOtp.hidden = false;
                var p = $('[data-sent-words]', stepOtp); if (p) p.textContent = fill(p.getAttribute('data-sent-words'), { email: val('email') });
                var c = form.elements.otp; if (c) c.focus();
              } else { say(err, (d && d.message) || 'Could not send the code. Please try again.'); }
            });
        }).catch(function () { say(err, 'Network error. Please try again.'); })
          .then(function () { tsReset(); busy(go, false); });
      });

      var confirm = $('[data-confirm]', form);
      if (confirm) confirm.addEventListener('click', function () {
        say(errOtp, '');
        if (!/^\d{6}$/.test(val('otp'))) { say(errOtp, 'Enter the 6-digit code.'); return; }
        busy(confirm, true);
        api('/api/vote', { email: val('email'), otp: val('otp'), nominee_id: +entry.n, award_id: +pid, name: val('name'), phone: val('phone'),
                           message: val('message'), message_show_name: form.elements.message_show_name && form.elements.message_show_name.checked,
                           accept_terms: agree && agree.checked ? 1 : 0 })
          .then(function (d) {
            if (d && d.success) {
              record(pid, cid, entry);
              // Reload, so the confirmation is drawn by the server from its own record.
              location.href = location.pathname + '#ballot';
              location.reload();
            } else { busy(confirm, false); say(errOtp, (d && d.message) || 'Could not record your vote.'); }
          }).catch(function () { busy(confirm, false); say(errOtp, 'Network error. Please try again.'); });
      });
      var back = $('[data-back]', form);
      if (back) back.addEventListener('click', function () { stepOtp.hidden = true; stepForm.hidden = false; });
    }

    // Points.
    var rd = $('[data-redeem]', sec), rdGo = rd && $('[data-redeem-go]', rd);
    if (rdGo) rdGo.addEventListener('click', function () {
      var msg = $('[data-redeem-msg]', rd); busy(rdGo, true);
      fetch('/account/redeem', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': rd.getAttribute('data-csrf') || '' },
                                body: new URLSearchParams({ nominee_id: entry.n }) })
        .then(function (r) { return r.json(); }).then(function (d) {
          busy(rdGo, false);
          if (d && d.ok) { record(pid, cid, entry); rdGo.disabled = true; say(msg, d.message || ''); }
          else say(msg, (d && d.message) || 'Could not redeem right now.');
        }).catch(function () { busy(rdGo, false); say(msg, 'Network error. Please try again.'); });
    });

    // Contributed votes: the chips set the quantity; the button states the price the
    // server will charge, by the same ladder (PaidVoteService::price) — the server still
    // prices the order and trusts nothing here.
    var pf = $('[data-paid-form]', sec);
    if (pf) {
      var tiers = []; try { tiers = JSON.parse(pf.getAttribute('data-tiers') || '[]'); } catch (e) {}
      var per = +pf.getAttribute('data-per') || 0, max = +pf.getAttribute('data-max') || 1;
      var qty = $('[data-qty]', pf), goPaid = $('[data-paid-go]', pf);
      var price = function (q) {
        var off = 0; tiers.forEach(function (t) { if (q >= t.qty) off = t.off; });
        return Math.max(100, Math.ceil(q * per * (100 - off) / 100));
      };
      var paint = function () {
        var q = Math.max(1, Math.min(max, parseInt(qty.value, 10) || 1));
        goPaid.textContent = fill(q === 1 ? pf.getAttribute('data-label-one') : pf.getAttribute('data-label'), { cost: fmt(price(q)), n: fmt(q) });
        $$('[data-tier]', pf).forEach(function (r) { r.checked = (+r.value === q); });
      };
      $$('[data-tier]', pf).forEach(function (r) { r.addEventListener('change', function () { qty.value = r.value; paint(); }); });
      if (qty) { qty.addEventListener('input', paint); paint(); }
      $$('[data-tier]', pf).forEach(function (r) { r.removeAttribute('name'); });
    }
  }

  /* ── messages: cheer and report ──────────────────────────────────────────── */
  function messages() {
    $$('[data-vmi]').forEach(function (li) {
      var tok = li.getAttribute('data-vmi');
      var ch = $('[data-vmi-cheer]', li), rp = $('[data-vmi-report]', li);
      if (ch && get('cheer:' + tok)) ch.setAttribute('aria-pressed', 'true');
      if (rp && get('report:' + tok)) { rp.textContent = rp.getAttribute('data-done'); rp.disabled = true; }
    });
    document.addEventListener('click', function (e) {
      var ch = e.target.closest && e.target.closest('[data-vmi-cheer]');
      var rp = e.target.closest && e.target.closest('[data-vmi-report]');
      var li = (ch || rp) && (ch || rp).closest('[data-vmi]'); if (!li) return;
      var tok = li.getAttribute('data-vmi');
      if (ch && ch.getAttribute('aria-pressed') !== 'true') {
        ch.disabled = true;
        api('/api/vote-message/cheer', { token: tok }).then(function (d) {
          ch.disabled = false;
          if (d && d.success) {
            ch.setAttribute('aria-pressed', 'true'); put('cheer:' + tok, '1');
            var n = $('[data-vmi-n]', ch); if (n && d.cheers != null) n.textContent = fmt(d.cheers);
          }
        }).catch(function () { ch.disabled = false; });
      }
      if (rp && !rp.disabled) {
        rp.disabled = true;
        api('/api/vote-message/report', { token: tok }).then(function (d) {
          if (d && d.success) { rp.textContent = rp.getAttribute('data-done'); put('report:' + tok, '1'); }
          else rp.disabled = false;
        }).catch(function () { rp.disabled = false; });
      }
    });
  }

  function boot() {
    var h = $('[data-vote-hub]'); if (h) hub(h);
    var v = $('[data-vote-page]'); if (v) votePage(v);
    $$('[data-ballot]').forEach(ballotForm);
    // A vote the server just confirmed (the page drew the celebration from the session):
    // record it here too, so a vote cast on another tab of this page is not missed.
    $$('[data-ballot] .vb-done').forEach(function (d) {
      var sec = d.closest('[data-ballot]');
      if (sec) record(sec.getAttribute('data-award'), sec.getAttribute('data-category'),
        { n: sec.getAttribute('data-nominee'), name: sec.getAttribute('data-name'), url: sec.getAttribute('data-url'), cat: sec.getAttribute('data-category-title'), at: 0 });
    });
    if ($('[data-vmi]')) messages();
    $$('[data-fl-share]').forEach(flierShare);
  }
  /* The flier's native share: the real PNG to the OS share sheet. Both checks are needed —
     iOS Safari has share() without canShare(), Android refuses some types — and a button
     that fails is worse than one never offered, because the downloads always work. */
  function flierShare(btn) {
    if (!(navigator.share && navigator.canShare && window.File)) return;
    var status = $('[data-fl-status]');
    btn.hidden = false;
    btn.addEventListener('click', function () {
      btn.setAttribute('aria-busy', 'true'); btn.disabled = true;
      if (status) status.textContent = btn.getAttribute('data-busy');
      fetch(btn.getAttribute('data-png'), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('fetch'); return r.blob(); })
        .then(function (blob) {
          var file = new File([blob], btn.getAttribute('data-file'), { type: 'image/png' });
          if (!navigator.canShare({ files: [file] })) throw new Error('files');
          return navigator.share({ files: [file], title: btn.getAttribute('data-title'), text: btn.getAttribute('data-text') });
        })
        .then(function () { if (status) status.textContent = btn.getAttribute('data-done'); })
        .catch(function (e) {
          // Dismissing the sheet is an AbortError — somebody changing their mind, not a failure.
          if (status) status.textContent = (e && e.name === 'AbortError') ? '' : btn.getAttribute('data-failed');
        })
        .then(function () { btn.setAttribute('aria-busy', 'false'); btn.disabled = false; });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
