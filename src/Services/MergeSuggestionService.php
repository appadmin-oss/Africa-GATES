<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Finds groups of nominees that are probably the SAME person, so an admin can
 * merge them (see {@see MergeService}) instead of hunting duplicates by eye.
 *
 * Two layers, mirroring NominationTriageService's philosophy — advisory only,
 * a human always confirms the merge:
 *   • deterministic (always on) — within a category, cluster nominees whose
 *     normalised names match exactly or are within edit-distance 2
 *     ("Dr Jane Doe" / "jane doe" / "Jane Do").
 *   • AI (optional) — when a provider is configured, ask it to catch the cases
 *     rules miss (nicknames, married/maiden names, transliteration) and attach
 *     a confidence + one-line rationale. Silently skipped with no key.
 *
 * Scoped to ONE category because that's what MergeService can merge (and where
 * vote-splitting actually happens).
 */
final class MergeSuggestionService
{
    private const SCAN_CAP = 400;

    /**
     * Normalise a name for duplicate matching: lowercase, drop a leading
     * honorific (Dr, Prof, Chief…), then strip everything but letters/digits —
     * so "Dr. Jane Doe", "jane doe" and "Jane  Doe" all collapse to "janedoe".
     * Stronger than the triage normalizer (which only stripped whitespace),
     * because merge candidates are frequently honorific/punctuation variants.
     */
    private static function norm(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = self::foldDiacritics($s);   // "José" → "jose" so it isn't gutted to "jos"
        $s = (string) preg_replace('/^(dr|prof|professor|chief|hon|honou?rable|mr|mrs|ms|miss|engr|sir|rev|pastor|alhaji|hajia)\.?\s+/u', '', $s);
        return (string) preg_replace('/[^a-z0-9]+/u', '', $s);
    }

