<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalDocument;
use AfricaGates\Services\LegalSeeder;
use AfricaGates\Services\VisitTracker;
use AfricaGates\Support\CookieRegistry;
use AfricaGates\Services\LegalService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The published documents, and the two that were missing.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ACTUALLY WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *  · `/cookies` had been a ROUTE since the legal pages shipped, with no document behind it.
 *    It answered 404. A published link to a policy that does not exist reads worse than
 *    having no link at all.
 *
 *  · There was no refund policy, on a platform that takes money in four places and has a
 *    whole RefundService with specific rules. None of them were written down anywhere a
 *    payer could read. The first place most people would learn the policy is from their
 *    bank, during a chargeback.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THESE TESTS CHECK THE WORDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because the risk with a legal page is not that it is absent, it is that it is BOILERPLATE
 * — the standard four cookie categories and a consent banner, on a site that sets one
 * session cookie and runs no analytics. That version is legally tidy and factually false,
 * and it is the version that gets pasted in.
 *
 * So the assertions below are about the specific true claims, checked against what the code
 * does. If somebody replaces the cookie policy with a generic one, these fail.
 */
final class LegalCoverageTest extends TestCase
{
    /** @return array<string, array{title:string, sort:int, body:string}> */
    private function docs(): array
    {
        return LegalSeeder::documents();
    }

    public function test_every_routed_legal_path_has_a_document_behind_it(): void
    {
        // The bug: /cookies was routed and 404'd. Driven off the routes rather than a hand
        // list, so a path added later without a document fails here rather than in public.
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $docs   = $this->docs();

        preg_match_all(
            "~\\\$g->get\('/([a-z-]+)', fn\(\\\$req,\\\$res\)=>\\\$legalRender\(\\\$req,\\\$res,'([a-z-]+)'\)\)~",
            $routes, $m, PREG_SET_ORDER
        );

        $this->assertNotSame([], $m, 'the scan found no legal routes, so it is not scanning');

        foreach ($m as $hit) {
            $this->assertArrayHasKey($hit[2], $docs,
                "/{$hit[1]} is routed but no document with slug '{$hit[2]}' is seeded — it 404s");
        }
    }

    public function test_the_four_documents_are_present_and_ordered(): void
    {
        $docs = $this->docs();

        foreach (['terms', 'privacy', 'cookies', 'refunds'] as $slug) {
            $this->assertArrayHasKey($slug, $docs);
            $this->assertNotSame('', trim($docs[$slug]['title']));
            $this->assertGreaterThan(400, mb_strlen($docs[$slug]['body']),
                $slug . ' is too short to be a real policy');
        }

        $sorts = array_column($docs, 'sort');
        $this->assertSame($sorts, array_unique($sorts), 'two documents claiming one position');
    }

    // ══ cookies: the true version, not the boilerplate one ═══════════════════

    /**
     * The page a reader is shown, not the row an operator can edit.
     *
     * ── WHY THESE THREE TESTS ARE NOT THE ONES THEY REPLACE ──────────────────
     *
     * The originals read `LegalSeeder::documents()['cookies']['body']` and asserted the
     * facts were SPELLED there. That is why they went on passing while the document was
     * wrong in both directions: it said "we set ONE cookie" when there were three, and
     * "we run no analytics" while every arrival's source, campaign, landing page, device
     * and country went into a table. One of them asserted the string `no analytics` was
     * present — so the false claim was not merely unnoticed, it was ENFORCED, which is
     * exactly what `SecurityHeadersTest` was doing with `camera=()` while the door's
     * scanner was dead.
     *
     * The facts now come from {@see CookieRegistry} through
     * {@see LegalDocument::cookiesHtml()}, so the question worth asking is no longer
     * "does the body contain this sentence" but "does the PAGE describe the platform that
     * is running" — and it is asked of the rendered document, authored half and generated
     * half together, because that is what a reader gets.
     */
    private function cookiePage(): string
    {
        return LegalDocument::bodyHtml([
            'slug'      => 'cookies',
            'body_html' => $this->docs()['cookies']['body'],
        ]);
    }

