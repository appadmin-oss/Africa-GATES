<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\AwardService;
use AfricaGates\Services\NominationCategoryFit;
use AfricaGates\Services\NominationRules;
use AfricaGates\Services\NominationTriageService;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\Like;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;

/**
 * The nomination review list (AdminConsole v2, README §10b, screenshot 02): its tabs, the
 * checks on every row, the quick views, the facets, and one nomination's side peek.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CHECKS ARE THE ONES THIS PLATFORM CAN ACTUALLY RUN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The design draws three: a possible duplicate, a supporting file, and "Phone unverified".
 * Nothing here verifies a nominator's phone — there is no column for it and no step that
 * could fill one — so a pill reading "Phone unverified" would be on every row, true of
 * every nomination ever made, and would say nothing. What DOES stop an approval is the
 * eligibility rule (Settings → Nomination eligibility: nominations from N different
 * places), and until now a reviewer met it only as an error after pressing Approve. So
 * that check takes the third slot, and only while the rule is switched on.
 *
 * Each check has ONE definition, shared with the screen that acts on it:
 *
 *   · Duplicate? — another nomination in the same edition under the same name, by
 *     {@see NominationTriageService::nameKey()}, which is how the desk's own duplicate
 *     banner matches. The list must never flag what the desk then cannot find.
 *   · No evidence — no link and no file, counted from `gates_nomination_evidence` and the
 *     older `reference_url*` columns together, de-duplicated by address.
 *   · Needs N more places — {@see AwardService::eligibilityMany()}, the same query the
 *     Approve button runs.
 *   · The category flag — {@see NominationCategoryFit::suggestions()}.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO SHAPES OF TAB, AND WHY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "To review" and "Asked" are the QUEUE: work nobody has finished, small by nature, and
 * the quick views ("Ready to approve", "Needs a check" …) are questions about readiness.
 * Their checks are worked out for every row so the counts are true of the whole queue,
 * not of one page. "Approved" and "Rejected" are the RECORD — thousands of decided rows,
 * where "ready to approve" means nothing — so they page in SQL and only the page's rows
 * are checked. The queue is read in full up to {@see CAP} rows and says so past it.
 */
final class ReviewQueue
{
    /** Tab key (the stored status) => label. "Asked" is `needs_details`. */
    public const TABS = [
        NS::PENDING       => 'To review',
        NS::NEEDS_DETAILS => 'Asked',
        NS::APPROVED      => 'Approved',
        NS::REJECTED      => 'Rejected',
    ];

    /**
     * The stored words each tab reads. `verified` is the challenge pipeline's spelling of
     * the same moderator pass as `approved` (Support\NominationStatus), so the record of
     * approvals holds both; a list of one would hide every challenge nomination ever passed.
     */
    public const TAB_STATUSES = [
        NS::PENDING       => [NS::PENDING],
        NS::NEEDS_DETAILS => [NS::NEEDS_DETAILS],
        NS::APPROVED      => [NS::APPROVED, NS::VERIFIED],
        NS::REJECTED      => [NS::REJECTED],
    ];

    /** The tabs whose rows are work in progress. */
    public const QUEUE = [NS::PENDING, NS::NEEDS_DETAILS];

    /** Quick views, in the design's order. Their rules are in {@see inView()}. */
    public const VIEWS = [
        'all'   => 'All',
        'ready' => 'Ready to approve',
        'check' => 'Needs a check',
        'dup'   => 'Possible duplicate',
        'move'  => 'Category flagged',
    ];

    /** Check keys, as the Checks facet names them. */
    public const CHECKS = [
        'clear'     => 'All clear',
        'dup'       => 'Duplicate?',
        'evidence'  => 'No evidence',
        'locations' => 'Not yet eligible',
    ];

    public const PER = 50;
    public const CAP = 2000;

