/**
 * The Volunteer academy as a course (/volunteer/academy): one lesson at a time, an outline
 * with ticks, and progress that survives a reload.
 *
 * The page works without this file — every lesson is drawn in order and the outline is a
 * list of anchors — so this only hides what is not the current lesson. The URL's hash is the
 * state, so a lesson link sent in a group chat opens on that lesson.
 *
 * Progress is a convenience, never a record: `ag-academy` (CookieRegistry) holds the ids of
 * lessons marked done, in localStorage only when the visitor allows Preferences
 * (`data-ag-keep`, the same answer every other store here reads), otherwise in this tab only.
 */
(function () {
  'use strict';
  var root = document.documentElement;
  var lessons = Array.prototype.slice.call(document.querySelectorAll('[data-lesson]'));
  if (!lessons.length) return;
  var KEY = 'ag-academy';
  var store;
  try { store = root.getAttribute('data-ag-keep') === '1' ? window.localStorage : window.sessionStorage; } catch (e) { store = null; }
  var done = {};
  try { (JSON.parse((store && store.getItem(KEY)) || '[]') || []).forEach(function (id) { done[id] = true; }); } catch (e) {}
  var counted = lessons.filter(function (l) { var id = l.getAttribute('data-lesson'); return id !== 'welcome' && id !== 'done'; });

  function save() { try { if (store) store.setItem(KEY, JSON.stringify(Object.keys(done))); } catch (e) {} }

  function paint(current) {
    var n = counted.filter(function (l) { return done[l.getAttribute('data-lesson')]; }).length;
    var prog = document.querySelector('[data-ac-progress]');
    if (prog) {
      prog.hidden = false;
      prog.querySelector('[data-ac-count]').textContent = String(n);
      var bar = prog.querySelector('[role="progressbar"]');
      bar.setAttribute('aria-valuenow', String(n));
      prog.querySelector('[data-ac-fill]').style.width = Math.round(100 * n / counted.length) + '%';
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-ac-link]'), function (a) {
      var id = a.getAttribute('data-ac-link');
      a.classList.toggle('is-done', !!done[id]);
      if (id === current) a.setAttribute('aria-current', 'step'); else a.removeAttribute('aria-current');
    });
  }

  function show(id, focus) {
    var target = null;
    lessons.forEach(function (l) { if (l.getAttribute('data-lesson') === id) target = l; });
    if (!target) target = lessons[0];
    lessons.forEach(function (l) { l.hidden = l !== target; });
    paint(target.getAttribute('data-lesson'));
    if (focus) {
      var h = target.querySelector('h1, h2');
      if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
      window.scrollTo(0, 0);
    }
  }

  root.classList.add('ac--js');
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('[data-ac-done]');
    if (a) { done[a.getAttribute('data-ac-done')] = true; save(); }
    var link = ev.target.closest && ev.target.closest('[data-ac-link]');
    var outline = document.querySelector('[data-ac-outline]');
    if (link && outline && window.matchMedia('(max-width: 899px)').matches) outline.open = false;
  });
  window.addEventListener('hashchange', function () { show(location.hash.slice(1), true); });

  var outline = document.querySelector('[data-ac-outline]');
  if (outline && window.matchMedia('(max-width: 899px)').matches) outline.open = false;
  show(location.hash.slice(1) || 'welcome', false);
})();