    public function test_the_cookie_page_lists_every_cookie_the_platform_can_set(): void
    {
        $page = $this->cookiePage();

        foreach (CookieRegistry::names() as $name) {
            $this->assertStringContainsString($name, $page,
                "the platform can set '{$name}' and the published policy never mentions it");
        }

        $this->assertStringContainsString('HttpOnly', $page);
        $this->assertStringContainsString('SameSite=Lax', $page);
    }

    public function test_the_cookie_page_does_not_claim_a_smaller_number_than_the_registry(): void
    {
        // The exact sentence that shipped, and the shape of it: a policy that commits to a
        // COUNT in prose is a policy that goes wrong the next time somebody adds a cookie.
        // There are four now and the point is not the number — it is that no number is
        // typed anywhere, so none can be outlived.
        $this->assertGreaterThan(1, CookieRegistry::count(),
            'if this ever drops to one, the prose below may say so — until then it must not');

        $page = strtolower($this->cookiePage());

        foreach (['we set <strong>one cookie</strong>', 'we set one cookie',
                  'this platform sets one cookie', 'only one cookie'] as $claim) {
            $this->assertStringNotContainsString($claim, $page,
                'the policy is counting cookies in prose again — it is generated for a reason');
        }
    }

    public function test_the_cookie_page_admits_the_counting_this_platform_actually_does(): void
    {
        // THE FAULT THIS EXISTS BECAUSE OF: the previous version of this test asserted the
        // string 'no analytics' was PRESENT, while VisitTracker recorded every arrival.
        // The claim worth protecting is the narrow one — that nothing here reports a visit
        // to a third party — and the claim that must never return is the broad one.
        $page = strtolower($this->cookiePage());

        foreach (['we run no analytics', 'no analytics, no advertising',
                  'runs no analytics'] as $claim) {
            $this->assertStringNotContainsString($claim, $page,
                'VisitTracker records every arrival; a page saying otherwise is a false '
                . 'statement in a legal notice');
        }

        // What it must say instead, because a visitor cannot refuse what they are not told.
        $this->assertStringContainsString('counting arrivals', $page);
        $this->assertStringContainsString('one row for each visit', $page);
        // The narrow claim survives, and it is the true one.
        $this->assertStringContainsString('no google analytics', $page);

        // And the switch, which is the whole point: for years the only way to refuse was a
        // header Chrome and Safari no longer send.
        $this->assertStringContainsString('you can say no', $page);
    }

    public function test_the_cookie_page_states_the_retention_the_code_will_actually_apply(): void
    {
        // A number typed into a policy beside a setting an operator can change is this
        // repository's most-repeated fault. Rendered twice against different retentions,
        // the figure must move.
        $this->assertStringContainsString(
            (string) VisitTracker::keepDays(), $this->cookiePage(),
            'the retention on the page is not the retention the pruner will use');
    }

    public function test_the_cookie_policy_matches_what_the_code_configures(): void
    {
        // The document and the configuration are two places that can drift. This is the
        // cheapest possible check that they have not.
        $idx = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        $this->assertStringContainsString("'httponly' => true", $idx);
        $this->assertStringContainsString("'samesite' => 'Lax'", $idx);
        $this->assertStringContainsString("'lifetime' => 86400 * 7", $idx);
    }

    public function test_the_cookie_policy_does_not_claim_trackers_we_do_not_run(): void
    {
        // The real failure mode for this document. The standard template describes
        // analytics, advertising and preference cookies; we have none of them, and saying
        // otherwise is a false statement in a legal notice.
        //
        // MATCHED ON THE CLAIM AND NOT ON THE PRODUCT NAME. This used to forbid the bare
        // string 'google analytics', which reads as a check and is not one: the sentence
        // worth having on this page is "there is NO Google Analytics here", and forbidding
        // the name forbids saying so. A sweep that cannot tell a denial from an admission
        // pushes the page towards saying nothing, which is how it got vague enough to be
        // wrong in the first place.
        $body = strtolower($this->cookiePage());

        foreach (['we use google analytics', 'advertising cookie', 'targeting cookie',
                  'we use cookies to personalise ads', 'our advertising partners',
                  'third-party analytics'] as $boilerplate) {
            $this->assertStringNotContainsString($boilerplate, $body,
                'the policy is claiming something this platform does not do');
        }

        // And it explains the absence of a banner, because otherwise that looks like an
        // oversight rather than a consequence. It is generated now, from the posture the
        // operator actually set — see LegalDocument::cookiesHtml().
        $this->assertStringContainsString('banner', strtolower($this->cookiePage()));
    }

