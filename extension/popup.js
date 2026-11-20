/**
 * The settings popup: the live key, and where to send it.
 *
 * A separate file rather than an inline <script> because Chrome's extension CSP forbids
 * inline script in an extension page — the same rule the platform applies to itself with a
 * per-request nonce, arriving from the other direction.
 */

const $ = (id) => document.getElementById(id);
const DEFAULT_BASE = 'https://afg.afrovanguard.org.ng';

chrome.storage.local.get(['token', 'base'], (s) => {
  $('token').value = s.token || '';
  $('base').value = s.base || DEFAULT_BASE;
});

$('save').addEventListener('click', () => {
  const token = $('token').value.trim().toLowerCase();
  const base = ($('base').value.trim() || DEFAULT_BASE).replace(/\/+$/, '');

  // Checked here so a mistyped key is caught before somebody is mid-interview wondering
  // why nothing is being captured.
  if (!/^[a-f0-9]{32}$/.test(token)) {
    alert('That does not look like a live key. It is 32 characters of letters a–f and digits, '
        + 'copied from the interview screen in the admin console.');
    return;
  }
  if (!/^https?:\/\/.+/.test(base)) {
    alert('The site address needs to start with https://');
    return;
  }

  chrome.storage.local.set({ token, base }, () => {
    $('ok').hidden = false;
    setTimeout(() => window.close(), 1200);
  });
});
