<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{EventArrivals, EventScanPass, EventTicketService, InviteAudience, InvitePass};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The door: a scanning page anybody holding a live pass can work, with no account.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE INTERFACE HAS TO SURVIVE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is the most hostile screen on the platform, and not because of attackers. It is used
 * one-handed, outdoors, at night, by somebody who has never seen it before, with a queue
 * behind them and a phone on one bar. Every decision below comes from that:
 *
 *   THE VERDICT IS A WORD, NOT A COLOUR.   Green and red alone fail WCAG 1.4.1 and fail
 *                                          harder in sunlight and colour blindness. Admit /
 *                                          Already in / Refuse are the first thing on screen,
 *                                          at a size readable at arm's length.
 *   THREE STATES, NOT TWO.                 "Already checked in" is neither success nor
 *                                          failure — it is the case that needs a human — so
 *                                          it gets its own colour, word and icon rather than
 *                                          being folded into either.
 *   NO PAGE RELOAD.                        The admin check-in posts and redirects, which is a
 *                                          full page load per attendee. This returns JSON and
 *                                          repaints in place: at a door, the reload IS the
 *                                          queue.
 *   THE TYPED BOX IS THE DOOR.             BarcodeDetector is absent on iOS Safari, and a
 *                                          camera fails on a wet lens and a cracked screen.
 *                                          Manual entry is always present and always focused,
 *                                          never a fallback hidden behind a toggle.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHAT IT REFUSES TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It cannot list attendees, cannot search by name, cannot see money and cannot reach another
 * event. A door pass is permission to answer one question about one code. Anything more would
 * make a link shared into a volunteers' WhatsApp group into a data breach.
 */
final class DoorController
{
    public function __construct(private readonly Twig $view) {}

    private function json(Response $res, array $data, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store')
                   ->withStatus($status);
    }

