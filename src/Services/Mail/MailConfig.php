<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE ONE ANSWER TO "HOW DO WE SEND MAIL".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: TWO READERS OF ONE SETTING, DISAGREEING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `OtpService::boot()` sent mail and `ProviderProbe::smtp()` reported on it, and each
 * resolved the SMTP settings for itself. They disagreed on the case the settings form
 * tells an operator to choose: "SMTP host — leave blank for the Brevo relay". The sender
 * read blank as Brevo; the probe read blank as "No SMTP host set" and called mail OFF.
 * So the one screen built to answer "is email working?" answered about a configuration
 * the platform was not using — CLAUDE.md's pair most likely to disagree, the one that
 * publishes a value and the one that acts on it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE ENCRYPTION WAS NOT A SETTING AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The transport hard-coded STARTTLS whatever the port. Port 465 is IMPLICIT TLS — the
 * server expects a TLS handshake before it says a word — so STARTTLS there waits for a
 * greeting that never comes, and every send dies on the 12-second timeout with
 * "SMTP connect() failed", which reads as a firewall. 465 is the port most hosts and
 * most providers other than Brevo hand out. `security()` derives it from the port, and
 * `mail_smtp_secure` overrides it for the provider that does something unusual.
 *
 * ── WHERE EACH VALUE CAME FROM ───────────────────────────────────────────────────
 *
 * `source()` records it — settings, env or built-in default — because "it is using the
 * default host" is the single most useful sentence a diagnosis can say, and it cannot
 * be said by anything that only holds the resolved value.
 *
 * Immutable. Built once per use with `load()`; nothing caches it across requests, so
 * an operator who saves the settings form is testing what they saved.
 */
final class MailConfig
{
    public const DEFAULT_HOST = 'smtp-relay.brevo.com';
    public const DEFAULT_PORT = 587;
    public const DEFAULT_FROM = 'noreply@afrovanguard.org.ng';
    public const DEFAULT_POSTAL = 'Afrovanguard, Lagos, Nigeria';

    /** The encryption modes, as the settings form offers them. */
    public const SECURE_AUTO     = 'auto';
    public const SECURE_STARTTLS = 'starttls';
    public const SECURE_SMTPS    = 'smtps';
    public const SECURE_NONE     = 'none';

    /**
     * HOW a message leaves, as the settings form offers it.
     *
     * SMTP was the only road, and on a shared host it is the one most likely to be shut:
     * cPanel's "SMTP Restrictions" and the common firewalls refuse a user account's
     * outbound connections to ports 25, 465 and 587 and route everything through the
     * host's own server. On such a host no SMTP setting can ever work — every fix to the
     * SMTP code changed nothing, and the diagnosis could only advise asking the host to
     * open a port, which a shared host does not do.
     *
     *   auto  SMTP while it works; on a failure that is about the ROAD (it cannot connect,
     *         negotiate TLS or log in), the provider's HTTPS API, then this server's own
     *         mail. A refused RECIPIENT is about the address and never falls through —
     *         it would only bounce a second time.
     *   smtp  SMTP and nothing else.
     *   api   Brevo's transactional API over HTTPS (port 443, which every integration on
     *         this platform already reaches).
     *   host  The server's own mail, through PHP's mail() — what every site on a cPanel
     *         host can send with, and whose deliverability rests on the domain's SPF/DKIM.
     */
    public const TRANSPORT_AUTO = 'auto';
    public const TRANSPORT_SMTP = 'smtp';
    public const TRANSPORT_API  = 'api';
    public const TRANSPORT_HOST = 'host';
    public const TRANSPORTS = [self::TRANSPORT_AUTO, self::TRANSPORT_SMTP, self::TRANSPORT_API, self::TRANSPORT_HOST];

    /** What `.env.example` ships with. A login equal to one of these is not a login. */
    private const PLACEHOLDERS = ['your_brevo_login@email.com', 'your_brevo_smtp_key',
                                  'your@email.com', 'smtp_key'];

    /**
     * @param array<string,string> $sources field => settings | env | default
     */
    private function __construct(
        public readonly string $host,
        public readonly int    $port,
        public readonly string $secureSetting,
        public readonly string $username,
        public readonly string $password,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $replyTo,
        private readonly array $sources,
        /**
         * The postal address every bulk mail's footer prints. Commercial mail owes one
         * (CAN-SPAM; Gmail and Yahoo read its absence as a spam signal), and it was read
         * from `.env` alone by six senders — on a host with no shell to edit it on.
         */
        public readonly string $postalAddress = self::DEFAULT_POSTAL,
        /** One of {@see TRANSPORTS}. */
        public readonly string $transport = self::TRANSPORT_AUTO,
        /** Brevo's API key — a different credential from the SMTP key, from the same dashboard. */
        public readonly string $apiKey = '',
        /**
         * `.env`'s own SMTP login, when a DIFFERENT one stored in Settings is in force —
         * host, port, secure, username, password, as {@see load()} resolves them. Null when
         * `.env` has no complete login or it is the same one. See {@see load()}.
         *
         * @var array{host:string,port:int,secure:string,username:string,password:string}|null
         */
        public readonly ?array $envSmtp = null,
    ) {}

