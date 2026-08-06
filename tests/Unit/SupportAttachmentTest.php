<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{SupportAttachmentService, TicketLinkService};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * Evidence on a support ticket.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS ACTUALLY AT RISK HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The commonest attachment on this platform will be a screenshot of a bank alert:
 * an account name, a balance, a masked card number. So the tests that matter are
 * not "does the upload work" — they are the two that decide whether somebody
 * else's bank alert is readable:
 *
 *   1. The bytes must not be under the web root, ever.
 *   2. Nobody may fetch one without being staff, the owner, or the holder of a
 *      valid link for THAT ticket.
 *
 * Everything else is a supporting detail. The type check is here because a file
 * called `receipt.png` that is really a PHP script must be refused on the bytes
 * rather than on its name.
 */
final class SupportAttachmentTest extends TestCase
{
    private int $ticketId;
    private int $otherTicketId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticketId = (int) DB::table('gates_support_tickets')->insertGetId([
            'reference' => 'AGS-AAA111', 'subject' => 'Paid, no votes',
            'email' => 'owner@example.test', 'name' => 'Owner', 'user_id' => 7,
            'status' => 'open', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->otherTicketId = (int) DB::table('gates_support_tickets')->insertGetId([
            'reference' => 'AGS-BBB222', 'subject' => 'Something else',
            'email' => 'stranger@example.test', 'name' => 'Stranger', 'user_id' => 9,
            'status' => 'open', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        // The service writes real files; do not leave them behind between runs.
        foreach (glob(SupportAttachmentService::root() . '/*/*') ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    /** A real uploaded file, with bytes we control. */
    private function upload(string $bytes, string $name, string $claimedType): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agatt');
        file_put_contents($tmp, $bytes);
        return new UploadedFile($tmp, $name, $claimedType, filesize($tmp) ?: null, UPLOAD_ERR_OK);
    }

    private function pngBytes(): string
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start(); imagepng($im); $out = (string) ob_get_clean(); imagedestroy($im);
        return $out;
    }

    // ── Where the bytes live ─────────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS MOST. A bank screenshot must have no path under the
     * document root — not an obscure one, not an unguessable one. None.
     */
    public function test_the_file_is_stored_outside_the_web_root(): void
    {
        $r = SupportAttachmentService::store(
            $this->upload($this->pngBytes(), 'alert.png', 'image/png'), $this->ticketId);
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));

        $att  = DB::table('gates_support_attachments')->where('id', $r['id'])->first();
        $path = SupportAttachmentService::pathOf($att);

        $this->assertNotNull($path);
        $this->assertFileExists($path);

        $public = realpath(dirname(__DIR__, 2) . '/public');
        $this->assertStringStartsNotWith((string) $public, (string) realpath($path),
            'an attachment under public/ is a bank alert with a URL');
    }

    /** The stored name carries nothing the uploader chose. */
    public function test_the_stored_name_does_not_echo_the_original(): void
    {
        $r = SupportAttachmentService::store(
            $this->upload($this->pngBytes(), 'my-gtbank-statement-march.png', 'image/png'), $this->ticketId);

        $att = DB::table('gates_support_attachments')->where('id', $r['id'])->first();
        $this->assertStringNotContainsString('gtbank', (string) $att->storage_path);
        $this->assertStringContainsString('gtbank', (string) $att->original_name,
            'though it is kept for display, because the reader chose it');
    }

    // ── What is accepted ─────────────────────────────────────────────────────

    /** The type comes from the BYTES. A script wearing a .png is refused. */
    public function test_a_script_renamed_as_an_image_is_refused(): void
    {
        $r = SupportAttachmentService::store(
            $this->upload('<?php echo shell_exec($_GET["c"]); ?>', 'receipt.png', 'image/png'),
            $this->ticketId);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, DB::table('gates_support_attachments')->count(),
            'and nothing is recorded for it');
    }

    public function test_a_pdf_is_accepted_because_statements_arrive_as_one(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $r = SupportAttachmentService::store($this->upload($pdf, 'statement.pdf', 'application/pdf'), $this->ticketId);

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame('application/pdf',
            (string) DB::table('gates_support_attachments')->where('id', $r['id'])->value('mime'));
    }

