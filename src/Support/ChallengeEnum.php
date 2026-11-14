<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Every word a challenge column may hold, beside the code that writes it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE LIST PER COLUMN, AND THE MIGRATION IS CHECKED AGAINST IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A value outside an `ENUM` is `Data truncated` on MySQL — not an error anybody
 * notices — and SQLite has no `ENUM` at all, so it stores whatever it is handed. A
 * sixth action added here and not to the column would pass every test on this
 * harness and land as an empty string on production, for ever.
 *
 * This codebase has shipped that three times, and each presented as something else:
 * a schedule screen filtering on a status its column has never allowed, a funnel
 * reading "0 — 0%" on every deployment since it shipped, and a moderation warning
 * that could not fire. `ChallengeSchemaWordsTest` compares these lists against the
 * migration, both directions.
 */
final class ChallengeEnum
{
    /** What a person does to make progress. */
    public const ACTION_NOMINATE = 'nominate';
    public const ACTION_VOTE     = 'vote';
    public const ACTION_REFER    = 'refer';
    public const ACTION_GIVE     = 'give';
    public const ACTION_ATTEND   = 'attend';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_NOMINATE, self::ACTION_VOTE, self::ACTION_REFER,
        self::ACTION_GIVE, self::ACTION_ATTEND,
    ];

    /** How winners are chosen. */
    public const MODE_FIRST = 'first';
    public const MODE_TOP   = 'top';
    public const MODE_DRAW  = 'draw';

    /** @var list<string> */
    public const MODES = [self::MODE_FIRST, self::MODE_TOP, self::MODE_DRAW];

    /** What a winner receives. */
    public const PRIZE_CASH_EACH = 'cash_each';
    public const PRIZE_CASH_POOL = 'cash_pool';
    public const PRIZE_POINTS    = 'points';
    public const PRIZE_TICKETS   = 'tickets';

    /** @var list<string> */
    public const PRIZE_TYPES = [
        self::PRIZE_CASH_EACH, self::PRIZE_CASH_POOL,
        self::PRIZE_POINTS, self::PRIZE_TICKETS,
    ];

    /**
     * The four theme presets, and no fifth.
     *
     * §0.5 of the handoff forbids a new colour, so a theme is a NAME that resolves to
     * tokens already in §2 — never a hex stored on the row. An art file may be any
     * picture; the chrome around it is one of these.
     */
    public const THEME_GREEN = 'green';
    public const THEME_BLUE  = 'blue';
    public const THEME_GOLD  = 'gold';
    public const THEME_ROSE  = 'rose';

    /** @var list<string> */
    public const THEMES = [self::THEME_GREEN, self::THEME_BLUE, self::THEME_GOLD, self::THEME_ROSE];

    /** Where a challenge is in its life. */
    public const ST_DRAFT     = 'draft';
    public const ST_UPCOMING  = 'upcoming';
    public const ST_OPEN      = 'open';
    public const ST_FULL      = 'full';
    public const ST_ENDED     = 'ended';
    public const ST_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::ST_DRAFT, self::ST_UPCOMING, self::ST_OPEN,
        self::ST_FULL, self::ST_ENDED, self::ST_CANCELLED,
    ];

    /** What a challenge may be scoped to. An action counts ONLY inside a scope. */
    public const SCOPE_CYCLE    = 'award_cycle';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_EVENT    = 'event';

    /** @var list<string> */
    public const SCOPE_TYPES = [self::SCOPE_CYCLE, self::SCOPE_CATEGORY, self::SCOPE_EVENT];

    /** Where one person's attempt stands. */
    public const E_ACTIVE       = 'active';
    public const E_QUALIFIED    = 'qualified';
    public const E_WON          = 'won';
    public const E_DISQUALIFIED = 'disqualified';
    public const E_WITHDRAWN    = 'withdrawn';

    /** @var list<string> */
    public const ENTRY_STATUSES = [
        self::E_ACTIVE, self::E_QUALIFIED, self::E_WON,
        self::E_DISQUALIFIED, self::E_WITHDRAWN,
    ];

    /** Whether the money has moved. */
    public const PAY_NONE    = 'none';
    public const PAY_PENDING = 'pending';
    public const PAY_PAID    = 'paid';
    public const PAY_FAILED  = 'failed';

    /** @var list<string> */
    public const PAYOUT_STATUSES = [
        self::PAY_NONE, self::PAY_PENDING, self::PAY_PAID, self::PAY_FAILED,
    ];

    /** Where a promo may be shown. */
    public const PLACEMENTS = ['account', 'nominate', 'home', 'vote', 'events', 'award', 'event'];

    /** Who sees a promo. */
    public const AUDIENCES = ['all', 'signed_in', 'signed_out'];
}
