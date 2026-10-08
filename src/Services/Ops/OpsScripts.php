<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ops;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Console\Commands;
use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;
use AfricaGates\Services\MergeSuggestionService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\PaymentService;
use AfricaGates\Support\CronHealth;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The scripts this platform can run on itself — from the console, by a person or by the
 * admin assistant — with no shell.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no SSH on production. `payments:triage`, `standings:verify`, `app:doctor`,
 * `votes:proof` and their siblings were written to answer exactly the questions an operator
 * brings to a bad day — "did every paid vote land?", "is the published standing intact?" —
 * and nobody could run them. Their answers existed only for whoever had a terminal.
 *
 * So each one is declared here and run IN-PROCESS: the Symfony command is built and given an
 * ArrayInput and a BufferedOutput, exactly as `bin/console` would, and what it printed is
 * returned. No exec, no shell, no new code path for the work itself — the command that runs
 * from the console is the command that runs here.
 *
 * ── TWO KINDS, AND WHO MAY RUN EACH ──────────────────────────────────────────
 *
 *   check   reads and reports. The admin assistant may run these ITSELF, which is what lets
 *           it answer "is anything wrong with payments?" with the platform's own evidence
 *           instead of a guess — and at almost no token cost, because the work is done by
 *           code and the model only reads the result.
 *   repair  changes data. Run only by a PERSON, admin or superadmin, with a reason, from
 *           /admin/assistant/scripts. The assistant can recommend one and put the button in
 *           front of them; it can never press it. A model that can move money is a model
 *           that can be talked into moving money.
 *
 * Every script names the console page whose gate covers what it reveals, and is offered only
 * to a role `Permissions::canOpen()` lets through that page — so a moderator's assistant cannot
 * read payment triage that a moderator's rail would not show them.
 *
 * Every run is a row in `gates_ops_runs`: who, by which road, how long, what it said.
 */
final class OpsScripts
{
    public const CHECK  = 'check';
    public const REPAIR = 'repair';

    /** Characters of output kept per run. A report longer than this is a report nobody reads. */
    private const MAX_OUTPUT = 12000;

