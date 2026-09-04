<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{DoorVoice, DoorWelcome, EventArrivals, EventScanPass, EventTicketService,
                          InviteAudience, InvitePass, RateLimitService};
use AfricaGates\Support\EventTime;
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
    /**
     * Scans allowed per pass per minute.
     *
     * ── WHY A DOOR NEEDS A CEILING AT ALL ────────────────────────────────────
     *
     * A pass is a bearer token that is MEANT to be posted into a volunteers' group chat, so
     * it leaks by design and the time window is the accepted control. But this endpoint
     * returns an attendee's NAME for any valid code, and nothing bounded attempts — so a
     * leaked link was an unmetered name-lookup oracle for anybody holding a list of codes,
     * and an unmetered database load on a live door.
     *
     * 120 a minute is two scans a second sustained, which is faster than a human queue moves
     * and far below what a script wants. Chosen so it cannot bite a real door: the cost of
     * this number being wrong is a steward being refused mid-queue, which is worse than the
     * thing it defends against.
     */
    private const SCANS_PER_MIN = 120;

    /**
     * Scans accepted in one flush.
     *
     * An outage at a door is minutes, not hours — the gate is capped at {@see SCANS_PER_MIN}
     * while it is working, so a queue this long means something other than a busy evening.
     */
    private const SYNC_MAX = 200;

    public function __construct(
        private readonly Twig $view,
        private readonly ?RateLimitService $limits = null,
    ) {}

    /**
     * True when this pass has scanned too fast to be a person.
     *
     * Keyed on the PASS, not the IP: a hall with four gates behind one venue router is four
     * stewards sharing an address, and rate-limiting them together would throttle the
     * busiest door on the strength of the other three.
     */
    private function tooFast(object $pass, string $action): bool
    {
        if ($this->limits === null) return false;

        try {
            return !$this->limits->check('pass:' . (int) $pass->id, $action, self::SCANS_PER_MIN, 60);
        } catch (\Throwable) {
            // A limiter that cannot read its own table must never close a door.
            return false;
        }
    }

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
            // A refused pass still knows which event it belongs to, so its times can be
            // shown in that event's zone rather than in the platform's.
            $ev = $pass !== null ? self::eventOfPass($pass) : null;
            return $this->view->render($res->withStatus(403), 'pages/events/door.twig', $common + [
                'ok'      => false,
                'reason'  => $r['reason'],
                'message' => $r['message'],
                // The times, when we know them — that is what makes the refusal actionable.
                //
                // Formatted HERE, in the event's own zone. They were sliced out of the
                // stored string in the template, and storage is UTC by this application's
                // convention — so a gate that closed at 23:00 in Lagos told the person
                // holding the phone it had closed at 22:00, an hour before it did.
                'opens_at'  => $pass !== null
                    ? EventTime::zoned($ev, (string) ($pass->opens_at ?? ''), 'j M, H:i') : '',
                'closes_at' => $pass !== null
                    ? EventTime::zoned($ev, (string) ($pass->closes_at ?? ''), 'j M, H:i') : '',
                'event'   => null, 'token' => '', 'label' => '',
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        EventScanPass::touch((int) $r['pass']->id);

        $accent = \AfricaGates\Services\EventTicketDesign::colour(
            (string) ($r['event']->ticket_accent ?? ''));

        return $this->view->render($res, 'pages/events/door.twig', $common + [
            'ok'        => true,
            'reason'    => '', 'message' => '',
            'event'     => (array) $r['event'],
            'label'     => (string) ($r['pass']->label ?? ''),
            'closes_at'    => EventTime::zoned($r['event'], (string) $r['pass']->closes_at, 'j M, H:i'),
            'opens_at'     => EventTime::zoned($r['event'], (string) ($r['pass']->opens_at ?? ''), 'j M, H:i'),
            // The header wants the clock alone: the zone is already stated in the note
            // further down, and repeating it in a line that has to fit on a phone costs
            // the gate label its room.
            'closes_short' => EventTime::at($r['event'], (string) $r['pass']->closes_at, 'H:i'),
            'token'     => $token,
            // The room, so somebody on the door knows whether they are near the end.
            'admitted'  => $this->admitted((int) $r['event']->id),
            // Same resolver, same reason: tickets sold PLUS invitations actually sent.
            'expected'  => EventArrivals::expected((int) $r['event']->id),
            // Whether to wire the player up at all. Off is a valid answer and a silent
            // door is a working door.
            'welcome_on' => DoorWelcome::enabled(),
            // ── THE CLIP THE PAGE UNLOCKS ITS PLAYER WITH ─────────────────
            //
            // A greeting is played from the scanner's decode loop, which is not a user
            // gesture, and both mobile browsers refuse audible playback on an element that
            // has never been played inside one. The page therefore plays THIS clip, muted,
            // on the steward's first touch of anything — after which the element is
            // unlocked and every guest is greeted.
            //
            // It has to be a real file on our own origin: `media-src` is `'self'` plus two
            // video hosts, with no `data:` and no `blob:`, so the usual silent data-URI
            // primer is blocked by the CSP with nothing on the page to say so. The generic
            // clip is the only greeting guaranteed to exist whenever the voice is on at
            // all, and '' when it does not — in which case there is nothing to unlock for.
            'welcome_prime' => DoorWelcome::keyToPlay(''),
            // ── WHY THE DOOR IS SILENT, ON THE DOOR ──────────────────────────
            //
            // `readiness()` has answered this since it was written and only the admin
            // screen ever asked. So a steward at a gate where nobody is being greeted had
            // no way to tell "switched off" from "no clips made yet" from "this browser
            // refused", and neither did anybody they reported it to — every one of those
            // reaches a person as the same sentence, which is no sentence at all.
            //
            // The BLOCKER only. `readiness()['fix']` names settings screens a steward
            // cannot open, and an instruction somebody cannot follow is worse than none.
            'welcome_why' => DoorWelcome::enabled()
                ? (string) (DoorWelcome::readiness((int) $r['event']->id)['blocker'] ?? '')
                : '',
            // ── THE COLOUR THE EFFECTS ARE PAINTED IN ─────────────────────
            //
            // The organiser's own accent, through the same resolver the ticket and the
            // flier take it through. A hardcoded palette in the door's stylesheet would be
            // a fourth opinion about an event's colour and the only one the organiser
            // could not change — and a gold gala bursting emerald at its own door is the
            // failure that rule exists to prevent.
            'accent'      => $accent,
            // The same accent at 55%, resolved HERE rather than with color-mix() in the
            // stylesheet. A browser that does not know color-mix() treats the whole
            // gradient as invalid and drops the background declaration with it — so the
            // sweep would vanish silently on exactly the older venue phones this page is
            // written for.
            'accent_soft' => self::soften($accent, 0.55),
            // ── THE ACCENT, LIFTED FOR A DARK FRAME ───────────────────────
            //
            // Every other surface resolves this event's colour for PAPER, and the door's
            // frame runs #2C3838 to #080D0E. `EventTicketDesign::DEFAULT_ACCENT` is
            // #10292C — a near-black teal that is exactly right on a printed ticket and
            // invisible here. `DoorTone` walks it lighter along its own hue until it
            // clears the frame, so the organiser's colour stays theirs.
            'tone'        => \AfricaGates\Services\DoorTone::forEvent($r['event']),
            // ── THE ARRIVALS LIST, SEEDED ─────────────────────────────────
            //
            // A steward taking a gate over at nine o'clock used to open Arrivals and find
            // it empty, because the list was only ever built from scans this browser had
            // made. An empty list on a half-full room does not read as "this phone has
            // seen nothing" — it reads as "nobody has come in".
            'arrivals'    => $this->arrivalsSeed((int) $r['event']->id, $r['event']),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The arrivals log as the door draws it: newest first, times already in the event's
     * zone, and nothing in it that the door's own promise forbids.
     *
     * THE DOOR CANNOT SHOW THE GUEST LIST. That is the constraint this method is written
     * around, and it is why the seed is capped and why it carries no codes: what is
     * published here is the history of scans AT THIS GATE, which the steward performed and
     * already saw. A longer window, or the ticket codes, would turn a mislaid phone into
     * the attendee list.
     *
     * @return list<array{t:string, n:string, v:string}>
     */
    private function arrivalsSeed(int $eventId, ?object $event): array
    {
        $out = [];
        foreach (EventArrivals::recent($eventId, 60) as $row) {
            $who = trim((string) ($row['who'] ?? ''));
            if ($who === '') continue;

            $seats = (int) ($row['seats'] ?? 0);
            $out[] = [
                't' => EventTime::at($event, (string) ($row['created_at'] ?? ''), 'H:i'),
                'n' => $who . ($seats > 1 ? ' · ' . $seats : ''),
                // The log's own vocabulary, which is narrower than the screen's: a refusal
                // is never written to it, so only these two can appear.
                'v' => ((string) ($row['action'] ?? '')) === 'undo' ? 'back' : 'in',
            ];
        }

        return $out;
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

        // A distinct verdict, never a refusal. "Not a ticket for this event" on a code that
        // is perfectly good would send a steward to argue with somebody who has paid, and
        // neither of them would have any way to know the screen was lying.
        if ($this->tooFast($pass, 'door_scan')) {
            return $this->json($res, [
                'ok' => false, 'verdict' => 'slow',
                'title' => 'Going too fast',
                'detail' => 'This gate has scanned a great many codes in the last minute. '
                          . 'Wait a moment and scan again — nothing was recorded.',
                'name' => '', 'tier' => '', 'seats' => 0, 'code' => '', 'welcome' => '',
            ], 429);
        }

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
            $verdict = $this->honour($code, (int) $pass->event_id, $this->via($pass),
                                     self::eventOfPass($pass));
            EventScanPass::touch((int) $pass->id);

            return $this->json($res, $verdict + [
                'admitted' => $this->admitted((int) $pass->event_id),
            ]);
        }

        // How many of the party are standing there. 0 — the default and what a single-seat
        // ticket always sends — means "everyone still to come on this ticket".
        $want = max(0, (int) ($body['seats'] ?? 0));

        // ── A PARTY IS ASKED ABOUT BEFORE ANYBODY IS ADMITTED ────────────────
        //
        // The door used to admit ONE seat on the first scan and then offer to add more.
        // So a steward who scanned a table of four and turned away to talk had admitted
        // one person and recorded three as still to come — and the only way back was the
        // undo, which they had no reason to think they needed.
        //
        // Now a multi-seat ticket with more than one seat free stops and asks, and writes
        // nothing until it is answered. The verdict is still the SERVER's: this is a
        // decision the door makes, not the browser, so an offline queue or a second gate
        // cannot skip it by leaving the field out.
        //
        // It refuses to become an oracle. Anything that does not resolve to a confirmed
        // multi-seat ticket ON THIS EVENT falls through to the ordinary path and gets the
        // ordinary answer — a code that is unknown here and a code that is unknown
        // everywhere must remain indistinguishable.
        if ($want === 0) {
            $peek  = EventTicketService::byTicketCode($code);
            $seats = $peek !== null ? max(0, (int) ($peek->quantity ?? 1)) : 0;
            $in    = $peek !== null ? max(0, (int) ($peek->checked_in_seats ?? 0)) : 0;

            if ($peek !== null
                && (int) ($peek->event_id ?? 0) === (int) $pass->event_id
                && (string) ($peek->status ?? '') === 'confirmed'
                && $seats > 1 && ($seats - $in) > 1) {

                EventScanPass::touch((int) $pass->id);

                return $this->json($res, [
                    'ok' => false, 'verdict' => 'ask', 'honour' => false,
                    'title'  => 'How many are here?',
                    'detail' => ($seats - $in) . ' of ' . $seats . ' still to come.',
                    'name'   => (string) ($peek->name ?? ''),
                    'tier'   => (string) ($peek->tier ?? ''),
                    'seats'  => $seats, 'code' => $code, 'welcome' => '',
                    'seats_in' => $in, 'seats_left' => $seats - $in, 'admitted_now' => 0,
                    'at_short' => '', 'via_label' => trim((string) ($pass->label ?? '')),
                    'admitted' => $this->admitted((int) $pass->event_id),
                ]);
            }
        }

        $v = EventTicketService::checkIn($code, (int) $pass->event_id, $this->via($pass),
                                         null, '', $want);

        EventScanPass::touch((int) $pass->id);

        // ── THE GREETING IS KEYED ON THE EVENT, SO THE LOOKUP MUST BE TOO ────
        //
        // The clip was rendered hours ago from the event's own start time — "Good evening.
        // Ada, you are welcome." Ask here for a line built WITHOUT the event and the text
        // is "Ada, you are welcome.", which hashes to a different key, which is not on
        // disk. Every guest would then get the generic clip: no error, no log line, just a
        // room full of people not hearing their names on the one night it matters.
        //
        // Read only on an admit, so a refusal and a duplicate cost the queue no query.
        // ── THE LINE TRAVELS WITH THE KEY ───────────────────────────────────
        //
        // A key alone means the door can only speak when a clip was rendered ahead of it.
        // A walk-up, a late booking, a sweep that never ran because the cron was not set
        // up — all of those arrive with no clip and the door said nothing at all. The
        // browser has a voice of its own that needs no key and no network, and it cannot
        // use it without the words.
        //
        // Same door token, same page, and the clip says this name aloud anyway, so the
        // text is no wider an exposure than the audio it replaces.
        $welcomeLine = $v['verdict'] === 'admit'
            ? DoorWelcome::line((string) $v['name'], self::eventOfPass($pass))
            : '';
        $welcome = $welcomeLine !== '' ? DoorWelcome::keyToPlay($welcomeLine) : '';

        return $this->json($res, [
            'ok'       => $v['verdict'] === 'admit',
            'verdict'  => $v['verdict'],
            // What to say if there is no clip. Plain text: the browser's synthesiser
            // reads words, not SSML, and the pause marker would be read out loud.
            'say'      => DoorVoice::plain($welcomeLine),
            'title'    => $v['title'],
            'detail'   => $v['detail'],
            'name'     => $v['name'],
            'tier'     => $v['tier'],
            'seats'    => $v['seats'],
            // The three the panel needs to offer a split, and to show one afterwards.
            'seats_in'     => $v['seats_in'] ?? 0,
            'seats_left'   => $v['seats_left'] ?? 0,
            'admitted_now' => $v['admitted_now'] ?? 0,
            'code'     => $v['code'],
            // A key, never audio, and never a synthesis call. The clip was rendered hours
            // ago by the sweep; this is a filename lookup, so the greeting costs the queue
            // nothing. Only on an admit — a refusal is not a welcome, and greeting somebody
            // by name while turning them away would be worse than silence.
            'welcome'  => $welcome,
            // ── THREE THE SCREEN NEEDS AND COULD NOT INFER ────────────────────
            //
            // `honour` is stated rather than left absent. The invitation path has always
            // returned it and the ticket path never did, so the door read `undefined` and
            // got the right answer by luck — a ticket is never a guest of honour. A
            // contract that happens to work is not one, and the screen branches on this to
            // decide between a green frame and a gold one.
            'honour'   => (bool) ($v['honour'] ?? false),
            // The clock a duplicate was first admitted at, FORMATTED HERE in the event's
            // own zone. The raw column is UTC by this application's convention, so a
            // browser formatting it would tell a steward in Lagos an hour that never
            // happened — the same fault the closing time had before it was moved here.
            'at_short' => ($v['verdict'] ?? '') === 'duplicate' && ($v['at'] ?? '') !== ''
                ? EventTime::at(self::eventOfPass($pass), (string) $v['at'], 'H:i') : '',
            // Which gate, so "Came in 21:11 · Main gate" can name the door rather than
            // implying there was only ever one.
            'via_label' => trim((string) ($pass->label ?? '')),
            // Recomputed after the write, so the running count on the page is the real one
            // rather than a number the browser has been incrementing since it was opened.
            'admitted' => $this->admitted((int) $pass->event_id),
        ]);
    }

    /**
     * POST /door/{token}/sync — the scans a gate took while the line was down.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY AN OFFLINE DOOR RECORDS AND DOES NOT DECIDE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The obvious offline design is to ship the event's ticket codes to the phone so it can
     * answer for itself. It was considered and rejected on the numbers: a code is eight
     * characters from a 29-letter alphabet, about 2^39, which is brute-forceable against a
     * hash list in minutes on ordinary hardware. So a manifest on a volunteer's phone — a
     * phone that gets lost at galas — is the event's whole valid-code set, recoverable, and
     * this door's standing promise to whoever holds the link is that it "cannot show you the
     * guest list".
     *
     * A stale manifest is the second problem and the worse one in practice. A list fetched
     * at 18:00 does not know about a ticket sold at 19:30, so it would refuse a paying
     * attendee CONFIDENTLY, at the door, on data it had no way to know was old.
     *
     * So an offline gate records what it scanned and says so — plainly, on screen, in its
     * own words that are not "admit". The steward has the physical ticket in front of them
     * and uses their eyes, which is what they were doing before this platform existed. The
     * SERVER stays the only thing that ever decides, and it decides here, late, with every
     * check it always had.
     *
     * ── AND THE TIME TRAVELS WITH THE SCAN ───────────────────────────────────
     *
     * Each item carries the moment it was taken. Without it, forty people go through the
     * arrivals log in the same second half an hour after they walked in, and that log is
     * what an organiser stands behind when an entry is disputed.
     */
    public function sync(Request $req, Response $res, array $args): Response
    {
        $r = EventScanPass::resolve((string) ($args['token'] ?? ''));
        if (!$r['ok']) {
            return $this->json($res, ['ok' => false, 'reason' => $r['reason'],
                                      'detail' => $r['message']], 403);
        }

        $pass = $r['pass'];
        if ($this->tooFast($pass, 'door_sync')) {
            // Its own bucket again. A flush of forty is one request, so a gate that hits
            // this ceiling is retrying in a loop rather than working a queue.
            return $this->json($res, ['ok' => false, 'reason' => 'slow',
                                      'detail' => 'Too many flushes. Wait a moment.'], 429);
        }

        $body  = (array) $req->getParsedBody();
        $items = $body['scans'] ?? [];
        if (!is_array($items)) $items = [];

        // Bounded. A flush is one gate's outage, not a database import, and an unbounded
        // loop of writes behind a bearer token is a denial of service with a queue behind it.
        $items = array_slice($items, 0, self::SYNC_MAX);

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $code = (string) ($item['code'] ?? '');
            if (str_contains($code, '/')) {
                $code = (string) preg_replace('~^.*/~', '', rtrim(trim($code), '/'));
            }
            $id = (string) ($item['id'] ?? '');
            if ($code === '') continue;

            $v = EventTicketService::checkIn(
                $code, (int) $pass->event_id, $this->via($pass) . ' (offline)',
                null, (string) ($item['at'] ?? ''), max(0, (int) ($item['seats'] ?? 0)));

            // Every item answered, in the order it was sent, so the page can retire exactly
            // the ones that landed. A flush that reported only a total would leave a gate
            // guessing which forty of its forty-one it may now forget.
            $out[] = [
                'id'      => $id,
                'code'    => $code,
                'verdict' => $v['verdict'],
                'title'   => $v['title'],
                'name'    => $v['name'],
            ];
        }

        EventScanPass::touch((int) $pass->id);

        return $this->json($res, [
            'ok'       => true,
            'results'  => $out,
            'admitted' => $this->admitted((int) $pass->event_id),
        ]);
    }

    /**
     * GET /door/{token}/welcome/{key} — one greeting, as audio.
     *
     * Behind the pass, and that is not belt-and-braces: the file SAYS A GUEST'S NAME ALOUD.
     * On a public path it would be the attendee list in audio for anybody who could guess a
     * filename, and this door's own promise to the person holding it is that it "cannot show
     * you the guest list".
     *
     * It never renders. A key with no file is a 404, the page stays silent, and the queue
     * moves — which is the correct outcome of a missing clip and not an error.
     */
    public function welcome(Request $req, Response $res, array $args): Response
    {
        $r = EventScanPass::resolve((string) ($args['token'] ?? ''));
        if (!$r['ok']) return $res->withStatus(403);

        $path = DoorWelcome::pathFor((string) ($args['key'] ?? ''));
        if ($path === null || !is_file($path)) return $res->withStatus(404);

        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') return $res->withStatus(404);

        $res->getBody()->write($bytes);

        return $res
            ->withHeader('Content-Type', 'audio/mpeg')
            ->withHeader('Content-Length', (string) strlen($bytes))
            // Cached hard and privately. The same steward re-scans the same guest, and a
            // clip re-fetched over venue wifi is the latency this whole design removes.
            // `private` because the URL is behind a bearer token and a shared proxy must not
            // hold a guest's name.
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
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

        // Its own bucket. Sharing the scan counter would let a busy gate spend the budget it
        // needs for taking a mistake back — and a reversal refused because the queue was
        // moving is the exact failure this feature exists to prevent.
        if ($this->tooFast($pass, 'door_undo')) {
            return $this->json($res, [
                'ok' => false, 'verdict' => 'slow', 'title' => 'Going too fast',
                'detail' => 'Wait a moment and try again — nothing was changed.',
                'name' => '', 'tier' => '', 'seats' => 0, 'code' => '',
            ], 429);
        }

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
    private function honour(string $code, int $eventId, string $via = '', ?object $event = null): array
    {
        $r = InvitePass::verify($code);

        if (!$r['ok']) {
            return [
                'ok' => false, 'verdict' => 'refuse', 'honour' => false,
                'title' => 'Invitation not valid', 'detail' => $r['reason'],
                'name' => '', 'tier' => '', 'seats' => 0, 'code' => $code,
                'seats_in' => 0, 'seats_left' => 0, 'admitted_now' => 0,
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
                'seats_in' => 0, 'seats_left' => 0, 'admitted_now' => 0,
            ];
        }

        $spec = InviteAudience::spec((string) $invite->audience);
        $seen = (int) $invite->scans;

        InvitePass::touch((int) $invite->id, $via);
        // Into the arrivals log as well as the invite's own counter, so the record of who was
        // in the room is one list rather than two tables an organiser has to join by hand.
        EventArrivals::honoured($invite, $via);

        // Built once: the key is looked up from it AND the words travel beside the key,
        // so a guest of honour with no clip rendered is still greeted — by the browser.
        $honourLine = DoorWelcome::honourLine((string) $invite->name,
                                              strtolower((string) ($spec['one'] ?? '')), $event);

        // Already through is NOT a refusal. A nominee who steps out to take a call and
        // comes back is the ordinary case, and turning them away from their own ceremony
        // over a re-scan is a worse failure than a second admission. It is reported so a
        // steward can see it and use their judgement.
        return [
            'ok'      => true,
            'verdict' => 'admit',
            'honour'  => true,
            'title'   => $seen > 0 ? 'Welcome back' : 'Guest of honour',
            // THE SINGULAR, because this is one person standing there. `label` is the
            // plural for a group — "Nominees", "Judges" — and it is the right word on the
            // invitations screen and the wrong one on a card showing somebody's face:
            // "Ngozi Adaeze / Judges" reads as a category rather than as a guest, on the
            // one screen a steward glances at with a queue behind them.
            'detail'  => $seen > 0
                ? $spec['one'] . ' — already admitted ' . $seen . ' time' . ($seen === 1 ? '' : 's') . ' this evening.'
                : 'Admit and show them to their seat. ' . $spec['one'] . '.',
            'name'    => (string) $invite->name,
            'tier'    => (string) $spec['one'],
            'seats'   => 1,
            'seats_in' => 1, 'seats_left' => 0, 'admitted_now' => 1,
            'code'    => (string) $invite->reference,
            // Greeted as what they are. "Our nominee this evening" is a different sentence
            // from a ticket holder's, because arriving at an evening held for you is a
            // different arrival from arriving at one you bought a seat at.
            'welcome' => DoorWelcome::keyToPlay($honourLine),
            'say'     => DoorVoice::plain($honourLine),
        ];
    }

    /** The event a pass belongs to, for its zone. Null is a working answer — see EventTime. */
    private static function eventOfPass(object $pass): ?object
    {
        try {
            return DB::table('gates_site_events')->where('id', (int) $pass->event_id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `#RRGGBB` at $alpha, as an rgba() string.
     *
     * Server-side so the stylesheet needs no colour function at all. A CSS feature an old
     * browser does not know does not degrade gracefully here — it invalidates the whole
     * declaration and takes the effect with it, silently, on the oldest device in the
     * building, which at a venue is a safe bet about somebody's phone.
     */
    private static function soften(string $hex, float $alpha): string
    {
        $h = ltrim($hex, '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $h) !== 1) {
            return 'rgba(35,123,34,' . $alpha . ')';
        }

        return sprintf('rgba(%d,%d,%d,%s)',
            hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), $alpha);
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
