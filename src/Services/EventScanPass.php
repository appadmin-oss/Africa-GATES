<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A door pass: permission to check tickets in, for one event, between two timestamps.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT IS, AND WHAT IT DELIBERATELY IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It IS: the ability to present a ticket code and be told admit / already in / refuse, and to
 * record the first of those.
 *
 * It is NOT a login, not a way to read the attendee list, not scoped to more than one event,
 * and not permanent. Those four absences are the feature. A door is worked by volunteers and
 * venue staff who exist for four hours, and the realistic alternative — real admin accounts,
 * created by hand and deleted later — leaves accounts behind on a platform that runs an awards
 * cycle and moves money.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE WINDOW IS CHECKED ON EVERY REQUEST AND NOT ONLY AT ISSUE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A link that has been sent to six phones cannot be recalled. The only thing that reliably
 * stops it is that it refuses on its own, so expiry is evaluated at USE and the answer says
 * which side of the window it fell on — "this opens at 18:00" and "this closed at 23:00" are
 * different problems for the person holding the phone, and a single "invalid" would send both
 * of them to ring an organiser mid-event.
 *
 * Only the SHA-256 is stored, so a dump of the table yields nothing usable. The token is shown
 * once, at creation, because that is the only moment it exists in a form anybody can copy.
 */
final class EventScanPass
{
    /** 32 bytes. This is a bearer credential for a door, not a reference somebody reads out. */
    private const TOKEN_BYTES = 32;

    /** How long a pass lasts when the admin does not say. Long enough for a gala and its overrun. */
    public const DEFAULT_HOURS = 8;

    /** Every reason a pass can refuse, and the sentence each one shows. */
    public const REFUSALS = [
        'unknown' => 'This scanning link is not valid.',
        'revoked' => 'This scanning link was turned off by an organiser.',
        'early'   => 'This scanning link is not open yet.',
        'expired' => 'This scanning link has closed.',
        'cancelled' => 'This event was cancelled, so nobody is being admitted.',
    ];

    public static function ready(): bool
    {
        try { return DB::schema()->hasTable('gates_event_scan_passes'); }
        catch (\Throwable) { return false; }
    }

