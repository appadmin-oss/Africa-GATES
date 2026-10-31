<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * "WHAT DO YOU CARE ABOUT?" — the last step of joining (SignIn.dc.html, view=interests).
 *
 * ── THE SCREEN MAKES A PROMISE, SO SOMETHING HAS TO KEEP IT ──────────────────
 *
 * The step says "We'll show you awards and people in these areas first." A choice stored
 * and read by nothing would make that sentence the §17 fault with a form in front of it —
 * a field written on every join and consulted never. So the reader ships with the writer:
 * {@see rank()} puts the awards that match a member's areas first on the nomination hub
 * (`/nominate`), and the account page shows the choice and lets it be changed.
 *
 * ── MATCHED ON WORDS, AND SAID SO ────────────────────────────────────────────
 *
 * There is no subject taxonomy on an award or a category here — a programme is a title and
 * a set of category titles an operator typed. So an area matches an award when one of its
 * words appears in those titles ("Teachers' Prize" is Education; "Choir of the Year" is
 * Music & arts). It reorders and never hides: an award that matches nothing is still on the
 * page, below the ones that do, so a word missing from a list costs a position and never a
 * nomination. The words are here, in one table, where whoever notices a miss can add one.
 *
 * The ten areas are the DC's list, in its order, under its labels.
 */
final class MemberInterests
{
    /** key => [label, words]. The key is what is stored; the label is what is drawn. */
    public const FIELDS = [
        'education'   => ['Education',      ['education', 'teacher', 'teachers', 'school', 'schools', 'student', 'students', 'stem', 'educator', 'learning', 'university', 'scholar']],
        'health'      => ['Health',         ['health', 'nurse', 'nurses', 'doctor', 'doctors', 'medical', 'medicine', 'care', 'hospital', 'wellbeing']],
        'arts'        => ['Music & arts',   ['music', 'art', 'arts', 'artist', 'choir', 'choral', 'film', 'creative', 'culture', 'dance', 'literature', 'writer', 'poet', 'fashion', 'design']],
        'business'    => ['Business',       ['business', 'entrepreneur', 'entrepreneurs', 'enterprise', 'founder', 'startup', 'trade', 'sme', 'industry']],
        'technology'  => ['Technology',     ['technology', 'tech', 'digital', 'innovation', 'innovator', 'software', 'engineering', 'science']],
        'sport'       => ['Sport',          ['sport', 'sports', 'athlete', 'athletes', 'football', 'athletics', 'coach']],
        'community'   => ['Community',      ['community', 'youth', 'volunteer', 'volunteers', 'grassroots', 'builder', 'social', 'impact']],
        'faith'       => ['Faith',          ['faith', 'church', 'mosque', 'gospel', 'ministry', 'religious', 'worship']],
        'public'      => ['Public service', ['public', 'service', 'civil', 'government', 'leadership', 'policy', 'governance']],
        'environment' => ['Environment',    ['environment', 'environmental', 'climate', 'green', 'conservation', 'sustainability', 'agriculture', 'farmer', 'farming']],
    ];

    /** @return list<array{key:string,label:string}> in the DC's order. */
    public static function options(): array
    {
        $out = [];
        foreach (self::FIELDS as $k => [$label]) $out[] = ['key' => $k, 'label' => $label];
        return $out;
    }

    /**
     * Only known keys survive, once each, in the canonical order — a posted list is a claim,
     * and an unknown key stored here would be one no screen can draw a label for.
     *
     * @param  mixed $raw
     * @return list<string>
     */
    public static function normalise(mixed $raw): array
    {
        $raw = is_array($raw) ? array_map('strval', $raw) : [];
        return array_values(array_filter(array_keys(self::FIELDS), static fn (string $k) => in_array($k, $raw, true)));
    }

    /** @return list<string> */
    public static function of(int $userId): array
    {
        if ($userId < 1) return [];
        try {
            $j = DB::table('gates_users')->where('id', $userId)->value('interests_json');
        } catch (\Throwable) {
            return [];   // pre-migration: nothing chosen, which is what is shown
        }
        $d = is_string($j) ? json_decode($j, true) : null;
        return self::normalise($d);
    }

    /** @param list<string> $keys */
    public static function save(int $userId, array $keys): bool
    {
        if ($userId < 1) return false;
        $keys = self::normalise($keys);
        try {
            DB::table('gates_users')->where('id', $userId)
                ->update(['interests_json' => $keys === [] ? null : json_encode($keys)]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Does this text belong to any of these areas? Whole words, case-folded. */
    public static function matches(string $text, array $keys): bool
    {
        if ($keys === []) return false;
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $words = array_flip(array_filter($words, static fn ($w) => $w !== ''));
        foreach ($keys as $k) {
            foreach (self::FIELDS[$k][1] ?? [] as $w) {
                if (isset($words[$w])) return true;
            }
        }
        return false;
    }

    /**
     * Matching items first, the order otherwise kept — a stable partition, never a filter.
     *
     * @template T
     * @param  list<T>              $items
     * @param  callable(T): string  $text  what an item is about (its title and its categories)
     * @param  list<string>         $keys
     * @return list<T>
     */
    public static function rank(array $items, callable $text, array $keys): array
    {
        if ($keys === []) return $items;
        $hit = $miss = [];
        foreach ($items as $it) {
            if (self::matches($text($it), $keys)) $hit[] = $it; else $miss[] = $it;
        }
        return array_merge($hit, $miss);
    }
}
