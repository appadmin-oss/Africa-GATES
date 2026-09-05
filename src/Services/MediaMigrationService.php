<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\MediaPublicId;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Bulk migration of locally-stored images to Cloudinary.
 *
 * Every image this platform has ever accepted lives under `public/uploads/`, and every
 * row that references one stores a path like `/uploads/nominees/2026/07/<uuid>.jpg`.
 * This sweeps all of them: uploads the file, then rewrites the referencing column to
 * the Cloudinary URL, so the site starts serving from the CDN with no template change
 * and no redeploy.
 *
 * ── FOUR PROPERTIES IT HAD TO HAVE ───────────────────────────────────────────
 *
 * **Idempotent.** `gates_media_migrations.source_path` is UNIQUE, and a file already in
 * the ledger is reused rather than re-uploaded. Combined with the deterministic public
 * id ({@see MediaPublicId}) even a re-upload overwrites one asset instead of making a
 * second. This is what makes "run it again, I'm not sure it finished" the correct
 * advice rather than a way to triple a Cloudinary bill. It also means the same file
 * referenced by three rows — a profile avatar that is also a nominee photo, which does
 * happen — is uploaded once and all three rows point at it.
 *
 * **Resumable.** Work is done in bounded batches and committed per row, so a request
 * that dies on `max_execution_time` — the normal outcome on shared cPanel hosting with
 * a few thousand photos — loses at most the row in flight. {@see run()} reports what is
 * left so a caller (the admin page, the console command) can simply call again.
 *
 * **Non-destructive.** Local files are NEVER deleted. The DB points at Cloudinary
 * afterwards, but the originals stay exactly where they were, which is the only version
 * of this that can be undone by an operator who decides they do not like it. Deleting
 * them would save disk on a host where disk is not the constraint, in exchange for
 * making a mistake permanent.
 *
 * **Honest about failure.** A file the database references but which is not on disk
 * (restored DB, pruned uploads directory, a hand-edited path) is recorded as `missing`
 * and LEFT ALONE. It is not an error to report and abort on — it is pre-existing
 * breakage the sweep merely discovers — but silently rewriting it to a Cloudinary URL
 * that 404s would convert a broken image into a broken image nobody can diagnose.
 */
final class MediaMigrationService
{
    /**
     * Every column in the database that holds an image path.
     *
     * Written out rather than discovered by scanning for `%_path` columns, because that
     * heuristic would have swept `gates_posts.audio_path` (an MP3 — `image/upload` would
     * reject it) and missed `cover_image` on two tables. `json` marks a column holding
     * an ARRAY of paths; `folder` is the Cloudinary folder the assets land in, chosen so
     * the media library is browsable by what the image IS rather than by which table
     * happened to reference it.
     *
     * @var list<array{table:string, column:string, folder:string, json?:bool}>
     */
    private const TARGETS = [
        ['table' => 'gates_nominees',          'column' => 'photo_path',          'folder' => 'nominees'],
        ['table' => 'gates_nominations',       'column' => 'nominee_photo_path',  'folder' => 'nominations'],
        ['table' => 'gates_profiles',          'column' => 'avatar_path',         'folder' => 'profiles'],
        ['table' => 'gates_profiles',          'column' => 'cover_path',          'folder' => 'profiles'],
        ['table' => 'gates_profiles',          'column' => 'gallery_paths',       'folder' => 'profiles', 'json' => true],
        ['table' => 'gates_judges',            'column' => 'avatar_path',         'folder' => 'judges'],
        ['table' => 'gates_admins',            'column' => 'avatar_path',         'folder' => 'admins'],
        ['table' => 'gates_award_programmes',  'column' => 'cover_path',          'folder' => 'programmes'],
        ['table' => 'gates_legacy_events',     'column' => 'cover_path',          'folder' => 'legacy'],
        ['table' => 'gates_legacy_events',     'column' => 'gallery_paths',       'folder' => 'legacy', 'json' => true],
        ['table' => 'gates_products',          'column' => 'cover_path',          'folder' => 'shop'],
        ['table' => 'gates_site_events',       'column' => 'cover_image',         'folder' => 'events'],
        ['table' => 'gates_posts',             'column' => 'cover_image',         'folder' => 'posts'],
        // Last on purpose: the media library's own index. By the time it is reached the
        // referencing rows are already pointing at Cloudinary, so a `path` rewritten
        // here can never be the only surviving reference to an un-migrated asset.
        ['table' => 'gates_uploads',           'column' => 'path',                'folder' => 'library'],
    ];

