<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Scoped, expiring access: a capability at a scope, for one person, that ENDS
 * (docs/architecture/CONSOLES-V7-REVIEW.md K1; plan §7.2; v7 handoff §7).
 *
 * A global role cannot say "door steward at the Alimosho ceremony" or "lead on these two
 * events", so the volunteer workspace and the event lead console both stand on this. The rules
 * that make it safe, each held by GrantsTest:
 *
 *   · EVERY GRANT ENDS. `grant()` refuses one with no expiry: access must never outlive the
 *     reason for it, and an open-ended grant is a role somebody forgot to take away.
 *   · EXPIRY IS READ, NOT SWEPT. `allows()` compares against now on every call, so a grant stops
 *     working the moment it ends with nothing scheduled — on a host with no shell, a cleanup job
 *     that has quietly stopped is the normal failure, and here it costs nothing.
 *   · A GRANT AT A PARENT COVERS ITS CHILDREN. Programme covers each of its editions; platform
 *     covers everything. An event has no parent but the platform: gates_site_events carries no
 *     award link today.
 *   · REVOKING IS A STAMP, never a delete, so who had what and when stays answerable.
 *
 * Volunteers are member actors and are never admin actors (handoff §0.4): nothing here is read
 * by AdminAuthMiddleware, and a member grant opens nothing under /admin.
 */
final class Grants
{
    /** What each capability lets its holder do, in the words the access page shows. */
    public const CAPABILITIES = [
        'event.lead'                => 'Lead this event',
        // An event's OWNER approves what its leads propose about who may do what there
        // (EventPlan\Teams). Given by staff, never by a lead (owner, 3 Nov 2026).
        'event.own'                 => 'Own this event',
        // A role a lead defined and an owner approved; what it opens is the role's ticks
        // from EventPlan\EventPermissions::MENU, read live, never this word.
        'event.role'                => 'Event team role',
        // An event's FINANCE ADMIN (owner, 12 Nov 2026): decides its spend requests, approves
        // its spend lines and signs off its money gates. Never plans spend — the lead does —
        // so the person who asks for money is never the one who approves it. Given by staff.
        'event.finance'             => 'Finance admin for this event',
        'award.moderate'            => 'Moderate this award',
        'vol.nomination_check'      => 'Nomination check',
        'vol.voter_drive'           => 'Voter drive',
        'vol.nominee_outreach'      => 'Nominee outreach',
        'vol.door'                  => 'Door steward',
        'vol.usher'                 => 'Usher',
        'vol.interview_scheduling'  => 'Interview scheduling',
        'vol.team_lead'             => 'Team lead',
        // The same, for a role the lead made a VOLUNTEER role: the vetting rule holds
        // (RoleInvites::eligible reads the `vol.` prefix), and the role shows in the workspace.
        'vol.event_role'            => 'Event team role (volunteer)',
    ];

    public const SCOPES = ['platform', 'programme', 'cycle', 'event'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_grants');
    }

    /**
     * Give somebody a capability at a scope until a date.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function grant(string $actorKind, int $actorId, string $capability, string $scopeKind, ?int $scopeId,
                                 string $expiresAt, ?int $grantedBy = null, string $note = '', ?string $startsAt = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!isset(self::CAPABILITIES[$capability])) return ['ok' => false, 'error' => 'That is not a role this platform knows.'];
        if (!in_array($scopeKind, self::SCOPES, true)) return ['ok' => false, 'error' => 'That is not a place a role can be given.'];
        if ($scopeKind !== 'platform' && !$scopeId) return ['ok' => false, 'error' => 'Say which award or event the role is for.'];
        // Who asks for money and who approves it stay two people (AUDIT-AND-PLAN EV-5). The
        // owner's decision held this on ONE path (Changes::decide); staff giving a finance
        // admin directly, or later making that person a lead, put both in one pair of hands.
        // Checked here, where every road to a grant ends.
        if ($scopeKind === 'event' && $scopeId && ($clash = self::moneyClash($actorKind, $actorId, $capability, (int) $scopeId)) !== null) {
            return ['ok' => false, 'error' => $clash];
        }
        $end = strtotime($expiresAt);
        if ($end === false) return ['ok' => false, 'error' => 'Every role needs an end date.'];
        if ($end <= time()) return ['ok' => false, 'error' => 'The end date has already passed.'];
        $id = (int) DB::table('gates_grants')->insertGetId([
            'actor_kind' => $actorKind, 'actor_id' => $actorId, 'capability' => $capability,
            'scope_kind' => $scopeKind, 'scope_id' => $scopeKind === 'platform' ? null : $scopeId,
            'granted_by' => $grantedBy, 'note' => mb_substr($note, 0, 255) ?: null,
            'starts_at' => $startsAt ? date('Y-m-d H:i:s', (int) strtotime($startsAt)) : null,
            'expires_at' => date('Y-m-d H:i:s', $end), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** A reason this grant would put asking for and approving an event's money in one person, or null. */
    private static function moneyClash(string $actorKind, int $actorId, string $capability, int $eventId): ?string
    {
        $holds = static fn (string $cap): bool => self::allows($actorKind, $actorId, $cap, 'event', $eventId);
        if ($capability === 'event.finance' && ($holds('event.lead') || $holds('event.own'))) {
            return 'A lead or owner of this event cannot also be its finance admin: who asks for money and who approves it stay two people.';
        }
        if (in_array($capability, ['event.lead', 'event.own'], true) && $holds('event.finance')) {
            return 'This person is the event’s finance admin. End that role first: who asks for money and who approves it stay two people.';
        }
        return null;
    }

