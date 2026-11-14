/**
 * THE SERVICE WORKER — every network call the extension makes happens here.
 *
 * ── WHY NOT FETCH FROM THE CONTENT SCRIPT ────────────────────────────────────
 *
 * A content script runs in the Meet PAGE's origin, so a fetch from it to
 * afg.afrovanguard.org.ng is a cross-origin request: it needs CORS headers on our side, it
 * fires a preflight, and Meet's own Content-Security-Policy can block it outright.
 *
 * A fetch from the service worker, with the host listed in `host_permissions`, is a
 * privileged extension request. No CORS, no preflight, no dependence on Meet's CSP, and the
 * platform needs no cross-origin headers at all — which keeps that surface closed for
 * everybody else.
 *
 * So the content script reads the page and messages what it found; this file talks to the
 * server. That split is the whole architecture.
 *
 * ── AND WHY THE TOKEN LIVES HERE ─────────────────────────────────────────────
 *
 * In chrome.storage, not in the page. A live key in a content script is a live key readable
 * by any script Meet loads.
 */

const DEFAULT_BASE = 'https://afg.afrovanguard.org.ng';

async function settings() {
  const s = await chrome.storage.local.get(['base', 'token']);
  return {
    base: (s.base || DEFAULT_BASE).replace(/\/+$/, ''),
    token: (s.token || '').trim(),
  };
}

/**
 * One POST to the platform.
 *
 * Never throws: the caller is a live interview and a dropped request must show a line on
 * the panel, not break the loop that captures the rest of the conversation.
 */
async function post(path, payload) {
  const { base, token } = await settings();
  if (!token) {
    return { ok: false, message: 'No live key set. Click the extension icon and paste the key from the interview screen.' };
  }
  try {
    const res = await fetch(base + '/api/interview/live/' + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...payload, token }),
    });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      // An HTML body here is almost always the login page or a 404 — i.e. the wrong base
      // URL. Saying so beats "unexpected token < in JSON".
      return {
        ok: false,
        message: 'The platform did not answer with JSON (HTTP ' + res.status + '). Check the site address in the extension settings.',
      };
    }
  } catch (e) {
    return { ok: false, message: 'Could not reach the platform: ' + e.message };
  }
}

chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
  if (msg && msg.type === 'hello') {
    post('hello', { meet_code: msg.meetCode || '' }).then(reply);
    return true;
  }
  if (msg && msg.type === 'say') {
    post('say', { lines: msg.lines || [], question_key: msg.questionKey || '' }).then(reply);
    return true;
  }
  if (msg && msg.type === 'finish') {
    post('finish', {}).then(reply);
    return true;
  }
  if (msg && msg.type === 'settings') {
    settings().then(reply);
    return true;
  }
  return false;
});