    /**
     * key => [title, what it tells you, kind, gate path, command name or null (native), command args]
     *
     * @return array<string, array{title:string, what:string, kind:string, path:string, command:?string, args:array<string,mixed>}>
     */
    public static function all(): array
    {
        return [
            // ── checks ──────────────────────────────────────────────────────────
            'ops_snapshot' => ['title' => 'What needs attention', 'kind' => self::CHECK, 'path' => '/admin/dashboard',
                'what' => 'Queues and counts right now: pending nominations, quarantined posts, pending payments, each award cycle\'s live phase and deadline, schema warnings, webhook and message failures.',
                'command' => null, 'args' => []],
            'payments_triage' => ['title' => 'Where paid-vote orders stopped', 'kind' => self::CHECK, 'path' => '/admin/payments',
                'what' => 'For the last 7 days of paid-vote orders: how many confirmed, how many stuck and at which step, and whether any charge the platform never noticed. Asks no gateway.',
                'command' => 'payments:triage', 'args' => ['--days' => '7', '--json' => true]],
            'votes_proof' => ['title' => 'Did every paid vote land?', 'kind' => self::CHECK, 'path' => '/admin/payments',
                'what' => 'Proves, or disproves, that every paid vote of the last 7 days was delivered to the tally.',
                'command' => 'votes:proof', 'args' => ['--days' => '7', '--json' => true]],
            'payments_reconcile_preview' => ['title' => 'Stale payments that would be confirmed', 'kind' => self::CHECK, 'path' => '/admin/payments',
                'what' => 'A dry run of the payment reconciler: which pending orders and gifts the gateway says were paid. Changes nothing.',
                'command' => 'payments:reconcile', 'args' => ['--dry-run' => true, '--limit' => '50']],
            'standings_verify' => ['title' => 'Is the published standing intact?', 'kind' => self::CHECK, 'path' => '/admin/result-release',
                'what' => 'Re-walks the tamper-evident chain of sealed standings and says whether every link still verifies.',
                'command' => 'standings:verify', 'args' => ['--json' => true]],
            'cycles_audit' => ['title' => 'Anything recorded outside its window', 'kind' => self::CHECK, 'path' => '/admin/programmes',
                'what' => 'Votes, nominations and payments recorded outside their award cycle\'s dates. Read-only.',
                'command' => 'cycles:audit', 'args' => ['--json' => true, '--limit' => '10']],
            'duplicate_nominees' => ['title' => 'Likely duplicate nominees', 'kind' => self::CHECK, 'path' => '/admin/nominees',
                'what' => 'The name rules over the latest cycle: how many groups look like the same person entered twice, the clearest first. The AI-assisted scan runs from the Nominees page.',
                'command' => null, 'args' => []],
            'support_queue' => ['title' => 'The support queue', 'kind' => self::CHECK, 'path' => '/admin/support',
                'what' => 'Open tickets by severity, the oldest one waiting, and how many arrived in the last day.',
                'command' => null, 'args' => []],
            'support_gaps' => ['title' => 'Questions the help centre does not answer', 'kind' => self::CHECK, 'path' => '/admin/support',
                'what' => 'Reads the last 30 days of tickets and lists what people asked that no help article covers.',
                'command' => 'support:gaps', 'args' => ['--days' => '30', '--limit' => '200', '--show' => '2']],
            'mail_status' => ['title' => 'Is email going out?', 'kind' => self::CHECK, 'path' => '/admin/settings/mail',
                'what' => 'Mail health and what is outstanding: receipts owed, recovery mail queued. Sends nothing.',
                'command' => 'mail:checkout', 'args' => ['--status' => true]],
            'ai_health' => ['title' => 'Is the AI working, and what is it costing?', 'kind' => self::CHECK, 'path' => '/admin/settings',
                'what' => 'Which AI providers are configured, today\'s calls, tokens, cache hits and failures per feature, and the providers\' own words for the latest failures. Makes no AI call.',
                'command' => null, 'args' => []],
            'recent_errors' => ['title' => 'What has been failing', 'kind' => self::CHECK, 'path' => '/admin/settings',
                'what' => 'Server errors in the last 24 hours, grouped by the page that failed, with the newest references people may quote.',
                'command' => null, 'args' => []],
            'doctor' => ['title' => 'What code and settings are live', 'kind' => self::CHECK, 'path' => '/admin/settings',
                'what' => 'The running release, environment, timezone, pending migrations and integrations as the server sees them.',
                'command' => 'app:doctor', 'args' => ['--json' => true]],
            'votes_remint_preview' => ['title' => 'Confirmed votes that never landed', 'kind' => self::CHECK, 'path' => '/admin/payments',
                'what' => 'A dry run of the re-mint: which confirmed paid-vote orders still owe votes. Mints nothing.',
                'command' => 'votes:remint', 'args' => ['--limit' => '100']],
            'cycles_advance_preview' => ['title' => 'Award cycles whose status is behind', 'kind' => self::CHECK, 'path' => '/admin/programmes',
                'what' => 'A dry run of the cycle advance: which cycles\' stored status is behind their dates. Writes nothing.',
                'command' => 'cycles:advance', 'args' => ['--dry-run' => true]],
            'privacy_purge_preview' => ['title' => 'Personal data past its retention', 'kind' => self::CHECK, 'path' => '/admin/settings',
                'what' => 'A dry run of the retention purge: how much personal data is older than its configured window. Deletes nothing.',
                'command' => 'privacy:purge', 'args' => []],

            // ── repairs: a person, with a reason ────────────────────────────────
            'payments_reconcile' => ['title' => 'Confirm stale payments the gateway says were paid', 'kind' => self::REPAIR, 'path' => '/admin/payments',
                'what' => 'Re-checks pending orders and gifts older than 15 minutes with the gateway and confirms the ones that were paid, delivering their votes.',
                'command' => 'payments:reconcile', 'args' => ['--limit' => '200']],
            'payments_triage_fix' => ['title' => 'Repair paid-vote orders the platform missed', 'kind' => self::REPAIR, 'path' => '/admin/payments',
                'what' => 'Asks the gateway about the last 30 days of stuck paid-vote orders and confirms the ones it says were paid.',
                'command' => 'payments:triage', 'args' => ['--days' => '30', '--fix' => true]],
            'votes_remint' => ['title' => 'Deliver confirmed votes that never landed', 'kind' => self::REPAIR, 'path' => '/admin/payments',
                'what' => 'Retries minting paid votes on orders that were confirmed but never delivered. Safe to run again.',
                'command' => 'votes:remint', 'args' => ['--commit' => true]],
            'cycles_advance' => ['title' => 'Bring award cycles up to date', 'kind' => self::REPAIR, 'path' => '/admin/programmes',
                'what' => 'Materialises each cycle\'s status from its dates — what the scheduled task does when it is running.',
                'command' => 'cycles:advance', 'args' => []],
            'cpi_recompute' => ['title' => 'Recompute every score', 'kind' => self::REPAIR, 'path' => '/admin/programmes',
                'what' => 'Recomputes the Cultural Power Index for every nominee. Released results are sealed and do not move.',
                'command' => 'cpi:recompute', 'args' => []],
            'repair_indexes' => ['title' => 'Repair the vote indexes', 'kind' => self::REPAIR, 'path' => '/admin/settings',
                'what' => 'Creates the gates_votes indexes earlier migrations failed to create.',
                'command' => 'db:repair-indexes', 'args' => []],
            'cache_clear' => ['title' => 'Clear the page and AI caches', 'kind' => self::REPAIR, 'path' => '/admin/settings',
                'what' => 'Empties the database cache and compiled templates. Pages are slower for a minute while they rebuild.',
                'command' => 'cache:clear', 'args' => []],
        ];
    }

