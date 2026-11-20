<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Lightweight structured event log.
 * Every major platform action should dispatch an event.
 * Events are cheap writes — analytics and notifications consume them asynchronously.
 *
 * Event names follow <domain>.<action> convention:
 *   vote.submitted  milestone.reached  otp.requested  fraud.flagged
 *
 * ── WHY THAT LIST IS SHORTER THAN IT WAS ─────────────────────────────────────
 *
 * It also carried nomination.received, nominee.approved, registration.completed and
 * share.clicked, and not one of them was ever dispatched — four emitters written against
 * the intent above and never wired to anything. They are gone rather than wired, because
 * their content is not missing: registrations and nominations are counted straight off the
 * domain tables by {@see \AfricaGates\Admin\Services\AnalyticsService::audience()} and
 * `nominationFunnel()`, which is a better count than a parallel log that can be forgotten
 * at a call site.
 *
 * What is left is the four that DO fire and that nothing else records with an actor, an IP
 * and a device hash beside them — which is the only reason this table earns its writes.
 *
 * `funnelReport()` went the same way: it read `gates_funnel_events`, which
 * `AnalyticsService::ballotFunnel()` already reads and renders. Two readers of one table
 * is how the two come to disagree about what a funnel step means.
 */
class EventService
{
    private bool $enabled = false;

    public function __construct()
    {
        // The RETURN VALUE, which this discarded. The comment said "silently disable if
        // the events table doesn't exist yet" and the code set $enabled = true whenever
        // hasTable() failed to throw — so on a database without the table, every dispatch
        // went ahead and fell into its own silent catch. A guard describing behaviour it
        // did not have, in front of writes nobody could see failing.
        try {
            $this->enabled = DB::getSchemaBuilder()->hasTable('gates_events');
        } catch (\Throwable) {
            $this->enabled = false;
        }
    }

    public function dispatch(
        string  $name,
        string  $actorType   = 'system',
        ?string $actorHash   = null,
        ?string $subjectType = null,
        ?int    $subjectId   = null,
        array   $payload     = [],
        ?string $ipHash      = null,
        ?string $deviceHash  = null,
    ): void {
        if (!$this->enabled) return;
        try {
            DB::table('gates_events')->insert([
                'name'         => $name,
                'actor_type'   => $actorType,
                'actor_hash'   => $actorHash,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'payload'      => $payload ? json_encode($payload) : null,
                'ip_hash'      => $ipHash,
                'device_hash'  => $deviceHash,
                'created_at'   => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {}
    }

    public function voteCast(int $nomineeId, int $categoryId, string $emailHash, string $ipHash, ?string $deviceHash): void
    {
        $this->dispatch('vote.submitted', 'voter', $emailHash, 'nominee', $nomineeId,
            ['category_id' => $categoryId], $ipHash, $deviceHash);
    }




    public function milestoneReached(int $nomineeId, int $milestone): void
    {
        $this->dispatch('milestone.reached', 'system', null, 'nominee', $nomineeId,
            ['milestone' => $milestone]);
    }

    public function fraudFlagged(int $nomineeId, int $score, string $decision): void
    {
        $this->dispatch('fraud.flagged', 'system', null, 'nominee', $nomineeId,
            ['score' => $score, 'decision' => $decision]);
    }

    public function otpRequested(string $emailHash, int $nomineeId, string $ipHash): void
    {
        $this->dispatch('otp.requested', 'voter', $emailHash, 'nominee', $nomineeId, [], $ipHash);
    }


    /** Funnel analytics — track conversion steps */
    public function funnelStep(
        string  $sessionId,
        string  $step,
        ?int    $nomineeId  = null,
        ?int    $awardId    = null,
        ?string $deviceHash = null,
        ?string $ipHash     = null,
        array   $meta       = [],
    ): void {
        if (!$this->enabled) return;
        try {
            DB::table('gates_funnel_events')->insert([
                'session_id'  => $sessionId,
                'step'        => $step,
                'nominee_id'  => $nomineeId,
                'award_id'    => $awardId,
                'device_hash' => $deviceHash,
                'ip_hash'     => $ipHash,
                'meta'        => $meta ? json_encode($meta) : null,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {}
    }

}
