<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Closes the feedback loop for nominators — every nomination gets a response,
 * nothing goes silent:
 *
 *   • suggestReason()  — an AI-drafted, plain-language note explaining an
 *     approve/reject decision, so moderators can collect a reason OR just use
 *     the AI's. Uses the moderation AiService (dedicated Groq key + best model,
 *     with the free backup fallback); returns null when no provider is set so
 *     the moderator simply types their own.
 *   • pendingNeedingAck() / markAcked()  — power a cron that emails a "still
 *     under review" acknowledgement for nominations sitting past the review
 *     SLA, so a slow queue never reads as being ignored.
 */
class NominationFeedbackService
{
    /**
     * Draft a short reviewer-to-nominator note for a decision. Advisory — the
     * moderator can edit or replace it. Null when AI is unavailable.
     */
    public static function suggestReason(object|array $nom, string $decision, ?AiService $ai = null): ?string
    {
        $nom = (object) $nom;
        $decision = $decision === 'approved' ? 'approved' : 'rejected';
        $system = 'You write brief, warm, respectful notes from the Africa GATES review team to the person who submitted a nomination. '
            . '2–3 sentences, plain language, no greeting or sign-off (the email adds those). '
            . ($decision === 'approved'
                ? 'The nomination was APPROVED and is now live for voting — thank them and say what happens next (community voting, then judging).'
                : 'The nomination was NOT approved this cycle. Be kind and constructive: give a plausible, non-accusatory reason and invite them to resubmit with more specific, verifiable detail. Do NOT allege bad faith.')
            . ' Never invent specific facts about the nominee.';
        // This note is sent to a real person, so the nominator's own text is
        // fenced as untrusted data and the reply must be non-empty prose to be
        // used at all.
        $r = (new AiGateway($ai))->run('nomination.decision_note', [
            'system'       => $system,
            'trusted'      => 'Nominee: ' . $nom->nominee_name . "\n" . 'The reason the nominator gave follows.',
            'user'         => mb_substr((string) ($nom->reason ?? ''), 0, 1500),
            'temperature'  => 0.4,
            'subject_type' => 'nomination',
            'subject_id'   => (int) ($nom->id ?? 0),
            'schema'       => static function (string $raw): ?string {
                $t = trim($raw);
                return $t === '' ? null : mb_substr($t, 0, 600);
            },
        ]);
        return $r->ok ? $r->value : null;
    }

    /**
     * Long-pending nominations that have had NO response yet (no acknowledgement
     * sent, still pending past the SLA). Oldest first.
     *
     * @return list<object>
     */
    public static function pendingNeedingAck(int $slaHours, int $limit = 200): array
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - max(1, $slaHours) * 3600);
            return DB::table('gates_nominations')
                ->where('status', 'pending')
                ->whereNull('nominator_ack_at')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(max(1, $limit))
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function markAcked(int $nominationId): void
    {
        try {
            DB::table('gates_nominations')->where('id', $nominationId)->update(['nominator_ack_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {}
    }
}
