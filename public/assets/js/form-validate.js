/* ══════════════════════════════════════════════════════════════════════════════
   FORM VALIDATION · the client half, which is never the authority
   ══════════════════════════════════════════════════════════════════════════════

   The server decides. This exists so somebody does not wait for a round trip to be
   told they left the email out, and it is written so that switching it off changes
   nothing about which forms are accepted.

   FIVE RULES, EACH ONE A FAULT SOMEBODY SHIPPED SOMEWHERE

   1. NEVER VALIDATE ON KEYSTROKE. "a@" is not a valid address and neither is "a@e"
      — a field that goes red while somebody is still typing their address is telling
      them they are wrong for being slow. Validation happens on BLUR, and after that
      first blur a field that is already in error clears on input, because then the
      red is a thing they are actively fixing.

   2. NEVER BLOCK SUBMIT ON A RULE THE SERVER DOES NOT HAVE. The attributes read here
      are the ones the markup already carries for assistive technology — `required`,
      `minlength`, `type`, `pattern` — and the macros only emit those where the server
      enforces the same thing. A stricter check here is worse than no check: it
      refuses a valid answer with no way to argue, which is how `\s*\S+\s+\S+\s*`
      came to reject *Ngozi Chimamanda Adichie*.

   3. THE BUBBLE STAYS OFF. `novalidate` is on the form; this draws the message in the
      page where it can be styled, translated, and read by the same summary the server
      renders. One appearance for a client-side and a server-side error, or the person
      learns two different vocabularies for the same mistake.

   4. FOCUS GOES TO THE FIRST BAD FIELD, ONCE. Not to every bad field in turn, and not
      to the summary on every keystroke.

   5. IT MUST SURVIVE BEING WRONG. Anything unexpected and the handler gets out of the
      way and lets the form post — the server will catch it. A validator that throws
      must not be the reason a nomination cannot be submitted.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var SUMMARY = '[data-ag-form-summary]';

  /* The one place a rule is checked. Returns a message or ''. */
  function failure(el) {
    var v = (el.value == null ? '' : String(el.value));
    var trimmed = v.trim();
    var label = fieldLabel(el);

    if (el.hasAttribute('required') && trimmed === '') {
      return label + ' is needed.';
    }

    /* Everything below is about a value somebody has actually typed. An empty
       optional field is finished, not wrong. */
    if (trimmed === '') return '';

    var min = parseInt(el.getAttribute('minlength') || '0', 10);
    if (min > 0 && trimmed.length < min) {
      var short = min - trimmed.length;
      return short + ' more character' + (short === 1 ? '' : 's') + ' — ' + min + ' at least.';
    }

    var type = (el.getAttribute('type') || '').toLowerCase();

    /* Deliberately loose, and loose in the direction that costs nothing: something,
       an @, something, a dot, something. The server checks properly. A client-side
       address regex that is stricter than the server is a tooltip refusing a real
       address. */
    if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(trimmed)) {
      return 'That does not look like an email address.';
    }

    /* A phone is digits, spaces, dashes, parens and an optional leading +. The
       country resolution and the E.164 rule belong to the server, which has the
       country; this only catches letters and obvious nonsense. */
    if (type === 'tel' && (/[a-z]/i.test(trimmed) || trimmed.replace(/\D/g, '').length < 7)) {
      return 'That does not look like a phone number.';
    }

    if (type === 'url' && !/^https?:\/\/[^\s.]+\.[^\s]{2,}$/i.test(trimmed)) {
      return 'Start the link with http:// or https://';
    }

    var pattern = el.getAttribute('pattern');
    if (pattern) {
      try {
        if (!new RegExp('^(?:' + pattern + ')$').test(v)) {
          /* The field's own hint is the only sentence that can explain a pattern.
             A generic "invalid format" tells somebody nothing they can act on. */
          return hintFor(el) || 'Please check this.';
        }
      } catch (e) { /* an un-compilable pattern is the server's problem, not a refusal */ }
    }

    return '';
  }

  function hasLabel(el) {
    return !!(el.id && document.querySelector('label[for="' + cssEscape(el.id) + '"]'))
      || !!el.closest('label')
      || !!el.getAttribute('aria-label');
  }

  function fieldLabel(el) {
    var l = (el.id && document.querySelector('label[for="' + cssEscape(el.id) + '"]'))
      || el.closest('label');
    if (!l) return (el.getAttribute('aria-label') || 'This').trim();
    /* The clone drops the "*" and the "Optional" chip, which would otherwise end up
       inside the sentence: "Full name * is needed." */
    var c = l.cloneNode(true);
    Array.prototype.forEach.call(c.querySelectorAll('.ag-label__req,.ag-label__opt,.ag-sr'),
      function (n) { n.remove(); });
    return (c.textContent || 'This').trim().replace(/[:：]\s*$/, '') || 'This';
  }

  function hintFor(el) {
    var h = el.id && document.getElementById(el.id + '-hint');
    return h ? (h.textContent || '').trim() : '';
  }

  function cssEscape(s) {
    return (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/"/g, '\\"');
  }

  /* ── drawing ─────────────────────────────────────────────────────────────── */

  function wrapperOf(el) {
    return el.tagName === 'TEXTAREA' ? el : (el.closest('.ag-field') || el);
  }

  /* Where the message goes.

     `.ag-fieldset` is the macro's wrapper, and requiring it was a bug: on a form built
     before the macros — which is most of them — this returned null, `show()` bailed,
     and the only thing the enhancement did was suppress the native bubble. A form with
     `novalidate` and no message is strictly worse than the bubble it replaced, and it
     would have failed silently on every page it was switched on for.

     So the wrapper is a preference, not a requirement: the control's own parent hosts
     the message otherwise. */
  function hostOf(el) {
    return el.closest('.ag-fieldset') || el.parentElement;
  }

  function show(el, message) {
    var id = el.id + '-err';
    var box = hostOf(el);
    if (!box) return;

    var p = document.getElementById(id);

    if (!message) {
      el.removeAttribute('aria-invalid');
      el.removeAttribute('data-invalid');
      wrapperOf(el).removeAttribute('data-invalid');
      if (p) p.remove();
      describe(el, id, false);
      return;
    }

    if (!p) {
      p = document.createElement('p');
      p.className = 'ag-err';
      p.id = id;
      /* The icon is built here rather than copied as innerHTML so the markup matches
         the macro exactly — two renderings of one state is two things to keep true. */
      p.innerHTML = '<svg class="ag-err__i" width="15" height="15" viewBox="0 0 24 24" fill="none"'
        + ' stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">'
        + '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v6M12 16.5v.5"></path></svg> ';
      box.appendChild(p);
    }

    /* textContent on a node that already holds the svg would wipe it, so the sentence
       is appended as its own text node and replaced in place. */
    var text = p.lastChild && p.lastChild.nodeType === 3 ? p.lastChild : p.appendChild(document.createTextNode(''));
    text.nodeValue = ' ' + message;

    el.setAttribute('aria-invalid', 'true');
    /* The CONTROL as well as its wrapper. `.ag-field` is the redesign's wrapper and
       most forms here predate it — `/account/register` styles the input itself with
       `ag-acct__input--err`. Marking only the wrapper left those forms announcing an
       error that was invisible on screen. `forms.css` styles `[data-invalid]` on the
       control directly, so every form shows the state whatever its class idiom. */
    el.setAttribute('data-invalid', '');
    wrapperOf(el).setAttribute('data-invalid', '');
    describe(el, id, true);
  }

  /* `aria-describedby` is a LIST. Replacing it wholesale drops the hint, which is the
     sentence explaining the rule they just broke. */
  function describe(el, id, on) {
    var ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    var at = ids.indexOf(id);

    if (on && at === -1) ids.push(id);
    if (!on && at !== -1) ids.splice(at, 1);

    if (ids.length) el.setAttribute('aria-describedby', ids.join(' '));
    else el.removeAttribute('aria-describedby');
  }

  function controls(form) {
    return Array.prototype.filter.call(
      form.querySelectorAll('input,select,textarea'),
      function (el) {
        return !el.disabled
          && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button'
          /* An id is required — `aria-describedby` has nothing to point at without
             one — and a control with no label has no sentence to put in the message,
             so it is left to the server rather than described as "This". */
          && el.id && hasLabel(el);
      });
  }

  /* ── the summary ─────────────────────────────────────────────────────────── */

  function summarise(form, failures) {
    var box = form.querySelector(SUMMARY);

    if (!failures.length) { if (box) box.hidden = true; return; }

    if (!box) {
      box = document.createElement('div');
      box.className = 'ag-formsum';
      box.setAttribute('role', 'alert');
      box.setAttribute('tabindex', '-1');
      box.setAttribute('data-ag-form-summary', '');
      form.insertBefore(box, form.firstChild);
    }

    var list = failures.map(function (f) {
      return '<li><a href="#' + f.el.id + '">' + escapeHtml(fieldLabel(f.el) + ': ' + f.message) + '</a></li>';
    }).join('');

    box.hidden = false;
    box.innerHTML = '<p class="ag-formsum__t">'
      + (failures.length === 1 ? 'There is one thing to fix' : 'There are ' + failures.length + ' things to fix')
      + '</p><ul class="ag-formsum__list">' + list + '</ul>';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ── wiring ──────────────────────────────────────────────────────────────── */

  function enhance(form) {
    if (form.hasAttribute('data-ag-validated')) return;
    form.setAttribute('data-ag-validated', '');

    controls(form).forEach(function (el) {
      el.addEventListener('blur', function (ev) {
        el.setAttribute('data-ag-touched', '');

        /* ── RULE 6, WHICH COST A WHOLE CLICK ────────────────────────────────
           Showing a message INSERTS an element, which pushes everything below it
           down. If the person is in the middle of clicking the submit button, the
           button moves out from under the pointer between mousedown and mouseup —
           so the browser generates no click at all, and the form never submits.

           Measured on `/account/register`, where the first field carries `autofocus`:
           the very first press of "Create account" did nothing whatever. No error, no
           submit, no console line. On a phone that is indistinguishable from a dead
           button, and the second press works, so it looks like a flaky site.

           The submit handler validates everything anyway, so there is nothing to gain
           by also doing it on the way out. `relatedTarget` is what the focus is moving
           TO, and it is null for a click on a non-focusable area, hence the guard. */
        var to = ev.relatedTarget;
        if (to && (to.type === 'submit' || to.tagName === 'BUTTON' || to.closest('button,[type=submit]'))) return;

        show(el, failure(el));
      });

      el.addEventListener('input', function () {
        /* …and after that, clearing is instant. Red that persists while somebody
           corrects it is red they stop reading. */
        if (el.hasAttribute('data-ag-touched') && el.getAttribute('aria-invalid') === 'true') {
          var still = failure(el);
          if (!still) show(el, '');
        }
      });
    });

    counters(form);

    form.addEventListener('submit', function (ev) {
      var failures = [];

      try {
        controls(form).forEach(function (el) {
          var m = failure(el);
          show(el, m);
          if (m) failures.push({ el: el, message: m });
        });
      } catch (e) {
        /* Rule 5. Let it post; the server is the authority anyway. */
        return;
      }

      if (failures.length) {
        ev.preventDefault();
        summarise(form, failures);

        var box = form.querySelector(SUMMARY);
        if (box) box.focus();
        /* Rule 4: one move, to the summary, which links onward. Jumping straight to
           the field would skip the count, and somebody with three mistakes would fix
           one and resubmit. */
        return;
      }

      /* Pressed, and going. `aria-disabled` rather than `disabled` so focus is not
         thrown to the top of the document mid-task; the guard below is what actually
         stops the second post. */
      var btn = form.querySelector('button[type="submit"],input[type="submit"]');
      if (btn) {
        if (btn.getAttribute('aria-disabled') === 'true') { ev.preventDefault(); return; }
        btn.setAttribute('aria-disabled', 'true');
        /* Released if the page is still here — a validation failure the server found,
           a back-button restore from the cache — or the form is dead to them. */
        window.setTimeout(function () { btn.removeAttribute('aria-disabled'); }, 12000);
      }
    });
  }

  function counters(form) {
    Array.prototype.forEach.call(form.querySelectorAll('[data-ag-count]'), function (out) {
      var id = out.id.replace(/-count$/, '');
      var el = document.getElementById(id);
      if (!el) return;

      var min = parseInt(out.getAttribute('data-ag-count') || '0', 10);
      var max = parseInt(out.getAttribute('data-ag-max') || '0', 10);

      var paint = function () {
        var n = el.value.trim().length;

        if (min && n < min) {
          out.setAttribute('data-state', 'short');
          out.textContent = (min - n) + ' more character' + (min - n === 1 ? '' : 's') + ' needed';
        } else if (max && n > max - 40) {
          out.setAttribute('data-state', 'near');
          out.textContent = (max - n) + ' left';
        } else {
          out.removeAttribute('data-state');
          /* Deliberately empty once the rule is met: a counter that keeps talking
             about a satisfied requirement is noise with an `aria-live` on it. */
          out.textContent = '';
        }
      };

      el.addEventListener('input', paint);
      paint();
    });
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-ag-validate]'), enhance);

    /* A summary the SERVER rendered takes focus on arrival, so somebody who was
       bounced back is told what happened rather than finding out by scrolling. */
    var served = document.querySelector('form[data-ag-validate] ' + SUMMARY);
    if (served && !served.hidden) served.focus();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
