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

    /**
     * ── THE GAP EVERY FIXTURE IN THIS FILE HID ──────────────────────────────
     *
     * The three demo configs carry `ends` as a formatted STRING, because a design comp
     * has no database behind it. A real `gates_challenges` row carries `ends_at`, a
     * datetime, and nothing named `ends` at all.
     *
     * So on a live row the facts strip printed "Ends" with no value and a `top`
     * challenge promised "The top 3 by  win 5,000 points" — while all sixteen tests
     * above stayed green, because their inputs were the comp's. Found by rendering a
     * seeded row in a browser, not by reading.
     *
     * The same shape as a fixture that writes a vote counter and no ballot rows: the
     * test is scoring a different rule from the one that runs.
     */
    public function test_the_close_is_derived_from_the_stored_datetime(): void
    {
        $c = [
            'action' => E::ACTION_REFER, 'mode' => E::MODE_TOP, 'target' => 5, 'cap' => 3,
            'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 5000,
            'ends_at' => '2026-11-30 23:59:00', 'timezone' => 'Africa/Nairobi',
        ];

        $v = ChallengeCopy::for($c);

        // Stored UTC, shown in the challenge's own zone — so 23:59 UTC is 02:59 the
        // next morning in Nairobi, and the page says so rather than printing a time
        // nobody in that country would recognise as the deadline.
        self::assertSame('1 Dec, 02:59 EAT', $v['facts'][3]['v'],
            'the facts strip printed nothing for a row that has a close');
        self::assertSame(
            'The top 3 by 1 Dec win 5,000 points. Every paid ticket through your link counts.',
            $v['promise'],
            'the promise lost its deadline on a real row',
        );
    }

    /** The zone is the CHALLENGE's, not the server's. */
    public function test_the_close_is_printed_in_the_challenge_own_timezone(): void
    {
        $base = ['action' => E::ACTION_NOMINATE, 'mode' => E::MODE_FIRST, 'target' => 1,
                 'cap' => 5, 'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 10,
                 'ends_at' => '2026-11-30 23:00:00'];

        // A deadline printed in a zone the reader does not share is a deadline they
        // will miss, and this platform runs awards across several of them.
        self::assertStringContainsString('WAT',
            ChallengeCopy::for($base + ['timezone' => 'Africa/Lagos'])['facts'][3]['v']);
        self::assertStringContainsString('EAT',
            ChallengeCopy::for($base + ['timezone' => 'Africa/Nairobi'])['facts'][3]['v']);
    }

    /**
     * A promise with a blank where its deadline should be is worse than one without
     * the clause at all — it reads as a broken page, on the sentence somebody is
     * deciding by.
     */
    public function test_a_top_challenge_with_no_close_still_reads_as_a_sentence(): void
    {
        $v = ChallengeCopy::for([
            'action' => E::ACTION_REFER, 'mode' => E::MODE_TOP, 'target' => 5, 'cap' => 3,
            'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 5000,
        ]);

        self::assertSame(
            'The top 3 win 5,000 points. Every paid ticket through your link counts.',
            $v['promise'],
        );
        self::assertStringNotContainsString('by  win', $v['promise']);
    }

    /**
     * A draw with no date set still reads as English everywhere it appears.
     *
     * One stand-in phrase cannot fit every slot: "the closing date" gave
     * "20 winners drawn the closing date" in the meter and "The draw happens on on the
     * closing date" in the empty state. Both were live on `/challenges/teachers-day`
     * until a real row was rendered — the demo config carries `draw_date`, so every
     * test above supplied one.
     */
    public function test_a_draw_with_no_date_reads_as_a_sentence_in_every_slot(): void
    {
        $c = ChallengeDemo::get('teachers');
        unset($c['draw_date']);

        $v = ChallengeCopy::for($c);

        self::assertSame('20 winners drawn at the close', $v['meter_title']);
        self::assertSame('The draw happens after the close', $v['empty_title']);
        self::assertSame('Drawn after the close', $v['win_sub']);
        self::assertSame('The draw is recorded and published after the close',
            $v['rules'][array_key_last($v['rules'])],
            'the published rule lost its promise along with its date');

        foreach (['meter_title', 'empty_title', 'win_sub'] as $k) {
            self::assertStringNotContainsString(' on on ', $v[$k]);
            self::assertStringNotContainsString('drawn the ', $v[$k]);
        }
    }

    /** A stored `draw_at` is formatted in the challenge's zone, like the close. */
    public function test_a_stored_draw_datetime_is_formatted(): void
    {
        $c = ChallengeDemo::get('teachers');
        unset($c['draw_date']);
        $c['draw_at']  = '2026-10-14 10:00:00';
        $c['timezone'] = 'Africa/Lagos';

        self::assertSame('20 winners drawn 14 Oct', ChallengeCopy::for($c)['meter_title']);
    }

    /**
     * The "where" step never renders with a hole in it.
     *
     * The comp's config carries `award` and `cats` as strings; a real row carries
     * SCOPES and neither field. The first live render of this page therefore said
     * "For , in , with every field complete." under a heading telling somebody how to
     * take part — with all nineteen tests above green, because their fixture supplies
     * both strings.
     */
    public function test_the_where_step_never_has_a_hole_in_it(): void
    {
        $base = ['action' => E::ACTION_NOMINATE, 'mode' => E::MODE_FIRST, 'target' => 3,
                 'cap' => 5, 'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 100];

        $cases = [
            ['award' => 'Alimosho Awards 2026', 'cats' => 'Choral or Impact',
             'want' => 'For Alimosho Awards 2026, in Choral or Impact, with every field complete.'],
            ['award' => 'Alimosho Awards 2026', 'cats' => '',
             'want' => 'For Alimosho Awards 2026, with every field complete.'],
            ['award' => '', 'cats' => 'Choral or Impact',
             'want' => 'In Choral or Impact, with every field complete.'],
            ['award' => '', 'cats' => '', 'want' => 'With every field complete.'],
        ];

        foreach ($cases as $i => $case) {
            $v = ChallengeCopy::for($base + ['award' => $case['award'], 'cats' => $case['cats']]);

            self::assertSame($case['want'], $v['steps'][1]['s'], "case $i");
            // The shape of the fault, pinned directly: a sentence with nothing between
            // a preposition and its comma.
            self::assertStringNotContainsString('For ,', $v['steps'][1]['s']);
            self::assertStringNotContainsString('in ,', $v['steps'][1]['s']);
        }
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

    // ══════════════════════════════════════════════════════════════════════════
    // The flier's lines — ChallengeCopy::flier()
    // ══════════════════════════════════════════════════════════════════════════

    /** The seeded campaign's own lines, as the artwork prints them, numbers filled in. */
    public function test_the_flier_prints_the_campaigns_own_lines_with_the_numbers_filled(): void
    {
        $f = ChallengeCopy::flier(ChallengeDemo::get('nigeria') + [
            'headline'   => 'Know {target} people who make Alimosho proud?',
            'standfirst' => 'Nominate them for the Alimosho Awards. Once all {target} are verified, you win.',
            'tagline'    => 'Celebrate Nigeria, one name at a time',
        ], ['state' => E::ST_OPEN, 'claimed' => 0]);

        self::assertSame('Know 10 people who make Alimosho proud?', $f['headline']);
        self::assertSame('Nominate them for the Alimosho Awards. Once all 10 are verified, you win.', $f['standfirst']);
        self::assertSame('Celebrate Nigeria, one name at a time', $f['tagline']);
        self::assertSame('Independence Day challenge', $f['kicker']);
        self::assertSame('First 11 Winners', $f['tab']);
        self::assertSame('₦6,000', $f['prize_big']);
        self::assertSame('each', $f['prize_unit']);
        self::assertSame('Nominate 10, get them verified', $f['card_line']);
        self::assertSame('JOIN NOW', $f['cta']);
    }

    /**
     * A challenge nobody wrote flier lines for still makes a flier — from its title and
     * its generated promise, the same sentence the page leads with — rather than a
     * blank headline over a prize.
     */
    public function test_a_challenge_with_no_flier_lines_falls_back_to_its_own_title_and_promise(): void
    {
        $c = ChallengeDemo::get('nigeria');
        $f = ChallengeCopy::flier($c, ['state' => E::ST_OPEN, 'claimed' => 0]);

        self::assertSame('Celebrate Nigeria', $f['headline']);
        self::assertSame('Celebrate Nigeria', $f['tagline']);
        self::assertSame(ChallengeCopy::for($c, ['state' => E::ST_OPEN, 'claimed' => 0])['promise'], $f['standfirst']);
    }

    public function test_the_tab_names_who_wins_in_every_mode(): void
    {
        $c = ChallengeDemo::get('nigeria');
        $tab = static fn (array $o): string => ChallengeCopy::flier($o + $c, ['state' => E::ST_OPEN, 'claimed' => 0])['tab'];

        self::assertSame('First 11 Winners', $tab([]));
        self::assertSame('First 1 Winner', $tab(['cap' => 1]));
        self::assertSame('Top 5 Win', $tab(['mode' => E::MODE_TOP, 'cap' => 5]));
        self::assertSame('3 Winners Drawn', $tab(['mode' => E::MODE_DRAW, 'draw_count' => 3]));
        self::assertSame('1 Winner Drawn', $tab(['mode' => E::MODE_DRAW, 'draw_count' => 1]));
        self::assertSame('Every Finisher Wins', $tab(['cap' => 0]));
    }

    public function test_the_card_line_is_the_action_and_its_target(): void
    {
        $c = ChallengeDemo::get('nigeria');
        $line = static fn (array $o): string => ChallengeCopy::flier($o + $c, ['state' => E::ST_OPEN])['card_line'];

        self::assertSame('Nominate 10, get them verified', $line([]));
        foreach ([E::ACTION_VOTE => 'Vote in ', E::ACTION_REFER => 'Bring ', E::ACTION_GIVE => 'Give '] as $action => $verb) {
            $u = ChallengeCopy::for(['action' => $action] + $c, ['state' => E::ST_OPEN])['u'];
            self::assertSame($verb . '10 ' . $u, $line(['action' => $action]), $action);
        }
    }

    /**
     * The button follows the state. A flier for a race whose prizes are all claimed
     * that still says JOIN NOW sends people to a page that tells them they are too late.
     */
    public function test_the_button_follows_the_state(): void
    {
        $c = ChallengeDemo::get('nigeria');
        self::assertSame('JOIN NOW', ChallengeCopy::flier($c, ['state' => E::ST_OPEN, 'claimed' => 0])['cta']);
        self::assertSame('SEE THE AWARDS', ChallengeCopy::flier($c, ['state' => E::ST_OPEN, 'claimed' => 11])['cta']);
        self::assertSame('SEE THE RESULTS', ChallengeCopy::flier($c, ['state' => E::ST_ENDED, 'claimed' => 3])['cta']);
    }

    /** The address and its date, in the challenge's own zone — 22:59 UTC is the 15th in Lagos. */
    public function test_the_address_line_carries_the_date_in_the_challenges_zone(): void
    {
        $prev = getenv('APP_URL');
        putenv('APP_URL=https://afg.afrovanguard.org.ng');
        try {
            $c = ['starts_at' => '2026-09-30 23:00:00', 'ends_at' => '2026-10-15 22:59:59',
                  'timezone' => 'Africa/Lagos'] + ChallengeDemo::get('nigeria');
            self::assertSame('afg.afrovanguard.org.ng/challenges · ends 15 Oct',
                ChallengeCopy::flier($c, ['state' => E::ST_OPEN, 'claimed' => 0])['url_line']);
            self::assertSame('afg.afrovanguard.org.ng/challenges · starts 1 Oct',
                ChallengeCopy::flier($c, ['state' => E::ST_UPCOMING, 'claimed' => 0])['url_line']);
            self::assertSame('afg.afrovanguard.org.ng/challenges · ended',
                ChallengeCopy::flier($c, ['state' => E::ST_ENDED, 'claimed' => 0])['url_line']);
        } finally {
            putenv($prev === false ? 'APP_URL' : 'APP_URL=' . $prev);
        }
    }

    /** A placeholder nobody filled must never reach a printed flier. */
    public function test_no_flier_line_carries_an_unfilled_placeholder(): void
    {
        foreach (array_keys(ChallengeDemo::all()) as $key) {
            $f = ChallengeCopy::flier(ChallengeDemo::get($key) + [
                'headline' => '{target} {cap} {prize}', 'standfirst' => 'x {prize}', 'tagline' => '{cap}',
            ], ['state' => E::ST_OPEN, 'claimed' => 0]);
            foreach ($f as $k => $v) {
                self::assertDoesNotMatchRegularExpression('~\{[a-z_]+\}~', (string) $v, "$key.$k");
            }
        }
    }
}
