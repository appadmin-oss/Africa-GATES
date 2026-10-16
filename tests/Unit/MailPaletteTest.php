<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailCampaign;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\Accent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PublicSurface;

/**
 * MAIL DRAWS IN THE PALETTE, BY NAME, AND A WRONG NAME CANNOT GO SILENT.
 *
 * The owner decided on 3 Oct 2026 that the seven email templates and the house shell
 * they arrive in are rebuilt to the handoff's type ladder with colours from
 * `Support\Accent` (docs/handoff/inventory/_emails.md). Mail cannot read `var()`, so each
 * sender passes `Accent::mail()` to its template as `c` and the template writes
 * `{{ c.ink }}` or `{{ c['ink-2'] }}`.
 *
 * That design has one failure that nothing else here would see, and it is the reason
 * this file exists: the mail environments are NOT strict. `{{ c.inc }}` — a typo — and a
 * sender that forgets to pass `c` at all both render as an EMPTY STRING. The message
 * still sends; the button arrives with `background-color:;`, which Outlook and Gmail
 * draw as no background, white words on white, on every send. `Accent::hex()` throws on
 * an unknown name precisely to stop that, and a template lookup bypasses it. So:
 *
 *  - every `c.<name>` in a mail template is a real palette token;
 *  - every sender that renders a mail template passes `Accent::mail()` in the same call;
 *  - a real render carries no empty colour and no colour outside the palette;
 *  - the shell (`OtpService::brandWrap()`, PHP, invisible to the template sweeps) is on
 *    the ladder and in the palette too.
 *
 * Each of these was watched failing before it was trusted (docs/handoff/PHASE-2.md §10).
 */
final class MailPaletteTest extends TestCase
{
    private const SENDERS = [
        'src/Services/InviteMailer.php'          => 'emails/invitation.twig',
        'src/Services/InviteReminders.php'       => 'emails/invite-reminder.twig',
        'src/Services/QuestionnaireInvites.php'  => 'emails/questionnaire.twig',
        'src/Services/StandNotice.php'           => 'emails/stand-decision.twig',
        'src/Services/NomineeBroadcast.php'      => 'emails/final-hours.twig',
        'src/Services/EmailCampaign.php'         => 'emails/campaign.twig',
        'src/Services/Newsletter/Newsletter.php' => 'emails/newsletter.twig',
    ];

    public function test_every_colour_a_mail_template_names_is_a_palette_token(): void
    {
        $bad = [];
        $seen = 0;
        foreach (glob(PublicSurface::root() . '/templates/emails/*.twig') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all('~\bc\.([a-zA-Z_][\w]*)|\bc\[\s*\'([\w-]+)\'\s*\]~', $src, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $name = ($hit[2] ?? '') !== '' ? $hit[2] : $hit[1];
                $seen++;
                if (!array_key_exists($name, Accent::mail())) $bad[] = basename($file) . ": c.$name";
            }
            $this->assertDoesNotMatchRegularExpression('~#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?![\w-])|rgba?\(~',
                (string) preg_replace('~\{#.*?#\}~s', '', $src), basename($file) . ' types a colour instead of naming a token');
        }
        $this->assertGreaterThan(200, $seen, 'the sweep found almost no palette references — is it reading the templates?');
        $this->assertSame([], array_values(array_unique($bad)),
            'a mail template names a colour the palette does not have; it would render as an empty value');
    }

    /** Every template is rendered by exactly one sender, and that call passes the palette. */
    public function test_every_sender_hands_its_template_the_palette(): void
    {
        $root = PublicSurface::root();
        $templates = array_map(static fn(string $f): string => 'emails/' . basename($f),
            glob($root . '/templates/emails/*.twig') ?: []);
        sort($templates);
        $named = array_values(self::SENDERS);
        sort($named);
        $this->assertSame($templates, $named, 'a mail template has no sender listed here, or a listed one is gone');

        foreach (self::SENDERS as $file => $tpl) {
            $src = (string) file_get_contents($root . '/' . $file);
            $this->assertMatchesRegularExpression(
                '~->render\(\s*\'' . preg_quote($tpl, '~') . '\'[^;]*~s', $src, "$file no longer renders $tpl");
            if ($tpl === 'emails/newsletter.twig') {
                // The view is built in vars(); the palette is one of its keys.
                $this->assertMatchesRegularExpression('~\'c\'\s*=>\s*Accent::mail\(\)~', $src, "$file builds no `c`");
                continue;
            }
            preg_match('~->render\(\s*\'' . preg_quote($tpl, '~') . '\'(.*?)\);~s', $src, $call);
            $this->assertStringContainsString('Accent::mail()', $call[1] ?? '',
                "$file renders $tpl without passing the palette — every colour in it would be blank");
        }
    }

    /** A real render: nothing blank, nothing off the palette. */
    public function test_a_rendered_campaign_and_the_house_shell_draw_only_in_the_palette(): void
    {
        $campaign = EmailCampaign::render('Subject', 'Preheader', EmailCampaign::starter(), EmailCampaign::sampleVars());
        $shell = (new OtpService([]))->brandWrap('Subject', '<p>Body</p>', 'Reminder', 'https://x.test/h.jpg', 'https://x.test/u');

        foreach (['campaign' => $campaign, 'shell' => $shell] as $what => $html) {
            $this->assertDoesNotMatchRegularExpression('~(?:color|background(?:-color)?)\s*:\s*(?:!important\s*)?[;"\'}]~i',
                $html, "the $what has an empty colour declaration");
            $this->assertDoesNotMatchRegularExpression('~\b(?:bgcolor|fillcolor|strokecolor)\s*=\s*(["\'])\s*\1~i',
                $html, "the $what has an empty colour attribute");
            preg_match_all('~#[0-9a-fA-F]{6}\b~', $html, $m);
            $this->assertNotEmpty($m[0], "the $what carries no colour at all");
            $off = array_values(array_unique(array_diff(array_map('strtolower', $m[0]), array_values(Accent::mail()))));
            $this->assertSame([], $off, "the $what draws in colours that are not the palette's");
        }
        $this->assertStringNotContainsString('rgba(255', $shell, 'the shell went back to typing white at an alpha');
    }

    /** The shell is PHP, so TypeScaleTest never reads it: it is held to the ladder here. */
    public function test_the_house_shell_is_on_the_ladder(): void
    {
        $shell = (new OtpService([]))->brandWrap('Subject', '<p>Body</p>', 'Reminder');
        $f = TypeScaleTest::findings(['templates/emails/_shell.twig' => $shell]);
        $this->assertSame([], $f['off'], 'the house shell sets type off the §6.2 ladder');
        $this->assertStringContainsString('mso-hide:all', $shell, 'the preheader collapse is gone, so 1px would be type');
    }
}
