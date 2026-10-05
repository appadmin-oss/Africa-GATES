/* ══════════════════════════════════════════════════════════════════════════════
   ONE EVENT — the registration card, the agenda filter, the referral copy
   Phase 7 · EventsPage.dc.html · §8.10. A classic deferred script, no framework.
   ══════════════════════════════════════════════════════════════════════════════

   THE TIER GROUP IS A RADIO GROUP. One tab stop (roving tabindex); arrows, Home and End
   move AND select, skipping a disabled row; Tab leaves. Picking a row:
     · copies the row's six tier properties onto the card, which re-tints ONLY the card's
       accents (eyebrow, figure, border and wash, "Only N left", Apply, total, CTA) — the
       radio stays neutral;
     · replays the glow by REPLACING its element: a finished CSS animation does not restart
       when the same name is re-applied, so a fresh node is the only reliable replay;
     · drops a discount priced against the old tier rather than re-asking silently — the
       number on screen must never belong to a ticket they are no longer buying.

   THE DISCOUNT IS A PREVIEW. `/events/{slug}/quote` says what a code takes off; reserve()
   prices the row again, so a forged reply changes what somebody is shown, never what they
   pay. A quantity change re-asks, because a percentage against three seats is not the
   same number as against one and the rounding is the server's.

   ONE ENDPOINT FOR "I WANT TO COME". A free tier answers with the ticket; a paid one with
   the gateway's URL, followed at once (the seats are already held). A sold-out tier with a
   waiting list posts to /waitlist instead. A waiting list is not a win: the success screen
   says so in its own words. Every string arrives from the template, through |trans. */
