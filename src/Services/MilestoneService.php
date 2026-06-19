<?php
declare(strict_types=1);
namespace AfricaGates\Services;

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

            foreach (self::MILESTONES as $milestone) {
                if ($count < $milestone) break;

                $alreadyRecorded = DB::table('gates_vote_milestones')
                    ->where('nominee_id', $nomineeId)
                    ->where('milestone', $milestone)
                    ->exists();

                if (!$alreadyRecorded) {
                    DB::table('gates_vote_milestones')->insert([
                        'nominee_id'  => $nomineeId,
                        'milestone'   => $milestone,
                        'notified'    => 0,
                        'achieved_at' => Carbon::now()->toDateTimeString(),
                    ]);

                    $this->events?->milestoneReached($nomineeId, $milestone);
                    $this->notifyAdmin($nominee, $milestone);
                    $this->log?->info("[milestone] {$nominee->name} reached {$milestone} votes");
                    return $milestone;
                }
            }
        } catch (\Throwable $e) {
            $this->log?->error('[milestone] check failed: ' . $e->getMessage());
        }
        return null;
    }

    private function notifyAdmin(object $nominee, int $milestone): void
    {
        $adminEmail = $_ENV['ADMIN_ALERT_EMAIL'] ?? ($_ENV['MAIL_FROM_ADDRESS'] ?? null);
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
