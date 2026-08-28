<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Who is invited to a ceremony as a guest of honour, and what they are given.
 *
 * ── THE SHAPE OF THE RUN ─────────────────────────────────────────────────────
 *
 * An award programme leads to a ceremony (`gates_site_events.programme_id`). The people
 * invited to it are the published shortlist of that programme's current cycle, plus the
 * panel that judged it. Each of them gets one row in `gates_event_invites` carrying:
 *
 *   • a reference, which is their identity at the door and their discount code
 *   • a secret, which never leaves the server and is what makes the rotating ID
 *     on their phone impossible to forge from anything they can see
 *   • a guest quota, resolved and STORED at mint time, so the number in the letter and
 *     the number the code allows cannot drift apart afterwards
 *
 * ── WHY THE REFERENCE IS ALSO THE DISCOUNT CODE ──────────────────────────────
 *
 * Twenty-five guests learn it, which is the point of asking. It is safe because the
 * reference alone opens nothing: the door verifies an HMAC under the per-invite secret
 * (see {@see InvitePass}), so a guest holding the code they were given to buy a ticket
 * cannot mint a pass with it. And it buys exact attribution — a redemption names the
 * person who mobilised it, without a second identifier for an operator to reconcile.
 *
 * ── NOTHING HERE SENDS ───────────────────────────────────────────────────────
 *
 * Minting and sending are separate on purpose. An operator can build the list, read it,
 * see who is unreachable and who is ambiguous, and correct it, before a single message
 * leaves — because an invitation carrying the wrong person's name and personal reference
 * cannot be recalled. {@see InviteMailer} does the sending, against rows this made.
 */
