<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Gee and the help desk, 12 Oct 2026 — the owner: "add more tools", "make both highly
 * effective", and "most of Gee's widgets are not displaying when it calls it".
 *
 * Held here:
 *   · the new tools answer from the platform's own gates — an UNANNOUNCED award names no
 *     winner, a nomination window is the one the form enforces, a member's own tickets,
 *     orders and account are theirs and a guest's are nobody's;
 *   · a by-name nominee lookup never reaches the sandbox (DemoSeeder rows are real rows);
 *   · a lookup that found a page earns its button even when the model makes no second
 *     offer_action call — which it usually does not, and that was the missing widget;
 *   · the guide can diagnose (gateway, email domain, references) rather than redirect;
 *   · the widget draws the "checked …" line and a ticket opened inside a chat reply.
 */
final class GeeToolsTest extends TestCase
{
    private function guest(): SupportContext { return new SupportContext(null, null); }

    private function programme(string $title, int $active): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => $title, 'slug' => 'p-' . bin2hex(random_bytes(3)), 'is_active' => $active, 'sort_order' => 1]);
    }

    private function cycle(int $pid, string $status, array $extra = []): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId($extra + [
            'programme_id' => $pid, 'year' => 2026, 'status' => $status,
            'results_date' => Carbon::now()->addDays(20)->toDateTimeString()]);
    }

    private function category(int $cycle, string $title): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'c-' . bin2hex(random_bytes(3)), 'title' => $title, 'sort_order' => 1]);
    }

    private function nominee(int $cat, string $name): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => $name, 'status' => 'approved', 'vote_count' => 10, 'organic_vote_count' => 10]);
    }

    public function test_a_name_lookup_never_hands_out_a_sandbox_ballot(): void
    {
        $live = $this->category($this->cycle($this->programme('Live Awards', 1), 'voting'), 'Teacher of the Year');
        $demo = $this->category($this->cycle($this->programme('Sandbox', 0), 'voting'), 'Rehearsal');
        $this->nominee($live, 'Adaeze Live');
        $this->nominee($demo, 'Adaeze Rehearsal');

        foreach (['find_nominee', 'nominee_tally'] as $tool) {
            $r = $this->guest()->run($tool, ['name' => 'Adaeze']);
            $this->assertTrue($r['ok'], $tool);
            $this->assertStringNotContainsString('Rehearsal', json_encode($r['data']), "$tool reached the sandbox");
            $this->assertStringContainsString('Adaeze Live', json_encode($r['data']), $tool);
        }
    }

    public function test_an_unannounced_award_names_no_winner(): void
    {
        $cat = $this->category($this->cycle($this->programme('Education Awards', 1), 'judging'), 'STEM Educator');
        $this->nominee($cat, 'Amara Okonkwo');

        $r = $this->guest()->run('award_results', ['query' => 'STEM']);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['data']['found'], 'nothing announced, nothing found');
        $this->assertSame('STEM Educator', $r['data']['not_yet_announced'][0]['category']);
        $this->assertStringNotContainsString('Amara', json_encode($r['data']),
            'a decided-but-unannounced award is not the assistant\'s to announce');
    }

    public function test_nominations_open_is_the_window_the_form_enforces(): void
    {
        $open = $this->programme('Open Award', 1);
        $this->cycle($open, 'nominations', ['nominations_open' => Carbon::now()->subDay()->toDateTimeString(),
                                            'nominations_close' => Carbon::now()->addDays(9)->toDateTimeString()]);
        $shut = $this->programme('Shut Award', 1);
        $this->cycle($shut, 'nominations', ['nominations_open' => Carbon::now()->subDays(30)->toDateTimeString(),
                                            'nominations_close' => Carbon::now()->subDay()->toDateTimeString()]);

        $d = $this->guest()->run('nominations_open')['data'];
        $this->assertSame(['Open Award'], array_column($d['open'], 'award'));
        $this->assertStringStartsWith('/nominate/p-', $d['open'][0]['url']);
    }

    public function test_a_members_own_account_tools_are_theirs_and_a_guest_is_not_offered_them(): void
    {
        $names = array_column($this->guest()->tools(), 'name');
        foreach (['my_account', 'my_event_tickets', 'my_shop_orders'] as $t) {
            $this->assertNotContains($t, $names, "a guest is never even told $t exists");
            $this->assertFalse($this->guest()->run($t)['ok']);
        }
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chidi', 'email' => 'chidi-' . bin2hex(random_bytes(3)) . '@example.test',
            'points' => 0, 'status' => 'active', 'email_verified' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $me = new SupportContext($uid, (string) DB::table('gates_users')->where('id', $uid)->value('email'));
        $acct = $me->run('my_account');
        $this->assertTrue($acct['ok']);
        $this->assertFalse($acct['data']['email_verified']);
        $this->assertSame([], $me->run('my_event_tickets')['data']['tickets']);
        $this->assertSame([], $me->run('my_shop_orders')['data']['orders']);
    }

    public function test_a_lookup_that_found_a_page_earns_its_button_without_a_second_call(): void
    {
        $facts = [
            'find_nominee:{}' => ['ok' => true, 'tool' => 'find_nominee', 'data' => ['nominees' => [
                ['name' => 'Ada Obi', 'url' => '/vote/ada-obi', 'votable_now' => true]]]],
            'event_details:{}' => ['ok' => true, 'tool' => 'event_details',
                'data' => ['url' => \AfricaGates\Support\SiteUrl::base() . '/events/gala-2026']],
            'invitation_lookup:{}' => ['ok' => true, 'tool' => 'invitation_lookup', 'data' => ['pass_url' => 'https://evil.example/x']],
        ];
        $this->assertSame([['label' => 'Vote for Ada Obi', 'url' => '/vote/ada-obi'],
                           ['label' => 'See the event', 'url' => '/events/gala-2026']],
            SupportAgentService::actionsFrom($facts),
            'the button the lookup found, an absolute URL on this site reduced to its path, a foreign one dropped');

        $offered = ['offer_action:{}' => ['ok' => true, 'tool' => 'offer_action', 'data' => ['label' => 'Nominate', 'url' => '/nominate']]] + $facts;
        $this->assertSame('/nominate', SupportAgentService::actionsFrom($offered)[0]['url'], 'the model\'s own offer comes first');
        $this->assertCount(2, SupportAgentService::actionsFrom($offered), 'and the cap is still two');
        $this->assertSame([], SupportAgentService::actionsFrom(['x' => ['ok' => false, 'tool' => 'find_nominee', 'data' => null]]));
    }

    public function test_the_answer_says_where_it_came_from_in_words(): void
    {
        $this->assertSame(['your payment with the gateway', 'the payment gateways'],
            SupportAgentService::checkedFrom(['fix_payment', 'help_article', 'gateway_status', 'offer_action', 'site_state']));
        $this->assertSame([], SupportAgentService::checkedFrom(['help_article', 'offer_action']), 'reading our own pages is not news');
    }

    public function test_the_guide_can_diagnose_and_every_tool_it_names_exists(): void
    {
        foreach (['gateway_status', 'check_email_domain', 'check_reference', 'refund_status', 'award_results', 'nominations_open'] as $t) {
            $this->assertContains($t, SupportAgentService::GUIDE_TOOLS, "the guide can $t instead of redirecting");
        }
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'M', 'email' => 'm-' . bin2hex(random_bytes(3)) . '@example.test',
            'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $all = array_column((new SupportContext($uid, 'm@example.test'))->tools(), 'name');
        $shop = ['shop_suggest', 'shop_availability', 'shop_quote', 'shop_compare', 'shop_delivery', 'shop_link'];
        foreach (SupportAgentService::GUIDE_TOOLS as $t) {
            if (in_array($t, $shop, true)) continue;   // offered only while the shop has stock
            $this->assertContains($t, $all, "GUIDE_TOOLS names $t, which no member is offered");
        }
    }

    public function test_the_widget_draws_the_checked_line_and_a_ticket_opened_inside_a_reply(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gee.js');
        $this->assertMatchesRegularExpression("~checked: d\\.checked~", $js);
        $this->assertMatchesRegularExpression("~if \\(m\\.checked && m\\.checked\\.length\\) thread\\.appendChild\\(checked\\(m\\.checked\\)\\)~", $js);
        $this->assertMatchesRegularExpression("~if \\(d\\.escalated && d\\.ticket\\) \\{\\s*push\\(\\{ kind: 'hand', ticket: String\\(d\\.ticket\\)~", $js,
            'the reply carried the ticket and the widget dropped it');
        $this->assertStringContainsString('data-msg-checked=', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/gee.twig'));

        foreach (['SupportController', 'GuideController'] as $c) {
            $this->assertStringContainsString("'checked'   => SupportAgentService::checkedFrom(",
                (string) file_get_contents(dirname(__DIR__, 2) . "/src/Controllers/$c.php"), $c);
        }
        $this->assertStringContainsString("'work'      => \\AfricaGates\\Services\\SupportWork::fromResults(",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/GuideController.php'),
            'the repair card on the road a stuck visitor takes from Gee');
    }
}
