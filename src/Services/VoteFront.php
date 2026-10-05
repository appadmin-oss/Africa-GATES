<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\ProgrammeHost;
use AfricaGates\Support\Slug;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE VOTING PAGES' FACTS — `/vote` and `/vote/{programme}` (Phase 5, VoteHub / VotePage DCs).
 *
 * Plain arrays for the templates. The phase is computed (`CyclePolicy`, never the stored
 * status), the race framing is `RaceService`'s, the split is `RuleEngine`'s for THIS
 * programme and edition, the contribution sentence is `PaidVoteCopy`'s, and the countdown
 * is the server's seconds (the browser only decrements — VoteCountdownTest).
 *
 * ── WHAT "YOUR BALLOT" CAN HONESTLY SAY ─────────────────────────────────────
 *
 * The DC's "You've voted in 4 of 19 open categories" and "1 of 6 categories voted" are
 * per-person facts, and votes are verified by an email code with no account, so the server
 * cannot know them for a visitor. The DENOMINATOR is a server fact (open categories) and
 * is sent from here; the numerator is what THIS DEVICE recorded at each confirmed vote
 * (`ag-voted:` in browser storage, written only after the server said the vote counted —
 * CookieRegistry). The page says it is this device's record, never "you".
 *
 * Only active programmes are read (the sandbox's containment, CLAUDE.md).
 */
final class VoteFront
{
    /**
     * The hub: every active programme's current edition as a row.
     *
     * @param array<int,array<string,mixed>> $hub AwardService::voteHub()
     * @return array<string,mixed>
     */
    public static function hub(array $hub, string $filter = 'all'): array
    {
        $rows = [];
        $votesTotal = 0; $openAwards = 0; $openCats = 0;
        foreach ($hub as $p) {
            $cycle = !empty($p['cycle_id']) ? DB::table('gates_award_cycles')->where('id', (int) $p['cycle_id'])->first() : null;
            $key   = $cycle ? AwardsFront::phaseKey($cycle) : 'upcoming';
            $phase = (array) ($p['phase'] ?? []);
            $open  = !empty($phase['is_voting_open']);
            $host  = ProgrammeHost::forProgramme((int) $p['id']);
            $cats  = count($p['categories'] ?? []);
            $votes = (int) ($p['total_votes'] ?? 0);
            $votesTotal += $votes;
            if ($open) { $openAwards++; $openCats += $cats; }

            $leader = $p['leader'] ?? null;
            $status = match (true) {
                $open && $leader && (int) $leader['vote_count'] > 0
                    => Translator::t('%n% leads %c%', ['%n%' => $leader['name'], '%c%' => $leader['category']]),
                $open => Translator::t('Be among the first to vote'),
                $key === 'nominations' => ($cycle && $cycle->voting_open)
                    ? Translator::t('Nominations open · voting from %d%', ['%d%' => DisplayTime::show((string) $cycle->voting_open, 'j M')])
                    : Translator::t('Nominations open'),
                $key === 'shortlisting' => Translator::t('Shortlisting · voting opens soon'),
                $key === 'judging' => ($cycle && $cycle->results_date)
                    ? Translator::t('Judging · results %d%', ['%d%' => DisplayTime::show((string) $cycle->results_date, 'j M')])
                    : Translator::t('Judging'),
                $key === 'late' => Translator::t('Results delayed'),
                in_array($key, ['results', 'archived'], true) => Translator::t('Results published'),
                default => $cycle && ($cycle->nominations_open ?? $cycle->voting_open ?? null)
                    ? Translator::t('Opens %d%', ['%d%' => DisplayTime::show((string) ($cycle->nominations_open ?? $cycle->voting_open), 'j M Y')])
                    : Translator::t('Coming soon'),
            };

            $left = $open ? (int) ($phase['seconds_left'] ?? 0) : 0;
            $rows[] = [
                'id'      => (int) $p['id'],
                'slug'    => (string) $p['slug'],
                'title'   => (string) $p['title'],
                'href'    => $open || in_array($key, ['shortlisting', 'judging', 'late'], true)
                                 ? '/vote/' . rawurlencode((string) $p['slug'])
                                 : '/awards/' . rawurlencode((string) $p['slug']),
                'cover'   => trim((string) (DB::table('gates_award_programmes')->where('id', (int) $p['id'])->value('cover_path') ?? '')),
                'tone'    => AwardsFront::coverTone((int) $p['id']),
                'meta'    => implode(' · ', array_filter([
                                 (string) ($host['name'] ?? ''),
                                 $cycle ? EditionName::label($cycle) : '',
                                 $cats ? ($cats === 1 ? Translator::t('1 category') : Translator::t('%n% categories', ['%n%' => $cats])) : '',
                             ])),
                'status'  => $status,
                'open'    => $open,
                'votes'   => $votes,
                'cats'    => $cats,
                // The urgency chip only inside CyclePolicy's own closing-soon window, so it
                // cannot disagree with the phase logic that gates the vote.
                'left'    => !empty($phase['closing_soon'])
                                 ? self::leftWords($left) : '',
            ];
        }

        $want = in_array($filter, ['all', 'voting', 'soon'], true) ? $filter : 'all';
        $shown = array_values(array_filter($rows, static fn (array $r): bool =>
            $want === 'all' || ($want === 'voting' ? $r['open'] : !$r['open'])));
        // Open ballots first, then the rest in the operator's order.
        usort($shown, static fn (array $a, array $b): int => (int) $b['open'] <=> (int) $a['open']);

        return ['rows' => $shown, 'all' => $rows, 'filter' => $want, 'votes_total' => $votesTotal,
                'open_awards' => $openAwards, 'open_categories' => $openCats];
    }

    /** "2 days left", "5 hours left" — the hub's urgency chip. */
    public static function leftWords(int $seconds): string
    {
        if ($seconds >= 86400) {
            $d = intdiv($seconds, 86400);
            return $d === 1 ? Translator::t('1 day left') : Translator::t('%n% days left', ['%n%' => $d]);
        }
        $h = max(1, intdiv($seconds, 3600));
        return $h === 1 ? Translator::t('1 hour left') : Translator::t('%n% hours left', ['%n%' => $h]);
    }

    /**
     * One edition's vote page.
     *
     * @param array<string,mixed> $p           AwardService::getProgrammeBySlug()
     * @param list<array<string,mixed>> $cats  the controller's annotated categories
     * @return array<string,mixed>
     */
    public static function programme(array $p, array $cats, ?int $catId): array
    {
        $cycle = $p['cycle'] ? (object) $p['cycle'] : null;
        $phase = (array) ($p['phase'] ?? []);
        $key   = $cycle ? AwardsFront::phaseKey($cycle) : 'upcoming';
        $host  = ProgrammeHost::forProgramme((int) $p['id']);
        $rules = new RuleEngine();
        $w     = $rules->weights((int) $p['id'], $cycle ? (int) $cycle->id : null);
        $wc    = (int) round(($w['community'] ?? 0) * 100); $wj = (int) round(($w['judge'] ?? 0) * 100);
        $open  = !empty($phase['is_voting_open']);

        $current = $cats[0] ?? null;
        foreach ($cats as $c) { if ($catId !== null && (int) $c['category']['id'] === $catId) { $current = $c; break; } }

        $nominees = 0; foreach ($cats as $c) $nominees += count($c['nominees']);
        $left = $open ? (int) ($phase['seconds_left'] ?? 0) : 0;
        $closes = $cycle && $cycle->voting_close ? (string) $cycle->voting_close : '';
        $event = EventInvites::eventForProgramme((int) $p['id']);
        $judges = self::judgeCount((int) $p['id']);
        $paid = PaidVoteService::enabled();
        $edition = $cycle ? EditionName::label($cycle) : '';

        $sub = trim($edition . ' · ' . match ($key) {
            'voting' => Translator::t('voting open'), 'nominations' => Translator::t('nominations open'),
            'shortlisting' => Translator::t('shortlisting'), 'judging' => Translator::t('judging'),
            'late' => Translator::t('results delayed'), 'results', 'archived' => Translator::t('results published'),
            default => Translator::t('coming soon'),
        }, ' ·');

        $faq = [
            [Translator::t('Can I vote more than once?'),
             Translator::t('One free vote per category, confirmed by an email code, and never duplicated.')
             . ($paid ? ' ' . Translator::t('Where the host allows it, you can add contributed votes or redeem points on a nominee’s page.') : '')],
            [Translator::t('Why can I see the totals?'),
             Translator::t('Totals are live so everyone can see the race as it happens. They are one part of the score; the judges decide the rest after voting closes.')],
            [Translator::t('Does extra support guarantee a win?'),
             Translator::t('No. Community support is %c% of the score and the judges’ marks are %j%.', ['%c%' => $wc . '%', '%j%' => $wj . '%'])
             . ($paid ? ' ' . PaidVoteCopy::sentence((int) $p['id'], $cycle ? (int) $cycle->id : null) : '')],
        ];
        if ($paid) {
            $faq[] = [Translator::t('Where does the money go?'),
                      Translator::t('It funds the awards. What every nominee received in contributed votes is published with the result.')];
        }

        $chip = match ($key) {
            'voting' => Translator::t('Voting open'), 'nominations' => Translator::t('Nominations open'),
            'shortlisting' => Translator::t('Shortlisting'), 'judging' => Translator::t('Judging'),
            'late' => Translator::t('Results delayed'), 'results', 'archived' => Translator::t('Results published'),
            default => Translator::t('Coming soon'),
        };

        return [
            'key'      => $key,
            'chip'     => $chip,
            'open'     => $open,
            'sub'      => $sub,
            'edition'  => $edition,
            'host'     => (string) ($host['name'] ?? ''),
            'meta'     => implode(' · ', array_filter([
                              (string) ($host['name'] ?? ''), $edition,
                              count($cats) === 1 ? Translator::t('1 category') : Translator::t('%n% categories', ['%n%' => count($cats)]),
                              $nominees === 1 ? Translator::t('1 nominee') : Translator::t('%n% nominees', ['%n%' => $nominees]),
                          ])),
            'cover'    => (string) ($p['cover'] ?? ''),
            'tone'     => AwardsFront::coverTone((int) $p['id']),
            'left'     => $left,
            'closes'   => $closes,
            'current'  => $current,
            'categories' => count($cats),
            'weights'  => ['community' => $wc, 'judge' => $wj],
            'judging_from' => $closes,
            'facts'    => array_values(array_filter([
                $host ? ['k' => Translator::t('Host'), 'v' => (string) $host['name']] : null,
                ($p['subtitle'] ?? '') !== '' ? ['k' => Translator::t('Celebrates'), 'v' => (string) $p['subtitle']] : null,
                ['k' => Translator::t('Editions'), 'v' => self::editionsLine($p)],
                ['k' => Translator::t('Who can vote'), 'v' => Translator::t('Anyone who confirms their vote with an email code')],
                ['k' => Translator::t('Support'), 'v' => $paid
                    ? Translator::t('One free vote per category, plus optional contributed votes from ₦%p% a vote (up to %q% in one order)',
                        ['%p%' => number_format(PaidVoteService::pricePerVote()), '%q%' => number_format(PaidVoteService::maxQtyForOrder())])
                    : Translator::t('Free, one vote per category')],
                $judges > 0 ? ['k' => Translator::t('Judges'), 'v' => $judges === 1 ? Translator::t('1 judge') : Translator::t('%n% judges', ['%n%' => $judges])] : null,
            ])),
            'timeline' => $cycle ? self::timeline($cycle, $key) : [],
            'how'      => Translator::t('Community support counts for %c% of the final score and the judges’ marks for %j%. After voting closes, the judges score the nominees in each category. The weights are fixed before voting opens.', ['%c%' => $wc . '%', '%j%' => $wj . '%']),
            'faq'      => $faq,
            'event'    => $event ? ['url' => '/events/' . rawurlencode((string) $event->slug), 'title' => (string) $event->title,
                                    'mon' => DisplayTime::show((string) $event->event_date, 'M'), 'day' => DisplayTime::show((string) $event->event_date, 'd'),
                                    'where' => trim((string) ($event->location ?? $event->venue ?? ''))] : null,
        ];
    }

    /** "11 since 2016", or "1" — counted from this platform's rows and the stored number. */
    private static function editionsLine(array $p): string
    {
        $cycle = $p['cycle'] ?? null;
        $n = $cycle ? EditionName::number((object) $cycle) : 0;
        $first = $p['first_year'] ?? null;
        if ($n > 1 && $first) {
            $since = (int) $first - (EditionName::number(DB::table('gates_award_cycles')->where('programme_id', $p['id'])->orderBy('year')->orderBy('id')->first() ?? []) - 1);
            return Translator::t('%n% since %y%', ['%n%' => $n, '%y%' => $since]);
        }
        return (string) max(1, $n);
    }

    /** The four steps of VotePage's About tab: Nominations → Community voting → Judging after voting → Results & ceremony. */
    private static function timeline(object $c, string $key): array
    {
        $order = ['upcoming' => -1, 'nominations' => 0, 'shortlisting' => 0.5, 'voting' => 1, 'judging' => 2, 'late' => 2, 'results' => 3, 'archived' => 3];
        $now = $order[$key] ?? -1;
        $d = static fn ($v): string => $v ? DisplayTime::show((string) $v, 'j M') : '';
        $steps = [
            ['Nominations', $now > 0 ? Translator::t('Closed %d%', ['%d%' => $d($c->nominations_close ?? null)]) : $d($c->nominations_open ?? null)],
            ['Community voting', $now == 1 ? Translator::t('Now · closes %d%', ['%d%' => $d($c->voting_close ?? null)])
                                 : ($now > 1 ? Translator::t('Closed %d%', ['%d%' => $d($c->voting_close ?? null)]) : $d($c->voting_open ?? null))],
            ['Judging', Translator::t('After voting')],
            ['Results & ceremony', $key === 'late' ? Translator::t('Delayed') : ($d($c->results_date ?? null) ?: Translator::t('To be announced'))],
        ];
        $out = [];
        foreach ($steps as $i => [$t, $sub]) {
            $out[] = ['label' => Translator::t($t), 'sub' => trim($sub), 'state' => $i < $now ? 'done' : ($i == $now ? 'now' : 'next')];
        }
        return $out;
    }

    /** Real judges assigned to this programme (never the sandbox's — JudgeService::realJudges()). */
    private static function judgeCount(int $programmeId): int
    {
        try {
            $n = 0;
            foreach (\AfricaGates\Judge\Services\JudgeService::realJudges()->where('is_active', 1)->pluck('programme_ids') as $ids) {
                $list = json_decode((string) $ids, true);
                if (is_array($list) && in_array($programmeId, array_map('intval', $list), true)) $n++;
            }
            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }
}
