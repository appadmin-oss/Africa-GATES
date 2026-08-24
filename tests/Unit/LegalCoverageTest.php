<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalSeeder;
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

    public function test_the_cookie_policy_describes_the_one_cookie_we_actually_set(): void
    {
        $body = $this->docs()['cookies']['body'];

        $this->assertStringContainsString('PHPSESSID', $body);
        $this->assertStringContainsString('HttpOnly', $body);
        $this->assertStringContainsString('SameSite=Lax', $body);
        // Seven days, matching session_set_cookie_params() in public/index.php.
        $this->assertStringContainsString('Seven days', $body);
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
        $body = strtolower($this->docs()['cookies']['body']);

        foreach (['google analytics', 'advertising cookie', 'targeting cookie',
                  'we use cookies to personalise ads'] as $boilerplate) {
            $this->assertStringNotContainsString($boilerplate, $body);
        }

        $this->assertStringContainsString('no analytics', $body);
        // And it explains the ABSENCE of a banner, because otherwise that looks like an
        // oversight rather than a consequence of not tracking anybody.
        $this->assertStringContainsString('banner', $body);
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

        $this->assertStringContainsString('which are not cookies', $body);
        $this->assertStringContainsString('It never leaves your device', $body);
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
}