    /** Only so many per message, and the rest is said rather than dropped silently. */
    public function test_attachments_are_capped_per_message_and_the_overflow_is_reported(): void
    {
        $files = [];
        for ($i = 0; $i < SupportAttachmentService::MAX_PER_MESSAGE + 2; $i++) {
            $files[] = $this->upload($this->pngBytes(), "shot{$i}.png", 'image/png');
        }

        $r = SupportAttachmentService::attachAll($files, $this->ticketId, null);

        $this->assertSame(SupportAttachmentService::MAX_PER_MESSAGE, $r['stored']);
        $this->assertNotEmpty($r['problems'], 'the reader is told some were not kept');
    }

    /** A rejected file must never cost somebody the message they wrote. */
    public function test_one_bad_file_does_not_stop_the_good_ones(): void
    {
        $r = SupportAttachmentService::attachAll([
            $this->upload($this->pngBytes(), 'good.png', 'image/png'),
            $this->upload('not an image at all', 'bad.png', 'image/png'),
        ], $this->ticketId, null);

        $this->assertSame(1, $r['stored']);
        $this->assertCount(1, $r['problems']);
    }

    // ── Who may see it ───────────────────────────────────────────────────────

    public function test_a_stranger_may_not_see_an_attachment(): void
    {
        $r   = SupportAttachmentService::store($this->upload($this->pngBytes(), 'a.png', 'image/png'), $this->ticketId);
        $att = DB::table('gates_support_attachments')->where('id', $r['id'])->first();

        $this->assertFalse(SupportAttachmentService::mayView($att, null, null, null),
            'nobody signed in, no token');
        $this->assertFalse(SupportAttachmentService::mayView($att, null, 9, null),
            'a signed-in member who does not own this ticket');
        $this->assertFalse(SupportAttachmentService::mayView($att, null, null, str_repeat('a', 64)),
            'a token that resolves to nothing');
    }

    public function test_staff_and_the_owner_may_see_it(): void
    {
        $r   = SupportAttachmentService::store($this->upload($this->pngBytes(), 'a.png', 'image/png'), $this->ticketId);
        $att = DB::table('gates_support_attachments')->where('id', $r['id'])->first();

        $this->assertTrue(SupportAttachmentService::mayView($att, 1, null, null), 'staff');
        $this->assertTrue(SupportAttachmentService::mayView($att, null, 7, null), 'the member who owns it');
    }

    /**
     * A thread link admits its holder to ITS OWN ticket and no other. A token is
     * permission for one conversation, not a skeleton key for the table.
     */
    public function test_a_link_token_opens_only_its_own_ticket(): void
    {
        if (!TicketLinkService::ready()) {
            $this->markTestSkipped('ticket links are not available on this schema');
        }

        $token = TicketLinkService::issue($this->ticketId, 'owner@example.test');
        $this->assertNotNull($token);

        $mine  = DB::table('gates_support_attachments')->where('id',
            SupportAttachmentService::store($this->upload($this->pngBytes(), 'm.png', 'image/png'), $this->ticketId)['id'])->first();
        $other = DB::table('gates_support_attachments')->where('id',
            SupportAttachmentService::store($this->upload($this->pngBytes(), 'o.png', 'image/png'), $this->otherTicketId)['id'])->first();

        $this->assertTrue(SupportAttachmentService::mayView($mine, null, null, $token));
        $this->assertFalse(SupportAttachmentService::mayView($other, null, null, $token),
            'the same token must not reach a different ticket');
    }

    /** A traversal in the stored path is refused rather than read. */
    public function test_a_path_that_climbs_out_is_refused(): void
    {
        $this->assertNull(SupportAttachmentService::pathOf((object) ['storage_path' => '../../.env']));
        $this->assertNull(SupportAttachmentService::pathOf((object) ['storage_path' => '/etc/passwd']));
        $this->assertNull(SupportAttachmentService::pathOf((object) ['storage_path' => '']));
    }

    /** The advertised ceiling is never larger than what PHP will actually take. */
    public function test_the_stated_limit_cannot_exceed_what_the_server_accepts(): void
    {
        $this->assertLessThanOrEqual(SupportAttachmentService::MAX_BYTES, SupportAttachmentService::limitBytes());
        $this->assertGreaterThan(0, SupportAttachmentService::limitBytes());
    }
}
