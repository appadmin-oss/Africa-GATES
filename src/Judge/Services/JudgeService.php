<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

class JudgeService
{
    /** Find judge by email (case-insensitive). */
    public function findByEmail(string $email): ?object
    {
        $email = strtolower(trim($email));
        $row = DB::table('gates_judges')->where('email', $email)->where('is_active', 1)->first();
        return $row ?: null;
    }

    public function findById(int $id): ?object
    {
        $row = DB::table('gates_judges')->where('id', $id)->first();
        return $row ?: null;
    }

    /**
     * Public-facing roster for the "Meet the Judges" page: active judges only,
     * with display fields (never email or programme assignments). Judges with a
     * photo are surfaced first, then alphabetical — so the showcase always leads
     * with complete cards.
     */
    public function publicRoster(): array
    {
        $judges = self::realJudges()
            ->where('is_active', 1)
            ->orderByRaw("CASE WHEN avatar_path IS NULL OR avatar_path = '' THEN 1 ELSE 0 END")
            ->orderBy('name')
            ->get();
        $progs = DB::table('gates_award_programmes')->get()->keyBy('id'); // resolved once
        return $judges->map(fn ($r) => $this->shapePublic($r, $progs))->all();
    }

    /** One judge's public profile (for /judges/{slug}); null if missing or inactive. */
    public function publicJudge(int $id): ?array
    {
        $r = self::realJudges()->where('id', $id)->where('is_active', 1)->first();
        if (!$r) return null;
        $progs = DB::table('gates_award_programmes')->get()->keyBy('id');
        return $this->shapePublic($r, $progs, true);
    }

    /**
     * Judges the PUBLIC may be shown — which excludes the sandbox's rehearsal panellist.
     *
     * ── THE BUG THIS EXISTS BECAUSE OF ──────────────────────────────────────
     *
     * {@see \AfricaGates\Services\DemoSeeder} creates "DEMO — Test Judge" with
     * `is_active = 1`, and it has to: the sandbox exists so an operator can walk the judge
     * portal without appointing a real person, and the portal reads that flag. But
     * `is_active` was also the ONLY thing the public "Meet the Judges" page filtered on, so
     * building a sandbox published a fictional judge onto the page whose entire purpose is
     * to say who is really deciding these awards.
     *
     * On a platform that argues its integrity from the panel being real and named, a made-up
     * panellist on that page is not a cosmetic bug.
     *
     * ── WHY THE EMAIL DOMAIN, AND NOT A FLAG ────────────────────────────────
     *
     * `demo.invalid` — `.invalid` is reserved by RFC 2606 precisely so it can never be a
     * real address, so no genuine judge can ever be excluded by this. DemoSeeder already
     * uses that domain as the sandbox's identity and already deletes by it, so this reads
     * the discriminator that exists rather than adding a second one that could disagree
     * with it.
     *
     * A nullable `is_demo` column would have to be set by the seeder and honoured by every
     * reader — one more thing to forget, on the same page, in the same way.
     */
    public static function realJudges(): \Illuminate\Database\Query\Builder
    {
        // Matched on the DOMAIN, not on the word "demo" anywhere in the address: a real
        // panellist called Demola, or one at a university running a `demo.` subdomain, is a
        // person who agreed to sit on this panel, and dropping them would be silent — nobody
        // checks a page for a name that isn't there.
        //
        // COALESCE, not a whereNull branch beside it: in SQL, NULL NOT LIKE '…' is NULL, not
        // true, so a judge with no address on file would be filtered out by the comparison
        // rather than kept by it. `email` is NOT NULL in both schemas today; that is a schema
        // fact, not something this query should depend on.
        return DB::table('gates_judges')
            ->whereRaw("LOWER(COALESCE(email, '')) NOT LIKE ?",
                       ['%@' . \AfricaGates\Services\DemoSeeder::MAIL_DOMAIN]);
    }

    /** Public, non-sensitive shape of a judge row (never email or assignments JSON). */
    private function shapePublic(object $r, $progs, bool $rich = false): array
    {
        $ids = $r->programme_ids ? (json_decode((string) $r->programme_ids, true) ?: []) : [];
        $jp = [];
        foreach ($ids as $pid) {
            $p = $progs[$pid] ?? null;
            if ($p && (int) $p->is_active === 1) {
                $jp[] = $rich
                    ? ['slug' => $p->slug, 'title' => $p->title, 'icon_emoji' => $p->icon_emoji, 'subtitle' => $p->subtitle]
                    : ['slug' => $p->slug, 'title' => $p->title];
            }
        }
        return [
            'id'           => (int) $r->id,
            'name'         => (string) $r->name,
            'title'        => (string) ($r->title ?? ''),
            'organisation' => (string) ($r->organisation ?? ''),
            'bio'          => (string) ($r->bio ?? ''),
            'avatar_path'  => (string) ($r->avatar_path ?? ''),
            'country_code' => strtoupper((string) ($r->country_code ?? '')),
            'programmes'   => $jp,
            'slug'         => $this->judgeSlug((int) $r->id, (string) $r->name),
        ];
    }

    /** Canonical judge slug: {id}-{name}. */
    public function judgeSlug(int $id, string $name): string
    {
        $s = \AfricaGates\Support\Slug::make($name, 60);
        return $id . ($s !== '' ? '-' . $s : '');
    }

    /**
     * Programmes this judge is assigned to.
     *
     * ── AND NOTHING AT ALL FOR A JUDGE WHO HAS BEEN TAKEN OFF THE PANEL ──────
     *
     * This decoded `programme_ids` whatever `is_active` said, so a deactivated judge still
     * resolved their whole appointment — and every gate built on this method (the ballot,
     * the evidence reader, the dossier map, canScore's fallback) asked a question whose
     * answer ignored the one fact that means "this person no longer judges here". The
     * assignment rule itself lives in {@see programmeIdsFor()}, which the scorer reads too.
     */
    public function programmes(int $judgeId): array
    {
        $a = self::assignments([$judgeId])[$judgeId] ?? null;
        if ($a === null || $a['ids'] === []) return [];

        $out = $a['appointed']
            ? DB::table('gates_award_programmes')->whereIn('id', $a['appointed'])->orderBy('sort_order')
                ->get()->map(fn($r) => (array)$r)->all()
            : [];

        // ── AND THE PRACTICE PROGRAMME, WHEN THERE IS ONE ────────────────────
        //
        // A judge appointed to a real panel had nowhere to try the portal before the round
        // they are being trusted with. The sandbox existed, but only for its own rehearsal
        // account, reachable by a superadmin pressing a button on an admin screen — which is
        // no use at all to the person who actually needs the practice.
        //
        // Appended rather than assigned: nothing is written to `programme_ids`, so a
        // practice run leaves no trace on the record of who was appointed to what. The
        // programme is `is_active = 0` and lives in its own category, so a score written
        // there cannot reach a real result — which is what makes handing it to every judge
        // safe rather than merely convenient.
        //
        // It appears only when an operator has BUILT the sandbox. That build is the opt-in.
        if ($a['practice'] !== null) {
            $practice = $this->practiceProgramme();
            if ($practice !== null) $out[] = $practice;
        }

        return $out;
    }

