<?php
declare(strict_types=1);
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Support\Environment;

class EnvironmentTest extends TestCase
{
    /** A leftover APP_ENV=development must NOT leak stack traces on a real public host. */
    public function test_development_env_does_not_leak_on_public_host(): void
    {
        $this->assertFalse(
            Environment::showErrorDetails('development', true, 'afg.afrovanguard.org.ng')
        );
    }

    public function test_development_debug_shows_details_on_localhost(): void
    {
        $this->assertTrue(Environment::showErrorDetails('development', true, 'localhost:8000'));
        $this->assertTrue(Environment::showErrorDetails('development', true, '127.0.0.1'));
    }

    public function test_cli_no_host_is_treated_as_local(): void
    {
        $this->assertTrue(Environment::showErrorDetails('development', true, null));
    }

    public function test_production_never_shows_details_even_when_debug_on(): void
    {
        $this->assertFalse(Environment::showErrorDetails('production', true, 'localhost'));
        $this->assertFalse(Environment::showErrorDetails('production', true, null));
    }

    public function test_demo_never_shows_details(): void
    {
        $this->assertFalse(Environment::showErrorDetails('demo', true, 'localhost'));
    }

    public function test_debug_off_never_shows_details(): void
    {
        $this->assertFalse(Environment::showErrorDetails('development', false, 'localhost'));
    }

    public function test_is_local_host_classification(): void
    {
        $this->assertTrue(Environment::isLocalHost('localhost'));
        $this->assertTrue(Environment::isLocalHost('127.0.0.1'));
        $this->assertTrue(Environment::isLocalHost('::1'));
        $this->assertTrue(Environment::isLocalHost('africa-gates.local'));
        $this->assertTrue(Environment::isLocalHost('site.test'));
        $this->assertTrue(Environment::isLocalHost(''));
        $this->assertFalse(Environment::isLocalHost('afg.afrovanguard.org.ng'));
        $this->assertFalse(Environment::isLocalHost('example.com'));
    }
}
