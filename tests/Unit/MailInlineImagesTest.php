<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Mail\AppsScriptMail;
use AfricaGates\Services\Mail\BuiltMessage;
use AfricaGates\Services\Mail\InlineImages;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\Brand;
use PHPMailer\PHPMailer\PHPMailer;
use Tests\TestCase;

/**
 * OUR IMAGES SHOW WITHOUT "DISPLAY IMAGES" (owner, 5 Nov 2026: "the email images do not load
 * by default"). Every picture in our mail was a link to the site, and Outlook, Yahoo and most
 * work mail block linked images until the reader asks. Our own files now travel inside the
 * message; the HTTP roads, which cannot carry an inline part, get the links back.
 */
final class MailInlineImagesTest extends TestCase
{
    private function transport(): OtpService
    {
        return new class(['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'news@africagates.org', 'from_name' => 'Africa GATES']) extends OtpService {
            public ?PHPMailer $last = null;
            public string $wire = '';
            protected function transmit(PHPMailer $m): void
            {
                $m->preSend();
                $this->wire = $m->getSentMIMEMessage();
                $this->last = $m;
            }
        };
    }

    public function test_the_branded_mail_carries_its_logo_and_illustration_inside_the_message(): void
    {
        $t = $this->transport();
        $base = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
        $r = $t->sendBranded('ada@africagates.org', 'You have been nominated', '<p>Hello</p>', 'Hello', 'nomination',
            $base . '/assets/img/illustrations/illo-trophy.jpg');
        $this->assertTrue($r['success'], json_encode($r));
        $m = $t->last;
        $this->assertNotNull($m);

        $this->assertStringNotContainsString('src="' . Brand::markUrl($base) . '"', $m->Body, 'the mark is no longer a remote load');
        $this->assertMatchesRegularExpression('~src="cid:ag[0-9a-f]{16}@africagates"~', $m->Body);
        $this->assertStringContainsString('multipart/related', $t->wire, 'the images are parts of the message');
        $this->assertStringContainsString('Content-ID: <ag', $t->wire);
        $this->assertGreaterThanOrEqual(2, preg_match_all('~Content-Disposition: inline~', $t->wire), 'mark and illustration');

        // The API roads: links back, and no stray attachment.
        $this->assertStringContainsString(Brand::markUrl($base), InlineImages::html($m));
        $this->assertStringNotContainsString('cid:', InlineImages::html($m));
        $this->assertSame([], BuiltMessage::attachments($m), 'an inline image is not a file the reader was sent');
    }

    public function test_only_our_own_small_static_files_are_carried(): void
    {
        $m = new PHPMailer(true);
        $m->isHTML(true);
        $m->Body = '<img src="https://site.test/assets/img/logo-mark.png">'          // ours: carried
                 . '<img src="https://other.test/assets/img/logo-mark.png">'         // another host
                 . '<img src="https://site.test/countdown.png?to=2026">'             // generated per open
                 . '<img src="https://site.test/assets/img/../../.env">'            // traversal
                 . '<img src="https://site.test/assets/img/missing.png">';           // no file
        $this->assertSame(1, InlineImages::embed($m, 'https://site.test'));
        $this->assertSame(1, substr_count($m->Body, 'cid:'));
        $this->assertStringContainsString('https://other.test/assets/img/logo-mark.png', $m->Body);
        $this->assertStringContainsString('countdown.png?to=2026', $m->Body);
    }

    public function test_the_size_ceiling_holds(): void
    {
        $m = new PHPMailer(true);
        $m->isHTML(true);
        $imgs = '';
        foreach (glob(dirname(__DIR__, 2) . '/public/assets/img/illustrations/*.jpg') as $f) {
            $imgs .= '<img src="https://site.test/assets/img/illustrations/' . basename($f) . '">';
        }
        $m->Body = $imgs;
        InlineImages::embed($m, 'https://site.test');
        $bytes = 0;
        foreach ($m->getAttachments() as $a) $bytes += strlen((string) $a[0]);
        $this->assertLessThanOrEqual(InlineImages::MAX_BYTES, $bytes);
        $this->assertGreaterThan(0, $bytes);
    }

    public function test_the_api_roads_read_the_linked_html(): void
    {
        foreach (['src/Services/Mail/BrevoApi.php', 'src/Services/Mail/AppsScriptMail.php'] as $f) {
            $this->assertStringContainsString('InlineImages::html($m)', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f), $f);
        }
    }
}
