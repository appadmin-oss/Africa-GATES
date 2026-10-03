<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * STARTING NEXT YEAR'S EDITION OF AN AWARD.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THERE WAS BEFORE THIS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Nothing. A programme runs once a year and the cycle screen edits exactly one cycle —
 * whichever one the public site is running. Past editions were rendered as CHIPS YOU
 * CANNOT CLICK, so last year's dates, label and categories were unreachable from the
 * console; and there was no create path at all, so the only way to open a 2027 edition of
 * a programme with five categories was to have somebody with database access write six
 * rows by hand.
 *
 * Worse than merely missing: the form posts the CURRENT cycle's id, so an operator who
 * reasonably tried "change the year to 2027 and save" did not create next year's edition —
 * they RENAMED this year's, taking its nominees, votes and scores with it. The screen's
 * own comment records that the previous behaviour (insert on a changed year) was removed
 * for being surprising; what replaced it was surprising in the other direction and still
 * had no way forward.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT CARRIES OVER, AND WHAT MUST NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The CATEGORIES carry: slug, title, description, order. They are the shape of the award
 * and retyping them every year is how a slug drifts and last year's links break.
 *
 * Nothing else does, and the list of what does not is the point:
 *
 *   · no nominees, no votes, no shortlists, no scorecards — a new edition is a fresh
 *     contest, and a carried-over nominee would arrive already holding last year's tally;
 *   · no DATES. A results date is a promise, and one inherited from last year is a promise
 *     nobody made, arriving already passed — `PublicResults::delayed()` would publish a
 *     "this award is late" notice on an edition that has not opened;
 *   · no STATUS beyond `upcoming`. A cycle created in `voting` is a ballot nobody opened.
 *
 * The rubric and the judges are per-PROGRAMME, so they carry by not being copied at all.
 */
