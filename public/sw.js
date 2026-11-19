/* ══════════════════════════════════════════════════════════════════════════════
   THE SERVICE WORKER — offline page and fingerprinted assets (10 Nov 2026)
   ══════════════════════════════════════════════════════════════════════════════

   Most visitors here are on mobile data, and a dropped signal used to mean the browser's
   own error page. Two jobs, and deliberately nothing more:

   1. PAGES ARE ALWAYS FROM THE NETWORK. A page is never served from a cache — a ballot, a
      result, a wallet must be today's — and only when the network FAILS does the visitor get
      /offline, our own page at the address that failed, with "Try again". The offline page
      is fetched once, signed out (`credentials: 'omit'`), so it holds nobody's details.
   2. FINGERPRINTED FILES ARE KEPT. A stylesheet or script whose URL carries its content hash
      (`/assets/min/…/name.<hash>.js`, `/assets/dist/site.<hash>.css`, `?v=<hash>`) can never
      change under that URL, so it is served from this device after the first visit. Anything
      without a fingerprint goes to the network as before — a cached unversioned file is how a
      site shows yesterday's stylesheet for a month.

   Never touched: anything not a GET, other origins, the consoles (/admin, /judge, /lead,
   /volunteer, /org), /api, the webhooks and cron (/hooks, /__), and uploads (a photo a
   nominee removed must not live on in a cache). Bump VERSION to drop every cache.
   ══════════════════════════════════════════════════════════════════════════════ */
'use strict';

var VERSION = 'ag-sw-1';
var PAGES = VERSION + ':pages';
var FILES = VERSION + ':files';
var OFFLINE = '/offline';

var SKIP = /^\/(admin|judge|lead|volunteer|org|api|hooks|__|uploads|account\/(login|logout))(\/|$)/;
var FINGERPRINT = /\.[0-9a-f]{8,}\.(css|js|woff2?|svg|png|webp|jpg)$|[?&]v=[0-9a-f]{6,}/;

self.addEventListener('install', function (event) {
  event.waitUntil((async function () {
    var cache = await caches.open(PAGES);
    var res = await fetch(OFFLINE, { credentials: 'omit', cache: 'no-store' });
    if (!res.ok) return;
    var html = await res.clone().text();
    await cache.put(OFFLINE, res);
    // The offline page's own fingerprinted files, so it is drawn properly with no network.
    var files = await caches.open(FILES);
    var urls = [];
    html.replace(/(?:href|src)="(\/assets\/[^"]+)"/g, function (_, u) { if (FINGERPRINT.test(u)) urls.push(u.replace(/&amp;/g, '&')); });
    await Promise.all(urls.map(function (u) { return files.add(u).catch(function () {}); }));
  })());
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    var keys = await caches.keys();
    await Promise.all(keys.filter(function (k) { return k.indexOf(VERSION + ':') !== 0; }).map(function (k) { return caches.delete(k); }));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin || SKIP.test(url.pathname)) return;

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(function () {
      return caches.match(OFFLINE).then(function (r) { return r || Response.error(); });
    }));
    return;
  }

  // The house faces too: a face never changes under its name (a new cut is a new file, as
  // .htaccess says when it gives them a year), and without them the offline page falls back
  // to a system font.
  if (url.pathname.indexOf('/assets/') === 0 && (FINGERPRINT.test(url.pathname + url.search) || url.pathname.indexOf('/assets/fonts/') === 0)) {
    event.respondWith(caches.open(FILES).then(function (cache) {
      return cache.match(req).then(function (hit) {
        if (hit) return hit;
        return fetch(req).then(function (res) {
          if (res.ok && res.type === 'basic') {
            // Every deploy fingerprints afresh, so old copies pile up: keep the newest 200.
            cache.put(req, res.clone()).then(function () { return cache.keys(); }).then(function (keys) {
              return Promise.all(keys.slice(0, Math.max(0, keys.length - 200)).map(function (k) { return cache.delete(k); }));
            }).catch(function () {});
          }
          return res;
        });
      });
    }));
  }
});
