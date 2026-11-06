<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;

/**
 * THE AWARD PAGE'S FACTS, WORKED OUT — never typed into the template.
 *
 * `/awards/{slug}` states four kinds of fact about an award: where it is in its cycle,
 * what a reader can do about that, who runs it and how it is scored. Every one of them
 * is a rule somewhere else in this codebase — `CyclePolicy` decides the phase,
 * `RuleEngine` holds the weights — and a template that spelled any of them out would
 * be one more copy to fall out of step. The settings screen was once found explaining
 * the scoring basis with a different basis's arithmetic, 157 points out; the scoring
 * fact here is read from the same `weights()` the scorer uses, for this programme and
 * this edition.
 *
 * Plain arrays out, so the template draws and decides nothing.
 */
final class AwardOverview
{
    /** The four steps of a cycle, as the comp draws them. Shortlisting folds into judging's
        neighbour: to a reader it is "nominations closed, voting not yet open". */
    private const STEPS = ['nominations' => 'Nominations', 'voting' => 'Voting',
                           'judging' => 'Judging', 'results' => 'Results'];

    /**
     * @param array<string,mixed>      $cycle  the edition's row
     * @param array<string,mixed>|null $phase  CyclePolicy::stateFor()
     * @return list<array{key:string,label:string,sub:string,state:string}> state: done|now|next
     */
    public static function timeline(array $cycle, ?array $phase): array
    {
        $now = match ((string) ($phase['phase'] ?? 'upcoming')) {
            'upcoming'     => -1,
            'nominations'  => 0,
            // Between the two windows. Nominations are done and voting is next — drawing
            // "now" on either would describe something that is not happening.
            'shortlisting' => 0.5,
            'voting'       => 1,
            'judging'      => 2,
            'results', 'archived' => 3,
            default        => -1,
        };

        $date = static fn ($v): string => $v ? DisplayTime::show((string) $v, 'j M') : '';
        $subs = [
            'nominations' => $now > 0 ? 'Closed'
                : ($now === 0 ? self::until($cycle['nominations_close'] ?? null)
                              : self::from($cycle['nominations_open'] ?? null)),
            'voting'      => $now > 1 ? 'Closed'
                : ($now === 1 ? self::until($cycle['voting_close'] ?? null)
                              : self::from($cycle['voting_open'] ?? null)),
            'judging'     => $now > 2 ? 'Done' : 'After voting',
            'results'     => $date($cycle['results_date'] ?? null) ?: 'To be announced',
        ];

        $out = [];
        $i = 0;
        foreach (self::STEPS as $key => $label) {
            $out[] = [
                'key'   => $key,
                'label' => $label,
                'sub'   => $subs[$key],
                // Results is "now" once released and stays so — it is the state the
                // edition ends in, not a step it passes through.
                'state' => $i < $now ? 'done' : ($i == $now ? 'now' : 'next'),
            ];
            $i++;
        }
        return $out;
    }

    /**
     * The one thing a reader can do about this award right now, and where it goes.
     *
     * To THIS award, never a hub: a nomination goes to `/nominate/{slug}`, which knows
     * the award's own wording and categories, and the old link to `/nominate` dropped
     * that context and asked the reader to find the award again.
     *
     * @return array{label:string,href:string}|null
     */
    public static function action(string $slug, ?array $phase): ?array
    {
        return match ((string) ($phase['phase'] ?? '')) {
            'nominations'            => ['label' => 'Submit a nomination', 'href' => '/nominate/' . $slug],
            'voting'                 => ['label' => 'Vote now',         'href' => '/vote/' . $slug],
            'shortlisting', 'judging'=> ['label' => 'See the nominees', 'href' => '/vote/' . $slug],
            'results', 'archived'    => ['label' => 'See the results',  'href' => '/results'],
            default                  => null,
        };
    }

    /**
     * "About the award": host, since, editions, scoring.
     *
     * @param array{community:float,judge:float} $weights RuleEngine::weights() for this scope
     * @return list<array{k:string,v:string}>
     */
    public static function facts(?array $host, ?int $firstYear, int $editions, array $weights): array
    {
        $out = [];
        if ($host && ($host['name'] ?? '') !== '') {
            $out[] = ['k' => 'Host', 'v' => (string) $host['name']];
        }
        if ($firstYear) {
            $out[] = ['k' => 'Since', 'v' => $firstYear . ($editions > 1 ? ' · ' . $editions . ' editions' : '')];
        }
        $out[] = ['k' => 'Scoring', 'v' => self::pct($weights['community'] ?? 0) . '% public · '
                                         . self::pct($weights['judge'] ?? 0) . '% panel'];
        return $out;
    }

    private static function pct(float $w): int
    {
        return (int) round($w * 100);
    }

    private static function until(?string $at): string
    {
        return $at ? 'Until ' . DisplayTime::show($at, 'j M') : 'Open now';
    }

    private static function from(?string $at): string
    {
        return $at ? 'Opens ' . DisplayTime::show($at, 'j M') : 'Dates to come';
    }
}
