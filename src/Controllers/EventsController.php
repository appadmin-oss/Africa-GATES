<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\{CacheService, OtpService, Notifier, WebhookService,
                         EventTicketService, GatewayHandoff, PaymentService};

/**
 * Events, and — new — tickets somebody can actually buy.
 *
 * ── WHAT CHANGED, AND WHY IT NEEDED A SERVICE ────────────────────────────────
 *
 * Registration was a free RSVP against one capacity number for the whole event, and
 * "ticket tiers" were a JSON blob this page printed as prose. There was no price, no
 * per-tier limit and no payment: `gates_event_registrations` had carried `amount_naira`
 * and `reference` since it was created with nothing ever writing them.
 *
 * The arithmetic — which tier, how many seats are left in it, does the room still have
 * room, is this hold still holding — lives in {@see EventTicketService}, because it is
 * read by this controller, the admin screens, the reconciler and the door-check, and
 * four implementations of "is it sold out" is exactly the drift this codebase keeps
 * finding. This controller does HTTP: read the form, hand off to the gateway, come back.
 */
class EventsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ?OtpService $mailer = null,
        private readonly ?PaymentService $payments = null,
    ) {}

    private function payments(): PaymentService
    {
        return $this->payments ?? new PaymentService();
    }

    private function base(Request $req): string
    {
        return rtrim(\AfricaGates\Support\SiteUrl::base($req), '/');
    }

    public function index(Request $req, Response $res): Response
    {
        $now = Carbon::now()->toDateTimeString();
        $upcoming = $this->cache->remember('events:upcoming', 900, fn() =>
            DB::table('gates_site_events')->where('status', 'published')
                ->where('event_date', '>=', $now)
                ->orderBy('event_date')->get()->map(fn($r) => (array)$r)->all()
        );
        $past = $this->cache->remember('events:past', 1800, fn() =>
            DB::table('gates_site_events')->where('status', 'published')
                ->where('event_date', '<', $now)
                ->orderByDesc('event_date')->limit(12)->get()->map(fn($r) => (array)$r)->all()
        );
        return $this->view->render($res, 'pages/events.twig', [
            'page_title'       => 'Events — Africa GATES',
            'meta_description' => 'Ceremonies, webinars and community sessions across the Africa GATES cycle.',
            'gates_page'       => 'events',
            'has_hero'         => true,
            'upcoming'         => $upcoming,
            'past'             => $past,
        ]);
    }

    /** Public event detail page (with on-platform RSVP). */
    public function show(Request $req, Response $res, array $args): Response
    {
        $slug  = (string)($args['slug'] ?? '');
        $event = DB::table('gates_site_events')
            ->where('slug', $slug)->where('status', 'published')->first();

        if (!$event) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }
        $event   = (array)$event;
        $now      = Carbon::now()->toDateTimeString();
        $regCount = (int) DB::table('gates_event_registrations')->where('event_id', $event['id'])->count();
        $isPast   = $event['event_date'] < $now;
        $capacity  = ($event['capacity'] ?? null) !== null ? (int) $event['capacity'] : null;
        $spotsLeft = $capacity !== null ? max(0, $capacity - $regCount) : null;
        $isFull    = $capacity !== null && $regCount >= $capacity;
        $pctSold   = ($capacity !== null && $capacity > 0) ? min(100, (int) round($regCount * 100 / $capacity)) : null;

        // Admin-driven rich sections (rendered only when present → no empty blocks).
        $schedule = json_decode((string)($event['schedule'] ?? '[]'), true) ?: [];

        // ── TIERS ARE ROWS NOW, EACH WITH ITS OWN LIMIT ──────────────────────
        //
        // The `ticket_tiers` JSON blob is still read as a FALLBACK, for the minutes
        // between an operator uploading this code and running /__setup/migrate: on this
        // deployment those are two separate acts and an event page that went blank in
        // between would be the upgrade breaking the site.
        $code  = trim((string) ($req->getQueryParams()['code'] ?? ''));
        $tiers = EventTicketService::tiers((int) $event['id'], $code);
        if ($tiers === []) {
            $legacy = json_decode((string) ($event['ticket_tiers'] ?? '[]'), true);
            $tiers  = is_array($legacy) ? $legacy : [];
        }
        // The event's own ceiling still applies on top of every tier's, so a tier with
        // seats left in a room that is full cannot be bought.
        $eventSold = EventTicketService::soldForEvent((int) $event['id']);

        // Early-bird banner: active when text is set and (no deadline OR deadline still ahead).
        $ebText  = trim((string)($event['early_bird_text'] ?? ''));
        $ebUntil = trim((string)($event['early_bird_deadline'] ?? ''));
        $earlyBird = ($ebText !== '' && !$isPast && ($ebUntil === '' || $ebUntil >= $now))
            ? ['text' => $ebText, 'deadline' => $ebUntil, 'url' => trim((string)($event['early_bird_url'] ?? ''))]
            : null;

        return $this->view->render($res, 'pages/events/detail.twig', [
            'page_title'       => $event['title'] . ' — Africa GATES',
            'meta_description' => ($event['tagline'] ?? null)
                ?: mb_substr(strip_tags((string)($event['description'] ?? '')), 0, 150),
            'gates_page'       => 'events',
            'has_hero'         => false,
            'event'            => $event,
            'member'           => \AfricaGates\Services\UserAccountService::memberForForms(),
            'reg_count'        => $regCount,
            'is_past'          => $isPast,
            'capacity'         => $capacity,
            'spots_left'       => $spotsLeft,
            'is_full'          => $isFull,
            'pct_sold'         => $pctSold,
            'schedule'         => $schedule,
            'tiers'            => $tiers,
            'event_sold'       => $eventSold,
            // Whether anything on this page costs money, which decides whether the form
            // says "Register" or "Buy tickets" — and it is per-event rather than a site
            // setting, because a free community session and a paid gala are both events.
            'paid_tiers'       => (bool) array_filter($tiers,
                                    static fn (array $t): bool => (int) ($t['price_naira'] ?? 0) > 0),
            'access_code'      => $code,
            'gateway_ready'    => $this->payments()->enabledProviderIds() !== [],
            'early_bird'       => $earlyBird,
        ] + array_filter([
            'og_image'     => \AfricaGates\Support\Assets::absoluteOg($event['cover_image'] ?? null),
            'og_image_alt' => (string) $event['title'],
        ], fn($v) => $v !== null));
    }

    /**
     * Take a registration. Free tiers are done on the spot; paid ones go to the gateway.
     *
     * ── WHY ONE ENDPOINT AND NOT TWO ─────────────────────────────────────────
     *
     * From the visitor's side there is one act — "I want to come" — and whether it costs
     * money is a property of the ticket they chose, not of a different feature. Splitting
     * it would mean the page deciding which endpoint to post to, which is a decision it
     * would have to make from a price it read out of a template, and that is exactly the
     * kind of client-side branch that goes wrong when a tier is edited.
     *
     * So: JSON in, JSON out. A free tier answers `{success, ticket_code}`. A paid one
     * answers `{success, pay: <url>}` and the page sends the browser there. Nothing on
     * this side trusts the amount — {@see EventTicketService::reserve()} prices from the
     * tier row, and {@see EventTicketService::confirm()} re-checks it against the gateway
     * before a ticket exists.
     */
    public function register(Request $req, Response $res, array $args): Response
    {
        $json = function (array $payload, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };

        $slug  = (string) ($args['slug'] ?? '');
        $event = DB::table('gates_site_events')
            ->where('slug', $slug)->where('status', 'published')->first();
        if (!$event) return $json(['success' => false, 'message' => 'That event no longer exists.'], 404);

        $data = (array) $req->getParsedBody();
        $who  = [
            'name'  => trim((string) ($data['name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ];
        $qty  = max(1, (int) ($data['quantity'] ?? 1));
        $code = trim((string) ($data['code'] ?? ''));

        // ── which tier ───────────────────────────────────────────────────────
        //
        // A tier id is required as soon as there is more than one, because guessing on the
        // visitor's behalf would sell somebody a seat at a price they did not choose. With
        // exactly one tier — every event that has just been migrated from the old JSON
        // blob, and every free community session — the choice is not a choice, and making
        // them make it would be a regression in the name of correctness.
        $tierId = (int) ($data['tier_id'] ?? 0);
        if ($tierId <= 0) {
            $available = EventTicketService::tiers((int) $event->id, $code);
            if (count($available) === 1) {
                $tierId = (int) $available[0]['id'];
            } else {
                return $json(['success' => false, 'message' => 'Please choose a ticket type.']);
            }
        }

        $r = EventTicketService::reserve(
            (int) $event->id, $tierId, $who, $qty, $code,
            ((int) ($_SESSION['user_id'] ?? 0)) ?: null
        );

        if (!($r['ok'] ?? false)) {
            return $json(['success' => false, 'full' => ($r['state'] ?? '') === 'sold_out',
                          'message' => (string) $r['message']]);
        }

        // ── free: done, and told ─────────────────────────────────────────────
        if ($r['free'] ?? false) {
            $this->announce($req, $event, $who, (string) ($r['ticket_code'] ?? ''), 0, $qty,
                            (string) $r['reference']);
            return $json(['success' => true, 'ticket_code' => (string) ($r['ticket_code'] ?? ''),
                          'ticket_url' => $this->base($req) . '/events/ticket/' . urlencode((string) $r['reference']),
                          'message' => 'You are registered — we have emailed you the details.']);
        }

        // ── paid: hand off ───────────────────────────────────────────────────
        $providers = $this->payments()->enabledProviderIds();
        if ($providers === []) {
            // The seats are already held, so release them rather than leaving a hold that
            // nobody can ever pay against — that would shrink the tier by one for half an
            // hour every time somebody pressed a button on a misconfigured site.
            EventTicketService::cancel((int) $r['id'], 'no payment gateway is configured');
            return $json(['success' => false, 'message' => 'Paid tickets are not available just now. '
                                                         . 'Please contact us and we will register you.']);
        }
        $provider  = in_array('paystack', $providers, true) ? 'paystack' : $providers[0];
        $reference = (string) $r['reference'];

        $callback = $this->base($req) . '/events/callback?provider=' . urlencode($provider)
                  . '&ref=' . urlencode($reference);
        $init = $this->payments()->initialize(
            $provider, (int) $r['amount'], $who['email'], $reference, $callback,
            ['reference' => $reference, 'purpose' => 'event',
             'event' => (string) $event->slug, 'quantity' => $qty]
        );

        if (!($init['ok'] ?? false) || empty($init['checkout_url'])) {
            EventTicketService::cancel((int) $r['id'], 'the gateway would not start a transaction');
            return $json(['success' => false,
                          'message' => 'We could not start the payment. Nothing has been charged — '
                                     . 'please try again in a moment.']);
        }

        try {
            DB::table('gates_event_registrations')->where('id', (int) $r['id'])
                ->update(['provider' => $provider]);
        } catch (\Throwable) {}

        // Through GatewayHandoff rather than a bare URL, for the same reason the shop
        // does it: the redirect happens inside a form submission, so `form-action`
        // governs it and a CSP without the gateway's hosts blocks the POST in the browser
        // before any of this runs.
        return $json([
            'success' => true, 'pay' => GatewayHandoff::remember(
                $reference, (string) $init['checkout_url'],
                $this->base($req) . '/events/redirect', $provider
            ),
            'amount'  => (int) $r['amount'],
            'message' => 'Taking you to the payment page…',
        ]);
    }

    /** The interstitial that performs the actual hand-off. Mirrors /shop/redirect. */
    public function redirect(Request $req, Response $res): Response
    {
        return GatewayHandoff::render($this->view, $req, $res, '/events');
    }

    /**
     * The browser comes back. Re-verified server-to-server — the query string is a hint.
     *
     * A callback is a URL the buyer's browser was told to visit, so nothing in it can be
     * believed: the reference names which registration to look at and the gateway is
     * asked whether it was paid. Identical to the shop and paid votes.
     */
    public function callback(Request $req, Response $res): Response
    {
        $q   = $req->getQueryParams();
        $ref = trim((string) ($q['ref'] ?? $q['reference'] ?? $q['tx_ref'] ?? ''));
        if ($ref === '') return $this->goTo($res, '/events');

        $reg = EventTicketService::byReference($ref);
        if (!$reg) return $this->goTo($res, '/events');

        $slug = (string) (DB::table('gates_site_events')->where('id', (int) $reg->event_id)->value('slug') ?? '');
        $r = EventTicketService::confirm($ref, $this->payments());

        if ($r['ok'] ?? false) {
            if (!($r['already'] ?? false)) {
                $this->announce($req, DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first(),
                    ['name' => (string) $reg->name, 'email' => (string) $reg->email, 'phone' => (string) $reg->phone],
                    (string) ($r['ticket_code'] ?? ''), (int) ($reg->amount_naira ?? 0),
                    (int) ($reg->quantity ?? 1), $ref);
            }
            return $this->goTo($res, '/events/ticket/' . urlencode($ref));
        }

        // Not confirmed. The hold is left alone rather than cancelled: a bank transfer can
        // settle after the browser has given up, and the reconciler will find it. Telling
        // them it failed and then issuing a ticket an hour later is better than releasing
        // the seat they may have paid for.
        return $this->goTo($res, '/events/' . urlencode($slug) . '?payment=pending&ref=' . urlencode($ref));
    }

    /**
     * The ticket. Reachable with the reference alone, and deliberately so.
     *
     * Same doctrine as the claim link, the interview page and the questionnaire: an
     * attendee has no account, and requiring one to see the ticket they just bought would
     * put a login between a person and the door they are standing at. The reference is a
     * ten-hex-character secret this platform generated, it is in their receipt email, and
     * the page is `noindex`.
     */
    public function ticket(Request $req, Response $res, array $args): Response
    {
        $ref = trim((string) ($args['ref'] ?? ''));
        $reg = EventTicketService::byReference($ref);

        if (!$reg) {
            // No hint about whether the reference is unknown or merely not ours: the
            // difference is a way to test references.
            return $this->view->render($res->withStatus(404), 'pages/events/ticket.twig', [
                'page_title' => 'Ticket', 'gates_page' => 'events', 'has_hero' => false,
                'reg' => null, 'event' => null,
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $event = DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first();

        return $this->view->render($res, 'pages/events/ticket.twig', [
            'page_title'   => 'Your ticket — ' . (string) ($event->title ?? 'Africa GATES'),
            'gates_page'   => 'events',
            'has_hero'     => false,
            'reg'          => (array) $reg,
            'event'        => $event ? (array) $event : null,
            'support_email'=> Notifier::supportEmail(),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function goTo(Response $res, string $path): Response
    {
        return $res->withHeader('Location', $path)->withStatus(302);
    }

    /**
     * Tell the attendee, tell the team, tell the integrations.
     *
     * Best-effort throughout: a mail failure must never undo a confirmed ticket. The
     * ticket exists in the database and on its own page either way, which is the half
     * that matters — an email is a convenience, and treating it as the delivery mechanism
     * is how somebody ends up at a door with nothing to show.
     */
    private function announce(Request $req, ?object $event, array $who, string $ticketCode,
                              int $amount, int $qty, string $reference = ''): void
    {
        if (!$event) return;
        $slug = (string) ($event->slug ?? '');

        WebhookService::dispatch('event.registration', [
            'event'    => ['slug' => $slug, 'title' => (string) ($event->title ?? '')],
            'attendee' => ['name' => $who['name'], 'email' => $who['email'], 'phone' => $who['phone']],
            'ticket'   => ['code' => $ticketCode, 'quantity' => $qty, 'amount_naira' => $amount],
        ]);

        if (!$this->mailer) return;

        $base  = $this->base($req);
        $e     = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $title = $e((string) ($event->title ?? 'the event'));
        $when  = $e((string) ($event->event_date ?? ''));
        $where = $e((string) ($event->location ?? ''));
        $nm    = $e($who['name']);

        $ticketUrl = $reference !== ''
            ? $base . '/events/ticket/' . rawurlencode($reference)
            : $base . '/events/' . rawurlencode($slug);

        $codeRow = $ticketCode !== ''
            ? '<br>Ticket code: <strong style="font-family:monospace;font-size:16px">' . $e($ticketCode) . '</strong>'
            : '';
        $seatRow = $qty > 1 ? '<br>Seats: <strong>' . $qty . '</strong>' : '';
        $paidRow = $amount > 0 ? '<br>Paid: <strong>₦' . number_format($amount) . '</strong>' : '';

        $html = "<p>Hi <strong>{$nm}</strong>,</p>"
              . '<p>' . ($amount > 0
                  ? 'Your payment has been received and your ticket is confirmed.'
                  : 'You are registered — we have saved your spot.') . '</p>'
              . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" "
              . "style=\"margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px\">"
              . "<tr><td style=\"font-size:14px;color:#166534;line-height:1.7\">Event: <strong>{$title}</strong><br>"
              . "When: <strong>{$when}</strong>"
              . ($where !== '' ? "<br>Where: <strong>{$where}</strong>" : '')
              . $seatRow . $paidRow . $codeRow . '</td></tr></table>'
              // The ticket page, not the event page. This is the link somebody opens at the
              // door, and it is reachable with the reference alone precisely so that they do
              // not need an account they never made in a queue with no signal.
              . '<p style="text-align:center;margin:22px 0"><a href="' . $ticketUrl . '"'
              . ' style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;'
              . 'border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">Your ticket →</a></p>';

        $plain = "Hi {$who['name']},\n\n"
               . ($amount > 0 ? "Your payment has been received and your ticket is confirmed.\n"
                              : "You are registered.\n")
               . 'Event: ' . (string) ($event->title ?? '') . "\n"
               . 'When: ' . (string) ($event->event_date ?? '') . "\n"
               . ($ticketCode !== '' ? "Ticket code: {$ticketCode}\n" : '')
               . "\nYour ticket: {$ticketUrl}\n\n— Africa GATES";

        try {
            $this->mailer->sendBranded(
                $who['email'],
                ($amount > 0 ? 'Your ticket — ' : 'You are registered — ')
                    . (string) ($event->title ?? 'Africa GATES event'),
                $html, $plain, 'Events',
                $base . '/assets/img/illustrations/illo-trophy2.jpg'
            );
        } catch (\Throwable) {}

        Notifier::adminAlert($this->mailer,
            ($amount > 0 ? 'Event ticket sold' : 'New event RSVP') . ' — ' . (string) ($event->title ?? ''),
            "Event: " . (string) ($event->title ?? '') . "\nName: {$who['name']}\nEmail: {$who['email']}"
            . "\nPhone: {$who['phone']}\nSeats: {$qty}"
            . ($amount > 0 ? "\nPaid: NGN " . number_format($amount) : '')
            . ($ticketCode !== '' ? "\nTicket: {$ticketCode}" : ''));
    }
}
