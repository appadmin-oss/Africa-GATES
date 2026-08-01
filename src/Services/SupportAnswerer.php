<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The seam between "something that answers support questions" and the code that
 * decides what to do with an answer.
 *
 * ── WHY THIS INTERFACE EXISTS ────────────────────────────────────────────────
 *
 * {@see SupportAutoResolver} is the riskiest thing in this codebase — it lets a
 * language model reply to a member and mark their ticket resolved — and every
 * rule that makes that safe lives in the resolver, not in the model: only close
 * what a repair actually fixed, never touch an urgent ticket, never answer
 * twice, say nothing rather than something empty.
 *
 * Those rules have to be provable, and you cannot prove them against a real
 * model. They need an answerer that returns exactly what a test tells it to,
 * including the badly-behaved answers that are the whole point — the confident
 * paragraph with no lookup behind it, the one-word reply, the failed tool. This
 * interface is what makes that substitutable without loosening `final` on the
 * production class, which would let anything override the tool loop.
 *
 * It is deliberately the SMALLEST surface the resolver uses. Not the planner,
 * not the writer, not the escalation policy — just "are you up" and "answer
 * this", which is all an unattended caller has any business reaching for.
 */
interface SupportAnswerer
{
    /** False when no model is configured, or the gateway has been switched off. */
    public function available(): bool;

    /**
     * Answer one message.
     *
     * @param list<array{role:string,content:string}> $history
     * @param list<string> $only Restrict to these tools. Empty means "whatever the
     *        context allows" — narrowing here is a guarantee, not a suggestion to
     *        a prompt.
     * @param bool $escalate Whether a failed conversation may open a ticket.
     * @return array{reply:string, escalated:bool, ticket:?string, used:list<string>,
     *               results:list<array>, provider:?string}
     */
    public function ask(string $message, array $history, SupportContext $ctx,
                        array $only = [], bool $escalate = true): array;
}
