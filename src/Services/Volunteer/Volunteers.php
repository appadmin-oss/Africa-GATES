<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\DoorWelcome;
use AfricaGates\Services\Grants;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A member who volunteers (plan §7.3 lifecycle: apply → screen → train → activate → work →
 * recognise → offboard; v7 handoff §6).
 *
 * The workspace has four faces, and `role()` is the one place that says which a person sees:
 *
 *   applicant  applied, not yet approved: Application, Training, Profile and access
 *   new        approved, nothing verified yet: Today, Shifts, Tasks, Training
 *   active     approved, with a verified record: the full root
 *   lead       active and holding a live `vol.team_lead` grant: + My team
 *
 * The level is never stored: `Level::of()` computes it from the record on every read, so it
 * cannot be set by hand or drift from the evidence (plan §7.3).
 */
final class Volunteers
{
    public const CONDUCT_VERSION = '3';

    /** Roles an applicant can say they are interested in, with what each involves. */
    public const INTERESTS = [
        'drive'   => 'Voter drives',
        'door'    => 'Door and ushering at events',
        'checks'  => 'Checking nominations',
        'desk'    => 'Registration and help desks',
        'digital' => 'Digitising, translating, writing',
    ];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_volunteers');
    }

    public static function of(int $userId): ?object
    {
        if (!self::ready() || $userId <= 0) return null;
        return DB::table('gates_volunteers')->where('user_id', $userId)->first() ?: null;
    }

    public static function byId(int $id): ?object
    {
        return self::ready() ? (DB::table('gates_volunteers')->where('id', $id)->first() ?: null) : null;
    }

    /** The name a volunteer is shown by: what they chose, else their account name. */
    public static function name(object $v): string
    {
        $n = trim((string) ($v->display_name ?? ''));
        if ($n !== '') return $n;
        return (string) (DB::table('gates_users')->where('id', $v->user_id)->value('name') ?? 'Volunteer');
    }

    public static function firstName(object $v): string
    {
        $n = self::name($v);
        return DoorWelcome::firstName($n) ?: $n;
    }

    /** @return 'applicant'|'new'|'active'|'lead'|'paused'|'left'|'declined' */
    public static function role(object $v): string
    {
        $st = (string) $v->status;
        if ($st !== 'active') return $st;
        if (Grants::forActor('member', (int) $v->user_id, 'vol.team_lead') !== []) return 'lead';
        $kept = DB::table('gates_vol_signups')->where('volunteer_id', $v->id)->where('status', 'kept')->exists();
        $hours = DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'verified')->where('hours', '>', 0)->exists();
        return ($kept || $hours) ? 'active' : 'new';
    }

    /** "Volunteer · Level 2", "Team lead · Level 3", "Applicant" (handoff §6.1). */
    public static function roleLabel(object $v): string
    {
        $role = self::role($v);
        if ($role === 'applicant') return 'Applicant';
        if (!in_array($role, ['new', 'active', 'lead'], true)) return 'Volunteer';
        $lv = Level::of($v)['level'];
        return ($role === 'lead' ? 'Team lead' : 'Volunteer') . ' · Level ' . $lv;
    }

    /**
     * Apply. One application per member; applying again while it waits updates it.
     *
     * @param array<string,mixed> $f
     * @return array{ok:bool, errors:list<string>, id?:int}
     */
    public static function apply(int $userId, array $f): array
    {
        if (!self::ready()) return ['ok' => false, 'errors' => ['The database needs its latest update first.']];
        $e = [];
        $area = trim((string) ($f['area'] ?? ''));
        $dob = trim((string) ($f['dob'] ?? ''));
        $refName = trim((string) ($f['ref_name'] ?? ''));
        $refContact = trim((string) ($f['ref_contact'] ?? ''));
        $interests = array_values(array_intersect(array_keys(self::INTERESTS), (array) ($f['interests'] ?? [])));
        if ($area === '') $e[] = 'Say where you are, so we can suggest shifts near you.';
        $age = null;
        if ($dob === '' || strtotime($dob) === false) {
            $e[] = 'Add your date of birth.';
        } else {
            $age = (int) floor((time() - (int) strtotime($dob)) / (365.25 * 86400));
            if ($age < Rules::get('vol_min_age')) $e[] = sprintf('Volunteers need to be %d or older.', Rules::get('vol_min_age'));
            elseif ($age < Rules::get('vol_guardian_under') && empty($f['guardian'])) {
                $e[] = sprintf('Under %d, a parent or guardian needs to agree. Tick the box once they have.', Rules::get('vol_guardian_under'));
            }
        }
        if ($interests === []) $e[] = 'Pick at least one thing you would like to help with.';
        if ($refName === '' || $refContact === '') $e[] = 'Name one person who can vouch for you, and how to reach them.';
        if (empty($f['conduct'])) $e[] = 'Read and accept the code of conduct.';
        if ($e !== []) return ['ok' => false, 'errors' => $e];

        $now = date('Y-m-d H:i:s');
        $row = [
            'display_name' => mb_substr(trim((string) ($f['display_name'] ?? '')), 0, 190) ?: null,
            'area' => mb_substr($area, 0, 190), 'state' => mb_substr(trim((string) ($f['state'] ?? '')), 0, 64) ?: null,
            'languages' => mb_substr(trim((string) ($f['languages'] ?? '')), 0, 255) ?: null,
            'interests' => implode(',', $interests), 'motivation' => mb_substr(trim((string) ($f['motivation'] ?? '')), 0, 2000) ?: null,
            'dob' => date('Y-m-d', (int) strtotime($dob)),
            'guardian_consent_at' => ($age !== null && $age < Rules::get('vol_guardian_under')) ? $now : null,
            'conduct_version' => self::CONDUCT_VERSION, 'conduct_accepted_at' => $now, 'updated_at' => $now,
        ];
        $have = self::of($userId);
        if ($have && !in_array($have->status, ['applicant', 'declined', 'left'], true)) {
            return ['ok' => false, 'errors' => ['You are already a volunteer.']];
        }
        if ($have) {
            DB::table('gates_volunteers')->where('id', $have->id)->update($row + ['status' => 'applicant', 'applied_at' => $now, 'decline_reason' => null, 'left_at' => null]);
            $id = (int) $have->id;
        } else {
            $id = (int) DB::table('gates_volunteers')->insertGetId($row + ['user_id' => $userId, 'status' => 'applicant', 'applied_at' => $now, 'created_at' => $now]);
        }
        DB::table('gates_vol_references')->where('volunteer_id', $id)->where('status', 'asked')->update(['status' => 'replaced']);
        DB::table('gates_vol_references')->insert(['volunteer_id' => $id, 'name' => mb_substr($refName, 0, 190), 'contact' => mb_substr($refContact, 0, 190),
            'relation' => mb_substr(trim((string) ($f['ref_relation'] ?? '')), 0, 64) ?: null, 'status' => 'asked', 'asked_at' => $now]);
        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    /** Swap the referee an applicant named (the handoff's "Change referee"). */
    public static function changeReferee(object $v, string $name, string $contact): bool
    {
        $name = trim($name);
        $contact = trim($contact);
        if ($name === '' || $contact === '' || $v->status !== 'applicant') return false;
        DB::table('gates_vol_references')->where('volunteer_id', $v->id)->where('status', 'asked')->update(['status' => 'replaced']);
        DB::table('gates_vol_references')->insert(['volunteer_id' => $v->id, 'name' => mb_substr($name, 0, 190),
            'contact' => mb_substr($contact, 0, 190), 'status' => 'asked', 'asked_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    public static function approve(int $id, int $adminId): bool
    {
        return DB::table('gates_volunteers')->where('id', $id)->where('status', 'applicant')
            ->update(['status' => 'active', 'approved_at' => date('Y-m-d H:i:s'), 'approved_by' => $adminId, 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public static function decline(int $id, int $adminId, string $reason): bool
    {
        $reason = trim($reason);
        if ($reason === '') return false;
        return DB::table('gates_volunteers')->where('id', $id)->where('status', 'applicant')
            ->update(['status' => 'declined', 'decline_reason' => mb_substr($reason, 0, 255), 'approved_by' => $adminId, 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /** Mark a referee's reply (staff, after hearing from them). */
    public static function refereeReplied(int $refId, string $note = ''): bool
    {
        return DB::table('gates_vol_references')->where('id', $refId)->where('status', 'asked')
            ->update(['status' => 'replied', 'note' => mb_substr(trim($note), 0, 2000) ?: null, 'replied_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /** @return list<object> */
    public static function references(int $volId): array
    {
        return DB::table('gates_vol_references')->where('volunteer_id', $volId)->where('status', '!=', 'replaced')->orderBy('id')->get()->all();
    }

    /**
     * Save one of the Profile and access forms. Only the named fields are written, each checked.
     *
     * @param 'profile'|'availability'|'notifications' $form
     * @param array<string,mixed> $f
     */
    public static function save(object $v, string $form, array $f): array
    {
        $row = [];
        $allowed = [
            'profile' => ['display_name' => 190, 'languages' => 255, 'area' => 190, 'emergency_contact' => 255],
            'availability' => [], 'notifications' => [],
        ][$form] ?? null;
        if ($allowed === null) return ['ok' => false, 'error' => 'That form is not here.'];
        foreach ($allowed as $k => $len) if (array_key_exists($k, $f)) $row[$k] = mb_substr(trim((string) $f[$k]), 0, $len) ?: null;
        if ($form === 'availability') {
            if (in_array($f['weekdays'] ?? '', ['none', 'evenings', 'any'], true)) $row['weekdays'] = $f['weekdays'];
            if (in_array($f['weekends'] ?? '', ['none', 'sat', 'sun', 'both'], true)) $row['weekends'] = $f['weekends'];
            if (isset($f['max_hours_week'])) $row['max_hours_week'] = max(1, min(60, (int) $f['max_hours_week']));
            if (isset($f['travel_km'])) $row['travel_km'] = max(0, min(500, (int) $f['travel_km']));
            $row['from_home'] = empty($f['from_home']) ? 0 : 1;
        }
        if ($form === 'notifications') {
            if (in_array($f['notify_fit'] ?? '', ['never', 'weekly', 'posted'], true)) $row['notify_fit'] = $f['notify_fit'];
            $row['notify_news'] = empty($f['notify_news']) ? 0 : 1;
            if (in_array($f['channel'] ?? '', ['email', 'whatsapp', 'sms'], true)) $row['channel'] = $f['channel'];
        }
        if ($row === []) return ['ok' => true];
        $row['updated_at'] = date('Y-m-d H:i:s');
        DB::table('gates_volunteers')->where('id', $v->id)->update($row);
        return ['ok' => true];
    }

    public static function pause(object $v, int $weeks): bool
    {
        if (!in_array($weeks, [2, 4, 8], true) || $v->status !== 'active') return false;
        return DB::table('gates_volunteers')->where('id', $v->id)->update([
            'status' => 'paused', 'paused_until' => date('Y-m-d H:i:s', strtotime('+' . $weeks . ' weeks')), 'updated_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    public static function resume(object $v): bool
    {
        return $v->status === 'paused' && DB::table('gates_volunteers')->where('id', $v->id)
            ->update(['status' => 'active', 'paused_until' => null, 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /** Step back for good. The verified record stays theirs; every role they hold ends now. */
    public static function leave(object $v): bool
    {
        foreach (Grants::forActor('member', (int) $v->user_id, 'vol.') as $g) Grants::revoke((int) $g->id, null);
        return DB::table('gates_volunteers')->where('id', $v->id)
            ->update(['status' => 'left', 'left_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public static function dismissTip(object $v): void
    {
        DB::table('gates_volunteers')->where('id', $v->id)->update(['tip_dismissed_at' => date('Y-m-d H:i:s')]);
    }
}
