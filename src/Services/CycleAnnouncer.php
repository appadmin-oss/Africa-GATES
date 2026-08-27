<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
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
 *
 * The sandbox takes the same door. A rehearsal result is still a result and is
 * still written down; it is simply never published, never emailed, and never
 * celebrated at the supporters who cast the demo votes.
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

        // ── AND THE SANDBOX NEVER ANNOUNCES ──────────────────────────────────
        //
        // Containment holds for everything computed per category, and this is not that:
        // the activity row is a GLOBAL broadcast, read by CommunityService::activityFeed()
        // on nothing but `is_public = 1`. A rehearsal winner would appear in the public
        // feed under a name beginning "DEMO —".
        //
        // It is reachable, not theoretical. The practice cycle exists so real judges can
        // sit a practice ballot; two of them completing a scorecard on the same practice
        // nominee meets the default quorum, and the cycle's own results_date then does the
        // rest with nobody deciding anything.
        //
        // Suppressed through the EXISTING gate rather than a new one: the stale-backlog
        // case already had to record a result without publishing it, and this is the same
        // requirement arriving from a different direction.
        $sandbox = self::isSandbox($n);
        if ($sandbox) $announce = false;

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
                    'cycle'     => (int) ($n->cycle_year ?? 0),
                    'announced' => $announce,
                    // Why this one is not public, for whoever reads the row later and
                    // finds a result that never reached the feed.
                    'sandbox'   => $sandbox,
                ]),
                // The suppression has to cover this row too. is_public = 1 drops the
                // result straight into the site's activity feed
                // (CommunityService::activityFeed), which is a broadcast — so a cycle
                // that ended months ago would have announced itself to every visitor
                // today while carefully not sending an email. Same mistake, different
                // delivery. The row is written either way: recording the result is
                // correctness, publishing it is the announcement.
                'is_public'    => $announce ? 1 : 0,
                'created_at'   => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) { /* best-effort */ }

        if (!$announce) return;
        self::email($n, $kind);

        // ── AND THE PEOPLE WHO PUT THEM THERE ────────────────────────────────
        //
        // The nominee has just been congratulated. Until now the supporters who
        // decided the community half of that result heard nothing at all — a strange
        // silence for a platform about communal recognition, and the one moment when
        // a message is certain to be welcome.
        //
        // Behind the same $announce gate as the nominee's own mail, for the same
        // reason: a backlog of cycles all reaching 'results' at once must correct the
        // standings without writing to anybody about a competition that ended months
        // ago. Best-effort — a failed fan-out may never leave a winner un-promoted.
        try {
            SupporterHonours::celebrate($nomineeId, $kind);
        } catch (\Throwable) { /* best-effort */ }
    }

    private static function nominee(int $nomineeId): ?object
    {
        try {
            // cy.year, because the congratulations mail used to print date('Y') — the
            // year the CRON RAN, not the year of the award. A cycle that closes on the
            // 30th of December and promotes on the 2nd of January is three days late,
            // well inside the announcement grace window, and told its winner they had
            // won an edition that did not exist. It is one line on the single most
            // important email this platform ever sends.
            return DB::table('gates_nominees as n')
                ->leftJoin('gates_profiles as p', 'p.id', '=', 'n.profile_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->where('n.id', $nomineeId)
                ->select(['n.name', 'n.category_id', 'c.title as category',
                          'cy.year as cycle_year', 'cy.edition_label', 'cy.programme_id',
                          'p.email as profile_email', 'p.display_name as profile_name'])
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether this result belongs to the rehearsal programme.
     *
     * Reads the programme through the cycle rather than trusting the "DEMO — " name
     * prefix, which is a display convention an operator can edit and a real nominee
     * could coincidentally match.
     */
    private static function isSandbox(object $n): bool
    {
        $pid = (int) ($n->programme_id ?? 0);
        return $pid > 0 && $pid === DemoSeeder::programmeId();
    }

    /** Branded congratulations mail. No DI available in the console context. */
    private static function email(object $n, string $kind): void
    {
        if (empty($n->profile_email) || !filter_var($n->profile_email, FILTER_VALIDATE_EMAIL)) return;

        try {
            // Was the only sender with no gates_settings lookup at all, so it and
            // CheckoutMailer disagreed about where configuration comes from.
            $mailer = OtpService::boot();
            $base     = rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
            $headline = $kind === 'winner' ? 'Congratulations — you won.' : 'Congratulations — you are a runner-up.';
            $nm       = htmlspecialchars((string) $n->profile_name, ENT_QUOTES, 'UTF-8');
            $catN     = htmlspecialchars((string) $n->category, ENT_QUOTES, 'UTF-8');
            // The cycle's own edition, never the wall clock. Falls back to the current
            // year only when the join found nothing, which means the category has no
            // cycle and the result should not have existed in the first place.
            $edition  = trim((string) ($n->edition_label ?? '')) !== ''
                ? (string) $n->edition_label
                : (string) ((int) ($n->cycle_year ?? 0) ?: date('Y'));
            $year     = htmlspecialchars($edition, ENT_QUOTES, 'UTF-8');

            $html = "<p>Hi <strong>{$nm}</strong>,</p>"
                . "<p style=\"font-size:17px;font-weight:700;color:#10292C\">{$headline}</p>"
                . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:14px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:12px 16px\">"
                . "<tr><td style=\"font-size:14px;color:#166534;line-height:1.7\">Category: <strong>{$catN}</strong><br>Cycle: <strong>{$year}</strong></td></tr></table>"
                . "<p>The full results are now live — and your profile carries a permanent record on the leaderboard.</p>"
                . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$base}/leaderboard\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">See the results &rarr;</a></p>";
            $plain = "Hi {$n->profile_name},\n\n{$headline}\n\nCategory: {$n->category}\nCycle: {$edition}\n\n"
                . "The full results are now on {$base}/leaderboard — and your profile carries a permanent record.\n\n— Africa GATES";

            $mailer->sendBranded((string) $n->profile_email, $headline . ' — Africa GATES', $html, $plain,
                'Results', $base . '/assets/img/illustrations/illo-trophy.jpg');
        } catch (\Throwable) { /* best-effort */ }
    }
}