    /** Rows examined per table per batch. Small enough that one batch fits a web request. */
    public const BATCH = 25;

    /** Wall-clock ceiling for one {@see run()}, so a web caller returns before the SAPI kills it. */
    private const BUDGET_SECONDS = 20.0;

    public function __construct(
        private readonly ?CloudinaryService $cloud = null,
        private readonly ?LoggerInterface   $log   = null,
        private readonly ?string            $publicRoot = null,
    ) {}

    private function root(): string
    {
        return $this->publicRoot ?? dirname(__DIR__, 2) . '/public';
    }

    /**
     * How much is left to do, without doing any of it.
     *
     * Cheap enough to render on an admin page: one COUNT per target column, over an
     * indexed-ish LIKE. Reported per column rather than as a single number because
     * "3,000 remaining" tells an operator nothing about whether the important ones
     * (nominee photos) are done.
     *
     * @return array{configured:bool, total:int, by_target:array<string,int>, migrated:int, missing:int, failed:int}
     */
    public function status(): array
    {
        $by = [];
        $total = 0;
        foreach (self::TARGETS as $t) {
            if (!$this->tableHasColumn($t['table'], $t['column'])) continue;
            try {
                $n = (int) $this->pendingQuery($t)->count();
            } catch (\Throwable) {
                continue;
            }
            if ($n > 0) {
                $by[$t['table'] . '.' . $t['column']] = $n;
                $total += $n;
            }
        }

        $ledger = ['migrated' => 0, 'missing' => 0, 'failed' => 0];
        try {
            foreach (DB::table('gates_media_migrations')->select('status')->get() as $r) {
                $k = (string) $r->status;
                if (isset($ledger[$k])) $ledger[$k]++;
            }
        } catch (\Throwable) { /* ledger table not migrated yet */ }

        return [
            'configured' => CloudinaryService::enabled(),
            'total'      => $total,
            'by_target'  => $by,
            'migrated'   => $ledger['migrated'],
            'missing'    => $ledger['missing'],
            'failed'     => $ledger['failed'],
        ];
    }

    /**
     * Migrate one batch.
     *
     * @param bool $dryRun Report what WOULD happen; upload nothing, rewrite nothing.
     *                     The ledger is not written either, so a dry run leaves no
     *                     trace that would make the real run skip work.
     * @param int  $limit  Maximum ROWS to process across all targets this call.
     *
     * @return array{ok:bool, done:int, migrated:int, missing:int, failed:int, skipped:int,
     *               pending:int, lines:list<string>, error:?string}
     */
    public function run(bool $dryRun = false, int $limit = self::BATCH, ?string $onlyTable = null): array
    {
        $lines = [];
        $counts = ['migrated' => 0, 'missing' => 0, 'failed' => 0, 'skipped' => 0];
        $done = 0;

        if (!$dryRun && !CloudinaryService::enabled()) {
            return $counts + [
                'ok' => false, 'done' => 0, 'pending' => $this->status()['total'],
                'lines' => ['Cloudinary is not configured — set CLOUDINARY_URL (or the three CLOUDINARY_* names) first.'],
                'error' => 'not_configured',
            ];
        }

        $deadline = microtime(true) + self::BUDGET_SECONDS;

        foreach (self::TARGETS as $t) {
            if ($done >= $limit || microtime(true) > $deadline) break;
            if ($onlyTable !== null && $onlyTable !== '' && $t['table'] !== $onlyTable) continue;
            if (!$this->tableHasColumn($t['table'], $t['column'])) continue;

            try {
                $rows = $this->pendingQuery($t)->limit(min(self::BATCH, $limit - $done))->get(['id', $t['column']]);
            } catch (\Throwable $e) {
                $lines[] = 'skip ' . $t['table'] . '.' . $t['column'] . ': ' . $e->getMessage();
                continue;
            }

            foreach ($rows as $row) {
                if ($done >= $limit || microtime(true) > $deadline) break;
                $done++;
                $outcome = ($t['json'] ?? false)
                    ? $this->migrateJsonColumn($t, $row, $dryRun, $lines)
                    : $this->migrateScalarColumn($t, $row, $dryRun, $lines);
                foreach ($outcome as $k => $n) $counts[$k] = ($counts[$k] ?? 0) + $n;
            }
        }

        $pending = $this->status()['total'];
        $lines[] = $dryRun
            ? "Dry run: examined {$done} row(s); {$pending} still reference local files."
            : "Migrated {$counts['migrated']} image(s); {$counts['missing']} file(s) missing on disk; "
              . "{$counts['failed']} failed; {$pending} row(s) remaining.";

        return $counts + ['ok' => true, 'done' => $done, 'pending' => $pending, 'lines' => $lines, 'error' => null];
    }

