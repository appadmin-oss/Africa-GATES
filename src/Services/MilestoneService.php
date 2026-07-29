<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Vote milestone tracker.
 * Checks after every vote whether the nominee has crossed a milestone.
 * When a milestone is crossed:
 *  1. A row is inserted into gates_vote_milestones.
 *  2. An event is dispatched.
 *  3. An email is sent to the admin (to notify the nominee).
 */
class MilestoneService
{
    private const MILESTONES = [100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000];

    public function __construct(
        private readonly ?OtpService      $mailer   = null,
        private readonly ?EventService    $events   = null,
        private readonly ?LoggerInterface $log      = null,
    ) {}

    /**
     * Call after a vote is successfully cast.
     * Checks whether the nominee just crossed a milestone and, if so, records and notifies.
     */
    public function checkAndNotify(int $nomineeId): ?int
    {
        try {
            $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();
            if (!$nominee) return null;

            $count = (int)$nominee->vote_count;
            $highest = null;

            // Record EVERY newly-crossed milestone — a single vote or a bonus
            // redemption can leap several thresholds at once; returning after the
            // first would silently drop the rest.
            foreach (self::MILESTONES as $milestone) {
                if ($count < $milestone) break;

                $alreadyRecorded = DB::table('gates_vote_milestones')
                    ->where('nominee_id', $nomineeId)
                    ->where('milestone', $milestone)
                    ->exists();
                if ($alreadyRecorded) continue;

                try {
                    DB::table('gates_vote_milestones')->insert([
                        'nominee_id'  => $nomineeId,
                        'milestone'   => $milestone,
                        'notified'    => 0,
                        'achieved_at' => Carbon::now()->toDateTimeString(),
                    ]);
                } catch (\Throwable $e) {
                    // A concurrent voter crossed the same milestone first (UNIQUE
                    // violation) — not an error; skip notifying for it here.
                    continue;
                }

                $this->events?->milestoneReached($nomineeId, $milestone);
                $this->notifyAdmin($nominee, $milestone);
                $this->log?->info("[milestone] {$nominee->name} reached {$milestone} votes");
                $highest = $milestone;
            }
            return $highest; // highest milestone newly recorded this call (null if none)
        } catch (\Throwable $e) {
            $this->log?->error('[milestone] check failed: ' . $e->getMessage());
        }
        return null;
    }

    private function notifyAdmin(object $nominee, int $milestone): void
    {
        $adminEmail = Env::get('ADMIN_ALERT_EMAIL') ?? Env::get('MAIL_FROM_ADDRESS');
        if (!$adminEmail || !$this->mailer) return;

        $formatted = number_format($milestone);
        $html = <<<HTML
<p><strong>{$nominee->name}</strong> has just crossed the
<span style="color:#15803d;font-size:1.25em;font-weight:700">{$formatted} votes</span>
milestone in the Africa GATES 2026 cycle. 🎉</p>
<p>Notify the nominee so they can share this achievement with their supporters.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0"
       style="margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:12px 16px">
  <tr><td style="font-size:14px;color:#166534">
    Nominee: <strong>{$nominee->name}</strong><br>
    Milestone: <strong>{$formatted} votes</strong><br>
    Total votes: <strong>{$nominee->vote_count}</strong>
  </td></tr>
</table>
HTML;
        try {
            $this->mailer->sendBranded(
                $adminEmail,
                "🎉 {$nominee->name} reached {$formatted} votes!",
                $html,
                "{$nominee->name} reached {$formatted} votes in the Africa GATES 2026 cycle."
            );
            DB::table('gates_vote_milestones')
                ->where('nominee_id', $nominee->id)
                ->where('milestone', $milestone)
                ->update(['notified' => 1]);
        } catch (\Throwable) {}

        // Also congratulate the nominee themselves — the admin alert above says to
        // "notify the nominee", so do it automatically when we can reach their
        // linked registry profile. Best-effort; never breaks the vote path.
        try {
            $pid   = DB::table('gates_nominees')->where('id', $nominee->id)->value('profile_id');
            $email = $pid ? DB::table('gates_profiles')->where('id', $pid)->value('email') : null;
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $base = rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
                $nm   = htmlspecialchars((string)$nominee->name, ENT_QUOTES, 'UTF-8');
                $nhtml = "<p>Hi <strong>{$nm}</strong>,</p>"
                    . "<p>Your supporters just pushed you past <strong>{$formatted} votes</strong> in the Africa GATES cycle. 🎉</p>"
                    . "<p>Share your campaign to keep the momentum going — every verified vote counts toward your Cultural Power Index.</p>"
                    . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$base}/vote\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">Rally more votes →</a></p>";
                $this->mailer->sendBranded((string)$email, "You reached {$formatted} votes — Africa GATES", $nhtml,
                    "Hi {$nominee->name}, you reached {$formatted} votes in the Africa GATES cycle. Rally more support: {$base}/vote",
                    'Milestone', $base . '/assets/img/illustrations/illo-medallion.jpg');
            }
        } catch (\Throwable) {}
    }

    /**
     * Get all milestones achieved by a nominee (for their campaign page).
     */
    public function getForNominee(int $nomineeId): array
    {
        try {
            return DB::table('gates_vote_milestones')
                ->where('nominee_id', $nomineeId)
                ->orderBy('milestone')
                ->get()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Next milestone the nominee hasn't reached yet */
    public function nextMilestone(int $currentVotes): ?int
    {
        foreach (self::MILESTONES as $m) {
            if ($currentVotes < $m) return $m;
        }
        return null;
    }
}
