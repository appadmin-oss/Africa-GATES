/*!
 * ag-social.js — the one implementation of "react to a thing".
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * Liking, commenting, saving, reposting, following and reporting were written
 * once, inline, inside the community thread template. Pulse needs every one of
 * them. Copying that block into a second template would mean two optimistic
 * rollbacks to keep in step, two places to fix a 401 handler, and two chances
 * for one surface to quietly stop rolling back a failed like — which shows the
 * reader a like that the server never recorded.
 *
 * So the primitives live here and the templates own only their markup. There is
 * no Alpine dependency in this file: callers pass in a plain state object and
 * this mutates it, which keeps it usable from Alpine, from a plain listener, or
 * from a test.
 *
 * Every mutating call is a form POST to /api/v1/*, which the CSRF middleware
 * admits on same-origin + X-Requested-With rather than a token, so these work
 * from a page that was served from cache.
 */
(function (w, d) {
  'use strict';

  var API = '/api/v1/community';
  var HEADERS = {
    'Content-Type': 'application/x-www-form-urlencoded',
    'X-Requested-With': 'XMLHttpRequest'   // cannot be set by a cross-origin form post
  };

  function send(path, params) {
    return fetch(API + path, {
      method: 'POST', headers: HEADERS, body: new URLSearchParams(params)
    }).then(function (r) {
      return r.json().catch(function () { return {}; })
        .then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function needsSignIn(status, data) {
    return status === 401 || (data && (data.code === 'SIGN_IN' || data.auth === true));
  }

  function signInUrl() {
    return '/account/login?next=' + encodeURIComponent(w.location.pathname + w.location.search);
  }

  /**
   * Optimistic cheer/like toggle.
   *
   * `state` is mutated in place: {on, n, busy}. The UI flips FIRST — a like that
   * waits for a round trip feels broken on a phone on 3G, which is the connection
   * most of this audience is on — and the previous values are kept so a failure
   * puts the heart back where it was. `hooks.onSignIn` fires for a guest;
   * `hooks.onError` gets a human message; `hooks.onLiked` fires only on a
   * transition to liked, so a caller can run the burst animation.
   */
  function cheer(targetType, targetId, state, hooks) {
    hooks = hooks || {};
    if (state.busy) return Promise.resolve(false);
    state.busy = true;

    var prev = { on: state.on, n: state.n };
    state.on = !state.on;
    state.n = Math.max(0, (state.n | 0) + (state.on ? 1 : -1));
    if (state.on && hooks.onLiked) hooks.onLiked();

    return send('/cheer', { target_type: targetType, target_id: targetId })
      .then(function (r) {
        if (r.data && r.data.success) {
          state.on = !!r.data.cheered;
          state.n = typeof r.data.count === 'number' ? r.data.count : state.n;
          return true;
        }
        state.on = prev.on; state.n = prev.n;
        if (needsSignIn(r.status, r.data)) { if (hooks.onSignIn) hooks.onSignIn(); }
        else if (hooks.onError) hooks.onError((r.data && r.data.message) || 'Could not react just now.');
        return false;
      })
      .catch(function () {
        state.on = prev.on; state.n = prev.n;
        if (hooks.onError) hooks.onError('Network error — please try again.');
        return false;
      })
      .finally(function () { state.busy = false; });
  }

  /**
   * Set, change or clear one of the four reactions.
   *
   * ── WHY THIS IS NOT `cheer` WITH AN EXTRA ARGUMENT ─────────────────────────
   *
   * A cheer is a BOOLEAN and rolls back to on or off. A reaction is SINGULAR:
   * you hold at most one, and pressing a different one MOVES it rather than
   * adding a second. That is three optimistic outcomes, not two —
   *
   *   press the one you hold  → cleared, total −1
   *   press a different one   → moved,   total UNCHANGED
   *   press with none held    → set,     total +1
   *
   * — and the middle case is the one a boolean cannot express. Written as a flag
   * on cheer(), changing a reaction would flash the total up or down and then
   * snap back when the server answered.
   *
   * `state` is {kind, n, breakdown, busy}, mutated in place — same contract as
   * everything else here: no Alpine, no framework, just an object to watch.
   */
  function react(targetType, targetId, kind, state, hooks) {
    hooks = hooks || {};
    if (state.busy) return Promise.resolve(false);
    state.busy = true;

    var prev = { kind: state.kind, n: state.n | 0, breakdown: assign({}, state.breakdown) };
    var held = state.kind || null;
    var bd = assign({}, state.breakdown);

    if (held) bd[held] = Math.max(0, (bd[held] | 0) - 1);
    if (held === kind) {
      state.kind = null;
      state.n = Math.max(0, (state.n | 0) - 1);
    } else {
      bd[kind] = (bd[kind] | 0) + 1;
      state.kind = kind;
      if (!held) state.n = (state.n | 0) + 1;
      if (hooks.onReacted) hooks.onReacted(kind);
    }
    // A kind that has fallen to zero is REMOVED, not left sitting at 0 — the
    // rail draws whatever is in the breakdown, and a lingering zero renders as
    // an empty pip that nobody pressed.
    state.breakdown = prune(bd);

    return send('/cheer', { target_type: targetType, target_id: targetId, kind: kind })
      .then(function (r) {
        if (r.data && r.data.success) {
          state.kind = r.data.kind || null;
          if (typeof r.data.count === 'number') state.n = r.data.count;
          if (r.data.breakdown) state.breakdown = prune(assign({}, r.data.breakdown));
          return true;
        }
        state.kind = prev.kind; state.n = prev.n; state.breakdown = prev.breakdown;
        if (needsSignIn(r.status, r.data)) { if (hooks.onSignIn) hooks.onSignIn(); }
        else if (hooks.onError) hooks.onError((r.data && r.data.message) || 'Could not react just now.');
        return false;
      })
      .catch(function () {
        state.kind = prev.kind; state.n = prev.n; state.breakdown = prev.breakdown;
        if (hooks.onError) hooks.onError('Network error — please try again.');
        return false;
      })
      .finally(function () { state.busy = false; });
  }

  /** Object.assign, minus the assumption that every browser here has it. */
  function assign(to, from) {
    if (from) for (var k in from) if (Object.prototype.hasOwnProperty.call(from, k)) to[k] = from[k];
    return to;
  }

  function prune(bd) {
    for (var k in bd) if (!(bd[k] | 0)) delete bd[k];
    return bd;
  }

  /**
   * Save / repost / follow. Same shape as cheer but the server names the new
   * state differently per endpoint (`bookmarked`, `reposted`, `following`), so
   * the caller says which field to read.
   */
  function toggle(path, params, state, key, hooks) {
    hooks = hooks || {};
    if (state.busy) return Promise.resolve(false);
    state.busy = true;

    var prev = { on: state.on, n: state.n };
    state.on = !state.on;

    return send(path, params)
      .then(function (r) {
        if (r.data && r.data.success) {
          state.on = !!r.data[key];
          if (typeof r.data.count === 'number') state.n = r.data.count;
          return true;
        }
        state.on = prev.on; state.n = prev.n;
        if (needsSignIn(r.status, r.data)) { if (hooks.onSignIn) hooks.onSignIn(); }
        else if (hooks.onError) hooks.onError((r.data && r.data.message) || 'That did not go through.');
        return false;
      })
      .catch(function () {
        state.on = prev.on; state.n = prev.n;
        if (hooks.onError) hooks.onError('Network error — please try again.');
        return false;
      })
      .finally(function () { state.busy = false; });
  }

  /**
   * Post a comment. Resolves to {ok, id, status, message}.
   *
   * `status` is 'approved' or 'quarantined' — the caller MUST honour the
   * difference. Showing a quarantined comment in the thread as though it were
   * live tells the author their post is public when a moderator has not seen it
   * yet, and on a platform with children in the audience that is the one lie
   * that matters most.
   */
  function comment(opts) {
    return send('/comment', {
      target_type: opts.targetType,
      target_id: opts.targetId,
      body: opts.body,
      parent_id: opts.parentId || ''
    }).then(function (r) {
      if (r.data && r.data.success) {
        return { ok: true, id: r.data.id, status: r.data.status || 'approved' };
      }
      return {
        ok: false,
        signIn: needsSignIn(r.status, r.data),
        message: (r.data && r.data.message) || 'Could not post that.'
      };
    }).catch(function () {
      return { ok: false, message: 'Network error — please try again.' };
    });
  }

  function report(targetType, targetId, reason) {
    return send('/report', { target_type: targetType, target_id: targetId, reason: reason || '' })
      .then(function (r) { return { ok: !!(r.data && r.data.success), message: r.data && r.data.message }; })
      .catch(function () { return { ok: false, message: 'Network error — please try again.' }; });
  }

  /** Share sheet where the browser has one, clipboard where it does not. */
  function share(url, title, hooks) {
    hooks = hooks || {};
    var abs = new URL(url, w.location.origin).href;
    if (navigator.share) {
      return navigator.share({ title: title || d.title, url: abs })
        .then(function () { return true; })
        // An abort is the user closing the sheet, not a failure — saying
        // "couldn't share" when they simply changed their mind is noise.
        .catch(function (e) {
          if (e && e.name === 'AbortError') return false;
          return copy(abs, hooks);
        });
    }
    return copy(abs, hooks);
  }

  function copy(text, hooks) {
    hooks = hooks || {};
    if (navigator.clipboard && w.isSecureContext) {
      return navigator.clipboard.writeText(text)
        .then(function () { if (hooks.onCopied) hooks.onCopied(); return true; })
        .catch(function () { if (hooks.onError) hooks.onError('Could not copy the link.'); return false; });
    }
    if (hooks.onError) hooks.onError('Copying is not available in this browser.');
    return Promise.resolve(false);
  }

  // ── text helpers ──────────────────────────────────────────────────────────

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * Links, @mentions and #hashtags, as links.
   *
   * ESCAPES FIRST, THEN LINKIFIES. The input is a member-written post going into
   * innerHTML; doing it the other way round — linkify a raw string, then trust it
   * — is how a feed becomes a stored-XSS delivery mechanism. Because escaping runs
   * first, the patterns below only ever match text that is already inert.
   *
   * ── WHY URLS ARE MATCHED, AND MATCHED FIRST ─────────────────────────────
   *
   * They were not matched at all, so every link anyone shared in the Pulse rendered
   * as text nobody could follow. The result announcement made that impossible to
   * leave: its last line is the address of the page holding the whole standing, and
   * that post exists to carry people there.
   *
   * FIRST, before the two sigil passes, because those insert `<a href="/registry?q=…">`
   * and a URL pattern run afterwards would match inside the attribute it just wrote and
   * shred the markup. In the other order nothing can go wrong: both sigil patterns
   * require whitespace or `(` before the sigil, and a URL contains neither, so they
   * cannot reach inside an anchor this pass produced.
   *
   * http(s) ONLY — never a bare `/path`. A relative pattern would turn "and/or" and
   * every date written "12/06" into a link to a page that does not exist, which is a
   * worse outcome on far more posts than the one it fixes.
   *
   * Trailing `.,;:!?)` is left OUT of the link: a URL at the end of a sentence is the
   * normal case, and swallowing the full stop breaks the address itself.
   */
  var URL_RE = /\bhttps?:\/\/[^\s<>"']+/g;

  function linkify(text) {
    var safe = escapeHtml(text);
    safe = safe.replace(URL_RE, function (url) {
      var tail = '';
      var m = url.match(/[.,;:!?)]+$/);
      if (m) { tail = m[0]; url = url.slice(0, -tail.length); }
      if (!url) return tail;
      // `&` arrived here as `&amp;` from escapeHtml, and that is correct in an href —
      // the browser decodes the entity back to `&` when it resolves the link. The label
      // keeps the entity too, so it renders as the single character it is.
      return '<a class="ag-link" href="' + url + '" rel="nofollow ugc noopener">' + url + '</a>' + tail;
    });
    safe = safe.replace(/(^|[\s(])@([A-Za-z0-9_.]{2,30})\b/g, function (_, pre, name) {
      return pre + '<a class="ag-tag" href="/registry?q=' + encodeURIComponent(name) + '">@' + name + '</a>';
    });
    safe = safe.replace(/(^|[\s(])#([A-Za-z0-9_]{2,40})\b/g, function (_, pre, tag) {
      return pre + '<a class="ag-tag" href="/activity?q=' + encodeURIComponent(tag) + '">#' + tag + '</a>';
    });
    return safe.replace(/\n/g, '<br>');
  }

  /**
   * "just now", "4m", "3h", "2d" — then a real date.
   *
   * Past about a week a relative stamp stops being information ("47d" tells you
   * nothing you wanted to know), so it hands over to a date.
   */
  function timeAgo(value) {
    if (!value) return '';
    // "2026-08-01 00:16:03" is not a format Safari will parse; give it a T and a Z.
    var iso = typeof value === 'string' && value.indexOf('T') === -1
      ? value.trim().replace(' ', 'T') + 'Z' : value;
    var t = new Date(iso).getTime();
    if (isNaN(t)) return '';

    var s = Math.floor((Date.now() - t) / 1000);
    if (s < 0) s = 0;                                  // clock skew reads as "just now"
    if (s < 45) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'h';
    if (s < 604800) return Math.floor(s / 86400) + 'd';
    return new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  w.agSocial = {
    cheer: cheer,
    react: react,
    toggle: toggle,
    comment: comment,
    report: report,
    share: share,
    copy: copy,
    linkify: linkify,
    escapeHtml: escapeHtml,
    timeAgo: timeAgo,
    signInUrl: signInUrl
  };
})(window, document);
