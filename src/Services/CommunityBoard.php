<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\AwardCampaign;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE COMMUNITY PRIDE BOARD (owner, 16 Nov 2026: "more features for voters … make them feel
 * good"): "Alimosho — 3,400 people backing their own". People rally for their PLACE, not only
 * for a friend, and a place on a list is the most shareable thing a community has.
 *
 * Each live award is a community: the host's own community name where they set one
 * (AwardCampaign), else the award's title — nothing about a voter's location is collected, so
 * nothing here pretends to know it. Ranked by PEOPLE backing their own (the Spotlight's count,
 * never the tally, so a single big buyer cannot carry a community up the board).
 *
 * Only active programmes (the sandbox is inactive and never appears), only a current edition
 * that has opened its vote. Cached five minutes; it is read on the busiest page there is.
 */
final class CommunityBoard
{
    /** @return list<array{programme_id:int, slug:string, label:string, people:int, rank:int}> */
    public static function current(): array
    {
        $calc = static function (): array {
            try {
                $progs = DB::table('gates_award_programmes')->where('is_active', 1)->get(['id', 'slug', 'title', 'campaign_json'])->all();
            } catch (\Throwable) {
                return [];
            }
            $rows = [];
            foreach ($progs as $p) {
                $cycle = BallotGuard::currentCycleForProgramme((int) $p->id);
                if (!$cycle) continue;
                $phase = CyclePolicy::phaseFor($cycle)->value;
                if (in_array($phase, ['upcoming', 'nominations', 'archived'], true)) continue;
                $people = (int) (SupporterSpotlight::forCycle((int) $cycle->id)['people'] ?? 0);
                if ($people < 1) continue;
                $c = AwardCampaign::of($p);
                $rows[] = ['programme_id' => (int) $p->id, 'slug' => (string) $p->slug, 'label' => $c['community'] !== '' ? $c['community'] : (string) $p->title, 'people' => $people];
            }
            usort($rows, static fn ($a, $b) => $b['people'] <=> $a['people'] ?: strcmp($a['label'], $b['label']));
            foreach ($rows as $i => $r) $rows[$i]['rank'] = $i + 1;
            return $rows;
        };
        if (SupporterSpotlight::$ttl < 1) return $calc();
        try {
            return (array) (new CacheService())->remember('community-board', 300, $calc, ['spotlight']);
        } catch (\Throwable) {
            return $calc();
        }
    }

    /** Where one award's community stands, or null when it is not on the board. @return array{rank:int, of:int}|null */
    public static function placeOf(int $programmeId): ?array
    {
        $all = self::current();
        foreach ($all as $r) if ($r['programme_id'] === $programmeId) return ['rank' => $r['rank'], 'of' => count($all)];
        return null;
    }
}
