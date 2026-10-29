<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * People a volunteer meets who want to hear from Africa GATES (owner, 29 Oct 2026), logged in
 * the workspace and pushed to the CACENTRE CRM as enquiries (CrmAudiencePush), so the team can
 * follow up from one list.
 *
 * ── THEIR AGREEMENT, OR NOTHING ──────────────────────────────────────────────
 *
 * A contact is somebody else's personal data, given to us by a third person. So it is taken
 * only with two things the volunteer confirms they asked: that the person agreed to be
 * contacted about what they said they were interested in, and that they are 18 or older. A
 * child's details are never logged here, by the same rule that keeps nominees and minors off
 * the CRM. And "they asked not to be contacted" is one press, sent on to the CRM as a stop.
 *
 * ── RECORDED, NEVER SENT ─────────────────────────────────────────────────────
 *
 * A volunteer logs; the team follows up. Nothing here messages a contact, singly or in bulk
 * (the owner: no broadcast). A volunteer sees only the contacts they logged themselves.
 */
final class Contacts
{
    /** What the person is interested in, in the words the CRM shows. */
    public const INTERESTS = [
        'volunteer' => 'Volunteering',
        'nominate'  => 'Nominating someone',
        'vote'      => 'Voting',
        'partner'   => 'Sponsoring or partnering',
        'attend'    => 'Coming to an event',
        'other'     => 'Something else',
    ];

    /** A day's ceiling per volunteer: room for a busy drive, not for a pasted list. */
    public const PER_DAY = 60;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_contacts');
    }

    /**
     * Log a contact. `place` is "kind:id" for one of the person's own places, or "else" with
     * `place_text` saying where.
     *
     * @return array{ok:bool, error?:string, id?:int}
     */
    public static function log(object $v, array $f): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Contacts are not set up yet.'];
        if (!in_array(Volunteers::role($v), ['new', 'active', 'lead'], true)) return ['ok' => false, 'error' => 'Contacts open once you’re approved.'];
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($f['name'] ?? '')));
        $email = strtolower(trim((string) ($f['email'] ?? '')));
        $phone = trim((string) ($f['phone'] ?? ''));
        $interest = (string) ($f['interest'] ?? '');
        if ($name === '' || mb_strlen($name) > 160) return ['ok' => false, 'error' => 'Add their name.'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'That email address doesn’t look right.'];
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($phone !== '' && (strlen($digits) < 7 || strlen($digits) > 15)) return ['ok' => false, 'error' => 'That phone number doesn’t look right.'];
        if ($email === '' && $phone === '') return ['ok' => false, 'error' => 'Add a phone number or an email, so the team can reach them.'];
        if (!isset(self::INTERESTS[$interest])) return ['ok' => false, 'error' => 'Pick what they’re interested in.'];
        if (empty($f['consent'])) return ['ok' => false, 'error' => 'Only log somebody who agreed to be contacted.'];
        if (empty($f['adult'])) return ['ok' => false, 'error' => 'Only log somebody aged 18 or over. Point a younger person to a parent or teacher.'];

        [$kind, $sid, $text] = [null, null, null];
        $place = (string) ($f['place'] ?? '');
        if ($place !== '' && $place !== 'else') {
            [$k, $i] = array_pad(explode(':', $place, 2), 2, '0');
            // Only one of the person's own places, so a crafted post cannot claim another.
            if (!Workspace::place($v, $k, (int) $i)) return ['ok' => false, 'error' => 'Pick where you met them.'];
            [$kind, $sid] = [$k, (int) $i];
        } else {
            $text = mb_substr(trim((string) ($f['place_text'] ?? '')), 0, 160) ?: null;
        }

        $today = (int) DB::table('gates_vol_contacts')->where('volunteer_id', $v->id)->where('created_at', '>=', date('Y-m-d 00:00:00'))->count();
        if ($today >= self::PER_DAY) return ['ok' => false, 'error' => 'That’s ' . self::PER_DAY . ' today, the most in a day. Log the rest tomorrow.'];
        $dupe = DB::table('gates_vol_contacts')->where('volunteer_id', $v->id)->whereNull('forgotten_at')
            ->where(static function ($w) use ($email, $digits) {
                if ($email !== '') $w->orWhere('email', $email);
                if ($digits !== '') $w->orWhere('phone', $digits);
            })->exists();
        if ($dupe) return ['ok' => false, 'error' => 'You’ve logged them already. It’s under Mine.'];

        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_vol_contacts')->insertGetId([
            'volunteer_id' => (int) $v->id, 'name' => $name, 'email' => $email ?: null, 'phone' => $digits ?: null,
            'interest' => $interest, 'scope_kind' => $kind, 'scope_id' => $sid, 'place_text' => $text,
            'note' => mb_substr(trim((string) ($f['note'] ?? '')), 0, 300) ?: null,
            'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** @return list<object> the contacts this volunteer logged, newest first, each with ->where and ->interest_label */
    public static function mine(object $v): array
    {
        if (!self::ready()) return [];
        $rows = DB::table('gates_vol_contacts')->where('volunteer_id', $v->id)->orderByDesc('id')->limit(200)->get()->all();
        foreach ($rows as $r) {
            $r->interest_label = self::INTERESTS[$r->interest] ?? 'Something else';
            $r->where = self::where($r);
        }
        return $rows;
    }

    /** "They asked not to be contacted": kept, marked, and sent to the CRM as a stop. */
    public static function forget(object $v, int $id): bool
    {
        if (!self::ready()) return false;
        $now = date('Y-m-d H:i:s');
        return DB::table('gates_vol_contacts')->where('id', $id)->where('volunteer_id', $v->id)->whereNull('forgotten_at')
            ->update(['forgotten_at' => $now, 'updated_at' => $now]) > 0;
    }

    /** Where they met, in words. */
    public static function where(object $r): string
    {
        if ($r->scope_kind && $r->scope_id) return Shifts::scopeName((string) $r->scope_kind, (int) $r->scope_id);
        return (string) ($r->place_text ?? '');
    }
}
