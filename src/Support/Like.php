<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * THE ONE PLACE THIS PLATFORM BUILDS A `LIKE` CLAUSE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A CLASS AND NOT FOUR PRIVATE METHODS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It was two, with a third about to be written, and they agreed only by luck.
 * {@see \AfricaGates\Admin\Services\AuditService} and
 * {@see \AfricaGates\Services\ActivityFeedService} each carried their own copy of the
 * escape dance, each with its own long comment explaining the same driver trap, and
 * neither knew about the other.
 *
 * That is the shape this codebase has paid for repeatedly: not a wrong implementation,
 * but a SECOND one — because the next person fixes the copy in front of them, the two
 * drift, and the drift is silent. The audit log's filter and the site search would then
 * disagree about whether `stand_call` is a search for a literal underscore, and nothing
 * anywhere would say so.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TRAP, WHICH RUNS THE OPPOSITE WAY TO EVERY OTHER ONE HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Production is MySQL and dev and the suite are SQLite, and almost every divergence in
 * this repository is "SQLite forgives what MySQL enforces" — so the bug ships. This one
 * is the reverse, which is worse.
 *
 * `LIKE` wildcards have to be escaped, and the drivers disagree about how. MySQL's
 * default escape character is a backslash; **SQLite has none at all**. So
 * `LIKE 'stand\_call.%'` matches on production and returns ZERO rows in dev and in the
 * suite. The failure looks like the feature simply not working, on the machine where
 * somebody is trying to fix it — and the natural "fix" is to loosen a filter that was
 * never broken where it actually runs.
 *
 * Spelling the clause out fixes it, but not with a backslash:
 *
 *   - `ESCAPE '\\'` is ONE character to MySQL and TWO to SQLite, which does not process
 *     escapes inside string literals.
 *   - `ESCAPE '\'`  is an unterminated string literal to MySQL.
 *
 * `!` is not a wildcard in either dialect, needs no escaping in either, and is not
 * special to PHP string literals or to the query builder. Hence {@see CHAR}.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ESCAPE THE ESCAPE FIRST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see esc()} replaces `!` before `%` and `_`, and the order is load-bearing: doing it
 * last would re-escape the `!` characters the earlier replacements had just introduced,
 * so a search for `100%` would come out as `100!!%` — a literal `!` followed by a
 * wildcard, which is the opposite of what was asked for.
 */
final class Like
{
    /**
     * The escape character, in both dialects.
     *
     * Public because it appears in the SQL {@see clause()} emits, so a test that wants to
     * assert the emitted string should read it here rather than spell it again.
     */
    public const CHAR = '!';

    /**
     * `<column> LIKE ? ESCAPE '!'`, for `whereRaw`/`orWhereRaw`.
     *
     * The column is interpolated and the term is BOUND. Every call site in this codebase
     * passes a literal column name; this class cannot check that, so the rule is stated
     * where it is broken rather than enforced here — never pass a column name that came
     * from a request.
     */
    public static function clause(string $column): string
    {
        return $column . " LIKE ? ESCAPE '" . self::CHAR . "'";
    }

    /**
     * A user's words, with the wildcards defused, so a search for `100%` is a search for
     * `100%` and not a search for everything.
     */
    public static function esc(string $term): string
    {
        return str_replace(
            [self::CHAR, '%', '_'],
            [self::CHAR . self::CHAR, self::CHAR . '%', self::CHAR . '_'],
            $term,
        );
    }

    /** {@see esc()}, wrapped in the wildcards that make it a contains-match. */
    public static function contains(string $term): string
    {
        return '%' . self::esc($term) . '%';
    }
}
