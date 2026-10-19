<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\OtpService;
use AfricaGates\Support\Accent;
use AfricaGates\Support\SiteUrl;

/**
 * The transactional and campaign emails of design_handoff_letter_email_admin — one body per
 * message in templates/emails/{key}.html.twig, its plain-text twin in {key}.txt.twig, all on
 * the one house shell (OtpService::brandWrap()).
 *
 * ── WHY ONE RENDERER ─────────────────────────────────────────────────────────
 *
 * Each sender used to type its own HTML: nineteen copies of "a heading, a paragraph, a
 * button", each with its own colours (the vote code alone had eight hexes in no palette).
 * A body is now a list of blocks, and the shell, the palette and the inbox rules are
 * decided in one place each.
 *
 * ── WHAT A BODY MAY SAY ──────────────────────────────────────────────────────
 *
 * Only what is true on the live site, read from the code that makes it true (owner, 19 Oct
 * 2026). A link's lifetime is the constant that expires it, never a number typed beside it;
 * a response time is the setting that promises it. The design's own figures that the
 * platform has never measured ("the last 72 hours bring in more votes…") are not in it.
 */
final class Emails
{
    /** Where a key's two parts live. EmailsTest renders every key, so a missing file fails there. */
    private const BODY = 'emails/%s.html.twig';
    private const TEXT = 'emails/%s.txt.twig';

    /** key → the sender that sends it. A key here with no body file is a test failure. */
    public const KEYS = [
        'otp'        => 'AccountController::otpRequest',
        'confirm'    => 'AccountController::sendVerification',
        'reset'      => 'AccountController::forgot',
        'welcome'    => 'AccountController::sendWelcome',
        'nom-in'     => 'NominationAftercare::email (to the nominator)',
        'nominated'  => 'NominationAftercare::email + NomineeConfirmation::ask (to the nominee)',
        'approved'   => 'Admin NominationsController::sendNomineeForm',
        'rejected'   => 'Admin NominationsController (reject)',
        'vote'       => 'CheckoutMailer::receipt (votes added)',
        'notminted'  => 'CheckoutMailer::receipt (votes not added)',
        'gift'       => 'CheckoutMailer::receipt (gift)',
        'refund'     => 'RefundService::tellTheBuyer',
        'milestone'  => 'MilestoneService::checkAndNotify (to the nominee)',
        'ticket'     => 'EventTicketMailer::send (confirmed and waitlisted)',
    ];

    /**
     * Render one email into the pieces the shell takes.
     *
     * @param array<string,mixed> $vars
     * @return array{subject:string, preheader:string, html:string, text:string, category:string,
     *               hero:string, why:string, transactional:bool}
     */
    public static function compose(string $key, array $vars): array
    {
        if (!isset(self::KEYS[$key])) throw new \InvalidArgumentException('No such email: ' . $key);
        $base = rtrim((string) ($vars['site_url'] ?? SiteUrl::base()), '/');
        $vars += ['site_url' => $base, 'postal_address' => MailConfig::postal(), 'c' => Accent::mail()];

        $env  = EmailView::env();
        $tpl  = $env->load(sprintf(self::BODY, $key));
        $one  = static fn(string $b): string => trim(html_entity_decode($tpl->renderBlock($b, $vars), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hero = $one('hero');

        return [
            'subject'       => $one('subject'),
            'preheader'     => $one('preheader'),
            'category'      => $one('category'),
            'why'           => $one('why'),
            'transactional' => $one('transactional') !== '0',
            'hero'          => $hero !== '' ? $base . '/assets/img/illustrations/' . basename($hero) . '.jpg' : '',
            // The blocks are table rows; this is the table they are rows of.
            'html'          => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                             . $tpl->renderBlock('body', $vars) . '</table>',
            'text'          => trim($env->render(sprintf(self::TEXT, $key), $vars)),
        ];
    }

    /**
     * Compose and send through the house shell.
     *
     * An announcement (transactional = 0) must be given its unsubscribe URL; a transactional
     * message never carries one — a sign-in code with an Unsubscribe link invites somebody to
     * switch off the thing that lets them in.
     *
     * @param array<string,mixed> $vars
     * @param list<array<string,string>> $attachments as OtpService::sendBranded() takes them
     * @return array{ok:bool,error?:string}
     */
    public static function send(OtpService $mailer, string $to, string $key, array $vars,
                                array $attachments = [], string $unsubscribeUrl = ''): array
    {
        $m = self::compose($key, $vars);
        return $mailer->sendBranded($to, $m['subject'], $m['html'], $m['text'], $m['category'], $m['hero'],
            $m['transactional'] ? '' : $unsubscribeUrl, $attachments, $m['preheader'], 0, $m['why']);
    }
}
