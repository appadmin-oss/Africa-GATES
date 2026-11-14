<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Telling CACENTRE's person directory who holds an account on Africa GATES.
 *
 * ── WHY ────────────────────────────────────────────────────────────────────
 * The estate runs five apps over fourteen identity stores with no common key,
 * four of them here: a community member, a console admin, a judge and a
 * partner organisation's user are four unrelated rows, and one human is
 * routinely several of them. CACENTRE keeps one record per person. We push;
 * it reads no database of ours, which is the posture the estate already takes
 * for money (CFIS ADR 22) and the reason a break-in there cannot read this
 * platform's members.
 *
 * ── WHAT LEAVES, AND WHAT MUST NOT ─────────────────────────────────────────
 * Name, address, whether the address is verified, the local role and whether
 * the account is active. NOT `password_hash`, NOT a passkey, NOT a vote, NOT
 * a point balance, NOT a phone number, NOT `last_login_ip`. CACENTRE refuses
 * a field it did not ask for rather than ignoring it, so an addition here
 * fails loudly on the next run instead of quietly becoming a second copy of
 * everybody's personal data on another host.
 *
 * The role travels and is acted on NOWHERE over there — the estate's standing
 * rule is that the far side says who somebody is and never what they may do.
 * If permission were read off this, promoting an editor here would silently
 * widen their access on four other systems.
 *
 * ── THE SANDBOX DOES NOT GO ────────────────────────────────────────────────
 * `DemoSeeder` mints a judge at `demo.invalid` so a rehearsal can be walked
 * through for real, and `purge()` destroys those rows afterwards. Pushing one
 * puts a fixture into the estate's record of actual humans and then leaves an
 * orphan when the source is deleted. Excluded by the rule the platform
 * already has for this — `SendPolicy::reserved()`, an address at a domain RFC
 * 2606 and 6761 reserve — and not by naming the seeder's address, which would
 * be an enumeration of the one case somebody already found.
 *
 * ── THE DOOR PASSES ARE NOT PEOPLE AND ARE DELIBERATELY ABSENT ─────────────
 * `gates_event_scan_passes` is how volunteers work a door: a time-boxed,
 * revocable capability for "people who exist for four hours and must not end
 * up holding an admin account". They are credentials for a context, not
 * identities, and giving one a person record in the estate directory would
 * undo the decision that keeps them out of the admin table. A door volunteer
 * appears here only if they also hold one of the four accounts below.
 */
final class DirectoryPush
{
    /** Who we are in the directory's app registry. */
    public const APP = 'gates';

    /** The four stores this key may write, and nothing else. */
    public const STORES = ['gates_users', 'gates_admins', 'gates_judges', 'gates_org_users'];

    /** Rows per batch. CACENTRE refuses a bigger one and names this number. */
    public const BATCH = 500;

    /** Must match the receiver's. */
    public const TTL = 300;

    public const TYP = 'dir.push';

    /** Where the member sweep has reached. */
    private const CURSOR = 'dir_push_cursor';

    /* ══ Configuration ═══════════════════════════════════════════════════ */

    /**
     * The push secret — ONE resolver, settings first, env as the fallback.
     *
     * There is no shell on production, so a credential readable only from
     * `.env` is a credential that cannot be set: that is exactly how the
     * whole Google Calendar integration came to be dead while every screen
     * explained itself correctly. Same shape as `GoogleMeetService::gasUrl()`,
     * and the one reader of this key, because two readers of one setting is
     * how the halves of an integration come to disagree about whether it is
     * configured.
     */
    public static function secret(): string
    {
        $v = null;
        try {
            $v = DB::table('gates_settings')->where('key_name', 'dir_push_secret')->value('value');
        } catch (\Throwable) {
            // No database yet (installer, CLI before boot) is not an error:
            // the env fallback below is the whole point of having one.
        }
        $v = is_string($v) ? trim($v) : '';

        return $v !== '' ? $v : trim((string) Env::get('DIR_PUSH_SECRET_GATES', ''));
    }

