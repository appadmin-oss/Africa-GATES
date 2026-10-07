<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GuideService;
use AfricaGates\Services\HelpCentre;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportTicketService;
use AfricaGates\Support\PublicFault;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Tell Gee" on the error page, and what Gee said back.
 *
 * The error page sends "Something went wrong. Reference EC4Y-Y8Y7". It reached the Help
 * Centre search, which matched "reference" to the wallet-app article, and the bubble read
 * "Ours begins with <code>AFG-</code>" — the wrong answer, with our markup printed raw. And
 * Gee's own "A result looks wrong" button was answered with the hash-chain article, with
 * `<strong>` in the bubble.
 */
final class HelpDeskErrorReferenceTest extends TestCase
{
    private const ASKED = 'Something went wrong. Reference EC4Y-Y8Y7';

    private string $log = '';
    private ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = dirname(__DIR__, 2) . '/var/logs/error-detail.log';
        $this->saved = is_file($this->log) ? (string) file_get_contents($this->log) : null;
    }

    protected function tearDown(): void
    {
        if ($this->saved === null) @unlink($this->log); else file_put_contents($this->log, $this->saved);
        parent::tearDown();
    }

    public function test_an_error_page_reference_is_recognised_and_a_payment_reference_is_not(): void
    {
        $this->assertSame('EC4Y-Y8Y7', PublicFault::quoted(self::ASKED));
        $this->assertSame('EC4Y-Y8Y7', PublicFault::quoted('got an error, ec4y-y8y7'), 'typed in lower case');
        $this->assertNull(PublicFault::quoted('I paid, reference AFG-PVOTE-957ef35ed73d'));
        $this->assertNull(PublicFault::quoted('my reference is AFG-4c1e9a0b2d3f4e5a'));
        // The right shape and nothing else to say it is one: not in the log, no mention of
        // an error or a reference.
        $this->assertNull(PublicFault::quoted('NYSC-TEAM is nominated'));
        // A reference minted here always round-trips.
        $ref = PublicFault::reference();
        $this->assertSame($ref, PublicFault::quoted('Reference ' . $ref));
    }

    public function test_the_log_entry_is_found_by_its_reference_and_named_by_when_and_where_only(): void
    {
        $ref = PublicFault::record(new \RuntimeException('SQLSTATE[23000] secret detail'), 'POST /admin/challenges');
        $hit = PublicFault::find($ref);
        $this->assertNotNull($hit);
        $this->assertSame('POST /admin/challenges', $hit['where']);

        $reply = PublicFault::chatReply($ref, $hit);
        $this->assertStringContainsString($ref, $reply);
        $this->assertStringContainsString('/admin/challenges', $reply);
        $this->assertStringNotContainsString('POST', $reply, 'the method is machinery');
        $this->assertStringNotContainsString('SQLSTATE', $reply, 'never what failed, only where');
        $this->assertNull(PublicFault::find('ZZZZ-ZZZZ'));
    }

    public function test_the_floor_answers_an_error_reference_rather_than_quoting_the_wallet_article(): void
    {
        $out = (new GuideService())->supportFallback(self::ASKED);
        $this->assertStringContainsString('EC4Y-Y8Y7', $out['reply']);
        $this->assertStringContainsString('our fault', $out['reply']);
        $this->assertStringNotContainsString('wallet', strtolower($out['reply']));
        $this->assertStringNotContainsString('AFG-', $out['reply']);
    }

    public function test_the_desk_answers_it_and_puts_it_in_front_of_a_person_once(): void
    {
        $mailer = new class(['from_address' => 'x@y.io']) extends OtpService {
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            { return ['ok' => true]; }
        };
        $desk = new SupportAgentService(null, new SupportTicketService($mailer));
        $before = DB::table('gates_support_tickets')->count();

        $r = $desk->ask(self::ASKED, [], SupportContext::fromSession());
        $this->assertStringNotContainsString('wallet', strtolower($r['reply']));
        $this->assertNotNull($r['ticket'], 'a quoted reference always reaches the team');
        $this->assertSame($before + 1, DB::table('gates_support_tickets')->count());
        $row = DB::table('gates_support_tickets')->where('reference', $r['ticket'])->first();
        $this->assertStringContainsString('EC4Y-Y8Y7', (string) $row->subject);
        $this->assertSame('high', $row->severity);

        // Pressing "Tell Gee" again in the same conversation is the same report.
        $again = $desk->ask(self::ASKED, [['role' => 'user', 'content' => self::ASKED],
            ['role' => 'assistant', 'content' => $r['reply']]], SupportContext::fromSession());
        $this->assertNull($again['ticket']);
        $this->assertSame($before + 1, DB::table('gates_support_tickets')->count());
    }

    public function test_a_result_looks_wrong_reaches_the_dispute_answer(): void
    {
        $this->assertSame('dispute-a-result', HelpCentre::search('A result looks wrong', 1)[0]['slug']);
    }

    /** Every article's quoted paragraph, as the chat bubble receives it: no markup survives. */
    public function test_no_written_answer_carries_html_into_the_chat(): void
    {
        foreach (HelpCentre::all() as $a) {
            $w = (string) HelpCentre::writtenAnswer((string) $a['title']);
            $this->assertDoesNotMatchRegularExpression('~</?[a-z][^>]*>~i', $w, 'markup in the answer to “' . $a['title'] . '”');
        }
        $this->assertSame('Ours begins with **AFG-**. See [the other one](/help/wallet-app-reference).',
            HelpCentre::chatText('Ours begins with <code>AFG-</code>. See <a href="/help/wallet-app-reference">the other one</a>.'));
        $this->assertSame('written down and **sealed**: it &',
            HelpCentre::chatText('written down and <strong>sealed</strong>: it &amp;'));
    }
}
