<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Controllers\ShopCheckoutController;

/**
 * Every `?checkout=<code>` the checkout can emit must have something to SAY.
 *
 * The bug this exists for: the controller emitted twelve codes and the notice on
 * pages/shop/index.twig had messages for ten. The two it was missing were the two that
 * needed words most — `mismatch` (the gateway says money moved but the amount does not
 * reconcile) and `gone` (something sold out mid-checkout). The template guards on
 * `if(!row) return`, so both rendered an EMPTY box: a customer who had just been debited
 * was redirected to the shop and told nothing at all, and their likely next move is to pay
 * again or open a dispute.
 *
 * Asserted by reading both files rather than by booting a browser, because the failure was
 * never in behaviour — each half was individually correct. It was the two lists drifting
 * apart, and that is what this locks together.
 */
class ShopCheckoutNoticeTest extends TestCase
{
    private const CONTROLLER = __DIR__ . '/../../src/Controllers/ShopCheckoutController.php';
    private const TEMPLATE   = __DIR__ . '/../../templates/pages/shop/index.twig';

    /** @return list<string> every code the controller can put in `?checkout=` */
    private function emittedCodes(): array
    {
        $src = (string) file_get_contents(self::CONTROLLER);
        $codes = [];
        // $bail('why') — including the ternary form, $bail($cond ? 'a' : 'b').
        preg_match_all("/\\\$bail\\(\s*(?:[^)]*?\?\s*)?'([a-z]+)'(?:\s*:\s*'([a-z]+)')?/", $src, $m);
        foreach ($m[1] as $c) if ($c !== '') $codes[] = $c;
        foreach ($m[2] as $c) if ($c !== '') $codes[] = $c;
        // Direct redirects that build the query string themselves.
        preg_match_all("/checkout=([a-z]+)/", $src, $m2);
        foreach ($m2[1] as $c) $codes[] = $c;

        return array_values(array_unique($codes));
    }

    /** @return list<string> every code the shop notice has a message for */
    private function templateCodes(): array
    {
        $tpl = (string) file_get_contents(self::TEMPLATE);
        // Keys of the `var m={...}` map: `name: ['tone','text']`
        if (!preg_match('/var m=\{(.*?)\n    \};/s', $tpl, $block)) {
            $this->fail('Could not find the checkout message map in ' . self::TEMPLATE);
        }
        preg_match_all("/^\s*([a-z]+):\s*\['(?:err|warn)'/m", $block[1], $m);

        return array_values(array_unique($m[1]));
    }

    public function test_every_emitted_checkout_code_has_a_message(): void
    {
        $emitted  = $this->emittedCodes();
        $template = $this->templateCodes();

        // Guard the guard: if the regexes stop matching, the test must fail loudly rather
        // than pass on two empty lists.
        $this->assertGreaterThan(8, count($emitted), 'Expected to find the controller bail codes');
        $this->assertGreaterThan(8, count($template), 'Expected to find the template message keys');

        $missing = array_values(array_diff($emitted, $template));
        $this->assertSame([], $missing, sprintf(
            "These ?checkout= codes render an EMPTY notice — the shopper is bounced back and told nothing: %s",
            implode(', ', $missing)
        ));
    }

    public function test_mismatch_is_worded_as_money_moved_not_as_a_failure(): void
    {
        $tpl = (string) file_get_contents(self::TEMPLATE);
        preg_match("/mismatch:\s*\['(err|warn)','([^']*)'/", $tpl, $m);
        $this->assertNotEmpty($m, 'No `mismatch` entry in the checkout message map');

        // Not the red error tone: the gateway says the money moved.
        $this->assertSame('warn', $m[1], '`mismatch` must not be styled as a failure');
        // And it must tell them not to pay twice, which is the whole point of the message.
        // `.{0,7}` rather than `\W?`: the apostrophe is written as the JS escape \u2019 in
        // the template source, which is six characters here, not one.
        $this->assertMatchesRegularExpression(
            '/don.{0,7}t pay again/i',
            $m[2],
            '`mismatch` must tell a debited customer not to pay again'
        );
    }

    public function test_take_retry_reads_and_clears(): void
    {
        $_SESSION = ['shop_checkout_retry' => [
            'name' => 'Amara Okonkwo', 'email' => 'a@example.com', 'phone' => '+2348012345678',
            'address' => '12 Market Rd, Nsukka', 'region' => 'South East',
            'provider' => 'paystack', 'discount' => 'GATES10',
        ]];

        $first = ShopCheckoutController::takeRetry();
        $this->assertSame('Amara Okonkwo', $first['name']);
        $this->assertSame('12 Market Rd, Nsukka', $first['address']);
        $this->assertTrue($first['any']);
        $this->assertArrayNotHasKey('shop_checkout_retry', $_SESSION, 'The flash must be cleared on read');

        // Second read is blank: the prefill belongs to exactly the one page load it was
        // written for, so a shared phone never re-opens somebody else's address.
        $second = ShopCheckoutController::takeRetry();
        $this->assertSame('', $second['name']);
        $this->assertFalse($second['any']);
    }

    public function test_take_retry_is_blank_when_nothing_was_flashed(): void
    {
        $_SESSION = [];
        $r = ShopCheckoutController::takeRetry();
        $this->assertFalse($r['any']);
        $this->assertSame(['name','email','phone','address','region','provider','discount','any'],
                          array_keys($r));
    }
}