    /**
     * Is the push configured?
     *
     * A half-set key counts as absent. A push that runs and is refused every
     * hour is noise an operator learns to scroll past; a push that says it is
     * switched off is a fact.
     */
    public static function ready(): bool
    {
        $s = self::secret();
        if (strlen($s) < 32) {
            return false;
        }

        return !preg_match('/^<.*>$/s', $s)
            && !preg_match('/x{8,}/i', $s)
            && !preg_match('/^(your|changeme|replace|example|todo)/i', $s);
    }

    /** Where CACENTRE is. */
    public static function endpoint(): string
    {
        $base = null;
        try {
            $base = DB::table('gates_settings')->where('key_name', 'dir_push_url')->value('value');
        } catch (\Throwable) {
        }
        $base = is_string($base) ? trim($base) : '';
        if ($base === '') {
            $base = trim((string) Env::get('DIR_PUSH_URL', 'https://cacentre.afrovanguard.org.ng'));
        }

        return rtrim($base, '/') . '/api/directory-push.php?app=' . self::APP;
    }

    /* ══ The rows ════════════════════════════════════════════════════════ */

    /**
     * A community member.
     *
     * The only one of the four whose table tracks verification, so the only
     * one that sends true or false. The other three send null, which is a
     * third state meaning "this source does not track it" — sending false
     * would mark every judge and every admin unverified on a screen that is
     * not about them.
     *
     * @param array<string,mixed>|object $r
     * @return array<string,mixed>
     */
    public static function fromUser(array|object $r): array
    {
        $r = (array) $r;

        return [
            'store' => 'gates_users',
            'local_id' => (string) ($r['id'] ?? ''),
            'email' => self::email($r['email'] ?? ''),
            'email_verified' => !empty($r['email_verified']),
            'name' => trim((string) ($r['name'] ?? '')),
            /* A member holds no role on this platform. Empty rather than an
               invented 'member': the column records what the SOURCE says, and
               this source says nothing. */
            'local_role' => '',
            'local_status' => trim((string) ($r['status'] ?? '')),
            'last_seen_at' => self::when($r['last_login_at'] ?? null),
        ];
    }

    /** @param array<string,mixed>|object $r @return array<string,mixed> */
    public static function fromAdmin(array|object $r): array
    {
        $r = (array) $r;

        return [
            'store' => 'gates_admins',
            'local_id' => (string) ($r['id'] ?? ''),
            'email' => self::email($r['email'] ?? ''),
            'email_verified' => null,
            'name' => trim((string) ($r['name'] ?? '')),
            'local_role' => trim((string) ($r['role'] ?? '')),
            'local_status' => self::activeWord($r['is_active'] ?? 1),
            'last_seen_at' => self::when($r['last_login_at'] ?? null),
        ];
    }

    /**
     * A judge.
     *
     * `admin_id` is not sent, and that is a decision rather than an omission.
     * It is a real same-person assertion — somebody recorded that this judge
     * IS that console account — but both tables enforce a UNIQUE email, so
     * where the two rows share an address CACENTRE links them on the address
     * alone and the hint adds nothing. Where they do NOT share one, a human
     * should see both records and decide, which is what the review queue is
     * for. Sending it would widen the agreed payload to save a click in the
     * case that wants a person looking.
     *
     * @param array<string,mixed>|object $r
     * @return array<string,mixed>
     */
    public static function fromJudge(array|object $r): array
    {
        $r = (array) $r;

        return [
            'store' => 'gates_judges',
            'local_id' => (string) ($r['id'] ?? ''),
            'email' => self::email($r['email'] ?? ''),
            'email_verified' => null,
            'name' => trim((string) ($r['name'] ?? '')),
            'local_role' => 'judge',
            'local_status' => self::activeWord($r['is_active'] ?? 1),
            'last_seen_at' => null,
        ];
    }

    /** @param array<string,mixed>|object $r @return array<string,mixed> */
    public static function fromOrgUser(array|object $r): array
    {
        $r = (array) $r;

        return [
            'store' => 'gates_org_users',
            'local_id' => (string) ($r['id'] ?? ''),
            'email' => self::email($r['email'] ?? ''),
            'email_verified' => null,
            'name' => trim((string) ($r['name'] ?? '')),
            'local_role' => trim((string) ($r['role'] ?? '')),
            'local_status' => self::activeWord($r['is_active'] ?? 1),
            'last_seen_at' => null,
        ];
    }

