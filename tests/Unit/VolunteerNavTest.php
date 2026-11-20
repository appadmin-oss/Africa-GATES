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
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * ONE MENU, EVERYWHERE (owner, 16 Nov 2026: "the navigation even for users that can see is
 * actually quite poor … it is properly confusing … especially mobile"). Workspace::RAIL says why.
 *
 * What these try to break: an award's page drawing a second menu behind "Back"; the phone's
 * bottom tabs changing under the person's thumb when they open an award; Nominees — the job most
 * volunteers come for — missing from the top, or drawn twice; the award you are in not opening in
 * place; a count on Nominees counting one nominee twice; and the phone's menu as a flat run of
 * rows with no title, no way to close it and its numbers drawn as blank dots.
 */
final class VolunteerNavTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        Outreach::reset();
        [$this->cycle, $this->cat] = $this->award('Teachers Prize');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_old']);
        parent::tearDown();
    }

    /** @return array{0:int,1:int} cycle, category */
    private function award(string $title): array
    {
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'nv-' . bin2hex(random_bytes(3)), 'title' => $title, 'is_active' => 1]);
        $c = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $c, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Of the Year', 'sort_order' => 1]);
        return [$c, $cat];
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    private function volunteer(array $grants): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chika Nwosu', 'email' => 'chika.' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['phone' => '0803 555 0101', 'state' => 'Lagos', 'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'area' => 'Ikotun',
            'dob' => '1999-05-01', 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        foreach ($grants as [$cap, $cycle]) $this->assertTrue(Grants::grant('member', $uid, $cap, 'cycle', $cycle, date('Y-m-d', strtotime('+20 days')), 1)['ok']);
        $_SESSION['user_id'] = $uid;
        return Volunteers::of($uid);
    }

    private function nominee(string $name, int $cat): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => $name, 'status' => 'approved', 'nominated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_nominee_details')->insert(['nominee_id' => $id, 'phones' => json_encode(['+2348031112233']), 'emails' => json_encode(['n@school.ng']),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    private function get(string $path, array $args, string $method = 'root'): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $res = $this->ctrl()->{$method}($req, new Response(), $args);
        $this->assertSame(200, $res->getStatusCode(), $path);
        return (string) $res->getBody();
    }

    /** The phone's bottom tabs, in order. */
    private function phoneTabs(string $html): array
    {
        $this->assertSame(1, preg_match('~<nav class="sh7-phonetabs" aria-label="Pages">(.*?)<details class="sh7-more">~s', $html, $m));
        preg_match_all('~<a class="sh7-phonetab[^"]*" href="([^"]+)"~', $m[1], $t);
        return $t[1];
    }

    /** The rail's own items (top level), as label => href. */
    private function railTop(string $html): array
    {
        $this->assertSame(1, preg_match('~<nav class="sh7-nav" aria-label="Pages">(.*?)</nav>~s', $html, $m));
        $top = preg_replace('~<ul class="sh7-sub".*?</ul>~s', '', $m[1]);
        preg_match_all('~<a class="sh7-item[^"]*" href="([^"]+)"[^>]*>.*?<span class="sh7-label">([^<]+)</span>~s', $top, $t, PREG_SET_ORDER);
        return array_column(array_map(static fn ($x) => [html_entity_decode($x[2]), $x[1]], $t), 1, 0);
    }

    public function test_an_award_page_keeps_the_same_menu_and_the_same_phone_tabs(): void
    {
        $v = $this->volunteer([[Outreach::CAPABILITY, $this->cycle]]);
        Outreach::agree($v, $this->cycle);
        $this->nominee('Halima Bello', $this->cat);
        $today = $this->get('/volunteer', []);
        $award = $this->get('/volunteer/cycle/' . $this->cycle . '/tasks', ['kind' => 'cycle', 'id' => (string) $this->cycle, 'page' => 'tasks'], 'place');
        $me = $this->get('/volunteer/me/privacy', ['page' => 'privacy'], 'me');

        foreach ([$award, $me] as $html) {
            $this->assertStringNotContainsString('Back to my volunteering', $html, 'a second menu, behind Back, replaced the first');
            $this->assertStringNotContainsString('<a class="sh7-back"', $html, 'the rail opens on a Back link, as the second menu did');
            $this->assertStringContainsString('<div class="sh7-brand">', $html);
            $this->assertSame(array_keys($this->railTop($today)), array_keys($this->railTop($html)), 'the menu changed when the person opened a page');
            $this->assertSame($this->phoneTabs($today), $this->phoneTabs($html), 'the phone’s bottom tabs changed under the person’s thumb');
        }
        $nominees = '/volunteer/cycle/' . $this->cycle . '/nominees';
        $this->assertSame(['/volunteer', $nominees, '/volunteer/tasks', '/volunteer/meetings'], $this->phoneTabs($today), 'Nominees is not on the phone’s bar');
        $this->assertSame($nominees, $this->railTop($today)['Nominees'], 'with one award, Nominees opens its list');
        foreach (['Grow', 'You'] as $vague) $this->assertStringNotContainsString('<p class="sh7-group__label" id="sh7G4">' . $vague . '</p>', $today);
        $this->assertStringContainsString('>Help and settings</p>', $today);

        // The award you are in opens in place, its page marked, and Nominees is not repeated under it.
        $this->assertSame(1, preg_match('~<ul class="sh7-sub" aria-label="([^"]+)">(.*?)</ul>~s', $award, $sub));
        $this->assertStringContainsString('Teachers Prize', html_entity_decode($sub[1]));
        $this->assertMatchesRegularExpression('~is-on" href="/volunteer/cycle/' . $this->cycle . '/tasks" aria-current="page"~', $sub[2]);
        $this->assertStringNotContainsString('/nominees"', $sub[2], 'Nominees drawn twice, once at the top and once under the award');
        $this->assertStringNotContainsString('<ul class="sh7-sub"', $today, 'a place is open on a page that is not in it');

        // On the award's Nominees page, the top entry is the current one, and the bar says whose list it is.
        $list = $this->get($nominees, ['kind' => 'cycle', 'id' => (string) $this->cycle, 'page' => 'nominees'], 'place');
        $this->assertMatchesRegularExpression('~<a class="sh7-item is-on" href="' . preg_quote($nominees, '~') . '" aria-current="page"~', $list);
        $this->assertMatchesRegularExpression('~<a class="sh7-phonetab is-on" href="' . preg_quote($nominees, '~') . '" aria-current="page"~', $list);
        $this->assertStringContainsString('<span class="sh7-apptitle__sub">Teachers Prize', $list);
    }

    public function test_the_phone_menu_is_grouped_titled_closable_and_its_numbers_are_numbers(): void
    {
        $v = $this->volunteer([[Outreach::CAPABILITY, $this->cycle]]);
        // Verified hours make them an active volunteer, whose menu carries Hours; one more in review is its count.
        DB::table('gates_vol_hours')->insert(['volunteer_id' => $v->id, 'source' => 'manual', 'worked_on' => date('Y-m-d', strtotime('-3 days')), 'hours' => 3, 'what' => 'Door', 'status' => 'verified', 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_vol_hours')->insert(['volunteer_id' => $v->id, 'source' => 'manual', 'worked_on' => date('Y-m-d'), 'hours' => 2, 'what' => 'Calls', 'status' => 'review', 'created_at' => date('Y-m-d H:i:s')]);
        $html = $this->get('/volunteer', []);
        $this->assertSame(1, preg_match('~<div class="sh7-sheet" role="dialog" aria-labelledby="sh7SheetH">(.*?)</details>~s', $html, $m), 'the menu sheet has no name');
        $sheet = $m[1];
        $this->assertStringContainsString('<h2 class="sh7-sheet__h" id="sh7SheetH">Menu</h2>', $sheet);
        $this->assertStringContainsString('data-sh7-sheet-x>Close</button>', $sheet, 'no way to close the menu but the button that opened it');
        $this->assertStringContainsString('<p class="sh7-sheet__g">Where you help</p>', $sheet, 'the menu is a flat run of rows');
        $this->assertStringNotContainsString('href="/volunteer/tasks"', $sheet, 'a page already on the bar is repeated in the menu');
        $this->assertStringContainsString('<span class="sh7-label">Hours</span><span class="sh7-badge', $sheet);
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/console/shell7.css');
        $this->assertStringContainsString('.sh7-sheet .sh7-item .sh7-badge { position: static;', $css, 'the icon rail’s dot rule hides the menu’s numbers');
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/shell7.js');
        $this->assertStringContainsString('[data-sh7-sheet-x]', $js);
    }

    public function test_nominees_counts_people_waiting_and_finds_the_right_list_for_several_awards(): void
    {
        // The waiting is on the SECOND award, so it is first only because it has the most waiting.
        [$c2, $cat2] = $this->award('Nurses Honours');
        $v = $this->volunteer([[Outreach::CAPABILITY, $this->cycle], [Outreach::CAPABILITY, $c2], ['vol.team_lead', $c2]]);
        Outreach::agree($v, $this->cycle);
        Outreach::agree($v, $c2);
        $a = $this->nominee('Halima Bello', $cat2);
        $this->nominee('Ada Obi', $cat2);
        $this->nominee('Ngozi Eze', $this->cat);
        // Halima has a follow-up due AND no update since the request: one person waiting, not two.
        DB::table('gates_vol_outreach')->insert(['volunteer_id' => $v->id, 'nominee_id' => $a, 'cycle_id' => $c2, 'channel' => 'call',
            'outcome' => 'no_answer', 'follow_up_on' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        Outreach::reset();
        $this->assertTrue(NomineeStatus::request($v, $c2, '', date('Y-m-d', strtotime('+2 days')))['ok']);
        $this->assertSame(2, Workspace::nomineesWaiting($v, $c2), 'one nominee counted twice, or the update request not counted');
        $this->assertSame(['n' => 2, 'tone' => 'warn'], Workspace::counts($v, Volunteers::role($v))['nominees']);

        $today = $this->get('/volunteer', []);
        $this->assertSame('/volunteer/nominees', $this->railTop($today)['Nominees']);
        $hub = $this->get('/volunteer/nominees', ['page' => 'nominees']);
        $hub = substr($hub, (int) strpos($hub, '<main'));   // the menu lists the awards too, in its own order
        $this->assertLessThan(strpos($hub, 'Teachers Prize'), strpos($hub, 'Nurses Honours'), 'the award with most waiting is not first');
        $this->assertStringContainsString('2 waiting', $hub);
        $this->assertStringContainsString('href="/volunteer/cycle/' . $c2 . '/nominees"', $hub);
    }

    public function test_one_award_goes_straight_to_its_list_and_no_role_has_no_nominees(): void
    {
        $v = $this->volunteer([[Outreach::CAPABILITY, $this->cycle]]);
        $res = $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/nominees'), new Response(), ['page' => 'nominees']);
        $this->assertSame('/volunteer/cycle/' . $this->cycle . '/nominees', $res->getHeaderLine('Location'));

        $this->volunteer([['vol.voter_drive', $this->cycle]]);
        $this->assertArrayNotHasKey('Nominees', $this->railTop($this->get('/volunteer', [])), 'Nominees offered to somebody who reaches no nominees');
        $this->expectException(HttpNotFoundException::class);
        $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/nominees'), new Response(), ['page' => 'nominees']);
    }
}