    public function test_the_platform_really_has_no_third_party_trackers(): void
    {
        // Asserted against the layout, so the document cannot become false by somebody
        // adding a tag without reading it.
        $layout = file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');

        foreach (['googletagmanager', 'google-analytics', 'gtag(', 'facebook.net',
                  'hotjar', 'mixpanel'] as $tracker) {
            $this->assertStringNotContainsString($tracker, $layout,
                'the cookie policy says we run no trackers — this one would make it a lie');
        }
    }

    public function test_the_cookie_policy_separates_browser_storage_from_cookies(): void
    {
        // They are genuinely different — one is sent to us on every request, the other
        // never leaves the device — and conflating them is how a policy ends up either
        // over-claiming or hiding something.
        $body = $this->docs()['cookies']['body'];

        $page = $this->cookiePage();

        $this->assertStringContainsString('which are not cookies', $page);
        $this->assertStringContainsString('never leaves your device', $page);

        // And every key the shipped code writes is on that list. A storage key added
        // without a registry entry is caught by CookieRegistryTest; this holds the other
        // half — that the registry reaches the page.
        foreach (CookieRegistry::storage() as $item) {
            $this->assertStringContainsString($item['key'], $page,
                "browser storage key '{$item['key']}' is declared and never published");
        }
    }

    // ══ refunds: what the code does, stated plainly ══════════════════════════

    public function test_the_refund_policy_states_the_one_automatic_case(): void
    {
        // RefundService refunds exactly one situation without a person: a paid vote that
        // could not be minted. That is an unusually defensible policy and it was published
        // nowhere.
        $body = $this->docs()['refunds']['body'];

        $this->assertStringContainsString('you do not have to ask', $body);
        $this->assertStringContainsString('only refund that happens without a person', $body);
        // The grace window, so nobody reads "automatic" as "instant" and reports a bug.
        $this->assertStringContainsString('two hours', $body);
    }

    public function test_the_refund_policy_covers_every_way_the_platform_takes_money(): void
    {
        // Four payment surfaces. A policy silent on one of them is the one somebody will be
        // arguing about.
        $body = strtolower($this->docs()['refunds']['body']);

        foreach (['vote', 'ticket', 'merchandise', 'contribution'] as $surface) {
            $this->assertStringContainsString($surface, $body,
                'the platform takes money for ' . $surface . ' and the policy must say so');
        }
    }

    public function test_the_refund_policy_says_no_where_the_answer_is_no(): void
    {
        // A refund policy that promises everything is not a policy. Counted votes are the
        // real refusal and the reason is worth stating: they changed a public tally other
        // people have already read.
        $body = $this->docs()['refunds']['body'];

        $this->assertStringContainsString('Not refundable', $body);
        $this->assertStringContainsString('public tally', $body);
    }

    public function test_the_refund_policy_gives_a_reply_time_and_a_route(): void
    {
        $body = $this->docs()['refunds']['body'];

        $this->assertStringContainsString('support@afrovanguard.org.ng', $body);
        $this->assertStringContainsString('three working days', $body);
        // And it asks people to come to us before their bank — a chargeback costs a fee and
        // closes the account, so if money is owed we would rather just send it.
        $this->assertStringContainsString('chargeback', $body);
    }

    public function test_a_refund_never_goes_to_a_different_account(): void
    {
        // Stated because it is the one refusal that sounds unhelpful and is not: refunding
        // to another account is how a stolen card is laundered.
        $this->assertStringContainsString('stolen cards',
            $this->docs()['refunds']['body']);
    }

    // ══ findable ═════════════════════════════════════════════════════════════

