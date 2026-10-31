<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailCampaign;
use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\OtpService;

/**
 * The twelve inbox properties, on the fixed final-hours message as it goes on the wire.
 *
 * This class's parent was written against `templates/emails/final-hours.twig`, a whole
 * document. The message is a body in the house shell now (emails/final.html.twig, sent by
 * NomineeBroadcast through sendRawHtml), so — as with {@see InviteInboxCompatTest} — the
 * properties are asserted on the RENDERED message: the doctype, the fluid card, the MSO
 * conditional and the preheader all live in the shell, and a test of the fragment would
 * pass or fail on the wrong half of the mail.
 */
final class FinalHoursInboxCompatTest extends EmailInboxCompatCase
{
    private static string $rendered = '';

    protected function setUp(): void
    {
        parent::setUp();
        $vars = EmailCampaign::sampleVars();
        $m    = Emails::compose('final', $vars);
        self::$rendered = OtpService::boot()->brandWrap($m['subject'], $m['html'], $m['category'], $m['hero'],
            (string) $vars['unsubscribe_url'], $m['preheader'], 0, $m['why']);
    }

    protected static function tpl(): string
    {
        return __DIR__ . '/../../templates/emails/final.html.twig';
    }

    protected static function markup(): string
    {
        return parent::stripComments(self::$rendered);
    }

    protected static function fluidWrapperRe(): string
    {
        return '/class="ag-card"[^>]*width="100%"/';
    }

    protected static function cardMaxWidth(): string
    {
        return 'max-width:600px';
    }

    public function test_the_deadline_is_readable_with_images_blocked(): void
    {
        // OUTLOOK DESKTOP and most corporate mail block remote images by default. The
        // countdown GIF is the message's subject, so the deadline exists as text as well.
        $vars = EmailCampaign::sampleVars();
        $withoutImages = (string) preg_replace('/<img[^>]*>/', '', self::markup());
        $this->assertStringContainsString('Voting closes ' . $vars['closes_human'], $withoutImages,
            'with images off there is nothing left saying when voting closes');

        preg_match('~<img[^>]*countdown\.gif[^>]*>~s', self::markup(), $img);
        $img = $img[0] ?? '';
        $this->assertNotSame('', $img, 'countdown image not found');
        $this->assertStringContainsString('alt="' . $vars['countdown_alt'] . '"', $img);
        $this->assertStringContainsString('font-family', $img, 'unstyled alt text renders as 10px serif');
        $this->assertStringContainsString('width="320" height="104"', $img,
            'the box is not CountdownGif\'s own size — a blocked image collapses or the GIF is stretched');
    }

    public function test_it_is_an_announcement_with_its_unsubscribe(): void
    {
        $this->assertStringContainsString('/email/unsubscribe?e=', self::markup());
    }
}
