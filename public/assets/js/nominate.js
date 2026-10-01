/* ══════════════════════════════════════════════════════════════════════════════
   AFRICA GATES — THE NOMINATION FLOW
   Steps · the live reason counter · the category cap
   design/NominationFlow.dc.html · phase §8.16
   ══════════════════════════════════════════════════════════════════════════════

   EVERYTHING HERE IS AN ENHANCEMENT OVER A FORM THAT ALREADY WORKS. Every field is
   in the document from the first render and the whole thing posts to `/nominate`,
   so with this file absent or broken a nominator sees one long form, fills it in,
   and submits it — and the server applies exactly the same rules in exactly the
   same words. A five-screen wizard that exists only in JavaScript is a nomination
   form that does not work for the people this platform is most trying to reach.

   That shapes two decisions below that would otherwise look odd:

   · The counter is drawn here and the RULE is the server's. `data-min` carries the
     number from `NominationRules::MIN_REASON` through the template, so there is no
     40 in this file. A literal here would be a second copy of a rule, and a form
     that counts to one number while the server refuses by another is the shape this
     codebase keeps paying for.

   · Nothing is ever disabled for being incomplete. A greyed-out Continue with no
     stated reason is the pattern that makes somebody close the tab; the button
     always works and says what is missing when it cannot go forward.

   Classic script, `defer`, no module — the reason shell.js gives.
   ══════════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  var form = document.querySelector('[data-nf]');
  if (!form) return;

  function all(sel, root) { return Array.prototype.slice.call((root || form).querySelectorAll(sel)); }

  var steps  = all('[data-nf-step]');
  var rail   = form.querySelector('[data-nf-rail]');
  var back   = form.querySelector('[data-nf-back]');
  var next   = form.querySelector('[data-nf-next]');
  var submit = form.querySelector('[data-nf-submit]');
  var main   = document.querySelector('.ag-main');
  if (!steps.length) return;

  var at = 1;
  var LAST = steps.length;

  /* ════════════════════════════════════════════════════════════════════════
     THE REASON COUNTER
     ════════════════════════════════════════════════════════════════════════ */

  /* "12/40", turning green at the floor. Counted in CHARACTERS through the string
     iterator and not `.length`, which counts UTF-16 code units: an emoji is 2 and
     several African orthographies combine marks, so `.length` would tell somebody
     writing in Yorùbá they had passed the floor before the server agreed. The
     server counts with `mb_strlen`, and these two have to be the same count or the
     form is lying about how close somebody is. */
  function chars(s) {
    var n = 0;
    for (var i = 0, it = String(s)[Symbol.iterator](); !it.next().done;) n++;
    return n;
  }

  function meter(box) {
    var ta  = box.querySelector('[data-nf-reason]');
    var out = box.querySelector('[data-nf-meter]');
    if (!ta || !out) return 0;

    var min = parseInt(out.getAttribute('data-min'), 10) || 0;
    var n   = chars(ta.value.trim());

    out.textContent = n + '/' + min;
    out.toggleAttribute('data-ok', n >= min);
    return n;
  }

  form.addEventListener('input', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-nf-reason')) {
      meter(e.target.closest('[data-nf-why]'));
    }
  });

  /* ════════════════════════════════════════════════════════════════════════
     THE CATEGORIES
     ════════════════════════════════════════════════════════════════════════ */

  /* A category's reason box is revealed by choosing it, and its textarea is
     DISABLED while hidden — so an unchosen category cannot post a reason. Without
     that, somebody who types a reason, changes their mind and unticks the box
     still sends `categories[7]`, and the server counts a category they did not
     choose. `disabled` is the only thing a browser will not submit; `hidden` alone
     submits perfectly well. */
  function syncCats() {
    var picks = all('[data-nf-pick]');
    var on    = 0;
    var max   = picks.length ? (parseInt(form.getAttribute('data-max-cats'), 10) || 3) : 0;

    picks.forEach(function (p) {
      var box = p.closest('[data-nf-cat]');
      var why = box && box.querySelector('[data-nf-why]');
      var ta  = why && why.querySelector('[data-nf-reason]');
      if (!why || !ta) return;

      why.hidden  = !p.checked;
      ta.disabled = !p.checked;
      if (p.checked) { on++; meter(why); }
    });

    /* At the cap, the unchosen boxes are stopped rather than hidden — a category
       that vanishes when you pick three reads as the list being broken, and
       somebody looking for the one they wanted cannot find it to swap. */
    picks.forEach(function (p) {
      var over = !p.checked && on >= max;
      p.disabled = over;
      var box = p.closest('[data-nf-cat]');
      if (box) box.toggleAttribute('data-maxed', over);
    });

    var count = form.querySelector('[data-nf-cats]');
    if (count) {
      count.textContent = on + ' of ' + max + ' chosen'
        + (on >= max ? ' — remove one to swap' : '');
    }
    return on;
  }

  form.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-nf-pick')) syncCats();
  });

  /* ════════════════════════════════════════════════════════════════════════
     THE NOMINEE KIND
     ════════════════════════════════════════════════════════════════════════ */

  /* "Full name" is wrong for a registered body and "Registered name" is wrong for a
     person, so the label and the hint follow the chip. The WORDS come from the
     server through data attributes rather than being written here, because
     `Support\NomineeKind` is where the three kinds are declared and a second copy
     in a script is a second copy. */
  var KIND_WORDS = {
    person:       ['Full name', 'As they would write it themselves.'],
    organisation: ['Registered name', 'The name on its registration, not its trading name.'],
    business:     ['Registered name', 'The name the business is registered under.']
  };

  /* "Why {name} for {category}?" — the award's own sentence, with the nominee in it
     once there is one. The TEMPLATE is the award's (`data-nf-tpl`, from
     `Support\AwardWording`); this only fills the two placeholders, and it fills them
     the same way `AwardWording::reasonQuestion()` does on the server, because the
     admin's preview and this form have to show one sentence. Falls back to "them",
     which is what the page renders before anybody has typed. */
  function nameForQuestion() {
    var el = form.querySelector('[name="nominee_name"]');
    var v  = el ? el.value.trim() : '';
    return v !== '' ? v.split(/\s+/)[0] : 'them';
  }

  function retitleReasons() {
    var who = nameForQuestion();
    all('[data-nf-why-label]').forEach(function (l) {
      var tpl = l.getAttribute('data-nf-tpl') || '';
      var cat = l.getAttribute('data-nf-cat-title') || '';
      if (!tpl) return;
      l.textContent = tpl.replace('{name}', who).replace('{category}', cat);
    });
  }

  form.addEventListener('input', function (e) {
    if (e.target.name === 'nominee_name') retitleReasons();
  });

  form.addEventListener('change', function (e) {
    if (!e.target.hasAttribute || !e.target.hasAttribute('data-nf-kind')) return;
    var w = KIND_WORDS[e.target.value];
    if (!w) return;
    var label = form.querySelector('[data-nf-name-label]');
    var hint  = form.querySelector('[data-nf-name-hint]');
    if (label) label.textContent = w[0];
    if (hint)  hint.textContent  = w[1];
  });

  /* ════════════════════════════════════════════════════════════════════════
     THE PORTRAIT
     ════════════════════════════════════════════════════════════════════════ */

  /* A file input that says nothing after a choice is a file input somebody presses
     twice. The label is the only visible part, so the chosen name goes there — and
     the FILE NAME rather than a tick, because "that is not the photo I meant" is the
     thing a person needs to be able to see. */
  var port = form.querySelector('[data-nf-port]');
  if (port) {
    port.addEventListener('change', function () {
      var slot  = form.querySelector('.nf__port-slot');
      var label = form.querySelector('[data-nf-port-label]');
      var f     = port.files && port.files[0];
      if (label) label.textContent = f ? f.name : 'Add a photo';
      if (slot) slot.toggleAttribute('data-has', !!f);
    });
  }

  /* ════════════════════════════════════════════════════════════════════════
     EVIDENCE LINKS
     ════════════════════════════════════════════════════════════════════════ */

  /* The fields all exist; "Add another" reveals the next one. Building inputs
     would mean this file deciding the field name, and `evidence_links[]` is the
     server's contract — one place, in the template, next to everything else the
     form posts. */
  var addLink = form.querySelector('[data-nf-add-link]');
  if (addLink) {
    addLink.addEventListener('click', function () {
      var hiddenOne = form.querySelector('.nf__link[hidden]');
      if (hiddenOne) {
        hiddenOne.hidden = false;
        var input = hiddenOne.querySelector('input');
        if (input) input.focus();
      }
      if (!form.querySelector('.nf__link[hidden]')) addLink.hidden = true;
    });
  }

  /* ════════════════════════════════════════════════════════════════════════
     THE STEPS
     ════════════════════════════════════════════════════════════════════════ */

  /* What is missing before this step can be left — or null.
     The sentences are the server's, near enough that nobody meets two different
     accounts of one rule; the server's copy is what arrives if this never runs. */
  /* THE STEP'S OWN REQUIRED FIELDS, READ FROM THE MARKUP.
     The server has a list of required field names in `NominationController::submit()`
     and the template marks the same fields `required`. A THIRD copy in this file is
     how they come apart — and they had: `nominee_state` and `nominee_lga` are
     required by the server and were not checked here, so somebody could complete all
     four steps and be refused on a step-one field, with the message appearing on
     step four beside the Submit button. Measured end to end.

     Reading `[required]` inside the panel means there is no list here at all: a field
     added to a step is covered the day it is added, and the browser, this script and
     the server are looking at the same declaration. The message names the field from
     its own visible label, so it is never a sentence somebody has to map onto a box. */
  function missingRequired(step) {
    var panel = form.querySelector('[data-nf-step="' + step + '"]');
    if (!panel) return null;

    var fields = panel.querySelectorAll('[required]');
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      if (f.disabled) continue;
      var empty = f.type === 'checkbox' || f.type === 'radio' ? !f.checked : !String(f.value).trim();
      if (!empty) continue;

      var lab = f.id ? panel.querySelector('label[for="' + f.id + '"]') : null;
      if (!lab) lab = f.closest('label');
      var what = lab ? lab.textContent.trim().replace(/\s+/g, ' ') : '';

      try { f.focus(); } catch (e) { /* a hidden panel cannot take focus yet */ }
      return what ? 'Please fill in “' + what + '”.' : 'Please fill in the rest of this step.';
    }
    return null;
  }

  function blocking(step) {
    /* The markup's own rules first, then the ones markup cannot express. */
    var missing = missingRequired(step);

    if (step === 1) {
      var name = form.querySelector('[name="nominee_name"]');
      if (!name || !name.value.trim()) return 'Please tell us who you are nominating.';

      var kindEl = form.querySelector('[name="nominee_kind"]:checked')
                || form.querySelector('[name="nominee_kind"]');
      var kind = kindEl ? kindEl.value : 'person';
      // A person needs a first and a last name; "Andela" is a whole registered name.
      // Demanding a second word of an organisation is the browser being stricter than
      // the server, which is the worse of the two ways to disagree with it.
      if (kind === 'person' && name.value.trim().split(/\s+/).length < 2) {
        return "Please enter the nominee's full name — first and last name.";
      }
      var country = form.querySelector('[name="country_code"]');
      if (country && !country.value) return 'Please choose their country.';

      /* ONE OF THE TWO, which is a rule HTML cannot state. `required` on both would
         demand both; on neither it demands nothing — so this is the one step-one
         rule that has to be written out, and it was missing. The server refuses in
         these words and somebody met them four steps later, beside Submit, about a
         box at the top of the first screen. */
      var mail = form.querySelector('[name="nominee_email"]');
      var tel  = form.querySelector('[name="nominee_phone"]');
      if (mail && tel && !mail.value.trim() && !tel.value.trim()) {
        mail.focus();
        return 'Please give their email address or phone number — we need one of the '
             + 'two so we can tell them.';
      }

      return missing;
    }

    if (step === 2) {
      var picks = all('[data-nf-pick]');
      if (!picks.length) return null;           // an award with no categories yet
      var on = picks.filter(function (p) { return p.checked; }).length;
      var min = parseInt(form.getAttribute('data-min-cats'), 10) || 2;
      if (on < min) {
        return 'Choose ' + min + ' or more categories — at least two, so we can see where '
             + 'the work fits best.';
      }
      var short = all('[data-nf-why]').filter(function (w) {
        return !w.hidden && meter(w) < (parseInt(
          w.querySelector('[data-nf-meter]').getAttribute('data-min'), 10) || 0);
      });
      if (short.length) {
        var first = short[0].querySelector('[data-nf-reason]');
        if (first) first.focus();
        // The fallback carries NO NUMBER. A `|| 'at least 40 characters'` here would
        // be the fifth typed copy of a rule, taking over silently the day the
        // template stops passing the attribute — which is exactly how a screen comes
        // to state a window nothing else enforces. This one says the right thing at
        // any floor.
        return form.getAttribute('data-short-reason')
            || 'Please write a little more in each reason.';
      }
      return missing;
    }

    return missing;
  }

  function show(step, announce) {
    at = Math.max(1, Math.min(LAST, step));

    steps.forEach(function (s) {
      s.hidden = parseInt(s.getAttribute('data-nf-step'), 10) !== at;
    });

    if (rail) {
      all('[data-nf-dot]', rail).forEach(function (d) {
        var i = parseInt(d.getAttribute('data-nf-dot'), 10);
        // `aria-current="step"` on exactly one, and `data-done` behind it — a rail
        // where every passed step also claims to be current tells a screen-reader
        // user they are in four places at once.
        if (i === at) d.setAttribute('aria-current', 'step');
        else d.removeAttribute('aria-current');
        d.toggleAttribute('data-done', i < at);
      });
    }

    /* §8's summary: where you are, and what this step wants. The step names live
       here and in the rail; the rail is the markup's and this reads FROM it, so the
       two cannot drift and nothing in this file spells a step name. */
    var sumV = form.querySelector('[data-nf-sum-v]');
    var sumD = form.querySelector('[data-nf-sum-d]');
    if (sumV) sumV.textContent = 'Step ' + at + ' of ' + LAST;
    if (sumD) {
      var panel2 = form.querySelector('[data-nf-step="' + at + '"]');
      var head2  = panel2 && panel2.querySelector('h1, h2');
      /* The heading's own words only. "Add evidence" carries an `Optional` pill
         inside the <h2>, and `textContent` glues them into "Add evidence Optional". */
      var own = '';
      if (head2) {
        for (var k = 0; k < head2.childNodes.length; k++) {
          if (head2.childNodes[k].nodeType === 3) own += head2.childNodes[k].textContent;
        }
        own = own.trim() || head2.textContent.trim();
      }
      sumD.textContent = own;
    }

    /* These three run on boot as well as on every step change, which is what turns
       the server's long form into a wizard — and is why the markup ships in the
       no-script shape rather than this one. */
    if (back)   back.hidden   = at === 1;
    if (next)   next.hidden   = at === LAST;
    if (submit) submit.hidden = at !== LAST;

    if (main) main.scrollTop = 0;

    /* Focus moves to the new step's heading. A wizard that swaps its panel and
       leaves focus on the Continue button it just moved is a wizard a screen-reader
       user cannot follow — they hear nothing change. */
    if (announce) {
      var panel = form.querySelector('[data-nf-step="' + at + '"]');
      var head  = panel && panel.querySelector('h1, h2');
      if (head) {
        head.setAttribute('tabindex', '-1');
        head.focus();
      }
    }
  }

  /* The message goes INSIDE the step it is about, under its heading — not at the top
     of the form. Above the rail it pushed the whole page down, so the thing somebody
     was looking at moved while they were reading why; and it sat four elements away
     from the field it was describing. `role="alert"` is on the element either way, so
     a screen reader hears it the moment it appears. */
  function say(msg) {
    var panel = form.querySelector('[data-nf-step="' + at + '"]');
    if (!panel) return;

    var box = panel.querySelector('[data-nf-said]');
    if (!box) {
      box = document.createElement('p');
      box.className = 'nf__err';
      box.setAttribute('role', 'alert');
      box.setAttribute('data-nf-said', '');
      var head = panel.querySelector('h1, h2');
      var lede = head && head.nextElementSibling;
      panel.insertBefore(box, lede && lede.classList.contains('nf__lede')
        ? lede.nextSibling : (head ? head.nextSibling : panel.firstChild));
    }
    box.textContent = msg;
    box.hidden = false;
  }

  function clearSaid() {
    all('[data-nf-said]').forEach(function (b) { b.hidden = true; });
  }

  if (next) {
    next.addEventListener('click', function () {
      var why = blocking(at);
      if (why) { say(why); return; }
      clearSaid();
      show(at + 1, true);
    });
  }
  if (back) {
    back.addEventListener('click', function () { clearSaid(); show(at - 1, true); });
  }

  /* The last step's Submit is a real submit button, so Enter works from any field
     and the browser's own required-field messages still appear. What this adds is
     the step-2 rules, which the browser cannot express: it will happily submit a
     form whose hidden panel holds a 12-character reason. */
  form.addEventListener('submit', function (e) {
    for (var s = 1; s <= LAST; s++) {
      var why = blocking(s);
      if (why) {
        e.preventDefault();
        show(s, true);
        say(why);
        return;
      }
    }

    /* SHOWN ONLY ONCE THE FORM IS ACTUALLY GOING — after every rule has passed and
       nothing has called preventDefault. Put on the press instead, it would sit
       spinning over a submission the step-2 rules had just refused, which is a page
       that lies about what it is doing.

       This is the one state a nomination needs most and had none of: the last step
       posts a file upload, and over a slow connection a form with no sign of life is
       a form somebody presses again. The button is disabled with it, because a
       second POST is a second nomination. */
    var busy = form.querySelector('[data-nf-busy]');
    if (busy) busy.hidden = false;
    if (submit) submit.disabled = true;
  });

  /* ════════════════════════════════════════════════════════════════════════ */

  /* THE FORM BECOMES A WIZARD HERE, and the attribute is what says so to the
     stylesheet. Everything that only makes sense with four screens — the fixed
     thumb-zone bar and the clearance under it — hangs off this, so the page the
     server sent stays a working long form for anybody the script never reaches. */
  form.setAttribute('data-nf-wizard', '');

  syncCats();
  retitleReasons();
  all('[data-nf-why]').forEach(meter);
  show(1, false);
})();
