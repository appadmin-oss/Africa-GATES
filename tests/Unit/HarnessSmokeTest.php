<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

class HarnessSmokeTest extends TestCase
{
    public function test_core_tables_exist_and_are_writable(): void
    {
        DB::table('gates_profiles')->insert([
            'slug' => 'x', 'display_name' => 'X', 'email' => 'x@x.io',
            'country_code' => 'NG', 'status' => 'approved',
        ]);
        $this->assertSame(1, DB::table('gates_profiles')->count());

        // A couple more tables the later suites rely on.
        $this->assertSame(0, DB::table('gates_otp_tokens')->count());
        $this->assertSame(0, DB::table('gates_votes')->count());
    }
}
