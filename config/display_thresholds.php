<?php
declare(strict_types=1);

/*
 * THE LOW-DATA THRESHOLDS — one table, read by Support\DisplayThreshold and nothing else
 * (design_handoff_home_giving/LOW-DATA-RULES.md §3).
 *
 * A public block below its minimum is REMOVED, heading and all; a figure below its
 * minimum is dropped for the fallback copy. Africa GATES is young, and "1 donor",
 * "0% raised" or a ledger of one anonymised row reads as a verdict on the cause rather
 * than as a fact about it. Kept here rather than inline so the numbers can be tuned
 * without touching the templates that obey them.
 */
return [
    'votes_public_total'     => 50,
    'votes_race_bar'         => 2,
    'leaderboard'            => 100,
    'gifts_count'            => 25,
    'gifts_this_week'        => 10,
    'campaign_pct_ring'      => 5,
    'campaign_feature_pct'   => 10,
    'campaign_feature_gifts' => 10,
    'words_count_badge'      => 5,
    'updates_count_badge'    => 2,
    'wall_quotes_min'        => 3,
    'wall_quotes_full'       => 6,
    'hero_slides_rotate'     => 2,
    'joins_ticker_7d'        => 10,
    'tickets_left_show'      => 50,
    'event_going_show'       => 20,
    'pulse_min_posts'        => 5,
    'days_left_show'         => 30,
    'related_campaigns_min'  => 2,
];
