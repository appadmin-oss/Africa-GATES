<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * One reply from a model, including what it asked the platform to DO.
 *
 * ── WHY A REPLY IS NO LONGER A STRING ────────────────────────────────────────
 *
 * {@see \AfricaGates\Services\AiService::complete()} returns `?string`, and that was right for
 * every caller it has: moderate a comment, polish a paragraph, draft a summary. One question,
 * one answer, nothing to remember afterwards.
 *
 * A conversation is not that. The live interview needs three things out of a single turn that a
 * string cannot carry:
 *
 *   • The prose to show the nominee.
 *   • The TOOL CALLS the model made — `record_outcome`, `set_focus`, `save_note`,
 *     `propose_complete`. These are the entire mechanism by which a free-flowing conversation
 *     converges on what the judges need, and they arrive alongside the prose rather than
 *     inside it. Parsing them back out of text would mean a model that writes the words
 *     "record_outcome" in a sentence could move the ledger.
 *   • The TOKENS the turn cost, because the ceiling is enforced per submission and a running
 *     total needs each turn's contribution, not the last one's.
 *
 * ── AND WHY IT CARRIES ITS OWN PROVENANCE ────────────────────────────────────
 *
 * `provider` and `model` are what ANSWERED, not what was configured — the fallback chain means
 * those are different questions, and an interview transcript that named the preferred provider
 * rather than the one that spoke would be wrong precisely on the turns where something went
 * wrong. Same reasoning as `AiService::lastProvider()`, kept on the reply so a caller holding
 * two replies cannot attribute both to whichever was fetched second.
 */
final class AiReply
{
    /**
     * @param string $text                     the prose for the person, possibly empty on a
     *                                         pure tool turn
     * @param list<array{id:string,name:string,arguments:array<string,mixed>}> $toolCalls
     * @param array{in:int,out:int} $usage     tokens this turn cost
     * @param string $stopReason               'stop' | 'tools' | 'length' | 'other'
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls = [],
        public readonly array $usage = ['in' => 0, 'out' => 0],
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly string $stopReason = 'stop',
    ) {}

    /** Did the model ask for anything to be done? */
    public function hasTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * The calls to one named tool, in the order the model made them.
     *
     * A turn may legitimately record three outcomes at once — one good paragraph often settles
     * several — so this returns a list rather than the first match. A caller that took only the
     * first would silently drop evidence the nominee had already given.
     *
     * @return list<array{id:string,name:string,arguments:array<string,mixed>}>
     */
    public function callsTo(string $name): array
    {
        return array_values(array_filter(
            $this->toolCalls,
            static fn(array $c): bool => ($c['name'] ?? '') === $name
        ));
    }

    /** Tokens in + out, for a running total against a ceiling. */
    public function tokens(): int
    {
        return (int) ($this->usage['in'] ?? 0) + (int) ($this->usage['out'] ?? 0);
    }
}
