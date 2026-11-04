/* Shared focus and overflow-region behaviour. Preferences remain in a11y.js. */
(function () {
  'use strict';
  var observers = [];
  var updates = [];

  function scrollRegions(root) {
    observers.forEach(function (observer) { observer.disconnect(); });
    observers = [];
    updates = [];
    root.querySelectorAll('.ad-table-wrap, .an-scroll, [data-scroll-region]').forEach(function (region) {
      var table = region.querySelector('table');
      var original = {role: region.getAttribute('role'), label: region.getAttribute('aria-label')};
      function update() {
        var overflows = region.scrollWidth > region.clientWidth + 1;
        if (overflows && !region.hasAttribute('tabindex')) {
          region.tabIndex = 0;
          region.dataset.a11yScroll = 'true';
          if (!original.role) region.setAttribute('role', 'region');
          var heading = region.closest('.an-panel,.ad-card,section');
          heading = heading && heading.querySelector('h2,h3,.ad-card__title');
          var name = table && table.caption ? table.caption.textContent.trim() : heading ? heading.textContent.trim() : 'Scrollable table';
          if (!original.label && !region.hasAttribute('aria-labelledby')) region.setAttribute('aria-label', name);
        } else if (!overflows && region.dataset.a11yScroll) {
          region.removeAttribute('tabindex');
          if (!original.role) region.removeAttribute('role');
          if (!original.label) region.removeAttribute('aria-label');
          delete region.dataset.a11yScroll;
        }
      }
      updates.push(update);
      update();
      if (window.ResizeObserver) {
        var observer = new ResizeObserver(update);
        observer.observe(region);
        if (table) observer.observe(table);
        observers.push(observer);
      }
    });
  }

  function load(root) {
    scrollRegions(root);
    var error = root.querySelector('[data-feedback-error]');
    if (error) {
      error.tabIndex = -1;
      error.focus();
    }
  }
  document.addEventListener('click', function (event) {
    var link = event.target.closest('[data-skip-link]');
    if (!link) return;
    var target = document.getElementById(link.hash.slice(1));
    if (target) {
      event.preventDefault();
      target.tabIndex = -1;
      target.focus();
    }
  });
  window.addEventListener('resize', function () { updates.forEach(function (update) { update(); }); });
  load(document);
  document.addEventListener('cn:load', function (event) { load(event.detail && event.detail.root || document); });
  document.addEventListener('cn:unload', function () {
    observers.forEach(function (observer) { observer.disconnect(); });
    observers = [];
    updates = [];
  });
})();
