<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;
use AfricaGates\Services\MergeService;
use AfricaGates\Services\MergeSuggestionService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * How many PEOPLE an edition's nominee list holds, not how many entries.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO DIFFERENT QUESTIONS, AND WHY THEY ARE KEPT APART
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The merge scan (MergeSuggestionService) asks "is this entry a duplicate of another in
 * the SAME category?" — the case where one person's votes are split and a merge is the
 * fix. This asks the wider question: "is this the same person as an entry ANYWHERE in the
 * edition?" A teacher nominated for Teacher of the Year and for STEM Educator is two
 * legitimate entries and one person. Merging them would be wrong (MergeService refuses
 * across categories, correctly); counting them as two people is also wrong. So the answer
 * here is a COUNT and a list of rulings, never a merge — the same-category clusters it
 * finds are handed to the merge screen, and the rest are "same person" rulings.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT LINKS TWO ENTRIES, STRONGEST FIRST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   · a person's ruling (IdentityVerdicts, and merge-scan dismissals for "different")
 *     — final either way;
 *   · the same linked profile — one profile is one person by construction;
 *   · the same name once honorifics, case, accents, punctuation and word order are set
 *     aside (scan score ≥ 0.94, so not across two countries) — counted;
 *   · the AI's "same person" at ≥ 0.8 confidence — counted, and marked as the AI's;
 *   · a near name (an initial, a spelling variant: 0.72–0.94) — NOT counted. Listed as
 *     possible, so the net figure is a firm one with a stated range below it rather than
 *     a guess. The AI is asked about exactly these.
 *
 * The name rule is the scan's own (MergeSuggestionService::pairScore), not a copy.
 */
final class NomineePeople
{
    /** The statuses the merge scan reads — an entry the edition is carrying. */
    public const STATUSES = ['pending', 'approved', 'winner', 'runner_up'];

    public const EXACT = 0.94;
    public const POSSIBLE = 0.72;
    public const AI_TRUST = 0.8;
    /** A blocking bucket bigger than this carries no identifying signal (see the scan's BUCKET_CAP). */
    private const BUCKET_CAP = MergeSuggestionService::BUCKET_CAP;
    public const AI_PAIRS_PER_CALL = 40;
    public const AI_MAX_CALLS = 3;

