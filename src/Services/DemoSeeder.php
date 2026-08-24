<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Slug;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A nominee you can drive through every screen, without touching a real award.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS A WHOLE SANDBOX PROGRAMME AND NOT A FLAGGED ROW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious approach is `gates_nominees.is_demo` and a filter wherever it matters. It is
 * the wrong one, and the reason is arithmetic rather than taste: a nominee participates in
 * vote tallies, category ranks, the CPI, headline gaps, the shortlist cut, leaderboards and
 * the standings chain. "Wherever it matters" is a dozen queries in nine files, and the ONE
 * that gets missed is the one that quietly counts a test vote toward a real result. Nobody
 * finds that by looking; they find it when a nominee asks why the numbers moved.
 *
 * So the demo is CONTAINED instead. It gets its own programme, its own cycle and its own
 * category, and everything about it lives inside them:
 *
 *   · Every tally, rank, gap and shortlist cut on this platform is computed PER CATEGORY.
 *     A demo category cannot contribute to a real one — not because a filter excludes it,
 *     but because the query never reaches it.
 *   · The programme is `is_active = 0`, and both public readers ({@see AwardService}'s
 *     programme list and slug lookup) already require `is_active = 1`. So it is invisible
 *     on the public site with no new filter anywhere.
 *   · The questionnaire keeps using the `is_test` flag it already has, because that one is
 *     real and already honoured — by the invitation planner, the disqualification rule and
 *     the queue screen.
 *
 * Containment is also what makes {@see purge()} honest: everything the seeder made hangs
 * off one programme id, so removing it is a delete by that id and not a hunt.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT IS OBVIOUSLY FAKE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every name starts with "DEMO —". Not decoration: an operator who is one tab away from the
 * live console has to be unable to mistake a rehearsal for the real thing, and the place
 * they will be looking is the name in a table row. The email domain is `demo.invalid`,
 * which RFC 6761 reserves and no mail server will ever accept — so a stray send cannot
 * reach a person.
 */
final class DemoSeeder
{
    public const PROGRAMME_SLUG = 'demo-sandbox';
    public const PREFIX         = 'DEMO — ';

    /** RFC 6761 reserves .invalid, so nothing here can ever be delivered to a person. */
    /**
     * The sandbox's identity, and the thing public readers exclude on.
     *
     * PUBLIC rather than private because it is now a contract with the rest of the codebase:
     * {@see \AfricaGates\Judge\Services\JudgeService::realJudges()} keeps the sandbox's
     * rehearsal panellist off the public "Meet the Judges" page by reading it.
     *
     * `.invalid` is reserved by RFC 2606 precisely so it can never resolve to a real
     * address, which is what makes it safe to filter on: no genuine judge, nominee or
     * nominator can ever be excluded by mistake.
     */
    public const MAIL_DOMAIN = 'demo.invalid';

