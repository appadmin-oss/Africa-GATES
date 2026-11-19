<?php
declare(strict_types=1);
namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\TestApp;
use Tests\TestCase;

final class VoteRetryTest extends TestCase
{
    public function test_an_http_retry_recovers_confirmation_without_repeating_side_effects(): void
    {
        DB::table('gates_award_programmes')->insert(['id'=>1,'slug'=>'retry','title'=>'Retry','is_active'=>1]);
        DB::table('gates_award_cycles')->insert(['id'=>1,'programme_id'=>1,'year'=>(int)date('Y'),'status'=>'voting',
            'voting_open'=>Carbon::now()->subDay()->toDateTimeString(),'voting_close'=>Carbon::now()->addDay()->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id'=>1,'cycle_id'=>1,'slug'=>'leadership','title'=>'Leadership']);
        DB::table('gates_nominees')->insert(['id'=>1,'category_id'=>1,'name'=>'Ada Okafor','status'=>'approved']);
        DB::table('gates_otp_tokens')->insert(['email_hash'=>hash('sha256','retry@example.org'),'token_hash'=>hash('sha256','123456'),
            'purpose'=>'vote','nominee_id'=>1,'award_id'=>1,'expires_at'=>Carbon::now()->addMinutes(10)->toDateTimeString()]);
        DB::table('gates_settings')->updateOrInsert(['key_name'=>'gas_url'],['value'=>'https://example.org/exec']);
        DB::table('gates_settings')->updateOrInsert(['key_name'=>'paid_voting_enabled'],['value'=>'0']);
        $_SESSION=[];
        $body=['email'=>'retry@example.org','otp'=>'123456','nominee_id'=>1,'award_id'=>1,
            'name'=>'Retry Voter','phone'=>'+2348012345678','device_hash'=>'retry-device','accept_terms'=>1,
            'idempotency_key'=>'http-retry'];
        $app=TestApp::build();
        $request=(new ServerRequestFactory())->createServerRequest('POST','https://localhost/api/vote',['REMOTE_ADDR'=>'192.0.2.19'])
            ->withParsedBody($body)->withHeader('Accept','application/json');
        $first=$app->handle($request);
        $this->assertSame(200,$first->getStatusCode(),(string)$first->getBody());
        $this->assertTrue(json_decode((string)$first->getBody(),true)['success']);
        $jobs=DB::table('gates_jobs')->count();$events=DB::table('gates_events')->count();
        $this->assertGreaterThan(0,DB::table('gates_jobs')->where('type','vote.sheets_push')->count());
        unset($_SESSION['vote_cast']);
        $retry=$app->handle($request);
        $this->assertSame(200,$retry->getStatusCode(),(string)$retry->getBody());
        $this->assertTrue(json_decode((string)$retry->getBody(),true)['success']);
        $this->assertSame(1,DB::table('gates_votes')->count());
        $this->assertSame(1,(int)DB::table('gates_nominees')->where('id',1)->value('vote_count'));
        $this->assertSame($jobs,DB::table('gates_jobs')->count());
        $this->assertSame($events,DB::table('gates_events')->count());
        $this->assertArrayHasKey(1,$_SESSION['vote_cast']);
    }
}
