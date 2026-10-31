<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\JudgesController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\OtpService;
use AfricaGates\Admin\Services\UploadService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The judge welcome (emails/judge.html.twig), sent from the judges list.
 *
 * A new judge used to get two emails: an "appointment" with a typed 2026, typed weights and
 * "reply to this email", and the profile form. It is one now, and its dates are the
 * programme's own — so these tests hold that the dates come from the CYCLE, and that a judge
 * on two programmes is not given one programme's dates as if they were everybody's.
 */
final class JudgeWelcomeTest extends TestCase
{
    private const P1 = 211;   // TINYINT-safe
    private const P2 = 212;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::P1 => '2026-11-20 18:00:00', self::P2 => '2026-12-01 18:00:00'] as $pid => $close) {
            DB::table('gates_award_programmes')->insertOrIgnore(['id' => $pid, 'title' => 'Programme ' . $pid, 'slug' => 'jw-' . $pid]);
            DB::table('gates_award_cycles')->insertOrIgnore(['programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
                'voting_close' => $close, 'results_date' => '2026-12-20 12:00:00']);
        }
    }

    /** @param list<int> $programmes @return list<array<string,string>> */
    private function resend(array $programmes): array
    {
        $id = (int) DB::table('gates_judges')->insertGetId([
            'name' => 'Kofi Mensah', 'email' => 'kofi-' . bin2hex(random_bytes(3)) . '@example.org',
            'programme_ids' => json_encode($programmes), 'is_active' => 1,
        ]);
        $mailer = new class extends OtpService {
            public array $sent = [];
            public function __construct() { parent::__construct(['username' => 'u', 'password' => 'p']); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'category');
                return ['success' => true];
            }
        };
        $_SESSION['admin_id'] = 1;
        (new JudgesController(\Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'), new AuditService(), new UploadService(), $mailer))
            ->regenerateForm((new ServerRequestFactory())->createServerRequest('POST', '/x'), new Response(), ['id' => $id]);
        unset($_SESSION['admin_id'], $_SESSION['flash_ok']);
        return $mailer->sent;
    }

    public function test_a_judge_on_one_programme_gets_its_own_scoring_window_in_one_email(): void
    {
        $sent = $this->resend([self::P1]);
        $this->assertCount(1, $sent);
        $this->assertSame('Judges', $sent[0]['category']);
        $this->assertStringContainsString('Welcome to the panel, Kofi', $sent[0]['htmlBody']);
        $this->assertStringContainsString('/form/', $sent[0]['htmlBody'], 'the profile form link is missing');
        $this->assertStringContainsString('/judge/login', $sent[0]['htmlBody']);
        $this->assertStringContainsString('20 November 2026', $sent[0]['plainBody'], 'scoring did not open on the cycle\'s voting close');
        $this->assertStringContainsString('20 December 2026', $sent[0]['plainBody'], 'scoring did not close on the results date');
    }

    public function test_a_judge_on_two_programmes_is_given_no_one_programmes_dates(): void
    {
        $sent = $this->resend([self::P1, self::P2]);
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('Scoring closes', $sent[0]['plainBody']);
        $this->assertStringNotContainsString('20 November 2026', $sent[0]['plainBody']);
        $this->assertStringContainsString('Programme ' . self::P1 . ' · Programme ' . self::P2, $sent[0]['plainBody']);
    }
}
