<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\{CacheService, OtpService, Notifier, WebhookService};

class EventsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ?OtpService $mailer = null,
    ) {}

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
        $tiers    = json_decode((string)($event['ticket_tiers'] ?? '[]'), true) ?: [];

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
            'early_bird'       => $earlyBird,
        ] + array_filter([
            'og_image'     => \AfricaGates\Support\Assets::absoluteOg($event['cover_image'] ?? null),
            'og_image_alt' => (string) $event['title'],
        ], fn($v) => $v !== null));
    }

    /** Store an on-platform RSVP. JSON in/out (Alpine), CSRF-protected via the public group. */
    public function register(Request $req, Response $res, array $args): Response
    {
        $json = function (array $payload, int $code = 200) use ($res): Response {
            $res->getBody()->write(json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };

        $slug  = (string)($args['slug'] ?? '');
        $event = DB::table('gates_site_events')
            ->where('slug', $slug)->where('status', 'published')->first();

        $data  = (array) $req->getParsedBody();
        $name  = trim((string)($data['name']  ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));

        if (!$event) {
            return $json(['success' => false, 'message' => 'That event no longer exists.'], 404);
        }
        if (((array)$event)['event_date'] < Carbon::now()->toDateTimeString()) {
            return $json(['success' => false, 'message' => 'Registration has closed for this event.']);
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $json(['success' => false, 'message' => 'Please enter your name and a valid email address.']);
        }
        // Phone is REQUIRED for event registrations — organisers need to reach
        // attendees with venue/joining details and last-minute changes.
        if (strlen((string) preg_replace('/\D+/', '', $phone)) < 7) {
            return $json(['success' => false, 'message' => 'Please enter a valid phone number.']);
        }
        // Capacity gate — refuse new RSVPs once the published cap is reached
        // (NULL capacity = unlimited). Free RSVPs, so a count check is sufficient.
        $ev  = (array) $event;
        $cap = ($ev['capacity'] ?? null) !== null ? (int) $ev['capacity'] : null;
        if ($cap !== null && (int) DB::table('gates_event_registrations')->where('event_id', $ev['id'])->count() >= $cap) {
            return $json(['success' => false, 'full' => true, 'message' => 'This event is fully booked — registration is closed.']);
        }

        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
        try {
            DB::table('gates_event_registrations')->insert([
                'event_id'   => ((array)$event)['id'],
                'name'       => mb_substr($name, 0, 160),
                'email'      => mb_substr($email, 0, 190),
                'phone'      => mb_substr($phone, 0, 40),
                'tier'       => mb_substr(trim((string)($data['tier'] ?? '')), 0, 80) ?: null,
                'ip_hash'    => $ip !== '' ? hash('sha256', $ip) : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // UNIQUE(event_id,email) → already registered; idempotent success.
            return $json(['success' => true, 'duplicate' => true, 'message' => 'You’re already on the list for this event.']);
        }

        // Notify subscribed integrations / AI agents (best-effort; never blocks RSVP).
        WebhookService::dispatch('event.registration', [
            'event'    => ['slug' => $slug, 'title' => (string) (((array) $event)['title'] ?? '')],
            'attendee' => ['name' => $name, 'email' => $email, 'phone' => $phone],
        ]);

        // Confirm to the attendee + alert the team (best-effort; never blocks RSVP).
        if ($this->mailer) {
            $ev    = (array)$event;
            $base  = rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
            $title = htmlspecialchars((string)($ev['title'] ?? 'the event'), ENT_QUOTES, 'UTF-8');
            $when  = htmlspecialchars((string)($ev['event_date'] ?? ''), ENT_QUOTES, 'UTF-8');
            $where = htmlspecialchars((string)($ev['location'] ?? ''), ENT_QUOTES, 'UTF-8');
            $nm    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $whereRow = $where !== '' ? "<br>Where: <strong>{$where}</strong>" : '';
            $html = "<p>Hi <strong>{$nm}</strong>,</p><p>You’re registered for <strong>{$title}</strong> — we’ve saved your spot.</p>"
                . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px\"><tr><td style=\"font-size:14px;color:#166534;line-height:1.7\">Event: <strong>{$title}</strong><br>When: <strong>{$when}</strong>{$whereRow}</td></tr></table>"
                . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$base}/events/{$slug}\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">View event details →</a></p>";
            $plain = "Hi {$name},\n\nYou’re registered for {$ev['title']}.\nWhen: {$ev['event_date']}\n\n{$base}/events/{$slug}\n\n— Africa GATES";
            try { $this->mailer->sendBranded($email, 'You’re registered — ' . (string)($ev['title'] ?? 'Africa GATES event'), $html, $plain, 'Events', $base . '/assets/img/illustrations/illo-trophy2.jpg'); } catch (\Throwable $e) {}
            Notifier::adminAlert($this->mailer, 'New event RSVP', "Event: " . (string)($ev['title'] ?? '') . "\nName: {$name}\nEmail: {$email}\nPhone: {$phone}");
        }

        return $json(['success' => true, 'message' => 'You’re registered! We’ve emailed you the details.']);
    }
}
