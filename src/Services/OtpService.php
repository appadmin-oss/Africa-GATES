<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
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
        $s = [];
        try { $s = DB::table('gates_settings')->pluck('value', 'key_name')->all(); }
        catch (\Throwable) {}

        $pick = static fn (string $key, string $env, string $dft): string
            => trim((string) ($s[$key] ?? '')) ?: (string) Env::get($env, $dft);

        return new self([
            'host'         => $pick('mail_smtp_host', 'SMTP_HOST', 'smtp-relay.brevo.com'),
            // Cast late: a settings row is a string and Env::int is not reachable through
            // $pick, so an operator typing "587 " must still produce an int port.
            'port'         => (int) $pick('mail_smtp_port', 'SMTP_PORT', '587') ?: 587,
            'username'     => $pick('mail_smtp_user', 'SMTP_USER', ''),
            'password'     => $pick('mail_smtp_pass', 'SMTP_PASS', ''),
            'from_address' => $pick('mail_from_address', 'MAIL_FROM_ADDRESS', 'noreply@afrovanguard.org.ng'),
            'from_name'    => $pick('mail_from_name', 'MAIL_FROM_NAME', 'Africa GATES'),
            'reply_to'     => $pick('mail_reply_to', 'MAIL_REPLY_TO', ''),
        ], $log);
    }

    /* ══════════════════════════════════════════════════════════
       TRANSPORT
    ══════════════════════════════════════════════════════════ */

    /** True when real (non-placeholder) SMTP credentials are configured. */
    public function smtpConfigured(): bool
    {
        $u = (string)($this->smtp['username'] ?? '');
        $p = (string)($this->smtp['password'] ?? '');
        if ($u === '' || $p === '') return false;
        $bad = ['your_brevo_login@email.com', 'your_brevo_smtp_key', 'your@email.com', 'smtp_key'];
        return !in_array($u, $bad, true) && !in_array($p, $bad, true);
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
        $m->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
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

    /**
     * Delivery audit: one row per attempted send, recipient masked. Powers the
     * admin Email-health card so failures are visible instead of silent.
     * Fault-tolerant — auditing can never break sending.
     */
    private function mailLog(string $to, string $subject, string $category, string $status, ?string $error = null): void
    {
        try {
            [$local, $domain] = array_pad(explode('@', $to, 2), 2, '');
            $masked = mb_substr($local, 0, 2) . '***@' . $domain;
            \Illuminate\Database\Capsule\Manager::table('gates_mail_log')->insert([
                'to_masked'  => mb_substr($masked, 0, 120),
                'subject'    => mb_substr($subject, 0, 200),
                'category'   => $category !== '' ? mb_substr($category, 0, 40) : null,
                'status'     => $status,
                'error'      => $error !== null ? mb_substr($error, 0, 300) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {}
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
    public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = []): array
    {
        if (!$this->smtpConfigured()) {
            $this->devLog($to, $subject, $plainBody ?: strip_tags($htmlBody));
            if ($this->isProduction()) {
                $this->log?->error('[mail] SMTP not configured in production — message NOT delivered', ['to' => $to, 'subject' => $subject]);
                $this->mailLog($to, $subject, $category, 'failed', 'SMTP not configured (Settings → Email & sender)');
                return ['success' => false, 'fallback' => 'log',
                        'error' => 'Email is not configured — set the SMTP host and login in Settings → Email & sender. The message was written to var/logs/outgoing-mail.log but was NOT delivered.'];
            }
            $this->mailLog($to, $subject, $category, 'logged_dev');
            return ['success' => true, 'fallback' => 'log'];
        }
        try {
            $m = $this->mailer($to);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $this->brandWrap($subject, $htmlBody, $category, $hero, $unsubscribeUrl);
            $m->AltBody = $plainBody ?: strip_tags($htmlBody);
            if ($unsubscribeUrl !== '') {
                $m->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
                $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
            foreach ($attachments as $f) {
                $name = trim((string) ($f['name'] ?? ''));
                if ($name === '') continue;
                // basename, because the filename travels into the reader's downloads folder
                // and a name carrying a path separator is a name that can point elsewhere.
                $m->addStringAttachment((string) ($f['body'] ?? ''), basename($name),
                                        PHPMailer::ENCODING_BASE64,
                                        (string) ($f['mime'] ?? 'application/octet-stream'));
            }
            $m->send();
            $this->log?->info('[mail] sent', ['to' => $to, 'subject' => $subject]);
            $this->mailLog($to, $subject, $category, 'sent');
            return ['success' => true];
        } catch (MailException|\Throwable $e) {
            $this->log?->error('[mail] send failed: ' . $e->getMessage(), ['to' => $to]);
            $this->mailLog($to, $subject, $category, 'failed', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
        if (!$this->smtpConfigured()) {
            $this->devLog($to, $subject, $plainBody ?: strip_tags($html));
            if ($this->isProduction()) {
                $this->log?->error('[mail] SMTP not configured in production — message NOT delivered', ['to' => $to, 'subject' => $subject]);
                $this->mailLog($to, $subject, $category, 'failed', 'SMTP not configured (Settings → Email & sender)');
                return ['success' => false, 'fallback' => 'log',
                        'error' => 'Email is not configured — set the SMTP host and login in Settings → Email & sender. The message was written to var/logs/outgoing-mail.log but was NOT delivered.'];
            }
            $this->mailLog($to, $subject, $category, 'logged_dev');
            return ['success' => true, 'fallback' => 'log'];
        }
        try {
            $m = $this->mailer($to);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;                       // verbatim — no brandWrap
            $m->AltBody = $plainBody ?: strip_tags($html);
            if ($unsubscribeUrl !== '') {
                $m->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
                $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
            $m->send();
            $this->mailLog($to, $subject, $category, 'sent');
            return ['success' => true];
        } catch (MailException|\Throwable $e) {
            $this->log?->error('[mail] sendRawHtml failed: ' . $e->getMessage(), ['to' => $to]);
            $this->mailLog($to, $subject, $category, 'failed', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a plain-text email (admin alerts, simple notifications).
     * Best-effort — falls back to log.
     */
    public function sendCustom(string $to, string $subject, string $body): array
    {
        if (!$this->smtpConfigured()) {
            $this->devLog($to, $subject, $body);
            if ($this->isProduction()) {
                $this->mailLog($to, $subject, 'custom', 'failed', 'SMTP not configured (Settings → Email & sender)');
                return ['success' => false, 'fallback' => 'log',
                        'error' => 'Email is not configured — set the SMTP host and login in Settings → Email & sender. Written to var/logs/outgoing-mail.log but NOT delivered.'];
            }
            $this->mailLog($to, $subject, 'custom', 'logged_dev');
            return ['success' => true, 'fallback' => 'log'];
        }
        try {
            $m = $this->mailer($to);
            $m->isHTML(false);
            $m->Subject = $subject;
            $m->Body    = $body;
            $m->send();
            $this->mailLog($to, $subject, 'custom', 'sent');
            return ['success' => true];
        } catch (MailException|\Throwable $e) {
            $this->log?->error('[mail] sendCustom failed: ' . $e->getMessage(), ['to' => $to]);
            $this->mailLog($to, $subject, 'custom', 'failed', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * SMTP self-test — sends a test email to $to and returns the raw result.
     * Used by the admin settings panel "Send test email" button.
     */
    public function selfTest(string $to): array
    {
        if (!$this->smtpConfigured()) {
            return ['success' => false, 'error' => 'SMTP credentials are not configured. Set them in Settings → Email & sender.'];
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
            'expires_at' => Carbon::now()->addMinutes(10)->toDateTimeString(),
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
        $html = <<<HTML
<h1 style="margin:0;font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:26px;color:#10292C;letter-spacing:-.01em">Confirm it's you</h1>
<p style="margin:13px 0 0;font-size:15px;line-height:1.65;color:#4a5256">Enter this one-time code to verify your email and cast your vote. It expires in <strong style="color:#10292C">10 minutes</strong>.</p>
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
            "Africa GATES verification code: $code\n\nExpires in 10 minutes. One vote per email per category.\n\nDidn't request this? Ignore this email.",
            'Security',
            $this->base() . '/assets/img/illustrations/illo-envelope.jpg'
        );
    }

    /* ══════════════════════════════════════════════════════════
       TRANSACTIONAL NOTIFICATIONS
    ══════════════════════════════════════════════════════════ */

    /** Branded HTML nomination confirmation to the nominator. */
    public function sendNominationConfirmation(
        string $nominatorEmail,
        string $nominatorName,
        string $nomineeName,
        string $programme,
    ): array {
        $html = <<<HTML
<p style="margin:0 0 14px;font-size:15px;color:#374151">Hi <strong>$nominatorName</strong>,</p>
<p style="margin:0 0 14px;font-size:15px;color:#374151">
  Thank you for nominating <strong>$nomineeName</strong> for the <strong>$programme</strong>.
  Your submission has been received and is now in our moderation queue.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr>
    <td style="font-size:14px;color:#166534">
      Once approved, <strong>$nomineeName</strong> will appear on the public shortlist and the community can begin voting.
      You'll receive a follow-up email when the decision is made.
    </td>
  </tr>
</table>
<p style="margin:0;font-size:14px;color:#6b7280">
  Questions? Reply to this email and our team will get back to you.
</p>
HTML;

        return $this->sendBranded(
            $nominatorEmail,
            "Your nomination of $nomineeName was received",
            $html,
            "Hi $nominatorName,\n\nThank you for nominating $nomineeName for the $programme. "
                . "Your submission is now in our moderation queue.\n\nOnce approved, "
                . "$nomineeName will appear on the public shortlist for community voting.\n\n— Africa GATES",
            'Confirmation',
            $this->base() . '/assets/img/illustrations/illo-plane.jpg'
        );
    }

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
    private function brandWrap(string $subject, string $body, string $category = '', string $hero = '', string $unsubscribeUrl = ''): string
    {
        $year    = date('Y');
        $base    = $this->base();
        $catCell = $category !== ''
            ? '<span style="font-size:10.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,0.5)">' . htmlspecialchars($category, ENT_QUOTES) . '</span>'
            : '';
        $heroRow = $hero !== ''
            ? '<tr><td style="padding:0;font-size:0;line-height:0"><img src="' . htmlspecialchars($hero, ENT_QUOTES) . '" alt="" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>'
            : '';
        // A visible way out, next to the other two footer links. The List-Unsubscribe
        // header is what Gmail reads; this is what a person reads, and only one of those
        // two is a promise the platform made in writing.
        $unsub = $unsubscribeUrl !== ''
            ? ' · <a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES)
              . '" style="color:rgba(255,255,255,0.8);text-decoration:underline">Unsubscribe</a>'
            : '';
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>$subject</title>
</head>
<body style="margin:0;padding:0;background:#dfe1dc;font-family:'DM Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#dfe1dc;padding:28px 16px">
    <tr><td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;background:#ffffff;border-radius:6px;overflow:hidden;border:1px solid rgba(16,41,44,0.06);box-shadow:0 6px 24px -12px rgba(16,41,44,0.3)">

        <!-- Pre-header -->
        <tr><td style="background:#fbfbfa;border-bottom:1px solid rgba(16,41,44,0.06);padding:9px 20px">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td align="left" style="font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#a9b0ad">Africa GATES</td>
            <td align="right" style="font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#a9b0ad">Cultural Power Index</td>
          </tr></table>
        </td></tr>

        <!-- Masthead -->
        <tr><td style="background:#10292C;padding:18px 32px">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td align="left">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                <td width="34" style="width:34px;height:34px;background:rgba(127,200,124,0.16);border-radius:9px;text-align:center;vertical-align:middle;font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:18px;color:#7FC87C">G</td>
                <td style="padding-left:11px;vertical-align:middle;line-height:1.1">
                  <span style="font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:15px;color:#ffffff">Africa</span><br>
                  <span style="font-size:9px;font-weight:700;letter-spacing:.26em;color:#7FC87C">GATES</span>
                </td>
              </tr></table>
            </td>
            <td align="right" style="vertical-align:middle">$catCell</td>
          </tr></table>
        </td></tr>

        $heroRow

        <!-- Body -->
        <tr><td style="padding:34px 40px 30px;color:#4a5256;font-size:15px;line-height:1.65">
          $body
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#0c2225;padding:24px 40px">
          <span style="font-family:'Playfair Display',Georgia,serif;font-weight:700;font-size:14px;color:#ffffff">Africa<span style="color:#7FC87C">GATES</span></span>
          <div style="height:1px;background:rgba(255,255,255,0.1);margin:14px 0"></div>
          <p style="margin:0;font-size:11.5px;line-height:1.7;color:rgba(255,255,255,0.55)">
            © $year Afrovanguard Initiative · Lagos, Nigeria · We hash every email — plain text is never stored.<br>
            <a href="{$base}/help" style="color:rgba(255,255,255,0.8);text-decoration:underline">Help Center</a> ·
            <a href="{$base}/privacy" style="color:rgba(255,255,255,0.8);text-decoration:underline">Privacy</a>$unsub
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /* ── Additional transactional emails ─────────────────────── */

    /**
     * Voting reminder — sent by the maintenance cron 48h before a cycle closes.
     * $nominees is an array of objects with ->name, ->vote_count, ->category.
     */
    public function sendVotingReminder(
        string $to,
        string $cycleName,
        string $closingDate,
        array  $topNominees = [],
    ): array {
        $base = $this->base();
        $rows = '';
        foreach (array_slice($topNominees, 0, 5) as $n) {
            $rows .= '<tr><td style="padding:6px 0;font-size:14px;color:#374151;border-bottom:1px solid #e5e7eb">'
                   . htmlspecialchars($n->name ?? '')
                   . '</td><td style="padding:6px 0;font-size:14px;color:#15803d;font-weight:600;text-align:right;border-bottom:1px solid #e5e7eb;font-family:monospace">'
                   . number_format((int)($n->vote_count ?? 0)) . ' votes</td></tr>';
        }
        $leaderTable = $rows
            ? "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='margin:16px 0'>{$rows}</table>"
            : '';

        $html = <<<HTML
<p style="font-size:16px;font-weight:700;color:#10292C;margin:0 0 8px">⏰ Voting closes soon</p>
<p style="font-size:15px;color:#374151;margin:0 0 16px">
  The <strong>{$cycleName}</strong> voting window closes on
  <strong style="color:#10292C">{$closingDate}</strong>.
  If you haven't voted yet, now is the time.
</p>
{$leaderTable}
<p style="text-align:center;margin:24px 0">
  <a href="{$base}/vote"
     style="display:inline-block;padding:14px 32px;background:#10292C;color:#fff;border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">
    Cast my vote now →
  </a>
</p>
<p style="font-size:13px;color:#9ca3af;margin-top:8px">
  One OTP-verified vote per category. Takes under a minute.
</p>
HTML;
        return $this->sendBranded(
            $to,
            "⏰ {$cycleName} voting closes {$closingDate} — have you voted?",
            $html,
            "{$cycleName} voting closes {$closingDate}. Vote now at {$base}/vote",
            'Reminder',
            $this->base() . '/assets/img/illustrations/illo-ballot-countdown.jpg'
        );
    }

    /**
     * Judge assignment — sent when an admin assigns a judge to a programme.
     */
    public function sendJudgeAssignment(
        string $to,
        string $judgeName,
        string $programme,
        string $loginUrl,
    ): array {
        $html = <<<HTML
<p>Hi <strong>{$judgeName}</strong>,</p>
<p>You have been appointed as a judge for the <strong>{$programme}</strong> in the Africa GATES 2026 awards cycle. Your scores will form 55% of each nominee's final Cultural Power Index (CPI) score.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr><td style="font-size:14px;color:#166534;line-height:1.7">
    Programme: <strong>{$programme}</strong><br>
    Your weight: <strong>55% of final score</strong><br>
    Criteria: Impact · Originality · Reach · Integrity (25% each)
  </td></tr>
</table>
<p>Access the judging panel using your assigned credentials. Score each nominee independently on the four criteria — you can save and update scores at any time before the panel closes.</p>
<p style="text-align:center;margin:24px 0">
  <a href="{$loginUrl}"
     style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">
    Access the judging panel →
  </a>
</p>
<p style="font-size:13px;color:#9ca3af">Questions? Reply to this email and a programme director will assist you.</p>
HTML;
        return $this->sendBranded(
            $to,
            "You've been appointed as a judge — {$programme}",
            $html,
            "Hi {$judgeName},\n\nYou've been appointed as a judge for {$programme} in Africa GATES 2026.\n\nAccess the panel: {$loginUrl}\n\n— Africa GATES",
            'Judges',
            $this->base() . '/assets/img/illustrations/illo-shield.jpg'
        );
    }
}
