<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The community pages Phase 8 rebuilt WITHOUT A DC — /community, /community/{slug},
 * /community/new, /opportunities, /partner, /judges, /judges/{slug}.
 *
 * All seven were destroyed on 3 Oct with their routes left standing, so each answered 500
 * until rebuilt. These re-assert the rules held by the guards destroyed with them
 * (inventory `pages--community--*.md`, `pages--opportunities.md`, `pages--partner.md`,
 * `pages--judges.md`, `pages--judge.md`), through the REAL router, so a template reading
 * something its controller stopped passing fails here and not in production. Each was
 * watched failing against a planted break before it was trusted.
 */
final class CommunityPagesTest extends TestCase
{
    private function member(): array
    {
        $id = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ngozi Chimamanda Adichie', 'email' => 'ngozi.cp@mailbox.ng',
            'password_hash' => '', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['user_id' => $id, 'user_name' => 'Ngozi Chimamanda Adichie', 'user_email' => 'ngozi.cp@mailbox.ng'];
    }

    private function thread(array $x = []): string
    {
        static $n = 0; $n++;
        $slug = $x['slug'] ?? ('cp-thread-' . $n);
        DB::table('gates_threads')->insert($x + [
            'slug' => $slug, 'title' => 'Thread ' . $n, 'body' => 'Body of thread ' . $n,
            'author_name' => 'Amara Okonkwo', 'author_email_hash' => hash('sha256', 'a@b.ng'),
            'status' => 'approved', 'created_at' => date('Y-m-d H:i:s'), 'last_activity' => date('Y-m-d H:i:s'),
        ]);
        return $slug;
    }

    // ── every route answers ─────────────────────────────────────────────────

    public function test_every_page_answers_200_for_a_guest_and_a_member(): void
    {
        $slug = $this->thread();
        $me   = $this->member();
        foreach (['/community', '/community/' . $slug, '/opportunities', '/partner', '/partner/success', '/judges'] as $uri) {
            $this->assertSame(200, ChromeRender::page($uri)->getStatusCode(), "{$uri} as a guest");
            $this->assertSame(200, ChromeRender::page($uri, $me)->getStatusCode(), "{$uri} as a member");
        }
        $this->assertSame(200, ChromeRender::page('/community/new', $me)->getStatusCode());
    }

    // ── /community ──────────────────────────────────────────────────────────

    public function test_a_guest_is_offered_sign_in_not_a_button_the_server_refuses(): void
    {
        $html = ChromeRender::html('/community');
        $this->assertStringNotContainsString('href="/community/new"', $html);
        $this->assertStringContainsString('href="/account/login?next=%2Fcommunity%2Fnew"', $html);

        $guest = ChromeRender::page('/community/new');
        $this->assertSame(302, $guest->getStatusCode());
        $this->assertStringStartsWith('/account/login', $guest->getHeaderLine('Location'));
    }

