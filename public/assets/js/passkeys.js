/* ══════════════════════════════════════════════════════════════════════════════
   PASSKEYS — the browser half of a live server mechanism (rebuilt Phase 8)
   Services\Passkeys · AccountController passkey* · five routes under /account
   ══════════════════════════════════════════════════════════════════════════════

   Destroyed with the old account pages on 3 Oct, which left the server half — enrolment,
   sign-in, removal, PasskeyTest — complete and unreachable from any page: a mechanism with
   no way in (inventory _scripts.md, MUST RESTORE). Written again for the rebuilt sign-in
   screen and the account's Security section.

   · A control is SHOWN only once this browser can run the ceremony
     (`PublicKeyCredential` and a platform authenticator) — the server already omits it
     when it cannot verify one. Both halves are asked; a button that opens nothing reads as
     the account being broken.
   · base64url ↔ ArrayBuffer by hand: `PublicKeyCredential.parseCreationOptionsFromJSON`
     and `toJSON()` exist only from Chrome 119 / Safari 18.
   · The CSRF token travels as `X-CSRF-Token` (CsrfMiddleware), read off the control's own
     `data-csrf` so a guest's sign-in screen needs no member meta tag.
   · EVERY failure is said in the note beside the control. A cancelled prompt is said as
     a cancellation, not as an error, and nothing is swallowed.

   Hooks: [data-passkey-signin] on /account/login · [data-passkey-add] on the account page ·
   [data-passkey-note] the line that answers either.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function b64urlToBuf(s) {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
  }
  function bufToB64url(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function note(text) {
    var n = document.querySelector('[data-passkey-note]');
    if (n) n.textContent = text;
  }

  function post(url, csrf, body) {
    var form = new URLSearchParams();
    Object.keys(body || {}).forEach(function (k) { form.append(k, body[k]); });
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: form.toString()
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.error || 'That did not work. Try again.');
        return j;
      });
    });
  }

  function credentialJson(c) {
    var r = c.response, out = {
      id: c.id, rawId: bufToB64url(c.rawId), type: c.type,
      clientExtensionResults: (c.getClientExtensionResults ? c.getClientExtensionResults() : {}),
      response: { clientDataJSON: bufToB64url(r.clientDataJSON) }
    };
    if (r.attestationObject) {
      out.response.attestationObject = bufToB64url(r.attestationObject);
      if (r.getTransports) out.response.transports = r.getTransports();
    }
    if (r.authenticatorData) {
      out.response.authenticatorData = bufToB64url(r.authenticatorData);
      out.response.signature = bufToB64url(r.signature);
      if (r.userHandle) out.response.userHandle = bufToB64url(r.userHandle);
    }
    return JSON.stringify(out);
  }

  function said(err) {
    /* The user closing the prompt is not a fault. */
    if (err && (err.name === 'NotAllowedError' || err.name === 'AbortError')) return 'Cancelled — nothing was changed.';
    return (err && err.message) || 'That did not work. Try again.';
  }

  function signIn(btn) {
    var csrf = btn.getAttribute('data-csrf') || '';
    btn.setAttribute('aria-busy', 'true');
    note('');
    post('/account/login/passkey/options', csrf, {}).then(function (j) {
      var o = j.options || {};
      o.challenge = b64urlToBuf(o.challenge);
      (o.allowCredentials || []).forEach(function (c) { c.id = b64urlToBuf(c.id); });
      return navigator.credentials.get({ publicKey: o });
    }).then(function (cred) {
      return post('/account/login/passkey', csrf, { credential: credentialJson(cred) });
    }).then(function (j) {
      window.location.assign(j.next || '/account');
    }).catch(function (e) {
      note(said(e));
    }).then(function () { btn.removeAttribute('aria-busy'); });
  }

  function enrol(btn) {
    var csrf = btn.getAttribute('data-csrf') || '';
    btn.setAttribute('aria-busy', 'true');
    note('');
    post('/account/passkeys/options', csrf, {}).then(function (j) {
      var o = j.options || {};
      o.challenge = b64urlToBuf(o.challenge);
      if (o.user) o.user.id = b64urlToBuf(o.user.id);
      (o.excludeCredentials || []).forEach(function (c) { c.id = b64urlToBuf(c.id); });
      return navigator.credentials.create({ publicKey: o });
    }).then(function (cred) {
      return post('/account/passkeys', csrf, { credential: credentialJson(cred), label: btn.getAttribute('data-label') || '' });
    }).then(function () {
      note('Passkey added. This device can now sign you in.');
      window.location.reload();
    }).catch(function (e) {
      note(said(e));
    }).then(function () { btn.removeAttribute('aria-busy'); });
  }

  function init() {
    var controls = document.querySelectorAll('[data-passkey-signin],[data-passkey-add]');
    if (!controls.length || !window.PublicKeyCredential || !navigator.credentials) return;
    var ready = PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable
      ? PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable() : Promise.resolve(true);
    /* A security key is still a passkey, so a browser that has the API but no built-in
       authenticator keeps the control: the prompt offers the key. */
    ready.catch(function () { return true; }).then(function () {
      Array.prototype.forEach.call(controls, function (b) {
        b.hidden = false;
        b.addEventListener('click', function () {
          if (b.getAttribute('aria-busy') === 'true') return;
          if (b.hasAttribute('data-passkey-signin')) signIn(b); else enrol(b);
        });
      });
    });
  }

  window.agPasskeys = { signIn: signIn, enrol: enrol };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
