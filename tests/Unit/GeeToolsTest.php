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

    // ── "Gee does not have so much knowledge. It is not very smart." ─────────────

    public function test_with_no_model_gee_still_answers_the_facts_from_the_site(): void
    {
        $pid = $this->programme('Education Awards', 1);
        $cat = $this->category($this->cycle($pid, 'voting', ['nominations_open' => Carbon::now()->subDay()->toDateTimeString(),
                                                             'nominations_close' => Carbon::now()->addDays(5)->toDateTimeString(),
                                                             'voting_open' => Carbon::now()->subDay()->toDateTimeString(),
                                                             'voting_close' => Carbon::now()->addDays(9)->toDateTimeString()]), 'STEM Educator');
        $this->nominee($cat, 'Amara Okonkwo');
        $g = new \AfricaGates\Services\GuideService();

        $won = $g->scripted('Who won the STEM Educator award?')['reply'];
        $this->assertStringContainsString('STEM Educator** has not been announced yet', $won);
        $this->assertStringNotContainsString('Amara', $won, 'an unannounced award names nobody, even on the floor');

        $nom = $g->scripted('Are nominations still open?')['reply'];
        $this->assertStringContainsString('**Education Awards**', $nom);
        $this->assertMatchesRegularExpression('~\[Nominate\]\(/nominate/p-[a-f0-9]+\)~', $nom);

        $vote = $g->scripted('How do I vote for Amara Okonkwo?')['reply'];
        $this->assertStringContainsString('[Vote for Amara Okonkwo](', $vote, 'the ballot, as a link the widget draws');
        $this->assertStringNotContainsString(' her ', ' ' . $vote . ' ', 'a nominee is never gendered by a template');
    }

    public function test_the_agent_is_handed_the_whole_help_shelf(): void
    {
        $m = new \ReflectionMethod(\AfricaGates\Services\GuideService::class, 'helpGrounding');
        $text = (string) $m->invoke(new \AfricaGates\Services\GuideService(), 'where does my money go when I vote');
        $this->assertStringContainsString('EVERY WRITTEN ANSWER THE HELP CENTRE HOLDS', $text);
        foreach (\AfricaGates\Services\HelpCentre::all() as $a) {
            $this->assertStringContainsString(\AfricaGates\Services\HelpCentre::url((string) $a['slug']), $text, $a['slug']);
        }
        $this->assertStringContainsString('### Why is voting paid?', $text, 'the article that answers it, at length');
    }

    public function test_on_a_phone_gee_is_a_sheet_and_its_header_is_one_line(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/gee.css');
        $this->assertMatchesRegularExpression('~@media \(max-width:599\.98px\)\{\s*\.gee__layer\{ inset:0;~', $css);
        $this->assertMatchesRegularExpression('~\.gee__panel\{ height:100%; border-radius:var\(--ag-r-24\) var\(--ag-r-24\) 0 0~', $css);
        $this->assertMatchesRegularExpression('~\.gee__status\{[^}]*white-space:nowrap[^}]*text-overflow:ellipsis~', $css);
        $this->assertStringContainsString("layer.addEventListener('click', function (e) { if (e.target === layer) close(); });",
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gee.js'), 'the scrim closes the sheet');
    }

    // ── second pass (12 Oct 2026): "solve all kinds of problems, real-world scenarios" ──

    private function member(string $email): SupportContext
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi', 'email' => $email, 'points' => 0,
            'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        return new SupportContext($uid, $email);
    }

    public function test_our_own_mail_outage_is_said_before_anyone_is_sent_to_spam(): void
    {
        $this->assertFalse($this->guest()->run('mail_health')['data']['incident']);
        DB::table('gates_mail_incidents')->insert(['opened_at' => date('Y-m-d H:i:s')]);
        $d = $this->guest()->run('mail_health')['data'];
        $this->assertTrue($d['incident']);
        $this->assertStringContainsString('Our email is having trouble', $d['say']);
    }

    public function test_sign_in_help_gives_the_wait_and_says_nothing_about_who_is_registered(): void
    {
        $known = 'kemi-' . bin2hex(random_bytes(3)) . '@example.test';
        $this->member($known);
        $unknown = 'nobody-' . bin2hex(random_bytes(3)) . '@example.test';
        $lim = new \AfricaGates\Services\RateLimitService();
        foreach ([$known, $unknown] as $e) for ($k = 0; $k < 10; $k++) $lim->check(hash('sha256', $e), 'user_login_email', 10, 3600);

        $a = $this->guest()->run('sign_in_help', ['email' => $known])['data'];
        $b = $this->guest()->run('sign_in_help', ['email' => $unknown])['data'];
        $this->assertTrue($a['limits'][0]['paused']);
        $this->assertGreaterThan(0, $a['limits'][0]['minutes']);
        unset($a['address'], $b['address']);
        $this->assertSame($a, $b, 'a registered address and an unregistered one get the same answer');
    }

    public function test_an_order_and_a_ticket_are_found_by_reference_without_their_owners_details(): void
    {
        DB::table('gates_orders')->insert(['reference' => 'AFG-SHOP-TEST01', 'email' => 'buyer@example.test', 'name' => 'Ngozi',
            'address' => '12 Private Road', 'phone' => '+2348000000000',
            'items_json' => json_encode([['name' => 'Heritage tee', 'qty' => 1]]), 'subtotal_naira' => 15000, 'status' => 'paid']);
        $o = $this->guest()->run('order_lookup', ['reference' => 'afg-shop-test01'])['data'];
        $this->assertTrue($o['found']);
        $this->assertSame('/shop/order/AFG-SHOP-TEST01', $o['order']['url']);
        $this->assertStringNotContainsString('Private Road', json_encode($o));
        $this->assertStringNotContainsString('buyer@', json_encode($o));
        $this->assertFalse($this->guest()->run('order_lookup', ['reference' => 'AFG-NOPE-0000'])['data']['found']);

        $ev = (int) DB::table('gates_site_events')->insertGetId(['title' => 'The Gala', 'slug' => 'gala-' . bin2hex(random_bytes(2)),
            'event_date' => date('Y-m-d H:i:s', time() + 86400 * 9)]);
        DB::table('gates_event_registrations')->insert(['event_id' => $ev, 'name' => 'Ngozi', 'email' => 'guest@example.test',
            'reference' => 'EVT-TEST-0001', 'status' => 'confirmed']);
        $t = $this->guest()->run('registration_lookup', ['reference' => 'EVT-TEST-0001'])['data'];
        $this->assertTrue($t['found']);
        $this->assertSame('The Gala', $t['ticket']['event']);
        $this->assertStringNotContainsString('guest@', json_encode($t));
    }

    public function test_claiming_a_profile_never_says_who_holds_it_and_never_reaches_the_sandbox(): void
    {
        $live = $this->category($this->cycle($this->programme('Live', 1), 'voting'), 'Teacher');
        $demo = $this->category($this->cycle($this->programme('Sandbox', 0), 'voting'), 'Rehearsal');
        $n = $this->nominee($live, 'Tendai Moyo');
        $this->nominee($demo, 'Tendai Rehearsal');

        $d = $this->guest()->run('claim_profile', ['name' => 'Tendai'])['data'];
        $this->assertSame(['Tendai Moyo', 'open', '/claim/' . $n], [$d['nominee'], $d['state'], $d['url']]);

        DB::table('gates_nominee_claims')->insert(['nominee_id' => $n, 'status' => 'active', 'method' => 'otp']);
        $d = $this->guest()->run('claim_profile', ['name' => 'Tendai'])['data'];
        $this->assertSame('claimed', $d['state']);
        $this->assertSame([], SupportAgentService::derivedActions(['c' => ['ok' => true, 'tool' => 'claim_profile', 'data' => $d]]),
            'no "Claim this profile" button on a profile somebody already holds');
    }

    public function test_a_member_sees_what_we_emailed_them_and_whether_their_address_is_blocked(): void
    {
        $email = 'kemi-' . bin2hex(random_bytes(3)) . '@example.test';
        $me = $this->member($email);
        \AfricaGates\Services\Mail\MailLog::write($email, 'Your voting code', 'otp', 'sent');
        \AfricaGates\Services\Mail\MailLog::write('someone-else@example.test', 'Not yours', 'otp', 'sent');
        $d = $me->run('my_email_delivery')['data'];
        $this->assertSame(['Your voting code'], array_column($d['recent'], 'subject'), 'their mail, and only theirs');
        $this->assertFalse($d['blocked']);

        \AfricaGates\Services\Mail\Suppression::record($email, \AfricaGates\Services\Mail\Suppression::BOUNCE, 'smtp');
        $this->assertTrue($me->run('my_email_delivery')['data']['blocked']);
        $this->assertFalse($this->guest()->run('my_email_delivery')['ok'], 'a guest is never offered it');
    }

    public function test_a_held_comment_is_said_to_be_waiting_not_lost(): void
    {
        $email = 'kemi-' . bin2hex(random_bytes(3)) . '@example.test';
        $me = $this->member($email);
        DB::table('gates_comments')->insert(['target_type' => 'thread', 'target_id' => 1, 'author_name' => 'Kemi',
            'author_email' => $email, 'author_email_hash' => hash('sha256', $email), 'body' => 'Congratulations to every nominee!',
            'status' => 'quarantined', 'created_at' => date('Y-m-d H:i:s')]);
        $d = $me->run('my_held_posts')['data'];
        $this->assertSame('Congratulations to every nominee!', $d['held'][0]['start']);
    }

    public function test_the_playbook_names_only_tools_that_exist_and_the_guide_holds_them(): void
    {
        $rules = (new \ReflectionClassConstant(SupportAgentService::class, 'AGENT_RULES'))->getValue();
        $this->assertStringContainsString('THE SITUATIONS PEOPLE ACTUALLY BRING', $rules);
        preg_match_all('/\b([a-z]+(?:_[a-z]+)+)\b/', $rules, $m);
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'M', 'email' => 'pb-' . bin2hex(random_bytes(3)) . '@example.test',
            'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $all = array_column((new SupportContext($uid, 'pb@example.test'))->tools(), 'name');
        foreach (array_unique($m[1]) as $tool) {
            $this->assertContains($tool, $all, "the playbook sends the agent to $tool, which does not exist");
        }
        foreach (['mail_health', 'sign_in_help', 'order_lookup', 'registration_lookup', 'claim_profile', 'my_email_delivery', 'my_held_posts'] as $t) {
            $this->assertContains($t, SupportAgentService::GUIDE_TOOLS, $t);
        }
    }
}
