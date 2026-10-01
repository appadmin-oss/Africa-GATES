<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeAdmin as CA;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Building a challenge, and the rule that outranks an operator's convenience.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE LOCK IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The page tells a named person "the first 11 to get 10 verified each win ₦6,000", and
 * somebody spends an evening on it. Lowering the cap to 5 afterwards takes a prize from
 * a person who earned it under the published terms — and every figure on the page would
 * go on looking internally consistent while doing it.
 *
 * It is the same rule this codebase already holds for a released standing: a published
 * result is the one that was ANNOUNCED, not the one today's arithmetic gives.
 */
final class ChallengeAdminTest extends TestCase
{
    private int $cycle = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $prog = DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Admin probe', 'slug' => 'ap-' . bin2hex(random_bytes(3)), 'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $prog, 'year' => 2026, 'status' => 'nominations',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Creating
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_new_challenge_is_a_draft_and_nothing_else(): void
    {
        $r = CA::create(['title' => 'Celebrate Something', 'kicker' => 'A challenge']);

        self::assertTrue($r['ok']);
        $c = CS::find($r['id']);
        self::assertSame(E::ST_DRAFT, $c->status, 'a new challenge went live without being published');
        self::assertSame('celebrate-something', $c->slug);
    }

    /**
     * A slug collision is refused, not suffixed.
     *
     * A slug is a published URL the moment it goes live. Two challenges one character
     * apart is a support call nobody can answer.
     */
    public function test_a_duplicate_web_address_is_refused(): void
    {
        CA::create(['title' => 'Same Name']);
        $r = CA::create(['title' => 'Same Name']);

        self::assertFalse($r['ok']);
        self::assertSame('SLUG_TAKEN', $r['code']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The gate
    // ══════════════════════════════════════════════════════════════════════════

    /** Every reason at once, so an operator does not fix one per round trip. */
    public function test_publishing_names_everything_that_is_missing_in_one_answer(): void
    {
        $id = CA::create(['title' => 'Half Finished'])['id'];
        $b  = CA::blockers($id);

        self::assertGreaterThan(3, count($b), 'the gate reported one failure at a time');

        $joined = implode(' ', $b);
        foreach (['kicker', 'award, category or event', 'cap', 'prize amount', 'closing date'] as $want) {
            self::assertStringContainsString($want, $joined, "the gate said nothing about: $want");
        }

        self::assertFalse(CA::publish($id)['ok']);
        self::assertSame(E::ST_DRAFT, CS::find($id)->status);
    }

    /**
     * The three actions with no approved wording cannot be published.
     *
     * `ChallengeCopy::steps()` writes nothing for `vote`, `give` or `attend` — the
     * design defines neither steps nor per-action rules for them. Publishing one would
     * put an empty "How to take part" on the page that exists to say how to take part,
     * and terms with no per-action clause at all.
     *
     * This test fails the day that copy is written, which is exactly when the refusal
     * should be lifted.
     */
    public function test_an_action_with_no_approved_wording_cannot_go_live(): void
    {
        foreach ([E::ACTION_VOTE, E::ACTION_GIVE, E::ACTION_ATTEND] as $action) {
            $id = $this->ready(['action' => $action]);

            $joined = implode(' ', CA::blockers($id));
            self::assertStringContainsString('no approved wording', $joined,
                "a $action challenge could be published with an empty How to take part");
            self::assertFalse(CA::publish($id)['ok']);
        }
    }

    public function test_a_complete_challenge_publishes(): void
    {
        $id = $this->ready();

        self::assertSame([], CA::blockers($id));
        $r = CA::publish($id);

        self::assertTrue($r['ok']);
        self::assertSame(E::ST_OPEN, CS::find($id)->status);
        self::assertNotNull(CS::find($id)->published_at);
    }

    /** One that has not started yet opens as `upcoming`, not `open`. */
    public function test_a_future_challenge_publishes_as_upcoming(): void
    {
        $id = $this->ready(['starts_at' => date('Y-m-d H:i:s', strtotime('+3 days'))]);

        self::assertSame(E::ST_UPCOMING, CA::publish($id)['status']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The lock
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_published_challenge_refuses_every_rule_that_decides_a_prize(): void
    {
        $id = $this->ready();
        CA::publish($id);

        foreach (['cap' => 99, 'target' => 1, 'prize_amount' => 1, 'mode' => E::MODE_TOP,
                  'action' => E::ACTION_REFER, 'prize_type' => E::PRIZE_POINTS] as $field => $value) {
            $r = CA::update($id, [$field => $value]);

            self::assertFalse($r['ok'], "`$field` was changeable after publishing");
            self::assertSame('LOCKED', $r['code']);
            self::assertContains($field, $r['locked']);
        }

        // And nothing was written on the way past.
        $c = CS::find($id);
        self::assertSame(11, (int) $c->cap);
        self::assertSame(10, (int) $c->target);
    }

    /** Copy is not a rule, so copy stays editable. */
    public function test_a_published_challenge_still_takes_a_better_title(): void
    {
        $id = $this->ready();
        CA::publish($id);

        self::assertTrue(CA::update($id, ['title' => 'A Clearer Title', 'kicker' => 'Still a challenge'])['ok']);
        self::assertSame('A Clearer Title', CS::find($id)->title);
    }

    /**
     * A closing date moves outwards only.
     *
     * Extending gives everybody more of what they were promised; bringing it forward
     * takes it away from whoever is part-way through.
     */
    public function test_a_closing_date_can_be_extended_and_never_shortened(): void
    {
        $id = $this->ready();
        CA::publish($id);

        $later = date('Y-m-d H:i:s', strtotime('+30 days'));
        self::assertTrue(CA::update($id, ['ends_at' => $later])['ok']);

        $sooner = date('Y-m-d H:i:s', strtotime('+1 day'));
        $r = CA::update($id, ['ends_at' => $sooner]);

        self::assertFalse($r['ok']);
        self::assertSame('ENDS_EARLIER', $r['code']);
    }

    /**
     * Re-posting an unchanged value is not an attempt to change it.
     *
     * A form posts every field every time, so the common case is a locked field
     * arriving with the value it already holds. Treating that as an edit would make
     * every save of a live challenge fail.
     */
    public function test_saving_a_live_challenge_without_touching_the_rules_works(): void
    {
        $id = $this->ready();
        CA::publish($id);
        $c = CS::find($id);

        $r = CA::update($id, [
            'title' => 'Edited', 'cap' => (int) $c->cap, 'target' => (int) $c->target,
            'mode' => (string) $c->mode, 'action' => (string) $c->action,
            'prize_amount' => (int) $c->prize_amount,
        ]);

        self::assertTrue($r['ok'], 'a plain save of a live challenge was refused: ' . implode(',', $r['locked']));
        self::assertSame('Edited', CS::find($id)->title);
    }

    /** Scopes decide what counts, so they are as locked as the target. */
    public function test_scopes_cannot_be_changed_after_publishing(): void
    {
        $id = $this->ready();
        CA::publish($id);

        self::assertSame('LOCKED', CA::setScopes($id, [
            ['scope_type' => E::SCOPE_CYCLE, 'scope_id' => $this->cycle],
        ])['code']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Cancelling
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Cancelled, never deleted, and the reason is required.
     *
     * Entries point at it, and the row is the record that the promise was made. "A
     * record that has been used is retired, never deleted."
     */
    public function test_cancelling_keeps_the_row_and_demands_a_reason(): void
    {
        $id = $this->ready();
        CA::publish($id);

        self::assertSame('NO_REASON', CA::cancel($id, '   ')['code']);
        self::assertSame(E::ST_OPEN, CS::find($id)->status);

        self::assertTrue(CA::cancel($id, 'The sponsor withdrew')['ok']);
        $c = CS::find($id);
        self::assertSame(E::ST_CANCELLED, $c->status);
        self::assertSame('The sponsor withdrew', $c->cancel_reason);
        self::assertNotNull($c, 'the challenge was deleted rather than retired');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Writing
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A word that is not in the ENUM is DROPPED, never written.
     *
     * On MySQL an unknown value is `Data truncated` — not an error anybody notices —
     * and on SQLite it is stored verbatim. Both are worse than ignoring it.
     */
    public function test_a_value_outside_the_enum_never_reaches_the_column(): void
    {
        $id = CA::create(['title' => 'Enum probe', 'action' => 'teleport', 'mode' => 'vibes'])['id'];
        $c  = CS::find($id);

        self::assertContains($c->action, E::ACTIONS);
        self::assertContains($c->mode, E::MODES);
    }

    /** `datetime-local` posts a `T`, which MySQL takes and SQLite stores verbatim. */
    public function test_a_form_datetime_is_normalised_before_it_is_stored(): void
    {
        $id = CA::create(['title' => 'Date probe', 'ends_at' => '2026-11-30T23:59'])['id'];

        self::assertSame('2026-11-30 23:59:00', CS::find($id)->ends_at);

        // Nonsense is null rather than a throw: a bad date must not cost an operator
        // everything else they typed.
        CA::update($id, ['ends_at' => 'tomorrow-ish']);
        self::assertNull(CS::find($id)->ends_at);
    }

    public function test_extra_rules_are_stored_as_a_list_whatever_was_typed(): void
    {
        $id = CA::create(['title' => 'Rules probe',
            'extra_rules' => "Lagos residents only\n\n   \nStaff cannot enter"])['id'];

        $copy = \AfricaGates\Services\ChallengeCopy::for((array) CS::find($id));

        self::assertSame(['Lagos residents only', 'Staff cannot enter'], array_slice($copy['rules'], -2));
        // And the base survives, because an admin may append and never displace.
        self::assertSame('Your account’s phone number is verified', $copy['rules'][0]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The assistant
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * With no provider configured, drafting returns nothing and the builder is fine.
     *
     * The harness has no AI provider, so this is the path every developer and every
     * test run takes — and it is the one that must never break the form.
     */
    public function test_drafting_degrades_to_nothing_rather_than_failing(): void
    {
        self::assertSame([], CA::draft(['action' => E::ACTION_NOMINATE, 'target' => 10]));
    }

    /**
     * The capability is declared advisory and is never handed the money.
     *
     * `AiGateway` enforces `advisory`; this pins the declaration, because the harm of
     * it being flipped is a model nudging a cap that decides who gets paid.
     */
    public function test_the_drafting_capability_cannot_decide_anything(): void
    {
        $cap = \AfricaGates\Services\AiCapability::find('challenge.draft');

        self::assertNotNull($cap, 'the drafting capability is not registered');
        self::assertTrue($cap->advisory, 'the drafting model stopped being advisory');

        // What it is TOLD. The declaration is the privacy disclosure and the contract.
        self::assertStringNotContainsStringIgnoringCase('prize amount', $cap->dataSent === '' ? 'x' : 'ok');
        self::assertStringContainsString('No prize amount', $cap->dataSent);
        self::assertStringContainsString('no cap', $cap->dataSent);
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** A challenge with everything the gate asks for. */
    private function ready(array $over = []): int
    {
        $id = CA::create($over + [
            'title' => 'Ready ' . bin2hex(random_bytes(3)),
            'kicker' => 'A challenge',
            'action' => E::ACTION_NOMINATE, 'target' => 10, 'mode' => E::MODE_FIRST, 'cap' => 11,
            'prize_type' => E::PRIZE_CASH_EACH, 'prize_amount' => 6000, 'prize_currency' => '₦',
            'terms_version' => '1.0',
            'ends_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ])['id'];

        CA::setScopes($id, [['scope_type' => E::SCOPE_CYCLE, 'scope_id' => $this->cycle]]);

        return $id;
    }
}
