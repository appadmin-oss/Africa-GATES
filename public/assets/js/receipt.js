/* receipt.js — the one interactive part of a receipt: the paid vote's message of support.
 *
 * The section is `hidden` in the markup and shown here, because its endpoint
 * (/api/vote-message, VoteMessageController::post) answers JSON — a plain form posting to
 * it without script would land somebody on a page of raw JSON. With no script the receipt
 * is complete; it simply does not offer the optional box.
 *
 * The payment reference authorises the message (a reference belongs to somebody who paid);
 * the buyer's name and their consent to show it are read from the ORDER by the server, never
 * sent from here. Nothing is stored in the browser. */
(function () {
  'use strict';
  var box = document.querySelector('[data-rc-msg]');
  if (!box) return;
  box.hidden = false;
  if (box.hasAttribute('data-sent')) return;

  var form = box.querySelector('[data-rc-msg-form]');
  var body = box.querySelector('[data-rc-msg-body]');
  var n    = box.querySelector('[data-rc-msg-n]');
  var err  = box.querySelector('[data-rc-msg-err]');
  var go   = box.querySelector('[data-rc-msg-go]');
  var done = box.querySelector('[data-rc-msg-done]');
  var ref  = box.getAttribute('data-ref') || '';
  var label = go.textContent;

  function say(msg) { err.textContent = msg || ''; err.hidden = !msg; }
  function count() { n.textContent = String(body.value.length); }
  body.addEventListener('input', function () { count(); say(''); });
  count();

  go.addEventListener('click', function () {
    var text = body.value.trim();
    if (text.length < 3) { say(go.getAttribute('data-short') || 'Write a few words first.'); body.focus(); return; }
    go.disabled = true; go.setAttribute('aria-busy', 'true'); go.textContent = go.getAttribute('data-busy') || label;
    fetch('/api/vote-message', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ref: ref, body: text })
    }).then(function (r) { return r.json().catch(function () { return {}; }); }).then(function (d) {
      if (!d || !d.success) {
        say((d && d.message) || 'That did not send. Your words are still here — try again.');
        return;
      }
      form.hidden = true;
      done.textContent = d.message || '';
      if (d.url) {
        var a = document.createElement('a');
        a.href = d.url; a.textContent = ' ' + (box.getAttribute('data-open') || 'Open your message');
        done.appendChild(a);
      }
      done.hidden = false;
    }, function () {
      say('That did not send — check your connection. Your words are still here.');
    }).then(function () {
      go.disabled = false; go.removeAttribute('aria-busy'); go.textContent = label;
    });
  });
})();
