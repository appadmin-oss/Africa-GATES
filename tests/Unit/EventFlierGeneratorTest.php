<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The generator's ten states, and the two rules about them that were caught in review.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE READS THE TEMPLATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The state machine itself was driven in a real browser — every stage entered, every
 * transition taken, the pressable buttons enumerated at each one. That is where the
 * behaviour is proved, and it cannot run in this suite: there is no JavaScript runtime here.
 *
 * What this file guards is the set of claims a later edit could quietly break and no PHP
 * test would notice: that all ten states exist at all, that Generating and Ready are
 * separate, that the fallback path is chosen by feature detection rather than by sniffing a
 * user agent, and that the copy the handoff fixed is the copy that ships.
 *
 * The handoff's own framing, about the first pass: "That is the demo, not the feature."
 */
final class EventFlierGeneratorTest extends TestCase
{
    private function tpl(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');
    }

    /** The template with its Twig comments removed. */
    private function body(): string
    {
        // Required, not tidiness: the generator's comments quote the strings and rules under
        // test — "wa.me", "Generating and Ready", the negative language — and a scan that
        // reads comments reports the correct implementation as broken. This exact mistake has
        // now been made three times in this suite, twice in Twig comments and once in a
        // JavaScript one, so BOTH kinds go.
        $s = (string) preg_replace('~\{#.*?#\}~s', '', $this->tpl());
        // `(?<![\w"'])` because `accept="image/*"` contains a literal `/*`, and without the
        // guard the strip ran from there to the next `*/` — swallowing the progressbar, the
        // share buttons and half the states, so four tests failed for something none of them
        // was about.
        $s = (string) preg_replace('~(?<![\w"\'])/\*[\s\S]*?\*/~', '', $s);

        // Line comments only where the line is nothing but a comment: `//` inside a string —
        // `https://…` — must survive, and this template is full of URLs.
        return (string) preg_replace('~^\s*//.*$~m', '', $s);
    }

    // ══ all ten states exist ═════════════════════════════════════════════════

    public function test_every_state_from_the_handoff_is_present(): void
    {
        $b = $this->body();

        foreach ([
            "stage === 'entry'"  => 'entry',
            "stage === 'frame'"  => 'reframe',
            "stage === 'busy'"   => 'generating',
            "stage === 'ready'"  => 'ready',
            "stage === 'failed'" => 'generation failed',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $b, $what . ' has no screen');
        }

        // The photo picker, the caption, and the past-event branch.
        $this->assertStringContainsString('id="flPhoto"', $b, 'no photo picker');
        $this->assertStringContainsString('id="flCap"', $b, 'no caption field');
        $this->assertStringContainsString('This event has finished', $b, 'no past-event branch');
        $this->assertStringContainsString('See what is coming up', $b,
            'a finished event must offer somewhere to go');
    }

    public function test_generating_and_ready_are_separate_screens(): void
    {
        $b = $this->body();

        // "A progress bar above an enabled Save the image is an interface offering an action
        // it cannot perform" — and merging them to save a screen is named in the handoff as
        // the thing that ships if nobody checks.
        $busy = $this->between($b, "stage === 'busy'", "stage === 'ready'");
        $this->assertNotSame('', $busy, 'the generating screen could not be located');
        $this->assertStringNotContainsString('Save the image', $busy);
        $this->assertStringNotContainsString('Copy the caption', $busy);
        $this->assertStringNotContainsString('Share it', $busy);
        // And the only thing pressable is Cancel.
        $this->assertSame(1, substr_count($busy, '<button'),
            'the generating screen has more than one button');
        $this->assertStringContainsString('Cancel', $busy);
    }

    public function test_progress_is_announced_and_not_only_animated(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('role="progressbar"', $b);
        // Indeterminate on purpose: the server does not report how far through a render it
        // is, and a bar with a made-up percentage is a lie with a number on it.
        $this->assertStringContainsString('role="status"', $b);
    }

    // ══ sharing is feature-detected, and never falls back to a link ═══════════

