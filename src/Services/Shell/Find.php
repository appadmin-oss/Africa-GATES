<?php
declare(strict_types=1);
namespace AfricaGates\Services\Shell;

/**
 * FIND, ONCE, FOR EVERY HOME ON THE v7 SHELL (handoff §2.4, ⌘K).
 *
 * Each home (the lead console, the volunteer workspace) builds its own list of what a person
 * may go to — that list is the access rule, so it is built where access is known. This holds
 * the two things the homes must do identically: match a query, and draw the results page that
 * answers when there is no script or Enter is pressed on nothing. The script in shell7.js
 * matches the same way (every word, anywhere in the title, place or kind), so the page and the
 * pop-up cannot disagree about what "found" means.
 *
 * @phpstan-type Item array{t:string, sub:string, href:string, kind:string}
 */
final class Find
{
    /** @param list<array{t:string, sub:string, href:string, kind:string}> $items @return list<array{t:string, sub:string, href:string, kind:string}> */
    public static function match(array $items, string $q): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($q))) ?: []));
        if ($words === []) return [];
        return array_values(array_filter($items, static function ($i) use ($words) {
            $hay = mb_strtolower($i['t'] . ' ' . $i['sub'] . ' ' . $i['kind']);
            foreach ($words as $w) if (!str_contains($hay, $w)) return false;
            return true;
        }));
    }

    /** The results page's content, in the shell's section kinds. */
    public static function page(array $items, string $q): array
    {
        $q = trim(mb_substr($q, 0, 100));
        $hits = self::match($items, $q);
        $rows = array_map(static fn ($i) => ['t' => $i['t'], 'sub' => $i['sub'], 'meta' => $i['kind'], 'href' => $i['href']], array_slice($hits, 0, 50));
        return ['title' => 'Find', 'purpose' => $hits ? count($hits) . (count($hits) === 1 ? ' match' : ' matches') . ' for “' . $q . '”.' : '',
                'secs' => [$rows ? ['k' => 'rows', 'items' => $rows]
                                 : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing matches “' . $q . '”. Try a page’s name, a place, or a word from a task or a meeting.']]];
    }
}
