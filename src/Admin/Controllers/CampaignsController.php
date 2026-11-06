<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\EmailCampaign;
use AfricaGates\Services\EmailInboxGuard;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\NomineeBroadcast;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\SiteUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Nominee campaigns: write one, look at it, send it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `HANDOFF.md` §3.6: the campaign was a Twig file sent from `/__setup/broadcast`. The
 * operator has no SSH, so changing one comma of copy was a full deploy cycle — and
 * `/__setup/*` is where the database migrator lives, which is the wrong neighbourhood for
 * something a comms person uses weekly.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS DELIBERATELY NOT REBUILT HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Anything to do with WHO gets mail. {@see NomineeBroadcast} resolves recipients, applies
 * {@see EmailOptOut}, and writes `gates_broadcast_log` under its `UNIQUE(campaign,
 * email_hash)` — the one thing that makes an interrupted send resume instead of repeat.
 * This controller hands it a campaign and reads back its plan. §6 is explicit about the
 * reason: a third recipient query would drift from the other two, and the way that drifts
 * on a mail sender is that one of them mails somebody the other already did.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ORDER OF THE SCREENS IS THE SAFETY MECHANISM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Edit → preview → test-send → approve → read the plan → send. Every step is reachable
 * only from the one before it, and `send` refuses unless the campaign is `approved`. The
 * blast radius is every nominee's inbox and there is no undo, so nobody should be able to
 * reach a live send by pressing one button twice.
 */
