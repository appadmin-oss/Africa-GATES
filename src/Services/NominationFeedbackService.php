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
 *   • slaHours()  — THE one reader of `review_sla_hours`. See its docblock: the
 *     value was resolved in three places with three different answers.
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

    /** The settings key, so the three former call sites cannot spell it differently. */
    public const SLA_KEY = 'review_sla_hours';

    /** Two working days, which is what the copy said before the value existed. */
    public const SLA_DEFAULT = 48;

    /**
     * HOW LONG WE TELL SOMEBODY THEIR NOMINATION WILL TAKE.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * ONE VALUE, THREE RESOLVERS, AND THE PUBLIC ONES WERE THE WRONG TWO
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `review_sla_hours` had three readers and no owner:
     *
     *   · `config/container.php` cast it for a Twig global with no floor. That global is
     *     printed on `/nominate-success` ("usually within N hours") and on `/integrity`
     *     ("Acknowledge the complaint within N hours").
     *   · `GuideService` cast it with no floor into the site-state block the assistant is
     *     allowed to quote to the public.
     *   · `Maintenance::sendPendingAcknowledgements()` floored it at one before deciding
     *     when the "still under review" mail goes out.
     *
     * So the only reader that ACTED on the number was the only one that guarded it, and the
     * three that PUBLISH it did not. The settings form offers `min="0"` and the writer
     * clamps with `max(0, …)`, so nought is a value an operator can save — it reads like
     * "turn the promise off". It does not turn anything off. It makes two public pages and
     * the assistant promise a nominator their entry is reviewed "within 0 hours" and a
     * complainant that we acknowledge "within 0 hours", on the page whose entire subject is
     * whether this platform can be believed, while the mailer carries on at one hour.
     *
     * Nothing throws, nothing logs, and every screen looks ordinary — which is why the
     * house rule is one resolver per value and never two. This is it. A caller that already
     * has the row in hand passes it in rather than asking the database again; the
     * NORMALISATION is the same function either way, which is the whole point.
     */
    public static function slaHours(?string $raw = null): int
    {
        if ($raw === null) {
            try {
                $v = DB::table('gates_settings')->where('key_name', self::SLA_KEY)->value('value');
                $raw = is_scalar($v) ? (string) $v : null;
            } catch (\Throwable) {
                // No settings table yet (a deploy before db:migrate). The default stands.
            }
        }

        // Blank counts as unset, not as nought: an operator clearing the field is removing
        // an override, and a cleared override must not become a promise of instant review.
        if ($raw === null || trim($raw) === '') return self::SLA_DEFAULT;

        return max(1, (int) $raw);
    }
}
