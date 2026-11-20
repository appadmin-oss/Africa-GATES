<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * How a search query is read: as WORDS, folded, singular, and forgiven one slip.
 *
 * Owner, 10 Nov 2026 ("Optimize. Improve. Research"), on Discover. What the research on site
 * search agrees on, and what each rule here answers:
 *
 *  · WORDS, NOT THE PHRASE. Every word must appear somewhere in a row, so a name and a city
 *    ("Tolu Lagos") finds the person, where the whole phrase appears nowhere.
 *  · ACCENTS FOLDED. Names here carry them — Ọlá, Adébáyọ̀, Ṣẹ̀gun — and people type them
 *    without. Production's `utf8mb4_unicode_ci` already ignores accents inside SQL; the rules
 *    that run in PHP (matching a cached list, ranking, correcting) did not, so "Ola" found Ọlá
 *    and then ranked it as if it were not a match. fold() is the one comparison.
 *  · SINGULAR. "Teachers" did not find "Teacher of the Year": a word is searched by its stem,
 *    which is a substring of both forms.
 *  · ONE SLIP FORGIVEN. Roughly one search in ten carries a typo. When a search finds nothing,
 *    each unknown word is corrected to the nearest word the site actually holds — a name, a
 *    title — within a distance that grows with the word, and the page says it did so and
 *    offers the original back. Never a correction to a word nobody here uses.
 */
final class SearchWords
{
    /** At most this many words are searched; a pasted paragraph is not sixty LIKE clauses. */
    public const MAX_WORDS = 6;

    /** Lower case, accents and tone marks removed: "Ọlá" and "ola" compare equal. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
        }
        return $s;
    }

    /**
     * The words of a query, folded, de-duplicated, at most MAX_WORDS.
     *
     * @return list<string>
     */
    public static function words(string $q): array
    {
        $w = preg_split('/[\s,;]+/u', self::fold(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_values(array_unique($w)), 0, self::MAX_WORDS);
    }

    /**
     * The form of a word that is searched: its singular stem, which is a substring of both
     * "teacher" and "teachers". Conservative on purpose — a wrong stem finds the wrong things,
     * a missed one only finds fewer — so only plain plurals, and never a short word ("bus",
     * "gas") or one ending in -ss ("class").
     */
    public static function stem(string $w): string
    {
        $n = mb_strlen($w);
        if ($n > 4 && str_ends_with($w, 'ies')) return mb_substr($w, 0, $n - 3);   // categories → categor(y|ies)
        if ($n > 4 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) return mb_substr($w, 0, $n - 1);
        return $w;
    }

    /** The stems of a query's words. @return list<string> */
    public static function stems(string $q): array
    {
        return array_values(array_unique(array_map([self::class, 'stem'], self::words($q))));
    }

    /** Does every word of the query appear (folded) somewhere in these strings? */
    public static function matches(string $q, string ...$hay): bool
    {
        $all = self::fold(implode(' ', $hay));
        foreach (self::stems($q) as $w) if (mb_strpos($all, $w) === false) return false;
        return true;
    }

    /**
     * How well a name answers a query, for ordering: the exact name, then a name that starts
     * with the query, then one in which every word starts a word of the name, then one
     * containing it, then a match on other fields.
     */
    public static function score(string $name, string $q): int
    {
        $n = self::fold(trim($name));
        $f = self::fold(trim($q));
        if ($f === '') return 0;
        if ($n === $f) return 4;
        if (str_starts_with($n, $f)) return 3;
        $starts = true;
        foreach (self::stems($q) as $w) {
            if (!preg_match('/(^|[\s\-\'’.])' . preg_quote($w, '/') . '/u', $n)) { $starts = false; break; }
        }
        if ($starts) return 2;
        return str_contains($n, $f) ? 1 : 0;
    }

    /** How many slips a word of this length may carry and still be corrected. */
    public static function tolerance(int $len): int
    {
        return $len <= 2 ? 0 : ($len <= 4 ? 1 : ($len <= 8 ? 2 : 3));
    }

    /**
     * The query with each word the vocabulary does not hold replaced by its nearest word that
     * it does, or null when nothing needed or could be corrected.
     *
     * @param array<string,int> $vocab folded word => how many rows use it
     */
    public static function correct(string $q, array $vocab): ?string
    {
        $words = self::words($q);
        if ($words === [] || $vocab === []) return null;
        $out = [];
        $changed = false;
        foreach ($words as $w) {
            $len = mb_strlen($w);
            $tol = self::tolerance($len);
            if (isset($vocab[$w]) || $tol === 0 || self::known($w, $vocab)) { $out[] = $w; continue; }
            $best = null; $bestD = PHP_INT_MAX; $bestF = -1;
            foreach ($vocab as $cand => $freq) {
                $cand = (string) $cand;
                if (abs(mb_strlen($cand) - $len) > $tol) continue;
                // levenshtein() counts bytes; folded text is almost always ASCII, and a word
                // that is not is compared byte-wise, which only makes the match stricter.
                $d = levenshtein($w, $cand);
                if ($d > $tol) continue;
                if ($d < $bestD || ($d === $bestD && $freq > $bestF)) { $best = $cand; $bestD = $d; $bestF = $freq; }
            }
            if ($best !== null) { $out[] = $best; $changed = true; } else { $out[] = $w; }
        }
        return $changed ? implode(' ', $out) : null;
    }

    /** A word that begins a vocabulary word is not a typo — it is somebody still typing. */
    private static function known(string $w, array $vocab): bool
    {
        if (mb_strlen($w) < 3) return true;
        $s = self::stem($w);
        foreach ($vocab as $cand => $_) if (str_starts_with((string) $cand, $s)) return true;
        return false;
    }
}
