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
 *   vote.submitted  nominee.approved  nomination.received  milestone.reached
 *   registration.completed  share.clicked  otp.requested  fraud.flagged
 */
class EventService
{
    private bool $enabled = false;

    public function __construct()
    {
        // Silently disable if the events table doesn't exist yet
        try {
            DB::getSchemaBuilder()->hasTable('gates_events');
            $this->enabled = true;
        } catch (\Throwable) {}
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

    public function nominationReceived(int $nominationId, int $programmeId, string $nominatorHash): void
    {
        $this->dispatch('nomination.received', 'nominator', $nominatorHash, 'nomination', $nominationId,
            ['programme_id' => $programmeId]);
    }

    public function nomineeApproved(int $nomineeId, int $adminId): void
    {
        $this->dispatch('nominee.approved', 'admin', hash('sha256', (string)$adminId), 'nominee', $nomineeId);
    }

    public function registrationCompleted(int $profileId, string $country): void
    {
        $this->dispatch('registration.completed', 'voter', null, 'profile', $profileId,
            ['country' => $country]);
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

    public function shareClicked(string $platform, int $nomineeId, ?string $deviceHash): void
    {
        $this->dispatch('share.clicked', 'voter', null, 'nominee', $nomineeId,
            ['platform' => $platform], null, $deviceHash);
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

    /** Funnel drop-off report for admin analytics */
    public function funnelReport(int $days = 7): array
    {
        if (!$this->enabled) return [];
        try {
            $since = Carbon::now()->subDays($days)->toDateTimeString();
            $steps = [
                'nominee_view', 'vote_button_click', 'otp_requested',
                'otp_delivered', 'otp_verified', 'vote_cast', 'vote_shared',
            ];
            $result = [];
            foreach ($steps as $step) {
                $result[$step] = DB::table('gates_funnel_events')
                    ->where('step', $step)->where('created_at', '>=', $since)
                    ->distinct('session_id')->count('session_id');
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
}