    /**
     * @return array{entries:int, people:int, people_low:int, possible:int,
     *               clusters:list<array<string,mixed>>, pairs:list<array<string,mixed>>,
     *               by_basis:array<string,int>, crowded:int}
     */
    public static function forCycle(int $cycleId): array
    {
        $rows = self::rows($cycleId);
        $empty = ['entries' => count($rows), 'people' => count($rows), 'people_low' => count($rows), 'possible' => 0,
                  'clusters' => [], 'pairs' => [], 'by_basis' => [], 'crowded' => 0];
        if (count($rows) < 2) return $empty;

        $byId = [];
        foreach ($rows as $r) $byId[(int) $r->id] = $r;
        $verdicts = IdentityVerdicts::forIds('nominee', array_keys($byId));

        // Candidate pairs: shared blocking key, or shared profile.
        $buckets = [];
        foreach ($rows as $r) {
            foreach (MergeSuggestionService::nameKeys((string) $r->name) as $k) $buckets[$k][] = (int) $r->id;
            if (!empty($r->profile_id)) $buckets['p:' . (int) $r->profile_id][] = (int) $r->id;
        }
        $pairs = []; $crowded = 0;
        foreach ($buckets as $k => $ids) {
            if (count($ids) < 2) continue;
            if (count($ids) > self::BUCKET_CAP && !str_starts_with($k, 'p:')) { $crowded++; continue; }
            for ($x = 0; $x < count($ids); $x++) for ($y = $x + 1; $y < count($ids); $y++) {
                $pairs[MergeSuggestionService::pairKey($ids[$x], $ids[$y])] = [min($ids[$x], $ids[$y]), max($ids[$x], $ids[$y])];
            }
        }
        // Every pair a person or the AI ruled on is a candidate too, whatever the names say.
        foreach ($verdicts as $key => $_) {
            [$a, $b] = array_map('intval', explode('-', $key));
            if (isset($byId[$a], $byId[$b])) $pairs[$key] = [$a, $b];
        }

        $links = []; $possible = [];
        foreach ($pairs as $key => [$a, $b]) {
            $ra = $byId[$a]; $rb = $byId[$b];
            $v = $verdicts[$key] ?? null;
            $score = MergeSuggestionService::pairScore((string) $ra->name, (string) $rb->name, $ra->country_code, $rb->country_code);
            $cross = self::cc($ra) !== '' && self::cc($rb) !== '' && self::cc($ra) !== self::cc($rb);
            $link = ['a' => $a, 'b' => $b, 'key' => $key, 'score' => round($score, 2), 'same_category' => (int) $ra->category_id === (int) $rb->category_id];

            if ($v && $v['source'] === 'human') {
                if ($v['verdict'] === IdentityVerdicts::SAME) $links[] = $link + ['basis' => 'human', 'why' => 'A person ruled these the same person.'];
                continue;                                   // a person's "different" ends it
            }
            if (!empty($ra->profile_id) && (int) $ra->profile_id === (int) $rb->profile_id) {
                $links[] = $link + ['basis' => 'profile', 'why' => 'Both entries are linked to the same profile.'];
                continue;
            }
            if ($v && $v['source'] === 'ai') {
                if ($v['verdict'] === IdentityVerdicts::SAME && (float) $v['confidence'] >= self::AI_TRUST) {
                    $links[] = $link + ['basis' => 'ai', 'why' => 'AI: ' . (string) ($v['reason'] ?: 'the same person.'), 'confidence' => $v['confidence']];
                    continue;
                }
                if ($v['verdict'] === IdentityVerdicts::DIFFERENT) continue;   // the AI looked and said no
            }
            if ($score >= self::EXACT) {
                $links[] = $link + ['basis' => 'name', 'why' => MergeSuggestionService::explain($score, $cross)];
            } elseif ($score >= self::POSSIBLE) {
                $possible[] = $link + ['basis' => 'possible', 'why' => MergeSuggestionService::explain($score, $cross),
                                       'ai' => $v ? $v + [] : null];
            }
        }

        $firm = self::clusters(array_keys($byId), $links);
        $low  = self::clusters(array_keys($byId), array_merge($links, $possible));
        $byBasis = [];
        foreach ($links as $l) $byBasis[$l['basis']] = ($byBasis[$l['basis']] ?? 0) + 1;

        $cats = self::categories($cycleId);
        $member = static function (int $id) use ($byId, $cats): array {
            $r = $byId[$id];
            return ['id' => $id, 'name' => (string) $r->name, 'category_id' => (int) $r->category_id,
                    'category' => $cats[(int) $r->category_id] ?? '', 'votes' => (int) $r->vote_count,
                    'country' => self::cc($r), 'status' => (string) $r->status, 'profile_id' => $r->profile_id ? (int) $r->profile_id : null,
                    'linked' => !empty($r->profile_id), 'has_photo' => !empty($r->photo_path)];
        };
        $clusters = [];
        foreach ($firm['groups'] as $ids) {
            $ls = array_values(array_filter($links, static fn (array $l): bool => in_array($l['a'], $ids, true)));
            $byCat = [];
            foreach ($ids as $i) $byCat[(int) $byId[$i]->category_id][] = $i;
            $clusters[] = [
                'ids' => $ids, 'members' => array_map($member, $ids), 'links' => $ls,
                // The part a merge can fix: two or more entries in ONE category split one
                // person's votes. The rest is one person standing in several categories.
                'mergeable' => array_values(array_map(static function (array $g) use ($member): array {
                    $ms = array_map($member, $g);
                    return ['ids' => $g, 'keep' => MergeSuggestionService::survivor($ms), 'category' => $ms[0]['category'],
                            'votes' => array_sum(array_column($ms, 'votes'))];
                }, array_filter($byCat, static fn (array $g): bool => count($g) > 1))),
                'categories' => count($byCat),
            ];
        }
        usort($clusters, static fn (array $x, array $y): int => [count($y['ids']), $y['members'][0]['votes']] <=> [count($x['ids']), $x['members'][0]['votes']]);
        $pairsOut = array_map(static fn (array $p): array => $p + ['members' => [$member($p['a']), $member($p['b'])]], $possible);
        usort($pairsOut, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        return [
            'entries'    => count($byId),
            'people'     => $firm['count'],
            'people_low' => $low['count'],
            'possible'   => count($possible),
            'clusters'   => $clusters,
            'pairs'      => $pairsOut,
            'by_basis'   => $byBasis,
            'crowded'    => $crowded,
        ];
    }

    /**
     * Ask the AI about the pairs the rules could not settle, and keep its answers.
     *
     * Only `possible` pairs nobody has ruled on are sent — names, country, category and
     * organisation, never contact details or nomination text. A person's ruling is never
     * replaced (IdentityVerdicts::record). Returns what happened, honestly: how many pairs
     * were asked, answered, and whether the AI could be reached at all.
     *
     * @return array{asked:int, answered:int, same:int, different:int, calls:int, ok:bool, message:string}
     */
    public static function verifyWithAi(int $cycleId, ?AiService $ai = null): array
    {
        $out = ['asked' => 0, 'answered' => 0, 'same' => 0, 'different' => 0, 'calls' => 0, 'ok' => true, 'message' => ''];
        // An injected client is a test's; the gateway still applies every switch and budget.
        if ($ai === null && !AiGateway::available('nominee.identity_verify')) {
            return ['ok' => false, 'message' => 'The AI is switched off or out of budget for this check, so the near matches are left for a person.'] + $out;
        }
        $m = self::forCycle($cycleId);
        $todo = array_values(array_filter($m['pairs'], static fn (array $p): bool => empty($p['ai'])));
        if (!$todo) return ['message' => 'Nothing left for the AI: every near match has a ruling.'] + $out;

        $orgs = self::organisations(array_merge(array_column($todo, 'a'), array_column($todo, 'b')));
        foreach (array_slice(array_chunk($todo, self::AI_PAIRS_PER_CALL), 0, self::AI_MAX_CALLS) as $batch) {
            $out['calls']++;
            $out['asked'] += count($batch);
            $answers = self::askAi($batch, $orgs, $ai);
            if ($answers === null) {
                $out['ok'] = false;
                $out['message'] = 'The AI did not answer. Nothing was changed; the near matches are still waiting for a person.';
                break;
            }
            foreach ($answers as $key => $ans) {
                $pair = array_values(array_filter($batch, static fn (array $p): bool => $p['key'] === $key))[0] ?? null;
                if (!$pair) continue;
                if ($ans['verdict'] === 'unsure') continue;
                $verdict = $ans['verdict'] === 'same' ? IdentityVerdicts::SAME : IdentityVerdicts::DIFFERENT;
                if (IdentityVerdicts::record('nominee', [$pair['a'], $pair['b']], $verdict, 'ai', $ans['confidence'], $ans['reason'])) {
                    $out['answered']++;
                    $verdict === IdentityVerdicts::SAME ? $out['same']++ : $out['different']++;
                }
            }
        }
        if ($out['ok'] && $out['message'] === '') {
            $left = count($todo) - $out['asked'];
            $out['message'] = 'The AI looked at ' . $out['asked'] . ' near match' . ($out['asked'] === 1 ? '' : 'es')
                . ': ' . $out['same'] . ' the same person, ' . $out['different'] . ' different, '
                . ($out['asked'] - $out['answered']) . ' unsure.' . ($left > 0 ? ' ' . $left . ' more wait for the next run.' : '');
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $batch
     * @param array<int,string> $orgs
     * @return array<string, array{verdict:string, confidence:float, reason:string}>|null
     */
    private static function askAi(array $batch, array $orgs, ?AiService $ai): ?array
    {
        $lines = [];
        $valid = [];
        foreach ($batch as $p) {
            $valid[] = $p['key'];
            $side = static function (array $m) use ($orgs): string {
                $bits = [mb_substr($m['name'], 0, 80)];
                if ($m['country'] !== '') $bits[] = '[' . $m['country'] . ']';
                if ($m['category'] !== '') $bits[] = 'category: ' . mb_substr($m['category'], 0, 60);
                if (!empty($orgs[$m['id']])) $bits[] = 'organisation: ' . mb_substr($orgs[$m['id']], 0, 80);
                return implode(' · ', $bits);
            };
            $lines[] = $p['key'] . ' | A: ' . $side($p['members'][0]) . ' | B: ' . $side($p['members'][1]);
        }
        $system = 'You decide whether two award nominee entries are the SAME real person or organisation. '
            . 'Each line is "<pair id> | A: … | B: …" with the name, the country it was entered under, the award category '
            . 'and the organisation where known. The same person may be nominated in several categories; different '
            . 'categories alone are not evidence either way. An initial may stand for either of two people; a different '
            . 'country or a different organisation is evidence of two people. Answer "unsure" whenever the names alone cannot '
            . 'settle it — a wrong "same" joins two strangers. Reply ONLY with JSON: '
            . '{"pairs":[{"id":"<pair id>","verdict":"same"|"different"|"unsure","confidence":<0-1>,"reason":"<short why>"}]}.';
        $r = (new AiGateway($ai))->run('nominee.identity_verify', [
            'system'      => $system,
            'trusted'     => 'The pairs follow, one per line.',
            'user'        => implode("\n", $lines),
            'json'        => true,
            'temperature' => 0.1,
            'schema'      => static function (string $raw) use ($valid): ?array {
                $j = json_decode($raw, true);
                if (!is_array($j) || !isset($j['pairs']) || !is_array($j['pairs'])) return null;
                $out = [];
                foreach ($j['pairs'] as $p) {
                    if (!is_array($p)) continue;
                    $id = (string) ($p['id'] ?? '');
                    $v = (string) ($p['verdict'] ?? '');
                    if (!in_array($id, $valid, true) || !in_array($v, ['same', 'different', 'unsure'], true)) continue;
                    $out[$id] = [
                        'verdict'    => $v,
                        'confidence' => max(0.0, min(1.0, (float) ($p['confidence'] ?? 0.5))),
                        'reason'     => mb_substr(trim((string) ($p['reason'] ?? '')), 0, 200),
                    ];
                }
                return $out;
            },
        ]);
        return $r->ok ? $r->value : null;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return list<object> */
    private static function rows(int $cycleId): array
    {
        try {
            $q = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)
                ->whereIn('n.status', self::STATUSES);
            MergeService::notMerged($q, 'n.merged_into');
            return $q->orderBy('n.id')->get(['n.id', 'n.name', 'n.category_id', 'n.profile_id', 'n.photo_path', 'n.country_code', 'n.vote_count', 'n.status'])->all();
        } catch (\Throwable $e) {
            error_log('[nominee-people] ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int,string> */
    private static function categories(int $cycleId): array
    {
        try {
            return DB::table('gates_award_categories')->where('cycle_id', $cycleId)->pluck('title', 'id')
                ->map(static fn ($t): string => (string) $t)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<int> $ids @return array<int,string> */
    private static function organisations(array $ids): array
    {
        try {
            if (!\AfricaGates\Support\SchemaHas::column('gates_nominees', 'organisation')) return [];
            return DB::table('gates_nominees')->whereIn('id', array_values(array_unique($ids)))
                ->whereNotNull('organisation')->pluck('organisation', 'id')->map(static fn ($o): string => (string) $o)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function cc(object $r): string
    {
        return strtoupper(trim((string) ($r->country_code ?? '')));
    }

    /**
     * Union-find: how many people the links leave, and the groups of two or more.
     *
     * @param list<int> $ids
     * @param list<array{a:int,b:int}> $links
     * @return array{count:int, groups:list<list<int>>}
     */
    public static function clusters(array $ids, array $links): array
    {
        $parent = [];
        foreach ($ids as $i) $parent[$i] = $i;
        $find = static function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
            return $x;
        };
        foreach ($links as $l) {
            if (!isset($parent[$l['a']], $parent[$l['b']])) continue;
            $ra = $find($l['a']); $rb = $find($l['b']);
            if ($ra !== $rb) $parent[max($ra, $rb)] = min($ra, $rb);
        }
        $groups = [];
        foreach ($ids as $i) $groups[$find($i)][] = $i;
        $multi = array_values(array_filter($groups, static fn (array $g): bool => count($g) > 1));
        foreach ($multi as &$g) sort($g);
        return ['count' => count($groups), 'groups' => $multi];
    }
}
