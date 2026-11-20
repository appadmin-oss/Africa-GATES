/* ══════════════════════════════════════════════════════════════════════════════
   RESULTS — one award's replies · templates/pages/results/show.twig
   ══════════════════════════════════════════════════════════════════════════════

   A result is a post in the Pulse, and its replies are that thread's. The form posts to
   /api/v1/community/comment by itself (method and fields are in the markup); this upgrade
   sends it in the background and answers in place.

   · An APPROVED reply is drawn at the end of the list straight away, from what the member
     typed — escaped, as text, never as HTML.
   · A QUARANTINED one is said to be held, in the page's own words, and is never drawn as
     live: a reply nobody else can see must not look published to its author.
   · A failure keeps the text in the box and says so; nothing typed is ever cleared on error.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var form = document.querySelector('[data-rs-reply]');
  if (!form || !window.fetch || !window.FormData) return;
  var box = form.querySelector('textarea');
  var msg = form.querySelector('[data-rs-msg]');
  var btn = form.querySelector('button[type="submit"]');
  var list = document.querySelector('[data-rs-list]');

  function say(text) {
    if (!msg) return;
    msg.textContent = text;
    msg.hidden = !text;
  }

  function draw(body) {
    if (!list) return;
    var none = list.querySelector('.rs-c--none');
    if (none) none.remove();
    var who = form.getAttribute('data-you') || '';
    var li = document.createElement('li'); li.className = 'rs-c';
    var av = document.createElement('span'); av.className = 'rs-c__av'; av.setAttribute('aria-hidden', 'true');
    av.textContent = who.charAt(0).toUpperCase();
    var wrap = document.createElement('span');
    var b = document.createElement('b'); b.textContent = who;
    var t = document.createElement('span'); t.className = 'rs-c__b'; t.textContent = body;
    wrap.appendChild(b); wrap.appendChild(t);
    li.appendChild(av); li.appendChild(wrap);
    list.appendChild(li);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = box ? box.value.trim() : '';
    if (!body) { if (box) box.focus(); return; }
    if (btn) btn.disabled = true;
    say('');
    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
                         headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (d) {
        if (!d || !d.success) {
          say((d && d.message) || form.getAttribute('data-failed') || '');
          if (d && d.code === 'SIGN_IN' && d.login_url) window.location.href = d.login_url + '?next=' + encodeURIComponent(location.pathname);
          return;
        }
        if (box) box.value = '';
        if (d.status === 'approved') draw(body);
        else say(form.getAttribute('data-held') || '');
      })
      .catch(function () { say(form.getAttribute('data-failed') || ''); })
      .then(function () { if (btn) btn.disabled = false; });
  });
})();
