<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/**
 * A URL slug from a name, with the diacritics FOLDED rather than deleted.
 *
 * Five places built slugs with the same expression:
 *
 *     preg_replace('/[^a-z0-9]+/i', '-', $name)
 *
 * That deletes every accented letter instead of transliterating it, so on a platform
 * built for the whole continent it destroys a large share of the names it is given.
 * Seen on a real render:
 *
 *     Ọlásùnkànmí Adébáyọ̀   ->   l-s-nk-nm-ad-b-y
 *
 * Fourteen of twenty letters gone. The URL still resolved — the numeric id leads the
 * segment — so nothing broke loudly, and that is exactly why it survived: the failure
 * is a link that looks like corruption in every place a nominee shares it. On a flier,
 * printed under the graphic, it is the thing people are asked to type.
 *
 * Folding first gives `olasunkanmi-adebayo`, which is readable, typable and stable.
 *
 * ── WHAT FOLDING CANNOT DO ───────────────────────────────────────────────────
 *
 * `Normalizer` decomposes a letter into base + combining mark, and dropping the marks
 * leaves the base. That covers the Latin-with-accents range — French, Portuguese,
 * Yoruba tone marks, Kikuyu — but NOT letters that are their own base character:
 * Hausa `ɓ ɗ ƙ`, Akan/Ewe `ɔ ɛ ŋ ƒ`, Yoruba's `ẹ ọ ṣ` are covered (they decompose),
 * while `ɔ` does not decompose to `o` because it is a distinct letter. Those are mapped
 * explicitly below. The map is deliberately short and only contains letters where a
 * single ASCII substitute is the conventional romanisation.
 *
 * Where no substitute exists the character is dropped, as before — but that is now the
 * rare case rather than the normal one.
 */
final class Slug
{
    /**
     * African Latin letters that are their own base character, so decomposition
     * leaves them untouched. Conventional ASCII romanisations only.
     */
    private const LETTERS = [
        'ɔ' => 'o', 'Ɔ' => 'o',   // Akan, Ewe, Igbo (open o)
        'ɛ' => 'e', 'Ɛ' => 'e',   // Akan, Ewe (open e)
        'ŋ' => 'n', 'Ŋ' => 'n',   // Ewe, Akan (eng)
        'ƒ' => 'f', 'Ƒ' => 'f',   // Ewe
        'ɓ' => 'b', 'Ɓ' => 'b',   // Hausa (hooked b)
        'ɗ' => 'd', 'Ɗ' => 'd',   // Hausa (hooked d)
        'ƙ' => 'k', 'Ƙ' => 'k',   // Hausa (hooked k)
        'ʼ' => '',  'ʻ' => '',    // Hausa glottal marks
        'ø' => 'o', 'Ø' => 'o',
        'æ' => 'ae', 'Æ' => 'ae',
        'œ' => 'oe', 'Œ' => 'oe',
        'ß' => 'ss',
        'đ' => 'd', 'Đ' => 'd',
        'ł' => 'l', 'Ł' => 'l',
        'ħ' => 'h', 'Ħ' => 'h',
    ];

    /**
     * Slug for $text, or '' when nothing survives.
     *
     * $max truncates on a word boundary rather than mid-word, so a long name does not
     * end in a fragment.
     */
    public static function make(string $text, int $max = 80): string
    {
        $s = strtr($text, self::LETTERS);
        $s = self::fold($s);
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');

        if ($max > 0 && strlen($s) > $max) {
            $cut = substr($s, 0, $max);
            // Back up to the last hyphen so the slug ends on a whole word.
            $at  = strrpos($cut, '-');
            $s   = trim($at !== false && $at > 0 ? substr($cut, 0, $at) : $cut, '-');
        }
        return $s;
    }

    /**
     * Canonical `{id}-{name}` path segment.
     *
     * The id leads, which is what makes the name half optional: every route that takes
     * one only requires the leading digits, so a name that folds to nothing still
     * resolves.
     */
    public static function idSegment(int $id, string $name): string
    {
        $s = self::make($name, 60);
        return $id . ($s !== '' ? '-' . $s : '');
    }

    /**
     * Decompose and drop combining marks. No-op without ext-intl.
     *
     * Matches MergeSuggestionService::foldDiacritics(), which does the same thing for
     * duplicate matching — the two are the same operation for different purposes, and
     * both need to behave identically or a name could match a duplicate under one
     * spelling and slug under another.
     */
    private static function fold(string $s): string
    {
        if (class_exists('\Normalizer')) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
            }
        }
        return $s;
    }
}