final class EventInvites
{
    /** The ceremony for a programme, or null when none is linked. */
    public static function eventForProgramme(int $programmeId): ?object
    {
        if ($programmeId < 1) return null;
        try {
            return DB::table('gates_site_events')
                ->where('programme_id', $programmeId)
                ->where('status', 'published')
                ->orderBy('event_date')
                ->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The cheapest tier on sale — the "minimum support" an invitation asks for.
     *
     * Anchored to the ladder rather than to a number in the copy. A letter that names a
     * figure the ticket page does not sell is a letter that has to be re-sent, and the
     * organiser moves prices right up to the week of the event.
     *
     * PAID tiers only, and that is the whole subtlety. `gates_event_tiers` records that
     * "zero is a real price, not a missing one: a free tier inside a paid event (press,
     * sponsors, students) is the ordinary case" — so the cheapest tier by price is quite
     * often a comp tier nobody can buy. An invitation that asks a nominee's guests to
     * support at the lowest tier and points them at the press list asks for nothing.
     *
     * @return ?object with `name` and `price_naira`
     */
    public static function lowestTier(int $eventId): ?object
    {
        try {
            return DB::table('gates_event_tiers')
                ->where('event_id', $eventId)
                ->where('is_active', 1)
                ->where('price_naira', '>', 0)
                ->orderBy('price_naira')
                ->first(['id', 'name', 'price_naira']) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Everybody who should be invited, by audience, with why any of them cannot be.
     *
     * Read-only. Nothing is minted and nothing is sent.
     *
     * @return array<string, array{
     *   audience:array<string,mixed>,
     *   ready:list<array{name:string,email:string,nominee_id:int,judge_id:int,category:string}>,
     *   unreachable:list<array{name:string,why:string}>,
     *   already:int
     * }>
     */
    public static function plan(int $eventId): array
    {
        $event = DB::table('gates_site_events')->where('id', $eventId)->first();
        $out   = [];

        foreach (InviteAudience::all() as $key) {
            $spec  = InviteAudience::spec($key);
            $rows  = $key === InviteAudience::JUDGE
                ? self::judges()
                : self::shortlisted((string) $spec['programme_slug']);

            $ready = [];
            $bad   = [];
            foreach ($rows as $r) {
                if ($r['email'] === '') { $bad[] = ['name' => $r['name'], 'why' => $r['why']]; continue; }
                $ready[] = $r;
            }

            $out[$key] = [
                'audience'    => $spec,
                'ready'       => $ready,
                'unreachable' => $bad,
                'already'     => $event ? self::countFor($eventId, $key) : 0,
            ];
        }

        return $out;
    }

    /**
     * Mint one invite, or return the existing one for that person.
     *
     * Idempotent on (event, email) — the table's own unique key says so, and an operator
     * running the build twice must not produce two references for one person, each with
     * its own discount code, only one of which is in the letter they were sent.
     *
     * @param array{name:string,email:string,nominee_id:int,judge_id:int} $who
     */
    public static function mint(int $eventId, string $audience, array $who, ?int $cycleId = null): ?object
    {
        if (!InviteAudience::isValid($audience)) return null;

        $email = EmailOptOut::normalise($who['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        try {
            $existing = DB::table('gates_event_invites')
                ->where('event_id', $eventId)->where('email', $email)->first();
            if ($existing) return $existing;

            $spec      = InviteAudience::spec($audience);
            $reference  = self::freshReference();
            $quota      = (int) $spec['quota'];

            $id = (int) DB::table('gates_event_invites')->insertGetId([
                'event_id'      => $eventId,
                'cycle_id'      => $cycleId,
                'audience'      => $audience,
                'nominee_id'    => ($who['nominee_id'] ?? 0) > 0 ? (int) $who['nominee_id'] : null,
                'judge_id'      => ($who['judge_id'] ?? 0) > 0 ? (int) $who['judge_id'] : null,
                'name'          => mb_substr(trim((string) ($who['name'] ?? '')), 0, 160),
                'email'         => $email,
                'reference'     => $reference,
                'id_secret'     => InvitePass::secret(),
                'discount_code' => $reference,
                'guest_quota'   => $quota,
                'created_at'    => Carbon::now()->toDateTimeString(),
            ]);

            self::mintCode($eventId, $reference, $quota, (string) $spec['label']);

            return DB::table('gates_event_invites')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The discount code an invitee's guests spend.
     *
     * `max_uses` is the invitee's own quota, so the code runs out exactly where the
     * letter said it would. `max_per_email` is 1 because the quota counts PEOPLE — one
     * guest buying twice would otherwise consume two of the twenty-five somebody else
     * was promised.
     */
    private static function mintCode(int $eventId, string $code, int $quota, string $label): void
    {
        try {
            if (DB::table('gates_event_codes')->where('event_id', $eventId)->where('code', $code)->exists()) {
                return;
            }
            DB::table('gates_event_codes')->insert([
                'event_id'      => $eventId,
                'code'          => $code,
                'label'         => mb_substr($label . ' — guest of honour', 0, 120),
                'kind'          => 'percent',
                'amount'        => InviteAudience::discountPercent(),
                'tier_ids'      => null,          // every tier: they choose where to sit
                'max_uses'      => $quota,
                'max_per_email' => 1,
                'used_count'    => 0,
                'is_active'     => 1,
                'created_at'    => Carbon::now()->toDateTimeString(),
                'updated_at'    => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // A missing code is visible on the invite row (discount_code with no code row)
            // and the admin screen reports it. It must not lose the invite itself.
        }
    }

    /** @return list<object> */
    public static function forEvent(int $eventId, string $audience = ''): array
    {
        try {
            $q = DB::table('gates_event_invites')->where('event_id', $eventId);
            if ($audience !== '') $q->where('audience', $audience);

            return $q->orderBy('audience')->orderBy('name')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function byReference(string $reference): ?object
    {
        try {
            return DB::table('gates_event_invites')
                ->where('reference', strtoupper(trim($reference)))->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The live mobile ID. Absolute, because it is opened from an email. */
    public static function idUrl(string $reference, string $base = ''): string
    {
        $base = $base !== '' ? rtrim($base, '/') : rtrim(SiteUrl::base(), '/');

        return $base . '/honour/' . rawurlencode(strtoupper($reference));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function countFor(int $eventId, string $audience): int
    {
        try {
            return (int) DB::table('gates_event_invites')
                ->where('event_id', $eventId)->where('audience', $audience)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * A reference nothing else holds.
     *
     * Retried rather than trusted: `reference` is UNIQUE, and a collision on a random
     * eight-character token is vanishingly unlikely but would surface as a failed mint
     * for one person in the middle of a run rather than as anything an operator could read.
     */
    private static function freshReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $r = InvitePass::reference();
            if (!DB::table('gates_event_invites')->where('reference', $r)->exists()) return $r;
        }

        throw new \RuntimeException('Could not mint a unique invitation reference.');
    }

    /**
     * The published shortlist of a programme's current cycle.
     *
     * @return list<array{name:string,email:string,nominee_id:int,judge_id:int,category:string,why:string,cycle_id:int}>
     */
    private static function shortlisted(string $programmeSlug): array
    {
        $programme = DB::table('gates_award_programmes')->where('slug', $programmeSlug)->first(['id']);
        if (!$programme) return [];

        $cycle = DB::table('gates_award_cycles')
            ->where('programme_id', $programme->id)
            ->orderByDesc('year')->orderByDesc('id')
            ->first(['id']);
        if (!$cycle) return [];

        $cycleId = (int) $cycle->id;
        $onList  = ShortlistService::shortlistedIn($cycleId);
        if ($onList === []) return [];

        $rows = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('c.cycle_id', $cycleId)
            ->whereIn('n.id', array_keys($onList))
            ->whereNull('n.merged_into')
            ->select('n.id', 'n.name', 'n.profile_id', 'c.title as category')
            ->orderBy('c.title')->orderBy('n.name')
            ->get()->all();

        $out = [];
        foreach ($rows as $n) {
            $found = NomineeAddress::candidates($n, $cycleId);
            $out[] = [
                'name'       => (string) $n->name,
                'email'      => count($found) === 1 ? $found[0] : '',
                'nominee_id' => (int) $n->id,
                'judge_id'   => 0,
                'category'   => (string) $n->category,
                'cycle_id'   => $cycleId,
                // Named, not just counted: "two people share this name" and "we have no
                // address" are different problems and an operator fixes them differently.
                'why'        => $found === []
                    ? 'No address on the nomination or the linked profile.'
                    : (count($found) > 1
                        ? 'More than one nominee is approved under this name — pick the right address by hand.'
                        : ''),
            ];
        }

        return $out;
    }

    /**
     * The panel. Through realJudges(), so the sandbox's rehearsal judge is not invited
     * to a real ceremony.
     *
     * @return list<array{name:string,email:string,nominee_id:int,judge_id:int,category:string,why:string,cycle_id:int}>
     */
    private static function judges(): array
    {
        try {
            $rows = JudgeService::realJudges()
                ->where('is_active', 1)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $j) {
            $e = EmailOptOut::normalise((string) ($j->email ?? ''));
            $out[] = [
                'name'       => (string) $j->name,
                'email'      => filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '',
                'nominee_id' => 0,
                'judge_id'   => (int) $j->id,
                'category'   => 'Judging panel',
                'cycle_id'   => 0,
                'why'        => filter_var($e, FILTER_VALIDATE_EMAIL) ? '' : 'No usable address on the judge record.',
            ];
        }

        return $out;
    }
}
