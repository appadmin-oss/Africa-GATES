<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AttendeeBot;
use Tests\TestCase;

/**
 * Two gaps the interview-bot handoff filed as item 6, and the state map underneath them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE HOLD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 6a — consent withdrawn mid-call. `consent_at` gates three things and is deliberately
 * one column: whether a bot is sent, whether what it hears is stored, and whether it may
 * speak. Storage was enforced where the words arrive and dispatch refuses without it;
 * neither reaches the recording accumulating on the bot host. The only lever was removing
 * the bot, which also ends the sitting for the panel.
 *
 * 6c — erasure that stops at this database. A judging interview leaves the member's words
 * in `gates_interview_guard_log` and on the bot host, and `privacy:erase-user` walked past
 * both.
 *
 * The state map is not in the handoff at all. `normaliseState()` ended in
 * `default => 'joining'`, and `sweep()` polls exactly ['requested','joining','in_call'] —
 * so every state the map did not name became a bot polled for the rest of the sitting.
 * Read against the provider's `BotStates` at 77e990ed, four real states landed there.
 */
final class InterviewBotConsentAndErasureTest extends TestCase
{
    // ══ the state map ════════════════════════════════════════════════════════

    /**
     * A refused recording permission is a dead sitting, not a slow one. Under the old
     * default it read as 'joining' and the sweep chased it until the call ended,
     * reporting a bot that was never going to record anything.
     */
    public function test_a_refused_recording_permission_is_an_error_not_a_bot_still_joining(): void
    {
        $this->assertSame('error', AttendeeBot::normaliseState('joined_recording_permission_denied'));
    }

    /** A bot on its way out is finishing, not arriving. */
    public function test_leaving_is_not_joining(): void
    {
        $this->assertSame('done', AttendeeBot::normaliseState('leaving'));
    }

    /** Data erased on request must not put the bot back in the sweep's live set. */
    public function test_data_deleted_leaves_the_live_set(): void
    {
        $this->assertSame('removed', AttendeeBot::normaliseState('data_deleted'));
    }

    /**
     * A paused recording IS in the room and must keep being polled — folding it into
     * 'in_call' is correct, and it is why enforceConsent() reads the raw state instead.
     */
    public function test_a_paused_recording_is_still_in_the_call(): void
    {
        $this->assertSame('in_call', AttendeeBot::normaliseState('joined_recording_paused'));
    }

    public function test_a_breakout_room_is_still_in_the_call(): void
    {
        $this->assertSame('in_call', AttendeeBot::normaliseState('joining_breakout_room'));
        $this->assertSame('in_call', AttendeeBot::normaliseState('leaving_breakout_room'));
    }

    /**
     * Every state the provider defines at 77e990ed is named, so nothing real reaches the
     * default. It survives for a NEWER instance, where staying in the live set beats
     * ending a sitting on a string this release has not seen.
     */
    public function test_every_state_the_provider_defines_is_named(): void
    {
        $states = [
            'ready', 'joining', 'joined_not_recording', 'joined_recording', 'leaving',
            'post_processing', 'fatal_error', 'waiting_room', 'ended', 'data_deleted',
            'scheduled', 'staged', 'joined_recording_paused', 'joining_breakout_room',
            'leaving_breakout_room', 'joined_recording_permission_denied',
        ];

        // A bot in the waiting room genuinely IS still joining, so those two are the
        // only states that may legitimately read as 'joining'. Anything else doing so has
        // fallen through the default.
        $genuinelyJoining = ['joining', 'waiting_room'];

        $unnamed = [];
        foreach ($states as $s) {
            if (in_array($s, $genuinelyJoining, true)) continue;
            if (AttendeeBot::normaliseState($s) === 'joining') $unnamed[] = $s;
        }

        $this->assertSame([], $unnamed, 'these states fall through to the default and would be polled forever');
    }

    public function test_an_unknown_state_stays_in_the_live_set(): void
    {
        $this->assertSame('joining', AttendeeBot::normaliseState('some_state_from_a_newer_release'));
        $this->assertSame('', AttendeeBot::normaliseState(''));
    }

    // ══ 6a: the pause path exists ════════════════════════════════════════════

    public function test_pause_and_resume_refuse_cleanly_when_nothing_is_configured(): void
    {
        // Unconfigured must be a refusal with a reason, not an exception or a silent
        // success that leaves a caller believing a recording stopped.
        foreach (['pauseRecording', 'resumeRecording', 'deleteData'] as $method) {
            $r = AttendeeBot::$method('bot_x');
            $this->assertFalse($r['ok'], "{$method} claimed success with no API key");
            $this->assertNotSame('', (string) $r['error']);
        }
    }

    public function test_a_blank_bot_id_is_refused(): void
    {
        $this->assertFalse(AttendeeBot::pauseRecording('')['ok']);
    }

    /**
     * The handoff proposed wiring `/admit_from_waiting_room` for the same class of
     * problem — "the most likely live failure". It is **Zoom-only**: the provider answers
     * 400 for any other meeting type. These interviews are on Google Meet, so that
     * endpoint would produce a button that always fails, and it is deliberately absent.
     */
    public function test_the_zoom_only_admit_endpoint_was_not_wired(): void
    {
        $this->assertFalse(
            method_exists(AttendeeBot::class, 'admitFromWaitingRoom'),
            'admit_from_waiting_room is Zoom-only and these interviews are on Google Meet'
        );
    }

    // ══ 6c: erasure reaches past this database ═══════════════════════════════

    public function test_erasure_reaches_the_guard_log_and_the_bot_host(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\AfricaGates\Console\Commands\PrivacyEraseUserCommand::class))->getFileName()
        );

        $this->assertStringContainsString('gates_interview_guard_log', $src, 'erasure does not reach the guard log');
        $this->assertStringContainsString('AttendeeBot::deleteData', $src, 'erasure does not reach the bot host');
    }

    /**
     * The refusal survives; the quoted sentence does not. `InterviewGuard::tally()` is how
     * the corpus is extended after a real judging round, so deleting the rows outright
     * would erase the evidence that a rule fired along with the words that triggered it.
     */
    public function test_the_guard_log_is_scrubbed_rather_than_deleted(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\AfricaGates\Console\Commands\PrivacyEraseUserCommand::class))->getFileName()
        );

        $this->assertMatchesRegularExpression(
            '/gates_interview_guard_log\'\)\s*\n\s*->whereIn\(\'interview_id\'.*\n\s*->update\(/',
            $src,
            'the guard log should be updated in place, not deleted'
        );
    }
}
