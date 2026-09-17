<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\DemoSeeder;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A judge can try the portal before the round they are being trusted with.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A person appointed to a panel had nowhere to rehearse. The sandbox existed, but only for
 * its own account — reachable by a superadmin pressing a button on an admin screen, which
 * is no use at all to the person who actually needs the practice. So a judge's first
 * encounter with the ballot was the live round, on a real nominee's dossier, with real
 * scores.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE TWO THINGS THAT MAKE IT SAFE RATHER THAN MERELY CONVENIENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · NOTHING IS WRITTEN TO THE APPOINTMENT RECORD. The practice programme is APPENDED at
 *     read time, never added to `programme_ids` — so who was appointed to what stays a
 *     clean record, and a practice run leaves no trace on it.
 *
 * 2 · IT CANNOT REACH A RESULT. The sandbox programme is `is_active = 0` and lives in its
 *     own category, and every tally, rank and cut on this platform is computed PER
 *     CATEGORY. A score written there is not filtered out of a real result; the query never
 *     reaches it.
 */
final class JudgePracticeRunTest extends TestCase
{
    private JudgeService $svc;
    private int $judge = 0;
    private int $realProg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new JudgeService();

        $this->realProg = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'real-awards', 'title' => 'Real Awards', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->judge = (int) DB::table('gates_judges')->insertGetId([
            'name' => 'Prof. Amina Yusuf', 'email' => 'amina@example.org', 'is_active' => 1,
            'programme_ids' => json_encode([$this->realProg]),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE PRACTICE RUN ITSELF
    // ════════════════════════════════════════════════════════════════════════

    public function test_a_real_judge_is_offered_the_practice_ballot(): void
    {
        $this->assertCount(1, $this->svc->programmes($this->judge),
            'before the sandbox exists there is nothing to practise on');

        DemoSeeder::seed(1);

        $progs = $this->svc->programmes($this->judge);
        $this->assertCount(2, $progs);
        $this->assertTrue((bool) ($progs[1]['is_practice'] ?? false));
        $this->assertSame(DemoSeeder::PROGRAMME_SLUG, (string) $progs[1]['slug']);
    }

    /**
     * And it OPENS. A locked practice ballot rehearses nothing.
     *
     * The sandbox's main cycle is deliberately in voting, where nobody can score, so the
     * practice run needed a second cycle in the judging phase — which is what a real
     * programme in its second year has anyway.
     */
    public function test_the_practice_ballot_is_open_and_has_somebody_to_score(): void
    {
        $seeded = DemoSeeder::seed(1);

        $b = $this->svc->ballot($this->judge, (int) $seeded['programme_id']);

        $this->assertTrue($b['judging_open'], 'the practice ballot is locked: ' . $b['lock_reason']);
        $this->assertSame(2, $b['progress']['total']);
        $this->assertSame(0, $b['progress']['scored'],
            'a practice ballot where the work is already done rehearses nothing');
    }

    /** A real judge can actually save a score on it — the whole point of a rehearsal. */
    public function test_a_practice_score_saves(): void
    {
        $seeded = DemoSeeder::seed(1);
        $b = $this->svc->ballot($this->judge, (int) $seeded['programme_id']);
        $nominee = (int) $b['categories'][0]['nominees'][0]['id'];

        $marks = [];
        foreach ($b['criteria'] as $c) $marks[(int) $c['id']] = 7;

        $r = $this->svc->saveScore($this->judge, $nominee, $marks);

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertGreaterThan(0, $r['saved']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND WHAT IT MUST NOT DO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS: practice never touches the record of who judges what.
     *
     * `programme_ids` is the appointment. Writing to it so that somebody could rehearse
     * would make a practice run indistinguishable, afterwards, from an appointment nobody
     * made.
     */
    public function test_practising_does_not_appoint_anybody_to_anything(): void
    {
        DemoSeeder::seed(1);
        $before = (string) DB::table('gates_judges')->where('id', $this->judge)->value('programme_ids');

        $this->svc->programmes($this->judge);
        $this->svc->dashboard($this->judge);

        $this->assertSame($before,
            (string) DB::table('gates_judges')->where('id', $this->judge)->value('programme_ids'));
    }

    /**
     * Practice is excluded from every number on the dashboard.
     *
     * "2 of 5 scored" has to be about the round a judge is accountable for. Folding a
     * rehearsal into it makes the one figure on the page wrong in both directions: it
     * inflates what is outstanding, and a judge who has finished their real work still
     * reads as unfinished.
     */
    public function test_the_practice_ballot_is_kept_out_of_the_real_counts(): void
    {
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->realProg, 'year' => (int) date('Y'), 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'rc', 'title' => 'Real Category', 'sort_order' => 1,
        ]);
        $nom = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'A Real Nominee', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        $this->publishShortlist($cycle, $cat, [$nom]);

        DemoSeeder::seed(1);

        $o = $this->svc->dashboard($this->judge)['overview'];

        $this->assertSame(1, $o['programmes'], 'the practice card was counted as a panel');
        $this->assertSame(1, $o['total'], 'practice nominees were added to the real workload');
    }

    /** An inactive judge is not offered one — they should not be in the portal at all. */
    public function test_a_deactivated_judge_gets_no_practice_ballot(): void
    {
        DemoSeeder::seed(1);
        DB::table('gates_judges')->where('id', $this->judge)->update(['is_active' => 0]);

        $this->assertCount(1, $this->svc->programmes($this->judge));
    }

    /**
     * And the practice programme still never reaches the public panel page.
     *
     * It is offered to every active judge now, which is a new way for it to leak: a judge's
     * public profile lists the programmes they judge.
     */
    public function test_the_practice_programme_is_not_listed_on_a_public_profile(): void
    {
        DemoSeeder::seed(1);

        $profile = $this->svc->publicJudge($this->judge);

        $this->assertNotNull($profile);
        $this->assertNotContains(DemoSeeder::PROGRAMME_SLUG,
            array_column($profile['programmes'], 'slug'),
            'the sandbox appeared on a judge\'s public profile');
    }
}
