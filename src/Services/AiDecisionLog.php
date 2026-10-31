<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What the AI suggested, and what the human actually decided.
 *
 * {@see AiGateway} records that a call happened; this records whether it was any
 * use. Without it there is no way to answer the only question that justifies an
 * advisory AI at all — "is this helping the reviewer, or just decorating the
 * page?" — and no accountability trail for a decision a person made with a
 * machine score in front of them.
 *
 * It serves three purposes at once:
 *
 *   1. ACCOUNTABILITY. For any nomination you can show the suggestion, the human
 *      verdict, who made it and when. A reviewer who disagreed is on the record
 *      as having disagreed, which is exactly what "advisory" is supposed to mean.
 *   2. MEASUREMENT. Agreement rate per capability. Below a floor, a capability
 *      should be switched off rather than tuned indefinitely.
 *   3. AN EVAL SET. Every disagreement is a labelled example, accumulated from
 *      real reviews rather than invented.
 *
 * DELIBERATELY NOT HERE: any inference about WHY a reviewer agreed. Whether a
 * high agreement rate means the AI is good or means the reviewer is anchoring on
 * it is a question about automation bias, and answering it needs research this
 * environment cannot currently reach. This class measures agreement and says
 * nothing about its cause — see the note on {@see agreement()}.
 */
final class AiDecisionLog
{
    /**
     * Record a human decision against whatever the AI suggested for the same
     * subject. Best-effort: never break a moderator's action to write a row.
     *
     * $suggested and $decided are compared as strings, so a capability can use
     * whatever vocabulary fits — 'approved'/'rejected', a score band, a category
     * id. `agreed` is null when there was no suggestion to agree with.
     */
    public static function record(
        string $capability,
        string $subjectType,
        int $subjectId,
        ?string $suggested,
        string $decided,
        ?int $actorId = null,
        ?string $note = null,
    ): void {
        $agreed = $suggested === null ? null : ($suggested === $decided ? 1 : 0);
        try {
            DB::table('gates_ai_decisions')->insert([
                'capability'   => mb_substr($capability, 0, 60),
                'subject_type' => mb_substr($subjectType, 0, 40),
                'subject_id'   => $subjectId,
                'suggested'    => $suggested === null ? null : mb_substr($suggested, 0, 120),
                'decided'      => mb_substr($decided, 0, 120),
                'agreed'       => $agreed,
                'actor_id'     => $actorId,
                'note'         => $note === null ? null : mb_substr($note, 0, 300),
                'created_at'   => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) { /* never block a decision to log it */ }
    }

    /**
     * Agreement rate per capability over the last $days.
     *
     * READ THIS WITH CARE. A high agreement rate is ambiguous: it can mean the
     * suggestion is good, or it can mean reviewers are deferring to it. The
     * research on automation bias in human-in-the-loop review is what settles
     * which, and it is not yet available here — so treat a rate near 100% as a
     * prompt to investigate, not as a success metric. A LOW rate is the
     * unambiguous signal: the suggestion is not earning its cost.
     *
     * @return list<array{capability:string, decisions:int, agreed:int, rate:?float}>
     */
    public static function agreement(int $days = 30): array
    {
        $since = Carbon::now()->copy()->subDays(max(1, $days))->toDateTimeString();
        try {
            return DB::table('gates_ai_decisions')
                ->where('created_at', '>=', $since)
                ->whereNotNull('agreed')
                ->groupBy('capability')
                ->selectRaw('capability, COUNT(*) as decisions, COALESCE(SUM(agreed),0) as agreed')
                ->orderByDesc('decisions')
                ->get()
                ->map(function ($r) {
                    $n = (int) $r->decisions;
                    return [
                        'capability' => (string) $r->capability,
                        'decisions'  => $n,
                        'agreed'     => (int) $r->agreed,
                        // Null rather than 0 when there is nothing to divide by,
                        // so an empty capability does not read as "0% agreement".
                        'rate'       => $n > 0 ? round((int) $r->agreed / $n * 100, 1) : null,
                    ];
                })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The decisions recorded for one subject, newest first — the accountability
     * view for a single nomination.
     *
     * @return list<array<string,mixed>>
     */
    public static function forSubject(string $subjectType, int $subjectId): array
    {
        try {
            return DB::table('gates_ai_decisions')
                ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
                ->orderByDesc('id')->limit(20)
                ->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
