<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\InterviewService;
use AfricaGates\Services\ReferralService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Mechanisms that were complete, correct, and reached by nothing.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `CODEBASE-INDEX.md` §17 records six shipped bugs of one shape — a declaration with no
 * reader — and §18 its sibling, a mechanism with no route in. The audit of 2026-08-27
 * found forty-three more methods in `src/` that nothing in production calls, and the four
 * below were the ones where something an operator believes is happening was not:
 *
 *   · an interview cancelled here stayed in everybody's calendar with a live Meet link
 *   · a revoked admin kept the console until their cookie expired (AdminAuthMiddlewareTest)
 *   · the tail of every recorded interview was dropped as the bot walked out of the room
 *   · one click on a referral link earned commission on every purchase after it
 *
 * None of them raised anything. That is what a no-caller bug is: the code is right, the
 * tests of the code pass, and the behaviour simply does not occur.
 *
 * ── AND WHY THE ASSERTIONS ARE PARTLY STRUCTURAL ─────────────────────────────
 *
 * A behavioural test cannot catch this class on its own. Every one of these methods had a
 * passing test of its own logic; what was missing was a CALLER, and the only way to hold
 * that is to look at the call graph. So the first test reads the source: it is the same
 * instrument `TwigBlockScopeTest` and `NestedFormTest` use, for the same reason — the
 * failure is invisible from inside the unit.
 */
