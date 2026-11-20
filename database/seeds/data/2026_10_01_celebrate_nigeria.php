<?php
declare(strict_types=1);

/**
 * What an operator may change about the Celebrate Nigeria challenge BEFORE it is added,
 * and the handoff's value for each.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SEED HAS A FORM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The seed writes a PUBLISHED challenge, and the hourly sweep runs it the moment an
 * Alimosho Awards edition opens for nominations. Once it is published the target, the
 * cap, the prize and the start are LOCKED (`ChallengeAdmin::LOCKED`) — people are already
 * competing under them. So the only moment anybody can correct a number is before it
 * runs, and with the sweep in charge that moment did not exist: the handoff's figures
 * went live as typed, and the person who knew the prize had changed found out by reading
 * the public page.
 *
 * So the values live here, ONE copy, read by the seed (what it writes), by
 * `SeedReview` (the form on /admin/challenges, its validation and the stored edits) and
 * by `2027_02_22_challenge_flier_copy.php` (the flier lines, for a database where the
 * seed ran before they existed). And `review => true` holds the seed out of the sweep
 * until an operator has opened that form and pressed "Add the challenge".
 *
 * Kept OUT of `database/seeds/` itself: `SeedRunner` treats every `*.php` there as a seed.
 *
 * ── TYPES ────────────────────────────────────────────────────────────────────
 *
 *   text / textarea   a string, trimmed, at most `max` characters — the column's width.
 *   int               a whole number between `min` and `max`.
 *   when              a wall-clock time IN LAGOS, `Y-m-d H:i:s`. The seed converts it to
 *                     UTC; the form never asks anybody to do time-zone arithmetic.
 *   lines             one entry per line.
 *
 * `{target}`, `{cap}` and `{prize}` in a text are filled from the numbers, so changing the
 * cap cannot leave a summary or a headline promising the old one.
 *
 * @return array{review:bool, zone:string, fields:array<string,array{label:string,type:string,default:mixed,max?:int,min?:int,help?:string,group:string}>}
 */
return [
    'review' => true,
    'zone'   => 'Africa/Lagos',
    'fields' => [
        // ── The page ────────────────────────────────────────────────────────────
        'title'   => ['group' => 'The page', 'label' => 'Title', 'type' => 'text', 'max' => 200,
                      'default' => 'Celebrate Nigeria'],
        'kicker'  => ['group' => 'The page', 'label' => 'Kicker — the line above the title', 'type' => 'text', 'max' => 160,
                      'default' => 'Independence Day challenge'],
        'summary' => ['group' => 'The page', 'label' => 'Summary', 'type' => 'textarea', 'max' => 300,
                      'default' => 'Nominate {target} different people for the Alimosho Awards. The first '
                                 . '{cap} people to get {target} nominees verified win {prize}.'],
        'extra_rules' => ['group' => 'The page', 'label' => 'Extra rules — one per line', 'type' => 'lines', 'max' => 1200,
                      'default' => ['Nominees must live or work in Alimosho, Lagos.',
                                    'You cannot nominate yourself or the same person twice.']],

        // ── The rules — locked once it is added ─────────────────────────────────
        'target'       => ['group' => 'The rules', 'label' => 'Verified nominees each person needs', 'type' => 'int',
                           'min' => 1, 'max' => 999, 'default' => 10],
        'cap'          => ['group' => 'The rules', 'label' => 'How many people can win', 'type' => 'int',
                           'min' => 1, 'max' => 9999, 'default' => 11],
        'prize_amount' => ['group' => 'The rules', 'label' => 'Prize for each winner, in naira', 'type' => 'int',
                           'min' => 1, 'max' => 10000000, 'default' => 6000,
                           'help' => 'Whole naira. 6000 is ₦6,000.'],
        'starts_at'    => ['group' => 'The rules', 'label' => 'Opens (Lagos time)', 'type' => 'when',
                           'default' => '2026-10-01 00:00:00'],
        'ends_at'      => ['group' => 'The rules', 'label' => 'Closes (Lagos time)', 'type' => 'when',
                           'default' => '2026-10-15 23:59:59'],

        // ── The art ─────────────────────────────────────────────────────────────
        'art_url' => ['group' => 'The art', 'label' => 'Page artwork', 'type' => 'text', 'max' => 400,
                      'default' => '/assets/img/challenges/alimosho-celebrates-nigeria.png'],
        'art_alt' => ['group' => 'The art', 'label' => 'Describe the artwork', 'type' => 'text', 'max' => 200,
                      'default' => 'Àlímọ̀ṣọ́ celebrates Nigeria'],

        // ── The flier ───────────────────────────────────────────────────────────
        'headline'     => ['group' => 'The flier', 'label' => 'Headline', 'type' => 'text', 'max' => 160,
                           'default' => 'Know {target} people who make Alimosho proud?'],
        'standfirst'   => ['group' => 'The flier', 'label' => 'The line under it', 'type' => 'text', 'max' => 300,
                           'default' => 'Nominate them for the Alimosho Awards. Once all {target} are verified, you win.'],
        'tagline'      => ['group' => 'The flier', 'label' => 'Footer line', 'type' => 'text', 'max' => 120,
                           'default' => 'Celebrate Nigeria, one name at a time'],
        'portrait_url' => ['group' => 'The flier', 'label' => 'Portrait (a cut-out on a clear background)', 'type' => 'text', 'max' => 400,
                           'default' => '/assets/img/challenges/celebrate-nigeria-portrait.png'],
        'portrait_alt' => ['group' => 'The flier', 'label' => 'Describe the portrait', 'type' => 'text', 'max' => 200,
                           'default' => 'A young woman in a green and white headwrap, smiling and holding the Nigerian flag'],
    ],
];
