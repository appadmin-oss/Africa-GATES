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
    /** @var callable(MailConfig):?array */
    private $certify;

    public function __construct(
        private readonly MailConfig $config,
        ?callable $smtp = null,
        ?callable $resolve = null,
        ?callable $reach = null,
        ?callable $certify = null,
    ) {
        $this->smtp    = $smtp    ?? static fn (): SMTP => new SMTP();
        $this->resolve = $resolve ?? static fn (string $h): array => (array) (@gethostbynamel($h) ?: []);
        $this->reach   = $reach   ?? static function (string $h, int $p, int $t): bool {
            $s = @fsockopen($h, $p, $no, $str, $t);
            if (!$s) return false;
            fclose($s);
            return true;
        };
        $this->certify = $certify ?? static fn (MailConfig $c): ?array => PeerCertificate::of($c);
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
    public static function roads(MailConfig $c, ?\Closure $api = null, ?bool $production = null, ?\Closure $gas = null): array
    {
        $api ??= static fn (string $k): array => (new BrevoApi($k))->check();
        $gas ??= static fn (): ?array => AppsScriptMail::configured() ? AppsScriptMail::boot()->check() : null;
        $production ??= strtolower((string) \AfricaGates\Support\Env::get('APP_ENV', 'production')) === 'production';
        $host = $production && MailConfig::hostMailAvailable();

        $smtp = in_array($c->transport, [MailConfig::TRANSPORT_AUTO, MailConfig::TRANSPORT_SMTP], true)
            ? (new self($c))->run() : null;
        if ($smtp !== null && ($smtp['ok'] || $c->transport === MailConfig::TRANSPORT_SMTP)) {
            return $smtp + ['road' => 'smtp', 'degraded' => false];
        }

        // See degraded(): from here on, mail may still be reaching people by another road,
        // and `$base` is carrying the SMTP run's own `fix` — which is written for an
        // outage. Every working return below passes it through degraded().
        $base = $smtp ?? ['ok' => false, 'cause' => MailFailure::CONFIG, 'title' => '', 'fix' => '', 'detail' => '',
            'steps' => [], 'config' => $c->describe(), 'ports' => [], 'ran_at' => gmdate('Y-m-d H:i:s'), 'took_ms' => 0];
        $why = $smtp !== null ? 'SMTP is failing (' . $smtp['title'] . ')' : 'SMTP is not used';

        // ── Google Apps Script: the road for when Google SMTP fails ─────────────
        if (in_array($c->transport, [MailConfig::TRANSPORT_AUTO, MailConfig::TRANSPORT_GAS], true)) {
            $g = $gas();
            if ($g !== null) {
                $base['steps'][] = ['key' => 'gas', 'label' => 'Google Apps Script can send for us',
                                    'state' => $g['ok'] ? self::OK : self::FAIL, 'detail' => $g['detail']];
                if ($g['ok']) {
                    return array_merge($base, ['ok' => true, 'road' => 'gas', 'degraded' => $smtp !== null,
                        'title' => $smtp !== null
                            ? $why . ' — sign-in codes, receipts and confirmations are going out by Google Apps Script; announcements wait for SMTP'
                            : 'Email can be sent by Google Apps Script (announcements wait for SMTP)']);
                }
            } elseif ($c->transport === MailConfig::TRANSPORT_GAS) {
                return array_merge($base, ['ok' => false, 'cause' => MailFailure::CONFIG, 'road' => 'gas', 'degraded' => false,
                    'title' => 'Google Apps Script is not set up',
                    'fix' => 'Paste the Apps Script web-app address (ending /exec) and its secret into Email health → Google Apps Script, and deploy the latest config/AfricaGATES_AppScript.gs.']);
            }
            if ($c->transport === MailConfig::TRANSPORT_GAS) {
                return array_merge($base, ['ok' => false, 'cause' => MailFailure::CONFIG, 'road' => 'gas', 'degraded' => false,
                    'title' => 'Google Apps Script could not send', 'fix' => (string) ($g['detail'] ?? '')]);
            }
        }

        if (in_array($c->transport, [MailConfig::TRANSPORT_AUTO, MailConfig::TRANSPORT_API], true)) {
            if ($c->hasApiKey()) {
                $r = $api($c->apiKey);
                $base['steps'][] = ['key' => 'api', 'label' => 'The Brevo API accepts our key',
                                    'state' => $r['ok'] ? self::OK : self::FAIL, 'detail' => $r['detail']];
                if ($r['ok']) {
                    return self::degraded(array_merge($base, ['ok' => true, 'road' => 'api', 'degraded' => $smtp !== null,
                        'title' => $smtp !== null ? $why . ' — mail is going out by the Brevo API instead' : 'Email can be sent by the Brevo API']),
                        'api', $smtp !== null);
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

        if (in_array($c->transport, [MailConfig::TRANSPORT_AUTO, MailConfig::TRANSPORT_HOST], true)) {
            $base['steps'][] = ['key' => 'host', 'label' => 'This server can send its own mail',
                                'state' => $host ? self::OK : self::FAIL,
                                'detail' => $host ? 'PHP’s mail() is available here.' : 'PHP’s mail() is not available on this server.'];
            if ($host) {
                return self::degraded(array_merge($base, ['ok' => true, 'road' => 'host', 'degraded' => $c->transport === MailConfig::TRANSPORT_AUTO,
                    'title' => $c->transport === MailConfig::TRANSPORT_AUTO
                        ? $why . ' — mail is going out by this server’s own mail instead'
                        : 'Email can be sent by this server’s own mail']),
                    'host', $c->transport === MailConfig::TRANSPORT_AUTO);
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
     * ── AND THEN IT SAID A SECOND THING IT HAD NOT MEASURED ──────────────────────────
     *
     * Its replacement read any cert-shaped word in PHPMailer's error and announced, as a
     * fact, "the web host is intercepting outbound mail and answering it itself". Nothing
     * had looked at a certificate. Run against the three error strings OpenSSL actually
     * emits — a stale CA bundle, a genuine name mismatch, an expired intermediate — the
     * screen printed one identical sentence, and it is the right answer to exactly one of
     * them. So an operator was sent to their web host with an accusation two times in
     * three; the host replied with boilerplate, because they had been handed a conclusion
     * rather than an observation, and the real fault went on being invisible.
     *
     * The commonest of the three is the one that reads as "mail stopped working after an
     * update": a PHP version change on a shared host swaps the OpenSSL trust store, and
     * every outbound TLS verification starts failing against certificates that are
     * perfectly genuine. Nothing is intercepting anything, and no amount of asking the
     * host to disable SMTP Restrictions will fix it.
     *
     * So {@see PeerCertificate} LOOKS, and this says only what came back:
     *
     *   · names that do not cover the host  → somebody else is answering, named.
     *   · names that DO cover the host      → our own trust store, not the host.
     *   · no certificate to look at         → the server's own words, no cause invented.
     *
     * `$cert` is null when the probe could not reach that far, which is a different
     * finding from "we looked and it was wrong" and must not read like it.
     *
     * @param array{subject:string,issuer:string,names:list<string>,matches:bool,
     *              expired:bool,valid_to:string}|null $cert
     */
    /** The detail recorded when the server answered EHLO without offering STARTTLS. */
    private const NO_STARTTLS = 'The server does not offer STARTTLS.';

    /** OpenSSL's way of saying "this certificate may be fine; I cannot check it". */
    private const UNTRUSTED = '~unable to get local issuer|self.signed certificate|unable to verify the first certificate|certificate verify failed~i';

    private static function tlsFix(MailConfig $c, string $detail, ?array $cert = null): string
    {
        $sec   = $c->security();
        $pair  = 'Port ' . $c->port . ' with ' . ($sec === MailConfig::SECURE_SMTPS ? 'SMTPS' : 'STARTTLS');
        $mismatched = ($c->port === 465 && $sec !== MailConfig::SECURE_SMTPS)
                   || ($c->port !== 465 && $sec === MailConfig::SECURE_SMTPS);
        if ($mismatched) {
            return 'Port ' . $c->port . ' is set with ' . ($sec === MailConfig::SECURE_SMTPS ? 'SMTPS' : 'STARTTLS')
                . ', which that port does not speak. Set Encryption to Automatic in Settings → Email & sender'
                . ' (465 is SMTPS, 587 and 2525 are STARTTLS).';
        }

        // ── THE ROAD THAT COSTS NOTHING COMES FIRST ─────────────────────────────────
        // Every branch below used to end "save a Brevo API key", with this server's own
        // mail in a parenthesis after it. On a cPanel host PHP's mail() is always there,
        // needs no account, no key and no third party, and "Send by: Automatic" ALREADY
        // falls through to it — so an SMTP fault is not a reason to go and open an
        // account anywhere. Read twice as though the paid relay were the only way out,
        // which is what naming it first does.
        $road = MailConfig::hostMailAvailable()
            ? 'leave “Send by” on Automatic, which already falls through to this server’s own mail'
              . ($c->hasApiKey() ? ' (and to the Brevo API, whose key is saved)' : '')
            : ($c->hasApiKey()
                ? 'set “Send by” to Automatic so mail goes out by the Brevo API'
                : 'set “Send by” to Automatic and save a Brevo API key, so mail goes out over HTTPS');
        $settled = $pair . ' is already the right pairing, so changing the port or the encryption will not help. ';

        // A server that answers 587 and offers no encryption at all, where the provider
        // does, is not the provider. Nothing to measure: the absence IS the measurement.
        if ($detail === self::NO_STARTTLS) {
            return $settled . 'The server answering on ' . $c->port . ' offered no encryption at all'
                . ' — the web host is intercepting outbound mail and answering it itself (cPanel calls this'
                . ' “SMTP Restrictions”). Ask the host to turn that off for this account, or ' . $road . '.';
        }

        if ($cert !== null && !$cert['matches']) {
            return $settled . 'We looked at what the server on ' . $c->host . ':' . $c->port . ' presents: a'
                . ' certificate for ' . self::names($cert) . ', issued by ' . $cert['issuer'] . '. That is not '
                . $c->host . ', so something between this server and your mail provider is answering in its'
                . ' place — usually the web host (cPanel calls this “SMTP Restrictions”). Send them those'
                . ' certificate details and ask them to stop intercepting outbound mail for this account, or '
                . $road . '.';
        }

        if ($cert !== null && $cert['expired']) {
            return $settled . 'The certificate ' . $c->host . ' presents expired on ' . $cert['valid_to']
                . ', so this server is right to refuse it. That is the mail provider’s to renew — nothing here'
                . ' can be set to fix it. Until they do, ' . $road . '.';
        }

        // ── A MATCHING NAME IS NOT A CLEARED NAME ───────────────────────────────────
        // Measured against this container's own egress while writing it: the proxy
        // intercepting outbound HTTPS presented a certificate whose SUBJECT was exactly
        // the host asked for — *.google.com — and whose ISSUER was the proxy's own CA.
        // An interceptor forges the name; that is the entire trick. So a draft of this
        // branch that read a matching name as "nothing is intercepting the connection"
        // would have cleared a live interception in the one sentence written to catch it.
        //
        // The name therefore proves nothing on its own and the issuer is the evidence,
        // and we cannot decide it here: "Google Trust Services" means our trust store is
        // stale, "QServers" means somebody is re-signing the connection, and only the
        // reader knows which their provider uses. So the issuer is NAMED and both
        // readings are given, rather than one of them being guessed at.
        if ($cert !== null) {
            return $settled . 'The server answering is presenting a certificate for ' . self::names($cert)
                . ' — the right name — issued by ' . $cert['issuer'] . '. This server could not verify it,'
                . ' and which fault that is depends on that issuer. If ' . $cert['issuer'] . ' is not the'
                . ' certificate authority your mail provider uses, something is re-signing the connection'
                . ' between this server and them (an interceptor forges the name, so a matching name clears'
                . ' nothing). If it IS theirs, the certificate is genuine and this server’s CA trust store is'
                . ' out of date — the usual cause is a PHP version change on the host, and the fix is for them'
                . ' to update the CA bundle for the PHP version this site runs (openssl.cafile / curl.cainfo).'
                . ' Either way, ' . $road . '.';
        }

        // Nothing was measured. Say the server's words and name both possibilities as
        // possibilities — an unmeasured cause stated as fact is what this method is for.
        $trust = preg_match(self::UNTRUSTED, $detail)
            ? ' That wording is usually this server’s CA trust store being out of date rather than anything'
              . ' at the provider, and a host PHP update is the usual cause.'
            : '';
        return $settled . 'The encrypted handshake itself failed'
            . ($detail !== '' ? ' (' . mb_substr($detail, 0, 160) . ')' : '') . '.' . $trust
            . ' We could not read the certificate the server presented, so this is the server’s own wording and'
            . ' not a diagnosis. Run Email health again to retry the check, or ' . $road . '.';
    }

    /** The presented names, trimmed to something a sentence can hold. */
    private static function names(array $cert): string
    {
        $n = $cert['names'] ?: [$cert['subject']];
        return count($n) > 3
            ? implode(', ', array_slice($n, 0, 3)) . ' and ' . (count($n) - 3) . ' more'
            : implode(', ', $n);
    }

    /**
     * ── A WORKING SCREEN MUST NOT READ LIKE AN OUTAGE ───────────────────────────────
     *
     * When SMTP fails under `auto`, roads() concludes `ok: true, degraded: true` — mail
     * IS reaching people, by the API or by this server's own mail. But the fix it
     * carried was the SMTP run's, written for a total outage, so the screen said "mail
     * is going out by this server's own mail instead" in its title and then, underneath,
     * handed the operator an emergency and a relay to go and buy. Measured against a
     * real reader: they took the second half for the state of the platform, because an
     * instruction to act outranks a sentence saying everything is fine.
     *
     * So a degraded fix leads with what is TRUE — nobody is missing mail — and only then
     * describes the SMTP fault as the thing it is: one road of several, worth repairing,
     * costing nobody a message while it is down.
     */
    private static function degraded(array $r, string $road, bool $degraded): array
    {
        if (!$degraded || trim((string) ($r['fix'] ?? '')) === '') return $r;
        $by = $road === 'api' ? 'the Brevo API' : 'this server’s own mail';
        $r['fix'] = 'Nobody is missing mail: it is going out by ' . $by . ', and sign-in codes, '
            . 'receipts and announcements are all being delivered. What follows is about the SMTP '
            . 'road only, which is worth repairing but is not an outage. ' . $r['fix'];
        return $r;
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
                // Only now, and only for this cause: the probe costs a second handshake,
                // and it has nothing to say about a port that never connected. A server
                // that offered no STARTTLS has no certificate to show, so it is not asked.
                $cert = $detail === self::NO_STARTTLS ? null : ($this->certify)($c);
                if ($cert !== null) {
                    // Onto the step that already failed, not a second row for the same
                    // step: the screen walks the conversation in order, and a duplicate
                    // key appended after the not-reached filler reads as a later stage.
                    foreach ($steps as $i => $st) {
                        if ($st['key'] !== 'tls') continue;
                        $steps[$i]['detail'] = rtrim($st['detail'], ' .') . '. Presented '
                            . self::names($cert) . ' (issued by ' . $cert['issuer']
                            . ($cert['valid_to'] !== '' ? ', valid to ' . $cert['valid_to'] : '') . ') — '
                            . ($cert['matches'] ? 'this IS ' . $c->host : 'this is NOT ' . $c->host) . '.';
                        break;
                    }
                }
                $fix = self::tlsFix($c, $detail, $cert);
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