    private static function email(mixed $v): string
    {
        return strtolower(trim((string) $v));
    }

    /** `is_active` is a TINYINT; the directory wants a word. */
    private static function activeWord(mixed $v): string
    {
        return empty($v) ? 'inactive' : 'active';
    }

    /**
     * A datetime CACENTRE can store, or null.
     *
     * MySQL normalises a `T`-separated value into a TIMESTAMP column and
     * SQLite stores the string verbatim, so a row read in dev and the same
     * row read in production do not always carry the same text. Normalised
     * here, once, rather than hoping the far side copes.
     */
    public static function when(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        $t = strtotime($s);

        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    /* ══ The sweep ═══════════════════════════════════════════════════════ */

    /**
     * The three small tables, whole, in batches.
     *
     * Admins, judges and partner users are bounded by how many people run
     * this platform — tens, not thousands — so they go in full each sweep
     * and need no cursor to lose. The members do not, which is why they page.
     *
     * @return list<list<array<string,mixed>>>
     */
    public static function staffBatches(int $limit = self::BATCH): array
    {
        $rows = [];

        foreach (self::rows('gates_admins', ['id', 'email', 'name', 'role', 'is_active', 'last_login_at']) as $r) {
            $rows[] = self::fromAdmin($r);
        }
        foreach (self::rows('gates_judges', ['id', 'email', 'name', 'is_active']) as $r) {
            $rows[] = self::fromJudge($r);
        }
        foreach (self::rows('gates_org_users', ['id', 'email', 'name', 'role', 'is_active']) as $r) {
            $rows[] = self::fromOrgUser($r);
        }

        $rows = self::live($rows);

        return $rows === [] ? [] : array_chunk($rows, max(1, min(self::BATCH, $limit)));
    }

    /**
     * One page of community members, and where the cursor goes next.
     *
     * A rolling sweep rather than a diff: `gates_users` has `created_at` and
     * `last_login_at` and neither is a change marker — a name corrected by
     * support moves nothing. So it walks the table in id order and starts
     * again at the top, and every row is re-sent once a sweep. Each upserts
     * on its source key over there, so the directory is eventually correct
     * for every account rather than exactly correct for the ones a guessed
     * watermark happened to notice.
     *
     * @return array{rows:list<array<string,mixed>>, cursor:int, wrapped:bool, skipped:int}
     */
    public static function memberBatch(int $cursor, int $limit = self::BATCH): array
    {
        $limit = max(1, min(self::BATCH, $limit));
        $raw = [];
        $last = $cursor;

        foreach (
            DB::table('gates_users')
                ->select(['id', 'name', 'email', 'status', 'email_verified', 'last_login_at'])
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($limit)
                ->get() as $r
        ) {
            $raw[] = self::fromUser($r);
            $last = (int) ((array) $r)['id'];
        }

        /* The page size is measured BEFORE the sandbox filter. A page that is
           short only because its reserved-domain rows were dropped is not the
           end of the table, and treating it as one would wrap the cursor
           early and leave every later member unsent for ever. */
        $wrapped = count($raw) < $limit;
        if ($wrapped) {
            $last = 0;
        }

        $rows = self::live($raw);

        return ['rows' => $rows, 'cursor' => $last, 'wrapped' => $wrapped, 'skipped' => count($raw) - count($rows)];
    }

    /**
     * Drop anything at a reserved domain.
     *
     * The platform's own rule, from `SendPolicy::reserved()` — the test that
     * already decides an address can never belong to a real person. Asked as
     * the RULE and not as a list of the sandbox's current addresses, because
     * a list is an enumeration of the one case somebody already found.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function live(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['email'] !== '' && !SendPolicy::reserved((string) $r['email'])
        ));
    }

    /**
     * Read a table, tolerating one this deployment has not migrated yet.
     *
     * `gates_org_users` arrived in a dated migration and a database that has
     * not run it is behind, not broken. One missing table must not stop the
     * other three being pushed.
     *
     * @param  list<string> $cols
     * @return list<array<string,mixed>|object>
     */
    private static function rows(string $table, array $cols): array
    {
        try {
            return DB::table($table)->select($cols)->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /* ══ The wire ════════════════════════════════════════════════════════ */

    /**
     * Sign a batch.
     *
     * `v1.<base64url payload>.<base64url HMAC-SHA256>` — the estate's
     * envelope, not a fifth format. The caller's claims go FIRST and
     * nonce/iat/exp are appended, which is what makes this byte-identical to
     * the other senders: a MAC is over serialised bytes, so the order of the
     * keys is part of the format whether anybody intended it or not.
     * `tests/Unit/DirectoryPushTest.php` holds that against shared vectors —
     * the same file asserted in the other three repositories.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function mint(array $rows, ?string $secret = null, ?int $now = null): string
    {
        $now ??= time();
        $payload = json_encode([
            'rows' => array_values($rows),
            'iss' => self::APP,
            'typ' => self::TYP,
            'nonce' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + self::TTL,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $body = rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
        $mac = hash_hmac('sha256', $body, $secret ?? self::secret(), true);

        return 'v1.' . $body . '.' . rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * Send one batch.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function send(array $rows): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'error' => 'not-configured'];
        }
        if ($rows === []) {
            return ['ok' => true, 'written' => 0];
        }

        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            /* The batch is INSIDE the token and the token is in the body. Not
               a header — five hundred accounts is far past the 8KB a server
               allows there, and that is a limit met on the first real run. */
            CURLOPT_POSTFIELDS => json_encode(['token' => self::mint($rows)], JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            error_log('[directory-push] ' . ($err ?: 'no response'));

            return ['ok' => false, 'error' => 'unreachable'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            error_log('[directory-push] HTTP ' . $code . ' in a shape this does not understand');

            return ['ok' => false, 'error' => 'bad-response', 'http' => $code];
        }
        if (empty($json['ok'])) {
            /* The reason CACENTRE gave, kept. "expired" and "bad_signature"
               are different afternoons — a clock and a key — and collapsing
               them into "push failed" costs whichever one it was. */
            error_log('[directory-push] HTTP ' . $code . ' ' . json_encode($json));
        }

        return $json + ['http' => $code];
    }

    /* ══ The tick ════════════════════════════════════════════════════════ */

    /**
     * One pass: the staff tables at the top of a sweep, then a page of
     * members.
     *
     * The cursor only advances on a push CACENTRE accepted. A page lost to a
     * timeout goes again next tick — the whole sweep is idempotent, and a
     * page skipped is a silent hole in the directory until the sweep comes
     * round again.
     *
     * @return array<string,mixed>
     */
    public static function tick(int $limit = self::BATCH): array
    {
        if (!self::ready()) {
            return ['skipped' => 'no key'];
        }

        $cursor = (int) self::setting(self::CURSOR, '0');
        $out = ['sent' => 0, 'written' => 0, 'refused' => 0, 'sandbox_skipped' => 0];
        $failed = false;

        $note = static function (array $res) use (&$out, &$failed): void {
            $out['written'] += (int) ($res['written'] ?? 0);
            $out['refused'] += (int) ($res['refused'] ?? 0);
            if (empty($res['ok'])) {
                $failed = true;
                $out['error'] = (string) ($res['error'] ?? 'refused');
            }
        };

        if ($cursor <= 0) {
            foreach (self::staffBatches($limit) as $chunk) {
                $out['sent'] += count($chunk);
                $note(self::send($chunk));
            }
        }

        $b = self::memberBatch($cursor, $limit);
        $out['sandbox_skipped'] = $b['skipped'];
        if ($b['rows'] !== []) {
            $out['sent'] += count($b['rows']);
            $note(self::send($b['rows']));
        }

        if (!$failed) {
            self::putSetting(self::CURSOR, (string) $b['cursor']);
        }

        return $out + ['wrapped' => $b['wrapped'], 'cursor' => $failed ? $cursor : $b['cursor']];
    }

    private static function setting(string $key, string $default = ''): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');

            return is_string($v) && $v !== '' ? $v : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function putSetting(string $key, string $value): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => $key],
                ['value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            error_log('[directory-push] cursor: ' . $e->getMessage());
        }
    }
}
