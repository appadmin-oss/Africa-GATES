<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventPlan\Changes;
use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\Goals;
use AfricaGates\Services\EventPlan\Pipeline;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\Planner;
use AfricaGates\Services\EventPlan\Rules;
use AfricaGates\Services\EventPlan\Setup;
use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * GOALS THAT COUNT THEMSELVES, AND WHAT KEEPS THE PLAN HONEST (owner, 5 Nov 2026, "Build this
 * out", against the design plan's acceptance tests):
 *
 *   AT-1  a goal with no metric or deadline        → refused, with what it needs
 *   gates sign off on the data; a mismatch is refused, never corrected
 *   back-solving re-solves when an assumption changes
 *   the pipeline: "nominated" only from a submitted form; one person, one row
 *   AT-2  a change routes by its tier; small ones lapse; large ones are an owner's, never the asker's
 *   AT-11 a rule starts in shadow and changes nothing; switched on, it acts
 *   setup reads a plain sentence, and asks only what it could not find
 */
final class EventGoalsTest extends TestCase
{
    private int $lead;
    private int $vol;
    private int $ev;
    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = $this->member('Tunde Akin');
        $this->vol = $this->member('Chika Nwosu');
        $now = date('Y-m-d H:i:s');
        $this->ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho Honours',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+40 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'created_at' => $now]);
        $prog = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'tg-' . bin2hex(random_bytes(3)), 'title' => 'Teaching Award', 'is_active' => 1, 'created_at' => $now]);
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $prog, 'year' => 2026, 'status' => 'nominations', 'created_at' => $now]);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cycle, 'slug' => 'primary', 'title' => 'Primary', 'sort_order' => 0]);
        DB::table('gates_event_programmes')->insert(['event_id' => $this->ev, 'programme_id' => $prog]);
        Grants::grant('member', $this->lead, 'event.lead', 'event', $this->ev, date('Y-m-d', strtotime('+60 days')), 1);
        Grants::grant('member', $this->vol, 'vol.door', 'event', $this->ev, date('Y-m-d', strtotime('+60 days')), 1);
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function nominate(string $email, string $phone = ''): void
    {
        DB::table('gates_nominations')->insert(['cycle_id' => DB::table('gates_award_categories')->where('id', $this->cat)->value('cycle_id'), 'category_id' => $this->cat,
            'nominee_name' => 'Somebody', 'nominee_email' => $email, 'nominee_phone' => $phone, 'nominator_name' => 'N', 'nominator_email' => 'n@example.test',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function goal(array $in = []): int
    {
        $r = Goals::create($this->ev, $in + ['metric' => 'nominations', 'target' => '60', 'deadline' => date('Y-m-d', strtotime('+30 days')), 'scope' => 'category:' . $this->cat], $this->lead);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) $r['id'];
    }

    public function test_a_goal_without_its_metric_target_and_deadline_is_refused_with_what_it_needs(): void
    {
        $r = Goals::create($this->ev, ['metric' => '', 'target' => '', 'deadline' => ''], $this->lead);
        $this->assertFalse($r['ok']);
        foreach (['what should be counted', 'how many', 'by when'] as $q) $this->assertStringContainsString($q, $r['error']);
        $this->assertSame(0, DB::table('gates_event_goals')->count(), 'nothing half-saved');
        $this->assertFalse(Goals::create($this->ev, ['metric' => 'nominations', 'target' => '5', 'deadline' => '2027-01-01'], $this->vol)['ok'], 'a lead sets goals');
    }

    public function test_progress_counts_itself_and_a_gate_signs_off_only_on_the_data(): void
    {
        $g = $this->goal(['target' => '2']);
        $this->assertSame(0, Goals::find($g)->value);
        $this->nominate('a@example.test');
        $this->assertSame(1, Goals::find($g)->value, 'a submitted nomination moves the count — nobody typed it');
        $gate = DB::table('gates_event_gates')->where('goal_id', $g)->orderByDesc('due_at')->first();
        $r = Goals::signGate((int) $gate->id, $this->lead, 'passed', '');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('1 of 2', $r['error'], 'the mismatch is shown, not corrected');
        $this->nominate('b@example.test');
        $this->assertTrue(Goals::signGate((int) $gate->id, $this->lead, 'passed', '')['ok']);
        $this->assertSame(2, (int) DB::table('gates_event_gates')->where('id', $gate->id)->value('signed_value'), 'signed with the count as it stood');
    }

    public function test_a_money_goal_back_solves_and_re_solves_when_a_number_changes(): void
    {
        $g = $this->goal(['metric' => 'revenue', 'target' => '15M', 'assume' => ['price' => '200']]);
        $steps = Planner::solve(Goals::find($g));
        $this->assertStringContainsString('75,000', $steps[0]['v'], '₦15M at ₦200 a vote');
        $this->assertSame(3, DB::table('gates_event_gates')->where('goal_id', $g)->count(), 'one gate per week of the curve');
        $this->assertSame('owner', DB::table('gates_event_gates')->where('goal_id', $g)->value('approver'), 'a money gate is an owner’s');
        $a = Planner::assumptions($g)['curve'];
        $this->assertFalse(Planner::setAssumption((int) $a->id, '50,10', $this->lead)['ok'], 'shares must add to 100');
        $this->assertTrue(Planner::setAssumption((int) $a->id, '50,50', $this->lead)['ok']);
        $this->assertSame(2, DB::table('gates_event_gates')->where('goal_id', $g)->count(), 'the open gates re-drafted from it');
    }

    public function test_the_pipeline_moves_to_nominated_only_on_a_submitted_form_and_holds_one_row_a_person(): void
    {
        $id = (int) Pipeline::add($this->ev, ['name' => 'Ngozi Eze', 'email' => 'Ngozi@Example.test', 'phone' => '0803 123 4567'], $this->vol)['id'];
        $this->assertStringContainsString('Already in the pipeline', Pipeline::add($this->ev, ['name' => 'N. Eze', 'phone' => '+234 803 123 4567'], $this->lead)['error']);
        $this->assertFalse(Pipeline::move($id, 'nominated', $this->vol)['ok'], 'never by hand');
        $this->assertTrue(Pipeline::move($id, 'yes', $this->vol)['ok']);
        $this->nominate('ngozi@example.test');
        $this->assertCount(1, Pipeline::signal($this->ev));
        $this->assertSame('nominated', DB::table('gates_event_contacts')->where('id', $id)->value('stage'));
        Pipeline::rate($id, 3, $this->vol);
        $other = $this->member('Ada Obi');
        Grants::grant('member', $other, 'vol.usher', 'event', $this->ev, date('Y-m-d', strtotime('+60 days')), 1);
        $this->assertNull(Pipeline::list($this->ev, $other)[0]->reach, 'the reach rating is private');
        $this->assertSame(3, (int) Pipeline::list($this->ev, $this->lead)[0]->reach, 'a lead sees it');
        $r = Pipeline::import($this->ev, "Bola Ade, bola@example.test\nNgozi, ngozi@example.test\n\n", $this->lead);
        $this->assertStringContainsString('1 added, 1 already there', $r['msg']);
    }

    public function test_changes_route_by_tier_small_ones_lapse_and_large_ones_are_never_the_askers(): void
    {
        $w = (int) Plan::addWork($this->ev, ['title' => 'Venue contract', 'owner' => 'member:' . $this->vol], $this->lead)['id'];
        $small = Changes::propose('reword', $w, 'Signed venue contract', '', $this->vol);
        $this->assertStringContainsString('applies itself', $small['msg']);
        $this->assertSame([], Changes::lapse($this->ev, true), 'not before its time');
        DB::table('gates_event_changes')->where('id', $small['id'])->update(['lapse_at' => date('Y-m-d H:i:s', time() - 60)]);
        Changes::lapse($this->ev, true);
        $this->assertSame('Signed venue contract', DB::table('gates_event_work')->where('id', $w)->value('title'));
        $medium = Changes::propose('due_shift', $w, date('Y-m-d', strtotime('+9 days')), 'Venue slow', $this->vol);
        $this->assertFalse(Changes::decide($medium['id'], $this->vol, 'approve', '')['ok'], 'a lead decides a medium one');
        $this->assertTrue(Changes::decide($medium['id'], $this->lead, 'approve', '')['ok']);
        $g = $this->goal();
        $large = Changes::propose('target', $g, '80', 'More categories', $this->lead);
        $this->assertTrue($large['ok']);
        $this->assertSame('large', DB::table('gates_event_changes')->where('id', $large['id'])->value('tier'));
        $this->assertFalse(Changes::decide($large['id'], $this->lead, 'approve', '')['ok'], 'not the lead who asked');
        $this->assertSame(60, (int) DB::table('gates_event_goals')->where('id', $g)->value('target'), 'unchanged until decided');
        $this->assertTrue(Changes::decide($large['id'], 0, 'approve', 'Staff', true)['ok']);
        $this->assertSame(80, (int) DB::table('gates_event_goals')->where('id', $g)->value('target'));
    }

    public function test_a_rule_starts_in_shadow_and_changes_nothing_then_acts_when_switched_on(): void
    {
        $id = (int) Pipeline::add($this->ev, ['name' => 'Kemi', 'email' => 'kemi@example.test'], $this->vol)['id'];
        DB::table('gates_event_contacts')->where('id', $id)->update(['stage' => 'contacted']);
        $this->nominate('kemi@example.test');
        $this->assertSame('shadow', Rules::forEvent($this->ev)['stage_move']->mode);
        Rules::run($this->ev);
        $this->assertSame('contacted', DB::table('gates_event_contacts')->where('id', $id)->value('stage'), 'shadow changes nothing');
        $this->assertStringStartsWith('Would have:', (string) DB::table('gates_event_rule_log')->where('subject', 'contact:' . $id)->value('did'));
        $this->assertFalse(Rules::setMode($this->ev, 'stage_move', 'active', $this->vol)['ok'], 'a lead switches rules');
        Rules::setMode($this->ev, 'stage_move', 'active', $this->lead);
        DB::table('gates_event_rule_log')->delete();
        Rules::run($this->ev);
        $this->assertSame('nominated', DB::table('gates_event_contacts')->where('id', $id)->value('stage'));
        Rules::setMode($this->ev, 'all', 'paused', $this->lead);
        $this->assertSame(0, DB::table('gates_event_rules')->where('event_id', $this->ev)->where('mode', '!=', 'paused')->count(), 'one switch pauses every rule');
    }

    public function test_setup_reads_a_plain_sentence_and_asks_only_what_it_could_not_find(): void
    {
        $r = Setup::read($this->ev, '₦15M from paid votes by 6 December at ₦200 a vote');
        $this->assertSame(['revenue', 15_000_000, 200], [$r['metric'], $r['target'], $r['price']]);
        $this->assertSame('12-06', substr((string) $r['deadline'], 5));
        $r = Setup::read($this->ev, '60 nominees for Primary');
        $this->assertSame(['nominees', 60, 'category:' . $this->cat], [$r['metric'], $r['target'], $r['scope']]);
        $this->assertSame(['deadline'], $r['ask']);
        $this->assertSame(['metric', 'target', 'deadline'], Setup::read($this->ev, 'make it great')['ask'], 'no guess: questions');
    }

    public function test_the_goals_and_rules_pages_draw_for_a_lead(): void
    {
        $this->goal();
        $e = Plan::event($this->ev);
        $me = ['id' => $this->lead, 'name' => 'Tunde Akin'];
        foreach ([['goals', 'progress'], ['goals', 'numbers'], ['goals', 'pipeline'], ['goals', 'setup'], ['rules', 'rules'], ['rules', 'log'], ['rules', 'changes']] as [$p, $t]) {
            $c = EventPages::page($me, $e, $p, $t, ['say' => '60 nominees by 15 December']);
            $this->assertNotEmpty($c['secs'], "$p/$t");
        }
    }
}
