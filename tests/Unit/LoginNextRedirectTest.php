<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\AccountController;
use PHPUnit\Framework\TestCase;

/**
 * THE POST-LOGIN DESTINATION CANNOT LEAVE THE SITE.
 *
 * `?next=` survives a sign-in and ends up in a Location header, so `safeNext()` is the
 * whole of the open-redirect defence on the member door. "Begins with one slash" is the
 * obvious check and it is not enough: the WHATWG URL parser every browser implements
 * strips tab and newline from anywhere in a URL before resolving it, so `/\t/evil.example/`
 * passes a check on the second character and is followed as `//evil.example/`.
 */
final class LoginNextRedirectTest extends TestCase
{
    private function safeNext(?string $raw): ?string
    {
        $c = (new \ReflectionClass(AccountController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(AccountController::class, 'safeNext');
        return $m->invoke($c, $raw);
    }

    public function test_a_browser_stripped_character_cannot_make_a_protocol_relative_url(): void
    {
        foreach ([
            "/\t/evil.example/",
            "/\n/evil.example/",
            "/\r/evil.example/",
            "/\t\\evil.example/",
            "/\x00/evil.example",
            "/\x7f/evil.example",
            "/ /evil.example",
            '//evil.example',
            '/\\evil.example',
            'https://evil.example',
        ] as $in) {
            $this->assertNull($this->safeNext($in), 'safeNext(' . json_encode($in) . ')');
        }
    }

    public function test_an_ordinary_local_path_still_goes_through(): void
    {
        $this->assertSame('/vote/12?c=3', $this->safeNext('/vote/12?c=3'));
        $this->assertSame('/account/points', $this->safeNext('  /account/points  '));
        $this->assertNull($this->safeNext('/account/login?x=1'));
    }
}
