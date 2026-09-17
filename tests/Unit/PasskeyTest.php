<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Passkeys;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * PASSKEYS, DRIVEN BY A SOFTWARE AUTHENTICATOR.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A FAKE DEVICE AND NOT A MOCK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The thing that can go wrong here is not "does the method get called". It is whether a
 * signature actually verifies, whether the challenge actually has to match, and whether a
 * credential minted for one origin is actually refused at another. Every one of those is a
 * property of real bytes, and a mocked validator asserts none of them — it asserts that
 * the code under test believes whatever it was told to believe, which is exactly the
 * failure mode an authentication bypass has.
 *
 * So this builds the real thing: a P-256 key in openssl, a CBOR attestation object with
 * `fmt: none`, authenticator data with the right flag bits and rpIdHash, and an ECDSA
 * signature over `authenticatorData || sha256(clientDataJSON)`. If the library's
 * verification were replaced with `return true` tomorrow, the negative cases below would
 * be the only thing that noticed.
 *
 * ── WHAT THE NEGATIVE CASES ARE FOR ─────────────────────────────────────────
 * A replayed challenge, a signature from the wrong key, and a credential presented from
 * another origin are the three attacks this mechanism exists to stop. A passing
 * registration test proves none of them.
 */
final class PasskeyTest extends TestCase
{
    /**
     * The RP ID is DERIVED, never typed.
     *
     * It was a literal, and these tests then passed alone and failed in the full suite:
     * `Passkeys::rpId()` falls back to the host in `SiteUrl::base()`, which reads
     * `APP_URL` — so any earlier test that sets that environment variable moves the RP ID,
     * the rpIdHash in the authenticator data stops matching, and eight tests fail naming a
     * device that "could not be registered". Which points at the service, not at the
     * fixture that assumed a hostname.
     */
    private static function rpId(): string
    {
        return Passkeys::rpId();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['csrf_token' => 'tok'];
    }

    private function user(): object
    {
        $email = 'pk-' . bin2hex(random_bytes(4)) . '@example.test';
        $id = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ada Obi', 'email' => $email, 'phone' => '+2348000000000',
            'password_hash' => null, 'status' => 'active', 'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return DB::table('gates_users')->where('id', $id)->first();
    }

    // ───────────────────────── the software authenticator ─────────────────────

