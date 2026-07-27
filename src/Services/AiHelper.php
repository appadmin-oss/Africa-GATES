<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Deterministic slug helpers.
 *
 * These used to be "AI with a guaranteed fallback". They are now simply
 * deterministic: an LLM round-trip to turn a name into a URL slug cost money and
 * latency, could not be budgeted or logged, produced different answers for the
 * same input, and had its output re-sanitised afterwards anyway — so the model
 * could only choose WHICH valid slug you got, never whether it was safe.
 */
final class AiHelper
{
    /**
     * Deterministic URL-slug base — ASCII, lowercased, hyphen-collapsed. No AI.
     * Transliterates common accents (é→e) via iconv when available so Latin
     * names with diacritics don't lose characters.
     */
    public static function slugify(string $s): string
    {
        $s = trim($s);
        if ($s !== '' && function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = (string) preg_replace('/[^a-zA-Z0-9]+/', '-', $s);
        return trim(strtolower($s), '-');
    }

    /**
     * A clean, human-readable slug base for a name.
     *
     * THIS NO LONGER CALLS A MODEL. It used to send the name to an LLM and ask
     * for a slug back — an unbounded, unbudgeted, unlogged network round-trip
     * with nondeterministic output, in the naming path, to do work a lookup table
     * does deterministically in microseconds. The AI output was then re-run
     * through slugify() anyway, so the model could only ever change WHICH valid
     * slug you got, never whether it was safe.
     *
     * $ai is accepted and ignored, so existing call sites keep working.
     */
    public static function slugBase(string $name, string $default = 'item', ?AiService $ai = null): string
    {
        $s = self::transliterate($name);
        // Drop honorifics the old prompt asked the model to remove.
        $s = (string) preg_replace('/\b(dr|prof|professor|chief|hon|honourable|mr|mrs|ms|sir|dame|rev|engr|barr|alhaji|hajia)\b\.?/i', ' ', $s);
        $s = self::slugify($s);
        if ($s !== '') return substr($s, 0, 60);

        $fallback = self::slugify($name);
        return $fallback !== '' ? substr($fallback, 0, 60) : $default;
    }

    /**
     * Extend iconv's transliteration for scripts it cannot handle, so non-Latin
     * names still produce a readable slug rather than falling back to 'item'.
     * This is the capability the model was being paid for.
     */
    private static function transliterate(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                // Cyrillic
                'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
                'и'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
                'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh',
                'щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
                // Greek
                'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i',
                'κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s',
                'ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o',
                // Common African-language Latin extensions
                'ẹ'=>'e','ọ'=>'o','ṣ'=>'s','ǹ'=>'n','ń'=>'n','ɛ'=>'e','ɔ'=>'o','ŋ'=>'ng',
                'ǀ'=>'','ǃ'=>'','ʼ'=>'','’'=>'','ʻ'=>'',
                // Arabic (rough, readable)
                'ا'=>'a','ب'=>'b','ت'=>'t','ث'=>'th','ج'=>'j','ح'=>'h','خ'=>'kh','د'=>'d','ذ'=>'dh',
                'ر'=>'r','ز'=>'z','س'=>'s','ش'=>'sh','ص'=>'s','ض'=>'d','ط'=>'t','ظ'=>'z','ع'=>'a',
                'غ'=>'gh','ف'=>'f','ق'=>'q','ك'=>'k','ل'=>'l','م'=>'m','ن'=>'n','ه'=>'h','و'=>'w','ي'=>'y',
                // Misc
                'ß'=>'ss','æ'=>'ae','œ'=>'oe','ø'=>'o','å'=>'a','ð'=>'d','þ'=>'th','ł'=>'l','đ'=>'d',
            ];
        }
        $lower = mb_strtolower(trim($s));
        return strtr($lower, $map);
    }

    /**
     * Resolve a unique slug against a table: take the AI/deterministic base,
     * then append -2, -3… until it is free. $exists is a callable(string):bool
     * so this stays storage-agnostic (pass a closure that hits your table).
     */
    public static function uniqueSlug(string $name, callable $exists, string $default = 'item', ?AiService $ai = null): string
    {
        $base = self::slugBase($name, $default, $ai);
        $slug = $base;
        $i = 2;
        while ($exists($slug)) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
