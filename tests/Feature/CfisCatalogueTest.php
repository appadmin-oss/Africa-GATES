<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\CfisCatalogue as C;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The list of kinds of money, and what it answers when finance cannot be reached.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE FALLBACK IS THE WHOLE TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is read while a checkout is being rendered. Every path through it is therefore in front
 * of a buyer, and the only unacceptable outcome is an exception — a finance box that is down,
 * slow, moved, or answering nonsense must cost nobody a payment.
 *
 * So what is asserted here is not "it fetches the catalogue". It is the four ways fetching can
 * fail and what each one falls back to:
 *
 *   · no credentials at all        → the three built-ins
 *   · credentials, no answer       → the last good list, else the built-ins
 *   · an answer in a shape nobody  → treated as no answer
 *     expected
 *   · a last good list that is old → still used, deliberately
 *
 * The last one is the one worth stating. A long outage must not mean knowing LESS: an expiry on
 * the last-good copy would make the platform forget how to route money precisely when it cannot
 * ask. Routing yesterday's way is almost always right; refusing to sell never is.
 *
 * A BUILT-IN IS NEVER LOST. Events, shop and votes predate CFIS and money is already attributed
 * to them. If finance stops listing one, the stream must stay on the admin screen — otherwise a
 * configured subaccount silently stops being editable while continuing to route.
 */
