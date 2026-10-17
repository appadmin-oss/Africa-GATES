<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * PASSKEYS — signing in with the device instead of with a secret we hold.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A PASSKEY IS HERE, AND WHY IT IS WORTH THE DEPENDENCY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The private key never leaves the member's device; this platform stores only a public
 * key. So there is nothing on our side worth stealing, nothing to phish (the browser will
 * not offer a credential to a look-alike domain), and nothing to reuse from some other
 * site's breach. That is a different security property from a password, not a nicer
 * password field.
 *
 * The ceremonies are verified by `web-auth/webauthn-lib`. Hand-rolling CBOR decoding, COSE
 * key conversion and signature verification is possible, and the failure mode of getting
 * one of them subtly wrong is an authentication bypass that no test here would notice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DEPLOYMENT FACT THAT MAKES `available()` NECESSARY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `vendor/` is NOT in this repository — .gitignore excludes all of it but the .htaccess —
 * so a deployment that copies changed files rather than shipping a freshly composed tree
 * will have this file and NOT the library it calls. Without the guard the first passkey
 * request is a fatal error, and on a host with no shell the symptom is a 500 on the
 * account page with nothing to read. With it the feature is simply absent, every screen
 * says so, and `app:doctor` names the missing package.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RELYING PARTY ID IS A DOMAIN, AND CHANGING IT ORPHANS EVERY PASSKEY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A credential is bound to the RP ID it was created under. Move the site from `example.org`
 * to `www.example.org` and every passkey stops being offered — not an error, the browser
 * simply has nothing to show, which reads as "passkeys are broken". So it is derived from
 * the site URL by ONE resolver, and the setting exists only to pin it where a deployment
 * spans hosts (an apex serving a `www` front door). It must be the site's own domain or a
 * registrable parent of it; anything else is refused by the browser before a request is made.
 */
final class Passkeys
{
    public const TABLE = 'gates_user_passkeys';

    /** How long a ceremony challenge stays spendable. */
    private const TIMEOUT_MS = 120_000;

    /** Where the pending challenge lives between the two halves of a ceremony. */
    private const SESSION_CREATE = 'passkey_create_options';
    private const SESSION_REQUEST = 'passkey_request_options';

    /** Somebody with no device is not "signed out", they simply have none of these. */
    public const MAX_PER_USER = 10;

    /**
     * Is the library actually here? See the deployment note above.
     *
     * Memoised per process rather than per call: this is asked once per account-page
     * render and once per auth request, and `class_exists` walks the autoloader.
     */
    public static function available(): bool
    {
        static $memo = null;
        return $memo ??= class_exists(\Webauthn\PublicKeyCredentialCreationOptions::class)
            && class_exists(\Webauthn\Denormalizer\WebauthnSerializerFactory::class);
    }

    // ── Identity of this relying party ──────────────────────────────────────

    /** The site origin the browser must be on: scheme://host[:port]. */
    public static function origin(): string
    {
        return rtrim(\AfricaGates\Support\SiteUrl::base(), '/');
    }

