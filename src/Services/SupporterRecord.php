<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\AwardCampaign;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "MY SUPPORT" — a member's record as a supporter, across every award and every year (owner,
 * 16 Nov 2026: "more features for voters … make them feel good"; "build them all").
 *
 * A vote used to be a one-off: cast, celebrated, gone. This is the record that builds — everybody
 * the member has backed, edition by edition, how each one turned out, the "I backed" card for
 * each, and badges that are EARNED, honestly, by time, judgement and people brought:
 *
 *   early      voted in the first 48 hours an award was open
 *   winner     backed somebody who won
 *   mobiliser  brought 10 or more people to vote through their own links (VoteRecruit)
 *   loyal      backed somebody in every edition of an award since their first, two or more
 *
 * NEVER by money. How much somebody gave is the reward ladder's business (AwardCampaign); a
 * badge here says what kind of supporter they are, and a bought one would say nothing.
 *
 * A member's votes are found the ways the platform can PROVE are theirs: a free vote hashed from
 * their account's address, an order paid from it (settled, not refunded), and their points
 * (`points:<id>:…`, VoterReach) and their wallet (`walletvote:<id>:…`). Fraud-flagged rows and the sandbox (inactive programmes) are not
 * theirs to be proud of.
 *
 * ── CERTIFICATES ─────────────────────────────────────────────────────────────────────────
 * An edition where, once voting closed, they were among the top 10 supporters (of more than
 * ten), or brought 10 or more people, earns a
 * printable certificate of community recognition ({@see self::certificateFor()}), with a code
 * anybody can check at /certificates/{code} — a certificate nobody can verify is decoration.
 */
final class SupporterRecord
{
    public const EARLY_HOURS = 48;
    public const MOBILISER = 10;
    public const CERT_TOP = 10;

    /**
     * Every badge, in order, with how it is earned — the record shows the ones not yet earned
     * too, because "what would it take" is half of why a badge means anything.
     */
    public const BADGES = [
        'early'     => ['Early backer', 'Vote in the first 48 hours an award is open.'],
        'winner'    => ['Backed a winner', 'Back somebody who goes on to win.'],
        'mobiliser' => ['Mobiliser', 'Bring 10 people to vote through your own link.'],
        'loyal'     => ['Every edition', 'Back somebody in every edition of an award, two or more.'],
    ];

