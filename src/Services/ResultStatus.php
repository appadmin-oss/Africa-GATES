<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * WHERE ONE AWARD STANDS RIGHT NOW, IN ONE VOCABULARY, FOR EVERY LEVEL OF /results.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A CLASS AND NOT A MATCH BLOCK IN EACH TEMPLATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/results`, `/results/{edition}` and `/results/{slug}` all answer the same question a
 * reader arrives with — is this decided, is it still counting, is the panel still
 * working, or is it being held back — and the fastest way to get that wrong is to let
 * three screens each keep their own list of the answers.
 *
 * This platform has already paid for that once. `JudgeSchedule` filtered the schedule
 * screen on a status `'scheduled'` that `gates_interviews.status` has never allowed, so
 * the one screen whose job is listing sittings matched nothing on production, while
 * `'draft'` was missing from the same list and never appeared at all. Two lists claimed
 * the same thing in the same words and only one of them was right. One resolver, never
 * two.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT DECIDES NOTHING — IT NAMES WHAT TWO OTHER AUTHORITIES ALREADY DECIDED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The phase comes from {@see CyclePolicy::phaseFor()}, which derives it from the cycle's
 * own date windows rather than from `gates_award_cycles.status` — a column that is a
 * materialised cache and has been wrong on production. Withholding comes from
 * {@see PublicResults}'s `HELD_*` reasons, which are the platform's own account of why a
 * released award still has no public page.
 *
 * Nothing here re-derives either. It maps them onto the four words a reader is shown, so
 * a change to the lifecycle cannot leave this saying something the lifecycle stopped
 * meaning.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * `publishesStanding` IS THE RULE THAT MATTERS, AND IT LIVES HERE FOR THAT REASON
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * NO LEADER IS PUBLISHED FOR AN AWARD WHILE ITS VOTING IS OPEN — at any level, in any
 * column, in any sentence. Publishing a running order turns an open window into a
 * bandwagon, and on a platform that sells vote packs it turns a result page into a sales
 * page: a nominee's supporters would be able to see exactly how many votes buy the lead,
 * and the platform would be the one telling them.
 *
 * Vote COUNTS are published — those are a measure of participation and a nominee is
 * entitled to see their own support — and standings are not. The distinction is the whole
 * point, and it is carried on the status rather than remembered by three templates,
 * because a rule each screen has to remember is a rule one screen will forget.
 */
final class ResultStatus
{
    /** Voting is open. Counts are public; the order is not. */
    public const COUNTING = 'counting';

    /** Voting has closed and the panel is marking. */
    public const JUDGING = 'judging';

    /** Announced, but this award has no public page — see PublicResults::HELD_*. */
    public const WITHHELD = 'withheld';

    /** Decided, announced, and on a page. */
    public const DECIDED = 'decided';

    /** Nothing has opened yet. Named rather than omitted: a reader who followed a link
     *  to a cycle that has not started is owed the same sentence as everybody else. */
    public const PENDING = 'pending';

    /**
     * The four statuses in reading order — open first, because "what is open now" is what
     * brings somebody here and "what happened in 2025" is what brings them back.
     *
     * Used to ORDER a list, so it is a list and not a lookup: the index is the sort key.
     */
    public const ORDER = [self::COUNTING, self::JUDGING, self::DECIDED, self::WITHHELD,
                          self::PENDING];

    /**
     * One award's standing.
     *
     * @param ?string $held a {@see PublicResults} `HELD_*` reason, or null
     * @return array{key:string, label:string, meaning:?string, publishes_standing:bool,
     *               sort:int, note:string}
     */
    public static function forAward(CyclePhase $phase, ?string $held = null): array
    {
        // WITHHELD OUTRANKS THE PHASE, and only in this direction. A held award sits in a
        // cycle that has been announced, so its phase says `results` — reading the phase
        // first would put "Decided" beside an award with no result on it, which is the
        // one sentence this platform must never print.
        if ($held !== null && $held !== '') {
            return self::make(self::WITHHELD, 'Withheld', 'withheld', false, (string) $held);
        }

        return match ($phase) {
            CyclePhase::Voting
                => self::make(self::COUNTING, 'Counting', 'counting', false,
                    'Voting is open. Vote counts are published while it runs; the standing '
                  . 'is not, because a running order published during an open window is a '
                  . 'bandwagon.'),

            CyclePhase::Judging
                => self::make(self::JUDGING, 'With the panel', null, false,
                    'Voting has closed and the panel is marking.'),

            CyclePhase::Results, CyclePhase::Archived => self::decided(),

            default
                => self::make(self::PENDING, 'Not open yet', null, false,
                    'This award has not opened.'),
        };
    }

    /**
     * A whole edition's standing, from its awards'.
     *
     * An edition is decided when it has at least one decided award — not when every award
     * is. The alternative reads well and is wrong: one award held back for verification
     * would put an entire announced edition under "Withheld", so eleven results a reader
     * can open would be filed under a word meaning they cannot.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND A DRAWN AWARD IS PROOF OF AN ANNOUNCEMENT, WHICH NO DATE MAY UNSAY
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This used to ask the phase even when awards had been drawn, and it was wrong on
     * real data the first time it was rendered: a cycle materialised to `results`, with
     * six published awards and a named winner, carried a `results_date` still in the
     * future and no voting windows at all — so {@see CyclePolicy::phaseFor()} correctly
     * answered `Upcoming` and this page printed "Not open yet" over an edition whose
     * results a reader could already open.
     *
     * The phase is authoritative about what may HAPPEN to a cycle — may a vote be cast,
     * may a scorecard be filed — and it is right to derive that from the calendar rather
     * than from a column a dead scheduler writes. It is not authoritative about what has
     * ALREADY happened. {@see PublicResults::category()} returning a drawn award means
     * {@see CycleMaterialiser} crowned and announced it, in a transaction, and a date
     * sitting in the future cannot make that untrue. So a decided count settles this
     * before the phase is consulted at all.
     *
     * @param ?string $held any HELD_* reason, to answer for a fully-withheld edition
     * @return array{key:string, label:string, meaning:?string, publishes_standing:bool,
     *               sort:int, note:string}
     */
    public static function forEdition(CyclePhase $phase, int $decided, ?string $held = null): array
    {
        if ($decided > 0) return self::decided();

        return self::forAward($phase, $held);
    }

    /**
     * Decided, without asking the calendar.
     *
     * For the one caller that already HAS the proof — a drawn award — and for which the
     * phase can only introduce a contradiction. See {@see forEdition()}.
     *
     * @return array{key:string, label:string, meaning:?string, publishes_standing:bool,
     *               sort:int, note:string}
     */
    public static function decided(): array
    {
        return self::make(self::DECIDED, 'Decided', null, true, '');
    }

    /** @return array{key:string,label:string,meaning:?string,publishes_standing:bool,sort:int,note:string} */
    private static function make(string $key, string $label, ?string $meaning,
                                 bool $publishes, string $note): array
    {
        return [
            'key'   => $key,
            'label' => $label,
            // An Accent MEANING, never a role and never a hue — Accent::for() throws on a
            // meaning nobody defined, so a typo here stops the page rather than painting
            // "withheld" in the colour of "counting".
            //
            // `judging` and `decided` deliberately have none. A panel's progress is a
            // statement about OUR work and gets a neutral bar; a decided award is plain
            // ink and a date, because on a page that is mostly good news the one thing
            // that must not be the loudest is the absence of a result.
            'meaning'            => $meaning,
            'publishes_standing' => $publishes,
            'sort'               => (int) array_search($key, self::ORDER, true),
            'note'               => $note,
        ];
    }
}