    // ── Per-row work ─────────────────────────────────────────────────────────

    /**
     * One scalar path column on one row.
     *
     * @return array<string,int> outcome counters
     */
    private function migrateScalarColumn(array $t, object $row, bool $dryRun, array &$lines): array
    {
        $stored = (string) ($row->{$t['column']} ?? '');
        $res = $this->resolveOne($stored, $t, (int) $row->id, $dryRun, $lines);
        if ($res['url'] === null) return [$res['status'] => 1];

        if (!$dryRun) {
            $updates = [$t['column'] => $res['url']];
            // The media library's own row also records WHERE the asset now is, so the
            // admin delete can remove it from Cloudinary rather than orphaning it.
            if ($t['table'] === 'gates_uploads' && $this->tableHasColumn('gates_uploads', 'provider')) {
                $updates['provider']   = 'cloudinary';
                $updates['public_id']  = $res['public_id'];
                $updates['local_path'] = $stored;
            }
            DB::table($t['table'])->where('id', (int) $row->id)->update($updates);
        }
        return [$res['status'] => 1];
    }

    /**
     * A JSON array of paths (`gallery_paths`).
     *
     * Rewritten entry by entry, and the column is only updated when at least one entry
     * actually changed — so a gallery whose files are all missing keeps its original
     * JSON rather than being rewritten to an identical value, which would make the row
     * look freshly migrated in every subsequent audit.
     *
     * A malformed value (hand-edited JSON, a legacy comma-separated string) is counted
     * as skipped and left untouched. Guessing at its structure risks destroying a
     * gallery to save an operator one manual fix.
     *
     * @return array<string,int>
     */
    private function migrateJsonColumn(array $t, object $row, bool $dryRun, array &$lines): array
    {
        $raw = (string) ($row->{$t['column']} ?? '');
        $list = json_decode($raw, true);
        if (!is_array($list) || $list === []) {
            $lines[] = 'skip ' . $t['table'] . '#' . $row->id . '.' . $t['column'] . ': not a JSON array';
            return ['skipped' => 1];
        }

        $counts = [];
        $changed = false;
        foreach ($list as $i => $entry) {
            if (!is_string($entry) || $entry === '') continue;
            $res = $this->resolveOne($entry, $t, (int) $row->id, $dryRun, $lines);
            $counts[$res['status']] = ($counts[$res['status']] ?? 0) + 1;
            if ($res['url'] !== null) { $list[$i] = $res['url']; $changed = true; }
        }

        if ($changed && !$dryRun) {
            DB::table($t['table'])->where('id', (int) $row->id)
                ->update([$t['column'] => json_encode(array_values($list), JSON_UNESCAPED_SLASHES)]);
        }
        return $counts === [] ? ['skipped' => 1] : $counts;
    }

