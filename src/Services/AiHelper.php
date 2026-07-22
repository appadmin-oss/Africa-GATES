<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Thin, dependency-light helpers layered over {@see AiService} for the small
 * "AI with a guaranteed fallback" touchpoints (slugs, dedup hints, filter
 * parsing). Every method works with NO AI provider configured — it degrades to
 * a deterministic result — so nothing here can ever break a code path when the
 * platform runs without a key.
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
     * Produce a clean, human-readable slug BASE for a name — AI-assisted when a
     * provider is configured, deterministic otherwise. Uniqueness is the
     * caller's job (append -2, -3… against the target table).
     *
     * AI is asked to transliterate non-Latin scripts and drop honorifics/emoji
     * ("Dr. Chinwé Okónkwò 🔥" → "chinwe-okonkwo", "陈伟" → "chen-wei"), but its
     * output is ALWAYS re-run through slugify() so a misbehaving model can never
     * emit anything unsafe. Fallback order: AI → deterministic slugify → $default
     * (covers fully non-ASCII names that iconv can't transliterate).
     */
    public static function slugBase(string $name, string $default = 'item', ?AiService $ai = null): string
    {
        $deterministic = self::slugify($name);
        $ai ??= AiService::boot();
        if ($ai->configured()) {
            $system = 'You convert a person or organisation name into a short, clean, human-readable URL slug. '
                . 'Transliterate accents and non-Latin scripts to plain ASCII (e.g. "Chinwé Okónkwò" -> "chinwe-okonkwo", "陈伟" -> "chen-wei"). '
                . 'Drop honorifics (Dr, Prof, Chief, Hon), emoji and punctuation. '
                . 'Lowercase; words joined by single hyphens; ASCII a-z and 0-9 only; max 60 characters. '
                . 'Reply with ONLY the slug — no quotes, no explanation.';
            $raw = $ai->complete($system, $name, 30, false, 0.0);
            if (is_string($raw)) {
                $candidate = self::slugify($raw);
                if ($candidate !== '') return substr($candidate, 0, 60);
            }
        }
        if ($deterministic !== '') return substr($deterministic, 0, 60);
        return $default;
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
