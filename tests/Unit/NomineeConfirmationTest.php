<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Services\NomineeConfirmation as NC;
use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\NominationStatus as NS;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The nominee's half of `verified`.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THING THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `nominee_confirm_token`, `nominee_confirmed_at` and `confirm_sends` were added by
 * the challenge migration and written by NOTHING for two commits. `ChallengeService`
 * read `nominee_confirmed_at` to decide whether a nomination counted, so with no
 * writer no nomination could ever reach `verified` and no challenge could ever pay
 * out — with the suite green the whole time, because every test stamped the column by
 * hand. A declared field with no reader, authored by me.
 *
 * So the case that matters most here is {@see
 * test_a_nomination_only_counts_once_the_nominee_has_answered}: it drives the real
 * service rather than writing the timestamp itself.
 */
final class NomineeConfirmationTest extends TestCase
{
    private int $cycle = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $prog = DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Confirmation probe', 'slug' => 'nc-' . bin2hex(random_bytes(3)), 'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $prog, 'year' => 2026, 'status' => 'nominations',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The whole point
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A moderator's approval alone does not pay a prize.
     *
     * Driven end to end through the real service, because the fault this guards was a
     * column nothing wrote while every test wrote it by hand.
     */
    public function test_a_nomination_only_counts_once_the_nominee_has_answered(): void
    {
        [$ch, $entry] = $this->challengeWithEntry(['target' => 1, 'cap' => 3]);

        $nom = $this->nomination($entry, 'Approved but silent', NS::VERIFIED);

        CS::recount($entry);
        self::assertSame(0, (int) CS::entry($entry)->verified,
            'a nomination counted before the nominee had said anything');

        $token = $this->tokenFor($nom);
        $r = NC::confirm($token);

        self::assertTrue($r['ok']);
        self::assertSame(1, (int) CS::entry($entry)->verified,
            'the confirmation did not move the entry — nothing recounted');
        self::assertSame(1, (int) CS::entry($entry)->standing,
            'the entry reached its target and was not qualified');
    }

    /**
     * A decline is an ANSWER, recorded, not an absence.
     *
     * `gates_nominee_submissions.skipped_json` exists in this codebase for exactly this
     * distinction, and its migration names the harm: a panel reading a decline as "not
     * answered" treats somebody who refused the same as somebody who never saw the
     * message. Here it is worse — an unconfirmed nomination gets asked again, so a
     * person who said no would be texted twice more.
     */
    public function test_a_decline_takes_the_nomination_down_and_is_terminal(): void
    {
        [$ch, $entry] = $this->challengeWithEntry(['target' => 1, 'cap' => 3]);
        $nom = $this->nomination($entry, 'Would rather not', NS::VERIFIED);

        self::assertTrue(NC::decline($this->tokenFor($nom))['ok']);

        $row = DB::table('gates_nominations')->where('id', $nom)->first();
        self::assertSame(NS::REJECTED, $row->status);
        self::assertNull($row->nominee_confirmed_at);
        self::assertStringContainsString('declined', (string) $row->decision_reason);
        self::assertNull($row->nominee_confirm_token, 'the link stayed live after an answer');

        self::assertSame(0, (int) CS::entry($entry)->verified);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The link is a credential
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_presented_link_is_spent_whichever_way_it_is_answered(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $nom   = $this->nomination($entry, 'Answers once', NS::VERIFIED);
        $token = $this->tokenFor($nom);

        self::assertSame('CONFIRMED', NC::confirm($token)['code']);
        // The same link again is nothing: it is not an error page about the nomination,
        // it is a dead token, because anybody holding the URL can answer as this person.
        self::assertSame('INVALID', NC::confirm($token)['code']);
        self::assertSame('INVALID', NC::decline($token)['code'],
            'a spent link could still be used to withdraw somebody else\'s nomination');
    }

    public function test_a_token_that_is_not_the_right_shape_never_reaches_the_database(): void
    {
        foreach (['', 'abc', '../../etc/passwd', str_repeat('z', 40), str_repeat('a', 39)] as $bad) {
            self::assertNull(NC::peek($bad), "a malformed token was looked up: `$bad`");
        }
    }

    public function test_a_link_older_than_its_window_is_refused(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $nom = $this->nomination($entry, 'Too late', NS::VERIFIED);

        DB::table('gates_nominations')->where('id', $nom)->update([
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . (NC::TTL_DAYS + 1) . ' days')),
        ]);