(function () {
  'use strict';

  var root = document.querySelector('[data-ed]');
  if (!root) return;
  var slug = root.getAttribute('data-slug');
  var csrf = root.getAttribute('data-csrf');
  var card = root.querySelector('[data-ed-card]');

  function q(sel, el) { return (el || root).querySelector(sel); }
  function qa(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
  function naira(n) { return '₦' + Number(n || 0).toLocaleString('en'); }
  function post(path, data) {
    var body = new URLSearchParams();
    body.append('_token', csrf);
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch('/events/' + encodeURIComponent(slug) + '/' + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString()
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  /* ── Agenda: the track filter ─────────────────────────────────────────── */
  var agenda = q('[data-ed-agenda]');
  if (agenda) {
    agenda.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ed-track]');
      if (!b) return;
      var t = b.getAttribute('data-ed-track');
      qa('[data-ed-track]', agenda).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      qa('.ed-ag__row', agenda).forEach(function (row) {
        var rt = row.getAttribute('data-track') || '';
        row.hidden = !(t === '' || rt === '' || rt === t);
      });
    });
  }

  /* ── Referral link copy: the field is a real input first ─────────────── */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ag-do="copy-ref"]');
    if (!b) return;
    var f = document.getElementById(b.getAttribute('data-target'));
    if (!f) return;
    f.select();
    var label = b.textContent;
    var done = function (ok) { if (ok) { b.textContent = b.getAttribute('data-done') || label; setTimeout(function () { b.textContent = label; }, 2200); } };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(f.value).then(function () { done(true); }, function () { done(false); });
  });

  /* ── The registration form ───────────────────────────────────────────── */
  var form = q('[data-ed-form]');
  if (!form || !card) return;
  var T = function (k) { return form.getAttribute('data-t-' + k) || ''; };
  var group = q('[data-ed-tiers]', form);
  var rows = group ? qa('[data-ed-tier]', group) : [];
  var qty = q('[data-ed-qty]', form), disc = q('[data-ed-disc]', form);
  var err = q('[data-ed-err]', form), cta = q('[data-ag-do="ed-submit"]', form);
  var codeSaid = q('[data-ed-code-said]', form);
  var hasTiers = form.getAttribute('data-has-tiers') === '1';
  var state = { id: 0, price: 0, min: 1, max: 1, gone: false, off: 0, busy: false };

  var picked = rows.filter(function (r) { return r.getAttribute('aria-checked') === 'true'; })[0];
  if (picked) read(picked);

  function read(r) {
    state.id = +r.getAttribute('data-id');
    state.price = +r.getAttribute('data-price');
    state.min = Math.max(1, +r.getAttribute('data-min') || 1);
    state.max = Math.max(state.min, +r.getAttribute('data-max') || 1);
    state.gone = r.getAttribute('data-gone') === '1';
  }

  function glow() {
    var g = q('[data-ev-glow]', card);
    if (!g) return;
    var fresh = g.cloneNode(true);
    fresh.hidden = false;
    g.parentNode.replaceChild(fresh, g);
  }

  function pick(r, quiet) {
    if (!r || r.disabled) return;
    rows.forEach(function (x) {
      var on = x === r;
      x.setAttribute('aria-checked', on ? 'true' : 'false');
      x.tabIndex = on ? 0 : -1;
    });
    read(r);
    card.setAttribute('style', r.getAttribute('style') || '');
    state.off = 0;
    if (codeSaid) codeSaid.textContent = '';
    // Seats: only when the tier allows more than one. Clamped, not reset.
    var keep = qty ? +qty.value || state.min : state.min;
    if (qty) {
      qty.innerHTML = '';
      for (var n = state.min; n <= state.max; n++) { var o = document.createElement('option'); o.value = n; o.textContent = n; qty.appendChild(o); }
      qty.value = Math.min(Math.max(keep, state.min), state.max);
    }
    toggle('[data-ed-qty-wrap]', state.max > 1 && !state.gone);
    toggle('[data-ed-disc-wrap]', state.price > 0 && !state.gone);
    toggle('[data-ed-total-wrap]', state.price > 0 && !state.gone);
    err.textContent = '';
    paint();
    if (!quiet) glow();
  }
  function toggle(sel, on) { var el = q(sel, form); if (el) el.hidden = !on; }

  function seats() { return qty && !q('[data-ed-qty-wrap]', form).hidden ? (+qty.value || 1) : state.min; }
  function due() { return Math.max(0, state.price * seats() - state.off); }

  function paint() {
    var tot = q('[data-ed-total]', form), was = q('[data-ed-was]', form);
    if (tot) tot.textContent = naira(due());
    if (was) { was.hidden = !(state.off > 0); was.textContent = naira(state.price * seats()); }
    if (state.busy) return;
    var n = seats();
    if (state.gone) cta.textContent = T('cta-wait');
    else if (!hasTiers) cta.textContent = T('cta-reg');
    else if (due() > 0) cta.textContent = (n > 1 ? T('cta-pay').replace('%n%', n) : T('cta-one')).replace('%price%', naira(due()));
    else if (state.price > 0) cta.textContent = T('cta-code');
    else cta.textContent = T('cta-free');
  }

  if (group) {
    group.addEventListener('click', function (e) { var r = e.target.closest('[data-ed-tier]'); if (r) pick(r); });
    group.addEventListener('keydown', function (e) {
      var live = rows.filter(function (r) { return !r.disabled; });
      if (!live.length) return;
      var at = live.indexOf(document.activeElement), next = null;
      if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = live[(at + 1 + live.length) % live.length];
      else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = live[(at - 1 + live.length) % live.length];
      else if (e.key === 'Home') next = live[0];
      else if (e.key === 'End') next = live[live.length - 1];
      if (!next) return;
      e.preventDefault();
      next.focus();
      pick(next);
    });
  }
  if (qty) qty.addEventListener('change', function () { if (disc && disc.value.trim()) checkCode(); else paint(); });

  function checkCode() {
    var code = disc.value.trim();
    if (!code || !state.id) return;
    post('quote', { tier_id: state.id, quantity: seats(), discount: code, email: (q('[data-ed-email]', form) || {}).value || '' })
      .then(function (d) {
        state.off = d.applied ? (+d.off || 0) : 0;
        codeSaid.textContent = d.message || (d.applied ? '' : T('code-no'));
        paint();
      }, function () { state.off = 0; codeSaid.textContent = T('code-net'); paint(); });
  }
  if (disc) disc.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); checkCode(); } });

  form.addEventListener('click', function (e) {
    if (e.target.closest('[data-ag-do="ed-code"]')) { checkCode(); return; }
    if (e.target.closest('[data-ag-do="ed-submit"]')) submit();
  });

  function submit() {
    if (state.busy) return;
    var name = q('[data-ed-name]', form).value.trim();
    var email = q('[data-ed-email]', form).value.trim();
    var phone = q('[data-ed-phone]', form).value.trim();
    err.textContent = '';
    if (hasTiers && !state.id) { err.textContent = T('tier'); return; }
    if (!name) { err.textContent = T('name'); return; }
    if (!/.+@.+\..+/.test(email)) { err.textContent = T('email'); return; }
    if (phone.replace(/\D/g, '').length < 7) { err.textContent = T('phone'); return; }

    state.busy = true;
    cta.setAttribute('aria-busy', 'true');
    cta.textContent = state.gone ? T('busy-wait') : (due() > 0 ? T('busy-pay') : T('busy-reg'));
    var finish = function () { state.busy = false; cta.removeAttribute('aria-busy'); paint(); };

    if (state.gone) {
      post('waitlist', { tier_id: state.id, name: name, email: email, phone: phone }).then(function (d) {
        if (d.success) done(d.message + (d.place ? ' ' + T('place').replace('%n%', d.place) : ''), '', true);
        else err.textContent = d.message || root.getAttribute('data-t-net');
        finish();
      }, function () { err.textContent = root.getAttribute('data-t-net'); finish(); });
      return;
    }

    post('register', { tier_id: state.id, quantity: seats(), name: name, email: email, phone: phone,
                       code: form.getAttribute('data-code') || '', discount: disc ? disc.value.trim() : '' })
      .then(function (d) {
        if (d.success && d.pay) { window.location.href = d.pay; return; }
        if (d.success) {
          if (d.flier_token) window.dispatchEvent(new CustomEvent('ag:flier-token', { detail: { token: d.flier_token, name: name } }));
          done(d.message || '', d.ticket_url || '', false);
        } else if (d.full && d.waitlist) {
          // It sold out while they typed: the answer is the queue, and they are typed in.
          state.gone = true; err.textContent = d.message || '';
        } else {
          err.textContent = d.message || root.getAttribute('data-t-net');
        }
        finish();
      }, function () { err.textContent = root.getAttribute('data-t-net'); finish(); });
  }

  function done(msg, ticketUrl, wait) {
    var box = q('[data-ed-done]', card), h = q('[data-ed-done-h]', card);
    form.hidden = true;
    box.hidden = false;
    h.textContent = h.getAttribute(wait ? 'data-t-wait' : 'data-t-reg');
    q('[data-ed-done-msg]', card).textContent = msg;
    var a = q('[data-ed-ticket]', card);
    if (ticketUrl) { a.href = ticketUrl; a.hidden = false; }
    box.focus();
  }

  paint();
})();
