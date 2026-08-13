<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\CacheService;

class EventsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly CacheService $cache,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_site_events')->orderByDesc('event_date')->get()->map(fn($r)=>(array)$r)->all();
        return $this->view->render($res, 'admin/events/index.twig', [
            'page_title' => 'Events — Admin',
            'admin_page' => 'events',
            'rows'       => $rows,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_site_events')->where('id', $id)->first() : [];
        // Seeds for the Alpine repeaters (stable ids → editable rows without the focus bug).
        $schedSeed = [];
        foreach ((json_decode((string)($row['schedule'] ?? '[]'), true) ?: []) as $i => $s) {
            $schedSeed[] = ['id' => $i + 1, 'time' => (string)($s['time'] ?? ''), 'title' => (string)($s['title'] ?? ''), 'body' => (string)($s['body'] ?? '')];
        }
        // Tiers come from the TABLE now. The old `ticket_tiers` JSON is only read when the
        // table has none for this event, which happens for the minutes between an operator
        // uploading this code and running /__setup/migrate — on this deployment those are
        // two separate acts, and an editor that lost their tiers in between would be the
        // upgrade eating their work.
        $tierSeed = [];
        $i = 0;
        if ($id) {
            foreach (DB::table('gates_event_tiers')->where('event_id', $id)
                        ->orderBy('sort_order')->orderBy('id')->get() as $t) {
                $tierSeed[] = [
                    'id'     => ++$i,
                    'tid'    => (int) $t->id,
                    'name'   => (string) $t->name,
                    'price'  => (int) $t->price_naira,
                    'cap'    => $t->capacity !== null ? (string) (int) $t->capacity : '',
                    'perk'   => (string) ($t->description ?? ''),
                    'from'   => self::forInput((string) ($t->sale_starts_at ?? '')),
                    'until'  => self::forInput((string) ($t->sale_ends_at ?? '')),
                    'code'   => (string) ($t->access_code ?? ''),
                    'active' => (int) ($t->is_active ?? 1) === 1,
                    // Shown beside the row so an organiser can see why a tier refuses to be
                    // deleted, and how close it is to its own limit.
                    'sold'   => \AfricaGates\Services\EventTicketService::sold((int) $t->id),
                ];
            }
        }
        if ($tierSeed === []) {
            foreach ((json_decode((string)($row['ticket_tiers'] ?? '[]'), true) ?: []) as $t) {
                if (!is_array($t)) continue;
                $tierSeed[] = ['id' => ++$i, 'tid' => 0, 'name' => (string)($t['name'] ?? ''),
                               'price' => (int) preg_replace('/\D+/', '', (string) ($t['price'] ?? '0')),
                               'cap' => '', 'perk' => (string)($t['perk'] ?? ''),
                               'from' => '', 'until' => '', 'code' => '', 'active' => true, 'sold' => 0];
            }
        }
        return $this->view->render($res, 'admin/events/form.twig', [
            'page_title' => $id ? 'Edit Event — Admin' : 'New Event — Admin',
            'admin_page' => 'events',
            'row'        => $row,
            'is_new'     => !$id,
            'sched_seed' => $schedSeed,
            'tier_seed'  => $tierSeed,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', strtolower(trim((string)($b['slug'] ?: $b['title'] ?? ''))));

        // Run-of-show: parallel arrays from the repeater → JSON [{time,title,body}].
        $schedule = [];
        $sTitle = (array)($b['sched_title'] ?? []); $sTime = (array)($b['sched_time'] ?? []); $sBody = (array)($b['sched_body'] ?? []);
        foreach ($sTitle as $i => $title) {
            $title = trim((string)$title);
            if ($title === '') continue;
            $schedule[] = ['time' => mb_substr(trim((string)($sTime[$i] ?? '')), 0, 40), 'title' => mb_substr($title, 0, 160), 'body' => mb_substr(trim((string)($sBody[$i] ?? '')), 0, 300)];
        }

        $data = [
            'slug'        => trim($slug, '-'),
            'title'       => trim((string)($b['title'] ?? '')),
            'tagline'     => trim((string)($b['tagline'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'location'    => trim((string)($b['location'] ?? '')),
            'venue'       => trim((string)($b['venue'] ?? '')),
            'event_date'  => (string)($b['event_date'] ?: Carbon::now()->toDateTimeString()),
            'end_date'    => $b['end_date'] ?: null,
            'cover_image' => trim((string)($b['cover_image'] ?? '')),
            'rsvp_url'    => trim((string)($b['rsvp_url'] ?? '')),
            'status'      => in_array($b['status'] ?? '', ['published','draft'], true) ? $b['status'] : 'draft',
            'capacity'    => trim((string)($b['capacity'] ?? '')) !== '' ? max(0, (int)$b['capacity']) : null,
            'schedule'            => $schedule ? json_encode($schedule) : null,
            'map_embed'           => trim((string)($b['map_embed'] ?? '')) ?: null,
            'early_bird_text'     => trim((string)($b['early_bird_text'] ?? '')) ?: null,
            'early_bird_deadline' => trim((string)($b['early_bird_deadline'] ?? '')) ?: null,
            'early_bird_url'      => trim((string)($b['early_bird_url'] ?? '')) ?: null,
        ];
        if ($data['title'] === '' || $data['slug'] === '') {
            $_SESSION['flash_error'] = 'Title and slug are required.';
            return $res->withHeader('Location', $id ? "/admin/events/{$id}" : '/admin/events/new')->withStatus(302);
        }
        if ($id) {
            DB::table('gates_site_events')->where('id', $id)->update($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'event.update', 'site_event', $id);
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int)DB::table('gates_site_events')->insertGetId($data);
            $this->audit->record((int)$_SESSION['admin_id'], 'event.create', 'site_event', $id);
        }

        $this->saveTiers($id, $b);
        $this->cache->forget('events:upcoming');
        $this->cache->forget('events:past');
        $this->cache->forget('home:site_events');
        $_SESSION['flash_ok'] = 'Event saved.';
        return $res->withHeader('Location', '/admin/events')->withStatus(302);
    }

    /** `2026-01-31 19:00:00` → `2026-01-31T19:00`, which is what datetime-local wants. */
    private static function forInput(string $stamp): string
    {
        $stamp = trim($stamp);
        if ($stamp === '') return '';
        try { return Carbon::parse($stamp)->format('Y-m-d\TH:i'); }
        catch (\Throwable) { return ''; }
    }

    /** `2026-01-31T19:00` → a database timestamp, or null for an empty box. */
    private static function fromInput(mixed $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        try { return Carbon::parse($raw)->toDateTimeString(); }
        catch (\Throwable) { return null; }
    }

    /**
     * Write the tier rows the form submitted.
     *
     * ── A TIER IS NEVER DELETED ONCE ANYTHING IS SOLD AGAINST IT ─────────────
     *
     * Removing a row from the repeater deactivates the tier rather than deleting it, when
     * somebody has bought one. A ticket points at its tier for its name, its price and the
     * count that decides whether the room is full, and hard-deleting the row would leave
     * paying attendees attached to nothing — the door screen would show them with no ticket
     * type, and every "how many did we sell at that price" answer would change retroactively.
     *
     * A tier nobody has bought IS deleted, because that is a mistake being corrected rather
     * than history being rewritten, and leaving a graveyard of inactive typos on the screen
     * is how the screen becomes unreadable.
     *
     * @param array<string,mixed> $b the submitted form body
     */
    private function saveTiers(int $eventId, array $b): void
    {
        $names = (array) ($b['tier_name'] ?? []);
        if ($names === []) return;

        $ids    = (array) ($b['tier_id'] ?? []);
        $prices = (array) ($b['tier_price'] ?? []);
        $caps   = (array) ($b['tier_capacity'] ?? []);
        $perks  = (array) ($b['tier_perk'] ?? []);
        $from   = (array) ($b['tier_from'] ?? []);
        $until  = (array) ($b['tier_until'] ?? []);
        $codes  = (array) ($b['tier_code'] ?? []);
        $active = (array) ($b['tier_active'] ?? []);

        $now  = Carbon::now()->toDateTimeString();
        $kept = [];
        $order = 0;

        foreach ($names as $i => $rawName) {
            $name = trim((string) $rawName);
            if ($name === '') continue;

            // Slug::make(), not a regex: this platform is full of names carrying accents and
            // a bare character class deletes them instead of folding them, so "Ìbàdàn" would
            // become "bdn". SlugTest enforces that there is only one slug builder left.
            $slug = \AfricaGates\Support\Slug::make($name, 60) ?: 'tier';
            // Two rows can slugify the same ("VIP" and "V.I.P."), and the unique index would
            // reject the second — losing an organiser's tier with no message. Suffix instead.
            $try = $slug; $n = 2;
            while (in_array($try, array_column($kept, 'slug'), true)) $try = mb_substr($slug, 0, 55) . '-' . $n++;
            $slug = $try;

            $row = [
                'event_id'    => $eventId,
                'slug'        => $slug,
                'name'        => mb_substr($name, 0, 120),
                'description' => mb_substr(trim((string) ($perks[$i] ?? '')), 0, 500) ?: null,
                'price_naira' => max(0, (int) ($prices[$i] ?? 0)),
                // '' is "no limit" and 0 is "none available" — two different intentions that
                // an intval() would flatten into the same closed tier.
                'capacity'    => trim((string) ($caps[$i] ?? '')) !== '' ? max(0, (int) $caps[$i]) : null,
                'sale_starts_at' => self::fromInput($from[$i] ?? ''),
                'sale_ends_at'   => self::fromInput($until[$i] ?? ''),
                'access_code' => mb_substr(trim((string) ($codes[$i] ?? '')), 0, 60) ?: null,
                'is_active'   => (string) ($active[$i] ?? '1') === '1' ? 1 : 0,
                'sort_order'  => $order++,
                'updated_at'  => $now,
            ];

            $tid = (int) ($ids[$i] ?? 0);
            try {
                if ($tid > 0 && DB::table('gates_event_tiers')->where('id', $tid)
                        ->where('event_id', $eventId)->exists()) {
                    DB::table('gates_event_tiers')->where('id', $tid)->update($row);
                } else {
                    $row['created_at'] = $now;
                    $tid = (int) DB::table('gates_event_tiers')->insertGetId($row);
                }
                $kept[] = ['id' => $tid, 'slug' => $slug];
            } catch (\Throwable $e) {
                error_log('[event] could not save tier "' . $name . '": ' . $e->getMessage());
            }
        }

        // Anything the form no longer lists.
        try {
            $gone = DB::table('gates_event_tiers')->where('event_id', $eventId)
                ->whereNotIn('id', array_column($kept, 'id') ?: [0])->get();
            foreach ($gone as $t) {
                $sold = DB::table('gates_event_registrations')->where('tier_id', (int) $t->id)
                    ->whereIn('status', ['confirmed', 'pending'])->count();
                if ($sold > 0) {
                    DB::table('gates_event_tiers')->where('id', (int) $t->id)
                        ->update(['is_active' => 0, 'updated_at' => $now]);
                } else {
                    DB::table('gates_event_tiers')->where('id', (int) $t->id)->delete();
                }
            }
        } catch (\Throwable $e) {
            error_log('[event] could not tidy removed tiers: ' . $e->getMessage());
        }

        // The old JSON blob is the PRE-MIGRATION fallback the public page reads when the
        // table has nothing. Now that it does, a stale blob would resurface the moment an
        // organiser deactivated every tier — showing prices nobody can buy.
        if ($kept !== []) {
            try { DB::table('gates_site_events')->where('id', $eventId)->update(['ticket_tiers' => null]); }
            catch (\Throwable) {}
        }
    }

    /**
     * Who is coming, and what each tier has sold.
     *
     * Its own screen rather than a block on the edit form: an organiser looking at their
     * door list is doing a different job from one editing a description, and on the morning
     * of an event this is the only page they want.
     */
    public function tickets(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $status = (string) ($req->getQueryParams()['status'] ?? '');

        return $this->view->render($res, 'admin/events/tickets.twig', [
            'page_title' => 'Tickets — ' . (string) $event->title,
            'admin_page' => 'events',
            'event'      => (array) $event,
            'summary'    => \AfricaGates\Services\EventTicketService::summary($id),
            'attendees'  => \AfricaGates\Services\EventTicketService::attendees($id, $status),
            'filter'     => $status,
            'hold_minutes' => \AfricaGates\Services\EventTicketService::HOLD_MINUTES,
        ]);
    }

    /**
     * Mark somebody as arrived, by ticket code.
     *
     * By CODE rather than by row id, because the person doing this is holding a phone at a
     * door and reading a code off an attendee's screen — not scrolling a list of four
     * hundred names looking for a button.
     */
    public function checkIn(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $code = strtoupper(trim((string) (((array) $req->getParsedBody())['code'] ?? '')));
        $back = '/admin/events/' . $id . '/tickets';

        $reg = \AfricaGates\Services\EventTicketService::byTicketCode($code);

        if (!$reg || (int) $reg->event_id !== $id) {
            $_SESSION['flash_error'] = 'No ticket for this event has the code ' . ($code !== '' ? $code : '—') . '.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        if ((string) $reg->status !== 'confirmed') {
            $_SESSION['flash_error'] = 'That ticket is "' . (string) $reg->status . '", not confirmed. '
                . ((string) $reg->status === 'pending'
                    ? 'The payment has not reached us — do not admit on this alone.'
                    : 'It was cancelled.');
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        if (($reg->checked_in_at ?? null) !== null) {
            // Said plainly rather than silently accepted a second time: the same code used
            // twice is either a friend sharing a screenshot or a mistake, and both want a
            // person to look up.
            $_SESSION['flash_error'] = (string) $reg->name . ' was already checked in at '
                . (string) $reg->checked_in_at . '.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        DB::table('gates_event_registrations')->where('id', (int) $reg->id)
            ->whereNull('checked_in_at')
            ->update(['checked_in_at' => Carbon::now()->toDateTimeString(),
                      'checked_in_by' => (int) ($_SESSION['admin_id'] ?? 0) ?: null]);

        $seats = (int) ($reg->quantity ?? 1);
        $_SESSION['flash_ok'] = 'Checked in: ' . (string) $reg->name
            . ($seats > 1 ? ' (' . $seats . ' seats)' : '') . '.';
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.check_in', 'event_registration', (int) $reg->id);

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** The door list as CSV, because on the day it is printed or opened on a laptop. */
    public function exportAttendees(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Name', 'Email', 'Phone', 'Ticket', 'Seats', 'Status',
                       'Amount (NGN)', 'Ticket code', 'Reference', 'Registered', 'Checked in']);
        foreach (\AfricaGates\Services\EventTicketService::attendees($id, '', 5000) as $a) {
            fputcsv($out, [
                $a['name'] ?? '', $a['email'] ?? '', $a['phone'] ?? '',
                $a['tier'] ?? '', $a['quantity'] ?? 1, $a['status'] ?? '',
                $a['amount_naira'] ?? 0, $a['ticket_code'] ?? '', $a['reference'] ?? '',
                $a['created_at'] ?? '', $a['checked_in_at'] ?? '',
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $name = preg_replace('/[^a-z0-9\-]+/i', '-', (string) $event->slug) . '-attendees.csv';
        $res->getBody()->write($csv);
        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
                   ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        DB::table('gates_site_events')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'event.delete', 'site_event', $id);
        $this->cache->forget('events:upcoming');
        $this->cache->forget('events:past');
        $this->cache->forget('home:site_events');
        $_SESSION['flash_ok'] = 'Event deleted.';
        return $res->withHeader('Location', '/admin/events')->withStatus(302);
    }
}