    /**
     * @return array{editions:list<array>, backed:int, editions_n:int, brought:int, earned:int, badges:list<array{key:string,t:string,d:string,earned:bool}>}
     */
    public static function forMember(int $userId, string $email): array
    {
        $empty = ['editions' => [], 'backed' => 0, 'editions_n' => 0, 'brought' => 0, 'badges' => [], 'earned' => 0];
        $email = trim($email);
        if ($userId < 1 || $email === '') return $empty;
        $rows = self::votes($userId, $email);
        if ($rows === []) return $empty;
        $brought = self::brought($email);
        $short = [];
        $editions = [];
        $byProg = [];
        $badges = [];
        foreach ($rows as $r) {
            $cid = (int) $r->cycle_id;
            $short[$cid] ??= ShortlistService::shortlistedIn($cid);
            if (!isset($editions[$cid])) {
                $cycle = DB::table('gates_award_cycles')->where('id', $cid)->first();
                $camp = AwardCampaign::forProgramme((int) $r->programme_id);
                $editions[$cid] = ['cycle_id' => $cid, 'award' => (string) $r->programme, 'slug' => (string) $r->programme_slug,
                    'edition' => $cycle ? EditionName::label($cycle) : (string) $r->year, 'year' => (int) $r->year,
                    'place' => (string) $camp['community'], 'phase' => $cycle ? CyclePolicy::phaseFor($cycle)->value : '',
                    'opened' => (string) ($cycle->voting_open ?? ''), 'nominees' => [], 'votes' => 0];
                $byProg[(int) $r->programme_id][(int) $r->year] = true;
            }
            $status = (string) $r->status;
            $result = match (true) {
                $status === 'winner'                 => ['Won', 'win'],
                $status === 'runner_up'              => ['Runner-up', 'place'],
                isset($short[$cid][(int) $r->id])    => ['Shortlisted', 'place'],
                in_array($editions[$cid]['phase'], ['results', 'archived'], true) => ['Did not place', 'mute'],
                default                              => [$editions[$cid]['phase'] === 'voting' ? 'Voting open' : 'Being judged', 'live'],
            };
            $editions[$cid]['nominees'][] = ['id' => (int) $r->id, 'name' => (string) $r->name, 'category' => (string) $r->category,
                'votes' => (int) $r->votes, 'first_at' => (string) $r->first_at, 'result' => $result[0], 'tone' => $result[1],
                'url' => NomineeUrl::of((string) $r->programme_slug, (int) $r->id, (string) $r->name)];
            $editions[$cid]['votes'] += (int) $r->votes;

            if (!isset($badges['early']) && $editions[$cid]['opened'] !== '' && (string) $r->first_at !== ''
                && Carbon::parse((string) $r->first_at)->lessThanOrEqualTo(Carbon::parse($editions[$cid]['opened'])->addHours(self::EARLY_HOURS))) {
                $badges['early'] = ['key' => 'early', 't' => 'Early backer', 'd' => 'Backed ' . $r->name . ' in the first ' . self::EARLY_HOURS . ' hours of ' . $r->programme . '.'];
            }
            if (!isset($badges['winner']) && $status === 'winner') {
                $badges['winner'] = ['key' => 'winner', 't' => 'Backed a winner', 'd' => $r->name . ' won ' . $r->category . ' at ' . $r->programme . ' ' . $editions[$cid]['edition'] . '.'];
            }
        }
        if ($brought >= self::MOBILISER) $badges['mobiliser'] = ['key' => 'mobiliser', 't' => 'Mobiliser', 'd' => 'Brought ' . number_format($brought) . ' people to vote through your own links.'];
        foreach ($byProg as $pid => $years) {
            if (count($years) < 2) continue;
            $first = min(array_keys($years));
            try {
                $held = DB::table('gates_award_cycles')->where('programme_id', $pid)->where('year', '>=', $first)
                    ->where('voting_open', '<=', Carbon::now()->toDateTimeString())->distinct()->pluck('year')->map(fn ($y) => (int) $y)->all();
            } catch (\Throwable) {
                $held = [];
            }
            if ($held !== [] && array_diff($held, array_keys($years)) === []) {
                $name = (string) DB::table('gates_award_programmes')->where('id', $pid)->value('title');
                $badges['loyal'] = ['key' => 'loyal', 't' => 'Every edition', 'd' => 'Backed somebody in every edition of ' . $name . ' since ' . $first . '.'];
                break;
            }
        }
        usort($editions, static fn ($a, $b) => strcmp($b['opened'], $a['opened']));
        $backed = 0;
        foreach ($editions as $e) $backed += count($e['nominees']);
        foreach ($editions as $i => $e) {
            $editions[$i]['certificate'] = self::qualifies($userId, $email, (int) $e['cycle_id']) !== [];
            $editions[$i]['closed'] = self::closed((int) $e['cycle_id']);
        }
        $all = [];
        foreach (self::BADGES as $k => [$t, $how]) {
            $all[] = isset($badges[$k]) ? $badges[$k] + ['earned' => true] : ['key' => $k, 't' => $t, 'd' => $how, 'earned' => false];
        }
        return ['editions' => $editions, 'backed' => $backed, 'editions_n' => count($editions), 'brought' => $brought, 'badges' => $all,
                'earned' => count($badges)];
    }

