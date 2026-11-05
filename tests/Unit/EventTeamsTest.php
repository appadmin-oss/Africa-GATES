<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\LeadController;
use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\EventPermissions;
use AfricaGates\Services\EventPlan\Leads;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\TeamPages;
use AfricaGates\Services\EventPlan\Teams;
use AfricaGates\Services\EventPlan\TeamSuggest;
use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * AN EVENT'S OWNER, TEAMS AND CUSTOM ROLES (owner, 3 Nov 2026), driven through the real lead
 * console and read back from the records.
 *
 * The promises: nothing gives anybody access until an OWNER who did not propose it approves it;
 * a role does exactly what its ticks from the fixed menu say — on every write path, whatever a
 * crafted post names — and never gives access; a role's money ceiling is never above the lead's;
 * the console a role opens shows only its own pages; and the assistant is a suggestion that
 * works with no provider at all.
 */
final class EventTeamsTest extends TestCase
{
    private int $lead;
    private int $owner;
    private int $ev;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::resetRules();
        $this->lead = $this->member('Tunde Akin');
        $this->owner = $this->member('Ngozi Eze');
        $this->ev = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'ev-' . bin2hex(random_bytes(3)), 'title' => 'Alimosho award ceremony',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+10 days')), 'venue' => 'Civic Centre', 'status' => 'published', 'capacity' => 800, 'created_at' => date('Y-m-d H:i:s')]);
        $this->give('event.lead', $this->lead);
        $this->give('event.own', $this->owner);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['lead_toast'], $_SESSION['lead_error'], $_SESSION[TeamPages::SESSION]);
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    private function member(string $name, int $verified = 1): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => $verified, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function email(int $id): string
    {
        return (string) DB::table('gates_users')->where('id', $id)->value('email');
    }

    private function give(string $cap, int $who): void
    {
        $this->assertTrue(Grants::grant('member', $who, $cap, 'event', $this->ev, date('Y-m-d', strtotime('+40 days')), 1)['ok']);
    }

    private function ctrl(string $class = LeadController::class): object
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get($class);
    }

    /** POST as somebody; returns the sentence they were shown. */
    private function post(int $as, array $body): string
    {
        $_SESSION['user_id'] = $as;
        unset($_SESSION['lead_toast'], $_SESSION['lead_error']);
        $this->ctrl()->act((new ServerRequestFactory())->createServerRequest('POST', '/lead/act')->withParsedBody($body + ['back' => '/lead']), new Response());
        return (string) ($_SESSION['lead_error'] ?? $_SESSION['lead_toast'] ?? '');
    }

    private function refused(int $as, array $body): string
    {
        $msg = $this->post($as, $body);
        $this->assertArrayHasKey('lead_error', $_SESSION, 'refused: ' . json_encode($body) . ' → ' . $msg);
        return $msg;
    }

    /** GET an event page as somebody; [status, html]. A page that is not theirs is a 404. */
    private function get(int $as, string $page = 'today', array $q = []): array
    {
        $_SESSION['user_id'] = $as;
        $_SERVER['REQUEST_URI'] = '/lead/event/' . $this->ev;
        try {
            $res = $this->ctrl()->event((new ServerRequestFactory())->createServerRequest('GET', '/lead')->withQueryParams($q), new Response(), ['id' => $this->ev, 'page' => $page]);
            return [$res->getStatusCode(), (string) $res->getBody()];
        } catch (HttpNotFoundException) {
            return [404, ''];
        }
    }

    /** A role, approved, with somebody approved into it; returns [roleId, memberUserId]. */
    /**
     * Access follows the decision that WON the guarded write, never precedes it. Given first,
     * an approval racing a refusal could mail somebody "you are in" over a row that ended up
     * refused. A failure to give access hands the decision back rather than leaving
     * "approved" over a person who holds nothing.
     */
    public function test_access_is_given_only_by_a_decision_that_was_recorded(): void
    {
        $this->post($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Usher', 'kind' => 'staff', 'perms' => ['show.run']]);
        $role = (int) DB::table('gates_event_roles')->value('id');
        $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => 'Needed', 'perms' => ['show.run']]);
        $who = $this->member('Bisi Lawal');
        $this->post($this->lead, ['do' => 'member_propose', 'event' => $this->ev, 'email' => $this->email($who), 'role' => $role, 'team_role' => 'member']);
        $m = (int) DB::table('gates_event_team_members')->value('id');

        // Another owner's refusal lands while this approval is in flight — modelled by a
        // trigger that refuses the row the moment anything gives access, but only while it
        // is still waiting. Claimed first, the row is no longer waiting and the trigger never
        // fires; given first, the refusal wins and the access already given is orphaned.
        if (DB::connection()->getDriverName() !== 'sqlite') $this->markTestSkipped('the race is modelled with a SQLite trigger');
        foreach (['gates_grants', 'gates_role_invites'] as $i => $tbl) {
            DB::statement("CREATE TRIGGER race_$i AFTER INSERT ON $tbl WHEN (SELECT status FROM gates_event_team_members WHERE id = $m) = 'proposed'
                BEGIN UPDATE gates_event_team_members SET status = 'refused' WHERE id = $m; END");
        }
        $grantsBefore = DB::table('gates_grants')->where('actor_id', $who)->count();
        try {
            $r = Teams::decide('member', $m, 'approve', 'Known', ['kind' => 'admin', 'id' => 1, 'name' => 'Staff']);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS race_0');
            DB::statement('DROP TRIGGER IF EXISTS race_1');
        }
        $row = DB::table('gates_event_team_members')->where('id', $m)->first();
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('approved', $row->status, 'access was given by a decision that did not stand');
        $this->assertTrue($row->grant_id !== null || $row->invite_id !== null, 'what was given is recorded on the row that gave it');
        $this->assertGreaterThanOrEqual($grantsBefore, DB::table('gates_grants')->where('actor_id', $who)->count());

        // End it to start clean for the failure case.
        DB::table('gates_event_team_members')->where('id', $m)->update(['status' => 'proposed', 'grant_id' => null, 'invite_id' => null, 'decided_at' => null]);

        // Giving access fails: the row goes back to waiting, holding nothing.
        DB::table('gates_event_team_members')->where('id', $m)->update(['status' => 'proposed']);
        DB::table('gates_site_events')->where('id', $this->ev)->delete();
        $r = Teams::decide('member', $m, 'approve', 'Checked', ['kind' => 'admin', 'id' => 1, 'name' => 'Staff']);
        $this->assertFalse($r['ok']);
        $row = DB::table('gates_event_team_members')->where('id', $m)->first();
        $this->assertSame('proposed', $row->status);
        $this->assertNull($row->decided_at);
        $this->assertNull($row->grant_id);
        $this->assertNull($row->invite_id);
    }

    private function roleHolder(array $perms, string $ceiling = '', string $kind = 'staff', string $name = 'Hospitality lead', ?string $leadWith = null): array
    {
        $msg = $this->post($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => $name, 'description' => 'Looks after guests',
            'kind' => $kind, 'perms' => $perms, 'ceiling' => $ceiling] + ($leadWith !== null ? ['lead_with' => explode(',', $leadWith)] : []));
        $this->assertStringContainsString('Proposed', $msg);
        $role = (int) DB::table('gates_event_roles')->where('name', $name)->max('id');
        $this->assertStringContainsString('Approved', $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role,
            'verdict' => 'approve', 'reason' => 'Fits the night', 'perms' => $perms, 'ceiling' => $ceiling]));
        $who = $this->member('Ada Obi ' . bin2hex(random_bytes(2)));
        $this->post($this->lead, ['do' => 'member_propose', 'event' => $this->ev, 'email' => $this->email($who), 'role' => $role, 'team_role' => 'member']);
        $m = (int) DB::table('gates_event_team_members')->where('role_id', $role)->max('id');
        $this->assertStringContainsString('Approved', $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'member', 'id' => $m, 'verdict' => 'approve', 'reason' => 'Known to us']));
        return [$role, $who];
    }

    /** Everything a console write can touch at this event, as one fingerprint. */
    private function fingerprint(): string
    {
        $out = [];
        foreach (['gates_event_work', 'gates_event_risks', 'gates_event_budget', 'gates_event_decisions', 'gates_event_show', 'gates_event_sponsors', 'gates_event_checks',
                  'gates_event_tables', 'gates_event_seats', 'gates_requests', 'gates_grants', 'gates_role_invites', 'gates_event_teams', 'gates_event_roles',
                  'gates_event_team_members', 'gates_event_scan_passes', 'gates_site_events'] as $t) {
            if (DB::schema()->hasTable($t)) $out[$t] = DB::table($t)->orderBy('id')->get()->all();
        }
        return md5(json_encode($out));
    }

    // ── the tests ───────────────────────────────────────────────────────────────────────

    public function test_the_migration_builds_all_three_tables_with_varchar_statuses(): void
    {
        foreach (['gates_event_teams' => ['parent_id', 'status', 'proposed_id', 'decided_id', 'reason'],
                  'gates_event_roles' => ['perms_json', 'proposed_json', 'spend_ceiling_minor', 'lead_with', 'review_json', 'kind', 'status'],
                  'gates_event_team_members' => ['email', 'role_id', 'team_role', 'grant_id', 'invite_id', 'until_at', 'status']] as $t => $cols) {
            $this->assertTrue(DB::schema()->hasTable($t), $t);
            foreach ($cols as $c) $this->assertTrue(DB::schema()->hasColumn($t, $c), "$t.$c");
        }
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2027_05_07_event_teams.php');
        $this->assertStringNotContainsString('ENUM(', $body, 'a status is VARCHAR: an ENUM truncates a word added later on MySQL');
        $this->assertStringNotContainsString('CREATE INDEX', $body, 'indexes go through SchemaIndex::ensure');
    }

    public function test_nothing_gives_access_until_an_owner_approves_it(): void
    {
        $this->post($this->lead, ['do' => 'team_propose', 'event' => $this->ev, 'name' => 'Guests and hospitality', 'purpose' => 'The welcome']);
        $team = (int) DB::table('gates_event_teams')->value('id');
        $this->post($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Hospitality lead', 'team' => $team, 'kind' => 'staff', 'perms' => ['guests.manage', 'show.run']]);
        $role = (int) DB::table('gates_event_roles')->value('id');
        $ada = $this->member('Ada Obi');
        $this->post($this->lead, ['do' => 'member_propose', 'event' => $this->ev, 'email' => $this->email($ada), 'role' => $role, 'team' => $team, 'team_role' => 'lead']);
        $m = (int) DB::table('gates_event_team_members')->value('id');

        $this->assertSame(0, DB::table('gates_grants')->where('actor_id', $ada)->count(), 'proposed is nothing');
        $this->assertSame(404, $this->get($ada)[0], 'and the event does not exist for them');
        $this->assertStringContainsString('Decide the role first', $this->refused($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'member', 'id' => $m, 'verdict' => 'approve', 'reason' => 'ok']));
        $this->assertStringContainsString('Give a reason', $this->refused($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => '', 'perms' => ['show.run']]));
        $this->assertStringContainsString('Only an owner', $this->refused($this->lead, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => 'x', 'perms' => ['show.run']]),
            'a lead never decides');
        $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'team', 'id' => $team, 'verdict' => 'approve', 'reason' => 'Needed']);
        $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => 'Seating only', 'perms' => ['guests.manage']]);
        $this->assertSame(['guests.manage'], EventPermissions::ticks((string) DB::table('gates_event_roles')->where('id', $role)->value('perms_json')), 'the owner set the ticks');
        $this->assertSame(['guests.manage', 'show.run'], EventPermissions::ticks((string) DB::table('gates_event_roles')->where('id', $role)->value('proposed_json')), 'and what was asked is kept');
        $this->assertSame(0, DB::table('gates_grants')->where('actor_id', $ada)->count(), 'an approved role still gives nobody access');
        $this->assertStringContainsString('Approved', $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'member', 'id' => $m, 'verdict' => 'approve', 'reason' => 'Known to us']));

        $g = DB::table('gates_grants')->where('actor_id', $ada)->first();
        $this->assertSame('event.role', $g->capability);
        $this->assertNotNull($g->expires_at, 'every grant ends');
        $this->assertSame((int) $g->id, (int) DB::table('gates_event_team_members')->where('id', $m)->value('grant_id'));
        $st = EventPermissions::standing($ada, $this->ev);
        $this->assertSame(['guests.manage'], $st['perms']);
        $this->assertSame([$team], $st['teamLeadOf'], 'a team lead of their team');
        $this->assertSame(200, $this->get($ada)[0]);
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'lead.decide')->where('meta', 'like', '%changed%')->count(), 'the change of ticks is audited as a change, with its reason');
    }

    public function test_nobody_decides_their_own_proposal_or_their_own_access(): void
    {
        // A second person who both leads and owns: still two people.
        $both = $this->member('Femi Ade');
        $this->give('event.lead', $both);
        $this->give('event.own', $both);
        $this->post($both, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Stage manager', 'kind' => 'staff', 'perms' => ['show.run']]);
        $role = (int) DB::table('gates_event_roles')->value('id');
        $this->assertStringContainsString('You proposed this', $this->refused($both, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => 'mine', 'perms' => ['show.run']]));
        $this->assertSame('proposed', DB::table('gates_event_roles')->where('id', $role)->value('status'));
        $this->assertStringContainsString('Approved', $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => 'Second pair of eyes', 'perms' => ['show.run']]));
        // The owner is put in a role by the lead: the owner cannot approve their own access.
        $this->post($this->lead, ['do' => 'member_propose', 'event' => $this->ev, 'email' => $this->email($this->owner), 'role' => $role, 'team_role' => 'member']);
        $m = (int) DB::table('gates_event_team_members')->value('id');
        $this->assertStringContainsString('Nobody approves access for themselves', $this->refused($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'member', 'id' => $m, 'verdict' => 'approve', 'reason' => 'me']));
        // Staff are the other person.
        $r = Teams::decide('member', $m, 'approve', 'Checked by staff', ['kind' => 'admin', 'id' => 1, 'name' => 'Staff']);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue(Grants::allows('member', $this->owner, 'event.role', 'event', $this->ev));
    }

    public function test_a_role_does_only_what_it_is_ticked_for_on_every_write_path(): void
    {
        // The lead leaves one row of everything a write can name.
        $this->post($this->lead, ['do' => 'work_add', 'event' => $this->ev, 'title' => 'Floor plan']);
        $this->post($this->lead, ['do' => 'risk_add', 'event' => $this->ev, 'title' => 'Rain', 'likelihood' => 'possible', 'impact' => 'high']);
        $this->post($this->lead, ['do' => 'line_add', 'event' => $this->ev, 'title' => 'Chairs', 'estimate' => '10000']);
        $this->post($this->lead, ['do' => 'decision_add', 'event' => $this->ev, 'title' => 'Doors at 17:00']);
        $this->post($this->lead, ['do' => 'cue_add', 'event' => $this->ev, 'at' => '18:30', 'title' => 'Welcome']);
        $this->post($this->lead, ['do' => 'sponsor_add', 'event' => $this->ev, 'name' => 'Bank']);
        $this->post($this->lead, ['do' => 'check_add', 'event' => $this->ev, 'title' => 'Exits clear']);
        $ids = ['work' => DB::table('gates_event_work')->value('id'), 'risk' => DB::table('gates_event_risks')->value('id'), 'line' => DB::table('gates_event_budget')->value('id'),
                'decision' => DB::table('gates_event_decisions')->value('id'), 'cue' => DB::table('gates_event_show')->value('id'),
                'sponsor' => DB::table('gates_event_sponsors')->value('id'), 'check' => DB::table('gates_event_checks')->value('id')];
        foreach ($ids as $k => $v) $this->assertNotNull($v, "the lead's $k exists");

        [, $ada] = $this->roleHolder(['show.run']);
        $before = $this->fingerprint();
        $base = ['event' => $this->ev, 'title' => 'Sneaky', 'name' => 'Sneaky', 'why' => 'because', 'status' => 'done', 'estimate' => '5', 'stage' => 'paid',
                 'likelihood' => 'likely', 'impact' => 'high', 'committed' => '5', 'paid' => '5', 'budget' => '1', 'series' => 'x', 'type' => 'spend',
                 'closes_at' => date('Y-m-d', strtotime('+10 days')) . 'T23:00:00', 'email' => 'x@example.test', 'capability' => 'vol.door', 'reason' => 'x',
                 'subject' => 'role', 'id' => 1, 'verdict' => 'approve', 'perms' => ['money.approve'], 'role' => 1, 'member' => 1, 'audience' => 'tickets',
                 'subject_line' => 'x', 'body' => 'x', 'vol' => 1, 'hour' => 1, 'table' => 1, 'kind' => 'staff', 'pass' => 1] + $ids;
        $tried = 0;
        foreach (EventPermissions::MENU as $perm => $m) {
            if ($perm === 'show.run') continue;
            foreach ($m['acts'] as $do) {
                if ($do === 'request_act' || $do === 'request_remind') continue;   // answered by Requests: only the asker's own request, held in LeadConsoleTest
                $this->refused($ada, ['do' => $do] + $base);
                $tried++;
            }
        }
        foreach (array_unique(array_merge(EventPermissions::LEAD_ACTS, EventPermissions::OWNER_ACTS)) as $do) {
            $this->refused($ada, ['do' => $do] + $base);
            $tried++;
        }
        $this->assertGreaterThan(30, $tried);
        $this->assertSame($before, $this->fingerprint(), 'a role without the tick wrote nothing, on any path');
        // And the one it holds works.
        $this->assertStringContainsString('Added to the running order', $this->post($ada, ['do' => 'cue_add', 'event' => $this->ev, 'at' => '19:00', 'title' => 'Awards']));
    }

    public function test_a_custom_role_can_never_give_access_or_make_a_lead_or_an_owner(): void
    {
        foreach (EventPermissions::MENU as $k => $m) {
            $this->assertSame([], array_intersect($m['acts'], EventPermissions::LEAD_ACTS, EventPermissions::OWNER_ACTS), "$k opens nothing that gives access");
            $this->assertSame([], array_intersect($m['acts'], array_merge(EventPermissions::LEAD_ACTS, EventPermissions::OWNER_ACTS)), "$k opens nothing that gives access");
        }
        $this->assertStringContainsString('not a permission this platform can enforce',
            $this->refused($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Gatekeeper', 'kind' => 'staff', 'perms' => ['show.run', 'access.grant']]));
        $this->assertSame(0, DB::table('gates_event_roles')->count(), 'an invented permission is refused, not stored');
        // Everything ticked: still no way to give access.
        [, $ada] = $this->roleHolder(array_keys(EventPermissions::MENU), '1000', 'staff', 'Everything');
        $leadBefore = DB::table('gates_grants')->whereIn('capability', ['event.lead', 'event.own'])->count();
        $this->refused($ada, ['do' => 'vol_invite', 'event' => $this->ev, 'email' => 'friend@example.test', 'capability' => 'vol.door']);
        $this->refused($ada, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Mine', 'kind' => 'staff', 'perms' => ['show.run']]);
        $this->refused($ada, ['do' => 'member_propose', 'event' => $this->ev, 'email' => 'friend@example.test', 'role' => 1]);
        $this->assertFalse(EventPermissions::standing($ada, $this->ev)['lead']);
        $this->assertSame($leadBefore, DB::table('gates_grants')->whereIn('capability', ['event.lead', 'event.own'])->count());
        $this->assertSame(0, DB::table('gates_role_invites')->where('email', 'friend@example.test')->count());
    }

    public function test_a_roles_money_ceiling_is_its_own_and_never_above_the_leads(): void
    {
        $limit = Plan::limitMinor();
        $this->assertStringContainsString('above the lead’s own limit', $this->refused($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Treasurer', 'kind' => 'staff',
            'perms' => ['money.view', 'money.approve'], 'ceiling' => (string) (intdiv($limit, 100) + 1)]));
        $this->assertStringContainsString('Say the most', $this->refused($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Treasurer', 'kind' => 'staff', 'perms' => ['money.approve']]));
        [, $ada] = $this->roleHolder(['money.view', 'money.approve'], '50000', 'staff', 'Treasurer');
        $this->post($this->lead, ['do' => 'line_add', 'event' => $this->ev, 'title' => 'Chairs', 'estimate' => '40000']);
        $this->post($this->lead, ['do' => 'line_add', 'event' => $this->ev, 'title' => 'Stage', 'estimate' => '60000']);
        [$small, $big] = DB::table('gates_event_budget')->orderBy('id')->pluck('id')->all();
        $this->assertStringContainsString('over your limit of ₦50,000', $this->refused($ada, ['do' => 'line_approve', 'line' => $big]));
        $this->assertSame('Approved.', $this->post($ada, ['do' => 'line_approve', 'line' => $small]));
        $this->assertSame('Approved.', $this->post($this->lead, ['do' => 'line_approve', 'line' => $big]), 'the lead’s own limit still applies to the lead');
        $this->assertSame(50000 * 100, EventPermissions::ceiling($ada, $this->ev));
        // The owner lowering the platform limit below the role's ceiling lowers the role too.
        DB::table('gates_settings')->insert(['key_name' => 'lead_spend_limit_naira', 'value' => '20000']);
        Plan::resetRules();
        $this->assertSame(20000 * 100, EventPermissions::ceiling($ada, $this->ev));
    }

    public function test_a_roles_console_shows_only_its_own_pages_in_the_order_it_leads_with(): void
    {
        [, $ada] = $this->roleHolder(['guests.manage', 'show.run'], '', 'staff', 'Hospitality lead', 'night,people');
        [$code, $html] = $this->get($ada);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Where your role works', $html);
        $cards = substr($html, (int) strpos($html, 'Where your role works'));
        $this->assertLessThan(strpos($cards, '/lead/event/' . $this->ev . '/people'), strpos($cards, '/lead/event/' . $this->ev . '/night'), 'The night first, as the role leads with it');
        foreach (['/money', '/plan', '/crm', '/settings', '/roles', '/reports'] as $p) {
            $this->assertStringNotContainsString('/lead/event/' . $this->ev . $p, $html, "$p is not in their rail");
        }
        $this->assertSame(404, $this->get($ada, 'money')[0], 'and not at its address either');
        $this->assertSame(404, $this->get($ada, 'roles')[0]);
        [, $people] = $this->get($ada, 'people');
        $this->assertStringContainsString('tab=seating', $people . ' ' . $this->get($ada, 'people', ['tab' => 'seating'])[1]);
        $this->assertStringNotContainsString('tab=volunteers', $people, 'a tab their ticks do not open is not drawn');
        [, $night] = $this->get($ada, 'night');
        $this->assertStringContainsString('Add a cue', $night);
        $this->assertStringNotContainsString('tab=door', $night);
        // The lead still sees the whole console.
        [, $lead] = $this->get($this->lead);
        foreach (['/money', '/plan', '/roles', '/settings'] as $p) $this->assertStringContainsString('/lead/event/' . $this->ev . $p, $lead);
    }

    public function test_an_owner_sees_every_page_and_runs_none_of_it(): void
    {
        foreach (EventPages::NAV as [, $pages]) {
            foreach (array_keys($pages) as $p) $this->assertSame(200, $this->get($this->owner, $p)[0], "the owner opens $p");
        }
        [, $plan] = $this->get($this->owner, 'plan');
        $this->assertStringNotContainsString('name="do" value="work_add"', $plan, 'no form the owner cannot use');
        $this->refused($this->owner, ['do' => 'work_add', 'event' => $this->ev, 'title' => 'X']);
        $this->assertSame(0, DB::table('gates_event_work')->count());
        // The decide tab carries the owner's form, and the lead's carries none.
        $this->post($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Treasurer', 'kind' => 'staff', 'perms' => ['money.plan', 'money.approve'], 'ceiling' => '1000']);
        [, $own] = $this->get($this->owner, 'roles', ['tab' => 'decide']);
        $this->assertStringContainsString('name="do" value="decide"', $own);
        $this->assertStringContainsString('Flagged: Adds spend and approves it', $own, 'the risky pair is said to the owner');
        [, $lead] = $this->get($this->lead, 'roles', ['tab' => 'decide']);
        $this->assertStringNotContainsString('name="do" value="decide"', $lead);
        $this->assertStringContainsString('proposal_withdraw', $lead, 'the proposer may withdraw their own');
    }

    public function test_ending_a_role_or_archiving_it_takes_access_away_at_once(): void
    {
        [$role, $ada] = $this->roleHolder(['show.run']);
        $m = (int) DB::table('gates_event_team_members')->where('role_id', $role)->value('id');
        $this->assertStringContainsString('Give a reason', $this->refused($this->owner, ['do' => 'member_end', 'event' => $this->ev, 'member' => $m, 'reason' => '']));
        $this->assertStringContainsString('Ended', $this->post($this->owner, ['do' => 'member_end', 'event' => $this->ev, 'member' => $m, 'reason' => 'Left the committee']));
        $this->assertFalse(Grants::allows('member', $ada, 'event.role', 'event', $this->ev), 'the grant ended with the last role on it');
        $this->assertSame(404, $this->get($ada)[0]);

        [$role2, $bola] = $this->roleHolder(['show.run'], '', 'staff', 'Stage manager');
        $this->assertStringContainsString('Only an owner', $this->refused($this->lead, ['do' => 'role_archive', 'event' => $this->ev, 'role' => $role2, 'reason' => 'x']));
        $this->post($this->owner, ['do' => 'role_archive', 'event' => $this->ev, 'role' => $role2, 'reason' => 'Merged into another']);
        $this->assertSame([], EventPermissions::standing($bola, $this->ev)['perms']);
        $this->assertSame(404, $this->get($bola)[0]);
    }

    public function test_a_team_lead_proposes_only_into_their_own_team_and_never_a_lead(): void
    {
        foreach (['Guests', 'Stage'] as $n) {
            $this->post($this->lead, ['do' => 'team_propose', 'event' => $this->ev, 'name' => $n]);
            $t = (int) DB::table('gates_event_teams')->where('name', $n)->value('id');
            $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'team', 'id' => $t, 'verdict' => 'approve', 'reason' => 'ok']);
        }
        [$guests, $stage] = DB::table('gates_event_teams')->orderBy('id')->pluck('id')->map(static fn ($i) => (int) $i)->all();
        $this->post($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Host', 'team' => $guests, 'kind' => 'staff', 'perms' => ['guests.manage']]);
        $role = (int) DB::table('gates_event_roles')->value('id');
        $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'role', 'id' => $role, 'verdict' => 'approve', 'reason' => 'ok', 'perms' => ['guests.manage']]);
        $tl = $this->member('Kemi Bello');
        $this->post($this->lead, ['do' => 'member_propose', 'event' => $this->ev, 'email' => $this->email($tl), 'role' => $role, 'team' => $guests, 'team_role' => 'lead']);
        $this->post($this->owner, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'member', 'id' => (int) DB::table('gates_event_team_members')->value('id'), 'verdict' => 'approve', 'reason' => 'ok']);

        $this->assertStringContainsString('Proposed', $this->post($tl, ['do' => 'member_propose', 'event' => $this->ev, 'email' => 'helper@example.test', 'role' => $role, 'team' => $guests]));
        $this->refused($tl, ['do' => 'member_propose', 'event' => $this->ev, 'email' => 'other@example.test', 'role' => $role, 'team' => $stage]);
        $this->refused($tl, ['do' => 'member_propose', 'event' => $this->ev, 'email' => 'deputy@example.test', 'role' => $role, 'team' => $guests, 'team_role' => 'lead']);
        $this->refused($tl, ['do' => 'decide', 'event' => $this->ev, 'subject' => 'member', 'id' => (int) DB::table('gates_event_team_members')->max('id'), 'verdict' => 'approve', 'reason' => 'mine']);
        $this->assertSame(0, DB::table('gates_grants')->whereIn('actor_id', DB::table('gates_users')->where('email', 'helper@example.test')->pluck('id'))->count());
    }

    public function test_suggest_a_team_works_with_no_provider_and_stays_on_the_menu(): void
    {
        $s = (new TeamSuggest())->suggest(Plan::event($this->ev));
        $this->assertSame('template', $s['source'], 'no provider: the templates answer');
        $this->assertNotEmpty($s['roles']);
        foreach ($s['roles'] as $r) {
            $this->assertSame([], array_diff($r['perms'], array_keys(EventPermissions::MENU)), $r['name']);
            $this->assertFalse(in_array('money.plan', $r['perms'], true) && in_array('money.approve', $r['perms'], true), 'money is split');
        }
        $this->assertSame([], (new TeamSuggest())->review(Plan::event($this->ev), 'X', '', ['show.run'], 'staff'), 'no provider: no notes, and nothing breaks');
        // A model's answer is kept to the menu.
        $clean = TeamSuggest::clean(json_encode(['teams' => [['key' => 'g', 'name' => 'Guests']], 'roles' => [
            ['name' => 'Host', 'team' => 'g', 'perms' => ['guests.manage', 'access.grant', 'root'], 'lead_with' => ['people', 'nowhere']],
            ['name' => 'Ghost', 'perms' => ['invent.this']], ['name' => 'Cashier', 'perms' => ['money.plan', 'money.approve']]]]));
        $this->assertSame(['guests.manage'], $clean['roles'][0]['perms']);
        $this->assertSame(['people'], $clean['roles'][0]['lead_with']);
        $this->assertCount(2, $clean['roles'], 'a role left with nothing real is dropped');
        $this->assertSame(['money.plan'], $clean['roles'][1]['perms']);
        // Through the console: a suggestion is a set of forms, and applies nothing.
        $before = DB::table('gates_event_roles')->count();
        $this->assertStringContainsString('Suggested from the templates', $this->post($this->lead, ['do' => 'team_suggest', 'event' => $this->ev]));
        [, $html] = $this->get($this->lead, 'roles', ['tab' => 'suggest']);
        $this->assertStringContainsString('Suggested from the platform’s templates', $html);
        $this->assertStringContainsString('Propose the role Stage manager', $html);
        $this->assertSame($before, DB::table('gates_event_roles')->count(), 'never applied');
        $this->refused($this->owner, ['do' => 'team_suggest', 'event' => $this->ev]);
    }

    public function test_staff_see_owners_and_teams_and_decide_from_the_events_page(): void
    {
        $this->post($this->lead, ['do' => 'role_propose', 'event' => $this->ev, 'name' => 'Stage manager', 'kind' => 'staff', 'perms' => ['show.run']]);
        $role = (int) DB::table('gates_event_roles')->value('id');
        $_SESSION['admin_id'] = (int) DB::table('gates_admins')->insertGetId(['email' => 'staff' . bin2hex(random_bytes(3)) . '@example.test', 'name' => 'Staff One',
            'role' => 'superadmin', 'is_active' => 1, 'password_hash' => 'x', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $_SESSION['admin_name'] = 'Staff One';
        $_SESSION['admin_role'] = 'superadmin';
        $c = $this->ctrl(\AfricaGates\Admin\Controllers\EventsController::class);
        $html = (string) $c->leads((new ServerRequestFactory())->createServerRequest('GET', '/admin/events/' . $this->ev . '/leads'), new Response(), ['id' => $this->ev])->getBody();
        $this->assertStringContainsString('Ngozi Eze', $html, 'the owner is on the event’s page');
        $this->assertStringContainsString('Role: Stage manager', $html);
        $this->assertStringContainsString('value="team_decide"', $html);
        $c->leadsAct((new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['do' => 'team_decide', 'subject' => 'role', 'id' => $role, 'verdict' => 'refuse', 'reason' => 'Not this year']),
            new Response(), ['id' => $this->ev]);
        $this->assertSame('refused', DB::table('gates_event_roles')->where('id', $role)->value('status'));
        $this->assertSame(1, DB::table('gates_audit_log')->where('action', 'event.team.refused')->count());
        $c->leadsAct((new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody(['do' => 'owner_give', 'email' => 'organiser@example.test']), new Response(), ['id' => $this->ev]);
        $this->assertSame(1, DB::table('gates_role_invites')->where('email', 'organiser@example.test')->where('capability', 'event.own')->count(), 'an outside owner is invited by address');
        $all = (string) $c->allLeads((new ServerRequestFactory())->createServerRequest('GET', '/admin/events/leads'), new Response())->getBody();
        $this->assertStringContainsString('owns it', $all);
        $this->assertStringContainsString('invited to own it', $all);
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
    }
}