    /**
     * Build (or rebuild) the sandbox and return where to click.
     *
     * Idempotent by teardown-and-rebuild rather than by upsert: the point of pressing this
     * twice is to get a clean rehearsal, and half-updating a nominee somebody has already
     * scored is worse than starting again.
     *
     * @return array{ok:bool, programme_id:int, cycle_id:int, category_id:int,
     *               nominees:array<string,int>, links:list<array{label:string,href:string}>,
     *               message:string}
     */
    public static function seed(int $adminId = 0): array
    {
        return DB::transaction(function () use ($adminId) {
            self::purge();

            $now = Carbon::now();

            // ── the programme ────────────────────────────────────────────────
            //
            // `is_active = 0` is the whole public-invisibility mechanism. Both public
            // readers already require 1, so nothing new has to know about the demo.
            $programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
                'slug'        => self::PROGRAMME_SLUG,
                'title'       => self::PREFIX . 'Sandbox Programme',
                'subtitle'    => 'A rehearsal space. Nothing here is a real nomination.',
                'description' => 'Everything in this programme is test data created by the demo '
                               . 'seeder. It is hidden from the public site because the programme '
                               . 'is inactive, and its votes and scores cannot reach a real '
                               . 'category. Delete it from Programmes when you are done.',
                'icon_emoji'  => '🧪',
                'scope'       => 'national',
                'is_active'   => 0,
                // Last, so it never sits above a real programme on an admin list.
                //
                // 200 and not 999: `gates_award_programmes.sort_order` is TINYINT UNSIGNED
                // in MySQL, so 999 is out of range and strict mode REJECTS the insert — a
                // 500 on the build button. SQLite does not enforce integer widths, so the
                // whole seeder passed its tests and failed on the only database that
                // matters. Any value over 255 is the same bug.
                'sort_order'  => 200,
            ]);

            // ── the cycle ────────────────────────────────────────────────────
            //
            // Windows placed so the COMPUTED phase is `voting` right now: nominations
            // already closed, voting open, results ahead. That is the phase most of the
            // screens an operator wants to rehearse actually depend on — a sandbox stuck in
            // `upcoming` shows a ballot nobody can use.
            $cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
                'programme_id'      => $programmeId,
                'year'              => (int) $now->format('Y'),
                'edition_label'     => 'Rehearsal',
                'status'            => 'voting',
                'nominations_open'  => $now->copy()->subDays(40)->toDateTimeString(),
                'nominations_close' => $now->copy()->subDays(10)->toDateTimeString(),
                'voting_open'       => $now->copy()->subDays(5)->toDateTimeString(),
                'voting_close'      => $now->copy()->addDays(20)->toDateTimeString(),
                'results_date'      => $now->copy()->addDays(30)->toDateTimeString(),
            ]);

            $categoryId = (int) DB::table('gates_award_categories')->insertGetId([
                'cycle_id'    => $cycleId,
                'slug'        => 'demo-category',
                'title'       => self::PREFIX . 'Community Impact',
                'description' => 'A rehearsal category.',
                'sort_order'  => 1,
            ]);

            // ── the nominees ─────────────────────────────────────────────────
            //
            // Three, because the interesting screens need more than one row to be worth
            // looking at: a shortlist cut needs somebody above and below the line, and a
            // moderation queue needs something still pending.
            $ids = [];
            foreach ([
                ['leader',  'Adaeze Nwosu',      420, 400, 'approved'],
                ['runner',  'Kwabena Mensah',    180, 175, 'approved'],
                ['pending', 'Fatimata Diallo',     0,   0, 'pending'],
            ] as [$key, $name, $votes, $organic, $status]) {
                $ids[$key] = (int) DB::table('gates_nominees')->insertGetId([
                    'category_id'        => $categoryId,
                    'name'               => self::PREFIX . $name,
                    'tagline'            => 'Rehearsal nominee — not a real person.',
                    'story'              => 'This nominee exists so the judging, interview and '
                                          . 'questionnaire screens can be tried end to end without '
                                          . 'touching a real award.',
                    'country_code'       => 'NG',
                    'organisation'       => 'Demo Collective',
                    'vote_count'         => $votes,
                    'organic_vote_count' => $organic,
                    'status'             => $status,
                    'nominated_at'       => $now->copy()->subDays(20)->toDateTimeString(),
                ]);

                // The nomination behind it, so contact resolution has something to find —
                // ClaimIndependence matches by name within the category, not by an id.
                DB::table('gates_nominations')->insert([
                    'cycle_id'        => $cycleId,
                    'category_id'     => $categoryId,
                    'nominee_name'    => self::PREFIX . $name,
                    'nominee_email'   => self::mail($name),
                    'country_code'    => 'NG',
                    'reason'          => 'Seeded by the demo builder.',
                    'nominator_name'  => 'Demo Nominator',
                    'nominator_email' => 'nominator@' . self::MAIL_DOMAIN,
                    'status'          => $status === 'pending' ? 'pending' : 'approved',
                ]);
            }

            self::seedVotes($categoryId, $ids, $now);
            self::seedQuestionnaires($programmeId, $cycleId, $ids, $adminId, $now);
            self::seedEvidence($ids, $now);
            self::seedInterview($ids['leader'], $now);
            $judgeId = self::seedJudge($programmeId);
            self::seedScores($judgeId, $categoryId, $programmeId, $ids, $now);
            self::seedShortlistRule($cycleId);

            return [
                'ok' => true, 'programme_id' => $programmeId, 'cycle_id' => $cycleId,
                'category_id' => $categoryId, 'nominees' => $ids,
                'links' => self::links($programmeId, $cycleId, $categoryId, $ids),
                'message' => 'Sandbox built: 3 nominees, a questionnaire in each state, evidence, '
                           . 'an interview, a judge with scores, and votes. Nothing is public and '
                           . 'nothing counts toward a real award.',
            ];
        });
    }

    /** A deliverable-looking address at a domain no mail server will ever accept. */
    private static function mail(string $name): string
    {
        return Slug::make($name, 40) . '@' . self::MAIL_DOMAIN;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE PARTS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Individual ballots behind the tallies.
     *
     * `vote_count` was already set on the nominee, and these rows make the two agree —
     * because several screens read the tally and several read the ballots, and a sandbox
     * where they disagree teaches an operator to distrust whichever one they looked at.
     * Not one row per vote: 600 inserts to rehearse a screen is a slow button. Twenty
     * sampled ballots is enough for every "recent voters" and per-day chart to have shape.
     *
     * @param array<string,int> $ids
     */
    private static function seedVotes(int $categoryId, array $ids, Carbon $now): void
    {
        $rows = [];
        foreach (['leader' => 14, 'runner' => 6] as $key => $n) {
            for ($i = 0; $i < $n; $i++) {
                // A hash, not an address: gates_votes stores voter_email_hash and nothing
                // here should look like a real voter's mail even in a test.
                $rows[] = [
                    'nominee_id'       => $ids[$key],
                    'category_id'      => $categoryId,
                    'voter_email_hash' => hash('sha256', "demo:{$key}:{$i}"),
                    'nominee_country'  => 'NG',
                    'voter_name'       => 'Demo Supporter ' . ($i + 1),
                    'voted_at'         => $now->copy()->subHours(($i * 5) + 1)->toDateTimeString(),
                ];
            }
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            try { DB::table('gates_votes')->insert($chunk); }
            catch (\Throwable) {
                // A unique index on (voter_email_hash, category_id) is doing its job on a
                // re-run whose purge missed something. Not worth failing the whole build.
            }
        }
    }

    /**
     * One questionnaire per state, because the screens differ by state and an operator
     * rehearsing needs to see all three: never invited, invited-and-answered, and a
     * rehearsal row.
     *
     * @param array<string,int> $ids
     */
    private static function seedQuestionnaires(int $programmeId, int $cycleId, array $ids,
                                               int $adminId, Carbon $now): void
    {
        // Questions first, or `saveDraft` keeps nothing: cleanAnswers() filters submitted
        // answers against the programme's defined questions.
        $questions = [
            ['impact',    'What changed because of this work?'],
            ['evidence',  'What can you show us?'],
            ['obstacles', 'What has not worked, and what did you learn?'],
        ];
        foreach ($questions as $i => [$slug, $label]) {
            DB::table('gates_programme_questions')->insert([
                'programme_id'  => $programmeId,
                'slug'          => $slug,
                'kind'          => 'textarea',
                'label'         => $label,
                'help'          => 'Rehearsal question.',
                'evidence_kind' => 'note',
                'is_required'   => $i === 0 ? 1 : 0,
                'max_len'       => 1200,
                'sort_order'    => $i + 1,
                'is_active'     => 1,
            ]);
        }

        $answers = json_encode([
            'impact'    => 'Two hundred and forty households now have a working borehole within '
                         . 'a fifteen minute walk. Before this it was ninety minutes each way.',
            'evidence'  => 'Council sign-off letter, the handover photographs, and the water '
                         . 'board test certificate.',
            'obstacles' => 'The first pump we installed was the wrong specification for the '
                         . 'water table and failed in four months. We replaced it at our own '
                         . 'cost and now test the borehole before ordering.',
        ], JSON_UNESCAPED_UNICODE);

        // SUBMITTED — what a judge reads.
        DB::table('gates_nominee_submissions')->insert([
            'nominee_id'    => $ids['leader'],
            'programme_id'  => $programmeId,
            'cycle_id'      => $cycleId,
            'invite_token'  => bin2hex(random_bytes(16)),
            'status'        => 'submitted',
            'answers_json'  => $answers,
            'works_json'    => json_encode([]),
            'invited_at'    => $now->copy()->subDays(8)->toDateTimeString(),
            'started_at'    => $now->copy()->subDays(7)->toDateTimeString(),
            'submitted_at'  => $now->copy()->subDays(6)->toDateTimeString(),
            'declared_name' => self::PREFIX . 'Adaeze Nwosu',
            'created_by'    => $adminId ?: null,
            'created_at'    => $now->copy()->subDays(8)->toDateTimeString(),
        ]);

        // DRAFT, invited — the chase-up case the invitation screen is built around.
        DB::table('gates_nominee_submissions')->insert([
            'nominee_id'   => $ids['runner'],
            'programme_id' => $programmeId,
            'cycle_id'     => $cycleId,
            'invite_token' => bin2hex(random_bytes(16)),
            'status'       => 'draft',
            'answers_json' => json_encode(['impact' => 'We started in 2021 and']),
            'works_json'   => json_encode([]),
            'invited_at'   => $now->copy()->subDays(8)->toDateTimeString(),
            'started_at'   => $now->copy()->subDays(4)->toDateTimeString(),
            'created_by'   => $adminId ?: null,
            'created_at'   => $now->copy()->subDays(8)->toDateTimeString(),
        ]);

        // NEVER INVITED — so the "never invited" audience filter has something in it.
        DB::table('gates_nominee_submissions')->insert([
            'nominee_id'   => $ids['pending'],
            'programme_id' => $programmeId,
            'cycle_id'     => $cycleId,
            'invite_token' => bin2hex(random_bytes(16)),
            'status'       => 'draft',
            'created_by'   => $adminId ?: null,
            'created_at'   => $now->copy()->subDays(2)->toDateTimeString(),
        ]);
    }

    /** @param array<string,int> $ids */
    private static function seedEvidence(array $ids, Carbon $now): void
    {
        $rows = [
            ['note', 'Borehole handover letter', 'Signed by the district council on 4 March.',
             'nominee_supplied'],
            ['link', 'Local press coverage', 'A write-up in the state paper.', 'nominee_supplied'],
            ['note', 'What went wrong first time', 'The nominee\'s own account of the failed pump.',
             'nominee_supplied'],
        ];
        foreach ($rows as $i => [$kind, $title, $body, $prov]) {
            DB::table('gates_nominee_evidence')->insert([
                'nominee_id'        => $ids['leader'],
                'kind'              => $kind,
                'title'             => self::PREFIX . $title,
                'body'              => $body,
                'source_label'      => 'Demo source',
                'source_url'        => $kind === 'link' ? 'https://example.invalid/demo-coverage' : null,
                'provenance'        => $prov,
                'verified'          => $i === 0 ? 1 : 0,
                'visible_to_judges' => 1,
                'sort_order'        => $i + 1,
                'created_at'        => $now->copy()->subDays(6)->toDateTimeString(),
            ]);
        }
    }

    private static function seedInterview(int $nomineeId, Carbon $now): void
    {
        DB::table('gates_nominee_interviews')->insert([
            'nominee_id'        => $nomineeId,
            'interviewed_at'    => $now->copy()->subDays(3)->toDateTimeString(),
            'interviewer'       => 'Demo Panel',
            'medium'            => 'video',
            'language'          => 'en',
            'transcript'        => "Q: Tell us what changed.\n"
                                 . "A: The walk to water went from ninety minutes to fifteen. "
                                 . "That is two hours a day back for two hundred and forty households.\n\n"
                                 . "Q: What did not work?\n"
                                 . "A: Our first pump was wrong for the water table. It failed in "
                                 . "four months and we replaced it ourselves.",
            'transcript_source' => 'human',
            'transcriber'       => 'Demo seeder',
            'consent_given'     => 1,
            'consent_note'      => 'Rehearsal record — no real person was interviewed.',
            'status'            => 'published',
            'created_at'        => $now->copy()->subDays(3)->toDateTimeString(),
        ]);
    }

    /**
     * A judge who can actually sign in, and criteria for them to score against.
     *
     * The criteria matter more than they look: {@see \AfricaGates\Judge\Services\JudgeService}
     * locks scoring when a programme has none, and a sandbox that cannot be scored does not
     * rehearse the one screen an operator most wants to check.
     */
    private static function seedJudge(int $programmeId): int
    {
        foreach ([
            ['impact',      'Impact',      40, 'What actually changed, and for how many.'],
            ['originality', 'Originality', 25, 'Whether this needed doing this way.'],
            ['evidence',    'Evidence',    20, 'Whether the claim can be checked.'],
            ['durability',  'Durability',  15, 'Whether it survives the nominee leaving.'],
        ] as $i => [$slug, $label, $weight, $desc]) {
            DB::table('gates_judge_criteria')->insert([
                'programme_id' => $programmeId,
                'slug'         => $slug,
                'label'        => $label,
                'description'  => $desc,
                'weight'       => $weight,
                'sort_order'   => $i + 1,
                'is_active'    => 1,
            ]);
        }

        // Assignments live in a JSON column on the judge, not a join table.
        return (int) DB::table('gates_judges')->insertGetId([
            'name'          => self::PREFIX . 'Test Judge',
            'email'         => 'judge@' . self::MAIL_DOMAIN,
            'title'         => 'Rehearsal panellist',
            'organisation'  => 'Demo Collective',
            'bio'           => 'Seeded so the judge portal can be tried without appointing anybody.',
            'country_code'  => 'NG',
            'programme_ids' => json_encode([$programmeId]),
            'is_active'     => 1,
        ]);
    }

    /**
     * One nominee scored, one not.
     *
     * Deliberately partial: "what does a half-finished panel look like" is the question the
     * judging screens are built to answer, and a sandbox where everything is scored cannot
     * show it.
     *
     * @param array<string,int> $ids
     */
    private static function seedScores(int $judgeId, int $categoryId, int $programmeId,
                                       array $ids, Carbon $now): void
    {
        $criteria = DB::table('gates_judge_criteria')
            ->where('programme_id', $programmeId)->orderBy('sort_order')->get(['id', 'slug']);

        $marks = ['impact' => 9, 'originality' => 7, 'evidence' => 8, 'durability' => 6];

        foreach ($criteria as $c) {
            DB::table('gates_judge_criteria_scores')->insert([
                'judge_id'     => $judgeId,
                'nominee_id'   => $ids['leader'],
                'category_id'  => $categoryId,
                'criterion_id' => (int) $c->id,
                'score'        => $marks[(string) $c->slug] ?? 7,
                'created_at'   => $now->copy()->subDays(2)->toDateTimeString(),
                'updated_at'   => $now->copy()->subDays(2)->toDateTimeString(),
            ]);
        }
    }

    /** A rule that puts one nominee above the line and one below, so the cut is visible. */
    private static function seedShortlistRule(int $cycleId): void
    {
        try {
            ShortlistService::saveRule($cycleId, null, new ShortlistRule('top_n', 1, 1), 0);
        } catch (\Throwable) {
            // The shortlist tables arrive in a migration. A deployment mid-upgrade should
            // still get a usable sandbox rather than a failed build.
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TEARDOWN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Remove the sandbox and everything hanging off it.
     *
     * Everything is reachable from the programme id, which is the point of containing it in
     * one — a per-row flag would make this a hunt across nine tables, and the row nobody
     * remembered is the one that stays behind counting toward something.
     *
     * @return array{ok:bool, removed:bool, message:string}
     */
    public static function purge(): array
    {
        $programme = DB::table('gates_award_programmes')
            ->where('slug', self::PROGRAMME_SLUG)->first(['id']);

        if (!$programme) return ['ok' => true, 'removed' => false, 'message' => 'There is no sandbox to remove.'];

        $pid       = (int) $programme->id;
        $cycleIds  = DB::table('gates_award_cycles')->where('programme_id', $pid)->pluck('id')->all();
        $catIds    = $cycleIds === [] ? []
                   : DB::table('gates_award_categories')->whereIn('cycle_id', $cycleIds)->pluck('id')->all();
        $nomIds    = $catIds === [] ? []
                   : DB::table('gates_nominees')->whereIn('category_id', $catIds)->pluck('id')->all();

        // Children first: several of these have foreign keys, and SQLite enforces them.
        if ($nomIds !== []) {
            DB::table('gates_nominee_evidence')->whereIn('nominee_id', $nomIds)->delete();
            DB::table('gates_nominee_interviews')->whereIn('nominee_id', $nomIds)->delete();
            DB::table('gates_nominee_submissions')->whereIn('nominee_id', $nomIds)->delete();
            DB::table('gates_judge_criteria_scores')->whereIn('nominee_id', $nomIds)->delete();
            DB::table('gates_votes')->whereIn('nominee_id', $nomIds)->delete();
        }
        if ($catIds !== []) {
            DB::table('gates_nominations')->whereIn('category_id', $catIds)->delete();
        }
        if ($cycleIds !== []) {
            foreach (['gates_shortlist_entries' => null,
                      'gates_shortlists' => 'cycle_id',
                      'gates_shortlist_rules' => 'cycle_id',
                      'gates_questionnaire_policy' => 'cycle_id'] as $table => $col) {
                try {
                    if ($col === null) {
                        // Entries hang off shortlists, which hang off the cycle.
                        $slIds = DB::table('gates_shortlists')->whereIn('cycle_id', $cycleIds)->pluck('id')->all();
                        if ($slIds !== []) DB::table($table)->whereIn('shortlist_id', $slIds)->delete();
                        continue;
                    }
                    DB::table($table)->whereIn($col, $cycleIds)->delete();
                } catch (\Throwable) {
                    // A table this migration has not created yet. Nothing to remove from it.
                }
            }
        }

        DB::table('gates_programme_questions')->where('programme_id', $pid)->delete();
        DB::table('gates_judge_criteria')->where('programme_id', $pid)->delete();
        DB::table('gates_judges')->where('email', 'judge@' . self::MAIL_DOMAIN)->delete();

        // The cascade on gates_award_cycles → categories → nominees does the rest on MySQL,
        // and is declared in the SQLite schema too. Deleted explicitly regardless: relying
        // on a cascade to remove test data means a deployment with foreign keys off keeps it.
        if ($nomIds !== []) DB::table('gates_nominees')->whereIn('id', $nomIds)->delete();
        if ($catIds !== []) DB::table('gates_award_categories')->whereIn('id', $catIds)->delete();
        if ($cycleIds !== []) DB::table('gates_award_cycles')->whereIn('id', $cycleIds)->delete();
        DB::table('gates_award_programmes')->where('id', $pid)->delete();

        return ['ok' => true, 'removed' => true,
                'message' => 'Sandbox removed — the programme, its cycle, category, nominees, '
                           . 'votes, questionnaires, evidence, interview and judge.'];
    }

    /** Does a sandbox exist right now? */
    public static function exists(): bool
    {
        try {
            return DB::table('gates_award_programmes')->where('slug', self::PROGRAMME_SLUG)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{programme_id:int,cycle_id:int,category_id:int,links:list<array{label:string,href:string}>}|null */
    public static function current(): ?array
    {
        $p = DB::table('gates_award_programmes')->where('slug', self::PROGRAMME_SLUG)->first(['id']);
        if (!$p) return null;

        $pid = (int) $p->id;
        $cid = (int) (DB::table('gates_award_cycles')->where('programme_id', $pid)->value('id') ?? 0);
        $cat = (int) ($cid > 0 ? (DB::table('gates_award_categories')->where('cycle_id', $cid)->value('id') ?? 0) : 0);

        $ids = [];
        if ($cat > 0) {
            foreach (DB::table('gates_nominees')->where('category_id', $cat)
                        ->orderByDesc('vote_count')->get(['id']) as $i => $n) {
                $ids[['leader', 'runner', 'pending'][$i] ?? ('n' . $i)] = (int) $n->id;
            }
        }

        return ['programme_id' => $pid, 'cycle_id' => $cid, 'category_id' => $cat,
                'links' => self::links($pid, $cid, $cat, $ids)];
    }

    /**
     * Every screen the sandbox makes reachable, in the order somebody would walk them.
     *
     * The links are the deliverable as much as the data is. A seeder that produces rows and
     * leaves the operator to find them has not saved anybody any time.
     *
     * @param array<string,int> $ids
     * @return list<array{label:string,href:string}>
     */
    private static function links(int $programmeId, int $cycleId, int $categoryId, array $ids): array
    {
        $subId = (int) (DB::table('gates_nominee_submissions')
            ->where('programme_id', $programmeId)->where('status', 'submitted')->value('id') ?? 0);

        $out = [
            ['label' => 'Programme and cycle',   'href' => '/admin/programmes/' . $programmeId],
            ['label' => 'Nominees',              'href' => '/admin/nominees'],
            ['label' => 'Moderation queue',      'href' => '/admin/moderation'],
            ['label' => 'Questionnaire queue',   'href' => '/admin/questionnaires'],
            ['label' => 'Questionnaire invitations', 'href' => '/admin/questionnaires/invitations?cycle=' . $cycleId],
            ['label' => 'Interviews',            'href' => '/admin/interviews'],
            ['label' => 'The judging panel',     'href' => '/admin/judges'],
            ['label' => 'Shortlist for this category', 'href' => '/admin/shortlists/category/' . $categoryId],
        ];

        if ($subId > 0) {
            $out[] = ['label' => 'The submitted questionnaire',
                      'href'  => '/admin/questionnaires/' . $subId];
        }

        return $out;
    }
}
