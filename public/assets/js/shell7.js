/* The v7 shell's small behaviours (docs/handoff/consoles-v7/README §2.4). Everything works
   without this file: forms post, links are links. It adds three things on top:
     · a toast leaves on its own after a few seconds (and stays while hovered or focused);
     · a button with data-sh7-confirm asks first, since a dropped shift or leaving is hard to undo;
     · Escape closes the phone's More sheet and returns focus to its button. */
(function () {
  'use strict';
  var toast = document.querySelector('[data-sh7-toast]');
  if (toast) {
    var held = false;
    toast.addEventListener('mouseenter', function () { held = true; });
    toast.addEventListener('mouseleave', function () { held = false; });
    setTimeout(function hide() {
      if (held) { setTimeout(hide, 1500); return; }
      toast.classList.add('is-gone');
      setTimeout(function () { toast.remove(); }, 400);
    }, 5200);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sh7-confirm]');
    if (b && !window.confirm(b.getAttribute('data-sh7-confirm'))) e.preventDefault();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.sh7-more[open]');
    if (open) { open.removeAttribute('open'); var s = open.querySelector('summary'); if (s) s.focus(); }
  });
})();
