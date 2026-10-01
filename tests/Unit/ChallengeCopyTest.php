<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeCopy;
use AfricaGates\Support\ChallengeDemo;
use AfricaGates\Support\ChallengeEnum as E;
use PHPUnit\Framework\TestCase;

/**
 * The generated copy, against the three configurations the design was drawn with.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE ASSERTIONS ARE WHOLE SENTENCES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A test that checks the promise "contains the cap" passes on a sentence with the
 * words in the wrong order, the wrong preposition, or the prize unit doubled —
 * `str_contains` is the shape that let `camera=()` be asserted by name while the
 * scanner was dead. These are the terms of a contest that pays money, so they are
 * pinned as the reader will see them, apostrophes included.
 *
 * The three configs are the DC's own `CH` object, value for value. Between them they
 * reach every branch `derive()` has: two actions, three modes, three prize types,
 * capped and uncapped. {@see \AfricaGates\Support\ChallengeDemo}.
 *
 * ── PROVED BY BREAKING IT ───────────────────────────────────────────────────
 *
 * Every assertion here was watched failing before it was trusted passing, which this
 * repo requires of a new guard and has been burnt by skipping. Five breaks, and the
 * failure each one actually produced:
 *
 *   · cap 11 → 12 in `ChallengeDemo` — 3 failures. The promise named the new cap, and
 *     the derived state fell back to `open`, because 7 claimed is no longer the lot.
 *   · dropping the `cash_each` conditional from `promise()` — 2 failures, both reading
 *     "verified each win ₦6,000 each".
 *   · `target === 1 ? unit[0] : unit[1]` inverted — 8 failures: "10 different nominee"
 *     on every config, in the promise, the steps, the facts and the compact strip.
 *   · deleting one base rule from `rules()` — 5 failures, including both `extra_rules`
 *     cases and the three-unwritten-actions case, which is the point: the base is what
 *     those three have instead of a per-action clause.
 *   · the full-state `claimed` clamp removed — 1 failure, "7 of 11 prizes claimed"
 *     under a pill reading Full.
 */
