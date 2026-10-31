/* A poll (partials/poll.twig): a member's answer posts to /api/community/poll and the
   payload it returns is drawn back — percentages, the count, which option is theirs.
   Nothing here decides anything; a refusal is said in the status line. */
(function () {
  'use strict';
  document.querySelectorAll('[data-poll]').forEach(function (box) {
    var say = box.querySelector('[data-poll-say]');
    function draw(p) {
      var mine = p.my_votes || [];
      (p.options || []).forEach(function (o) {
        var li = box.querySelector('[data-poll-opt="' + o.index + '"]');
        if (!li) return;
        li.style.setProperty('--pct', o.pct + '%');
        li.classList.toggle('is-mine', mine.indexOf(o.index) > -1);
        var pct = li.querySelector('[data-poll-pct]'); if (pct) pct.textContent = o.pct + '%';
        var b = li.querySelector('[data-poll-vote]'); if (b) b.setAttribute('aria-pressed', mine.indexOf(o.index) > -1 ? 'true' : 'false');
      });
      var t = box.querySelector('[data-poll-total]');
      if (t) t.textContent = p.total === 1 ? box.getAttribute('data-voters-one') : box.getAttribute('data-voters-many').replace('%n%', String(p.total));
    }
    box.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-poll-vote]') : null;
      if (!b) return;
      var body = new URLSearchParams({ poll_id: box.getAttribute('data-poll'), option_index: b.getAttribute('data-poll-vote') });
      fetch('/api/community/poll', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.success) { draw(d); if (say) say.textContent = ''; }
          else if (say) say.textContent = (d && d.message) || box.getAttribute('data-failed');
        })
        .catch(function () { if (say) say.textContent = box.getAttribute('data-failed'); });
    });
  });
})();