final class CfisCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        foreach (['CFIS_URL', 'CFIS_SOURCE', 'CFIS_SECRET'] as $k) {
            unset($_ENV[$k]);
        }
        $this->reset();
        parent::tearDown();
    }

    /** Clear both the per-request memo and the two cache rows. */
    private function reset(): void
    {
        C::refresh();
        (function (): void { self::$memo = null; })->bindTo(null, C::class)();
        foreach (['cfis_stream_catalogue', 'cfis_stream_catalogue_last_good'] as $k) {
            DB::table('gates_cache')->where('cache_key', $k)->delete();
        }
    }

    /** Put a catalogue in the cache as though finance had answered with it. */
    private function seedLastGood(array $streams, int $expiresIn = 86400): void
    {
        DB::table('gates_cache')->updateOrInsert(['cache_key' => 'cfis_stream_catalogue_last_good'], [
            'payload' => json_encode(['streams' => $streams, 'cache_seconds' => 900]),
            'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn),
            'tags' => 'cfis',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        (function (): void { self::$memo = null; })->bindTo(null, C::class)();
    }

    // ══ 1. nothing configured ════════════════════════════════════════════════

    public function test_with_no_cfis_configured_the_built_ins_answer(): void
    {
        $s = C::streams();

        $this->assertSame(C::BUILT_IN, $s);
        $this->assertTrue(C::knows('events'));
        $this->assertFalse(C::knows('membership'));
    }

    public function test_describing_a_stream_nobody_has_heard_of_is_empty_not_an_error(): void
    {
        $this->assertSame([], C::describe('membership'));
    }

    // ══ 2. finance unreachable ═══════════════════════════════════════════════

    public function test_credentials_but_no_answer_falls_back_rather_than_throwing(): void
    {
        // Port 9 is discard: it refuses immediately, so this is an outage without a wait.
        $_ENV['CFIS_URL'] = 'http://127.0.0.1:9';
        $_ENV['CFIS_SOURCE'] = 'avg';
        $_ENV['CFIS_SECRET'] = str_repeat('k', 64);

        $this->assertSame(C::BUILT_IN, C::streams());
    }

    // ══ 3. the last good list ════════════════════════════════════════════════

    public function test_a_stale_catalogue_is_used_when_finance_cannot_be_reached(): void
    {
        $this->seedLastGood([
            ['code' => 'membership', 'name' => 'Membership fees', 'expects_subaccount' => true,
             'posts_to' => 'Member subscriptions', 'description' => 'Annual membership.'],
            ['code' => 'fines', 'name' => 'Fines'],
        ]);

        $s = C::streams();

        $this->assertSame('Membership fees', $s['membership']);
        $this->assertArrayHasKey('fines', $s);
        $this->assertTrue(C::knows('membership'));
    }

    public function test_a_built_in_is_never_lost_even_if_finance_stops_listing_it(): void
    {
        // Money is already attributed to these three. A stream that vanishes from the admin
        // screen while still routing is worse than one that is merely out of date.
        $this->seedLastGood([['code' => 'dues', 'name' => 'Dues']]);

        $s = C::streams();

        foreach (C::BUILT_IN as $code => $label) {
            $this->assertSame($label, $s[$code], $code . ' was dropped');
        }
        $this->assertArrayHasKey('dues', $s);
        $this->assertCount(4, $s);
    }

    public function test_what_the_admin_screen_reads_survives_the_round_trip(): void
    {
        /* TWO streams, and the one asked for is NOT first. With a single-row fixture
           this case passes against a describe() that returns row zero whatever it is
           asked — which is the shape that has cost this repo a scale warning and a
           panel mark already. */
        $this->seedLastGood([
            ['code' => 'fines', 'name' => 'Fines', 'expects_subaccount' => false,
             'posts_to' => 'General fund', 'description' => 'Penalties.'],
            ['code' => 'membership', 'name' => 'Membership fees', 'expects_subaccount' => true,
             'posts_to' => 'Member subscriptions', 'description' => 'Annual membership.'],
        ]);

        $d = C::describe('membership');

        // These three are what tells an operator which rows finance is waiting on, which is the
        // difference between a row they must fill in and one they are not meant to.
        $this->assertTrue($d['expects_subaccount']);
        $this->assertSame('Member subscriptions', $d['posts_to']);
        $this->assertSame('Annual membership.', $d['description']);
    }

    public function test_an_expired_last_good_row_is_not_used(): void
    {
        $this->seedLastGood([['code' => 'gone', 'name' => 'Gone']], -10);

        $this->assertArrayNotHasKey('gone', C::streams());
    }

    // ══ 4. nonsense from finance ═════════════════════════════════════════════

    public function test_rows_without_a_usable_code_are_dropped(): void
    {
        $this->seedLastGood([
            ['name' => 'no code at all'],
            ['code' => '', 'name' => 'blank code'],
            ['code' => '   ', 'name' => 'whitespace'],
            ['code' => 'training', 'name' => 'Training fees'],
        ]);

        $s = C::streams();

        // The one usable row, plus the three built-ins. Nothing else got through.
        $this->assertSame(['training', 'events', 'shop', 'votes'], array_keys($s));
    }

    public function test_a_cache_row_that_is_not_a_catalogue_degrades_rather_than_throwing(): void
    {
        /* A payload survives the code that wrote it — a half-finished write, a restore, a
           future version of the shape. This runs while a checkout is being rendered, so
           the only unacceptable outcome is an exception; the built-ins are the answer. */
        foreach (['not json at all', '{}', '{"streams":"a string"}', 'null', '[]'] as $junk) {
            DB::table('gates_cache')->updateOrInsert(['cache_key' => 'cfis_stream_catalogue_last_good'], [
                'payload' => $junk,
                'expires_at' => date('Y-m-d H:i:s', time() + 86400),
                'tags' => 'cfis',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            (function (): void { self::$memo = null; })->bindTo(null, C::class)();

            $this->assertSame(C::BUILT_IN, C::streams(), 'payload: ' . $junk);
            $this->assertSame([], C::describe('anything'), 'payload: ' . $junk);
        }
    }

    public function test_a_code_with_no_name_falls_back_to_the_code(): void
    {
        // Better a row labelled `training` than a row labelled nothing: an operator can act on
        // the first and cannot see the second.
        $this->seedLastGood([['code' => 'training', 'name' => '']]);

        $this->assertSame('training', C::streams()['training']);
    }
}
