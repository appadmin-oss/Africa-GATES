<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\EditionName;
use AfricaGates\Support\Like;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\ProgrammeHost;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * RECOGNITIONS FROM VERIFIED ISSUERS — the one resolver (REFERENCE §11, GAPS §3.1, PHASE-6).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHERE THE RECORDS COME FROM, AND THE ONLY PLACE THEY MAY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The redesign draws "recognitions from verified issuers" on the home page, Discover and every
 * profile, and there was no table behind any of it. The owner (5 Oct 2026): "find a smart way
 * around it — build it". The way round is that this platform has already ISSUED recognitions
 * and kept the record: every award it announced is a sealed standing, written by
 * {@see SnapshotService::captureRelease()} in the act of announcing. So each WINNER, RUNNER-UP
 * and published-shortlist FINALIST of a sealed release becomes a recognition issued by that
 * award programme. The issuer is "verified" because the platform verified it in the strongest
 * sense available — it ran the count itself — and `verified_basis = 'platform_count'` says so.
 *
 * What is deliberately NOT a source, each because it would publish something nobody announced:
 *
 *  · a live recomputation — {@see ReleasedStanding} exists because one released nominee went
 *    693 → 885 with nothing edited. A recognition built from today's arithmetic is that fault
 *    with a certificate on it;
 *  · a cycle that is late, delayed or unreleased — a results DATE is a promise, not an
 *    announcement (CLAUDE.md), and only `results`/`archived` ({@see PublicResults::RELEASED})
 *    count. A seal without that status is not read;
 *  · a cycle released before sealing existed — there is no honest way to recover who was
 *    announced from routine captures, and this does not guess ({@see ReleasedStanding});
 *  · the sandbox — its programme is `is_active = 0`, and every reader here walks the issuer to
 *    its programme AND runs {@see DemoSeeder::liveAwardOnly()} on the recipient, because a
 *    lookup by id has no containment of its own.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IMMUTABLE, EXCEPT TO BE WITHDRAWN — AND EVERY WITHDRAWAL IS PUBLIC
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no update path. {@see withdraw()} is the only writer after issue, it touches only
 * `withdrawn_at`/`withdrawn_reason`, and it writes the public log row in the same transaction.
 * A withdrawn recognition leaves every count and list but stays in {@see withdrawals()} and on
 * its recipient's profile under "withdrawn", with the reason — a record that disappears is
 * history being edited. RecognitionsTest sweeps `src/` for any other writer.
 *
 * ── THE ROW SHAPE EVERY READER RETURNS ──────────────────────────────────────
 *
 *   id, reference, kind, standing, title, citation, issued_at, withdrawn_at, withdrawn_reason,
 *   recipient: {name, nominee_id, profile_id, url, photo, country}
 *   issuer:    {id, name, type, verified, verified_at, url, host}
 *   award:     {programme, category, edition, year, url}
 */
final class Recognitions
{
    /** REFERENCE §11, exactly. */
    public const KINDS = ['award', 'honour', 'commendation', 'staff', 'certificate'];

    /** REFERENCE §11, exactly. Government verification is manual: nothing here stamps one. */
    public const ISSUER_TYPES = ['community', 'organisation', 'business', 'government'];

    public const WINNER    = 'winner';
    public const RUNNER_UP = 'runner_up';
    public const FINALIST  = 'finalist';

    /** Standing → [kind, the words a reader sees]. One table, so a title and a kind cannot disagree. */
    public const STANDINGS = [
        self::WINNER    => ['award',        'Winner'],
        self::RUNNER_UP => ['honour',       'Runner-up'],
        self::FINALIST  => ['commendation', 'Finalist'],
    ];

    public const BASIS_PLATFORM_COUNT = 'platform_count';

    // ─────────────────────────────────────────────────────────────────────────
    // Readers. Every one is contained (active programme, no sandbox, public, not withdrawn
    // unless it says otherwise) and none throws: a missing table is an empty answer.
    // ─────────────────────────────────────────────────────────────────────────

    /** The newest recognitions on the platform, newest first. @return list<array<string,mixed>> */
    public static function recent(int $limit = 6): array
    {
        $q = self::base();
        if ($q === null) return [];
        return self::shape($q->select('r.id')->whereNull('r.withdrawn_at')
            ->orderByDesc('r.issued_at')->orderByDesc('r.id')
            ->limit(max(1, min(100, $limit)))->get()->all());
    }

