<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * /giving, REBUILT (13 Oct 2026) — and the rules its old guard tests held.
 *
 * `pages/donate.twig` was destroyed on 3 Oct with the old layout and never written again,
 * so every visitor to /giving — the footer, the menu, the homepage's campaign card — got a
 * 500. GivingFormFlowTest and DonationGoalTest went with it. The inventory
 * (pages--donate.md) says the rebuild re-asserts each rule against the new markup; these
 * are those rules, read off the rendered page rather than off the template's source.
 */
final class GivingPageTest extends TestCase
{
    private function withPaystack(callable $fn): mixed
    {
        $keys = ['PAYSTACK_SECRET_KEY' => 'sk_test_x', 'PAYSTACK_PUBLIC_KEY' => 'pk_test_x'];
        foreach ($keys as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
        try { return $fn(); }
        finally { foreach (array_keys($keys) as $k) { putenv($k); unset($_ENV[$k]); } }
    }

    private function controller(): \AfricaGates\Controllers\DonationController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Controllers\DonationController::class);
    }

    /** @return array{0:int,1:string} */
    private function page(string $query = '', array $args = []): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/giving' . ($query !== '' ? '?' . $query : ''));
        parse_str($query, $q);
        $res = $this->controller()->page($req->withQueryParams($q), new Response(), $args);
        return [$res->getStatusCode(), (string) $res->getBody()];
    }

    public function test_the_page_renders_instead_of_a_500(): void
    {
        [$status, $html] = $this->page();
        $this->assertSame(200, $status);
        $this->assertSame(1, substr_count($html, '<h1'), 'one heading, moved by CSS between phone and desktop');
        $this->assertStringContainsString('Fund the next generation of leaders', $html);
    }

    /** The checkout is an ADDRESS, so Back, a reload and a link in a message all land on it. */
    public function test_give_now_is_a_link_to_the_checkout_view(): void
    {
        $html = $this->withPaystack(fn () => $this->page()[1]);
        $this->assertStringContainsString('href="/giving?step=give#give"', $html);
        $this->assertStringNotContainsString('data-gv-form', $html, 'the campaign view carries no form');

        $co = $this->withPaystack(fn () => $this->page('step=give')[1]);
        $this->assertStringContainsString('data-gv-form', $co);
        $this->assertMatchesRegularExpression('~<form[^>]+id="give"[^>]+method="post"[^>]+action="/giving"~', $co);
        $this->assertStringContainsString('form="give"', $co, 'the phone action bar submits the form it sits outside');
    }

    /** A refused checkout returns to the checkout, where the reason and the fields are. */
    public function test_a_refusal_lands_on_the_checkout_with_its_reason(): void
    {
        $html = $this->withPaystack(fn () => $this->page('give=low')[1]);
        $this->assertStringContainsString('data-gv-form', $html);
        $this->assertStringContainsString('That amount is below the minimum we can process.', $html);
    }

    /** AND THE MONTHLY OPTION IS NEVER PRE-SELECTED — only the donor's own "Give monthly" does. */
    public function test_a_standing_order_is_never_the_default(): void
    {
        $co = $this->withPaystack(fn () => $this->page('step=give')[1]);
        if (!str_contains($co, 'name="monthly"')) $this->markTestSkipped('recurring giving is not available in this environment');
        $this->assertMatchesRegularExpression('~name="monthly" value="0" checked~', $co);
        $this->assertDoesNotMatchRegularExpression('~name="monthly" value="1" checked~', $co);

        $asked = $this->withPaystack(fn () => $this->page('step=give&freq=monthly')[1]);
        $this->assertMatchesRegularExpression('~name="monthly" value="1" checked~', $asked);
    }

    /** THE PAGE SAYS A DONATION BUYS NO VOTES, at the moment the decision is made. */
    public function test_the_form_states_that_a_gift_buys_no_votes(): void
    {
        $co = $this->withPaystack(fn () => $this->page('step=give')[1]);
        $this->assertStringContainsString('A donation buys no votes and changes no score', $co);
        $this->assertStringContainsString('href="/integrity"', $co);
    }

    /** "₦0 raised" beside an ask reads as a verdict; with nothing confirmed the space says so. */
    public function test_nothing_raised_is_never_printed_as_zero(): void
    {
        $html = $this->withPaystack(fn () => $this->page()[1]);
        $this->assertStringNotContainsString('₦0 raised', $html);
        $this->assertStringContainsString('Be among the first to give', $html);
    }

    /** With no provider the page says how to give rather than drawing a button that cannot. */
    public function test_no_provider_is_said_and_no_button_is_drawn(): void
    {
        [, $html] = $this->page();
        $this->assertStringNotContainsString('?step=give', $html);
        $this->assertStringContainsString('Online giving is being set up', $html);
        $this->assertStringNotContainsString('Card, bank transfer or USSD. Every gift', $html, 'and promises no payment methods it cannot take');
    }

    /** A closed appeal 404s with its reason PRINTED, never a silent fall-through to the fund. */
    public function test_a_closed_appeal_says_so(): void
    {
        [$status, $html] = $this->page('', ['slug' => 'no-such-organisation']);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('This appeal is closed', $html);
        $this->assertStringContainsString('Nothing was charged', $html);
        $this->assertStringNotContainsString('data-gv-form', $html);
    }

    /** "Other amount" is its own field, so the form needs no script — and a typed figure wins. */
    public function test_a_typed_amount_is_the_donors_answer(): void
    {
        $this->withPaystack(function (): void {
            $req = (new ServerRequestFactory())->createServerRequest('POST', '/giving')
                ->withParsedBody(['amount' => '5000', 'amount_other' => '50', 'email' => 'donor@example.com', 'provider' => 'paystack']);
            $res = $this->controller()->start($req, new Response());
            $this->assertStringContainsString('give=low', $res->getHeaderLine('Location'),
                'the preset of 5,000 would pass; only reading the typed 50 refuses it');
        });
    }
}
