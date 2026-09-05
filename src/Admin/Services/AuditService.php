<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\AuditTargets;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The admin audit log: 124 places write it, and until now nothing could ask it a question.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ACTUALLY WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_audit_log` is written well. A hundred-odd distinct actions across every admin
 * controller, each with the admin, the thing acted on, a JSON `meta` naming what changed,
 * a hashed IP and the user agent. As a record it is close to complete.
 *
 * It had two readers, and neither of them is a reader in the sense that matters:
 *
 *   1. {@see recent()} — the last TWELVE rows, on the dashboard. No filter, no search,
 *      no history. On a busy morning twelve rows is under a minute of activity.
 *   2. `/admin/data/audit-log`, the generic table browser. It dumps the raw columns:
 *      the admin is `7`, the target is `412`, and the free-text search covers the action
 *      string alone. `meta` is not a list column, and `ip_hash` ends in `_hash` so
 *      {@see \AfricaGates\Admin\Support\DataRegistry::isHidden()} strips it from the
 *      detail page and the export both — it has never been rendered anywhere at all.
 *
 * So every question anybody actually brings to an audit log was unanswerable on a host
 * with no shell: what has this admin been doing; everything that ever happened to this
 * nominee; who changed the payment settings and when; was this run from the same machine
 * as the rest of their session. The facts were all recorded. None of them could be asked
 * for.
 *
 * That is §17's failure with a twist — not a column nothing writes, a column everything
 * writes and nothing can reach.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE QUERY BUILDER, AND THE DASHBOARD USES IT TOO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see recent()} is now {@see search()} with a limit. It would have been less work to
 * leave the dashboard's own query alone, and that is exactly how two readers of one table
 * come to disagree about what a row means — the dashboard would keep showing raw target
 * ids while the audit screen resolved them, and nobody would know which was right.
 */
class AuditService
{
    /** Rows per page. High, because reading an audit trail is scanning, not browsing. */
    public const PER_PAGE = 60;

    public function record(?int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, array $meta = []): void
    {
        // ══ admin_id = 0 WAS SILENTLY DROPPED ON PRODUCTION ══════════════════
        //
        // `gates_audit_log.admin_id` carries a foreign key to `gates_admins` with
        // ON DELETE SET NULL, and there is no admin with id 0. Seventy-one call sites
        // pass `(int) ($_SESSION['admin_id'] ?? 0)`, so every action taken WITHOUT a
        // live session — scheduled work, the console, a session that expired between
        // the page and the post — inserted a 0, MySQL refused the row on the
        // constraint, and the catch below swallowed the exception.
        //
        // The audit log was therefore failing at precisely the moment it matters
        // most: the action nobody was logged in for. Doubly invisible — the harness
        // runs `PRAGMA foreign_keys = OFF`, so the suite has always been green, and
        // an audit write is deliberately not allowed to surface an error, so
        // production could not report it either.
        //
        // Normalised here rather than at seventy-one call sites. 0 was never an admin
        // id; it is the "no session" sentinel that `?? 0` leaks, and NULL is what this
        // column already means by it.
        $adminId = ($adminId !== null && $adminId > 0) ? $adminId : null;

        try {
            DB::table('gates_audit_log')->insert([
                'admin_id'    => $adminId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'meta'        => $meta ? json_encode($meta) : null,
                'ip_hash'     => isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', (string)$_SERVER['REMOTE_ADDR']) : null,
                'ua'          => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // Audit failures must never break the app.
        }
    }

    /**
     * The dashboard's strip. Thin wrapper so there is one query builder, not two.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return $this->search(['per' => $limit])['rows'];
    }

    /**
     * The log, asked a question.
     *
     * @param array<string,mixed> $f admin, action, area, target_type, target_id, q, from, to, page, per
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int, per:int}
     */
    public function search(array $f = []): array
    {
        $per  = max(1, min(200, (int) ($f['per'] ?? self::PER_PAGE)));
        $page = max(1, (int) ($f['page'] ?? 1));

        try {
            $q = $this->filtered($f);

            // Counted before the limit, and separately: an audit screen that cannot say
            // "3 of 41,220" leaves the reader unable to tell a narrow filter from an
            // empty log, which are opposite conclusions.
            $total = (int) (clone $q)->count();
            $pages = max(1, (int) ceil($total / $per));
            if ($page > $pages) $page = $pages;

            $rows = $q->leftJoin('gates_admins as ad', 'ad.id', '=', 'a.admin_id')
                ->select(['a.id', 'a.admin_id', 'a.action', 'a.target_type', 'a.target_id',
                          'a.meta', 'a.ip_hash', 'a.ua', 'a.created_at',
                          'ad.name as admin_name', 'ad.email as admin_email'])
                ->orderByDesc('a.id')
                ->forPage($page, $per)->get()
                ->map(fn (object $r): array => $this->shape($r))->all();

            return ['rows' => AuditTargets::resolve($rows), 'total' => $total,
                    'page' => $page, 'pages' => $pages, 'per' => $per];
        } catch (\Throwable $e) {
            error_log('[audit] search: ' . $e->getMessage());
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per' => $per];
        }
    }

    /**
     * Everything that ever happened to one record.
     *
     * Queries every alias of the type, not just the one asked for — see
     * {@see AuditTargets::ALIASES}. An event's history is written under `site_event` by
     * one controller and `event` by another, so a caller asking for either and getting
     * only its own half would be a worse answer than none.
     *
     * @return list<array<string,mixed>>
     */
    public function forTarget(string $type, ?int $id, int $limit = 200): array
    {
        return $this->search(['target_type' => $type, 'target_id' => $id, 'per' => $limit])['rows'];
    }

    /**
     * What can be filtered on, drawn from the log itself rather than a hardcoded list.
     *
     * The alternative is a constant naming the hundred-odd actions, which would go stale
     * the first time somebody added a controller — and a filter that silently omits an
     * action is how you conclude something never happened.
     *
     * @return array{areas:list<array<string,mixed>>, admins:list<array<string,mixed>>,
     *               types:list<array<string,mixed>>, total:int,
     *               span:array{first:?string,last:?string}}
     */
    public function facets(): array
    {
        $empty = ['areas' => [], 'admins' => [], 'types' => [], 'total' => 0,
                  'span' => ['first' => null, 'last' => null]];

        try {
            // Actions are dotted (`settings.update`, `event.create`), so the useful filter
            // is the part before the dot — thirty-odd areas rather than a hundred actions.
            // Split in PHP: MySQL has SUBSTRING_INDEX and SQLite does not, and a driver
            // fork here would be a second definition of what an "area" is.
            $actions = DB::table('gates_audit_log')
                ->select('action', DB::raw('COUNT(*) as n'))
                ->groupBy('action')->get();

            $areas = [];
            foreach ($actions as $r) {
                $action = (string) $r->action;
                $area   = str_contains($action, '.') ? strstr($action, '.', true) : $action;
                $areas[$area]['area']  = $area;
                $areas[$area]['n']     = ($areas[$area]['n'] ?? 0) + (int) $r->n;
                $areas[$area]['actions'][] = ['action' => $action, 'n' => (int) $r->n];
            }
            foreach ($areas as $k => $a) {
                usort($areas[$k]['actions'], static fn ($x, $y): int => $y['n'] <=> $x['n']);
            }
            uasort($areas, static fn ($x, $y): int => $y['n'] <=> $x['n']);

            $admins = DB::table('gates_audit_log as a')
                ->leftJoin('gates_admins as ad', 'ad.id', '=', 'a.admin_id')
                ->select('a.admin_id', 'ad.name', 'ad.email', DB::raw('COUNT(*) as n'),
                         DB::raw('MAX(a.created_at) as last_at'))
                // ONLY_FULL_GROUP_BY: every selected non-aggregate must be grouped.
                ->groupBy('a.admin_id', 'ad.name', 'ad.email')
                ->orderByDesc('n')->get()
                ->map(static fn (object $r): array => [
                    'admin_id' => $r->admin_id === null ? null : (int) $r->admin_id,
                    'name'     => $r->name ?: ($r->email ?: null),
                    'n'        => (int) $r->n,
                    'last_at'  => $r->last_at,
                ])->all();

            $types = [];
            foreach (DB::table('gates_audit_log')->whereNotNull('target_type')
                        ->select('target_type', DB::raw('COUNT(*) as n'))
                        ->groupBy('target_type')->get() as $r) {
                // Fold the aliases together so one subject is one filter entry.
                $key = AuditTargets::canonical((string) $r->target_type);
                $types[$key]['type']  = $key;
                $types[$key]['label'] = AuditTargets::label($key);
                $types[$key]['n']     = ($types[$key]['n'] ?? 0) + (int) $r->n;
            }
            uasort($types, static fn ($x, $y): int => $y['n'] <=> $x['n']);

            $span = DB::table('gates_audit_log')
                ->select(DB::raw('MIN(created_at) as first_at'), DB::raw('MAX(created_at) as last_at'))
                ->first();

            return ['areas'  => array_values($areas),
                    'admins' => $admins,
                    'types'  => array_values($types),
                    // The unfiltered size of the log. Returned rather than left for the
                    // template to sum out of the area counts, because a page deriving it
                    // is a second opinion about how much history exists — and the one
                    // number a reader checks a filtered count against.
                    'total'  => array_sum(array_column($areas, 'n')),
                    'span'   => ['first' => $span->first_at ?? null, 'last' => $span->last_at ?? null]];
        } catch (\Throwable $e) {
            error_log('[audit] facets: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * One admin, summarised: how much, over what span, from how many devices.
     *
     * The device count is the reason `ip_hash` exists, and nothing has ever read it. Two
     * networks over a year is ordinary. Nine in a week beside a run of destructive actions
     * is the shape of a shared or stolen session, and it was recorded on every row from
     * the day the table shipped with no way to see it.
     *
     * @return array<string,mixed>
     */
    public function actorSummary(?int $adminId): array
    {
        $out = ['total' => 0, 'first_at' => null, 'last_at' => null, 'devices' => 0,
                'agents' => 0, 'top' => []];
        try {
            $q = DB::table('gates_audit_log');
            self::whereAdmin($q, (int) $adminId, 'admin_id');
            $row = (clone $q)
                ->select(DB::raw('COUNT(*) as n'),
                         DB::raw('MIN(created_at) as first_at'),
                         DB::raw('MAX(created_at) as last_at'),
                         DB::raw('COUNT(DISTINCT ip_hash) as devices'),
                         DB::raw('COUNT(DISTINCT ua) as agents'))
                ->first();

            $out['total']    = (int) ($row->n ?? 0);
            $out['first_at'] = $row->first_at ?? null;
            $out['last_at']  = $row->last_at ?? null;
            $out['devices']  = (int) ($row->devices ?? 0);
            $out['agents']   = (int) ($row->agents ?? 0);

            $out['top'] = (clone $q)
                ->select('action', DB::raw('COUNT(*) as n'))
                ->groupBy('action')->orderByDesc('n')->limit(8)->get()
                ->map(static fn (object $r): array => ['action' => (string) $r->action, 'n' => (int) $r->n])
                ->all();
        } catch (\Throwable $e) {
            error_log('[audit] actorSummary: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Rows attributed to one admin — or, for 0 and null, the ones attributed to nobody.
     *
     * ONE predicate, used by the filter and by the per-admin summary. They must select
     * the same set or the summary counts a different population from the table under it,
     * which is the kind of disagreement nobody spots because both numbers look plausible.
     *
     * "Unattributed" is two spellings. NULL is what {@see record()} writes now; a literal
     * 0 is what it wrote before, and those rows survive anywhere the foreign key was not
     * enforcing — SQLite, or a MySQL restore taken with FOREIGN_KEY_CHECKS off. One
     * bucket, because to a reader they are one thing.
     *
     * @param \Illuminate\Database\Query\Builder $q
     */
    private static function whereAdmin($q, int $adminId, string $col): void
    {
        if ($adminId > 0) { $q->where($col, $adminId); return; }
        $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, 0));
    }

    /** The filtered query, without the select or the ordering. Shared by search and count. */
    private function filtered(array $f): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('gates_audit_log as a');

        if (($f['admin'] ?? null) !== null && $f['admin'] !== '') {
            self::whereAdmin($q, (int) $f['admin'], 'a.admin_id');
        }

        if (($f['action'] ?? '') !== '') $q->where('a.action', (string) $f['action']);

        // An area is the dotted prefix. Matched as `area.%` PLUS the bare word, because a
        // handful of actions have no dot at all (`login`) and would otherwise be filed
        // under an area that could never be selected.
        if (($f['area'] ?? '') !== '') {
            $area = (string) $f['area'];
            $q->where(fn ($w) => $w->where('a.action', $area)
                                   ->orWhereRaw($this->like('a.action'), [$this->esc($area) . '.%']));
        }

        if (($f['target_type'] ?? '') !== '') {
            $q->whereIn('a.target_type', AuditTargets::aliasesOf(AuditTargets::canonical((string) $f['target_type'])));
        }
        if (($f['target_id'] ?? null) !== null && (int) $f['target_id'] > 0) {
            $q->where('a.target_id', (int) $f['target_id']);
        }

        // Dates are normalised to the stored format. MySQL rewrites a `T`-separated
        // datetime on the way into a TIMESTAMP column and SQLite stores whatever string
        // it is handed, so an unnormalised bound compares correctly on one driver and
        // silently matches nothing on the other.
        if ($from = $this->stamp($f['from'] ?? null, false)) $q->where('a.created_at', '>=', $from);
        if ($to   = $this->stamp($f['to'] ?? null, true))    $q->where('a.created_at', '<=', $to);

        if (($f['q'] ?? '') !== '') {
            $needle = '%' . $this->esc(trim((string) $f['q'])) . '%';
            $q->where(fn ($w) => $w->whereRaw($this->like('a.action'), [$needle])
                                   ->orWhereRaw($this->like('a.meta'), [$needle]));
        }

        return $q;
    }

    /**
     * `col LIKE ? ESCAPE '!'`.
     *
     * ── WHY NOT JUST ->where(…, 'like', …) ───────────────────────────────────
     *
     * Because the wildcards have to be escaped, and the two drivers disagree about how.
     * MySQL treats backslash as the LIKE escape by default; SQLite has NO default escape
     * character at all, so a backslash-escaped `_` matches a literal backslash there and
     * therefore matches nothing. Several areas in this log contain an underscore —
     * `stand_call`, `vendor_policy`, `stand_type` — so the naive form would work on
     * production and silently return zero rows in dev and in the suite, which is this
     * codebase's most expensive shape of bug pointing the wrong way round.
     *
     * Spelling the ESCAPE clause out fixes it, but not with a backslash: `ESCAPE '\\'` is
     * one character to MySQL and two to SQLite (which does not process escapes inside
     * string literals), and `ESCAPE '\'` is an unterminated literal to MySQL. `!` needs
     * no escaping in either dialect and is not a wildcard in either.
     */
    private function like(string $column): string
    {
        return $column . " LIKE ? ESCAPE '!'";
    }

    /** Escape the LIKE wildcards in user input, so a search for `100%` is not a search for everything. */
    private function esc(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    /** A date or datetime bound in the format the column stores, or null. */
    private function stamp(mixed $v, bool $endOfDay): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        try {
            $c = Carbon::parse($s);
            // A bare date as an upper bound means "to the end of that day". Without this
            // `to=2026-09-02` excludes everything that happened on the 2nd, which reads
            // as a quiet day rather than an off-by-one.
            if ($endOfDay && !str_contains($s, ':')) $c = $c->endOfDay();
            return $c->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** One row, decoded — including the two columns nothing has ever rendered. */
    private function shape(object $r): array
    {
        $meta = $r->meta ? json_decode((string) $r->meta, true) : null;

        return [
            'id'          => (int) $r->id,
            'admin_id'    => $r->admin_id === null ? null : (int) $r->admin_id,
            'action'      => (string) $r->action,
            'area'        => str_contains((string) $r->action, '.')
                                ? strstr((string) $r->action, '.', true) : (string) $r->action,
            'target_type' => $r->target_type,
            'target_id'   => $r->target_id === null ? null : (int) $r->target_id,
            'meta'        => is_array($meta) ? $meta : null,
            'admin_name'  => $r->admin_name ?? null,
            'admin_email' => $r->admin_email ?? null,
            'created_at'  => $r->created_at,
            // A sha256 of an IP is not an address and cannot be turned back into one. Six
            // characters of it is enough to say "the same network as the row above" and
            // nothing more, which is the whole question anybody brings to it.
            'device'      => self::device($r->ip_hash ?? null),
            'agent'       => self::agent($r->ua ?? null),
        ];
    }

    /** Six characters of the IP hash: a label for sameness, never an address. */
    public static function device(?string $ipHash): ?string
    {
        $h = trim((string) ($ipHash ?? ''));
        return $h === '' ? null : substr($h, 0, 6);
    }

    /**
     * The user agent, said in words. Stored on every row since the table shipped and
     * rendered nowhere — the data browser hides it behind a column list that never
     * included it.
     */
    public static function agent(?string $ua): ?string
    {
        $ua = trim((string) ($ua ?? ''));
        if ($ua === '') return null;

        // Order matters: Edge and Opera both carry "Chrome", Chrome carries "Safari".
        $browser = 'Unknown browser';
        foreach ([['Edg', 'Edge'], ['OPR', 'Opera'], ['Firefox', 'Firefox'],
                  ['Chrome', 'Chrome'], ['Safari', 'Safari'], ['curl', 'curl'],
                  ['Postman', 'Postman'], ['bot', 'Bot']] as [$needle, $name]) {
            if (stripos($ua, $needle) !== false) { $browser = $name; break; }
        }

        $os = null;
        foreach ([['Android', 'Android'], ['iPhone', 'iPhone'], ['iPad', 'iPad'],
                  ['Windows', 'Windows'], ['Mac OS X', 'macOS'], ['Macintosh', 'macOS'],
                  ['CrOS', 'ChromeOS'], ['Linux', 'Linux']] as [$needle, $name]) {
            if (stripos($ua, $needle) !== false) { $os = $name; break; }
        }

        return $os ? $browser . ' on ' . $os : $browser;
    }
}
