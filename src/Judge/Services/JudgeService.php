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

    /** Programmes this judge is assigned to. */
    public function programmes(int $judgeId): array
    {
        $j = $this->findById($judgeId);
        if (!$j) return [];
        $ids = $j->programme_ids ? (json_decode((string)$j->programme_ids, true) ?: []) : [];

        $out = $ids
            ? DB::table('gates_award_programmes')->whereIn('id', $ids)->orderBy('sort_order')
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
        if ((int) ($j->is_active ?? 0) === 1) {
            $practice = $this->practiceProgramme();
            if ($practice !== null && !in_array((int) $practice['id'], array_map('intval', $ids), true)) {
                $out[] = $practice;
            }
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

        $mine = array_map(static fn (array $p): int => (int) $p['id'], $this->programmes($judgeId));
        if ($mine === []) return null;

        try {
            return DB::table('gates_nominee_evidence as e')
                ->join('gates_nominees as n', 'n.id', '=', 'e.nominee_id')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->where('e.id', $evidenceId)
                ->where('e.visible_to_judges', 1)
                ->whereIn('cy.programme_id', $mine)
                // A tombstone left the ballot; its dossier goes with it.
                ->whereNull('n.merged_into')
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->first(['e.id', 'e.title', 'e.source_url', 'e.nominee_id']);
        } catch (\Throwable $ex) {
            error_log('[judge] evidence lookup ' . $evidenceId . ': ' . $ex->getMessage());
            return null;
        }
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
            return DB::table('gates_nominees as n')
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
                ->exists();
        } catch (\Throwable $ex) {
            error_log('[judge] nominee access ' . $nomineeId . ': ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Nominee ids on a published shortlist in this cycle.
     *
     * Separated from {@see \AfricaGates\Services\ShortlistService::shortlistedIn()} only by
     * being fault-tolerant: the shortlist tables arrive in a migration, and a judging portal
     * that 500s on a deployment mid-upgrade is worse than one that locks and says why.
     *
     * @return array<int,true>
     */
    private function shortlistedIn(int $cycleId): array
    {
        try {
            return \AfricaGates\Services\ShortlistService::shortlistedIn($cycleId);
        } catch (\Throwable $e) {
            error_log('[judge] shortlist lookup for cycle ' . $cycleId . ': ' . $e->getMessage());
            return [];
        }
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
            try {
                if (\AfricaGates\Services\CyclePolicy::phaseFor($c)
                    === \AfricaGates\Services\CyclePhase::Judging) {
                    return $c;
                }
            } catch (\Throwable) {
                // A row with unreadable windows is not a reason to show no ballot.
            }
        }

        return $cycles->first();
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
        $shortlisted = $this->shortlistedIn((int) $cycle->id);
        $beforeCut   = count($nominees);
        $nominees    = array_values(array_filter(
            $nominees,
            static fn (array $n): bool => isset($shortlisted[(int) $n['id']])
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

        $byCategory = [];
        foreach ($cats as $c) {
            $byCategory[$c['id']] = ['category' => $c, 'nominees' => []];
        }
        // The dossier, in one query for the whole ballot rather than one per nominee on
        // the screen a judge keeps open for hours. See EvidenceService.
        $dossiers = (new \AfricaGates\Services\EvidenceService())
            ->forBallot(array_column($nominees, 'id'));

        // The dossier maps that ALREADY exist. Read-only on render, deliberately: a judge
        // opening a ballot of forty must not start forty model calls by scrolling, and a
        // page that spends money on render spends it again on every refresh and every back
        // button. The ballot shows what is there and offers a button for the rest.
        $maps = \AfricaGates\Services\JudgeAssist::forBallot(array_column($nominees, 'id'));

        // The summary the nominee themselves confirmed, in their own submission. Distinct
        // from the dossier map above and shown separately: the map is ours, written for a
        // judge; this is a description of the entry that the nominee read and agreed
        // represents them before pressing send. Confirmed-only — a draft summary nobody
        // approved must never sit at the top of somebody's entry.
        $summaries = \AfricaGates\Services\QuestionnaireSummary::forNominees(
            array_column($nominees, 'id'));

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
            $n['map'] = $maps[(int) $n['id']] ?? null;
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
        $coi = $this->hasConflict($judgeId, $programmeId);
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
        $noShortlist = $nominees === [] && $beforeCut > 0;

        $judgingOpen = ($cycle->status === 'judging') && !$coi && !$noRubric && !$noShortlist;
        $lockReason = $coi
            ? 'You have declared a conflict of interest for this programme, so scoring is disabled.'
            : ($noRubric
                ? 'No scoring rubric has been set up for this programme, so there is nothing to score '
                  . 'yet. This is a setup step for the organisers, not something you can fix — please '
                  . 'tell them the rubric is missing.'
                : ($noShortlist
                    ? 'The shortlist for this programme has not been published yet, so there is nobody '
                      . 'to judge. The panel scores the shortlist rather than every entry — this is a '
                      . 'step for the organisers, not something you can fix. Please tell them the '
                      . 'shortlist is still unpublished.'
                    : (($cycle->status !== 'judging')
                        ? 'Scoring is closed — this cycle is not in the judging phase yet.'
                        : '')));

        return [
            'cycle' => (array)$cycle,
            'criteria' => $criteria,
            'categories' => array_values($byCategory),
            'judging_open' => $judgingOpen,
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
        $catId = (int)$nominee->category_id;

        // Judging window: scores are writable only while this nominee's cycle is in
        // the 'judging' phase — locked before (nominations/voting) and after (results).
        $cy = DB::table('gates_award_cycles AS cy')
            ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
            ->where('c.id', $catId)->select('cy.status', 'cy.programme_id')->first();
        if (!$cy || $cy->status !== 'judging') {
            return ['ok' => false, 'message' => 'Scoring is closed — this cycle is not in the judging phase.'];
        }
        // Conflict of interest: a judge who recused from this programme cannot score it.
        if ($this->hasConflict($judgeId, (int)$cy->programme_id)) {
            return ['ok' => false, 'message' => 'You have declared a conflict of interest for this programme.'];
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

    /** Record a programme-level conflict-of-interest recusal for a judge. */
    public function declareConflict(int $judgeId, int $programmeId, ?string $reason = null): void
    {
        DB::table('gates_judge_coi')->updateOrInsert(
            ['judge_id' => $judgeId, 'programme_id' => $programmeId],
            [
                'reason'     => $reason !== null && $reason !== '' ? mb_substr(trim($reason), 0, 500) : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]
        );
    }

    /** Withdraw a previously-declared conflict of interest (a judge may have declared in error). */
    public function withdrawConflict(int $judgeId, int $programmeId): void
    {
        DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->delete();
    }

    /** True if the judge has declared a conflict of interest for the programme. */
    public function hasConflict(int $judgeId, int $programmeId): bool
    {
        return DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->exists();
    }

    /** The COI recusal row for a judge+programme, or null. */
    public function coiFor(int $judgeId, int $programmeId): ?object
    {
        return DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->first() ?: null;
    }

    /** All COI recusals for a judge, with the programme title, newest first. */
    public function conflicts(int $judgeId): array
    {
        return DB::table('gates_judge_coi as coi')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'coi.programme_id')
            ->where('coi.judge_id', $judgeId)
            ->orderByDesc('coi.created_at')
            ->select('coi.programme_id', 'p.title as programme', 'coi.reason', 'coi.created_at')
            ->get()->map(fn ($r) => (array) $r)->all();
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
            $judgingOpen = $status === 'judging' && !$coi;

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