    /** @return array{priv:\OpenSSLAsymmetricKey, x:string, y:string} a fresh P-256 key */
    private static function key(): array
    {
        $priv = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($priv, 'openssl could not mint a P-256 key');
        $d = openssl_pkey_get_details($priv);
        return [
            'priv' => $priv,
            'x'    => str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT),
            'y'    => str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function clientData(string $type, string $challenge, string $origin): string
    {
        return (string) json_encode([
            'type'        => $type,
            'challenge'   => self::b64($challenge),
            'origin'      => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Authenticator data: rpIdHash(32) ‖ flags(1) ‖ signCount(4, big-endian) ‖ [attested].
     *
     * Flags UP|UV|AT = 0x45. The backup bits are left clear together — `BS` set without
     * `BE` is refused by the library as an inconsistent authenticator, correctly.
     */
    private static function authData(string $rpId, int $flags, int $count, string $attested = ''): string
    {
        return hash('sha256', $rpId, true) . chr($flags) . pack('N', $count) . $attested;
    }

    /** aaguid(16) ‖ credIdLen(2) ‖ credId ‖ COSE key. */
    private static function attestedData(string $credId, string $x, string $y): string
    {
        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))   // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))  // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))  // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        return str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . (string) $cose;
    }

    /** The JSON a browser posts back after `navigator.credentials.create()`. */
    private static function registration(string $credId, array $key, string $challenge, string $origin): string
    {
        $authData = self::authData(self::rpId(), 0x45, 0, self::attestedData($credId, $key['x'], $key['y']));
        $att = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return (string) json_encode([
            'id' => self::b64($credId), 'rawId' => self::b64($credId), 'type' => 'public-key',
            'response' => [
                'clientDataJSON'    => self::b64(self::clientData('webauthn.create', $challenge, $origin)),
                'attestationObject' => self::b64((string) $att),
            ],
        ]);
    }

    /** The JSON a browser posts back after `navigator.credentials.get()`. */
    private static function assertion(
        string $credId, array $key, string $challenge, string $origin, string $userHandle,
        int $count = 1, ?\OpenSSLAsymmetricKey $signWith = null,
    ): string {
        $client   = self::clientData('webauthn.get', $challenge, $origin);
        $authData = self::authData(self::rpId(), 0x05, $count);
        $sig = '';
        openssl_sign($authData . hash('sha256', $client, true), $sig,
                     $signWith ?? $key['priv'], OPENSSL_ALGO_SHA256);

        return (string) json_encode([
            'id' => self::b64($credId), 'rawId' => self::b64($credId), 'type' => 'public-key',
            'response' => [
                'clientDataJSON'    => self::b64($client),
                'authenticatorData' => self::b64($authData),
                'signature'         => self::b64($sig),
                // A DISCOVERABLE credential returns the handle it stored at
                // registration; that is what identifies the account when nothing was
                // typed. An authenticator that omits it is not signing anybody in.
                'userHandle'        => $userHandle,
            ],
        ]);
    }

    /** The user handle the device would have stored, straight off the creation options. */
    private static function handleFrom(string $creationOptionsJson): string
    {
        return (string) (json_decode($creationOptionsJson, true)['user']['id'] ?? '');
    }

    /** The raw challenge out of the options the service just stashed in the session. */
    private static function challengeFrom(string $optionsJson): string
    {
        $d = json_decode($optionsJson, true);
        $raw = (string) ($d['challenge'] ?? '');
        $b64 = strtr($raw, '-_', '+/');
        return (string) base64_decode(str_pad($b64, (int) (ceil(strlen($b64) / 4) * 4), '='), true);
    }

    // ─────────────────────────────── the tests ────────────────────────────────

    public function test_the_library_is_installed(): void
    {
        // `vendor/` is not in this repository, so this is the assertion that tells a
        // deployment apart from a broken one. Everything below is meaningless without it.
        $this->assertTrue(Passkeys::available(),
            'web-auth/webauthn-lib is missing — run composer install');
        // The RELATIONSHIP, not the hostname: a credential is bound to the RP ID, and the
        // browser refuses one that is not the origin's own domain or a registrable parent
        // of it. So what has to hold is that the two agree — whatever the site is called.
        $this->assertSame(parse_url(Passkeys::origin(), PHP_URL_HOST), Passkeys::rpId());
    }

    public function test_a_device_can_register_and_then_sign_in(): void
    {
        $user   = $this->user();
        $key    = self::key();
        $credId = random_bytes(32);

        $created = Passkeys::creationOptions($user);
        $r = Passkeys::register(
            $user,
            self::registration($credId, $key, self::challengeFrom($created), Passkeys::origin()),
            'Ada’s phone');
        $this->assertTrue($r['ok'] ?? false, 'registration refused: ' . ($r['error'] ?? ''));

        $rows = Passkeys::listFor((int) $user->id);
        $this->assertCount(1, $rows);
        $this->assertSame('Ada’s phone', $rows[0]->label);

        $asked = Passkeys::requestOptions();
        $signedIn = Passkeys::verifyAssertion(self::assertion(
            $credId, $key, self::challengeFrom($asked), Passkeys::origin(), self::handleFrom($created)));

        $this->assertNotNull($signedIn, 'a real assertion from the registered key was refused');
        $this->assertSame((int) $user->id, (int) $signedIn->id);

        // The counter the device reported is written back, or the clone check below has
        // nothing to compare against.
        $after = Passkeys::listFor((int) $user->id)[0];
        $this->assertSame(1, (int) $after->sign_count);
        $this->assertNotNull($after->last_used_at);
    }

    public function test_a_signature_from_a_different_key_is_refused(): void
    {
        [$user, $key, $credId, $handle] = $this->enrolled();

        $asked = Passkeys::requestOptions();
        $this->assertNull(Passkeys::verifyAssertion(self::assertion(
            $credId, $key, self::challengeFrom($asked), Passkeys::origin(), $handle, 1,
            self::key()['priv'],      // somebody else's key, everything else identical
        )), 'an assertion signed by the wrong key was accepted');
    }

    public function test_a_replayed_challenge_is_refused(): void
    {
        [$user, $key, $credId, $handle] = $this->enrolled();

        $asked     = Passkeys::requestOptions();
        $challenge = self::challengeFrom($asked);
        // COUNTER ZERO, DELIBERATELY. An authenticator that implements the signature
        // counter also stops a replay, because the second use reports a count that has
        // not advanced — and with any non-zero count this test passes whether or not the
        // challenge is single-use, which is what it is here to prove. It did exactly
        // that until the count was pinned: removing the challenge invalidation changed
        // nothing, because the counter was quietly doing the work.
        //
        // Most real passkeys — the synced, platform kind — report zero for ever, so this
        // is not a contrived case. It is the common one, and the case where the spent
        // challenge is the ONLY thing standing between a captured response and a session.
        $body = self::assertion($credId, $key, $challenge, Passkeys::origin(), $handle, 0);

        $this->assertNotNull(Passkeys::verifyAssertion($body), 'the first use should work');

        $this->assertNull(Passkeys::verifyAssertion($body),
            'a captured assertion could be replayed — the challenge is not single-use');
    }

    public function test_a_stale_challenge_from_an_earlier_ceremony_is_refused(): void
    {
        [$user, $key, $credId, $handle] = $this->enrolled();

        $first = self::challengeFrom(Passkeys::requestOptions());
        Passkeys::requestOptions();   // a second ceremony replaces the first

        $this->assertNull(Passkeys::verifyAssertion(
            self::assertion($credId, $key, $first, Passkeys::origin(), $handle)),
            'an assertion answering a superseded challenge was accepted');
    }

    public function test_a_credential_presented_from_another_origin_is_refused(): void
    {
        [$user, $key, $credId, $handle] = $this->enrolled();

        $asked = Passkeys::requestOptions();
        $this->assertNull(Passkeys::verifyAssertion(self::assertion(
            $credId, $key, self::challengeFrom($asked),
            'https://' . Passkeys::rpId() . '.evil.test', $handle)),
            'a look-alike origin was accepted — this is the phishing resistance');
    }

    public function test_a_subdomain_of_our_own_site_is_a_different_origin(): void
    {
        // The sharper half of the same check, and the one a loosened configuration
        // actually lets through: `sub.<our domain>` passes every rpId suffix test there
        // is, because it genuinely is under our domain. Only an EXACT origin match
        // refuses it — and on a platform where an operator may one day host something
        // else on a subdomain, that is the difference between a credential minted here
        // and a credential spendable from wherever that something else runs.
        [$user, $key, $credId, $handle] = $this->enrolled();

        $asked = Passkeys::requestOptions();
        $this->assertNull(Passkeys::verifyAssertion(self::assertion(
            $credId, $key, self::challengeFrom($asked),
            'https://sub.' . Passkeys::rpId(), $handle)),
            'a subdomain was accepted as this site');
    }

    public function test_registration_without_the_matching_challenge_is_refused(): void
    {
        $user   = $this->user();
        $key    = self::key();
        $credId = random_bytes(32);

        Passkeys::creationOptions($user);
        $r = Passkeys::register($user,
            self::registration($credId, $key, random_bytes(32), Passkeys::origin()), 'Phone');

        $this->assertFalse($r['ok'] ?? false, 'a registration answering no challenge was stored');
        $this->assertCount(0, Passkeys::listFor((int) $user->id));
    }

    public function test_one_device_cannot_be_enrolled_twice(): void
    {
        [$user, $key, $credId] = $this->enrolled();

        $created = Passkeys::creationOptions($user);
        $r = Passkeys::register($user,
            self::registration($credId, $key, self::challengeFrom($created), Passkeys::origin()), 'Again');

        $this->assertFalse($r['ok'] ?? false);
        $this->assertCount(1, Passkeys::listFor((int) $user->id), 'a duplicate row was written');
    }

    public function test_a_passkey_can_only_be_removed_by_its_owner(): void
    {
        [$user] = $this->enrolled();
        $row = Passkeys::listFor((int) $user->id)[0];
        $other = $this->user();

        // A row id in a form is a claim, not a right.
        $this->assertFalse(Passkeys::forget((int) $other->id, (int) $row->id));
        $this->assertCount(1, Passkeys::listFor((int) $user->id));

        $this->assertTrue(Passkeys::forget((int) $user->id, (int) $row->id));
        $this->assertCount(0, Passkeys::listFor((int) $user->id));
    }

    public function test_the_user_handle_written_to_the_device_is_not_the_account_id(): void
    {
        // A user handle is stored ON the authenticator and readable by anything that can
        // talk to it. A raw sequential id would say how many members this platform has.
        $user = $this->user();
        $options = json_decode(Passkeys::creationOptions($user), true);
        $b64 = strtr((string) ($options['user']['id'] ?? ''), '-_', '+/');
        $handle = (string) base64_decode(str_pad($b64, (int) (ceil(strlen($b64) / 4) * 4), '='), true);

        // DECODED before comparing. The first version of this compared the account id
        // against the base64url STRING, and `base64url("42")` is `NDI` — so it passed
        // with the handle set to the raw id, which is the one thing it was written to
        // forbid. An assertion that cannot see the value it is judging is not one.
        $this->assertNotSame('', $handle);
        $this->assertNotSame((string) $user->id, $handle, 'the account id is written to the device');
        $this->assertSame(32, strlen($handle), 'the handle is not a fixed-width digest');

        // And two accounts must not produce handles a reader can order or step through.
        $other = json_decode(Passkeys::creationOptions($this->user()), true);
        $this->assertNotSame($options['user']['id'], $other['user']['id']);
    }

    /** @return array{0:object,1:array,2:string,3:string} a member with one working passkey */
    private function enrolled(): array
    {
        $user   = $this->user();
        $key    = self::key();
        $credId = random_bytes(32);
        $created = Passkeys::creationOptions($user);
        $r = Passkeys::register($user,
            self::registration($credId, $key, self::challengeFrom($created), Passkeys::origin()), 'Phone');
        $this->assertTrue($r['ok'] ?? false, 'fixture failed to enrol: ' . ($r['error'] ?? ''));
        return [$user, $key, $credId, self::handleFrom($created)];
    }
}