    /**
     * Everything one registry profile has been given: by profile id, AND through every nominee
     * row that profile stood as (most awards were won before anybody registered).
     *
     * @return array{active:list<array<string,mixed>>, withdrawn:list<array<string,mixed>>}
     */
    public static function forProfile(int $profileId): array
    {
        if ($profileId < 1) return ['active' => [], 'withdrawn' => []];
        $q = self::base();
        if ($q === null) return ['active' => [], 'withdrawn' => []];
        $q->where(function ($w) use ($profileId) {
            $w->where('r.recipient_profile_id', $profileId)
              ->orWhereIn('r.recipient_nominee_id', function ($s) use ($profileId) {
                  $s->select('id')->from('gates_nominees')->where('profile_id', $profileId);
              });
        });
        return self::split($q);
    }

    /**
     * Everything one nominee row was given, including rows later merged INTO it — a merge moves
     * the person, so it moves what they were given.
     *
     * @return array{active:list<array<string,mixed>>, withdrawn:list<array<string,mixed>>}
     */
    public static function forNominee(int $nomineeId): array
    {
        if ($nomineeId < 1) return ['active' => [], 'withdrawn' => []];
        $q = self::base();
        if ($q === null) return ['active' => [], 'withdrawn' => []];
        $q->where(function ($w) use ($nomineeId) {
            $w->where('r.recipient_nominee_id', $nomineeId)
              ->orWhereIn('r.recipient_nominee_id', function ($s) use ($nomineeId) {
                  $s->select('id')->from('gates_nominees')->where('merged_into', $nomineeId);
              });
        });
        return self::split($q);
    }