    public static function revoke(int $grantId, ?int $by = null): bool
    {
        if (!self::ready()) return false;
        return DB::table('gates_grants')->where('id', $grantId)->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $by]) > 0;
    }

    /** Does this person hold this capability here, or at a scope above it, right now? */
    public static function allows(string $actorKind, int $actorId, string $capability, string $scopeKind, ?int $scopeId): bool
    {
        if (!self::ready() || $actorId <= 0) return false;
        $q = self::live(DB::table('gates_grants'))->where('actor_kind', $actorKind)->where('actor_id', $actorId)
            ->where('capability', $capability);
        $q->where(static function ($w) use ($scopeKind, $scopeId) {
            foreach (self::chain($scopeKind, $scopeId) as [$k, $id]) {
                $w->orWhere(static function ($o) use ($k, $id) {
                    $o->where('scope_kind', $k);
                    $id === null ? $o->whereNull('scope_id') : $o->where('scope_id', $id);
                });
            }
        });
        return $q->exists();
    }

    /**
     * Everything a person holds right now, soonest-ending first.
     *
     * @return list<object>
     */
    public static function forActor(string $actorKind, int $actorId, ?string $capabilityPrefix = null): array
    {
        if (!self::ready() || $actorId <= 0) return [];
        $q = self::live(DB::table('gates_grants'))->where('actor_kind', $actorKind)->where('actor_id', $actorId);
        // `!` is the escape both drivers read the same way (CLAUDE.md: LIKE has no common default).
        if ($capabilityPrefix !== null) $q->whereRaw("capability LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $capabilityPrefix) . '%']);
        return $q->orderBy('expires_at')->get()->all();
    }

    /**
     * Who holds a capability at exactly this scope right now.
     *
     * @return list<object>
     */
    public static function holders(string $capability, string $scopeKind, ?int $scopeId): array
    {
        if (!self::ready()) return [];
        $q = self::live(DB::table('gates_grants'))->where('capability', $capability)->where('scope_kind', $scopeKind);
        $scopeId === null ? $q->whereNull('scope_id') : $q->where('scope_id', $scopeId);
        return $q->get()->all();
    }

    /** A scope and every scope above it, nearest first. @return list<array{0:string,1:?int}> */
    public static function chain(string $scopeKind, ?int $scopeId): array
    {
        $out = [];
        if ($scopeKind === 'cycle' && $scopeId) {
            $out[] = ['cycle', $scopeId];
            $p = DB::table('gates_award_cycles')->where('id', $scopeId)->value('programme_id');
            if ($p) $out[] = ['programme', (int) $p];
        } elseif ($scopeKind !== 'platform' && $scopeId) {
            $out[] = [$scopeKind, $scopeId];
        }
        $out[] = ['platform', null];
        return $out;
    }

    /** @param \Illuminate\Database\Query\Builder $q */
    /**
     * THE answer to "is this grant live now": not revoked, not expired, and started. Public so
     * every reader asks it (as `->where(fn ($w) => Grants::live($w, 'g.'))`) instead of spelling
     * the clause again: seven readers did, and none checked `starts_at`, so a role that had
     * not started yet already allowed check-in, team membership and meeting invites
     * (AUDIT-AND-PLAN VO-9). VolunteerControlsTest sweeps for a new copy.
     */
    public static function live($q, string $prefix = '')
    {
        $now = date('Y-m-d H:i:s');
        return $q->whereNull($prefix . 'revoked_at')->where($prefix . 'expires_at', '>', $now)
            ->where(static fn ($w) => $w->whereNull($prefix . 'starts_at')->orWhere($prefix . 'starts_at', '<=', $now));
    }
}
