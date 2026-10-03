/* ══════════════════════════════════════════════════════════════════════════════
   The celebration's boot — §7.8 of the Phase 3 spec, loaded nonced and deferred by
   partials/celebration.twig after celebration.js (the handoff's engine, shipped
   byte-identical; nothing here edits or wraps it).
   ══════════════════════════════════════════════════════════════════════════════

   The spec's boot, plus the four things the verbatim engine cannot know about this site:

   · PLAY ONCE NEEDS THE PREFERENCES ANSWER. The engine remembers a seen moment in
     localStorage `ag-cel-<key>` unconditionally — remembering for next time is the
     Preferences category (CookieRegistry). So the key is handed over only when the
     visitor allowed Preferences (`data-ag-keep="1"` on <html>, the same answer a11y.js
     reads). Without it the engine is given no key, writes nothing, and the burst plays
     on each visit. That is the cost of consent with this engine, and it is recorded as
     a question for the owner (docs/handoff/PHASE-3.md) rather than solved by editing it.

   · HAPTICS ONLY AFTER A TAP (see tapped()).

   · THE SITE'S OWN REDUCE MOTION. The engine reads the OS media query only; the Display &
     reading switch sets `html.ag-rm`. Under it: no haptics, no count-up, no replay — and
     celebration-card.css hides the particle layers.

   · THE TICKET'S TWO WORDS. The engine draws `ticketLabel` / `ticketWhen` into its stub
     with innerHTML and falls back to its demo defaults ("KCEA Ceremony", "Sat 6 Dec · 18:00")
     on an empty value. So both are HTML-escaped here, and an empty one is passed as a
     no-break space — the demo event can never reach a real ticket.

   · IDEMPOTENT. Two celebrations on one page load these scripts twice; a stage is mounted
     once (`data-agc-on`).

   Never instead of the page: the title, the sub and every stat's FINAL figure are the
   server's markup, complete before this runs. countUp() only rewinds and replays numbers
   that are already right, and a page where this never runs is a page that is correct. */
(function () {
  'use strict';

  function esc(v) {
    return String(v || '').replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    }) || '\u00a0';
  }

  /* A browser refuses navigator.vibrate() until the person has touched the page, and says
     so in the console — so a celebration that arrives on page load (a success page reached
     by a redirect) would log a refusal on every view. Asked only once there has been a tap
     here, which is exactly when a buzz can happen at all. */
  function tapped() {
    try { return !!(navigator.userActivation && navigator.userActivation.hasBeenActive); } catch (e) { return false; }
  }

  function boot() {
    if (!window.AGCelebrate) return;
    var root = document.documentElement;
    var keep = root.getAttribute('data-ag-keep') === '1';
    var rm = root.classList.contains('ag-rm');

    document.querySelectorAll('[data-agc-kind]').forEach(function (el) {
      if (el.hasAttribute('data-agc-on')) return;
      el.setAttribute('data-agc-on', '');
      var card = el.closest('.ag-cel');
      var forced = el.getAttribute('data-agc-layout');
      var fx = window.AGCelebrate.mount(el, {
        kind: el.dataset.agcKind,
        size: el.dataset.agcSize,
        layout: forced || (matchMedia('(min-width:1024px)').matches ? 'desktop' : 'phone'),
        seenKey: keep ? el.dataset.agcSeenKey : '',
        haptics: !rm && tapped(),
        ticketLabel: esc(el.dataset.agcTicketLabel),
        ticketWhen: esc(el.dataset.agcTicketWhen)
      });
      window.AGCelebrate.countUp(card, fx.calm || rm);
      var again = card.querySelector('[data-agc-replay]');
      // A button that does nothing is pressed again; under reduced motion there is
      // nothing for it to replay, so it is taken away rather than left dead.
      if (again && (rm || fx.reduced)) again.hidden = true;
      else if (again) again.addEventListener('click', function () {
        fx.replay();
        window.AGCelebrate.countUp(card);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
