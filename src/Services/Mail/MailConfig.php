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

        $host = $pick('host', 'mail_smtp_host', 'SMTP_HOST', self::DEFAULT_HOST);
        // Cast late and floor it: a settings row is a string, and an operator typing
        // "587 " or nothing must still produce a port somebody can connect to.
        $port = (int) $pick('port', 'mail_smtp_port', 'SMTP_PORT', (string) self::DEFAULT_PORT);
        if ($port < 1 || $port > 65535) $port = self::DEFAULT_PORT;

        $secure = strtolower($pick('secure', 'mail_smtp_secure', 'SMTP_SECURE', self::SECURE_AUTO));
        if (!in_array($secure, [self::SECURE_AUTO, self::SECURE_STARTTLS, self::SECURE_SMTPS,
                                self::SECURE_NONE, 'ssl', 'tls'], true)) {
            $secure = self::SECURE_AUTO;
        }

        return new self(
            host:          $host,
            port:          $port,
            secureSetting: $secure,
            username:      $pick('username', 'mail_smtp_user', 'SMTP_USER', ''),
            password:      $pick('password', 'mail_smtp_pass', 'SMTP_PASS', ''),
            fromAddress:   $pick('from', 'mail_from_address', 'MAIL_FROM_ADDRESS', self::DEFAULT_FROM),
            fromName:      $pick('from_name', 'mail_from_name', 'MAIL_FROM_NAME', 'Africa GATES'),
            replyTo:       $pick('reply_to', 'mail_reply_to', 'MAIL_REPLY_TO', ''),
            postalAddress: $pick('postal', 'mail_postal_address', 'MAIL_POSTAL_ADDRESS', self::DEFAULT_POSTAL),
            sources:       $sources,
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
            (string) ($v['username'] ?? ''), (string) ($v['password'] ?? ''),
            (string) ($v['from'] ?? self::DEFAULT_FROM), (string) ($v['from_name'] ?? 'Africa GATES'),
            (string) ($v['reply_to'] ?? ''),
            // Everything passed in was chosen, so nothing is reported as the default.
            array_fill_keys(array_keys($v), 'given'),
            (string) ($v['postal'] ?? self::DEFAULT_POSTAL),
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
        ];
    }
}
