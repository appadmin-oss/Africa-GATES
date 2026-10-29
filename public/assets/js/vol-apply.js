/* /volunteer/apply — one step at a time (templates/volunteer/apply.twig).
 *
 * Without this file every step is on the page and the last one sends; with it, one step shows,
 * Next checks only that step's required fields, the step bar is the progress, focus moves to the
 * new step's heading and the live region says where the person is. Nothing is sent until the
 * last step: the server checks everything again and is the only judge.
 */
(function () {
  'use strict';
  var root = document.body, form = document.querySelector('[data-ap-form]');
  if (!form) return;
  var steps = Array.prototype.slice.call(form.querySelectorAll('.ap-step'));
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-ap-tab]'));
  var live = document.getElementById('apLive');
  var at = 0;

  function show(i, focus) {
    at = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, n) { s.classList.toggle('is-on', n === at); });
    tabs.forEach(function (t, n) {
      if (n === at) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
      t.classList.toggle('is-done', n < at);
    });
    var h = steps[at].querySelector('.ap-step__h');
    if (live) live.textContent = 'Step ' + (at + 1) + ' of ' + steps.length + ': ' + (h ? h.lastChild.textContent : '');
    if (focus && h) { h.focus(); h.scrollIntoView({ block: 'start' }); }
  }

  /* Only the step's own required fields; a missing one is named, focused and marked. */
  function valid(step) {
    var bad = null;
    step.querySelectorAll('[required]').forEach(function (f) {
      var empty = f.type === 'checkbox' ? !f.checked : !String(f.value || '').trim();
      f.setAttribute('aria-invalid', empty ? 'true' : 'false');
      if (empty && !bad) bad = f;
    });
    if (step.id === 'ap-help' && !step.querySelector('input[name="interests[]"]:checked')) bad = bad || step.querySelector('input[name="interests[]"]');
    if (bad) {
      bad.focus();
      var label = bad.closest('label, fieldset');
      var name = label ? (label.querySelector('.ap-f__l, legend') || label).textContent.replace(/\s+/g, ' ').trim() : 'this';
      if (live) live.textContent = 'Please fill in: ' + name;
    }
    return !bad;
  }

  /* Under the guardian age, the guardian box shows; otherwise it is not asked. */
  var dob = form.querySelector('[data-ap-dob]'), guard = form.querySelector('[data-ap-guardian]');
  function guardian() {
    if (!dob || !guard) return;
    var d = new Date(dob.value), under = parseInt(dob.getAttribute('data-guardian-under'), 10) || 18;
    var age = isNaN(d) ? 99 : (Date.now() - d.getTime()) / (365.25 * 864e5);
    guard.hidden = !(age < under);
  }
  if (dob) { dob.addEventListener('change', guardian); guardian(); }

  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ap-next], [data-ap-back]');
    if (!b) return;
    if (b.hasAttribute('data-ap-back')) return show(at - 1, true);
    if (valid(steps[at])) show(at + 1, true);
  });
  tabs.forEach(function (t, n) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      // Back freely; forward only past steps that are complete.
      for (var i = at; i < n; i++) if (!valid(steps[i])) return show(i, false);
      show(n, true);
    });
  });
  form.addEventListener('submit', function (e) {
    for (var i = 0; i < steps.length; i++) if (!valid(steps[i])) { e.preventDefault(); show(i, false); valid(steps[i]); return; }
  });

  root.classList.add('is-stepped');
  // A refused application opens on the errors, which name what to fix; otherwise on step one.
  var errs = document.getElementById('apErrors');
  show(0, false);
  if (errs) errs.focus();
})();
