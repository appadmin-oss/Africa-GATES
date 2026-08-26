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

        $this->assertStringContainsString('discarded', $picker);
        $this->assertStringContainsString('never saved on our server', $picker);
        $this->assertStringContainsString('optional', strtolower($picker));
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

    public function test_a_photo_format_with_no_photo_skips_the_reframe(): void
    {
        // A reframe screen over a picture nobody supplied is a screen with one thing to do
        // and no way to do it.
        $this->assertMatchesRegularExpression(
            "~if \(this\.fmt !== 'plain' && this\.file\) \{ this\.stage = 'frame'; return; \}~",
            $this->body());
    }

    public function test_the_no_photo_design_is_offered_first(): void
    {
        $b = $this->body();

        // "plain — no photo — the most common case. Offer this first." So it is the first
        // radio and the default.
        $this->assertLessThan(strpos($b, "fmt = 'story'"), strpos($b, "fmt = 'plain'"),
            'the no-photo shape must be the first option');
        $this->assertMatchesRegularExpression("~fmt: 'plain'~", $b, 'and the default');
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
}
