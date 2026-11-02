/* ══════════════════════════════════════════════════════════════════════════════
   site-nav.js — the public site moves between pages without reloading
   (owner, 2 Nov 2026: "prevent full reload, add SPA where necessary")
   ══════════════════════════════════════════════════════════════════════════════

   Every page is still a complete server-rendered page, and every link and form still works
   with this file absent. What it adds is console-nav.js's idea, on the public shell:

     · a link (or a GET form — a search, a filter, a tab) fetches the next page and swaps in
       only what changes: the inside of <main>, the header, the app bar, the bottom bars, the
       page's own sheets, the title and the description. The shell, the fonts, the open
       menu, Gee, the consent layer and every script already loaded stay where they are —
       no white flash, no re-parse of 60K of shared script on every tap. It works the same
       in every browser, which prefetch-and-cross-fade (Chrome only) did not;
     · a link is fetched AHEAD when a pointer rests on it or a finger lands on it, kept a few
       seconds, marked `X-Site-Prefetch` so it writes nothing (Support\Speculative) and is
       not counted as a visit until it is shown (`X-Site-Nav`, VisitTracker);
     · Back and Forward return to the same scroll position; focus moves to the new page and
       its title is announced, as a full load would; the change cross-fades where the
       browser can (`startViewTransition`), and never under reduced motion.

   WHAT IT WILL NOT SWAP, and loads the ordinary way instead:

     · another origin, a file, the consoles and sign-in, anything instant-nav.twig already
       refuses to prefetch (a GET there DOES something), and every POST — a vote, a payment,
       a form that sends somebody to a gateway is a full navigation, always;
     · a page in another LAYOUT, another language, or whose shared overlays differ (Pulse
       has no Gee): the shells would not match;
     · a page with a script of its own that has not been checked to be safe to run again.
       A page script that binds to the window or the document would keep running after its
       page had gone. So a page script takes part only when it says `data-nav="rerun"`,
       which SiteNavTest only lets it say while it binds nothing outside its own elements
       except through `agPage` (layout/shell.twig), which undoes it all when the page
       leaves. Shell scripts say `data-nav="shell"`. Anything unmarked: a full load, into
       that page and out of it. Slower, never wrong.

   Scripts hear `ag:unload` before a swap and `ag:load` after it, on `document`.
   `data-nav="off"` on a link, a form or an ancestor opts it out.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var shell = document.querySelector('.ag-shell');
  var main = document.getElementById('main');
  var body = document.body;
  if (!shell || !main || body.getAttribute('data-nav') === 'off') return;
  if (!window.fetch || !window.DOMParser || !window.URL || !history.pushState || !main.replaceChildren) return;

  var NONCE = (function () {
    var s = document.querySelector('script[nonce]');
    return s ? (s.nonce || s.getAttribute('nonce') || '') : '';
  })();

  // This file prefetches for itself; the browser's own prefetch would fetch every hovered
  // link a second time into a cache a fetch() cannot read.
  [].forEach.call(document.querySelectorAll('script[type="speculationrules"]'), function (s) { s.remove(); });

  var TTL = 15000, CACHE_MAX = 10;
  // The same refusals as instant-nav.twig's prefetch list: a GET here does something.
  var SKIP = /^\/(admin|judge|org|volunteer|lead|__|hooks|api|ads\/go|email|claim|form|account\/(login|logout|verify|reset|confirm|switch|register|forgot)|n\/confirm|nominee\/start|vote\/verify|shop\/back-in-stock\/stop|events\/[^/]+\/door)(\/|$)/;
  var FILE = /(\/export|\/download|\/redirect|\.(csv|zip|json|png|jpe?g|webp|gif|pdf|svg|txt|xml|ics|xlsx?))(\/|$|\?)/i;
  var refused = {};        // paths that turned out not to be swappable: straight to a full load

  function eligible(u) {
    if (u.origin !== location.origin) return false;
    if (SKIP.test(u.pathname) || FILE.test(u.pathname) || /(^|&)lang=/.test(u.search.slice(1))) return false;
    if (/logout/.test(u.pathname)) return false;
    return !refused[u.pathname];
  }
  function here() { return location.pathname + location.search; }
  function key(u) { return u.pathname + u.search; }

  // ── which scripts belong to the page ─────────────────────────────────────────
  function runnable(s) {
    var t = (s.getAttribute('type') || '').trim().toLowerCase();
    return t === '' || t === 'module' || /^(text|application)\/(x-)?(java|ecma)script$/.test(t);
  }
  function pageScripts(doc) {
    return [].filter.call(doc.querySelectorAll('body script'), function (s) {
      return runnable(s) && s.getAttribute('data-nav') !== 'shell';
    });
  }
  // `rerun`: checked safe to run on every visit. `once`: a third party's loader (Turnstile)
  // that must run at most once in a document — inserted if absent, never run again.
  function unsafe(doc) {
    return pageScripts(doc).some(function (s) { var n = s.getAttribute('data-nav'); return n !== 'rerun' && n !== 'once'; });
  }
  /** Two pages can share a shell only if the shell is the same: layout, language, overlays. */
  function signature(doc) {
    var h = doc.documentElement;
    return [h.getAttribute('lang'), h.getAttribute('dir'), doc.body.getAttribute('data-ag-layout') || '',
            [].map.call(doc.querySelectorAll('[data-nav-keep]'), function (e) { return e.getAttribute('data-nav-keep'); }).join(',')].join('|');
  }
  var stuck = unsafe(document);
  var sig = signature(document);

  // ── the bar, and the announcement ────────────────────────────────────────────
  var bar = document.createElement('div');
  bar.className = 'ag-navbar'; bar.setAttribute('aria-hidden', 'true');
  body.appendChild(bar);
  var live = document.createElement('p');
  live.className = 'ag-sr'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
  body.appendChild(live);
  var barTimer = 0, navigating = false;
  function busy(on) {
    clearTimeout(barTimer);
    if (!on) navigating = false;
    if (on) {
      main.setAttribute('aria-busy', 'true');
      barTimer = setTimeout(function () { bar.setAttribute('data-on', ''); }, 120);
    } else {
      main.removeAttribute('aria-busy');
      if (bar.hasAttribute('data-on')) {
        bar.setAttribute('data-done', '');
        barTimer = setTimeout(function () { bar.removeAttribute('data-on'); bar.removeAttribute('data-done'); }, 240);
      }
    }
  }

  // ── fetching ─────────────────────────────────────────────────────────────────
  var cache = new Map();
  function request(url, o) {
    o = o || {};
    var headers = { 'Accept': 'text/html,application/xhtml+xml', 'X-Site-Nav': '1' };
    if (o.prefetch) headers['X-Site-Prefetch'] = 'prefetch';
    return fetch(url, { headers: headers, credentials: 'same-origin', redirect: 'follow', signal: o.signal })
      .then(function (res) {
        var type = (res.headers.get('Content-Type') || '').toLowerCase();
        var page = { url: res.url || url, status: res.status, redirected: res.redirected };
        if (type.indexOf('text/html') === -1) { if (res.body && res.body.cancel) res.body.cancel(); return page; }
        return res.text().then(function (html) { page.html = html; return page; });
      });
  }
  function cached(k) {
    var c = cache.get(k);
    if (!c) return null;
    cache.delete(k);
    return Date.now() - c.at > TTL ? null : c;
  }
  var inflight = 0;
  function prefetch(u) {
    var k = key(u);
    if (stuck || navigating || k === here() || cache.has(k) || inflight >= 2) return;
    var conn = navigator.connection;
    if (conn && (conn.saveData || /(^|-)2g$/.test(conn.effectiveType || ''))) return;
    inflight++;
    var p = request(u.href, { prefetch: true });
    var entry = { at: Date.now(), p: p, prefetched: true };
    cache.set(k, entry);
    p.catch(function () { if (cache.get(k) === entry) cache.delete(k); }).then(function () { inflight--; });
    while (cache.size > CACHE_MAX) cache.delete(cache.keys().next().value);
  }

  // ── going somewhere ──────────────────────────────────────────────────────────
  var seq = 0, ctrl = null, shown = here();
  function hard(url, o) { if (o && o.pop) location.reload(); else location.assign(url); }
  function saveScroll() {
    try { history.replaceState(Object.assign({}, history.state || {}, { ag: 1, y: main.scrollTop }), ''); } catch (_) {}
  }

  function go(href, o) {
    o = o || {};
    var u = new URL(href, location.href);
    if (!eligible(u) || stuck) { hard(u.href, o); return; }
    var mine = ++seq;
    navigating = true;
    if (ctrl) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    busy(true);
    var used = cached(key(u));
    var p = used ? used.p : request(u.href, { signal: ctrl ? ctrl.signal : undefined });
    p.then(function (page) {
      if (mine !== seq) return;
      return apply(page, u, o).then(function (swapped) {
        // A page answered from a prefetch was never counted as a visit. Now it is one.
        if (swapped && used && used.prefetched) fetch(page.url, { method: 'HEAD', credentials: 'same-origin', headers: { 'X-Site-Nav': '1' } }).catch(function () {});
      });
    }).catch(function (err) {
      if (mine !== seq || (err && err.name === 'AbortError')) return;
      busy(false);
      hard(u.href, o);
    });
  }

  function apply(page, u, o) {
    if (page.html == null) { busy(false); hard(page.url, o); return Promise.resolve(false); }
    // Re-signed BEFORE it is parsed (console-nav.js says why): a nonce this document has
    // never seen is refused, and reported, even inside an inert parsed document.
    var doc = new DOMParser().parseFromString(page.html.replace(/\snonce="[^"]*"/g, ' nonce="' + NONCE + '"'), 'text/html');
    var final = new URL(page.url, location.href);
    if (!doc.querySelector('.ag-shell') || !doc.getElementById('main') || !eligible(final) || signature(doc) !== sig || unsafe(doc)) {
      refused[final.pathname] = true;
      hard(final.href, o);
      return Promise.resolve(false);
    }
    var samePath = final.pathname === location.pathname;
    return styles(doc).then(function (drop) {
      if (!o.pop) saveScroll();
      var run = function () { return swap(doc).then(drop); };
      var done = function () {
        var hash = final.hash || u.hash;
        if (!o.pop) history.pushState({ ag: 1, y: 0 }, '', final.pathname + final.search + hash);
        shown = here();
        var target = hash && document.getElementById(decodeURIComponent(hash.slice(1)));
        if (target) target.scrollIntoView();
        // A tab or a filter on the same page keeps the reader where they were reading.
        else main.scrollTop = o.pop ? (o.y || 0) : (samePath && o.keep ? main.scrollTop : 0);
        if (!o.pop && !main.contains(document.activeElement)) main.focus({ preventScroll: true });
        live.textContent = document.title;
        busy(false);
        return true;
      };
      var still = document.documentElement.classList.contains('ag-rm')
        || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
      if (document.startViewTransition && !still) {
        var vt = document.startViewTransition(function () { return run(); });
        return vt.updateCallbackDone.then(done, function () { return run().then(done); });
      }
      return run().then(done);
    });
  }

  // ── the swap ─────────────────────────────────────────────────────────────────
  function styles(doc) {
    var want = new Set(), loads = [];
    [].forEach.call(doc.querySelectorAll('head link[rel="stylesheet"]'), function (l) {
      var href = l.getAttribute('href');
      want.add(new URL(href, location.href).href);
      if (document.querySelector('head link[rel="stylesheet"][href="' + href.replace(/"/g, '\\"') + '"]')) return;
      loads.push(new Promise(function (done) {
        var n = document.createElement('link');
        n.rel = 'stylesheet'; n.href = href;
        if (l.media && l.media !== 'all') n.media = l.media;
        n.onload = n.onerror = done; setTimeout(done, 3000);
        document.head.appendChild(n);
      }));
    });
    return Promise.all(loads).then(function () {
      return function drop() {
        [].forEach.call(document.querySelectorAll('head link[rel="stylesheet"]'), function (l) {
          if (!want.has(l.href) && !l.hasAttribute('data-ag-fonts')) l.remove();
        });
      };
    });
  }

  function adopt(n) { return document.importNode(n, true); }

  /** A fresh <script> for an inert one, so it runs, under this document's nonce. */
  function revive(old) {
    var s = document.createElement('script');
    [].forEach.call(old.attributes, function (a) { if (a.name !== 'nonce') s.setAttribute(a.name, a.value); });
    s.nonce = NONCE;
    var wait = null;
    if (old.src) { s.async = false; wait = new Promise(function (done) { s.onload = s.onerror = done; setTimeout(done, 5000); }); }
    else s.textContent = old.textContent;
    old.replaceWith(s);
    return wait;
  }

  function meta(doc, sel, attr) {
    var n = doc.querySelector(sel), c = document.querySelector(sel);
    if (n && c) c.setAttribute(attr, n.getAttribute(attr) || '');
    else if (n && !c) document.head.appendChild(adopt(n));
    else if (!n && c) c.remove();
  }

  /**
   * Everything in `cur` that is not kept, not <main>, not the shell and not a script is
   * replaced by the new page's, in the new page's order. `same(el)` maps an element of the
   * new page onto the live one it stands for (or null when it is the page's own, to adopt).
   */
  function replaceAround(cur, next, same) {
    [].slice.call(cur.children).forEach(function (el) {
      if (el === main || el === shell || el.hasAttribute('data-nav-keep') || el.tagName === 'SCRIPT' || el === bar || el === live) return;
      el.remove();
    });
    var anchor = null;
    [].slice.call(next.children).forEach(function (el) {
      if (el.tagName === 'SCRIPT') return;
      var live = same(el);
      if (live) { anchor = live; return; }
      var n = adopt(el);
      if (anchor && anchor.parentNode === cur) anchor.after(n); else cur.prepend(n);
      anchor = n;
    });
  }
  function kept(el) {
    var k = el.getAttribute('data-nav-keep');
    return k ? document.querySelector('[data-nav-keep="' + k.replace(/"/g, '\\"') + '"]') : null;
  }

  /** A kept overlay stays, but its links that point at "this page" (a language switch) follow. */
  function syncKept(doc) {
    [].forEach.call(document.querySelectorAll('[data-nav-keep]'), function (el) {
      var n = doc.querySelector('[data-nav-keep="' + el.getAttribute('data-nav-keep') + '"]');
      if (!n) return;
      // And the attributes it names as the PAGE's rather than its own (Gee's default mode:
      // the help desk on help pages, the guide everywhere else).
      (el.getAttribute('data-nav-sync') || '').split(/\s+/).filter(Boolean).forEach(function (name) {
        if (n.hasAttribute(name)) el.setAttribute(name, n.getAttribute(name)); else el.removeAttribute(name);
      });
      var a = el.querySelectorAll('a[hreflang]'), b = n.querySelectorAll('a[hreflang]');
      if (a.length === b.length) for (var i = 0; i < a.length; i++) a[i].setAttribute('href', b[i].getAttribute('href'));
    });
  }

  function swap(doc) {
    document.dispatchEvent(new CustomEvent('ag:unload'));
    if (window.agPage && window.agPage._reset) window.agPage._reset();

    document.title = doc.title;
    meta(doc, 'meta[name="description"]', 'content');
    meta(doc, 'meta[name="robots"]', 'content');
    meta(doc, 'link[rel="canonical"]', 'href');
    meta(doc, 'meta[name="ag-csrf"]', 'content');
    body.setAttribute('data-page', doc.body.getAttribute('data-page') || '');

    var nextShell = doc.querySelector('.ag-shell');
    [].forEach.call(nextShell.attributes, function (a) { if (a.name !== 'data-scrolled') shell.setAttribute(a.name, a.value); });
    replaceAround(shell, nextShell, function (el) { return el.id === 'main' ? main : kept(el); });
    replaceAround(body, doc.body, function (el) { return el.classList.contains('ag-shell') ? shell : kept(el); });
    syncKept(doc);

    var next = doc.getElementById('main');
    [].forEach.call(next.attributes, function (a) { if (a.name !== 'aria-busy') main.setAttribute(a.name, a.value); });
    main.replaceChildren.apply(main, [].map.call(next.childNodes, adopt));

    var waits = [];
    [].forEach.call(main.querySelectorAll('script'), function (s) { if (runnable(s)) waits.push(revive(s)); });
    [].forEach.call(document.querySelectorAll('body script[data-nav="rerun"]'), function (s) { if (!main.contains(s)) s.remove(); });
    [].forEach.call(doc.querySelectorAll('body script'), function (s) {
      if (!runnable(s) || s.getAttribute('data-nav') === 'shell' || next.contains(s)) return;
      if (s.getAttribute('data-nav') === 'once' && s.src
          && [].some.call(document.querySelectorAll('script[src]'), function (x) { return x.src === s.src; })) return;
      var n = document.importNode(s, true);
      body.appendChild(n);
      waits.push(revive(n));
    });
    // A shell script stays loaded, but what the server wrote onto it for THIS page (ga.js's
    // redacted path) is the new page's: copied across before anybody hears ag:load.
    [].forEach.call(document.querySelectorAll('body script[data-nav="shell"][src]'), function (s) {
      var base = s.getAttribute('src').split('?')[0].replace(/\.[0-9a-f]{10}\.js$/, '.js');
      var n = [].filter.call(doc.querySelectorAll('body script[data-nav="shell"][src]'), function (x) {
        return x.getAttribute('src').split('?')[0].replace(/\.[0-9a-f]{10}\.js$/, '.js') === base;
      })[0];
      if (n) [].forEach.call(n.attributes, function (a) { if (/^data-(?!nav$)/.test(a.name)) s.setAttribute(a.name, a.value); });
    });
    // The page is SHOWN now; its scripts finish behind it. Waiting for them first held every
    // navigation for as long as the slowest script took to arrive (measured: a page with two
    // scripts of its own appeared ~400ms later than one with none, on a 400ms link).
    Promise.all(waits.filter(Boolean)).then(function () {
      stuck = unsafe(document);
      document.dispatchEvent(new CustomEvent('ag:load', { detail: { root: main } }));
    });
    return Promise.resolve();
  }

  // ── listening ────────────────────────────────────────────────────────────────
  function optedOut(el) { return !!(el && el.closest && el.closest('[data-nav="off"]')); }
  function linkOf(e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || optedOut(a) || a.hasAttribute('download')) return null;
    var t = a.getAttribute('target');
    if (t && t !== '_self') return null;
    var raw = a.getAttribute('href');
    if (!raw || raw.charAt(0) === '#' || /^(mailto|tel|sms|javascript|whatsapp):/i.test(raw)) return null;
    if (/(^|\s)(nofollow|external)(\s|$)/.test(a.getAttribute('rel') || '')) return null;
    var u = new URL(a.href, location.href);
    if (!eligible(u)) return null;
    if (key(u) === here() && u.hash) return null;
    return u;
  }

  // On WINDOW, not the document: every page script's own click handler on the document has
  // run first, whenever it was added, and may have prevented the default (console-nav.js).
  window.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var u = linkOf(e);
    if (!u) return;
    e.preventDefault();
    go(u.href, { keep: u.pathname === location.pathname });
  });

  // GET forms only: a search, a filter. Every POST is a full navigation, deliberately.
  window.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target, b = e.submitter || null;
    if (optedOut(f) || optedOut(b)) return;
    var method = ((b && b.getAttribute('formmethod')) || f.getAttribute('method') || 'get').toLowerCase();
    if (method !== 'get') return;
    var target = (b && b.getAttribute('formtarget')) || f.getAttribute('target');
    if (target && target !== '_self') return;
    var action = new URL((b && b.getAttribute('formaction')) || f.getAttribute('action') || location.href, location.href);
    if (!eligible(action) || stuck) return;
    var fd;
    try { fd = new FormData(f, b); } catch (_) { fd = new FormData(f); if (b && b.name) fd.append(b.name, b.value); }
    var q = new URLSearchParams();
    fd.forEach(function (v, k) { if (typeof v === 'string') q.append(k, v); });
    action.search = q.toString();
    e.preventDefault();
    go(action.href, { keep: action.pathname === location.pathname });
  });

  var hoverTimer = 0;
  document.addEventListener('pointerover', function (e) {
    if (e.pointerType === 'touch') return;
    var u = linkOf(e);
    clearTimeout(hoverTimer);
    if (u) hoverTimer = setTimeout(function () { prefetch(u); }, 70);
  });
  document.addEventListener('pointerout', function () { clearTimeout(hoverTimer); });
  document.addEventListener('touchstart', function (e) { var u = linkOf(e); if (u) prefetch(u); }, { passive: true });

  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  try { history.replaceState(Object.assign({}, history.state || {}, { ag: 1, y: main.scrollTop }), ''); } catch (_) {}
  window.addEventListener('popstate', function (e) {
    if (here() === shown) return;
    // An entry of ours, or ANY entry for another path: a page script's own history (Discover's
    // tabs) is that page's to handle only while that page is on screen. Back from somewhere
    // else into one of its entries is a navigation, or the address and the page would differ.
    var shownPath = shown.split('?')[0];
    if (!(e.state && e.state.ag) && location.pathname === shownPath) return;
    go(location.href, { pop: true, y: (e.state && e.state.y) || 0 });
  });

  window.agNav = { go: function (href) { go(href); }, prefetch: function (href) { prefetch(new URL(href, location.href)); } };
})();