    /**
     * Which programmes each of these judges may judge, as ids — THE assignment rule.
     *
     * Appointed (`programme_ids`) plus the practice programme when a sandbox exists, and
     * nothing for an inactive judge. A judge id with no row is ABSENT from the result
     * rather than mapped to []: the caller decides what an unknown judge means, and the
     * scorer and the portal answer that differently for good reasons.
     *
     * Public and batched because {@see \AfricaGates\Services\NomineeScoringService} asks
     * it too, for every judge with a mark — a mark from somebody no longer assigned to the
     * programme must not count, and a second decoding of `programme_ids` over there is
     * exactly how canScore() once came to disagree with the ballot about practice.
     *
     * @param  list<int> $judgeIds
     * @return array<int, list<int>>
     */
    public static function programmeIdsFor(array $judgeIds): array
    {
        return array_map(static fn (array $a): array => $a['ids'], self::assignments($judgeIds));
    }

    /**
     * @param  list<int> $judgeIds
     * @return array<int, array{ids:list<int>, appointed:list<int>, practice:?int}>
     */
    private static function assignments(array $judgeIds): array
    {
        $judgeIds = array_values(array_unique(array_filter(array_map('intval', $judgeIds))));
        if ($judgeIds === []) return [];

        $practice = null;
        try {
            $pid = DB::table('gates_award_programmes')
                ->where('slug', \AfricaGates\Services\DemoSeeder::PROGRAMME_SLUG)->value('id');
            $practice = $pid !== null ? (int) $pid : null;
        } catch (\Throwable) {
            // No programmes table yet is not a reason to refuse a real appointment.
        }

        $out = [];
        foreach (DB::table('gates_judges')->whereIn('id', $judgeIds)
                    ->get(['id', 'is_active', 'programme_ids']) as $j) {
            if ((int) ($j->is_active ?? 0) !== 1) {
                $out[(int) $j->id] = ['ids' => [], 'appointed' => [], 'practice' => null];
                continue;
            }
            $decoded   = $j->programme_ids ? (json_decode((string) $j->programme_ids, true) ?: []) : [];
            $appointed = array_values(array_unique(array_filter(array_map('intval', (array) $decoded))));
            $extra     = ($practice !== null && !in_array($practice, $appointed, true)) ? $practice : null;
            $out[(int) $j->id] = [
                'ids'       => $extra !== null ? [...$appointed, $extra] : $appointed,
                'appointed' => $appointed,
                'practice'  => $extra,
            ];
        }

        return $out;
    }

