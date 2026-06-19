<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Controllers\ApiController;
use AfricaGates\Services\{CacheService, ProfileService, AwardService, VoteService, OtpService, RateLimitService, GoogleSheetsService, CommunityService, TurnstileService, SpamService};
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class NewsletterSubscribeTest extends TestCase
{
    private function controller(): ApiController
    {
        // Real services where they don't touch network; nulls for the optional
        // outbound integrations.
        return new ApiController(
            new CacheService(),
            new ProfileService(),
            new AwardService(),
            new VoteService(),
            new OtpService(['username' => '', 'password' => '']),
            new RateLimitService(),
            null, // GoogleSheetsService — optional
            new CommunityService(new SpamService()),
            null, // TurnstileService — optional
        );
    }

    private function post(array $body, string $ip = '1.2.3.4'): Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://afg.local/api/newsletter/subscribe',
                ['REMOTE_ADDR' => $ip])
            ->withParsedBody($body);
        return $this->controller()->newsletterSubscribe($req, new Response());
    }

    public function test_valid_email_is_stored(): void
    {
        $res = $this->post(['email' => 'reader@example.com']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('gates_newsletter')->count());

        $row = DB::table('gates_newsletter')->first();
        $this->assertSame('reader@example.com', $row->email);
        $this->assertSame(hash('sha256', 'reader@example.com'), $row->email_hash);
    }

    public function test_invalid_email_rejected(): void
    {
        $res = $this->post(['email' => 'not-an-email']);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(0, DB::table('gates_newsletter')->count());
    }

    public function test_duplicate_subscribe_is_idempotent_and_not_leaky(): void
    {
        $this->post(['email' => 'x@example.com']);
        $second = $this->post(['email' => 'X@example.com']); // same email, different case
        $this->assertSame(200, $second->getStatusCode());     // still ok (no leak)
        $this->assertSame(1, DB::table('gates_newsletter')->count()); // still one row
    }

    public function test_rate_limit_blocks_after_threshold(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->post(['email' => "r{$i}@example.com"])->getStatusCode());
        }
        $sixth = $this->post(['email' => 'r6@example.com']);
        $this->assertSame(429, $sixth->getStatusCode());
    }
}
