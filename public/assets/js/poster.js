/* The poster's one control (pages/vote-poster.twig): print. A page of its own, outside the
   shell, so site-nav never swaps it and this never has to run twice. */
(function () {
  'use strict';
  var b = document.querySelector('[data-po-print]');
  if (b) b.addEventListener('click', function () { window.print(); });
})();
