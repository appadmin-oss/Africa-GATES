<?php
declare(strict_types=1);
namespace AfricaGates\Services;

/**
 * Thin, dependency-free helper that routes submission notifications through
 * the existing OtpService SMTP transport. Every call is best-effort: a null
 * mailer or a send failure is swallowed so a notification problem can never
 * break a user-facing submission.
 */
final class Notifier {
    /** Resolve the operator inbox for admin alerts. */
    public static function adminEmail(): string {
        $e = (string)($_ENV['ADMIN_ALERT_EMAIL'] ?? '');
        if ($e === '') $e = (string)($_ENV['MAIL_FROM_ADDRESS'] ?? '');
        if ($e === '') $e = 'app.admin@afrovanguard.org.ng';
        return $e;
    }

    /** Email the operators about a new submission. Best-effort. */
    public static function adminAlert(?OtpService $mailer, string $subject, string $body): void {
        if ($mailer === null) return;
        try { $mailer->sendCustom(self::adminEmail(), '[Africa GATES] ' . $subject, $body); }
        catch (\Throwable $e) { error_log('[Notifier] admin alert failed: ' . $e->getMessage()); }
    }

    /** Send a confirmation to a user who just submitted something. Best-effort. */
    public static function confirm(?OtpService $mailer, string $to, string $subject, string $body): void {
        if ($mailer === null) return;
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        try { $mailer->sendCustom($to, '[Africa GATES] ' . $subject, $body); }
        catch (\Throwable $e) { error_log('[Notifier] confirm failed: ' . $e->getMessage()); }
    }
}
