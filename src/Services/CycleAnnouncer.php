<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Publishes a winner/runner-up result: the public activity row, and the
 * best-effort congratulations email to the linked registry profile.
 *
 * Split out of the advancer so the RECORD and the NOTIFY halves can be decided
 * separately. When a scheduler has been dead for weeks and a backlog of cycles
 * all reach 'results' at once, the standings must still be corrected — but
 * nobody should receive congratulations about a competition that ended months
 * ago. {@see CycleMaterialiser::ANNOUNCE_GRACE_DAYS} decides; $announce=false
 * records the result and skips every outbound message.
 */
final class CycleAnnouncer
{
    /**
     * Record the result publicly and, when $announce, email the linked profile.
     * Every step is best-effort: a failed insert or send must never leave a
     * nominee un-promoted.
     */
    public static function record(int $nomineeId, string $kind, bool $announce = true): void
    {
        $n = self::nominee($nomineeId);
        if (!$n) return;

        try {
            DB::table('gates_activity')->insert([
                'kind'         => 'winner',
                'actor_label'  => 'Africa GATES',
                'target_type'  => 'nominee',
                'target_id'    => $nomineeId,
                'target_label' => (string) $n->name,
                'meta'         => json_encode([
                    'kind'      => $kind,
                    'category'  => $n->category,
                    'announced' => $announce,
                ]),
                'is_public'    => 1,
                'created_at'   => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) { /* best-effort */ }

        if (!$announce) return;
        self::email($n, $kind);
    }

    private static function nominee(int $nomineeId): ?object
    {
        try {
            return DB::table('gates_nominees as n')
                ->leftJoin('gates_profiles as p', 'p.id', '=', 'n.profile_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)
                ->select(['n.name', 'n.category_id', 'c.title as category',
                          'p.email as profile_email', 'p.display_name as profile_name'])
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Branded congratulations mail. No DI available in the console context. */
    private static function email(object $n, string $kind): void
    {
        if (empty($n->profile_email) || !filter_var($n->profile_email, FILTER_VALIDATE_EMAIL)) return;

        try {
            $mailer = new OtpService([
                'host'         => $_ENV['SMTP_HOST'] ?? 'smtp-relay.brevo.com',
                'port'         => (int) ($_ENV['SMTP_PORT'] ?? 587),
                'username'     => $_ENV['SMTP_USER'] ?? '',
                'password'     => $_ENV['SMTP_PASS'] ?? '',
                'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@afrovanguard.org.ng',
                'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'Africa GATES',
            ]);
            $base     = rtrim((string) ($_ENV['APP_URL'] ?? 'https://afg.afrovanguard.org.ng'), '/');
            $headline = $kind === 'winner' ? 'Congratulations — you won.' : 'Congratulations — you are a runner-up.';
            $nm       = htmlspecialchars((string) $n->profile_name, ENT_QUOTES, 'UTF-8');
            $catN     = htmlspecialchars((string) $n->category, ENT_QUOTES, 'UTF-8');
            $year     = date('Y');

            $html = "<p>Hi <strong>{$nm}</strong>,</p>"
                . "<p style=\"font-size:17px;font-weight:700;color:#10292C\">{$headline}</p>"
                . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:14px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:12px 16px\">"
                . "<tr><td style=\"font-size:14px;color:#166534;line-height:1.7\">Category: <strong>{$catN}</strong><br>Cycle: <strong>{$year}</strong></td></tr></table>"
                . "<p>The full results are now live — and your profile carries a permanent record on the leaderboard.</p>"
                . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$base}/leaderboard\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">See the results &rarr;</a></p>";
            $plain = "Hi {$n->profile_name},\n\n{$headline}\n\nCategory: {$n->category}\nCycle: {$year}\n\n"
                . "The full results are now on {$base}/leaderboard — and your profile carries a permanent record.\n\n— Africa GATES";

            $mailer->sendBranded((string) $n->profile_email, $headline . ' — Africa GATES', $html, $plain,
                'Results', $base . '/assets/img/illustrations/illo-trophy.jpg');
        } catch (\Throwable) { /* best-effort */ }
    }
}
