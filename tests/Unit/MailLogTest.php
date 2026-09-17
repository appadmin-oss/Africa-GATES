<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Email delivery audit: every attempted send lands in gates_mail_log with a
 * masked recipient, and the dev fallback is recorded as 'logged_dev' (visibly
 * NOT a real delivery) while unconfigured-in-production records 'failed'.
 * This is what makes "sign-in links are not arriving" diagnosable.
 */
final class MailLogTest extends TestCase
{
    private function svc(): OtpService
    {
        // Unconfigured SMTP (placeholder creds count as unconfigured).
        return new OtpService(['username' => '', 'password' => '', 'host' => 'x', 'port' => 587]);
    }

    public function test_dev_fallback_is_audited_as_logged_dev_with_masked_recipient(): void
    {
        $_ENV['APP_ENV'] = 'development';
        try {
            $r = $this->svc()->sendBranded('ada.obi@example.com', 'Your sign-in code', '<p>123456</p>', '123456', 'Accounts');
            $this->assertTrue($r['success'], 'dev fallback reports success (message reached the dev log)');
            $row = DB::table('gates_mail_log')->first();
            $this->assertNotNull($row);
            $this->assertSame('logged_dev', $row->status);
            $this->assertSame('ad***@example.com', $row->to_masked);
            $this->assertStringNotContainsString('ada.obi', $row->to_masked);
            $this->assertSame('Accounts', $row->category);
        } finally {
            $_ENV['APP_ENV'] = 'development';
        }
    }

    public function test_unconfigured_in_production_is_audited_as_failed_and_reports_failure(): void
    {
        $_ENV['APP_ENV'] = 'production';
        try {
            $r = $this->svc()->sendBranded('ada.obi@example.com', 'Your sign-in code', '<p>123456</p>');
            $this->assertFalse($r['success'], 'production must NEVER fake success when nothing was delivered');
            $row = DB::table('gates_mail_log')->first();
            $this->assertSame('failed', $row->status);
            $this->assertStringContainsString('SMTP not configured', (string) $row->error);
        } finally {
            $_ENV['APP_ENV'] = 'development';
        }
    }
}
