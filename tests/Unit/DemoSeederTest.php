<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AwardService;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\ShortlistService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The rehearsal sandbox.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS ACTUALLY GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * One question: can test data reach a real award? Everything else here is convenience.
 *
 * The alternative design was `gates_nominees.is_demo` plus a filter wherever it matters —
 * and "wherever it matters" is a dozen queries across vote tallies, category ranks, the
 * CPI, headline gaps, shortlist cuts, leaderboards and the standings chain. The one that
 * gets missed is the one that counts a test vote toward a real result, and nobody finds it
 * by looking.
 *
 * So the sandbox is CONTAINED in its own programme, cycle and category, and the tests below
 * are the containment proof rather than a feature tour.
 */
final class DemoSeederTest extends TestCase
{
    /** A real programme, cycle, category and nominee for the sandbox to fail to touch. */
    private function realAward(): array
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'real-awards', 'title' => 'Real Awards', 'is_active' => 1, 'sort_order' => 1,
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'voting',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cid, 'slug' => 'real-cat', 'title' => 'Real Category', 'sort_order' => 1,
        ]);
        $nom = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'A Real Nominee', 'status' => 'approved',
            'vote_count' => 50, 'organic_vote_count' => 50, 'country_code' => 'NG',
        ]);

        return ['programme' => $pid, 'cycle' => $cid, 'category' => $cat, 'nominee' => $nom];
    }

    // ══ containment ══════════════════════════════════════════════════════════

    /** THE ONE THAT MATTERS. Not one row of the sandbox lands in a real category. */
    public function test_the_sandbox_shares_no_category_with_a_real_award(): void
    {
        $real = $this->realAward();
        $r    = DemoSeeder::seed(1);

        $this->assertTrue($r['ok']);
        $this->assertNotSame($real['programme'], $r['programme_id']);
        $this->assertNotSame($real['category'], $r['category_id']);

        // Every demo nominee, vote and score is inside the demo category.
        $demoNominees = DB::table('gates_nominees')->where('category_id', $r['category_id'])->pluck('id')->all();
        $this->assertCount(3, $demoNominees);

        $this->assertSame(0, DB::table('gates_votes')
            ->where('category_id', $real['category'])->count(),
            'a demo vote reached the real category');
        $this->assertSame(0, DB::table('gates_nominees')
            ->where('category_id', $real['category'])->where('name', 'like', DemoSeeder::PREFIX . '%')->count());
    }

    /** The real nominee's tally is byte-for-byte what it was. */
    public function test_a_real_nominees_numbers_are_untouched(): void
    {
        $real   = $this->realAward();
        $before = (array) DB::table('gates_nominees')->where('id', $real['nominee'])->first();

        DemoSeeder::seed(1);

        $this->assertSame($before, (array) DB::table('gates_nominees')->where('id', $real['nominee'])->first());
    }

    /**
     * The public-invisibility mechanism, checked through the readers that actually serve
     * the site rather than by asserting a column.
     */
    public function test_the_sandbox_is_invisible_to_every_public_reader(): void
    {
        $this->realAward();
        DemoSeeder::seed(1);

        $svc   = new AwardService();
        $slugs = array_column($svc->getActiveProgrammesWithStatus(), 'slug');

        $this->assertContains('real-awards', $slugs);
        $this->assertNotContains(DemoSeeder::PROGRAMME_SLUG, $slugs,
            'the sandbox appeared in the public programme list');
        $this->assertNull($svc->getProgrammeBySlug(DemoSeeder::PROGRAMME_SLUG),
            '/vote/demo-sandbox must not resolve');
    }

    /** A shortlist rule on the sandbox cycle must not appear on a real one. */
    public function test_the_sandbox_shortlist_rule_is_scoped_to_its_own_cycle(): void
    {
        $real = $this->realAward();
        $r    = DemoSeeder::seed(1);

        $this->assertSame('cycle', ShortlistService::ruleFor($r['cycle_id'], null)['scope']);
        $this->assertSame('default', ShortlistService::ruleFor($real['cycle'], null)['scope'],
            'the real cycle picked up the sandbox rule');
    }

    // ══ obviously fake ═══════════════════════════════════════════════════════

    /** An operator one tab from the live console must not be able to mistake this. */
    public function test_every_created_name_is_prefixed(): void
    {
        $r = DemoSeeder::seed(1);

        foreach (DB::table('gates_nominees')->where('category_id', $r['category_id'])->pluck('name') as $n) {
            $this->assertStringStartsWith(DemoSeeder::PREFIX, (string) $n);
        }
        $this->assertStringStartsWith(DemoSeeder::PREFIX, (string) DB::table('gates_award_programmes')
            ->where('id', $r['programme_id'])->value('title'));
        $this->assertStringStartsWith(DemoSeeder::PREFIX, (string) DB::table('gates_award_categories')
            ->where('id', $r['category_id'])->value('title'));
    }

    /** RFC 6761 reserves .invalid, so a stray send cannot reach a person. */
    public function test_every_address_is_unroutable(): void
    {
        $r = DemoSeeder::seed(1);
        $cat = (int) $r['category_id'];

        $mails = array_merge(
            DB::table('gates_nominations')->where('category_id', $cat)->pluck('nominee_email')->all(),
            DB::table('gates_nominations')->where('category_id', $cat)->pluck('nominator_email')->all(),
            [DB::table('gates_judges')->where('name', 'like', DemoSeeder::PREFIX . '%')->value('email')]
        );

        $this->assertNotSame([], array_filter($mails));
        foreach (array_filter($mails) as $m) {
            $this->assertStringEndsWith('.invalid', (string) $m, "{$m} is deliverable");
        }
    }

    // ══ it actually exercises the screens ════════════════════════════════════

    /**
     * A questionnaire in each state, because the screens differ by state — and an operator
     * rehearsing needs the chase-up case and the never-invited case, not only a finished one.
     */
    public function test_there_is_a_questionnaire_in_every_state_the_screens_branch_on(): void
    {
        $r = DemoSeeder::seed(1);

        $states = DB::table('gates_nominee_submissions')
            ->where('cycle_id', $r['cycle_id'])->pluck('status')->all();

        $this->assertContains('submitted', $states, 'a judge needs something to read');
        $this->assertSame(2, count(array_filter($states, fn ($s) => $s === 'draft')),
            'one invited-but-unfinished and one never invited');

        $never = DB::table('gates_nominee_submissions')
            ->where('cycle_id', $r['cycle_id'])->whereNull('invited_at')->count();
        $this->assertSame(1, $never, 'the "never invited" audience filter needs a member');
    }

    /**
     * The submitted answers have to SURVIVE, which means the programme's questions must
     * exist — `saveDraft` filters answers against them, so a seeded submission with no
     * questions defined reads as empty on the judge's screen.
     */
    public function test_the_submitted_questionnaire_has_readable_answers_and_defined_questions(): void
    {
        $r = DemoSeeder::seed(1);

        $this->assertSame(3, DB::table('gates_programme_questions')
            ->where('programme_id', $r['programme_id'])->count());

        $json = (string) DB::table('gates_nominee_submissions')
            ->where('cycle_id', $r['cycle_id'])->where('status', 'submitted')->value('answers_json');
        $answers = json_decode($json, true);

        $this->assertIsArray($answers);
        foreach (['impact', 'evidence', 'obstacles'] as $slug) {
            $this->assertArrayHasKey($slug, $answers);
            $this->assertNotSame('', trim((string) $answers[$slug]));
        }
    }

    /**
     * Scoring is LOCKED when a programme has no criteria, so a sandbox without them cannot
     * rehearse the one screen an operator most wants to try.
     */
    public function test_the_judge_can_actually_score_because_criteria_exist(): void
    {
        $r = DemoSeeder::seed(1);

        $this->assertSame(4, DB::table('gates_judge_criteria')
            ->where('programme_id', $r['programme_id'])->where('is_active', 1)->count());

        $judge = DB::table('gates_judges')->where('email', 'judge@demo.invalid')->first();
        $this->assertNotNull($judge);
        $this->assertContains($r['programme_id'],
            (array) json_decode((string) $judge->programme_ids, true),
            'a judge assigned to no programme sees an empty portal');
    }

    /** Deliberately partial: "what does a half-finished panel look like" is a real screen. */
    public function test_one_nominee_is_scored_and_the_others_are_not(): void
    {
        $r = DemoSeeder::seed(1);

        $scored = DB::table('gates_judge_criteria_scores')
            ->where('category_id', $r['category_id'])->distinct()->pluck('nominee_id')->all();

        $this->assertCount(1, $scored);
        $this->assertSame($r['nominees']['leader'], (int) $scored[0]);
    }

    public function test_evidence_and_an_interview_exist_for_the_judged_nominee(): void
    {
        $r = DemoSeeder::seed(1);
        $leader = $r['nominees']['leader'];

        $this->assertSame(3, DB::table('gates_nominee_evidence')->where('nominee_id', $leader)->count());
        $this->assertSame(3, DB::table('gates_nominee_evidence')
            ->where('nominee_id', $leader)->where('visible_to_judges', 1)->count(),
            'evidence a judge cannot see does not rehearse the dossier');
        $this->assertSame(1, DB::table('gates_nominee_interviews')->where('nominee_id', $leader)->count());
    }

    /**
     * The stored tally and the individual ballots must agree. Several screens read one and
     * several read the other, and a sandbox where they disagree teaches an operator to
     * distrust whichever one they happened to look at.
     */
    public function test_the_ballots_and_the_tallies_point_the_same_way(): void
    {
        $r = DemoSeeder::seed(1);

        $rows = DB::table('gates_nominees')->where('category_id', $r['category_id'])
            ->orderByDesc('vote_count')->get(['id', 'vote_count']);

        $this->assertGreaterThan((int) $rows[1]->vote_count, (int) $rows[0]->vote_count);

        $ballots = DB::table('gates_votes')->where('category_id', $r['category_id'])
            ->selectRaw('nominee_id, COUNT(*) as n')->groupBy('nominee_id')
            ->pluck('n', 'nominee_id')->all();

        $this->assertGreaterThan((int) ($ballots[$r['nominees']['runner']] ?? 0),
            (int) ($ballots[$r['nominees']['leader']] ?? 0),
            'the ballots must rank the same way the tallies do');
    }

    public function test_the_cycle_is_in_a_phase_worth_rehearsing(): void
    {
        $r = DemoSeeder::seed(1);
        $c = DB::table('gates_award_cycles')->where('id', $r['cycle_id'])->first();

        $this->assertTrue(strtotime((string) $c->voting_open) < time(), 'voting must already be open');
        $this->assertTrue(strtotime((string) $c->voting_close) > time(), 'and not yet closed');
        $this->assertTrue(strtotime((string) $c->nominations_close) < time(),
            'a sandbox stuck in nominations shows a ballot nobody can use');
    }

    /** The links are the deliverable as much as the rows are. */
    public function test_the_build_returns_links_to_the_screens_it_populated(): void
    {
        $r = DemoSeeder::seed(1);

        $hrefs = array_column($r['links'], 'href');
        $this->assertContains('/admin/shortlists/category/' . $r['category_id'], $hrefs);
        $this->assertContains('/admin/questionnaires/invitations?cycle=' . $r['cycle_id'], $hrefs);

        foreach ($hrefs as $h) {
            $this->assertStringStartsWith('/admin/', $h, "{$h} points outside the admin");
        }
        $this->assertNotSame([], array_filter($hrefs, fn ($h) => str_contains($h, '/questionnaires/')),
            'the submitted questionnaire link is the one an operator most wants');
    }

    // ══ rebuild and teardown ═════════════════════════════════════════════════

    /** Pressing build twice is how you get a CLEAN rehearsal, not a doubled one. */
    public function test_rebuilding_replaces_rather_than_duplicates(): void
    {
        $first  = DemoSeeder::seed(1);
        $second = DemoSeeder::seed(1);

        $this->assertNotSame($first['programme_id'], $second['programme_id']);
        $this->assertSame(1, DB::table('gates_award_programmes')
            ->where('slug', DemoSeeder::PROGRAMME_SLUG)->count());
        $this->assertSame(3, DB::table('gates_nominees')
            ->where('name', 'like', DemoSeeder::PREFIX . '%')->count(),
            'a second build left the first build\'s nominees behind');
        $this->assertSame(1, DB::table('gates_judges')->where('email', 'judge@demo.invalid')->count());
    }

    /** Everything hangs off the programme, so removal is a delete and not a hunt. */
    public function test_removal_takes_everything_and_leaves_real_data_alone(): void
    {
        $real = $this->realAward();
        DemoSeeder::seed(1);

        $this->assertTrue(DemoSeeder::exists());
        $this->assertTrue(DemoSeeder::purge()['removed']);
        $this->assertFalse(DemoSeeder::exists());

        foreach ([['gates_nominees', 'name'], ['gates_award_programmes', 'title'],
                  ['gates_award_categories', 'title']] as [$table, $col]) {
            $this->assertSame(0, DB::table($table)->where($col, 'like', DemoSeeder::PREFIX . '%')->count(),
                "{$table} still holds sandbox rows");
        }
        foreach (['gates_votes', 'gates_nominee_submissions', 'gates_nominee_evidence',
                  'gates_nominee_interviews', 'gates_judge_criteria_scores'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), "{$t} was not cleared");
        }
        $this->assertSame(0, DB::table('gates_judges')->count());

        // And the real award is exactly as it was.
        $this->assertSame(1, DB::table('gates_nominees')->where('id', $real['nominee'])->count());
        $this->assertSame(1, DB::table('gates_award_programmes')->where('id', $real['programme'])->count());
    }

    public function test_removing_nothing_is_a_success_not_an_error(): void
    {
        $r = DemoSeeder::purge();

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['removed']);
    }

    public function test_current_reports_null_before_a_build_and_the_ids_after(): void
    {
        $this->assertNull(DemoSeeder::current());

        $seeded = DemoSeeder::seed(1);
        $now    = DemoSeeder::current();

        $this->assertNotNull($now);
        $this->assertSame($seeded['programme_id'], $now['programme_id']);
        $this->assertSame($seeded['category_id'], $now['category_id']);
        $this->assertNotSame([], $now['links']);
    }
    // ══ and the half of the sandbox nobody could reach ═══════════════════════

    /**
     * A sitting somebody has yet to attend, not only a transcript of one that happened.
     *
     * Two tables with confusingly similar names. `gates_nominee_interviews` is a TRANSCRIPT
     * filed as evidence; `gates_interviews` is the live appointment — the consent page, the
     * meeting, the question pack, the bot, and everything on `/admin/interviews`.
     *
     * Only the first was seeded, so `/admin/interviews` was EMPTY in the sandbox: the screen
     * an operator most wants to rehearse before putting a real nominee in front of a panel
     * had nothing on it, and the sandbox looked complete because the other table did have a
     * row in it.
     */
    public function test_there_is_a_sitting_still_to_be_held_not_only_a_filed_transcript(): void
    {
        $seeded = DemoSeeder::seed(1);
        $ids    = array_values($seeded['nominees']);

        $filed = DB::table('gates_nominee_interviews')->whereIn('nominee_id', $ids)->count();
        $this->assertSame(1, $filed, 'the transcript on file');

        $sitting = DB::table('gates_interviews')->whereIn('nominee_id', $ids)->first();
        $this->assertNotNull($sitting, '/admin/interviews is empty in the sandbox');

        $this->assertContains((string) $sitting->status, ['draft', 'invited', 'confirmed', 'live'],
            'a done or cancelled sitting is not something anybody can rehearse');
        $this->assertNotNull($sitting->scheduled_at, 'a sitting with no appointment is a draft row');
        $this->assertGreaterThan(date('Y-m-d H:i:s'), (string) $sitting->scheduled_at,
            'an appointment in the past cannot be walked through');

        // And on a DIFFERENT nominee from the transcript, so both states are visible at once.
        $filedFor = (int) DB::table('gates_nominee_interviews')->whereIn('nominee_id', $ids)
            ->value('nominee_id');
        $this->assertNotSame($filedFor, (int) $sitting->nominee_id);
    }

    /**
     * THE ONE THAT MADE THE SANDBOX HALF A SANDBOX.
     *
     * Judges sign in with a six-digit code EMAILED to them, and the sandbox judge is at
     * `demo.invalid` — reserved by RFC 2606 precisely so that no mail server will ever
     * accept it. So the demo judge could not sign in AT ALL: the ballot, the dossier map,
     * the recusal flow and the AI assist were unreachable in the one environment built for
     * trying them, and the documented workaround was to read a log file on a host with no
     * shell.
     *
     * Asserted against the REAL verification query rather than against the return value —
     * a code this test agrees with and the login screen rejects is the bug intact.
     */
    public function test_the_sandbox_judge_can_actually_sign_in(): void
    {
        DemoSeeder::seed(1);

        $r = DemoSeeder::judgeSignInCode();

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertMatchesRegularExpression('~^[0-9]{6}$~', (string) $r['code']);

        $row = DB::table('gates_otp_tokens')
            ->where('email_hash', hash('sha256', 'judge@' . DemoSeeder::MAIL_DOMAIN))
            ->where('purpose', 'judge_login')
            ->where('is_used', 0)
            ->first();

        $this->assertNotNull($row, 'the login screen looks for exactly this row');
        $this->assertTrue(hash_equals((string) $row->token_hash, hash('sha256', (string) $r['code'])),
            'the code shown is not the code the portal will accept');
        $this->assertGreaterThan(date('Y-m-d H:i:s'), (string) $row->expires_at);
    }

    /** A second code retires the first, exactly as a second real request would. */
    public function test_asking_twice_leaves_only_one_live_code(): void
    {
        DemoSeeder::seed(1);

        $first = DemoSeeder::judgeSignInCode();
        DemoSeeder::judgeSignInCode();

        $live = DB::table('gates_otp_tokens')
            ->where('email_hash', hash('sha256', 'judge@' . DemoSeeder::MAIL_DOMAIN))
            ->where('is_used', 0)->get();

        $this->assertCount(1, $live, 'two live codes is how somebody types the older one');
        $this->assertFalse(
            hash_equals((string) $live[0]->token_hash, hash('sha256', (string) $first['code'])),
            'the newest code must be the one that works');
    }

    /** Without a sandbox there is no judge, and no code is minted for a name that is free. */
    public function test_no_code_is_minted_when_there_is_no_sandbox(): void
    {
        $r = DemoSeeder::judgeSignInCode();

        $this->assertFalse($r['ok']);
        $this->assertNull($r['code']);
        $this->assertSame(0, DB::table('gates_otp_tokens')
            ->where('email_hash', hash('sha256', 'judge@' . DemoSeeder::MAIL_DOMAIN))->count());
    }

    /**
     * The doors lead to the three portals, and a token that opens nothing is not a door.
     */
    public function test_the_doors_open_every_portal_the_console_cannot_reach(): void
    {
        DemoSeeder::seed(1);
        $d = DemoSeeder::doors();

        $this->assertSame('judge@' . DemoSeeder::MAIL_DOMAIN, $d['judge_email']);

        $this->assertNotNull($d['interview']);
        $this->assertMatchesRegularExpression('~^/interview/[a-f0-9]{32}$~', $d['interview']['href'],
            'the route only matches a 32-character hex token');

        // One per state, because a submitted questionnaire, a draft and an untouched one are
        // three different screens and walking one says nothing about the other two.
        $this->assertCount(3, $d['questionnaires']);
        $this->assertSame(['draft', 'draft', 'submitted'],
            (static function (array $q): array {
                $s = array_column($q, 'state');
                sort($s);
                return $s;
            })($d['questionnaires']));

        foreach ($d['questionnaires'] as $q) {
            $this->assertMatchesRegularExpression('~^/my-work/[a-f0-9]{32}$~', $q['href']);
        }
    }

    /** Teardown takes the sitting and any live sign-in code with it. */
    public function test_removal_leaves_no_sitting_and_no_live_credential(): void
    {
        $seeded = DemoSeeder::seed(1);
        DemoSeeder::judgeSignInCode();

        DemoSeeder::purge();

        $this->assertSame(0, DB::table('gates_interviews')
            ->whereIn('nominee_id', array_values($seeded['nominees']))->count());
        $this->assertSame(0, DB::table('gates_otp_tokens')
            ->where('email_hash', hash('sha256', 'judge@' . DemoSeeder::MAIL_DOMAIN))->count(),
            'a live sign-in code for an account that no longer exists');
    }
}
