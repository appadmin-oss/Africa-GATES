<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Brand;
use AfricaGates\Support\Env;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\Mail\AppsScriptMail;
use AfricaGates\Services\Mail\BrevoApi;
use AfricaGates\Services\Mail\MailConfig;
use AfricaGates\Services\Mail\MailFailure;
use AfricaGates\Services\Mail\MailLog;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\Mail\Suppression;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

/**
 * Transactional email + voting OTP service (PHPMailer over STARTTLS).
 *
 * All outgoing mail is routed through one shared transport builder so
 * credentials, TLS settings, and timeouts are never duplicated. Every
 * method returns ['success' => bool, ...] so callers always know
 * whether the send landed.
 *
 * When SMTP credentials are absent or are the .env.example placeholders,
 * every send falls back to var/logs/outgoing-mail.log so local dev works
 * without a mail server. The fallback is detectable via ['fallback' => 'log'].
 */
class OtpService
{
    public function __construct(
        private readonly array          $smtp,
        private readonly ?LoggerInterface $log = null,
    ) {}

    /**
     * The one place mail configuration is resolved. `gates_settings` first, `.env` as
     * the fallback.
     *
     * ── WHY THIS IS A STATIC AND NOT SIX ARRAY LITERALS ──────────────────────
     *
     * It was six. The class docblock above already promised that "all outgoing mail is
     * routed through one shared transport builder so credentials are never duplicated",
     * and that was true of the SOCKET and false of the CONFIGURATION: `container.php`,
     * `CheckoutMailer`, `CycleAnnouncer`, `SupporterHonours` and two closures in
     * `routes.php` each built the same array from `Env::get` by hand.
     *
     * Two of them had already drifted. `CheckoutMailer` grew a settings-aware `$pick()`
     * for the sender identity and kept reading the credentials from the environment, so
     * an operator who pasted a login into Settings got their from-name applied and their
     * password ignored — and `CycleAnnouncer` had no settings lookup at all, so the two
     * mailers disagreed about where configuration comes from.
     *
     * ── AND WHY THE CREDENTIALS MOVED, NOT JUST THE SENDER NAME ──────────────
     *
     * `container.php` used to carry the line "Credentials stay env-only." There is no
     * SSH on production, so that sentence means the SMTP login cannot be set at all —
     * which is precisely the GAS_URL failure CLAUDE.md records, where a whole
     * integration sat dead while every screen explained itself correctly and told the
     * operator to edit a file they cannot open. The settings page even printed the
     * symptom: "SMTP not set — mail is written to var/logs/outgoing-mail.log", above a
     * form with no field to fix it, on the platform whose OTP codes gate voting.
     *
     * `AiService::boot()` already stores provider keys this way. This is the same
     * resolver shape, for the same reason.
     */
    public static function boot(?LoggerInterface $log = null): self
    {
        return self::fromConfig(MailConfig::load(), $log);
    }

    /**
     * From the one resolver. The array this class keeps is its historical constructor
     * shape, which the suite still builds by hand; production never does.
     */
    public static function fromConfig(MailConfig $c, ?LoggerInterface $log = null): self
    {
        return new self([
            'host'         => $c->host,
            'port'         => $c->port,
            'secure'       => $c->security(),
            'username'     => $c->username,
            'password'     => $c->password,
            'from_address' => $c->fromAddress,
            'from_name'    => $c->fromName,
            'reply_to'     => $c->replyTo,
            'transport'    => $c->transport,
            'api_key'      => $c->hasApiKey() ? $c->apiKey : '',
            'smtp_env'     => $c->envSmtp,
        ], $log);
    }

    /* ══════════════════════════════════════════════════════════
       TRANSPORT
    ══════════════════════════════════════════════════════════ */

    /** True when real (non-placeholder) SMTP credentials are configured. */
    public function smtpConfigured(): bool
    {
        return MailConfig::of([
            'username' => (string) ($this->smtp['username'] ?? ''),
            'password' => (string) ($this->smtp['password'] ?? ''),
        ])->hasCredentials();
    }