    /**
     * The request, normalised. Everything the page can be asked is here and nowhere else,
     * so a link the page builds and a link somebody pasted mean the same thing.
     *
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    public static function params(array $q): array
    {
        $list = static function (mixed $v): array {
            $v = is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]);
            $out = [];
            foreach ($v as $x) if (is_scalar($x) && ($x = trim((string) $x)) !== '') $out[] = mb_substr($x, 0, 80);
            return array_values(array_unique($out));
        };
        $tab = (string) ($q['tab'] ?? NS::PENDING);
        if (!isset(self::TABS[$tab])) $tab = NS::PENDING;
        $scope = (string) ($q['scope'] ?? '');
        if (!preg_match('/^[pc][0-9]+$/', $scope)) $scope = '';
        $sort = (string) ($q['sort'] ?? '');
        if (!in_array($sort, ['name', 'cat', 'recv'], true)) $sort = '';
        $dir = ($q['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $view = (string) ($q['view'] ?? 'all');
        if (!isset(self::VIEWS[$view])) $view = 'all';
        return [
            'tab'   => $tab,
            'q'     => mb_substr(trim((string) ($q['q'] ?? '')), 0, 80),
            'scope' => $scope,
            'cat'   => array_values(array_filter(array_map('intval', $list($q['cat'] ?? [])))),
            'chk'   => array_values(array_intersect($list($q['chk'] ?? []), array_keys(self::CHECKS))),
            'rel'   => $list($q['rel'] ?? []),
            'cc'    => array_map('strtoupper', $list($q['cc'] ?? [])),
            'view'  => in_array($tab, self::QUEUE, true) ? $view : 'all',
            'sort'  => $sort,
            'dir'   => $sort === '' ? 'desc' : $dir,
            'page'  => max(1, (int) ($q['page'] ?? 1)),
            // The address calls it `item`, not `open`: production's request filter answered
            // `?open=510` with a 406 before PHP saw it, so the peek and its plain-link
            // fallback both died. `open` is still read, for a link somebody kept.
            'open'  => max(0, (int) ($q['item'] ?? $q['open'] ?? 0)),
        ];
    }

    /** The query string for a state, dropping defaults so links stay short. */
    public static function qs(array $p, array $change = []): string
    {
        $p = $change + $p;
        $out = [];
        if ($p['tab'] !== NS::PENDING) $out['tab'] = $p['tab'];
        foreach (['q', 'scope'] as $k) if ($p[$k] !== '') $out[$k] = $p[$k];
        foreach (['cat', 'chk', 'rel', 'cc'] as $k) if ($p[$k] !== []) $out[$k] = array_values($p[$k]);
        if ($p['view'] !== 'all') $out['view'] = $p['view'];
        if ($p['sort'] !== '') { $out['sort'] = $p['sort']; $out['dir'] = $p['dir']; }
        if ($p['page'] > 1) $out['page'] = $p['page'];
        if (!empty($p['open'])) $out['item'] = $p['open'];
        $s = http_build_query($out);
        return '/admin/nominations/review' . ($s !== '' ? '?' . preg_replace('/%5B[0-9]+%5D/', '%5B%5D', $s) : '');
    }

    /**
     * Everything the list draws.
     *
     * @param array<string,mixed> $p from {@see params()}
     * @return array<string,mixed>
     */
    public static function build(array $p, ?AwardService $awards = null): array
    {
        $isQueue = in_array($p['tab'], self::QUEUE, true);
        $hasRel = SchemaHas::column('gates_nominations', 'nominator_relation');

        // Tab counts follow the scope (the programme select is a real filter), not the
        // search: a tab's count answers "how much is there", and a search is a question
        // asked of one tab.
        $tabs = [];
        foreach (self::TABS as $k => $label) {
            $n = 0;
            try { $n = (int) self::scoped($p)->whereIn('n.status', self::TAB_STATUSES[$k])->count(); } catch (\Throwable) {}
            $tabs[] = ['key' => $k, 'label' => $label, 'n' => $n, 'on' => $k === $p['tab'],
                       'href' => self::qs($p, ['tab' => $k, 'page' => 1, 'view' => 'all', 'chk' => [], 'open' => 0])];
        }

        $base = self::scoped($p)->whereIn('n.status', self::TAB_STATUSES[$p['tab']]);
        self::search($base, $p['q']);
        // Ordering goes on the ROW queries only. A count or a GROUP BY carrying
        // `ORDER BY n.id` is error 1140 on MySQL under ONLY_FULL_GROUP_BY, and SQLite
        // runs it happily — the suite would pass a page production cannot draw.

        $capped = false;
        if ($isQueue) {
            $rq = clone $base;
            self::order($rq, $p);
            $all = self::rows($rq->limit(self::CAP + 1));
            if (count($all) > self::CAP) { $capped = true; array_pop($all); }
            $all = self::annotate($all, $awards);
            $facets = self::facets($all, $p, $hasRel, true);
            $faceted = array_values(array_filter($all, static fn (array $r): bool => self::inFacets($r, $p)));
            $views = [];
            foreach (self::VIEWS as $k => $label) {
                $n = count(array_filter($faceted, static fn (array $r): bool => self::inView($r, $k)));
                if ($k !== 'all' && $n === 0 && $p['view'] !== $k) continue;
                $views[] = ['key' => $k, 'label' => $label, 'n' => $n, 'on' => $p['view'] === $k,
                            'tone' => ['all' => '', 'ready' => 'green', 'check' => 'gold', 'dup' => 'red', 'move' => 'gold'][$k],
                            'href' => self::qs($p, ['view' => ($p['view'] === $k && $k !== 'all') ? 'all' : $k, 'page' => 1, 'open' => 0])];
            }
            $shown = array_values(array_filter($faceted, static fn (array $r): bool => self::inView($r, $p['view'])));
            $total = count($shown);
            $pages = max(1, (int) ceil($total / self::PER));
            $page = min($p['page'], $pages);
            $rows = array_slice($shown, ($page - 1) * self::PER, self::PER);
            $baseTotal = count($all);
        } else {
            $facets = self::facets([], $p, $hasRel, false, clone $base);
            $q = clone $base;
            if ($p['cat'] !== []) $q->whereIn('n.category_id', $p['cat']);
            if ($p['rel'] !== [] && $hasRel) $q->whereIn('n.nominator_relation', $p['rel']);
            if ($p['cc'] !== []) $q->whereIn('n.country_code', $p['cc']);
            $baseTotal = 0;
            try { $baseTotal = (int) (clone $base)->count(); $total = (int) (clone $q)->count(); } catch (\Throwable) { $total = 0; }
            $pages = max(1, (int) ceil($total / self::PER));
            $page = min($p['page'], $pages);
            self::order($q, $p);
            $rows = self::annotate(self::rows($q->offset(($page - 1) * self::PER)->limit(self::PER)), $awards);
            $views = [];
        }

        foreach ($rows as $i => $r) $rows[$i]['open_href'] = self::qs($p, ['open' => (int) $r['id'], 'page' => $page]);
        $from = $total === 0 ? 0 : ($page - 1) * self::PER + 1;
        return [
            'p'          => $p,
            'is_queue'   => $isQueue,
            'tabs'       => $tabs,
            'views'      => $views,
            'facets'     => $facets,
            'chips'      => self::chips($p, $facets),
            'suggest'    => self::filterInstead($p, $facets),
            'rows'       => $rows,
            'total'      => $total,
            'base_total' => $baseTotal,
            'capped'     => $capped,
            'filtered'   => $p['q'] !== '' || $p['view'] !== 'all' || $p['cat'] || $p['chk'] || $p['rel'] || $p['cc'],
            'page'       => $page,
            'pages'      => $pages,
            'from'       => $from,
            'to'         => min($total, $page * self::PER),
            'prev'       => $page > 1 ? self::qs($p, ['page' => $page - 1, 'open' => 0]) : null,
            'next'       => $page < $pages ? self::qs($p, ['page' => $page + 1, 'open' => 0]) : null,
            'sorts'      => self::sortLinks($p),
            'scopes'     => self::scopes(),
            'scope'      => self::scopeLine($p['scope']),
            'export'     => '/admin/nominations/review/export.csv' . substr(self::qs($p, ['page' => 1, 'open' => 0]), strlen('/admin/nominations/review')),
            'clear_q'    => self::qs($p, ['q' => '', 'page' => 1, 'open' => 0]),
            'clear'      => self::qs($p, ['q' => '', 'view' => 'all', 'cat' => [], 'chk' => [], 'rel' => [], 'cc' => [], 'page' => 1, 'open' => 0]),
        ];
    }

