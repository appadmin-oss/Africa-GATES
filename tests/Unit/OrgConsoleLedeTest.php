<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OrgPayout;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The console opens with the thing it is a console FOR.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PAGE'S OWN FAULT, REPEATED ON THE HALF IT DID NOT REACH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `pages/org/dashboard.twig` opens with a note about people arriving with three questions
 * — where am I, what is holding it up, what can I do about it — answered in three
 * disconnected places, and one strip built to answer all three at the top. That was done
 * for the ELIGIBILITY half. The money half, which is what an organisation that is already
 * live opens this console for, still had the fault:
 *
 *   · A live partner's first screen was one green box saying their organisation is live,
 *     and a cumulative line. A cumulative line states no value anywhere — it is a shape.
 *   · The four figures opened section 03, behind the rail.
 *   · `available` — the only number here that can be ACTED on — was the fourth tile of
 *     four in "Donations", while the form that spends it sat in "Getting paid", four
 *     sections further down. A figure and its action in different rooms is the exact split
 *     the strip above them was built to close.
 *
 * ── THE INVARIANT, WHICH IS NOT "THE STRIP EXISTS" ───────────────────────────
 *
 * A strip that says money is ready to request, above a form that then declines, is worse
 * than no strip: the reader goes hunting for the refusal's cause in the wrong section, and
 * the console has lied about money. So what is pinned here is the AGREEMENT — the offer
 * and the form appear and disappear together, across every state the form gates on.
 *
 * Driven through the real router and the real controller rather than by reading the
 * template, because the template's conditions are only right if the values reaching them
 * are the ones the form uses.
 */
final class OrgConsoleLedeTest extends TestCase
{
    private int $orgId = 0;
    private int $userId = 0;
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'name' => 'Kigali Signal Trust', 'slug' => 'kigali-signal-trust',
            'status' => 'approved', 'contact_email' => 'compliance@example.org',
            'platform_fee_bps' => 1000,
        ]);
        $this->userId = (int) DB::table('gates_org_users')->insertGetId([
            'org_id' => $this->orgId, 'email' => 'owner@example.org', 'role' => 'owner',
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);
        $_SESSION['org_user_id'] = $this->userId;
        $_SESSION['org_id']      = $this->orgId;

        $this->gift(400000, 40000, 'R1');
        $this->gift(120000, 12000, 'R2');

        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $this->app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($this->app);
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(false, false, false);
    }

    private function gift(int $gross, int $fee, string $ref): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'A supporter', 'donor_email' => 'd@example.org', 'show_name' => 1,
            'amount_naira' => $gross, 'platform_fee_naira' => $fee,
            'recipient_org_id' => $this->orgId, 'status' => 'confirmed',
            'payment_ref' => $ref, 'bonus_votes' => 0,
            'created_at' => '2026-09-01 10:00:00', 'confirmed_at' => '2026-09-01 10:00:00',
        ]);
    }

    private function console(): string
    {
        return (string) $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/org'))->getBody();
    }

    /**
     * The page BELOW its stylesheet.
     *
     * Every class named here is also declared in this template's own `head_styles`, so a
     * naive search for `pd__grid--lede` matches the CSS rule and reports the summary as
     * present on a console that draws nothing — and, worse, finds it at a byte offset
     * inside `<head>`, which makes an ordering check pass for a reason that has nothing to
     * do with where anything is on the page.
     *
     * Every style block is removed rather than cut to the first or the last one: partials
     * here ship their own nonced `<style>` inline with the markup they belong to, so a cut
     * at the LAST closing tag throws away most of the page and an ordering check then
     * fails on markup that is present and correctly placed.
     */
    private function markup(string $body): string
    {
        return (string) preg_replace('~<style\\b[^>]*>.*?</style>~is', '', $body);
    }

    /** Does the page offer a payout, and does it actually carry the form to take one? */
    private function offersAndCarries(string $body): array
    {
        return [
            str_contains($body, 'Request a payout</a>'),
            str_contains($body, 'action="/org/payout"'),
        ];
    }

    // ════════════════════════════════════════════════════════════════════════

    /**
     * The figures open the console, and they are no longer inside a later section.
     */
    public function test_the_money_is_on_the_first_screen_and_not_behind_the_rail(): void
    {
        $body = $this->markup($this->console());

        $lede      = strpos($body, 'pd__grid--lede');
        $donations = strpos($body, 'id="donations"');

        $this->assertNotFalse($lede, 'the console no longer opens with the money');
        $this->assertNotFalse($donations, 'the donations section is gone');
        $this->assertLessThan($donations, $lede,
            'the figures are back inside a section the reader has to go and find');

        $this->assertStringContainsString('₦520,000', $body, 'the received total is not drawn');
        $this->assertStringContainsString('₦468,000', $body, 'the net is not drawn');
    }

    /**
     * THE ONE THAT MATTERS: the offer and the form agree, in every state.
     *
     * Each case moves exactly one of the three things `OrgPayout` and the form gate on.
     */
    public function test_the_lede_never_offers_a_payout_the_form_would_refuse(): void
    {
        [$offers, $carries] = $this->offersAndCarries($this->console());
        $this->assertTrue($offers,  'an owner with a requestable balance is not offered it');
        $this->assertTrue($carries, 'this test is proving nothing: the form is absent too');

        // 1 · below the minimum
        DB::table('gates_donations')->where('recipient_org_id', $this->orgId)
            ->update(['amount_naira' => 300, 'platform_fee_naira' => 30]);
        [$offers, $carries] = $this->offersAndCarries($body = $this->console());
        $this->assertFalse($offers,  'a balance under the minimum is offered as requestable');
        $this->assertFalse($carries, 'the form appeared for a balance under the minimum');
        $this->assertStringContainsString('below the ₦' . number_format(OrgPayout::MIN_NAIRA), $body,
            'the balance is simply withheld with no reason — the threshold is the answer '
            . 'to the commonest question this console gets');

        // 2 · a viewer, who cannot move money
        DB::table('gates_donations')->where('recipient_org_id', $this->orgId)
            ->update(['amount_naira' => 400000, 'platform_fee_naira' => 40000]);
        DB::table('gates_org_users')->where('id', $this->userId)->update(['role' => 'viewer']);
        [$offers, $carries] = $this->offersAndCarries($this->console());
        $this->assertFalse($offers,  'a view-only member is offered a payout they cannot request');
        $this->assertFalse($carries, 'a view-only member is shown the payout form');

        // 3 · a suspended organisation
        DB::table('gates_org_users')->where('id', $this->userId)->update(['role' => 'owner']);
        DB::table('gates_partner_orgs')->where('id', $this->orgId)->update(['status' => 'suspended']);
        [$offers, $carries] = $this->offersAndCarries($this->console());
        $this->assertFalse($offers,  'a suspended organisation is offered a payout');
        $this->assertFalse($carries, 'a suspended organisation is shown the payout form');
    }

    /**
     * A console with no money does not draw four zeroes at somebody.
     *
     * The public donation page suppresses its figures for the same reason, and the rule is
     * worth keeping here: "₦0 received" on an organisation's own dashboard is that sentence
     * said four times to the person it is about, on the day they are least able to act on it.
     */
    public function test_an_organisation_with_no_gifts_is_not_shown_a_wall_of_zeroes(): void
    {
        DB::table('gates_donations')->where('recipient_org_id', $this->orgId)->delete();

        $body = $this->markup($this->console());
        $this->assertStringNotContainsString('pd__grid--lede', $body,
            'an organisation with nothing received is shown an empty summary');
        $this->assertStringNotContainsString('pd-ready', $body,
            'an organisation with nothing received is told about a balance');
    }
}
