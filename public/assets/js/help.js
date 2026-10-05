/* ══════════════════════════════════════════════════════════════════════════════
   THE HELP CENTRE — help.js · Phase 9 · design/HelpCentre.dc.html · §8.21
   ══════════════════════════════════════════════════════════════════════════════

   Improves pages that already work without it (templates/pages/help*.twig):

     · INDEX: typing narrows the directory in place from the corpus embedded as JSON
       (#help-index). Titles past a card's preview are already in the page, `hidden`, so a
       match among them is shown without a fetch; a card with no match is hidden; the
       status line counts. Enter still submits the real, scored, shareable GET search.
     · Every link into the retired desk (`/support/assistant…`, which 301s to
       `/help?gee=support`) opens Gee's help desk IN PLACE, carrying its `q` — the corpus's
       own prose links there, and "Ask Gee" must never navigate (§8.21).
     · ARTICLE: Copy link; "Yes, thanks" (routing, not rating: nothing is recorded);
       HIGHLIGHT TO ASK — a pill at the selection that asks Gee about that exact passage.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var root = document.querySelector('[data-help]');
  if (!root) return;

  function gee(q, trigger) {
    if (window.AGGee && window.AGGee.open) { window.AGGee.open({ mode: 'support', q: q || '', trigger: trigger }); return true; }
    return false;
  }

  /* ── Links into the retired desk open Gee in place ──────────────────────── */
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href^="/support/assistant"]') : null;
    if (!a || !root.contains(a)) return;
    var q = '';
    try { q = new URL(a.getAttribute('href'), location.href).searchParams.get('q') || ''; } catch (err) {}
    if (gee(q, a)) e.preventDefault();
  });

  /* ── Index: narrow in place ─────────────────────────────────────────────── */
  var input = root.querySelector('[data-help-q]');
  var data = document.getElementById('help-index');
  var say = root.querySelector('[data-help-say]');
  var cats = Array.prototype.slice.call(root.querySelectorAll('[data-help-cat]'));
  var index = [];
  try { index = data ? JSON.parse(data.textContent || '[]') : []; } catch (err) { index = []; }

  if (input && cats.length && index.length) {
    var keys = {};
    index.forEach(function (r) { keys[r.s] = r.k || ''; });
    var top = root.querySelector('[data-help-top]');
    var filter = function () {
      var q = input.value.trim().toLowerCase();
      var words = q.split(/\s+/).filter(function (w) { return w.length > 1; });
      var shown = 0;
      cats.forEach(function (card) {
        var any = false;
        card.querySelectorAll('[data-help-slug]').forEach(function (li) {
          var k = keys[li.getAttribute('data-help-slug')] || '';
          var hit = words.length === 0 ? !li.hasAttribute('data-help-more')
                                       : words.every(function (w) { return k.indexOf(w) > -1; });
          li.hidden = !hit;
          if (hit) { any = true; if (words.length) shown++; }
        });
        card.hidden = words.length > 0 && !any;
      });
      if (top) top.hidden = words.length > 0;
      if (say) {
        say.textContent = words.length === 0 ? ''
          : shown === 0 ? say.getAttribute('data-none')
          : shown === 1 ? say.getAttribute('data-one')
          : say.getAttribute('data-many').replace('%n%', String(shown));
      }
    };
    input.addEventListener('input', filter);
  }

  /* ── Article: copy link ─────────────────────────────────────────────────── */
  var copy = root.querySelector('[data-help-copy]');
  if (copy) {
    copy.hidden = false;
    copy.addEventListener('click', function () {
      var url = location.origin + copy.getAttribute('data-help-copy');
      var done = function () {
        copy.textContent = copy.getAttribute('data-done');
        window.setTimeout(function () { copy.textContent = copy.getAttribute('data-label'); }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(done, function () {});
      else {
        try {
          var t = document.createElement('textarea'); t.value = url; t.className = 'ag-sr';
          document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); done();
        } catch (err) {}
      }
    });
  }

  /* ── Article: "Yes, thanks" ─────────────────────────────────────────────── */
  var yes = root.querySelector('[data-help-yes]');
  if (yes) {
    yes.hidden = false;
    yes.addEventListener('click', function () {
      var ask = root.querySelector('[data-help-fb-ask]'), ok = root.querySelector('[data-help-fb-ok]');
      if (ask) ask.hidden = true;
      if (ok) { ok.hidden = false; }
    });
  }

  /* ── Article: highlight to ask ──────────────────────────────────────────────
     Four guards, each from a bug the old page shipped: a trivial selection (one tapped
     word) is not a question; BOTH ends must be inside the answer, or a drag that
     overshoots quotes site furniture; the pill is clamped into the viewport and flips
     below the selection when there is no room above; it follows the selection on scroll
     and resize and is dropped once the selection leaves the screen. Capped at 240. */
  var answer = root.querySelector('[data-help-answer]');
  var summary = root.querySelector('.ha__sum');
  var pill = document.querySelector('[data-help-pill]');
  var pillA = pill && pill.querySelector('[data-help-pill-a]');
  if (answer && pill && pillA) {
    var MIN = 12, CAP = 240, sel = '';
    var inside = function (node) {
      if (!node) return false;
      var el = node.nodeType === 1 ? node : node.parentNode;
      return !!el && (answer.contains(el) || (summary && summary.contains(el)));
    };
    var hide = function () { pill.hidden = true; sel = ''; };
    var place = function () {
      var s = window.getSelection();
      if (!s || s.rangeCount === 0 || s.isCollapsed) { hide(); return; }
      if (!inside(s.anchorNode) || !inside(s.focusNode)) { hide(); return; }
      var text = String(s).replace(/\s+/g, ' ').trim();
      if (text.length < MIN) { hide(); return; }
      sel = text.slice(0, CAP);
      var r = s.getRangeAt(0).getBoundingClientRect();
      var vw = window.innerWidth, vh = window.innerHeight;
      if (r.bottom < 0 || r.top > vh) { hide(); return; }
      var x = Math.min(Math.max(r.left + r.width / 2, 90), vw - 90);
      var below = r.top < 64;
      var y = below ? Math.min(r.bottom + 10, vh - 54) : r.top - 10;
      pill.classList.toggle('is-below', below);
      pill.style.left = x + 'px';
      pill.style.top = y + 'px';
      pillA.setAttribute('href', '/help?gee=support&q=' + encodeURIComponent(sel));
      pill.hidden = false;
    };
    document.addEventListener('selectionchange', function () { window.requestAnimationFrame(place); });
    var main = document.querySelector('.ag-main');
    if (main) main.addEventListener('scroll', function () { if (!pill.hidden) place(); }, { passive: true });
    window.addEventListener('resize', function () { if (!pill.hidden) place(); });
    pillA.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the selection
    pillA.addEventListener('click', function (e) {
      if (gee(sel, pillA)) { e.preventDefault(); hide(); }
    });
  }
})();