final class ChallengeCopyTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════════════════
    // nigeria — nominate · first · cash_each · capped
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_nigeria_config_renders_the_designed_copy(): void
    {
        $v = ChallengeCopy::for(ChallengeDemo::get('nigeria'));

        self::assertSame(
            'The first 11 people to get 10 different nominees verified each win ₦6,000.',
            $v['promise'],
            'The promise is a term of the contest. It is the DC\'s sentence or it is wrong.',
        );
        self::assertSame('₦6,000', $v['prize_big']);
        self::assertSame('each', $v['prize_unit']);
        self::assertSame('different nominees', $v['u']);

        self::assertSame([
            ['k' => 'Prize',      'v' => '₦6,000 each'],
            ['k' => 'Winners',    'v' => 'First 11'],
            ['k' => 'To qualify', 'v' => '10 different nominees'],
            ['k' => 'Ends',       'v' => '15 Oct, 23:59 WAT'],
        ], $v['facts']);

        // 7 of 11 claimed on the fixture.
        self::assertTrue($v['has_meter']);
        self::assertSame('4 of 11 prizes left', $v['meter_title']);
        self::assertSame('7 of 11 prizes claimed', $v['meter_aria']);

        self::assertSame('What counts as verified', $v['count_title']);
        self::assertSame('Winners so far', $v['win_title']);
        self::assertSame('In the order they qualified', $v['win_sub']);
        self::assertSame('Nobody has qualified yet', $v['empty_title']);
        self::assertSame('14 days left', $v['time_left']);
        self::assertSame('1.2', $v['terms_version']);
    }

    public function test_the_nigeria_steps_name_the_award_and_the_categories(): void
    {
        $v = ChallengeCopy::for(ChallengeDemo::get('nigeria'));

        self::assertSame([
            ['n' => '1', 't' => 'Sign in or create your account',
             's' => 'Free. Verify your phone number with a code.'],
            ['n' => '2', 't' => 'Nominate 10 different nominees',
             's' => 'For Alimosho Awards 2026, in Choral, Business or Impact, with every field complete.'],
            ['n' => '3', 't' => 'Get them verified',
             's' => 'Each nominee confirms, and our team checks the details.'],
        ], $v['steps']);
    }

    public function test_the_nigeria_rules_are_the_base_the_action_and_the_ordering(): void
    {
        $v = ChallengeCopy::for(ChallengeDemo::get('nigeria'));

        self::assertSame([
            'Your account’s phone number is verified',
            'One entry per person and per phone number',
            '10 different people, none of them you',
            'Every field is complete: full name, phone, email, category and a reason of at least 40 characters',
            'Each nominee confirms by SMS or WhatsApp that they are real and agree to be nominated',
            'Our team checks each nomination. Fake or copied details disqualify the whole entry',
            'Prizes go in the order entries are fully verified, not the order they were sent',
        ], $v['rules']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // teachers — nominate · draw · tickets · uncapped
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_teachers_config_renders_the_draw_copy(): void
    {
        $v = ChallengeCopy::for(ChallengeDemo::get('teachers'));

        self::assertSame(
            '20 people drawn at random from everyone who gets 5 different nominees verified win 2 gala tickets.',
            $v['promise'],
        );
        self::assertSame('2', $v['prize_big']);
        self::assertSame('gala tickets', $v['prize_unit']);

        // A draw has no race, so there is no meter to fill.
        self::assertFalse($v['has_meter']);
        self::assertSame('20 winners drawn 14 Oct', $v['meter_title']);

        self::assertSame('20, by draw', $v['facts'][1]['v']);
        self::assertSame('Draw on 14 Oct', $v['win_sub']);
        self::assertSame('The draw happens on 14 Oct', $v['empty_title']);
        self::assertSame(
            'Everyone who gets 5 different nominees verified before the close goes into the draw.',
            $v['empty_sub'],
        );
        self::assertSame(
            'The draw is recorded and published on 14 Oct',
            $v['rules'][array_key_last($v['rules'])],
        );
        self::assertSame(
            'Tickets are sent to your account and by SMS within a day of the draw.',
            $v['faq'][1]['a'],
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // gala — refer · top · points
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_gala_config_renders_the_referral_copy(): void
    {
        $v = ChallengeCopy::for(ChallengeDemo::get('gala'));

        self::assertSame(
            'The top 3 by 30 Nov win 5,000 points. Every paid ticket through your link counts.',
            $v['promise'],
            'The deadline in the promise drops the minute and the zone, as the DC does.',
        );
        self::assertSame('5,000', $v['prize_big']);
        self::assertSame('points', $v['prize_unit']);
        self::assertSame('paid tickets', $v['u']);

        // A referral challenge counts tickets, not verifications, and says so.
        self::assertSame('What counts', $v['count_title']);
        self::assertSame('Leading now', $v['win_title']);
        self::assertSame('Updated every hour', $v['win_sub']);
        self::assertSame('Top 3', $v['facts'][1]['v']);
        self::assertFalse($v['has_meter']);
        self::assertSame('Top 3 win', $v['meter_title']);

        self::assertSame([
            'Your account’s phone number is verified',
            'One entry per person and per phone number',
            'Tickets bought through your link or code',
            'Paid, not reserved. Refunded tickets are removed',
            'Buying tickets for yourself through your own link doesn’t count',
            'Ranked by verified count at the close. Ties go to whoever reached the count first',
        ], $v['rules']);

        self::assertSame(
            'Points land in your account within a day of the close and can be redeemed for votes.',
            $v['faq'][1]['a'],
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The one control, in all five states
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_cta_follows_the_state_and_the_action(): void
    {
        $n = ChallengeDemo::get('nigeria');
        $g = ChallengeDemo::get('gala');

        $open = ChallengeCopy::for($n, ['state' => 'open', 'signed_in' => false]);
        self::assertSame('Sign in to join', $open['cta']);
        self::assertSame(
            'Free to join. You need an account with a verified phone number.',
            $open['cta_note'],
        );
        // Somebody who pressed this is mid-task; the sign-in comes back here.
        self::assertStringContainsString('next=', $open['cta_href']);
        self::assertStringContainsString('celebrate-nigeria', rawurldecode($open['cta_href']));

        $joined = ChallengeCopy::for($n, ['state' => 'open', 'signed_in' => true]);
        self::assertSame('Add a nominee', $joined['cta']);
        self::assertSame('Track your progress in your account.', $joined['cta_note']);

        // The same state on a referral challenge asks for a different thing entirely.
        $ref = ChallengeCopy::for($g, ['state' => 'open', 'signed_in' => true]);
        self::assertSame('Get my link', $ref['cta']);

        $up = ChallengeCopy::for($n, ['state' => E::ST_UPCOMING]);
        self::assertSame('Remind me', $up['cta']);
        self::assertSame('We’ll send one SMS when it opens.', $up['cta_note']);
        self::assertSame('Starts soon', $up['state_label']);

        $end = ChallengeCopy::for($n, ['state' => E::ST_ENDED]);
        self::assertSame('See the results', $end['cta']);
        self::assertSame('Thanks to everyone who took part.', $end['cta_note']);
        self::assertSame('Closed', $end['time_left']);
        self::assertSame('Ended', $end['facts'][3]['k'], 'The fact label flips once it is over.');
        self::assertFalse($end['cta_primary']);
    }

    /**
     * The cap being reached is a fact about the rows, not a word in a column.
     *
     * A sweep writes `status = 'full'` at some point after the eleventh person
     * qualifies. Between those two moments the page must not go on inviting people
     * into a race that is over, so the state is derived as the DC derives it.
     */
    public function test_a_first_mode_challenge_reads_full_the_moment_the_cap_is_reached(): void
    {
        $n = ChallengeDemo::get('nigeria');

        $v = ChallengeCopy::for($n, ['state' => 'open', 'claimed' => 11, 'signed_in' => true]);

        self::assertSame('full', $v['state']);
        self::assertSame('Full', $v['state_label']);
        self::assertSame('See the awards', $v['cta']);
        self::assertSame('All 11 prizes claimed', $v['meter_title']);
        self::assertSame('Challenge full', $v['time_left']);
        self::assertSame('All claimed', $v['bar_sub']);
        self::assertFalse($v['cta_primary'], 'A full challenge offers a way on, not a way in.');

        // Said because it is true and because it is what somebody who just missed out
        // needs to hear.
        self::assertSame(
            'All 11 prizes are taken. Your nominations still count for the awards.',
            $v['cta_note'],
        );
    }

    /**
     * A challenge marked full renders its meter full, whatever the count says.
     *
     * The DC's `claimed = full && capped ? c.cap : c.claimed`. Without it, a race that
     * closed on a disqualification draws a pill reading Full beside "4 of 11 prizes
     * left" — two statements on one card contradicting each other.
     */
    public function test_a_full_challenge_never_draws_prizes_still_left(): void
    {
        $v = ChallengeCopy::for(ChallengeDemo::get('nigeria'), ['state' => E::ST_FULL]);

        self::assertSame('All 11 prizes claimed', $v['meter_title']);
        self::assertSame('11 of 11 prizes claimed', $v['meter_aria']);
        self::assertSame(11, $v['claimed']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // What an admin may and may not do to the words
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * "Admins may append rules; they can never remove the generated base."
     *
     * The published terms and the code enforcing them are the same claim, and the code
     * is what decides who gets paid. An extra rule is appended last and the base comes
     * out of this method whatever the row says.
     */
    public function test_extra_rules_are_appended_and_the_base_cannot_be_displaced(): void
    {
        $c = ChallengeDemo::get('nigeria');
        $c['extra_rules'] = ['Lagos residents only', '   ', 'Staff and their families cannot enter'];

        $v = ChallengeCopy::for($c);

        self::assertSame('Your account’s phone number is verified', $v['rules'][0]);
        self::assertSame('One entry per person and per phone number', $v['rules'][1]);
        self::assertSame(
            ['Lagos residents only', 'Staff and their families cannot enter'],
            array_slice($v['rules'], -2),
            'An admin pressing "add a rule" and leaving it blank must not publish an empty bullet.',
        );
        self::assertCount(9, $v['rules']);
    }

    /** The column holds JSON; a fixture holds an array. Both are the same rule list. */
    public function test_extra_rules_are_read_from_json_as_well_as_from_an_array(): void
    {
        $c = ChallengeDemo::get('nigeria');
        $c['extra_rules'] = json_encode(['Lagos residents only'], JSON_THROW_ON_ERROR);

        self::assertSame('Lagos residents only', ChallengeCopy::for($c)['rules'][7]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The edges the DC's own fixture never reaches
    // ══════════════════════════════════════════════════════════════════════════

    /** "1 paid tickets" is the sentence that makes a campaign look unfinished. */
    public function test_a_target_of_one_is_singular(): void
    {
        $c = ['action' => E::ACTION_REFER, 'mode' => E::MODE_FIRST, 'target' => 1, 'cap' => 5,
              'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 100];

        self::assertSame('paid ticket', ChallengeCopy::for($c)['u']);
        self::assertSame('1 paid ticket', ChallengeCopy::for($c)['facts'][2]['v']);
    }

    /**
     * `cash_each` carries "each" in its unit, so the promise does not repeat it.
     *
     * A pool prize does take the unit, and the two sentences must not converge.
     */
    public function test_a_pooled_cash_prize_says_shared_and_a_per_head_one_does_not(): void
    {
        $base = ['action' => E::ACTION_NOMINATE, 'mode' => E::MODE_FIRST, 'target' => 3,
                 'cap' => 5, 'prize_amount' => 50000, 'prize_currency' => '₦'];

        self::assertSame(
            'The first 5 people to get 3 different nominees verified each win ₦50,000.',
            ChallengeCopy::for($base + ['prize_type' => E::PRIZE_CASH_EACH])['promise'],
        );
        self::assertSame(
            'The first 5 people to get 3 different nominees verified each win ₦50,000 shared.',
            ChallengeCopy::for($base + ['prize_type' => E::PRIZE_CASH_POOL])['promise'],
        );
    }

    /**
     * The countdown is computed from the stored close, never from a typed number.
     *
     * A "days left" column is right the day it is saved and wrong every day after, on
     * a page that prints it as a promise. And "1 days left" is how a countdown
     * announces that nobody read it — on the last day, which is the day most people do.
     */
    public function test_the_countdown_is_read_from_the_close_and_is_never_plural_at_one(): void
    {
        $c = ChallengeDemo::get('nigeria');          // its fixture says 14 days
        $c['ends_at'] = '2026-10-15 23:59:00';

        self::assertSame('14 days left',
            ChallengeCopy::for($c, ['now' => '2026-10-01 10:00:00'])['time_left']);
        self::assertSame('1 day left',
            ChallengeCopy::for($c, ['now' => '2026-10-14 10:00:00'])['time_left']);
        self::assertSame('Closes today',
            ChallengeCopy::for($c, ['now' => '2026-10-15 10:00:00'])['time_left']);

        // A date the fixture cannot parse falls back to the stored figure rather than
        // printing a negative countdown.
        $c['ends_at'] = 'not a date';
        self::assertSame('14 days left', ChallengeCopy::for($c)['time_left']);
    }

    /**
     * ── AN OPEN QUESTION FOR THE DESIGNER, PINNED SO IT IS NOT MISTAKEN FOR A BUG ──
     *
     * `derive()` defines steps and per-action rules for `nominate` and `refer` only,
     * and falls back to `[]` for the other three actions `ChallengeEnum` allows. So a
     * `vote`, `give` or `attend` challenge renders an empty "How to take part" on the
     * page somebody opens to find out how to take part — and, worse, publishes terms
     * with no per-action clause at all.
     *
     * The handoff's rule is "if something can't be matched, stop and ask", so this
     * asserts the DC's answer rather than inventing three sentences nobody reviewed.
     * **The admin builder must refuse to publish those actions until it is answered**;
     * this test is the record of why, and will fail the moment copy is written for
     * them — which is the point at which the refusal can be lifted.
     */
    public function test_three_actions_have_no_designed_copy_and_must_not_be_publishable(): void
    {
        foreach ([E::ACTION_VOTE, E::ACTION_GIVE, E::ACTION_ATTEND] as $action) {
            $v = ChallengeCopy::for([
                'action' => $action, 'mode' => E::MODE_FIRST, 'target' => 3, 'cap' => 5,
                'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 100,
            ]);

            self::assertSame([], $v['steps'], $action . ': the DC writes no steps for this action');
            self::assertCount(3, $v['rules'], $action . ': base + ordering only, no action clause');
        }

        // `attend` is not even in the DC's unit map, so its promise would read
        // "3 different nominees" — a sentence about the wrong activity entirely.
        self::assertSame(
            'different nominees',
            ChallengeCopy::for(['action' => E::ACTION_ATTEND, 'target' => 3])['u'],
            'attend falls back to the nominate unit; it needs its own before it ships',
        );
    }

    /** The strip beside an award cannot promise a different prize from the page. */
    public function test_the_compact_strip_agrees_with_the_page(): void
    {
        $c = ChallengeDemo::get('nigeria');
        $s = ChallengeCopy::strip($c);
        $v = ChallengeCopy::for($c);

        self::assertSame('Celebrate Nigeria', $s['title']);
        self::assertSame('₦6,000 each · 10 different nominees', $s['line']);
        self::assertSame($v['prize_big'] . ' ' . $v['prize_unit'] . ' · 10 ' . $v['u'], $s['line']);
        self::assertSame('/challenges/celebrate-nigeria', $s['href']);
        self::assertSame($v['cta'], $s['cta']);
    }

    /** Every demo config renders every key, with nothing reading "null" or "0 of". */
    public function test_no_config_renders_a_placeholder_or_an_unset_value(): void
    {
        foreach (array_keys(ChallengeDemo::all()) as $key) {
            foreach (['open', E::ST_UPCOMING, E::ST_ENDED, E::ST_FULL] as $state) {
                $v = ChallengeCopy::for(ChallengeDemo::get($key), ['state' => $state]);

                foreach (['promise', 'meter_title', 'cta', 'cta_note', 'time_left', 'bar_sub',
                          'empty_title', 'empty_sub', 'win_sub'] as $k) {
                    self::assertIsString($v[$k], "$key/$state/$k");
                    self::assertNotSame('', trim($v[$k]), "$key/$state/$k is empty");
                    self::assertStringNotContainsStringIgnoringCase('null', $v[$k], "$key/$state/$k");
                    self::assertStringNotContainsString('  ', $v[$k], "$key/$state/$k double-spaced");
                }
            }
        }
    }
}
