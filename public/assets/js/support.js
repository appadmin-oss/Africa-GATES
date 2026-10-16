/* /support — the appeal composer (Phase 9). Builds a mailto: to the appeals inbox from
   the fields and hands it to the reader's own mail app; nothing is submitted from the
   page, and the page says so before the press. Without this script the form's own
   `action="mailto:"` still opens a message, and the address is printed as a link. */
(function () {
  'use strict';
  var f = document.querySelector('[data-sp-compose]');
  if (!f) return;
  var hint = document.querySelector('[data-sp-hint]');
  f.addEventListener('change', function (e) {
    var r = e.target;
    if (r && r.name === 'reason' && hint) hint.textContent = r.getAttribute('data-hint') || '';
  });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!f.reportValidity()) return;
    var v = function (n) { var el = f.elements[n]; return el ? String(el.value || '').trim() : ''; };
    var reason = (f.querySelector('input[name="reason"]:checked') || {}).value || '';
    var body = f.getAttribute('data-l-reason') + ': ' + reason + '\r\n'
             + f.getAttribute('data-l-name') + ': ' + v('name') + ' <' + v('email') + '>\r\n'
             + f.getAttribute('data-l-ref') + ': ' + v('ref') + '\r\n\r\n' + v('body');
    var subject = f.getAttribute('data-subject').replace('%r%', reason);
    window.location.href = 'mailto:' + f.getAttribute('data-to')
      + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
  });
})();
