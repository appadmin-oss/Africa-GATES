<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * A person or an organisation with no picture: their initials and their tone.
 *
 * DEFAULT-GRAPHICS §8 (handoff 5 Oct 2026), ported from DefaultAvatar.dc.html so the page
 * and the design agree letter for letter. `partials/avatar.twig` asks this through the
 * `avatar_mark()` Twig function; nothing else works out initials.
 *
 *  · Initials: the first letter of the first two SIGNIFICANT words — "of", "the", "and",
 *    "&", "de", "da", "al", "el" and the rest of the DC's list are skipped, so "Bank of
 *    Ghana" is "BG" and not "BO". Unicode-aware: a Yorùbá, Arabic or Amharic name keeps its
 *    own first letter, never a transliteration. One letter at 32px and under. "?" only if
 *    nothing at all remains.
 *  · Tone: a hash of the profile ID, never the name, so a rename keeps the colour. The
 *    DC's own 31-multiplier hash, in 32-bit signed arithmetic as JavaScript does it, so the
 *    same id lands on the same tone on the page and in the design.
 */
final class AvatarMark
{
    /** Order matters: index = hash % 5, as the DC's `T`. */
    public const TONES = ['green', 'gold', 'live', 'info', 'stone'];

    public const KINDS = ['person', 'organisation', 'business', 'government'];

    private const STOP = ['of', 'the', 'and', '&', 'for', 'de', 'du', 'la', 'le', 'des', 'et',
                          'da', 'do', 'dos', 'das', 'e', 'y', 'al', 'el'];

    /** The sizes the spec uses (§8). Anything else snaps to the nearest. */
    public const SIZES = [24, 32, 44, 56, 96, 160];

    /**
     * @return array{initials:string, one:bool, tone:string, kind:string, size:int, badge:bool, label:string}
     */
    public static function of(string $name, int|string|null $id = null, string $kind = 'person',
                              int $size = 44, bool $verified = false): array
    {
        $name = trim($name);
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'person';
        $size = self::size($size);
        $letters = self::letters($name);
        $one = $size <= 32 || count($letters) < 2;
        $ini = mb_strtoupper(implode('', array_slice($letters, 0, $one ? 1 : 2)));

        return [
            'initials' => $ini !== '' ? $ini : '?',
            'one'      => $one || $ini === '',
            'tone'     => self::tone($id ?? $name),
            'kind'     => $kind,
            'size'     => $size,
            // A person carries a badge only when verified; an organisation always shows its kind.
            'badge'    => $size >= 40 && ($verified || $kind !== 'person'),
            'label'    => $name . ($kind !== 'person' ? ', ' . $kind : '') . ($verified ? ', verified' : ''),
        ];
    }

    /** @return list<string> the first letter of each significant word */
    public static function letters(string $name): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], static fn ($w) =>
            $w !== '' && !in_array(mb_strtolower($w), self::STOP, true) && preg_match('/\p{L}/u', $w)));
        if ($words === [] && trim($name) !== '') $words = [trim($name)];
        $out = [];
        foreach ($words as $w) {
            $clean = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $w);
            // A grapheme, not a code unit: "Ọlá" keeps its dot below.
            $first = $clean !== '' && preg_match('/^\X/u', $clean, $m) ? $m[0] : '';
            if ($first !== '') $out[] = $first;
        }

        return $out;
    }

    /** The DC's `crc`: h = h*31 + c, wrapped to a signed 32-bit int, then made positive. */
    public static function tone(int|string $id): string
    {
        $h = 0;
        foreach (self::utf16Units((string) $id) as $c) {
            $h = ($h * 31 + $c) & 0xFFFFFFFF;
        }
        if ($h & 0x80000000) $h -= 0x100000000;

        return self::TONES[abs($h) % count(self::TONES)];
    }

    private static function size(int $size): int
    {
        $best = self::SIZES[0];
        foreach (self::SIZES as $s) if (abs($s - $size) < abs($best - $size)) $best = $s;

        return $best;
    }

    /** @return list<int> UTF-16 code units, as JavaScript's charCodeAt() sees the string */
    private static function utf16Units(string $s): array
    {
        $u = mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
        $out = [];
        for ($i = 0, $n = strlen($u); $i + 1 < $n; $i += 2) $out[] = (ord($u[$i]) << 8) | ord($u[$i + 1]);

        return $out;
    }
}
