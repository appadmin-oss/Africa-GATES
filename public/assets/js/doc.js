/* ══════════════════════════════════════════════════════════════════════════════
   THE DOCUMENT FAMILY — doc.js · Phase 9 · design/DocPage.dc.html · §8.20
   ══════════════════════════════════════════════════════════════════════════════

   Everything here IMPROVES a page that already works without it (partials/article.twig):
   the contents are links, the downloads are anchors, the contents sheet opens on its own
   id, every citation format is printed. What this adds:

     · the 2px reading-progress bar and the current section (the rail's white pill, the
       Contents bar's "02 · The score", the sheet's dot) — one passive listener on <main>;
     · the contents sheet through AGChrome.openSheet (focus in, Tab trapped, Esc and the
       scrim close, Back closes, focus returns), and a jump that lands the heading BELOW
       the sticky bars (`scroll-margin-top:64px` on the section and its heading);
     · Listen / Pause by speech synthesis, shown only where the browser has it, read a
       block at a time — one long utterance is cut off after ~15 seconds in Chrome;
     · the citation tablist: roving tabindex with arrow keys, Home and End — without them
       a keyboard reaches exactly one of the four formats;
     · Copy citation and Share link, with a status line that says what happened.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var root = document.querySelector('[data-doc]');
  if (!root) return;
  var main = document.querySelector('.ag-main') || document.scrollingElement;
  var secs = Array.prototype.slice.call(root.querySelectorAll('[data-doc-sec]'));
  var fill = root.querySelector('[data-doc-progress]');
  var curLabel = root.querySelector('[data-doc-current]');
  var links = Array.prototype.slice.call(document.querySelectorAll('[data-doc-toc]'));

  function topOf(el) {
    return el.getBoundingClientRect().top - main.getBoundingClientRect().top + main.scrollTop;
  }

  /* ── Progress and the current section ─────────────────────────────────── */
  var current = -1;
  function mark(i) {
    if (i === current) return;
    current = i;
    links.forEach(function (a) {
      if (+a.getAttribute('data-doc-toc') === i) a.setAttribute('aria-current', 'location');
      else a.removeAttribute('aria-current');
    });
    if (curLabel && secs[i]) {
      var h = secs[i].querySelector('.dp-sec__h');
      var n = h && h.querySelector('.dp-sec__n');
      var title = h ? h.textContent.replace(n ? n.textContent : '', '').trim() : '';
      curLabel.textContent = (n ? n.textContent : '') + ' · ' + title;
    }
  }
  var ticking = false;
  function measure() {
    ticking = false;
    var max = main.scrollHeight - main.clientHeight;
    var p = max > 0 ? Math.min(1, Math.max(0, main.scrollTop / max)) : 0;
    if (fill) fill.style.transform = 'scaleX(' + p.toFixed(3) + ')';
    var at = 0;
    for (var i = 0; i < secs.length; i++) {
      if (topOf(secs[i]) - 90 <= main.scrollTop) at = i;
    }
    mark(secs.length ? at : -1);
  }
  main.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; window.requestAnimationFrame(measure); }
  }, { passive: true });
  measure();

  /* ── Contents sheet ───────────────────────────────────────────────────── */
  var sheet = document.querySelector('[data-doc-sheet]');
  var scrim = document.querySelector('[data-doc-scrim]');
  var opener = root.querySelector('[data-doc-open]');
  var closeSheet = null;
  if (sheet) {
    sheet.setAttribute('inert', '');
    // The no-script door is the sheet's own id; with the script it opens in place, so a
    // `#dp-contents` already in the address (a reload) must not leave it open and inert.
    if (location.hash === '#dp-contents') { try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {} }
  }
  function openSheet(e) {
    if (!sheet) return;
    if (e) e.preventDefault();
    var o = window.AGChrome && window.AGChrome.openSheet;
    closeSheet = o ? o(sheet, scrim, opener) : (window.AGShell ? window.AGShell.openSheet(sheet, scrim, opener) : null);
  }
  function shut() {
    if (closeSheet) { var c = closeSheet; closeSheet = null; c(); }
    else if (sheet && sheet.hasAttribute('data-open') && scrim) scrim.click();
  }
  if (opener) opener.addEventListener('click', openSheet);
  var x = document.querySelector('[data-doc-close]');
  if (x) x.addEventListener('click', function (e) { e.preventDefault(); shut(); });

  /* A jump: the section's heading lands under the bars and takes focus, so a screen
     reader and a keyboard continue from where the eye went. */
  function jump(id) {
    var sec = document.getElementById(id);
    if (!sec) return;
    var h = sec.querySelector('.dp-sec__h') || sec;
    sec.scrollIntoView({ block: 'start' });
    try { h.focus({ preventScroll: true }); } catch (err) { h.focus(); }
    try { history.replaceState(null, '', '#' + id); } catch (err) {}
  }
  links.forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = (a.getAttribute('href') || '').slice(1);
      if (!id) return;
      e.preventDefault();
      if (sheet && sheet.contains(a)) {
        // The sheet's close gives focus back to the bar and steps history back (Back
        // closes a sheet, chrome.js); a jump made before that traversal lands is undone
        // by it. So jump on the popstate it causes, or after 300ms if none comes.
        var done = false;
        var go = function () {
          if (done) return; done = true;
          window.removeEventListener('popstate', go);
          window.setTimeout(function () { jump(id); }, 0);
        };
        window.addEventListener('popstate', go);
        window.setTimeout(go, 300);
        shut();
      } else {
        jump(id);
      }
    });
  });

  /* ── Listen ───────────────────────────────────────────────────────────── */
  var listen = root.querySelector('[data-doc-listen]');
  var synth = window.speechSynthesis;
  if (listen && synth && typeof window.SpeechSynthesisUtterance === 'function') {
    listen.hidden = false;
    var label = listen.querySelector('[data-doc-listen-label]');
    var state = 'idle';   // idle · speaking · paused
    var set = function (s) {
      state = s;
      var on = s === 'speaking';
      listen.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (label) label.textContent = on ? listen.getAttribute('data-pause') : listen.getAttribute('data-listen');
    };
    var chunks = function () {
      var out = [];
      var read = root.querySelector('[data-doc-read]');
      var head = root.querySelector('.dp-head__h1');
      if (head) out.push(head.textContent);
      if (!read) return out;
      read.querySelectorAll('h2, h3, p, li, th, td, blockquote, figcaption').forEach(function (el) {
        if (el.closest('li') && el.tagName !== 'LI') return;   // a <p> inside a <li> is read with it
        var t = el.textContent.replace(/\s+/g, ' ').trim();
        if (t) out.push(t);
      });
      return out;
    };
    listen.addEventListener('click', function () {
      if (state === 'speaking') { synth.pause(); set('paused'); return; }
      if (state === 'paused') { synth.resume(); set('speaking'); return; }
      synth.cancel();
      var list = chunks();
      var lang = document.documentElement.getAttribute('lang') || 'en';
      list.forEach(function (t, i) {
        var u = new window.SpeechSynthesisUtterance(t);
        u.lang = lang;
        if (i === list.length - 1) u.onend = function () { set('idle'); };
        u.onerror = function () { set('idle'); };
        synth.speak(u);
      });
      set('speaking');
    });
    window.addEventListener('pagehide', function () { synth.cancel(); });
  }

  /* ── Citation: tabs, copy, share ──────────────────────────────────────── */
  var cite = root.querySelector('[data-doc-cite]');
  if (!cite) return;
  var say = cite.querySelector('[data-doc-say]');
  var tabs = Array.prototype.slice.call(cite.querySelectorAll('[role="tab"]'));
  var panels = Array.prototype.slice.call(cite.querySelectorAll('[data-doc-panel]'));
  function select(i, focus) {
    tabs.forEach(function (t, j) {
      t.setAttribute('aria-selected', j === i ? 'true' : 'false');
      t.setAttribute('tabindex', j === i ? '0' : '-1');
    });
    panels.forEach(function (p, j) { p.hidden = j !== i; });
    if (focus && tabs[i]) tabs[i].focus();
  }
  if (tabs.length) {
    select(0, false);
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(i, false); });
      t.addEventListener('keydown', function (e) {
        var n = tabs.length, to = null;
        var rtl = document.documentElement.getAttribute('dir') === 'rtl';
        if (e.key === 'ArrowRight') to = (i + (rtl ? n - 1 : 1)) % n;
        else if (e.key === 'ArrowLeft') to = (i + (rtl ? 1 : n - 1)) % n;
        else if (e.key === 'Home') to = 0;
        else if (e.key === 'End') to = n - 1;
        if (to === null) return;
        e.preventDefault();
        select(to, true);
      });
    });
  }

  function tell(key) {
    if (!say) return;
    say.textContent = '';
    window.setTimeout(function () { say.textContent = say.getAttribute('data-' + key) || ''; }, 40);
  }
  /* navigator.clipboard needs a secure context and is missing on some older Android
     WebViews, so the textarea path is not belt-and-braces: on a real slice of this
     platform's traffic it is the only one. */
  function put(text, okKey) {
    function fallback() {
      try {
        var t = document.createElement('textarea');
        t.value = text; t.setAttribute('readonly', '');
        t.className = 'ag-sr';
        document.body.appendChild(t); t.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(t);
        tell(ok ? okKey : 'failed');
      } catch (e) { tell('failed'); }
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { tell(okKey); }, fallback);
    } else fallback();
  }

  var copy = cite.querySelector('[data-doc-copy-cite]');
  if (copy) {
    copy.hidden = false;
    copy.addEventListener('click', function () {
      var shown = panels.filter(function (p) { return !p.hidden; })[0] || panels[0];
      var t = shown && shown.querySelector('[data-doc-cite-text]');
      if (t) put(t.textContent.trim(), 'copied');
    });
  }
  var share = cite.querySelector('[data-doc-share]');
  if (share) {
    share.hidden = false;
    share.addEventListener('click', function () {
      var url = share.getAttribute('data-doc-share');
      if (navigator.share) {
        navigator.share({ title: document.title, url: url }).catch(function () {});
      } else put(url, 'linked');
    });
  }
})();
