<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\InviteSequence;
use AfricaGates\Services\InviteSequenceWriter;
use Tests\TestCase;

/**
 * The agent that drafts an organiser's countdown letters.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS ACTUALLY AT RISK HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two things, and neither is "does the model write well".
 *
 * THE TOKENS. A letter is only reusable if its placeholders survive. A model is reliably
 * willing to be helpful in the worst way — writing the ceremony's actual name where
 * `{event}` belongs — and that draft reads perfectly today and is wrong for every
 * ceremony after it. The failure is invisible at the moment it is introduced and shows up
 * a year later in somebody's inbox.
 *
 * THE SAVE. Five letters addressed to people receiving an honour in public, in an
 * organisation's name. Nothing a model writes may reach a shortlist without a person
 * having read it, so the draft path and the send path must not touch anywhere.
 */
final class InviteSequenceWriterTest extends TestCase
{
    /** A well-formed reply, as the contract asks for it. */
    private function reply(array $overrides = []): string
    {
        $body = static fn (int $d): string =>
            "It is {days} days to {event}, and the hall is being made ready.\n\n"
            . str_repeat('This is the argument of day ' . $d . ', written out at the length a real '
                       . 'letter runs to so that the minimum-length floor is cleared honestly. ', 4)
            . "\n\nWhat will you say when you are asked about {theme}?";

        $out = [];
        foreach (InviteSequence::DAYS as $d) $out[(string) $d] = $body($d);

        return json_encode(array_replace($out, $overrides), JSON_UNESCAPED_SLASHES);
    }

    // ══ THE CONTRACT ════════════════════════════════════════════════════════

    public function test_a_good_reply_becomes_five_letters(): void
    {
        $r = InviteSequenceWriter::parse($this->reply());

        $this->assertTrue($r['ok'], $r['error']);
        $this->assertSame(InviteSequence::DAYS, array_keys($r['letters']));
        $this->assertSame([], $r['notes']);
    }

    /** Models wrap JSON in a fence, or preface it with a sentence. Both are ordinary. */
    public function test_a_fenced_or_prefaced_reply_is_still_read(): void
    {
        $this->assertTrue(InviteSequenceWriter::parse("```json\n" . $this->reply() . "\n```")['ok']);
        $this->assertTrue(InviteSequenceWriter::parse("Here are the letters:\n" . $this->reply())['ok']);
    }

    /** `{"letters": {...}}` is the other shape a model reaches for. */
    public function test_the_nested_shape_is_read_too(): void
    {
        $r = InviteSequenceWriter::parse(json_encode(['letters' => json_decode($this->reply(), true)]));

        $this->assertTrue($r['ok']);
        $this->assertCount(5, $r['letters']);
    }

    /**
     * One missing day does not discard the other four.
     *
     * An operator who gets four good letters and a note about the fifth is better served
     * than one who gets an error and nothing.
     */
    public function test_a_missing_day_keeps_the_rest_and_says_so(): void
    {
        $r = InviteSequenceWriter::parse($this->reply(['3' => '']));

        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey(3, $r['letters']);
        $this->assertCount(4, $r['letters']);
        $this->assertStringContainsString('Day 3', implode(' ', $r['notes']));
    }

    /** A forty-character "letter" is a refusal wearing a letter's clothes. */
    public function test_a_letter_too_short_to_be_one_is_dropped(): void
    {
        $r = InviteSequenceWriter::parse($this->reply(['2' => 'Sure! Here you go.']));

        $this->assertArrayNotHasKey(2, $r['letters']);
        $this->assertStringContainsString('too short', implode(' ', $r['notes']));
    }

    /** Markup would reach the reader as literal angle brackets mid-sentence. */
    public function test_markup_never_survives_into_a_draft(): void
    {
        $r = InviteSequenceWriter::parse($this->reply([
            '1' => '<p>Tomorrow is {event}.</p>' . str_repeat('A real paragraph of the letter. ', 20),
        ]));

        $this->assertStringNotContainsString('<p>', $r['letters'][1]);
        $this->assertStringContainsString('Tomorrow is {event}', $r['letters'][1]);
    }

    public function test_nothing_usable_is_an_error_rather_than_five_empty_letters(): void
    {
        $this->assertFalse(InviteSequenceWriter::parse('I would rather not.')['ok']);
        $this->assertFalse(InviteSequenceWriter::parse('{"5":"no"}')['ok']);
        $this->assertFalse(InviteSequenceWriter::parse('')['ok']);
    }

    /** The gateway's schema hook rejects rather than logging a useless success. */
    public function test_an_unusable_reply_is_rejected_at_the_schema_hook(): void
    {
        $this->assertNull(InviteSequenceWriter::validate('not json at all'));
        $this->assertIsArray(InviteSequenceWriter::validate($this->reply()));
    }

    // ══ THE TOKENS ══════════════════════════════════════════════════════════

