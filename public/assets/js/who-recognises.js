/* "Who recognises" — turns the stacked audience panels into a tablist (WAI-ARIA tabs:
   click or arrow keys, Home/End). Without this script every panel stays visible. */
(function () {
  'use strict';
  var root = document.querySelector('[data-who]');
  if (!root) return;
  var list = root.querySelector('[data-who-tabs]');
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
  var panels = Array.prototype.slice.call(root.querySelectorAll('[data-who-panel]'));
  if (!list || !tabs.length) return;
  list.hidden = false;
  function pick(i, focus) {
    tabs.forEach(function (t, k) {
      var on = k === i;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      if (panels[k]) panels[k].hidden = !on;
    });
    if (focus) tabs[i].focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { pick(i, false); });
    t.addEventListener('keydown', function (e) {
      var rtl = document.documentElement.dir === 'rtl';
      var next = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[e.key];
      if (next) { e.preventDefault(); pick((i + next + tabs.length) % tabs.length, true); }
      else if (e.key === 'Home') { e.preventDefault(); pick(0, true); }
      else if (e.key === 'End') { e.preventDefault(); pick(tabs.length - 1, true); }
    });
  });
  pick(0, false);
})();
