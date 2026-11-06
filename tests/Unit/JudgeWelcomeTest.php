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

    /** Dates RELATIVE to today: the fixed ones this file used would have started failing the week they passed. */
    private string $close = '';
    private string $results = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->close   = \Carbon\Carbon::now()->addDays(16)->setTime(18, 0)->toDateTimeString();
        $this->results = \Carbon\Carbon::now()->addDays(46)->setTime(12, 0)->toDateTimeString();
        $later = \Carbon\Carbon::now()->addDays(27)->setTime(18, 0)->toDateTimeString();
        foreach ([self::P1 => $this->close, self::P2 => $later] as $pid => $close) {
            DB::table('gates_award_programmes')->insertOrIgnore(['id' => $pid, 'title' => 'Programme ' . $pid, 'slug' => 'jw-' . $pid]);
            DB::table('gates_award_cycles')->insertOrIgnore(['programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'voting',
                'voting_open' => \Carbon\Carbon::now()->subDays(5)->toDateTimeString(),
                'voting_close' => $close, 'results_date' => $this->results]);
        }
    }

    private static function shown(string $at): string
    {
        return \AfricaGates\Support\DisplayTime::showZoned($at, 'j F Y, H:i');
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
        $this->assertStringContainsString(self::shown($this->close), $sent[0]['plainBody'], 'scoring did not open on the cycle\'s voting close');
        $this->assertStringContainsString(self::shown($this->results), $sent[0]['plainBody'], 'scoring did not close on the results date');
    }

    public function test_a_judge_on_two_programmes_is_given_no_one_programmes_dates(): void
    {
        $sent = $this->resend([self::P1, self::P2]);
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('Scoring closes', $sent[0]['plainBody']);
        $this->assertStringNotContainsString(self::shown($this->close), $sent[0]['plainBody']);
        $this->assertStringContainsString('Programme ' . self::P1 . ' · Programme ' . self::P2, $sent[0]['plainBody']);
    }

    /**
     * A judge added to a programme whose latest edition is already decided was told
     * "Scoring opens" on a date months gone, in the letter whose job is telling them when
     * to turn up. No date is better than a wrong one: the mail then says scoring opens
     * when voting closes, which is true.
     */
    public function test_a_decided_or_passed_edition_gives_no_dates(): void
    {
        DB::table('gates_award_cycles')->where('programme_id', self::P1)->update([
            'status' => 'results',
            'voting_close' => \Carbon\Carbon::now()->subDays(60)->toDateTimeString(),
            'results_date' => \Carbon\Carbon::now()->subDays(30)->toDateTimeString(),
        ]);
        $sent = $this->resend([self::P1]);
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('Scoring opens:', $sent[0]['plainBody']);
        $this->assertStringNotContainsString('Scoring closes:', $sent[0]['plainBody']);

        // Voting already closed, results still ahead: only the date still to come is given.
        DB::table('gates_award_cycles')->where('programme_id', self::P1)->update([
            'status' => 'judging',
            'voting_close' => \Carbon\Carbon::now()->subDays(2)->toDateTimeString(),
            'results_date' => $this->results,
        ]);
        [$opens, $closes] = JudgesController::scoringDates(self::P1);
        $this->assertSame('', $opens, 'a date already gone is not "scoring opens"');
        $this->assertSame(self::shown($this->results), $closes);
    }
}