    /**
     * The review desk's queue: what is waiting, oldest first, in the list's scope and
     * quick view. The same rows the list's To review tab would show, so a nomination is
     * "up next" on the desk exactly when it is waiting on the list.
     *
     * @return list<array<string,mixed>>
     */
    public static function deskQueue(array $p, ?AwardService $awards = null): array
    {
        $q = self::scoped($p)->where('n.status', NS::PENDING);
        $q->orderBy('n.created_at')->orderBy('n.id');
        try {
            $rows = self::annotate(self::rows($q->limit(self::CAP)), $awards);
        } catch (\Throwable $e) {
            error_log('[review-desk] queue: ' . $e->getMessage());
            return [];
        }
        return array_values(array_filter($rows, static fn (array $r): bool => self::inView($r, $p['view'])));
    }

    /**
     * Every row the list would show for an export: the same filters, no page.
     *
     * @param list<int> $ids when set, only these (the bulk bar's "Export")
     * @return list<array<string,mixed>>
     */
    public static function exportRows(array $p, ?AwardService $awards, array $ids = []): array
    {
        $base = self::scoped($p)->whereIn('n.status', self::TAB_STATUSES[$p['tab']]);
        self::search($base, $p['q']);
        self::order($base, $p);
        if ($ids !== []) $base->whereIn('n.id', $ids);
        $rows = self::annotate(self::rows($base->limit(self::CAP)), $awards);
        return array_values(array_filter($rows, static fn (array $r): bool => self::inFacets($r, $p) && self::inView($r, $p['view'])));
    }

    // ── the query ────────────────────────────────────────────────────────────

