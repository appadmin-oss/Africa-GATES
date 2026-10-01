/* ══════════════════════════════════════════════════════════════════════════════
   AFRICA GATES — THE SHARE LINK ON A LANDED NOMINATION
   ══════════════════════════════════════════════════════════════════════════════

   Mints a link that prefills what this nominator already knew, so the next person
   adds their own voice rather than retyping a stranger's details. The same mechanism
   `/nominate/{slug}?share=` reads.

   A FILE RATHER THAN AN INLINE ALPINE COMPONENT, which is what this was. The page
   moved to the redesign shell, where Alpine is not loaded — an `x-data` block there
   is markup that never binds, so the button would have rendered, looked enabled, and
   done nothing at all on press. That failure has no console line and no visual tell,
   which is the shape this codebase keeps paying for.

   Classic script, `defer`, no module — the reason shell.js gives.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var root = document.querySelector('[data-ag-share]');
  if (!root) return;

  /* THE PAYLOAD COMES FROM A `<template>`, not a `data-` attribute and not a script.

     An attribute is out because a nominee's name can hold an apostrophe and the HTML
     parser closes an attribute at the first one — that is how the flier's styles were
     silently shredded here once. A `<script type="application/json">` block is out
     because the template sweep looks for un-nonced inline scripts and should not have
     to know which `type` values execute.

     A template's content is inert and parsed, so `.content` is a real fragment. */
  var payload = {};
  try {
    var blob = document.querySelector('[data-ag-share-payload]');
    var raw  = blob ? (blob.content ? blob.content.textContent : blob.textContent) : '';
    payload = JSON.parse(raw || '{}') || {};
  } catch (e) { payload = {}; }

  var make = root.querySelector('[data-ag-share-make]');
  var out  = root.querySelector('[data-ag-share-out]');
  var urlI = root.querySelector('[data-ag-share-url]');
  var copy = root.querySelector('[data-ag-share-copy]');
  var errP = root.querySelector('[data-ag-share-err]');
  var life = root.querySelector('[data-ag-share-life]');

  function fail(msg) {
    if (!errP) return;
    errP.textContent = msg;
    errP.hidden = false;
  }

  if (make) {
    make.addEventListener('click', function () {
      if (make.disabled) return;
      make.disabled = true;
      var was = make.textContent;
      make.textContent = 'Creating link…';
      if (errP) errP.hidden = true;

      fetch('/api/v1/nominations/share-link', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !(j.ok || j.success) || !j.url) {
          fail((j && (j.error || j.message)) || 'Could not create the link — please try again.');
          return;
        }
        /* A relative URL is made absolute HERE, because the whole point is that it
           gets pasted somewhere else — a path alone is a link that works only for
           somebody already on this site. */
        urlI.value = j.url.indexOf('http') === 0 ? j.url : (location.origin + j.url);
        if (life) {
          life.textContent = 'Link expires in ' + (j.expires_days || 30) + ' days. '
            + 'Anyone who opens it can review and change every detail before submitting.';
        }
        out.hidden = false;
        make.hidden = true;
        urlI.focus();
        urlI.select();
      })
      .catch(function () { fail('Could not create the link — please try again.'); })
      .finally(function () { make.disabled = false; make.textContent = was; });
    });
  }

  if (copy && urlI) {
    copy.addEventListener('click', function () {
      /* The label says it worked whether or not the clipboard API was willing — the
         address is selected and on screen either way, so "Copied" after a refusal is
         still true of what the person can now do. A silent no-op is not. */
      var done = function () {
        var was = copy.textContent;
        copy.textContent = 'Copied ✓';
        setTimeout(function () { copy.textContent = was; }, 1600);
      };
      urlI.select();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(urlI.value).then(done, done);
      } else {
        done();
      }
    });
  }

  if (urlI) {
    urlI.addEventListener('focus', function () { urlI.select(); });
  }

  /* CARRIED ACROSS FROM THE PAGE THIS REPLACED. The old success screen invited the
     nominator into the WhatsApp community the moment their nomination landed, and a
     rebuild that quietly drops it loses a channel nobody would notice going — the
     invite simply stops appearing, with no error and no empty space where it was. */
  if (window.AGCommunity && typeof window.AGCommunity.open === 'function') {
    window.AGCommunity.open('nomination');
  }
})();