    /**
     * Mint a pass and return the token ONCE. Null when it could not be stored.
     *
     * @param ?string $opensAt  'Y-m-d H:i:s', or null for "works immediately"
     * @param ?string $closesAt 'Y-m-d H:i:s'; defaults to {@see DEFAULT_HOURS} from now
     */
    public static function issue(int $eventId, ?string $closesAt = null, ?string $opensAt = null,
                                 string $label = '', ?int $adminId = null): ?string
    {
        if ($eventId < 1 || !self::ready()) return null;

        $closes = trim((string) $closesAt) !== ''
            ? self::stamp($closesAt)
            : Carbon::now()->addHours(self::DEFAULT_HOURS)->toDateTimeString();
        $opens = trim((string) $opensAt) !== '' ? self::stamp($opensAt) : null;

        if ($closes === null) return null;
        // A window that closes before it opens can never admit anybody, and the form is the
        // only place that mistake is cheap to catch.
        if ($opens !== null && $opens >= $closes) return null;

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        try {
            DB::table('gates_event_scan_passes')->insert([
                'event_id'   => $eventId,
                'token_hash' => hash('sha256', $token),
                'label'      => $label !== '' ? mb_substr($label, 0, 60) : null,
                'opens_at'   => $opens,
                'closes_at'  => $closes,
                'created_by' => $adminId ?: null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[door] could not issue a scan pass for event ' . $eventId . ': ' . $e->getMessage());
            return null;
        }

        return $token;
    }

    /**
     * Resolve a token to the pass it names, or say why not.
     *
     * @return array{ok:bool, reason:string, message:string, pass:?object, event:?object}
     */
    public static function resolve(string $token): array
    {
        $no = static fn (string $reason): array => [
            'ok' => false, 'reason' => $reason,
            'message' => self::REFUSALS[$reason] ?? self::REFUSALS['unknown'],
            'pass' => null, 'event' => null,
        ];

        $token = trim($token);
        // Cheap shape check before touching the database: a door gets scanned by bots like
        // every other public URL, and none of them will be 64 hex characters.
        if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) return $no('unknown');
        if (!self::ready()) return $no('unknown');

        try {
            $pass = DB::table('gates_event_scan_passes')
                ->where('token_hash', hash('sha256', $token))->first();
        } catch (\Throwable) {
            return $no('unknown');
        }
        if (!$pass) return $no('unknown');
        if (($pass->revoked_at ?? null) !== null) return $no('revoked');

        // The window, evaluated NOW. Which side it failed on is carried back, because "opens at
        // 18:00" and "closed at 23:00" send the holder to do different things.
        $now = Carbon::now()->toDateTimeString();
        if (($pass->opens_at ?? null) !== null && $now < (string) $pass->opens_at) {
            $r = $no('early');
            $r['pass'] = $pass;
            return $r;
        }
        if ($now > (string) $pass->closes_at) {
            $r = $no('expired');
            $r['pass'] = $pass;
            return $r;
        }

        try {
            $event = DB::table('gates_site_events')->where('id', (int) $pass->event_id)->first();
        } catch (\Throwable) {
            $event = null;
        }
        if (!$event) return $no('unknown');

        // ── AND THE EVENT ITSELF ─────────────────────────────────────────────
        //
        // Checked here rather than nowhere, which is where it was checked before. The pass
        // window and the event's own state are different facts, and a door that validated
        // only the first went on admitting people to a gala an organiser had cancelled —
        // for as long as the pass lasted, with the cancellation notice already sent.
        //
        // Only 'cancelled' stops it. A draft event with a live pass is an organiser
        // testing their door before publishing, which is exactly what a pass is for.
        if ((string) ($event->status ?? '') === 'cancelled') {
            $r = $no('cancelled');
            $r['pass'] = $pass;
            return $r;
        }

        return ['ok' => true, 'reason' => '', 'message' => '', 'pass' => $pass, 'event' => $event];
    }

    /**
     * Note that a pass was used.
     *
     * Counted rather than logged per scan: a busy door is hundreds of requests and this runs
     * inside each one. The count and the last-used time answer what an organiser asks
     * afterwards — "was that gate actually working?" — without a row per attendee.
     */
    public static function touch(int $passId): void
    {
        try {
            DB::table('gates_event_scan_passes')->where('id', $passId)->update([
                'last_used_at' => Carbon::now()->toDateTimeString(),
                'scans'        => DB::raw('scans + 1'),
            ]);
        } catch (\Throwable) { /* never break a check-in over a counter */ }
    }

    /** Turn one off. Immediate, and it cannot be undone — issue another. */
    public static function revoke(int $passId, int $eventId): bool
    {
        try {
            return DB::table('gates_event_scan_passes')
                ->where('id', $passId)->where('event_id', $eventId)->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()->toDateTimeString()]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every pass for an event, newest first, each with the state an admin needs to read.
     *
     * The state is computed here rather than in the template because "open", "not yet" and
     * "closed" are a comparison against now, and a template that got it wrong would tell an
     * organiser their door is live when it is not.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEvent(int $eventId): array
    {
        if (!self::ready()) return [];
        try {
            $rows = DB::table('gates_event_scan_passes')->where('event_id', $eventId)
                ->orderByDesc('id')->limit(50)->get();
        } catch (\Throwable) {
            return [];
        }

        $now = Carbon::now()->toDateTimeString();
        $out = [];
        foreach ($rows as $r) {
            $state = match (true) {
                ($r->revoked_at ?? null) !== null                                  => 'revoked',
                $now > (string) $r->closes_at                                      => 'closed',
                ($r->opens_at ?? null) !== null && $now < (string) $r->opens_at    => 'scheduled',
                default                                                            => 'open',
            };
            $out[] = [
                'id'        => (int) $r->id,
                'label'     => (string) ($r->label ?? ''),
                'opens_at'  => (string) ($r->opens_at ?? ''),
                'closes_at' => (string) $r->closes_at,
                'state'     => $state,
                'scans'     => (int) ($r->scans ?? 0),
                'last_used' => (string) ($r->last_used_at ?? ''),
                'revoked'   => ($r->revoked_at ?? null) !== null,
            ];
        }
        return $out;
    }

    /** Is there a pass that would work right now? Drives the admin screen's "the door is live" line. */
    public static function anyOpen(int $eventId): bool
    {
        foreach (self::forEvent($eventId) as $p) {
            if ($p['state'] === 'open') return true;
        }
        return false;
    }

    /** Normalise whatever a datetime-local field submitted. Null when it is not a date. */
    private static function stamp(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        try { return Carbon::parse(str_replace('T', ' ', $raw))->toDateTimeString(); }
        catch (\Throwable) { return null; }
    }

    /** Housekeeping: passes long past their window are of no interest to anybody. */
    public static function prune(int $graceDays = 30): int
    {
        if (!self::ready()) return 0;
        try {
            return (int) DB::table('gates_event_scan_passes')
                ->where('closes_at', '<', Carbon::now()->subDays(max(1, $graceDays))->toDateTimeString())
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }
}
