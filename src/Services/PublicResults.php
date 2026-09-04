<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * WHAT THE PUBLIC MAY SEE OF A RESULT, AND WHEN.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Until now the answer was "nothing". A cycle reached `results`, {@see CycleMaterialiser}
 * promoted a winner, {@see CycleAnnouncer} emailed them a link to `/leaderboard` — and
 * `/leaderboard` is a ranking of registry PROFILES by their rolled-up index. It does not
 * name the category, does not name the award, and does not say who won it. The single most
 * important thing this platform produces had no page.
 *
 * Which also meant the arithmetic had no page. The release screen shows every step of a CPI
 * and it is behind the admin login, so the only people who could check a result were the
 * people who published it. On a platform whose entire promise is that money cannot buy a
 * ranking, that is the wrong way round.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE GATES, AND WHY EACH ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. THE CYCLE HAS RELEASED. `results` or `archived`, or a `results_date` that has passed.
 *    A judged-but-unreleased category is a decided award nobody has announced, and serving
 *    it publicly is announcing it.
 *
 * 2. THE SANDBOX CANNOT REACH IT. Not by name prefix — {@see DemoSeeder::notSandbox()},
 *    through the programme, the same door every other public reader takes. A rehearsal
 *    result is a real row with real flags precisely so the rehearsal is real.
 *
 * 3. IT IS A RESULT THIS PLATFORM CAN STAND BEHIND. A category whose community half is
 *    dark was decided by the panel alone at a weight nobody agreed to; the promotion now
 *    refuses to crown one, and this refuses to publish one, so neither half of the system
 *    can be the only thing standing between a broken index and the public.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT COMPUTES NOTHING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every figure comes from {@see ResultRelease::category()} — the same call, on the same
 * scorer, that the admin release screen audits. A public page that worked the numbers out
 * its own way could disagree with the screen an operator signed off, and the disagreement
 * would surface as a member arguing with an administrator about which of two Africa GATES
 * pages was lying. There is one scorer and one drawer of results on this platform.
 */
final class PublicResults
{
    /** A released cycle is one of these, whatever its dates say. */
    public const RELEASED = ['results', 'archived'];

    /**
     * Reasons a released category still has no public page. Returned rather than thrown so
     * the index can COUNT them: "four of this cycle's categories are still being verified"
     * is a fact the public is entitled to, and silence is how a withheld award becomes a
     * rumour.
     */
    public const HELD_DARK    = 'the community vote has not been counted into this result yet';
    public const HELD_NOBODY  = 'no nominee here has met the judging quorum';

    /**
     * One category, drawn for the public, or null when there is nothing to show.
     *
     * @return array<string,mixed>|null Adds to {@see ResultRelease::category()}:
     *   `held` (a HELD_* reason or null), `programme`, `cycle_year`, `edition`,
     *   `slug`, `url`, `released_at`.
     */
    public static function category(int $categoryId): ?array
    {
        $ctx = self::context($categoryId);
        if ($ctx === null) return null;

        $drawn = ResultRelease::category($categoryId);
        if ($drawn['category'] === null) return null;

        return $drawn + [
            'held'        => self::heldReason($drawn),
            'programme'   => (string) ($ctx->programme ?? ''),
            'programme_slug' => (string) ($ctx->programme_slug ?? ''),
            'cycle_id'    => (int) $ctx->cycle_id,
            'cycle_year'  => (int) ($ctx->year ?? 0),
            'edition'     => self::edition($ctx),
            'slug'        => self::slug($categoryId, (string) ($ctx->title ?? '')),
            'url'         => '/results/' . self::slug($categoryId, (string) ($ctx->title ?? '')),
            'released_at' => (string) ($ctx->results_date ?? ''),
        ];
    }

    /**
     * Why this result is not published, or null when it is.
     *
     * Split out because THREE callers need the same answer and would otherwise each spell
     * it: this class's own index, the share card (which must not raster a winner's name
     * onto a graphic for a result the page will not show), and the announcement thread
     * (which must not post one to the Pulse either). A withheld result that is withheld in
     * only two of the three places is worse than one published everywhere, because the
     * inconsistency is what people screenshot.
     *
     * @param array<string,mixed> $drawn A {@see ResultRelease::category()} result.
     */
    public static function heldReason(array $drawn): ?string
    {
        if (!empty($drawn['community_dark'])) return self::HELD_DARK;
        if (empty($drawn['winner']))          return self::HELD_NOBODY;
        return null;
    }

    /**
     * Every published result, newest cycle first.
     *
     * @return array{items: list<array<string,mixed>>, held: int}
     */
    public static function index(int $limit = 60): array
    {
        try {
            $rows = DemoSeeder::notSandbox(
                DB::table('gates_award_categories as c')
                    ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->where(function ($w) {
                        $w->whereIn('cy.status', self::RELEASED)
                          ->orWhere(function ($x) {
                              $x->whereNotNull('cy.results_date')
                                ->where('cy.results_date', '<=', Carbon::now()->toDateTimeString());
                          });
                    }),
                'cy.programme_id')
                ->orderByDesc('cy.year')->orderByDesc('cy.id')
                ->orderBy('c.sort_order')->orderBy('c.id')
                ->limit(max(1, min(200, $limit)))
                ->pluck('c.id')->all();
        } catch (\Throwable) {
            return ['items' => [], 'held' => 0];
        }

        $items = [];
        $held  = 0;
        foreach ($rows as $id) {
            $c = self::category((int) $id);
            if ($c === null) continue;
            if ($c['held'] !== null) { $held++; continue; }
            $items[] = $c;
        }

        return ['items' => $items, 'held' => $held];
    }

    /**
     * The canonical `{id}-{title}` segment, so a shared link carries the award's name.
     *
     * {@see Slug::idSegment()} rather than the category's own `slug` column: that column is
     * unique per CYCLE, not globally, so `/results/leadership` would mean a different award
     * every year and every old share would silently start pointing at the new one.
     */
    public static function slug(int $categoryId, string $title): string
    {
        return Slug::idSegment($categoryId, $title);
    }

    /** The category id out of a `{id}-{name}` segment. */
    public static function idFrom(string $segment): int
    {
        return (int) $segment;
    }

    /**
     * The cycle/programme context, ONLY where the public may see it.
     *
     * Returns null rather than an "is it visible" boolean beside a separate fetch: two
     * calls is how a caller comes to read the row and forget to ask.
     */
    private static function context(int $categoryId): ?object
    {
        try {
            return DemoSeeder::notSandbox(
                DB::table('gates_award_categories as c')
                    ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->where('c.id', $categoryId)
                    ->where(function ($w) {
                        $w->whereIn('cy.status', self::RELEASED)
                          ->orWhere(function ($x) {
                              $x->whereNotNull('cy.results_date')
                                ->where('cy.results_date', '<=', Carbon::now()->toDateTimeString());
                          });
                    }),
                'cy.programme_id')
                ->select('c.id', 'c.title', 'cy.id as cycle_id', 'cy.year', 'cy.edition_label',
                         'cy.results_date', 'p.title as programme', 'p.slug as programme_slug')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The edition as the award calls itself.
     *
     * The cycle's own label, else its year — never `date('Y')`. The congratulations mail
     * printed the wall-clock year once and told a winner they had taken an edition that did
     * not exist; a page that outlives the cycle by years must not repeat it.
     */
    private static function edition(object $ctx): string
    {
        $label = trim((string) ($ctx->edition_label ?? ''));
        return $label !== '' ? $label : (string) ((int) ($ctx->year ?? 0) ?: '');
    }
}