    /** The nominees this member backed, with their own votes counted. @return list<object> */
    private static function votes(int $userId, string $email): array
    {
        try {
            $hash = VoteService::voterHash($email);
            $orders = DB::table('gates_donations')->whereRaw('LOWER(donor_email) = ?', [mb_strtolower($email)])->where('status', 'confirmed');
            if (SchemaHas::column('gates_donations', 'refunded_at')) $orders->whereNull('refunded_at');
            $ids = $orders->pluck('id')->map(fn ($v) => (int) $v)->all();
            $esc = static fn (string $p): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $p) . '%';
            $like = $esc('points:' . $userId . ':');
            $wallet = $esc(WalletVotes::HASH_PREFIX . $userId . ':');
            return DB::table('gates_votes as v')
                ->join('gates_nominees as n', 'n.id', '=', 'v.nominee_id')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
                ->where('p.is_active', 1)->where('v.fraud_flag', 0)
                ->where(static function ($q) use ($hash, $ids, $like, $wallet) {
                    $q->where('v.voter_email_hash', $hash)
                      ->orWhereRaw("v.voter_email_hash LIKE ? ESCAPE '!'", [$like])
                      ->orWhereRaw("v.voter_email_hash LIKE ? ESCAPE '!'", [$wallet]);
                    if ($ids !== []) $q->orWhereIn('v.donation_id', $ids);
                })
                ->groupBy('n.id', 'n.name', 'n.status', 'c.title', 'y.id', 'y.year', 'p.id', 'p.title', 'p.slug')
                ->get(['n.id', 'n.name', 'n.status', 'c.title as category', 'y.id as cycle_id', 'y.year', 'p.id as programme_id', 'p.title as programme',
                       'p.slug as programme_slug', DB::raw('SUM(v.weight) as votes'), DB::raw('MIN(v.voted_at) as first_at')])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** People this member brought to vote through their own links, across every nominee. */
    public static function brought(string $email, ?int $cycleId = null): int
    {
        if (!SchemaHas::table('gates_vote_recruits') || !SchemaHas::table('gates_vote_recruit_credits')) return 0;
        try {
            $q = DB::table('gates_vote_recruit_credits as k')->join('gates_vote_recruits as r', 'r.id', '=', 'k.recruit_id')
                ->where('r.email_hash', VoteService::voterHash($email));
            if ($cycleId !== null) {
                $q->join('gates_nominees as n', 'n.id', '=', 'r.nominee_id')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')->where('c.cycle_id', $cycleId);
            }
            return (int) $q->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Why this member earns a certificate for this edition: among the top supporters, and/or a
     * mobiliser. Empty when they do not.
     *
     * Only once voting has CLOSED: a place on a live board can still be taken, and a framed
     * "number 3" that ended the edition as number 14 is a certificate for something that did not
     * happen. And "top 10" needs more than ten people in it — number 1 of 1 is everybody.
     *
     * @return list<string>
     */
    public static function qualifies(int $userId, string $email, int $cycleId): array
    {
        if (!self::closed($cycleId)) return [];
        $why = [];
        $st = SupporterSpotlight::standingOf($cycleId, $email, $userId);
        if ($st['rank'] > 0 && $st['rank'] <= self::CERT_TOP && $st['people'] > self::CERT_TOP) {
            $why[] = 'Number ' . $st['rank'] . ' of ' . number_format($st['people']) . ' supporters';
        }
        $b = self::brought($email, $cycleId);
        if ($b >= self::MOBILISER) $why[] = 'Brought ' . number_format($b) . ' people to vote';
        return $why;
    }

    /** Has voting in this edition finished — judging, results or archived? */
    public static function closed(int $cycleId): bool
    {
        try {
            $c = DB::table('gates_award_cycles')->where('id', $cycleId)->first();
        } catch (\Throwable) {
            return false;
        }
        return $c !== null && in_array(CyclePolicy::phaseFor($c)->value, ['judging', 'results', 'archived'], true);
    }

    /** The code printed on a certificate, which /certificates/{code} checks. */
    public static function code(int $userId, int $cycleId): string
    {
        return $userId . '-' . $cycleId . '-' . substr(EmailOptOut::sign('cert:' . $userId . ':' . $cycleId, 'certificate'), 0, 12);
    }

    /**
     * Everything a certificate states, or null when it is not earned (or the code is forged).
     *
     * @return array{name:string, award:string, edition:string, place:string, host:string, why:list<string>, code:string, date:string}|null
     */
    public static function certificateFor(int $userId, int $cycleId): ?array
    {
        try {
            $u = DB::table('gates_users')->where('id', $userId)->where('status', 'active')->first(['id', 'name', 'email']);
            $c = DB::table('gates_award_cycles as y')->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
                ->where('y.id', $cycleId)->where('p.is_active', 1)->first(['y.*', 'p.id as programme_id', 'p.title as programme']);
        } catch (\Throwable) {
            return null;
        }
        if (!$u || !$c || trim((string) $u->name) === '') return null;
        $why = self::qualifies($userId, (string) $u->email, $cycleId);
        if ($why === []) return null;
        $host = \AfricaGates\Support\ProgrammeHost::forProgramme((int) $c->programme_id);
        $camp = AwardCampaign::forProgramme((int) $c->programme_id);
        return ['name' => (string) $u->name, 'award' => (string) $c->programme, 'edition' => EditionName::label($c), 'place' => (string) $camp['community'],
                'host' => (string) ($host['name'] ?? 'Africa GATES'), 'why' => $why, 'code' => self::code($userId, $cycleId),
                'date' => Carbon::now()->format('j F Y')];
    }

    /** A code from the URL, checked: [userId, cycleId] or null. */
    public static function verify(string $code): ?array
    {
        if (!preg_match('/^(\d+)-(\d+)-([a-f0-9]{12})$/', $code, $m)) return null;
        return hash_equals(self::code((int) $m[1], (int) $m[2]), $code) ? [(int) $m[1], (int) $m[2]] : null;
    }
}