    /**
     * The prompt has to NAME every token, or the model cannot use the ones it is not told
     * about — and a token nothing mentions is a placeholder that quietly stops appearing.
     */
    public function test_the_prompt_names_every_token_the_letters_support(): void
    {
        $ref = new \ReflectionMethod(InviteSequenceWriter::class, 'system');
        $ref->setAccessible(true);
        $system = (string) $ref->invoke(null);

        foreach (array_keys(InviteSequence::TOKENS) as $token) {
            $this->assertStringContainsString('{' . $token . '}', $system,
                '{' . $token . '} exists but the writer is never told about it');
        }

        $this->assertStringContainsString('Never replace one with its value', $system,
            'the one instruction that keeps a letter reusable next year');
    }

    /**
     * The writer is told WHO it is writing to, and the premise is not the arc.
     *
     * The one failure a reader notices instantly: telling a judge their nomination
     * represents trust, to somebody who was never nominated. The beats stay identical —
     * that is what makes the two sets sound like one organisation — so the difference has
     * to be carried by the premise, and the premise has to be stated.
     */
    public function test_the_writer_is_told_which_arc_it_is_writing(): void
    {
        $ref = new \ReflectionMethod(InviteSequenceWriter::class, 'system');
        $ref->setAccessible(true);

        $nominee = (string) $ref->invoke(null, \AfricaGates\Services\InviteAudience::NOMINEE);
        $judge   = (string) $ref->invoke(null, \AfricaGates\Services\InviteAudience::JUDGE);

        $this->assertNotSame($nominee, $judge);

        $this->assertStringContainsString('NOMINATED', $nominee);
        $this->assertStringContainsString('JUDGING PANEL', $judge);
        $this->assertStringContainsString('They were not nominated', $judge,
            'the one sentence that stops the whole set being addressed to the wrong person');
        $this->assertStringContainsString('STAND BEHIND a result', $judge,
            'day one is the beat where the two arcs genuinely diverge');
        $this->assertStringContainsString('platform, not a pathway', $nominee,
            'and the nominee keeps the beat written for somebody receiving the honour');

        // The tokens are the contract for both, and a token the writer is not told about
        // is a placeholder that quietly stops appearing.
        foreach (array_keys(InviteSequence::TOKENS) as $token) {
            $this->assertStringContainsString('{' . $token . '}', $judge, '{' . $token . '} for the panel');
        }
    }

    /** A drafted letter keeps its placeholders, which is what makes it reusable. */
    public function test_a_draft_keeps_its_placeholders(): void
    {
        $r = InviteSequenceWriter::parse($this->reply());

        foreach ($r['letters'] as $day => $text) {
            $this->assertStringContainsString('{event}', $text, 'day ' . $day);
        }
    }

    // ══ THE GUARD RAILS ═════════════════════════════════════════════════════

    /**
     * Declared, budgeted and logged like every other AI call on this platform.
     *
     * A capability that is not declared is refused by the gateway with UNDECLARED, so this
     * also proves the writer can run at all.
     */
    public function test_the_capability_is_declared_and_bounded(): void
    {
        $cap = AiCapability::find(InviteSequenceWriter::CAPABILITY);

        $this->assertNotNull($cap, 'an undeclared capability is refused before any provider is asked');
        $this->assertTrue($cap->advisory, 'nothing it writes may act on its own');
        $this->assertFalse($cap->untrustedInput,
            'it is given an administrator\'s own event — the day a nominee\'s text reaches '
            . 'it, this must flip and the fence must come back');
        $this->assertGreaterThanOrEqual(1000, $cap->maxTokens, 'five letters is long output');
        $this->assertLessThanOrEqual(100, $cap->callsPerDay,
            'an operator settling wording presses this a handful of times, not thousands');
        $this->assertGreaterThan(6, $cap->timeout, 'five long letters do not arrive in six seconds');
    }

    /**
     * The draft path and the send path do not touch.
     *
     * A model that could post its own letters to a published shortlist would be the most
     * consequential unattended writer here. Asserted structurally rather than trusted:
     * the sweep must not reference the writer at all.
     */
    public function test_the_sweep_cannot_reach_the_writer(): void
    {
        $reminders = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/InviteReminders.php');
        $sequence  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/InviteSequence.php');

        $this->assertStringNotContainsString('InviteSequenceWriter', $reminders,
            'the thing that SENDS must have no path to the thing that writes');
        $this->assertStringNotContainsString('InviteSequenceWriter', $sequence);
    }

    /** Draft, read, save — three presses, and the middle one is a person. */
    public function test_drafting_and_saving_are_separate_routes(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertStringContainsString('invites/letters/draft', $routes);
        $this->assertStringContainsString('invites/letters/save', $routes);
        $this->assertStringContainsString('invites/letters/discard', $routes);

        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/InvitesController.php');

        // The draft action must not write a setting. If these two ever merge, five letters
        // reach a shortlist with nobody having read them.
        $draft = substr($ctl, (int) strpos($ctl, 'function draftLetters'),
                        (int) strpos($ctl, 'function saveLetters') - (int) strpos($ctl, 'function draftLetters'));
        $this->assertStringNotContainsString('->set(', $draft,
            'drafting must change nothing — saving is a separate, deliberate press');
    }
}