    /**
     * Resolve from `gates_settings`, then `.env`, then the built-in default — the order
     * every operational value on this host follows, because there is no shell to edit a
     * file with.
     *
     * @param array<string,mixed>|null $settings an already-loaded settings map, so a
     *                                           caller holding one queries nothing twice
     */
    public static function load(?array $settings = null): self
    {
        if ($settings === null) {
            $settings = [];
            try {
                $settings = DB::table('gates_settings')->pluck('value', 'key_name')->all();
            } catch (\Throwable) {
                // No database is not a reason to be unable to say what .env holds.
            }
        }

        $sources = [];
        $pick = static function (string $field, string $key, string $env, string $default)
                use ($settings, &$sources): string {
            $v = trim((string) ($settings[$key] ?? ''));
            if ($v !== '') { $sources[$field] = 'settings'; return $v; }
            $v = trim((string) Env::get($env, ''));
            if ($v !== '') { $sources[$field] = 'env'; return $v; }
            $sources[$field] = 'default';
            return $default;
        };

        /* ── THE SMTP LOGIN IS ONE THING, NOT FIVE ─────────────────────────────
         *
         * Each of host, port, security, username and password used to be picked on its
         * own — Settings if that field was filled, `.env` if not. So a username stored in
         * Settings went out with `.env`'s password, or a stored host with `.env`'s login:
         * a combination that existed nowhere, that no provider would accept, and that
         * every diagnosis then described as "wrong password". The September settings
         * page made it the common case, because it re-posted the SMTP fields on every
         * save of any setting.
         *
         * So the five are resolved as a GROUP: a complete login stored in Settings (user
         * AND password) brings its own host, port and security, with the built-in
         * default where it left one blank — the form says "leave blank for the Brevo
         * relay". Anything less and `.env` supplies all five. Never a mixture. */
        $grp = static function (bool $env) use ($settings): array {
            $get = static fn (string $key, string $envKey): string => trim((string) ($env
                ? Env::get($envKey, '') : ($settings[$key] ?? '')));
            return [
                'host'     => $get('mail_smtp_host', 'SMTP_HOST'),
                'port'     => $get('mail_smtp_port', 'SMTP_PORT'),
                'secure'   => strtolower($get('mail_smtp_secure', 'SMTP_SECURE')),
                'username' => $get('mail_smtp_user', 'SMTP_USER'),
                'password' => $get('mail_smtp_pass', 'SMTP_PASS'),
            ];
        };
        $complete = static fn (array $g): bool => $g['username'] !== '' && $g['password'] !== ''
            && !in_array($g['username'], self::PLACEHOLDERS, true) && !in_array($g['password'], self::PLACEHOLDERS, true);
        $fromSettings = $grp(false);
        $fromEnv      = $grp(true);
        $useSettings  = $complete($fromSettings);
        $g   = $useSettings ? $fromSettings : $fromEnv;
        $tag = $useSettings ? 'settings' : 'env';
        foreach (['host', 'port', 'secure', 'username', 'password'] as $f) {
            $sources[$f] = $g[$f] !== '' ? $tag : 'default';
        }
        $norm = static function (array $g): array {
            $host = $g['host'] !== '' ? $g['host'] : self::DEFAULT_HOST;
            // Cast late and floor it: a settings row is a string, and an operator typing
            // "587 " or nothing must still produce a port somebody can connect to.
            $port = (int) ($g['port'] !== '' ? $g['port'] : (string) self::DEFAULT_PORT);
            if ($port < 1 || $port > 65535) $port = self::DEFAULT_PORT;
            $secure = $g['secure'] !== '' ? $g['secure'] : self::SECURE_AUTO;
            if (!in_array($secure, [self::SECURE_AUTO, self::SECURE_STARTTLS, self::SECURE_SMTPS,
                                    self::SECURE_NONE, 'ssl', 'tls'], true)) {
                $secure = self::SECURE_AUTO;
            }
            return ['host' => $host, 'port' => $port, 'secure' => $secure,
                    'username' => $g['username'], 'password' => self::password($host, $g['password'])];
        };
        $smtp = $norm($g);
        $host = $smtp['host']; $port = $smtp['port']; $secure = $smtp['secure'];

        /* And `.env`'s own login stays within reach. A login stored in Settings by the
           September page was never tested — that page saved whatever the form held — so
           it can be wrong while the `.env` beside it is the one that has always worked.
           When the two differ, the sender tries `.env`'s after Settings' fails on the
           road, rather than reporting an outage that a file on the server already fixes. */
        $envSmtp = null;
        if ($useSettings && $complete($fromEnv)) {
            $e = $norm($fromEnv);
            if ([$e['host'], $e['port'], $e['username'], $e['password']] !== [$host, $port, $smtp['username'], $smtp['password']]) {
                $envSmtp = $e;
            }
        }

        return new self(
            host:          $host,
            port:          $port,
            secureSetting: $secure,
            username:      $smtp['username'],
            password:      $smtp['password'],
            fromAddress:   $pick('from', 'mail_from_address', 'MAIL_FROM_ADDRESS', self::DEFAULT_FROM),
            fromName:      $pick('from_name', 'mail_from_name', 'MAIL_FROM_NAME', 'Africa GATES'),
            replyTo:       $pick('reply_to', 'mail_reply_to', 'MAIL_REPLY_TO', ''),
            postalAddress: $pick('postal', 'mail_postal_address', 'MAIL_POSTAL_ADDRESS', self::DEFAULT_POSTAL),
            sources:       $sources,
            transport:     in_array($t = strtolower($pick('transport', 'mail_transport', 'MAIL_TRANSPORT', self::TRANSPORT_AUTO)),
                                    self::TRANSPORTS, true) ? $t : self::TRANSPORT_AUTO,
            apiKey:        $pick('api_key', 'mail_brevo_api_key', 'BREVO_API_KEY', ''),
            envSmtp:       $envSmtp,
        );
    }

