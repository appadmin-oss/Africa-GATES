/* Results — a member's reply to a result's Pulse thread (pages/results/show.twig).

   The reply posts to the same endpoint the Pulse uses (/api/v1/community/comment, target
   `thread`), so a result's replies and the thread's are one conversation and one moderation
   queue. A reply the service holds (status `quarantined`) is SAID to be held, in the form's
   own words (`data-held`), and never drawn into the list as if it were live — drawing it
   would show the member a public reply nobody else can see. Nothing here decides anything:
   a refusal is the server's sentence, put in the status line. */
(function () {
  'use strict';
  document.querySelectorAll('[data-rs-reply]').forEach(function (form) {
    var msg = form.querySelector('[data-rs-msg]');
    var list = document.querySelector('[data-rs-list]');
    var btn = form.querySelector('button[type="submit"]');
    function say(t) { if (!msg) return; msg.textContent = t || ''; msg.hidden = !t; }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ta = form.querySelector('textarea[name="body"]');
      var text = ta ? ta.value.trim() : '';
      if (!text) { if (ta) ta.focus(); return; }
      if (btn) btn.disabled = true;
      var body = new URLSearchParams({ target_type: 'thread', target_id: form.getAttribute('data-thread') || '', body: text });
      fetch('/api/v1/community/comment', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body
      })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (d) {
          if (!d || !d.success) { say((d && d.message) || form.getAttribute('data-failed') || ''); return; }
          if (ta) ta.value = '';
          if (d.status && d.status !== 'approved') { say(form.getAttribute('data-held')); return; }
          say('');
          if (!list) return;
          var none = list.querySelector('.rs-c--none');
          if (none) none.remove();
          var li = document.createElement('li'); li.className = 'rs-c';
          var av = document.createElement('span'); av.className = 'rs-c__av'; av.setAttribute('aria-hidden', 'true');
          var name = String(d.author_name || form.getAttribute('data-me') || '');
          av.textContent = name.slice(0, 1);
          var wrap = document.createElement('span');
          var b = document.createElement('b'); b.textContent = name;
          var p = document.createElement('span'); p.className = 'rs-c__b'; p.textContent = text;
          wrap.appendChild(b); wrap.appendChild(p); li.appendChild(av); li.appendChild(wrap);
          list.insertBefore(li, list.firstChild);
        })
        .catch(function () { say(form.getAttribute('data-failed') || ''); })
        .then(function () { if (btn) btn.disabled = false; });
    });
  });
})();