    public function test_the_share_path_is_chosen_by_asking_the_device(): void
    {
        $b = $this->body();

        // `navigator.canShare({files:[…]})` with a REAL File is the only honest test: the API
        // exists on desktop Chrome and refuses files, and exists on iOS Safari and accepts
        // them. Anything sniffing a user agent would get both wrong.
        $this->assertStringContainsString('navigator.canShare', $b);
        $this->assertMatchesRegularExpression('~canShare\(\{\s*files:~', $b);
        $this->assertStringContainsString('new File(', $b, 'canShare must be asked with a real File');
    }

    public function test_there_is_no_silent_fallback_to_a_wa_me_link(): void
    {
        // Named in the handoff as the thing not to do: wa.me cannot attach an image, so it
        // produces a message with no flier in it — which looks like the feature working.
        $this->assertStringNotContainsString('wa.me', $this->body());
    }

    public function test_the_two_step_path_says_why_there_are_two_steps(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('cannot hand an image straight to WhatsApp', $b);
        // Save is the PRIMARY there, not a secondary beside a share button that will not work.
        $ready = $this->between($b, '!canShareFile', 'Change something');
        $this->assertMatchesRegularExpression('~fl__btn--go[^>]*>\s*Save the image~', $ready);
    }

    // ══ the caption ══════════════════════════════════════════════════════════

    public function test_the_caption_goes_in_the_message_and_not_on_the_image(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('goes with the message, not on the image', $b);
        // And it reaches navigator.share as text, not as anything drawn.
        $this->assertMatchesRegularExpression('~share\(\{\s*files:\s*\[file\],\s*text:\s*this\.caption~', $b);
    }

    public function test_the_caption_is_prefilled_with_a_blank_first_line(): void
    {
        $b = $this->body();

        // A fully prefilled caption gets sent verbatim and reads like an ad; an empty one gets
        // sent with no context. Blank first line, everything else supplied.
        $this->assertMatchesRegularExpression("~this\.caption = '\\\\n' \\+ this\.title~", $b);
    }

    // ══ the photo, and the promise made about it ══════════════════════════════

    public function test_the_reason_to_trust_the_upload_is_at_the_point_of_upload(): void
    {
        $b = $this->body();

        // Not in a policy page. Asking somebody to upload a photo of their face to an events
        // site needs a stated reason, at the moment of asking.
        // Whitespace collapsed: the sentence wraps in the source, and asserting against the
        // raw bytes would be asserting against where the line happens to break.
        $picker = (string) preg_replace('~\s+~', ' ',
            $this->between($b, 'id="flPhoto"', 'id="flCap"'));

        $this->assertStringContainsString('throw the photo away', $picker);
        $this->assertStringContainsString('never saved on our server', $picker);
        $this->assertStringContainsString('optional', strtolower($picker));

        // ── AND IT SAYS THE FACE IS LOOKED AT ────────────────────────────────
        //
        // FaceFinder reads the picture to decide the crop. Software that looks at a face is
        // software somebody is entitled to be told about, and the place to tell them is the
        // same place the discard promise is made — at the moment of asking, not in a policy
        // page nobody opens.
        $this->assertStringContainsString('find the face', $picker);
    }

    public function test_the_reframe_shows_what_the_type_will_cover(): void
    {
        $b = $this->body();

        // The handoff: "The reframe step is not optional polish. A mis-cropped selfie is the
        // main reason a generated flier gets binned, and the type sits over the lower third."
        $this->assertStringContainsString('fl__typeline', $b);
        $this->assertStringContainsString('Your name and the event go here', $b);
        // Draggable, and also operable without a pointer.
        $this->assertStringContainsString('@pointerdown="grab(', $b);
        $this->assertStringContainsString('type="range"', $b);
    }

    public function test_the_reframe_comes_after_the_first_render_not_before_it(): void
    {
        // It used to run BEFORE the render, which meant the reframe screen had to guess where
        // the crop would land — and it guessed the middle of the picture while the renderer
        // used FaceFinder's answer. A preview that disagrees with the image it previews.
        //
        // So start() goes straight to render() with no branch to 'frame', and the way back to
        // reframing is a control on the READY screen, over an image that exists.
        $b = $this->body();
        $start = $this->between($b, 'start(){', 'grab(e){');

        $this->assertStringNotContainsString("'frame'", $start,
            'start() must not route to the reframe screen — the render comes first now');
        $this->assertStringContainsString('this.render();', $start);
        $this->assertMatchesRegularExpression(
            '~stage = \'frame\'">Move the photo~', $b,
            'the ready screen is the only way into the reframe');
    }