    /**
     * The sandbox programme, marked as practice — or null when no sandbox has been built.
     *
     * @return array<string,mixed>|null
     */
    public function practiceProgramme(): ?array
    {
        try {
            $p = DB::table('gates_award_programmes')
                ->where('slug', \AfricaGates\Services\DemoSeeder::PROGRAMME_SLUG)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (!$p) return null;

        $row = (array) $p;
        // Read by the dashboard to keep practice out of the real counts, and by the portal
        // to label it. A judge who cannot tell a practice ballot from a live one has been
        // given something worse than no practice at all.
        $row['is_practice'] = true;

        return $row;
    }

    /**
     * An evidence row this judge is entitled to read, or null.
     *
     * ── THE CHAIN IS RE-DERIVED, NOT TRUSTED ─────────────────────────────────
     *
     * evidence → nominee → category → cycle → programme, checked against this judge's own
     * assignments. Nothing in the request contributes to the answer except the id, so a
     * judge on one panel cannot reach another panel's dossier by incrementing it — which
     * is the shape Broken Access Control takes in an application like this one.
     *
     * `visible_to_judges` is honoured too: an item withheld from the panel stays withheld
     * from every judge, including one otherwise entitled to the nominee.
     */
    public function evidenceFor(int $judgeId, int $evidenceId): ?object
    {
        if ($judgeId < 1 || $evidenceId < 1) return null;

        try {
            $row = DB::table('gates_nominee_evidence as e')
                ->where('e.id', $evidenceId)
                ->where('e.visible_to_judges', 1)
                ->first(['e.id', 'e.title', 'e.source_url', 'e.nominee_id']);
        } catch (\Throwable $ex) {
            error_log('[judge] evidence lookup ' . $evidenceId . ': ' . $ex->getMessage());
            return null;
        }
        if (!$row) return null;

        // ── THE NOMINEE HALF IS ASKED THROUGH mayJudgeNominee(), NOT RE-SPELLED ──
        //
        // This carried its own copy of the chain — programme, merge, status — and that copy
        // had already fallen behind: the shortlist clause and the conflict clause were added
        // to mayJudgeNominee() and never here, so a judge could open the dossier of a
        // nominee the shortlist had left off, or of a programme they had recused from, by
        // incrementing an evidence id. The note on mayJudgeNominee() says an access check
        // that exists twice will eventually differ; this was the second copy, differing.
        return $this->mayJudgeNominee($judgeId, (int) $row->nominee_id) ? $row : null;
    }

    /**
     * May this judge see this nominee at all?
     *
     * The same resolution {@see evidenceFor()} does, asked about the nominee directly:
     * nominee → category → cycle → programme, checked against this judge's own assignments.
     * Nothing in the request contributes to the answer except the id, so a judge on one
     * panel cannot reach another panel's entry by incrementing it.
     *
     * Extracted rather than inlined at the caller because it is now asked in two places,
     * and an access check that exists twice is an access check that will eventually differ.
     */
    public function mayJudgeNominee(int $judgeId, int $nomineeId): bool
    {
        if ($judgeId < 1 || $nomineeId < 1) return false;

        $mine = array_map(static fn (array $p): int => (int) $p['id'], $this->programmes($judgeId));
        if ($mine === []) return false;

        try {
            $programmeId = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->where('n.id', $nomineeId)
                ->whereIn('cy.programme_id', $mine)
                // A tombstone left the ballot; everything about it goes with it.
                ->whereNull('n.merged_into')
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                // ── AND ON THE PUBLISHED SHORTLIST ──────────────────────────
                //
                // The panel judges the shortlist, not the whole field. Enforced HERE
                // rather than only in ballot(), because this method is the shared gate
                // for the ballot, the evidence reader and the dossier-map endpoint — a
                // nominee scoped off the ballot but still reachable by id would be the
                // restriction with one more click in front of it.
                ->whereExists(static function ($q): void {
                    $q->selectRaw('1')
                      ->from('gates_shortlist_entries as e')
                      ->join('gates_shortlists as sl', 'sl.id', '=', 'e.shortlist_id')
                      ->where('sl.status', 'published')
                      ->whereColumn('e.nominee_id', 'n.id');
                })
                ->value('cy.programme_id');
        } catch (\Throwable $ex) {
            error_log('[judge] nominee access ' . $nomineeId . ': ' . $ex->getMessage());
            return false;
        }
        if ($programmeId === null) return false;

        // ── AND NOT A PROGRAMME THIS JUDGE HAS RECUSED FROM ──────────────────
        //
        // BallotController::orient() has always said the dossier map is withheld from a
        // judge with a conflict, and nothing here checked one — so a recused judge could
        // still pull the map, open the evidence, and flag maps for the panel, on the
        // programme they had told us they should not be judging. saveScore() refused the
        // MARK, which is the half that was visible; the rest stayed open behind it.
        return !$this->hasConflict($judgeId, (int) $programmeId);
    }

    /** All criteria (currently global; programme-specific override supported). */
    public function criteria(int $programmeId): array
    {
        $rows = DB::table('gates_judge_criteria')
            ->where('is_active', 1)
            ->where(function ($q) use ($programmeId) {
                $q->where('programme_id', $programmeId)->orWhereNull('programme_id');
            })
            ->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        // Prefer programme-specific over global if both exist for same slug
        $bySlug = [];
        foreach ($rows as $r) {
            if (!isset($bySlug[$r['slug']]) || $r['programme_id']) $bySlug[$r['slug']] = $r;
        }
        return array_values($bySlug);
    }

    /** Nominees in this programme's current cycle, with this judge's existing scores. */
    /**
     * The cycle this programme's panel is actually judging.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY NOT SIMPLY THE NEWEST ONE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This was `orderByDesc('year')->first()`, which is right exactly while a programme has
     * one cycle. A programme with last year's cycle still being judged while this year's is
     * open for nominations is not an exotic case — it is what the second year of any awards
     * programme looks like — and the panel was being handed the NEW cycle, which has no
     * shortlist, nobody to score, and a lock reason about a phase they are not in.
     *
     * ── AND WHY THE PHASE IS COMPUTED RATHER THAN READ ──────────────────────
     *
     * The old query never looked at the phase at all; this one asks
     * {@see CyclePolicy::phaseFor()}, which derives it from the date windows. Everywhere
     * else on this platform the windows are authoritative and `status` is a materialised
     * cache that is allowed to lag — the admin state report even flags it as
     * `cached_status_stale`. A panel locked out of judging by a column the platform itself
     * describes as possibly-stale is the same bug as a ballot that shows the wrong cycle,
     * one layer down.
     *
     * Falls back to the newest cycle when none is in judging, so a ballot opened early or
     * late still describes a real cycle and locks with an accurate reason rather than
     * rendering nothing at all.
     */
    private static function cycleToJudge(int $programmeId): ?object
    {
        $cycles = DB::table('gates_award_cycles')
            ->where('programme_id', $programmeId)->orderByDesc('year')->get();

        if ($cycles->isEmpty()) return null;

        foreach ($cycles as $c) {
            // A row with unreadable windows (null) is not a reason to show no ballot.
            if (self::judgingPhaseOf($c) === \AfricaGates\Services\CyclePhase::Judging) {
                return $c;
            }
        }

        return $cycles->first();
    }

    /**
     * The phase a ballot is judged against — THE one reader for "may marks be written".
     *
     * {@see CyclePolicy::phaseFor()}, the same computation cycleToJudge() picks a cycle
     * with. Null when the row's windows cannot be read, which every caller treats as
     * closed: a lock with a reason is recoverable, a mark written outside the window is not.
     */
    private static function judgingPhaseOf(object $cycle): ?\AfricaGates\Services\CyclePhase
    {
        try {
            return \AfricaGates\Services\CyclePolicy::phaseFor($cycle);
        } catch (\Throwable) {
            return null;
        }
    }

    public function ballot(int $judgeId, int $programmeId): array
    {
        $cycle = self::cycleToJudge($programmeId);
        if (!$cycle) return ['cycle' => null, 'categories' => []];

        $cats = DB::table('gates_award_categories')->where('cycle_id', $cycle->id)
            ->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        $catIds = array_column($cats, 'id');
        if (!$catIds) return ['cycle' => (array)$cycle, 'categories' => []];

        $nq = DB::table('gates_nominees')->whereIn('category_id', $catIds)
            ->whereIn('status', ['approved','winner','runner_up']);
        \AfricaGates\Services\MergeService::notMerged($nq);       // tombstones drop off the ballot
        // NOT orderByDesc('vote_count'), which is what this used to be.
        //
        // The ballot prints "judge on documented impact, not popularity" and then walked
        // the panel through the nominees in exactly popularity order, most-voted first.
        // The number itself was never rendered, so it looked clean; the ordering carried
        // it anyway, and position is one of the better-evidenced anchors there is. Every
        // judge saw the SAME order, so the bias pointed the same way for the whole panel
        // and landed on the 55% that exists to be independent of the 45%.
        //
        // Shuffled per judge instead, and deterministically: one judge gets the same
        // order every time they open the ballot — a list that reshuffles between page
        // loads is how somebody scores the wrong nominee — while different judges get
        // different orders, so position bias cancels across a panel instead of
        // accumulating. Seeded on judge + cycle, so it is reproducible months later if a
        // result is ever questioned.
        $nominees = $nq->get()->map(fn($r) => (array)$r)->all();

        // ── THE PANEL JUDGES THE SHORTLIST, NOT THE WHOLE FIELD ──────────────
        //
        // Every approved nominee used to reach the ballot, so a panel of six opened a
        // list of two hundred and the cut that the shortlist rules had already computed
        // and PUBLISHED counted for nothing at the one screen it was for.
        //
        // Filtered here, after the query, rather than joined into it: the join would drop
        // a whole category the moment its shortlist was unpublished, and this needs to
        // tell the difference between "this category has no shortlist yet" and "this
        // category has one and you have finished it". An empty ballot with no explanation
        // is the failure mode this file already exists to prevent once.
        //
        // ── AND PER CATEGORY, WHICH IS WHERE A SHORTLIST LIVES ───────────────
        //
        // This asked for the whole CYCLE's shortlisted ids in one set and kept any nominee
        // in it. A shortlist belongs to a category, so on a cycle where one category had
        // published and another had not, every nominee of the second was absent from the
        // set and silently dropped — the panel got an empty category with no explanation,
        // and the cycle-wide `$noShortlist` lock below could not fire because the FIRST
        // category still had nominees. Judging read as open, the category read as
        // finished, and nobody could be scored in it.
        //
        // {@see ResultRelease::shortlistedIn()} answers per category. NULL means no
        // PUBLISHED shortlist — never created, or withdrawn — and per this file's own
        // rule that is a LOCK rather than an open field: a judge scoring somebody who was
        // never shortlisted produces marks the results stage has no place for. What
        // changes here is only the SCOPE of the question, from cycle to category, and
        // that an empty category now says which of the two reasons it is empty for.
        //
        // The fault tolerance a local wrapper used to add is already inside that resolver:
        // the shortlist tables arrive in a migration, and it catches and returns null
        // rather than throwing. A judging portal that 500s mid-upgrade is worse than one
        // that locks and says why — and null locks, which is the safe direction. It will
        // name the wrong cause on such a deployment, and that is the right trade against
        // opening the whole field to scoring.
        $listed = [];
        foreach ($catIds as $catId) {
            $ids = \AfricaGates\Services\ResultRelease::shortlistedIn((int) $catId);
            $listed[(int) $catId] = $ids === null ? null : array_fill_keys($ids, true);
        }

        // Counted per category as well, so an empty one can say WHICH of the two reasons
        // it is empty for. A cycle-wide count could only ever describe the cycle.
        $beforeCut = [];
        foreach ($nominees as $n) {
            $beforeCut[(int) $n['category_id']] = ($beforeCut[(int) $n['category_id']] ?? 0) + 1;
        }

        $nominees = array_values(array_filter(
            $nominees,
            static function (array $n) use ($listed): bool {
                $map = $listed[(int) $n['category_id']] ?? null;
                // null → no published shortlist for that category → nobody from it.
                return $map !== null && isset($map[(int) $n['id']]);
            }
        ));

        $seat = static fn(array $n): string =>
            hash('sha256', $judgeId . ':' . $cycle->id . ':' . ($n['id'] ?? 0));
        usort($nominees, static fn(array $a, array $b): int => $seat($a) <=> $seat($b));

        $criteria = $this->criteria($programmeId);
        $criteriaIds = array_column($criteria, 'id');

        $scores = $criteriaIds ? DB::table('gates_judge_criteria_scores')
            ->where('judge_id', $judgeId)
            ->whereIn('criterion_id', $criteriaIds)
            ->get() : collect([]);
        $byNominee = [];
        foreach ($scores as $s) {
            $byNominee[$s->nominee_id][$s->criterion_id] = (int)$s->score;
        }

        $notes = DB::table('gates_judge_notes')->where('judge_id', $judgeId)
            ->get()->keyBy('nominee_id');

        // What actually survived the cut, per category, so an empty one can say which of
        // the two reasons it is empty FOR. Measured after the filter rather than guessed
        // from the shortlist state: a category with no published shortlist keeps its whole
        // field, so reaching the second branch below means something specific.
        $onBallot = [];
        foreach ($nominees as $n) {
            $onBallot[(int) $n['category_id']] = ($onBallot[(int) $n['category_id']] ?? 0) + 1;
        }

        $byCategory = [];
        foreach ($cats as $c) {
            $cid = (int) $c['id'];

            // A category that renders empty and says nothing is indistinguishable from
            // one a judge has finished. The cycle-level lock below could only ever speak
            // for the cycle, and was silenced entirely by any OTHER category having
            // people; this speaks for the category it is about.
            $why = '';
            if (($onBallot[$cid] ?? 0) === 0) {
                if (($beforeCut[$cid] ?? 0) === 0) {
                    // Different problem, different person, different next step —
                    // "publish the shortlist" is useless advice when there is nothing
                    // to shortlist.
                    $why = 'No entries have been approved in this category yet.';
                } elseif (($listed[$cid] ?? null) === null) {
                    $why = 'The shortlist for this category has not been published yet, so '
                         . 'there is nobody here to judge. The panel scores the shortlist '
                         . 'rather than every entry. This is a step for the organisers, not '
                         . 'something you can fix.';
                } else {
                    // Published, and naming nobody who is still an approved entry: a
                    // withdrawal or a merge after the list was frozen. A different fix
                    // from publishing one, so a different sentence.
                    $why = 'This category has entries, and its published shortlist names '
                         . 'none of them — so there is nobody here to judge. The list may '
                         . 'need rebuilding. This is a step for the organisers.';
                }
            }

            $byCategory[$c['id']] = [
                'category'  => $c,
                'nominees'  => [],
                'empty_why' => $why,
            ];
        }
        // ── A RECUSED JUDGE IS NOT HANDED THE DOSSIER EITHER ─────────────────
        //
        // Asked here, before anything is fetched, because mayJudgeNominee() now refuses a
        // judge with a conflict — so the evidence links, the map button and the flag on
        // this page would each answer "not on your ballot" one click later. A locked
        // ballot already says why it is locked; it should not also be a page of dead links.
        $coi = $this->hasConflict($judgeId, $programmeId);
        $dossierIds = $coi ? [] : array_column($nominees, 'id');

        // The dossier, in one query for the whole ballot rather than one per nominee on
        // the screen a judge keeps open for hours. See EvidenceService.
        $dossiers = (new \AfricaGates\Services\EvidenceService())
            ->forBallot($dossierIds);

        // The dossier maps that ALREADY exist. Read-only on render, deliberately: a judge
        // opening a ballot of forty must not start forty model calls by scrolling, and a
        // page that spends money on render spends it again on every refresh and every back
        // button. The ballot shows what is there and offers a button for the rest.
        $maps = \AfricaGates\Services\JudgeAssist::forBallot($dossierIds);

        // …minus the ones THIS judge has said misread the dossier. Continuing to show a
        // map above the evidence after its reader has told us it is wrong is the whole
        // harm the map risks, delivered on purpose. Per judge: the map is cached and
        // shared across a panel, and one judge's objection does not decide for the rest.
        $flagged = \AfricaGates\Services\JudgeAssist::flaggedBy(
            $judgeId, $dossierIds);

        // The summary the nominee themselves confirmed, in their own submission. Distinct
        // from the dossier map above and shown separately: the map is ours, written for a
        // judge; this is a description of the entry that the nominee read and agreed
        // represents them before pressing send. Confirmed-only — a draft summary nobody
        // approved must never sit at the top of somebody's entry.
        $summaries = \AfricaGates\Services\QuestionnaireSummary::forNominees($dossierIds);

        foreach ($nominees as $n) {
            // Popularity is stripped at the boundary, not merely left unrendered. The row
            // arrives from `select *` carrying vote_count and organic_vote_count, and the
            // template not using them today is a property of today's template — one
            // `{{ n.vote_count }}` added in good faith by somebody building a nicer card
            // would put the community signal back inside the expert one. It cannot be
            // printed if it is not there.
            foreach (\AfricaGates\Services\EvidenceService::FORBIDDEN_FIELDS as $banned) {
                unset($n[$banned]);
            }

            $n['scores'] = $byNominee[$n['id']] ?? [];
            $n['notes']  = isset($notes[$n['id']]) ? (string)$notes[$n['id']]->notes : '';
            $n['avg']    = $this->avgFromScores($n['scores'], $criteria);
            // `count([]) === count([])` is TRUE, so with no rubric every nominee reported
            // itself COMPLETE and the progress counter read "N of N scored" on a ballot
            // where nothing had been or could be scored. The guard is the whole fix.
            $n['complete'] = $criteria !== [] && count($n['scores']) === count($criteria);
            $n['evidence'] = $dossiers[(int) $n['id']] ?? ['items' => [], 'interviews' => [], 'coverage' => null];
            // Null when nobody has asked for one. The ballot renders a button in that case
            // rather than an empty panel, because an empty "what this rests on" reads as a
            // statement that the entry rests on nothing.
            $n['map'] = isset($flagged[(int) $n['id']]) ? null : ($maps[(int) $n['id']] ?? null);
            // Distinct from "no map yet", which offers a button. A judge who disputed one
            // must not be handed the same button on the next page load — that reads as the
            // objection having been ignored, which is exactly what it would be.
            $n['map_flagged'] = isset($flagged[(int) $n['id']]);
            $n['summary'] = $summaries[(int) $n['id']] ?? null;
            $byCategory[$n['category_id']]['nominees'][] = $n;
        }

        // Whether this judge can actually write scores now — the same gate
        // saveScore() enforces server-side. The template uses it to render a
        // read-only ballot with a clear reason instead of live sliders that
        // would only fail on submit.
        // ── AND A MISSING RUBRIC IS A LOCK, NOT A BALLOT WITH NOTHING ON IT ──
        //
        // The rubric is seeded by an OPTIONAL migrate flag (`--with-seed-rubric`), so a
        // deployment that ran `db:migrate` without it has no criteria at all. Every gate
        // below passed, the page rendered, and the panel got a ballot with no score
        // inputs on which every nominee already read as complete. Locking it states the
        // cause instead, and names the fix, because the person who hits this cannot apply
        // it themselves.
        $noRubric = $criteria === [];

        // ── AND AN UNPUBLISHED SHORTLIST IS A LOCK FOR THE SAME REASON ───────
        //
        // Same failure shape as the missing rubric above: every gate passes, the page
        // renders, and the panel gets a ballot with nobody on it. A judge cannot tell an
        // empty ballot from a finished one, and the thing they would report — "there is
        // nothing to score" — is indistinguishable from "I have scored everything".
        //
        // Distinguished from a genuinely empty field: `$beforeCut` counts the approved
        // nominees that EXIST, so "there are entries but none are shortlisted" is a
        // different message from "there are no entries", and only the first one is
        // somebody's outstanding task.
        // Every category that HAS entries has been cut to nothing. Still cycle-level,
        // because `judging_open` is — but now it cannot be defeated by one category
        // having published while the rest have not, which is what let a half-configured
        // cycle render as open with empty categories in it.
        $noShortlist = $nominees === [] && array_sum($beforeCut) > 0;

        // ── THE PHASE IS THE COMPUTED ONE, THE SAME ONE THAT CHOSE THIS CYCLE ─
        //
        // This read `$cycle->status === 'judging'` two screens after cycleToJudge() chose
        // the cycle by its COMPUTED phase — so on a host whose scheduler had not yet
        // materialised the column, the ballot picked the judging cycle and then locked it
        // as "not in the judging phase yet", while a cycle whose results date had passed
        // stayed writable for as long as the cache lagged. One resolver, asked once:
        // {@see self::judgingPhaseOf()}, which saveScore() reads too.
        $phase = self::judgingPhaseOf($cycle);
        $inJudging = $phase === \AfricaGates\Services\CyclePhase::Judging;

        $judgingOpen = $inJudging && !$coi && !$noRubric && !$noShortlist;
        $lockReason = $coi
            ? 'You have declared a conflict of interest for this programme, so scoring is disabled.'
            : ($noRubric
                ? 'No scoring rubric has been set up for this programme, so there is nothing to score '
                  . 'yet. This is a setup step for the organisers, not something you can fix — please '
                  . 'tell them the rubric is missing.'
                : ($noShortlist
                    // Still the programme-level lock, for the case where NOTHING is
                    // judgeable. The per-category sentences above cover the half-configured
                    // cycle this lock could never see: it is silenced by any one category
                    // having nominees, which is exactly when the others went quietly empty.
                    ? 'The shortlist for this programme has not been published yet, so there is nobody '
                      . 'to judge. The panel scores the shortlist rather than every entry — this is a '
                      . 'step for the organisers, not something you can fix. Please tell them the '
                      . 'shortlist is still unpublished.'
                    : (!$inJudging
                        ? ($phase !== null && $phase->ordinal() > \AfricaGates\Services\CyclePhase::Judging->ordinal()
                            ? 'Scoring is closed — judging for this cycle has finished.'
                            : 'Scoring is closed — this cycle is not in the judging phase yet.')
                        : '')));

        return [
            'cycle' => (array)$cycle,
            'criteria' => $criteria,
            'categories' => array_values($byCategory),
            'judging_open' => $judgingOpen,
            'in_judging' => $inJudging,
            'coi' => $coi,
            'no_rubric' => $noRubric,
            'no_shortlist' => $noShortlist,
            'lock_reason' => $lockReason,
            'progress' => [
                'total' => count($nominees),
                'scored' => $criteria === [] ? 0 : count(array_filter(
                    $nominees,
                    fn($n) => count($byNominee[$n['id']] ?? []) === count($criteria)
                )),
            ],
        ];
    }

    /** Save a scoring update for one nominee. */
    /** True if the judge is assigned to the programme that owns this nominee. */
    public function canScore(int $judgeId, int $nomineeId): bool
    {
        $j = $this->findById($judgeId);
        if (!$j || !(int)$j->is_active) return false;

        // ── READ THROUGH programmes(), NOT THE COLUMN ────────────────────────
        //
        // This decoded `programme_ids` itself, which made it a SECOND copy of the
        // assignment rule — and {@see mayJudgeNominee()} already carries a note saying an
        // access check that exists twice is one that will eventually differ. It duly did:
        // the practice programme is appended at read time rather than written to the
        // column, so a judge could open a practice ballot, move every slider, press save
        // and be told they were not assigned to the programme they were looking at.
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $this->programmes($judgeId));
        if (!$ids) return false;

        $progId = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('n.id', $nomineeId)
            ->value('cy.programme_id');
        return $progId !== null && in_array((int)$progId, $ids, true);
    }

