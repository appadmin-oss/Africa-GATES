<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\NomineeStatus;
use AfricaGates\Services\Volunteer\Outreach;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The volunteer portal, after two messages on 16 Nov 2026:
 *
 *  · a blind volunteer: "some areas my screen reader is supposed to speak out, it doesn't …
 *    I'm finding it difficult to navigate the portal" — fields named twice or three times and
 *    their help never heard; two date boxes sharing one id, so one had two names and the other
 *    none; an unnamed bell; "Getting started" steps drawn as buttons that went nowhere;
 *  · the coordinators: "Can we have an update on all nominees, please? … their current status,
 *    including portal updates, publicity and voting mobilisation" — answered in WhatsApp
 *    threads until now (Services\Volunteer\NomineeStatus).
 */
final class VolunteerStatusUpdatesTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset(); Training::reset(); Outreach::reset();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'su-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho Awards', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'voting']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Community Leader', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_old']);
        parent::tearDown();
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    private function volunteer(string $name, array $caps): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . bin2hex(random_bytes(2)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun',
            'dob' => '1995-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        foreach ($caps as $c) $this->assertTrue(Grants::grant('member', $uid, $c, 'cycle', $this->cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $_SESSION['user_id'] = $uid;
        $v = Volunteers::of($uid);
        if (in_array(Outreach::CAPABILITY, $caps, true)) Outreach::agree($v, $this->cycle);
        return $v;
    }

    private function nominee(string $name): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat, 'name' => $name, 'status' => 'approved', 'nominated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_nominee_details')->insert(['nominee_id' => $id, 'phones' => json_encode(['+2348031112233']), 'emails' => json_encode(['n' . $id . '@school.ng']),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    private function place(string $tab = '', array $q = []): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer')->withQueryParams($q);
        $res = $this->ctrl()->place($req, new Response(), ['kind' => 'cycle', 'id' => (string) $this->cycle, 'page' => 'nominees'] + ($tab ? ['tab' => $tab] : []));
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    private function act(array $body): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody($body + ['back' => '/volunteer']);
        return $this->ctrl()->act($req, new Response());
    }

    // ── what a screen reader hears ───────────────────────────────────────────

    /**
     * Two forms on one page (log a contact, write an email) each drew a date box called
     * `f_follow_up_on`: both labels named the first box and the second had none. And every
     * group's visible name was a paragraph beside a hidden label, with the help never part of
     * the field. One id per form now; the visible name IS the label; the help describes it.
     */
    public function test_every_field_on_the_nominee_page_has_one_name_and_hears_its_help(): void
    {
        $n = $this->nominee('Halima Bello');
        $this->volunteer('Chika Nwosu', [Outreach::CAPABILITY]);
        $html = $this->place('', ['n' => (string) $n]);

        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $dupes = array_keys(array_filter(array_count_values($m[1]), static fn ($c) => $c > 1));
        $this->assertSame([], $dupes, 'two elements share an id, so a label names the wrong box');

        preg_match_all('/<label[^>]*\sfor="([^"]+)"/', $html, $l);
        $twice = array_keys(array_filter(array_count_values($l[1]), static fn ($c) => $c > 1));
        $this->assertSame([], $twice, 'a field with two labels is read with its name twice');

        // The note's help is its description, not a paragraph a reader passes on the way.
        $this->assertMatchesRegularExpression('~<label class="sh7-form__name" for="(f_outreach_log_note)">Note</label>\s*<p class="sh7-form__help" id="([^"]+)">What they said~', $html);
        preg_match('~<label class="sh7-form__name" for="f_outreach_log_note">Note</label>\s*<p class="sh7-form__help" id="([^"]+)">~', $html, $h);
        $this->assertMatchesRegularExpression('~<textarea class="sh7-input" id="f_outreach_log_note" name="note" rows="4" aria-describedby="' . preg_quote($h[1], '~') . '"~', $html);
    }

    /** The bell is named by its own text, and the Getting-started steps go somewhere. */
    public function test_the_bell_is_named_and_the_first_steps_are_links(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'New Person', 'email' => 'new' . bin2hex(random_bytes(2)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0102', 'state' => 'Lagos', 'emergency_name' => 'Ada', 'emergency_phone' => '0803 555 0198', 'area' => 'Ikotun',
            'dob' => '1995-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs A', 'ref_contact' => '0803 000 0001', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        $_SESSION['user_id'] = $uid;
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer');
        $html = (string) $this->ctrl()->root($req, new Response(), [])->getBody();
        $this->assertStringNotContainsString('<summary class="sh7-bell" aria-label=', $html, 'the bell is announced as an unnamed group');
        $this->assertMatchesRegularExpression('~<a class="sh7-step__t sh7-step__link" href="/volunteer/training">Short training</a>~', $html, 'a step drawn as a button that goes nowhere');
        $this->assertMatchesRegularExpression('~<a class="sh7-step__t sh7-step__link" href="/volunteer/tasks">A first task</a>~', $html);
        // With no module written there is nothing to train on: the step is done, not a dead end.
        $this->assertStringContainsString('Nothing to do yet', $html);
    }

    // ── status updates ───────────────────────────────────────────────────────

    public function test_a_lead_asks_the_volunteers_answer_and_the_progress_is_tracked(): void
    {
        $a = $this->nominee('Halima Bello');
        $b = $this->nominee('Bayo Adisa');
        $lead = $this->volunteer('Mrs Lead', ['vol.team_lead']);
        // Not a lead on another award: refused.
        $this->assertFalse(NomineeStatus::request($lead, $this->cycle + 999, 'x', date('Y-m-d', strtotime('+2 days')))['ok']);
        $this->assertFalse(NomineeStatus::request($lead, $this->cycle, 'x', date('Y-m-d', strtotime('-1 day')))['ok'], 'a due date in the past');
        $res = $this->act(['do' => 'status_request', 'cycle' => (string) $this->cycle, 'message' => '', 'due_on' => date('Y-m-d', strtotime('+2 days'))]);
        $this->assertSame(303, $res->getStatusCode());
        $req = NomineeStatus::open($this->cycle);
        $this->assertNotNull($req, 'the request was not kept');
        $this->assertSame(NomineeStatus::ASK, $req->message, 'a blank message did not take the coordinators’ words');

        $v = $this->volunteer('Chika Nwosu', [Outreach::CAPABILITY]);
        // An update sent BEFORE the lead asked does not answer the request.
        $this->assertTrue(NomineeStatus::record($v, $b, ['portal' => 'none', 'publicity' => 'none', 'mobilising' => 'none'])['ok']);
        DB::table('gates_vol_nominee_status')->where('nominee_id', $b)->update(['created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $list = $this->place();
        $this->assertStringContainsString('asked for an update on every nominee', $list);
        // Both owe one, so the shell states the shared chip once for the list.
        $this->assertStringContainsString('>Update due<', $list, 'nominees owed an update are not marked');
        $this->assertStringContainsString('0 of 2 updated so far', $list);

        // A refused update keeps its answers; a sent one opens the next nominee owed one.
        $this->assertFalse(NomineeStatus::record($v, $a, ['portal' => 'helping', 'publicity' => 'nonsense', 'mobilising' => 'none'])['ok']);
        $res = $this->act(['do' => 'status_update', 'nominee' => (string) $a, 'portal' => 'helping', 'publicity' => 'flier', 'mobilising' => 'started', 'status_note' => 'Sharing in her church group']);
        $this->assertStringContainsString('?n=' . $b . '#status', $res->getHeaderLine('Location'), 'the next nominee owed an update did not open');
        $this->assertStringContainsString('Next to update: Bayo Adisa', (string) $_SESSION['vol_toast']);

        $this->assertSame(['done' => 1, 'total' => 2], NomineeStatus::progress($req, [$a, $b]));
        $st = NomineeStatus::latest([$a])[$a];
        $this->assertSame('Portal: helping them finish it · Publicity: flier and voting link shared · Voting: started asking people to vote', NomineeStatus::words($st));
        $this->assertSame('Sharing in her church group', $st->note);

        // The "still to update" view lists only who is left.
        $todo = $this->place('update');
        $this->assertStringContainsString('Bayo Adisa', $todo);
        $this->assertStringNotContainsString('>Halima Bello<', $todo);

        // The lead's view: progress and where every nominee stands.
        $_SESSION['user_id'] = (int) $lead->user_id;
        $rq = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer/team/updates');
        $team = (string) $this->ctrl()->root($rq, new Response(), ['page' => 'team', 'tab' => 'updates'])->getBody();
        $this->assertStringContainsString('1 of 2', $team);
        $this->assertMatchesRegularExpression('~Halima Bello.*?Portal: helping them finish it~s', $team);
        // Bayo's update is from before the request: shown, and still owed.
        $this->assertMatchesRegularExpression('~Bayo Adisa.*?Portal: not started.*?Update due~s', $team);
    }

    /** An update needs the same access as reaching the nominee — the role, on that award. */
    public function test_only_somebody_reaching_nominees_on_the_award_may_update_one(): void
    {
        $n = $this->nominee('Halima Bello');
        $other = $this->volunteer('Not Outreach', ['vol.nomination_check']);
        $this->assertFalse(NomineeStatus::record($other, $n, ['portal' => 'none', 'publicity' => 'none', 'mobilising' => 'none'])['ok']);
        $this->assertSame([], NomineeStatus::latest([$n]));
    }
}
