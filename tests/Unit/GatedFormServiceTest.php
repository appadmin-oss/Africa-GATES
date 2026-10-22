<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\GatedFormService;
use Illuminate\Database\Capsule\Manager as DB;

/** Gated single-use form tokens: issue → open → submit ONCE; expire; regenerate voids the old. */
class GatedFormServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0]);
    }

    public function test_issue_resolve_submit_is_single_use(): void
    {
        $raw = GatedFormService::issue('nominee', 1, 'ada@x.io');
        $this->assertSame('ok', GatedFormService::status(GatedFormService::resolve($raw)));

        $r = GatedFormService::submit($raw, ['bio' => 'My cultural impact', 'accept_terms' => 1]);
        $this->assertTrue($r['ok']);
        $this->assertSame('nominee', $r['purpose']);
        // Applied to the subject (nominee tagline).
        $this->assertSame('My cultural impact', DB::table('gates_nominees')->where('id', 1)->value('tagline'));

        // Single-use: a second submit is refused.
        $r2 = GatedFormService::submit($raw, ['bio' => 'again', 'accept_terms' => 1]);
        $this->assertFalse($r2['ok']);
        $this->assertSame('used', $r2['status']);
        $this->assertSame('used', GatedFormService::status(GatedFormService::resolve($raw)));
    }

    public function test_invalid_token(): void
    {
        $this->assertSame('invalid', GatedFormService::status(GatedFormService::resolve('not-a-real-token')));
        $this->assertFalse(GatedFormService::submit('not-a-real-token', ['bio' => 'x', 'accept_terms' => 1])['ok']);
    }

    public function test_regenerate_invalidates_prior_unused_token(): void
    {
        $old = GatedFormService::issue('nominee', 1, 'ada@x.io');
        $new = GatedFormService::issue('nominee', 1, 'ada@x.io'); // regenerate
        $this->assertSame('invalid', GatedFormService::status(GatedFormService::resolve($old))); // old deleted
        $this->assertSame('ok', GatedFormService::status(GatedFormService::resolve($new)));
    }

    public function test_expired_token_cannot_submit(): void
    {
        $raw = GatedFormService::issue('nominee', 1, 'ada@x.io');
        DB::table('gates_form_tokens')->where('purpose', 'nominee')->update(['expires_at' => '2020-01-01 00:00:00']);
        $this->assertSame('expired', GatedFormService::status(GatedFormService::resolve($raw)));
        $this->assertFalse(GatedFormService::submit($raw, ['bio' => 'x', 'accept_terms' => 1])['ok']);
    }
}