    public function saveScore(int $judgeId, int $nomineeId, array $criteriaScores, ?string $notes = null): array
    {
        $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();
        if (!$nominee) return ['ok' => false, 'message' => 'Nominee not found'];
        // Only nominees actually on the ballot are scoreable — a crafted POST must
        // not let a judge pre-score a pending/rejected nominee, or a merged-away
        // tombstone, before/after it leaves the ballot.
        if (!in_array($nominee->status, ['approved', 'winner', 'runner_up'], true) || !empty($nominee->merged_into ?? null)) {
            return ['ok' => false, 'message' => 'This nominee is not open for scoring.'];
        }
        // Authorisation: a judge may only score nominees in a programme they're
        // assigned to — prevents cross-panel score tampering via crafted POSTs.
        if (!$this->canScore($judgeId, $nomineeId)) {
            return ['ok' => false, 'message' => 'You are not assigned to this nominee\'s programme.'];
        }
        $catId = (int)$nominee->category_id;

        // Judging window: scores are writable only while this nominee's cycle is in
        // the 'judging' phase — locked before (nominations/voting) and after (results).
        //
        // The COMPUTED phase, through the same resolver the ballot uses to decide whether
        // to draw the sliders at all. Reading the stored column here while the ballot read
        // the computed phase meant the two could disagree in both directions: sliders drawn
        // and every save refused, or a ballot locked "after judging" still accepting marks.
        $cy = DB::table('gates_award_cycles AS cy')
            ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
            ->where('c.id', $catId)->select('cy.*')->first();
        if (!$cy || self::judgingPhaseOf($cy) !== \AfricaGates\Services\CyclePhase::Judging) {
            return ['ok' => false, 'message' => 'Scoring is closed — this cycle is not in the judging phase.'];
        }
        // Conflict of interest: a judge who recused from this programme cannot score it.
        // Asked BEFORE the shortlist gate below, which refuses a recused judge too and
        // would otherwise answer with the wrong reason.
        if ($this->hasConflict($judgeId, (int)$cy->programme_id)) {
            return ['ok' => false, 'message' => 'You have declared a conflict of interest for this programme.'];
        }

        // ── AND ONLY THE SHORTLIST ──────────────────────────────────────────
        //
        // Enforced server-side as well as in ballot(), for the same reason the status
        // check above is: a tab left open before the shortlist was withdrawn, or a
        // crafted POST, must not be able to write a score for somebody the panel is not
        // judging. Scoping a screen is presentation; this is the rule.
        if (!$this->mayJudgeNominee($judgeId, $nomineeId)) {
            return ['ok' => false, 'saved' => 0,
                    'message' => 'This nominee is not on the published shortlist, so they are not '
                               . 'open for scoring. Reload the ballot — it may have changed since '
                               . 'you opened it.'];
        }

        // Only accept criteria belonging to THIS programme's rubric — silently
        // ignore unknown/injected criterion ids from a crafted request.
        $allowed = array_map('intval', array_column($this->criteria((int)$cy->programme_id), 'id'));

        // ── A RUBRIC THAT DOES NOT EXIST IS NOT A SUCCESSFUL SAVE ────────────
        //
        // This method used to end `return ['ok' => true, 'saved' => $valid]` unconditionally.
        // With no rubric, $allowed is empty, every posted score is skipped as unrecognised,
        // $valid stays 0 — and it answered ok:true. The ballot showed its green "saved"
        // state and stored nothing, for every nominee, for the whole panel. Reporting
        // success for work that was discarded is the worst failure this file can have:
        // a judge has no way to discover it, and the scores are simply absent afterwards.
        if ($allowed === []) {
            return ['ok' => false, 'saved' => 0,
                    'message' => 'No scoring rubric is set up for this programme, so scores cannot be '
                               . 'recorded. Please tell the organisers — this needs fixing on their side.'];
        }

        // ── WHAT THIS JUDGE HAS ALREADY GIVEN THIS NOMINEE ───────────────────
        //
        // Read once, before the loop, so the append-only log below can record what a mark
        // CHANGED FROM. One query rather than one per criterion — the ballot autosaves on
        // a debounce, so this path runs far more often than a judge presses anything.
        $before = [];
        try {
            foreach (DB::table('gates_judge_criteria_scores')
                        ->where('judge_id', $judgeId)->where('nominee_id', $nomineeId)
                        ->get(['criterion_id', 'score']) as $r) {
                $before[(int) $r->criterion_id] = (int) $r->score;
            }
        } catch (\Throwable $e) {
            error_log('[judge] prior scores for ' . $judgeId . '/' . $nomineeId . ': ' . $e->getMessage());
        }

        $valid = 0;
        $trail = [];
        $now   = Carbon::now()->toDateTimeString();

        foreach ($criteriaScores as $criterionId => $score) {
            $cid = (int)$criterionId;
            if (!in_array($cid, $allowed, true)) continue;
            $score = max(0, min(10, (int)$score));

            // Logged only when the value actually MOVES. The ballot re-sends every mark it
            // holds on each autosave, so recording unconditionally would bury the handful
            // of real revisions under thousands of no-ops — which is the same as not
            // having a log.
            $old = $before[$cid] ?? null;
            if ($old !== $score) {
                $trail[] = [
                    'judge_id'     => $judgeId,
                    'nominee_id'   => $nomineeId,
                    'criterion_id' => $cid,
                    // NULL means FIRST MARK, not a score of zero.
                    'old_score'    => $old,
                    'new_score'    => $score,
                    'changed_at'   => $now,
                ];
            }

            DB::table('gates_judge_criteria_scores')->updateOrInsert(
                ['judge_id' => $judgeId, 'nominee_id' => $nomineeId, 'criterion_id' => $cid],
                [
                    'category_id' => $catId,
                    'score' => $score,
                    'updated_at' => $now,
                ]
            );
            $valid++;
        }

        // After the writes, and never allowed to fail one. A log that can refuse a judge's
        // score is worse than a gap in the log: the mark is the thing the platform exists
        // to collect, and the audit trail is what explains it afterwards.
        if ($trail !== []) {
            try {
                DB::table('gates_judge_score_log')->insert($trail);
            } catch (\Throwable $e) {
                error_log('[judge] score log ' . $judgeId . '/' . $nomineeId . ': ' . $e->getMessage());
            }
        }
        if ($notes !== null) {
            DB::table('gates_judge_notes')->updateOrInsert(
                ['judge_id' => $judgeId, 'nominee_id' => $nomineeId],
                [
                    'notes' => mb_substr($notes, 0, 5000),
                    'submitted_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]
            );
        }
        // Scores were offered and none of them landed: every id was outside this
        // programme's rubric. Silently reporting success here is what let a crafted or
        // stale payload look accepted; a judge whose page is out of date needs to be told
        // to reload rather than believing their work was kept.
        if ($valid === 0 && $criteriaScores !== []) {
            return ['ok' => false, 'saved' => 0,
                    'message' => 'None of those scores matched this programme\'s rubric, so nothing was '
                               . 'saved. Reload the page and try again.'];
        }

        // $valid === 0 with no scores posted is a NOTES-ONLY save, which is legitimate —
        // a judge writing a note before scoring anything.
        return ['ok' => true, 'saved' => $valid];
    }

    /**
     * Record a programme-level conflict-of-interest recusal for a judge.
     *
     * ── ONLY ON A PROGRAMME THEY ACTUALLY SIT ON ─────────────────────────────
     *
     * The id arrives from the URL. Unchecked, a typo or a crafted post wrote a row against
     * a programme that does not exist — refused outright by the foreign key on production,
     * which surfaced as a 500 on the one form a judge uses to do the right thing — or
     * against a panel they are not on, which is a recusal from nothing that the audit then
     * reports as a declaration.
     *
     * ── AND THE FIRST DECLARATION'S DATE IS KEPT ─────────────────────────────
     *
     * This was an upsert that rewrote `created_at` on every post. The judging audit orders
     * "declared, then scored" against that stamp, so declaring a second time moved the
     * declaration to after every mark and turned "a control that did not hold" into "a
     * judge recusing partway". The first moment we were told is the fact; a repeat or a
     * re-declaration after a withdrawal is in the audit log.
     *
     * @return array{ok:bool, message:string}
     */
    public function declareConflict(int $judgeId, int $programmeId, ?string $reason = null): array
    {
        $mine = array_map(static fn (array $p): int => (int) $p['id'], $this->programmes($judgeId));
        if ($programmeId < 1 || !in_array($programmeId, $mine, true)) {
            return ['ok' => false, 'message' => 'That programme is not one you are judging.'];
        }

        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
        $now    = Carbon::now()->toDateTimeString();
        $soft   = self::coiIsSoft();

        $existing = DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->first();
        if ($existing) {
            $set = ['reason' => $reason ?? $existing->reason];
            if ($soft) $set['withdrawn_at'] = null;
            DB::table('gates_judge_coi')->where('id', $existing->id)->update($set);
        } else {
            DB::table('gates_judge_coi')->insert([
                'judge_id' => $judgeId, 'programme_id' => $programmeId,
                'reason' => $reason, 'created_at' => $now,
            ]);
        }

        self::auditCoi('judge.conflict_declare', $judgeId, $programmeId, [
            'reason'      => $reason,
            'redeclared'  => $existing ? true : null,
        ]);

        return ['ok' => true, 'message' => 'Conflict of interest recorded — you are recused from scoring this programme.'];
    }

    /**
     * Withdraw a previously-declared conflict of interest (a judge may have declared in error).
     *
     * ── WITHDRAWN, NEVER DELETED ─────────────────────────────────────────────
     *
     * This deleted the row. Withdrawing a recusal does restore the judge's marks to the
     * result — that is what "I declared in error" means, and it is reversible on purpose —
     * but the delete also erased the declaration from the judging audit and wrote nothing
     * anywhere, so "declared a conflict, then withdrew it and kept scoring" left no trace
     * on the one screen built to compare what a judge declared with what they did. The row
     * is stamped `withdrawn_at` now, the audit reads it, and the audit log has the act.
     *
     * ── AND NOT AFTER JUDGING HAS CLOSED ─────────────────────────────────────
     *
     * Once the cycle has gone to results, a withdrawal would put marks back into a decided
     * award — moving every recomputed figure for it — on the say-so of the judge whose
     * marks they are. That needs a person and a reason, not a button on the judge's own
     * screen.
     *
     * @return array{ok:bool, message:string}
     */
    public function withdrawConflict(int $judgeId, int $programmeId): array
    {
        $row = $this->coiFor($judgeId, $programmeId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'There is no conflict of interest on record for this programme.'];
        }

        $cycle = self::cycleToJudge($programmeId);
        $phase = $cycle ? self::judgingPhaseOf($cycle) : null;
        if ($phase !== null && $phase->ordinal() > \AfricaGates\Services\CyclePhase::Judging->ordinal()) {
            return ['ok' => false, 'message' => 'Judging for this programme has closed, so the conflict '
                . 'can no longer be withdrawn here. Please contact the organisers if it was declared in error.'];
        }

        $now = Carbon::now()->toDateTimeString();
        if (self::coiIsSoft()) {
            DB::table('gates_judge_coi')->where('id', $row->id)->update(['withdrawn_at' => $now]);
        } else {
            // Not migrated yet: the only way to withdraw is the old one. The audit log
            // entry below still records that it happened.
            DB::table('gates_judge_coi')->where('id', $row->id)->delete();
        }

        self::auditCoi('judge.conflict_withdraw', $judgeId, $programmeId, [
            'declared_at' => (string) ($row->created_at ?? ''),
        ]);

        return ['ok' => true, 'message' => 'Conflict of interest withdrawn — you can score this programme again.'];
    }