    private static function scoped(array $p): Builder
    {
        $q = DB::table('gates_nominations as n')
            ->leftJoin('gates_award_categories as cat', 'cat.id', '=', 'n.category_id')
            ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'n.cycle_id')
            ->leftJoin('gates_award_programmes as pr', 'pr.id', '=', 'c.programme_id');
        if ($p['scope'] !== '') {
            $id = (int) substr($p['scope'], 1);
            $p['scope'][0] === 'p' ? $q->where('c.programme_id', $id) : $q->where('n.cycle_id', $id);
        }
        return $q;
    }

    private static function search(Builder $q, string $term): void
    {
        if ($term === '') return;
        $like = Like::contains($term);
        $q->where(static function (Builder $x) use ($like): void {
            foreach (['n.nominee_name', 'n.nominator_name', 'n.nominator_email', 'n.reference', 'cat.title'] as $col) {
                $x->orWhereRaw(Like::clause($col), [$like]);
            }
            if (SchemaHas::column('gates_nominations', 'nominee_org')) $x->orWhereRaw(Like::clause('n.nominee_org'), [$like]);
        });
    }

    private static function order(Builder $q, array $p): void
    {
        $dir = $p['dir'];
        match ($p['sort']) {
            'name'  => $q->orderBy('n.nominee_name', $dir),
            'cat'   => $q->orderBy('cat.title', $dir),
            default => null,
        };
        // Received is the submission time, newest first unless asked; the id breaks a tie,
        // so two rows stamped in one second keep one order across pages.
        $rd = $p['sort'] === 'recv' ? $dir : 'desc';
        $q->orderBy('n.created_at', $rd)->orderBy('n.id', $rd);
    }

    /** @return list<array<string,mixed>> */
    private static function rows(Builder $q): array
    {
        $cols = ['n.id', 'n.cycle_id', 'n.category_id', 'n.nominee_name', 'n.country_code', 'n.nominee_state',
                 'n.nominator_name', 'n.status', 'n.created_at', 'n.reference', 'n.reason',
                 'cat.title as category_title', 'pr.title as programme_title', 'c.year as cycle_year'];
        foreach (['nominee_org', 'nominator_relation', 'nominee_photo_path', 'reference_url', 'reference_url_2', 'reference_url_3',
                  'nominator_email', 'asked_message', 'asked_at', 'merged_into'] as $c) {
            if (SchemaHas::column('gates_nominations', $c)) $cols[] = 'n.' . $c;
        }
        try {
            return $q->get($cols)->map(static fn (object $r): array => (array) $r)->all();
        } catch (\Throwable $e) {
            error_log('[review-queue] ' . $e->getMessage());
            return [];
        }
    }

    // ── the checks ───────────────────────────────────────────────────────────

    /**
     * Each row with its checks, worked out in a handful of queries for the whole batch.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function annotate(array $rows, ?AwardService $awards = null): array
    {
        if ($rows === []) return [];
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $cycles = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['cycle_id'], $rows)));

        // Duplicates: every name in the rows' editions, grouped by the desk's own key.
        // A reviewer's "Not a duplicate" ruling (the desk) takes that entry out of every
        // group, its own and the earlier one's — or the same pair is flagged for ever.
        $byKey = [];
        $ruledApart = [];
        try {
            $ruled = SchemaHas::column('gates_nominations', 'not_duplicate');
            foreach (DB::table('gates_nominations')->whereIn('cycle_id', $cycles)
                         ->get(array_merge(['id', 'cycle_id', 'nominee_name'], $ruled ? ['not_duplicate'] : [])) as $o) {
                if ($ruled && (int) ($o->not_duplicate ?? 0) === 1) { $ruledApart[(int) $o->id] = true; continue; }
                $byKey[(int) $o->cycle_id . '|' . NominationTriageService::nameKey((string) $o->nominee_name)][] = (int) $o->id;
            }
        } catch (\Throwable) {}

        // Evidence: files and links, plus the older reference columns, by distinct address.
        $ev = [];
        try {
            if (SchemaHas::table('gates_nomination_evidence')) {
                foreach (DB::table('gates_nomination_evidence')->whereIn('nomination_id', $ids)->get(['nomination_id', 'url', 'path']) as $e) {
                    $addr = trim((string) ($e->url ?: $e->path));
                    if ($addr !== '') $ev[(int) $e->nomination_id][$addr] = true;
                }
            }
        } catch (\Throwable) {}

        $filed = [];
        foreach ($rows as $r) $filed[(int) $r['id']] = (int) ($r['category_id'] ?? 0);
        $sugg = NominationCategoryFit::suggestions($filed);

        $elig = [];
        if ($awards) {
            try {
                $elig = $awards->eligibilityMany(array_map(static fn (array $r): array => [(string) $r['nominee_name'], (int) $r['cycle_id']], $rows));
            } catch (\Throwable) {}
        }

        $rel = NominationRules::RELATIONS;
        $now = time();
        foreach ($rows as $i => $r) {
            $id = (int) $r['id'];
            foreach (['reference_url', 'reference_url_2', 'reference_url_3'] as $c) {
                $u = trim((string) ($r[$c] ?? ''));
                if ($u !== '') $ev[$id][$u] = true;
            }
            $others = isset($ruledApart[$id]) ? []
                : array_values(array_diff($byKey[(int) $r['cycle_id'] . '|' . NominationTriageService::nameKey((string) $r['nominee_name'])] ?? [], [$id]));
            $e = $elig[AwardService::eligibilityKey((string) $r['nominee_name'], (int) $r['cycle_id'])] ?? null;
            $short = ($e && $e['enabled'] && !$e['eligible']) ? max(1, (int) $e['min'] - (int) $e['distinct_locations']) : 0;

            $checks = [];
            if ($others) $checks[] = ['key' => 'dup', 't' => 'Duplicate?', 'tone' => 'red'];
            if (empty($ev[$id])) $checks[] = ['key' => 'evidence', 't' => 'No evidence', 'tone' => 'gold'];
            if ($short > 0) $checks[] = ['key' => 'locations', 'tone' => 'gold',
                                         't' => 'Needs ' . $short . ' more place' . ($short === 1 ? '' : 's')];
            if (!$checks) $checks[] = ['key' => 'clear', 't' => 'All clear', 'tone' => 'green'];

            $at = strtotime((string) $r['created_at']) ?: $now;
            $relKey = (string) ($r['nominator_relation'] ?? '');
            $rows[$i] = $r + [
                'name'      => (string) $r['nominee_name'],
                'org'       => trim((string) ($r['nominee_org'] ?? '')),
                'initials'  => self::initials((string) $r['nominee_name']),
                'tone'      => ['green', 'gold', 'slate', 'red'][crc32((string) $r['nominee_name']) % 4],
                'category'  => (string) ($r['category_title'] ?? ''),
                'edition'   => trim((string) ($r['programme_title'] ?? '') . ' · ' . (string) ($r['cycle_year'] ?? ''), ' ·'),
                'by'        => (string) ($r['nominator_name'] ?? ''),
                'rel_key'   => $relKey,
                'rel'       => $rel[$relKey] ?? '',
                'checks'    => $checks,
                'keys'      => array_column($checks, 'key'),
                'dup_of'    => $others,
                'evidence'  => count($ev[$id] ?? []),
                'eligibility' => $e,
                'suggest'   => $sugg[$id] ?? null,
                'recv'      => self::ago($now - $at, $at),
                'recv_at'   => date('D j M Y, H:i', $at),
                'status_label' => NS::label((string) $r['status']),
            ];
        }
        return $rows;
    }

    /** The quick view rules (README §10b "Nominations quick views"). */
    public static function inView(array $r, string $view): bool
    {
        $dup = in_array('dup', $r['keys'], true);
        return match ($view) {
            'ready' => !$dup && $r['evidence'] > 0 && $r['suggest'] === null && !in_array('locations', $r['keys'], true),
            'check' => $r['evidence'] === 0 || in_array('locations', $r['keys'], true),
            'dup'   => $dup,
            'move'  => $r['suggest'] !== null,
            default => true,
        };
    }

    private static function inFacets(array $r, array $p): bool
    {
        if ($p['cat'] && !in_array((int) $r['category_id'], $p['cat'], true)) return false;
        if ($p['chk'] && !array_intersect($p['chk'], $r['keys'])) return false;
        if ($p['rel'] && !in_array($r['rel_key'], $p['rel'], true)) return false;
        if ($p['cc'] && !in_array(strtoupper((string) $r['country_code']), $p['cc'], true)) return false;
        return true;
    }

    // ── facets, chips, "filter instead" ──────────────────────────────────────

    /**
     * Facets built from the data, never a fixed list, sorted by count, each option with
     * its count from the list BEFORE facets apply — so a value never shows 0 because a
     * sibling filter removed it.
     *
     * @return list<array{key:string,label:string,opts:list<array<string,mixed>>}>
     */
    private static function facets(array $rows, array $p, bool $hasRel, bool $isQueue, ?Builder $base = null): array
    {
        $tally = ['cat' => [], 'chk' => [], 'rel' => [], 'cc' => []];
        $labels = ['cat' => [], 'rel' => NominationRules::RELATIONS, 'cc' => [], 'chk' => self::CHECKS];
        if ($isQueue) {
            foreach ($rows as $r) {
                $c = (int) $r['category_id'];
                if ($c > 0) { $tally['cat'][$c] = ($tally['cat'][$c] ?? 0) + 1; $labels['cat'][$c] = $r['category'] ?: 'Category #' . $c; }
                foreach ($r['keys'] as $k) $tally['chk'][$k] = ($tally['chk'][$k] ?? 0) + 1;
                if ($r['rel_key'] !== '') $tally['rel'][$r['rel_key']] = ($tally['rel'][$r['rel_key']] ?? 0) + 1;
                $cc = strtoupper((string) $r['country_code']);
                if ($cc !== '') $tally['cc'][$cc] = ($tally['cc'][$cc] ?? 0) + 1;
            }
        } elseif ($base) {
            try {
                foreach ((clone $base)->whereNotNull('n.category_id')->groupBy('n.category_id', 'cat.title')
                             ->get([DB::raw('n.category_id as k'), DB::raw('cat.title as t'), DB::raw('COUNT(*) as n')]) as $g) {
                    $tally['cat'][(int) $g->k] = (int) $g->n;
                    $labels['cat'][(int) $g->k] = (string) ($g->t ?? 'Category #' . $g->k);
                }
                if ($hasRel) {
                    foreach ((clone $base)->whereNotNull('n.nominator_relation')->groupBy('n.nominator_relation')
                                 ->get([DB::raw('n.nominator_relation as k'), DB::raw('COUNT(*) as n')]) as $g) $tally['rel'][(string) $g->k] = (int) $g->n;
                }
                foreach ((clone $base)->whereNotNull('n.country_code')->where('n.country_code', '!=', '')->groupBy('n.country_code')
                             ->get([DB::raw('n.country_code as k'), DB::raw('COUNT(*) as n')]) as $g) $tally['cc'][strtoupper((string) $g->k)] = (int) $g->n;
            } catch (\Throwable) {}
        }
        foreach ($tally['cc'] as $k => $_) $labels['cc'][$k] = $k;

        $defs = ['cat' => 'Category', 'chk' => 'Checks', 'rel' => 'Nominated by', 'cc' => 'Country'];
        $out = [];
        foreach ($defs as $key => $label) {
            if ($key === 'chk' && !$isQueue) continue;
            $counts = $tally[$key];
            foreach ($p[$key] as $v) if (!isset($counts[$v])) $counts[$v] = 0;   // a selected value stays visible
            arsort($counts);
            $opts = [];
            foreach ($counts as $v => $n) {
                $v = $key === 'cat' ? (int) $v : (string) $v;
                $on = in_array($v, $p[$key], true);
                $opts[] = [
                    'v' => $v, 'label' => (string) ($labels[$key][$v] ?? $v), 'n' => $n, 'on' => $on,
                    'tone' => $key === 'chk' ? ['clear' => 'green', 'dup' => 'red', 'evidence' => 'gold', 'locations' => 'gold'][$v] ?? '' : '',
                    'only' => self::qs($p, [$key => [$v], 'page' => 1, 'open' => 0]),
                ];
            }
            if ($opts) $out[] = ['key' => $key, 'label' => $label, 'opts' => $opts];
        }
        return $out;
    }

    /** @return list<array{k:string,v:string,href:string}> */
    private static function chips(array $p, array $facets): array
    {
        $out = [];
        if ($p['scope'] !== '') $out[] = ['k' => 'Scope', 'v' => self::scopeLine($p['scope']), 'href' => self::qs($p, ['scope' => '', 'page' => 1, 'open' => 0])];
        foreach ($facets as $f) {
            foreach ($f['opts'] as $o) {
                if (!$o['on']) continue;
                $out[] = ['k' => $f['label'], 'v' => $o['label'],
                          'href' => self::qs($p, [$f['key'] => array_values(array_diff($p[$f['key']], [$o['v']])), 'page' => 1, 'open' => 0])];
            }
        }
        if ($p['q'] !== '') $out[] = ['k' => 'Search', 'v' => $p['q'], 'href' => self::qs($p, ['q' => '', 'page' => 1, 'open' => 0])];
        return $out;
    }

    /**
     * "Filter instead": a search of three or more characters that names a facet value is
     * offered as that filter, because a search for "STEM" matches nominees whose
     * nomination happens to mention it, and the reviewer almost always meant the category.
     *
     * @return list<array{k:string,v:string,n:int,href:string}>
     */
    private static function filterInstead(array $p, array $facets): array
    {
        if (mb_strlen($p['q']) < 3) return [];
        $q = mb_strtolower($p['q']);
        $out = [];
        foreach ($facets as $f) {
            foreach ($f['opts'] as $o) {
                if ($o['on'] || $o['n'] < 1 || !str_contains(mb_strtolower($o['label']), $q)) continue;
                $out[] = ['k' => $f['label'], 'v' => $o['label'], 'n' => $o['n'],
                          'href' => self::qs($p, ['q' => '', $f['key'] => array_values(array_merge($p[$f['key']], [$o['v']])), 'page' => 1, 'open' => 0])];
                if (count($out) >= 4) return $out;
            }
        }
        return $out;
    }

    /** @return array<string,array{href:string,state:string}> header links: off → ↑ → ↓ → off */
    private static function sortLinks(array $p): array
    {
        $out = [];
        foreach (['name', 'cat', 'recv'] as $col) {
            $on = $p['sort'] === $col;
            $state = $on ? $p['dir'] : 'off';
            $next = !$on ? ['sort' => $col, 'dir' => 'asc'] : ($p['dir'] === 'asc' ? ['sort' => $col, 'dir' => 'desc'] : ['sort' => '', 'dir' => 'desc']);
            $out[$col] = ['state' => $state, 'href' => self::qs($p, $next + ['page' => 1, 'open' => 0])];
        }
        return $out;
    }

    // ── scope ────────────────────────────────────────────────────────────────

    /** Programmes with their editions, for the scope select. @return list<array<string,mixed>> */
    public static function scopes(): array
    {
        try {
            $cycles = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->orderBy('p.title')->orderByDesc('c.year')->get(['c.*', 'p.title as programme']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($cycles as $c) {
            $pid = (int) $c->programme_id;
            $out[$pid] ??= ['value' => 'p' . $pid, 'label' => (string) $c->programme, 'editions' => []];
            $out[$pid]['editions'][] = ['value' => 'c' . (int) $c->id, 'label' => EditionName::full($c)];
        }
        return array_values($out);
    }

    /** "Teachers Prize · 4th Edition", or "Every programme". */
    public static function scopeLine(string $scope): string
    {
        if ($scope === '') return 'Every programme';
        $id = (int) substr($scope, 1);
        try {
            if ($scope[0] === 'p') return (string) (DB::table('gates_award_programmes')->where('id', $id)->value('title') ?? 'A programme');
            $c = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', $id)->first(['c.*', 'p.title as programme']);
            return $c ? (string) $c->programme . ' · ' . EditionName::full($c) : 'An edition';
        } catch (\Throwable) {
            return 'Every programme';
        }
    }

    /**
     * The header's sub line: the scope and the next nominations deadline inside it, from
     * the stored date. No date is invented when none is set.
     */
    public static function subLine(string $scope): string
    {
        $line = self::scopeLine($scope);
        try {
            $q = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)->whereNotNull('c.nominations_close')->where('c.nominations_close', '>=', date('Y-m-d H:i:s'));
            if ($scope !== '') {
                $id = (int) substr($scope, 1);
                $scope[0] === 'p' ? $q->where('c.programme_id', $id) : $q->where('c.id', $id);
            }
            $next = $q->orderBy('c.nominations_close')->value('c.nominations_close');
            if ($next && ($t = strtotime(str_replace('T', ' ', (string) $next)))) $line .= ' · nominations close ' . date('j M', $t);
        } catch (\Throwable) {}
        return $line;
    }

    // ── one nomination ───────────────────────────────────────────────────────

    /**
     * The side peek: every field, the checks spelled out, the history and the team notes.
     *
     * @return array<string,mixed>|null
     */
    public static function peek(int $id, ?AwardService $awards = null): ?array
    {
        $rows = self::rows(DB::table('gates_nominations as n')
            ->leftJoin('gates_award_categories as cat', 'cat.id', '=', 'n.category_id')
            ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'n.cycle_id')
            ->leftJoin('gates_award_programmes as pr', 'pr.id', '=', 'c.programme_id')
            ->where('n.id', $id));
        if (!$rows) return null;
        $r = self::annotate($rows, $awards)[0];

        $cats = [];
        try {
            $cats = DB::table('gates_award_categories')->where('cycle_id', (int) $r['cycle_id'])->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'title'])->map(static fn (object $c): array => ['id' => (int) $c->id, 'title' => (string) $c->title])->all();
        } catch (\Throwable) {}

        // The case as the nominator made it, per category, where the newer form kept it.
        $reasons = [];
        try {
            if (SchemaHas::table('gates_nomination_categories')) {
                foreach (DB::table('gates_nomination_categories as nc')->leftJoin('gates_award_categories as c', 'c.id', '=', 'nc.category_id')
                             ->where('nc.nomination_id', $id)->orderBy('nc.id')->get(['c.title', 'nc.reason']) as $x) {
                    if (trim((string) $x->reason) !== '') $reasons[] = ['title' => (string) ($x->title ?? ''), 'reason' => (string) $x->reason];
                }
            }
        } catch (\Throwable) {}

        $evidence = [];
        try {
            if (SchemaHas::table('gates_nomination_evidence')) {
                foreach (DB::table('gates_nomination_evidence')->where('nomination_id', $id)->orderBy('id')->get() as $e) {
                    $href = (string) ($e->url ?: $e->path);
                    if ($e->url && !NominationRules::isHttpUrl((string) $e->url)) continue;   // re-checked on the way out
                    $evidence[] = ['href' => $href, 'label' => (string) ($e->label ?: ($e->kind === 'file' ? 'Supporting file' : (parse_url($href, PHP_URL_HOST) ?: 'Link'))),
                                   'kind' => (string) $e->kind];
                }
            }
        } catch (\Throwable) {}
        foreach (['reference_url', 'reference_url_2', 'reference_url_3'] as $c) {
            $u = trim((string) ($r[$c] ?? ''));
            if ($u !== '' && NominationRules::isHttpUrl($u) && !in_array($u, array_column($evidence, 'href'), true)) {
                $evidence[] = ['href' => $u, 'label' => (string) (parse_url($u, PHP_URL_HOST) ?: 'Link'), 'kind' => 'link'];
            }
        }

        $e = $r['eligibility'];
        $checks = [
            in_array('dup', $r['keys'], true)
                ? ['ok' => false, 'bad' => true, 't' => count($r['dup_of']) . ' other nomination' . (count($r['dup_of']) === 1 ? '' : 's') . ' under this name in this edition']
                : ['ok' => true, 't' => 'No other nomination under this name'],
            $r['evidence'] > 0
                ? ['ok' => true, 't' => $r['evidence'] . ' supporting link' . ($r['evidence'] === 1 ? '' : 's') . ' or file' . ($r['evidence'] === 1 ? '' : 's')]
                : ['ok' => false, 't' => 'No supporting links or files'],
        ];
        if ($e && $e['enabled']) {
            $checks[] = $e['eligible']
                ? ['ok' => true, 't' => 'Nominated from ' . $e['distinct_locations'] . ' place' . ($e['distinct_locations'] === 1 ? '' : 's') . ' — enough to approve']
                : ['ok' => false, 't' => 'Nominated from ' . $e['distinct_locations'] . ' of the ' . $e['min'] . ' places needed to approve'];
        }

        return $r + [
            'categories' => $cats,
            'reasons'    => $reasons,
            'evidence_list' => $evidence,
            'check_list' => $checks,
            'history'    => self::history($id, $r),
            'can_approve'=> !($e && $e['enabled'] && !$e['eligible']) && (int) ($r['category_id'] ?? 0) > 0,
        ];
    }

    /**
     * What has happened to a nomination, oldest first: its arrival, every decision from the
     * audit log, and the team's notes. Nothing here is invented — a step with no record is
     * not drawn.
     *
     * @return list<array{who:string,what:string,at:string,when:string,tone:string,note:bool}>
     */
    public static function history(int $id, array $r): array
    {
        $out = [];
        $at = (string) $r['created_at'];
        $out[] = ['who' => $r['by'] !== '' ? $r['by'] : 'The nominator', 'what' => 'submitted the nomination', 'at' => $at, 'tone' => 'faint', 'note' => false];
        $words = [
            'nomination.approve' => ['approved it', 'green'], 'nomination.reject' => ['rejected it', 'red'],
            'nomination.pending' => ['moved it back to review', 'gold'], 'nomination.ask' => ['asked the nominator for more', 'gold'],
            'nomination.export' => null,
        ];
        try {
            foreach (DB::table('gates_audit_log as l')->leftJoin('gates_admins as a', 'a.id', '=', 'l.admin_id')
                         ->where('l.target_type', 'nomination')->where('l.target_id', $id)
                         ->orderBy('l.created_at')->orderBy('l.id')->limit(40)
                         ->get(['l.action', 'l.created_at', 'a.name']) as $l) {
                $w = $words[(string) $l->action] ?? [str_replace(['nomination.', '_', '-'], ['', ' ', ' '], (string) $l->action), 'faint'];
                if ($w === null) continue;
                $out[] = ['who' => (string) ($l->name ?? 'System'), 'what' => $w[0], 'at' => (string) $l->created_at, 'tone' => $w[1], 'note' => false];
            }
        } catch (\Throwable) {}
        if ($r['suggest']) {
            $out[] = ['who' => 'System', 'what' => 'suggested “' . $r['suggest']['title'] . '” as a closer fit', 'at' => $at, 'tone' => 'gold', 'note' => false];
        }
        try {
            if (SchemaHas::table('gates_nomination_notes')) {
                foreach (DB::table('gates_nomination_notes as x')->leftJoin('gates_admins as a', 'a.id', '=', 'x.admin_id')
                             ->where('x.nomination_id', $id)->orderBy('x.created_at')->orderBy('x.id')->limit(50)
                             ->get(['x.body', 'x.created_at', 'a.name']) as $n) {
                    $out[] = ['who' => (string) ($n->name ?? 'A colleague'), 'what' => (string) $n->body, 'at' => (string) $n->created_at, 'tone' => 'slate', 'note' => true];
                }
            }
        } catch (\Throwable) {}
        usort($out, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));
        $now = time();
        foreach ($out as $i => $h) {
            $t = strtotime($h['at']) ?: $now;
            $out[$i]['when'] = self::ago($now - $t, $t);
        }
        return $out;
    }

    /** Save a team note. Returns false on an empty body or a store that is not there yet. */
    public static function addNote(int $nominationId, int $adminId, string $body): bool
    {
        $body = trim(mb_substr($body, 0, 2000));
        if ($body === '' || !SchemaHas::table('gates_nomination_notes')) return false;
        try {
            DB::table('gates_nomination_notes')->insert([
                'nomination_id' => $nominationId, 'admin_id' => $adminId > 0 ? $adminId : null,
                'body' => $body, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[review-queue] note: ' . $e->getMessage());
            return false;
        }
    }

    // ── small things ─────────────────────────────────────────────────────────

    /** "12m", "3h", "2d", then the date: short enough for a 56px column. */
    public static function ago(int $secs, int $at): string
    {
        if ($secs < 60) return 'now';
        if ($secs < 3600) return intdiv($secs, 60) . 'm';
        if ($secs < 86400) return intdiv($secs, 3600) . 'h';
        if ($secs < 86400 * 7) return intdiv($secs, 86400) . 'd';
        return date(date('Y', $at) === date('Y') ? 'j M' : 'M Y', $at);
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $ini = '';
        foreach ([reset($parts), count($parts) > 1 ? end($parts) : ''] as $w) {
            if (is_string($w) && $w !== '') $ini .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $ini !== '' ? $ini : '?';
    }
}
