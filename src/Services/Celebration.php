<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Slug;

/**
 * WHETHER A PAGE MAY CELEBRATE, AND THE KEY THAT MAKES IT PLAY ONCE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A CELEBRATION NEEDS PERMISSION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The celebration this replaces (`celebrate.js`, destroyed 3 Oct 2026) refused a held or
 * delayed result by PLACEMENT: its loader sat inside the markup that names a winner, so a
 * page with no winner block loaded no celebration. That held only while every page was
 * written by somebody who knew it, and the pages that carried it were all destroyed
 * together (docs/handoff/inventory/_scripts.md, "MUST RESTORE"). The pages that will
 * celebrate again are rebuilt by three different phases, so the rule cannot live in their
 * markup any more. It lives here, and `partials/celebration.twig` asks it before drawing
 * anything — a page cannot include the partial and skip the question.
 *
 * The question is about the MOMENT, not the kind. Confetti on a withheld result announces
 * an award the platform is refusing to announce; confetti on "draft saved" teaches people
 * that the burst means nothing, and then it means nothing on the day somebody wins. So:
 *
 *   · a moment this site never celebrates (§7.8: saving drafts, settings, sign-in, add to
 *     cart, subscribe) is refused by name — {@see NEVER};
 *   · a moment must be one of the five the handoff names and must match its kind, so a
 *     nomination cannot borrow the winner's choreography;
 *   · money and tickets celebrate only once CONFIRMED — a gateway's "pending" is not a gift;
 *   · `win` is asked of the PUBLISHED result, through the one gate the result pages use
 *     ({@see PublicResults::category()} / {@see PublicResults::edition()}): released, not
 *     the sandbox, not held, laid over with the sealed standing — and the person being
 *     celebrated is the one that standing names. A results date that has passed is a
 *     promise, not an announcement (CLAUDE.md), so a delayed cycle is refused first and by
 *     its own reason.
 *
 * {@see refusal()} returns the REASON rather than a bare false, because a guard resting on
 * an accident of today's fixture (a missing winner row, an empty table) refuses for the
 * wrong reason and stops refusing the day the fixture changes. CelebrationTest asserts the
 * reason.
 *
 * ── WHO WIRES EACH MOMENT ───────────────────────────────────────────────────
 *
 *   award_won        the nominee page / award result        Phase 5 (VotePage, results)
 *   edition_won      the overall edition result             Phase 5
 *   vote_cast        vote done                              Phase 5
 *   nomination_sent  /nominate/success                      Phase 8
 *   gift_confirmed   /giving/success                        Phase 7
 *   ticket_confirmed the ticket page after checkout         Phase 7
 */
final class Celebration
{
    /** The handoff's five kinds (§7.8), in its order. */
    public const KINDS = ['win', 'vote', 'nominate', 'give', 'ticket'];

    public const SIZES = ['full', 'inline'];

    /**
     * The palette family each kind is drawn in — the DC's KIND table (kicker ink, its dot,
     * the card's tint) read as Support\Accent families, so the slots come from the one
     * palette rather than from five hand-picked hexes.
     *
     * Emitted per card as inline custom properties ({@see style()}), not as five modifier
     * blocks in the stylesheet: a page draws ONE kind, and a sheet holding all five would
     * charge every page that celebrates four colour families in ColourBudgetTest — which
     * counts a celebration by the kind its include names, through this table.
     */
    public const FAMILY = [
        'win'      => 'gold',
        'vote'     => 'green',
        'nominate' => 'green',
        'give'     => 'live',
        'ticket'   => 'info',
    ];

    /** The only moments that celebrate, and the kind each one is drawn as. */
    public const MOMENTS = [
        'award_won'        => 'win',
        'edition_won'      => 'win',
        'vote_cast'        => 'vote',
        'nomination_sent'  => 'nominate',
        'gift_confirmed'   => 'give',
        'ticket_confirmed' => 'ticket',
    ];

    /** §7.8 "Never celebrate", by name, so a page that asks for one is told why. */
    public const NEVER = ['draft_saved', 'settings_saved', 'signed_in', 'added_to_cart', 'subscribed'];

    /** Moments whose kind involves money or a seat: only once the record says it happened. */
    private const NEEDS_CONFIRMATION = ['vote_cast', 'gift_confirmed', 'ticket_confirmed'];

    public const R_NEVER         = 'this moment is never celebrated';
    public const R_UNKNOWN       = 'not a moment that celebrates';
    public const R_WRONG_KIND    = 'this moment is drawn as a different kind';
    public const R_UNCONFIRMED   = 'not confirmed yet';
    public const R_DELAYED       = 'the results date has passed and the award is not announced';
    public const R_UNRELEASED    = 'the result is not published';
    public const R_HELD          = 'the published result names nobody';
    public const R_NOT_THE_WINNER = 'the published result names somebody else';

