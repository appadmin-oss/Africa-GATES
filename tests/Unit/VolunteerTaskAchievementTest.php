<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\VolunteersController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\Volunteer\Effect;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Getting a task FINISHED, from the staff side (addendum of 19 Nov 2026 to
 * docs/audits/2026-11-02-volunteer-tasks.md). The Tasks tab could accept work that was handed in
 * and could edit or close a task, but it could not answer "who is behind, and what do we do
 * about it": no take showed its due date, nothing said overdue, the list could not be narrowed
 * to what is waiting or what is late, and the only remedies were to wait or close the whole
 * task. Each case holds one of those:
 *
 *   1. every take shows its due date, and one held past it says OVERDUE in words;
 *   2. the list narrows to "handed in, waiting" and to "overdue";
 *   3. a nudge is one message to that volunteer, through the reminders' one mail path, recorded
 *      on the take and refused a second time inside a day;
 *   4. release frees the task and does not count against the volunteer; reassign moves the
 *      work to somebody who could have taken it, and tells both;
 *   5. a role that cannot write can do none of it.
 */
final class VolunteerTaskAchievementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_name'] = 'Femi Ade';
        $_SESSION['admin_role'] = 'superadmin';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['vol_admin_ok'], $_SESSION['vol_admin_error']);
        parent::tearDown();
    }

    private function vol(string $name, array $set = []): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01',
            'interests' => ['door'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        Volunteers::approve((int) $r['id'], 1);
        if ($set) DB::table('gates_volunteers')->where('id', $r['id'])->update($set);
        return Volunteers::of($uid);
    }

    private function task(array $o = []): int
    {
        $r = Tasks::create($o + ['title' => 'Digitise the 1st Edition booklet', 'checked_by' => 'lead', 'from_home' => '1', 'due_at' => date('Y-m-d\TH:i', strtotime('+3 days'))], 1);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        return (int) $r['id'];
    }

    private function takeId(int $task, object $v): int
    {
        return (int) DB::table('gates_vol_task_takes')->where('task_id', $task)->where('volunteer_id', $v->id)->value('id');
    }

    /** A task somebody took in time and is now behind on: its due date has passed while they hold it. */
    private function overdue(object $v, string $title = 'Ring the Kano nominees'): array
    {
        $t = $this->task(['title' => $title]);
        $this->assertTrue(Tasks::take($v, $t)['ok']);
        DB::table('gates_vol_tasks')->where('id', $t)->update(['due_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]);
        return [$t, $this->takeId($t, $v)];
    }

    private function ctrl(?OtpService $mailer = null): VolunteersController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $c = $b->build();
        return new VolunteersController($c->get(\Slim\Views\Twig::class), $c->get(AuditService::class), $mailer);
    }

    private function admin(array $body, ?OtpService $mailer = null): string
    {
        unset($_SESSION['vol_admin_ok'], $_SESSION['vol_admin_error']);
        $this->ctrl($mailer)->act((new ServerRequestFactory())->createServerRequest('POST', '/admin/volunteers')->withParsedBody($body + ['tab' => 'tasks']), new Response());
        return (string) ($_SESSION['vol_admin_error'] ?? $_SESSION['vol_admin_ok'] ?? '');
    }

    private function tab(array $q = []): string
    {
        return (string) $this->ctrl()->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/volunteers')->withQueryParams(['tab' => 'tasks'] + $q), new Response())->getBody();
    }

    /** @return list<int> the take ids the "Who is working on what" list drew */
    private static function drawn(string $html): array
    {
        preg_match_all('~data-take="(\d+)"~', $html, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    private function mailer(array &$sent, bool $ok = true): OtpService
    {
        $m = $this->createMock(OtpService::class);
        $m->method('sendBranded')->willReturnCallback(static function (string $to, string $subject, string $html, string $plain) use (&$sent, $ok): array {
            $sent[] = [$to, $subject, $plain];
            return ['success' => $ok];
        });
        return $m;
    }

    // ── 1 ─────────────────────────────────────────────────────────────────────────────

    public function test_every_take_shows_its_due_date_and_one_held_past_it_says_overdue(): void
    {
        $late = $this->vol('Bola Late');
        $fine = $this->vol('Chidi Fine');
        [, $lateTake] = $this->overdue($late);
        $t = $this->task(['title' => 'Fold the programmes']);
        $this->assertTrue(Tasks::take($fine, $t)['ok']);

        $rows = [];
        foreach (Tasks::takes() as $r) $rows[(int) $r->id] = $r;
        $this->assertTrue($rows[$lateTake]->overdue, 'held past its due date and not handed in');
        $this->assertFalse($rows[$this->takeId($t, $fine)]->overdue);

        $html = $this->tab();
        $this->assertSame([$lateTake, $this->takeId($t, $fine)], self::drawn($html), 'overdue first, then by due date');
        $this->assertMatchesRegularExpression('~data-take="' . $lateTake . '".*?Overdue~s', $html, 'overdue is said in words, not only a colour');
        $this->assertStringContainsString('Due ' . \AfricaGates\Support\DisplayTime::show((string) DB::table('gates_vol_tasks')->where('id', $t)->value('due_at'), 'j M Y, H:i'), $html);

        // Handed in after the due date is waiting on staff, not overdue.
        $this->assertTrue(Tasks::handIn($late, (int) DB::table('gates_vol_task_takes')->where('id', $lateTake)->value('task_id')));
        $this->assertFalse(Tasks::takes()[0]->overdue);
    }

    // ── 2 ─────────────────────────────────────────────────────────────────────────────

    public function test_the_list_narrows_to_handed_in_waiting_and_to_overdue(): void
    {
        [, $lateTake] = $this->overdue($this->vol('Bola Late'));
        $w = $this->vol('Dayo Waiting');
        $t = $this->task(['title' => 'Check the photo captions']);
        $this->assertTrue(Tasks::take($w, $t)['ok']);
        $this->assertTrue(Tasks::handIn($w, $t));
        $waitTake = $this->takeId($t, $w);
        $o = $this->vol('Efe Onit');
        $t2 = $this->task(['title' => 'Draft the thank-you note']);
        $this->assertTrue(Tasks::take($o, $t2)['ok']);

        $this->assertSame(['all' => 3, 'waiting' => 1, 'overdue' => 1], Tasks::takeCounts());
        $this->assertSame([$lateTake], self::drawn($this->tab(['show' => 'overdue'])));
        $this->assertSame([$waitTake], self::drawn($this->tab(['show' => 'waiting'])));
        $this->assertCount(3, self::drawn($this->tab(['show' => 'nonsense'])), 'an unknown filter falls back to everything, never an error');
        $html = $this->tab(['show' => 'overdue']);
        $this->assertMatchesRegularExpression('~<a[^>]+href="/admin/volunteers\?tab=tasks&amp;show=overdue[^"]*"[^>]*aria-current="true"~', $html, 'the filter in use is marked for a screen reader too');
    }

    // ── 3 ─────────────────────────────────────────────────────────────────────────────

    public function test_a_nudge_is_one_message_recorded_and_refused_again_inside_a_day(): void
    {
        $v = $this->vol('Bola Late');
        [, $take] = $this->overdue($v);
        $sent = [];
        $send = static function (string $to, string $subject, string $html, string $plain) use (&$sent): bool { $sent[] = [$to, $subject, $plain]; return true; };

        $r = Tasks::nudge($take, 'The printers need it by Friday.', 'Femi Ade', $send);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertCount(1, $sent);
        $email = (string) DB::table('gates_users')->where('id', $v->user_id)->value('email');
        $this->assertSame($email, $sent[0][0], 'to the volunteer, and nobody else');
        $this->assertStringContainsString('Ring the Kano nominees', $sent[0][2]);
        $this->assertStringContainsString('The printers need it by Friday.', $sent[0][2]);
        $this->assertStringContainsString('hand it in', $sent[0][2]);
        $this->assertNotNull(DB::table('gates_vol_task_takes')->where('id', $take)->value('nudged_at'), 'recorded on the take');
        $this->assertSame(1, DB::table('gates_broadcast_log')->where('campaign', 'like', 'vol-nudge:' . $take . ':%')->count(), 'through the reminders’ claimed-once log');

        $again = Tasks::nudge($take, '', 'Femi Ade', $send);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already', $again['error']);
        $this->assertCount(1, $sent, 'at most once a day');

        DB::table('gates_vol_task_takes')->where('id', $take)->update(['nudged_at' => date('Y-m-d H:i:s', time() - 25 * 3600)]);
        $this->assertTrue(Tasks::nudge($take, '', 'Femi Ade', $send)['ok'], 'a day later it may go again');
        $this->assertCount(2, $sent);

        // A send that fails is not recorded as a nudge, so staff can try again.
        DB::table('gates_vol_task_takes')->where('id', $take)->update(['nudged_at' => null]);
        $fail = Tasks::nudge($take, '', 'Femi Ade', static fn (): bool => false);
        $this->assertFalse($fail['ok']);
        $this->assertNull(DB::table('gates_vol_task_takes')->where('id', $take)->value('nudged_at'));

        // Handed in: the volunteer is waiting on us; there is nothing to nudge them about.
        $w = $this->vol('Dayo Waiting');
        $t = $this->task(['title' => 'Check the captions']);
        $this->assertTrue(Tasks::take($w, $t)['ok']);
        $this->assertTrue(Tasks::handIn($w, $t));
        $this->assertFalse(Tasks::nudge($this->takeId($t, $w), '', 'Femi Ade', $send)['ok']);
    }

    public function test_the_tasks_tab_nudges_through_the_mailer_audits_it_and_shows_when(): void
    {
        $v = $this->vol('Bola Late');
        [, $take] = $this->overdue($v);
        $sent = [];
        $this->assertStringContainsString('Nudged', $this->admin(['do' => 'nudge', 'take' => $take, 'note' => 'Nearly there?'], $this->mailer($sent)));
        $this->assertCount(1, $sent);
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'volunteers.nudge')->where('target_id', $take)->count());
        $this->assertStringContainsString('already', $this->admin(['do' => 'nudge', 'take' => $take], $this->mailer($sent)));
        $this->assertCount(1, $sent);
        $this->assertMatchesRegularExpression('~data-take="' . $take . '".*?Nudged~s', $this->tab(), 'the tab says it was done, so a second person does not do it again');

        $failed = [];
        DB::table('gates_vol_task_takes')->where('id', $take)->update(['nudged_at' => null]);
        $this->assertStringContainsString('could not be sent', $this->admin(['do' => 'nudge', 'take' => $take], $this->mailer($failed, false)));
    }

    // ── 4 ─────────────────────────────────────────────────────────────────────────────

    public function test_release_frees_the_task_and_does_not_count_against_the_volunteer(): void
    {
        $v = $this->vol('Bola Late');
        $t = $this->task(['title' => 'Proofread the brochure']);
        $this->assertTrue(Tasks::take($v, $t)['ok']);
        $take = $this->takeId($t, $v);

        $this->assertStringContainsString('Released', $this->admin(['do' => 'release', 'take' => $take]));
        $this->assertSame(Tasks::RELEASED, DB::table('gates_vol_task_takes')->where('id', $take)->value('status'));
        $this->assertSame([], Tasks::mine($v), 'it leaves their Mine list');
        $e = Effect::of($v);
        $this->assertSame([0, 0, 0], [$e['dropped'], $e['late'], $e['open']], 'staff releasing it is not the volunteer handing it back');

        $other = $this->vol('Chidi Next');
        $this->assertContains($t, array_map(static fn ($x) => (int) $x->id, Tasks::library($other)), 'the task is offered again');
        $this->assertTrue(Tasks::take($v, $t)['ok'], 'and the person it was released from may take it again');
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'volunteers.release')->count());
        $this->assertStringContainsString('isn’t held', $this->admin(['do' => 'release', 'take' => 999999]));
    }

    public function test_reassign_moves_the_work_to_somebody_who_could_take_it_and_tells_both(): void
    {
        $from = $this->vol('Bola Late');
        [$t, $take] = $this->overdue($from);
        $to = $this->vol('Chidi Next');
        $sent = [];

        $this->assertStringContainsString('the same person', $this->admin(['do' => 'reassign', 'take' => $take, 'to' => $from->id], $this->mailer($sent)));
        $untrained = $this->vol('Dayo Untrained');
        $door = $this->task(['title' => 'Door rota', 'module_key' => 'door', 'from_home' => '']);
        // Held by somebody trained (seeded directly: the rule under test is the TARGET's training).
        DB::table('gates_vol_task_takes')->insert(['task_id' => $door, 'volunteer_id' => $from->id, 'status' => Tasks::TAKEN, 'taken_at' => date('Y-m-d H:i:s')]);
        $this->assertStringContainsString('training', $this->admin(['do' => 'reassign', 'take' => $this->takeId($door, $from), 'to' => $untrained->id], $this->mailer($sent)));
        $this->assertSame([], $sent, 'a refused reassignment tells nobody anything');

        $this->assertStringContainsString('Given to', $this->admin(['do' => 'reassign', 'take' => $take, 'to' => $to->id], $this->mailer($sent)));
        $this->assertSame(Tasks::RELEASED, DB::table('gates_vol_task_takes')->where('id', $take)->value('status'));
        $new = DB::table('gates_vol_task_takes')->where('task_id', $t)->where('volunteer_id', $to->id)->first();
        $this->assertSame(Tasks::TAKEN, $new->status, 'staff may give an overdue task on; the new holder sees its date and staff can change it');
        $emails = array_column($sent, 0);
        $this->assertContains((string) DB::table('gates_users')->where('id', $from->user_id)->value('email'), $emails);
        $this->assertContains((string) DB::table('gates_users')->where('id', $to->user_id)->value('email'), $emails);
        $this->assertCount(2, $sent, 'one message each, no more');
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'volunteers.reassign')->count());

        $this->assertStringContainsString('isn’t held', $this->admin(['do' => 'reassign', 'take' => $take, 'to' => $to->id], $this->mailer($sent)), 'a take already passed on cannot be passed on again');
        $this->assertStringContainsString('Given to', $this->admin(['do' => 'reassign', 'take' => (int) $new->id, 'to' => $from->id], $this->mailer($sent)), 'and the first holder may be given it back');
    }

    // ── 5 ─────────────────────────────────────────────────────────────────────────────

    public function test_a_role_that_cannot_write_can_neither_nudge_release_nor_reassign(): void
    {
        $v = $this->vol('Bola Late');
        [, $take] = $this->overdue($v);
        $to = $this->vol('Chidi Next');
        $_SESSION['admin_role'] = 'viewer';
        $sent = [];
        foreach ([['do' => 'nudge', 'take' => $take], ['do' => 'release', 'take' => $take], ['do' => 'reassign', 'take' => $take, 'to' => $to->id]] as $b) {
            $this->assertStringContainsString('can look here but not change anything', $this->admin($b, $this->mailer($sent)));
        }
        $this->assertSame([], $sent);
        $this->assertSame(Tasks::TAKEN, DB::table('gates_vol_task_takes')->where('id', $take)->value('status'));
        $this->assertNull(DB::table('gates_vol_task_takes')->where('id', $take)->value('nudged_at'));
        $html = $this->tab();
        $this->assertStringNotContainsString('name="do" value="nudge"', $html, 'the controls are not drawn for a role that cannot use them');
        $this->assertStringNotContainsString('name="do" value="release"', $html);
    }
}