    /**
     * The footer's postal address, for the six senders that print one.
     *
     * Through load(), never a second reading of the key: it fetches only that row and
     * hands it over, so the resolution order — settings, then `.env`, then the default —
     * is the one written above. A broadcast renders per recipient, and this is one
     * indexed row rather than the whole settings table each time.
     */
    public static function postal(): string
    {
        $row = [];
        try {
            $row = DB::table('gates_settings')->where('key_name', 'mail_postal_address')
                ->pluck('value', 'key_name')->all();
        } catch (\Throwable) {
        }
        return self::load($row)->postalAddress;
    }

    /** A config built from literal values, for the test suite and nothing else. */
    public static function of(array $v): self
    {
        return new self(
            (string) ($v['host'] ?? self::DEFAULT_HOST), (int) ($v['port'] ?? self::DEFAULT_PORT),
            (string) ($v['secure'] ?? self::SECURE_AUTO),
            (string) ($v['username'] ?? ''), self::password((string) ($v['host'] ?? self::DEFAULT_HOST), (string) ($v['password'] ?? '')),
            (string) ($v['from'] ?? self::DEFAULT_FROM), (string) ($v['from_name'] ?? 'Africa GATES'),
            (string) ($v['reply_to'] ?? ''),
            // Everything passed in was chosen, so nothing is reported as the default.
            array_fill_keys(array_keys($v), 'given'),
            (string) ($v['postal'] ?? self::DEFAULT_POSTAL),
            in_array((string) ($v['transport'] ?? ''), self::TRANSPORTS, true) ? (string) $v['transport'] : self::TRANSPORT_AUTO,
            (string) ($v['api_key'] ?? ''),
        );
    }

    /**
     * The encryption actually used.
     *
     * `auto` follows the port, which is the convention every provider documents: 465 is
     * implicit TLS, everything else negotiates STARTTLS. `ssl` and `tls` are accepted as
     * the names PHPMailer and most provider dashboards use for the same two things, so an
     * operator copying a value from a help page gets what the page meant.
     */
    public function security(): string
    {
        return match ($this->secureSetting) {
            self::SECURE_SMTPS, 'ssl'    => self::SECURE_SMTPS,
            self::SECURE_STARTTLS, 'tls' => self::SECURE_STARTTLS,
            self::SECURE_NONE            => self::SECURE_NONE,
            default => $this->port === 465 ? self::SECURE_SMTPS : self::SECURE_STARTTLS,
        };
    }

    /** True when there is a real login — not blank, not the `.env.example` placeholder. */
    public function hasCredentials(): bool
    {
        if ($this->username === '' || $this->password === '') return false;
        return !in_array($this->username, self::PLACEHOLDERS, true)
            && !in_array($this->password, self::PLACEHOLDERS, true);
    }

