<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Support\ColumnRange;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\CacheService;
use AfricaGates\Services\EventAgenda;
use AfricaGates\Services\EventDiscount;
use AfricaGates\Services\EventTicketDesign;
use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\EventWaitlist;
use AfricaGates\Services\TicketArtwork;
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
        // Optional and constructed on demand, like the mailer above. It is here so a test can
        // hand in one pointed at a temporary directory: without that, exercising the artwork
        // path would mean writing into the real public/uploads tree and leaving it there.
        private readonly ?UploadService $uploads = null,
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
                    // The palette SLOT, '' for "no colour on this tier" — which is the
                    // default and a real answer. See the colour field's note in the form.
                    'colour' => (string) (\AfricaGates\Services\EventTierPalette::slot($t->colour ?? null) ?? ''),
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
                               'colour' => '',
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

        // Asked once. It reads the schema and, when the table is absent, the migration
        // ledger — neither of which should be done twice to answer one question.
        $linkBlocker = \AfricaGates\Services\EventInvites::linkBlocker();

        return $this->view->render($res, 'admin/events/form.twig', [
            'page_title' => $id ? 'Edit Event — Admin' : 'New Event — Admin',
            'admin_page' => 'events',
            'row'        => $row,
            'is_new'     => !$id,
            // The live rate, so the checkbox beside it states the real number rather than
            // a hardcoded "10%" that would start lying the day an admin changes it.
            'referral_rate_pct' => \AfricaGates\Services\ReferralService::ratePct(),
            // The zones an event can be held in. Africa in full plus the handful of
            // places this platform's diaspora events actually run — the same list the
            // platform's own timezone setting offers, because two lists would drift.
            'tz_choices' => \AfricaGates\Support\DisplayTime::choices(),
            'sched_seed' => $schedSeed,
            'tier_seed'  => $tierSeed,
            // ── THE TIER COLOUR PICKER ───────────────────────────────────────
            //
            // The six slots with their resolved hexes for THIS event, so an organiser
            // choosing "Warm" is shown what warm actually is against their own accent
            // rather than a word. Resolved live: change the accent above and the swatches
            // move with it on the next load, which is the whole reason the column stores a
            // slot instead of a hex.
            'tier_palette' => \AfricaGates\Services\EventTierPalette::forEvent($row ?: null),
            'tier_slots'   => \AfricaGates\Services\EventTierPalette::SLOTS,
            // Hidden rather than shown-and-dropped on a deployment that has not migrated.
            'tier_colour_missing' => \AfricaGates\Support\OptionalColumn::missing(
                'gates_event_tiers', ['colour']
            ),
            'session_seed' => $sessionSeed,
            // The awards this event is the ceremony for. PLURAL: one gala night hands out
            // several, and a single-select would force an operator to run four events for
            // one evening or leave three shortlists uninvited. Drives the invitation run —
            // see Admin\Controllers\InvitesController.
            'programmes' => DB::table('gates_award_programmes')->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('title')->get(['id', 'title'])
                ->map(fn ($r) => (array) $r)->all(),
            'programme_ids' => array_map(
                static fn (object $p): int => (int) $p->id,
                \AfricaGates\Services\EventInvites::programmesFor((int) ($row['id'] ?? 0))
            ),
            // The blocker itself, not a boolean. This screen used to be handed a yes/no
            // and print its own hard-coded sentence telling the operator to run
            // /__setup/migrate — which is the one instruction that cannot work when the
            // step is recorded as applied and the table is absent, and it went on saying
            // it after they had done it. EventInvites::linkBlocker() knows which of the
            // two faults it is and whether a repair is available; both screens read it.
            'programme_link'       => $linkBlocker,
            'programme_link_ready' => $linkBlocker === null,
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
            // The enforceable half of the refund policy, hidden until migrated for the same
            // reason as the rest — an organiser setting "50% up to 48 hours" and having it
            // silently dropped would be worse than not offering it.
            'refund_missing' => OptionalColumn::missing('gates_site_events', [
                'self_cancel', 'refund_mode', 'refund_percent', 'refund_cutoff_hours',
            ]),
            'refund_modes'   => \AfricaGates\Services\EventRefundPolicy::MODES,
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
            // The artwork editor's own two columns, on their own migration. Without them the
            // template falls back to the address box the field has always been, so a
            // deployment that has not migrated keeps working instead of losing the field.
            'artwork_missing' => OptionalColumn::missing('gates_site_events', [
                'ticket_image_src', 'ticket_image_edit',
            ]),
            // The ORIGINAL, not the baked crop. The editor has to show the whole picture for
            // the frame to be draggable — handing it the finished 3:2 file would let somebody
            // crop a crop, which is the thing TicketArtwork exists to prevent.
            'artwork_src'    => EventTicketDesign::image(['ticket_image' => (string) ($row['ticket_image_src'] ?? '')]),
            'artwork_edit'   => TicketArtwork::recipe($row['ticket_image_edit'] ?? null),
            'artwork_ratio'  => TicketArtwork::W / TicketArtwork::H,
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

        // Validated against the real tz database, never stored as typed: an invalid
        // identifier here would make every date on this event's pages throw. Blank means
        // "the platform's zone", which is what every event before this column had.
        $tz = trim((string) ($b['timezone'] ?? ''));
        if ($tz !== '' && !\AfricaGates\Support\Clock::isValid($tz)) $tz = '';

        $data = [
            'slug'        => trim($slug, '-'),
            'title'       => trim((string)($b['title'] ?? '')),
            'tagline'     => trim((string)($b['tagline'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'location'    => trim((string)($b['location'] ?? '')),
            'venue'       => trim((string)($b['venue'] ?? '')),
            // ── READ AS THE EVENT'S OWN WALL CLOCK ───────────────────────
            //
            // An organiser setting a Nairobi gala to 19:00 means 19:00 in Nairobi.
            // Interpreting that in the platform's zone — which is what every other
            // datetime on this form correctly does, because a deadline IS platform-wide —
            // would start the evening an hour out for everybody holding a ticket.
            //
            // `$tz` is read from the POST rather than the stored row, so a zone and the
            // times typed beside it are saved in the same breath: changing an event from
            // Lagos to Nairobi and its start to 19:00 in one save must mean 19:00 Nairobi,
            // not 19:00 Lagos reinterpreted afterwards.
            'timezone'    => $tz,
            'event_date'  => (string)(\AfricaGates\Support\EventTime::toStored(['timezone' => $tz], (string)($b['event_date'] ?? ''))
                                      ?: Carbon::now()->toDateTimeString()),
            'end_date'    => \AfricaGates\Support\EventTime::toStored(['timezone' => $tz], (string)($b['end_date'] ?? '')),
            'cover_image' => trim((string)($b['cover_image'] ?? '')),
            'rsvp_url'    => trim((string)($b['rsvp_url'] ?? '')),
            'status'      => in_array($b['status'] ?? '', ['published','draft'], true) ? $b['status'] : 'draft',
            'capacity'    => trim((string)($b['capacity'] ?? '')) !== '' ? max(0, (int)$b['capacity']) : null,
            'schedule'            => $schedule ? json_encode($schedule) : null,
            'map_embed'           => trim((string)($b['map_embed'] ?? '')) ?: null,
            'early_bird_text'     => trim((string)($b['early_bird_text'] ?? '')) ?: null,
            'early_bird_deadline' => self::fromInput($b['early_bird_deadline'] ?? ''),
            'early_bird_url'      => trim((string)($b['early_bird_url'] ?? '')) ?: null,
            // ── the organiser's own operating rules ──────────────────────────
            // Off unless ticked. A waiting list nobody works is worse than an honest
            // "fully booked", because it costs somebody hope as well as a seat.
        ];


        // ══ THE EXTRAS PANEL, AND A DATA-LOSS BUG IT WAS CAUSING ════════════════
        //
        // These six columns were written UNCONDITIONALLY from `$b`, and the event form does
        // not contain a single one of them. So every save of any event — changing a title,
        // fixing a typo in the venue — silently blanked the refund policy, the attendee note,
        // the organiser's email and phone and the sales cutoff, and switched the waiting list
        // off. The organiser saw a form that looked fine and lost the fields that were not on it.
        //
        // The fix is the marker pattern already used two blocks below for the ticket design,
        // whose comment states the rule exactly: only merge when the panel was actually on
        // screen, because a save that does not post a field is not a request to clear it. It
        // was written there and never applied here.
        if (array_key_exists('event_extras_posted', $b)) {
            $data += [
                'waitlist_open'   => !empty($b['waitlist_open']) ? 1 : 0,
                // Per-event referral sharing. Inside the marker block for the same reason
                // as the rest: an unticked checkbox posts nothing, so writing it from a
                // form that does not carry it would switch off every event's referrals on
                // the next unrelated save.
                'referrals_enabled' => !empty($b['referrals_enabled']) ? 1 : 0,
                // A cutoff independent of the event date — catering, badges, a venue list.
                'sales_close_at'  => self::fromInput($b['sales_close_at'] ?? ''),
                'attendee_note'   => trim((string) ($b['attendee_note'] ?? '')) ?: null,
                'refund_policy'   => mb_substr(trim((string) ($b['refund_policy'] ?? '')), 0, 1000) ?: null,
                'organiser_email' => mb_substr(trim((string) ($b['organiser_email'] ?? '')), 0, 190) ?: null,
                'organiser_phone' => mb_substr(trim((string) ($b['organiser_phone'] ?? '')), 0, 40) ?: null,
                // ── THE REFUND POLICY, AS A RULE ─────────────────────────
                //
                // `refund_policy` above stays: it is the prose a buyer reads, and it can say
                // things a rule cannot. These four are the machine-readable version, so an
                // attendee can be shown the actual figure before they cancel rather than after
                // somebody reads their email. Off unless switched on — it is the organiser's
                // event and the organiser's money.
                'self_cancel'     => !empty($b['self_cancel']) ? 1 : 0,
                'refund_mode'     => isset(\AfricaGates\Services\EventRefundPolicy::MODES[(string) ($b['refund_mode'] ?? '')])
                                        ? (string) $b['refund_mode'] : 'none',
                // Clamped rather than rejected: a typo'd 0 or 300 is a slip, and refusing the
                // whole save over it would lose everything else on a long form.
                'refund_percent'  => min(100, max(1, (int) ($b['refund_percent'] ?? 50))),
                'refund_cutoff_hours' => max(0, min(8760, (int) ($b['refund_cutoff_hours'] ?? 0))),
            ];
        }
        // Dropped rather than written when the migration has not run. An operator uploads the
        // zip and runs /__setup/migrate as two separate acts, and a save that 500ed in
        // between would look like the editor breaking rather than a step being outstanding.
        $data = OptionalColumn::filter('gates_site_events', $data, [
            'waitlist_open', 'sales_close_at', 'attendee_note', 'refund_policy',
            'organiser_email', 'organiser_phone',
            'self_cancel', 'refund_mode', 'refund_percent', 'refund_cutoff_hours',
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
            $data = array_merge($data, $this->ticketArtwork($req, $b, $id));
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

        // The awards this ceremony is for. Written only when the form posted the field, for
        // the reason the extras panel above records: writing something the form does not
        // contain wipes it on every unrelated save.
        if (array_key_exists('programme_ids', $b)) {
            \AfricaGates\Services\EventInvites::setProgrammes($id, (array) $b['programme_ids']);
        }

        $this->saveTiers($id, $b);
        $this->saveSessions($id, $b);
        $this->cache->forget('events:upcoming');
        $this->cache->forget('events:past');
        $this->cache->forget('home:site_events');
        $_SESSION['flash_ok'] = 'Event saved.';
        return $res->withHeader('Location', '/admin/events')->withStatus(302);
    }

    /**
     * The ticket's artwork: store the original, cut the crop, hand back the three columns.
     *
     * ── THE TWO WAYS TO PUT A PICTURE ON A TICKET, AND WHY ONE ALWAYS WINS ───
     *
     * The panel offers an editor (upload, drag, zoom, adjust) and, underneath it, a plain
     * address box for an image that lives somewhere else. Both write `ticket_image`, so
     * "which one is in charge" has to be answered here rather than left to whichever ran
     * last. The rule is the one an organiser would guess:
     *
     *   • Upload a file → that becomes the artwork, and whatever was in the address box is
     *     replaced by the path to the crop this method just cut.
     *   • Type an address over an existing crop → the crop is let go. Not kept as a hidden
     *     source that reappears on the next save, which is how somebody ends up unable to
     *     get rid of a picture they have already replaced.
     *   • Change only the sliders → the original is re-read and re-cut. No new upload, no
     *     loss of quality, and the frame stays exactly where it was left.
     *
     * ── WHAT IS RETURNED, AND WHAT IS DELIBERATELY NOT ──────────────────────
     *
     * An empty array means "this method has no opinion", and every failure returns one, so a
     * rejected upload or a renderer that fell over costs the organiser the picture and NOT
     * the forty other fields on the form. The flash says which happened; the save proceeds.
     *
     * @return array<string, mixed> columns to merge into the event row
     */
    private function ticketArtwork(Request $req, array $b, int $id): array
    {
        // Same rule as every other panel on this form: a deployment that has the code and
        // not the migration must save what it can rather than 500 on an unknown column.
        if (OptionalColumn::missing('gates_site_events', ['ticket_image_src', 'ticket_image_edit']) !== []) {
            return [];
        }

        $blank   = ['ticket_image_src' => null, 'ticket_image_edit' => null];
        $adminId = ((int) ($_SESSION['admin_id'] ?? 0)) ?: null;

        if (!empty($b['ticket_artwork_clear'])) {
            return $blank + ['ticket_image' => null];
        }

        $stored = $id
            ? (array) (DB::table('gates_site_events')->where('id', $id)
                ->first(['ticket_image', 'ticket_image_src']) ?: [])
            : [];
        $storedSrc = EventTicketDesign::image(['ticket_image' => (string) ($stored['ticket_image_src'] ?? '')]);

        $file = $req->getUploadedFiles()['ticket_artwork'] ?? null;
        $sent = $file instanceof UploadedFileInterface
             && $file->getError() === UPLOAD_ERR_OK
             && $file->getSize() > 0;

        if (!$sent) {
            // No new file. Did they type over the address box? Compare against what is on the
            // row, because the box is pre-filled with the current path — an untouched form
            // posts it back verbatim and that is not a decision to do anything.
            $typed = trim((string) ($b['ticket_image'] ?? ''));
            if ($typed !== '' && $typed !== trim((string) ($stored['ticket_image'] ?? ''))) {
                return $blank;                   // EventTicketDesign::fromForm already took the address
            }
            if ($storedSrc === '') {
                return [];                       // never had artwork; nothing here to decide
            }
        }

        $uploads = $this->uploads ?? new UploadService();
        $src     = $storedSrc;

        if ($sent) {
            try {
                // 2400px and q88, both higher than this codebase's usual: this file is a
                // MASTER, not a delivered image. Everything a visitor loads is cut from it,
                // so the compression that is invisible on a cover photo is compression that
                // every future crop inherits — and a 300px floor because a picture smaller
                // than the band it fills is a blurred ticket nobody can fix later.
                $up  = $uploads->uploadImage($file, 'tickets', 2400, 88, $adminId, 'site_event', $id ?: null, 300);
                $src = (string) $up['local'];
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Ticket artwork was not used: ' . $e->getMessage()
                    . ' Everything else on the form was saved.';
                return [];
            }
        }

        // A recipe is only absent when the editor never ran — an old cached page, or a browser
        // where the script failed. A file still arrived in that case, so it is cut to the
        // default frame (the whole picture, centred) rather than stored with nothing rendering
        // it; without a file, the stored crop is left exactly as it was.
        $recipe = TicketArtwork::fromForm($b) ?? ($sent ? TicketArtwork::recipe(null) : null);
        if ($recipe === null) {
            // `ticket_image` is put back deliberately. EventTicketDesign::fromForm has already
            // set it to NULL from an address box that is not on screen while the editor is,
            // and returning nothing here would let that blank the crop of every organiser
            // whose browser did not run the script.
            return ['ticket_image' => ((string) ($stored['ticket_image'] ?? '')) ?: null];
        }

        // The service's own root, not the repository's: the two differ wherever this
        // controller is handed an UploadService pointed somewhere else.
        $srcAbs = $uploads->publicRoot() . $src;
        $tmp    = (string) tempnam(sys_get_temp_dir(), 'ag_tk_');
        try {
            TicketArtwork::render($srcAbs, $recipe, $tmp);
            $out = $uploads->storeRendered($tmp, 'jpg', 'tickets', $adminId, 'site_event', $id ?: null);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $_SESSION['flash_error'] = 'The ticket artwork could not be cut: ' . $e->getMessage()
                . ' The picture and the frame were kept, so try saving again.';
            // The source and the recipe ARE stored even though the cut failed — that is what
            // lets the next save retry from the same starting point instead of asking the
            // organiser to upload and re-frame the whole thing again.
            return ['ticket_image_src' => $src, 'ticket_image_edit' => TicketArtwork::pack($recipe)];
        }

        return [
            'ticket_image'      => (string) $out['path'],
            'ticket_image_src'  => $src,
            'ticket_image_edit' => TicketArtwork::pack($recipe),
        ];
    }

    /**
     * Both halves of the `datetime-local` round trip, delegated.
     *
     * These were a local pair built on Carbon, and they were the SECOND
     * implementation of a round trip `Support\DisplayTime` already owns — the cycle
     * form's inline Twig filter was the third. Two things were wrong with this copy:
     * it formatted `'Y-m-d\TH:i'`, dropping the seconds off every value that passed
     * through it, and it read the typed time in the PROCESS zone, so an organiser in
     * Lagos setting a sales close at 18:00 got 18:00 UTC — an hour later than the
     * hour they picked. Storage is still UTC either way; the conversion is the point.
     */
    private static function forInput(string $stamp): string
    {
        return \AfricaGates\Support\DisplayTime::forInput($stamp);
    }

    private static function fromInput(mixed $raw): ?string
    {
        return \AfricaGates\Support\DisplayTime::toStored(
            $raw === null ? null : (string) $raw
        );
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
        $hues   = (array) ($b['tier_colour'] ?? []);

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
                // ── THE TIER'S COLOUR ────────────────────────────────────────
                //
                // A SLOT, checked against EventTierPalette::SLOTS, never a hex. The hex is
                // computed from the event's accent every time it is read, so changing the
                // accent moves the whole ladder — the dot on the printed ticket and the
                // selection light on the registration card both follow, because both read
                // the same resolver.
                //
                // This field is new. The palette, the six named slots, the separation pass
                // and the WCAG edge guarantee all shipped months ago and the column was
                // never writable: there was no field anywhere in the admin, so every tier
                // on the platform had NULL and every surface that reads a tier colour fell
                // back to a default. A whole mechanism with no route in — the pattern in
                // CODEBASE-INDEX §18, third instance.
                //
                // NULL is preserved as NULL rather than stored as '': "no colour on this
                // tier" is the default and a real answer, and a ladder where every row is
                // coloured because the field had to be filled in is noisier than one where
                // the organiser marked the two that matter.
                'sort_order'  => $order++,
                'updated_at'  => $now,
            ];

            // `colour` arrived on its own dated migration, so a deployment that has uploaded
            // this code and not yet run /__setup/migrate does not have the column. Writing it
            // there would throw, and the catch below would swallow it — losing the WHOLE tier
            // silently, which is much worse than not having the field. OptionalColumn is the
            // convention for exactly this and every other block on this screen uses it.
            if (\AfricaGates\Support\OptionalColumn::on('gates_event_tiers', 'colour')) {
                $row['colour'] = \AfricaGates\Services\EventTierPalette::slot($hues[$i] ?? null);
            }

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
            // See ShopController — the same two fields, the same column widths, and the
            // same failure that only ever appears on MySQL.
            'max_uses'      => trim((string) ($b['max_uses'] ?? '')) !== ''
                ? ColumnRange::clamp((int) $b['max_uses'], ColumnRange::INT_UNSIGNED, 1) : null,
            'max_per_email' => ColumnRange::clamp((int) ($b['max_per_email'] ?? 1),
                                                  ColumnRange::SMALLINT_UNSIGNED, 1),
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
    /**
     * POST /admin/events/{id}/welcome-render — make the greetings now.
     *
     * ── WHY THIS BUTTON EXISTS ───────────────────────────────────────────────
     *
     * Greetings are rendered by a maintenance sweep, and on a deployment where the
     * scheduled run has never been set up that sweep NEVER HAPPENS. So the whole feature
     * can be switched on, configured, tested from Settings, and still produce complete
     * silence at the door — with no step anywhere that an operator can take. The screen
     * used to point at "run `welcome` from Automation & cron", which is a page about
     * setting up a cron on a host with no shell, three clicks from the person standing in
     * front of the guest list with an hour to go.
     *
     * ── AND WHY IT IS BOUNDED ────────────────────────────────────────────────
     *
     * Azure's free tier allows about eighteen requests a minute, and a gala is a few
     * hundred names. `sweep()` already budgets ATTEMPTS against that ceiling and stops on
     * a 429, so this renders one tier-sized batch and says what is left rather than
     * holding a browser request open through several hundred refusals. Pressing it again
     * takes the next batch; nothing already on disk is re-rendered.
     */
    public function welcomeRender(Request $req, Response $res, array $args): Response
    {
        $id  = (int) $args['id'];
        $to  = '/admin/events/' . $id . '/tickets';
        $was = \AfricaGates\Services\DoorWelcome::readiness($id);

        // Nothing to press through: say the actual blocker rather than making a batch of
        // zero clips and reporting success.
        if (!$was['on'] || !$was['voice'] || !$was['writable']) {
            $_SESSION['flash_error'] = $was['blocker'] . ' ' . $was['fix'];
            return $res->withHeader('Location', $to)->withStatus(302);
        }

        $made = (int) \AfricaGates\Services\DoorWelcome::sweep();
        $now  = \AfricaGates\Services\DoorWelcome::readiness($id);
        $left = max(0, $now['lines'] - $now['ready']);

        try {
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0),
                'event.welcome_render', 'event', $id, ['made' => $made]);
        } catch (\Throwable) {}

        if ($made < 1 && $left > 0) {
            // The likeliest cause by far on the free tier, and the one an operator cannot
            // guess: the per-minute quota is spent. Naming it stops them pressing again
            // for a minute and concluding the button does nothing.
            $_SESSION['flash_error'] = 'No greetings could be made just now. '
                . (\AfricaGates\Services\AzureVoice::lastError()
                   ?: 'The voice did not answer. If you have pressed this a few times, wait a '
                      . 'minute — the free tier allows about '
                      . \AfricaGates\Services\AzureVoice::perMinute() . ' a minute.');
            return $res->withHeader('Location', $to)->withStatus(302);
        }

        $_SESSION['flash_ok'] = $left > 0
            ? $made . ' greeting' . ($made === 1 ? '' : 's') . ' made. ' . $left
              . ' still to go — press again in a minute for the next batch.'
            : 'Every guest on this list will be welcomed by name.';

        return $res->withHeader('Location', $to)->withStatus(302);
    }

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
            // ── IS THE DOOR GOING TO SPEAK? ──────────────────────────────
            //
            // Greetings are rendered by a 06:00 sweep, hours or days ahead, so on the
            // afternoon of a gala the honest answer to "will it say their names" is a
            // COUNT, not a switch. An organiser who has just imported ninety guests needs
            // to know that ninety clips do not yet exist — while there is still time to run
            // the sweep — rather than finding out at the door, where the failure is silent
            // by design and every one of them gets "you are welcome".
            //
            // Null when the voice is off, so the panel says nothing rather than reporting
            // "0 of 90 ready" about a feature nobody switched on.
            // ── WILL THE DOOR SPEAK, AND IF NOT, WHY NOT ────────────────
            //
            // `welcome_ready` above is null whenever the voice is switched off, so the
            // panel said NOTHING in the one case that is both the most likely and the
            // easiest to fix. Every other broken link — no key, no writable cache, an
            // event outside the render window, a sweep that has never run — was equally
            // invisible, and all of them sound identical at the door: silence, which is
            // what a working door sounds like when the voice is deliberately off.
            //
            // Replaces a `welcome_ready` that was `costOf()` or NULL — null being exactly
            // the switched-off case the panel most needed to describe. readiness() calls
            // costOf() itself, so the counts are the same numbers from the same place.
            'welcome_state' => \AfricaGates\Services\DoorWelcome::readiness($id),
            // ── THE ARRIVALS LOG ─────────────────────────────────────────
            //
            // The first reader `checked_in_via` and `checked_in_by` have ever had. Both were
            // written from day one and rendered nowhere, so the only account of who admitted
            // whom lived in a JavaScript list on the steward's phone — capped at twelve,
            // timestamped from that phone's clock, and erased by a refresh. That is the
            // record an organiser reaches for when an attendee disputes an entry, and it
            // did not survive the screen locking.
            //
            // It carries reversals too, which is why it is a log and not a column: setting
            // `checked_in_at` back to NULL would erase the fact that somebody was scanned in
            // at 19:42 and un-scanned at 19:43, and that is exactly what gets asked about.
            //
            // ── AND EVERY TIME ON IT IS IN THE ROOM'S OWN CLOCK ──────────
            //
            // The rows were rendered with `|slice(11, 5)` over the stored string, and
            // storage here is UTC by convention — so an admission at 19:42 in Lagos was
            // printed as 18:42, on the record an organiser is meant to stand behind a week
            // later. Slicing a datetime is not formatting it: it reads the storage
            // convention out loud. The door had exactly this bug on its closing time; the
            // office screen that reads the same log kept it.
            'arrivals'    => $this->inTheRoomsClock($event,
                \AfricaGates\Services\EventArrivals::recent($id, 300)),
            'room'        => \AfricaGates\Services\EventArrivals::summary($id),
            // ── REFUNDS ──────────────────────────────────────────────────
            //
            // Here rather than on the finance screen because a refund that did not land is
            // not an accounting entry, it is an attendee owed money — and the person who
            // will hear from them is the organiser reading this page. Finance shows totals;
            // this shows the two rows somebody has to do something about.
            'refunds'      => EventTicketService::refunds($id),
            'refund_tally' => EventTicketService::refundTally($id),
            // Same treatment, same reason: the attendee list stamps an arrival too.
            'attendees'  => $this->inTheRoomsClock($event,
                EventTicketService::attendees($id, $status), 'checked_in_at', 'arrived'),
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

        // Both windows through the converter — the form renders them with |when_input,
        // and a gate pass that opens an hour late is a queue outside the door.
        $token = \AfricaGates\Services\EventScanPass::issue(
            $id,
            (string) (\AfricaGates\Support\DisplayTime::toStored($b['closes_at'] ?? null) ?? ''),
            (string) (\AfricaGates\Support\DisplayTime::toStored($b['opens_at'] ?? null) ?? ''),
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

    // ══ REFUNDS THAT DID NOT LAND ════════════════════════════════════════════

    /**
     * POST /admin/events/{id}/refunds/retry — ask the gateway again.
     *
     * ── WHY THIS IS A BUTTON AND NOT A CRON ──────────────────────────────────
     *
     * A refund fails for reasons a retry loop cannot fix: the transaction is past the
     * refundable age, the account balance never covers it, the key was rotated. Retrying
     * those on a timer burns the gateway's rate limit and produces a log nobody reads,
     * while the person owed the money waits. A human pressing this has usually just done
     * the thing that makes the retry work — topped the balance up, fixed the key — and is
     * the only party who can know that.
     *
     * The double-press guard lives in the SERVICE, not here: the row is claimed to
     * `pending` before the network call, so two organisers pressing together send one
     * refund rather than two. On a refund endpoint that is the difference between paying
     * somebody once and paying them twice.
     */
    public function retryRefund(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $regId = (int) (((array) $req->getParsedBody())['reg_id'] ?? 0);
        $back  = '/admin/events/' . $id . '/tickets#refunds';
        $admin = (int) ($_SESSION['admin_id'] ?? 0);

        $r = EventTicketService::retryRefund($regId, $id);

        // Three outcomes, not two. `pending` is neither a success nor a failure — the
        // gateway took it and has not finished — and saying "done" there is how an
        // organiser ends up telling an attendee the money is back before it is.
        if ($r['status'] === 'refunded') {
            $_SESSION['flash_ok'] = 'Refunded. The money is on its way back to them.';
        } elseif ($r['status'] === 'pending') {
            $_SESSION['flash_ok'] = 'Sent to the gateway. It usually settles within a few '
                . 'hours — this list updates itself when it does.';
        } else {
            $_SESSION['flash_error'] = 'That refund failed again'
                . ($r['message'] !== '' ? ' — ' . $r['message'] : '.')
                . ' If it will not go through, record it as paid by hand once you have sent it.';
        }

        $this->audit->record($admin, 'event.refund.retry', 'event_registration', $regId);
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * POST /admin/events/{id}/refunds/settle — "I have paid this person another way."
     *
     * It moves no money. It records that a person did — which is why the audit line carries
     * who, and why the stored reference says `by-hand` rather than borrowing the shape of a
     * gateway reference and becoming indistinguishable from one six months later.
     *
     * Without it a permanently unrefundable row sits red forever, and a list that is always
     * red is a list nobody checks.
     */
    public function settleRefund(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $regId = (int) (((array) $req->getParsedBody())['reg_id'] ?? 0);
        $back  = '/admin/events/' . $id . '/tickets#refunds';
        $admin = (int) ($_SESSION['admin_id'] ?? 0);

        if (EventTicketService::settleRefundByHand($regId, $id, $admin)) {
            $_SESSION['flash_ok'] = 'Recorded as refunded by hand. No money moved through the '
                . 'platform — the transfer is yours to make.';
            $this->audit->record($admin, 'event.refund.by_hand', 'event_registration', $regId);
        } else {
            $_SESSION['flash_error'] = 'That one could not be marked as paid — it may have '
                . 'settled on its own already. Reload the page.';
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
    /**
     * Stamp each row with its time in the EVENT'S own zone, and name the person behind an id.
     *
     * ── WHY THE TEMPLATE CANNOT DO THIS ──────────────────────────────────────
     *
     * It was doing it, with `|slice(11, 5)`, and that is the bug rather than a shortcut:
     * storage is UTC by this application's convention, so slicing the stored string prints
     * the convention rather than the time. A gala in Lagos showed every arrival an hour
     * early on the one record an organiser is meant to stand behind. Twig's `|date` is
     * pinned to the PLATFORM's display zone, which is right for a deadline and wrong for a
     * room — a Nairobi gala is not read in Lagos hours.
     *
     * ── AND WHY THE ADMIN'S NAME, NOT THEIR NUMBER ───────────────────────────
     *
     * The log rendered "admin #7". Nobody knows who #7 is, least of all a week later when
     * somebody is disputing an entry — which is the only moment this screen is read.
     * `checked_in_by` on the registration row had never been rendered at all.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function inTheRoomsClock(?object $event, array $rows,
                                     string $stamp = 'created_at', string $as = 'at'): array
    {
        if ($rows === []) return $rows;

        // One query for every name on the page, not one per row: an evening with three
        // hundred arrivals would otherwise be three hundred round trips to render a list.
        $ids = [];
        foreach ($rows as $r) {
            foreach (['admin_id', 'checked_in_by'] as $k) {
                if (!empty($r[$k])) $ids[(int) $r[$k]] = true;
            }
        }
        $names = [];
        if ($ids !== []) {
            try {
                $names = \Illuminate\Database\Capsule\Manager::table('gates_admins')
                    ->whereIn('id', array_keys($ids))->pluck('name', 'id')
                    ->map(static fn ($v) => (string) $v)->all();
            } catch (\Throwable) { /* a list with no names still reads */ }
        }

        foreach ($rows as $i => $r) {
            $at = trim((string) ($r[$stamp] ?? ''));
            $rows[$i][$as . '_time'] = $at !== ''
                ? \AfricaGates\Support\EventTime::at($event, $at, 'H:i') : '';
            $rows[$i][$as . '_date'] = $at !== ''
                ? \AfricaGates\Support\EventTime::at($event, $at, 'j M') : '';
            $rows[$i][$as . '_zone'] = $at !== ''
                ? \AfricaGates\Support\EventTime::abbr($event, $at) : '';

            $by = (int) ($r['admin_id'] ?? $r['checked_in_by'] ?? 0);
            $rows[$i]['by_name'] = $by > 0 ? ($names[$by] ?? ('admin #' . $by)) : '';
        }

        return $rows;
    }

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

    /**
     * The box-office sheet: every ticket for this event, laid out to be printed and cut.
     *
     * ── WHY A BATCH SHEET EXISTS AT ALL ──────────────────────────────────────
     *
     * The attendee's own page prints one ticket beautifully and is the wrong tool for a door
     * team. Somebody has to hand physical tickets to a guest list, to a sponsor's table of
     * twelve, to the forty people who bought at a kiosk with no email address — and doing
     * that from the attendee page means opening forty tabs and pressing print forty times.
     * The alternative organisers actually reach for is a spreadsheet, which produces a row of
     * text with no QR on it, and then the door is typing codes by hand all evening.
     *
     * ── AND WHY IT IS A PAGE RATHER THAN A PDF ───────────────────────────────
     *
     * A PDF means a rendering library, a font stack, and a second implementation of this
     * layout that drifts from the first. The browser already has a print engine, it already
     * has the fonts, and `@page` with millimetre geometry is a real typesetting instruction
     * rather than an approximation of one. What is lost is control over the driver's
     * ink-saving default — which is exactly why nothing on this sheet depends on a fill.
     *
     * ── WHAT IT REFUSES ──────────────────────────────────────────────────────
     *
     * Only CONFIRMED registrations get printed. Same doctrine as the attendee page: a pending
     * payment rendered as a scannable ticket is an argument at a door, and a printed one is
     * worse, because paper carries an authority a web page does not — nobody at the gate
     * assumes a printed ticket might be provisional.
     */
    public function printTickets(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $q = $req->getQueryParams();

        // Three per page or two. See TicketPdf::LAYOUTS for why those two and not others.
        $per = (int) ($q['per'] ?? 3) === 2 ? 2 : 3;

        // A cap with a VISIBLE consequence. Several thousand inline QR symbols is tens of
        // megabytes of markup and a browser that stops responding mid-print, which reads as
        // "the printer is broken" rather than "there were too many". The template says how
        // many were left out and how to get them.
        $limit = 400;
        $tier  = trim((string) ($q['tier'] ?? ''));

        $all = EventTicketService::attendees($id, 'confirmed', 5000);
        if ($tier !== '') {
            $all = array_values(array_filter($all, static fn($a) => (string) ($a['tier'] ?? '') === $tier));
        }

        // Oldest booking first, so reprinting a sheet after a few more sales appends rather
        // than reshuffling — a box office that has already cut page three should not find
        // page three is now different people.
        usort($all, static fn($a, $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));

        $total = count($all);
        $rows  = array_slice($all, 0, $limit);

        // The cap is reported in the FILENAME, because a PDF has no room for a banner and a
        // sheet that silently stopped at four hundred would be read as "that is everyone" —
        // and the people it left out find out at the door.
        $pdf = \AfricaGates\Services\TicketPdf::sheet(
            $rows, (array) $event, EventTicketDesign::forEvent($event), $per
        );

        $slug = preg_replace('/[^a-z0-9\-]+/i', '-', (string) $event->slug);
        $name = $slug . '-tickets'
              . ($tier !== '' ? '-' . preg_replace('/[^a-z0-9\-]+/i', '-', $tier) : '')
              . '-' . count($rows) . 'of' . $total . '.pdf';

        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'event.tickets_printed', 'event', $id);

        $res->getBody()->write($pdf);
        return $res
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
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
