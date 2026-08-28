<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteMailer;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/admin/events/{id}/invites` — the invitation run for a ceremony.
 *
 * ── THREE SCREENS' WORTH OF WORK, DELIBERATELY IN THREE STEPS ────────────────
 *
 * Build, read, send. Never one button, because the thing being sent is a personal letter
 * to somebody who was shortlisted, carrying their name and their own reference, and it
 * cannot be recalled. So an operator mints the list first and READS it — who is
 * unreachable, whose name is ambiguous, how many of each audience — and only then sends.
 *
 * ── AND WHY SENDING IS BATCHED ───────────────────────────────────────────────
 *
 * Each invitation renders a PDF and does an SMTP round trip, so four hundred of them is
 * far past any shared host's max_execution_time. The same shape as
 * `/__setup/broadcast`: a small batch per request, the page reports where it got to, and
 * the operator presses again. Progress is in `gates_broadcast_log`, so a request that
 * dies half way through has still recorded everything it sent.
 */
final class InvitesController
{
    /** Invitations per press. Each one is a PDF render plus an SMTP round trip. */
    private const BATCH = 15;

    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $invites = EventInvites::forEvent($id);
        $sent    = 0;
        foreach ($invites as $i) if ($i->sent_at !== null) $sent++;

        return $this->view->render($res, 'admin/events/invites.twig', [
            'page_title' => 'Invitations — ' . $event->title,
            'admin_page' => 'events',
            'event'      => (array) $event,
            // The awards this ceremony is for. Read from the join table, not from
            // `gates_site_events.programme_id` — that is the single-programme column the
            // multi-programme migration folds INTO the join table, so on any migrated
            // install it is null and this page was rendering "From ''s published
            // shortlist" above a table of zeroes.
            'programmes' => \AfricaGates\Services\EventInvites::programmesFor($id),
            // Why the table is a table of zeroes, when it is. Five different failures
            // used to render identically here and there is no shell to go and look.
            'blockers'   => \AfricaGates\Services\EventInvites::readiness($id),
            // Read-only: nothing is minted by opening this page.
            'plan'        => EventInvites::plan($id),
            'invites'     => array_map(static fn ($r) => (array) $r, $invites),
            'sent_count'  => $sent,
            'pending'     => count($invites) - $sent,
            'audiences'   => InviteAudience::all(),
            'discount'    => InviteAudience::discountPercent(),
            'lowest_tier' => EventInvites::lowestTier($id),
            'batch'       => self::BATCH,
        ]);
    }

    /** Mint the list. Idempotent — a second press adds only who is new. */
    public function build(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $made  = 0;

        foreach (EventInvites::plan($id) as $audience => $group) {
            foreach ($group['ready'] as $who) {
                $before = DB::table('gates_event_invites')->where('event_id', $id)->count();
                EventInvites::mint($id, $audience, $who, (int) ($who['cycle_id'] ?? 0) ?: null);
                if (DB::table('gates_event_invites')->where('event_id', $id)->count() > $before) $made++;
            }
        }

        $_SESSION['flash'] = $made === 0
            ? 'Nothing new to add — everybody reachable already has an invitation.'
            : $made . ' invitation' . ($made === 1 ? '' : 's') . ' minted. Read the list, then send.';

        return $res->withHeader('Location', '/admin/events/' . $id . '/invites')->withStatus(302);
    }

    /** Send one batch. */
    public function send(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        // One transport for the whole batch, so fifteen invitations are fifteen messages
        // over one connection rather than fifteen handshakes.
        $mailer = OtpService::boot();
        if (!$mailer->smtpConfigured()) {
            $_SESSION['flash_error'] = 'SMTP is not configured — set it in Settings → Email & sender first. '
                                     . 'Nothing was sent.';
            return $res->withHeader('Location', '/admin/events/' . $id . '/invites')->withStatus(302);
        }

        $ok = 0; $failed = 0; $skipped = 0;
        foreach (EventInvites::forEvent($id) as $invite) {
            if ($invite->sent_at !== null) continue;
            if ($ok + $failed >= self::BATCH) break;

            $r = InviteMailer::send($invite, $event, $mailer);
            if ($r['skipped'])   { $skipped++; continue; }
            $r['ok'] ? $ok++ : $failed++;

            // A quarter-second between sends, the same pacing the nominee broadcast uses:
            // a shared host's relay drops a burst and reports success.
            usleep(250000);
        }

        $left = 0;
        foreach (EventInvites::forEvent($id) as $i) if ($i->sent_at === null) $left++;

        $_SESSION[$failed > 0 ? 'flash_error' : 'flash'] = trim(
            $ok . ' sent'
            . ($failed  > 0 ? ', ' . $failed . ' FAILED' : '')
            . ($skipped > 0 ? ', ' . $skipped . ' skipped (opted out or already sent)' : '')
            . '. ' . ($left > 0 ? $left . ' still to go — press Send again.' : 'That is everybody.')
        );

        return $res->withHeader('Location', '/admin/events/' . $id . '/invites')->withStatus(302);
    }

    /** The rendered invitation for one person, so an operator can read it before sending. */
    public function preview(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $invite = EventInvites::byReference((string) ($args['reference'] ?? ''));
        $event  = DB::table('gates_site_events')->where('id', $id)->first();

        if (!$invite || !$event || (int) $invite->event_id !== $id) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }

        $res->getBody()->write(InviteMailer::preview($invite, $event));

        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
