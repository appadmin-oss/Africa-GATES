<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\{CacheService, OtpService, Notifier,
                         EventTicketService, EventTicketMailer, EventDiscount, EventWaitlist,
                         EventAgenda, EventTicketDesign,
                         GatewayHandoff, PaymentService};

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

        // ── THE AGENDA ───────────────────────────────────────────────────────
        //
        // Sessions are rows now, grouped into days. The old JSON run of show is read only
        // when there are none, so an organiser who moves to sessions does not see their
        // agenda printed twice and one who never does keeps the page they had.
        $agenda   = EventAgenda::days((int) $event['id']);
        $schedule = $agenda === [] ? (json_decode((string) ($event['schedule'] ?? '[]'), true) ?: []) : [];

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
            'agenda'           => $agenda,
            'tiers'            => $tiers,
            'event_sold'       => $eventSold,
            // The waitlist is offered per TIER, because a tier is what sells out — somebody
            // priced out of the ₦380,000 table is not waiting for it, they are waiting for a
            // standard seat, and one queue for the whole event would mix them.
            'waitlist_open'    => EventWaitlist::open((object) $event) && !$isPast,
            'waitlist_counts'  => array_reduce($tiers, static function (array $c, array $t): array {
                if (isset($t['id'])) $c[(int) $t['id']] = EventWaitlist::length((int) $t['id']);
                return $c;
            }, []),
            // Shown BEFORE anybody pays, not in a confirmation email nobody reads twice.
            'refund_policy'    => trim((string) ($event['refund_policy'] ?? '')),
            'attendee_note'    => trim((string) ($event['attendee_note'] ?? '')),
            'organiser_email'  => trim((string) ($event['organiser_email'] ?? '')),
            'organiser_phone'  => trim((string) ($event['organiser_phone'] ?? '')),
            'sales_closed'     => self::salesClosed($event, $now),
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
     * When registration closed, if it closed before the event itself did.
     *
     * Returned as the sentence rather than a boolean, because "closed" and "closed on Tuesday
     * at 17:00 for catering" are different amounts of respect for somebody who arrived late.
     */
    private static function salesClosed(array $event, string $now): string
    {
        $closes = trim((string) ($event['sales_close_at'] ?? ''));
        if ($closes === '' || $closes >= $now) return '';
        try {
            return 'Registration closed on ' . Carbon::parse($closes)->format('j F, H:i') . '.';
        } catch (\Throwable) {
            return 'Registration has closed for this event.';
        }
    }

    /**
     * Price a discount code before anybody commits to anything.
     *
     * ── WHY THIS EXISTS AS WELL AS THE CHECK INSIDE reserve() ────────────────
     *
     * A buyer who types a code and cannot see what it does has to complete a purchase to
     * find out whether it worked — and if it did not, the first they learn of it is the
     * amount on the gateway's page, which is where a booking gets abandoned.
     *
     * It is a PREVIEW and nothing else. {@see EventTicketService::reserve()} prices the row
     * again from the tier and the code rows, so a forged response here changes what somebody
     * is shown and not what they are charged. Nothing is created, nothing is counted, and
     * `used_count` is untouched: a code exhausted by window shoppers would be a denial of
     * service on the organiser's own promotion.
     */
    public function quote(Request $req, Response $res, array $args): Response
    {
        $json = function (array $payload) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json');
        };

        $event = DB::table('gates_site_events')
            ->where('slug', (string) ($args['slug'] ?? ''))->where('status', 'published')->first();
        if (!$event) return $json(['success' => false, 'message' => 'That event no longer exists.']);

        $data   = (array) $req->getParsedBody();
        $tierId = (int) ($data['tier_id'] ?? 0);
        $qty    = max(1, (int) ($data['quantity'] ?? 1));
        $code   = trim((string) ($data['discount'] ?? ''));
        $email  = trim((string) ($data['email'] ?? ''));

        $tier = EventTicketService::tier($tierId);
        if (!$tier || (int) $tier->event_id !== (int) $event->id) {
            return $json(['success' => false, 'message' => 'Please choose a ticket type first.']);
        }

        $gross = (int) $tier->price_naira * $qty;
        if ($code === '') {
            return $json(['success' => true, 'applied' => false, 'gross' => $gross,
                          'total' => $gross, 'off' => 0, 'message' => '']);
        }

        $d = EventDiscount::apply($code, (int) $event->id, $tierId, $gross, $email, $qty);
        return $json(['success' => true, 'applied' => (bool) $d['ok'], 'gross' => $gross,
                      'off'     => (int) ($d['off'] ?? 0),
                      'total'   => (int) ($d['total'] ?? $gross),
                      'message' => (string) $d['message']]);
    }

    /**
     * Ask to be told when a seat comes free.
     *
     * The answer to a sold-out tier used to be the words "fully booked", which throws away
     * the most motivated person in the room — they wanted to come enough to arrive on a
     * sold-out page. {@see EventWaitlist} is off by default per event, because a queue
     * nobody works is worse than an honest no.
     */
    public function waitlist(Request $req, Response $res, array $args): Response
    {
        $json = function (array $payload) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json');
        };

        $event = DB::table('gates_site_events')
            ->where('slug', (string) ($args['slug'] ?? ''))->where('status', 'published')->first();
        if (!$event) return $json(['success' => false, 'message' => 'That event no longer exists.']);

        $data = (array) $req->getParsedBody();
        $r = EventWaitlist::join((int) $event->id, (int) ($data['tier_id'] ?? 0), [
            'name'  => trim((string) ($data['name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ]);

        return $json(['success' => (bool) $r['ok'], 'place' => (int) ($r['place'] ?? 0),
                      'message' => (string) $r['message']]);
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
            ((int) ($_SESSION['user_id'] ?? 0)) ?: null,
            trim((string) ($data['discount'] ?? '')) ?: null
        );

        if (!($r['ok'] ?? false)) {
            return $json(['success' => false, 'full' => ($r['state'] ?? '') === 'sold_out',
                          'waitlist' => ($r['state'] ?? '') === 'sold_out'
                                     && EventWaitlist::open($event),
                          'message' => (string) $r['message']]);
        }

        // ── free: done, and told ─────────────────────────────────────────────
        if ($r['free'] ?? false) {
            // Sent inline, not queued: nobody is on a gateway's clock here, and an RSVP
            // confirmation that arrives at the next cron tick reads as one that did not arrive.
            EventTicketMailer::send((int) $r['id'], $this->mailer);
            return $json(['success' => true, 'ticket_code' => (string) ($r['ticket_code'] ?? ''),
                          'ticket_url' => $this->base($req) . '/events/ticket/' . urlencode((string) $r['reference']),
                          'discount_note' => (string) ($r['discount_note'] ?? ''),
                          'message' => (string) $r['message']]);
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
            // The event's slug travels with the handoff URL, so that when a session has
            // expired by the time somebody comes back, the bounce lands them on the event they
            // were buying rather than on the list — which is where the "try again" button is.
            'success' => true, 'pay' => GatewayHandoff::remember(
                $reference, (string) $init['checkout_url'],
                $this->base($req) . '/events/redirect?event=' . rawurlencode($slug), $provider
            ),
            'amount'  => (int) $r['amount'],
            'discount' => (int) ($r['discount'] ?? 0),
            'discount_note' => (string) ($r['discount_note'] ?? ''),
            'message' => 'Taking you to the payment page…',
        ]);
    }

    /** The interstitial that performs the actual hand-off. Mirrors /shop/redirect. */
    /**
     * GET /events/redirect — the same-origin hop to the payment gateway.
     *
     * ── THIS WAS A 500 ON EVERY EVENT PAYMENT, AND NOTHING CAUGHT IT ─────────
     *
     * It called `GatewayHandoff::render($this->view, $req, $res, '/events')`. There is no
     * `render()` on that class and there never was: the method was invented at the call site and
     * the call was never once executed, so PHP raised an undefined-method fatal the first time a
     * real buyer came back from the gateway — which is to say, on every paid ticket this feature
     * has ever been asked to sell.
     *
     * The shape below is the one the vote flow and the shop checkout both already use, and the
     * reason it is three steps rather than one is worth keeping in view:
     *
     *   • THE REFERENCE COMES FROM THE REQUEST, not from the session alone, so a buyer who
     *     completes payment in a second tab still lands on their own handoff.
     *   • take() IS ONE-SHOT. It reads the stored checkout URL and forgets it, so a back button
     *     or a shared link cannot re-open somebody else's gateway session.
     *   • AND A MISSING URL BOUNCES rather than erroring. A stale tab, an expired session or a
     *     link somebody kept overnight is an ordinary thing, not an exception — and an error page
     *     at this exact moment reads as "my money is gone".
     *
     * Guarded now by EventPaymentHandoffTest, which asserts the method exists before asserting
     * anything about its behaviour — because "does this method exist" is precisely the question
     * nothing was asking.
     */
    public function redirect(Request $req, Response $res): Response
    {
        $reference = GatewayHandoff::reference($req);
        $url       = GatewayHandoff::take($reference);

        if ($url === null) {
            // Back to the event they were buying from when we know it, and to the list when we
            // do not. Either is better than an error: they have not been charged, and the page
            // they came from is where the "try again" button is.
            $slug = trim((string) ($req->getQueryParams()['event'] ?? ''));
            $back = $slug !== '' && preg_match('/^[a-z0-9-]{1,160}$/i', $slug) === 1
                ? '/events/' . rawurlencode($slug) . '?pay=restart'
                : '/events?pay=restart';
            return $this->goTo($res, $back);
        }

        return GatewayHandoff::page($res, $url, GatewayHandoff::providerLabel(), $reference);
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
            // Called on BOTH branches, including `already`. The webhook very often wins this
            // race — a buyer paying inside a wallet app comes back late or not at all — and
            // the loser used to walk away assuming the winner had finished the job. The claim
            // inside the mailer means a second call sends nothing, so calling it costs a query
            // and covers the case where the winner's mail failed.
            EventTicketMailer::send((int) $reg->id, $this->mailer);
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
                'reg' => null, 'event' => null, 'lite_page' => true, 'task_page' => true,
                // The template reads `design` unconditionally, including on this branch —
                // a "we cannot find this ticket" page that throws because there is no event
                // to take a colour from would turn a mistyped link into a 500.
                'design' => EventTicketDesign::forEvent(null),
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $event  = DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first();
        $design = EventTicketDesign::forEvent($event);

        return $this->view->render($res, 'pages/events/ticket.twig', [
            'page_title'   => 'Your ticket — ' . (string) ($event->title ?? 'Africa GATES'),
            'gates_page'   => 'events',
            'has_hero'     => false,
            // LITE. This page uses none of the heavy stack — no map, no carousel, no video
            // player, no scroll cinema — and it is the one page in the site whose whole
            // design premise is that it renders on a phone with one bar of signal at a door.
            // Every library it does not fetch is a request that cannot time out there.
            'lite_page'    => true,
            // And no entrance animation. Somebody is holding this up at a door with a queue
            // behind them; a logo drawing itself is an obstacle wearing a brand.
            'task_page'    => true,
            'reg'          => (array) $reg,
            'event'        => $event ? (array) $event : null,
            'support_email'=> Notifier::supportEmail(),
            // Colours, image, which rows show — resolved and VALIDATED in PHP, because the
            // accent lands inside a style attribute. See EventTicketDesign.
            'design'       => $design,
            // The code as a QR, so a door reads it in half a second instead of nine keystrokes.
            // Only for a confirmed ticket: a pending payment rendered as a scannable ticket is
            // an argument at a door. Null when the code cannot be encoded, and the template
            // shows the code alone in that case — see AfricaGates\Support\Qr.
            'qr' => $design['show_qr']
                && (string) $reg->status === 'confirmed'
                && trim((string) ($reg->ticket_code ?? '')) !== ''
                ? \AfricaGates\Support\Qr::svg((string) $reg->ticket_code, 6,
                    'Ticket code ' . (string) $reg->ticket_code)
                : null,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The event as a calendar file: `/events/{slug}/calendar.ics`.
     *
     * Public, because the reason to offer it is that somebody who has not booked yet wants
     * the date held before they decide — and a login between them and that is how the date
     * gets forgotten instead.
     */
    public function calendar(Request $req, Response $res, array $args): Response
    {
        $slug  = trim((string) ($args['slug'] ?? ''));
        $event = DB::table('gates_site_events')
            ->where('slug', $slug)->where('status', 'published')->first();

        if (!$event) {
            return $res->withStatus(404);
        }

        $ics = \AfricaGates\Support\Ics::event([
            'uid'         => \AfricaGates\Support\Ics::uid('event-' . (int) $event->id . '-' . $slug),
            'title'       => (string) ($event->title ?? 'Africa GATES event'),
            'description' => $this->calendarBlurb($event, null),
            'location'    => $this->calendarWhere($event),
            'url'         => $this->base($req) . '/events/' . rawurlencode($slug),
            'starts_at'   => (string) ($event->event_date ?? ''),
            'ends_at'     => (string) ($event->end_date ?? ''),
        ]);

        if ($ics === null) {
            return $res->withStatus(404);
        }
        return $this->serveIcs($res, $ics, (string) ($event->slug ?? 'event'));
    }

    /**
     * A confirmed ticket as a calendar file: `/events/ticket/{ref}/calendar.ics`.
     *
     * The reference alone, same doctrine as the ticket page itself — and `noindex` for the
     * same reason. It carries the ticket code in the description, because the calendar entry
     * is the thing that will be open on the phone on the day.
     *
     * A pending or cancelled registration gets the event's dates and NO code. The date is
     * still worth holding; a code is not a thing to hand out before the money has arrived.
     */
    public function ticketCalendar(Request $req, Response $res, array $args): Response
    {
        $ref = trim((string) ($args['ref'] ?? ''));
        $reg = EventTicketService::byReference($ref);
        if (!$reg) {
            return $res->withStatus(404)->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $event = DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first();
        if (!$event) {
            return $res->withStatus(404)->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $ics = \AfricaGates\Support\Ics::event([
            // Keyed on the REFERENCE, not the code: a transfer or a reissue changes the code,
            // and re-downloading must update the attendee's existing entry rather than leave
            // them holding two entries with different codes on them.
            'uid'         => \AfricaGates\Support\Ics::uid('ticket-' . (string) $reg->reference),
            'title'       => (string) ($event->title ?? 'Africa GATES event'),
            'description' => $this->calendarBlurb($event, $reg),
            'location'    => $this->calendarWhere($event),
            'url'         => $this->base($req) . '/events/ticket/' . rawurlencode((string) $reg->reference),
            'starts_at'   => (string) ($event->event_date ?? ''),
            'ends_at'     => (string) ($event->end_date ?? ''),
        ]);

        if ($ics === null) {
            return $res->withStatus(404)->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        return $this->serveIcs($res, $ics, (string) ($event->slug ?? 'event') . '-ticket')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * What the calendar entry says once it is open on somebody's phone on the day.
     *
     * Plain text, with the things a person standing outside a venue actually needs: the code,
     * the organiser's note, who to ring. Not marketing copy — they already came.
     */
    private function calendarBlurb(object $event, ?object $reg): string
    {
        $parts = [];

        $tagline = trim((string) ($event->tagline ?? ''));
        if ($tagline !== '') $parts[] = $tagline;

        if ($reg !== null && (string) $reg->status === 'confirmed'
            && trim((string) ($reg->ticket_code ?? '')) !== '') {
            $line = 'Ticket code: ' . (string) $reg->ticket_code;
            if (trim((string) ($reg->tier ?? '')) !== '') {
                $line .= ' (' . (string) $reg->tier . ')';
            }
            if ((int) ($reg->quantity ?? 1) > 1) {
                $line .= ' — ' . (int) $reg->quantity . ' seats';
            }
            $parts[] = $line;
        } elseif ($reg !== null) {
            $parts[] = 'Your registration is ' . (string) ($reg->status ?? 'incomplete')
                . '. Open your ticket page for the current position.';
        }

        $note = trim((string) ($event->attendee_note ?? ''));
        if ($note !== '') $parts[] = 'Before you come: ' . $note;

        $who = array_filter([
            trim((string) ($event->organiser_email ?? '')),
            trim((string) ($event->organiser_phone ?? '')),
        ]);
        if ($who !== []) $parts[] = 'Questions: ' . implode(' · ', $who);

        return implode("\n\n", $parts);
    }

    /** Venue and town as one line, without a dangling separator when only one is set. */
    private function calendarWhere(object $event): string
    {
        return implode(', ', array_filter([
            trim((string) ($event->venue ?? '')),
            trim((string) ($event->location ?? '')),
        ]));
    }

    /**
     * Serve the file so that a phone opens it in a calendar.
     *
     * `attachment` with a `.ics` name matters: served inline, iOS Mail and several Android
     * browsers render the raw text instead of offering "Add to Calendar". The filename is
     * built by {@see \AfricaGates\Support\Ics::filename()}, which strips everything that
     * could break out of the header.
     */
    private function serveIcs(Response $res, string $ics, string $stem): Response
    {
        $res->getBody()->write($ics);
        return $res
            ->withHeader('Content-Type', \AfricaGates\Support\Ics::MIME)
            ->withHeader('Content-Disposition',
                'attachment; filename="' . \AfricaGates\Support\Ics::filename($stem) . '"')
            // Short, not long: an organiser who corrects a start time needs the corrected
            // file to be what the next person downloads.
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    private function goTo(Response $res, string $path): Response
    {
        return $res->withHeader('Location', $path)->withStatus(302);
    }

}
