<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnaireChat as C;
use AfricaGates\Services\QuestionnaireService as Q;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A questionnaire an administrator can answer themselves, to see what a nominee sees.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THING THAT MATTERS MOST HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A TEST NEVER REACHES A JUDGE, AND THE REFUSAL IS IN THE WRITER.
 *
 * Not in the controller, not in a template condition, not in a convention about naming the
 * test nominee "ZZ Test" — in `publishEvidence()`, the single function that puts rows in a
 * dossier. A screen can be bypassed and the next caller added six months from now will not
 * remember to check, which is the same reasoning that put the interview consent gate in
 * `publish()` rather than in the page that shows the checkbox.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Before it, the only way to find out what the questionnaire feels like was to open one
 * against a real nominee: a live token, a row in the summary, a person the queue now shows as
 * asked, and on submit a set of evidence rows in that person's dossier. The only way to
 * rehearse was to contaminate the record you were rehearsing for — so nobody rehearsed, and
 * the first person to meet a confusing question was always a nominee.
 *
 * The rest follows from that:
 *
 *   1. IT BEHAVES LIKE THE REAL THING, because a rehearsal that behaves differently rehearses
 *      nothing. Same questions for the chosen programme, same conversation, same submit step.
 *   2. IT IS COUNTED NOWHERE. "Nine submitted" that silently includes an admin's own test is
 *      a number somebody plans a judging round around.
 *   3. THERE IS NOBODY TO INVITE, and pressing send says so rather than picking up an address.
 *   4. IT CAN BE DELETED, and a real submission cannot — that asymmetry is the feature.
 */