    public function test_the_move_the_photo_control_is_hidden_when_there_is_no_photo(): void
    {
        // A "move the photo" button on a design with no photo in it is a control that cannot
        // do anything — the same fault the old pre-render reframe screen had, just relocated.
        // The whole <button> element, so the guard has to be ON the control rather than
        // somewhere in the same region.
        $b   = $this->body();
        $btn = $this->between($b, '<button type="button" class="fl__btn" x-show="file', 'Move the photo');

        $this->assertStringContainsString("fmt !== 'plain'", $btn);
        $this->assertStringContainsString("stage = 'frame'", $btn);
    }

    public function test_the_no_photo_design_is_offered_first(): void
    {
        $b = $this->body();

        // "plain — no photo — the most common case. Offer this first." So it is the first
        // radio and the default.
        //
        // Asserted against setFmt() rather than a bare assignment: the shape and the style are
        // not independent — `tint` re-colours a photograph, so the no-photo design cannot draw
        // it — and every shape button goes through one setter for that reason. A test written
        // against `fmt = '…'` would pass again the moment somebody reintroduced a direct
        // assignment that skips the style re-check.
        $this->assertLessThan(strpos($b, "setFmt('story')"), strpos($b, "setFmt('plain')"),
            'the no-photo shape must be the first option');
        $this->assertMatchesRegularExpression("~fmt: 'plain'~", $b, 'and the default');
        $this->assertStringNotContainsString("@click=\"fmt = '", $b,
            'a shape button that assigns fmt directly skips the style re-check');
    }

    // ══ cancel, and not losing what somebody typed ═══════════════════════════

    public function test_cancel_actually_cancels_the_request(): void
    {
        $b = $this->body();

        // A Cancel that only changes the screen leaves the request running and the next one
        // racing it — so the second flier can arrive after the first and overwrite it.
        $this->assertStringContainsString('new AbortController()', $b);
        $this->assertStringContainsString('this.ctrl.abort()', $b);
        $this->assertStringContainsString("signal: this.ctrl.signal", $b);
        // And a cancelled request is not an error screen.
        $this->assertStringContainsString("e.name === 'AbortError'", $b);
    }

    public function test_a_failure_preserves_the_draft(): void
    {
        $b = $this->body();

        $failed = $this->between($b, "stage === 'failed'", '{% else %}');
        $this->assertStringContainsString('Nothing you typed has been lost', $failed);
        $this->assertStringContainsString('Try again', $failed);
        // `stage` is the only thing the failure path changes, so the name and caption are
        // still bound to their inputs behind it.
        $this->assertStringNotContainsString("this.name = ''", $b);
    }

    // ══ the accessibility floor ══════════════════════════════════════════════

    public function test_every_field_has_a_real_label(): void
    {
        $b = $this->body();

        foreach (['flName', 'flPhoto', 'flCap'] as $id) {
            $this->assertMatchesRegularExpression('~<label[^>]*for="' . $id . '"~', $b,
                $id . ' has no associated label');
        }
        // The shape picker is a radio group with a name, and arrow keys move inside it.
        $this->assertStringContainsString('role="radiogroup"', $b);
        $this->assertStringContainsString('aria-labelledby="flFmtLab"', $b);
        $this->assertStringContainsString('keydown.arrow-right.prevent="stepFmt(1)"', $b);
    }