    /**
     * The RP ID. Setting first, else the site URL's host.
     *
     * ONE resolver, because the value that MINTS a credential and the value that VERIFIES
     * one disagreeing is not a bug anybody sees — it is a passkey that registers and then
     * never appears again.
     */
    public static function rpId(): string
    {
        $v = null;
        try {
            $v = DB::table('gates_settings')->where('key_name', 'passkey_rp_id')->value('value');
        } catch (\Throwable) {
            // No database yet (installer, console before boot) is not an error.
        }
        $v = trim((string) ($v ?? ''));
        if ($v !== '') return strtolower($v);

        $host = parse_url(self::origin(), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : 'localhost';
    }

    public static function rpName(): string
    {
        return 'Africa GATES';
    }

    // ── Registration ────────────────────────────────────────────────────────

    /**
     * Options for creating a passkey on this member's device, as the JSON the browser
     * expects. The challenge is parked in the session — it is the only thing that stops a
     * captured response being replayed.
     *
     * `excludeCredentials` carries what they already have, so a device that is already
     * enrolled says "you already have one here" instead of silently minting a duplicate
     * the member then cannot tell apart in a list of identical rows.
     */
    public static function creationOptions(object $user): string
    {
        $rp   = \Webauthn\PublicKeyCredentialRpEntity::create(self::rpName(), self::rpId());
        $ue   = \Webauthn\PublicKeyCredentialUserEntity::create(
            (string) $user->email,
            self::userHandle((int) $user->id),
            (string) $user->name,
        );

        $exclude = [];
        foreach (self::recordsFor((int) $user->id) as $rec) {
            $exclude[] = \Webauthn\PublicKeyCredentialDescriptor::create(
                'public-key', $rec->publicKeyCredentialId, $rec->transports);
        }

        $options = \Webauthn\PublicKeyCredentialCreationOptions::create(
            $rp,
            $ue,
            random_bytes(32),
            [
                \Webauthn\PublicKeyCredentialParameters::create('public-key', -7),    // ES256
                \Webauthn\PublicKeyCredentialParameters::create('public-key', -257),  // RS256
            ],
            \Webauthn\AuthenticatorSelectionCriteria::create(
                null,
                // "preferred", not "required": a device with no biometric or PIN would
                // otherwise refuse outright, and a second factor nobody can enrol is worse
                // than a single factor they can.
                \Webauthn\AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                // A DISCOVERABLE credential is what makes the sign-in button work with no
                // email typed first. Without it the browser needs to be told which
                // credential to look for, and there is nothing to tell it with.
                \Webauthn\AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            // NONE. We do not ask the device to prove what make and model it is: that is a
            // certificate chain to validate and a root store to keep current, for an answer
            // this platform would not act on. Nothing here refuses a credential for being
            // from the wrong manufacturer.
            \Webauthn\PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            self::TIMEOUT_MS,
        );

        $json = self::serializer()->serialize($options, 'json');
        $_SESSION[self::SESSION_CREATE] = $json;
        return $json;
    }

    /**
     * Verify what the device sent back and store the credential.
     *
     * @return array{ok:bool, error?:string, id?:int}
     */
    public static function register(object $user, string $responseJson, string $label): array
    {
        $stashed = (string) ($_SESSION[self::SESSION_CREATE] ?? '');
        // The challenge is single-use whatever happens below. A failed attempt that leaves
        // it spendable is a replay window opened by the failure.
        unset($_SESSION[self::SESSION_CREATE]);
        if ($stashed === '') return ['ok' => false, 'error' => 'That took too long — start again.'];

        if (self::countFor((int) $user->id) >= self::MAX_PER_USER) {
            return ['ok' => false, 'error' => 'You already have ' . self::MAX_PER_USER . ' passkeys. Remove one first.'];
        }

        try {
            $serializer = self::serializer();
            $options = $serializer->deserialize(
                $stashed, \Webauthn\PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $serializer->deserialize(
                $responseJson, \Webauthn\PublicKeyCredential::class, 'json');

            $response = $credential->response;
            if (!$response instanceof \Webauthn\AuthenticatorAttestationResponse) {
                return ['ok' => false, 'error' => 'That was not a registration response.'];
            }

            $record = \Webauthn\AuthenticatorAttestationResponseValidator::create(
                self::ceremonies()->creationCeremony()
            )->check($response, $options, self::rpId());
        } catch (\Throwable $e) {
            error_log('[passkey] registration refused: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'That device could not be registered. Try again, or use a different one.'];
        }

        $b64 = self::b64url($record->publicKeyCredentialId);
        $hash = hash('sha256', $b64);
        if (DB::table(self::TABLE)->where('credential_hash', $hash)->exists()) {
            // Already enrolled — here or, in principle, on another account. Either way the
            // honest answer is that nothing changed, not a second row.
            return ['ok' => false, 'error' => 'That device already has a passkey for Africa GATES.'];
        }

        $id = (int) DB::table(self::TABLE)->insertGetId([
            'user_id'         => (int) $user->id,
            'credential_hash' => $hash,
            'credential_id'   => $b64,
            'record_json'     => self::serializer()->serialize($record, 'json'),
            'label'           => mb_substr(trim($label) !== '' ? trim($label) : 'This device', 0, 80),
            'sign_count'      => max(0, (int) $record->counter),
            'aaguid'          => mb_substr((string) $record->aaguid, 0, 64),
            'created_at'      => Carbon::now()->toDateTimeString(),
        ]);

        return ['ok' => true, 'id' => $id];
    }

    // ── Signing in ──────────────────────────────────────────────────────────

    /**
     * Options for a sign-in ceremony, as JSON.
     *
     * `allowCredentials` is EMPTY on purpose: the browser offers whatever discoverable
     * credential it holds for this domain, so somebody signs in without typing anything.
     * Listing credentials here would mean asking for an email first — and answering "which
     * passkeys does this address have" to anybody who asks, which is an account-enumeration
     * oracle wearing a convenience feature's clothes.
     */
    public static function requestOptions(): string
    {
        $options = \Webauthn\PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            self::rpId(),
            [],
            \Webauthn\PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            self::TIMEOUT_MS,
        );
        $json = self::serializer()->serialize($options, 'json');
        $_SESSION[self::SESSION_REQUEST] = $json;
        return $json;
    }

    /**
     * Verify an assertion and return the member it belongs to, or null.
     *
     * The counter is written back on every success. An authenticator that implements it
     * increments on each assertion, so a count that goes BACKWARDS means two devices are
     * answering for one credential — the library refuses that, and this keeps the stored
     * value honest enough for it to.
     */
    public static function verifyAssertion(string $responseJson): ?object
    {
        $stashed = (string) ($_SESSION[self::SESSION_REQUEST] ?? '');
        unset($_SESSION[self::SESSION_REQUEST]);
        if ($stashed === '') return null;

        try {
            $serializer = self::serializer();
            $options = $serializer->deserialize(
                $stashed, \Webauthn\PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $serializer->deserialize(
                $responseJson, \Webauthn\PublicKeyCredential::class, 'json');

            $response = $credential->response;
            if (!$response instanceof \Webauthn\AuthenticatorAssertionResponse) return null;

            $row = DB::table(self::TABLE)
                ->where('credential_hash', hash('sha256', self::b64url($credential->rawId)))
                ->first();
            if (!$row) return null;

            $record = $serializer->deserialize(
                (string) $row->record_json, \Webauthn\CredentialRecord::class, 'json');

            $updated = \Webauthn\AuthenticatorAssertionResponseValidator::create(
                self::ceremonies()->requestCeremony()
            )->check(
                $record,
                $response,
                $options,
                self::rpId(),
                // NULL — nobody was identified before the ceremony. That is not a
                // weaker check, it is the stricter branch: with a user handle supplied
                // the library will accept a response that omits one, and with null it
                // REQUIRES the authenticator to return the handle and match it. Which
                // is the whole basis of a sign-in where nothing was typed first.
                null,
            );
        } catch (\Throwable $e) {
            error_log('[passkey] assertion refused: ' . $e->getMessage());
            return null;
        }

        $user = DB::table('gates_users')
            ->where('id', (int) $row->user_id)->where('status', 'active')->first();
        if (!$user) return null;

        DB::table(self::TABLE)->where('id', (int) $row->id)->update([
            'record_json'  => self::serializer()->serialize($updated, 'json'),
            'sign_count'   => max(0, (int) $updated->counter),
            'last_used_at' => Carbon::now()->toDateTimeString(),
        ]);

        return $user;
    }

    // ── The member's own list ───────────────────────────────────────────────

    /** @return list<object> newest first */
    public static function listFor(int $userId): array
    {
        try {
            return DB::table(self::TABLE)->where('user_id', $userId)
                ->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function countFor(int $userId): int
    {
        try { return (int) DB::table(self::TABLE)->where('user_id', $userId)->count(); }
        catch (\Throwable) { return 0; }
    }

    /** Remove one, scoped to its owner — a row id in a form is a claim, not a right. */
    public static function forget(int $userId, int $id): bool
    {
        return DB::table(self::TABLE)->where('id', $id)->where('user_id', $userId)->delete() > 0;
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    /**
     * The stable, opaque handle the authenticator stores instead of an email address.
     *
     * Derived from the account id through a keyed hash rather than being the id: a user
     * handle is written to the DEVICE and can be read back by anything that can talk to it,
     * so a raw sequential id would tell a reader how many members this platform has and
     * which one they are holding.
     */
    private static function userHandle(int $userId): string
    {
        return hash_hmac('sha256', 'passkey-user:' . $userId, self::handleKey(), true);
    }

    private static function handleKey(): string
    {
        $k = (string) (\AfricaGates\Support\Env::get('APP_KEY') ?? '');
        return $k !== '' ? $k : 'africa-gates-passkey-handle';
    }

    /** @return list<object> the library's records for a member */
    private static function recordsFor(int $userId): array
    {
        $out = [];
        foreach (self::listFor($userId) as $row) {
            try {
                $out[] = self::serializer()->deserialize(
                    (string) $row->record_json, \Webauthn\CredentialRecord::class, 'json');
            } catch (\Throwable) {
                // A record this version cannot read is one we must not offer as an
                // exclusion, but it is not a reason to refuse the whole ceremony.
            }
        }
        return $out;
    }

    private static function ceremonies(): \Webauthn\CeremonyStep\CeremonyStepManagerFactory
    {
        $f = new \Webauthn\CeremonyStep\CeremonyStepManagerFactory();
        // The EXACT origin, scheme and port included. Not "the host, and trust the scheme"
        // — that is the check that keeps a credential minted on this site from being spent
        // from a look-alike one.
        $f->setAllowedOrigins([self::origin()], false);
        return $f;
    }

    private static function serializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        static $memo = null;
        return $memo ??= (new \Webauthn\Denormalizer\WebauthnSerializerFactory(
            \Webauthn\AttestationStatement\AttestationStatementSupportManager::create([
                // `none` only. We do not ask a device to prove its make and model, so the
                // serializer needs no support for any other statement format — and an
                // empty manager cannot read even the attestation object we DO receive.
                new \Webauthn\AttestationStatement\NoneAttestationStatementSupport(),
            ])
        ))->create();
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
