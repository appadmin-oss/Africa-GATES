/*
 * Pulse — the feed's hands (templates/pages/pulse.twig). Phase 8 §8.9.
 *
 * The page works with none of this: every tab and "Show more" is a link, the composer is
 * a form that posts, the reaction button's count is printed by the server. What this adds
 * is doing those things in place, over the live endpoints the destroyed client
 * (ag-social.js) used — /api/v1/community/cheer, /bookmark and /follow — and turning the
 * text of a post into links under the feed's linkify rule (MUST RESTORE, below).
 *
 * CSP: no inline handler anywhere; everything is a delegated listener on the document.
 */
(function (w, d) {
  'use strict';

  // ── linkify ────────────────────────────────────────────────────────────────
  /**
   * Links, @mentions and #hashtags, as links.
   *
   * ESCAPES FIRST, THEN LINKIFIES. The input is a member-written post going into
   * innerHTML; linkifying a raw string and then trusting it is how a feed becomes a
   * stored-XSS delivery mechanism. Because escaping runs first, the patterns below only
   * ever match text that is already inert.
   *
   * URLS FIRST, before the two sigil passes: those insert `<a href="/registry?q=…">`, and a
   * URL pattern run afterwards would match inside the attribute it just wrote. In this
   * order nothing can go wrong — both sigil patterns need whitespace or `(` before the
   * sigil, and a URL contains neither.
   *
   * http(s) ONLY — never a bare `/path`, which would turn "and/or" and "12/06" into links.
   * Trailing `.,;:!?)` stays OUTSIDE the link: a URL ending a sentence is the normal case.
   * rel="nofollow ugc noopener": somebody else's words, not our endorsement.
   */
  var URL_RE = /\bhttps?:\/\/[^\s<>"']+/g;

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function linkify(text) {
    var safe = escapeHtml(text);
    safe = safe.replace(URL_RE, function (url) {
      var tail = '';
      var m = url.match(/[.,;:!?)]+$/);
      if (m) { tail = m[0]; url = url.slice(0, -tail.length); }
      if (!url) return tail;
      return '<a class="ag-link" href="' + url + '" rel="nofollow ugc noopener">' + url + '</a>' + tail;
    });
    safe = safe.replace(/(^|[\s(])@([A-Za-z0-9_.]{2,30})\b/g, function (_, pre, name) {
      return pre + '<a class="ag-tag" href="/registry?q=' + encodeURIComponent(name) + '">@' + name + '</a>';
    });
    safe = safe.replace(/(^|[\s(])#([A-Za-z0-9_]{2,40})\b/g, function (_, pre, tag) {
      return pre + '<a class="ag-tag" href="/activity?q=' + encodeURIComponent(tag) + '">#' + tag + '</a>';
    });
    return safe;
  }

  function linkifyIn(root) {
    var els = (root || d).querySelectorAll('[data-linkify]:not([data-linked])');
    for (var i = 0; i < els.length; i++) {
      // textContent is what the server escaped and printed — re-escaped here, never trusted.
      els[i].innerHTML = linkify(els[i].textContent);
      els[i].setAttribute('data-linked', '1');
    }
  }

  // ── posting to the API ─────────────────────────────────────────────────────
  function token() {
    var t = d.querySelector('input[name="_token"]');
    var m = d.querySelector('meta[name="ag-csrf"]');
    return (t && t.value) || (m && m.content) || '';
  }

  function post(path, body) {
    var fd = new URLSearchParams();
    Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
    return fetch('/api/v1/community/' + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token(),
                 'Content-Type': 'application/x-www-form-urlencoded' },
      body: fd.toString()
    }).then(function (r) {
      if (r.status === 401) { w.location.href = '/account/login?next=' + encodeURIComponent(w.location.pathname + w.location.search); throw new Error('sign-in'); }
      return r.json();
    });
  }

  function count(n) { return n > 999 ? (Math.round(n / 100) / 10) + 'K' : String(n); }

  // ── the reaction picker ─────────────────────────────────────────────────────
  function closePickers(except) {
    var open = d.querySelectorAll('[data-pl-pick]:not([hidden])');
    for (var i = 0; i < open.length; i++) {
      if (open[i] === except) continue;
      open[i].hidden = true;
      var b = open[i].parentNode.querySelector('[data-pl-react]');
      if (b) b.setAttribute('aria-expanded', 'false');
    }
  }

  function openPicker(btn) {
    var pick = btn.parentNode.querySelector('[data-pl-pick]');
    if (!pick) return;
    var willOpen = pick.hidden;
    closePickers(pick);
    pick.hidden = !willOpen;
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) {
      var first = pick.querySelector('[aria-checked="true"]') || pick.querySelector('button');
      if (first) first.focus();
    }
  }

  function react(opt) {
    var pick = opt.closest('[data-pl-pick]');
    var rail = opt.closest('[data-pl-rail]');
    var art = opt.closest('[data-pl-post]');
    var btn = rail.querySelector('[data-pl-react]');
    var kind = opt.getAttribute('data-pl-kind');
    pick.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    btn.focus();
    post('cheer', { target_type: 'thread', target_id: art.getAttribute('data-pl-post'), kind: kind })
      .then(function (r) {
        if (!r || !r.success) return;
        var now = r.kind || '';
        btn.setAttribute('data-kind', now);
        btn.className = 'pl-act pl-act--react' + (now ? ' pl-act--on pl-rx--' + now : '');
        var n = btn.querySelector('[data-pl-n]');
        if (n) n.textContent = count(r.count || 0);
        var path = btn.querySelector('path');
        var chosen = now ? pick.querySelector('[data-pl-kind="' + now + '"] path') : pick.querySelector('[data-pl-kind="cheer"] path');
        if (path && chosen) { path.setAttribute('d', chosen.getAttribute('d')); path.setAttribute('fill', now ? 'currentColor' : 'none'); }
        var opts = pick.querySelectorAll('[data-pl-kind]');
        for (var i = 0; i < opts.length; i++) {
          opts[i].setAttribute('aria-checked', opts[i].getAttribute('data-pl-kind') === now ? 'true' : 'false');
        }
        var label = now ? (pick.querySelector('[data-pl-kind="' + now + '"]').getAttribute('data-label')) : '';
        btn.setAttribute('aria-label', now ? (btn.getAttribute('data-said') || 'You reacted %r%. Change reaction').replace('%r%', label) : (btn.getAttribute('data-react') || 'React'));
      })
      .catch(function () {});
  }

  // ── clicks ─────────────────────────────────────────────────────────────────
  d.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t) return;

    var reactBtn = t.closest('[data-pl-react]');
    if (reactBtn) {
      if (reactBtn.getAttribute('data-signed') !== '1') {
        w.location.href = '/account/login?next=' + encodeURIComponent('/pulse');
        return;
      }
      e.preventDefault(); openPicker(reactBtn); return;
    }
    var opt = t.closest('[data-pl-kind]');
    if (opt) { e.preventDefault(); react(opt); return; }

    var save = t.closest('[data-pl-save]');
    if (save) {
      e.preventDefault();
      post('bookmark', { thread_id: save.getAttribute('data-pl-save') }).then(function (r) {
        if (!r || !r.ok) return;
        save.setAttribute('aria-pressed', r.bookmarked ? 'true' : 'false');
        var p = save.querySelector('path'); if (p) p.setAttribute('fill', r.bookmarked ? 'currentColor' : 'none');
      }).catch(function () {});
      return;
    }

    var fol = t.closest('[data-pl-follow]');
    if (fol && fol.tagName === 'BUTTON') {
      e.preventDefault();
      post('follow', { target_type: 'programme', target_id: fol.getAttribute('data-pl-follow') }).then(function (r) {
        if (!r || !r.ok) return;
        fol.setAttribute('aria-pressed', r.following ? 'true' : 'false');
        fol.textContent = r.following ? fol.getAttribute('data-on') : fol.getAttribute('data-off');
      }).catch(function () {});
      return;
    }

    var more = t.closest('[data-pl-more]');
    if (more && w.fetch) {
      e.preventDefault();
      var href = more.getAttribute('href');
      more.setAttribute('aria-busy', 'true');
      fetch(href + (href.indexOf('?') > -1 ? '&' : '?') + 'part=items', { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var box = d.createElement('div');
          box.innerHTML = html;
          var page = box.querySelector('[data-pl-page]');
          if (!page) { w.location.href = href; return; }
          var feed = more.parentNode;
          var firstNew = page.firstElementChild;
          while (page.firstChild) feed.insertBefore(page.firstChild, more);
          more.parentNode.removeChild(more);
          linkifyIn(feed);
          // Keyboard and reader focus lands on the first post that arrived, not at the top.
          var a = firstNew && firstNew.querySelector('a, button');
          if (a) a.focus();
        })
        .catch(function () { w.location.href = href; });
      return;
    }

    var openC = t.closest('[data-pl-compose-open]');
    var sheet = d.querySelector('[data-pl-compose]');
    if (openC && sheet) {
      e.preventDefault();
      sheet.classList.add('is-open');
      var ta = sheet.querySelector('textarea'); if (ta) ta.focus();
      return;
    }
    var closeC = t.closest('[data-pl-compose-close]');
    if (closeC && sheet) { e.preventDefault(); sheet.classList.remove('is-open'); return; }
    if (sheet && t === sheet && sheet.classList.contains('is-open')) { sheet.classList.remove('is-open'); return; }

    if (!t.closest('[data-pl-pick]')) closePickers(null);
  });

  d.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = d.querySelector('[data-pl-pick]:not([hidden])');
    if (open) {
      closePickers(null);
      var b = open.parentNode.querySelector('[data-pl-react]'); if (b) b.focus();
      return;
    }
    var sheet = d.querySelector('[data-pl-compose].is-open');
    if (sheet) sheet.classList.remove('is-open');
  });

  // Arrow keys move along the picker (it is a menu).
  d.addEventListener('keydown', function (e) {
    var o = e.target.closest && e.target.closest('[data-pl-kind]');
    if (!o || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
    var rtl = d.documentElement.dir === 'rtl';
    var fwd = (e.key === 'ArrowRight') !== rtl;
    var sib = fwd ? o.nextElementSibling : o.previousElementSibling;
    if (sib) { e.preventDefault(); sib.focus(); }
  });

  d.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('[data-pl-media]')) return;
    var out = d.querySelector('[data-pl-file]');
    if (out) out.textContent = e.target.files && e.target.files[0] ? e.target.files[0].name : '';
  });

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { linkifyIn(d); });
  else linkifyIn(d);

  w.agSocial = { linkify: linkify, escapeHtml: escapeHtml };
})(window, document);
