<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\DisposableEmail;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Disposable-email detection: known domains + distinctive substrings are blocked,
 * legitimate domains (incl. ones that merely contain a common word) are NOT, and
 * admins can extend the blocklist from settings without a deploy.
 */
class DisposableEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DisposableEmail::flushCache();
    }

    public function test_known_domains_are_blocked(): void
    {
        $this->assertTrue(DisposableEmail::isDisposable('a@mailinator.com'));
        $this->assertTrue(DisposableEmail::isDisposable('b@10minutemail.com'));
        $this->assertTrue(DisposableEmail::isDisposable('c@guerrillamail.org'));
    }

    public function test_distinctive_substrings_catch_new_variants(): void
    {
        // Not in the exact list, but unmistakably throwaway.
        $this->assertTrue(DisposableEmail::isDisposable('x@my-yopmail-clone.com'));
        $this->assertTrue(DisposableEmail::isDisposable('x@fresh-tempmail.xyz'));
        $this->assertTrue(DisposableEmail::isDisposable('x@get.mailinator.io'));
    }

    public function test_legit_domains_are_not_blocked(): void
    {
        $this->assertFalse(DisposableEmail::isDisposable('user@gmail.com'));
        $this->assertFalse(DisposableEmail::isDisposable('team@afrovanguard.org.ng'));
        // Contains the word "temp" but not the signal "tempmail" — must NOT trip.
        $this->assertFalse(DisposableEmail::isDisposable('hello@contempo.com'));
        $this->assertFalse(DisposableEmail::isDisposable('sales@templeuniversity.edu'));
    }

    public function test_malformed_address_is_not_disposable(): void
    {
        $this->assertFalse(DisposableEmail::isDisposable('not-an-email'));
        $this->assertFalse(DisposableEmail::isDisposable(''));
    }

    public function test_admin_can_extend_the_blocklist(): void
    {
        // Signal-free domains, so only the admin list can block them.
        $this->assertFalse(DisposableEmail::isDisposable('a@coolinbox.example'));
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'disposable_domains_extra'],
            ['value' => "coolinbox.example, second-site.io\nthird.example"]
        );
        DisposableEmail::flushCache();
        $this->assertTrue(DisposableEmail::isDisposable('a@coolinbox.example'));
        $this->assertTrue(DisposableEmail::isDisposable('b@third.example'));
        $this->assertTrue(DisposableEmail::isDisposable('c@SECOND-SITE.IO'), 'case-insensitive');
    }
}
