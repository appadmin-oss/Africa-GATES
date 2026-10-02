<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Newsletter\NewsletterAudience;
use AfricaGates\Services\Newsletter\NewsletterComposer;
use AfricaGates\Services\Newsletter\NewsletterSchedule;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Support\ClientIp;
use AfricaGates\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /newsletter — the way in, and /email/confirm — the yes.
 *
 * ── A FORM THAT POSTS, NOT A FETCH ───────────────────────────────────────────
 *
 * The only signup that existed was a script in main.js bound to a `.subscribe-form` that
 * no template renders, so the newsletter had no door at all. This one is a plain form:
 * it works without JavaScript, it lands on a page that says what to do next, and it is
 * linked from the footer of every page.
 *
 * ── GET SHOWS, POST CONFIRMS ─────────────────────────────────────────────────
 *
 * The same reasoning as `/email/unsubscribe`: mail scanners fetch every link in a message.
 * A confirmation that fired on the GET would be confirmed by Outlook's Safe Links for
 * anybody whose employer scans their mail — the exact person double opt-in exists to ask.
 */
final class NewsletterController
{
    public function __construct(
        private readonly Twig $view,
        private readonly RateLimitService $rateLimit,
        private readonly ?OtpService $mailer = null,
    ) {}

    public function show(Request $req, Response $res): Response
    {
        return $this->page($res, ['sent' => ($req->getQueryParams()['sent'] ?? '') === '1']);
    }

    public function join(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $email = strtolower(trim((string) ($b['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->page($res->withStatus(422), [
                'email_error' => 'Enter your email address, like name@example.com.', 'old' => $email,
            ]);
        }

        // ClientIp, not REMOTE_ADDR: behind the proxy every visitor shares one address, and
        // a per-address cap there is a cap on the whole site.
        $ip = hash('sha256', ClientIp::from($req));
        if (!$this->rateLimit->check($ip, 'newsletter_subscribe', 5, 3600)) {
            return $this->page($res->withStatus(429), [
                'email_error' => 'Too many signups from this connection in the last hour. Try again later.',
                'old'   => $email,
            ]);
        }

        try {
            NewsletterAudience::join($email, 'newsletter-page', $ip, SiteUrl::base($req),
                $this->mailer ? NewsletterAudience::transport($this->mailer) : null);
        } catch (\Throwable) {
            // The person is told the same thing either way; a second attempt writes the row.
        }

        // Post / redirect / get, so a refresh does not resubmit and the address does not
        // sit in the URL.
        return $res->withHeader('Location', '/newsletter?sent=1')->withStatus(303);
    }

    public function confirmShow(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $e = (string) ($q['e'] ?? '');
        $t = (string) ($q['t'] ?? '');

        return $this->confirmPage($res, NewsletterAudience::verify($e, $t), false, $e, $t);
    }

    public function confirm(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $email = NewsletterAudience::verify((string) ($b['e'] ?? ''), (string) ($b['t'] ?? ''));
        if ($email !== null) NewsletterAudience::confirm($email);

        return $this->confirmPage($res, $email, true, '', '');
    }

    /** @param array<string,mixed> $extra */
    private function page(Response $res, array $extra): Response
    {
        $schedule = NewsletterSchedule::load();

        return $this->view->render($res, 'pages/newsletter/index.twig', $extra + [
            'page_title'       => 'Newsletter — Africa GATES',
            'meta_description' => 'Nominations, voting deadlines, results and events from Africa GATES, in one email.',
            'gates_page'       => 'newsletter',
            // Read from the schedule, never typed: the day this page promises is the day
            // the setting sends on.
            'schedule'         => $schedule->on() ? $schedule->describe() : null,
            'sections'         => array_values(NewsletterComposer::SECTIONS),
            'sent'             => false,
            'email_error'      => null,
            'old'              => '',
        ]);
    }

    private function confirmPage(Response $res, ?string $email, bool $done, string $e, string $t): Response
    {
        return $this->view->render($res, 'pages/newsletter/confirm.twig', [
            'page_title' => 'Confirm your subscription — Africa GATES',
            'gates_page' => 'newsletter',
            'task_page'  => true,
            'valid'      => $email !== null,
            'done'       => $done,
            'email'      => $email,
            'e'          => $e,
            't'          => $t,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