    public function test_the_targets_and_focus_are_in_the_stylesheet(): void
    {
        $b = $this->body();

        // 44px is WCAG 2.5.5 and 52 is this platform's primary-CTA height. Measured in a
        // browser as well — every control came back 44 or more, the primary at 52 — but
        // asserted here so a later edit to the sheet cannot quietly drop below it.
        $this->assertMatchesRegularExpression('~\.fl__btn\{[^}]*min-height:44px~', $b);
        $this->assertMatchesRegularExpression('~\.fl__btn--go\{[^}]*min-height:52px~', $b);
        $this->assertMatchesRegularExpression('~\.fl__fmt\{[^}]*min-height:44px~', $b);
        $this->assertStringContainsString('.fl__btn:focus-visible', $b);
    }

    public function test_reduced_motion_stops_the_progress_animation(): void
    {
        $b = $this->body();

        $this->assertMatchesRegularExpression(
            '~prefers-reduced-motion: reduce\)\{[\s\S]{0,120}?\.fl__bar span\{ animation:none~', $b);
    }

    // ══ the ungated path is the point, and says so ═══════════════════════════

    public function test_the_open_entry_states_the_incentive_without_nagging(): void
    {
        $b = $this->body();

        $this->assertStringContainsString('No ticket needed', $b);
        // The rate is READ, not written into the copy: an admin can change it, and a page
        // promising 10% after a change to 8% is a promise the ledger will not honour. Same
        // rule as the referral card above it.
        $this->assertStringContainsString("pct ? pct + '%'", $b);
        $this->assertStringNotContainsString('earn 10% of every ticket', $b);
    }

    public function test_the_confirmed_path_gets_its_token_from_the_server(): void
    {
        $b = $this->body();

        // A browser cannot assert a registration id. The token is minted by the register
        // response and handed over by an event, rather than the two Alpine roots reaching
        // into each other.
        $this->assertStringContainsString("'ag:flier-token'", $b);
        $this->assertSame(2, substr_count($b, 'ag:flier-token'),
            'one dispatch and one listener, no more');
        $this->assertStringContainsString('d.flier_token', $b);
    }

    /** The slice of $hay between two markers, or ''. */
    private function between(string $hay, string $from, string $to): string
    {
        $a = strpos($hay, $from);
        if ($a === false) return '';
        $b = strpos($hay, $to, $a);
        return $b === false ? substr($hay, $a) : substr($hay, $a, $b - $a);
    }

    // ══ it is a dialog over the page, not a card in the rail ══════════════════

    public function test_the_generator_is_not_inside_the_rail(): void
    {
        // It shipped inside `<aside class="ed-rail">`, which is where this page puts its
        // asides — and a five-screen flow with a file picker and a drag-to-reframe canvas is
        // not an aside. The rail is 320px and `position:sticky`, so the reframe control was a
        // thumbnail with a sticky ancestor, on the one screen whose whole job is showing
        // somebody their own face.
        //
        // Asserted by POSITION, because `position:fixed` hides the mistake: an overlay nested
        // in the rail looks correct until its containing block or its width matters.
        $b = $this->body();

        $railStart = strpos($b, '<aside class="ed-rail">');
        $railEnd   = strpos($b, '</aside>', (int) $railStart);
        $dialog    = strpos($b, 'aria-modal="true"');

        $this->assertNotFalse($railStart);
        $this->assertNotFalse($railEnd);
        $this->assertNotFalse($dialog, 'the generator must be a dialog');
        $this->assertGreaterThan($railEnd, $dialog,
            'the generator must live outside the rail, not merely be positioned out of it');
    }

    public function test_the_dialog_carries_the_semantics_that_make_it_one(): void
    {
        $b = $this->body();

        // `role` and `aria-modal` are what tell a screen reader the rest of the page is
        // inert; without them this is a div that happens to cover things.
        $this->assertStringContainsString('role="dialog"', $b);
        $this->assertStringContainsString('aria-modal="true"', $b);
        $this->assertStringContainsString('aria-labelledby="flTitle"', $b);
        $this->assertStringContainsString('id="flTitle"', $b);

        // Three ways out, because people reach for different ones: the X, the ground, Escape.
        $this->assertStringContainsString('aria-label="Close"', $b);
        $this->assertStringContainsString('class="flo__scrim" @click="close()"', $b);
        $this->assertStringContainsString('@keydown.escape.window="close()"', $b);
    }

