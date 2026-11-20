<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Shortening prose without making it look broken.
 *
 * ── WHY THIS IS NOT mb_substr ────────────────────────────────────────────────
 *
 * Cutting at a character count is how "She has a profound impact on the lives of
 * those she teaches, not only through her exceptional teaching methods, but also
 * through her unwa" ended up on ballots. It reads as a fault in the platform rather
 * than as a summary — and a reader cannot tell whether the rest exists.
 *
 * A summary should end where a thought ends. These helpers cut at sentence and word
 * boundaries and mark the cut, so the result reads as deliberate.
 */
final class Text
{
    /**
     * The first sentence, for a one-line summary.
     *
     * Falls back to a word-boundary trim when the text has no sentence break inside
     * the limit — a nominator writing one long unpunctuated paragraph is common, and
     * "no full stop found" must not mean "return the whole essay".
     *
     * Abbreviations are the obvious trap: a naive split on `.` cuts "Dr. Amina" after
     * "Dr". So a full stop only ends a sentence when what follows looks like the start
     * of a new one — whitespace then an upper-case letter or end-of-string — and a
     * short capitalised token before it is treated as an abbreviation.
     */
    public static function firstSentence(string $s, int $max = 200): ?string
    {
        $s = self::tidy($s);
        if ($s === '') return null;
        if (mb_strlen($s) <= $max) return $s;

        $limit = mb_substr($s, 0, $max + 1);

        // Candidate sentence ends inside the window, latest first.
        if (preg_match_all('/[.!?](?=\s|$)/u', $limit, $m, PREG_OFFSET_CAPTURE)) {
            foreach (array_reverse($m[0]) as [$char, $byteOffset]) {
                $end = mb_strlen(substr($limit, 0, $byteOffset)) + 1;
                $head = mb_substr($s, 0, $end);
                if ($end < 20) continue;                    // too short to be a summary
                if (self::endsWithAbbreviation($head)) continue;
                return rtrim($head);
            }
        }

        return self::words($s, $max);
    }

    /**
     * Trim to a whole word and mark the cut.
     *
     * The ellipsis is the point: it is the difference between text that was shortened
     * and text that looks damaged.
     */
    public static function words(string $s, int $max): string
    {
        $s = self::tidy($s);
        if (mb_strlen($s) <= $max) return $s;

        $cut = mb_substr($s, 0, $max);
        $sp  = mb_strrpos($cut, ' ');
        // Only honour the space if it is far enough in; a single very long token would
        // otherwise collapse the whole string to nothing.
        if ($sp !== false && $sp > $max * 0.5) $cut = mb_substr($cut, 0, $sp);

        return rtrim($cut, " \t\n,;:—–-") . '…';
    }

    /** Roughly how long this takes to read, in minutes, never less than one. */
    public static function readMinutes(string $s, int $wpm = 200): int
    {
        $words = preg_split('/\s+/u', trim(strip_tags($s))) ?: [];
        return max(1, (int) ceil(count(array_filter($words)) / max(1, $wpm)));
    }

    /** Collapse whitespace, strip tags, normalise newlines. */
    private static function tidy(string $s): string
    {
        $s = strip_tags($s);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s*\n\s*/u', ' ', $s) ?? $s);
    }

    /**
     * Does this end in something like "Dr." or "St." rather than a real sentence?
     *
     * A short final token that is capitalised or a single letter is an abbreviation far
     * more often than it is a one-word sentence.
     */
    private static function endsWithAbbreviation(string $head): bool
    {
        if (!preg_match('/(\S+)\.$/u', $head, $m)) return false;
        $tok = $m[1];
        if (mb_strlen($tok) > 4) return false;
        return mb_strtoupper(mb_substr($tok, 0, 1)) === mb_substr($tok, 0, 1)
            || mb_strlen($tok) === 1;
    }
}