final class DeadMechanismTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    // ══ 1 · the callers exist at all ═════════════════════════════════════════

    /**
     * Each mechanism, and the production file that must reach it.
     *
     * Deliberately pinned to a FILE rather than "somewhere in src/": the bug was never
     * that the method was unreferenced in the abstract, it was that the one path a person
     * takes did not go through it. A caller in a different file is a different feature.
     *
     * @return array<string, array{0:string, 1:string, 2:string}> label => [callee, file, why]
     */
    public static function wiring(): array
    {
        return [
            'a cancelled interview cancels its calendar event' => [
                'cancelEvent(',
                'src/Services/InterviewService.php',
                'cancel() changed one column; the Meet link stayed live and the guests were never told',
            ],
            'the admin console re-reads the account behind the session' => [
                'currentAdmin(',
                'src/Admin/Middleware/AdminAuthMiddleware.php',
                'role and is_active were stamped in at login, so deactivating an admin ended nothing',
            ],
            'the sweep waits for the rest of the transcript' => [
                'transcriptReady(',
                'src/Services/InterviewBot.php',
                'polling stopped the moment the bot left, and transcription lags the audio',
            ],
            'a paid ticket spends the referral held in the session' => [
                'clearSession(',
                'src/Controllers/EventsController.php',
                'one followed link went on earning commission for the rest of the session',
            ],
            'a paid shop order spends it too' => [
                'clearSession(',
                'src/Controllers/ShopCheckoutController.php',
                'the same rule, on the other checkout',
            ],
        ];
    }

    /** @dataProvider wiring */
    public function test_the_mechanism_is_reached_from_production(string $callee, string $file, string $why): void
    {
        $this->assertStringContainsString(
            $callee,
            self::src($file),
            $file . ' must call ' . $callee . ') — ' . $why
        );
    }

    // ══ 2 · the Apps Script can be addressed the way we address it ═══════════

    /**
     * `calendar.cancel` used to demand the `agatesKey` extended property, and only
     * `calendar.sync` ever sets one. The interviews screen books through `meet.create`,
     * which sets none and hands back an event id — so the cancel could not find a single
     * event a judging panel had ever made, and said "nothing to cancel" about all of them.
     *
     * `calendarRead` has taken both handles since it was written. This asserts the pair is
     * now symmetric on both sides of the wire.
     */
    public function test_the_script_can_cancel_by_event_id_and_not_only_by_key(): void
    {
        $gs = (string) file_get_contents(dirname(__DIR__, 2) . '/config/AfricaGATES_AppScript.gs');

        $cancel = substr($gs, strpos($gs, 'function calendarCancel('));
        $cancel = substr($cancel, 0, strpos($cancel, "\n}\n") + 3);

        $this->assertStringContainsString('d.eventId', $cancel,
            'the interviews path stores an event id and no key — a cancel that needs a key cannot reach it');
        $this->assertStringContainsString('d.key', $cancel,
            'the key must stay: a sitting created by calendar.sync has no id stored');
    }

    /** And the PHP side sends the id it holds. */
    public function test_the_php_cancel_sends_the_event_id(): void
    {
        $php = self::src('src/Services/GoogleMeetService.php');
        $body = substr($php, strpos($php, 'public function cancelEvent('));
        $body = substr($body, 0, strpos($body, "\n    }\n") + 7);

        $this->assertStringContainsString("'eventId'", $body);
        $this->assertStringContainsString("'key'", $body);
    }

    // ══ 3 · the calendar leg never blocks the cancellation ═══════════════════

    private function sitting(?string $eventId): int
    {
        $nominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 1, 'name' => 'Ada Nwosu', 'status' => 'approved', 'vote_count' => 0,
        ]);
        return (int) DB::table('gates_interviews')->insertGetId([
            'nominee_id'        => $nominee,
            'status'            => 'confirmed',
            'scheduled_at'      => '2026-09-01 14:00:00',
            'duration_mins'     => 30,
            'timezone'          => 'Africa/Lagos',
            'meet_url'          => 'https://meet.google.com/abc-defg-hij',
            'meet_code'         => 'abc-defg-hij',
            'calendar_event_id' => $eventId,
            'created_at'        => '2026-08-19 09:00:00',
            'updated_at'        => '2026-08-20 09:00:00',
        ]);
    }

    /**
     * An unconfigured, unreachable or not-yet-redeployed Apps Script must not leave an
     * interview we have decided is off still marked as happening. The calendar leg is
     * best-effort in the one direction that matters.
     */
    public function test_an_unreachable_calendar_does_not_block_the_cancellation(): void
    {
        // No GAS_URL is configured in the suite, so canSchedule() is false — which is the
        // state of every installation that has not set the integration up.
        $id = $this->sitting('evt_live');

        $r = InterviewService::cancel($id, 'nominee withdrew');

        $this->assertTrue($r['ok']);
        $this->assertSame('cancelled', (string) DB::table('gates_interviews')->where('id', $id)->value('status'));
    }

    /** …and it SAYS so, rather than letting the operator believe the event is gone. */
    public function test_the_operator_is_told_when_the_calendar_could_not_be_cleared(): void
    {
        $id = $this->sitting('evt_live');

        $r = InterviewService::cancel($id);

        $this->assertStringContainsString('by hand', (string) $r['message'],
            'an operator who reads nothing about the calendar deletes nothing from it');
        // The link is NOT cleared on a failed cancel: it is still live, and a row that
        // says otherwise would hide the event still sitting in the nominee's diary.
        $this->assertNotEmpty(DB::table('gates_interviews')->where('id', $id)->value('meet_url'));
    }

    /** A sitting never booked through us says nothing about a calendar at all. */
    public function test_a_sitting_with_no_calendar_event_cancels_quietly(): void
    {
        $id = $this->sitting(null);

        $r = InterviewService::cancel($id);

        $this->assertTrue($r['ok']);
        $this->assertStringNotContainsString('calendar', strtolower((string) $r['message']));
    }

    // ══ 4 · the referral is spent, once ══════════════════════════════════════

    /**
     * The rule is written on clearSession() itself — "after it has been credited, so one
     * link cannot earn on two purchases" — and nothing called it, so the rule was a
     * comment. The commission was paid on every purchase in the session.
     */
    public function test_a_captured_referral_can_be_spent(): void
    {
        $_SESSION = [];
        ReferralService::capture('ADAN2026');
        $this->assertSame('ADAN2026', ReferralService::fromSession());

        ReferralService::clearSession();
        $this->assertSame('', ReferralService::fromSession(), 'a spent link must not earn again');
        $_SESSION = [];
    }

    /** The key it used to live under is cleared too, or a pre-deploy session keeps earning. */
    public function test_the_legacy_session_key_is_spent_with_it(): void
    {
        $_SESSION = ['event_ref' => 'ADAN2026'];
        $this->assertSame('ADAN2026', ReferralService::fromSession());

        ReferralService::clearSession();
        $this->assertSame('', ReferralService::fromSession());
        $_SESSION = [];
    }
}