    /** True if the judge has a STANDING (declared, not withdrawn) conflict for the programme. */
    public function hasConflict(int $judgeId, int $programmeId): bool
    {
        return $this->coiFor($judgeId, $programmeId) !== null;
    }

    /** The standing COI recusal row for a judge+programme, or null. */
    public function coiFor(int $judgeId, int $programmeId): ?object
    {
        $q = DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId);
        self::standing($q);

        return $q->first() ?: null;
    }

    /** All standing COI recusals for a judge, with the programme title, newest first. */
    public function conflicts(int $judgeId): array
    {
        $q = DB::table('gates_judge_coi as coi')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'coi.programme_id')
            ->where('coi.judge_id', $judgeId);
        self::standing($q, 'coi.withdrawn_at');

        return $q->orderByDesc('coi.created_at')
            ->select('coi.programme_id', 'p.title as programme', 'coi.reason', 'coi.created_at')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Narrow a `gates_judge_coi` query to recusals that STAND — THE one clause.
     *
     * Public because the scorer asks the same question when it decides whose marks count,
     * and a withdrawn recusal that still barred marks there while re-opening the ballot
     * here would be a judge scoring into a result that ignores them.
     */
    public static function standing(\Illuminate\Database\Query\Builder $q,
                                    string $col = 'withdrawn_at'): \Illuminate\Database\Query\Builder
    {
        if (self::coiIsSoft()) $q->whereNull($col);

        return $q;
    }

    private static function coiIsSoft(): bool
    {
        return \AfricaGates\Support\SchemaHas::column('gates_judge_coi', 'withdrawn_at');
    }

    /**
     * A judge's own act, in the audit log. There is no admin behind it, so `admin_id` is
     * null (the record() sentinel) and the judge is the target.
     *
     * @param array<string,mixed> $meta
     */
    private static function auditCoi(string $action, int $judgeId, int $programmeId, array $meta): void
    {
        (new \AfricaGates\Admin\Services\AuditService())->record(null, $action, 'judge', $judgeId,
            array_filter(['programme_id' => $programmeId, 'by' => 'judge'] + $meta,
                         static fn ($v): bool => $v !== null));
    }

    // ── Judge home / dashboard ──────────────────────────────────────────────

    /**
     * Everything the judge home needs in one payload: a cross-programme overview,
     * per-programme detail (deadline, COI, progress), an auditable activity trail,
     * a self-audit scoring profile, and the published scoring criteria. Safe and
     * fully zeroed when the judge has no assignments.
     */
    public function dashboard(int $judgeId): array
    {
        $progs = $this->programmes($judgeId);

        $programmes = [];
        $total = 0; $scored = 0; $open = 0;
        foreach ($progs as $p) {
            $b        = $this->ballot($judgeId, (int) $p['id']);
            $cycle    = is_array($b['cycle'] ?? null) ? $b['cycle'] : null;
            $progress = $b['progress'] ?? ['total' => 0, 'scored' => 0];
            $coi      = $this->coiFor($judgeId, (int) $p['id']);
            $status   = $cycle['status'] ?? null;
            // The judging deadline is when results are published; fall back to the
            // close of voting if no results date is set.
            $deadline = $cycle['results_date'] ?? ($cycle['voting_close'] ?? null);
            // The computed phase, from the ballot that was just built — not the stored
            // column, which the ballot's own lock stopped trusting. See judgingPhaseOf().
            $judgingOpen = !empty($b['in_judging']) && !$coi;

            // ── PRACTICE IS EXCLUDED FROM EVERY COUNT ────────────────────────
            //
            // "2 of 5 scored" has to be about the round a judge is accountable for. Folding
            // a practice ballot into it makes the one number on the page a lie in both
            // directions: it inflates what is outstanding, and a judge who finishes their
            // real work still reads as unfinished.
            $isPractice = !empty($p['is_practice']);

            if (!$isPractice) {
                $total  += (int) $progress['total'];
                $scored += (int) $progress['scored'];
                if ($judgingOpen) $open++;
            }

            $programmes[] = [
                'is_practice'  => $isPractice,
                'programme'    => $p,
                'cycle'        => $cycle,
                'progress'     => $progress,
                'categories'   => count($b['categories'] ?? []),
                'coi'          => $coi ? (array) $coi : null,
                'status'       => $status,
                'judging_open' => $judgingOpen,
                'deadline'     => $deadline,
                'days_left'    => $this->daysUntil($deadline),
            ];
        }

        $realProgs = array_values(array_filter($progs, static fn (array $p): bool => empty($p['is_practice'])));

        return [
            'overview' => [
                'programmes' => count($realProgs),
                'total'      => $total,
                'scored'     => $scored,
                'remaining'  => max(0, $total - $scored),
                'pct'        => $total > 0 ? (int) round($scored / $total * 100) : 0,
                'open'       => $open,
            ],
            'programmes' => $programmes,
            'activity'   => $this->activity($judgeId, 10),
            'summary'    => $this->scoringSummary($judgeId),
            'conflicts'  => $this->conflicts($judgeId),
            // The rubric a judge is shown on their home page is the one for their REAL
            // panel; showing the practice programme's override to somebody who has a real
            // assignment would misstate what they are about to be asked.
            'criteria'   => ($realProgs ?: $progs)
                ? $this->criteria((int) ($realProgs[0]['id'] ?? $progs[0]['id']))
                : [],
        ];
    }

    /**
     * An auditable trail of this judge's scoring: one entry per nominee touched,
     * with the weighted average given, how many criteria were marked, and when it
     * was last updated — newest first.
     */
    public function activity(int $judgeId, int $limit = 12): array
    {
        $byNom = [];
        foreach ($this->rawScores($judgeId) as $r) {
            $k = (int) $r->nominee_id;
            $byNom[$k] ??= [
                'nominee_id' => $k, 'nominee' => $r->nominee, 'category' => $r->category,
                'programme' => $r->programme, 'ws' => 0.0, 'wt' => 0.0, 'count' => 0, 'last_at' => $r->updated_at,
            ];
            $byNom[$k]['ws']    += $r->score * $r->weight;
            $byNom[$k]['wt']    += $r->weight;
            $byNom[$k]['count'] += 1;
            if ((string) $r->updated_at > (string) $byNom[$k]['last_at']) {
                $byNom[$k]['last_at'] = $r->updated_at;
            }
        }

        $out = [];
        foreach ($byNom as $v) {
            $out[] = [
                'nominee_id'      => $v['nominee_id'],
                'nominee'         => $v['nominee'],
                'category'        => $v['category'],
                'programme'       => $v['programme'],
                'criteria_scored' => $v['count'],
                'avg'             => $v['wt'] > 0 ? round($v['ws'] / $v['wt'], 1) : 0.0,
                'last_at'         => $v['last_at'],
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));
        return array_slice($out, 0, $limit);
    }

    /**
     * A judge's self-audit profile: how many marks given, their average, the
     * spread, and a 0–10 distribution. Surfacing this lets a judge see leniency
     * or harshness bias and whether they use the full scale.
     */
    public function scoringSummary(int $judgeId): array
    {
        $scores = [];
        foreach ($this->rawScores($judgeId) as $r) { $scores[] = (int) $r->score; }
        $n = count($scores);

        $bands = ['low' => 0, 'mid' => 0, 'good' => 0, 'high' => 0]; // 0-3 / 4-6 / 7-8 / 9-10
        foreach ($scores as $s) {
            if ($s <= 3)      $bands['low']++;
            elseif ($s <= 6)  $bands['mid']++;
            elseif ($s <= 8)  $bands['good']++;
            else              $bands['high']++;
        }

        $notes = DB::table('gates_judge_notes')->where('judge_id', $judgeId)
            ->whereNotNull('notes')->where('notes', '!=', '')->count();

        return [
            'total_marks'   => $n,
            'avg'           => $n > 0 ? round(array_sum($scores) / $n, 1) : null,
            'min'           => $n > 0 ? min($scores) : null,
            'max'           => $n > 0 ? max($scores) : null,
            'range_used'    => $n > 0 ? max($scores) - min($scores) : 0,
            'bands'         => $bands,
            'notes_written' => $notes,
        ];
    }

    /** Every score this judge has given, joined to criterion weight + names. */
    private function rawScores(int $judgeId): \Illuminate\Support\Collection
    {
        return DB::table('gates_judge_criteria_scores as s')
            ->leftJoin('gates_judge_criteria as cr', 'cr.id', '=', 's.criterion_id')
            ->leftJoin('gates_nominees as n', 'n.id', '=', 's.nominee_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 's.category_id')
            ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('s.judge_id', $judgeId)
            ->select(
                's.nominee_id', 's.score', 's.updated_at',
                DB::raw('COALESCE(cr.weight, 25) as weight'),
                'n.name as nominee', 'c.title as category', 'p.title as programme'
            )->get();
    }

    /** Whole days from now until $dt (negative if past), or null. */
    private function daysUntil(?string $dt): ?int
    {
        if (!$dt) return null;
        $ts = strtotime($dt);
        return $ts ? (int) ceil(($ts - time()) / 86400) : null;
    }

    private function avgFromScores(array $scores, array $criteria): float
    {
        if (!$scores || !$criteria) return 0;
        $weights = array_column($criteria, 'weight', 'id');
        $weightedSum = 0;
        $weightTotal = 0;
        foreach ($scores as $cid => $s) {
            $w = $weights[$cid] ?? 25;
            $weightedSum += $s * $w;
            $weightTotal += $w;
        }
        return $weightTotal > 0 ? round($weightedSum / $weightTotal, 1) : 0;
    }
}
