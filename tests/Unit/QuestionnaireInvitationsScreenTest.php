<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\QuestionnairePolicy;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The invitations screen, rendered through the real container.
 *
 * The plan is pinned by {@see QuestionnaireInvitesTest} and the deadline rule by
 * {@see QuestionnairePolicyTest}. What can only be seen by rendering is the property the
 * user asked for in the first place: that the NUMBER an operator is looking at is the
 * number the button will send, for the selection in front of them.
 *
 * That is why the selection round-trips through the query string rather than the session —
 * a count computed from one selection and a button posting another is the failure mode
 * here, and it looks completely fine on screen.
 */
final class QuestionnaireInvitationsScreenTest extends TestCase
{
    private const CYCLE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        DB::table('gates_award_programmes')->insert([
            ['id' => 1, 'slug' => 'gates',   'title' => 'Africa GATES',  'is_active' => 1, 'sort_order' => 1],
            ['id' => 2, 'slug' => 'schools', 'title' => 'Schools Prize', 'is_active' => 1, 'sort_order' => 2],
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging',
            'results_date' => '2026-07-15 12:00:00',
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => 1, 'cycle_id' => self::CYCLE, 'slug' => 'c', 'title' => 'Category', 'sort_order' => 1,
        ]);
    }

    private function nominee(int $id, string $email, int $programmeId = 1, string $status = 'draft'): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => 1, 'name' => 'Nominee ' . $id, 'status' => 'approved',
        ]);
        if ($email !== '') {
            DB::table('gates_nominations')->insert([
                'cycle_id' => self::CYCLE, 'category_id' => 1,
                'nominee_name' => 'Nominee ' . $id, 'nominee_email' => $email,
                'nominator_name' => 'Nominator', 'nominator_email' => 'nom@example.com',
                'status' => 'approved',
            ]);
        }
        DB::table('gates_nominee_submissions')->insert([
            'id' => $id, 'nominee_id' => $id, 'programme_id' => $programmeId,
            'cycle_id' => self::CYCLE, 'status' => $status,
            'invite_token' => str_pad((string) $id, 32, 'a'),
        ]);
    }

    private function render(string $query = ''): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/questionnaires/invitations' . ($query !== '' ? '?' . $query : ''));
        // Slim populates the parsed query from the URI; the factory does not, so the
        // controller would see none of it.
        parse_str(ltrim($query, '?'), $qs);

        $res = $b->build()->get(\AfricaGates\Admin\Controllers\QuestionnairesController::class)
            ->invitations($req->withQueryParams($qs), (new ResponseFactory())->createResponse());

        $this->assertSame(200, $res->getStatusCode(), 'the invitations screen did not render');
        return (string) $res->getBody();
    }

    // ══ the count is the count ═══════════════════════════════════════════════

    /**
     * THE ONE THE FEATURE IS FOR. Two programmes, four nominees; pick one programme and both
     * the headline count and the send button move to that programme's number.
     */
    public function test_the_count_and_the_button_follow_the_programme_selection(): void
    {
        $this->nominee(1, 'a@example.com', 1);
        $this->nominee(2, 'b@example.com', 1);
        $this->nominee(3, 'c@example.com', 2);

        $all = $this->render('cycle=1');
        $this->assertStringContainsString('Send 3 now', $all);

        $one = $this->render('cycle=1&p[]=2');
        $this->assertStringContainsString('Send 1 now', $one,
            'the button must send what the current selection resolves to, not the whole cycle');
        $this->assertStringContainsString('c@example.com', $one);
        $this->assertStringNotContainsString('a@example.com', $one);
    }

    /** The posted form must carry the same selection the count was computed from. */
    public function test_the_send_form_carries_the_selection_forward(): void
    {
        $this->nominee(1, 'a@example.com', 1);
        $this->nominee(2, 'c@example.com', 2);

        $html = $this->render('cycle=1&p[]=2&audience=all&again=1');

        $this->assertStringContainsString('name="p[]" value="2"', $html);
        $this->assertStringContainsString('name="audience" value="all"', $html);
        $this->assertStringContainsString('name="again" value="1"', $html);
    }

    public function test_every_bucket_is_shown_not_just_the_sendable_one(): void
    {
        $this->nominee(1, 'a@example.com', 1);
        $this->nominee(2, '', 1);
        $this->nominee(3, 'c@example.com', 1, 'disqualified');

        $html = $this->render('cycle=1&audience=all');

        foreach (['Will be written to', 'No email address', 'Unsubscribed', 'Disqualified'] as $label) {
            $this->assertStringContainsString($label, $html, "the {$label} count is not on screen");
        }
        $this->assertStringContainsString('not being written to', $html,
            'the skipped list must be reachable, or a nominee with no address is invisible');
    }

    /** Each programme's checkbox carries its own outstanding count, or the choice is a guess. */
    public function test_each_programme_shows_how_many_are_outstanding(): void
    {
        $this->nominee(1, 'a@example.com', 1, 'draft');
        $this->nominee(2, 'b@example.com', 1, 'submitted');
        $this->nominee(3, 'c@example.com', 2, 'draft');

        $html = $this->render('cycle=1');

        $this->assertStringContainsString('Africa GATES', $html);
        $this->assertStringContainsString('1 still to submit of 2', $html);
        $this->assertStringContainsString('1 still to submit of 1', $html);
    }

    // ══ the deadline half of the screen ══════════════════════════════════════

    public function test_the_screen_says_whether_the_deadline_is_set_or_merely_derived(): void
    {
        $derived = $this->render('cycle=1');
        $this->assertStringContainsString("taken from this cycle's results date", $derived);

        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00'], 1);
        $set = $this->render('cycle=1');
        $this->assertStringNotContainsString("taken from this cycle's results date", $set);
        $this->assertStringContainsString('2026-05-20T18:00', $set, 'the field must be pre-filled');
    }

    public function test_the_consequence_of_the_rule_is_stated_in_words(): void
    {
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00'], 1);
        $this->assertStringContainsString('keeps their nomination', $this->render('cycle=1'));

        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 5,
        ], 1);
        $this->assertStringContainsString('disqualified on 25 May 2026', $this->render('cycle=1'));
    }

    /**
     * Who would be disqualified, named, BEFORE the button that does it exists. A count is
     * not enough to authorise taking a nomination away.
     */
    public function test_the_disqualification_preview_names_people_and_changes_nothing(): void
    {
        $this->nominee(1, 'a@example.com', 1, 'draft');
        $this->nominee(2, 'b@example.com', 1, 'submitted');
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 1);

        $html = $this->render('cycle=1');

        $this->assertStringContainsString('would be disqualified now', $html);
        $this->assertStringContainsString('>Nominee 1<', $html);
        $this->assertStringNotContainsString('>Nominee 2<', $html, 'somebody who answered must not be listed');
        $this->assertStringContainsString('Try sending to them again first', $html);

        $this->assertSame(0, DB::table('gates_nominee_submissions')->where('status', 'disqualified')->count(),
            'rendering the screen must not have disqualified anybody');
    }

    public function test_no_disqualification_panel_appears_while_the_rule_is_off(): void
    {
        $this->nominee(1, 'a@example.com', 1, 'draft');

        $this->assertStringNotContainsString('would be disqualified now', $this->render('cycle=1'));
    }

    // ══ empties, roles and tokens ════════════════════════════════════════════

    public function test_a_cycle_with_nobody_in_it_says_so_rather_than_offering_a_send(): void
    {
        $html = $this->render('cycle=1');

        $this->assertStringContainsString('Nobody matches this selection', $html);
        $this->assertStringNotContainsString('Send 0 now', $html);
    }

    public function test_every_post_form_carries_a_csrf_token(): void
    {
        $this->nominee(1, 'a@example.com', 1);
        QuestionnairePolicy::save(self::CYCLE, [
            'deadline_at' => '2026-05-20 18:00', 'autodisqualify' => 1, 'grace_days' => 0,
        ], 1);

        $html = $this->render('cycle=1');

        preg_match_all('~<form[^>]*method="post"~i', $html, $posts);
        $this->assertGreaterThan(0, count($posts[0]));
        $this->assertSame(count($posts[0]), substr_count($html, 'name="_token"'),
            'a POST form without a token is a page whose buttons all fail');
    }

    /** A viewer may read the screen; the send and the deadline form are not offered. */
    public function test_a_viewer_is_shown_the_numbers_and_no_buttons(): void
    {
        $_SESSION['admin_role'] = 'viewer';
        $this->nominee(1, 'a@example.com', 1);

        $html = $this->render('cycle=1');

        $this->assertStringContainsString('Will be written to', $html);
        $this->assertStringNotContainsString('Send 1 now', $html, 'the UI must not offer a 403');
    }

    public function test_an_unknown_cycle_falls_back_to_the_newest_rather_than_rendering_empty(): void
    {
        $this->nominee(1, 'a@example.com', 1);

        $html = $this->render('cycle=99999');

        $this->assertStringContainsString('Send 1 now', $html);
    }
}