        self::assertNull(NC::peek($this->tokenFor($nom)));
        self::assertSame('EXPIRED', NC::confirm($this->tokenFor($nom))['code']);
    }

    /**
     * The window runs from the NOMINATION, not from the last send.
     *
     * A window that restarted on every resend would make a nomination confirmable
     * indefinitely by a nominator who keeps pressing the button — which is the thing
     * the send ceiling exists to bound.
     */
    public function test_the_window_does_not_restart_on_a_resend(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $nom = $this->nomination($entry, 'Pestered', NS::VERIFIED);

        DB::table('gates_nominations')->where('id', $nom)->update([
            'created_at'    => date('Y-m-d H:i:s', strtotime('-' . (NC::TTL_DAYS + 2) . ' days')),
            'confirm_sends' => 0,
        ]);

        // The ask is refused outright rather than minting a token for a link that is
        // dead on arrival — which would text a stranger to no purpose and leave the
        // nominator waiting for an answer that cannot come.
        self::assertSame('EXPIRED', NC::ask($nom)['code']);
        self::assertSame(0, (int) DB::table('gates_nominations')->where('id', $nom)->value('confirm_sends'),
            'a refused ask still spent one of the three sends');
        self::assertSame('EXPIRED', NC::confirm($this->tokenFor($nom))['code'],
            'an expired nomination became confirmable again by resending');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Not contacting a stranger forever
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Three sends, and the ceiling is about the NOMINEE.
     *
     * They did not ask to be contacted — somebody else typed their number into a form.
     * An uncapped resend is a way to make this platform text a stranger on demand.
     */
    public function test_the_send_ceiling_is_counted_per_ask_and_not_per_channel(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $nom = $this->nomination($entry, 'Reachable', NS::VERIFIED);

        DB::table('gates_nominations')->where('id', $nom)->update(['confirm_sends' => NC::MAX_SENDS]);

        $r = NC::ask($nom);
        self::assertFalse($r['ok']);
        self::assertSame('SEND_LIMIT', $r['code']);
        self::assertSame(NC::MAX_SENDS, (int) DB::table('gates_nominations')
            ->where('id', $nom)->value('confirm_sends'), 'a refused ask still counted');
    }

    /** Somebody already confirmed is never asked again. */
    public function test_a_confirmed_nominee_is_not_contacted_again(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $nom = $this->nomination($entry, 'Done already', NS::VERIFIED);
        NC::confirm($this->tokenFor($nom));

        self::assertSame('ALREADY_CONFIRMED', NC::ask($nom)['code']);
    }

    /**
     * No way to reach them is a fact about the nomination, not a retry.
     *
     * A challenge entry whose nominee cannot be asked can never count, and the
     * nominator has to learn that now rather than on the closing day.
     */
    public function test_a_nominee_with_no_contact_is_reported_rather_than_retried(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();

        $nom = (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => $this->cycle, 'nominee_name' => 'Unreachable',
            'nominator_name' => 'Someone', 'nominator_email' => 'a@b.test',
            'status' => NS::VERIFIED, 'challenge_entry_id' => $entry,
            'nominee_phone' => '', 'nominee_email' => '',
            'nominee_identity_hash' => hash('sha256', 'u'),
        ]);

        self::assertSame('NO_CHANNEL', NC::ask($nom)['code']);
    }

    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_link_is_long_enough_to_be_a_credential(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $token = $this->tokenFor($this->nomination($entry, 'Tokened', NS::VERIFIED));

        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $token);
        self::assertStringContainsString('/n/confirm/' . $token, NC::url($token));
    }

    /** Two nominations never share a token. */
    public function test_each_nomination_gets_its_own_link(): void
    {
        [$ch, $entry] = $this->challengeWithEntry();
        $a = $this->tokenFor($this->nomination($entry, 'One', NS::VERIFIED));
        $b = $this->tokenFor($this->nomination($entry, 'Two', NS::VERIFIED));

        self::assertNotSame($a, $b);
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** @return array{0:int,1:int} */
    private function challengeWithEntry(array $over = []): array
    {
        $ch = (int) DB::table('gates_challenges')->insertGetId($over + [
            'slug' => 'nc-' . bin2hex(random_bytes(4)), 'title' => 'P', 'kicker' => 'P',
            'action' => E::ACTION_NOMINATE, 'target' => 1, 'mode' => E::MODE_FIRST, 'cap' => 5,
            'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 100,
            'theme' => E::THEME_GREEN, 'status' => E::ST_OPEN,
        ]);

        $user = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Entrant', 'email' => 'e' . bin2hex(random_bytes(4)) . '@example.test',
            'status' => 'active',
        ]);

        $r = CS::join($ch, $user, '+23480' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
        self::assertTrue($r['ok'], 'fixture failed to join: ' . $r['code']);

        return [$ch, (int) $r['entry']->id];
    }

    private function nomination(int $entry, string $who, string $status): int
    {
        return (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => $this->cycle, 'nominee_name' => $who,
            'nominator_name' => 'Entrant', 'nominator_email' => 'entrant@example.test',
            'nominee_phone' => '+2348090000001', 'nominee_email' => 'nominee@example.test',
            'country_code' => 'NG',
            'status' => $status, 'challenge_entry_id' => $entry,
            'nominee_identity_hash' => hash('sha256', $who . $entry . random_int(1, 1000000)),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Mint through the service rather than by hand, so the shape is the real one. */
    private function tokenFor(int $nominationId): string
    {
        $m = new \ReflectionMethod(NC::class, 'mint');
        $m->setAccessible(true);

        $live = (string) (DB::table('gates_nominations')->where('id', $nominationId)
            ->value('nominee_confirm_token') ?? '');

        return $live !== '' ? $live : (string) $m->invoke(null, $nominationId);
    }
}