    public function test_spaces_are_active_programmes_and_never_a_typed_colour(): void
    {
        DB::table('gates_award_programmes')->insert(['slug' => 'cp-live', 'title' => 'Live Space Awards', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_programmes')->insert(['slug' => 'cp-off', 'title' => 'Retired Space Awards', 'is_active' => 0, 'sort_order' => 2]);
        $html = ChromeRender::html('/community');

        $this->assertStringContainsString('Live Space Awards', $html);
        $this->assertStringNotContainsString('Retired Space Awards', $html, 'an inactive programme (the sandbox is one) is offered as a space');
        $this->assertDoesNotMatchRegularExpression('~style="[^"]*#[0-9a-f]{3,6}~i', $html, 'a space is painted with a typed hex');
    }

    public function test_pinned_and_locked_are_words(): void
    {
        $this->thread(['title' => 'A pinned one', 'is_pinned' => 1]);
        $this->thread(['title' => 'A locked one', 'status' => 'locked']);
        $html = ChromeRender::html('/community');
        $this->assertStringContainsString('>Pinned<', $html);
        $this->assertStringContainsString('Locked — read only', $html);
    }

    // ── a thread ────────────────────────────────────────────────────────────

    public function test_a_reply_is_a_plain_form_and_lands_as_the_members_account(): void
    {
        $slug = $this->thread();
        $me   = $this->member();
        $html = ChromeRender::html('/community/' . $slug, $me);
        $this->assertStringContainsString('action="/community/' . $slug . '/reply"', $html);
        $this->assertDoesNotMatchRegularExpression('~name="author_(name|email)"~', $html, 'the reply form asks for an identity the account already has');

        $res = ChromeRender::page('/community/' . $slug . '/reply', $me, [], 'POST',
            ['_token' => 'test-token', 'body' => 'A reply long enough to be a reply.', 'author_name' => 'Somebody Else']);
        $this->assertSame(302, $res->getStatusCode());
        $row = DB::table('gates_comments')->where('target_type', 'thread')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('Ngozi Chimamanda Adichie', $row->author_name, 'the reply took the name from the form');
    }

    public function test_a_guest_reply_goes_to_sign_in_and_writes_nothing(): void
    {
        $slug = $this->thread();
        $before = DB::table('gates_comments')->count();
        $res = ChromeRender::page('/community/' . $slug . '/reply', [], [], 'POST', ['_token' => 'test-token', 'body' => 'Hello there, everybody.']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith('/account/login', $res->getHeaderLine('Location'));
        $this->assertSame($before, DB::table('gates_comments')->count());
    }

    public function test_a_locked_thread_offers_no_reply_box(): void
    {
        $slug = $this->thread(['status' => 'locked']);
        $html = ChromeRender::html('/community/' . $slug, $this->member());
        $this->assertStringNotContainsString('/reply"', $html);
        $this->assertStringContainsString('takes no new replies', $html);
    }

    public function test_a_thread_body_is_escaped(): void
    {
        $slug = $this->thread(['body' => 'Hi <script>alert(1)</script> there']);
        $html = ChromeRender::html('/community/' . $slug);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── /opportunities ──────────────────────────────────────────────────────

    public function test_opportunities_renders_listings_not_empty_state(): void
    {
        DB::table('gates_opportunities')->insert(['slug' => 'cp-o', 'title' => 'Creative Futures Grant', 'opportunity_type' => 'grant',
            'provider' => 'Lagos Arts Trust', 'apply_url' => 'https://example.org/apply', 'status' => 'active',
            'deadline' => date('Y-m-d', time() + 30 * 86400)]);
        DB::table('gates_cache')->delete();
        $html = ChromeRender::html('/opportunities');
        $this->assertStringContainsString('Creative Futures Grant', $html);
        $this->assertStringContainsString('href="https://example.org/apply"', $html);
        $this->assertStringNotContainsString('Nothing is open right now', $html);
    }

    public function test_with_nothing_open_the_page_says_so_and_invents_nothing(): void
    {
        DB::table('gates_cache')->delete();
        $html = ChromeRender::html('/opportunities');
        $this->assertStringContainsString('Nothing is open right now', $html);
        $this->assertStringNotContainsString('Apply on their site', $html);
    }

    public function test_an_apply_link_is_only_ever_an_http_address(): void
    {
        DB::table('gates_opportunities')->insert(['slug' => 'cp-x', 'title' => 'Odd link', 'opportunity_type' => 'grant',
            'provider' => 'X', 'apply_url' => 'javascript:alert(1)', 'status' => 'active']);
        DB::table('gates_opportunities')->insert(['slug' => 'cp-y', 'title' => 'No link', 'opportunity_type' => 'grant',
            'provider' => 'Y', 'apply_url' => null, 'status' => 'active']);
        DB::table('gates_cache')->delete();
        $html = ChromeRender::html('/opportunities');
        $this->assertStringContainsString('Odd link', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('href="#"', $html, 'a button that goes nowhere is back');
    }

    // ── /partner ────────────────────────────────────────────────────────────

    public function test_a_refused_enquiry_keeps_every_field_and_says_why(): void
    {
        $res = ChromeRender::page('/partner', [], [], 'POST', ['_token' => 'test-token',
            'organisation' => 'Bright Futures', 'contact_name' => 'Ada Obi', 'contact_email' => 'not-an-address',
            'tier' => 'Continental Patron', 'message' => 'We would like to sponsor a category.']);
        $html = (string) $res->getBody();
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('valid email', $html);
        $this->assertStringContainsString('value="Bright Futures"', $html);
        $this->assertMatchesRegularExpression('~<option value="Continental Patron" selected~', $html);
        $this->assertStringContainsString('We would like to sponsor a category.', $html);
    }

    public function test_the_partner_page_prints_no_zero_and_no_unbacked_label(): void
    {
        $html = ChromeRender::html('/partner');
        $this->assertStringNotContainsString('Verified profiles', $html, 'approved is not verified');
        $this->assertDoesNotMatchRegularExpression('~<dd>0</dd>~', $html, 'a partner page announcing a zero');
    }

    // ── /judges ─────────────────────────────────────────────────────────────

    public function test_the_judges_page_lists_real_judges_filters_by_url_and_links_each(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 91, 'slug' => 'cp-a', 'title' => 'Alpha Awards', 'is_active' => 1]);
        DB::table('gates_award_programmes')->insert(['id' => 92, 'slug' => 'cp-b', 'title' => 'Beta Awards', 'is_active' => 1]);
        DB::table('gates_judges')->insert(['name' => 'Judge Alpha', 'email' => 'ja@mailbox.ng', 'programme_ids' => '[91]', 'is_active' => 1, 'country_code' => 'NG']);
        DB::table('gates_judges')->insert(['name' => 'Judge Beta', 'email' => 'jb@mailbox.ng', 'programme_ids' => '[92]', 'is_active' => 1]);
        DB::table('gates_judges')->insert(['name' => 'Rehearsal Judge', 'email' => 'x@' . \AfricaGates\Services\DemoSeeder::MAIL_DOMAIN, 'programme_ids' => '[91]', 'is_active' => 1]);

        $all = ChromeRender::html('/judges');
        $this->assertStringContainsString('Judge Alpha', $all);
        $this->assertStringContainsString('Judge Beta', $all);
        $this->assertStringContainsString('Nigeria', $all);
        $this->assertStringNotContainsString('Rehearsal Judge', $all, 'the sandbox reached the public roster');
        $this->assertStringContainsString('href="/judges?programme=cp-a"', $all);

        $a = ChromeRender::html('/judges?programme=cp-a');
        $this->assertStringContainsString('Judge Alpha', $a);
        $this->assertStringNotContainsString('Judge Beta', $a);

        $id   = (int) DB::table('gates_judges')->where('name', 'Judge Alpha')->value('id');
        $page = ChromeRender::page('/judges/' . $id . '-judge-alpha');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('application/ld+json', (string) $page->getBody(), 'a judge page is a name page and must carry Person');
    }

    public function test_one_h1_on_each_page(): void
    {
        $slug = $this->thread();
        foreach (['/community', '/community/' . $slug, '/opportunities', '/partner', '/judges'] as $uri) {
            $this->assertSame(1, preg_match_all('~<h1\b~', ChromeRender::html($uri)), "{$uri} does not have exactly one h1");
        }
    }
}