    /**
     * The roads a message may take, in the order they are tried.
     *
     * `auto` is SMTP while it works (the Settings login, then `.env`'s), then Google Apps
     * Script for one-to-one mail, then the Brevo API over HTTPS — see MailConfig::TRANSPORTS
     * for why there has to be more than one road. This server's own mail is a road only
     * when none of those is set up (below), and only in production: a developer's laptop
     * calling mail() is a message to a real address from a machine nobody configured.
     *
     * An SMTP road that failed on the ROAD (connect, TLS, login) in the last half hour is
     * skipped, so one blocked port does not cost every later message a 12-second wait.
     * If it is the only road it is tried anyway: a skip must never turn into "no road".
     *
     * @return list<string>
     */
    public function routes(bool $bulk = false): array
    {
        $t = (string) ($this->smtp['transport'] ?? MailConfig::TRANSPORT_SMTP);
        $smtp = $this->smtpConfigured();
        $api  = trim((string) ($this->smtp['api_key'] ?? '')) !== '';
        $host = $this->isProduction() && MailConfig::hostMailAvailable();
        // `.env`'s own login, tried after a different one stored in Settings — see
        // MailConfig::load(). Only where SMTP is a road at all.
        $env  = $smtp && is_array($this->smtp['smtp_env'] ?? null);
        // Apps Script carries one-to-one mail only: MailApp's allowance is about 100
        // recipients a day, and a newsletter spending it is a sign-in code that cannot go.
        $gasSet = array_key_exists('gas', $this->smtp) ? (bool) $this->smtp['gas'] : AppsScriptMail::configured();
        $gas  = !$bulk && $gasSet;

        /* ── THIS SERVER'S OWN MAIL IS NOT A FALLBACK ─────────────────────────────
         *
         * `auto` used to end in `host` whatever came before it, and that one line is why
         * mail stopped arriving while the log said "sent". mail() on a shared host sends
         * as the server, not as afrovanguard.org.ng — whose SPF and DKIM are Google's and
         * Brevo's — so Gmail and Outlook discard it unseen; and mail() returns TRUE the
         * moment the local MTA accepts it. So every SMTP failure became a "sent" message
         * nobody received, and the log the operator reads to find the fault showed none.
         * Before these roads existed a failed SMTP send was a failure, and was fixed.
         *
         * It remains a road when it is the only one there is, or when chosen outright. */
        $out = match ($t) {
            MailConfig::TRANSPORT_SMTP => $smtp ? array_values(array_filter(['smtp', $env ? 'smtp-env' : null])) : [],
            MailConfig::TRANSPORT_GAS  => $gas ? ['gas'] : [],
            MailConfig::TRANSPORT_API  => $api ? ['api'] : [],
            MailConfig::TRANSPORT_HOST => $host ? ['host'] : [],
            // A configured Apps Script that a bulk send may not use still counts as set
            // up: announcements WAIT for a real road, they are not handed to mail().
            default => ($smtp || $gasSet || $api)
                ? array_values(array_filter([
                    $smtp ? 'smtp' : null, $env ? 'smtp-env' : null, $gas ? 'gas' : null, $api ? 'api' : null,
                ]))
                : ($host ? ['host'] : []),
        };
        /* A rested SMTP road waits behind Apps Script or the API — never behind the server's own mail,
           which would put every message for half an hour on the road that loses it. */
        if (($api || $gas) && count($out) > 1 && $out[0] === 'smtp' && self::smtpResting()) {
            $out = array_merge(array_values(array_diff($out, ['smtp', 'smtp-env'])),
                               array_values(array_intersect($out, ['smtp', 'smtp-env'])));
        }
        return $out;
    }

    /**
     * Can a message leave at all, by any road? What a sender asks before it starts a run —
     * `smtpConfigured()` asked only about one road, so a deployment sending perfectly over
     * the API or the host's own mail was told by every batch job that email was off.
     */
    public function canSend(bool $bulk = false): bool
    {
        return $this->routes($bulk) !== [];
    }

    /** The setting that rests a failing SMTP road. */
    public const SMTP_REST_KEY = 'mail_smtp_rest_until';
    public const SMTP_REST_MIN = 30;

    private static function smtpResting(): bool
    {
        try {
            $until = (string) \Illuminate\Database\Capsule\Manager::table('gates_settings')
                ->where('key_name', self::SMTP_REST_KEY)->value('value');
            return $until !== '' && $until > Carbon::now()->toDateTimeString();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function restSmtp(bool $rest): void
    {
        try {
            $q = \Illuminate\Database\Capsule\Manager::table('gates_settings');
            $rest
                ? $q->updateOrInsert(['key_name' => self::SMTP_REST_KEY],
                    ['value' => Carbon::now()->addMinutes(self::SMTP_REST_MIN)->toDateTimeString(),
                     'updated_at' => Carbon::now()->toDateTimeString()])
                : $q->where('key_name', self::SMTP_REST_KEY)->delete();
        } catch (\Throwable) {
        }
    }

    /** Absolute site base URL for every link/image in email — from APP_URL, no trailing slash. */
    private function base(): string
    {
        return rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
    }

    /**
     * True in production. The var/logs/outgoing-mail.log fallback is a DEV
     * convenience only — in production a missing SMTP config is a real outage,
     * so transactional sends (OTP, magic-link) must report failure rather than
     * silently "succeed" to a log nobody reads.
     */
    private function isProduction(): bool
    {
        return strtolower((string) Env::get('APP_ENV', 'production')) === 'production';
    }

    /** Build a configured PHPMailer instance ready to send to $to. */
    private function mailer(string $to): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->SMTPDebug   = SMTP::DEBUG_OFF;
        $m->Host        = (string)($this->smtp['host'] ?? 'smtp-relay.brevo.com');
        $m->Port        = (int)($this->smtp['port'] ?? 587);
        $m->SMTPAuth    = true;
        $m->Username    = (string)$this->smtp['username'];
        $m->Password    = (string)$this->smtp['password'];
        // From the port unless the operator chose otherwise — see MailConfig::security().
        // This was STARTTLS whatever the port, so every send on 465 (implicit TLS, the
        // port most providers other than Brevo hand out) waited for a greeting that never
        // came and died on the timeout as "SMTP connect() failed".
        $secure = (string) ($this->smtp['secure'] ?? '');
        if ($secure === '') {
            $secure = ((int) ($this->smtp['port'] ?? 587)) === 465
                ? MailConfig::SECURE_SMTPS : MailConfig::SECURE_STARTTLS;
        }
        $m->SMTPSecure  = match ($secure) {
            MailConfig::SECURE_SMTPS => PHPMailer::ENCRYPTION_SMTPS,
            MailConfig::SECURE_NONE  => '',
            default                  => PHPMailer::ENCRYPTION_STARTTLS,
        };
        // PHPMailer upgrades to TLS on its own whenever the server offers it, which
        // silently overrides an explicit "none" — the one setting it exists to honour.
        $m->SMTPAutoTLS = $secure !== MailConfig::SECURE_NONE;
        $m->Timeout     = 12;
        $m->SMTPKeepAlive = false;
        $m->CharSet     = PHPMailer::CHARSET_UTF8;
        $m->XMailer     = ' ';

        $from     = (string)($this->smtp['from_address'] ?? 'noreply@afrovanguard.org.ng');
        $fromName = (string)($this->smtp['from_name']    ?? 'Africa GATES');
        // Reply-To can point at a monitored inbox (e.g. africa-gates@…) while
        // From stays the domain-aligned envelope sender for SPF/DMARC. Falls
        // back to the From address when no reply-to is configured.
        $replyTo  = trim((string)($this->smtp['reply_to'] ?? '')) ?: $from;
        $m->setFrom($from, $fromName);
        $m->addReplyTo($replyTo, $fromName);
        $m->Sender = $from; // envelope-from aligned with From for SPF/DMARC
        $m->addAddress($to);
        return $m;
    }

    /** Append to the dev log file (fallback when SMTP is unconfigured). */
    private function devLog(string $to, string $subject, string $body): void
    {
        $dir = dirname(__DIR__, 2) . '/var/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/outgoing-mail.log',
            "\n=== " . date('c') . " ===\nTo: $to\nSubject: $subject\n\n$body\n",
            FILE_APPEND);
        $this->log?->warning('[mail] SMTP not configured — wrote to outgoing-mail.log',
            ['to' => $to, 'subject' => $subject]);
    }