    public function test_the_back_gesture_closes_the_sheet_instead_of_leaving_the_page(): void
    {
        // The interaction people try first on a phone and the one overlays get wrong most
        // often. Opening pushes a history entry and popstate closes — so back dismisses the
        // sheet rather than navigating away from the event.
        $b = $this->body();

        $this->assertStringContainsString("history.pushState({ agFlier: 1 }, '', '#flier')", $b);
        $this->assertMatchesRegularExpression(
            "~addEventListener\('popstate'[\s\S]{0,220}this\.shut\(\)~", $b);

        // And close() must not go back unconditionally: a sheet somebody opened BY pressing
        // back would then walk them off the page. `pushed` is what makes it conditional.
        $close = $this->between($b, 'close(){', 'trap(e){');
        $this->assertStringContainsString('if (this.pushed)', $close);
    }

    public function test_focus_is_moved_in_trapped_and_given_back(): void
    {
        $b = $this->body();

        // `aria-modal` says the page behind is inert; it does nothing about Tab. Without a
        // trap, tabbing off the last control lands on the event page behind a dark scrim,
        // which is where a keyboard user gets lost and stays lost.
        $this->assertStringContainsString('@keydown.tab="trap($event)"', $b);
        $this->assertStringContainsString('trap(e){', $b);
        $this->assertStringContainsString('e.shiftKey', $b);

        // In on open, and back where it came from on close.
        $this->assertStringContainsString('this.lastFocus = document.activeElement', $b);
        $this->assertStringContainsString('this.lastFocus.focus()', $b);
    }

    public function test_the_page_behind_does_not_scroll_and_gets_its_scrolling_back(): void
    {
        $b = $this->body();

        // `overflow` on the root rather than `position:fixed` on the body: the fixed trick
        // also loses the scroll position, so closing the sheet would dump somebody at the top
        // of the event page.
        $this->assertStringContainsString("document.documentElement.style.overflow = 'hidden'", $b);
        $this->assertStringContainsString("document.documentElement.style.overflow = ''", $b);
    }

    public function test_both_doors_open_it_and_neither_reaches_into_the_component(): void
    {
        $b = $this->body();

        // The rail card and the register card's success state are both OUTSIDE this component,
        // so they publish an event rather than touching its state — the same way the register
        // card already hands over its token.
        $this->assertSame(2, substr_count($b, "new CustomEvent('ag:flier-open'"),
            'the rail card and the post-registration nudge');
        $this->assertStringContainsString("addEventListener('ag:flier-open'", $b);

        // And the old fragment link is gone. `href="#flier"` used to scroll to a card; the
        // generator is a dialog now, so a fragment jump would land on a hidden element and
        // appear to do nothing at all.
        $this->assertStringNotContainsString('href="#flier"', $b);
    }

    public function test_a_bookmarked_fragment_still_opens_it(): void
    {
        // `#flier` was this thing's address before it became a dialog, and links to it exist —
        // in the account area's ticket rows among other places.
        $b = $this->body();
        $this->assertStringContainsString("(location.hash || '') === '#flier'", $b);
        $this->assertStringContainsString('flier=([^&]+)', $b);
    }

    // ══ the transport ladder, which is what the 406 cost ═════════════════════

    public function test_the_photo_has_a_second_transport_and_a_last_resort(): void
    {
        // Production answered the flier POST **406 Not Acceptable** — a status this
        // application returns from no route, for any input, which puts it in front of PHP.
        // With no shell on that host the filter cannot be read or relaxed, so the browser
        // tries shapes until one gets through.
        $b = $this->body();

        $this->assertStringContainsString("['multipart', 'b64', 'none']", $b);
        $this->assertStringContainsString('photo_b64', $b);

        // And the last rung still produces a flier — a real one, with the name and a working
        // code on it, which is worth far more than nothing.
        $this->assertStringContainsString('this.dropped = true', $b);
        $this->assertStringContainsString('would not accept the photo', $b);
    }

