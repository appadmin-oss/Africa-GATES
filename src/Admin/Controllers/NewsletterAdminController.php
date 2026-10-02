<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\EmailInboxGuard;
use AfricaGates\Services\Newsletter\HolidayCalendar;
use AfricaGates\Services\Newsletter\Newsletter;
use AfricaGates\Services\Newsletter\NewsletterAudience;
use AfricaGates\Services\Newsletter\NewsletterSchedule;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SiteUrl;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /admin/campaigns/newsletter — the automated newsletter's schedule, list and issues.
 *
 * A sub-page of Campaigns and linked from it, not a rail item: it is mail to people who
 * asked for it, beside mail to nominees, and the rail stays the seven gates it is.
 *
 * The roles are the campaign screen's — reaching an inbox list is programme work, and
 * `viewer`/`moderator` can read and not change anything. Every decision is audited.
 *
 * Nothing on this screen sends to the list directly except "Send a batch now", which
 * exists so an operator who has just approved an issue can watch the first batch go
 * rather than wait for the next tick. It is the same `sendBatch()` the tick runs, so
 * nobody is mailed twice by pressing it.
 */
final class NewsletterAdminController
{
    private const BASE = '/admin/campaigns/newsletter';

    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
        private readonly ?OtpService $mailer = null,
    ) {}

    private function blocked(Response $res, bool $write = true): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $may  = $write ? ['superadmin', 'admin'] : ['superadmin', 'admin', 'moderator', 'viewer'];
        if (in_array($role, $may, true)) return null;

        $_SESSION['flash_error'] = $write
            ? 'Your role can read the newsletter but not change or send it.'
            : 'You don’t have access to the newsletter.';
        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res, string $to = self::BASE): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    private function adminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    private function sender(Request $req): Newsletter
    {
        return new Newsletter($this->mailer, SiteUrl::base($req));
    }

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        $schedule = NewsletterSchedule::load();
        $issues   = Newsletter::recent(12);
        $active   = Newsletter::active();
        $draft    = null;
        foreach ($issues as $i) {
            if ((string) $i->status === Newsletter::ST_DRAFT) { $draft = $i; break; }
        }
        $focus = $draft ?? $active;

        return $this->view->render($res, 'admin/campaigns/newsletter.twig', [
            'page_title'   => 'Newsletter — Admin',
            'admin_page'   => 'campaigns',
            'schedule'     => $schedule,
            'describe'     => $schedule->describe(),
            'next'         => $schedule->on() ? DisplayTime::showZoned($schedule->nextSlot()) : null,
            'modes'        => NewsletterSchedule::MODES,
            'cadences'     => NewsletterSchedule::CADENCES,
            'weekdays'     => NewsletterSchedule::WEEKDAYS,
            'zone'         => DisplayTime::abbr(),
            'counts'       => NewsletterAudience::counts(),
            'issues'       => $issues,
            'statuses'     => Newsletter::STATUSES,
            'focus'        => $focus,
            'focus_content'=> $focus ? Newsletter::content($focus) : null,
            'focus_plain'  => $focus ? $this->sender($req)->plain($focus, 'reader@example.com') : '',
            'blocker'      => $this->sender($req)->blocker(),
            'holidays'     => HolidayCalendar::load()->upcoming(),
            'spacing'      => Newsletter::HOLIDAY_SPACING_HOURS,
            'batch'        => Newsletter::BATCH,
            'grace'        => NewsletterSchedule::GRACE_HOURS,
        ]);
    }

    public function settings(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $before = NewsletterSchedule::load();
        $after  = NewsletterSchedule::save((array) $req->getParsedBody());
        $this->audit?->record($this->adminId(), 'newsletter.settings', 'setting', null,
            ['from' => $before->mode, 'to' => $after->mode, 'cadence' => $after->cadence]);

        $_SESSION['flash'] = match ($after->mode) {
            NewsletterSchedule::MODE_OFF    => 'Saved. The newsletter is off: nothing is composed or sent.',
            NewsletterSchedule::MODE_REVIEW => 'Saved. ' . $after->describe() . ', an issue is composed and waits here for approval.',
            default                         => 'Saved. ' . $after->describe() . ', an issue is composed and sends itself.',
        };
        return $this->back($res);
    }

    /** Which holidays get an issue, and the declared dates of the two that move. */
    public function holidays(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $body = (array) $req->getParsedBody();
        $on   = array_values(array_filter((array) ($body['on'] ?? []), 'is_string'));
        $date = array_filter((array) ($body['date'] ?? []), 'is_string');
        HolidayCalendar::save($on, $date);
        $this->audit?->record($this->adminId(), 'newsletter.holidays', 'setting', null, ['on' => $on]);

        $_SESSION['flash'] = 'Saved. A greeting issue goes out on each holiday that is ticked, at the newsletter’s hour.';
        return $this->back($res, self::BASE . '#holidays');
    }

    /** Compose the current period's issue now, rather than at its slot. */
    public function compose(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $schedule = NewsletterSchedule::load();
        $key = $schedule->periodKey($schedule->dueSlot() ?? $schedule->nextSlot());
        // Composed for review whatever the mode: a person pressing "compose" wants to read
        // it, and an early issue sending itself is not what that button promises.
        $issue = Newsletter::compose($key, NewsletterSchedule::of([
            'newsletter_mode' => NewsletterSchedule::MODE_REVIEW,
            'newsletter_cadence' => $schedule->cadence,
            'newsletter_weekday' => $schedule->weekday,
            'newsletter_hour' => $schedule->hour,
        ]));

        if ($issue === null) {
            $_SESSION['flash_error'] = 'Could not compose an issue. Is the newsletter migration applied?';
        } else {
            $this->audit?->record($this->adminId(), 'newsletter.compose', 'newsletter_issue', (int) $issue->id);
            $_SESSION[(string) $issue->status === Newsletter::ST_SKIPPED ? 'flash_error' : 'flash'] =
                (string) $issue->status === Newsletter::ST_SKIPPED
                    ? 'Nothing to send: ' . (string) $issue->note
                    : 'Issue #' . (int) $issue->id . ' is ready to read below.';
        }
        return $this->back($res);
    }

    public function approve(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);

        $i = Newsletter::find($id);
        if ($i && ($problems = EmailInboxGuard::problems($this->sender($req)->html($i, 'reader@example.com'))) !== []) {
            $_SESSION['flash_error'] = 'Not approved — this would not render properly in an inbox. ' . implode(' ', $problems);
            return $this->back($res);
        }

        $r = Newsletter::approve($id, $this->adminId());
        if ($r['ok']) $this->audit?->record($this->adminId(), 'newsletter.approve', 'newsletter_issue', $id);
        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        return $this->back($res);
    }

    public function skip(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);

        $r = Newsletter::skip($id);
        if ($r['ok']) $this->audit?->record($this->adminId(), 'newsletter.skip', 'newsletter_issue', $id);
        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        return $this->back($res);
    }

    /**
     * One real copy to a typed address. Writes nothing to the send log, so that address
     * stays eligible for the real issue — the campaign screen's rule, for its reason.
     */
    public function test(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $to = strtolower(trim((string) (((array) $req->getParsedBody())['to'] ?? '')));
        $i  = Newsletter::find($id);

        if (!$i) { $_SESSION['flash_error'] = 'No such issue.'; return $this->back($res); }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'That does not look like an email address.';
            return $this->back($res);
        }
        $site = SiteUrl::base($req);
        if ($site === '' || $this->mailer === null) {
            $_SESSION['flash_error'] = 'Cannot send a test: ' . ($site === '' ? 'APP_URL is not set.' : 'no mailer is configured.');
            return $this->back($res);
        }

        $n = $this->sender($req);
        $sent = $this->mailer->sendRawHtml($to, '[TEST] ' . (string) $i->subject,
            $n->html($i, $to, Carbon::now()->toDateTimeString()), $n->plain($i, $to, Carbon::now()->toDateTimeString()),
            'newsletter-test', \AfricaGates\Services\EmailOptOut::url($site, $to));

        $this->audit?->record($this->adminId(), 'newsletter.test', 'newsletter_issue', $id);
        $_SESSION[($sent['success'] ?? false) ? 'flash' : 'flash_error'] = ($sent['success'] ?? false)
            ? 'Test sent to ' . $to . '. Nothing was written to the send log.'
              . (($sent['fallback'] ?? '') === 'log' ? ' SMTP is not configured, so it went to var/logs/outgoing-mail.log.' : '')
            : 'Test failed: ' . (string) ($sent['error'] ?? 'unknown');
        return $this->back($res);
    }

    public function send(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $i  = Newsletter::find($id);
        if (!$i) { $_SESSION['flash_error'] = 'No such issue.'; return $this->back($res); }

        $r = $this->sender($req)->sendBatch($i);
        $this->audit?->record($this->adminId(), 'newsletter.send', 'newsletter_issue', $id,
            ['sent' => $r['sent'], 'failed' => $r['failed']]);

        if ($r['note'] !== null && $r['sent'] === 0) {
            $_SESSION['flash_error'] = $r['note'];
        } else {
            $_SESSION['flash'] = sprintf('Batch done: %d sent, %d failed. %s', $r['sent'], $r['failed'],
                $r['left'] > 0 ? $r['left'] . ' still to go — the schedule continues it, or press again.'
                               : 'That is everybody.');
        }
        return $this->back($res);
    }

    /**
     * The issue exactly as a reader receives it, as its own document.
     *
     * Its own CSP, because the admin one forbids the inline styles an email is made of —
     * and a narrower one, not a wider one: no script, no frames, sandboxed. The middleware
     * leaves a response's own policy alone.
     */
    public function preview(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $i = Newsletter::find((int) ($args['id'] ?? 0));
        if (!$i) { $_SESSION['flash_error'] = 'No such issue.'; return $this->back($res); }

        $res->getBody()->write($this->sender($req)->html($i, 'reader@example.com', Carbon::now()->toDateTimeString()));
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Content-Security-Policy',
                "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; sandbox");
    }
}