    /** True when `$kind` may be drawn for this moment. */
    public static function allowed(string $kind, array $context): bool
    {
        return self::refusal($kind, $context) === null;
    }

    /**
     * Why a celebration is refused, or null when it may play.
     *
     * @param array{moment?:string, confirmed?:bool, category_id?:int, edition?:string,
     *              nominee_id?:int} $context
     */
    public static function refusal(string $kind, array $context): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            // A typo in a template is a bug to see, not a celebration to drop silently.
            throw new \InvalidArgumentException("Unknown celebration kind '$kind'");
        }

        $moment = (string) ($context['moment'] ?? '');
        if (in_array($moment, self::NEVER, true)) return self::R_NEVER;
        if (!isset(self::MOMENTS[$moment]))       return self::R_UNKNOWN;
        if (self::MOMENTS[$moment] !== $kind)     return self::R_WRONG_KIND;

        if (in_array($moment, self::NEEDS_CONFIRMATION, true) && ($context['confirmed'] ?? false) !== true) {
            return self::R_UNCONFIRMED;
        }

        return match ($moment) {
            'award_won'   => self::awardRefusal((int) ($context['category_id'] ?? 0),
                                                (int) ($context['nominee_id'] ?? 0)),
            'edition_won' => self::editionRefusal((string) ($context['edition'] ?? ''),
                                                  (int) ($context['nominee_id'] ?? 0)),
            default       => null,
        };
    }

    /**
     * The play-once key: `{kind}-{edition_slug}-{subject_slug}`, plus `-{user_id}` when
     * signed in (§7.8). The ONE place it is built — the partial asks for it through the
     * `celebration_seen_key()` Twig function, so two pages cannot spell one moment two ways
     * and have it play twice, or spell two moments one way and have the second never play.
     *
     * The parts are slugged, so the separator stays unambiguous.
     */
    public static function seenKey(string $kind, string $edition, string $subject, ?int $userId = null): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown celebration kind '$kind'");
        }

        $key = $kind . '-' . Slug::make($edition) . '-' . Slug::make($subject);

        return ($userId !== null && $userId > 0) ? $key . '-' . $userId : $key;
    }

    /**
     * The card's three colours for this kind, as data-driven custom properties pointing at
     * the palette (the `Accent::tileStyle()` pattern): `--cel-ink` the kicker's words,
     * `--cel-dot` its mark, `--cel-tint` the card's wash.
     */
    public static function style(string $kind): string
    {
        if (!isset(self::FAMILY[$kind])) {
            throw new \InvalidArgumentException("Unknown celebration kind '$kind'");
        }
        $f = Accent::families()[self::FAMILY[$kind]];

        return '--cel-ink:var(--ag-' . $f['ink'] . ');--cel-dot:var(--ag-' . $f['fill']
             . ');--cel-tint:var(--ag-' . $f['wash'] . ')';
    }

    /** The signed-in member, as every other per-member Twig function reads it. */
    public static function seenKeyForRequest(string $kind, string $edition, string $subject): string
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);

        return self::seenKey($kind, $edition, $subject, $id > 0 ? $id : null);
    }

    private static function awardRefusal(int $categoryId, int $nomineeId): ?string
    {
        // Delayed first, and by its own reason: the late page's whole job is to say nothing
        // is decided, so confetti there is the one contradiction a family refreshing on the
        // results date would screenshot.
        if (PublicResults::delayForCategory($categoryId) !== null) return self::R_DELAYED;

        $r = PublicResults::category($categoryId);
        if ($r === null)            return self::R_UNRELEASED;
        if ($r['held'] !== null)    return self::R_HELD;

        $winner = (int) ($r['winner']['nominee_id'] ?? 0);
        if ($winner < 1)            return self::R_HELD;

        return $winner === $nomineeId ? null : self::R_NOT_THE_WINNER;
    }

    private static function editionRefusal(string $editionSlug, int $nomineeId): ?string
    {
        $e = PublicResults::edition($editionSlug);
        if ($e === null) return self::R_UNRELEASED;

        // Provisional (an award in the edition is still withheld) and a dead heat at the
        // top both have no announced overall winner yet — `overallFor()` says so in words
        // on the page, and a burst beside those words would contradict them.
        $o = $e['overall'] ?? null;
        if ($o === null || !empty($o['provisional']) || !empty($o['dead_heat'])) return self::R_HELD;

        $winner = (int) ($o['winner']['nominee_id'] ?? 0);
        if ($winner < 1) return self::R_HELD;

        return $winner === $nomineeId ? null : self::R_NOT_THE_WINNER;
    }
}
