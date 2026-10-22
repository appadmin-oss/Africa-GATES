/* ══════════════════════════════════════════════════════════════════════════════
   console-nav.js — the console moves between pages without reloading
   ══════════════════════════════════════════════════════════════════════════════

   Every console page is still a complete server-rendered page, and every link and form
   still works with this file absent. What this adds is the speed of a single-page app
   without becoming one:

     · a link or form inside the console fetches the next page and swaps in only what
       changes — the page itself, the rail's tree, the top bar, the phone tab bar, the
       palette's Recent — so the shell, the fonts, the open assistant and every script
       already loaded stay where they are. No white flash, no re-parse of 1,600 lines of
       console script on every click;
     · a link is fetched AHEAD of the click when the pointer rests on it (or a finger
       lands on it), and the answer is kept for a few seconds, so most clicks are
       answered from memory. The request says so (`X-Console-Prefetch: 1`), and the
       server does not count it as a visit — not in Recent, not in "who else is here";
     · Back and Forward come back to the same scroll position, and a save that lands on
       the page it was sent from keeps your place rather than jumping to the top;
     · focus moves to the new page and its title is announced, as a full load would.

   WHAT IT WILL NOT SWAP, and loads the old way instead:

     · anything outside /admin, sign-in and sign-out, files and exports;
     · a page with a script of its own that has not been checked to be safe to run again.
       A page script that binds to the window or the document (keyboard shortcuts, a
       heartbeat, an unload prompt) would keep running after its page had gone — pressing
       A on the next page could approve a nomination from the last one. So a page script
       takes part only when it says `data-nav="rerun"`, which ConsoleNavTest only lets it
       say while it binds nothing outside its own elements. Shell scripts say
       `data-nav="shell"` and never run twice. Anything unmarked: a full load, both into
       that page and out of it. Slower, never wrong.

   Other scripts hear `cn:unload` before a swap and `cn:load` after it, on `document`.
   `data-nav="off"` on a link, a form, a button or any ancestor opts it out.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var main = document.getElementById('cnMain');
  var body = document.body;
  if (!main || !body.hasAttribute('data-console') || body.getAttribute('data-nav') === 'off') return;
  if (!window.fetch || !window.DOMParser || !window.URL || !history.pushState || !main.replaceChildren) return;

  // The nonce this document's policy accepts. A fetched page carries its own, which this
  // document has never heard of, so every script and style it brings in is given this one.
  var NONCE = (function () {
    var s = document.querySelector('script[nonce]');
    return s ? (s.nonce || s.getAttribute('nonce') || '') : '';
  })();

  var TTL = 20000;          // a prefetched page is fresh enough to show for this long
  var CACHE_MAX = 12;
  var SKIP = /^\/admin\/(login|logout|magic)(\/|$)/;
  var FILE = /(\/export|\/download|\.(csv|zip|json|png|jpe?g|webp|gif|pdf|svg|txt|xml|ics|xlsx?))(\/|$)/i;

  function eligible(u) {
    if (u.origin !== location.origin) return false;
    if (u.pathname !== '/admin' && u.pathname.indexOf('/admin/') !== 0) return false;
    if (SKIP.test(u.pathname) || FILE.test(u.pathname)) return false;
    if (/(^|&)part=/.test(u.search.slice(1))) return false;     // a fragment, not a page
    return true;
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
  /** A page whose own scripts are not known to be safe to run twice. */
  function unsafe(doc) {
    return pageScripts(doc).some(function (s) { return s.getAttribute('data-nav') !== 'rerun'; });
  }
  var stuck = unsafe(document);

  // ── the bar, and the announcement ────────────────────────────────────────────

  var bar = document.createElement('div');
  bar.className = 'cn-progress';
  bar.setAttribute('aria-hidden', 'true');
  body.appendChild(bar);
  var live = document.createElement('p');
  live.className = 'sr-only';
  live.setAttribute('role', 'status');
  live.setAttribute('aria-live', 'polite');
  body.appendChild(live);
  var barTimer = 0;
  function busy(on) {
    clearTimeout(barTimer);
    if (!on) navigating = false;
    if (on) {
      main.setAttribute('aria-busy', 'true');
      // Only a wait somebody would notice gets a bar; a page from memory gets none.
      barTimer = setTimeout(function () { bar.setAttribute('data-on', ''); }, 120);
    } else {
      main.removeAttribute('aria-busy');
      if (bar.hasAttribute('data-on')) {
        bar.setAttribute('data-done', '');
        barTimer = setTimeout(function () { bar.removeAttribute('data-on'); bar.removeAttribute('data-done'); }, 220);
      }
    }
  }

  // ── fetching ─────────────────────────────────────────────────────────────────

  var cache = new Map();   // key → { at, p: Promise<page> }

  function request(url, init) {
    init = init || {};
    var headers = { 'Accept': 'text/html,application/xhtml+xml', 'X-Console-Nav': '1' };
    if (init.prefetch) headers['X-Console-Prefetch'] = '1';
    return fetch(url, {
      method: init.method || 'GET', body: init.body, headers: headers,
      credentials: 'same-origin', redirect: 'follow', signal: init.signal,
    }).then(function (res) {
      var type = (res.headers.get('Content-Type') || '').toLowerCase();
      var page = { url: res.url || url, status: res.status, type: type, redirected: res.redirected,
                   disposition: res.headers.get('Content-Disposition') || '' };
      if (type.indexOf('text/html') === -1) {
        // A file. A GET is simply opened the ordinary way; a POST cannot be sent again, so
        // its answer is kept and handed over as the download it was.
        if ((init.method || 'GET') === 'GET') { if (res.body && res.body.cancel) res.body.cancel(); return page; }
        return res.blob().then(function (b) { page.blob = b; return page; });
      }
      return res.text().then(function (html) { page.html = html; return page; });
    });
  }

  function cached(k) {
    var c = cache.get(k);
    if (!c) return null;
    cache.delete(k);
    if (Date.now() - c.at > TTL) return null;
    return c;
  }

  var inflight = 0;
  var navigating = false;
  function prefetch(u) {
    var k = key(u);
    // Never while a page is on its way: a save's redirect carries a one-time message, and a
    // prefetch landing between the two would be the page that consumed it.
    if (stuck || navigating || k === here() || cache.has(k) || inflight >= 2) return;
    var conn = navigator.connection;
    if (conn && (conn.saveData || /(^|-)2g$/.test(conn.effectiveType || ''))) return;
    inflight++;
    var p = request(u.href, { prefetch: true });
    var entry = { at: Date.now(), p: p, prefetched: true };
    cache.set(k, entry);
    p.catch(function () { if (cache.get(k) === entry) cache.delete(k); })
     .then(function () { inflight--; });
    while (cache.size > CACHE_MAX) cache.delete(cache.keys().next().value);
  }

  // ── going somewhere ──────────────────────────────────────────────────────────

  var seq = 0;
  var ctrl = null;
  var shown = location.pathname + location.search;   // the page on screen

  function hard(url, o) {
    if (o && o.pop) location.reload(); else location.assign(url);
  }

  function saveScroll() {
    try { history.replaceState(Object.assign({}, history.state || {}, { cn: 1, y: main.scrollTop }), ''); } catch (_) {}
  }

  /**
   * @param {string} href
   * @param {{pop?:boolean, y?:number, post?:{action:string, body:*}, form?:HTMLFormElement}} [o]
   */
  function go(href, o) {
    o = o || {};
    var u = new URL(href, location.href);
    if (!eligible(u) || stuck) { hard(u.href, o); return; }
    if (!o.post && !o.pop && window.cnUx && window.cnUx.dirty()
        && !window.confirm('You have changes on this page that are not saved. Leave without saving them?')) return;

    var mine = ++seq;
    navigating = true;
    if (ctrl) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    var signal = ctrl ? ctrl.signal : undefined;
    busy(true);

    var p, used = null;
    if (o.post) {
      p = request(o.post.action, { method: 'POST', body: o.post.body, signal: signal });
    } else {
      used = cached(key(u));
      p = used ? used.p : request(u.href, { signal: signal });
    }

    p.then(function (page) {
      if (mine !== seq) return;
      if (o.post) cache.clear();       // whatever was fetched ahead may now be out of date
      return apply(page, u, o).then(function (swapped) {
        // A page answered from a prefetch was never counted as a visit. Now it is one.
        if (swapped && used && used.prefetched) fetch(page.url, { method: 'HEAD', credentials: 'same-origin', headers: { 'X-Console-Nav': '1' } }).catch(function () {});
      });
    }).catch(function (err) {
      if (mine !== seq || (err && err.name === 'AbortError')) return;
      busy(false);
      if (o.post) {
        // Nobody can know whether it arrived. Say so, and hand the form back.
        if (o.form && window.cnUx && window.cnUx.restore) window.cnUx.restore(o.form);
        if (window.agToast) window.agToast('The connection dropped before the server answered. Check whether that was saved before sending it again.');
      } else {
        hard(u.href, o);
      }
    });
  }

  function writeDocument(html) {
    // Last resort for an answer to a POST that is not a console page and has no address of
    // its own to load (an error page): shown as it came. Its scripts carry the server's
    // nonce for a document that no longer exists, so the policy declines them — the page
    // reads, nothing on it runs, and nothing is sent twice.
    document.open();
    document.write(html);
    document.close();
  }

  function download(page) {
    var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(page.disposition);
    var a = document.createElement('a');
    a.href = URL.createObjectURL(page.blob);
    a.download = m ? decodeURIComponent(m[1]) : '';
    body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 30000);
  }

  /** @return {Promise<boolean>} whether the page was swapped in */
  function apply(page, u, o) {
    var post = !!o.post;
    if (page.html == null) {
      busy(false);
      if (post && page.blob) {
        download(page);
        if (o.form && window.cnUx && window.cnUx.restore) window.cnUx.restore(o.form);
        return Promise.resolve(false);
      }
      hard(page.url, o);
      return Promise.resolve(false);
    }

    // Re-signed BEFORE it is parsed: Chromium checks a <style> against this document's
    // policy even inside an inert parsed document, so a nonce from the response — one this
    // document has never seen — is refused (and reported) on every swap. Only our own
    // server's markup reaches here, and Twig escapes everything a person typed, so the
    // only nonce attributes in it are the ones the templates wrote.
    var doc = new DOMParser().parseFromString(page.html.replace(/\snonce="[^"]*"/g, ' nonce="' + NONCE + '"'), 'text/html');
    var final = new URL(page.url, location.href);
    var consolePage = doc.body && doc.body.hasAttribute('data-console') && doc.getElementById('cnMain');
    if (!consolePage || !eligible(final) || unsafe(doc)) {
      // Signed out, a public page, a page with scripts of its own: the ordinary way. After a
      // POST that was redirected, loading the final address repeats nothing.
      if (!post || page.redirected) hard(final.href, post ? {} : o);
      else writeDocument(page.html);
      return Promise.resolve(false);
    }

    var samePage = key(final) === here();
    return styles(doc).then(function (drop) {
      if (!o.pop) saveScroll();
      var keepY = post && samePage ? main.scrollTop : null;

      return swap(doc).then(function () {
        drop();
        var hash = final.hash || u.hash;
        if (o.pop) { /* the URL is already right */ }
        else if (samePage) history.replaceState({ cn: 1, y: keepY || 0 }, '', final.pathname + final.search + hash);
        else history.pushState({ cn: 1, y: 0 }, '', final.pathname + final.search + hash);
        shown = here();

        var target = hash && document.getElementById(decodeURIComponent(hash.slice(1)));
        if (target) target.scrollIntoView();
        else main.scrollTop = o.pop ? (o.y || 0) : (keepY != null ? keepY : 0);

        // Focus to the page, unless the page's own script already placed it inside — the door
        // desk puts the cursor in the ticket-code box on purpose, and that choice wins.
        if (!o.pop && !main.contains(document.activeElement)) main.focus({ preventScroll: true });
        live.textContent = document.title;
        busy(false);
        return true;
      });
    });
  }

  // ── the swap ─────────────────────────────────────────────────────────────────

  /** New stylesheets in, loaded before the page is shown; returns how to drop the old. */
  function styles(doc) {
    var abs = function (h) { return new URL(h, location.href).href; };
    var want = new Set(), loads = [];
    [].forEach.call(doc.querySelectorAll('head link[rel="stylesheet"]'), function (l) {
      var h = abs(l.getAttribute('href'));
      want.add(h);
      if (document.querySelector('head link[rel="stylesheet"][href="' + l.getAttribute('href').replace(/"/g, '\\"') + '"]')) return;
      loads.push(new Promise(function (done) {
        var n = document.createElement('link');
        n.rel = 'stylesheet'; n.href = l.getAttribute('href');
        n.onload = n.onerror = done;
        setTimeout(done, 3000);
        document.head.appendChild(n);
      }));
    });
    var wantStyle = new Set([].map.call(doc.querySelectorAll('head style'), function (s) { return s.textContent; }));
    [].forEach.call(doc.querySelectorAll('head style'), function (s) {
      var exists = [].some.call(document.querySelectorAll('head style'), function (c) { return c.textContent === s.textContent; });
      if (exists) return;
      var n = document.createElement('style');
      n.nonce = NONCE; n.textContent = s.textContent;
      document.head.appendChild(n);
    });
    return Promise.all(loads).then(function () {
      return function drop() {
        [].forEach.call(document.querySelectorAll('head link[rel="stylesheet"]'), function (l) { if (!want.has(l.href)) l.remove(); });
        [].forEach.call(document.querySelectorAll('head style'), function (s) { if (!wantStyle.has(s.textContent)) s.remove(); });
      };
    });
  }

  /** A node from the fetched page, made this document's (already re-signed in apply()). */
  function adopt(n) { return document.importNode(n, true); }

  function take(doc, sel) { var n = doc.querySelector(sel); return n ? adopt(n) : null; }

  function replaceEl(doc, sel) {
    var cur = document.querySelector(sel), next = take(doc, sel);
    if (cur && next) cur.replaceWith(next);
  }

  function replaceKids(doc, sel) {
    var cur = document.querySelector(sel), next = doc.querySelector(sel);
    if (!cur || !next) return;
    cur.replaceChildren.apply(cur, [].map.call(next.childNodes, adopt));
  }

  function alpineRoots(root) {
    return [].filter.call(root.querySelectorAll('[x-data]'), function (el) {
      var up = el.parentElement && el.parentElement.closest('[x-data]');
      return !up || !root.contains(up);
    });
  }

  /** A fresh <script> for an inert one, so it runs, under this document's nonce. */
  function revive(old) {
    var s = document.createElement('script');
    [].forEach.call(old.attributes, function (a) { if (a.name !== 'nonce') s.setAttribute(a.name, a.value); });
    s.nonce = NONCE;
    var wait = null;
    if (old.src) {
      s.async = false;
      wait = new Promise(function (done) { s.onload = s.onerror = done; setTimeout(done, 5000); });
    } else {
      s.textContent = old.textContent;
    }
    old.replaceWith(s);
    return wait;
  }

  function closeOverlays() {
    [].forEach.call(document.querySelectorAll('dialog[open]'), function (d) { if (d.id !== 'cnConfirm') d.close(); });
    if (body.hasAttribute('data-side-open')) {
      body.removeAttribute('data-side-open');
      var scrim = document.querySelector('.cn-scrim');
      if (scrim) scrim.hidden = true;
    }
  }

  function swap(doc) {
    document.dispatchEvent(new CustomEvent('cn:unload'));
    closeOverlays();

    document.title = doc.title;
    var tok = doc.querySelector('meta[name="csrf-token"]'), curTok = document.querySelector('meta[name="csrf-token"]');
    if (tok && curTok) curTok.setAttribute('content', tok.getAttribute('content') || '');
    ['data-readonly', 'data-side'].forEach(function (a) {
      if (doc.body.hasAttribute(a)) body.setAttribute(a, doc.body.getAttribute(a)); else body.removeAttribute(a);
    });

    // The rail keeps its scroll: the page you came from is still where your eye left it.
    var nav = document.querySelector('.cn-nav'), y = nav ? nav.scrollTop : 0;
    replaceKids(doc, '.cn-nav');
    if (nav) nav.scrollTop = y;
    replaceEl(doc, '.cn-bar');
    replaceEl(doc, '.cn-tabbar');
    replaceKids(doc, '#cnPalList');
    var fp = doc.querySelector('#cnFeedback [name="page"]'), cp = document.querySelector('#cnFeedback [name="page"]');
    if (fp && cp) cp.value = fp.value;
    var toasts = document.getElementById('cnToasts');
    if (toasts) [].forEach.call(doc.querySelectorAll('#cnToasts .cn-toast'), function (t) { toasts.appendChild(adopt(t)); });

    // The page. Alpine is kept from starting on it until the page's own scripts have run,
    // because an x-data naming a component those scripts define would otherwise fail.
    var A = window.Alpine;
    var next = doc.getElementById('cnMain');
    var nodes = [].map.call(next.childNodes, adopt);
    if (A && A.destroyTree) alpineRoots(main).forEach(function (el) { A.destroyTree(el); });
    var put = function () { main.replaceChildren.apply(main, nodes); };
    if (A && A.mutateDom) A.mutateDom(put); else put();

    // Page scripts: those inside the page run where they stand; the page's own scripts
    // after the shell's (`foot_scripts`) replace the last page's.
    var waits = [];
    [].forEach.call(main.querySelectorAll('script'), function (s) { if (runnable(s)) waits.push(revive(s)); });
    [].forEach.call(document.querySelectorAll('body > script[data-nav="rerun"]'), function (s) { s.remove(); });
    [].forEach.call(doc.querySelectorAll('body > script'), function (s) {
      if (!runnable(s) || s.getAttribute('data-nav') === 'shell') return;
      var n = document.importNode(s, true);
      body.appendChild(n);
      waits.push(revive(n));
    });

    return Promise.all(waits.filter(Boolean)).then(function () {
      if (A && A.initTree) alpineRoots(main).forEach(function (el) { A.initTree(el); });
      stuck = unsafe(document);
      document.dispatchEvent(new CustomEvent('cn:load', { detail: { root: main } }));
    });
  }

  // ── listening ────────────────────────────────────────────────────────────────

  function optedOut(el) { return !!(el && el.closest && el.closest('[data-nav="off"]')); }

  function linkOf(e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || optedOut(a) || a.hasAttribute('download')) return null;
    var t = a.getAttribute('target');
    if (t && t !== '_self') return null;
    var raw = a.getAttribute('href');
    if (!raw || raw.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(raw)) return null;
    var u = new URL(a.href, location.href);
    if (!eligible(u)) return null;
    if (key(u) === here() && u.hash) return null;       // an anchor on this page
    return u;
  }

  // On WINDOW, not the document: an event reaches the window after every listener on the
  // document, whenever that listener was added. A page script swapped in later (the review
  // list's peek opens on a row click) has therefore always had its chance to handle the
  // click — and prevent it — before this decides it is a navigation.
  window.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var u = linkOf(e);
    if (!u) return;
    e.preventDefault();
    go(u.href);
  });

  window.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target, b = e.submitter || null;
    if (optedOut(f) || optedOut(b)) return;
    var method = ((b && b.getAttribute('formmethod')) || f.getAttribute('method') || 'get').toLowerCase();
    if (method === 'dialog') return;
    var target = (b && b.getAttribute('formtarget')) || f.getAttribute('target');
    if (target && target !== '_self') return;
    var action = new URL((b && b.getAttribute('formaction')) || f.getAttribute('action') || location.href, location.href);
    if (!eligible(action) || stuck) return;

    var fd;
    try { fd = new FormData(f, b); }
    catch (_) { fd = new FormData(f); if (b && b.name) fd.append(b.name, b.value); }
    e.preventDefault();
    if (method === 'get') {
      var q = new URLSearchParams();
      fd.forEach(function (v, k) { if (typeof v === 'string') q.append(k, v); });
      action.search = q.toString();
      go(action.href);
    } else {
      var multipart = ((b && b.getAttribute('formenctype')) || f.getAttribute('enctype') || '').toLowerCase() === 'multipart/form-data';
      var bodyData = fd;
      if (!multipart) { bodyData = new URLSearchParams(); fd.forEach(function (v, k) { if (typeof v === 'string') bodyData.append(k, v); }); }
      go(action.href, { post: { action: action.href, body: bodyData }, form: f });
    }
  });

  // Ahead of the click: a pointer that rests on a link for a moment, or a finger landing.
  var hoverTimer = 0;
  document.addEventListener('pointerover', function (e) {
    if (e.pointerType === 'touch') return;
    var u = linkOf(e);
    clearTimeout(hoverTimer);
    if (u) hoverTimer = setTimeout(function () { prefetch(u); }, 70);
  });
  document.addEventListener('pointerout', function () { clearTimeout(hoverTimer); });
  document.addEventListener('touchstart', function (e) { var u = linkOf(e); if (u) prefetch(u); }, { passive: true });
  document.addEventListener('focusin', function (e) {
    if (!e.target.matches || !e.target.matches(':focus-visible')) return;
    var u = linkOf(e);
    if (u) prefetch(u);
  });

  // Back and Forward. Our entries carry `cn`; a hash change on this page carries nothing.
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  try { history.replaceState(Object.assign({}, history.state || {}, { cn: 1, y: main.scrollTop }), ''); } catch (_) {}
  window.addEventListener('popstate', function (e) {
    if (!e.state || !e.state.cn) return;
    if (here() === shown) return;
    go(location.href, { pop: true, y: e.state.y || 0 });
  });

  window.cnNav = { go: function (href) { go(href); }, prefetch: function (href) { prefetch(new URL(href, location.href)); } };
})();