    /** The scripts $role may see, optionally of one kind. @return array<string, array<string,mixed>> */
    public static function forRole(string $role, ?string $kind = null): array
    {
        return array_filter(self::all(), static fn (array $s) => ($kind === null || $s['kind'] === $kind)
            && self::allowed($role, $s));
    }

    /** @param array<string,mixed> $s */
    public static function allowed(string $role, array $s): bool
    {
        if ($role === '' || !Permissions::canOpen($role, (string) $s['path'])) return false;
        return $s['kind'] !== self::REPAIR || Permissions::canManageIntegrity($role);
    }

    /**
     * Run one script. `via` is 'ai' or 'person'; the assistant can only ever reach a check.
     *
     * @return array{ok:bool, key:string, title:string, kind:string, output:string, ms:int, error?:string}
     */
    public static function run(string $key, string $role, ?int $adminId, string $via, string $reason = ''): array
    {
        $s = self::all()[$key] ?? null;
        if ($s === null) return ['ok' => false, 'key' => $key, 'title' => $key, 'kind' => '', 'output' => '', 'ms' => 0, 'error' => 'No such script.'];
        $base = ['key' => $key, 'title' => $s['title'], 'kind' => $s['kind']];
        if (!self::allowed($role, $s)) return $base + ['ok' => false, 'output' => '', 'ms' => 0, 'error' => 'Your role cannot run this.'];
        if ($via === 'ai' && $s['kind'] !== self::CHECK) {
            return $base + ['ok' => false, 'output' => '', 'ms' => 0, 'error' => 'The assistant may only run checks; a person runs repairs.'];
        }
        if ($s['kind'] === self::REPAIR && trim($reason) === '') {
            return $base + ['ok' => false, 'output' => '', 'ms' => 0, 'error' => 'A repair needs a reason.'];
        }

        $t0 = microtime(true);
        $code = null;
        try {
            if ($s['command'] === null) {
                $output = (string) json_encode(self::native($key), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $ok = true;
            } else {
                [$code, $output] = self::command((string) $s['command'], (array) $s['args']);
                $ok = $code === 0;
            }
        } catch (\Throwable $e) {
            $ok = false;
            $output = 'Failed: ' . $e->getMessage();
        }
        $output = mb_substr(trim((string) preg_replace('/\e\[[0-9;]*m/', '', $output)), 0, self::MAX_OUTPUT);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        try {
            DB::table('gates_ops_runs')->insert([
                'script_key' => $key, 'kind' => $s['kind'], 'via' => $via === 'ai' ? 'ai' : 'person',
                // A foreign-key-shaped column; there is no admin 0.
                'admin_id' => ($adminId ?? 0) > 0 ? $adminId : null,
                'ok' => $ok ? 1 : 0, 'exit_code' => $code, 'output' => $output,
                'reason' => trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
                'ms' => $ms, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) { /* the result matters more than the record of it */ }

        return $base + ['ok' => $ok, 'output' => $output, 'ms' => $ms];
    }

    /** The latest run of each script. @return array<string, object> */
    public static function lastRuns(): array
    {
        try {
            $ids = DB::table('gates_ops_runs')->groupBy('script_key')->selectRaw('MAX(id) as id')->pluck('id')->all();
            $out = [];
            foreach (DB::table('gates_ops_runs')->whereIn('id', $ids)->get() as $r) $out[(string) $r->script_key] = $r;
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A console command, built the way bin/console builds it, run in this process.
     *
     * @param array<string,mixed> $args
     * @return array{0:int, 1:string}
     */
    private static function command(string $name, array $args): array
    {
        $cmd = match ($name) {
            'payments:triage'    => new Commands\PaymentsTriageCommand(),
            'votes:proof'        => new Commands\VotesProofCommand(),
            'payments:reconcile' => new Commands\PaymentReconcileCommand(new PaymentService(), OtpService::boot()),
            'standings:verify'   => new Commands\StandingsVerifyCommand(),
            'cycles:audit'       => new Commands\CycleAuditCommand(),
            'support:gaps'       => new Commands\SupportGapsCommand(),
            'mail:checkout'      => new Commands\MailCheckoutCommand(),
            'app:doctor'         => new Commands\DoctorCommand(),
            'privacy:purge'      => new Commands\PrivacyPurgeCommand(),
            'votes:remint'       => new Commands\VotesRemintCommand(),
            'cycles:advance'     => new Commands\CycleAdvanceCommand(),
            'cpi:recompute'      => new Commands\CpiRecomputeCommand(),
            'db:repair-indexes'  => new Commands\RepairIndexesCommand(),
            'cache:clear'        => new Commands\CacheClearCommand(),
            default              => null,
        };
        if (!$cmd instanceof Command) throw new \RuntimeException('Unknown command ' . $name);
        $in = new ArrayInput($args);
        $in->setInteractive(false);
        $out = new BufferedOutput();
        @set_time_limit(120);
        $code = $cmd->run($in, $out);
        return [$code, $out->fetch()];
    }

    /** The checks answered by the platform's own services rather than a command. */
    private static function native(string $key): array
    {
        return match ($key) {
            'ops_snapshot'       => self::snapshot(),
            'duplicate_nominees' => self::duplicates(),
            'support_queue'      => self::supportQueue(),
            'ai_health'          => self::aiHealth(),
            'recent_errors'      => self::recentErrors(),
            default              => [],
        };
    }

    /** Read-only operational snapshot — every query individually fault-tolerant. */
    public static function snapshot(): array
    {
        $get = static function (callable $q, $fallback = null) {
            try { return $q(); } catch (\Throwable) { return $fallback; }
        };
        $today = date('Y-m-d 00:00:00');
        $day   = date('Y-m-d H:i:s', time() - 86400);
        return [
            'generated_at'           => date('c'),
            'nominations_pending'    => $get(fn () => (int) DB::table('gates_nominations')->where('status', 'pending')->count(), 0),
            'moderation_quarantined' => $get(fn () => (int) DB::table('gates_comments')->where('status', 'quarantined')->count()
                                                    + (int) DB::table('gates_threads')->where('status', 'quarantined')->count(), 0),
            'votes_today'            => $get(fn () => (int) DB::table('gates_votes')->where('voted_at', '>=', $today)->count(), 0),
            'votes_total'            => $get(fn () => (int) DB::table('gates_votes')->count(), 0),
            'members_total'          => $get(fn () => (int) DB::table('gates_users')->where('status', 'active')->count(), 0),
            'orders_pending'         => $get(fn () => (int) DB::table('gates_orders')->where('status', 'pending')->count(), 0),
            'donations_pending'      => $get(fn () => (int) DB::table('gates_donations')->where('status', 'pending')->count(), 0),
            // The COMPUTED phase per cycle — the stored status column can be behind.
            'cycles'                 => $get(fn () => DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.status', '!=', 'archived')
                ->get(['p.title', 'c.id', 'c.year', 'c.status', 'c.nominations_open', 'c.nominations_close',
                       'c.voting_open', 'c.voting_close', 'c.results_date'])
                ->map(function ($r) {
                    $phase = \AfricaGates\Services\CyclePolicy::stateFor($r);
                    return [
                        'programme' => (string) $r->title, 'year' => (int) $r->year,
                        'phase' => $phase['phase'], 'accepting_votes' => $phase['is_voting_open'],
                        'accepting_nominations' => $phase['is_nominations_open'],
                        'deadline' => $phase['closes_at'], 'note' => $phase['detail'],
                        'cached_status_stale' => $phase['drifted'],
                    ];
                })->all(), []),
            'phase_divergences'      => $get(fn () => count(\AfricaGates\Services\CycleMaterialiser::divergences()), 0),
            'schema_warnings'        => $get(fn () => \AfricaGates\Services\VoteIndexRepair::warnings(), []),
            'scheduled_tasks_stale'  => $get(fn () => CronHealth::isStale(), null),
            'webhook_failures_24h'   => $get(fn () => (int) DB::table('gates_webhook_deliveries')->where('ok', 0)->where('created_at', '>=', $day)->count(), 0),
            'messages_failed_24h'    => $get(fn () => (int) DB::table('gates_messages')->where('status', 'failed')->where('created_at', '>=', $day)->count(), 0),
            'support_open'           => $get(fn () => (int) DB::table('gates_support_tickets')->where('status', 'open')->count(), 0),
        ];
    }

    private static function duplicates(): array
    {
        $cycle = (int) (DB::table('gates_award_cycles')->orderByDesc('year')->orderByDesc('id')->value('id') ?? 0);
        if ($cycle <= 0) return ['cycle' => null, 'groups' => 0];
        $r = MergeSuggestionService::forCycle($cycle, null, withAi: false);
        return [
            'cycle' => $cycle, 'scanned' => $r['scanned'], 'groups' => count($r['groups']),
            'clearest' => array_map(static fn (array $g) => ['category' => $g['category'] ?? '', 'names' => $g['names'],
                'confidence' => round((float) $g['confidence'], 2)], array_slice($r['groups'], 0, 5)),
            'where' => '/admin/nominees — "Scan for duplicates" runs the AI-assisted version and merges.',
        ];
    }

    private static function supportQueue(): array
    {
        $open = DB::table('gates_support_tickets')->where('status', 'open');
        return [
            'open'          => (int) (clone $open)->count(),
            'by_severity'   => (clone $open)->groupBy('severity')->selectRaw('severity, COUNT(*) n')->pluck('n', 'severity')->all(),
            'oldest_open'   => (clone $open)->orderBy('created_at')->value('created_at'),
            'new_last_day'  => (int) DB::table('gates_support_tickets')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(),
        ];
    }

    private static function aiHealth(): array
    {
        $status = [];
        try { $status = AiService::boot()->status(); } catch (\Throwable) {}
        return [
            'providers'     => $status,
            'today'         => AiGateway::spendReport(),
            'recent_errors' => array_map(static fn ($r) => is_array($r) ? array_intersect_key($r, array_flip(['capability', 'provider', 'model', 'error', 'created_at'])) : $r,
                                         AiGateway::recentFailures(6, 24)),
        ];
    }

    private static function recentErrors(): array
    {
        $file = dirname(__DIR__, 3) . '/var/logs/error-detail.log';
        if (!is_file($file)) return ['last_24h' => 0, 'by_request' => [], 'newest' => []];
        $size = (int) @filesize($file);
        $h = @fopen($file, 'rb');
        if ($h === false) return ['last_24h' => 0];
        if ($size > 2 * 1024 * 1024) fseek($h, $size - 2 * 1024 * 1024);
        $raw = (string) stream_get_contents($h);
        fclose($h);
        $since = time() - 86400;
        $by = []; $newest = []; $n = 0;
        if (preg_match_all('/^\[([^\]]+)\](?: \[ref ([A-Z0-9-]+)\])?(?: \[([^\]]*)\])? ([^\n]{0,160})/m', $raw, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $t = strtotime($m[1]);
                if ($t === false || $t < $since) continue;
                $n++;
                $where = $m[3] !== '' ? $m[3] : '(no request)';
                $by[$where] = ($by[$where] ?? 0) + 1;
                $newest[] = ['at' => $m[1], 'ref' => $m[2] ?: null, 'where' => $where, 'what' => mb_substr(trim($m[4]), 0, 120)];
            }
        }
        arsort($by);
        return ['last_24h' => $n, 'by_request' => array_slice($by, 0, 10, true),
                'newest' => array_slice(array_reverse($newest), 0, 5),
                'full_entry' => '/__setup/errors?token=…&ref=XXXX-XXXX, or the support ticket that quotes it'];
    }
}
