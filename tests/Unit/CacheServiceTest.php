<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CacheService;

class CacheServiceTest extends TestCase
{
    public function test_remember_stores_and_returns_value(): void
    {
        $c = new CacheService();
        $calls = 0;
        $make = function () use (&$calls) { $calls++; return ['v' => 42]; };

        $this->assertSame(['v' => 42], $c->remember('k1', 300, $make));
        $this->assertSame(['v' => 42], $c->remember('k1', 300, $make)); // cached
        $this->assertSame(1, $calls); // callback ran once
    }

    public function test_forget_by_tag_purges_only_matching_entries(): void
    {
        $c = new CacheService();
        $c->remember('api:lb:20', 300, fn() => ['x' => 1], ['leaderboard']);
        $c->remember('api:reg:1', 300, fn() => ['y' => 2], ['registry']);

        $c->forgetByTag('leaderboard');

        $this->assertNull($c->get('api:lb:20'));        // purged
        $this->assertNotNull($c->get('api:reg:1'));     // untouched
    }
}
