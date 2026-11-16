<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Clock;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE SUPPORTER SPOTLIGHT — the people behind the nominees, celebrated on the vote page
 * (owner, 16 Nov 2026: "start highlighting top voters to celebrate them … the voter needs to
 * feel a reason to truly vote", after the Afrovanguard Community Wealth & Impact Framework).
 *
 * Three honours, each a different way of backing your own:
 *
 *   CHAMPION OF THE DAY     the most votes from one person today, in West Africa Time
 *   TOP SUPPORTERS          the most votes from one person across this edition
 *   MOBILISER OF THE WEEK   the most separate people brought to vote through one person's
 *                           own link this week (VoteRecruit) — the framework's point that
 *                           "financial capacity" must not be the only route to recognition
 *
 * ── A PERSON IS COUNTED THE WAY THE SCORE COUNTS ONE ─────────────────────────
 * {@see VoterReach::personKey()} is the one definition, so a supporter who splits ₦20,000
 * into twenty orders is one supporter here exactly as they are one in the people term, and
 * their free vote and their paid ones are the same person. A grant is nobody (nobody chose
 * to cast it). A fraud-flagged row is nobody. A vote whose order has been refunded, or has a
 * refund in flight, is not counted — "only verified, settled" is the framework's rule and
 * the one a champion must survive being checked against.
 *
 * ── A NAME ONLY WHERE SOMEBODY ASKED FOR ONE ─────────────────────────────────
 * The ranking is of everybody; the NAME comes only from a vote row whose `show_name` was
 * ticked ({@see SupportersService} has the whole doctrine). Somebody who did not ask is
 * still ranked, and drawn as "a supporter who chose not to be named" — dropping them would
 * crown the second-biggest backer and call them the biggest, which is a false fact on a
 * board whose whole value is being true. A name is never inferred from a donation's own
 * name field, which the buyer filled in without being told it would be shown.
 *
 * ── TIES ─────────────────────────────────────────────────────────────────────
 * Settled in advance and stated on the page: equal votes go to whoever got there first
 * (the earlier final vote). Never to the bigger order, which would make a tie something
 * money breaks.
 *
 * Cached for a minute per edition: a page that celebrates a burst of voting must not cost a
 * scan of the edition's whole ledger per visitor in the burst.
 */
final class SupporterSpotlight
{
    public const TOP = 5;
    public const ZONE = 'Africa/Lagos';
    public const CHUNK = 5000;

    /** Seconds a computed Spotlight is reused. Tests set 0. */
    public static int $ttl = 60;

    /**
     * The Spotlight for one edition.
     *
     * @return array{champion:?array, top:list<array>, mobiliser:?array, people:int, votes:int}
     */
    public static function forCycle(int $cycleId, ?Carbon $now = null): array
    {
        $empty = ['champion' => null, 'top' => [], 'mobiliser' => null, 'people' => 0, 'votes' => 0];
        if ($cycleId < 1) return $empty;
        if (self::$ttl < 1 || $now !== null) return self::build($cycleId, $now);
        try {
            return (array) (new CacheService())->remember('spotlight:' . $cycleId, self::$ttl,
                static fn () => self::build($cycleId, null));
        } catch (\Throwable) {
            return self::build($cycleId, null);
        }
    }

    /**
     * How many counted votes this person has given in the edition — the figure their
     * benefits are read against. By the address they vote with, resolved like every other
     * person here.
     */
    public static function votesBy(int $cycleId, string $email): int
    {
        $email = trim($email);
        if ($cycleId < 1 || $email === '') return 0;
        $me = VoteService::voterHash($email);
        $total = 0;
        self::scan($cycleId, null, static function (string $key, object $r) use ($me, &$total): void {
            if ($key === $me) $total += max(1, (int) ($r->weight ?? 1));
        });
        return $total;
    }

    /** @return array{champion:?array, top:list<array>, mobiliser:?array, people:int, votes:int} */
    public static function build(int $cycleId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $dayStart  = $now->copy()->setTimezone(self::ZONE)->startOfDay()->setTimezone(Clock::timezone())->toDateTimeString();
        $weekStart = $now->copy()->setTimezone(self::ZONE)->startOfWeek(Carbon::MONDAY)->setTimezone(Clock::timezone())->toDateTimeString();

        /** @var array<string,array{votes:int,last:string,name:?string,nameId:int,by:array<int,int>}> $all */
        $all = [];
        /** @var array<string,array{votes:int,last:string}> $today */
        $today = [];
        $votes = 0;

        self::scan($cycleId, null, static function (string $key, object $r) use (&$all, &$today, &$votes, $dayStart): void {
            $w = max(1, (int) ($r->weight ?? 1));
            $at = (string) ($r->voted_at ?? '');
            $votes += $w;
            $p = $all[$key] ?? ['votes' => 0, 'last' => '', 'name' => null, 'nameId' => 0, 'by' => []];
            $p['votes'] += $w;
            if ($at > $p['last']) $p['last'] = $at;
            $nid = (int) $r->nominee_id;
            $p['by'][$nid] = ($p['by'][$nid] ?? 0) + $w;
            // The newest name they ASKED to be shown under.
            $name = trim((string) ($r->voter_name ?? ''));
            if ((int) ($r->show_name ?? 0) === 1 && $name !== '' && strcasecmp($name, 'Supporter') !== 0 && (int) $r->id > $p['nameId']) {
                $p['name'] = mb_substr($name, 0, 60);
                $p['nameId'] = (int) $r->id;
            }
            $all[$key] = $p;
            if ($at !== '' && $at >= $dayStart) {
                $t = $today[$key] ?? ['votes' => 0, 'last' => ''];
                $t['votes'] += $w;
                if ($at > $t['last']) $t['last'] = $at;
                $today[$key] = $t;
            }
        });

        $ids = [];
        foreach ($all as $p) foreach (array_keys($p['by']) as $nid) $ids[$nid] = true;
        $names = self::nomineeNames(array_keys($ids));
        $card = static function (string $key, int $n) use (&$all, $names): array {
            $p = $all[$key];
            arsort($p['by']);
            $for = (int) array_key_first($p['by']);
            return ['name' => $p['name'], 'votes' => $n, 'for' => $names[$for] ?? null];
        };

        $order = static fn (array $a, array $b): int => $b['votes'] <=> $a['votes'] ?: strcmp($a['last'], $b['last']);

        $ranked = $all;
        uasort($ranked, $order);
        $top = [];
        foreach (array_slice($ranked, 0, self::TOP, true) as $k => $p) $top[] = $card((string) $k, $p['votes']);

        uasort($today, $order);
        $champKey = array_key_first($today);
        $champion = $champKey !== null ? $card((string) $champKey, $today[$champKey]['votes']) : null;

        return ['champion' => $champion, 'top' => $top, 'mobiliser' => self::mobiliser($cycleId, $weekStart, $all),
                'people' => count($all), 'votes' => $votes];
    }

    /**
     * Every counted vote in the edition, handed to `$each` with its person. One pass, in
     * chunks, so a busy edition is never one array in memory.
     *
     * @param callable(string, object): void $each
     */
    private static function scan(int $cycleId, ?string $since, callable $each): void
    {
        try {
            $cats = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return;
        }
        if (!$cats) return;

        $cols = ['id', 'nominee_id', 'voter_email_hash', 'vote_type', 'donation_id', 'weight', 'voter_name', 'show_name', 'voted_at', 'fraud_flag'];
        try {
            $q = DB::table('gates_votes')->whereIn('category_id', $cats);
            if ($since !== null) $q->where('voted_at', '>=', $since);
            $q->orderBy('id')->select($cols)->chunk(self::CHUNK, static function ($rows) use ($each): void {
                $buyers = VoterReach::buyerHashes($rows);
                $void   = self::unsettled($rows);
                foreach ($rows as $r) {
                    if ((int) ($r->fraud_flag ?? 0) !== 0) continue;
                    if (isset($void[(int) ($r->donation_id ?? 0)])) continue;
                    $key = VoterReach::personKey($r, $buyers);
                    if ($key === null) continue;
                    $each($key, $r);
                }
            });
        } catch (\Throwable) {
            // A Spotlight that cannot be read is not drawn; it never takes the ballot down.
        }
    }

    /**
     * Orders in this chunk whose votes must not be celebrated: refunded, a refund in
     * flight, or not confirmed.
     *
     * @return array<int,true>
     */
    private static function unsettled(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $r) { $d = (int) ($r->donation_id ?? 0); if ($d > 0) $ids[$d] = true; }
        if (!$ids) return [];
        $cols = ['id', 'status'];
        foreach (['refunded_at', 'refund_requested_at'] as $c) if (SchemaHas::column('gates_donations', $c)) $cols[] = $c;
        $out = [];
        try {
            foreach (DB::table('gates_donations')->whereIn('id', array_keys($ids))->get($cols) as $d) {
                if ((string) ($d->status ?? '') !== 'confirmed' || ($d->refunded_at ?? null) !== null || ($d->refund_requested_at ?? null) !== null) {
                    $out[(int) $d->id] = true;
                }
            }
        } catch (\Throwable) {
            // Unreadable: count nothing paid rather than celebrate money that may be gone.
            foreach (array_keys($ids) as $d) $out[$d] = true;
        }
        return $out;
    }

    /**
     * The person who brought the most separate people to vote through their own link this
     * week, among the edition's nominees. Their NAME comes from the same consented vote rows
     * as everybody's — a link owner who never asked to be shown is "a supporter".
     *
     * @param array<string,array> $people
     */
    private static function mobiliser(int $cycleId, string $weekStart, array $people): ?array
    {
        if (!VoteRecruit::ready()) return null;
        try {
            $noms = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)->pluck('n.id')->map(fn ($v) => (int) $v)->all();
            if (!$noms) return null;
            $rows = DB::table('gates_vote_recruit_credits as k')
                ->join('gates_vote_recruits as r', 'r.id', '=', 'k.recruit_id')
                ->whereIn('r.nominee_id', $noms)->where('k.created_at', '>=', $weekStart)
                ->get(['r.email_hash', 'k.voter_hash', 'k.created_at']);
        } catch (\Throwable) {
            return null;
        }
        $by = [];
        foreach ($rows as $r) {
            $o = (string) $r->email_hash;
            $by[$o]['who'][(string) $r->voter_hash] = true;
            $by[$o]['last'] = max((string) ($by[$o]['last'] ?? ''), (string) $r->created_at);
        }
        if (!$by) return null;
        uasort($by, static fn ($a, $b) => count($b['who']) <=> count($a['who']) ?: strcmp($a['last'], $b['last']));
        $k = (string) array_key_first($by);
        return ['name' => $people[$k]['name'] ?? null, 'people' => count($by[$k]['who'])];
    }

    /** @param list<int> $ids @return array<int,string> */
    private static function nomineeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) return [];
        try {
            return DB::table('gates_nominees')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
