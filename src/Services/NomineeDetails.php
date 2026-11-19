<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A nominee's own details, on their own account (owner, 30 Oct 2026; from the moment they are
 * nominated since 2 Nov 2026).
 *
 * The single-use nominee form it replaces could be filled once and never seen again, so a phone
 * number that changed, a photograph sent late or evidence found after the deadline had no way in.
 * Now approval emails a link; the link signs the nominee in (or makes their account) and ties the
 * nominee to that account ONCE; from then on the page is theirs at /account/nomination.
 *
 * ── FROM THE NOMINATION, NOT ONLY FROM THE APPROVAL ────────────────────────
 * The page used to open only once a nomination was approved, which is after the one moment
 * the nominee's help decides anything: a reviewer who cannot approve for want of one letter
 * or one photograph sent it back to the nominator, while the person able to supply it in a
 * minute had heard nothing useful. So the "You've been nominated" email now carries a link to
 * THIS page ({@see early()}), keyed on the nomination because there is no nominee yet
 * (`nominee_id` NULL, `nomination_id` the nomination a combined repeat was folded into), and
 * approval attaches it to the nominee it creates ({@see invite()}) — one page, one account,
 * from the first email to the last. Before approval the page also says how many more PLACES
 * the award needs nominations from ({@see places()}), the desk's own rule, because that is the
 * other thing a reviewer cannot approve without; and it never shows the nominator's reason,
 * because nobody has read it yet (NominationAftercare: nothing a stranger typed is relayed
 * under our name before a person has looked at it).
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

    /** Can a page exist before approval? (2027_05_02_nominee_details_early.php) */
    public static function earlyReady(): bool
    {
        return self::ready() && SchemaHas::column('gates_nominee_files', 'nomination_id');
    }

    /** A second nomination of the same person inside this window does not email them again. */
    public const QUIET_HOURS = 24;

    /**
     * At nomination: the page for the nomination this one was combined into, seeded with the
     * contact the nominator gave, and a link that signs the nominee in. `url` is '' when nothing
     * may be sent (not ready, already decided, or the address is the NOMINATOR'S own — a link
     * that lets "the nominee" add evidence, delivered to the person who nominated them, would let
     * one person nominate and vouch). `skip` is a repeat folded into a nomination the nominee
     * was sent a link for today: the email would be news to nobody.
     *
     * @return array{url:string, skip:bool, d:?object, nomination:?object}
     */
    public static function early(int $nominationId): array
    {
        $none = ['url' => '', 'skip' => false, 'd' => null, 'nomination' => null];
        if (!self::earlyReady() || $nominationId < 1) return $none;
        $orig = DB::table('gates_nominations')->where('id', $nominationId)->first();
        if (!$orig) return $none;
        $root = NominationCombine::root($orig);
        if (!in_array((string) $root->status, NominationCombine::OPEN, true)) return $none;
        if (NominationSignals::contactIsNominators($orig) || NominationSignals::contactIsNominators($root)) return $none;
        $email = strtolower(trim((string) ($orig->nominee_email ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $none;

        $d = DB::table('gates_nominee_details')->where('nomination_id', $root->id)->orderBy('id')->first();
        if ($d && $d->nominee_id) return $none;                 // approved: the approval email takes over
        if ((int) $root->id !== $nominationId && DB::table('gates_form_tokens')->where('purpose', 'nomination')
                ->where('subject_id', $root->id)->where('created_at', '>', date('Y-m-d H:i:s', time() - self::QUIET_HOURS * 3600))->exists()) {
            return ['skip' => true] + $none;
        }
        $now = date('Y-m-d H:i:s');
        if (!$d) {
            $phone = trim((string) ($orig->nominee_phone ?? ''));
            DB::table('gates_nominee_details')->insert(['nominee_id' => null, 'nomination_id' => (int) $root->id,
                'emails' => json_encode([$email]), 'phones' => json_encode(($p = Phone::normalize($phone, 'NG')) ? [$p] : []),
                'created_at' => $now, 'updated_at' => $now]);
            $d = DB::table('gates_nominee_details')->where('nomination_id', $root->id)->orderBy('id')->first();
        }
        $raw = GatedFormService::issue('nomination', (int) $root->id, $email, self::LINK_DAYS);
        return ['url' => \AfricaGates\Support\SiteUrl::base() . '/nominee/start/' . $raw, 'skip' => false, 'd' => $d, 'nomination' => $root];
    }

    /**
     * The "You've been nominated" email's variables: the link, what THIS nomination lacks, and the
     * places still needed. Never anything the nominator typed. With no link the email is the
     * plain notice it was; with `skip` it is not sent at all.
     *
     * @return array{complete_url:string, steps:list<array{0:string,1:string}>, places_short:int, places_need:int,
     *               nominate_url:string, link_days:int, no_pay:string, skip:bool}
     */
    public static function forEmail(int $nominationId): array
    {
        $out = ['complete_url' => '', 'steps' => [], 'places_short' => 0, 'places_need' => 0, 'nominate_url' => '',
                'link_days' => self::LINK_DAYS, 'no_pay' => CycleLetter::LOCKED, 'skip' => false];
        try {
            $e = self::early($nominationId);
            if ($e['skip']) return ['skip' => true] + $out;
            if ($e['url'] === '' || !$e['d'] || !$e['nomination']) return $out;
            $places = self::places($e['nomination']);
            return ['complete_url' => $e['url'], 'steps' => self::asks($e['d'], $e['nomination']),
                    'places_short' => $places['short'] ?? 0, 'places_need' => $places['need'] ?? 0,
                    'nominate_url' => $places['nominate_url'] ?? ''] + $out;
        } catch (\Throwable $x) {
            error_log('[nominee-early] ' . $x->getMessage());
            return $out;
        }
    }

    /** Where this page lives: by nominee once approved, by nomination until then. */
    public static function href(object $d): string
    {
        return $d->nominee_id ? '/account/nomination/' . (int) $d->nominee_id : '/account/nomination/pending/' . (int) $d->nomination_id;
    }

    /** The nomination behind a page that has no nominee yet, or null. */
    public static function nomination(object $d): ?object
    {
        if ($d->nominee_id || !$d->nomination_id) return null;
        return DB::table('gates_nominations')->where('id', $d->nomination_id)->first() ?: null;
    }

    /** May a not-yet-approved page still be added to? Only while the nomination waits for review. */
    public static function open(object $d): bool
    {
        if ($d->nominee_id) return true;
        $n = self::nomination($d);
        return $n !== null && in_array((string) $n->status, NominationCombine::OPEN, true);
    }

    /**
     * How many more PLACES the award still needs nominations from, or null when the rule is off
     * or met. The same AwardService::eligibilityMany() the review desk and the approve gate ask,
     * so the page can never tell a nominee they are short when Approve would let them through.
     *
     * @return array{have:int, need:int, short:int, nominate_url:string}|null
     */
    public static function places(object $nom): ?array
    {
        try {
            $e = (new AwardService(new SpamService()))->eligibilityMany([[(string) $nom->nominee_name, (int) $nom->cycle_id]])
                [AwardService::eligibilityKey((string) $nom->nominee_name, (int) $nom->cycle_id)] ?? null;
            if (!$e || !$e['enabled'] || $e['eligible']) return null;
            $slug = (string) (DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', (int) $nom->cycle_id)->value('p.slug') ?? '');
        } catch (\Throwable) {
            return null;
        }
        return ['have' => (int) $e['distinct_locations'], 'need' => (int) $e['min'], 'short' => max(1, (int) $e['min'] - (int) $e['distinct_locations']),
                'nominate_url' => $slug !== '' ? \AfricaGates\Support\SiteUrl::base() . '/nominate/' . rawurlencode($slug) : ''];
    }

    /** Evidence the NOMINATOR sent with the nomination — counted, never shown to the nominee before review. */
    public static function nominationEvidence(object $nom): int
    {
        $n = 0;
        try {
            if (SchemaHas::table('gates_nomination_evidence')) {
                $n = (int) DB::table('gates_nomination_evidence')->where('nomination_id', (int) $nom->id)->count();
            }
        } catch (\Throwable) {}
        foreach (['reference_url', 'reference_url_2', 'reference_url_3'] as $c) {
            if (trim((string) ($nom->{$c} ?? '')) !== '') $n++;
        }
        return $n;
    }

    /**
     * What the nominated email asks for, in the order that decides an approval: evidence first
     * (none is what stops one; some is good and more from different sources is better), then the
     * essentials the page's checklist still lacks. Read from THIS nomination, never a generic list.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function asks(object $d, object $nom): array
    {
        $check = self::checklist($d);
        $ev = self::nominationEvidence($nom) + count(self::filesOf($d, 'evidence'));
        $org = in_array((string) ($nom->nominee_kind ?? 'person'), ['organisation', 'business'], true);
        $out = [$ev === 0
            ? ['Add one piece of evidence', 'A photo of the work, a letter, a certificate or a link. A reviewer needs at least one to approve a nomination.']
            : ['Add more evidence', ($ev === 1 ? 'There is 1 piece' : 'There are ' . $ev . ' pieces') . ' on file. More, from different sources, makes the nomination stronger.']];
        if (!$check['photo'] && trim((string) ($nom->nominee_photo_path ?? '')) === '') {
            $out[] = $org ? ['Add your logo', 'It is what people see on your ballot page.'] : ['Add a clear photo of yourself', 'It is what people see on your ballot page.'];
        }
        if (self::list($d->phones) === []) $out[] = ['Add a phone number', 'So we can reach you about the award. It is never shown publicly.'];
        if (!$check['bio']) $out[] = ['Say a few words about your work', 'In your own words: what you do, and what you are proud of.'];
        return $out;
    }

    /**
     * On approval: the nominee's row, seeded with the contact the nominator gave, and a link that
     * signs them in. Returns the link, or '' when there is nothing to send it to.
     */
    public static function invite(int $nomineeId, object $nom): string
    {
        if (!self::ready() || $nomineeId < 1) return '';
        $now = date('Y-m-d H:i:s');
        self::attach($nomineeId, $nom);
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
     * Approval: the page the nominee may have filled in before review becomes the nominee's —
     * the row and every file added to it — so nothing they sent has to be sent again.
     * Public and idempotent because approval calls it even when no email goes out: a photo
     * sent before review must reach the ballot whether or not the nominee is mailed a link.
     */
    public static function adopt(int $nomineeId, object $nom): void
    {
        self::attach($nomineeId, $nom);
    }

    private static function attach(int $nomineeId, object $nom): void
    {
        if (!self::earlyReady() || empty($nom->id) || DB::table('gates_nominee_details')->where('nominee_id', $nomineeId)->exists()) return;
        $root = NominationCombine::root($nom);
        $d = DB::table('gates_nominee_details')->whereNull('nominee_id')->whereIn('nomination_id', array_unique([(int) $root->id, (int) $nom->id]))->orderBy('id')->first();
        if (!$d) return;
        DB::table('gates_nominee_details')->where('id', $d->id)->update(['nominee_id' => $nomineeId, 'updated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_nominee_files')->whereNull('nominee_id')->where('nomination_id', $d->nomination_id)->update(['nominee_id' => $nomineeId]);
    }

    /**
     * Tie the nominee behind a link to this account. The link is spent here, once.
     *
     * @return array{ok:bool, nominee?:int, error?:string}
     */
    public static function bind(string $raw, int $userId): array
    {
        $row = GatedFormService::resolve($raw);
        if (!$row || !in_array($row->purpose, ['nominee', 'nomination'], true)) return ['ok' => false, 'error' => 'That link is not one we sent.'];
        $early = $row->purpose === 'nomination';
        $nid = (int) $row->subject_id;
        // An early link names the nomination; by the time it is opened that may be approved, and
        // the page then lives under the nominee it was attached to.
        $d = $early ? DB::table('gates_nominee_details')->where('nomination_id', $nid)->orderBy('id')->first()
                    : DB::table('gates_nominee_details')->where('nominee_id', $nid)->first();
        if ($early && !$d) return ['ok' => false, 'error' => 'That link is not one we sent.'];
        if ($d && (int) $d->user_id === $userId) return ['ok' => true, 'nominee' => (int) $d->nominee_id, 'href' => self::href($d)];   // their own, opened again
        if ($early && !self::open($d)) return ['ok' => false, 'error' => 'This nomination is no longer waiting for review, so this link has closed.'];
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
            $d->user_id = $userId;
        } else {
            DB::table('gates_nominee_details')->insert(['nominee_id' => $nid, 'user_id' => $userId, 'emails' => '[]', 'phones' => '[]',
                'linked_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $d = (object) ['nominee_id' => $nid, 'nomination_id' => null];
        }
        // The page now has an account: the photo showing on the ballot becomes that account's
        // photo too, if the account has none yet (NomineePhoto).
        if ((int) $d->nominee_id > 0) NomineePhoto::sync((int) $d->nominee_id);
        return ['ok' => true, 'nominee' => (int) $d->nominee_id, 'href' => self::href($d)];
    }

    /** The nominees this account holds. @return list<object> */
    public static function forUser(int $userId): array
    {
        if (!self::ready() || $userId < 1) return [];
        // A page waiting for review is listed while it can still be added to; one whose
        // nomination was turned down is not, so the account never asks them to "finish" it.
        return DB::table('gates_nominee_details as d')->leftJoin('gates_nominees as n', 'n.id', '=', 'd.nominee_id')
            ->leftJoin('gates_nominations as nm', 'nm.id', '=', 'd.nomination_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', DB::raw('COALESCE(n.category_id, nm.category_id)'))
            ->where('d.user_id', $userId)
            ->where(static fn ($q) => $q->whereNotNull('n.id')->orWhere(static fn ($w) => $w->whereNull('d.nominee_id')->whereIn('nm.status', NominationCombine::OPEN)))
            ->orderByDesc('d.id')->get(['d.*', DB::raw('COALESCE(n.name, nm.nominee_name) as name'), 'c.title as category'])->all();
    }

    /** A page still waiting for review, only for the account that holds it. */
    public static function minePending(int $nominationId, int $userId): ?object
    {
        if (!self::ready() || $userId < 1) return null;
        $d = DB::table('gates_nominee_details')->where('nomination_id', $nominationId)->whereNull('nominee_id')->where('user_id', $userId)->first();
        return $d ?: null;
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
        if (count(self::filesOf($d, 'photo')) >= self::MAX_PHOTOS) return ['ok' => false, 'error' => 'That’s the most photographs we keep (' . self::MAX_PHOTOS . '). Remove one first.'];
        try {
            $up = (new \AfricaGates\Admin\Services\UploadService())->uploadImage($file, 'nominee-photos', 1600, 82, null, self::owner($d)[0], self::owner($d)[1], 300);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'That photograph could not be used: ' . $e->getMessage()];
        }
        DB::connection()->transaction(function () use ($d, $up, $label) {
            self::file($d, 'photo', (string) $up['path'], null, $label);
            if ($d->nominee_id) NomineePhoto::sync((int) $d->nominee_id);
        });
        NomineePortrait::refresh();
        return ['ok' => true];
    }

    /** Evidence is a file or a link, with a label saying what it shows. @return array{ok:bool, error?:string} */
    public static function addEvidence(object $d, ?UploadedFileInterface $file, string $url, string $label): array
    {
        $label = trim($label);
        if ($label === '') return ['ok' => false, 'error' => 'Say what this shows, in a few words.'];
        if (count(self::filesOf($d, 'evidence')) >= self::MAX_EVIDENCE) return ['ok' => false, 'error' => 'That’s the most evidence we keep (' . self::MAX_EVIDENCE . '). Remove one first.'];
        $url = trim($url);
        if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            try {
                $up = (new \AfricaGates\Admin\Services\UploadService())->uploadDocument($file, 'nominee-evidence', 15, null, 'public', self::owner($d)[0], self::owner($d)[1]);
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
        $n = self::scope($d)->where('id', $fileId)->whereNull('removed_at')->update(['removed_at' => date('Y-m-d H:i:s')]);
        if ($n) {
            self::touch($d, []);
            if ($d->nominee_id) NomineePhoto::sync((int) $d->nominee_id);
            NomineePortrait::refresh();
        }
        return $n > 0;
    }

    /** @return list<object> files still shown */
    public static function files(int $nomineeId, string $kind): array
    {
        return DB::table('gates_nominee_files')->where('nominee_id', $nomineeId)->where('kind', $kind)->whereNull('removed_at')->orderBy('id')->get()->all();
    }

    /** The files on THIS page — by nominee once approved, by nomination until then. @return list<object> */
    public static function filesOf(object $d, string $kind): array
    {
        if ($d->nominee_id) return self::files((int) $d->nominee_id, $kind);
        return self::scope($d)->where('kind', $kind)->whereNull('removed_at')->orderBy('id')->get()->all();
    }

    /** Every file row this page owns, and nobody else's. */
    private static function scope(object $d): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('gates_nominee_files');
        return $d->nominee_id ? $q->where('nominee_id', $d->nominee_id)
                              : $q->whereNull('nominee_id')->where('nomination_id', (int) $d->nomination_id);
    }

    /** What an upload is attached to, for the upload log. @return array{0:string, 1:int} */
    private static function owner(object $d): array
    {
        return $d->nominee_id ? ['nominee', (int) $d->nominee_id] : ['nomination', (int) $d->nomination_id];
    }

    /** What is done and what is left. @return array<string,bool> */
    public static function checklist(object $d): array
    {
        return ['contact' => self::list($d->phones) !== [] && self::list($d->emails) !== [],
                'photo' => self::filesOf($d, 'photo') !== [],
                'bio' => mb_strlen(trim((string) $d->bio)) >= 20,
                'evidence' => self::filesOf($d, 'evidence') !== [],
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
        DB::table('gates_nominee_files')->insert(['nominee_id' => $d->nominee_id ?: null, 'kind' => $kind, 'path' => $path, 'url' => $url,
            'label' => mb_substr(trim($label), 0, 190) ?: null, 'created_at' => date('Y-m-d H:i:s')]
            + (self::earlyReady() ? ['nomination_id' => $d->nomination_id ?: null] : []));
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