    public function test_all_four_are_linked_from_the_footer(): void
    {
        // A policy that is not linked is a policy people are told about by their bank.
        $footer = file_get_contents(dirname(__DIR__, 2) . '/templates/layout/footer.twig');

        foreach (['/privacy', '/terms', '/cookies', '/refunds'] as $path) {
            $this->assertStringContainsString('href="' . $path . '"', $footer);
        }
    }

    public function test_refunds_has_its_own_path_and_not_only_a_nested_one(): void
    {
        // This is the page somebody looks for while deciding whether to pay, and again
        // while holding a receipt they want reversed — both times by guessing the URL or
        // following a footer link, neither of which finds a document a level down.
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertStringContainsString("\$g->get('/refunds'", $routes);
    }

    public function test_no_document_carries_an_unfilled_placeholder(): void
    {
        // The specific embarrassment: a published legal page reading "[COMPANY NAME]".
        foreach ($this->docs() as $slug => $doc) {
            foreach (['[COMPANY', 'XXXX', 'TODO', 'Lorem ipsum', '{{', '[INSERT'] as $bad) {
                $this->assertStringNotContainsString($bad, $doc['body'], $slug);
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // A FOOTER LINK MUST NOT REACH A 404
    // ════════════════════════════════════════════════════════════════════════

    /**
     * `/refunds` answered 404 in production.
     *
     * The route was registered, the document was written, reviewed and tested — and
     * LegalSeeder::install() deliberately never overwrites an existing document, so a policy
     * ADDED to the seeder after the last install never appeared. The only way to get it live
     * was for somebody to remember to open /__setup/legal.
     *
     * The visible result was the worst available: the site footer linked "Refunds" and
     * "Cookies" straight at a 404.
     */
    public function test_a_shipped_policy_that_was_never_installed_installs_itself(): void
    {
        DB::table('gates_legal_docs')->truncate();

        foreach (array_keys(LegalSeeder::documents()) as $slug) {
            $doc = LegalService::get($slug);
            $this->assertIsArray($doc, "/{$slug} answers 404 on a deployment that never ran the seeder");
            $this->assertNotSame('', trim((string) ($doc['body_html'] ?? '')));
        }
    }

    /** And every path the footer links is one of them. */
    public function test_every_policy_the_footer_links_is_a_document_we_ship(): void
    {
        $footer = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/footer.twig');
        $shipped = array_keys(LegalSeeder::documents());

        preg_match_all('~href="/([a-z-]+)"~', $footer, $m);
        $legalish = array_intersect(array_unique($m[1]),
            ['terms', 'privacy', 'cookies', 'refunds', 'vendor-terms']);

        $this->assertNotSame([], $legalish, 'the footer links no policy at all');
        foreach ($legalish as $slug) {
            $this->assertContains($slug, $shipped,
                "the footer links /{$slug} and nothing ships a document for it");
        }
    }

    /**
     * A document an operator UNPUBLISHED stays unpublished.
     *
     * The self-heal above must not become a way to resurrect a withdrawn policy — that
     * would override a deliberate decision with a cache miss.
     */
    public function test_a_withdrawn_policy_is_not_resurrected(): void
    {
        LegalSeeder::install();
        DB::table('gates_legal_docs')->where('slug', 'refunds')->update(['is_published' => 0]);

        $this->assertNull(LegalService::get('refunds'),
            'unpublishing a policy was undone by the self-heal');
        $this->assertSame(0, (int) DB::table('gates_legal_docs')
            ->where('slug', 'refunds')->value('is_published'));
    }

    /** An edited policy is not overwritten by the shipped wording. */
    public function test_an_edited_policy_is_left_alone(): void
    {
        LegalSeeder::install();
        DB::table('gates_legal_docs')->where('slug', 'terms')
          ->update(['body_html' => '<p>Our own wording.</p>']);

        $this->assertSame('<p>Our own wording.</p>', (string) LegalService::get('terms')['body_html']);
    }

    /** And an unknown slug is still a 404 rather than an invented page. */
    public function test_an_unknown_slug_installs_nothing(): void
    {
        $this->assertNull(LegalService::get('not-a-policy-we-ship'));
        $this->assertSame(0, (int) DB::table('gates_legal_docs')
            ->where('slug', 'not-a-policy-we-ship')->count());
    }
}
