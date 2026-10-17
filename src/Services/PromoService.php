<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the promo band shows, where, and to whom.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A PROMO POINTING AT A CHALLENGE NEVER STORES THE CHALLENGE'S STATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_promos.challenge_id` is a pointer and the chip beside the title is computed
 * on every read. The alternative — writing "Open" into the promo row when it is
 * created — is the shape this codebase has paid for four times: the sentence is true
 * the day it is typed and wrong the day the challenge fills, on a banner across five
 * placements telling people to join a race that is over.
 *
 * So a promo carries copy and a destination; everything that can change comes from
 * `ChallengeCopy`, which is the one place a challenge's words are generated.
 *
 * ── AND A DEAD PROMO IS NOT SHOWN, WHICH IS NOT THE SAME AS `active` ────────
 *
 * Four things can retire a promo and only one of them is a switch: `active = 0`, a
 * `starts_at` in the future, an `ends_at` in the past, and — the one that is easy to
 * miss — a challenge that has ENDED. A banner on five pages outliving the thing it
 * advertises is worse than no banner, because it is an invitation to a closed door.
 */
final class PromoService
{
    /**
     * The slides for one placement, highest priority first.
     *
     * @param string $placement one of `ChallengeEnum::PLACEMENTS`
     * @param bool   $signedIn  decides the `audience` filter
     * @return list<array<string,mixed>>
     */
    public static function forPlacement(
        string $placement,
        bool $signedIn,
        int $limit = 5,
        ?int $programmeId = null
    ): array {
        if (!in_array($placement, E::PLACEMENTS, true)) return [];
        if (!\AfricaGates\Support\SchemaHas::table('gates_promos')) return [];

        // ── THE `award` PLACEMENT IS SCOPED, AND REFUSES WITHOUT A SCOPE ─────────
        //
        // "Banners show on the Alimosho Awards pages, and on NO OTHER AWARD." A promo
        // placed on `award` with nothing saying which award is a banner on every award
        // page this platform runs — the Kenya Creative Economy Awards advertising a
        // challenge nobody in Kenya can enter, with a prize in naira.
        //
        // So the absence of a programme is a refusal rather than a wildcard. A caller
        // that forgets to pass one shows nothing, which is a missing banner somebody
        // reports; the other way round is a wrong banner on somebody else's award, which
        // nobody reports because it looks deliberate.
        if ($placement === 'award' && ($programmeId === null || $programmeId <= 0)) {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        $rows = DB::table('gates_promos')
            ->where('placement', $placement)
            ->where('active', 1)
            // `audience` is the promo's own choice: a "finish setting up" banner is
            // meaningless to somebody signed out, and a "create an account" one is
            // noise to somebody who has.
            ->whereIn('audience', ['all', $signedIn ? 'signed_in' : 'signed_out'])
            ->where(static fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(static fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('priority')->orderBy('id')
            ->limit(max(1, $limit) * 2)   // room to drop the ones whose challenge is over
            ->get();

        $out = [];

        foreach ($rows as $p) {
            $chip = ''; $chipState = '';

            if (!empty($p->challenge_id)) {
                $c = ChallengeService::find((int) $p->challenge_id);

                // A promo for a challenge that no longer exists is dropped rather than
                // shown with a dead link — the slug may have been reused.
                if (!$c) continue;

                $copy = ChallengeCopy::for((array) $c, [
                    'claimed' => ChallengeService::claimed((int) $c->id),
                ]);

                // Ended, draft or cancelled: the banner comes down by itself. Nobody has
                // to remember, which is the rule `PublicResults::delayed()` already
                // holds — a banner an operator must take down is still up in March.
                // The CLOCK, through the one resolver — not the stored column, which is
                // a cache the hourly sweep writes. A banner that stays up for the hour
                // between a challenge ending and the sweep noticing is an hour of
                // inviting people into a closed race on five pages.
                $live = \AfricaGates\Support\ChallengeWindow::status(
                    $c, null, ChallengeService::claimed((int) $c->id));

                if (in_array($live, [E::ST_DRAFT, E::ST_CANCELLED, E::ST_ENDED], true)) {
                    continue;
                }

                // And on an award page, only a challenge that actually counts inside
                // THIS award.
                if ($placement === 'award'
                    && !self::countsInProgramme((int) $c->id, (int) $programmeId)) {
                    continue;
                }

                $chip      = $copy['state_label'];
                $chipState = $copy['state'];
            }

            $out[] = [
                'id'      => (int) $p->id,
                'kicker'  => (string) ($p->kicker ?? ''),
                'title'   => (string) $p->title,
                'sub'     => (string) ($p->sub ?? ''),
                'cta'     => (string) ($p->cta ?? 'See more'),
                'href'    => (string) ($p->href ?? '#'),
                'theme'   => (string) ($p->theme ?: E::THEME_GREEN),
                'art'     => (string) ($p->art_url ?? ''),
                'chip'    => $chip,
                'chip_state' => $chipState,
            ];

            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /**
     * Does this challenge count inside this award programme?
     *
     * Walks `gates_challenge_scopes` the same way `ChallengeController::included()` does
     * — cycle scopes directly, category scopes through their cycle — and through the
     * `is_active` gate for the reason every reader here carries it: the sandbox lives in
     * an inactive programme, and a reader that walks the chain is safe without knowing
     * the sandbox exists.
     *
     * An EVENT scope is not a programme scope and answers false. A challenge about a gala
     * does not belong on an award's page just because the gala is part of the season.
     */
    private static function countsInProgramme(int $challengeId, int $programmeId): bool
    {
        if ($challengeId <= 0 || $programmeId <= 0) return false;

        try {
            $byCycle = DB::table('gates_challenge_scopes as s')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 's.scope_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('s.challenge_id', $challengeId)
                ->where('s.scope_type', E::SCOPE_CYCLE)
                ->where('p.id', $programmeId)->where('p.is_active', 1)
                ->exists();

            if ($byCycle) return true;

            return DB::table('gates_challenge_scopes as s')
                ->join('gates_award_categories as ct', 'ct.id', '=', 's.scope_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'ct.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('s.challenge_id', $challengeId)
                ->where('s.scope_type', E::SCOPE_CATEGORY)
                ->where('p.id', $programmeId)->where('p.is_active', 1)
                ->exists();
        } catch (\Throwable $e) {
            // A banner is decoration on somebody else's page. It fails closed.
            return false;
        }
    }

    /**
     * The same slides, shaped for `/api/promos`.
     *
     * Deliberately the SAME method underneath. Two readers of one placement is how the
     * band and the endpoint come to disagree about what is running — the fault this
     * codebase records as "two readers of one KEY", and the pair most likely to differ
     * is the one that publishes a value and the one that acts on it.
     */
    public static function payload(string $placement, bool $signedIn, ?int $programmeId = null): array
    {
        $slides = self::forPlacement($placement, $signedIn, 5, $programmeId);

        return ['placement' => $placement, 'count' => count($slides), 'slides' => $slides];
    }
}