final class CampaignsController
{

    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
        private readonly ?OtpService $mailer = null,
    ) {}

    /**
     * Composing a campaign is programme work, like running an interview — not a governance
     * act. But it reaches every nominee's inbox, so `viewer` can read and not write.
     */
    private function blocked(Response $res, bool $write = true): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $may  = $write ? ['superadmin', 'admin'] : ['superadmin', 'admin', 'moderator', 'viewer'];
        if (in_array($role, $may, true)) return null;

        $_SESSION['flash_error'] = $write
            ? 'Your role can read campaigns but not write or send them.'
            : 'You don’t have access to campaigns.';
        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    // ══ list ═════════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        return $this->view->render($res, 'admin/campaigns/index.twig', [
            'page_title' => 'Campaigns — Admin',
            'admin_page' => 'campaigns',
            'campaigns'  => EmailCampaign::all(),
            'statuses'   => EmailCampaign::STATUSES,
        ]);
    }

    public function create(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $body = (array) $req->getParsedBody();
        $r    = EmailCampaign::create(
            (string) ($body['name'] ?? ''), (string) ($body['subject'] ?? ''),
            (int) ($_SESSION['admin_id'] ?? 0)
        );

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if (!$r['ok']) return $this->back($res, '/admin/campaigns');

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'campaign.create', 'campaign', $r['id']);
        return $this->back($res, '/admin/campaigns/' . $r['id']);
    }

    // ══ edit ═════════════════════════════════════════════════════════════════

    public function show(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $c  = EmailCampaign::find($id);
        if (!$c) {
            $_SESSION['flash_error'] = 'No such campaign.';
            return $this->back($res, '/admin/campaigns');
        }

        // The plan is read on every load, because §6 is firm that nobody should reach
        // "send" without having seen who it reaches. It touches no network — it is
        // database work — so it is safe on page load in a way the reconciler is not.
        $plan = (new NomineeBroadcast())->forCampaign($c)->plan();

        // Rendered against a deliberately long sample recipient, so the size shown is the
        // worst realistic case rather than a flattering one.
        $html     = EmailCampaign::renderFor($c, EmailCampaign::sampleVars());
        $problems = EmailInboxGuard::problems($html);

        return $this->view->render($res, 'admin/campaigns/show.twig', [
            'page_title' => 'Campaign — Admin',
            'admin_page' => 'campaigns',
            'c'          => $c,
            'blocks'     => EmailCampaign::blocksOf($c),
            'types'      => EmailCampaign::BLOCKS,
            'links'      => EmailCampaign::LINKS,
            'tokens'     => EmailCampaign::PLACEHOLDERS,
            'counts'     => $plan['counts'],
            'ambiguous'  => array_slice($plan['ambiguous'], 0, 10),
            'bytes'      => strlen($html),
            'max_bytes'  => EmailInboxGuard::MAX_BYTES,
            'problems'   => $problems,
            'plain'      => EmailCampaign::plainFor($c, EmailCampaign::sampleVars()),
            'versions'   => EmailCampaign::versions($id, 10),
            'site_ok'    => SiteUrl::base($req) !== '',
            // The optimiser (Mail\Outbox, Mail\MailTraction): where the queue stands, what it
            // achieved, and the pace it is held to.
            'goals'       => \AfricaGates\Services\Mail\MailTraction::GOALS,
            'outbox'      => \AfricaGates\Services\Mail\Outbox::status((string) $c->slug),
            'traction'    => \AfricaGates\Services\Mail\MailTraction::report((string) $c->slug),
            'pace'        => \AfricaGates\Services\Mail\Outbox::settings(),
            'budget'      => \AfricaGates\Services\Mail\Outbox::budget(),
            'first_batch' => \AfricaGates\Services\Mail\Outbox::FIRST_BATCH,
            'ab_hours'    => \AfricaGates\Services\Mail\Outbox::AB_WAIT_HOURS,
            'window_days' => \AfricaGates\Services\Mail\MailTraction::WINDOW_DAYS,
        ]);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id   = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();

        $r = EmailCampaign::save(
            $id, (string) ($body['subject'] ?? ''), (string) ($body['preheader'] ?? ''),
            $this->blocksFrom($body), (int) ($_SESSION['admin_id'] ?? 0),
            isset($body['goal']) ? (string) $body['goal'] : null,
            isset($body['subject_b']) ? (string) $body['subject_b'] : null
        );

        // A refusal carries WHY, in the operator's terms — a save that failed the inbox
        // rules with only "could not save" on screen is a save nobody can fix.
        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['problems'] === []
            ? $r['message']
            : $r['message'] . ' ' . implode(' ', $r['problems']);

        if ($r['ok']) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'campaign.save', 'campaign', $id);
        }
        return $this->back($res, '/admin/campaigns/' . $id);
    }

    /**
     * Blocks out of a flat form post.
     *
     * The form names fields `blocks[0][type]`, `blocks[0][text]` … so ordering is the array
     * order and reordering is a matter of renumbering — no JSON in a textarea, which would
     * put a syntax error between a comms person and their own copy.
     *
     * Everything is handed to {@see EmailCampaign::clean()}, which is the real boundary:
     * unknown types, unknown fields and link keys that are not on the whitelist are dropped
     * there rather than trusted from here.
     *
     * @param  array<string,mixed> $body
     * @return list<array<string,mixed>>
     */
    private function blocksFrom(array $body): array
    {
        $in = $body['blocks'] ?? [];
        if (!is_array($in)) return [];

        // A posted array is keyed by the form's indices, which the browser preserves in
        // order. ksort with SORT_NUMERIC keeps that stable even if a row was removed.
        $rows = array_filter($in, 'is_array');
        ksort($rows, SORT_NUMERIC);

        return array_values($rows);
    }

    // ══ approve ══════════════════════════════════════════════════════════════

    public function approve(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $r  = EmailCampaign::approve($id, (int) ($_SESSION['admin_id'] ?? 0));

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'campaign.approve', 'campaign', $id);
        }
        return $this->back($res, '/admin/campaigns/' . $id);
    }

    // ══ test send ════════════════════════════════════════════════════════════

    /**
     * One real copy, to an address of the operator's choosing.
     *
     * Borrowed from the setup endpoint, including the reasoning: it takes the FIRST
     * RESOLVED RECIPIENT'S data so the personalisation, the countdown's cycle and the vote
     * link are genuine rather than placeholders — what you read is what a nominee reads.
     *
     * It writes NOTHING to `gates_broadcast_log`. Logging a test would mark that address
     * as already-sent and quietly exclude it from the real run.
     */
    public function test(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $to = trim((string) (((array) $req->getParsedBody())['to'] ?? ''));
        $c  = EmailCampaign::find($id);

        if (!$c) {
            $_SESSION['flash_error'] = 'No such campaign.';
            return $this->back($res, '/admin/campaigns');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'That does not look like an email address.';
            return $this->back($res, '/admin/campaigns/' . $id);
        }

        $site = SiteUrl::base($req);
        if ($site === '') {
            $_SESSION['flash_error'] = 'APP_URL is not set, and every link in this email is absolute. '
                                     . 'Set it before sending anything.';
            return $this->back($res, '/admin/campaigns/' . $id);
        }

        $svc  = (new NomineeBroadcast())->forCampaign($c);
        $plan = $svc->plan();
        if (($plan['queue'] ?? []) === []) {
            $_SESSION['flash_error'] = 'No nominee resolved in a live voting cycle, so there is no real '
                                     . 'personalisation to preview. Check the counts on this page first.';
            return $this->back($res, '/admin/campaigns/' . $id);
        }

        // Whether or not they are sendable: a suppressed or already-sent nominee is still
        // a valid shape to preview.
        $sample  = $plan['queue'][0];
        $preview = ['nominee' => $sample['nominee'], 'cycle' => $sample['cycle'], 'email' => $to];

        $sent = $this->mailer?->sendRawHtml(
            $to, '[TEST] ' . $svc->subject(),
            $svc->html($preview, $site), $svc->plain($preview, $site),
            'campaign-test', EmailOptOut::url($site, $to)
        ) ?? ['success' => false, 'error' => 'No mailer is configured.'];

        $_SESSION[($sent['success'] ?? false) ? 'flash' : 'flash_error'] = ($sent['success'] ?? false)
            ? 'Test sent to ' . $to . ', personalised as ' . (string) $sample['nominee']->name
              . '. The subject carries a [TEST] prefix and nothing was written to the send log, so that '
              . 'address is still eligible for the real run.'
              . (($sent['fallback'] ?? '') === 'log'
                  ? ' NOTE: SMTP is not configured, so it went to var/logs/outgoing-mail.log.' : '')
            : 'Test failed: ' . (string) ($sent['error'] ?? 'unknown');

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'campaign.test', 'campaign', $id);
        return $this->back($res, '/admin/campaigns/' . $id);
    }

    // ══ send ═════════════════════════════════════════════════════════════════

    /**
     * Queue the whole campaign, send the first batch now, and let the outbox do the rest.
     *
     * ── WHY IT QUEUES ────────────────────────────────────────────────────────
     *
     * A few thousand SMTP calls at a quarter-second each is far past any shared host's
     * `max_execution_time`, so this used to send twenty-five and ask to be pressed again.
     * Mail\Outbox holds the list instead (UNIQUE(campaign, address)), and the maintenance
     * tick drains it at the account's pace. `gates_broadcast_log` still records every send,
     * so the plan above goes on excluding whoever has been reached.
     *
     * ── AND WHY IT REFUSES UNLESS APPROVED ───────────────────────────────────
     *
     * The blast radius is every nominee's inbox and there is no undo. `approved` is a
     * separate deliberate act, recorded against a person, and any edit clears it — because
     * an approval is of specific words.
     */
    public function send(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;

        $id = (int) ($args['id'] ?? 0);
        $c  = EmailCampaign::find($id);
        $to = '/admin/campaigns/' . $id;

        if (!$c) {
            $_SESSION['flash_error'] = 'No such campaign.';
            return $this->back($res, '/admin/campaigns');
        }
        if (!in_array((string) $c->status, ['approved', 'sent'], true)) {
            $_SESSION['flash_error'] = 'That campaign is not approved yet. Read the plan, then approve it — '
                                     . 'the approval is what makes the send button live.';
            return $this->back($res, $to);
        }

        $site = SiteUrl::base($req);
        if ($site === '') {
            $_SESSION['flash_error'] = 'APP_URL is not set. Every link in this email is absolute, so a send '
                                     . 'now would go out with broken links.';
            return $this->back($res, $to);
        }

        // Re-checked at the door, not only at save. The template can change under a
        // campaign that was approved a week ago.
        $problems = EmailInboxGuard::problems(EmailCampaign::renderFor($c, EmailCampaign::sampleVars()));
        if ($problems !== []) {
            $_SESSION['flash_error'] = 'Not sent — this would not render properly in an inbox. '
                                     . implode(' ', $problems);
            return $this->back($res, $to);
        }
        if ($this->mailer === null) {
            $_SESSION['flash_error'] = 'No mailer is configured, so nothing was sent.';
            return $this->back($res, $to);
        }

        $svc  = (new NomineeBroadcast())->forCampaign($c);
        $plan = $svc->plan();

        // ── QUEUED ONCE, SENT BY ITSELF (Mail\Outbox) ───────────────────────────
        // This used to send twenty-five and ask to be pressed again: a list of four thousand
        // was a hundred and sixty presses, and a send started on Friday evening stopped when
        // the person went home. Every recipient is queued now, in priority order, the first
        // batch goes at once, and the maintenance tick sends the rest at the account's pace.
        if (!\AfricaGates\Services\Mail\Outbox::ready()) {
            $_SESSION['flash_error'] = 'The database needs its latest update (the email outbox) before a campaign can be sent.';
            return $this->back($res, $to);
        }
        $queued = \AfricaGates\Services\Mail\Outbox::enqueueCampaign($c, $plan['sendable']);
        $first  = \AfricaGates\Services\Mail\Outbox::drain($this->mailer, $site, \AfricaGates\Services\Mail\Outbox::FIRST_BATCH, null, 20.0);
        $state  = \AfricaGates\Services\Mail\Outbox::status((string) $c->slug);

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'campaign.send', 'campaign', $id, ['queued' => $queued]);

        $_SESSION['flash'] = sprintf('Started: %d queued, %d sent just now. %s',
            $queued, $first['sent'],
            $state['queued'] > 0
                ? number_format($state['queued']) . ' more go out automatically' . ($state['eta'] ? ', done by about ' . $state['eta'] . ' (WAT)' : '') . '. Nobody is mailed twice.'
                : 'That is everybody.')
            . ($first['note'] !== '' ? ' ' . $first['note'] : '');
        return $this->back($res, $to);
    }

    /** Stop a campaign that is still sending: whoever has not been sent it, is not. */
    public function stop(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $c  = EmailCampaign::find($id);
        if (!$c) {
            $_SESSION['flash_error'] = 'No such campaign.';
            return $this->back($res, '/admin/campaigns');
        }
        $n = \AfricaGates\Services\Mail\Outbox::cancel((string) $c->slug);
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'campaign.stop', 'campaign', $id, ['stopped' => $n]);
        $_SESSION['flash'] = $n > 0 ? 'Stopped. ' . number_format($n) . ' people on the list will not be sent it.' : 'Nothing was waiting to be sent.';
        return $this->back($res, '/admin/campaigns/' . $id);
    }
}
