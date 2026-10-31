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
        'award.moderate'            => 'Moderate this award',
        'vol.nomination_check'      => 'Nomination check',
        'vol.voter_drive'           => 'Voter drive',
        'vol.nominee_outreach'      => 'Nominee outreach',
        'vol.door'                  => 'Door steward',
        'vol.usher'                 => 'Usher',
        'vol.interview_scheduling'  => 'Interview scheduling',
        'vol.team_lead'             => 'Team lead',
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
    private static function live($q)
    {
        $now = date('Y-m-d H:i:s');
        return $q->whereNull('revoked_at')->where('expires_at', '>', $now)
            ->where(static fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now));
    }
}