    /* ══════════════════════════════════════════════════════════
       LOW-LEVEL SENDERS
    ══════════════════════════════════════════════════════════ */

    /**
     * Send a fully-branded HTML email.
     * Falls back to plain text log when SMTP is unconfigured.
     */
    /**
     * @param string $unsubscribeUrl absolute URL. Empty for one-to-one mail, which is what
     *        this method was written for; set for anything a reader could reasonably call
     *        an announcement. It adds the RFC 8058 one-click headers — see
     *        {@see sendRawHtml} for why they are not optional on bulk — and puts a visible
     *        link in the footer, because a header alone is a way out only for the clients
     *        that render one.
     */
    /**
     * @param list<array{name:string, mime:string, body:string}> $attachments files built in
     *        memory and attached by value. Deliberately not paths: everything this platform
     *        attaches is generated for the message (a schedule, a receipt), and accepting a
     *        path would make it possible to attach a file somebody else's request named.
     */
    public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
    {
        return $this->dispatch($to, $subject, $category, $unsubscribeUrl, $plainBody ?: strip_tags($htmlBody),
            function (PHPMailer $m) use ($subject, $htmlBody, $plainBody, $category, $hero, $unsubscribeUrl, $attachments, $preheader, $heroHeight, $why): void {
                $m->isHTML(true);
                $m->Body    = $this->brandWrap($subject, $htmlBody, $category, $hero, $unsubscribeUrl, $preheader, $heroHeight, $why);
                $m->AltBody = $plainBody ?: strip_tags($htmlBody);
                foreach ($attachments as $f) {
                    $name = trim((string) ($f['name'] ?? ''));
                    if ($name === '') continue;
                    // basename, because the filename travels into the reader's downloads
                    // folder and a name carrying a path separator can point elsewhere.
                    $m->addStringAttachment((string) ($f['body'] ?? ''), basename($name),
                                            PHPMailer::ENCODING_BASE64,
                                            (string) ($f['mime'] ?? 'application/octet-stream'));
                }
            });
    }

    /**
     * Send a COMPLETE HTML document as-is, with no brand wrapper.
     *
     * {@see sendBranded} passes its body through {@see brandWrap}, which is right for the
     * short transactional notes it was written for and wrong for a designed campaign: the
     * result is an <html> document nested inside another one, which Outlook renders as
     * literal markup. {@see sendCustom} is plain text only. So this exists for a template
     * that is already a whole email — templates/emails/*.twig.
     *
     * ── LIST-UNSUBSCRIBE IS NOT OPTIONAL FOR BULK ────────────────────────────────
     * Gmail and Yahoo's 2024 bulk-sender rules expect a one-click unsubscribe header on
     * anything that looks like a campaign, and mail without it lands in Promotions or
     * Spam regardless of how careful the content is. Both headers go together:
     * List-Unsubscribe carries the URL, List-Unsubscribe-Post is what tells the client it
     * may POST to it without asking the reader to confirm — which is why the endpoint
     * accepts its parameters from the QUERY STRING as well as the body (see
     * EmailPrefsController::stop), since a one-click POST body is just the RFC 8058
     * marker and carries no fields of ours.
     *
     * @param string $unsubscribeUrl absolute URL; when '' no list headers are set, which
     *                               is correct for one-to-one mail and wrong for a campaign
     * @return array{success:bool, error?:string, fallback?:string}
     */
    public function sendRawHtml(string $to, string $subject, string $html, string $plainBody = '', string $category = 'campaign', string $unsubscribeUrl = ''): array
    {
        return $this->dispatch($to, $subject, $category, $unsubscribeUrl, $plainBody ?: strip_tags($html),
            static function (PHPMailer $m) use ($html, $plainBody): void {
                $m->isHTML(true);
                $m->Body    = $html;                       // verbatim — no brandWrap
                $m->AltBody = $plainBody ?: strip_tags($html);
            });
    }

