<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InvitePass;
use AfricaGates\Support\Qr;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/honour/{reference}` — a guest of honour's mobile ID.
 *
 * ── WHY THIS IS A PAGE AND NOT A PDF ─────────────────────────────────────────
 *
 * The pass has to rotate, and a PDF cannot. A printed QR is a photograph the moment it
 * exists: one nominee's ID, snapped once and passed round a car park, admits everybody
 * holding the picture, and every scan of it is genuine as far as the door can tell.
 *
 * So the ID is a live page. The formal letter is still attached to the invitation as a
 * PDF, because that is a document somebody keeps; the thing that opens a door is not.
 *
 * ── WHAT THE REFERENCE ALONE GETS YOU ────────────────────────────────────────
 *
 * This page is reachable by anybody holding the reference, and the reference is also the
 * discount code twenty-five guests are given. That is deliberate and safe: the page
 * shows a name, a category and a rotating code, and the rotating code is an HMAC under a
 * secret that never leaves the server. Somebody else's ID page is a picture of a pass
 * that stops working in under a minute. See {@see InvitePass}.
 *
 * It is `noindex` all the same — a search engine holding a directory of who was
 * shortlisted, by name and category, before the ceremony announces it, is a different
 * kind of leak from the one the rotation protects against.
 */
final class HonourController
{
    public function __construct(private readonly Twig $view) {}

    /** The ID itself. */
    public function page(Request $req, Response $res, array $args): Response
    {
        $invite = EventInvites::byReference((string) ($args['reference'] ?? ''));
        if (!$invite) throw new \Slim\Exception\HttpNotFoundException($req);

        $event = DB::table('gates_site_events')->where('id', $invite->event_id)->first();
        if (!$event) throw new \Slim\Exception\HttpNotFoundException($req);

        $spec = InviteAudience::spec((string) $invite->audience);
        $tier = EventInvites::lowestTier((int) $invite->event_id);

        // First open is worth recording: it is the only signal an operator has that an
        // invitation actually landed, short of the person replying.
        if ($invite->opened_at === null) {
            try {
                DB::table('gates_event_invites')->where('id', $invite->id)
                    ->update(['opened_at' => Carbon::now()->toDateTimeString()]);
            } catch (\Throwable) {}
        }

        return $this->view->render($res, 'pages/honour.twig', [
            'page_title'  => 'Your invitation — ' . $event->title,
            'invite'      => (array) $invite,
            'event'       => (array) $event,
            'audience'    => $spec,
            'lowest_tier' => $tier ? (array) $tier : null,
            'discount'    => InviteAudience::discountPercent(),
            'step'        => InvitePass::STEP_SECONDS,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow')
          // The page embeds a code that is only valid for one window. A cached copy is a
          // pass that has already expired, rendered as though it had not.
          ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * `/honour/{reference}/qr.svg` — the current code, as a symbol.
     *
     * Polled by the page rather than redrawn in JavaScript: the encoder is
     * {@see Qr::encodeBytes()}, it is verified by decoding, and reimplementing it in the
     * browser to save a request would be a second encoder to keep correct.
     */
    public function qr(Request $req, Response $res, array $args): Response
    {
        $invite = EventInvites::byReference((string) ($args['reference'] ?? ''));
        if (!$invite) throw new \Slim\Exception\HttpNotFoundException($req);

        $code = InvitePass::code((string) $invite->reference, (string) $invite->id_secret);

        // encodeBytes, never encode(): encode() is version 1, alphanumeric, and folds
        // case for a 16-character ticket code. This is thirty-odd characters and the
        // signature's case is part of it.
        $svg = Qr::svgBytes($code, 8);
        if ($svg === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $res->getBody()->write($svg);

        return $res
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * `/honour/{reference}/tick` — how long the code on screen has left.
     *
     * The page asks rather than counting down from its own clock: a phone that slept in
     * a pocket wakes with a countdown that says nine seconds and a code that expired four
     * minutes ago, which is the one moment this has to be right.
     */
    public function tick(Request $req, Response $res, array $args): Response
    {
        $invite = EventInvites::byReference((string) ($args['reference'] ?? ''));
        if (!$invite) throw new \Slim\Exception\HttpNotFoundException($req);

        $res->getBody()->write((string) json_encode([
            'seconds_left' => InvitePass::secondsLeft(),
            'step'         => InvitePass::STEP_SECONDS,
        ]));

        return $res
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