    /**
     * GET /door/{token} — the scanning page.
     *
     * A refused pass still renders a PAGE rather than a bare 404: the person holding it is
     * standing at a door, and "this opens at 18:00" is the difference between waiting two
     * minutes and ringing an organiser mid-event.
     */
    public function page(Request $req, Response $res, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $r     = EventScanPass::resolve($token);

        $common = [
            'gates_page' => 'events',
            'has_hero'   => false,
            // The two flags that strip the heavy stack. This page renders on a phone with one
            // bar at a venue door; every library it does not fetch is a request that cannot
            // time out there.
            'lite_page'  => true,
            'task_page'  => true,
            'page_title' => 'Door — Africa GATES',
        ];

        if (!$r['ok']) {
            $pass = $r['pass'];
            return $this->view->render($res->withStatus(403), 'pages/events/door.twig', $common + [
                'ok'      => false,
                'reason'  => $r['reason'],
                'message' => $r['message'],
                // The times, when we know them — that is what makes the refusal actionable.
                'opens_at'  => $pass !== null ? (string) ($pass->opens_at ?? '') : '',
                'closes_at' => $pass !== null ? (string) ($pass->closes_at ?? '') : '',
                'event'   => null, 'token' => '', 'label' => '',
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        EventScanPass::touch((int) $r['pass']->id);

        return $this->view->render($res, 'pages/events/door.twig', $common + [
            'ok'        => true,
            'reason'    => '', 'message' => '',
            'event'     => (array) $r['event'],
            'label'     => (string) ($r['pass']->label ?? ''),
            'closes_at' => (string) $r['pass']->closes_at,
            'opens_at'  => (string) ($r['pass']->opens_at ?? ''),
            'token'     => $token,
            // The room, so somebody on the door knows whether they are near the end.
            'admitted'  => $this->admitted((int) $r['event']->id),
            // Same resolver, same reason: tickets sold PLUS invitations actually sent.
            'expected'  => EventArrivals::expected((int) $r['event']->id),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * POST /door/{token}/check — one code, one verdict, as JSON.
     *
     * The pass is re-resolved on EVERY check rather than trusted from the page load. A door is
     * open for hours and the tab stays up the whole time; without this, a pass that expired or
     * was revoked at 23:00 would keep admitting people from a page loaded at 18:00 — which is
     * the exact failure the time window exists to prevent.
     */
    public function check(Request $req, Response $res, array $args): Response
    {
        $r = EventScanPass::resolve((string) ($args['token'] ?? ''));
        if (!$r['ok']) {
            return $this->json($res, [
                'ok' => false, 'verdict' => 'closed',
                'title' => 'This door is closed', 'detail' => $r['message'],
            ], 403);
        }

        $body = (array) $req->getParsedBody();
        $code = (string) ($body['code'] ?? '');

        // The scanner reads whatever is in the QR. Ours encodes the bare ticket code, but a
        // full ticket URL is what somebody gets if they scan the wrong thing on the page — so
        // the tail of a /events/ticket/... URL is accepted rather than refused with "no code".
        if (str_contains($code, '/')) {
            $code = (string) preg_replace('~^.*/~', '', rtrim(trim($code), '/'));
        }

        $pass = $r['pass'];

        // ── A GUEST OF HONOUR IS NOT A TICKET ────────────────────────────────
        //
        // Nominees and judges are invited, not sold to — see the note in the
        // gates_event_invites migration on why minting them complimentary tickets would
        // have counted them as sales and stopped the hall selling. So their pass is a
        // different thing and it is checked here, before the ticket path.
        //
        // Recognised by SHAPE, not tried-and-fallen-back-to: an invitation code is
        // `AGI-XXXXXXXX.<window>.<sig>`, and a ticket code has no dots in it. Trying the
        // ticket lookup first and falling through on failure would make the door an
        // oracle — two different "not found" answers for the same scan tell somebody
        // holding a random string which namespace it missed.
        if (substr_count($code, '.') === 2) {
            $verdict = $this->honour($code, (int) $pass->event_id, $this->via($pass));
            EventScanPass::touch((int) $pass->id);

            return $this->json($res, $verdict + [
                'admitted' => $this->admitted((int) $pass->event_id),
            ]);
        }

        $v = EventTicketService::checkIn($code, (int) $pass->event_id, $this->via($pass));

        EventScanPass::touch((int) $pass->id);

        return $this->json($res, [
            'ok'       => $v['verdict'] === 'admit',
            'verdict'  => $v['verdict'],
            'title'    => $v['title'],
            'detail'   => $v['detail'],
            'name'     => $v['name'],
            'tier'     => $v['tier'],
            'seats'    => $v['seats'],
            'code'     => $v['code'],
            // Recomputed after the write, so the running count on the page is the real one
            // rather than a number the browser has been incrementing since it was opened.
            'admitted' => $this->admitted((int) $pass->event_id),
        ]);
    }

    /**
     * Which door this is, as one string, written into `checked_in_via` and the arrivals log.
     *
     * One helper because the ticket path, the honour path and a reversal must all label
     * themselves identically — an evening whose log says 'door: Main gate' for admissions and
     * 'door' for reversals cannot be read as one sequence.
     */
    private function via(object $pass): string
    {
        $label = trim((string) ($pass->label ?? ''));

        return 'door' . ($label !== '' ? ': ' . $label : '');
    }

    /**
     * POST /door/{token}/undo — take an admission back.
     *
     * At the DOOR and not only in the admin panel, because the door is where the mistake is
     * noticed: a camera catches the ticket of the person behind in the queue, and the holder
     * is then refused, cannot transfer the ticket, and cannot be refunded. Sent to an admin
     * screen it is not fixed until after the event, by which time they have gone home.
     *
     * The pass is re-resolved here exactly as it is on a check — a revoked pass must not be
     * able to un-admit people from a tab that has been open since six.
     */
    public function undo(Request $req, Response $res, array $args): Response
    {
        $r = EventScanPass::resolve((string) ($args['token'] ?? ''));
        if (!$r['ok']) {
            return $this->json($res, [
                'ok' => false, 'verdict' => 'closed',
                'title' => 'This door is closed', 'detail' => $r['message'],
            ], 403);
        }

        $pass = $r['pass'];
        $body = (array) $req->getParsedBody();
        $code = (string) ($body['code'] ?? '');
        if (str_contains($code, '/')) {
            $code = (string) preg_replace('~^.*/~', '', rtrim(trim($code), '/'));
        }

        // The door's reason is fixed rather than typed. A steward with a queue will not write
        // prose, and an empty box would either block the fix or fill the log with "asdf" —
        // whereas "scanned in error at the door" is true of every reversal made here and is
        // the sentence somebody reading the log afterwards actually needs.
        $v = EventTicketService::undoCheckIn(
            $code, (int) $pass->event_id, $this->via($pass), null, 'scanned in error at the door');

        EventScanPass::touch((int) $pass->id);

        return $this->json($res, [
            'ok'       => $v['ok'],
            'verdict'  => $v['ok'] ? 'undone' : 'refuse',
            'title'    => $v['title'],
            'detail'   => $v['detail'],
            'name'     => $v['name'],
            'tier'     => '', 'seats' => 0, 'code' => $code,
            'admitted' => $this->admitted((int) $pass->event_id),
        ], $v['ok'] ? 200 : 409);
    }

    /**
     * Verify an invitation pass and say what the door should do.
     *
     * Returns the same shape as {@see EventTicketService::checkIn()} so the page has one
     * verdict renderer, plus `honour` — which is what makes the screen celebrate. This is
     * somebody being met at the door of an evening held for them; a green tick is the
     * right answer for a ticket and the wrong one for this.
     *
     * @return array<string,mixed>
     */
    private function honour(string $code, int $eventId, string $via = ''): array
    {
        $r = InvitePass::verify($code);

        if (!$r['ok']) {
            return [
                'ok' => false, 'verdict' => 'refuse', 'honour' => false,
                'title' => 'Invitation not valid', 'detail' => $r['reason'],
                'name' => '', 'tier' => '', 'seats' => 0, 'code' => $code,
            ];
        }

        $invite = $r['invite'];

        // An invitation to another evening is refused with the same words as one that does
        // not exist, for the reason the ticket path gives: splitting them turns a door into
        // an oracle for which references are real.
        if ($eventId > 0 && (int) $invite->event_id !== $eventId) {
            return [
                'ok' => false, 'verdict' => 'refuse', 'honour' => false,
                'title' => 'Invitation not valid',
                'detail' => 'No invitation here has that reference.',
                'name' => '', 'tier' => '', 'seats' => 0, 'code' => $code,
            ];
        }

        $spec = InviteAudience::spec((string) $invite->audience);
        $seen = (int) $invite->scans;

        InvitePass::touch((int) $invite->id);
        // Into the arrivals log as well as the invite's own counter, so the record of who was
        // in the room is one list rather than two tables an organiser has to join by hand.
        EventArrivals::honoured($invite, $via);

        // Already through is NOT a refusal. A nominee who steps out to take a call and
        // comes back is the ordinary case, and turning them away from their own ceremony
        // over a re-scan is a worse failure than a second admission. It is reported so a
        // steward can see it and use their judgement.
        return [
            'ok'      => true,
            'verdict' => 'admit',
            'honour'  => true,
            'title'   => $seen > 0 ? 'Welcome back' : 'Guest of honour',
            'detail'  => $seen > 0
                ? $spec['label'] . ' — already admitted ' . $seen . ' time' . ($seen === 1 ? '' : 's') . ' this evening.'
                : 'Admit and show them to their seat. ' . $spec['label'] . '.',
            'name'    => (string) $invite->name,
            'tier'    => (string) $spec['label'],
            'seats'   => 1,
            'code'    => (string) $invite->reference,
        ];
    }

    /**
     * Seats admitted so far — people through the door, not bookings scanned.
     *
     * Delegated rather than queried here, and that is the fix rather than a tidy-up. This
     * summed `gates_event_registrations` alone, and a guest of honour is admitted on an
     * INVITATION and has no registration row by design — minting them a complimentary ticket
     * would have counted as a sale and stopped the hall selling. So the number a steward
     * reads to judge the room, and the closest thing here to a fire-safety figure, silently
     * excluded every nominee and judge in the building.
     */
    private function admitted(int $eventId): int
    {
        return EventArrivals::inTheRoom($eventId);
    }
}