    /**
     * Who recognises: every issuer with at least one standing recognition, most given first.
     *
     * @return list<array{id:int,name:string,type:string,verified:bool,verified_at:?string,url:string,host:?array,count:int,since:?string}>
     */
    public static function issuers(int $limit = 24): array
    {
        $q = self::base();
        if ($q === null) return [];
        try {
            $rows = $q->whereNull('r.withdrawn_at')
                ->groupBy('i.id', 'i.name', 'i.issuer_type', 'i.verified_at', 'i.url', 'i.programme_id', 'p.slug')
                ->orderByRaw('COUNT(r.id) DESC')->orderBy('i.name')
                ->limit(max(1, min(100, $limit)))
                ->get([
                    'i.id', 'i.name', 'i.issuer_type', 'i.verified_at', 'i.url', 'i.programme_id', 'p.slug',
                    DB::raw('COUNT(r.id) as n'), DB::raw('MIN(r.issued_at) as since'),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::issuerShape($r) + ['count' => (int) $r->n, 'since' => $r->since ? (string) $r->since : null];
        }
        return $out;
    }

    /** How many standing recognitions the public can see. */
    public static function count(): int
    {
        $q = self::base();
        if ($q === null) return 0;
        try {
            return (int) $q->whereNull('r.withdrawn_at')->count('r.id');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Search by recipient, title, issuer or reference. Wildcards escaped with `!` — the one
     * escape both drivers read the same way (CLAUDE.md, `Support\Like`).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $q, int $limit = 20): array
    {
        $term = trim($q);
        if ($term === '' || mb_strlen($term) > 120) return [];
        $b = self::base();
        if ($b === null) return [];
        $like = Like::contains($term);
        $b->whereNull('r.withdrawn_at')->where(function ($w) use ($like) {
            $w->whereRaw(Like::clause('r.recipient_name'), [$like])
              ->orWhereRaw(Like::clause('r.title'), [$like])
              ->orWhereRaw(Like::clause('i.name'), [$like])
              ->orWhereRaw(Like::clause('r.reference'), [$like]);
        });
        return self::shape($b->select('r.id')->orderByDesc('r.issued_at')->orderByDesc('r.id')
            ->limit(max(1, min(100, $limit)))->get()->all());
    }

    /**
     * The public withdrawal log, newest first.
     *
     * @return list<array{reference:string,recipient:string,title:string,issuer:string,reason:string,at:string}>
     */
    public static function withdrawals(int $limit = 50): array
    {
        if (!SchemaHas::table('gates_recognition_withdrawals')) return [];
        $q = self::base();
        if ($q === null) return [];
        try {
            $rows = $q->join('gates_recognition_withdrawals as w', 'w.recognition_id', '=', 'r.id')
                ->orderByDesc('w.withdrawn_at')->orderByDesc('w.id')
                ->limit(max(1, min(200, $limit)))
                ->get(['r.reference', 'r.recipient_name', 'r.title', 'i.name as issuer_name', 'w.reason', 'w.withdrawn_at'])
                ->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn ($r): array => [
            'reference' => (string) $r->reference, 'recipient' => (string) $r->recipient_name,
            'title' => (string) $r->title, 'issuer' => (string) $r->issuer_name,
            'reason' => (string) $r->reason, 'at' => (string) $r->withdrawn_at,
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Writers. Exactly two: issue from a sealed release, and withdraw.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * WITHDRAW — the only change a recognition may ever undergo. Refuses an empty reason (a
     * public log entry with no reason is an unexplained deletion) and a second withdrawal.
     */
    public static function withdraw(int $id, string $reason, ?int $adminId = null): bool
    {
        $reason = trim($reason);
        if ($id < 1 || $reason === '' || mb_strlen($reason) > 2000) return false;
        if (!SchemaHas::table('gates_recognitions')) return false;
        $now = date('Y-m-d H:i:s');
        return (bool) DB::transaction(static function () use ($id, $reason, $adminId, $now): bool {
            // The predicate is the claim: two presses cannot both log a withdrawal.
            $n = DB::table('gates_recognitions')->where('id', $id)->whereNull('withdrawn_at')
                ->update(['withdrawn_at' => $now, 'withdrawn_reason' => $reason]);
            if ($n < 1) return false;
            DB::table('gates_recognition_withdrawals')->insert([
                'recognition_id' => $id, 'reason' => $reason, 'withdrawn_at' => $now,
                // No admin 0: the FK sentinel trap (CLAUDE.md). Null means "not a session".
                'actor_admin_id' => ($adminId !== null && $adminId > 0) ? $adminId : null,
            ]);
            return true;
        });
    }

    /** Issue from every sealed, announced release. Idempotent. @return int recognitions written */
    public static function syncAll(): int
    {
        if (!SchemaHas::table('gates_recognitions')) return 0;
        try {
            $ids = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)
                ->whereIn('c.status', PublicResults::RELEASED)
                ->orderBy('c.id')->pluck('c.id')->all();
        } catch (\Throwable) {
            return 0;
        }
        $n = 0;
        foreach ($ids as $id) $n += self::syncCycle((int) $id);
        return $n;
    }

    /**
     * Issue the recognitions one sealed release earned. Idempotent through the UNIQUE
     * `reference`, so the seed, the release hook and a re-run all agree.
     *
     *   rank 1, in the running ............ winner     (award)
     *   rank 2, in the running ............ runner-up  (honour)
     *   on the category's PUBLISHED shortlist and in the sealed standing, neither of the
     *   above ............................. finalist   (commendation)
     *
     * A shortlist withdrawn after publication is not a finalist list. A nominee who was in the
     * running with no shortlist at all is not called a finalist: "finalist" is a word somebody
     * published, and this only repeats what was published.
     */
    public static function syncCycle(int $cycleId): int
    {
        if ($cycleId < 1 || !SchemaHas::table('gates_recognitions')) return 0;

        try {
            $cycle = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', $cycleId)->where('p.is_active', 1)
                ->whereIn('c.status', PublicResults::RELEASED)
                ->first(['c.*', 'p.id as programme_id', 'p.title as programme_title', 'p.slug as programme_slug']);
        } catch (\Throwable) {
            return 0;
        }
        if (!$cycle) return 0;

        ReleasedStanding::forget($cycleId);
        $sealed = ReleasedStanding::forCycle($cycleId);
        if ($sealed === null || $sealed['rows'] === []) return 0;

        $issuerId = self::issuerFor((int) $cycle->programme_id, (string) $cycle->programme_title);
        if ($issuerId < 1) return 0;

        $nominees = DB::table('gates_nominees as n')
            ->join('gates_award_categories as cat', 'cat.id', '=', 'n.category_id')
            ->whereIn('n.id', array_keys($sealed['rows']))
            ->get(['n.id', 'n.name', 'n.profile_id', 'n.category_id', 'cat.title as category_title'])
            ->keyBy('id');

        $finalists = [];
        if (SchemaHas::table('gates_shortlists') && SchemaHas::table('gates_shortlist_entries')) {
            $finalists = array_flip(array_map('intval', DB::table('gates_shortlist_entries as e')
                ->join('gates_shortlists as sl', 'sl.id', '=', 'e.shortlist_id')
                ->where('sl.cycle_id', $cycleId)->where('sl.status', 'published')
                ->pluck('e.nominee_id')->all()));
        }

        $edition = EditionName::full($cycle);
        $issued  = $sealed['at'] !== '' ? $sealed['at'] : date('Y-m-d H:i:s');
        $written = 0;

        foreach ($sealed['rows'] as $nid => $s) {
            $n = $nominees[$nid] ?? null;
            if ($n === null) continue;

            $in   = $s['in_running'] ?? ($s['rank'] !== null);
            $rank = $s['rank'];
            $standing = null;
            if ($in && $rank === 1)               $standing = self::WINNER;
            elseif ($in && $rank === 2)           $standing = self::RUNNER_UP;
            elseif (isset($finalists[(int) $nid])) $standing = self::FINALIST;
            if ($standing === null) continue;

            [$kind, $word] = self::STANDINGS[$standing];
            $ref = 'AGR-' . $cycleId . '-' . (int) $nid;

            try {
                $written += DB::table('gates_recognitions')->insertOrIgnore([
                    'issuer_id'            => $issuerId,
                    'recipient_profile_id' => $n->profile_id !== null ? (int) $n->profile_id : null,
                    'recipient_nominee_id' => (int) $nid,
                    'recipient_name'       => (string) $n->name,
                    'kind'                 => $kind,
                    'standing'             => $standing,
                    'title'                => $word . ' · ' . (string) $n->category_title,
                    'citation'             => sprintf('%s, %s. Decided by the sealed count the platform ran and announced.',
                                                     $edition, (string) $cycle->programme_title),
                    'issued_at'            => $issued,
                    'reference'            => $ref,
                    'visibility'           => 'public',
                    'evidence_ids'         => null,
                    'cycle_id'             => $cycleId,
                    'category_id'          => (int) $n->category_id,
                    'created_at'           => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
                // One bad row must not cost every other recognition in the release.
            }
        }
        return $written;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** The issuer row for a programme, created on first need and verified by the count. */
    private static function issuerFor(int $programmeId, string $title): int
    {
        $id = DB::table('gates_recognition_issuers')->where('programme_id', $programmeId)->value('id');
        if ($id) return (int) $id;
        $now = date('Y-m-d H:i:s');
        DB::table('gates_recognition_issuers')->insertOrIgnore([
            'issuer_type' => 'organisation', 'name' => $title, 'programme_id' => $programmeId,
            'verified_at' => $now, 'verified_basis' => self::BASIS_PLATFORM_COUNT, 'created_at' => $now,
        ]);
        return (int) DB::table('gates_recognition_issuers')->where('programme_id', $programmeId)->value('id');
    }

    /**
     * The contained base query, or null where the tables are not there yet. It selects
     * nothing: every join carries an `id`, so a caller names its columns (`r.id` for the
     * row readers, which {@see shape()} then completes) or the last join's id wins.
     */
    private static function base(): ?object
    {
        if (!SchemaHas::table('gates_recognitions') || !SchemaHas::table('gates_recognition_issuers')) return null;
        $q = DB::table('gates_recognitions as r')
            ->join('gates_recognition_issuers as i', 'i.id', '=', 'r.issuer_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'i.programme_id')
            ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'r.cycle_id')
            ->where('r.visibility', 'public')
            // An issuer that is a programme speaks only while that programme is live: the
            // sandbox's is not, and neither is a programme an operator has switched off.
            ->where(fn ($w) => $w->whereNull('i.programme_id')->orWhere('p.is_active', 1))
            // And the cycle must still be one the public may read.
            ->where(fn ($w) => $w->whereNull('r.cycle_id')->orWhereIn('cy.status', PublicResults::RELEASED));
        DemoSeeder::liveAwardOnly($q, 'r.recipient_nominee_id');
        return $q;
    }

    /** @return array{active:list<array<string,mixed>>, withdrawn:list<array<string,mixed>>} */
    private static function split(object $q): array
    {
        try {
            $rows = self::shape($q->select('r.id')->orderByDesc('r.issued_at')->orderByDesc('r.id')->get()->all());
        } catch (\Throwable) {
            return ['active' => [], 'withdrawn' => []];
        }
        $out = ['active' => [], 'withdrawn' => []];
        foreach ($rows as $r) $out[$r['withdrawn_at'] === null ? 'active' : 'withdrawn'][] = $r;
        return $out;
    }

    /** @param list<object> $rows @return list<array<string,mixed>> */
    private static function shape(array $rows): array
    {
        if ($rows === []) return [];
        $ids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) $r->id, $rows))));
        try {
            $detail = DB::table('gates_recognitions as r')
                ->join('gates_recognition_issuers as i', 'i.id', '=', 'r.issuer_id')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'i.programme_id')
                ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'r.cycle_id')
                ->leftJoin('gates_award_categories as cat', 'cat.id', '=', 'r.category_id')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'r.recipient_nominee_id')
                // The person's CURRENT registry profile: a nominee who registers after the
                // award is linked later, and their recognition should lead to the page
                // they now have rather than to the one they had on the night.
                ->leftJoin('gates_profiles as pr', function ($j) {
                    $j->on(DB::raw('COALESCE(n.profile_id, r.recipient_profile_id)'), '=', 'pr.id')
                      ->where('pr.status', '=', 'approved');
                })
                ->whereIn('r.id', $ids)
                ->get([
                    'r.*', 'i.name as issuer_name', 'i.issuer_type', 'i.verified_at', 'i.url as issuer_url',
                    'i.programme_id', 'p.slug', 'p.title as programme_title',
                    'cy.year', 'cy.edition_label', 'cy.edition_number', 'cy.id as cyid',
                    'cat.title as category_title', 'n.photo_path', 'n.country_code',
                    'pr.slug as profile_slug', 'pr.avatar_path', 'pr.id as profile_live_id',
                ])->keyBy('id');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $d = $detail[(int) $row->id] ?? null;
            if ($d === null) continue;
            $nid = $d->recipient_nominee_id !== null ? (int) $d->recipient_nominee_id : null;
            $url = $d->profile_slug ? '/registry/' . $d->profile_slug : ($nid ? NomineeUrl::path($nid) : '');
            $cycleObj = (object) ['year' => $d->year, 'edition_label' => $d->edition_label,
                                  'edition_number' => $d->edition_number, 'id' => $d->cyid,
                                  'programme_id' => $d->programme_id];
            $out[] = [
                'id'               => (int) $d->id,
                'reference'        => (string) $d->reference,
                'kind'             => (string) $d->kind,
                'standing'         => $d->standing !== null ? (string) $d->standing : null,
                'title'            => (string) $d->title,
                'citation'         => (string) ($d->citation ?? ''),
                'issued_at'        => $d->issued_at !== null ? (string) $d->issued_at : null,
                'withdrawn_at'     => $d->withdrawn_at !== null ? (string) $d->withdrawn_at : null,
                'withdrawn_reason' => $d->withdrawn_reason !== null ? (string) $d->withdrawn_reason : null,
                'recipient' => [
                    'name'       => (string) $d->recipient_name,
                    'nominee_id' => $nid,
                    'profile_id' => $d->profile_live_id !== null ? (int) $d->profile_live_id : null,
                    'url'        => $url,
                    'photo'      => (string) ($d->avatar_path ?: ($d->photo_path ?? '')),
                    'country'    => (string) ($d->country_code ?? ''),
                ],
                'issuer' => self::issuerShape((object) [
                    'id' => $d->issuer_id, 'name' => $d->issuer_name, 'issuer_type' => $d->issuer_type,
                    'verified_at' => $d->verified_at, 'url' => $d->issuer_url,
                    'programme_id' => $d->programme_id, 'slug' => $d->slug,
                ]),
                'award' => [
                    'programme' => (string) ($d->programme_title ?? ''),
                    'category'  => (string) ($d->category_title ?? ''),
                    'edition'   => $d->cyid ? EditionName::full($cycleObj) : '',
                    'year'      => $d->year !== null ? (int) $d->year : null,
                    // The award's own result page — the sealed standing this was issued from.
                    'url'       => $d->category_id ? '/results/' . PublicResults::slug((int) $d->category_id, (string) ($d->category_title ?? '')) : '',
                ],
            ];
        }
        return $out;
    }

    /** @return array{id:int,name:string,type:string,verified:bool,verified_at:?string,url:string,host:?array} */
    private static function issuerShape(object $r): array
    {
        $pid = $r->programme_id !== null ? (int) $r->programme_id : 0;
        return [
            'id'          => (int) $r->id,
            'name'        => (string) $r->name,
            'type'        => in_array((string) $r->issuer_type, self::ISSUER_TYPES, true) ? (string) $r->issuer_type : 'organisation',
            'verified'    => $r->verified_at !== null,
            'verified_at' => $r->verified_at !== null ? (string) $r->verified_at : null,
            'url'         => $r->slug ? '/awards/' . $r->slug : (string) ($r->url ?? ''),
            // The programme's own host credit, through the one resolver — never a second read.
            'host'        => $pid > 0 ? ProgrammeHost::forProgramme($pid) : null,
        ];
    }
}
