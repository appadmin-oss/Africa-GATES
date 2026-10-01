<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use PHPMailer\PHPMailer\SMTP;

/**
 * WHICH STEP OF SENDING AN EMAIL FAILS, AND WHY — WITHOUT SENDING ONE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY STEP BY STEP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A real send answers one bit: it worked, or PHPMailer threw a sentence. The sentence
 * for six different faults is frequently the same ("SMTP connect() failed") and the
 * fixes are in six different places — the web host's firewall, the port, the
 * encryption, the provider's key page, the provider's sender list. The only thing that
 * tells them apart is WHICH PROTOCOL STEP got no further, so this walks them in order
 * with PHPMailer's own `SMTP` class (the same code a send uses — a diagnosis written on
 * a second socket library would be diagnosing a different client):
 *
 *     config → dns → connect → greeting → hello → tls → login → sender
 *
 * and stops at the first failure, because every later step is meaningless without it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT STOPS BEFORE SENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It runs by itself — hourly, and the moment `MailHealth` sees failures — so it cannot
 * email anybody: a diagnosis that sends is one that floods an inbox during exactly the
 * outage it runs in, and burns the daily quota the outage might be about. It ends with
 * `MAIL FROM` and `RSET`: the provider has accepted our sender, and no message exists.
 * "Send a test email" remains a separate, deliberate button.
 *
 * ── AND WHEN IT CANNOT CONNECT, IT ASKS WHICH PORTS CAN ──────────────────────────
 *
 * Shared hosts routinely block outbound 25, 465 and 587 and leave 2525 open, and from
 * the outside that is indistinguishable from the provider being down. A one-line TCP
 * check of the other standard ports turns "cannot connect" into "587 is blocked from
 * this server; 2525 is open — switch to it", which is the fix rather than a symptom.
 *
 * Every collaborator is injectable so the suite can walk every branch without a
 * network; production uses the real ones.
 */
final class MailDiagnosis
{
    /** Each step's own timeout. Bounded, because this runs inside a maintenance tick. */
    public const TIMEOUT = 8;

    /** The ports worth trying when the configured one cannot be reached. */
    public const ALT_PORTS = [587, 2525, 465, 25];

    public const OK   = 'ok';
    public const FAIL = 'fail';
    public const SKIP = 'skip';
    public const WARN = 'warn';

    private const LABELS = [
        'config'   => 'Settings are complete',
        'dns'      => 'The server name resolves',
        'connect'  => 'This host can reach the mail server',
        'greeting' => 'The mail server answers',
        'hello'    => 'The server accepts our hello',
        'tls'      => 'The connection is encrypted',
        'login'    => 'The provider accepts our login',
        'sender'   => 'The provider accepts our From address',
    ];

    /** @var callable():SMTP */
    private $smtp;
    /** @var callable(string):list<string> */
    private $resolve;
    /** @var callable(string,int,int):bool */
    private $reach;

    public function __construct(
        private readonly MailConfig $config,
        ?callable $smtp = null,
        ?callable $resolve = null,
        ?callable $reach = null,
    ) {
        $this->smtp    = $smtp    ?? static fn (): SMTP => new SMTP();
        $this->resolve = $resolve ?? static fn (string $h): array => (array) (@gethostbynamel($h) ?: []);
        $this->reach   = $reach   ?? static function (string $h, int $p, int $t): bool {
            $s = @fsockopen($h, $p, $no, $str, $t);
            if (!$s) return false;
            fclose($s);
            return true;
        };
    }