    public function test_only_statuses_this_application_never_returns_count_as_filtered(): void
    {
        // 403 is deliberately absent: CsrfMiddleware answers 403, and retrying a rejected
        // token in three transports is three failures and a misleading message.
        $b = $this->body();
        $fn = $this->between($b, 'filtered(code){', '},');

        foreach ([405, 406, 415, 501] as $code) {
            $this->assertStringContainsString((string) $code, $fn);
        }
        $this->assertStringNotContainsString('403', $fn,
            'a rejected CSRF token must not be retried as if it were a filter');
    }

    public function test_the_posts_go_to_the_extensionless_path(): void
    {
        // A POST body on a path a static handler claims by extension is one of the two shapes
        // a shared host rejects here. The `.png` POST route still exists as an alias for a
        // page already cached in somebody's browser, but nothing new aims at it.
        $b = $this->body();
        $this->assertStringContainsString("'/events/' + slug + '/flier'", $b);
        $this->assertStringNotContainsString("+ '/flier.png'", $b);
    }

    // ══ the face, as the generator presents it ═══════════════════════════════

    public function test_the_reframe_opens_on_the_frame_the_render_used(): void
    {
        // Adopted from `X-Flier-Focus`, so the control starts where the image is framed. A
        // reframe screen that opens anywhere else is a preview of a different picture.
        $b = $this->body();
        $this->assertStringContainsString("r.headers.get('X-Flier-Focus')", $b);

        // Null until the server answers or somebody drags — and null is what MEANS "nobody
        // has decided", which is what lets the server detect. A 0.5/0.22 default here would
        // have overruled the face on every first render.
        $this->assertStringContainsString('fx: null, fy: null', $b);
    }

    public function test_a_new_photo_discards_the_old_framing(): void
    {
        // The old pair was measured against a different picture, so keeping it would frame
        // photo B on where a face was in photo A.
        $pick = $this->between($this->body(), 'pick(){', 'start(){');
        $this->assertStringContainsString('this.fx = null; this.fy = null', $pick);
    }

    public function test_the_face_claim_is_only_made_when_a_face_was_found(): void
    {
        // "We framed this on the face we found" over a crop that fell back to the fixed
        // anchor is a claim somebody can see is false. `faced` is what makes the sentence
        // conditional, and the route sends an empty pair when it found nothing.
        $b = $this->body();
        $this->assertStringContainsString('faced: false', $b);
        $this->assertStringContainsString('x-text="faced', $b);
    }

    // ══ the style picker ═════════════════════════════════════════════════════

    public function test_the_style_picker_is_a_radiogroup_with_real_swatches(): void
    {
        $b = $this->body();

        // A radiogroup like the shape and the tier list, for the same reason: one choice from
        // a set, and people arrive knowing arrow keys move inside one.
        $this->assertStringContainsString('aria-labelledby="flStyleLab"', $b);
        $this->assertStringContainsString('data-fl-style', $b);
        $this->assertStringContainsString('stepStyle(1)', $b);
        $this->assertStringContainsString('stepStyle(-1)', $b);

        // The chips are painted from the server's own resolved palette. A picker that wrote
        // its colours into the template as approximations would show a generic chip beside a
        // flier that comes out teal — a control lying about its own outcome, on the feature
        // whose entire point is taking the organiser's colour.
        $this->assertStringContainsString("'background:' + s.ground", $b);
        $this->assertStringContainsString("'background:' + s.accent", $b);
        // No literal hex anywhere in the picker's markup: every colour it paints has to come
        // from the server's resolved palette, or it is an approximation that will drift.
        $picker = $this->between($b, 'id="flStyleLab"', '</div>');
        $this->assertDoesNotMatchRegularExpression('~#[0-9a-fA-F]{3,8}~', $picker,
            'the style chips must carry no colours of their own');

        // Colour is never the only signal (WCAG 1.4.1): the swatch is aria-hidden and the
        // label and note carry the meaning, because a screen reader reading three hex values
        // is worse off than one reading none.
        $this->assertStringContainsString('class="fl__st__sw" aria-hidden="true"', $b);
        $this->assertStringContainsString('x-text="s.label"', $b);
        $this->assertStringContainsString('x-text="s.note"', $b);
    }