final class CycleEdition
{
    /**
     * Open a new edition of a programme.
     *
     * @return array{ok:bool, cycle_id:int, categories:int, message:string}
     */
    public static function open(int $programmeId, int $year, string $label = '',
                                bool $copyCategories = true, ?int $adminId = null): array
    {
        $fail = static fn (string $m): array =>
            ['ok' => false, 'cycle_id' => 0, 'categories' => 0, 'message' => $m];

        if ($programmeId < 1) return $fail('No programme.');

        // A sane range rather than none. `year` is a MySQL YEAR column on production, which
        // silently coerces out-of-range values — a typo'd 20267 stored as something else is
        // an edition nobody can find by its number.
        $thisYear = (int) date('Y');
        if ($year < $thisYear - 5 || $year > $thisYear + 5) {
            return $fail('That year is outside the range this can open (' .
                ($thisYear - 5) . '–' . ($thisYear + 5) . ').');
        }

        try {
            $programme = DB::table('gates_award_programmes')->where('id', $programmeId)->first();
            if (!$programme) return $fail('No such programme.');

            // ── ONE EDITION PER YEAR, CHECKED RATHER THAN ASSUMED ────────────
            //
            // There is no unique key on (programme_id, year) — adding one to a live table
            // that may already hold a duplicate is a migration that fails on the one
            // database it matters on. So it is checked here, and the check is the reason a
            // second "Open 2027" press is a refusal rather than a second empty edition
            // sitting beside the real one with the same number on it.
            $clash = DB::table('gates_award_cycles')
                ->where('programme_id', $programmeId)->where('year', $year)->first();
            if ($clash) {
                return $fail('This programme already has a ' . $year . ' edition.');
            }
        } catch (\Throwable $e) {
            return $fail('Could not read the programme.');
        }

        $cycleId = 0; $copied = 0;

        try {
            DB::connection()->transaction(function () use (
                $programmeId, $year, $label, $copyCategories, &$cycleId, &$copied
            ): void {
                $cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
                    'programme_id'  => $programmeId,
                    'year'          => $year,
                    'edition_label' => trim(mb_substr($label, 0, 100)),
                    // Upcoming, and no dates. See the class docblock: an inherited results
                    // date is a promise nobody made, arriving already passed.
                    'status'        => 'upcoming',
                    'created_at'    => Carbon::now()->toDateTimeString(),
                ]);

                if (!$copyCategories) return;

                // The most recent edition that HAS categories — not simply the most recent.
                // A programme whose last edition was opened and abandoned would otherwise
                // carry nothing forward and the operator would be told it had worked.
                $source = DB::table('gates_award_cycles as c')
                    ->where('c.programme_id', $programmeId)
                    ->where('c.id', '!=', $cycleId)
                    ->whereExists(static fn ($q) => $q->selectRaw(1)
                        ->from('gates_award_categories as ac')
                        ->whereColumn('ac.cycle_id', 'c.id'))
                    ->orderByDesc('c.year')->orderByDesc('c.id')
                    ->first(['c.id']);

                if (!$source) return;

                foreach (DB::table('gates_award_categories')
                            ->where('cycle_id', (int) $source->id)
                            ->orderBy('sort_order')->orderBy('id')
                            ->get(['slug', 'title', 'description', 'sort_order']) as $cat) {
                    DB::table('gates_award_categories')->insert([
                        'cycle_id'    => $cycleId,
                        'slug'        => (string) $cat->slug,
                        'title'       => (string) $cat->title,
                        'description' => $cat->description,
                        // `sort_order` is TINYINT on production and caps at 255. A value
                        // above it does not error, it CLAMPS — and every category past the
                        // cap would collapse to one position. This codebase has been bitten
                        // by exactly that column before.
                        'sort_order'  => max(0, min(255, (int) ($cat->sort_order ?? 0))),
                    ]);
                    $copied++;
                }
            });
        } catch (\Throwable $e) {
            return $fail('Could not open the edition: ' . $e->getMessage());
        }

        return [
            'ok' => true, 'cycle_id' => $cycleId, 'categories' => $copied,
            'message' => $copied > 0
                ? 'Opened ' . $year . ' with ' . $copied . ' categor'
                  . ($copied === 1 ? 'y' : 'ies') . ' carried over. No dates are set yet.'
                : 'Opened ' . $year . '. There were no categories to carry over.',
        ];
    }

    /**
     * Every edition of a programme, newest first, with what is in it.
     *
     * The counts are what make the list usable: "2025" tells an operator nothing, and
     * "2025 · 5 categories · 41 nominees · archived" tells them which one they want.
     *
     * @return list<array<string,mixed>>
     */
    public static function listFor(int $programmeId): array
    {
        try {
            $cycles = DB::table('gates_award_cycles')->where('programme_id', $programmeId)
                ->orderByDesc('year')->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($cycles as $c) {
            $cats = DB::table('gates_award_categories')->where('cycle_id', $c->id)
                ->pluck('id')->map(static fn ($v): int => (int) $v)->all();

            $nominees = 0;
            if ($cats !== []) {
                // Chunked: `whereIn` with an unbounded list is a query that gets slower
                // every year a programme runs, on a screen somebody opens to orient
                // themselves.
                foreach (array_chunk($cats, 200) as $chunk) {
                    $nominees += (int) DB::table('gates_nominees')
                        ->whereIn('category_id', $chunk)
                        ->whereIn('status', ['approved', 'winner', 'runner_up'])
                        ->count();
                }
            }

            $out[] = [
                'row'        => (array) $c,
                'categories' => count($cats),
                'nominees'   => $nominees,
            ];
        }

        return $out;
    }

    /**
     * The year a "start the next edition" button should offer.
     *
     * The year after the programme's latest, or this one — whichever is later. A programme
     * whose last edition was 2019 should not be offered 2020; somebody opening it now means
     * to run it now.
     */
    public static function nextYearFor(int $programmeId): int
    {
        try {
            $latest = (int) (DB::table('gates_award_cycles')
                ->where('programme_id', $programmeId)->max('year') ?? 0);
        } catch (\Throwable) {
            $latest = 0;
        }

        return max((int) date('Y'), $latest + 1);
    }
}
