<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A nominee's own details, on their own account (owner, 30 Oct 2026).
 *
 * The single-use nominee form it replaces could be filled once and never seen again, so a phone
 * number that changed, a photograph sent late or evidence found after the deadline had no way in.
 * Now approval emails a link; the link signs the nominee in (or makes their account) and ties the
 * nominee to that account ONCE; from then on the page is theirs at /account/nomination.
 *
 * Four rules, held here so no screen can break them:
 *   · the nominator's reason is SHOWN and never written — nothing in this class updates
 *     `gates_nominations.reason` or `gates_nominees.story`, and the page has no field for either;
 *   · one account holds a nominee — the link is spent on first use, and a second account
 *     opening a spent link is told so rather than handed somebody else's page;
 *   · every list is capped (3 phones, 3 emails, 6 photographs, 10 evidence items), and every
 *     value is checked the way the rest of the platform checks it (Phone::normalize, the upload
 *     service that reads the bytes rather than the browser's word);
 *   · a file is removed, never deleted: a panel that saw it can still find it.
 */
final class NomineeDetails
{
    public const MAX_PHONES = 3;
    public const MAX_EMAILS = 3;
    public const MAX_PHOTOS = 6;
    public const MAX_EVIDENCE = 10;
    public const BIO_MAX = 1200;
    public const LINK_DAYS = 30;

    /** The checklist, in the order the page shows it. */
    public const STEPS = ['contact' => 'Your phone and email', 'photo' => 'A photograph', 'bio' => 'A few words about you',
                          'evidence' => 'Evidence of your work', 'terms' => 'Accept the terms'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_nominee_details') && SchemaHas::table('gates_nominee_files');
    }

    /**
     * On approval: the nominee's row, seeded with the contact the nominator gave, and a link that
     * signs them in. Returns the link, or '' when there is nothing to send it to.
     */
    public static function invite(int $nomineeId, object $nom): string
    {
        if (!self::ready() || $nomineeId < 1) return '';
        $now = date('Y-m-d H:i:s');
        if (!DB::table('gates_nominee_details')->where('nominee_id', $nomineeId)->exists()) {
            $email = strtolower(trim((string) ($nom->nominee_email ?? '')));
            $phone = trim((string) ($nom->nominee_phone ?? ''));
            DB::table('gates_nominee_details')->insert(['nominee_id' => $nomineeId, 'nomination_id' => (int) ($nom->id ?? 0) ?: null,
                'emails' => json_encode(filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email] : []),
                'phones' => json_encode(($p = Phone::normalize($phone, 'NG')) ? [$p] : []),
                'created_at' => $now, 'updated_at' => $now]);
        }
        $raw = GatedFormService::issue('nominee', $nomineeId, (string) ($nom->nominee_email ?? ''), self::LINK_DAYS);
        return \AfricaGates\Support\SiteUrl::base() . '/nominee/start/' . $raw;
    }

    /**
     * Tie the nominee behind a link to this account. The link is spent here, once.
     *
     * @return array{ok:bool, nominee?:int, error?:string}
     */
    public static function bind(string $raw, int $userId): array
    {
        $row = GatedFormService::resolve($raw);
        if (!$row || $row->purpose !== 'nominee') return ['ok' => false, 'error' => 'That link is not one we sent.'];
        $nid = (int) $row->subject_id;
        $d = DB::table('gates_nominee_details')->where('nominee_id', $nid)->first();
        if ($d && (int) $d->user_id === $userId) return ['ok' => true, 'nominee' => $nid];   // their own, opened again
        $status = GatedFormService::status($row);
        if ($status !== 'ok') return ['ok' => false, 'error' => $status === 'expired'
            ? 'That link has expired. Ask us for a new one from the help page.' : 'That link has already been used. Sign in with the account that used it.'];
        if ($d && $d->user_id) return ['ok' => false, 'error' => 'This nomination is already held by another account.'];
        // Single use, decided by the database: only the request that flips 0 → 1 goes on.
        if (DB::table('gates_form_tokens')->where('id', $row->id)->where('is_used', 0)->update(['is_used' => 1, 'used_at' => date('Y-m-d H:i:s')]) === 0) {
            return ['ok' => false, 'error' => 'That link has already been used.'];
        }
        $now = date('Y-m-d H:i:s');
        if ($d) {
            DB::table('gates_nominee_details')->where('id', $d->id)->update(['user_id' => $userId, 'linked_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('gates_nominee_details')->insert(['nominee_id' => $nid, 'user_id' => $userId, 'emails' => '[]', 'phones' => '[]',
                'linked_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        return ['ok' => true, 'nominee' => $nid];
    }

    /** The nominees this account holds. @return list<object> */
    public static function forUser(int $userId): array
    {
        if (!self::ready() || $userId < 1) return [];
        return DB::table('gates_nominee_details as d')->join('gates_nominees as n', 'n.id', '=', 'd.nominee_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('d.user_id', $userId)->orderByDesc('d.id')->get(['d.*', 'n.name', 'c.title as category'])->all();
    }

    /** One nominee's details, only for the account that holds them. */
    public static function mine(int $nomineeId, int $userId): ?object
    {
        if (!self::ready() || $userId < 1) return null;
        $d = DB::table('gates_nominee_details')->where('nominee_id', $nomineeId)->where('user_id', $userId)->first();
        return $d ?: null;
    }

    /** @return list<string> */
    public static function list(?string $json): array
    {
        $a = json_decode((string) $json, true);
        return is_array($a) ? array_values(array_filter(array_map('strval', $a))) : [];
    }

    /**
     * Phones and emails. Blank boxes are ignored; one bad value refuses the save and names it.
     *
     * @param list<string> $phones @param list<string> $emails
     * @return array{ok:bool, error?:string}
     */
    public static function saveContact(object $d, array $phones, array $emails): array
    {
        $p = [];
        foreach (array_map('trim', $phones) as $raw) {
            if ($raw === '') continue;
            $n = Phone::normalize($raw, 'NG');
            if ($n === null) return ['ok' => false, 'error' => '“' . $raw . '” doesn’t look like a phone number. Start with the country code if it isn’t Nigerian.'];
            $p[$n] = true;
        }
        $p = array_slice($p, 0, self::MAX_PHONES, true);
        $e = [];
        foreach (array_map(static fn ($x) => strtolower(trim((string) $x)), $emails) as $raw) {
            if ($raw === '') continue;
            if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => '“' . $raw . '” doesn’t look like an email address.'];
            $e[$raw] = true;
        }
        $e = array_slice($e, 0, self::MAX_EMAILS, true);
        if (!$p) return ['ok' => false, 'error' => 'Add at least one phone number.'];
        if (!$e) return ['ok' => false, 'error' => 'Add at least one email address.'];
        self::touch($d, ['phones' => json_encode(array_keys($p)), 'emails' => json_encode(array_keys($e))]);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public static function saveBio(object $d, string $bio): array
    {
        $bio = trim((string) preg_replace("/[ \t]+/u", ' ', $bio));
        if (mb_strlen($bio) < 20) return ['ok' => false, 'error' => 'Write at least a sentence or two about yourself.'];
        self::touch($d, ['bio' => mb_substr($bio, 0, self::BIO_MAX)]);
        return ['ok' => true];
    }

    /** @return array{ok:bool} */
    public static function acceptTerms(object $d): array
    {
        if (!$d->terms_at) self::touch($d, ['terms_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public static function addPhoto(object $d, UploadedFileInterface $file, string $label = ''): array
    {
        if (count(self::files((int) $d->nominee_id, 'photo')) >= self::MAX_PHOTOS) return ['ok' => false, 'error' => 'That’s the most photographs we keep (' . self::MAX_PHOTOS . '). Remove one first.'];
        try {
            $up = (new \AfricaGates\Admin\Services\UploadService())->uploadImage($file, 'nominee-photos', 1600, 82, null, 'nominee', (int) $d->nominee_id, 300);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'That photograph could not be used: ' . $e->getMessage()];
        }
        self::file($d, 'photo', (string) $up['path'], null, $label);
        return ['ok' => true];
    }

    /** Evidence is a file or a link, with a label saying what it shows. @return array{ok:bool, error?:string} */
    public static function addEvidence(object $d, ?UploadedFileInterface $file, string $url, string $label): array
    {
        $label = trim($label);
        if ($label === '') return ['ok' => false, 'error' => 'Say what this shows, in a few words.'];
        if (count(self::files((int) $d->nominee_id, 'evidence')) >= self::MAX_EVIDENCE) return ['ok' => false, 'error' => 'That’s the most evidence we keep (' . self::MAX_EVIDENCE . '). Remove one first.'];
        $url = trim($url);
        if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            try {
                $up = (new \AfricaGates\Admin\Services\UploadService())->uploadDocument($file, 'nominee-evidence', 15, null, 'public', 'nominee', (int) $d->nominee_id);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'That file could not be used: ' . $e->getMessage()];
            }
            self::file($d, 'evidence', (string) $up['path'], null, $label);
            return ['ok' => true];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!preg_match('~^https?://~i', $url) || $host === '') return ['ok' => false, 'error' => 'Add a file, or a link starting with https://'];
        self::file($d, 'evidence', null, mb_substr($url, 0, 255), $label);
        return ['ok' => true];
    }

    public static function remove(object $d, int $fileId): bool
    {
        $n = DB::table('gates_nominee_files')->where('id', $fileId)->where('nominee_id', $d->nominee_id)->whereNull('removed_at')
            ->update(['removed_at' => date('Y-m-d H:i:s')]);
        if ($n) self::touch($d, []);
        return $n > 0;
    }

    /** @return list<object> files still shown */
    public static function files(int $nomineeId, string $kind): array
    {
        return DB::table('gates_nominee_files')->where('nominee_id', $nomineeId)->where('kind', $kind)->whereNull('removed_at')->orderBy('id')->get()->all();
    }

    /** What is done and what is left. @return array<string,bool> */
    public static function checklist(object $d): array
    {
        $nid = (int) $d->nominee_id;
        return ['contact' => self::list($d->phones) !== [] && self::list($d->emails) !== [],
                'photo' => self::files($nid, 'photo') !== [],
                'bio' => mb_strlen(trim((string) $d->bio)) >= 20,
                'evidence' => self::files($nid, 'evidence') !== [],
                'terms' => (bool) $d->terms_at];
    }

    /** Why they were nominated, in the nominator's words. Read-only by construction. */
    public static function reason(object $d): string
    {
        $r = $d->nomination_id ? (string) (DB::table('gates_nominations')->where('id', $d->nomination_id)->value('reason') ?? '') : '';
        return trim($r) !== '' ? trim($r) : trim((string) (DB::table('gates_nominees')->where('id', $d->nominee_id)->value('story') ?? ''));
    }

    /**
     * Every contact the platform holds for a set of nominees: what the nominator typed and what the
     * nominee added. For the staff export. @param list<int> $nomineeIds @return array<int,array{phones:list<string>,emails:list<string>}>
     */
    public static function contactsFor(array $nomineeIds): array
    {
        if (!self::ready() || $nomineeIds === []) return [];
        $out = [];
        foreach (DB::table('gates_nominee_details')->whereIn('nominee_id', $nomineeIds)->get(['nominee_id', 'phones', 'emails']) as $r) {
            $out[(int) $r->nominee_id] = ['phones' => self::list($r->phones), 'emails' => self::list($r->emails)];
        }
        return $out;
    }

    private static function file(object $d, string $kind, ?string $path, ?string $url, string $label): void
    {
        DB::table('gates_nominee_files')->insert(['nominee_id' => $d->nominee_id, 'kind' => $kind, 'path' => $path, 'url' => $url,
            'label' => mb_substr(trim($label), 0, 190) ?: null, 'created_at' => date('Y-m-d H:i:s')]);
        self::touch($d, []);
    }

    /** Write, then stamp the first time the checklist is whole. */
    private static function touch(object $d, array $set): void
    {
        DB::table('gates_nominee_details')->where('id', $d->id)->update($set + ['updated_at' => date('Y-m-d H:i:s')]);
        foreach ($set as $k => $v) $d->{$k} = $v;
        if (!$d->completed_at && !in_array(false, self::checklist($d), true)) {
            $d->completed_at = date('Y-m-d H:i:s');
            DB::table('gates_nominee_details')->where('id', $d->id)->update(['completed_at' => $d->completed_at]);
        }
    }
}
