<?php
declare(strict_types=1);
namespace AfricaGates\Services;

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
        $m->setFrom($from, $fromName);
        $m->addReplyTo($from, $fromName);
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
    public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = ''): array
    {
        if (!$this->smtpConfigured()) {
            $this->devLog($to, $subject, $plainBody ?: strip_tags($htmlBody));
            return ['success' => true, 'fallback' => 'log'];
        }
        try {
            $m = $this->mailer($to);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $this->brandWrap($subject, $htmlBody);
            $m->AltBody = $plainBody ?: strip_tags($htmlBody);
            $m->send();
            $this->log?->info('[mail] sent', ['to' => $to, 'subject' => $subject]);
            return ['success' => true];
        } catch (MailException|\Throwable $e) {
            $this->log?->error('[mail] send failed: ' . $e->getMessage(), ['to' => $to]);
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
            return ['success' => true, 'fallback' => 'log'];
        }
        try {
            $m = $this->mailer($to);
            $m->isHTML(false);
            $m->Subject = $subject;
            $m->Body    = $body;
            $m->send();
            return ['success' => true];
        } catch (MailException|\Throwable $e) {
            $this->log?->error('[mail] sendCustom failed: ' . $e->getMessage(), ['to' => $to]);
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
            return ['success' => false, 'error' => 'SMTP credentials are not configured in .env (SMTP_USER / SMTP_PASS).'];
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

    /** Domains known to issue temporary/disposable addresses. */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com','guerrillamail.com','10minutemail.com','tempmail.com',
        'throwam.com','yopmail.com','dispostable.com','fakeinbox.com',
        'trashmail.com','mailnull.com','spamgourmet.com','jetable.fr',
        'spam4.me','sharklasers.com','guerrillamailblock.com','grr.la',
        'guerrillamail.info','guerrillamail.biz','guerrillamail.de',
        'guerrillamail.net','guerrillamail.org','spam.la','maildrop.cc',
        'tempr.email','tempm.com','throwam.com','temp-mail.org',
        'discard.email','mailnesia.com','trashmail.at','trashmail.io',
        'filzmail.com','spamboy.com','akerd.com','bongobongo.cf',
    ];

    private function isDisposable(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($domain, self::DISPOSABLE_DOMAINS, true);
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

        DB::table('gates_otp_tokens')->insert([
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
        if (!$sent['success']) {
            $this->log?->error('[otp] delivery failed', ['error' => $sent['error'] ?? 'unknown']);
            return ['success' => false, 'message' => 'We could not send your verification email. Please try again.'];
        }

        $this->log?->info('[otp] issued', ['purpose' => $purpose, 'fallback' => $sent['fallback'] ?? null]);
        return ['success' => true];
    }

    private function sendOtpEmail(string $to, string $code): array
    {
        $html = <<<HTML
<p style="margin:0 0 18px;font-size:15px;color:#4b5563">Your one-time voting code is:</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 20px">
  <tr>
    <td style="background:#f0fdf4;border:2px solid #86efac;border-radius:12px;padding:18px 32px;text-align:center;font-family:Courier New,Courier,monospace;font-size:36px;font-weight:700;letter-spacing:12px;color:#15803d">$code</td>
  </tr>
</table>
<p style="margin:0 0 10px;font-size:13px;color:#6b7280">Expires in <strong>10 minutes</strong>. One vote per email per category.</p>
<p style="margin:0;font-size:12px;color:#9ca3af">Didn't request this? You can safely ignore this email — your vote has not been submitted.</p>
HTML;

        return $this->sendBranded(
            $to,
            "[Africa GATES] Your code: $code",
            $html,
            "Africa GATES verification code: $code\n\nExpires in 10 minutes. One vote per email per category.\n\nDidn't request this? Ignore this email."
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
                . "$nomineeName will appear on the public shortlist for community voting.\n\n— Africa GATES"
        );
    }

    /** Branded HTML confirmation to a partner/sponsor after enquiry. */
    public function sendPartnerConfirmation(
        string $contactEmail,
        string $contactName,
        string $organisation,
        string $tier,
    ): array {
        $html = <<<HTML
<p style="margin:0 0 14px;font-size:15px;color:#374151">Hi <strong>$contactName</strong>,</p>
<p style="margin:0 0 14px;font-size:15px;color:#374151">
  Thank you for reaching out about a <strong>$tier</strong> partnership with Africa GATES on behalf of <strong>$organisation</strong>.
  We've received your enquiry and a programme director will be in touch within two working days.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr>
    <td style="font-size:14px;color:#92400e">
      In the meantime, you can explore the <a href="https://afg.afrovanguard.org.ng/awards" style="color:#b45309">award programmes</a>
      and the <a href="https://afg.afrovanguard.org.ng/legacy" style="color:#b45309">legacy vault</a> to see the reach of each cycle.
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
                . "A programme director will be in touch within two working days.\n\n— Africa GATES"
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
    private function brandWrap(string $subject, string $body): string
    {
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>$subject</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f4f6;padding:32px 16px">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="520" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(16,41,44,0.10)">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#10292C 0%,#1a4a30 100%);padding:28px 32px;text-align:center">
              <span style="font-size:22px;font-weight:800;letter-spacing:0.04em;color:#ffffff">
                Africa <span style="color:#f3b416">GATES</span>
              </span>
              <p style="margin:6px 0 0;font-size:12px;color:rgba(255,255,255,0.6);letter-spacing:0.08em;text-transform:uppercase">Cultural Power Index</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px 32px 24px">
              $body
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 32px;text-align:center">
              <p style="margin:0;font-size:12px;color:#9ca3af">
                © $year Afrovanguard · Africa GATES &nbsp;·&nbsp;
                <a href="https://afg.afrovanguard.org.ng" style="color:#6b7280;text-decoration:none">afg.afrovanguard.org.ng</a>
              </p>
              <p style="margin:6px 0 0;font-size:11px;color:#d1d5db">
                You received this email because of activity on the Africa GATES platform.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
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
  <a href="https://afg.afrovanguard.org.ng/vote"
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
            "{$cycleName} voting closes {$closingDate}. Vote now at https://afg.afrovanguard.org.ng/vote"
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
            "Hi {$judgeName},\n\nYou've been appointed as a judge for {$programme} in Africa GATES 2026.\n\nAccess the panel: {$loginUrl}\n\n— Africa GATES"
        );
    }
}
