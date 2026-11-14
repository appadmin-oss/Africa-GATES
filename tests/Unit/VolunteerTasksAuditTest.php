<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\VolunteersController;
use AfricaGates\Admin\Services\AttentionBoard;
use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Effect;
use AfricaGates\Services\Volunteer\Reminders;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Tasks;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The volunteer-task audit (docs/audits/2026-11-02-volunteer-tasks.md). Each case is one defect,
 * written to fail on the code the audit found and to hold the rule the screens now state:
 *
 *   1. a volunteer can hand work in; handed-in work is never late, on time is measured by the
 *      hand-in, and staff see what is waiting (the tab, its count and Home's card);
 *   2. what to do and how it is checked reach the volunteer on every row that offers a task;
 *   3. a past-due task cannot be taken, and no list offers one;
 *   4. staff record progress, edit and close a task; a closed task is not offered;
 *   5. "New tasks that fit me" is read: when posted, weekly, never — and a held task is
 *      reminded the day before it is due, unless handed in;
 *   6. accepting is a compare-and-set and an accepted take is one hours row, held by a UNIQUE;
 *   7. a finished task cannot be taken again, and no list offers "Take it" on it.
 */
final class VolunteerTasksAuditTest extends TestCase
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
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['admin_id'], $_SESSION['admin_name'],
              $_SESSION['admin_role'], $_SESSION['vol_admin_ok'], $_SESSION['vol_admin_error']);
        parent::tearDown();
    }

    private function vol(string $name = 'Chika Nwosu', array $set = []): object
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
        $r = Tasks::create($o + ['title' => 'Digitise the 1st Edition booklet', 'checked_by' => 'lead', 'from_home' => '1'], 1);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        return (int) $r['id'];
    }

    private function takeId(int $task, object $v): int
    {
        return (int) DB::table('gates_vol_task_takes')->where('task_id', $task)->where('volunteer_id', $v->id)->value('id');
    }

    private function vctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    private function actrl(): VolunteersController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteersController::class);
    }

    private function page(object $v, string $page, string $tab): string
    {
        $_SESSION['user_id'] = (int) $v->user_id;
        return (string) $this->vctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/' . $page), new Response(), ['page' => $page, 'tab' => $tab])->getBody();
    }

    private function act(object $v, array $body): array
    {
        $_SESSION['user_id'] = (int) $v->user_id;
        unset($_SESSION['vol_toast'], $_SESSION['vol_error']);
        $this->vctrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody($body), new Response());
        return [$_SESSION['vol_toast'] ?? null, $_SESSION['vol_error'] ?? null];
    }

    private function admin(array $body): string
    {
        unset($_SESSION['vol_admin_ok'], $_SESSION['vol_admin_error']);
        $this->actrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/admin/volunteers')->withParsedBody($body), new Response());
        return (string) ($_SESSION['vol_admin_error'] ?? $_SESSION['vol_admin_ok'] ?? '');
    }

    private function adminPage(string $tab): string
    {
        return (string) $this->actrl()->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/volunteers')->withQueryParams(['tab' => $tab]), new Response())->getBody();
    }

    // ── 1 ─────────────────────────────────────────────────────────────────────────────

    public function test_a_volunteer_hands_work_in_and_staff_see_it_waiting(): void
    {
        $v = $this->vol('Ngozi Eze');
        $t = $this->task(['due_at' => date('Y-m-d H:i:s', strtotime('+3 days'))]);
        $this->assertTrue(Tasks::take($v, $t)['ok']);

        $html = $this->page($v, 'tasks', 'mine');
        $this->assertStringContainsString('name="do" value="handin"', $html, 'the Mine row offers a way to say the task is finished');

        [$ok, $err] = $this->act($v, ['do' => 'handin', 'task' => $t]);
        $this->assertNull($err);
        $this->assertStringContainsString('Handed in', (string) $ok);
        $k = DB::table('gates_vol_task_takes')->where('id', $this->takeId($t, $v))->first();
        $this->assertSame(Tasks::HANDED_IN, $k->status);
        $this->assertNotNull($k->handed_in_at);
        [, $err] = $this->act($v, ['do' => 'handin', 'task' => $t]);
        $this->assertNotNull($err, 'handed in once');

        $html = $this->page($v, 'tasks', 'mine');
        $this->assertStringContainsString('waiting for a lead to accept it', $html, 'it stays on Mine, saying what it waits for');
        $this->assertStringNotContainsString('name="do" value="handin"', $html);

        // Staff: the tab's count, the waiting list, and Home's card.
        $admin = $this->adminPage('tasks');
        $this->assertStringContainsString('Handed in, waiting for you', $admin);
        $this->assertMatchesRegularExpression('~Tasks</span><span class="cn-fchip__v">1</span>~', $admin, 'the tab carries the count of work waiting');
        $this->assertStringContainsString('Ngozi Eze', $admin);
        $this->assertContains('volunteer_tasks', array_column(AttentionBoard::forRole('superadmin'), 'key'));

        // Accepting it from the waiting list ends it.
        $this->assertStringContainsString('Accepted', $this->admin(['do' => 'accept', 'tab' => 'tasks', 'take' => $k->id, 'hours' => '2', 'accepted' => '1']));
        $this->assertSame(Tasks::DONE, DB::table('gates_vol_task_takes')->where('id', $k->id)->value('status'));
        $this->assertSame(0, Tasks::handedInCount());
    }

    public function test_handed_in_work_is_never_late_and_on_time_is_measured_by_the_hand_in(): void
    {
        $v = $this->vol();
        $due = date('Y-m-d H:i:s', strtotime('-1 day'));
        // Three tasks due yesterday, each finished BEFORE the due date and accepted today.
        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $ids[$i] = $t = $this->task(['title' => 'Booklet ' . $i]);
            $this->assertTrue(Tasks::take($v, $t)['ok']);
            $this->assertTrue(Tasks::handIn($v, $t));
            DB::table('gates_vol_tasks')->where('id', $t)->update(['due_at' => $due]);
            DB::table('gates_vol_task_takes')->where('id', $this->takeId($t, $v))->update(['handed_in_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
            if ($i < 3) $this->assertTrue(Tasks::accept($this->takeId($t, $v), 1.0, 1, 'Kemi Bello'));
        }
        $e = Effect::of($v);
        $this->assertSame(0, $e['late'], 'a task handed in and waiting for staff is not late');
        $this->assertSame(2, $e['done']);
        $this->assertTrue(Tasks::accept($this->takeId($ids[3], $v), 1.0, 1, 'Kemi Bello'));
        $e = Effect::of($v);
        $this->assertSame(100, $e['on_time'], 'finished before the due date: on time, though accepted after it');
    }

    // ── 2 ─────────────────────────────────────────────────────────────────────────────

    public function test_what_to_do_and_how_it_is_checked_reach_every_row_that_offers_the_task(): void
    {
        $v = $this->vol();
        $ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue(Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $ev, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $body = 'Type each page into the sheet <b>exactly</b> as printed.';
        $t = $this->task(['body' => $body, 'checked_note' => 'A lead reads 5 pages in 50', 'scope_kind' => 'event', 'scope_id' => $ev]);
        $esc = 'Type each page into the sheet &lt;b&gt;exactly&lt;/b&gt; as printed.';

        $lib = $this->page($v, 'tasks', 'library');
        $this->assertStringContainsString($esc, $lib, 'the library shows what to do, escaped');
        $this->assertStringNotContainsString('<b>exactly</b>', $lib);
        $this->assertStringContainsString('A lead reads 5 pages in 50', $lib);

        $_SESSION['user_id'] = (int) $v->user_id;
        $place = (string) $this->vctrl()->place((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/event/' . $ev . '/tasks'), new Response(), ['kind' => 'event', 'id' => $ev, 'page' => 'tasks'])->getBody();
        $this->assertStringContainsString($esc, $place, 'the place page shows it');

        $this->assertTrue(Tasks::take($v, $t)['ok']);
        $this->assertStringContainsString($esc, $this->page($v, 'tasks', 'mine'), 'so does Mine');
        $this->assertTrue(Tasks::accept($this->takeId($t, $v), 1.0, 1, 'Kemi Bello'));
        $this->assertStringContainsString('How it was checked: A lead reads 5 pages in 50', $this->page($v, 'tasks', 'done'), 'and Done says how it was checked');
    }

    // ── 3 and 7 ───────────────────────────────────────────────────────────────────────

    public function test_a_past_due_or_finished_task_is_refused_and_never_offered(): void
    {
        $v = $this->vol();
        $ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho ceremony',
            'event_date' => date('Y-m-d', strtotime('+10 days')), 'status' => 'published', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertTrue(Grants::grant('member', (int) $v->user_id, 'vol.door', 'event', $ev, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $late = $this->task(['title' => 'Late flyer run', 'scope_kind' => 'event', 'scope_id' => $ev]);
        DB::table('gates_vol_tasks')->where('id', $late)->update(['due_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $r = Tasks::take($v, $late);
        $this->assertFalse($r['ok'], 'a past-due task cannot be taken');
        $this->assertStringContainsString('due date has passed', (string) $r['error']);

        $done = $this->task(['title' => 'Finished door rota', 'scope_kind' => 'event', 'scope_id' => $ev]);
        $this->assertTrue(Tasks::take($v, $done)['ok']);
        $this->assertTrue(Tasks::accept($this->takeId($done, $v), 1.0, 1, 'Kemi Bello'));
        $r = Tasks::take($v, $done);
        $this->assertFalse($r['ok'], 'taking a finished task again is refused, not "it’s yours"');

        $_SESSION['user_id'] = (int) $v->user_id;
        $place = (string) $this->vctrl()->place((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/event/' . $ev . '/tasks'), new Response(), ['kind' => 'event', 'id' => $ev, 'page' => 'tasks'])->getBody();
        $this->assertStringNotContainsString('Late flyer run', $place, 'the place page does not list a past-due task');
        $this->assertStringContainsString('Finished door rota', $place);
        $this->assertStringNotContainsString('name="task" value="' . $done . '"', $place, 'and offers no Take it on a finished one');
        $this->assertStringNotContainsString('Late flyer run', $this->page($v, 'tasks', 'library'));
    }

    // ── 4 ─────────────────────────────────────────────────────────────────────────────

    public function test_staff_record_progress_edit_and_close_a_task(): void
    {
        $v = $this->vol();
        $t = $this->task(['target' => '58']);
        $this->assertTrue(Tasks::take($v, $t)['ok']);
        $k = $this->takeId($t, $v);

        $this->assertStringContainsString('Progress saved', $this->admin(['do' => 'progress', 'tab' => 'tasks', 'take' => $k, 'progress' => '41', 'accepted' => '40']));
        $this->assertStringContainsString('Progress saved', $this->admin(['do' => 'progress', 'tab' => 'tasks', 'take' => $k, 'progress' => '41', 'accepted' => '40']), 'saving the same figures again is not "not found"');
        $this->assertStringContainsString('41 of 58', $this->page($v, 'tasks', 'mine'), 'the volunteer’s bar moves before acceptance');
        $this->assertStringContainsString('name="do" value="progress"', $this->adminPage('tasks'));

        $typed = date('Y-m-d\TH:i', strtotime('+5 days')) . ':00';
        $this->assertStringContainsString('Saved', $this->admin(['do' => 'task_edit', 'tab' => 'tasks', 'task' => $t, 'title' => 'Digitise booklet one', 'body' => 'Pages 1 to 58.',
            'due_at' => $typed, 'target' => '58']));
        $row = DB::table('gates_vol_tasks')->where('id', $t)->first();
        $this->assertSame(['Digitise booklet one', 'Pages 1 to 58.'], [$row->title, $row->body]);
        $this->assertSame($typed, \AfricaGates\Support\DisplayTime::forInput((string) $row->due_at), 'the form shows back what was typed: saving unchanged moves nothing');
        $this->assertStringContainsString('future', $this->admin(['do' => 'task_edit', 'tab' => 'tasks', 'task' => $t, 'title' => 'X', 'due_at' => date('Y-m-d\TH:i', strtotime('-1 day'))]));

        $other = $this->vol('Bola Ade');
        $this->assertStringContainsString('Closed', $this->admin(['do' => 'task_close', 'tab' => 'tasks', 'task' => $t]));
        $this->assertSame('closed', DB::table('gates_vol_tasks')->where('id', $t)->value('status'));
        $this->assertNotContains($t, array_map(static fn ($x) => (int) $x->id, Tasks::library($other)), 'a closed task is not offered');
        $this->assertFalse(Tasks::take($other, $t)['ok']);
        $this->assertStringContainsString('Digitise booklet one', $this->adminPage('tasks'), 'still listed while somebody holds it');
        $this->assertTrue(Tasks::handIn($v, $t), 'work under way can still be handed in');
        $this->assertSame(3, DB::table('gates_audit_log')->where('action', 'like', 'volunteers.%')->whereIn('action', ['volunteers.progress', 'volunteers.task_edit', 'volunteers.task_close'])->distinct()->count('action'));
    }

    // ── 5 ─────────────────────────────────────────────────────────────────────────────

    public function test_new_tasks_that_fit_are_told_as_each_person_chose_and_a_held_task_is_reminded(): void
    {
        $posted = $this->vol('Ada Posted', ['notify_fit' => 'posted', 'from_home' => 1]);
        $weekly = $this->vol('Bola Weekly', ['notify_fit' => 'weekly', 'from_home' => 1]);
        $never = $this->vol('Chidi Never', ['notify_fit' => 'never', 'from_home' => 1]);
        $notHome = $this->vol('Dayo Away', ['notify_fit' => 'posted', 'from_home' => 0]);

        $this->task(['title' => 'Ring three nominees']);
        $this->task(['title' => 'Door rota', 'module_key' => 'door', 'from_home' => '']);
        // One posted last week, for the weekly email.
        $lastWeek = $this->task(['title' => 'Last week’s booklet']);
        DB::table('gates_vol_tasks')->where('id', $lastWeek)->update(['created_at' => date('Y-m-d 10:00:00', strtotime('monday this week') - 3 * 86400)]);

        $mail = [];
        $send = function (string $to, string $subject, string $html, string $plain) use (&$mail): bool { $mail[] = [$to, $subject, $plain]; return true; };
        Reminders::sweep(null, $send, 'https://example.test');
        $to = static fn (object $v) => (string) DB::table('gates_users')->where('id', $v->user_id)->value('email');
        $for = static fn (object $v) => array_values(array_filter($mail, static fn ($m) => $m[0] === $to($v)));

        $subjects = array_column($for($posted), 1);
        $this->assertContains('A new task for you: Ring three nominees', $subjects, 'when posted: each fitting task');
        $this->assertNotContains('A new task for you: Door rota', $subjects, 'not one they are untrained for');
        $this->assertSame([], array_values(array_filter(array_column($for($notHome), 1), static fn ($s) => str_contains($s, 'Ring three'))), 'from-home work only to those who take it');
        $this->assertSame([], $for($never), 'never means never');
        $w = $for($weekly);
        $this->assertCount(1, $w, 'weekly: one email');
        $this->assertStringContainsString('Last week’s booklet', $w[0][2]);
        $this->assertStringNotContainsString('Ring three nominees', $w[0][2], 'this week’s task waits for next week’s email');

        $n = count($mail);
        Reminders::sweep(null, $send, 'https://example.test');
        $this->assertCount($n, $mail, 'a second tick sends nothing');

        // A held task due within a day is reminded once; a handed-in one is not.
        $soon = $this->task(['title' => 'Flyers for Ikotun', 'due_at' => date('Y-m-d H:i:s', strtotime('+20 hours'))]);
        $soon2 = $this->task(['title' => 'Flyers for Egbeda', 'due_at' => date('Y-m-d H:i:s', strtotime('+20 hours'))]);
        $this->assertTrue(Tasks::take($never, $soon)['ok']);
        $this->assertTrue(Tasks::take($never, $soon2)['ok']);
        $this->assertTrue(Tasks::handIn($never, $soon2));
        $keys = array_column(Reminders::due(), 0);
        $this->assertContains('vol-taskdue:' . $this->takeId($soon, $never), $keys);
        $this->assertNotContains('vol-taskdue:' . $this->takeId($soon2, $never), $keys, 'handed in: nothing to remind');
    }

    // ── 6 ─────────────────────────────────────────────────────────────────────────────

    public function test_an_accepted_take_is_one_hours_row_however_often_it_is_accepted(): void
    {
        $v = $this->vol();
        $t = $this->task();
        $this->assertTrue(Tasks::take($v, $t)['ok']);
        $k = $this->takeId($t, $v);
        $this->assertTrue(Tasks::accept($k, 2.0, 1, 'Kemi Bello'));
        $this->assertFalse(Tasks::accept($k, 2.0, 1, 'Kemi Bello'), 'accepted once');
        $this->assertSame(1, DB::table('gates_vol_hours')->where('ref_kind', 'take')->where('ref_id', $k)->count());

        // The second lock is the database's: a second row for the same evidence is refused.
        $threw = false;
        try {
            DB::table('gates_vol_hours')->insert(['volunteer_id' => $v->id, 'source' => 'task', 'ref_kind' => 'take', 'ref_id' => $k, 'worked_on' => date('Y-m-d'),
                'what' => 'again', 'hours' => 2, 'status' => 'verified', 'created_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'gates_vol_hours (ref_kind, ref_id) is UNIQUE');
    }

    public function test_the_accept_update_carries_the_status_it_expects(): void
    {
        // A compare-and-set, read off the source: the UPDATE that moves a take to done must carry
        // its own status predicate, or two presses that both read "taken" both pay.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Volunteer/Tasks.php');
        preg_match('~function accept\(.*?\n    \}~s', $src, $m);
        $this->assertNotEmpty($m);
        $this->assertMatchesRegularExpression("~->where\('id', \\\$takeId\)->whereIn\('status', self::ON_THE_GO\)->update\(~", $m[0]);
        $this->assertStringContainsString('if ($moved !== 1) return false;', $m[0]);
    }

    public function test_the_repair_keeps_the_earliest_of_a_duplicate_and_declines_the_rest(): void
    {
        $v = $this->vol();
        \AfricaGates\Support\SchemaIndex::drop('gates_vol_hours', 'uq_volhours_ref');
        $row = ['volunteer_id' => $v->id, 'source' => 'task', 'ref_kind' => 'take', 'ref_id' => 777, 'worked_on' => date('Y-m-d'), 'what' => 'Booklet',
                'hours' => 2, 'status' => 'verified', 'created_at' => date('Y-m-d H:i:s')];
        $first = (int) DB::table('gates_vol_hours')->insertGetId($row);
        $second = (int) DB::table('gates_vol_hours')->insertGetId($row);
        ob_start();
        require dirname(__DIR__, 2) . '/database/migrations/2027_05_03_vol_task_handin.php';
        ob_end_clean();
        $this->assertTrue(\AfricaGates\Support\SchemaIndex::exists('gates_vol_hours', 'uq_volhours_ref'));
        $this->assertSame('verified', DB::table('gates_vol_hours')->where('id', $first)->value('status'), 'the earliest stands');
        $dup = DB::table('gates_vol_hours')->where('id', $second)->first();
        $this->assertSame(['declined', null], [(string) $dup->status, $dup->ref_kind]);
        $this->assertStringContainsString('Counted twice', (string) $dup->reason);
    }
}
