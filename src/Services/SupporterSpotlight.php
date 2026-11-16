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
    /** Everybody backing their own, drawn as a wall — the first this many, then "and N more". */
    public const WALL = 60;
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
        $empty = ['champion' => null, 'top' => [], 'mobiliser' => null, 'wall' => [], 'people' => 0, 'votes' => 0, 'paid' => 0];
        if ($cycleId < 1) return $empty;
        // The page never needs an address, so none is cached or handed to a template:
        // only SpotlightMail, which calls build() itself, ever holds one.
        $public = static function (array $s): array {
            foreach (['champion', 'mobiliser'] as $k) if (is_array($s[$k] ?? null)) unset($s[$k]['email']);
            foreach ($s['top'] as $i => $t) unset($s['top'][$i]['email']);
            foreach ($s['wall'] ?? [] as $i => $t) unset($s['wall'][$i]['email']);
            return $s;
        };
        if (self::$ttl < 1 || $now !== null) return $public(self::build($cycleId, $now));
        try {
            return (array) (new CacheService())->remember('spotlight:' . $cycleId, self::$ttl,
                static fn () => $public(self::build($cycleId, null)), ['spotlight']);
        } catch (\Throwable) {
            return $public(self::build($cycleId, null));
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

    /**
     * Where ONE supporter stands in the edition — for the moment right after they vote, which
     * is when being told "you are the 3rd biggest supporter of 25" means most. Ranked by the
     * same rule as the board: votes, then whoever got there first.
     *
     * A member's points and wallet votes are their own person key (`points:<id>`, VoterReach),
     * so with a `$userId` the stronger of their two keys is the one they are ranked by.
     *
     * @return array{votes:int, rank:int, people:int}
     */
    public static function standingOf(int $cycleId, string $email, ?int $userId = null): array
    {
        $out = ['votes' => 0, 'rank' => 0, 'people' => 0];
        $email = trim($email);
        if ($cycleId < 1 || $email === '') return $out;
        $me = VoteService::voterHash($email);
        $tot = []; $last = [];
        self::scan($cycleId, null, static function (string $key, object $r) use (&$tot, &$last): void {
            $tot[$key] = ($tot[$key] ?? 0) + max(1, (int) ($r->weight ?? 1));
            $at = (string) ($r->voted_at ?? '');
            if ($at > ($last[$key] ?? '')) $last[$key] = $at;
        });
        $out['people'] = count($tot);
        if ($userId !== null && $userId > 0 && ($tot['points:' . $userId] ?? 0) > ($tot[$me] ?? 0)) $me = 'points:' . $userId;
        if (!isset($tot[$me])) return $out;
        $out['votes'] = $tot[$me];
        $ahead = 0;
        foreach ($tot as $k => $n) {
            if ($k === $me) continue;
            if ($n > $tot[$me] || ($n === $tot[$me] && $last[$k] < $last[$me])) $ahead++;
        }
        $out['rank'] = $ahead + 1;
        return $out;
    }

    /** @return array{champion:?array, top:list<array>, mobiliser:?array, people:int, votes:int} */
    /**
     * EVERYBODY WHO BACKED SOMEBODY in the edition, as a person, with who they backed and how
     * they can be reached — for the messages that honour them after the fact (ResultsDayMail,
     * NomineeThanks). The same scan and the same person rule as the board, so "you were one of
     * 412" here is the board's 412. An address is a checkout's, or a member account's VERIFIED
     * one; a free voter with no account is a hash only, deliberately, and has none.
     * Never cached, never handed to a template: it holds addresses.
     *
     * @return array<string,array{by:array<int,int>, votes:int, email:string, name:?string, uid:int}>
     */
    public static function backers(int $cycleId): array
    {
        $all = [];
        self::scan($cycleId, null, static function (string $key, object $r) use (&$all): void {
            $w = max(1, (int) ($r->weight ?? 1));
            $p = $all[$key] ?? ['by' => [], 'votes' => 0, 'email' => '', 'name' => null, 'uid' => 0, 'nameId' => 0];
            if ($p['email'] === '' && ($r->_email ?? '') !== '') $p['email'] = (string) $r->_email;
            $nid = (int) $r->nominee_id;
            $p['by'][$nid] = ($p['by'][$nid] ?? 0) + $w;
            $p['votes'] += $w;
            // The newest name they ASKED to be shown under — the board's rule (forCycle), so a
            // non-member who ticked "show my name" is named here exactly as on the board.
            $name = trim((string) ($r->voter_name ?? ''));
            if ((int) ($r->show_name ?? 0) === 1 && $name !== '' && strcasecmp($name, 'Supporter') !== 0 && (int) $r->id > $p['nameId']) {
                $p['name'] = mb_substr($name, 0, 60);
                $p['nameId'] = (int) $r->id;
            }
            $all[$key] = $p;
        });
        if ($all === []) return [];
        $who = self::identities(array_map('strval', array_keys($all)), $all);
        foreach ($all as $k => $p) {
            $all[$k]['email'] = (string) ($who[(string) $k]['email'] ?? $p['email']);
            $all[$k]['name'] = $who[(string) $k]['name'] ?? null;
            $all[$k]['uid'] = (int) ($who[(string) $k]['uid'] ?? 0);
            unset($all[$k]['nameId']);
        }
        return $all;
    }

    /**
     * ONE NOMINEE'S BACKING, for their own page: how many people, how many votes, and how many
     * of those people came this week and last — counted by the board's person rule, so the
     * nominee's "412 people" is the board's 412. Each person counted in the week of their FIRST
     * vote for this nominee, so a supporter voting every day is one new supporter, once.
     *
     * @return array{people:int, votes:int, week:int, last_week:int}
     */
    public static function growthOf(int $nomineeId, ?\Illuminate\Support\Carbon $now = null): array
    {
        $out = ['people' => 0, 'votes' => 0, 'week' => 0, 'last_week' => 0];
        if ($nomineeId < 1) return $out;
        try {
            $cycle = (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
        } catch (\Throwable) {
            return $out;
        }
        $now ??= \Illuminate\Support\Carbon::now();
        $week = $now->copy()->subDays(7)->toDateTimeString();
        $fortnight = $now->copy()->subDays(14)->toDateTimeString();
        $first = []; $votes = [];
        self::scan($cycle, null, static function (string $key, object $r) use (&$first, &$votes): void {
            $at = (string) ($r->voted_at ?? '');
            if (!isset($first[$key]) || $at < $first[$key]) $first[$key] = $at;
            $votes[$key] = ($votes[$key] ?? 0) + max(1, (int) ($r->weight ?? 1));
        }, $nomineeId);
        foreach ($first as $at) {
            if ($at >= $week) $out['week']++;
            elseif ($at >= $fortnight) $out['last_week']++;
        }
        $out['people'] = count($first);
        $out['votes'] = array_sum($votes);
        return $out;
    }

    /** How many of a nominee's earliest backers are "the first supporters". */
    public const FIRST = 50;

    /**
     * THE FIRST SUPPORTERS of one nominee: the first FIRST people, by their first counted vote,
     * named where the board's own rule names them (an account, unless taken down; anybody else
     * only by consent). Rewards spotting somebody early, which money cannot buy: a person is in
     * by WHEN they first backed, never by how much. Cached five minutes.
     *
     * @return array{named:list<array{name:string,photo:?string,uid:int}>, count:int}
     */
    public static function firstBackers(int $nomineeId): array
    {
        $empty = ['named' => [], 'count' => 0];
        if ($nomineeId < 1) return $empty;
        $calc = static function () use ($nomineeId, $empty): array {
            try {
                $cycle = (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
            } catch (\Throwable) {
                return $empty;
            }
            $order = [];
            $people = [];
            // Rows come in id order, so the first time a person is seen is their first vote.
            self::scan($cycle, null, static function (string $key, object $r) use (&$order, &$people): void {
                if (isset($people[$key]) || count($order) >= self::FIRST) return;
                $people[$key] = ['by' => [], 'email' => (string) ($r->_email ?? ''), 'name' => (int) ($r->show_name ?? 0) === 1 && trim((string) ($r->voter_name ?? '')) !== ''
                    && strcasecmp(trim((string) $r->voter_name), 'Supporter') !== 0 ? mb_substr(trim((string) $r->voter_name), 0, 60) : null];
                $order[] = $key;
            }, $nomineeId);
            if ($order === []) return $empty;
            $who = self::identities($order, $people);
            $named = [];
            foreach ($order as $k) {
                $w = $who[$k] ?? null;
                if ($w && ($w['name'] ?? null) !== null) $named[] = ['name' => (string) $w['name'], 'photo' => $w['photo'] ?? null, 'uid' => (int) ($w['uid'] ?? 0)];
            }
            return ['named' => $named, 'count' => count($order)];
        };
        if (self::$ttl < 1) return $calc();
        try {
            return (array) (new CacheService())->remember('spotlight:first:' . $nomineeId, 300, $calc, ['spotlight']);
        } catch (\Throwable) {
            return $calc();
        }
    }

    public static function build(int $cycleId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $dayStart  = $now->copy()->setTimezone(self::ZONE)->startOfDay()->setTimezone(Clock::timezone())->toDateTimeString();
        $weekStart = $now->copy()->setTimezone(self::ZONE)->startOfWeek(Carbon::MONDAY)->setTimezone(Clock::timezone())->toDateTimeString();
        // An upper bound as well: SpotlightMail reads YESTERDAY's champion at its last second,
        // and a vote cast this morning must not crown somebody for a day already over.
        $upTo = $now->copy()->setTimezone(Clock::timezone())->toDateTimeString();

        /** @var array<string,array{votes:int,last:string,name:?string,nameId:int,by:array<int,int>}> $all */
        $all = [];
        /** @var array<string,array{votes:int,last:string}> $today */
        $today = [];
        $votes = 0; $paid = 0;

        self::scan($cycleId, null, static function (string $key, object $r) use (&$all, &$today, &$votes, &$paid, $dayStart, $upTo): void {
            if ((string) ($r->voted_at ?? '') > $upTo) return;
            $w = max(1, (int) ($r->weight ?? 1));
            $at = (string) ($r->voted_at ?? '');
            $votes += $w;
            if ((string) ($r->vote_type ?? '') === 'paid') $paid += $w;
            $p = $all[$key] ?? ['votes' => 0, 'last' => '', 'name' => null, 'nameId' => 0, 'by' => [], 'email' => ''];
            // The address they gave a checkout, where they gave one: how the Spotlight's
            // messages reach them, and how an account is recognised as theirs.
            if ($p['email'] === '' && ($r->_email ?? '') !== '') $p['email'] = (string) $r->_email;
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
        $order = static fn (array $a, array $b): int => $b['votes'] <=> $a['votes'] ?: strcmp($a['last'], $b['last']);

        $ranked = $all;
        uasort($ranked, $order);
        $topKeys = array_map('strval', array_keys(array_slice($ranked, 0, self::TOP, true)));
        $wallKeys = array_map('strval', array_keys(array_slice($ranked, 0, self::WALL, true)));
        uasort($today, $order);
        $champKey = array_key_first($today);
        $mob = self::mobiliser($cycleId, $weekStart, $upTo);

        // Who each person on the board IS — an account, a hidden request, a consented name —
        // resolved for the handful drawn, never for the whole edition.
        $who = self::identities(array_values(array_unique(array_filter(array_merge($wallKeys, [(string) $champKey, (string) ($mob['key'] ?? '')])))), $all);

        $card = static function (string $key, int $n) use (&$all, $names, $who): array {
            $p = $all[$key] ?? ['by' => []];
            arsort($p['by']);
            $for = (int) array_key_first($p['by']);
            $id = $who[$key] ?? ['name' => null, 'photo' => null, 'uid' => 0, 'email' => ''];
            return ['key' => $key, 'name' => $id['name'], 'photo' => $id['photo'], 'uid' => $id['uid'],
                    'email' => $id['email'], 'votes' => $n, 'for' => $names[$for] ?? null];
        };

        $top = [];
        foreach ($topKeys as $i => $k) $top[] = $card($k, (int) $ranked[$k]['votes']) + ['rank' => $i + 1];
        // The wall: EVERYBODY, not only the top — a celebration of supporters that celebrates
        // five of them tells the other thousand they did not count (owner: "this feels a bit
        // petty. The voters need to really feel good about themselves").
        $wall = [];
        foreach ($wallKeys as $k) {
            $c = $card($k, (int) $ranked[$k]['votes']);
            $wall[] = ['key' => $c['key'], 'name' => $c['name'], 'photo' => $c['photo'], 'uid' => $c['uid'], 'votes' => $c['votes']];
        }
        $champion = $champKey !== null ? $card((string) $champKey, $today[$champKey]['votes']) : null;
        $mobiliser = $mob ? ['people' => $mob['people']] + $card($mob['key'], 0) : null;

        return ['champion' => $champion, 'top' => $top, 'mobiliser' => $mobiliser, 'wall' => $wall,
                'people' => count($all), 'votes' => $votes, 'paid' => $paid];
    }

    /**
     * WHO A PERSON ON THE BOARD IS.
     *
     * ── AN ACCOUNT HOLDER IS SHOWN, AND MAY TAKE IT DOWN ─────────────────────
     * Owner, 16 Nov 2026: "if they have an account with their images, it should display,
     * they can request to take it down if they do not want". So a supporter whose votes
     * resolve to a member account is drawn with that account's own name and photo by
     * default — the profile they chose to present on this platform — and every message the
     * Spotlight sends them carries a one-tap link to be taken off it (SpotlightMail), as does
     * the board itself when they are signed in. Somebody WITHOUT an account is still named
     * only where they ticked "show my name" (SupportersService's rule, unchanged), because
     * nothing else they gave us was offered for publication.
     *
     * A person who asked to be taken down is anonymous everywhere, whatever else is true.
     *
     * @param list<string> $keys
     * @param array<string,array> $people
     * @return array<string,array{name:?string,photo:?string,uid:int,email:string}>
     */
    private static function identities(array $keys, array $people): array
    {
        $out = [];
        if (!$keys) return $out;
        $hidden = [];
        if (SchemaHas::table('gates_spotlight_hidden')) {
            try { $hidden = array_flip(DB::table('gates_spotlight_hidden')->whereIn('person_key', $keys)->pluck('person_key')->all()); }
            catch (\Throwable) { $hidden = []; }
        }
        $users = self::accounts($keys, $people);
        foreach ($keys as $k) {
            $u = $users[$k] ?? null;
            $email = (string) ($u->email ?? ($people[$k]['email'] ?? ''));
            if (isset($hidden[$k])) { $out[$k] = ['name' => null, 'photo' => null, 'uid' => (int) ($u->id ?? 0), 'email' => $email]; continue; }
            $name = $u ? trim((string) ($u->name ?? '')) : '';
            $photo = $u ? trim((string) (($u->photo_path ?? '') ?: ($u->avatar_path ?? ''))) : '';
            $out[$k] = [
                'name'  => $name !== '' ? mb_substr($name, 0, 60) : ($people[$k]['name'] ?? null),
                'photo' => $photo !== '' ? $photo : null,
                'uid'   => (int) ($u->id ?? 0),
                'email' => $email,
            ];
        }
        return $out;
    }

    /**
     * The member account behind each person key, where there is one: `points:<id>` names it
     * outright; otherwise an account whose VERIFIED address hashes to the key. Addresses a
     * checkout gave are looked up directly; a free voter is only a hash, so accounts are
     * hashed in chunks until every key is found or there are none left.
     *
     * @param list<string> $keys
     * @return array<string,object>
     */
    private static function accounts(array $keys, array $people): array
    {
        $cols = ['id', 'name', 'email'];
        foreach (['photo_path', 'avatar_path', 'email_verified', 'status'] as $c) if (SchemaHas::column('gates_users', $c)) $cols[] = $c;
        $ok = static fn (object $u): bool => (!property_exists($u, 'status') || in_array((string) $u->status, ['', 'active'], true))
            && (!property_exists($u, 'email_verified') || (int) $u->email_verified === 1);
        $out = [];
        try {
            $ids = [];
            foreach ($keys as $k) if (preg_match('/^points:(\d+)$/', $k, $m)) $ids[(int) $m[1]] = $k;
            if ($ids) foreach (DB::table('gates_users')->whereIn('id', array_keys($ids))->get($cols) as $u) if ($ok($u)) $out[$ids[(int) $u->id]] = $u;

            $want = array_flip(array_filter($keys, static fn ($k) => !isset($out[$k]) && preg_match('/^[a-f0-9]{64}$/', $k)));
            if (!$want) return $out;
            $emails = [];
            foreach (array_keys($want) as $k) if (($people[$k]['email'] ?? '') !== '') $emails[] = strtolower(trim($people[$k]['email']));
            if ($emails) {
                foreach (DB::table('gates_users')->whereIn(DB::raw('LOWER(email)'), $emails)->get($cols) as $u) {
                    $h = VoteService::voterHash((string) $u->email);
                    if (isset($want[$h]) && $ok($u)) { $out[$h] = $u; unset($want[$h]); }
                }
            }
            if ($want) {
                DB::table('gates_users')->orderBy('id')->select($cols)->chunk(self::CHUNK, static function ($rows) use (&$want, &$out, $ok) {
                    foreach ($rows as $u) {
                        $h = VoteService::voterHash((string) ($u->email ?? ''));
                        if (isset($want[$h]) && $ok($u)) { $out[$h] = $u; unset($want[$h]); }
                    }
                    return $want !== [];
                });
            }
        } catch (\Throwable) {
            // No account found is the safe answer: the person is drawn by consent alone.
        }
        return $out;
    }

    /** Take a person off the Spotlight, everywhere, for good. Idempotent. */
    public static function hide(string $key): bool
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 80 || !SchemaHas::table('gates_spotlight_hidden')) return false;
        try {
            DB::table('gates_spotlight_hidden')->insertOrIgnore(['person_key' => $key, 'created_at' => Carbon::now()->toDateTimeString()]);
            // Gone from the board on the next view, not a minute later.
            (new CacheService())->forgetByTag('spotlight');
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** The person keys a signed-in member votes as — their points key and their verified address, hashed as the ledger does. */
    public static function keysForUser(int $userId): array
    {
        $keys = [];
        if ($userId < 1) return $keys;
        $keys[] = 'points:' . $userId;
        try {
            $email = (string) DB::table('gates_users')->where('id', $userId)->value('email');
            if ($email !== '') $keys[] = VoteService::voterHash($email);
        } catch (\Throwable) {}
        return $keys;
    }

    /**
     * Every counted vote in the edition, handed to `$each` with its person. One pass, in
     * chunks, so a busy edition is never one array in memory.
     *
     * @param callable(string, object): void $each
     */
    private static function scan(int $cycleId, ?string $since, callable $each, ?int $nomineeId = null): void
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
            if ($nomineeId !== null) $q->where('nominee_id', $nomineeId);
            $q->orderBy('id')->select($cols)->chunk(self::CHUNK, static function ($rows) use ($each): void {
                $buyers = VoterReach::buyerHashes($rows);
                [$void, $emails] = self::unsettled($rows);
                foreach ($rows as $r) {
                    if ((int) ($r->fraud_flag ?? 0) !== 0) continue;
                    if (isset($void[(int) ($r->donation_id ?? 0)])) continue;
                    $r->_email = $emails[(int) ($r->donation_id ?? 0)] ?? '';
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
     * @return array{0:array<int,true>, 1:array<int,string>} the void orders, and each order's address
     */
    private static function unsettled(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $r) { $d = (int) ($r->donation_id ?? 0); if ($d > 0) $ids[$d] = true; }
        if (!$ids) return [[], []];
        $cols = ['id', 'status', 'donor_email'];
        foreach (['refunded_at', 'refund_requested_at'] as $c) if (SchemaHas::column('gates_donations', $c)) $cols[] = $c;
        $out = []; $mail = [];
        try {
            foreach (DB::table('gates_donations')->whereIn('id', array_keys($ids))->get($cols) as $d) {
                if ((string) ($d->status ?? '') !== 'confirmed' || ($d->refunded_at ?? null) !== null || ($d->refund_requested_at ?? null) !== null) {
                    $out[(int) $d->id] = true;
                }
                $mail[(int) $d->id] = trim((string) ($d->donor_email ?? ''));
            }
        } catch (\Throwable) {
            // Unreadable: count nothing paid rather than celebrate money that may be gone.
            foreach (array_keys($ids) as $d) $out[$d] = true;
        }
        return [$out, $mail];
    }

    /**
     * The person who brought the most separate people to vote through their own link this
     * week, among the edition's nominees. Their NAME comes from the same consented vote rows
     * as everybody's — a link owner who never asked to be shown is "a supporter".
     *
     * @return array{key:string, people:int}|null
     */
    private static function mobiliser(int $cycleId, string $weekStart, string $upTo): ?array
    {
        if (!VoteRecruit::ready()) return null;
        try {
            $noms = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)->pluck('n.id')->map(fn ($v) => (int) $v)->all();
            if (!$noms) return null;
            $rows = DB::table('gates_vote_recruit_credits as k')
                ->join('gates_vote_recruits as r', 'r.id', '=', 'k.recruit_id')
                ->whereIn('r.nominee_id', $noms)->where('k.created_at', '>=', $weekStart)->where('k.created_at', '<=', $upTo)
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
        return ['key' => $k, 'people' => count($by[$k]['who'])];
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
