<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Grants;
use AfricaGates\Services\VoteService;
use AfricaGates\Services\Volunteer\NominationChecks;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\VoterDrive;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * A volunteer's award places (consoles v7 §6.2; review slice V2, G5 and G6).
 *
 * What these try to break: a nominator's name, email or phone reaching a volunteer's page;
 * a check from somebody with no grant, or a second check rewriting the first; a flag with no
 * reason; a drive link counting a person twice, counting a vote on another award, or counting
 * the volunteer themselves; and a menu that offers work the person's grants do not carry.
 */
final class VolunteerAwardPlacesTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        $this->slug = 'tp-' . bin2hex(random_bytes(3));
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => $this->slug, 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION[VoterDrive::SESSION_KEY]);
        parent::tearDown();
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    /** An approved volunteer, signed in, with these grants on the edition. */
    private function volunteer(array $caps): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chika Nwosu', 'email' => 'chika.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun', 'dob' => '1999-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok']);
        Volunteers::approve((int) $r['id'], 1);
        foreach ($caps as $c) $this->assertTrue(Grants::grant('member', $uid, $c, 'cycle', $this->cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $_SESSION['user_id'] = $uid;
        return Volunteers::of($uid);
    }

    private function nom(string $name, array $o = []): int
    {
        $id = (int) DB::table('gates_nominations')->insertGetId($o + [
            'cycle_id' => $this->cycle, 'category_id' => $this->cat, 'nominee_name' => $name, 'country_code' => 'NG', 'nominee_state' => 'Lagos',
            'nominator_name' => 'Grace Adeyemi', 'nominator_email' => 'grace.private@school.ng', 'nominator_phone' => '08031234567',
            'reason' => 'Runs a free Saturday reading club for 40 pupils.', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'link', 'url' => "https://example.org/club/$id", 'created_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    private function page(string $page = 'overview', ?string $tab = null, array $q = []): string
    {
        $args = ['kind' => 'cycle', 'id' => (string) $this->cycle, 'page' => $page] + ($tab ? ['tab' => $tab] : []);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/volunteer')->withQueryParams($q);
        $res = $this->ctrl()->place($req, new Response(), $args);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    private function post(array $b): ?string
    {
        unset($_SESSION['vol_toast'], $_SESSION['vol_error']);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody($b + ['back' => '/volunteer/cycle/' . $this->cycle . '/queue']);
        $this->ctrl()->act($req, new Response());
        return $_SESSION['vol_error'] ?? null;
    }

    public function test_a_nomination_reaches_a_volunteer_with_no_nominator_details_in_the_html(): void
    {
        $this->volunteer(['vol.nomination_check']);
        $n = $this->nom('Adebayo Okafor', ['nominator_relation' => 'colleague']);

        $list = $this->page('queue');
        $this->assertStringContainsString('N-' . $n . ' · Adebayo Okafor', $list);
        $one = $this->page('queue', null, ['n' => $n]);
        $this->assertStringContainsString('Runs a free Saturday reading club', $one, 'the reason is what a check is about');
        $this->assertStringContainsString('example.org/club/' . $n, $one, 'and the evidence');
        foreach (['Grace', 'Adeyemi', 'grace.private', '08031234567', 'colleague'] as $secret) {
            $this->assertStringNotContainsString($secret, $one, "masked on the server: “{$secret}” must never be sent");
            $this->assertStringNotContainsString($secret, (string) json_encode(NominationChecks::view(Volunteers::of((int) $_SESSION['user_id']), $this->cycle, $n)));
        }
    }

    public function test_a_check_needs_the_grant_a_reason_to_flag_and_is_made_once(): void
    {
        $n = $this->nom('Halima Bello');
        $this->volunteer(['vol.voter_drive']);   // a drive volunteer is not a checker
        $this->assertNotNull($this->post(['do' => 'nomcheck', 'cycle' => $this->cycle, 'nomination' => $n, 'check' => 'complete']));
        $this->assertSame(0, DB::table('gates_vol_nom_checks')->count(), 'no grant, no check');

        $v = $this->volunteer(['vol.nomination_check']);
        $this->assertNotNull($this->post(['do' => 'nomcheck', 'cycle' => $this->cycle, 'nomination' => $n, 'check' => 'flag_nonsense']), 'a flag names one of the four reasons');
        $this->assertNotNull($this->post(['do' => 'nomcheck', 'cycle' => $this->cycle, 'nomination' => $n, 'check' => 'flag_other', 'note' => '']), '"Something else" says what');
        $this->assertNull($this->post(['do' => 'nomcheck', 'cycle' => $this->cycle, 'nomination' => $n, 'check' => 'flag_duplicate', 'note' => 'Same school as N-3977']));
        $this->assertNotNull($this->post(['do' => 'nomcheck', 'cycle' => $this->cycle, 'nomination' => $n, 'check' => 'complete']), 'a check is never rewritten');
        $row = DB::table('gates_vol_nom_checks')->where('volunteer_id', $v->id)->first();
        $this->assertSame(['flag', 'duplicate'], [$row->rec, $row->reason]);
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', $n)->value('status'), 'a check is a recommendation: it moves nothing');

        // The moderator reads it, with the volunteer's name.
        $desk = NominationChecks::forNomination($n);
        $this->assertSame('Possible duplicate', $desk[0]['said']);
        $this->assertSame('Chika Nwosu', $desk[0]['by']);
        $this->assertStringNotContainsString('N-' . $n, $this->page('queue'), 'a checked nomination leaves the list');
    }

    public function test_agreement_counts_only_what_a_moderator_decided(): void
    {
        $v = $this->volunteer(['vol.nomination_check']);
        [$a, $b, $c] = [$this->nom('A One'), $this->nom('B Two'), $this->nom('C Three')];
        foreach ([[$a, 'complete'], [$b, 'flag_evidence'], [$c, 'complete']] as [$id, $chk]) $this->assertNull($this->post(['do' => 'nomcheck', 'cycle' => $this->cycle, 'nomination' => $id, 'check' => $chk]));
        $this->assertNull(NominationChecks::agreement($v, $this->cycle), 'nothing decided: no percentage of nothing');
        DB::table('gates_nominations')->where('id', $a)->update(['status' => 'approved']);       // agreed
        DB::table('gates_nominations')->where('id', $b)->update(['status' => 'approved']);       // differed: flagged, approved
        $ag = NominationChecks::agreement($v, $this->cycle);
        $this->assertSame(['decided' => 2, 'agreed' => 1, 'differed' => 1, 'pct' => 50], $ag, 'C is still waiting and counts for nothing');
        $this->assertStringContainsString('50%', $this->page('queue', 'agree'));
    }

    public function test_the_award_menu_is_built_from_the_grants(): void
    {
        $this->volunteer(['vol.voter_drive']);
        $html = $this->page();
        $this->assertStringContainsString('/drive"', $html);
        $this->assertStringNotContainsString('/queue"', $html, 'no check grant, no Check nominations');
        $this->expectException(HttpNotFoundException::class);
        $this->page('queue');
    }

    public function test_a_drive_link_counts_each_person_once_on_its_own_award_and_never_its_owner(): void
    {
        $v = $this->volunteer(['vol.voter_drive']);
        $link = VoterDrive::link($v, $this->cycle);
        $this->assertSame('/vote/' . $this->slug . '?drive=' . $link['code'], $link['path'], 'it opens the award’s ballot and names no nominee');
        $this->assertSame($link['code'], VoterDrive::link($v, $this->cycle)['code'], 'the same link every time');

        $nominee = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat, 'name' => 'Adebayo Okafor', 'status' => 'approved']);
        $voter = VoteService::voterHash('ada@example.test');
        $this->assertTrue(VoterDrive::credit($link['code'], $nominee, $voter));
        $this->assertFalse(VoterDrive::credit($link['code'], $nominee, $voter), 'a second vote is the same person');
        $owner = (string) DB::table('gates_users')->where('id', $v->user_id)->value('email');
        $this->assertFalse(VoterDrive::credit($link['code'], $nominee, VoteService::voterHash($owner)), 'the volunteer’s own vote brings nobody');

        $p2 = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'other-' . bin2hex(random_bytes(2)), 'title' => 'Other', 'is_active' => 1]);
        $c2 = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p2, 'year' => 2026, 'status' => 'voting']);
        $cat2 = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $c2, 'slug' => 'x' . bin2hex(random_bytes(2)), 'title' => 'X', 'sort_order' => 1]);
        $elsewhere = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat2, 'name' => 'Someone Else', 'status' => 'approved']);
        $this->assertFalse(VoterDrive::credit($link['code'], $elsewhere, VoteService::voterHash('bo@example.test')), 'a vote on another award is not this drive’s');

        $this->assertSame(1, VoterDrive::link($v, $this->cycle)['people']);
        $this->assertStringContainsString('People who voted through your link', $this->page('drive'));
    }
}