final class QuestionnaireTestModeTest extends TestCase
{
    private const PROG = 9700;
    private const CAT  = 9700;
    private const NOM  = 9701;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'Heritage', 'slug' => 'p-9700',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9700, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9700, 'title' => 'C', 'slug' => 'c-9700',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 800,
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9700, 'category_id' => self::CAT,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'grace@example.org',
            'country_code' => 'GH', 'reason' => 'Runs a girls coding club.',
            'nominator_name' => 'Kofi', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9701',
        ]);
    }

    /** Fill in and send a test, the way an admin rehearsing would. @return array{0:int,1:string} */
    private function answeredTest(?int $programme = self::PROG): array
    {
        $r = Q::openTest($programme, 1, 'Ama Test');
        $this->assertTrue($r['ok'], (string) $r['message']);
        $id = (int) $r['id'];
        $token = (string) $r['token'];

        $answers = [];
        foreach (Q::questions((int) ($programme ?? 0)) as $q) {
            $answers[(string) $q['slug']] = 'A rehearsal answer with 40 people in it, since 2019.';
        }
        Q::saveDraft($token, $answers, [[
            'uid' => 'w1', 'title' => 'A rehearsal work', 'kind' => 'report',
            'year' => '2024', 'link' => 'https://example.org/x', 'description' => 'x',
            'confirm' => 'Somebody',
        ]]);
        return [$id, $token];
    }

    // ══ 1. a test never reaches a judge ══════════════════════════════════════

    /** THE test in this file. */
    public function test_submitting_a_test_writes_nothing_into_any_dossier(): void
    {
        [$id, $token] = $this->answeredTest();

        $r = Q::submit($token, 'An Administrator', '10.0.0.1');

        $this->assertTrue($r['ok'], 'a test must still be submittable — the send step is worth rehearsing');
        $this->assertSame(0, (int) $r['evidence']);
        $this->assertStringContainsString('test questionnaire', (string) $r['message']);
        $this->assertStringContainsString('nothing was sent', strtolower((string) $r['message']));

        // Nothing anywhere in the evidence table, under any nominee id.
        $this->assertSame(0, DB::table('gates_nominee_evidence')
            ->where('provenance', 'nominee_supplied')->count());
        $this->assertSame(0, (int) DB::table('gates_nominee_submissions')
            ->where('id', $id)->value('evidence_count'));
    }

    /**
     * And the refusal is in the WRITER, so the "rewrite these rows" button — a route that
     * bypasses submit() entirely — cannot get a test in either.
     */
    public function test_republishing_a_submitted_test_still_writes_nothing(): void
    {
        [$id, $token] = $this->answeredTest();
        Q::submit($token, 'An Administrator');

        $this->assertSame(0, Q::publishEvidence($id));
        $this->assertSame(0, DB::table('gates_nominee_evidence')->count());
    }

    /**
     * A real submission through the identical path DOES publish. Without this, the test above
     * would pass just as happily if publishEvidence() were broken for everybody.
     */
    public function test_a_real_submission_through_the_same_path_does_publish(): void
    {
        $r = Q::open(self::NOM);
        $token = (string) $r['token'];
        $answers = [];
        foreach (Q::questions(self::PROG) as $q) {
            $answers[(string) $q['slug']] = 'A real answer about 400 girls in Accra, since 2019.';
        }
        Q::saveDraft($token, $answers, []);

        $sub = Q::submit($token, 'Grace Mensah');

        $this->assertTrue($sub['ok']);
        $this->assertGreaterThan(0, (int) $sub['evidence'],
            'the guard is refusing everything, not just tests');
        $this->assertStringNotContainsString('test', strtolower((string) $sub['message']));
    }

    // ══ 2. it behaves like the real thing ════════════════════════════════════

    public function test_it_asks_the_chosen_programmes_questions(): void
    {
        DB::table('gates_programme_questions')->insertOrIgnore([
            'id' => 9710, 'programme_id' => self::PROG, 'slug' => 'heritage_only',
            'kind' => 'textarea', 'label' => 'Which tradition does this belong to?',
            'is_required' => 1, 'sort_order' => 1, 'is_active' => 1,
        ]);

        $r = Q::openTest(self::PROG, 1, 'Ama Test');
        $form = Q::formFor((string) $r['token']);

        $labels = array_column($form['questions'], 'label');
        $this->assertContains('Which tradition does this belong to?', $labels,
            'a rehearsal that asks the wrong programme\'s questions rehearses nothing');
    }

    /**
     * The greeting is the first sentence anybody ever reads from this platform, so it is one of
     * the things most worth rehearsing — and a test has no nominee row to read a name from.
     */
    public function test_the_conversation_greets_the_made_up_name(): void
    {
        $r  = Q::openTest(self::PROG, 1, 'Ama Boateng');
        $st = C::start((string) $r['token']);

        $this->assertTrue($st['ok']);
        $this->assertStringContainsString('Hello Ama', $st['turns'][0]['text']);
        $this->assertStringNotContainsString('Hello there', $st['turns'][0]['text']);
    }

    public function test_an_unnamed_test_still_reads_as_a_sentence(): void
    {
        $r  = Q::openTest(null, 1, '');
        $st = C::start((string) $r['token']);

        $this->assertStringContainsString('Hello Test', $st['turns'][0]['text']);
        $this->assertSame('Test nominee', Q::formFor((string) $r['token'])['nominee']);
    }

    public function test_the_conversation_records_answers_exactly_as_it_would_for_a_nominee(): void
    {
        $r = Q::openTest(self::PROG, 1, 'Ama Test');
        $token = (string) $r['token'];
        C::start($token);

        C::say($token, 'I run a coding club for 400 girls in Accra, started in 2019.');

        $answers = json_decode((string) DB::table('gates_nominee_submissions')
            ->where('id', (int) $r['id'])->value('answers_json'), true);
        $this->assertContains('I run a coding club for 400 girls in Accra, started in 2019.',
            array_values($answers));
    }

    // ══ 3. counted nowhere ══════════════════════════════════════════════════

    /**
     * "Nine submitted" that silently includes an administrator's own rehearsal is a number
     * somebody plans a judging round around, and it would be wrong in the direction that
     * matters.
     */
    public function test_a_test_is_counted_separately_and_nowhere_else(): void
    {
        Q::open(self::NOM);                          // one real, never invited
        $before = Q::summary();

        [, $token] = $this->answeredTest();
        Q::submit($token, 'An Administrator');
        $after = Q::summary();

        $this->assertSame($before['total'], $after['total']);
        $this->assertSame($before['submitted'], $after['submitted']);
        $this->assertSame($before['not_invited'], $after['not_invited']);
        $this->assertSame($before['silent'], $after['silent']);
        $this->assertSame(1, $after['tests']);
    }

    public function test_it_appears_in_the_queue_marked_as_a_test(): void
    {
        Q::openTest(self::PROG, 1, 'Ama Test');

        $rows = array_values(array_filter(Q::queue(), static fn (array $r): bool => $r['is_test']));
        $this->assertCount(1, $rows);
        // Named rather than "Unknown": a row that reads as a broken record is a row somebody
        // investigates instead of deleting.
        $this->assertSame('Ama Test', $rows[0]['nominee']);
        $this->assertSame('Heritage', $rows[0]['programme']);
    }

    /** A test occupies no nominee, so it never hides a real person from the "not asked" list. */
    public function test_a_test_does_not_take_a_nominee_out_of_the_candidates_list(): void
    {
        $before = count(Q::candidates());
        Q::openTest(self::PROG, 1, 'Ama Test');

        $this->assertCount($before, Q::candidates());
    }

    // ══ 4. nobody to invite ══════════════════════════════════════════════════

    /**
     * The worst possible outcome of pressing "send the invitation" on a rehearsal would be an
     * email arriving at a real nominee's address, so the refusal is explicit rather than
     * relying on there happening to be no address to find.
     */
    public function test_a_test_cannot_be_invited(): void
    {
        $r = Q::openTest(self::PROG, 1, 'Ama Test');

        $inv = Q::invite((int) $r['id']);

        $this->assertFalse($inv['ok']);
        $this->assertStringContainsString('nobody to send it to', (string) $inv['message']);
        $this->assertNull(DB::table('gates_nominee_submissions')
            ->where('id', (int) $r['id'])->value('invited_at'));
    }

    // ══ 5. deletable, and only a test is ════════════════════════════════════

    public function test_a_test_can_be_deleted_outright(): void
    {
        $r = Q::openTest(self::PROG, 1, 'Ama Test');
        $id = (int) $r['id'];

        $d = Q::deleteTest($id);

        $this->assertTrue($d['ok']);
        $this->assertNull(Q::byId($id));
    }

    /**
     * And a real submission is not. Somebody's own account of their own work is re-opened, never
     * destroyed — a mistyped id on this route must not be the way it goes.
     */
    public function test_deleting_refuses_anything_that_is_not_a_test(): void
    {
        $r  = Q::open(self::NOM);
        $id = (int) $r['id'];

        $d = Q::deleteTest($id);

        $this->assertFalse($d['ok']);
        $this->assertStringContainsString('not a test', (string) $d['message']);
        $this->assertStringContainsString('Re-open', (string) $d['message']);
        $this->assertNotNull(Q::byId($id), 'a nominee\'s questionnaire was deleted');
    }

    public function test_deleting_something_that_does_not_exist_says_so(): void
    {
        $d = Q::deleteTest(987654);
        $this->assertFalse($d['ok']);
        $this->assertStringContainsString('could not be found', (string) $d['message']);
    }

    // ══ 6. several at once, and the constraint that protects real ones ══════

    /**
     * The unique index on (nominee_id, cycle_id) is what stops two half-finished submissions
     * racing for one real nominee, and it must not have to be weakened so an operator can keep
     * one test per programme. NULL cycle_id makes each test row distinct on both drivers.
     */
    public function test_several_tests_can_exist_side_by_side(): void
    {
        $a = Q::openTest(self::PROG, 1, 'One');
        $b = Q::openTest(self::PROG, 1, 'Two');
        $c = Q::openTest(null, 1, 'Three');

        foreach ([$a, $b, $c] as $r) $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertCount(3, array_unique([(int) $a['id'], (int) $b['id'], (int) $c['id']]));
        $this->assertSame(3, Q::summary()['tests']);
    }

    /** And the real constraint still holds: a second open for one nominee returns the first. */
    public function test_a_real_nominee_still_gets_exactly_one_questionnaire(): void
    {
        $first  = Q::open(self::NOM);
        $second = Q::open(self::NOM);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertStringContainsString('already has a questionnaire', (string) $second['message']);
    }

    public function test_a_programme_that_does_not_exist_is_refused(): void
    {
        $r = Q::openTest(88888, 1, 'Ama Test');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('could not be found', (string) $r['message']);
    }

    // ══ 7. and if the link escapes ══════════════════════════════════════════

    /**
     * A test link pasted into a message by mistake must tell the person who opens it what it is,
     * rather than letting them spend an evening describing work nobody will read.
     */
    public function test_the_nominee_facing_page_knows_it_is_a_test(): void
    {
        $r = Q::openTest(self::PROG, 1, 'Ama Test');

        $this->assertTrue(Q::formFor((string) $r['token'])['is_test']);
        $this->assertFalse(Q::formFor((string) Q::open(self::NOM)['token'])['is_test']);
    }
}
