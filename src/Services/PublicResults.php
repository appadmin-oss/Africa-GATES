<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
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
 * 1. THE CYCLE HAS BEEN ANNOUNCED. `results` or `archived`, and nothing else. A
 *    judged-but-unreleased category is a decided award nobody has announced, and serving
 *    it publicly is announcing it.
 *
 *    THIS GATE USED TO ALSO ACCEPT A `results_date` THAT HAD PASSED, which is the sentence
 *    above being contradicted two lines under it. A cycle is announced by
 *    {@see \AfricaGates\Services\CycleMaterialiser}, in one transaction that sets the
 *    status, promotes the winners and — where the announcement actually goes out — seals
 *    the standing; until it runs, nobody has been crowned. So a cycle still in `judging`
 *    three days past its date published a full standing with a named winner and an index
 *    — and no seal, so the page also printed
 *    "Recomputed under current rules", the platform admitting on a result page that this
 *    was not the announcement. Every figure on it could still move: the panel was open.
 *
 *    The date is a PROMISE, not a release. Where it has passed and the cycle has not been
 *    announced, {@see delayed()} says so instead — because the other way this fails is
 *    silence, and the page a nominee's family refreshes on the results date must not go
 *    from a wrong answer to no answer at all.
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
    /**
     * An ANNOUNCED cycle is one of these, whatever its dates say.
     *
     * The name is the point. These two statuses are what `CycleMaterialiser` writes in the
     * same transaction that crowns the winners and seals the standing, so they mean "this
     * was announced" and a date never does.
     *
     * ── WITH ONE EXCEPTION, AND IT IS THE SEAL THAT IS MISSING, NOT THE STATUS ──
     *
     * Where a cycle crosses its boundary more than `ANNOUNCE_GRACE_DAYS` late, the
     * materialiser corrects the status and promotes the winners but withholds every
     * outbound notification — and no longer seals, because a seal claims to be the
     * standing that was announced and on that path nothing was. Such a cycle is in
     * `results`, so it passes this gate and publishes; it simply publishes live figures
     * labelled as recomputed until somebody releases it deliberately. That is the honest
     * state for it, and {@see \AfricaGates\Services\ReleasedStanding} is where the
     * labelling is decided.
     */
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
    public static function category(int $categoryId, ?NomineeScoringService $scoring = null): ?array
    {
        $ctx = self::context($categoryId);
        if ($ctx === null) return null;

        // ── PASS THE SCORER WHEN YOU ARE DRAWING MORE THAN ONE ───────────────
        //
        // The community denominator is the whole EDITION's maximum, so scoring one category
        // reads every category in its cycle ({@see NomineeScoringService::editionScale()}).
        // That work is memoised per cycle ON THE SCORER, and a caller that builds a fresh
        // one per category therefore repeats a full-cycle pass for every card it draws —
        // quadratic in the size of an edition, on a public page, with nothing to see but
        // the page getting slower as a cycle grows.
        //
        // One scorer per LIST, not per row. Every loop over categories in this codebase
        // shares one for that reason: {@see index()}, {@see ResultRelease::forCycle()},
        // {@see \AfricaGates\Services\PulseFeedService::resultPayloads()}.
        $drawn = ResultRelease::category($categoryId, $scoring);
        if ($drawn['category'] === null) return null;

        // ── A PUBLISHED RESULT IS THE ONE THAT WAS ANNOUNCED ─────────────────
        //
        // Everything above recomputes from today's rules. That is right for a cycle still
        // being judged and wrong for one already released: it published what the CURRENT
        // arithmetic gives a nominee rather than what they were awarded, and named
        // whichever nominee the current arithmetic ranks first rather than the one who was
        // crowned. One real released nominee moved from 693 to 885 across a week of
        // scoring changes, with no record edited and the hash chain intact throughout.
        //
        // So a released cycle is laid over with its sealed standing. Where none was ever
        // recorded — a release from before sealing existed — `sealed_at` is empty and the
        // page says the figures are a live computation rather than the announcement.
        // {@see ReleasedStanding} for why no attempt is made to guess one from the
        // routine captures.
        $sealed = ReleasedStanding::forCycle((int) $ctx->cycle_id);
        if ($sealed !== null) $drawn = ReleasedStanding::apply($drawn, $sealed);

        return $drawn + [
            // Empty where the standing was never sealed, which the page must state.
            'sealed_at'   => (string) ($drawn['sealed_at'] ?? ''),
            // Sealed figures whose PLACINGS had to be reconstructed — see
            // {@see ReleasedStanding::apply()}. Defaulted here because an unsealed
            // release never sets it and the template reads it under strict_variables.
            'rank_recomputed' => (bool) ($drawn['rank_recomputed'] ?? false),
            'held'        => self::heldReason($drawn),
            // ── BOTH VOTE FIGURES, FOR THE WHOLE CATEGORY ────────────────────
            //
            // The page showed the organic count alone while a nominee's vote page shows
            // the full tally, so one person carried two different vote numbers on two
            // pages of one platform with nothing saying why. Summed here rather than in
            // the template or the controller: it is an aggregate of figures the scorer
            // already produced, and a template that adds up an award's votes is a second
            // place those totals live.
            'votes'       => self::tally($drawn['rows']),
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
     * What this category's votes actually were.
     *
     * `cast` is every vote counted toward a nominee's public tally. `organic` is the
     * subset the index reads — free, one per verified person per category. `bought` is
     * the remainder: votes purchased in a pack, or awarded as a bonus against a
     * contribution. It is a subtraction and not its own column because that is exactly
     * what it is, and inventing a third stored figure is how the three come to disagree.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{cast:int, organic:int, bought:int}
     */
    private static function tally(array $rows): array
    {
        $cast = $organic = 0;
        foreach ($rows as $r) {
            $cast    += (int) ($r['vote_count'] ?? 0);
            $organic += (int) ($r['organic'] ?? 0);
        }

        // Floored at zero. `vote_count` and `organic_vote_count` are two denormalised
        // counters maintained by different paths, and a drifted pair can leave organic
        // ABOVE the tally — see VoteRecount, which exists for exactly that. "−40 bought
        // votes" on a public page is a worse answer than none.
        return ['cast' => $cast, 'organic' => $organic, 'bought' => max(0, $cast - $organic)];
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
     * Every published result, newest edition first, GROUPED BY EDITION.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AN EDITION IS THE UNIT, AND THE LIMIT IS WHY
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This used to take the newest 60 CATEGORIES. That is the wrong unit for a page that
     * presents an edition: the cut lands wherever the sixtieth category happens to be, so
     * an award programme with eleven categories could appear with seven of them and no
     * indication that four were missing — a reader counting the awards would conclude
     * this platform had decided seven.
     *
     * So the cap is on EDITIONS and the categories follow whole. A page showing twelve
     * editions shows all of each of them, and an edition that is off the end is absent
     * rather than truncated.
     *
     * `items` is kept flat and unchanged for callers that want the whole list — the
     * sitemap and the tests read it — and `editions` is the same rows grouped, in the
     * order the page renders them.
     *
     * @return array{items: list<array<string,mixed>>, held: int,
     *               editions: list<array<string,mixed>>}
     */
    public static function index(int $maxEditions = 12): array
    {
        $empty = ['items' => [], 'held' => 0, 'editions' => []];

        try {
            // Cycles first. `year DESC, id DESC` is the order the page reads in, and the
            // categories are fetched against it rather than re-sorted afterwards.
            $cycles = DemoSeeder::notSandbox(
                DB::table('gates_award_cycles as cy')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->whereIn('cy.status', self::RELEASED),
                'cy.programme_id')
                ->orderByDesc('cy.year')->orderByDesc('cy.id')
                ->limit(max(1, min(60, $maxEditions)))
                // The NAME comes from the cycle, never from the first publishable award in
                // it. An edition whose every award is withheld has no such award, and the
                // first version of this took the label from one — so the one edition that
                // most needs explaining would have rendered with a blank heading.
                ->get(['cy.id', 'cy.year', 'cy.edition_label', 'p.title as programme']);

            $cycleIds = array_map(static fn ($c) => (int) $c->id, $cycles->all());
            if ($cycleIds === []) return $empty;

            $rows = DB::table('gates_award_categories as c')
                ->whereIn('c.cycle_id', $cycleIds)
                ->orderBy('c.sort_order')->orderBy('c.id')
                ->get(['c.id', 'c.cycle_id']);
        } catch (\Throwable) {
            return $empty;
        }

        // One scorer across the whole page — see the note on category(). It caches the
        // edition scale per cycle, so a page listing sixty results reads each cycle once
        // rather than once per award.
        $scoring = new NomineeScoringService();

        // Keyed by cycle in the cycle order above, so the groups come out newest first
        // without a second sort. A category whose cycle vanished between the two queries
        // is simply skipped.
        $byCycle = array_fill_keys($cycleIds, []);
        $heldBy  = array_fill_keys($cycleIds, 0);

        $items = [];
        $held  = 0;
        foreach ($rows as $row) {
            $c = self::category((int) $row->id, $scoring);
            if ($c === null) continue;
            $cid = (int) $row->cycle_id;
            if ($c['held'] !== null) {
                $held++;
                if (isset($heldBy[$cid])) $heldBy[$cid]++;
                continue;
            }
            $items[] = $c;
            if (isset($byCycle[$cid])) $byCycle[$cid][] = $c;
        }

        $editions = [];
        foreach ($cycles as $cy) {
            $cid    = (int) $cy->id;
            $awards = $byCycle[$cid] ?? [];
            // An edition with every award withheld is NOT dropped: it is named with a
            // count, because a released edition that disappears from this page reads as
            // one that never happened. An edition with nothing at all — no categories
            // scored, nothing held — has no news and is left out.
            if ($awards === [] && ($heldBy[$cid] ?? 0) === 0) continue;
            $editions[] = [
                'cycle_id'  => $cid,
                'programme' => (string) ($cy->programme ?? ''),
                'edition'   => self::edition($cy),
                'year'      => (int) ($cy->year ?? 0),
                'awards'    => $awards,
                'held'      => $heldBy[$cid] ?? 0,
                // The edition's own headline: the highest index anybody reached in it.
                'top'       => $awards === [] ? null : array_reduce(
                    $awards,
                    static fn ($best, $a) => ($best === null
                        || (float) ($a['winner']['cpi'] ?? 0) > (float) ($best['winner']['cpi'] ?? 0))
                        ? $a : $best),
            ];
        }

        return ['items' => $items, 'held' => $held, 'editions' => $editions];
    }

    /**
     * CYCLES WHOSE RESULTS DATE HAS PASSED AND WHICH HAVE NOT BEEN ANNOUNCED.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE SITE HAS TO SAY THIS OUT LOUD
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A results date is a promise made in public. When it passes and the announcement has
     * not happened — the promotion errored, a category is still being checked, a panel is
     * short — the people refreshing that page are the nominee, their family, and whoever
     * has been asked to write about it. Three things can be on the page for them, and only
     * one of them is honest:
     *
     *   · THE STANDING AS IT CURRENTLY COMPUTES. What this class used to serve, because
     *     the gate accepted a passed date. A named winner nobody crowned, unsealed, from a
     *     panel that is still open. The worst of the three by a distance.
     *   · NOTHING. What gating alone would give. A page that was going to carry a result
     *     and now 404s, on the day it was promised, reads as the result being hidden —
     *     and "silence is how a withheld award becomes a rumour" is already this class's
     *     rule about withheld categories.
     *   · THE FACT. The date that was promised, that the award has not been decided yet,
     *     and — where an operator has written one — why.
     *
     * ── DERIVED, SO IT CANNOT BE LEFT UP ────────────────────────────────────
     *
     * There is no flag anybody has to set or clear. The condition IS the two facts: a date
     * in the past, and a status that is not one of {@see RELEASED}. So the notice appears
     * by itself when a release slips, and disappears by itself the moment
     * `CycleMaterialiser` announces the cycle — which is also the moment the real result
     * takes its place. A banner an operator has to remember to take down is a banner that
     * is still up in March.
     *
     * `note` is the only part that waits on a person, and the notice does not: the platform
     * admitting a result is late is not something to hold until somebody is at a desk. An
     * empty note means the page states what it knows rather than inventing a reason or a
     * new date — a made-up date is a second broken promise, and the first one is why anybody
     * is reading this.
     *
     * The sandbox is excluded through the programme, the same door every other public
     * reader takes, or a rehearsal cycle left in `judging` would announce a delay on the
     * live site.
     *
     * @return list<array{cycle_id:int, programme:string, edition:string, promised:string,
     *                    note:string, awards:int}>
     */
    public static function delayed(int $limit = 12): array
    {
        try {
            $rows = DemoSeeder::notSandbox(
                DB::table('gates_award_cycles as cy')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->whereNotNull('cy.results_date')
                    ->where('cy.results_date', '<=', Carbon::now()->toDateTimeString())
                    ->whereNotIn('cy.status', self::RELEASED),
                'cy.programme_id')
                ->orderByDesc('cy.results_date')
                ->limit(max(1, min(60, $limit)))
                ->get(['cy.id', 'cy.year', 'cy.edition_label', 'cy.results_date',
                       'p.title as programme']);
        } catch (\Throwable) {
            // A deployment whose column is not there yet, or no cycles table at all. A
            // missing notice is a quiet page; an exception here is a 500 on /results.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'cycle_id'  => (int) $r->id,
                'programme' => (string) ($r->programme ?? ''),
                'edition'   => self::edition($r),
                'promised'  => (string) ($r->results_date ?? ''),
                'note'      => self::delayNote((int) $r->id),
                // How many awards are waiting, so the sentence can be about the right
                // number of people. Counted rather than described: "an award" and
                // "fourteen awards" are different pieces of news.
                'awards'    => self::awardsIn((int) $r->id),
            ];
        }

        return $out;
    }

    /**
     * The delay for ONE cycle, or null when that cycle is not late.
     *
     * For a category page reached by a shared link. A result's URL is put in front of
     * people days before the date — in the congratulations mail, in the Pulse, in a message
     * somebody forwarded — so on the day it must explain itself rather than 404. Anybody
     * following such a link is precisely the person owed the explanation.
     *
     * @return array{cycle_id:int, programme:string, edition:string, promised:string,
     *                note:string, awards:int}|null
     */
    public static function delayFor(int $cycleId): ?array
    {
        if ($cycleId < 1) return null;

        foreach (self::delayed(60) as $d) {
            if ($d['cycle_id'] === $cycleId) return $d;
        }

        return null;
    }

    /**
     * The category page's own delay, resolved from a category id.
     *
     * Deliberately NOT gated on {@see RELEASED} — it is the one lookup on this class that
     * has to see an unannounced cycle, which is what it exists to describe.
     *
     * @return array{cycle_id:int, programme:string, edition:string, promised:string,
     *                note:string, awards:int, award:string}|null
     */
    public static function delayForCategory(int $categoryId): ?array
    {
        if ($categoryId < 1) return null;

        try {
            $row = DB::table('gates_award_categories as c')
                ->where('c.id', $categoryId)->first(['c.cycle_id', 'c.title']);
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) return null;

        $d = self::delayFor((int) ($row->cycle_id ?? 0));
        if ($d === null) return null;

        // The award's own name, so the page says which one somebody was sent to rather
        // than only which edition it belongs to.
        return $d + ['award' => (string) ($row->title ?? '')];
    }

    /** The operator's own sentence for a cycle, or '' — see the migration for why it is nullable. */
    private static function delayNote(int $cycleId): string
    {
        if (!OptionalColumn::on('gates_award_cycles', 'results_delay_note')) return '';

        try {
            $v = DB::table('gates_award_cycles')->where('id', $cycleId)
                ->value('results_delay_note');
        } catch (\Throwable) {
            return '';
        }

        return trim((string) ($v ?? ''));
    }

    /** How many awards this cycle is holding — the number of people waiting, roughly. */
    private static function awardsIn(int $cycleId): int
    {
        try {
            return (int) DB::table('gates_award_categories')->where('cycle_id', $cycleId)->count();
        } catch (\Throwable) {
            return 0;
        }
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
                    ->whereIn('cy.status', self::RELEASED),
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