    /**
     * Resolve one stored path to a Cloudinary URL: ledger hit, or upload.
     *
     * @return array{url:?string, public_id:?string, status:string}
     */
    private function resolveOne(string $stored, array $t, int $rowId, bool $dryRun, array &$lines): array
    {
        $rel = $this->relativePath($stored);
        if ($rel === null) return ['url' => null, 'public_id' => null, 'status' => 'skipped'];

        // Already uploaded under some other row? Reuse it — one asset, many references.
        $seen = $this->ledgerHit($rel);
        if ($seen !== null && $seen['status'] === 'migrated' && ($seen['remote_url'] ?? '') !== '') {
            return ['url' => (string) $seen['remote_url'], 'public_id' => (string) ($seen['public_id'] ?? ''), 'status' => 'migrated'];
        }

        $abs = $this->root() . '/' . $rel;
        if (!is_file($abs)) {
            $lines[] = 'missing ' . $rel . ' (' . $t['table'] . '#' . $rowId . ')';
            if (!$dryRun) $this->recordLedger($rel, null, null, $t, $rowId, 'missing', 'file not found on disk', 0);
            return ['url' => null, 'public_id' => null, 'status' => 'missing'];
        }

        if ($dryRun) {
            $lines[] = 'would upload ' . $rel . ' → ' . CloudinaryService::rootFolder() . '/' . $t['folder'];
            return ['url' => null, 'public_id' => null, 'status' => 'migrated'];
        }

        $up = ($this->cloud ?? new CloudinaryService($this->log))
            ->upload($abs, $t['folder'], MediaPublicId::forPath($rel));

        if (!($up['ok'] ?? false)) {
            $err = (string) ($up['error'] ?? 'unknown error');
            $lines[] = 'FAILED ' . $rel . ': ' . $err;
            $this->recordLedger($rel, null, null, $t, $rowId, 'failed', $err, 0);
            return ['url' => null, 'public_id' => null, 'status' => 'failed'];
        }

        $this->recordLedger($rel, (string) $up['public_id'], (string) $up['url'], $t, $rowId, 'migrated', null, (int) ($up['bytes'] ?? 0));
        $lines[] = 'migrated ' . $rel;
        return ['url' => (string) $up['url'], 'public_id' => (string) $up['public_id'], 'status' => 'migrated'];
    }

    // ── Path handling ────────────────────────────────────────────────────────

