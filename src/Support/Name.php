<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Making a typed-in name look like a name.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT `ucwords(strtolower($n))`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Nominations are typed by whoever filled the form in — on a phone, in a hurry,
 * often with caps lock on. The same person appears as `ADA OKONKWO`, `ada
 * okonkwo` and `Ada Okonkwo`, and the ballot renders all three, which makes a
 * serious award page look like a spreadsheet export.
 *
 * The naive fix breaks real names. `ucwords(strtolower(...))` turns O'BRIEN into
 * O'brien, MCDONALD into Mcdonald, and van der Berg into Van Der Berg. Those are
 * not cosmetic errors — a name is the one field on the page that belongs to a
 * person, and getting it wrong in a way they did not ask for is worse than
 * leaving their capitals alone.
 *
 * ── THE RULE THAT MAKES THIS SAFE ────────────────────────────────────────────
 *
 * A word is only re-cased if it is entirely ONE case. `MCDONALD` and `mcdonald`
 * are things a keyboard did; `McDonald`, `deWayne`, `MacIntyre` and `NneKa` are
 * things a person did, and a mixed-case word is therefore left exactly as typed.
 *
 * That single test does most of the work, and it fails in the safe direction: the
 * worst outcome is a name we could have tidied and did not.
 *
 * ── AND WHAT IS DONE ON TOP ──────────────────────────────────────────────────
 *
 * Hyphens and apostrophes are word boundaries (Ama-Serwaa, O'Brien, D'Angelo).
 * Particles — de, van, bin, al — go lowercase unless they lead the name, because
 * "Van Der Berg" is not how the family writes it. Mc is capitalised on the
 * following letter; Mac deliberately is NOT, because Macaulay and Macharia are
 * far commoner in this platform's audience than MacArthur and would be mangled.
 * Roman numerals stay upper (Ade Adeyemi III), and single letters get a full
 * stop's worth of respect (A. B. Okafor).
 *
 * Applied at WRITE time, so the data is right and every one of the forty places
 * that render a name gets it for free — rather than a display filter that the
 * forty-first place forgets to use.
 */
final class Name
{
    /**
     * Words that stay lowercase when they are not the first word of the name.
     *
     * Deliberately short. Every entry is a nobiliary or relational particle that
     * is conventionally lowercase INSIDE a name across the languages this
     * platform actually sees — West African, Arabic, Dutch/Afrikaans, Portuguese
     * and French. Anything more speculative belongs in whoever's name it is.
     */
    private const PARTICLES = [
        'de', 'del', 'della', 'der', 'den', 'da', 'das', 'do', 'dos', 'du',
        'van', 'von', 'vander', 'ter', 'ten',
        'bin', 'binti', 'binte', 'ibn', 'bint', 'al', 'el', 'ould', 'ag',
        'la', 'le', 'les', 'e', 'y', 'of', 'the', 'and',
    ];

    /** Kept fully uppercase: generational suffixes. */
    private const NUMERALS = ['II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII'];

    /**
     * Title-case a personal name, conservatively.
     *
     * Returns the input unchanged when there is nothing safe to do, so it is
     * always safe to call — including on a name that is already correct.
     */
    public static function title(string $raw): string
    {
        // Collapse runs of whitespace, and strip the stray non-breaking spaces
        // that arrive from people pasting out of Word and WhatsApp.
        $name = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $raw));
        if ($name === '') return '';

        $words = explode(' ', $name);
        $out   = [];

        foreach ($words as $i => $word) {
            $out[] = self::word($word, $i === 0 || $i === count($words) - 1);
        }
        return implode(' ', $out);
    }

    /**
     * One space-separated word.
     *
     * @param bool $anchor True for the first and last words, which are never
     *        treated as particles — a person surnamed "De" or "Al" exists, and
     *        lowercasing the whole of a two-word name would be absurd.
     */
    private static function word(string $word, bool $anchor): string
    {
        if ($word === '') return $word;

        // Deliberate styling. Left alone — see the class note.
        if (self::isMixedCase($word)) return $word;

        $upper = mb_strtoupper($word, 'UTF-8');
        if (in_array($upper, self::NUMERALS, true)) return $upper;

        $lower = mb_strtolower($word, 'UTF-8');
        if (!$anchor && in_array(rtrim($lower, '.'), self::PARTICLES, true)) return $lower;

        // Hyphens and apostrophes are boundaries INSIDE a word, so each side is
        // capitalised: Ama-Serwaa, O'Brien, D'Angelo, Nnamdi-Okeke.
        $built = preg_replace_callback(
            "/[^\-'’]+/u",
            static fn (array $m): string => self::capitalise($m[0]),
            $lower
        );

        return is_string($built) ? self::mcPrefix($built) : self::capitalise($lower);
    }

    /** Does this word already contain both cases? Then somebody meant it. */
    private static function isMixedCase(string $word): bool
    {
        $letters = (string) preg_replace('/[^\p{L}]+/u', '', $word);
        if (mb_strlen($letters) < 2) return false;
        return $letters !== mb_strtoupper($letters, 'UTF-8')
            && $letters !== mb_strtolower($letters, 'UTF-8');
    }

    /** First letter up, rest as given. Unicode-safe, unlike ucfirst(). */
    private static function capitalise(string $s): string
    {
        if ($s === '') return $s;
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }

    /**
     * Mcdonald → McDonald.
     *
     * Only `Mc`, and only before a letter. `Mac` is left alone on purpose:
     * Macaulay, Macharia and Machado are ordinary names here and "MacAulay"
     * would be a change nobody asked for, whereas essentially every `Mc` name is
     * McSomething.
     */
    private static function mcPrefix(string $word): string
    {
        if (mb_strlen($word) < 4 || mb_strtolower(mb_substr($word, 0, 2, 'UTF-8'), 'UTF-8') !== 'mc') {
            return $word;
        }
        $rest = mb_substr($word, 2, null, 'UTF-8');
        return preg_match('/^\p{L}/u', $rest) ? 'Mc' . self::capitalise($rest) : $word;
    }
}