    /**
     * Send a plain-text email (admin alerts, simple notifications).
     * Best-effort — falls back to log.
     */
    public function sendCustom(string $to, string $subject, string $body): array
    {
        return $this->dispatch($to, $subject, 'custom', '', $body,
            static function (PHPMailer $m) use ($body): void {
                $m->isHTML(false);
                $m->Body = $body;
            });
    }

    /**
     * The one road every message takes: the send rules, the transport, the headers, the
     * log, and what a refusal teaches us.
     *
     * The three public senders used to carry three copies of this — the unconfigured
     * fallback, the try/catch, the four log calls — and the copies are where rules go to
     * be applied twice or not at all. A message is an ANNOUNCEMENT when it carries an
     * unsubscribe link; see {@see SendPolicy} for what that changes.
     *
     * @param callable(PHPMailer): void $build sets the body on a configured mailer
     * @return array{success:bool, error?:string, fallback?:string, held?:string}
     */
    private function dispatch(string $to, string $subject, string $category, string $unsubscribeUrl,
                              string $devBody, callable $build): array
    {
        $bulk = $unsubscribeUrl !== '';
        $routes = $this->routes($bulk);

        // An announcement with no road of its own while one-to-one mail has one (Apps
        // Script only): it WAITS for SMTP rather than failing — held, not lost, and not
        // counted towards a mail incident.
        if ($routes === [] && $bulk && $this->routes(false) !== []) {
            MailLog::write($to, $subject, $category, MailLog::DEFERRED,
                'Announcements wait for SMTP: the only road open is Google Apps Script, kept for sign-in codes and receipts.', $bulk);
            return ['success' => false, 'held' => MailLog::DEFERRED, 'error' => 'Announcements wait for SMTP.'];
        }

        if ($routes === []) {
            $this->devLog($to, $subject, $devBody);
            if ($this->isProduction()) {
                $this->log?->error('[mail] no way to send configured in production — message NOT delivered', ['to' => $to, 'subject' => $subject]);
                MailLog::write($to, $subject, $category, MailLog::FAILED, 'Email is not configured (Settings → Email health)', $bulk);
                return ['success' => false, 'fallback' => 'log',
                        'error' => 'Email is not configured — set it up in Settings → Email health. The message was written to var/logs/outgoing-mail.log but was NOT delivered.'];
            }
            MailLog::write($to, $subject, $category, MailLog::DEV, null, $bulk);
            return ['success' => true, 'fallback' => 'log'];
        }

        // Before any connection: a message the rules hold costs the domain nothing.
        if (($held = SendPolicy::decide($to, $bulk)) !== null) {
            MailLog::write($to, $subject, $category, $held['status'], $held['reason'], $bulk);
            return ['success' => false, 'held' => $held['status'], 'error' => $held['reason']];
        }

        try {
            $m = $this->mailer($to);
            $m->Subject = $subject;
            $build($m);
            $this->headers($m, $category, $unsubscribeUrl);
        } catch (MailException|\Throwable $e) {
            // A message that cannot even be built (an address PHPMailer rejects) is not a
            // road problem, and no other road would fix it.
            MailLog::write($to, $subject, $category, MailLog::FAILED, $e->getMessage(), $bulk);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // ── ONE MESSAGE, SEVERAL ROADS ───────────────────────────────────────
        // Tried in order. A failure that is about the ROAD moves on to the next one; a
        // refused RECIPIENT stops, because every road delivers to the same mailbox and
        // the second attempt would only bounce again. What reached the log is the road
        // that finally carried it, and every road that failed on the way, so an operator
        // reading "sent" can also read that SMTP has been failing for a week.
        $failed = [];
        foreach ($routes as $i => $road) {
            try {
                match ($road) {
                    'api'  => $this->transmitApi($m),
                    'gas'  => $this->transmitGas($m),
                    'host' => (function () use ($m): void { $m->isMail(); $this->transmit($m); })(),
                    'smtp-env' => (function () use ($m): void { $this->useEnvLogin($m); $m->isSMTP(); $this->transmit($m); })(),
                    default => (function () use ($m): void { $m->isSMTP(); $this->transmit($m); })(),
                };
                // Only the stored login succeeding lifts its rest; `.env`'s carrying the
                // message says nothing about whether the stored one works yet.
                if ($road === 'smtp') self::restSmtp(false);
                $note = $failed === [] ? null : 'via ' . $road . ' after: ' . implode(' | ', $failed);
                $this->log?->info('[mail] sent', ['to' => $to, 'subject' => $subject, 'via' => $road]);
                MailLog::write($to, $subject, $category, MailLog::SENT, $note, $bulk);
                return ['success' => true, 'via' => $road];
            } catch (MailException|\Throwable $e) {
                $err = $e->getMessage();
                $cause = MailFailure::classify($err);
                $this->log?->error('[mail] send failed via ' . $road . ': ' . $err, ['to' => $to]);
                if ($cause === MailFailure::RECIPIENT || $i === count($routes) - 1) {
                    $all = $failed === [] ? $err : implode(' | ', array_merge($failed, [$road . ': ' . $err]));
                    MailLog::write($to, $subject, $category, MailLog::FAILED, $all, $bulk);
                    // The receiving server said, permanently, that this mailbox does not exist.
                    // Remember it, or every announcement after this one bounces too.
                    if (Suppression::isPermanentBounce($err)) {
                        Suppression::record($to, Suppression::BOUNCE, 'smtp', $err);
                    }
                    return ['success' => false, 'error' => $err];
                }
                if ($road === 'smtp') self::restSmtp(true);
                $failed[] = $road . ': ' . $err;
            }
        }
        return ['success' => false, 'error' => 'no road delivered it'];
    }

    /**
     * Point a built message at `.env`'s SMTP login instead of the stored one. The From
     * stays what it was: the sender identity is a separate setting, and swapping it here
     * would make the second road send as somebody else.
     */
    private function useEnvLogin(PHPMailer $m): void
    {
        $e = (array) ($this->smtp['smtp_env'] ?? []);
        $m->Host     = (string) ($e['host'] ?? $m->Host);
        $m->Port     = (int) ($e['port'] ?? $m->Port);
        $m->Username = (string) ($e['username'] ?? '');
        $m->Password = (string) ($e['password'] ?? '');
        $sec = MailConfig::of(['port' => $m->Port, 'secure' => (string) ($e['secure'] ?? MailConfig::SECURE_AUTO)])->security();
        $m->SMTPSecure  = match ($sec) {
            MailConfig::SECURE_SMTPS => PHPMailer::ENCRYPTION_SMTPS,
            MailConfig::SECURE_NONE  => '',
            default                  => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $m->SMTPAutoTLS = $sec !== MailConfig::SECURE_NONE;
    }

    /**
     * Hand a built message to Brevo's API. Kept apart from `transmit()` for the same
     * reason: the suite replaces the one line that leaves the process.
     */
    protected function transmitApi(PHPMailer $m): void
    {
        (new BrevoApi((string) ($this->smtp['api_key'] ?? '')))->send($m);
    }

    /** Hand a built message to the platform's Apps Script. See AppsScriptMail. */
    protected function transmitGas(PHPMailer $m): void
    {
        AppsScriptMail::boot()->send($m);
    }

    /**
     * Hand a built message to the network. The one line of the pipeline that leaves the
     * process, kept apart so the suite can run every rule above it against a real
     * PHPMailer message without a server — {@see \Tests\Unit\MailSendRulesTest}.
     */
    protected function transmit(PHPMailer $m): void
    {
        $m->send();
    }

    /**
     * The headers that say what kind of message this is.
     *
     * EVERY MESSAGE gets a Message-ID on our own domain. PHPMailer otherwise builds it
     * from the server's hostname — on shared hosting a name like `server123.hostco.net`
     * that matches nothing in From, which is one more thing a filter scores against us.
     *
     * AN ANNOUNCEMENT also gets:
     *   · List-Unsubscribe + List-Unsubscribe-Post — RFC 8058 one-click, which Gmail and
     *     Yahoo require of bulk senders;
     *   · List-Id — so a reader's filters, and a provider's, can see one list per kind of
     *     announcement rather than one undifferentiated sender;
     *   · Precedence: bulk and Auto-Submitted: auto-generated — RFC 3834: no vacation
     *     reply comes back to a newsletter, and nobody's out-of-office lands in our inbox;
     *   · Feedback-ID — Gmail's complaint loop reports spam rates per this identifier, so
     *     a newsletter that annoys people can be told apart from a voting reminder that
     *     does not.
     */
    private function headers(PHPMailer $m, string $category, string $unsubscribeUrl): void
    {
        $domain = strtolower((string) substr((string) strrchr($m->From, '@'), 1)) ?: 'africagates.org';
        $m->MessageID = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);

        if ($unsubscribeUrl === '') return;

        $list = trim((string) preg_replace('~[^a-z0-9]+~', '-', strtolower($category !== '' ? $category : 'announcements')), '-');
        $m->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        // Letters, digits and spaces only in the display part: a category is an internal
        // label, but a header is the one place a stray character becomes a second header.
        $label = trim((string) preg_replace('~[^A-Za-z0-9 \-]+~', '', $category)) ?: 'announcements';
        $m->addCustomHeader('List-Id', sprintf('Africa GATES %s <%s.%s>', $label, $list ?: 'announcements', $domain));
        $m->addCustomHeader('Precedence', 'bulk');
        $m->addCustomHeader('Auto-Submitted', 'auto-generated');
        $m->addCustomHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        $m->addCustomHeader('Feedback-ID', ($list ?: 'announcements') . ':bulk:africagates');
    }

    /**
     * SMTP self-test — sends a test email to $to and returns the raw result.
     * Used by the admin settings panel "Send test email" button.
     */
    public function selfTest(string $to): array
    {
        if (!$this->canSend()) {
            return ['success' => false, 'error' => 'Email is not set up — no road to send by. Set it up in Settings → Email health.'];
        }
        return $this->sendBranded(
            $to,
            'Africa GATES — SMTP test',
            '<p>This test email confirms your SMTP settings are working correctly.</p>'
                . '<p style="color:#555">Sent at: <strong>' . date('Y-m-d H:i:s T') . '</strong></p>',
            'SMTP test successful. Sent at: ' . date('Y-m-d H:i:s T')
        );
    }

    /* ══════════════════════════════════════════════════════════
       OTP FLOW
    ══════════════════════════════════════════════════════════ */

    /**
     * Disposable/throwaway detection lives in the shared, admin-extensible
     * {@see \AfricaGates\Support\DisposableEmail} so the blocklist can grow
     * without a code deploy and is reused by other entry points (registration).
     */
    private function isDisposable(string $email): bool
    {
        return \AfricaGates\Support\DisposableEmail::isDisposable($email);
    }

    /**
     * How long a vote code lives. Read by the mint, both halves of the email and the ballot's
     * code screen, so the four cannot disagree: it was typed into three of them, and the
     * screen a voter types the code into said nothing about it at all.
     */
    public const VOTE_CODE_MINUTES = 10;

    /**
     * Generate and email a 6-digit voting OTP.
     * Invalidates any outstanding code for this email+purpose first
     * so there is always at most one live code per user.
     */
    public function generate(
        string $email,
        int    $nomineeId,
        int    $awardId,
        string $purpose = 'vote',
    ): array {
        if ($this->isDisposable($email)) {
            return ['success' => false, 'message' => 'Disposable email addresses cannot be used for voting. Please use a permanent email address.'];
        }

        $eh   = hash('sha256', strtolower(trim($email)));
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Invalidate any previous live code for this purpose
        DB::table('gates_otp_tokens')
            ->where('email_hash', $eh)->where('purpose', $purpose)->where('is_used', 0)
            ->update(['is_used' => 1]);

        $tokenId = (int) DB::table('gates_otp_tokens')->insertGetId([
            'email_hash' => $eh,
            'token_hash' => hash('sha256', $code),
            'purpose'    => $purpose,
            'nominee_id' => $nomineeId,
            'award_id'   => $awardId,
            'attempts'   => 0,
            'is_used'    => 0,
            'expires_at' => Carbon::now()->addMinutes(self::VOTE_CODE_MINUTES)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $sent = $this->sendOtpEmail($email, $code);

        // ── RECORD WHETHER THE CODE ACTUALLY LEFT THE BUILDING ───────────────
        //
        // This function has always known. It checked $sent, told the visitor we
        // could not send it, and discarded the fact — leaving a token row that is
        // indistinguishable from one belonging to somebody who got their code and
        // decided not to bother.
        //
        // That distinction is the entire basis on which a dropped vote may later be
        // repaired. "We failed to deliver this person's code" is a statement the
        // platform can make about itself, from its own records, written before
        // anybody knew it would matter — which is what makes
        // {@see \AfricaGates\Services\VoteRecoveryService} a repair mechanism rather
        // than a way to add votes. Without it, the only available evidence would be
        // somebody's later say-so, and there is no safe way to build on that.
        //
        // Best-effort: a failure to write the delivery state must never turn a
        // working OTP send into a broken one. The cost of it going unrecorded is
        // that the vote is not recoverable, which is the safe direction to fail in.
        self::recordDelivery($tokenId, (bool) $sent['success'], (string) ($sent['error'] ?? ''));

        if (!$sent['success']) {
            $this->log?->error('[otp] delivery failed', ['error' => $sent['error'] ?? 'unknown']);
            return ['success' => false, 'message' => 'We could not send your verification email. Please try again.'];
        }

        $this->log?->info('[otp] issued', ['purpose' => $purpose, 'fallback' => $sent['fallback'] ?? null]);
        return ['success' => true];
    }

    /** Stamp a token with what happened to its code. Never throws. */
    public static function recordDelivery(int $tokenId, bool $ok, string $error = ''): void
    {
        if ($tokenId < 1) return;
        try {
            DB::table('gates_otp_tokens')->where('id', $tokenId)->update(
                \AfricaGates\Support\OptionalColumn::filter('gates_otp_tokens', [
                    'delivery_state' => $ok ? 'sent' : 'failed',
                    'delivery_error' => $ok ? null : (mb_substr($error, 0, 300) ?: 'unknown'),
                    'delivery_at'    => Carbon::now()->toDateTimeString(),
                ], ['delivery_state', 'delivery_error', 'delivery_at']));
        } catch (\Throwable) { /* see the note above: silence here only costs recoverability */ }
    }

    private function sendOtpEmail(string $to, string $code): array
    {
        $mins = self::VOTE_CODE_MINUTES;
        $html = <<<HTML
<h1 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:26px;color:#10292C;letter-spacing:-.01em">Confirm it's you</h1>
<p style="margin:13px 0 0;font-size:15px;line-height:1.65;color:#4a5256">Enter this one-time code to verify your email and cast your vote. It expires in <strong style="color:#10292C">{$mins} minutes</strong>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0">
  <tr><td style="background:#f4f7f4;border:1px solid #d6e8d3;border-radius:14px;padding:24px;text-align:center">
    <div style="font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#92a6a7;margin-bottom:12px">Your verification code</div>
    <div style="font-family:'JetBrains Mono',Consolas,'Courier New',monospace;font-weight:700;font-size:38px;letter-spacing:.34em;color:#10292C;padding-left:.34em">$code</div>
  </td></tr>
</table>
<p style="margin:0;font-size:13px;line-height:1.6;color:#92a6a7">Didn't request this? Ignore this email — no one can vote as you, and your account stays secure. One vote per email per category.</p>
HTML;

        return $this->sendBranded(
            $to,
            'Africa GATES — your verification code',
            $html,
            "Africa GATES verification code: $code\n\nExpires in " . self::VOTE_CODE_MINUTES . " minutes. One vote per email per category.\n\nDidn't request this? Ignore this email.",
            'Security',
            $this->base() . '/assets/img/illustrations/illo-envelope.jpg'
        );
    }

    /* ══════════════════════════════════════════════════════════
       TRANSACTIONAL NOTIFICATIONS
    ══════════════════════════════════════════════════════════ */

    /** Branded HTML confirmation to a partner/sponsor after enquiry. */
    public function sendPartnerConfirmation(
        string $contactEmail,
        string $contactName,
        string $organisation,
        string $tier,
    ): array {
        $base = $this->base();
        $html = <<<HTML
<p style="margin:0 0 14px;font-size:15px;color:#374151">Hi <strong>$contactName</strong>,</p>
<p style="margin:0 0 14px;font-size:15px;color:#374151">
  Thank you for reaching out about a <strong>$tier</strong> partnership with Africa GATES on behalf of <strong>$organisation</strong>.
  We've received your enquiry and a programme director will be in touch within two working days.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr>
    <td style="font-size:14px;color:#92400e">
      In the meantime, you can explore the <a href="{$base}/awards" style="color:#b45309">award programmes</a>
      and the <a href="{$base}/legacy" style="color:#b45309">legacy vault</a> to see the reach of each cycle.
    </td>
  </tr>
</table>
<p style="margin:0;font-size:14px;color:#6b7280">
  Questions? Reply directly to this email.
</p>
HTML;

        return $this->sendBranded(
            $contactEmail,
            "Your partnership enquiry with Africa GATES",
            $html,
            "Hi $contactName,\n\nThank you for your $tier partnership enquiry on behalf of $organisation. "
                . "A programme director will be in touch within two working days.\n\n— Africa GATES",
            'Partnership',
            $this->base() . '/assets/img/illustrations/illo-ribbon.jpg'
        );
    }

    /* ══════════════════════════════════════════════════════════
       BRANDING WRAPPER
    ══════════════════════════════════════════════════════════ */

    /**
     * Wraps any HTML body in the standard Africa GATES email frame.
     * Uses <table> layout throughout for maximum email-client compatibility
     * (Outlook, Gmail app, Apple Mail, Yahoo Mail).
     */
    /**
     * The house shell every branded email arrives in.
     *
     * PUBLIC, and not for a test: the admin preview has to show what the RECIPIENT gets.
     * The invitation preview used to render its body and show that, so the operator read
     * one document and the inbox received another — which is exactly how a shell problem
     * survives four rounds of review.
     *
     * ── WHAT WAS ADDED, AND FOR WHICH CLIENT ─────────────────────────────────
     *
     * This carried the design and none of the scaffolding. Six of the twelve properties
     * EmailInboxCompatCase holds were missing — from the shell EVERY transactional message
     * on this platform goes out in, not from one template:
     *
     *   · No MSO conditional table. Outlook desktop ignores `max-width`, so the 600px card
     *     rendered edge to edge at whatever width the window was.
     *   · No VML behind the buttons in the bodies above, so Outlook drew them as bare text
     *     with the background dropped.
     *   · No hidden preheader, so Gmail's preview line read "Africa GATES Cultural Power
     *     Index" — the pre-header strip — for every message the platform sends.
     *   · No `x-apple-data-detectors` neutraliser: iOS finds dates and addresses, wraps
     *     them in its own anchor and restyles them blue.
     *   · No `color-scheme`, so Gmail and Outlook.com inverted a near-white card badly.
     *   · The hero image had a width and no HEIGHT, so a blocked image — Outlook desktop
     *     blocks by default — collapsed the hero to nothing.
     *
     * The look is unchanged. This is the difference between a design and a design that
     * arrives.
     *
     * @param string $preheader the line an inbox shows beside the subject. Falls back to
     *                          the subject rather than to the body's first words, which is
     *                          what a client picks up when there is nothing hidden for it.
     */
    public function brandWrap(string $subject, string $body, string $category = '', string $hero = '', string $unsubscribeUrl = '', string $preheader = '', int $heroHeight = 0, string $why = ''): string
    {
        // The markup is templates/emails/_shell.twig (design_handoff_letter_email_admin,
        // "Shell, final"), whose docblock lists what it must keep and which test keeps it.
        // What is decided HERE is only what the template cannot know:
        //
        //  · the mark comes from Brand, never a typed path: Brand::MARK, the square mark the
        //    handoff draws at 26×26 in a 40px white tile beside the name set as text (the
        //    file is 192px, so it is sharp at 2x and better on a phone);
        //  · the hero keeps a HEIGHT even when the caller has none: Outlook desktop blocks
        //    remote images by default, and a blocked image with no height collapses the band
        //    it is the only thing in — 260 is the shipped 600×260 crop;
        //  · the preheader falls back to the subject — without one, every message here
        //    previewed as the first words of the masthead;
        //  · the footer says who sent it and where they are (MailConfig::postal()). It used
        //    to claim "we hash every email — plain text is never stored", which three tables
        //    contradict; a footer is a statement in writing to every recipient.
        return \AfricaGates\Services\Mail\EmailView::env()->render('emails/_shell.twig', [
            'c'               => Accent::mail(),
            'subject'         => $subject,
            'preheader'       => $preheader !== '' ? $preheader : $subject,
            'category'        => $category,
            'hero'            => $hero,
            'hero_height'     => $heroHeight > 0 ? $heroHeight : 260,
            'body'            => $body,
            'why'             => $why,
            'logo'            => Brand::markUrl($this->base()),
            'base'            => $this->base(),
            'postal'          => MailConfig::postal(),
            'year'            => date('Y'),
            // A visible way out beside Help and Privacy. The List-Unsubscribe header is what
            // Gmail reads; this is what a person reads.
            'unsubscribe_url' => $unsubscribeUrl,
        ]);
    }

    /* ── Additional transactional emails ─────────────────────── */

    /**
     * "Voting closes soon" — sent by the maintenance tick 24–48 hours before a cycle closes.
     *
     * An announcement, so it carries the list headers and the footer's stop link. The copy
     * is the reader's: what closes, when (with its zone — a deadline without one is an hour
     * out for somebody), and the one thing to do. No emoji in the subject: it reads as a
     * promotion to the filters that sort Primary from Promotions, and the reminder is the
     * one announcement whose whole value is arriving on time where it is seen.
     *
     * @param string $cycleName   the award(s) closing, as the site names them
     * @param string $closingDate the close, already in the display zone with its zone
     * @param list<object> $topNominees ->name, ->vote_count
     */
    public function sendVotingReminder(
        string $to,
        string $cycleName,
        string $closingDate,
        array  $topNominees = [],
        string $unsubscribeUrl = '',
    ): array {
        $base  = $this->base();
        $ink   = Accent::hex('ink');
        $ink2  = Accent::hex('ink-2');
        $soft  = Accent::hex('soft');
        $line  = Accent::hex('line');
        $go    = Accent::hex('green');
        $name  = htmlspecialchars($cycleName, ENT_QUOTES);
        $when  = htmlspecialchars($closingDate, ENT_QUOTES);

        $rows = '';
        foreach (array_slice($topNominees, 0, 5) as $n) {
            $rows .= '<tr><td style="padding:9px 0;font-size:14px;color:' . $ink . ';border-bottom:1px solid ' . $line . '">'
                   . htmlspecialchars((string) ($n->name ?? ''), ENT_QUOTES)
                   . '</td><td style="padding:9px 0;font-size:14px;color:' . $ink2 . ';text-align:right;border-bottom:1px solid ' . $line . ';white-space:nowrap">'
                   . number_format((int) ($n->vote_count ?? 0)) . ' votes</td></tr>';
        }
        $leaders = $rows !== ''
            ? '<p style="margin:22px 0 4px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:' . $soft . '">Leading so far</p>'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
            : '';

        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">Voting closes soon</h1>'
              . '<p style="margin:12px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">Voting for <strong style="color:' . $ink . '">' . $name . '</strong> closes on <strong style="color:' . $ink . '">' . $when . '</strong>. If you have not voted yet, there is still time.</p>'
              . $leaders
              . '<p style="text-align:center;margin:28px 0 8px"><a href="' . $base . '/vote" style="display:inline-block;padding:14px 32px;background:' . $go . ';color:' . Accent::hex('surface') . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">Vote now</a></p>'
              . '<p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:' . $soft . '">One vote per category, confirmed with a code we send you. It takes under a minute.</p>';

        $plain = "Voting for {$cycleName} closes on {$closingDate}.\n\nVote now: {$base}/vote\n\n"
               . "One vote per category, confirmed with a code we send you."
               . ($unsubscribeUrl !== '' ? "\n\nStop these emails: {$unsubscribeUrl}" : '');

        return $this->sendBranded(
            $to,
            "Voting closes {$closingDate} — {$cycleName}",
            $html,
            $plain,
            'Reminder',
            $base . '/assets/img/illustrations/illo-ballot-countdown.jpg',
            $unsubscribeUrl,
            [],
            'Still time to vote — one vote per category, under a minute.'
        );
    }

}