    /**
     * Run it.
     *
     * @return array{ok:bool, cause:?string, title:string, fix:string, steps:list<array{key:string,label:string,state:string,detail:string}>,
     *               config:array<string,string>, ports:array<int,bool>, ran_at:string, took_ms:int}
     */
    public function run(): array
    {
        $t0    = microtime(true);
        $steps = [];
        $ports = [];
        $c     = $this->config;

        $finish = function (?string $cause, string $detail = '') use (&$steps, &$ports, $t0, $c): array {
            // Everything after the failing step is reported as not reached rather than
            // dropped, so the screen always shows the whole path and where it stopped.
            $have = array_column($steps, 'key');
            foreach (self::LABELS as $key => $label) {
                if (!in_array($key, $have, true)) {
                    $steps[] = ['key' => $key, 'label' => $label, 'state' => self::SKIP, 'detail' => 'Not reached.'];
                }
            }
            $fix = $cause === null ? '' : MailFailure::fix($cause);
            if ($cause === MailFailure::CONNECT && $ports !== []) {
                $open = array_keys(array_filter($ports));
                // The generic advice is "try another port" — and it has just tried them.
                // Measured on a host with SMTP egress blocked: every port timed out and
                // the advice still said "try 2525 or 587", sending the operator to retest
                // what the diagnosis had already proved.
                $fix = $open
                    ? 'Port ' . $c->port . ' is blocked from this server, but '
                      . implode(' and ', array_map(static fn ($p) => 'port ' . $p, $open))
                      . (count($open) === 1 ? ' is' : ' are') . ' open. Set Port to ' . $open[0]
                      . ' in Settings → Email & sender (leave Encryption on Automatic).'
                    : 'Every standard mail port (' . implode(', ', array_merge([$c->port], array_keys($ports)))
                      . ') is blocked from this server, so no setting here can fix it: the web host is '
                      . 'blocking outbound mail. Ask them to allow outbound SMTP to ' . $c->host
                      . ', or move sending to a provider that delivers over HTTPS.';
            }
            return [
                'ok'      => $cause === null,
                'cause'   => $cause,
                'title'   => $cause === null ? 'Email can be sent' : MailFailure::title($cause),
                'fix'     => $fix,
                'detail'  => $detail,
                'steps'   => $steps,
                'config'  => $c->describe(),
                'ports'   => $ports,
                'ran_at'  => gmdate('Y-m-d H:i:s'),
                'took_ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        };
        $step = function (string $key, string $state, string $detail) use (&$steps): void {
            $steps[] = ['key' => $key, 'label' => self::LABELS[$key], 'state' => $state, 'detail' => $detail];
        };

        // ── config ────────────────────────────────────────────────────────────────
        if (!$c->hasCredentials()) {
            $step('config', self::FAIL, $c->username === '' || $c->password === ''
                ? 'No SMTP ' . ($c->username === '' ? 'username' : 'password') . ' is set.'
                : 'The SMTP login is still the placeholder from the example settings.');
            return $finish(MailFailure::CONFIG);
        }
        $step('config', self::OK, $c->host . ':' . $c->port . ' · ' . $c->describe()['security']
            . ($c->source('host') === 'default' ? ' · host is the built-in default' : ''));

        // ── dns ───────────────────────────────────────────────────────────────────
        if (filter_var($c->host, FILTER_VALIDATE_IP)) {
            $step('dns', self::SKIP, 'The host is an IP address.');
        } else {
            $ips = ($this->resolve)($c->host);
            if (!$ips) {
                $step('dns', self::FAIL, $c->host . ' does not resolve to any address.');
                return $finish(MailFailure::DNS);
            }
            $step('dns', self::OK, $c->host . ' → ' . implode(', ', array_slice($ips, 0, 3)));
        }

        // ── connect + greeting ────────────────────────────────────────────────────
        $smtp  = ($this->smtp)();
        $smtp->setTimeout(self::TIMEOUT);
        $smtps = $c->security() === MailConfig::SECURE_SMTPS;

        if (!$smtp->connect(($smtps ? 'ssl://' : '') . $c->host, $c->port, self::TIMEOUT)) {
            $err = self::err($smtp);
            // A refusal that names the handshake on an SMTPS port is TLS, not a firewall.
            if ($smtps && MailFailure::classify($err) === MailFailure::TLS) {
                $step('connect', self::FAIL, $err);
                return $finish(MailFailure::TLS, $err);
            }
            $step('connect', self::FAIL, 'No connection to ' . $c->host . ':' . $c->port
                . ' within ' . self::TIMEOUT . 's. ' . $err);
            foreach (self::ALT_PORTS as $p) {
                if ($p !== $c->port) $ports[$p] = (bool) ($this->reach)($c->host, $p, 4);
            }
            return $finish(MailFailure::CONNECT, $err);
        }
        $step('connect', self::OK, 'Connected to ' . $c->host . ':' . $c->port . ($smtps ? ' over TLS' : ''));

        $banner = trim($smtp->getLastReply());
        if ($banner !== '' && !str_starts_with($banner, '220')) {
            $step('greeting', self::FAIL, 'The server answered: ' . mb_substr($banner, 0, 160));
            $smtp->close();
            // A 4xx/5xx greeting is the provider refusing service to this IP, which is a
            // limit or a block on the account, not a fault in our settings.
            return $finish(MailFailure::QUOTA, $banner);
        }
        $step('greeting', self::OK, $banner !== '' ? mb_substr($banner, 0, 120) : 'Greeted.');

        // ── hello ────────────────────────────────────────────────────────────────
        $me = (string) (parse_url((string) \AfricaGates\Support\Env::get('APP_URL', ''), PHP_URL_HOST) ?: 'localhost');
        if (!$smtp->hello($me)) {
            $err = self::err($smtp);
            $step('hello', self::FAIL, $err);
            $smtp->close();
            return $finish(MailFailure::classify($err), $err);
        }
        $step('hello', self::OK, 'EHLO ' . $me);

        // ── tls ──────────────────────────────────────────────────────────────────
        if ($smtps) {
            $step('tls', self::OK, 'Encrypted from the first byte (SMTPS).');
        } elseif ($c->security() === MailConfig::SECURE_NONE) {
            $step('tls', self::WARN, 'Encryption is switched off: the login is sent in plain text.');
        } else {
            if (!$smtp->getServerExt('STARTTLS')) {
                $step('tls', self::FAIL, 'The server does not offer STARTTLS on port ' . $c->port . '.');
                $smtp->close();
                return $finish(MailFailure::TLS);
            }
            if (!$smtp->startTLS() || !$smtp->hello($me)) {
                $err = self::err($smtp);
                $step('tls', self::FAIL, $err !== '' ? $err : 'The TLS handshake failed.');
                $smtp->close();
                return $finish(MailFailure::TLS, $err);
            }
            $step('tls', self::OK, 'STARTTLS negotiated.');
        }

        // ── login ────────────────────────────────────────────────────────────────
        if (!$smtp->authenticate($c->username, $c->password)) {
            $err = self::err($smtp);
            $step('login', self::FAIL, 'Rejected for ' . $c->username . '. ' . $err);
            $smtp->close();
            return $finish(MailFailure::AUTH, $err);
        }
        $step('login', self::OK, 'Signed in as ' . $c->username);

        // ── sender ───────────────────────────────────────────────────────────────
        if (!$smtp->mail($c->fromAddress)) {
            $err = self::err($smtp);
            $step('sender', self::FAIL, $c->fromAddress . ' was refused. ' . $err);
            $smtp->quit();
            $cause = MailFailure::classify($err);
            return $finish($cause === MailFailure::QUOTA ? $cause : MailFailure::SENDER, $err);
        }
        $smtp->reset();
        $smtp->quit();
        $step('sender', self::OK, $c->fromAddress . ' accepted. No message was sent.');

        return $finish(null);
    }

    /** PHPMailer's error, as one line: its message, its detail, and the server's reply. */
    private static function err(SMTP $smtp): string
    {
        $e = $smtp->getError();
        $parts = array_filter([
            trim((string) ($e['error'] ?? '')),
            trim((string) ($e['detail'] ?? '')),
            trim((string) ($e['smtp_code'] ?? '') . ' ' . (string) ($e['smtp_code_ex'] ?? '')),
        ]);
        $reply = trim($smtp->getLastReply());
        if ($reply !== '' && !in_array($reply, $parts, true)) $parts[] = $reply;
        return mb_substr(implode(' — ', $parts), 0, 400);
    }
}