    /** Fold accents to their base letter (é→e, ç→c) so diacritic variants match. No-op without ext-intl. */
    private static function foldDiacritics(string $s): string
    {
        if (class_exists('\Normalizer')) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
        }
        return $s;
    }

    /** Character-similarity percentage (0–100) between two normalised names. */
    private static function similarPct(string $a, string $b): float
    {
        similar_text($a, $b, $pct);
        return (float) $pct;
    }

    /**
     * @return array{groups: list<array{nominee_ids:int[],names:string[],confidence:float,reason:string,source:string}>, scanned:int, capped:bool, ai:bool}
     */
    public static function forCategory(int $categoryId, ?AiService $ai = null): array
    {
        $nominees = [];
        try {
            $q = DB::table('gates_nominees')
                ->where('category_id', $categoryId)
                ->whereIn('status', ['pending', 'approved', 'winner', 'runner_up']);
            MergeService::notMerged($q);                 // never suggest merging a tombstone
            $nominees = $q->orderBy('id')->limit(self::SCAN_CAP + 1)->get(['id', 'name'])->all();
        } catch (\Throwable) {}

        $capped = count($nominees) > self::SCAN_CAP;
        if ($capped) $nominees = array_slice($nominees, 0, self::SCAN_CAP);

        $names = [];
        foreach ($nominees as $n) { $names[(int) $n->id] = (string) $n->name; }

        $groups = self::ruleGroups($nominees);

        $usedAi = false;
        $aiSvc = $ai ?? AiService::boot();
        if (count($names) >= 2 && count($names) <= 120 && $aiSvc->configured()) {
            $aiGroups = self::aiGroups($names, $aiSvc);
            if ($aiGroups !== null) {
                $usedAi = true;
                $groups = self::dedupeGroups(array_merge($groups, $aiGroups));
            }
        }

        // Attach names to each group for display.
        $out = [];
        foreach ($groups as $g) {
            $ids = array_values(array_filter($g['nominee_ids'], fn($i) => isset($names[$i])));
            if (count($ids) < 2) continue;
            $out[] = [
                'nominee_ids' => $ids,
                'names'       => array_map(fn($i) => $names[$i], $ids),
                'confidence'  => $g['confidence'],
                'reason'      => $g['reason'],
                'source'      => $g['source'],
            ];
        }

        return ['groups' => $out, 'scanned' => count($names), 'capped' => $capped, 'ai' => $usedAi];
    }

    /**
     * Aggregate suggestions across every category in a cycle (merges only ever
     * happen within a category, so we scan each and combine the groups).
     *
     * @return array{groups: list<array{nominee_ids:int[],names:string[],confidence:float,reason:string,source:string,category:string}>, scanned:int, capped:bool, ai:bool}
     */
    public static function forCycle(int $cycleId, ?AiService $ai = null): array
    {
        $cats = [];
        try {
            $cats = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->get(['id', 'title'])->all();
        } catch (\Throwable) {}

        $groups = []; $scanned = 0; $capped = false; $usedAi = false;
        $ai ??= AiService::boot();
        foreach ($cats as $c) {
            $r = self::forCategory((int) $c->id, $ai);
            foreach ($r['groups'] as $g) { $g['category'] = (string) $c->title; $groups[] = $g; }
            $scanned += $r['scanned'];
            $capped = $capped || $r['capped'];
            $usedAi = $usedAi || $r['ai'];
        }
        // Highest-confidence first so the clearest duplicates are actioned first.
        usort($groups, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        return ['groups' => $groups, 'scanned' => $scanned, 'capped' => $capped, 'ai' => $usedAi];
    }

    /**
     * The id of an existing LIVE nominee in $categoryId that is CONFIDENTLY the
     * same person as $name — i.e. its normalised name is EQUAL (honorific / case /
     * punctuation / whitespace insensitive, via {@see norm()}). 0 when none.
     *
     * This is the auto-attach-on-approval resolver: approving a nomination for
     * someone who already has a nominee links to that nominee instead of minting
     * a duplicate that would split their votes. It is deliberately conservative —
     * only an EXACT normalised match auto-links. Near-but-unequal names
     * (edit-distance) are left to the human-confirmed duplicate-scan + (now
     * reversible) merge flow, so two genuinely different people are never silently
     * folded together by an automatic step. Tombstones are excluded.
     */
    public static function findLiveMatch(int $categoryId, string $name): int
    {
        $target = self::norm($name);
        if ($target === '') return 0;
        try {
            $q = DB::table('gates_nominees')
                ->where('category_id', $categoryId)
                ->whereIn('status', ['pending', 'approved', 'winner', 'runner_up']);
            MergeService::notMerged($q);
            foreach ($q->orderBy('id')->limit(self::SCAN_CAP)->get(['id', 'name']) as $row) {
                if (self::norm((string) $row->name) === $target) return (int) $row->id;
            }
        } catch (\Throwable) {}
        return 0;
    }

    /** Deterministic clusters: union nominees with equal-or-near normalised names. */
    private static function ruleGroups(array $nominees): array
    {
        $n = count($nominees);
        $parent = range(0, $n - 1);
        $find = function (int $x) use (&$parent, &$find): int { while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; } return $x; };
        $union = function (int $a, int $b) use (&$parent, $find): void { $parent[$find($a)] = $find($b); };

        $norms = array_map(fn($x) => self::norm((string) $x->name), $nominees);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $norms[$i]; $b = $norms[$j];
                if ($a === '' || $b === '') continue;
                // Exact (honorific/case/punctuation/diacritic-insensitive) OR a
                // TIGHT fuzzy match — small edit distance AND a high character
                // similarity, so "janedoe"/"johndoe" (distance 2 but different
                // people) is NOT wrongly clustered.
                $same = ($a === $b)
                    || (strlen($a) >= 6 && abs(strlen($a) - strlen($b)) <= 2
                        && levenshtein($a, $b) <= 2 && self::similarPct($a, $b) >= 80.0);
                if ($same) $union($i, $j);
            }
        }
        $buckets = [];
        for ($i = 0; $i < $n; $i++) { $buckets[$find($i)][] = $i; }

        $groups = [];
        foreach ($buckets as $idx) {
            if (count($idx) < 2) continue;
            $ids   = array_map(fn($i) => (int) $nominees[$i]->id, $idx);
            $gn    = array_map(fn($i) => $norms[$i], $idx);
            sort($ids);
            // High confidence only when two names are IDENTICAL once normalised;
            // a group held together purely by fuzzy links is advisory ("confirm").
            $hasExact = count($gn) !== count(array_unique($gn));
            $groups[] = [
                'nominee_ids' => $ids,
                'confidence'  => $hasExact ? 0.97 : 0.74,
                'reason'      => $hasExact
                    ? 'Identical names once normalised (honorifics/case/accents).'
                    : 'Very similar names — confirm they are the same person before merging.',
                'source'      => 'rule',
            ];
        }
        return $groups;
    }

    /** Optional AI clustering. Returns null when unavailable/unparseable. */
    private static function aiGroups(array $names, AiService $ai): ?array
    {
        $lines = [];
        foreach ($names as $id => $name) { $lines[] = $id . ': ' . mb_substr($name, 0, 80); }
        $system = 'You detect DUPLICATE award nominees — entries that are the SAME real person or organisation listed more than once '
            . '(nicknames, married/maiden names, transliteration, honorifics, spelling variants). '
            . 'Reply ONLY with JSON: {"groups":[{"ids":[<nominee ids that are the same entity>],"confidence":<0-1>,"reason":"<short why>"}]}. '
            . 'Each group MUST have 2+ ids. Do NOT group merely-similar but different people. If unsure, omit. Empty groups array if none.';
        $user = "Nominees (id: name):\n" . implode("\n", $lines);
        $raw = $ai->complete($system, $user, 700, true, 0.1);
        if (!is_string($raw)) return null;
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['groups']) || !is_array($j['groups'])) return null;

        $valid = array_keys($names);
        $out = [];
        foreach ($j['groups'] as $g) {
            if (!is_array($g) || !isset($g['ids']) || !is_array($g['ids'])) continue;
            $ids = array_values(array_unique(array_filter(array_map('intval', $g['ids']), fn($i) => in_array($i, $valid, true))));
            if (count($ids) < 2) continue;
            sort($ids);
            $out[] = [
                'nominee_ids' => $ids,
                'confidence'  => max(0.0, min(1.0, (float) ($g['confidence'] ?? 0.6))),
                'reason'      => mb_substr(trim((string) ($g['reason'] ?? 'AI-detected duplicate.')), 0, 200) ?: 'AI-detected duplicate.',
                'source'      => 'ai',
            ];
        }
        return $out;
    }

    /** Drop groups with an identical id-set (rule + AI can both find the same pair); keep the higher-confidence one. */
    private static function dedupeGroups(array $groups): array
    {
        $byKey = [];
        foreach ($groups as $g) {
            $key = implode('-', $g['nominee_ids']);
            if (!isset($byKey[$key]) || $g['confidence'] > $byKey[$key]['confidence']) $byKey[$key] = $g;
        }
        return array_values($byKey);
    }
}
