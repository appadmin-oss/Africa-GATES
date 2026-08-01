<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Env;
/**
 * Thin, dependency-free helper that routes submission notifications through
 * the existing OtpService SMTP transport. Every call is best-effort: a null
 * mailer or a send failure is swallowed so a notification problem can never
 * break a user-facing submission.
 */
final class Notifier {
    /** Resolve the operator inbox for admin alerts (admin setting → env → default). */
    public static function adminEmail(): string {
        $e = '';
        try {
            $e = (string)(\Illuminate\Database\Capsule\Manager::table('gates_settings')
                ->where('key_name', 'admin_alert_email')->value('value') ?? '');
        } catch (\Throwable $ex) {}
        $e = trim($e);
        if ($e === '') $e = (string) Env::get('ADMIN_ALERT_EMAIL', '');
        if ($e === '') $e = (string) Env::get('MAIL_FROM_ADDRESS', '');
        if ($e === '') $e = 'app.admin@afrovanguard.org.ng';
        return $e;
    }

    /**
     * The address a member is told to write to.
     *
     * Separate from {@see adminEmail()} on purpose: admin alerts are internal
     * plumbing and can point at whoever is on call, whereas THIS address is
     * printed on pages, quoted by the assistant and put in ticket emails — so it
     * has to be one a stranger can write to and get an answer from. Resolving it
     * in one place is what stops the site telling three different stories about
     * where to send a complaint, which is exactly what it did before.
     */
    public static function supportEmail(): string {
        $e = '';
        try {
            $e = (string)(\Illuminate\Database\Capsule\Manager::table('gates_settings')
                ->where('key_name', 'support_email')->value('value') ?? '');
        } catch (\Throwable $ex) {}
        $e = trim($e);
        if ($e === '') $e = trim((string) Env::get('SUPPORT_EMAIL', ''));
        if ($e === '') $e = 'gates@afrovanguard.org.ng';
        return $e;
    }

    /** Email the operators about a new submission — branded HTML. Best-effort. */
    public static function adminAlert(?OtpService $mailer, string $subject, string $body): void {
        if ($mailer === null) return;
        try { $mailer->sendBranded(self::adminEmail(), '[Africa GATES] ' . $subject, self::htmlify($body), $body, 'Admin'); }
        catch (\Throwable $e) { error_log('[Notifier] admin alert failed: ' . $e->getMessage()); }
    }

    /** Send a branded confirmation to a user who just submitted something. Best-effort. */
    public static function confirm(?OtpService $mailer, string $to, string $subject, string $body): void {
        if ($mailer === null) return;
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        try { $mailer->sendBranded($to, $subject, self::htmlify($body), $body, 'Confirmation'); }
        catch (\Throwable $e) { error_log('[Notifier] confirm failed: ' . $e->getMessage()); }
    }

    /** Render a plain-text notification body as safe, lightly-styled HTML (links clickable). */
    private static function htmlify(string $body): string {
        $safe = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        $safe = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#10785a">$1</a>', $safe) ?? $safe;
        return '<div style="font-size:14px;line-height:1.7;color:#374151">' . nl2br($safe) . '</div>';
    }
}
