<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\CrmAudiencePush;
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
        $c = self::clean($v, $f);
        if (isset($c['error'])) return ['ok' => false, 'error' => $c['error']];
        if (empty($f['consent'])) return ['ok' => false, 'error' => 'Only log somebody who agreed to be contacted.'];
        if (empty($f['adult'])) return ['ok' => false, 'error' => 'Only log somebody aged 18 or over. Point a younger person to a parent or teacher.'];
        $today = (int) DB::table('gates_vol_contacts')->where('volunteer_id', $v->id)->where('created_at', '>=', date('Y-m-d 00:00:00'))->count();
        if ($today >= self::PER_DAY) return ['ok' => false, 'error' => 'That’s ' . self::PER_DAY . ' today, the most in a day. Log the rest tomorrow.'];

        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_vol_contacts')->insertGetId($c + ['volunteer_id' => (int) $v->id, 'consent_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Correct a contact this volunteer logged: a misspelt name, a wrong digit. The change goes to
     * the CRM on its next push, onto the same CRM row. Not once they asked not to be contacted:
     * that record is the stop, and it stays as it was.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function edit(object $v, int $id, array $f): array
    {
        $r = self::get($v, $id);
        if (!$r) return ['ok' => false, 'error' => 'That contact isn’t yours.'];
        if ($r->forgotten_at) return ['ok' => false, 'error' => 'They asked not to be contacted, so their record stays as it is.'];
        $c = self::clean($v, $f, $id);
        if (isset($c['error'])) return ['ok' => false, 'error' => $c['error']];
        DB::table('gates_vol_contacts')->where('id', $id)->where('volunteer_id', $v->id)->update($c + ['updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    /**
     * The fields of a contact, checked, or ['error' => …]. One check for logging and editing,
     * so a correction cannot slip past a rule the first entry was held to.
     */
    private static function clean(object $v, array $f, ?int $except = null): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($f['name'] ?? '')));
        $email = strtolower(trim((string) ($f['email'] ?? '')));
        $phone = trim((string) ($f['phone'] ?? ''));
        $interest = (string) ($f['interest'] ?? '');
        if ($name === '' || mb_strlen($name) > 160) return ['error' => 'Add their name.'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'That email address doesn’t look right.'];
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($phone !== '' && (strlen($digits) < 7 || strlen($digits) > 15)) return ['error' => 'That phone number doesn’t look right.'];
        if ($email === '' && $phone === '') return ['error' => 'Add a phone number or an email, so the team can reach them.'];
        if (!isset(self::INTERESTS[$interest])) return ['error' => 'Pick what they’re interested in.'];

        [$kind, $sid, $text] = [null, null, null];
        $place = (string) ($f['place'] ?? '');
        if ($place !== '' && $place !== 'else') {
            [$k, $i] = array_pad(explode(':', $place, 2), 2, '0');
            // Only one of the person's own places, so a crafted post cannot claim another.
            if (!Workspace::place($v, $k, (int) $i)) return ['error' => 'Pick where you met them.'];
            [$kind, $sid] = [$k, (int) $i];
        } else {
            $text = mb_substr(trim((string) ($f['place_text'] ?? '')), 0, 160) ?: null;
        }
        $dupe = DB::table('gates_vol_contacts')->where('volunteer_id', $v->id)->whereNull('forgotten_at')
            ->when($except !== null, static fn ($q) => $q->where('id', '!=', $except))
            ->where(static function ($w) use ($email, $digits) {
                if ($email !== '') $w->orWhere('email', $email);
                if ($digits !== '') $w->orWhere('phone', $digits);
            })->exists();
        if ($dupe) return ['error' => 'You’ve logged them already. It’s under Mine.'];
        return ['name' => $name, 'email' => $email ?: null, 'phone' => $digits ?: null, 'interest' => $interest,
                'scope_kind' => $kind, 'scope_id' => $sid, 'place_text' => $text,
                'note' => mb_substr(trim((string) ($f['note'] ?? '')), 0, 300) ?: null];
    }

    /** One contact this volunteer logged, with ->interest_label, ->where and ->sent; null if not theirs. */
    public static function get(object $v, int $id): ?object
    {
        if (!self::ready()) return null;
        $r = DB::table('gates_vol_contacts')->where('id', $id)->where('volunteer_id', $v->id)->first();
        return $r ? self::dress($r, CrmAudiencePush::state()) : null;
    }

    /**
     * The contacts this volunteer logged, newest first, each dressed like get(). `q` matches a
     * name, an email or a phone (digits only, however it was typed); `interest` is one of INTERESTS.
     *
     * @return list<object>
     */
    public static function mine(object $v, string $q = '', string $interest = ''): array
    {
        if (!self::ready()) return [];
        $q = trim($q);
        $digits = (string) preg_replace('/\D+/', '', $q);
        // `!` escapes LIKE's wildcards: SQLite has no default escape and MySQL's is a backslash.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
        $rows = DB::table('gates_vol_contacts')->where('volunteer_id', $v->id)
            ->when(isset(self::INTERESTS[$interest]), static fn ($w) => $w->where('interest', $interest))
            ->when($q !== '', static fn ($w) => $w->where(static function ($o) use ($like, $digits) {
                $o->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ? ESCAPE '!'", [$like]);
                if (strlen($digits) >= 3) $o->orWhere('phone', 'like', '%' . $digits . '%');
            }))
            ->orderByDesc('id')->limit(200)->get()->all();
        $state = CrmAudiencePush::state();
        return array_map(static fn ($r) => self::dress($r, $state), $rows);
    }

    /** @return array{total:int, month:int, waiting:int, stopped:int} the figures at the top of Mine */
    public static function summary(object $v): array
    {
        $all = self::mine($v);
        $month = date('Y-m-01');
        return ['total' => count($all), 'month' => count(array_filter($all, static fn ($r) => (string) $r->created_at >= $month)),
                'waiting' => count(array_filter($all, static fn ($r) => !$r->sent && !$r->forgotten_at)), 'stopped' => count(array_filter($all, static fn ($r) => $r->forgotten_at))];
    }

    private static function dress(object $r, array $state): object
    {
        $r->interest_label = self::INTERESTS[$r->interest] ?? 'Something else';
        $r->where = self::where($r);
        // Only meaningful while the CRM is connected; otherwise nothing is ever sent, and saying
        // "waiting" for ever would describe a queue that does not exist.
        $r->sent = CrmAudiencePush::ready() && CrmAudiencePush::contactSent((string) $r->updated_at, (int) $r->id, $state);
        return $r;
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
