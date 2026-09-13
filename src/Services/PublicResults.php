<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;

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
            'edition'     => self::edition_($ctx),
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
                ->get(['cy.id', 'cy.year', 'cy.edition_label', 'cy.programme_id',
                       'cy.results_date',
                       'p.title as programme', 'p.slug as programme_slug']);

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
                // The programme's own id, for Support\Accent::forProgramme(). Without it
                // every edition on this page resolves to the same identity colour, which
                // is a palette that looks deliberate and says nothing.
                'programme_id' => (int) ($cy->programme_id ?? 0),
                // Built here rather than in the template, so the hall and the archive
                // cannot come to disagree about what colour a programme is.
                'programme_style' => Accent::programmeStyle((int) ($cy->programme_id ?? 0)),
                'programme' => (string) ($cy->programme ?? ''),
                'edition'   => self::edition_($cy),
                'year'      => (int) ($cy->year ?? 0),
                // The date the platform said it would announce, which is also the date it
                // did where the cycle went out on time. Printed on the row rather than
                // derived there, so the archive and the edition page cannot disagree.
                'announced' => (string) ($cy->results_date ?? ''),
                // Built by the one minter, so the link on this page and the route that
                // serves it cannot come to disagree about the shape of an edition URL.
                'url'       => self::editionUrl((string) ($cy->programme_slug ?? ''), (int) ($cy->year ?? 0)),
                'awards'    => $awards,
                'held'      => $heldBy[$cid] ?? 0,
                // The edition's own headline: the highest index anybody reached in it.
                'top'       => $awards === [] ? null : array_reduce(
                    $awards,
                    static fn ($best, $a) => ($best === null
                        || (float) ($a['winner']['cpi'] ?? 0) > (float) ($best['winner']['cpi'] ?? 0))
                        ? $a : $best),
                // WHO WON THE EDITION OUTRIGHT. See overallFor().
                'overall'   => self::overallFor($cid, $awards, (int) ($heldBy[$cid] ?? 0)),
            ];
        }

        return ['items' => $items, 'held' => $held, 'editions' => $editions];
    }

    /**
     * Who won the whole edition, not just a category.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * IT IS COMPUTED FROM SEALED FIGURES AND STILL SAYS IT WAS RECONSTRUCTED
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The categories handed in here have already been through
     * {@see ReleasedStanding::apply()}, so every `cpi` and every `in_running` below is the
     * SEALED one — the figure that was announced, not what today's rules give. That is the
     * half that matters, and it is why this takes the drawn categories rather than calling
     * `ResultRelease::overall()` with a cycle id and letting it re-score: doing that would
     * rank a released edition under current arithmetic and could name a different person
     * than the platform announced.
     *
     * But `standing_rank` is the rank WITHIN A CATEGORY. There is no sealed overall rank
     * anywhere, so the ORDER here is necessarily reconstructed by re-sorting sealed figures
     * through `ResultRelease::order()` — which is exactly the position the category rank was
     * in before it was sealed, and `ReleasedStanding`'s own docblock says why that is not
     * good enough on its own: a tiebreak is a rule like any other, and moving it would
     * silently reorder every dead heat ever announced.
     *
     * So `reconstructed` is always true for now and is carried rather than hidden, in the
     * same shape as `rank_recomputed`. Sealing an overall rank is a real change to the
     * announcement record — a new column, a backfill, and a decision about editions
     * released before it existed — and is not something to do inside a display feature.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * A WITHHELD AWARD MAKES IT PROVISIONAL, AND THAT IS NOT A DETAIL
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Only PUBLISHED categories are ranked, because a nominee from an award nobody has
     * announced must not appear in a public standing — that is the same rule
     * {@see PublicResults} enforces one level up. The cost is that the true top of the
     * edition may be sitting in the withheld category, so the answer can be overturned when
     * that award lands.
     *
     * Naming somebody "overall winner of the 2026 edition" and then quietly replacing them
     * is worse than saying it is not settled yet, so `provisional` is set and the number of
     * withheld awards travels with it. What a screen does with that is the screen's
     * decision; refusing to carry the fact is how a screen comes to make a claim it cannot
     * support.
     *
     * @param list<array<string,mixed>> $awards the drawn, sealed, PUBLISHED categories
     * @return array<string,mixed>|null null where nothing in the edition is in the running
     */
    private static function overallFor(int $cycleId, array $awards, int $held): ?array
    {
        if ($awards === []) return null;

        try {
            // No re-scoring: this sorts rows that are already in memory.
            $o = ResultRelease::overall($cycleId, $awards);
        } catch (\Throwable) {
            return null;
        }

        if (($o['winner'] ?? null) === null) return null;

        return [
            'winner'     => $o['winner'],
            'runner_up'  => $o['runner_up'] ?? null,
            'margin'     => $o['margin'] ?? null,
            // A dead heat at the top of a whole edition needs a person, and a silent
            // tiebreak is how it stops needing one.
            'dead_heat'  => (bool) ($o['dead_heat'] ?? false),
            'provisional' => $held > 0,
            'held'        => $held,
            // Always, for now. See the docblock — there is no sealed overall rank.
            'reconstructed' => true,
        ];
    }

    /**
     * The URL of an edition's own page, and the only place its shape is decided.
     *
     * `{programme-slug}-{year}` — `/results/alimosho-incredible-principal-awards-2026`.
     * Readable, guessable, and disjoint from the award route by construction: that one is
     * `{slug:[0-9]+[^/]*}` and begins with a digit, so a programme slug beginning with a
     * letter can never be served by it. {@see editionSlug} is the inverse and they are
     * written together, because a minter and a parser that drift produce a page that
     * links to itself and 404s.
     */
    public static function editionUrl(string $programmeSlug, int $year): string
    {
        return '/results/' . self::editionSlug($programmeSlug, $year);
    }

    public static function editionSlug(string $programmeSlug, int $year): string
    {
        $p = strtolower(trim($programmeSlug));
        $p = (string) preg_replace('~[^a-z0-9]+~', '-', $p);
        $p = trim($p, '-');
        // A programme with no usable slug still needs a URL that resolves, and `edition`
        // is a word no programme slug can be mistaken for once the year is appended.
        return ($p !== '' ? $p : 'edition') . '-' . $year;
    }

    /**
     * ONE EDITION, DRAWN WHOLE — every award in it, in the order it is presented.
     *
     * Returns null when the slug names nothing released, which the controller turns into
     * a 404. A released cycle whose every award is withheld is NOT null: it exists, it was
     * announced, and a page saying so is the honest answer. `awards` is then empty and
     * `held` says how many.
     *
     * @return array<string,mixed>|null
     */
    public static function edition(string $slug): ?array
    {
        // The year is the trailing four digits; everything before it is the programme.
        if (!preg_match('~^(?<p>[a-z0-9-]*?)-(?<y>\d{4})$~', strtolower(trim($slug)), $m)) {
            return null;
        }
        $programmeSlug = (string) $m['p'];
        $year          = (int) $m['y'];

        try {
            $cy = DemoSeeder::notSandbox(
                DB::table('gates_award_cycles as cy')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->whereIn('cy.status', self::RELEASED)
                    ->where('cy.year', $year),
                'cy.programme_id')
                // Matched on the SLUG the URL was built from, not on the title: a
                // programme renamed after an announcement keeps the URL it was announced
                // under, which is the one in the congratulations email and the press.
                ->where('p.slug', $programmeSlug)
                ->orderByDesc('cy.id')
                ->first(['cy.id', 'cy.year', 'cy.edition_label', 'cy.results_date',
                         'cy.status', 'cy.programme_id',
                         'cy.nominations_open', 'cy.nominations_close',
                         'cy.voting_open', 'cy.voting_close',
                         'p.title as programme', 'p.slug as programme_slug']);
            if (!$cy) return null;

            $catIds = DB::table('gates_award_categories')
                ->where('cycle_id', (int) $cy->id)
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('id')->all();
        } catch (\Throwable) {
            return null;
        }

        // One scorer for the whole edition — it memoises the edition scale per cycle, so
        // eleven awards read the cycle once rather than eleven times.
        $scoring = new NomineeScoringService();

        $awards = [];
        $held   = 0;
        foreach ($catIds as $id) {
            $c = self::category((int) $id, $scoring);
            if ($c === null) continue;
            if ($c['held'] !== null) { $held++; continue; }
            $awards[] = $c;
        }

        // The edition's headline: the highest index anybody in it reached. Not a separate
        // award and never presented as one — it is the answer to "who led this edition",
        // which is the question a reader arrives with and the old list could not answer.
        $top = null;
        foreach ($awards as $a) {
            if ($top === null || (float) ($a['winner']['cpi'] ?? 0) > (float) ($top['winner']['cpi'] ?? 0)) {
                $top = $a;
            }
        }

        return [
            'cycle_id'   => (int) $cy->id,
            'programme'  => (string) $cy->programme,
            'programme_slug' => (string) $cy->programme_slug,
            'programme_id'    => (int) ($cy->programme_id ?? 0),
            'programme_style' => Accent::programmeStyle((int) ($cy->programme_id ?? 0)),
            'year'       => (int) $cy->year,
            'edition'    => self::edition_($cy),
            'announced'  => (string) ($cy->results_date ?? ''),
            'slug'       => self::editionSlug((string) $cy->programme_slug, (int) $cy->year),
            'url'        => self::editionUrl((string) $cy->programme_slug, (int) $cy->year),
            'awards'     => $awards,
            'held'       => $held,
            'top'        => $top,
            'status'     => ResultStatus::forEdition(CyclePolicy::phaseFor($cy), count($awards),
                                                     $awards === [] && $held > 0 ? self::HELD_DARK : null),
            // EVERY category, decided or not, in the order the edition lists them.
            //
            // The withheld ones used to be counted and dropped, so an award being held
            // back appeared on this page as a number in a sentence and nowhere else — and
            // this class's own rule is that silence is how a withheld award becomes a
            // rumour. A count says four awards are being checked; a row says WHICH, which
            // is what a nominee waiting on one of them actually needs.
            'categories' => self::categoryRows((int) $cy->id, $catIds, $awards,
                                               CyclePolicy::phaseFor($cy), $scoring),
        ];
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
                'edition'   => self::edition_($r),
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
    private static function edition_(object $ctx): string
    {
        $label = trim((string) ($ctx->edition_label ?? ''));
        return $label !== '' ? $label : (string) ((int) ($ctx->year ?? 0) ?: '');
    }

    /**
     * EVERY AWARD THIS PLATFORM IS RUNNING OR HAS RUN, AND WHERE EACH ONE STANDS.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE ARCHIVE HAD TO STOP BEING AN ARCHIVE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `/results` listed announced editions, newest first, and that is an index of things
     * that have finished. The question people actually arrive with is not "which editions
     * exist" — it is "where does this award stand right now", and that has four answers:
     * counting, with the panel, decided, withheld. Three of the four were invisible here,
     * so an award in the middle of its voting window simply did not appear on the page
     * named after its results, and the only way to find out it was running was to already
     * know.
     *
     * So the list is ordered by STATUS and not by date. What is open now is what brings
     * somebody here; what happened in 2025 is what brings them back, and a date sort is
     * one tap away and changes nothing any row says.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND AN OPEN EDITION CARRIES NO STANDING — NOT EVEN A LEADER
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `overall`, `top` and `awards` are populated for a DECIDED edition and are empty for
     * every other one. That is not an omission to be filled in later: publishing a running
     * order while voting is open turns the window into a bandwagon, and on a platform that
     * sells vote packs it turns this page into a sales page. The rule is carried on
     * {@see ResultStatus} rather than remembered here, and this method's job is to have
     * nothing to leak — an open edition's row is built without ever drawing its awards.
     *
     * The cheapness is a consequence rather than the reason: a cycle in `voting` is not
     * scored at all here, so adding every open edition to this page costs two counting
     * queries and no scorer passes.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE PHASE IS COMPUTED, AND THAT IS THE WHOLE POINT OF ASKING
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see CyclePolicy::phaseFor()} derives it from the cycle's own date windows.
     * `gates_award_cycles.status` is a materialised cache written by a scheduler on a host
     * with no shell, and a cycle whose voting closed last week while the cron was dead
     * still says `voting` in that column. Reading it here would print "Counting" over an
     * award nobody can vote in, with a countdown that has already run out.
     *
     * @return array{editions:list<array<string,mixed>>, stats:array<string,int>}
     */
    public static function standings(int $maxEditions = 24, array $view = []): array
    {
        $decided = self::index($maxEditions);

        // The phase inputs for every cycle on the page, in one read. `phaseFor()` needs the
        // windows and the stored column together — the column is its fallback for a cycle
        // with no windows at all, which is what a hand-made or imported cycle looks like.
        $cols = ['cy.id', 'cy.year', 'cy.edition_label', 'cy.status', 'cy.programme_id',
                 'cy.nominations_open', 'cy.nominations_close', 'cy.voting_open',
                 'cy.voting_close', 'cy.results_date',
                 'p.title as programme', 'p.slug as programme_slug'];

        try {
            $rows = DemoSeeder::notSandbox(
                DB::table('gates_award_cycles as cy')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id'),
                'cy.programme_id')
                ->orderByDesc('cy.year')->orderByDesc('cy.id')
                ->limit(max(1, min(120, $maxEditions * 4)))
                ->get($cols);

            // Categories per cycle, counted in SQL. A cycle with none is not an award
            // anybody can stand in and is left off the page entirely.
            $counts = DB::table('gates_award_categories')
                ->whereIn('cycle_id', array_map(static fn ($r) => (int) $r->id, $rows->all()))
                ->groupBy('cycle_id')
                ->get(['cycle_id', DB::raw('COUNT(*) as n')]);
        } catch (\Throwable) {
            // A page that lists nothing is survivable; a 500 on /results is not.
            return ['editions' => $decided['editions'], 'shown' => count($decided['editions']),
                    'stats' => self::statsFor($decided['editions']), 'programmes' => [],
                    'held' => $decided['held'], 'view' => self::view($view)];
        }

        $catCount = [];
        foreach ($counts as $c) $catCount[(int) $c->cycle_id] = (int) $c->n;

        // The decided editions, keyed so an open row cannot duplicate one of them.
        $byCycle = [];
        foreach ($decided['editions'] as $e) $byCycle[(int) $e['cycle_id']] = $e;

        $out = [];
        foreach ($rows as $cy) {
            $cid   = (int) $cy->id;
            $phase = CyclePolicy::phaseFor($cy);
            $known = $byCycle[$cid] ?? null;

            if ($known !== null) {
                // Already drawn, with its awards and its overall winner. It only needs the
                // word for where it stands — and a fully-withheld edition gets the withheld
                // reason rather than "Decided", which is what its rows would otherwise say.
                $known['status'] = ResultStatus::forEdition(
                    $phase, count($known['awards']),
                    $known['awards'] === [] && $known['held'] > 0 ? self::HELD_DARK : null);
                $known['categories'] = $catCount[$cid] ?? (count($known['awards']) + $known['held']);
                $known['decided']    = count($known['awards']);
                $out[] = $known;
                continue;
            }

            $n = $catCount[$cid] ?? 0;
            if ($n === 0) continue;

            $status = ResultStatus::forAward($phase);

            // An edition that has not opened has no news on a results page. It is skipped
            // here rather than filtered in the template, because a row a template hides is
            // a row somebody re-adds by deleting one line.
            if ($status['key'] === ResultStatus::PENDING) continue;

            $out[] = [
                'cycle_id'        => $cid,
                'programme_id'    => (int) ($cy->programme_id ?? 0),
                'programme_style' => Accent::programmeStyle((int) ($cy->programme_id ?? 0)),
                'programme'       => (string) ($cy->programme ?? ''),
                'edition'         => self::edition_($cy),
                'year'            => (int) ($cy->year ?? 0),
                'url'             => self::editionUrl((string) ($cy->programme_slug ?? ''),
                                                      (int) ($cy->year ?? 0)),
                'status'          => $status,
                'categories'      => $n,
                'decided'         => 0,
                'held'            => 0,
                // How long the window has left, for the countdown. Null once it has passed,
                // so a dead clock is impossible rather than merely unlikely.
                'closes'          => $status['key'] === ResultStatus::COUNTING
                                     ? self::futureDate($cy->voting_close ?? null) : null,
                'votes'           => $status['key'] === ResultStatus::COUNTING
                                     ? self::votesIn($cid) : 0,
                // DELIBERATELY EMPTY, AND NOT A GAP TO FILL. See the docblock: an open
                // edition publishes counts and never an order.
                'awards'          => [],
                'top'             => null,
                'overall'         => null,
            ];
        }

        // ── THE CHIPS COME OFF THE WHOLE PAGE, NEVER OFF THE FILTERED ONE ────
        //
        // Built before the filter is applied, so choosing a programme does not delete the
        // other programmes' chips — a filter control that removes the way back out of
        // itself is the one interaction people report as the site being broken.
        $programmes = [];
        foreach ($out as $e) {
            $key = (string) $e['programme'];
            if ($key === '') continue;
            $programmes[$key] ??= ['name' => $key, 'id' => (int) $e['programme_id'],
                                   'style' => (string) $e['programme_style'], 'n' => 0];
            $programmes[$key]['n']++;
        }
        ksort($programmes);

        $view = self::view($view);
        $all  = $out;
        $out  = self::filtered($out, $view);

        usort($out, match ($view['order']) {
            // Newest first, and the status is still printed on every row — an order is a
            // way through a list, never a claim about what is in it.
            'date' => static fn (array $a, array $b): int
                => [$b['year'], $b['cycle_id']] <=> [$a['year'], $a['cycle_id']],

            'programme' => static fn (array $a, array $b): int
                => [$a['programme'], -$a['year']] <=> [$b['programme'], -$b['year']],

            // THE DEFAULT, and the reason this page was rebuilt. What is open now is what
            // brings somebody here; what happened in 2025 is what brings them back.
            // `sort` comes off ResultStatus::ORDER, so the reading order of this page is
            // stated once, in the same place as the words.
            default => static fn (array $a, array $b): int
                => [$a['status']['sort'], -$a['year']] <=> [$b['status']['sort'], -$b['year']],
        });

        return ['editions' => $out, 'stats' => self::statsFor($all),
                'shown' => count($out), 'programmes' => array_values($programmes),
                // Carried rather than re-derived by the caller: `index()` reads and draws
                // a whole edition, and calling it twice for one figure is a second pass
                // over every category on the page.
                'held' => $decided['held'], 'view' => $view];
    }

    /**
     * The view state, normalised.
     *
     * Every value a reader can choose arrives from a URL, so every value is untrusted and
     * is resolved to one of a known set rather than passed through. An unrecognised order
     * is the default order, not an error page: a stale or mistyped link is somebody trying
     * to read a results page, and answering them with a 404 over a sort key is absurd.
     *
     * @return array{order:string, programme:string, q:string}
     */
    private static function view(array $raw): array
    {
        $order = strtolower(trim((string) ($raw['order'] ?? '')));

        return [
            'order'     => in_array($order, ['date', 'programme'], true) ? $order : 'status',
            'programme' => trim((string) ($raw['programme'] ?? '')),
            'q'         => trim((string) ($raw['q'] ?? '')),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function filtered(array $rows, array $view): array
    {
        if ($view['programme'] !== '') {
            $rows = array_values(array_filter($rows, static fn (array $e): bool
                => (string) $e['programme'] === $view['programme']));
        }

        if ($view['q'] !== '') {
            // Matched against what the row PRINTS — the programme, the edition, the year —
            // and nothing it does not. A search that finds a row by a field the reader
            // cannot see returns a result they cannot explain.
            $q = mb_strtolower($view['q']);
            $rows = array_values(array_filter($rows, static function (array $e) use ($q): bool {
                $hay = mb_strtolower(trim(($e['programme'] ?? '') . ' '
                                        . ($e['edition'] ?? '') . ' ' . ($e['year'] ?? '')));
                return str_contains($hay, $q);
            }));
        }

        return $rows;
    }

    /** The three figures in the header, counted from the drawn page and never typed. */
    private static function statsFor(array $editions): array
    {
        $categories = 0;
        $decided    = 0;
        $open       = 0;

        foreach ($editions as $e) {
            $categories += (int) ($e['categories'] ?? 0);
            $decided    += (int) ($e['decided'] ?? 0);
            if (($e['status']['key'] ?? '') === ResultStatus::COUNTING) $open++;
        }

        return ['editions' => count($editions), 'categories' => $categories,
                'decided' => $decided, 'counting' => $open];
    }

    /** A datetime string only if it is still ahead of us, so a countdown cannot run dead. */
    private static function futureDate(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '') return null;

        try {
            $at = Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }

        return $at->isFuture() ? $at->toIso8601String() : null;
    }

    /**
     * Total votes cast in one cycle.
     *
     * The denormalised counter rather than the ballot ledger, deliberately: this is a
     * participation figure on a page header, not an input to anybody's score, and it has
     * to include imported tallies from before this platform held rows — an edition whose
     * ballots predate the ledger would otherwise announce that nobody had voted in it.
     * Nothing here is measured against it and no standing is derived from it.
     */
    private static function votesIn(int $cycleId): int
    {
        try {
            return (int) DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)
                ->whereNull('n.merged_into')
                ->sum('n.vote_count');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * AN EDITION WHOSE AWARDS ARE NOT DECIDED YET — WITHOUT ONE FIGURE FROM ITS STANDING.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS A SECOND METHOD AND NOT A RELAXED GATE ON {@see edition()}
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `edition()` refuses anything not in {@see RELEASED}, and that refusal is the rule
     * this platform breaks hardest when it breaks it: serving a judged-but-unreleased
     * standing publicly IS announcing it, and the last time this gate was loose a cycle
     * still in `judging` published a full standing with a named winner three days past its
     * results date, from a panel that was still open.
     *
     * So the gate is not relaxed. This is a different page with a different job: it says
     * an award is RUNNING, which is a fact the platform already publishes on every vote
     * page and every nominate form, and it says how far along each of its categories is.
     * It draws no nominee, calls no scorer and touches nothing that could produce an
     * order — the safety is structural rather than a condition somebody has to keep true.
     *
     * A reader arriving here has usually followed a row from `/results` or a link they
     * were sent. Answering them with a 404 reads as the award having been taken down.
     *
     * @return array<string,mixed>|null null for a released edition — that is {@see edition()}'s
     */
    public static function openEdition(string $slug): ?array
    {
        if (!preg_match('~^(?<p>[a-z0-9-]*?)-(?<y>\d{4})$~', strtolower(trim($slug)), $m)) {
            return null;
        }

        try {
            $cy = DemoSeeder::notSandbox(
                DB::table('gates_award_cycles as cy')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->whereNotIn('cy.status', self::RELEASED)
                    ->where('cy.year', (int) $m['y']),
                'cy.programme_id')
                ->where('p.slug', (string) $m['p'])
                ->orderByDesc('cy.id')
                ->first(['cy.id', 'cy.year', 'cy.edition_label', 'cy.results_date', 'cy.status',
                         'cy.programme_id', 'cy.nominations_open', 'cy.nominations_close',
                         'cy.voting_open', 'cy.voting_close',
                         'p.title as programme', 'p.slug as programme_slug']);
            if (!$cy) return null;

            $catIds = DB::table('gates_award_categories')
                ->where('cycle_id', (int) $cy->id)
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('id')->all();
        } catch (\Throwable) {
            return null;
        }

        $phase  = CyclePolicy::phaseFor($cy);
        $status = ResultStatus::forAward($phase);

        // A cycle that has not opened has nothing to say to a results page, and saying it
        // anyway would put a page with no content behind a URL people share.
        if ($status['key'] === ResultStatus::PENDING) return null;

        return [
            'cycle_id'        => (int) $cy->id,
            'programme'       => (string) $cy->programme,
            'programme_slug'  => (string) $cy->programme_slug,
            'programme_id'    => (int) ($cy->programme_id ?? 0),
            'programme_style' => Accent::programmeStyle((int) ($cy->programme_id ?? 0)),
            'year'            => (int) $cy->year,
            'edition'         => self::edition_($cy),
            'slug'            => self::editionSlug((string) $cy->programme_slug, (int) $cy->year),
            'url'             => self::editionUrl((string) $cy->programme_slug, (int) $cy->year),
            'status'          => $status,
            'promised'        => (string) ($cy->results_date ?? ''),
            'closes'          => $status['key'] === ResultStatus::COUNTING
                                 ? self::futureDate($cy->voting_close ?? null) : null,
            'votes'           => self::votesIn((int) $cy->id),
            'categories'      => self::categoryRows((int) $cy->id, $catIds, [], $phase),
            // Present and empty, so a template written against the released shape cannot
            // find a standing here by reaching for a key that is simply absent.
            'awards'          => [],
            'held'            => 0,
            'top'             => null,
        ];
    }

    /**
     * Every category in one edition as a row, decided or not.
     *
     * The DECIDED ones are matched against the awards already drawn by the caller — never
     * re-drawn. Drawing a category twice on one page is two scorer passes over the same
     * edition scale, and worse, it is two chances for the page to disagree with itself.
     *
     * A row for an undecided category carries a title, a link and a status. It carries no
     * nominee, because the whole reason this list can exist on an open edition's page is
     * that there is nothing on it to leak.
     *
     * ONE SCORER FOR THE LIST, not one per row. The held-award lookup below calls
     * {@see category()}, and the community denominator is the whole EDITION's maximum — so
     * a fresh scorer per row repeats a full-cycle pass for every category on the page,
     * quadratic in the size of the edition with nothing to see but a page that gets slower
     * as a cycle grows. `EditionScaleTest` sweeps `src/` for exactly this loop and found
     * this one.
     *
     * @param list<int>                 $catIds every category id, in the edition's own order
     * @param list<array<string,mixed>> $awards the drawn, sealed, published categories
     * @return list<array<string,mixed>>
     */
    private static function categoryRows(int $cycleId, array $catIds, array $awards,
                                         CyclePhase $phase,
                                         ?NomineeScoringService $scoring = null): array
    {
        $drawn = [];
        foreach ($awards as $a) {
            $id = (int) ($a['category']->id ?? 0);
            if ($id > 0) $drawn[$id] = $a;
        }

        // Titles for the ones that were not drawn. One query for the lot; a per-row lookup
        // on a page that already reads a whole edition is how a list becomes quadratic.
        $titles = [];
        try {
            foreach (DB::table('gates_award_categories')->whereIn('id', $catIds)
                        ->get(['id', 'title']) as $r) {
                $titles[(int) $r->id] = (string) $r->title;
            }
        } catch (\Throwable) {
            // Names missing is a poorer page; an exception is no page at all.
        }

        $progress = $phase === CyclePhase::Judging
            ? self::judgingProgress($cycleId) : [];

        $out = [];
        foreach ($catIds as $raw) {
            $id = (int) $raw;

            if (isset($drawn[$id])) {
                $a = $drawn[$id];
                $out[] = [
                    'id'       => $id,
                    'title'    => (string) ($a['category']->title ?? ($titles[$id] ?? '')),
                    'url'      => (string) ($a['url'] ?? ''),
                    // Decided WITHOUT asking the phase. A drawn award is proof the
                    // materialiser crowned and announced it; a cycle whose results date is
                    // still in the future computes as `Upcoming`, and asking here printed
                    // "Not open yet" over six published results. See ResultStatus::decided().
                    'status'   => ResultStatus::decided(),
                    'award'    => $a,
                    'progress' => null,
                ];
                continue;
            }

            // Not drawn. Either the cycle has not decided anything yet, or it has and THIS
            // award is being held — {@see category()} is the one thing that knows which,
            // and it answers with a reason rather than a silence precisely so this row can
            // say so.
            //
            // The question is "has this edition published anything", not "what does the
            // calendar say": an edition with awards on the page has been announced whatever
            // its dates claim, and reading the phase here filed a held-back award under
            // "Not open yet" — the one wording that tells a nominee waiting on it to stop
            // waiting.
            $held = $drawn !== []
                  ? (self::category($id, $scoring)['held'] ?? self::HELD_DARK) : null;

            $out[] = [
                'id'       => $id,
                'title'    => $titles[$id] ?? '',
                'url'      => '',
                'status'   => ResultStatus::forAward($phase, $held),
                'award'    => null,
                'progress' => $progress[$id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * How far each category's panel has got, as complete scorecards against what is needed.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE ONE HONEST RAMP ON THIS PLATFORM
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Every other progress-shaped thing here would be a statement about a PERSON — how far
     * up a ranking they are, how close to a threshold. This is a statement about OUR work:
     * we said a panel would mark these nominees and this is how much of that we have done.
     * It is also the only honest answer available to "why is this taking so long", which is
     * the question the silence on this page used to leave unanswered.
     *
     * A scorecard is COMPLETE when one judge has scored one nominee against every active
     * criterion — a half-filled card is not progress, it is a card somebody is in the
     * middle of, and counting it would let the bar move backwards when a criterion is
     * added. The denominator is nominees × quorum: the number of complete cards the rules
     * of this programme require before anybody can be crowned.
     *
     * @return array<int,array{done:int,needed:int,pct:int}> keyed by category id
     */
    private static function judgingProgress(int $cycleId): array
    {
        try {
            $ctx = DB::table('gates_award_cycles')->where('id', $cycleId)
                ->first(['programme_id']);

            $criteria = array_map(
                static fn (object $r): int => (int) $r->id,
                array_filter(JudgeRubric::effective((int) ($ctx->programme_id ?? 0)),
                             static fn (object $r): bool => (int) $r->is_active === 1));
            $required = count($criteria);
            if ($required < 1) return [];

            $quorum = (int) ((new RuleEngine())->effective(
                (int) ($ctx->programme_id ?? 0), $cycleId)['min_judges_per_nominee']
                ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);
            if ($quorum < 1) return [];

            // Nominees per category, which is the denominator's other half. A merged-away
            // nominee is nobody's work: counting one would hold a panel permanently short
            // of a total it can never reach.
            $nominees = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)
                ->whereNull('n.merged_into')
                ->groupBy('n.category_id')
                ->get(['n.category_id', DB::raw('COUNT(*) as n')]);

            // COMPLETE cards only, counted in SQL — on a full panel this is tens of
            // thousands of rows and walking them in PHP to draw a bar is not a trade.
            $cards = DB::table('gates_judge_criteria_scores as s')
                ->join('gates_award_categories as c', 'c.id', '=', 's.category_id')
                ->where('c.cycle_id', $cycleId)
                ->whereIn('s.criterion_id', $criteria)
                ->groupBy('s.category_id', 's.judge_id', 's.nominee_id')
                ->havingRaw('COUNT(DISTINCT s.criterion_id) = ?', [$required])
                ->get(['s.category_id']);
        } catch (\Throwable) {
            // No rubric, no judges table on this deployment, a column not migrated yet.
            // A row without a bar still says "with the panel", which is the fact.
            return [];
        }

        $done = [];
        foreach ($cards as $c) {
            $id = (int) $c->category_id;
            $done[$id] = ($done[$id] ?? 0) + 1;
        }

        $out = [];
        foreach ($nominees as $r) {
            $id     = (int) $r->category_id;
            $needed = (int) $r->n * $quorum;
            if ($needed < 1) continue;

            $got = min($done[$id] ?? 0, $needed);
            $out[$id] = ['done' => $got, 'needed' => $needed,
                         // Floored, never rounded up: a bar that reads 100% beside a panel
                         // that has one card left is the page contradicting its own status.
                         'pct' => (int) floor($got / $needed * 100)];
        }

        return $out;
    }
}
