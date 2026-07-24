<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Suggest the best-fit award category for a nomination from its story.
 *
 * Nominators routinely pick the wrong category, which then needs an admin to
 * move it. This reads the story against the SPECIFIC categories the wizard is
 * offering (passed in from the client — real ids/titles for the chosen
 * programme) and proposes one. The model may only ever return an id from that
 * list; {@see pick()} validates it, so a stray/invented id can never be applied.
 *
 * Advisory + model-agnostic: it pre-selects the dropdown, the nominator can
 * always override, and it returns null (caller shows nothing) when AI is
 * unconfigured or there's too little to go on — a pure accelerant.
 */
final class CategorySuggestService
{
    /**
     * @param array<int,array{id:mixed,title:mixed}> $categories the wizard's current options
     * @return array{id:int,title:string,why:string}|null
     */
    public static function suggest(string $story, array $categories, ?AiService $ai = null): ?array
    {
        $story = trim($story);
        if (mb_strlen($story) < 40) return null;   // too little signal to judge

        $allowed = [];
        foreach ($categories as $c) {
            $id = (int) ($c['id'] ?? 0);
            $t  = trim((string) ($c['title'] ?? ''));
            if ($id > 0 && $t !== '') $allowed[$id] = mb_substr($t, 0, 80);
        }
        if (count($allowed) < 2) return null;      // 0–1 options → nothing to suggest

        $ai ??= AiService::boot();
        if (!$ai->configured()) return null;

        $lines = [];
        foreach ($allowed as $id => $t) { $lines[] = $id . ': ' . $t; }
        $system = 'You match an award-nomination story to the single best-fit category. '
            . 'Reply ONLY with JSON {"category_id": <one id from the list>, "why": "<max 12 words>"}. '
            . 'Choose exactly one id from the provided list — never invent an id or add categories.';
        $user = "Categories (id: name):\n" . implode("\n", $lines) . "\n\nStory:\n" . mb_substr($story, 0, 1500);

        $raw = $ai->complete($system, $user, 120, true, 0.0);
        if (!is_string($raw)) return null;
        $j = json_decode($raw, true);
        $id = self::pick(is_array($j) ? $j : [], array_keys($allowed));
        if ($id === null) return null;

        return [
            'id'    => $id,
            'title' => $allowed[$id],
            'why'   => mb_substr(trim((string) ($j['why'] ?? '')), 0, 80),
        ];
    }

    /**
     * Validate the model's chosen id against the allow-list. Pure + testable.
     * @param int[] $allowedIds
     */
    public static function pick(array $j, array $allowedIds): ?int
    {
        $id = (int) ($j['category_id'] ?? 0);
        return in_array($id, array_map('intval', $allowedIds), true) && $id > 0 ? $id : null;
    }
}