    /** Google's SMTP hosts — the personal relay and Workspace's. */
    public const GOOGLE_HOSTS = ['smtp.gmail.com', 'smtp.googlemail.com', 'smtp-relay.gmail.com'];

    /**
     * Who the SMTP host belongs to, from the host itself: `google`, `brevo` or `other`.
     * Derived rather than chosen, so it cannot disagree with the host it describes.
     */
    public function provider(): string
    {
        $h = strtolower($this->host);
        if (in_array($h, self::GOOGLE_HOSTS, true)) return 'google';
        if (str_contains($h, 'brevo') || str_contains($h, 'sendinblue')) return 'brevo';
        return 'other';
    }

    /**
     * ── A GOOGLE APP PASSWORD IS SHOWN WITH SPACES, AND THE SPACES ARE NOT IN IT ──
     *
     * Google displays an App Password as four groups of four — `abcd efgh ijkl mnop` —
     * and that is what gets pasted. Sent as typed, the spaces are part of the password,
     * and the answer is `535 5.7.8 Username and Password not accepted`, which reads
     * exactly like the wrong password. Normalised only where it can only be that: a
     * Google host and sixteen letters once the spaces are gone.
     */
    private static function password(string $host, string $raw): string
    {
        if (!in_array(strtolower($host), self::GOOGLE_HOSTS, true)) return $raw;
        $squeezed = (string) preg_replace('~\s+~', '', $raw);
        return preg_match('~^[a-z]{16}$~i', $squeezed) ? $squeezed : $raw;
    }

    /**
     * How many messages the SMTP account may send in a day, or 0 when nobody knows.
     *
     * Google's: 500 a day for a gmail.com account, 2,000 for Workspace — and once it is
     * spent, Google refuses EVERY message for up to a day, sign-in codes included. So the
     * send rules stop announcements short of it ({@see SendPolicy::BULK_SHARE}); a
     * newsletter must never be what stops somebody signing in. `mail_daily_limit` sets it
     * for any provider whose plan says otherwise.
     */
    public function dailyLimit(?array $settings = null): int
    {
        $set = $settings['mail_daily_limit'] ?? null;
        if ($set === null) {
            try {
                $set = DB::table('gates_settings')->where('key_name', 'mail_daily_limit')->value('value');
            } catch (\Throwable) {
            }
        }
        if ($set !== null && trim((string) $set) !== '' && (int) $set >= 0) return (int) $set;
        if ($this->provider() !== 'google') return 0;
        $domain = strtolower((string) substr((string) strrchr($this->username, '@'), 1));
        return in_array($domain, ['gmail.com', 'googlemail.com'], true) ? 500 : 2000;
    }

    /** True when an API key is set that is not the placeholder. */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '' && !in_array($this->apiKey, self::PLACEHOLDERS, true)
            && !str_starts_with($this->apiKey, 'your_');
    }

    /** True where PHP's own mail() can be called — present and not in `disable_functions`. */
    public static function hostMailAvailable(): bool
    {
        if (!function_exists('mail')) return false;
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('mail', $off, true);
    }

    /** settings | env | default — where a field's value came from. */
    public function source(string $field): string
    {
        return $this->sources[$field] ?? 'default';
    }

    /** The reply-to address, falling back to the From address. */
    public function replyAddress(): string
    {
        return $this->replyTo !== '' ? $this->replyTo : $this->fromAddress;
    }

    /**
     * What can be shown on a screen. The password is never in it — not masked, not its
     * length: a length narrows a guess, and a key prefix identifies the account.
     *
     * @return array<string,string>
     */
    public function describe(): array
    {
        return [
            'host'     => $this->host . ($this->source('host') === 'default' ? ' (built-in default)' : ''),
            'port'     => (string) $this->port,
            'security' => match ($this->security()) {
                self::SECURE_SMTPS    => 'implicit TLS (SMTPS)',
                self::SECURE_STARTTLS => 'STARTTLS',
                default               => 'none — plain text',
            } . ($this->secureSetting === self::SECURE_AUTO ? ', chosen from the port' : ''),
            'username' => $this->username !== '' ? $this->username : '(not set)',
            'password' => $this->password !== '' ? 'set (' . $this->source('password') . ')' : '(not set)',
            'from'     => $this->fromAddress,
            'transport' => match ($this->transport) {
                self::TRANSPORT_SMTP => 'SMTP only',
                self::TRANSPORT_API  => 'Brevo API (HTTPS) only',
                self::TRANSPORT_HOST => 'this server’s own mail only',
                default              => 'automatic — SMTP, then the Brevo API; this server’s own mail only when neither is set up',
            },
            'api_key'  => $this->hasApiKey() ? 'set (' . $this->source('api_key') . ')' : '(not set)',
        ];
    }
}
