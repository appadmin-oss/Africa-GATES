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
use AfricaGates\Services\EventAgenda;
use AfricaGates\Services\EventDiscount;
use AfricaGates\Services\EventTicketDesign;
use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\EventWaitlist;
use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\Slug;

class EventsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly CacheService $cache,
        // Optional, and the waiting list works without it: a promotion exists in the database
        // whether or not the message got through, and the organiser's screen shows an
        // outstanding offer either way. See EventWaitlist::tell().
        private readonly ?\AfricaGates\Services\OtpService $mailer = null,
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
        // Sessions replace the run-of-show blob for anything with more than one room. Seeded
        // with the SAME stable-id trick as the other repeaters, and drafts included: an
        // organiser drafting an agenda must be able to see the rows they have not published.
        $sessionSeed = [];
        $j = 0;
        foreach ($id ? EventAgenda::sessions($id, true) : [] as $s) {
            $sessionSeed[] = [
                'id'    => ++$j,
                'sid'   => (int) $s['id'],
                'title' => (string) $s['title'],
                'body'  => (string) $s['description'],
                'from'  => self::forInput((string) ($s['starts_at'] ?? '')),
                'until' => self::forInput((string) ($s['ends_at'] ?? '')),
                'room'  => (string) $s['room'],
                'track' => (string) $s['track'],
                'who'   => implode(', ', $s['speakers']),
                'live'  => (bool) $s['published'],
            ];
        }

        return $this->view->render($res, 'admin/events/form.twig', [
            'page_title' => $id ? 'Edit Event — Admin' : 'New Event — Admin',
            'admin_page' => 'events',
            'row'        => $row,
            'is_new'     => !$id,
            'sched_seed' => $schedSeed,
            'tier_seed'  => $tierSeed,
            'session_seed' => $sessionSeed,
            // datetime-local wants its own format, and a raw timestamp in the box silently
            // renders as empty — which reads as "no cutoff set" and quietly removes one.
            'sales_close_input' => self::forInput((string) ($row['sales_close_at'] ?? '')),
            // Which of these columns the database actually has. On a deployment that has
            // uploaded this code and not yet run /__setup/migrate, showing the fields would
            // take an organiser's work and drop it silently on save.
            'extras_missing' => OptionalColumn::missing('gates_site_events', [
                'waitlist_open', 'sales_close_at', 'attendee_note', 'refund_policy',
                'organiser_email', 'organiser_phone',
            ]),
            // The ticket's appearance, same treatment: hidden until migrated rather than
            // shown and dropped.
            'design_missing' => OptionalColumn::missing('gates_site_events', [
                'ticket_accent', 'ticket_theme', 'ticket_image', 'ticket_note',
                'ticket_rows', 'ticket_show_qr',
            ]),
            // Resolved, not raw: the form shows the colour that WILL be used, so an
            // organiser is never looking at an empty box beside a ticket that is clearly
            // not colourless.
            'design'      => EventTicketDesign::forEvent($row ?: null),
            'design_rows' => EventTicketDesign::ROWS,
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
            // ── the organiser's own operating rules ──────────────────────────
            // Off unless ticked. A waiting list nobody works is worse than an honest
            // "fully booked", because it costs somebody hope as well as a seat.
            'waitlist_open'   => !empty($b['waitlist_open']) ? 1 : 0,
            // A cutoff independent of the event date — catering, badges, a venue list.
            'sales_close_at'  => self::fromInput($b['sales_close_at'] ?? ''),
            'attendee_note'   => trim((string) ($b['attendee_note'] ?? '')) ?: null,
            'refund_policy'   => mb_substr(trim((string) ($b['refund_policy'] ?? '')), 0, 1000) ?: null,
            'organiser_email' => mb_substr(trim((string) ($b['organiser_email'] ?? '')), 0, 190) ?: null,
            'organiser_phone' => mb_substr(trim((string) ($b['organiser_phone'] ?? '')), 0, 40) ?: null,
        ];
        // Dropped rather than written when the migration has not run. An operator uploads the
        // zip and runs /__setup/migrate as two separate acts, and a save that 500ed in
        // between would look like the editor breaking rather than a step being outstanding.
        $data = OptionalColumn::filter('gates_site_events', $data, [
            'waitlist_open', 'sales_close_at', 'attendee_note', 'refund_policy',
            'organiser_email', 'organiser_phone',
        ]);
        // The ticket's appearance. Validated in the service rather than here, because the
        // accent colour reaches a `style` attribute and the same value has to be checked
        // again on the way out — see EventTicketDesign's note on validating twice.
        //
        // Only merged when the panel was actually on screen: a save from a deployment that
        // has not migrated yet posts none of these fields, and treating that as "the
        // organiser cleared everything" would wipe a design somebody had already set.
        if (!empty($b['ticket_design_posted'])) {
            $data = array_merge($data, EventTicketDesign::fromForm($b));
        }
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
        $this->saveSessions($id, $b);
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
     * Turn the agenda repeater into session rows.
     *
     * The parallel-array shape matches every other repeater on this form, and the writing
     * itself is {@see EventAgenda::save()} — because the same upsert-by-id rule has to hold
     * whether a session arrives from this screen or, later, from an import.
     *
     * @param array<string,mixed> $b
     */
    private function saveSessions(int $eventId, array $b): void
    {
        // Absent, not empty. A form posted from a deployment whose migration has not run has
        // no session inputs at all, and treating that as "delete every session" would wipe an
        // agenda the moment somebody edited an unrelated field.
        if (!array_key_exists('ses_title', $b)) return;

        $titles = (array) $b['ses_title'];
        $ids    = (array) ($b['ses_id'] ?? []);
        $bodies = (array) ($b['ses_body'] ?? []);
        $from   = (array) ($b['ses_from'] ?? []);
        $until  = (array) ($b['ses_until'] ?? []);
        $rooms  = (array) ($b['ses_room'] ?? []);
        $tracks = (array) ($b['ses_track'] ?? []);
        $who    = (array) ($b['ses_who'] ?? []);
        $live   = (array) ($b['ses_live'] ?? []);

        $rows = [];
        foreach ($titles as $i => $title) {
            $rows[] = [
                'id'          => (int) ($ids[$i] ?? 0),
                'title'       => (string) $title,
                'description' => (string) ($bodies[$i] ?? ''),
                'starts_at'   => (string) ($from[$i] ?? ''),
                'ends_at'     => (string) ($until[$i] ?? ''),
                'room'        => (string) ($rooms[$i] ?? ''),
                'track'       => (string) ($tracks[$i] ?? ''),
                'speakers'    => (string) ($who[$i] ?? ''),
                'sort_order'  => $i * 10,
                'is_published'=> (string) ($live[$i] ?? '1') === '1' ? 1 : 0,
            ];
        }

        EventAgenda::save($eventId, $rows);
    }

    // ══ discount codes ═══════════════════════════════════════════════════════

    /**
     * The codes screen. Its own page rather than a block on the edit form.
     *
     * A code is created and retired on a completely different rhythm from an event's title
     * and venue — an organiser adds ALUMNI20 the afternoon they decide to, three weeks after
     * the event page was written, and making them re-save the whole event to do it is how a
     * description gets clobbered by a stale tab.
     */
    public function codes(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        return $this->view->render($res, 'admin/events/codes.twig', [
            'page_title' => 'Discount codes — ' . (string) $event->title,
            'admin_page' => 'events',
            'event'      => (array) $event,
            'codes'      => EventDiscount::forEvent($id),
            'tiers'      => EventTicketService::summary($id)['tiers'],
            'missing'    => OptionalColumn::missing('gates_event_registrations', ['discount_code']),
        ]);
    }

    /**
     * Create or edit one code.
     *
     * The letters are normalised to upper case here rather than at lookup time as well as:
     * `alumni20` and `ALUMNI20` are the same promise, and storing both would let two rows
     * exist for one code with the unique index none the wiser.
     */
    public function saveCode(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $b    = (array) $req->getParsedBody();
        $back = '/admin/events/' . $id . '/codes';

        $code = strtoupper(trim((string) ($b['code'] ?? '')));
        // Folded to A–Z0–9 and a dash: a code is read off a poster and typed by hand, and a
        // space or a curly apostrophe inside one is a support ticket waiting to happen.
        $code = (string) preg_replace('/[^A-Z0-9\-]+/', '', $code);
        if ($code === '') {
            $_SESSION['flash_error'] = 'A code needs some letters.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $kind   = (string) ($b['kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $amount = max(0, (int) ($b['amount'] ?? 0));
        if ($kind === 'percent' && $amount > 100) $amount = 100;
        if ($amount === 0) {
            $_SESSION['flash_error'] = 'A code that takes nothing off is not a discount.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // An empty tier list means every tier. A list means exactly those, which is the
        // control that stops a student discount applying to the ₦380,000 table.
        $tierIds = array_values(array_filter(array_map('intval', (array) ($b['tier_ids'] ?? []))));

        $row = [
            'event_id'      => $id,
            'code'          => mb_substr($code, 0, 40),
            'label'         => mb_substr(trim((string) ($b['label'] ?? '')), 0, 120) ?: null,
            'kind'          => $kind,
            'amount'        => $amount,
            'tier_ids'      => $tierIds !== [] ? json_encode($tierIds) : null,
            'max_uses'      => trim((string) ($b['max_uses'] ?? '')) !== '' ? max(1, (int) $b['max_uses']) : null,
            'max_per_email' => max(1, (int) ($b['max_per_email'] ?? 1)),
            'starts_at'     => self::fromInput($b['starts_at'] ?? ''),
            'ends_at'       => self::fromInput($b['ends_at'] ?? ''),
            'is_active'     => !empty($b['is_active']) ? 1 : 0,
            'updated_at'    => Carbon::now()->toDateTimeString(),
        ];

        $codeId = (int) ($b['code_id'] ?? 0);
        try {
            if ($codeId > 0 && DB::table('gates_event_codes')->where('id', $codeId)
                    ->where('event_id', $id)->exists()) {
                // `used_count` is deliberately not in $row: it is the record of what has
                // happened, not a setting, and letting a save reset it would hand an
                // exhausted code back its whole allowance.
                DB::table('gates_event_codes')->where('id', $codeId)->update($row);
                $_SESSION['flash_ok'] = 'Code ' . $code . ' updated.';
            } else {
                $row['created_at'] = $row['updated_at'];
                $codeId = (int) DB::table('gates_event_codes')->insertGetId($row);
                $_SESSION['flash_ok'] = 'Code ' . $code . ' created.';
            }
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.code.save', 'event_code', $codeId);
        } catch (\Throwable $e) {
            error_log('[event] could not save code ' . $code . ': ' . $e->getMessage());
            $_SESSION['flash_error'] = 'That code could not be saved — this event may already have one '
                                     . 'with those letters.';
        }

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Retire a code.
     *
     * Deactivated rather than deleted once anything has been bought against it: the
     * registrations carry the letters, and a receipt whose code no longer exists anywhere is
     * a number nobody can explain six months later at a reconciliation.
     */
    public function deleteCode(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $codeId = (int) ($args['code'] ?? 0);
        $back   = '/admin/events/' . $id . '/codes';

        $row = DB::table('gates_event_codes')->where('id', $codeId)->where('event_id', $id)->first();
        if (!$row) {
            $_SESSION['flash_error'] = 'That code could not be found on this event.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $used = (int) DB::table('gates_event_registrations')->where('event_id', $id)
            ->whereRaw('UPPER(discount_code) = ?', [strtoupper((string) $row->code)])->count();

        if ($used > 0) {
            DB::table('gates_event_codes')->where('id', $codeId)->update(['is_active' => 0]);
            $_SESSION['flash_ok'] = (string) $row->code . ' has been switched off. It is kept because '
                . $used . ' booking(s) were made with it, and their receipts name it.';
        } else {
            DB::table('gates_event_codes')->where('id', $codeId)->delete();
            $_SESSION['flash_ok'] = (string) $row->code . ' deleted.';
        }
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.code.delete', 'event_code', $codeId);

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    // ══ the waiting list ═════════════════════════════════════════════════════

    /**
     * Offer the seats that have come back to the people who have been waiting longest.
     *
     * Deliberately a BUTTON rather than something the cron does on its own. Promoting sends
     * mail to real people about a seat that is held for them for a fixed number of hours, and
     * an organiser who has just cancelled ten bookings to move a venue needs to decide when
     * that goes out — not discover it went out four minutes later.
     */
    public function promote(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $back   = '/admin/events/' . $id . '/tickets';
        $tierId = (int) (((array) $req->getParsedBody())['tier_id'] ?? 0);

        $tier = EventTicketService::tier($tierId);
        if (!$tier || (int) $tier->event_id !== $id) {
            $_SESSION['flash_error'] = 'That ticket type is not on this event.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // Offers nobody took are returned to the queue FIRST, so the seats they were holding
        // are counted as free by the promotion that follows in the same press.
        $expired = EventWaitlist::expireOffers();
        $r = EventWaitlist::promote($tierId, 20, $this->mailer);

        $_SESSION[$r['offered'] > 0 ? 'flash_ok' : 'flash_error'] = $r['message']
            . ($expired > 0 ? ' ' . $expired . ' unclaimed offer(s) went back to the queue first.' : '');
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.waitlist.promote', 'event_tier', $tierId);

        return $res->withHeader('Location', $back)->withStatus(302);
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
            'summary'    => EventTicketService::summary($id),
            // ── DOOR PASSES ──────────────────────────────────────────────
            //
            // Created here rather than on a settings screen because a door belongs to an
            // EVENT, and the person who needs one is already on this page counting seats an
            // hour before the gates open.
            'passes'      => \AfricaGates\Services\EventScanPass::forEvent($id),
            'door_live'   => \AfricaGates\Services\EventScanPass::anyOpen($id),
            // Shown once, immediately after creation, and never again — only the hash is
            // stored. Read-and-cleared so a refresh does not leave a live credential sitting
            // on a screen in an office.
            'new_pass'    => $this->takeNewPass(),
            'door_hours'  => \AfricaGates\Services\EventScanPass::DEFAULT_HOURS,
            'attendees'  => EventTicketService::attendees($id, $status),
            'filter'     => $status,
            'hold_minutes' => EventTicketService::HOLD_MINUTES,
            // The queue, and the outstanding offers — two different things an organiser has
            // to be able to tell apart. A waitlisted row is somebody hoping; an offered row
            // is a seat currently being held for somebody who has not answered yet.
            'queue'       => EventWaitlist::forEvent($id),
            'offers'      => $this->outstandingOffers($id),
            'waitlist_open' => (int) ($event->waitlist_open ?? 0) === 1,
            'offer_hours' => EventWaitlist::OFFER_HOURS,
            'code_count'  => count(EventDiscount::forEvent($id)),
            // Hide the seat boxes rather than offer a field that cannot store what is typed
            // into it. An organiser labelling thirty tables and then finding none of it saved
            // is a worse outcome than not seeing the feature until the migration has run.
            'seat_missing' => OptionalColumn::missing('gates_event_registrations', ['seat_label']) !== [],
        ]);
    }

    /**
     * Mark somebody as arrived, by ticket code.
     *
     * By CODE rather than by row id, because the person doing this is holding a phone at a
     * door and reading a code off an attendee's screen — not scrolling a list of four
     * hundred names looking for a button.
     */
    /**
     * Check somebody in from the admin screen.
     *
     * The DECISION moved to {@see EventTicketService::checkIn()} so the door pass — worked by
     * volunteers with no account — reaches exactly the same rules. Two implementations of "is
     * this ticket good" would disagree at the one moment there is a queue.
     */
    public function checkIn(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $code = (string) (((array) $req->getParsedBody())['code'] ?? '');
        $back = '/admin/events/' . $id . '/tickets';
        $admin = (int) ($_SESSION['admin_id'] ?? 0);

        $v = \AfricaGates\Services\EventTicketService::checkIn($code, $id, 'admin #' . $admin, $admin);

        if ($v['verdict'] === 'admit') {
            $_SESSION['flash_ok'] = 'Checked in: ' . $v['name']
                . ($v['seats'] > 1 ? ' (' . $v['seats'] . ' seats)' : '') . '.';
            $this->audit->record($admin, 'event.check_in', 'event_registration', null);
        } else {
            // A duplicate is not a success and not quite a failure, but on a redirect-and-flash
            // screen there are only two channels — and of the two, the one that makes somebody
            // look is right for a code presented twice.
            $_SESSION['flash_error'] = $v['title'] . ($v['detail'] !== '' ? ' — ' . $v['detail'] : '');
        }

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    // ══ DOOR PASSES ══════════════════════════════════════════════════════════

    /** Read-and-clear the freshly minted token, so a refresh does not re-display it. */
    private function takeNewPass(): string
    {
        $t = (string) ($_SESSION['new_door_pass'] ?? '');
        unset($_SESSION['new_door_pass']);
        return $t;
    }

    /**
     * POST /admin/events/{id}/door — mint a scanning link.
     *
     * The token is put in the session and shown exactly once on the next render. Only its
     * SHA-256 is stored, so there is no second chance to read it — which is deliberate, and
     * is why the screen says so beside it.
     */
    public function issueDoorPass(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $b    = (array) $req->getParsedBody();
        $back = '/admin/events/' . $id . '/tickets';

        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $token = \AfricaGates\Services\EventScanPass::issue(
            $id,
            (string) ($b['closes_at'] ?? ''),
            (string) ($b['opens_at'] ?? ''),
            trim((string) ($b['label'] ?? '')),
            (int) ($_SESSION['admin_id'] ?? 0)
        );

        if ($token === null) {
            // The one mistake worth naming: a window that closes before it opens can never
            // admit anybody, and the form is the cheapest place to catch it.
            $_SESSION['flash_error'] = 'That door pass could not be created. Check that the '
                . 'closing time is after the opening time.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $_SESSION['new_door_pass'] = $token;
        $_SESSION['flash_ok'] = 'Door pass created. Copy the link now — it is not shown again.';
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.door_pass.issue', 'event', $id);

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** POST /admin/events/{id}/door/revoke — turn one off immediately. */
    public function revokeDoorPass(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $passId = (int) (((array) $req->getParsedBody())['pass_id'] ?? 0);
        $back   = '/admin/events/' . $id . '/tickets';

        if (\AfricaGates\Services\EventScanPass::revoke($passId, $id)) {
            $_SESSION['flash_ok'] = 'That scanning link stopped working immediately.';
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.door_pass.revoke', 'event', $id);
        } else {
            $_SESSION['flash_error'] = 'That pass could not be revoked — it may already be off.';
        }

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Take somebody off the list, on purpose.
     *
     * The other half of the waiting list. Seats come back because somebody says they cannot
     * come — and until now there was no way to act on that: the seat stayed gone, the room
     * read as full, and the queue had nothing to be promoted into.
     */
    public function releaseSeat(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $back = '/admin/events/' . $id . '/tickets';
        $b    = (array) $req->getParsedBody();
        $regId = (int) ($b['reg_id'] ?? 0);

        $reg = DB::table('gates_event_registrations')->where('id', $regId)->first();
        if (!$reg || (int) $reg->event_id !== $id) {
            $_SESSION['flash_error'] = 'That registration is not on this event.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $r = EventTicketService::release($regId, trim((string) ($b['why'] ?? '')),
                                        (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.seat.release',
                                 'event_registration', $regId);
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Write a seat or table label onto one registration.
     *
     * Per attendee rather than per event, because that is what it is: "Table 12" belongs to
     * the four people sitting at it and not to the gala. Free text and not an integer,
     * because organisers use "Table 12", "Row C Seat 7" and "Balcony left" interchangeably,
     * and a schema that insists on a number gets "12" typed into some other column instead.
     *
     * Clearing it is a legitimate save, so an empty box writes NULL rather than being read as
     * "no change" — otherwise a seat assigned by mistake could never be taken off.
     */
    public function seat(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $back  = '/admin/events/' . $id . '/tickets';
        $b     = (array) $req->getParsedBody();
        $regId = (int) ($b['reg_id'] ?? 0);

        $reg = DB::table('gates_event_registrations')->where('id', $regId)->first();
        if (!$reg || (int) $reg->event_id !== $id) {
            // Checked against the event in the URL, not just the row id: without it, an
            // organiser with rights to one event could relabel a seat on another.
            $_SESSION['flash_error'] = 'That registration is not on this event.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($b['seat_label'] ?? ''))), 0, 60);
        $data  = OptionalColumn::filter('gates_event_registrations',
                                       ['seat_label' => $label !== '' ? $label : null],
                                       ['seat_label']);

        if ($data === []) {
            // The column is not there yet. Said out loud rather than silently succeeding —
            // an organiser who typed a table number and saw "Saved" would find out at the
            // door that it was never stored.
            $_SESSION['flash_error'] = 'Seat labels need one more step: run /__setup/migrate.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        DB::table('gates_event_registrations')->where('id', $regId)->update($data);
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.seat.label',
                             'event_registration', $regId);
        $_SESSION['flash_ok'] = $label !== ''
            ? 'Seat set to ' . $label . ' for ' . (string) $reg->name . '.'
            : 'Seat cleared for ' . (string) $reg->name . '.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Seats currently held by a waitlist offer nobody has answered.
     *
     * Separated from the ordinary pending list because the two need different actions: an
     * ordinary pending row is somebody mid-checkout, an offered one is a seat an organiser
     * has promised and may need to chase before it expires.
     *
     * @return list<array<string,mixed>>
     */
    private function outstandingOffers(int $eventId): array
    {
        try {
            return DB::table('gates_event_registrations')
                ->where('event_id', $eventId)->where('status', 'pending')
                ->whereNotNull('offered_at')
                ->orderBy('offer_expires_at')
                ->get()->map(static fn ($r): array => (array) $r)->all();
        } catch (\Throwable) { return []; }
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
        fputcsv($out, ['Name', 'Email', 'Phone', 'Ticket', 'Seat', 'Seats', 'Status',
                       'Amount (NGN)', 'Discount code', 'Discount (NGN)',
                       'Ticket code', 'Reference', 'Registered', 'Waitlisted',
                       'Offered', 'Offer expires', 'Checked in']);
        foreach (EventTicketService::attendees($id, '', 5000) as $a) {
            fputcsv($out, [
                $a['name'] ?? '', $a['email'] ?? '', $a['phone'] ?? '',
                $a['tier'] ?? '',
                // The seat label, next to the tier rather than at the far right: a printed
                // seating plan is read across the row, and a column nobody scrolls to is a
                // column that gets retyped by hand instead.
                $a['seat_label'] ?? '',
                $a['quantity'] ?? 1, $a['status'] ?? '',
                $a['amount_naira'] ?? 0,
                $a['discount_code'] ?? '', $a['discount_naira'] ?? '',
                $a['ticket_code'] ?? '', $a['reference'] ?? '',
                $a['created_at'] ?? '', $a['waitlist_at'] ?? '',
                $a['offered_at'] ?? '', $a['offer_expires_at'] ?? '',
                $a['checked_in_at'] ?? '',
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
