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
     * Can mail leave by ANY road the configuration allows — not only SMTP?
     *
     * `run()` walks the SMTP conversation, and was the whole of the hourly check: so a
     * deployment sending perfectly over the Brevo API, or by the server's own mail with
     * SMTP blocked, opened an incident every hour about a road it was not using. This
     * asks about the roads in the order a send tries them, and a fallback carrying the
     * mail is reported as WORKING and DEGRADED — mail is reaching people, and the SMTP
     * fault is still on the screen with its fix.
     *
     * @param \Closure(string):array{ok:bool,detail:string}|null $api
     * @return array<string,mixed> run()'s shape, plus `road` and `degraded`
     */
    public static function roads(MailConfig $c, ?\Closure $api = null, ?bool $production = null): array
    {
        $api ??= static fn (string $k): array => (new BrevoApi($k))->check();
        $production ??= strtolower((string) \AfricaGates\Support\Env::get('APP_ENV', 'production')) === 'production';
        $host = $production && MailConfig::hostMailAvailable();

        $smtp = in_array($c->transport, [MailConfig::TRANSPORT_AUTO, MailConfig::TRANSPORT_SMTP], true)
            ? (new self($c))->run() : null;
        if ($smtp !== null && $smtp['ok']) {
            return $smtp + ['road' => 'smtp', 'degraded' => false];
        }
        /* The login stored in Settings failed and `.env` holds a different one: the sender
           tries that next (OtpService::routes()), so the check asks it too. A carrying
           `.env` login is WORKING and DEGRADED — the stored one is still wrong, and the
           fix is the one press that removes it. */
        if ($smtp !== null && $c->envSmtp !== null) {
            $e = $c->envSmtp;
            $alt = (new self(MailConfig::of(['host' => $e['host'], 'port' => $e['port'], 'secure' => $e['secure'],
                'username' => $e['username'], 'password' => $e['password'], 'from' => $c->fromAddress,
                'from_name' => $c->fromName, 'transport' => $c->transport])))->run();
            if ($alt['ok']) {
                return array_merge($alt, ['road' => 'smtp-env', 'degraded' => true,
                    'title' => 'The SMTP login saved in Settings is failing (' . $smtp['title'] . ') — mail is going out with the server’s .env login instead',
                    'fix' => 'Press “Use the server’s .env settings instead” on Settings → Email health to remove the saved login, or save a working one.']);
            }
        }
        if ($smtp !== null && $c->transport === MailConfig::TRANSPORT_SMTP) {
            return $smtp + ['road' => 'smtp', 'degraded' => false];
        }

        $base = $smtp ?? ['ok' => false, 'cause' => MailFailure::CONFIG, 'title' => '', 'fix' => '', 'detail' => '',
            'steps' => [], 'config' => $c->describe(), 'ports' => [], 'ran_at' => gmdate('Y-m-d H:i:s'), 'took_ms' => 0];
        $why = $smtp !== null ? 'SMTP is failing (' . $smtp['title'] . ')' : 'SMTP is not used';

        if (in_array($c->transport, [MailConfig::TRANSPORT_AUTO, MailConfig::TRANSPORT_API], true)) {
            if ($c->hasApiKey()) {
                $r = $api($c->apiKey);
                $base['steps'][] = ['key' => 'api', 'label' => 'The Brevo API accepts our key',
                                    'state' => $r['ok'] ? self::OK : self::FAIL, 'detail' => $r['detail']];
                if ($r['ok']) {
                    return array_merge($base, ['ok' => true, 'road' => 'api', 'degraded' => $smtp !== null,
                        'title' => $smtp !== null ? $why . ' — mail is going out by the Brevo API instead' : 'Email can be sent by the Brevo API']);
                }
                if ($c->transport === MailConfig::TRANSPORT_API) {
                    return array_merge($base, ['ok' => false, 'cause' => MailFailure::AUTH, 'road' => 'api', 'degraded' => false,
                        'title' => 'The Brevo API refused our key', 'fix' => $r['detail']]);
                }
            } elseif ($c->transport === MailConfig::TRANSPORT_API) {
                return array_merge($base, ['ok' => false, 'cause' => MailFailure::CONFIG, 'road' => 'api', 'degraded' => false,
                    'title' => 'No Brevo API key is saved', 'fix' => 'Paste the API key into “How mail is sent” on Settings → Email health.']);
            }
        }

        /* The server's own mail is a road only when chosen, or when nothing else is set up
           at all — see OtpService::routes() for why it stopped being a fallback: it sends
           as the server, the domain's SPF and DKIM are not the server's, and a "sent" from
           it is mail Gmail discards unseen. */
        if ($c->transport === MailConfig::TRANSPORT_HOST
            || ($c->transport === MailConfig::TRANSPORT_AUTO && !$c->hasCredentials() && !$c->hasApiKey())) {
            $base['steps'][] = ['key' => 'host', 'label' => 'This server can send its own mail',
                                'state' => $host ? self::OK : self::FAIL,
                                'detail' => $host ? 'PHP’s mail() is available here.' : 'PHP’s mail() is not available on this server.'];
            if ($host) {
                return array_merge($base, ['ok' => true, 'road' => 'host', 'degraded' => $c->transport === MailConfig::TRANSPORT_AUTO,
                    'title' => $c->transport === MailConfig::TRANSPORT_AUTO
                        ? 'Nothing is set up, so mail is going out by this server’s own mail — which most inboxes discard'
                        : 'Email can be sent by this server’s own mail']);
            }
        }
        return $base + ['road' => null, 'degraded' => false];
    }

    /**
     * ── "THE PORT AND THE ENCRYPTION DO NOT MATCH", SAID TO SOMEBODY WHOSE DO ──
     *
     * The generic TLS advice is "use 587 with Encryption on Automatic". That is right only
     * when the pairing is actually wrong — 465 forced to STARTTLS, or 587 forced to SMTPS.
     * Shipped, it was sent to an operator already on 587 / Automatic, whose handshake was
     * failing for a different reason, so the alert told them to set what was already set,
     * every hour. Same shape as the CONNECT advice above it: the diagnosis has measured
     * the answer, so it says the measured thing.
     *
     * When the pairing is the conventional one, a STARTTLS that is not offered, or a
     * certificate that is not the provider's, is almost always the web host intercepting
     * outbound SMTP (cPanel's "SMTP Restrictions" answers port 587 itself) — and no port or
     * encryption setting on this side can fix that. The remedy is a road that is not SMTP.
     */
    /** The detail recorded when the server answered EHLO without offering STARTTLS. */
    private const NO_STARTTLS = 'The server does not offer STARTTLS.';

    private static function tlsFix(MailConfig $c, string $detail): string
    {
        $sec = $c->security();
        $mismatched = ($c->port === 465 && $sec !== MailConfig::SECURE_SMTPS)
                   || ($c->port !== 465 && $sec === MailConfig::SECURE_SMTPS);
        if ($mismatched) {
            return 'Port ' . $c->port . ' is set with ' . ($sec === MailConfig::SECURE_SMTPS ? 'SMTPS' : 'STARTTLS')
                . ', which that port does not speak. Set Encryption to Automatic in Settings → Email & sender'
                . ' (465 is SMTPS, 587 and 2525 are STARTTLS).';
        }

        $bare = $detail === self::NO_STARTTLS;
        $intercepted = $bare
            || preg_match('~certificate|peer|CN=|verify failed|subject name~i', $detail);
        $road = $c->hasApiKey()
            ? 'set “Send by” to Automatic so mail goes out by the Brevo API'
            : 'set “Send by” to Automatic and save a Brevo API key, so mail goes out over HTTPS'
              . (MailConfig::hostMailAvailable() ? ' (or by this server’s own mail)' : '');

        if ($intercepted) {
            return 'Port ' . $c->port . ' with ' . ($sec === MailConfig::SECURE_SMTPS ? 'SMTPS' : 'STARTTLS')
                . ' is already the right pairing, so changing the port or the encryption will not help. '
                . ($bare ? 'The server answering on ' . $c->port . ' offered no encryption at all'
                                  : 'The server answering presented a certificate that is not ' . $c->host . '’s')
                . ' — the web host is intercepting outbound mail and answering it itself (cPanel calls this'
                . ' “SMTP Restrictions”). Ask the host to turn that off for this account, or ' . $road . '.';
        }
        return 'Port ' . $c->port . ' with ' . ($sec === MailConfig::SECURE_SMTPS ? 'SMTPS' : 'STARTTLS')
            . ' is already the right pairing, so the settings are not the fault: the encrypted handshake itself'
            . ' failed' . ($detail !== '' ? ' (' . mb_substr($detail, 0, 160) . ')' : '') . '. If it persists, the web host may be interfering with'
            . ' outbound mail — ' . $road . '.';
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
            $fix = $cause === null ? '' : MailFailure::fix($cause, $c->provider());
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
            if ($cause === MailFailure::TLS) {
                $fix = self::tlsFix($c, $detail);
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
                return $finish(MailFailure::TLS, self::NO_STARTTLS);
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
        // Gmail ACCEPTS any From at this step and rewrites it later to the signed-in
        // account unless the address is one of its verified "Send mail as" aliases — so a
        // pass here is not the whole answer, and the screen says so rather than letting
        // somebody wonder why the mail arrives from a different address.
        $alias = $c->provider() === 'google' && strcasecmp($c->fromAddress, $c->username) !== 0;
        $step('sender', $alias ? self::WARN : self::OK, $c->fromAddress . ' accepted. No message was sent.'
            . ($alias ? ' Google will send it as ' . $c->username . ' unless ' . $c->fromAddress
                      . ' is added under Gmail → Settings → Accounts → “Send mail as”.' : ''));

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
