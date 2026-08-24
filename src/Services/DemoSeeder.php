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
            self::seedLiveInterview($ids['runner'], $categoryId, $cycleId, $adminId, $now);
            $judgeId = self::seedJudge($programmeId);
            self::seedScores($judgeId, $categoryId, $programmeId, $ids, $now);
            self::seedShortlistRule($cycleId, $categoryId, $ids);

            $practice = self::seedPracticeCycle($programmeId, $now);
            // ── WHY THE DEMO JUDGE IS GIVEN WORK ON THE PRACTICE CYCLE TOO ───
            //
            // Scores are per JUDGE, which is what makes one set of nominees serve both
            // audiences. The sandbox's own rehearsal account opens a half-finished panel —
            // one done, one to do, which is the state the judging screens exist to show. A
            // real judge opening the same practice ballot has scored nothing, so they get a
            // clean run. Neither sees the other's marks.
            if ($practice['nominees'] !== []) {
                self::seedScores($judgeId, $practice['category'], $programmeId,
                                 ['leader' => $practice['nominees'][0]], $now);
            }

            return [
                'ok' => true, 'programme_id' => $programmeId, 'cycle_id' => $cycleId,
                'category_id' => $categoryId, 'nominees' => $ids,
                'practice_cycle_id' => $practice['cycle'],
                'practice_nominees' => $practice['nominees'],
                'links' => self::links($programmeId, $cycleId, $categoryId, $ids),
                'message' => 'Sandbox built: 3 nominees, a questionnaire in each state, evidence, '
                           . 'a filed interview transcript AND a sitting three days out, a '
                           . 'published shortlist of two with one scored and one still to do, '
                           . 'votes, and a practice ballot every judge can try. Nothing is public '
                           . 'and nothing counts toward a real award.',
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
     * A sitting that has not happened yet — somebody to actually interview.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS A SECOND, DIFFERENT INTERVIEW
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see seedInterview()} writes `gates_nominee_interviews`, which is a TRANSCRIPT — a
     * record of an interview that already happened, filed as evidence and read by the
     * judging screens. It is not an appointment, and nothing about it appears on
     * `/admin/interviews`.
     *
     * `gates_interviews` is the live sitting: the appointment, the meeting link, the
     * nominee's own consent page, the question pack, the bot. None of it was seeded, so
     * `/admin/interviews` was EMPTY in the sandbox — the one screen an operator most needs
     * to rehearse before putting a real nominee in front of a panel had nothing on it, and
     * the sandbox looked complete because a differently-named table did have a row.
     *
     * Given to the RUNNER rather than the leader, so the sandbox shows both states at once:
     * one nominee interviewed and on file, one with an appointment coming up.
     *
     * `confirmed` and three days out: far enough that the reminder ladder (24h and 2h)
     * still has both rungs ahead of it, and confirmed rather than invited because the
     * nominee-facing consent page — which is the part worth walking — is what comes next.
     */
    private static function seedLiveInterview(int $nomineeId, int $categoryId, int $cycleId,
                                              int $adminId, Carbon $now): void
    {
        try {
            DB::table('gates_interviews')->insert([
                'nominee_id'    => $nomineeId,
                'category_id'   => $categoryId,
                'cycle_id'      => $cycleId,
                'status'        => 'confirmed',
                'scheduled_at'  => $now->copy()->addDays(3)->setTime(14, 0)->toDateTimeString(),
                'duration_mins' => 30,
                'timezone'      => 'Africa/Lagos',
                // No meet_url on purpose. Creating one needs the Apps Script bridge, and a
                // sandbox that silently books a room in somebody's real calendar is not a
                // sandbox. The console's "create a meeting" button is itself a thing to
                // rehearse, and it needs somewhere to be pressed.
                'invite_token'  => bin2hex(random_bytes(16)),
                'invited_at'    => $now->copy()->subDays(2)->toDateTimeString(),
                'confirmed_at'  => $now->copy()->subDay()->toDateTimeString(),
                'language'      => 'en',
                'created_by'    => $adminId ?: null,
                'created_at'    => $now->copy()->subDays(2)->toDateTimeString(),
                'updated_at'    => $now->copy()->subDay()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // The interview tables arrive in a migration. A deployment mid-upgrade should
            // still get a usable sandbox rather than a failed build — same reasoning as
            // seedShortlistRule().
            error_log('[demo] live interview skipped: ' . $e->getMessage());
        }
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
        // ── THE SAME FOUR SLUGS AS THE PUBLISHED RUBRIC ──────────────────────
        //
        // These used to be impact / originality / EVIDENCE / DURABILITY, chosen before the
        // platform shipped a rubric of its own. Once it did, the sandbox stopped rehearsing
        // it: the scorer resolves global + programme BY SLUG, so two of these overrode the
        // real ones and the other two — reach and integrity — arrived from the global rubric
        // alongside them. The rehearsal panel was asked SIX criteria that no real panel is
        // asked, and the demo's own scores covered only four of them, so its nominee could
        // never read as complete.
        //
        // Same four slugs, different weights on purpose: an override that only changes the
        // weighting is exactly what a programme-specific rubric is for, and rehearsing it is
        // the point.
        foreach ([
            ['impact',      'Impact',      40, 'What actually changed, and for how many.'],
            ['originality', 'Originality', 25, 'Whether this needed doing this way.'],
            ['reach',       'Reach',       20, 'How far it travelled, and who it reached.'],
            ['integrity',   'Integrity',   15, 'Whether the character holds when it costs something.'],
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

        $marks = ['impact' => 9, 'originality' => 7, 'reach' => 8, 'integrity' => 6];

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

    /**
     * A second cycle, in the JUDGING phase, so a judge has somewhere to practise.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A SECOND CYCLE AND NOT A FLAG ON THE FIRST
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The sandbox's main cycle is deliberately in VOTING — its windows are placed so an
     * operator can rehearse the voting screens, which is what most of the console is about.
     * A judge cannot score in a voting cycle, and no amount of flagging changes that: a
     * cycle is in one phase at a time, and "voting" and "judging" are the two the sandbox
     * needs at once.
     *
     * So the sandbox gets what a real programme in its second year has — two cycles. This
     * one's windows are all in the past, which puts {@see CyclePolicy::phaseFor()} in
     * Judging, and {@see \AfricaGates\Judge\Services\JudgeService::ballot()} now picks the
     * cycle being judged rather than the newest one.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND WHY NOTHING IS SCORED ON IT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This is the one a real judge opens to try the portal before a live round. Every
     * nominee is unscored on purpose: a practice ballot where the work is already done
     * rehearses nothing, and the whole point is to move the sliders, save, and see what
     * happens.
     *
     * @return array{cycle:int, category:int, nominees:list<int>} zeros when the tables
     *         are not there yet
     */
    private static function seedPracticeCycle(int $programmeId, Carbon $now): array
    {
        try {
            // A YEAR EARLIER, and every window behind us. Judging is what phaseFor()
            // returns once voting_close has passed and results_date has not — so the
            // results date is the one date left in the future.
            $cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
                'programme_id'      => $programmeId,
                'year'              => (int) $now->format('Y') - 1,
                'edition_label'     => 'Practice',
                'status'            => 'judging',
                'nominations_open'  => $now->copy()->subDays(120)->toDateTimeString(),
                'nominations_close' => $now->copy()->subDays(90)->toDateTimeString(),
                'voting_open'       => $now->copy()->subDays(80)->toDateTimeString(),
                'voting_close'      => $now->copy()->subDays(30)->toDateTimeString(),
                'results_date'      => $now->copy()->addDays(60)->toDateTimeString(),
            ]);

            $categoryId = (int) DB::table('gates_award_categories')->insertGetId([
                'cycle_id'    => $cycleId,
                'slug'        => 'demo-practice',
                'title'       => self::PREFIX . 'Practice Category',
                'description' => 'A practice ballot. Nothing scored here counts toward anything.',
                'sort_order'  => 1,
            ]);

            $ids = [];
            foreach ([
                ['Thandiwe Moyo',  'Rebuilt a district library from a burnt-out shell.'],
                ['Yusuf Abubakar', 'Trained 60 midwives across four states in two years.'],
            ] as $i => [$name, $tagline]) {
                $ids[] = (int) DB::table('gates_nominees')->insertGetId([
                    'category_id'        => $categoryId,
                    'name'               => self::PREFIX . $name,
                    'tagline'            => $tagline,
                    'story'              => 'A practice entry. It exists so a judge can open a real '
                                          . 'ballot, read a real dossier and move real sliders before '
                                          . 'a live round, without any of it counting.',
                    'country_code'       => 'NG',
                    'organisation'       => 'Demo Collective',
                    // No votes at all. The judging screen strips the tally anyway, and a
                    // practice entry carrying one invites somebody to check whether it does.
                    'vote_count'         => 0,
                    'organic_vote_count' => 0,
                    'status'             => 'approved',
                    'nominated_at'       => $now->copy()->subDays(85)->toDateTimeString(),
                ]);
            }

            // The panel judges the shortlist, so a practice ballot needs a published one or
            // it locks with a message about asking the organisers.
            $shortlistId = (int) DB::table('gates_shortlists')->insertGetId([
                'cycle_id'     => $cycleId,
                'category_id'  => $categoryId,
                'rule_text'    => 'Practice shortlist — both entries, nothing scored.',
                'entry_count'  => count($ids),
                'considered'   => count($ids),
                'status'       => 'published',
                'published_at' => $now->copy()->subDays(25)->toDateTimeString(),
            ]);
            foreach ($ids as $i => $nid) {
                DB::table('gates_shortlist_entries')->insert([
                    'shortlist_id' => $shortlistId,
                    'nominee_id'   => $nid,
                    'rank_no'      => $i + 1,
                    'nominee_name' => (string) DB::table('gates_nominees')->where('id', $nid)->value('name'),
                    'country_code' => 'NG',
                ]);
            }

            return ['cycle' => $cycleId, 'category' => $categoryId, 'nominees' => $ids];
        } catch (\Throwable $e) {
            error_log('[demo] practice cycle skipped: ' . $e->getMessage());
            return ['cycle' => 0, 'category' => 0, 'nominees' => []];
        }
    }

    /**
     * A rule that puts one nominee above the line and one below, and a PUBLISHED shortlist.
     *
     * ── WHY THE PUBLISH MATTERS AS MUCH AS THE RULE ─────────────────────────
     *
     * The judging panel scores the shortlist rather than the whole field, and a programme
     * whose shortlist is unpublished gives every judge a LOCKED ballot. So a sandbox that
     * saved a rule and stopped would hand its rehearsal judge a portal they cannot use —
     * with an accurate lock reason telling them to go and ask the organisers, who in this
     * case are the person who just pressed "build the sandbox".
     *
     * Published with BOTH nominees, not the one the top_n rule would cut to: the sandbox
     * exists to show a half-finished panel ({@see seedScores()} deliberately scores only
     * one), and a shortlist of one has nothing left to do on it. The rule is still saved so
     * the shortlist screens have something to preview and re-cut.
     */
    private static function seedShortlistRule(int $cycleId, int $categoryId, array $ids): void
    {
        try {
            ShortlistService::saveRule($cycleId, null, new ShortlistRule('top_n', 1, 1), 0);
        } catch (\Throwable) {
            // The shortlist tables arrive in a migration. A deployment mid-upgrade should
            // still get a usable sandbox rather than a failed build.
        }

        try {
            $shortlistId = (int) DB::table('gates_shortlists')->insertGetId([
                'cycle_id'     => $cycleId,
                'category_id'  => $categoryId,
                'rule_text'    => 'Rehearsal shortlist — everybody approved, so the panel has work to do.',
                'entry_count'  => 2,
                'considered'   => 3,
                'status'       => 'published',
                'published_at' => Carbon::now()->subDays(4)->toDateTimeString(),
            ]);

            $rank = 0;
            foreach (['leader', 'runner'] as $key) {
                if (empty($ids[$key])) continue;
                $rank++;
                $n = DB::table('gates_nominees')->where('id', $ids[$key])->first();
                DB::table('gates_shortlist_entries')->insert([
                    'shortlist_id'       => $shortlistId,
                    'nominee_id'         => (int) $ids[$key],
                    'rank_no'            => $rank,
                    // Frozen at publication, as the real publisher does — a nominee renamed
                    // afterwards must not rewrite a document somebody has been sent.
                    'nominee_name'       => (string) ($n->name ?? ''),
                    'country_code'       => (string) ($n->country_code ?? ''),
                    'vote_count'         => (int) ($n->vote_count ?? 0),
                    'organic_vote_count' => (int) ($n->organic_vote_count ?? 0),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[demo] shortlist publish skipped: ' . $e->getMessage());
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
            // The live sitting, separately from the transcript above — two different tables
            // with confusingly similar names. Guarded because the interview tables arrive in
            // a migration, and a deployment mid-upgrade must still be able to tear down.
            try {
                DB::table('gates_interviews')->whereIn('nominee_id', $nomIds)->delete();
            } catch (\Throwable) {
            }
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

        // Any sign-in code minted for the sandbox judge. It would expire on its own in
        // fifteen minutes, but leaving a live credential behind for an account that no
        // longer exists is not a state to walk away from — and a rebuild is exactly when
        // somebody has one open in another tab.
        try {
            DB::table('gates_otp_tokens')
                ->where('email_hash', hash('sha256', 'judge@' . self::MAIL_DOMAIN))
                ->delete();
        } catch (\Throwable) {
        }

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
    /**
     * The sandbox programme's id, or 0 when it has never been built.
     *
     * ── WHY ANYTHING NEEDS THIS ──────────────────────────────────────────────
     *
     * Containment holds for everything computed PER CATEGORY, which is nearly everything.
     * It does not hold for a query that starts at `gates_award_cycles` and walks down —
     * and the integrity scans do exactly that, because "which panels are being judged
     * right now" is a question about cycles, not about one category.
     *
     * The practice cycle {@see seedPracticeCycle()} makes that concrete: it carries
     * `status = 'judging'` deliberately, so a real judge can rehearse, and it is the
     * NEWEST such cycle on a fresh install. A scan that enumerates judging cycles finds
     * the rehearsal first.
     *
     * @see notSandbox() for the filter itself.
     */
    public static function programmeId(): int
    {
        try {
            return (int) (DB::table('gates_award_programmes')
                ->where('slug', self::PROGRAMME_SLUG)->value('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Exclude the sandbox from a query that reaches cycles or programmes directly.
     *
     * Shaped like {@see MergeService::notMerged()} so it reads the same at the call site:
     * one line, next to the other things a query has to be honest about.
     *
     * @param \Illuminate\Database\Query\Builder $q
     * @param string $programmeColumn the qualified column holding a programme id
     */
    public static function notSandbox(object $q, string $programmeColumn): object
    {
        $pid = self::programmeId();
        if ($pid > 0) $q->where($programmeColumn, '!=', $pid);
        return $q;
    }

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
        // The MAIN rehearsal cycle, named explicitly. The sandbox now has two — the
        // current-year voting one and a prior-year practice one — and `->value('id')` would
        // return whichever the database felt like, which is how the doors below started
        // looking for an interview in the wrong cycle.
        $cid = (int) (DB::table('gates_award_cycles')->where('programme_id', $pid)
            ->orderByDesc('year')->value('id') ?? 0);
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

    // ═══════════════════════════════════════════════════════════════════════
    // THE DOORS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The three PORTALS the sandbox exists to let somebody walk, with the keys to open them.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A SANDBOX WITHOUT THESE IS ONLY HALF ONE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Everything {@see links()} returns is an ADMIN screen. That is the half an operator can
     * already reach, and it is not the half they are nervous about: what a nominee sees when
     * they open the questionnaire, what the consent page asks before an interview, whether
     * the judge's ballot makes sense — none of those are visible from the console, and all
     * three are behind a token or a login the sandbox never handed anybody.
     *
     * The judge portal was the worst of it and worth stating plainly, because it reads as a
     * detail and is not: judges sign in with a six-digit code EMAILED to them, and the
     * sandbox's judge is at `demo.invalid`, which RFC 2606 reserves precisely so no mail
     * server will ever accept it. So the demo judge could not sign in AT ALL. Every screen
     * in the judge portal — the ballot, the dossier map, the recusal flow, the AI assist —
     * was unreachable in the one environment built for trying them, and the documented
     * workaround was to go and read a log file on a host with no shell.
     *
     * @return array{judge_email:string, judge_login:string,
     *                interview:?array{label:string,href:string,when:?string},
     *                questionnaires:list<array{label:string,href:string,state:string}>}
     */
    public static function doors(): array
    {
        $out = [
            'judge_email'    => 'judge@' . self::MAIL_DOMAIN,
            'judge_login'    => '/judge/login',
            'interview'      => null,
            'questionnaires' => [],
        ];

        $cur = self::current();
        if ($cur === null) return $out;

        try {
            $iv = DB::table('gates_interviews as i')
                ->join('gates_nominees as n', 'n.id', '=', 'i.nominee_id')
                ->where('i.cycle_id', $cur['cycle_id'])
                ->whereIn('i.status', ['draft', 'invited', 'confirmed', 'live'])
                ->orderBy('i.id')
                ->first(['i.invite_token', 'i.scheduled_at', 'n.name']);

            if ($iv && trim((string) $iv->invite_token) !== '') {
                $out['interview'] = [
                    'label' => (string) $iv->name,
                    'href'  => '/interview/' . (string) $iv->invite_token,
                    'when'  => $iv->scheduled_at ? (string) $iv->scheduled_at : null,
                ];
            }
        } catch (\Throwable) {
            // Interview tables may predate this deployment's migration state.
        }

        try {
            $subs = DB::table('gates_nominee_submissions as s')
                ->join('gates_nominees as n', 'n.id', '=', 's.nominee_id')
                ->where('s.programme_id', $cur['programme_id'])
                ->orderBy('s.id')
                ->get(['s.invite_token', 's.status', 'n.name']);

            foreach ($subs as $s) {
                $tok = trim((string) $s->invite_token);
                if ($tok === '') continue;
                $out['questionnaires'][] = [
                    'label' => (string) $s->name,
                    'href'  => '/my-work/' . $tok,
                    // The state is the point: a submitted one is read-only, a draft is the
                    // form mid-flight, and a never-started one is the first-open experience.
                    // Walking one of the three tells you nothing about the other two.
                    'state' => (string) $s->status,
                ];
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * Mint a real sign-in code for the sandbox judge, and hand it back rather than email it.
     *
     * ── WHY THIS IS SAFE, AND WHY THE GUARD IS WHERE IT IS ──────────────────
     *
     * This is a function that creates a valid login credential and shows it to the caller,
     * which is exactly the shape of a privilege-escalation bug — so it is bounded by the one
     * fact that cannot be argued with: it resolves the judge BY the sandbox address, at a
     * domain RFC 2606 reserves so that no real person can ever hold it. There is no
     * parameter to point it at somebody else, because a parameter is the thing that would
     * eventually be pointed at a real judge.
     *
     * The rest of the login is untouched: the same `gates_otp_tokens` row the real flow
     * writes, the same fifteen-minute expiry, the same form. The operator types the code
     * into the real screen. Nothing is bypassed — the code simply does not go to a mailbox
     * that cannot exist.
     *
     * @return array{ok:bool, code:?string, email:string, expires_in:int, message:string}
     */
    public static function judgeSignInCode(): array
    {
        $email = 'judge@' . self::MAIL_DOMAIN;

        try {
            $judge = DB::table('gates_judges')->where('email', $email)->first(['id', 'is_active']);
        } catch (\Throwable) {
            $judge = null;
        }

        if (!$judge) {
            return ['ok' => false, 'code' => null, 'email' => $email, 'expires_in' => 0,
                    'message' => 'There is no sandbox judge — build the sandbox first.'];
        }
        if ((int) ($judge->is_active ?? 0) !== 1) {
            return ['ok' => false, 'code' => null, 'email' => $email, 'expires_in' => 0,
                    'message' => 'The sandbox judge is not active, so the portal would refuse '
                               . 'the code. Rebuild the sandbox.'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $email);

        try {
            // Retire any outstanding code first, exactly as the real request does — two live
            // codes for one address is how somebody types the older one and is told it is
            // wrong.
            DB::table('gates_otp_tokens')->where('email_hash', $hash)
                ->where('purpose', 'judge_login')->where('is_used', 0)
                ->update(['is_used' => 1]);

            DB::table('gates_otp_tokens')->insert([
                'email_hash' => $hash,
                'token_hash' => hash('sha256', $code),
                'purpose'    => 'judge_login',
                'nominee_id' => (int) $judge->id,
                'award_id'   => 0,
                'attempts'   => 0,
                'is_used'    => 0,
                'expires_at' => Carbon::now()->addMinutes(15)->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[demo] judge sign-in code: ' . $e->getMessage());
            return ['ok' => false, 'code' => null, 'email' => $email, 'expires_in' => 0,
                    'message' => 'That code could not be created just now.'];
        }

        return ['ok' => true, 'code' => $code, 'email' => $email, 'expires_in' => 15,
                'message' => 'Sign in at /judge/login with ' . $email . ' and this code.'];
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
            ['label' => 'The judging rubric',    'href' => '/admin/rubric?programme=' . $programmeId],
            ['label' => 'Shortlist for this category', 'href' => '/admin/shortlists/category/' . $categoryId],
        ];

        if ($subId > 0) {
            $out[] = ['label' => 'The submitted questionnaire',
                      'href'  => '/admin/questionnaires/' . $subId];
        }

        return $out;
    }
}
