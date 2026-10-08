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
                         EventTicketService, EventTicketMailer, EventDiscount, EventCodeResolver, EventWaitlist,
                         EventAgenda, EventTicketDesign, StandCall,
                         GatewayHandoff, PaymentService, RateLimitService, ReferralService};

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
        // Self-service resends and confirmation codes both send email on a public endpoint,
        // so they are throttled. Optional, like the mailer, so nothing that constructs this
        // controller without one breaks — the services treat null as "no limit" and the
        // container supplies the real one.
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function payments(): PaymentService
    {
        return $this->payments ?? new PaymentService();
    }

    private function base(Request $req): string
    {
        return rtrim(\AfricaGates\Support\SiteUrl::base($req), '/');
    }

    /**
     * `/events` — upcoming first, coming soon among them, past in its own section (Phase 7,
     * §8.10; owner, 4 Oct 2026). Every figure from {@see \AfricaGates\Services\EventsFront}.
     *
     * Not cached: the state of a row (on sale, coming soon, sold out) moves on its own clock
     * and on every booking, and a chip that says "on sale" for fifteen minutes after the last
     * seat went is the stand-call chip's old fault. The page reads at most 84 events.
     */
    public function index(Request $req, Response $res): Response
    {
        self::captureRef($req);   // ?ref= — the primary referral path
        $q     = $req->getQueryParams();
        $front = \AfricaGates\Services\EventsFront::browse($q);

        return $this->view->render($res, 'pages/events.twig', [
            'page_title'       => \AfricaGates\Support\Translator::t('Events') . ' — Africa GATES',
            'meta_description' => \AfricaGates\Support\Translator::t('Upcoming ceremonies, live moments and events on Africa GATES — with tickets, dates and how to watch.'),
            'gates_page'       => 'events',
            'front'            => $front,
            // Which spotlight events are taking stand applications. Outside any cache, and
            // one query for the page — see StandCall::openFor().
            'stand_calls'      => StandCall::openFor(array_column($front['spotlight'], 'id')),
        ] + ($front['filtering'] ? ['meta_robots' => 'noindex, follow'] : []));
    }

    /** Public event detail page — the redesigned §8.10 page, every state. */
    public function show(Request $req, Response $res, array $args): Response
    {
        self::captureRef($req);   // ?ref= — the primary referral path
        $slug  = (string) ($args['slug'] ?? '');
        $event = \AfricaGates\Services\EventsFront::liveOnly(DB::table('gates_site_events as e')
            ->where('e.slug', $slug)->where('e.status', 'published'))->first(['e.*']);

        if (!$event) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }
        $event = (array) $event;
        $id    = (int) $event['id'];
        $now   = Carbon::now()->toDateTimeString();

        // ── HOW FULL IS THE ROOM, REALLY ─────────────────────────────────────
        //
        // Seats AND live holds drive capacity (a hold is a seat nobody else can buy); only
        // PAID seats are ever shown to a person as "registered". Both from
        // EventTicketService — four implementations of "is it sold out" is the drift this
        // codebase keeps finding.
        $seatsTaken = EventTicketService::soldForEvent($id);
        $attending  = EventTicketService::attendingForEvent($id);
        $capacity   = ($event['capacity'] ?? null) !== null ? (int) $event['capacity'] : null;
        $spotsLeft  = $capacity !== null ? max(0, $capacity - $seatsTaken) : null;
        $roomFull   = $capacity !== null && $seatsTaken >= $capacity;

        // Sessions are rows grouped into days; the old JSON run of show only when there are none.
        $agenda   = EventAgenda::days($id);
        $schedule = $agenda === [] ? (json_decode((string) ($event['schedule'] ?? '[]'), true) ?: []) : [];
        $tracks   = [];
        foreach ($agenda as $d) {
            foreach ($d['sessions'] ?? [] as $s) {
                $t = trim((string) ($s['track'] ?? ''));
                if ($t !== '' && !in_array($t, $tracks, true)) $tracks[] = $t;
            }
        }

        // Tiers are rows, each with its own limit; the legacy JSON blob only before migrating.
        $code  = trim((string) ($req->getQueryParams()['code'] ?? ''));
        $tiers = EventTicketService::tiers($id, $code);
        if ($tiers === []) {
            $legacy = json_decode((string) ($event['ticket_tiers'] ?? '[]'), true);
            $tiers  = is_array($legacy) ? $legacy : [];
        }

        $hosts = \AfricaGates\Services\EventsFront::hosts([$id]);
        $event['award_linked'] = isset(\AfricaGates\Services\EventsFront::linked([$id])[$id]);
        $ev    = \AfricaGates\Services\EventsFront::row($event, $hosts, $now, $tiers, $roomFull);
        $isPast = $ev['state'] === 'ended';

        // Early-bird row: text set, and no deadline or a deadline still ahead.
        $ebText  = trim((string) ($event['early_bird_text'] ?? ''));
        $ebUntil = trim((string) ($event['early_bird_deadline'] ?? ''));
        $earlyBird = ($ebText !== '' && !$isPast && in_array($ev['state'], ['open', 'soon'], true)
                      && ($ebUntil === '' || $ebUntil >= $now))
            ? ['text' => $ebText,
               'until' => $ebUntil !== '' ? \AfricaGates\Support\EventTime::at($event, $ebUntil, 'j M') : '',
               'url' => trim((string) ($event['early_bird_url'] ?? ''))]
            : null;

        // ── "YOU COULD EARN FROM THIS" ───────────────────────────────────────
        // Rate and threshold read LIVE — a page promising 10% after a change to 8% is a
        // promise the ledger will not honour. A link needs an owner, so a visitor is offered
        // the programme and a way to sign in; a member gets their real link.
        $referral = null;
        if (ReferralService::enabled() && ReferralService::enabledForEvent($id) && !$isPast) {
            $me = self::memberId();
            $referral = [
                'pct'       => ReferralService::ratePct(),
                'threshold' => ReferralService::threshold(),
                'link'      => null,
            ];
            $rc = $me !== null ? ReferralService::codeFor($me) : null;
            if ($rc !== null) {
                $referral['link'] = ReferralService::link(\AfricaGates\Support\SiteUrl::base($req), (string) $rc, (string) $event['slug']);
            }
        }

        // ── EACH TIER ROW, WITH ITS COLOURS AS CUSTOM PROPERTIES ─────────────
        //
        // Tone and colours from PHP: rank is a price question and the list is ordered by
        // `sort_order` (EventTierTone); the colours are DERIVED from the tier's slot and the
        // event's accent at read time (EventTierTone::card — GAPS Q6), never stored.
        $tones = \AfricaGates\Services\EventTierTone::forTiers($tiers);
        $peak  = \AfricaGates\Services\EventTierTone::peakId($tiers);
        $rows  = [];
        foreach ($tiers as $t) {
            if (!isset($t['id'])) continue;
            $st   = (string) ($t['state'] ?? 'open');
            $gone = $st === 'sold_out';
            $c    = \AfricaGates\Services\EventTierTone::card($t, $event);
            $rows[] = [
                'id'      => (int) $t['id'],
                'name'    => (string) $t['name'],
                'note'    => (string) ($t['description'] ?? ''),
                'perks'   => (array) ($t['perks'] ?? []),
                'price'   => (int) ($t['price_naira'] ?? 0),
                'price_text' => \AfricaGates\Services\EventSales::money((int) ($t['price_naira'] ?? 0)),
                'state'   => $st,
                'why'     => (string) ($t['why'] ?? ''),
                'left'    => $t['left'] ?? null,
                'min'     => (int) ($t['min'] ?? 1),
                'max'     => (int) ($t['max'] ?? 1),
                'gone'    => $gone,
                // A sold-out row stays pressable when there is a waiting list (the thing to
                // do about it is join); otherwise only an open row can be chosen.
                'enabled' => $st === 'open' || ($gone && EventWaitlist::open((object) $event)),
                'waiting' => EventWaitlist::length((int) $t['id']),
                'tone'    => $tones[(int) $t['id']] ?? 'calm',
                'peak'    => (int) $t['id'] === $peak,
                // Validated hexes from the palette, so the attribute is safe to write raw.
                'style'   => '--tier-accent:' . $c['accent'] . ';--tier-light:' . $c['light']
                           . ';--tier-wash:' . $c['wash'] . ';--tier-deep:' . $c['deep']
                           . ';--tier-glow:' . $c['glow'] . ';--tier-edge:' . $c['edge'],
            ];
        }

        // An uploaded photo wins everywhere a share image is read; cover_path is the newer
        // column, cover_image the one older rows carry.
        $uploaded = trim((string) ($event['cover_path'] ?? '')) ?: trim((string) ($event['cover_image'] ?? ''));

        $gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode((string) $event['title'])
              . '&dates=' . self::gcalStamp((string) $event['event_date']) . '/'
              . self::gcalStamp((string) (($event['end_date'] ?? '') ?: $event['event_date']))
              . '&location=' . rawurlencode($this->calendarWhere((object) $event))
              . '&details=' . rawurlencode((string) ($event['tagline'] ?? ''));

        return $this->view->render($res, 'pages/events/detail.twig', [
            'page_title'       => $event['title'] . ' — Africa GATES',
            'meta_description' => ($event['tagline'] ?? null)
                ?: mb_substr(strip_tags((string) ($event['description'] ?? '')), 0, 150),
            'gates_page'       => 'events',
            // Event JSON-LD: date, venue and PRICE render in the search listing itself.
            // Everything in it goes through Schema::text() — a partner-typed title is
            // untrusted in a script element (CLAUDE.md).
            'schema'           => \AfricaGates\Support\Schema::event(
                $event,
                \AfricaGates\Support\SiteUrl::base($req),
                array_map(static fn (array $t): array => [
                    'name'      => (string) ($t['name'] ?? ''),
                    'price'     => (int) ($t['price_naira'] ?? 0),
                    'available' => ($t['state'] ?? 'open') === 'open',
                ], $tiers),
                $uploaded !== '' ? $uploaded : CoverImageController::eventImages($id)
            ),
            'event'            => $event,
            'ev'               => $ev,
            'member'           => \AfricaGates\Services\UserAccountService::memberForForms(),
            'reg_count'        => $attending,
            'capacity'         => $capacity,
            'spots_left'       => $spotsLeft,
            'agenda'           => $agenda,
            'schedule'         => $schedule,
            'tracks'           => $tracks,
            'tiers'            => $rows,
            'early_bird'       => $earlyBird,
            'referral'         => $referral,
            // Live appeals joined to this event; the card reads "fundraiser" from them, so it
            // is derived and never a second flag to keep in step.
            'appeals'          => \AfricaGates\Services\OrgCampaign::forEvent($id),
            'waitlist_hours'   => EventWaitlist::OFFER_HOURS,
            'refund_policy'    => trim((string) ($event['refund_policy'] ?? '')),
            'refund_rule'      => \AfricaGates\Services\EventRefundPolicy::summary((object) $event),
            'attendee_note'    => trim((string) ($event['attendee_note'] ?? '')),
            'organiser_email'  => trim((string) ($event['organiser_email'] ?? '')),
            'organiser_phone'  => trim((string) ($event['organiser_phone'] ?? '')),
            'access'           => array_values(array_filter(array_map('trim',
                                    preg_split('/\R/', (string) ($event['access_notes'] ?? '')) ?: []))),
            'sales_closed'     => self::salesClosed($event, $now),
            'paid_tiers'       => (bool) array_filter($rows, static fn (array $t): bool => $t['price'] > 0),
            'access_code'      => $code,
            'gateway_ready'    => $this->payments()->enabledProviderIds() !== [],
            'stand_call'       => StandCall::nudge($id, (string) $event['slug'], $isPast),
            'awards'           => \AfricaGates\Services\EventsFront::awards($id),
            'gcal'             => $gcal,
            'alert_waiting'    => $ev['state'] === 'soon' ? \AfricaGates\Services\EventSaleAlert::waiting($id) : 0,
            'alert_said'       => self::takeAlertSaid(),
            // The flier's style picker, in THIS event's real colours (EventFlierTheme).
            'flier_styles'     => array_reduce(
                \AfricaGates\Services\EventFlierLayout::FORMATS,
                static function (array $c, string $fmt) use ($event): array {
                    $c[$fmt] = \AfricaGates\Services\EventFlierTheme::swatches($event, $fmt);
                    return $c;
                }, []),
            'flier_style_default' => \AfricaGates\Services\EventFlierTheme::DEFAULTS,
        ] + array_filter([
            // No upload → the default cover, drawn by GD at the share ratio, and an alt that
            // says what the picture says rather than repeating the title (§7).
            'og_image'     => \AfricaGates\Support\Assets::absoluteOg($uploaded !== '' ? $uploaded
                                : '/og/event/' . $id . '-1200x630.png'),
            'og_image_alt' => $uploaded !== '' ? (string) $event['title'] : (static function () use ($event): string {
                $f = CoverImageController::facts($event);
                return \AfricaGates\Services\CoverImage::alt($f['title'], $f['date'], $f['place']);
            })(),
            // Our own image has a known shape, so the FIRST fetch renders it right.
            'og_image_w'   => $uploaded !== '' ? null : 1200,
            'og_image_h'   => $uploaded !== '' ? null : 630,
        ], fn($v) => $v !== null));
    }

    /** The sale-alert reply, read once (a flash): a refresh must not repeat it. */
    private static function takeAlertSaid(): string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) return '';
        $v = (string) ($_SESSION['ev_alert_said'] ?? '');
        unset($_SESSION['ev_alert_said']);
        return $v;
    }

    /** A stored UTC datetime in Google Calendar's form, with the `Z` (see the .ics note). */
    private static function gcalStamp(string $stored): string
    {
        try { return Carbon::parse($stored, 'UTC')->format('Ymd\THis\Z'); }
        catch (\Throwable) { return ''; }
    }

    // ══ "EMAIL ME WHEN TICKETS GO ON SALE" ═══════════════════════════════════
    //
    // A plain form that posts (no script needed), throttled per connection, answering the
    // same sentence whatever happened. See EventSaleAlert for the double opt-in.

    public function alertWant(Request $req, Response $res, array $args): Response
    {
        $slug  = (string) ($args['slug'] ?? '');
        $event = DB::table('gates_site_events')->where('slug', $slug)->where('status', 'published')->first();
        if (!$event) throw new \Slim\Exception\HttpNotFoundException($req);

        $b  = (array) $req->getParsedBody();
        $ip = hash('sha256', \AfricaGates\Support\ClientIp::from($req));
        if ($this->rateLimit && !$this->rateLimit->check($ip, 'event_sale_alert', 10, 3600)) {
            $_SESSION['ev_alert_said'] = 'Too many requests from this connection in the last hour. Try again later.';
        } else {
            $r = \AfricaGates\Services\EventSaleAlert::want(
                (int) $event->id, (string) ($b['email'] ?? ''), $ip, $this->base($req),
                $this->mailer ? \AfricaGates\Services\Newsletter\NewsletterAudience::transport($this->mailer) : null
            );
            $_SESSION['ev_alert_said'] = $r['message'];
        }
        // Post / redirect / get: a refresh does not resubmit and the address is not in the URL.
        return $res->withHeader('Location', '/events/' . rawurlencode($slug) . '#ev-rsvp')->withStatus(303);
    }

    /** GET shows, POST confirms — mail scanners fetch every link (the newsletter's rule). */
    public function alertPage(Request $req, Response $res, array $args): Response
    {
        $token  = (string) ($args['token'] ?? '');
        $action = (string) ($args['action'] ?? '');
        $done   = false;
        $row    = \AfricaGates\Services\EventSaleAlert::find($token);
        if ($row && $req->getMethod() === 'POST') {
            $row  = $action === 'stop'
                ? \AfricaGates\Services\EventSaleAlert::stop($token)
                : \AfricaGates\Services\EventSaleAlert::confirm($token);
            $done = true;
        }
        $event = $row ? DB::table('gates_site_events')->where('id', (int) $row->event_id)->first(['slug', 'title']) : null;

        return $this->view->render($res->withStatus($row ? 200 : 404), 'pages/events/alert.twig', [
            'page_title' => \AfricaGates\Support\Translator::t('Ticket alert') . ' — Africa GATES',
            'gates_page' => 'events',
            'meta_robots'=> 'noindex, nofollow',
            'alert'      => $row ? ['token' => (string) $row->token, 'action' => $action,
                                    'confirmed' => $row->confirmed_at !== null,
                                    'stopped' => $row->cancelled_at !== null] : null,
            'done'       => $done,
            'alert_event' => $event ? ['title' => (string) $event->title,
                                       'url' => '/events/' . rawurlencode((string) $event->slug)] : null,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
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

        // The box takes a discount OR a referral, and a referral may also have arrived by
        // link with nothing typed — so there is something to say even for an empty box.
        // See EventCodeResolver for why one field rather than two.
        $linked = self::linkedRef();
        if ($code === '' && $linked === '') {
            return $json(['success' => true, 'applied' => false, 'gross' => $gross,
                          'total' => $gross, 'off' => 0, 'message' => '', 'kind' => 'none']);
        }

        $r = EventCodeResolver::resolve($code, $linked, (int) $event->id, $tierId, $gross,
                                        $email, $qty, self::memberId());

        return $json(['success' => true, 'applied' => (bool) $r['ok'], 'gross' => $gross,
                      'kind'    => (string) $r['kind'],
                      'off'     => (int) $r['off'],
                      'total'   => max(0, $gross - (int) $r['off']),
                      'message' => (string) $r['message']]);
    }

    /**
     * Remember a `?ref=` for the rest of the session.
     *
     * THE LINK IS THE PRIMARY PATH. A referrer shares a URL and the buyer does nothing at
     * all — no code to remember between the tweet they saw and the checkout they reach ten
     * minutes later. So the code is lifted off the query string the moment any events page
     * is opened and held in the session until a ticket is actually reserved.
     *
     * Overwritten by a later ?ref=, deliberately: the most recent link somebody followed is
     * the one that brought them to this purchase.
     */
    private static function captureRef(Request $req): void
    {
        // Delegated since the capture stopped being events-only. It used to live here and
        // write a key only this file read, which is why a link shared to the shop or the
        // home page earned its referrer nothing. See ReferralService::capture().
        \AfricaGates\Services\ReferralService::capture(
            (string) ($req->getQueryParams()['ref'] ?? '')
        );
    }

    /** The referral code captured from a link earlier in this session, or ''. */
    private static function linkedRef(): string
    {
        return \AfricaGates\Services\ReferralService::fromSession();
    }

    /**
     * The signed-in member, or null. Referral needs an owner; buying does not.
     *
     * `user_id`, which is the key AccountController's own signed-in guards and every
     * CommunityController handler read. (`member_id` appears in this codebase as a
     * template and audit field, not as the session key — an easy and expensive thing to
     * confuse, since reading the wrong one silently means "never signed in", and the
     * self-referral check would then pass for everybody.)
     */
    private static function memberId(): ?int
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) return null;
        $id = (int) ($_SESSION['user_id'] ?? 0);

        return $id > 0 ? $id : null;
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

        // The shared box may hold either kind of code, and a referral may have arrived by
        // link with the box left empty. Resolved once, here, so the row records exactly
        // what was decided rather than each layer guessing again.
        $typed  = trim((string) ($data['discount'] ?? ''));
        // Null-safe: $tierId is only known to be > 0 here, not to exist. reserve() is what
        // validates it, and a crafted tier_id reaching a property read on null would be a
        // 500 on a checkout instead of the refusal reserve() already gives.
        $tierRow   = EventTicketService::tier($tierId);
        $lineTotal = (int) ($tierRow->price_naira ?? 0) * $qty;
        $picked = EventCodeResolver::resolve(
            $typed, self::linkedRef(), (int) $event->id, $tierId, $lineTotal,
            (string) ($who['email'] ?? ''), $qty, self::memberId()
        );

        $r = EventTicketService::reserve(
            (int) $event->id, $tierId, $who, $qty, $code,
            self::memberId(),
            ($picked['discount'] ?? '') !== '' ? $picked['discount'] : null,
            ($picked['referral'] ?? '') !== '' ? $picked['referral'] : null
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
                          // ── THE FLIER'S CREDENTIAL ───────────────────────────
                          //
                          // Minted HERE, server-side, from the registration that was just
                          // created. It is the only way a browser can hold a confirmed flier:
                          // the client cannot assert a registration id, and this is the moment
                          // it is entitled to one — the handoff's first entry point, and the
                          // only moment somebody is proud.
                          'flier_token' => \AfricaGates\Services\EventFlierToken::mint(
                              // `$event` is a row OBJECT here, not the array the show()
                              // action works with — two shapes for one thing in one file, and
                              // the array subscript threw at runtime rather than at parse.
                              (int) $event->id, (string) ($data['name'] ?? ''), (int) ($r['id'] ?? 0)
                          ),
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

        // ── A RESUMED BOOKING CARRIES A REFERENCE THE GATEWAY HAS SEEN ───────
        //
        // `EventTicketService::hold()` deliberately hands back the reference of a booking
        // the buyer already started, so pressing the button twice cannot hold two lots of
        // seats out of a limited tier. Correct for the seats and wrong for the gateway:
        // Paystack refuses to open a second transaction against a reference it already
        // knows, this branch cancelled the hold, and the buyer was told "we could not start
        // the payment" for something they did nothing to cause. Three of those in one
        // production error log, each of them a sale that may have walked away.
        //
        // So: one retry with a fresh reference on the same booking. Safe precisely here and
        // nowhere else — a reference is refused as a duplicate because a transaction was
        // opened against it, and a transaction that had been PAID would have marked this
        // row paid, so a row still pending cannot have money attached to the reference
        // being replaced.
        if (!($init['ok'] ?? false)
            && PaymentService::isDuplicateReference((string) ($init['message'] ?? ''))) {
            $fresh = EventTicketService::rotateReference((int) $r['id']);
            if ($fresh !== '') {
                $reference = $fresh;
                $callback  = $this->base($req) . '/events/callback?provider=' . urlencode($provider)
                           . '&ref=' . urlencode($reference);
                $init = $this->payments()->initialize(
                    $provider, (int) $r['amount'], $who['email'], $reference, $callback,
                    ['reference' => $reference, 'purpose' => 'event',
                     'event' => (string) $event->slug, 'quantity' => $qty]
                );
            }
        }

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

            // ── SPEND THE REFERRAL ───────────────────────────────────────────
            //
            // ReferralService::clearSession() says what the rule is — "after it has been
            // credited, so one link cannot earn on two purchases" — and nothing called
            // it. So one click on a referral link stamped the referrer's code onto every
            // subsequent purchase in that browser session, and the commission was paid on
            // all of them. The referrer sees more earnings than they made; the operator
            // sees a liability figure nobody can reconcile against the links people
            // actually followed.
            //
            // HERE and not at reserve, because reserving is not paying: a buyer who
            // abandons a hold and comes back must keep the attribution they arrived
            // with. Guarded on the row actually carrying a code, so a self-referral that
            // was refused, or a free ticket that credits nothing, leaves the session
            // alone — nothing was earned, so nothing has been spent.
            $stamped = EventTicketService::byReference($ref);
            if (trim((string) ($stamped->referral_code ?? '')) !== '') {
                \AfricaGates\Services\ReferralService::clearSession();
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
                'page_title'    => \AfricaGates\Support\Translator::t('Ticket') . ' — Africa GATES',
                'gates_page'    => 'events',
                'meta_robots'   => 'noindex, nofollow',
                'reg'           => null,
                'support_email' => Notifier::supportEmail(),
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $event  = DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first();
        $design = EventTicketDesign::forEvent($event);
        $now    = Carbon::now();

        // The tier's colour, recomputed from the event's accent on every read — never a hex
        // stored on the tier. Null when the tier has no slot: the template then draws the
        // name and no dot, never a grey one that means nothing.
        $tierSwatch = null;
        if ((int) ($reg->tier_id ?? 0) > 0 && $event) {
            $tierSwatch = \AfricaGates\Services\EventTierPalette::forTier(
                EventTicketService::tier((int) $reg->tier_id), $event
            );
        }

        $start = $event ? (string) ($event->event_date ?? '') : '';
        $days  = null;
        if ($start !== '') {
            try {
                $days = (int) floor((Carbon::parse($start)->startOfDay()->getTimestamp()
                        - $now->copy()->startOfDay()->getTimestamp()) / 86400);
            } catch (\Throwable) {}
        }
        $where = $event ? $this->calendarWhere($event) : '';

        return $this->view->render($res, 'pages/events/ticket.twig', [
            'page_title'    => \AfricaGates\Support\Translator::t('Your ticket') . ' — ' . (string) ($event->title ?? 'Africa GATES'),
            'gates_page'    => 'events',
            'meta_robots'   => 'noindex, nofollow',
            'site_url'      => \AfricaGates\Support\SiteUrl::base($req),
            'reg'           => (array) $reg,
            'event'         => $event ? (array) $event : null,
            'tier_swatch'   => $tierSwatch,
            'support_email' => Notifier::supportEmail(),
            // Colours, image, which rows show — resolved and VALIDATED in PHP, because the
            // accent lands inside a style attribute. See EventTicketDesign.
            'design'        => $design,
            // The kicker's countdown, worked out here so the ticket needs no script to say it.
            'days'          => $days,
            'when'          => $event ? [
                'kick'  => \AfricaGates\Support\EventTime::at($event, $start, 'd.m.y'),
                'date'  => \AfricaGates\Support\EventTime::at($event, $start, 'D j M Y'),
                'doors' => \AfricaGates\Support\EventTime::zoned($event, $start, 'g:i a')
                         . (($event->end_date ?? '') ? ' – ' . \AfricaGates\Support\EventTime::at($event, (string) $event->end_date, 'g:i a') : ''),
                'print' => \AfricaGates\Support\EventTime::zoned($event, $start, 'd M Y · g:i a'),
            ] : null,
            'where'         => $where,
            // Directions: the organiser's own map link where one is set, otherwise a map
            // search for the address. A link out — nothing is embedded.
            'directions'    => $event ? (\AfricaGates\Services\EventsFront::link((string) ($event->map_embed ?? ''))
                                 ?: ($where !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($where) : '')) : '',
            // The code as a QR, only for a confirmed ticket: a pending payment rendered as a
            // scannable ticket is an argument at a door. Null when it cannot be encoded, and
            // the template shows the code alone (Support\Qr::encode — a ticket code, folded).
            'qr' => $design['show_qr']
                && (string) $reg->status === 'confirmed'
                && trim((string) ($reg->ticket_code ?? '')) !== ''
                ? \AfricaGates\Support\Qr::svg((string) $reg->ticket_code, 6,
                    'Ticket code ' . (string) $reg->ticket_code)
                : null,
            'can_manage'    => (string) $reg->status === 'confirmed' && trim((string) ($reg->checked_in_at ?? '')) === '',
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The ticket as a PDF, at a fixed physical size.
     *
     * ── WHY THIS EXISTS BESIDE A PERFECTLY GOOD WEB PAGE ─────────────────────
     *
     * Because `@media print` hands the artefact to the browser and the driver, and "Fit to
     * page" — on by default in more than one browser — silently rescales the QR symbol. A
     * code that came off the printer below the module size a scanner resolves is not
     * discovered in an office. It is discovered at a gate with a queue behind it.
     *
     * A PDF is 30mm on every machine. It is also a FILE, which the web page is not: it can
     * be forwarded to a guest with no printer, kept against a dispute, or sent to a print
     * shop that will not accept a URL.
     *
     * Reachable with the reference alone, like the page it comes from — an attendee has no
     * account, and a login between somebody and their own ticket is a queue that stops.
     */
    public function ticketPdf(Request $req, Response $res, array $args): Response
    {
        $ref = trim((string) ($args['ref'] ?? ''));
        $reg = $ref !== ''
            ? DB::table('gates_event_registrations')->where('reference', $ref)->first()
            : null;
        if (!$reg) return $res->withHeader('Location', '/events')->withStatus(302);

        // Only a confirmed booking becomes a document. Same doctrine as the page, and
        // stronger: paper carries an authority a screen does not, and nobody at a gate
        // assumes a printed ticket might still be provisional.
        if ((string) $reg->status !== 'confirmed') {
            return $res->withHeader('Location', '/events/ticket/' . rawurlencode($ref))->withStatus(302);
        }

        $event = DB::table('gates_site_events')->where('id', (int) $reg->event_id)->first();
        // SiteUrl, not APP_URL directly. It falls back to the request's own host, so a
        // deployment that never set APP_URL prints a working link rather than one beginning
        // "/events/…" — which on paper is not a link at all. SiteUrlTest enforces this.
        $url = \AfricaGates\Support\SiteUrl::base($req) . '/events/ticket/' . rawurlencode($ref);

        $pdf = \AfricaGates\Services\TicketPdf::one(
            (array) $reg,
            $event ? (array) $event : [],
            \AfricaGates\Services\EventTicketDesign::forEvent($event),
            $url
        );

        $name = preg_replace('/[^A-Za-z0-9\-]+/', '-', $ref) . '.pdf';
        $res->getBody()->write($pdf);
        return $res
            ->withHeader('Content-Type', 'application/pdf')
            // `inline`, not `attachment`: a phone opens it in place, and somebody who wants
            // the file still gets a save button from the viewer.
            ->withHeader('Content-Disposition', 'inline; filename="' . $name . '"')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ══ SELF-SERVICE ═════════════════════════════════════════════════════════
    //
    // Four endpoints, all JSON, all reachable with the reference alone — because an attendee
    // has no account and never will. What separates them is CONSEQUENCE, not convenience:
    // resending can only ever mail the address already on the booking, so it needs nothing;
    // changing the ticket needs a code sent to that address first. See TicketSelfService.

    /** POST /events/ticket/{ref}/resend — send the ticket to the address already on it. */
    public function resend(Request $req, Response $res, array $args): Response
    {
        $r = \AfricaGates\Services\TicketSelfService::resend(
            (string) ($args['ref'] ?? ''), $this->mailer, $this->rateLimit);
        return $this->jsonOut($res, $r);
    }

    /** POST /events/ticket/{ref}/code — email a 6-digit code before a change. */
    public function selfCode(Request $req, Response $res, array $args): Response
    {
        $r = \AfricaGates\Services\TicketSelfService::sendCode(
            (string) ($args['ref'] ?? ''), $this->mailer, $this->rateLimit);
        return $this->jsonOut($res, $r);
    }

    /** POST /events/ticket/{ref}/rename — change the name on the ticket. */
    public function rename(Request $req, Response $res, array $args): Response
    {
        $b = (array) $req->getParsedBody();
        $r = \AfricaGates\Services\TicketSelfService::rename(
            (string) ($args['ref'] ?? ''),
            (string) ($b['code'] ?? ''),
            (string) ($b['name'] ?? ''));
        return $this->jsonOut($res, $r);
    }

    /**
     * POST /events/ticket/{ref}/cancel-quote — what would I get back, before I commit?
     *
     * A read, and deliberately its own endpoint. The figure has to be on screen BEFORE the
     * irreversible step: a cancellation flow that reveals the consequence afterwards is the
     * pattern regulators enforce against, and it is also how somebody ends up furious with an
     * organiser who did nothing wrong.
     */
    public function cancelQuote(Request $req, Response $res, array $args): Response
    {
        $q = \AfricaGates\Services\EventRefundPolicy::quoteByReference((string) ($args['ref'] ?? ''));
        $res->getBody()->write((string) json_encode([
            'success'     => (bool) $q['can_cancel'],
            'message'     => (string) $q['why'],
            'naira'       => (int) $q['naira'],
            'mode'        => (string) $q['mode'],
            'policy_text' => (string) $q['policy_text'],
            'contact'     => (string) $q['contact'],
        ]));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store');
    }

    /** POST /events/ticket/{ref}/cancel — give the seat up, and refund per the policy. */
    public function cancel(Request $req, Response $res, array $args): Response
    {
        $b = (array) $req->getParsedBody();
        $r = \AfricaGates\Services\TicketSelfService::cancel(
            (string) ($args['ref'] ?? ''),
            (string) ($b['code'] ?? ''),
            $this->mailer);
        $res->getBody()->write((string) json_encode([
            'success'  => (bool) ($r['ok'] ?? false),
            'message'  => (string) ($r['message'] ?? ''),
            'refunded' => (int) ($r['refunded'] ?? 0),
            'status'   => (string) ($r['status'] ?? ''),
        ]));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store');
    }

    /** POST /events/ticket/{ref}/transfer — hand it to somebody else, with a fresh code. */
    public function transfer(Request $req, Response $res, array $args): Response
    {
        $b = (array) $req->getParsedBody();
        $r = \AfricaGates\Services\TicketSelfService::transfer(
            (string) ($args['ref'] ?? ''),
            (string) ($b['code'] ?? ''),
            (string) ($b['name'] ?? ''),
            (string) ($b['email'] ?? ''),
            $this->mailer);
        return $this->jsonOut($res, $r);
    }

    /** One JSON shape for all four, so the page can handle every reply the same way. */
    private function jsonOut(Response $res, array $r): Response
    {
        $res->getBody()->write((string) json_encode([
            'success' => (bool) ($r['ok'] ?? false),
            'message' => (string) ($r['message'] ?? ''),
        ] + (isset($r['sent_to']) ? ['sent_to' => (string) $r['sent_to']] : [])));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The event as a calendar file: `/events/{slug}/calendar.ics`.
     *
     * Public, because the reason to offer it is that somebody who has not booked yet wants
     * the date held before they decide — and a login between them and that is how the date
     * gets forgotten instead.
     */
    /**
     * The "I will be there" flier, as a PNG.
     *
     * ── 410, NOT 404, AND NOT AN IMAGE ───────────────────────────────────────
     *
     * A past or cancelled event refuses. The handoff is blunt about why and it is right: a
     * live QR on a finished event is worse than no flier, because somebody scans it, finds
     * nothing to buy, and concludes the platform is broken. 410 Gone rather than 404 because
     * the address WAS valid and the thing behind it has ended — which is the distinction a
     * cache respects.
     *
     * ── AND NO REGISTRATION ID IN THE URL ────────────────────────────────────
     *
     * The flier prints a name. `?reg=8814` would let anybody who can count render a stranger's
     * name and tier over this event's branding, and the platform would have generated it. The
     * payload travels inside a signed token instead — see EventFlierToken, which also explains
     * why every failure returns the same answer.
     */
    public function flier(Request $req, Response $res, array $args): Response
    {
        $q     = $req->getQueryParams();
        $fmt   = strtolower(trim((string) ($q['fmt'] ?? 'plain')));
        $token = (string) ($q['t'] ?? '');

        if (!\AfricaGates\Services\EventFlierLayout::valid($fmt)) $fmt = 'plain';

        // Against the format that will actually be DRAWN, which for this route is always the
        // no-photo one — a GET carries no upload. Normalised rather than rejected: a
        // hand-written URL asking for a style this format cannot draw gets the format's
        // default, which is a flier rather than an error. See EventFlierTheme::style().
        $fmt   = \AfricaGates\Services\EventFlier::format($fmt, false);
        $style = \AfricaGates\Services\EventFlierTheme::style($q['style'] ?? null, $fmt);

        $f = \AfricaGates\Services\EventFlier::forToken(
            $token, \AfricaGates\Support\SiteUrl::base($req)
        );

        // One answer for every refusal: expired, forged, wrong event, past, cancelled. A route
        // that distinguished them would be an oracle for guessing at the signing key, and
        // there is nothing a caller could usefully do differently with the distinction.
        if ($f === null) {
            $res->getBody()->write('This flier is no longer available.');
            return $res->withStatus(410)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        // The event in the token has to be the event in the URL. Without this a token minted
        // for one event renders under another's slug — the image would be right and the
        // address would be a lie, which is the kind of thing that gets screenshotted.
        if (trim((string) ($f['event']['slug'] ?? '')) !== trim((string) ($args['slug'] ?? ''))) {
            $res->getBody()->write('This flier is no longer available.');
            return $res->withStatus(410)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $png = (new \AfricaGates\Services\EventFlier())
            ->png($f, $fmt, null, null, null, null, $style);
        if ($png === null) {
            // Nothing drawn rather than a broken graphic: without GD or the bundled faces this
            // would render in GD's built-in bitmap font, which is not a degraded flier but a
            // graphic somebody would think we meant to make.
            $res->getBody()->write('The flier could not be generated on this server.');
            return $res->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $res->getBody()->write($png);

        return $res
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) strlen($png))
            // Cached per token, format AND style — all three are in the query string, so two
            // styles cannot collide in a cache. The token carries its own expiry, so a flier
            // that upgrades from open to confirmed gets a NEW token and therefore a new URL
            // rather than a stale image from before the payment landed.
            ->withHeader('Cache-Control', 'private, max-age=900')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Make a flier, in one request, and hand back the PNG.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS A POST THAT RETURNS AN IMAGE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The obvious build is: upload the photo, store it, mint a token, then GET the image by
     * token. That design has a storage layer, and the handoff's privacy promise is that the
     * photo is cropped and DISCARDED — verified "at the storage layer, not by reading the
     * code".
     *
     * The easiest way to keep that promise is to have no storage layer. The file arrives, is
     * decoded from PHP's own upload temp, is drawn into the flier, and the request ends —
     * at which point PHP unlinks the temp itself. Nothing to sweep, nothing to expire, no
     * cron to forget, and nothing for a later bug to leave behind.
     *
     * The browser holds the result as a blob, which is also exactly what `navigator.share`
     * wants for its `files` payload, so the share path needs no second fetch.
     *
     * {@see flier()} remains for the no-photo case, so a flier is still a shareable URL.
     *
     * ── AND ON RATE LIMITING ─────────────────────────────────────────────────
     *
     * The handoff's first open decision: anybody can generate one with any name, a session
     * token would limit abuse, and the friction would land on the ungated path that is the
     * whole point. Its call was "allow it, watch it, add friction only if abused" and that is
     * what this does — with the CSRF check as the only gate, which stops a drive-by without
     * asking a visitor for anything.
     */
    public function flierMake(Request $req, Response $res, array $args): Response
    {
        $b    = (array) $req->getParsedBody();
        $fmt  = strtolower(trim((string) ($b['fmt'] ?? 'plain')));
        $slug = trim((string) ($args['slug'] ?? ''));

        if (!\AfricaGates\Services\EventFlierLayout::valid($fmt)) $fmt = 'plain';

        $fail = function (Response $res, int $code, string $why): Response {
            $res->getBody()->write(json_encode(['success' => false, 'message' => $why]));
            return $res->withStatus($code)->withHeader('Content-Type', 'application/json');
        };

        $event = DB::table('gates_site_events')->where('slug', $slug)->first();
        if (!$event) return $fail($res, 404, 'That event could not be found.');

        // ── THROTTLED, BECAUSE OF WHAT ONE CALL COSTS ────────────────────────
        //
        // This is the most expensive public POST on the platform: a GD render at flier
        // dimensions, and on the photo path an image decode and a face-find, all in
        // request. CSRF gates it, which stops a script starting cold — but a token
        // harvested once from a page load is reusable for its lifetime, so CSRF is not a
        // rate limit, and it was the only thing here.
        //
        // ── BROWSER TIGHT, NETWORK LOOSE ─────────────────────────────────────
        //
        // Through Support\ClientIp, and NOT a bare REMOTE_ADDR, which is the whole reason
        // that class exists: this site is served through Cloudflare, so REMOTE_ADDR is an
        // edge address and every visitor on earth hashes to one fingerprint. A cap of
        // twenty on that is twenty fliers per hour FOR THE ENTIRE INTERNET — the exact
        // failure that told a supporter their network was suspicious on their first
        // attempt at a paid vote.
        //
        // The pair is what ClientIp prescribes and what this needs. The browser token is
        // per-session and cannot be shared by NAT, so it can be tight enough to mean
        // something. The network is a wide backstop only: Nigerian carriers run
        // large-scale NAT, and any IP cap tight enough to stop a script is tight enough to
        // block a stadium of real supporters.
        //
        // Both are generous because making a flier IS trying several shapes and styles and
        // reframing the crop, and every one of those is a render. A cap that punishes the
        // ordinary use of the feature defeats the feature.
        if ($this->rateLimit !== null) {
            $browser = \AfricaGates\Support\ClientIp::fingerprint(
                \AfricaGates\Support\ClientIp::browser($req), 'event_flier:browser'
            );
            if (!$this->rateLimit->check($browser, 'event_flier', 40, 3600)) {
                return $fail($res, 429, 'That is a lot of fliers. Give it a minute and try again.');
            }

            $ip = \AfricaGates\Support\ClientIp::from($req);
            if ($ip !== '' && !$this->rateLimit->check(
                    \AfricaGates\Support\ClientIp::fingerprint($ip, 'event_flier:network'),
                    'event_flier_net', 400, 3600)) {
                return $fail($res, 429, 'This network is making a lot of fliers right now. Try again shortly.');
            }
        }

        // A token when the browser has one — that is the confirmed path, and it is the only
        // way to be confirmed. Otherwise a typed name, which is the ungated path.
        $token = trim((string) ($b['t'] ?? ''));
        if ($token === '') {
            $name = \AfricaGates\Services\EventFlierToken::cleanName((string) ($b['name'] ?? ''));
            if ($name === '') return $fail($res, 422, 'Add your name and we will make the flier.');
            $token = \AfricaGates\Services\EventFlierToken::mint((int) $event->id, $name);
        }

        $f = \AfricaGates\Services\EventFlier::forToken(
            $token, \AfricaGates\Support\SiteUrl::base($req)
        );
        if ($f === null || trim((string) ($f['event']['slug'] ?? '')) !== $slug) {
            return $fail($res, 410, 'This event has finished, so there is no flier to make.');
        }

        // The photo, decoded and never written. Absent or unreadable is not an error: the
        // renderer draws `plain`, which is the design for that case rather than a fallback.
        $files = $req->getUploadedFiles();
        $photo = null;
        if (isset($files['photo']) && $files['photo'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['photo']->getError() === UPLOAD_ERR_OK) {
            $up = $files['photo'];
            $photo = \AfricaGates\Services\EventFlier::decodeUpload([
                'tmp_name' => (string) ($up->getStream()->getMetadata('uri') ?? ''),
                'size'     => (int) ($up->getSize() ?? 0),
                'error'    => $up->getError(),
            ]);
        }

        // ── OR AS BASE64, WHEN THE HOST WOULD NOT TAKE THE FILE PART ─────────
        //
        // Production answered this route **406** — a status this application returns from no
        // route, for any input, which makes it a request filter in front of PHP rather than
        // anything here. cPanel ships mod_security with `status:406` as its default deny and
        // its multipart rules are the ones an image upload trips. There is no shell on this
        // host, so that filter cannot be read or relaxed.
        //
        // The browser therefore has a second transport and this is where it lands. Both go
        // through the SAME decoder — see decodeBase64(), which writes a temp file and calls
        // decodeUpload() — so there is one set of guards, not two.
        if ($photo === null) {
            $b64 = (string) ($b['photo_b64'] ?? '');
            if ($b64 !== '') {
                $photo = \AfricaGates\Services\EventFlier::decodeBase64($b64);
            }
        }

        // Where the frame sits, 0..1 in each axis, from the reframe step. Absent means the
        // platform's own anchor, which is centred across and biased upward.
        $fx = array_key_exists('focus_x', $b) ? (float) $b['focus_x'] : null;
        $fy = array_key_exists('focus_y', $b) ? (float) $b['focus_y'] : null;

        // ── AND WHERE THE FACE IS, WHEN NOBODY DRAGGED ───────────────────────
        //
        // Resolved here as well as inside png() because the browser needs the answer: the
        // reframe screen opens with its handle on whatever the render used, so somebody who
        // presses "reposition" sees the frame they are looking at rather than a control that
        // has jumped to the middle of their photo.
        //
        // It is ONE function doing it in both places ({@see EventFlier::focus()}). Two
        // computations of where a crop sits is precisely how a preview comes to disagree with
        // the image it previews, and neither half looks wrong on its own.
        $fp = \AfricaGates\Services\EventFlier::focus($photo, $fx, $fy);

        // ── THE STYLE, AGAINST THE FORMAT THAT WILL BE DRAWN ─────────────────
        //
        // Resolved here, AFTER the photo is decoded, because the format falls back to `plain`
        // when no usable photo arrived and the styles a format can offer differ: `tint`
        // re-colours a photograph, so `plain` cannot draw it. Normalising against the
        // REQUESTED format would send back `X-Flier-Style: tint` on a `paper` image, and the
        // picker would then be describing something that is not on screen.
        //
        // Both calls go through EventFlier::format(), which is also what png() uses, so the
        // header and the pixels cannot disagree.
        $drawn = \AfricaGates\Services\EventFlier::format($fmt, $photo !== null);
        $style = \AfricaGates\Services\EventFlierTheme::style($b['style'] ?? null, $drawn);

        $png = (new \AfricaGates\Services\EventFlier())
            ->png($f, $fmt, null, $photo, $fp['x'], $fp['y'], $style);
        if ($photo !== null) imagedestroy($photo);

        if ($png === null) {
            return $fail($res, 503, 'The flier could not be generated on this server.');
        }

        $res->getBody()->write($png);

        return $res
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) strlen($png))
            // Never stored, never cached: the next press may carry a different crop.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            // The token the browser needs to re-share this as a LINK rather than a file, and
            // to regenerate in another format without retyping the name.
            ->withHeader('X-Flier-Token', $token)
            // Where the crop actually landed, so the reframe control can open on it. Sent
            // even when it came from the browser in the first place: echoing it back means
            // the client never has to decide whether the server honoured what it sent.
            // The style the render actually used, so the picker can correct itself when a
            // requested one was not available for the final format — a `tint` request whose
            // photo did not arrive comes back `paper`, and a control still showing `tint`
            // would be describing an image that is not on screen.
            ->withHeader('X-Flier-Style', $style)
            ->withHeader('X-Flier-Focus',
                ($fp['x'] === null ? '' : round($fp['x'], 4))
                . ',' . ($fp['y'] === null ? '' : round($fp['y'], 4)));
    }

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