    /**
     * The stored value as a path relative to `public/`, or null when it is not a local
     * upload this sweep should touch.
     *
     * Null covers three cases that all look similar and must not be conflated: an
     * absolute URL (already on Cloudinary, or an operator's hand-typed link to someone
     * else's host), anything outside the `uploads/` tree, and any path containing `..`.
     *
     * The traversal guard matters more than it looks. These values are partly
     * public-submitted — the nomination form writes `nominee_photo_path` — and this code
     * turns a stored string into a filesystem read followed by an upload to a public
     * CDN. Without the guard, a crafted `../../.env` would be published at a permanent
     * public URL. The `uploads/` prefix requirement is the real containment; the `..`
     * check is there so a future caller who relaxes the prefix does not silently remove
     * the containment along with it.
     */
    private function relativePath(string $stored): ?string
    {
        $p = trim($stored);
        if ($p === '') return null;
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $p) === 1) return null;   // absolute URL
        if (str_starts_with($p, '//')) return null;                          // protocol-relative

        $rel = ltrim(str_replace('\\', '/', $p), '/');
        if (str_contains($rel, '..')) return null;
        if (!str_starts_with($rel, 'uploads/')) return null;

        return $rel;
    }

    // ── Ledger ───────────────────────────────────────────────────────────────

    /** @return array{public_id:?string, remote_url:?string, status:string}|null */
    private function ledgerHit(string $rel): ?array
    {
        try {
            $r = DB::table('gates_media_migrations')->where('source_path', $rel)->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$r) return null;
        return [
            'public_id'  => $r->public_id ?? null,
            'remote_url' => $r->remote_url ?? null,
            'status'     => (string) ($r->status ?? ''),
        ];
    }

    /**
     * Write (or overwrite) the ledger row for a source path.
     *
     * Upsert by hand rather than with `insertOrIgnore`, because a retry after a
     * `failed` or `missing` outcome must be able to REPLACE that verdict with a
     * successful one. Ignoring the second write would leave a file permanently recorded
     * as failed after it had in fact been migrated.
     */
    private function recordLedger(
        string $rel, ?string $publicId, ?string $url, array $t, int $rowId,
        string $status, ?string $error, int $bytes
    ): void {
        $payload = [
            'source_path'   => $rel,
            'public_id'     => $publicId,
            'remote_url'    => $url,
            'target_table'  => $t['table'],
            'target_column' => $t['column'],
            'target_id'     => $rowId,
            'status'        => $status,
            'error'         => $error === null ? null : mb_substr($error, 0, 300),
            'bytes'         => $bytes,
        ];
        try {
            $existing = DB::table('gates_media_migrations')->where('source_path', $rel)->first();
            if ($existing) {
                DB::table('gates_media_migrations')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('gates_media_migrations')->insert($payload + ['created_at' => Carbon::now()->toDateTimeString()]);
            }
        } catch (\Throwable $e) {
            $this->log?->warning('[media-migrate] ledger write failed', ['path' => $rel, 'err' => $e->getMessage()]);
        }
    }

    // ── Query helpers ────────────────────────────────────────────────────────

    /**
     * Rows still holding a local upload path in this column.
     *
     * ── WHY `%uploads%` AND NOT `%uploads/%` ─────────────────────────────────
     *
     * `gallery_paths` is a JSON array, and `json_encode` escapes forward slashes by
     * default — so a gallery is stored as `["\/uploads\/legacy\/2026\/07\/g1.png"]`.
     * `LIKE '%uploads/%'` therefore matched not one gallery row in the database, and the
     * sweep silently reported nothing to do for both `gallery_paths` columns while
     * happily migrating every scalar column beside them. A "successful" migration that
     * skips a whole class of image, with no error and a `0 remaining` at the end, is the
     * worst available outcome, so the predicate stops depending on the character after
     * the word.
     *
     * Matching one character less is safe in the direction that matters: a Cloudinary
     * delivery URL contains `/image/upload/` — `upload` followed by a slash, never
     * `uploads` — so an already-migrated row does not match and cannot make the
     * auto-continuing admin page loop forever. The explicit remote exclusion below makes
     * that structural rather than a coincidence of Cloudinary's URL format, which is not
     * ours to rely on.
     *
     * `NOT LIKE 'http%'` would have been the obvious alternative and is wrong: it also
     * matches an empty string, an emoji placeholder, and an operator's `/assets/...`
     * reference to a bundled site graphic — and would try to upload each of them.
     * Precision here is what makes {@see status()}'s "remaining" a real number that
     * shrinks monotonically rather than a restatement of the table size.
     */
    private function pendingQuery(array $t)
    {
        return DB::table($t['table'])
            ->whereNotNull($t['column'])
            ->where($t['column'], 'like', '%uploads%')
            ->where($t['column'], 'not like', '%' . CloudinaryService::DELIVERY_HOST . '%')
            ->orderBy('id');
    }

    /** Does this table/column pair exist on the connected database? Memoised. */
    private function tableHasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columnMemo[$key])) return $this->columnMemo[$key];
        try {
            $schema = DB::schema();
            $ok = $schema->hasTable($table) && $schema->hasColumn($table, $column);
        } catch (\Throwable) {
            $ok = false;
        }
        return $this->columnMemo[$key] = $ok;
    }

    /** @var array<string,bool> */
    private array $columnMemo = [];

    /** The declared sweep targets, for the console command's `--table` help and the tests. */
    public static function targets(): array
    {
        return self::TARGETS;
    }
}