    public function test_changing_the_shape_rechecks_the_style(): void
    {
        // `tint` re-colours a photograph, so the no-photo design cannot draw it. Without the
        // re-check the picker keeps a selected chip that is no longer in its own list and the
        // render silently uses something else — the control and the image disagreeing, which
        // is the fault class this whole feature kept producing.
        $set = $this->between($this->body(), 'setFmt(next){', 'styles(){');

        $this->assertStringContainsString('this.fmt = next', $set);
        $this->assertStringContainsString('this.styles().some(', $set);
        $this->assertStringContainsString('styleDefaults[next]', $set);
    }

    public function test_the_style_rides_on_the_request_and_the_answer_is_adopted(): void
    {
        $b = $this->body();

        // Sent with every attempt, whichever transport carries it.
        $this->assertMatchesRegularExpression('~style: this\.style~', $b);

        // And corrected from the response, because a requested style is normalised against the
        // format that is actually DRAWN: `tint` on a photo format with no photo comes back
        // `paper` on `plain`.
        $this->assertStringContainsString("r.headers.get('X-Flier-Style')", $b);
    }

    public function test_the_style_data_is_not_passed_through_an_attribute(): void
    {
        // ── THE BUG THIS ASSERTS AGAINST ─────────────────────────────────────
        //
        // The swatches were handed over as JSON arguments inside
        // `x-data="evFlier(…, {…}, {…})"`, and the HTML parser destroyed it: the first `"`
        // inside the JSON closed the attribute and everything after it was re-read as a list
        // of attribute names. The element came out carrying `in=""`, `the=""`, `full=""`, and
        // Alpine never saw a component — no console error, no failed request, just a dialog
        // that did nothing when pressed.
        //
        // Found by driving the page in a browser. Nothing in this suite could have caught it,
        // which is why the assertion is on the SHAPE of the handover rather than on behaviour.
        $tpl = $this->tpl();

        $this->assertMatchesRegularExpression('~var AG_FLIER_STYLES\s*=~', $tpl);
        $this->assertStringNotContainsString("x-data=\"evFlier('{{ event.slug|e('js') }}', "
            . "'{{ csrf_token|e('js') }}', {{ referral and referral.pct ? referral.pct : 0 }},", $tpl,
            'JSON must not be passed through an HTML attribute');
    }

    // ══ the token path is not a dead button ══════════════════════════════════

    /**
     * A name is required only when there is no token to take it from.
     *
     * `flierMake()` reads `name` ONLY when `t` is empty — a token carries the name, minted
     * server-side, and `fields()` already knows it: `if (this.token) o.t = this.token; else
     * o.name = ...`. Two other places demanded a name regardless, and the third entry point
     * is the one that paid: arriving from the account area with `#flier=<token>` sets the
     * token and no name, so a ticket-holder saw the confirmed copy, an empty box and a
     * button that would not enable — and had to type a name the server then discards.
     */
    public function test_the_make_button_is_live_on_the_token_path(): void
    {
        $this->assertMatchesRegularExpression(
            '~:disabled="!token\s*&&\s*!name\.trim\(\)"~', $this->body(),
            'the button gates on a name the token path does not need — it stays dead for a ticket-holder'
        );
    }

    /** And the guard behind it agrees, or the button enables onto a refusal. */
    public function test_the_start_guard_agrees_with_the_button(): void
    {
        $this->assertMatchesRegularExpression(
            '~if\s*\(!this\.token\s*&&\s*!this\.name\.trim\(\)\)~', $this->body(),
            'start() still demands a name on the token path'
        );
    }

    /**
     * All three places that decide "is a name required" must say the same thing. This is
     * the property, not the two spellings above: the bug was that fields() was right and
     * the other two were not.
     */
    public function test_nothing_still_requires_a_name_unconditionally(): void
    {
        $body = $this->body();

        $this->assertStringNotContainsString(':disabled="!name.trim()"', $body);
        $this->assertStringNotContainsString("if (!this.name.trim())", $body);
        $this->assertStringContainsString('if (this.token) o.t = this.token; else o.name', $body,
            'fields() is the rule the other two follow — if it changed, they must too');
    }
}
