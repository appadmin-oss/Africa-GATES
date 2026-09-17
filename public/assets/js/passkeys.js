/* ════════════════════════════════════════════════════════════════════════════
   Africa GATES — passkeys (browser half)

   Two ceremonies, one file: enrolling a device from the account page, and signing
   in with one from /account/login. The server half is AfricaGates\Services\Passkeys.

   ── WHY THE BASE64URL CONVERSION IS WRITTEN OUT ─────────────────────────────
   WebAuthn takes and returns ArrayBuffers; JSON carries base64url strings. Newer
   browsers have PublicKeyCredential.parseCreationOptionsFromJSON() and
   credential.toJSON() which do exactly this, and they are used where present —
   but they landed in Chrome 119 and Safari 18, so on anything older the calls are
   simply undefined and the ceremony would fail with a TypeError that reads like a
   broken page. The hand conversion below is the floor, not the fallback.

   ── EVERY FAILURE IS SHOWN, NONE IS SWALLOWED ───────────────────────────────
   A refused or cancelled ceremony rejects a promise, and a page that catches and
   drops it is a button that does nothing — the exact shape of the camera and
   autoplay faults this codebase has already paid for. `onError` always fires.
   ════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function b64urlToBuf(s) {
    var pad = s.replace(/-/g, '+').replace(/_/g, '/');
    while (pad.length % 4) pad += '=';
    var bin = atob(pad), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out.buffer;
  }

  function bufToB64url(buf) {
    var bytes = new Uint8Array(buf), s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function descriptors(list) {
    return (list || []).map(function (c) {
      return { type: c.type || 'public-key', id: b64urlToBuf(c.id), transports: c.transports || undefined };
    });
  }

  /** The site's CSRF token, as CsrfMiddleware expects it in X-CSRF-Token. */
  function token() {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m && m.content) return m.content;
    var i = document.querySelector('input[name="_token"]');
    return i ? i.value : '';
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token(), 'Accept': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.error || 'Something went wrong. Please try again.');
        return j;
      });
    });
  }

  /** The browser's own answer, serialised the way the server's denormalizer reads it. */
  function credentialToJson(cred) {
    if (typeof cred.toJSON === 'function') return JSON.stringify(cred.toJSON());

    var r = cred.response, out = {
      id: cred.id, rawId: bufToB64url(cred.rawId), type: cred.type, response: {}
    };
    out.response.clientDataJSON = bufToB64url(r.clientDataJSON);
    if (r.attestationObject) {
      out.response.attestationObject = bufToB64url(r.attestationObject);
      if (typeof r.getTransports === 'function') {
        try { out.response.transports = r.getTransports(); } catch (e) { /* optional */ }
      }
    } else {
      out.response.authenticatorData = bufToB64url(r.authenticatorData);
      out.response.signature = bufToB64url(r.signature);
      // NOT omitted when absent: a discoverable credential must return the handle, and
      // the server refuses an assertion without one. Sending null says so honestly
      // instead of producing a body the server reads as malformed.
      out.response.userHandle = r.userHandle ? bufToB64url(r.userHandle) : null;
    }
    return JSON.stringify(out);
  }

  var AG = window.agPasskeys = {
    /** Is this browser capable at all? Nothing may be offered when it is not. */
    supported: function () {
      return typeof window.PublicKeyCredential === 'function' &&
             !!(navigator.credentials && navigator.credentials.create);
    },

    /** Enrol the device in front of the member. Signed in already. */
    enrol: function (label) {
      return post('/account/passkeys/options', {}).then(function (j) {
        var o = j.options;
        var pub;
        if (typeof window.PublicKeyCredential.parseCreationOptionsFromJSON === 'function') {
          pub = window.PublicKeyCredential.parseCreationOptionsFromJSON(o);
        } else {
          pub = Object.assign({}, o, {
            challenge: b64urlToBuf(o.challenge),
            user: Object.assign({}, o.user, { id: b64urlToBuf(o.user.id) }),
            excludeCredentials: descriptors(o.excludeCredentials)
          });
        }
        return navigator.credentials.create({ publicKey: pub });
      }).then(function (cred) {
        if (!cred) throw new Error('No passkey was created.');
        return post('/account/passkeys', { credential: credentialToJson(cred), label: label || '' });
      });
    },

    /** Sign in with nothing typed. The browser picks a credential for this domain. */
    signIn: function () {
      return post('/account/login/passkey/options', {}).then(function (j) {
        var o = j.options;
        var pub;
        if (typeof window.PublicKeyCredential.parseRequestOptionsFromJSON === 'function') {
          pub = window.PublicKeyCredential.parseRequestOptionsFromJSON(o);
        } else {
          pub = Object.assign({}, o, {
            challenge: b64urlToBuf(o.challenge),
            allowCredentials: descriptors(o.allowCredentials)
          });
        }
        return navigator.credentials.get({ publicKey: pub });
      }).then(function (cred) {
        if (!cred) throw new Error('No passkey was offered.');
        return post('/account/login/passkey', { credential: credentialToJson(cred) });
      });
    },

    /**
     * A refusal in words somebody can act on.
     *
     * `NotAllowedError` is what the browser throws for BOTH "the person pressed cancel"
     * and "the ceremony timed out", and it is by far the most common outcome — so it
     * must not read like a fault. `InvalidStateError` on enrolment means this device is
     * already enrolled, which is a success the person should be told about rather than
     * an error.
     */
    say: function (err) {
      var name = (err && err.name) || '';
      if (name === 'NotAllowedError') return 'That was cancelled, or it timed out. Try again when you are ready.';
      if (name === 'InvalidStateError') return 'This device already has a passkey for Africa GATES.';
      if (name === 'SecurityError') return 'Passkeys need a secure connection to this exact site address.';
      if (name === 'AbortError') return 'That was interrupted. Try again.';
      return (err && err.message) || 'Something went wrong. Please try again.';
    }
  };

  // ── Sign-in button, wherever a page offers one ──────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-passkey-signin]') : null;
    if (!btn) return;
    e.preventDefault();

    var note = document.getElementById(btn.getAttribute('data-passkey-note') || '');
    function show(msg, bad) {
      if (!note) return;
      note.textContent = msg;
      note.hidden = false;
      note.setAttribute('role', bad ? 'alert' : 'status');
    }

    btn.disabled = true;
    show('Waiting for your device…', false);
    AG.signIn().then(function (j) {
      show('Signed in. Taking you through…', false);
      window.location.href = j.next || '/account';
    }).catch(function (err) {
      btn.disabled = false;
      show(AG.say(err), true);
    });
  });
})();
