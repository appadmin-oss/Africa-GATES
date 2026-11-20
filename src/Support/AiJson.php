<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * THE JSON INSIDE A MODEL'S REPLY — found, never repaired.
 *
 * Owner, 13 Oct 2026, from the nominations desk: "AI triage unavailable (SCHEMA_REJECTED)
 * — reviewed on duplicates and heuristics only." Every capability that asks for JSON
 * handed the gateway's schema check the model's RAW text, and each check began with
 * `json_decode($raw)`. AiService strips a fence only when the reply STARTS with one, so a
 * reply of "Here is the assessment: {…}", a fence after a sentence, or an object followed
 * by a closing remark decoded to null and was discarded as the wrong shape — while the
 * object the capability asked for was sitting in the middle of it.
 *
 * This finds the first complete JSON object or array in the text and returns exactly that
 * substring, or null. It does not fix broken JSON, guess at a truncated one, or coerce
 * values: the schema callables still decide what is acceptable, and "discarded, never
 * coerced" stays the gateway's rule. What changes is only that a valid answer wrapped in
 * words is read rather than thrown away.
 */
final class AiJson
{
    public static function extract(string $raw): ?string
    {
        $t = trim($raw);
        if ($t === '') return null;
        if (self::valid($t)) return $t;

        // A fenced block anywhere in the reply, preferred over a scan: it is what the
        // model marked as the answer.
        if (preg_match('/```(?:json|JSON)?\s*(.*?)```/s', $t, $m) && self::valid(trim($m[1]))) {
            return trim($m[1]);
        }

        // Otherwise the first balanced {…} or […], string-aware so a brace inside a
        // quoted value ("a {draft} summary") does not end the object early.
        $len = strlen($t);
        for ($start = 0; $start < $len; $start++) {
            $open = $t[$start];
            if ($open !== '{' && $open !== '[') continue;
            $close = $open === '{' ? '}' : ']';
            $depth = 0; $inStr = false; $esc = false;
            for ($i = $start; $i < $len; $i++) {
                $c = $t[$i];
                if ($inStr) {
                    if ($esc) { $esc = false; }
                    elseif ($c === '\\') { $esc = true; }
                    elseif ($c === '"') { $inStr = false; }
                    continue;
                }
                if ($c === '"') { $inStr = true; continue; }
                if ($c === $open) $depth++;
                elseif ($c === $close && --$depth === 0) {
                    $candidate = substr($t, $start, $i - $start + 1);
                    if (self::valid($candidate)) return $candidate;
                    break;
                }
            }
        }
        return null;
    }

    private static function valid(string $s): bool
    {
        if ($s === '' || ($s[0] !== '{' && $s[0] !== '[')) return false;
        json_decode($s);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
