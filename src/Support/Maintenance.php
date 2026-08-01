<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\{QueueService, GoogleSheetsService, SmsService, NominationTriageService, OtpService, WebhookService, SnapshotService, CollusionService, NominationFeedbackService};
use AfricaGates\Console\Commands\CpiRecomputeCommand;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The platform's periodic-work orchestrator — one place, two front doors.
 *
 * Shared by BOTH the CLI hub (`cron/maintenance.php`, driven by system cron) and
 * the token-gated **webcron** HTTP endpoint (`/__cron/run`), so hosts without
 * reliable shell cron (shared cPanel, etc.) can drive the whole platform from a
 * webcron service (cron-job.org, EasyCron, cPanel "curl a URL") hitting one URL.
 *
 * run('auto') selects work by the clock exactly as before: every run drains the
 * job queue + advances cycles + prunes cache; hourly purges expired tokens;
 * every 6h recomputes CPI + writes tamper-evident snapshots; 06:00 daily runs
 * the collusion scan, reminders, acknowledgements and digest. Named tasks
 * ('cpi', 'queue', …) run one thing. CPI recompute runs the console command
 * IN-PROCESS (no exec) so it works where shell execution is disabled.
 */
final class Maintenance
{
    /** @var string[] */
    private array $lines = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly bool $echo = false,
    ) {}

    private function log(string $msg): void
    {
        $line = '[' . date('c') . "] $msg";
        $this->lines[] = $line;
        if ($this->echo) echo $line . "\n";
    }

    /** @return string[] */
    public function lines(): array { return $this->lines; }

    /**
     * Tasks that threw, keyed by task name.
     *
     * @var array<string,string>
     */
    private array $failures = [];

    /** @return array<string,string> */
    public function failures(): array { return $this->failures; }

    /**
     * The sentinel a failed task reports in the `ran` list.
     *
     * Not 0. A task returning 0 means "ran fine, nothing to do" — the overwhelmingly
     * common case — and collapsing a crash into that number is precisely how a broken
     * cron looks healthy.
     */
    public const TASK_FAILED = -1;

    /**
     * Run one task in isolation.
     *
     * ── THE FAILURE THIS REMOVES ────────────────────────────────────────────────
     *
     * Reported from production: "the cron page 500s even at the time the whole site was
     * 200." Three of the tasks below had no error handling at all — `pruneCache()`,
     * `advanceCycles()` and `purgeExpiredOtp()` each issue a bare query — and two of them
     * run on EVERY tick. So one missing table, one migration that had not been applied,
     * one locked row, and `run()` threw before reaching anything else. The webcron route
     * caught it, wrote `{"ok":false,"error":"maintenance run failed"}`, and put the actual
     * exception in a log an operator with no SSH cannot read.
     *
     * Two independent problems in that sentence, and both are fixed:
     *
     *   1. ALL-OR-NOTHING. A cache prune failing stopped the job queue draining, the
     *      cycles advancing, the payments reconciling and the receipts sending. The
     *      periodic work of the whole platform hung on the least important task in the
     *      list. Each task is now independent: the rest of the run completes.
     *   2. NO DIAGNOSIS. The reason existed and was discarded. It is now recorded per
     *      task, returned by run(), written to gates_cron_log, and — because the endpoint
     *      is gated by a shared secret and whoever holds it is the operator — shown in
     *      the webcron response body.
     */
    private function task(string $name, callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable $e) {
            $this->failures[$name] = $e->getMessage();
            // The file:line as well as the message. "Base table or view not found" names
            // the table but not which of the fifteen callers asked for it.
            $this->log('! ' . $name . ' FAILED: ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            return self::TASK_FAILED;
        }
    }

    /**
     * Run clock-selected work ('auto') or a single named task.
     * @return array{task:string, ran:array<int,array{0:string,1:int}>, lines:string[], runtime_ms:int}
     */
    public function run(string $task = 'auto'): array
    {
        $startedAt = microtime(true);
        $now = Carbon::now();
        $ran = [];

        if ($task === 'auto') {
            // Every run: drain the job queue + advance cycle lifecycle + cache prune
            $ran[] = ['queue',  $this->task('queue',  fn() => $this->drainJobs())];
            $ran[] = ['cycles', $this->task('cycles', fn() => $this->advanceCycles())];
            $ran[] = ['cache',  $this->task('cache',  fn() => $this->pruneCache())];
            // ORDER IS LOAD-BEARING. Reconciliation re-verifies stale pending payments
            // against the gateway and confirms the genuinely-paid ones; the recovery
            // mail then tells whoever is STILL pending that they did not finish. Run
            // the other way round and the first thing a paying supporter whose callback
            // was dropped receives is an email saying they did not pay.
            $ran[] = ['payments',      $this->task('payments',      fn() => $this->reconcilePayments())];
            $ran[] = ['checkout-mail', $this->task('checkout-mail', fn() => $this->mailAbandonedCheckouts())];
            // BOTH after reconciliation, deliberately, and refunds before support.
            // Reconciliation may have just confirmed and minted the very payment a
            // refund would otherwise return or a ticket would call stuck — going
            // first would send money back a second before it stopped being owed,
            // and would have the assistant write "still pending" a second before
            // it stopped being true.
            $ran[] = ['refunds',       $this->task('refunds',       fn() => $this->refundUnminted())];
            $ran[] = ['support',       $this->task('support',       fn() => $this->answerTickets())];
            // Every hour
            if ((int)$now->minute < 15) {
                $ran[] = ['otp',        $this->task('otp',        fn() => $this->purgeExpiredOtp())];
                $ran[] = ['magic',      $this->task('magic',      fn() => $this->purgeExpiredMagic())];
                $ran[] = ['ratelimit',  $this->task('ratelimit',  fn() => $this->pruneRateLimits())];
                $ran[] = ['sharelinks', $this->task('sharelinks', fn() => $this->pruneShareLinks())];
                $ran[] = ['triage-backfill', $this->task('triage-backfill', fn() => NominationTriageService::backfill(100))];
                $ran[] = ['maillog', $this->task('maillog', fn() => (int) DB::table('gates_mail_log')->where('created_at', '<', Carbon::now()->subDays(30))->delete())];
            }
            // Every 6 hours: CPI recompute + tamper-evident standings snapshot
            if ($now->hour % 6 === 0 && (int)$now->minute < 15) {
                $ran[] = ['cpi',      $this->task('cpi',      fn() => $this->recomputeCpi())];
                $ran[] = ['snapshot', $this->task('snapshot', fn() => $this->captureSnapshots())];
            }
            // 06:00 daily: collusion scan + reminders + acknowledgements + digest + cron-log trim
            if ($now->hour === 6 && (int)$now->minute < 15) {
                $ran[] = ['collusion', $this->task('collusion', fn() => $this->scanCollusion())];
                $ran[] = ['reminder',  $this->task('reminder',  fn() => $this->sendVotingReminders())];
                $ran[] = ['nom-ack',   $this->task('nom-ack',   fn() => $this->sendPendingAcknowledgements())];
                $ran[] = ['digest', $this->task('digest', fn() => $this->recordDigest())];
                $ran[] = ['cronlog',   $this->task('cronlog',   fn() => $this->trimCronLog())];
            }
        } else {
            match ($task) {
                'cycles'    => $ran[] = ['cycles', $this->task('cycles', fn() => $this->advanceCycles())],
                'cpi'       => $ran[] = ['cpi',   $this->task('cpi',   fn() => $this->recomputeCpi())],
                'cache'     => $ran[] = ['cache', $this->task('cache', fn() => $this->pruneCache())],
                'queue'     => $ran[] = ['queue', $this->task('queue', fn() => $this->drainJobs())],
                'otp'       => $ran[] = ['otp',   $this->task('otp',   fn() => $this->purgeExpiredOtp())],
                'magic'     => $ran[] = ['magic', $this->task('magic', fn() => $this->purgeExpiredMagic())],
                'collusion' => $ran[] = ['collusion', $this->task('collusion', fn() => $this->scanCollusion())],
                'payments'  => $ran[] = ['payments', $this->task('payments', fn() => $this->reconcilePayments())],
                'checkout-mail' => $ran[] = ['checkout-mail', $this->task('checkout-mail', fn() => $this->mailAbandonedCheckouts())],
                'support'   => $ran[] = ['support', $this->task('support', fn() => $this->answerTickets())],
                'refunds'   => $ran[] = ['refunds', $this->task('refunds', fn() => $this->refundUnminted())],
                'digest'    => $ran[] = ['digest', $this->task('digest', fn() => $this->recordDigest())],
                'all'       => (function () use (&$ran) {
                    $ran[] = ['queue', $this->task('queue', fn() => $this->drainJobs())];
                    $ran[] = ['cycles', $this->task('cycles', fn() => $this->advanceCycles())];
                    $ran[] = ['cache', $this->task('cache', fn() => $this->pruneCache())];
                    $ran[] = ['payments',      $this->task('payments',      fn() => $this->reconcilePayments())];
                    $ran[] = ['checkout-mail', $this->task('checkout-mail', fn() => $this->mailAbandonedCheckouts())];
                    $ran[] = ['otp',   $this->task('otp',   fn() => $this->purgeExpiredOtp())];
                    $ran[] = ['magic', $this->task('magic', fn() => $this->purgeExpiredMagic())];
                    $ran[] = ['ratelimit', $this->task('ratelimit', fn() => $this->pruneRateLimits())];
                    $ran[] = ['cpi',   $this->task('cpi',   fn() => $this->recomputeCpi())];
                    $ran[] = ['snapshot', $this->task('snapshot', fn() => $this->captureSnapshots())];
                    $ran[] = ['collusion', $this->task('collusion', fn() => $this->scanCollusion())];
                    $ran[] = ['reminder', $this->task('reminder', fn() => $this->sendVotingReminders())];
                    $ran[] = ['digest', $this->task('digest', fn() => $this->recordDigest())];
                    $ran[] = ['cronlog', $this->task('cronlog', fn() => $this->trimCronLog())];
                })(),
                default     => $this->log("Unknown task: $task"),
            };
        }

        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        try {
            DB::table('gates_cron_log')->insert([
                'job_name'   => 'maintenance',
                // 'success' unconditionally was a lie the admin console repeated back:
                // the run "succeeded" while the exception that ended it was in a file
                // nobody could open. The column is ENUM('success','error'), so a run with
                // any failed task is an error — and the reasons travel in `message`,
                // which is what the console already shows.
                'status'     => $this->failures === [] ? 'success' : 'error',
                'message'    => json_encode(
                    $this->failures === [] ? $ran : ['ran' => $ran, 'failures' => $this->failures],
                    JSON_UNESCAPED_SLASHES
                ),
                'runtime_ms' => $ms,
                'ran_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {}

        $this->log($this->failures === []
            ? 'Done.'
            : 'Done, with ' . count($this->failures) . ' failed task(s): ' . implode(', ', array_keys($this->failures)));

        return [
            'task'       => $task,
            'ran'        => $ran,
            // Present and empty on a clean run, so a caller can test it without having to
            // know whether the key exists.
            'failures'   => $this->failures,
            'lines'      => $this->lines,
            'runtime_ms' => $ms,
        ];
    }

    /**
     * Opportunistic "web cron" — run due maintenance off normal site traffic, for
     * hosts with no shell cron AND no external scheduler. Called after the HTTP
     * response is flushed (see public/index.php). Cost on a normal request is one
     * filemtime() check; it only touches the DB / runs work when actually due.
     *
     * Guards: a sentinel-file throttle (fast, no DB), then an authoritative
     * gates_cron_log due-check, then the settings toggle, then the single-instance
     * lock. Enabled by the admin (webcron_auto) — off by default so it never
     * surprises a host that already has real cron.
     *
     * @return array{skipped?:string, task?:string, ran?:array, runtime_ms?:int}
     */
    public static function tick(?ContainerInterface $container = null, int $intervalSec = 840): array
    {
        $root     = dirname(__DIR__, 2);
        $sentinel = $root . '/var/data/.maintenance_tick';
        $now      = time();

        // Fast throttle — most requests stop here with just a filemtime() call.
        if (is_file($sentinel) && ($now - (int) @filemtime($sentinel)) < $intervalSec) {
            return ['skipped' => 'throttled'];
        }
        if (!self::autoEnabled()) return ['skipped' => 'disabled'];

        // Authoritative due-check against the cron log (the sentinel can lie if the
        // FS isn't writable or was cleared).
        try {
            $last = DB::table('gates_cron_log')->where('job_name', 'maintenance')->max('ran_at');
            if ($last !== null && strtotime((string) $last) > $now - $intervalSec) {
                @touch($sentinel);
                return ['skipped' => 'recent'];
            }
        } catch (\Throwable) {}

        if (!\AfricaGates\Support\CronGuard::acquire('maintenance', $root . '/var/data')) {
            return ['skipped' => 'locked'];
        }
        @touch($sentinel);
        return (new self($container, false))->run('auto');
    }

    /** Whether the admin has enabled opportunistic web-traffic maintenance. */
    public static function autoEnabled(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'webcron_auto')->value('value');
            return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
        } catch (\Throwable) { return false; }
    }

    private function purgeExpiredOtp(): int
    {
        $c = (int) DB::table('gates_otp_tokens')->where('expires_at', '<', Carbon::now()->subDays(7))->delete();
        $this->log("OTP purge: $c rows");
        return $c;
    }

    private function purgeExpiredMagic(): int
    {
        try {
            $c = (int) DB::table('gates_magic_links')->where('expires_at', '<', Carbon::now()->subDays(3))->delete();
            $this->log("Magic-link purge: $c rows");
            return $c;
        } catch (\Throwable $e) { return 0; }
    }

    private function pruneCache(): int
    {
        $c = (int) DB::table('gates_cache')->where('expires_at', '<', Carbon::now())->delete();
        $this->log("Cache prune: $c rows");
        return $c;
    }

    private function pruneRateLimits(): int
    {
        try {
            $c = (int) DB::table('gates_rate_limits')->where('window_start', '<', Carbon::now()->subDay())->delete();
            $this->log("Rate-limit prune: $c rows");
            return $c;
        } catch (\Throwable $e) { return 0; }
    }

    private function pruneShareLinks(): int
    {
        try {
            $c = (int) DB::table('gates_nomination_links')->where('expires_at', '<', Carbon::now()->subDays(7))->delete();
            $this->log("Share-link prune: $c rows");
            return $c;
        } catch (\Throwable $e) { return 0; }
    }

    private function trimCronLog(): int
    {
        try {
            $c = (int) DB::table('gates_cron_log')->where('ran_at', '<', Carbon::now()->subDays(90))->delete();
            $this->log("Cron-log trim: $c rows");
            return $c;
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * Delegates to the single {@see \AfricaGates\Services\CycleMaterialiser}.
     *
     * This method used to be a SECOND, weaker lifecycle engine — and the only
     * one actually scheduled. It jumped straight to the target status with no
     * forward-only guard, wrote no transitions ledger row, and never promoted
     * winners, so cycles reached 'results' and crowned nobody. Meanwhile the
     * documented, tested engine in CycleAdvanceCommand was scheduled nowhere.
     * There is now one implementation behind both entry points.
     */
    private function advanceCycles(): int
    {
        $engine = new \AfricaGates\Services\CycleMaterialiser(false, $this->cacheService());
        $r = $engine->run();
        foreach ($engine->lines() as $line) {
            $this->log($line);
        }

        // Traffic-independent divergence check. Anything reported here means the
        // materialised status is behind its own declared boundary — the state a
        // computed phase is designed to survive, but which an operator still
        // needs to see. It lands in gates_cron_log, which the admin surfaces.
        foreach (\AfricaGates\Services\CycleMaterialiser::divergences() as $d) {
            $this->log(sprintf(
                '  ! DIVERGENCE cycle #%d: stored %s, computed %s, boundary %s passed %dh ago',
                $d['cycle_id'], $d['stored_status'], $d['computed_phase'],
                (string) $d['boundary_at'], (int) floor($d['seconds_behind'] / 3600)
            ));
        }

        // Schema integrity, beside the phase divergences and for the same reason:
        // both are conditions the platform keeps running through, and both are
        // invisible unless something says so on a schedule. A missing per-voter
        // idempotency constraint means a retried vote can be counted twice — it
        // must not wait to be discovered by a double-counted result.
        foreach (\AfricaGates\Services\VoteIndexRepair::warnings() as $w) {
            $this->log(sprintf('  ! SCHEMA %s: %s  fix: %s',
                strtoupper((string) $w['severity']), $w['message'], $w['fix']));
        }

        return (int) $r['changed'];
    }

    /** The container's CacheService when available, otherwise a bare one. */
    private function cacheService(): \AfricaGates\Services\CacheService
    {
        try {
            if ($this->container && $this->container->has(\AfricaGates\Services\CacheService::class)) {
                return $this->container->get(\AfricaGates\Services\CacheService::class);
            }
        } catch (\Throwable) {}
        return new \AfricaGates\Services\CacheService();
    }

    /**
     * Re-verify stale pending payments and confirm the genuinely-paid ones.
     *
     * `payments:reconcile` existed, was documented as "schedule every few minutes",
     * and was scheduled NOWHERE — not here, not in cron/maintenance.php. So the
     * documented backstop for a dropped gateway callback never ran, and a supporter
     * whose browser closed on the return trip stayed 'pending' with their money taken
     * until someone read `cycles:audit` by hand.
     *
     * SMALL LIMIT ON PURPOSE. Every candidate row costs one blocking server-to-server
     * verify PER ENABLED GATEWAY (gates_donations stores no provider, so each reference
     * is offered to each gateway until one recognises it). This same orchestrator also
     * runs from `Maintenance::tick()` — off ordinary web traffic, in a request-scoped
     * process with a max_execution_time — so the batch has to be small enough that a
     * backlog cannot turn one page view into a minute of outbound HTTP. In steady state
     * there are only ever a handful of rows older than fifteen minutes; clearing a real
     * backlog is `bin/console payments:reconcile --limit 200` from a shell.
     */
    /**
     * Give back money for votes that were paid for and never counted.
     *
     * The rule, the ceilings and the double-payment guards are all in
     * {@see RefundService} — see its class note. This stays a one-line call
     * because the one thing maintenance must not do is develop opinions about
     * when money moves.
     */
    private function refundUnminted(): int
    {
        try {
            if (!\AfricaGates\Services\RefundService::autoEnabled()) {
                $this->log('refunds: automatic refunds are switched off');
                return 0;
            }
            $n = (new \AfricaGates\Services\RefundService(
                new \AfricaGates\Services\PaymentService(), $this->mailer()
            ))->sweep();
            $this->log('refunds: ' . $n . ' unminted order(s) refunded');
            return $n;
        } catch (\Throwable $e) {
            $this->log('refunds error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Let the support assistant answer the tickets it can.
     *
     * Runs here rather than in the request that opens a ticket: two model calls
     * and a gateway round-trip do not belong in front of somebody pressing a
     * button, and a support desk that answers a minute later is a support desk
     * answering quickly. Every safety rule lives in the resolver — see its class
     * note — so this stays a one-line call that cannot smuggle in an exception.
     */
    private function answerTickets(): int
    {
        try {
            $r = new \AfricaGates\Services\SupportAutoResolver(
                new \AfricaGates\Services\SupportAgentService(
                    \AfricaGates\Services\AiService::boot(),
                    new \AfricaGates\Services\SupportTicketService($this->mailer())
                ),
                new \AfricaGates\Services\SupportTicketService($this->mailer())
            );
            if (!$r->available()) { $this->log('support: assistant unavailable, skipped'); return 0; }

            $n = $r->sweep();
            $this->log('support: ' . $n . ' ticket(s) answered');
            return $n;
        } catch (\Throwable $e) {
            $this->log('support error: ' . $e->getMessage());
            return 0;
        }
    }

    private function reconcilePayments(): int
    {
        try {
            $cmd = new \AfricaGates\Console\Commands\PaymentReconcileCommand(
                new \AfricaGates\Services\PaymentService(),
                $this->mailer()
            );
            $out  = new \Symfony\Component\Console\Output\BufferedOutput();
            $code = $cmd->run(new ArrayInput(['--minutes' => '15', '--limit' => '25']), $out);
            foreach (array_filter(array_map('trim', explode("\n", $out->fetch()))) as $line) {
                $this->log('payments: ' . $line);
            }
            return $code === 0 ? 1 : 0;
        } catch (\Throwable $e) {
            $this->log('payments error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * One recovery email per abandoned checkout, once ever.
     *
     * Runs on every tick rather than daily because the value of the nudge decays fast:
     * the supporter still has the tab open, still remembers why they were buying votes,
     * and a close race can move overnight. {@see CheckoutMailer::GRACE_MINUTES} keeps it
     * off the shoulder of anyone still at the gateway.
     */
    private function mailAbandonedCheckouts(): int
    {
        try {
            // Prefer the container's configured mailer (shared settings + logger) over
            // the service booting its own.
            \AfricaGates\Services\CheckoutMailer::using($this->mailer());
            $r = \AfricaGates\Services\CheckoutMailer::sweepAbandoned();
            if ($r['considered'] > 0) {
                $this->log("Checkout recovery: {$r['sent']} sent, {$r['skipped']} skipped"
                    . ($r['reasons'] ? ' (' . json_encode($r['reasons']) . ')' : ''));
            }
            return (int) $r['sent'];
        } catch (\Throwable $e) {
            $this->log('Checkout recovery error: ' . $e->getMessage());
            return 0;
        }
    }

    /** The container's configured mailer, when there is a container. */
    private function mailer(): ?OtpService
    {
        try {
            if ($this->container && $this->container->has(OtpService::class)) {
                return $this->container->get(OtpService::class);
            }
        } catch (\Throwable) {}
        return null;
    }

    private function recomputeCpi(): int
    {
        // In-process (no exec) so webcron works where shell execution is disabled.
        try {
            $code = (new CpiRecomputeCommand())->run(new ArrayInput([]), new NullOutput());
            $this->log('cpi: recompute exit ' . $code);
            return $code === 0 ? 1 : 0;
        } catch (\Throwable $e) {
            $this->log('cpi error: ' . $e->getMessage());
            return 0;
        }
    }

    private function captureSnapshots(): int
    {
        try {
            $n = (new SnapshotService())->capture();
            $this->log("Snapshots captured: $n");
            return $n;
        } catch (\Throwable $e) {
            $this->log('Snapshot error: ' . $e->getMessage());
            return 0;
        }
    }

    private function scanCollusion(): int
    {
        try {
            $r = (new CollusionService())->scan();
            if ($r['findings'] > 0) {
                $this->log("Collusion: {$r['findings']} finding(s) — " . json_encode($r['by_kind']));
            }
            return $r['findings'];
        } catch (\Throwable $e) {
            $this->log('Collusion error: ' . $e->getMessage());
            return 0;
        }
    }

    private function drainJobs(): int
    {
        try {
            $q = new QueueService();
            $sheets = $this->container?->get(GoogleSheetsService::class);
            $q->on('vote.sheets_push', function (array $p) use ($sheets) { $sheets?->pushVote($p); });
            $sms = SmsService::boot();
            $q->on(SmsService::JOB_SMS, function (array $p) use ($sms) {
                $sms->deliver('sms', (string)($p['to'] ?? ''), (string)($p['body'] ?? ''), (string)($p['template'] ?? 'generic'));
            });
            $q->on(SmsService::JOB_WHATSAPP, function (array $p) use ($sms) {
                $sms->deliver('whatsapp', (string)($p['to'] ?? ''), (string)($p['body'] ?? ''), (string)($p['template'] ?? 'generic'));
            });
            $q->on(NominationTriageService::JOB_TRIAGE, function (array $p) {
                NominationTriageService::generate((int)($p['nomination_id'] ?? 0));
            });
            $mailer = $this->container?->get(OtpService::class);
            $q->on('community.reply_email', function (array $p) use ($mailer) {
                if (!$mailer) return;
                $u = DB::table('gates_users')->where('id', (int)($p['author_user_id'] ?? 0))->where('status', 'active')->first();
                if (!$u || empty($u->email)) return;
                $esc  = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $base = rtrim((string) Env::get('APP_URL', ''), '/');
                $url  = $base . '/community/' . rawurlencode((string)($p['slug'] ?? ''));
                $html = '<p>Hi ' . $esc($u->name) . ',</p>'
                    . '<p><strong>' . $esc($p['replier'] ?? 'A member') . '</strong> replied to your community thread '
                    . '&ldquo;<strong>' . $esc($p['title'] ?? '') . '</strong>&rdquo;.</p>'
                    . '<p><a href="' . $esc($url) . '" style="display:inline-block;background:#237b22;color:#fff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:999px">Read the reply &rarr;</a></p>';
                $mailer->sendBranded((string)$u->email, 'New reply to your thread — Africa GATES', $html, strip_tags($html), 'Community');
            });
            $r = $q->work(50);
            if ($r['done'] || $r['failed'] || $r['retried']) {
                $this->log("Queue: {$r['done']} done, {$r['retried']} retried, {$r['failed']} failed");
            }
            return $r['done'];
        } catch (\Throwable $e) {
            $this->log('Queue error: ' . $e->getMessage());
            return 0;
        }
    }

    private function sendPendingAcknowledgements(): int
    {
        try {
            $sla = 48;
            try { $v = DB::table('gates_settings')->where('key_name', 'review_sla_hours')->value('value'); if ($v !== null) $sla = max(1, (int)$v); } catch (\Throwable $e) {}
            $pending = NominationFeedbackService::pendingNeedingAck($sla * 2, 200);
            if (!$pending) return 0;
            $mailer = $this->container?->get(OtpService::class);
            if (!$mailer || !$mailer->smtpConfigured()) return 0;
            $sent = 0;
            foreach ($pending as $nom) {
                if (!filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
                    NominationFeedbackService::markAcked((int)$nom->id);
                    continue;
                }
                $by = htmlspecialchars((string)$nom->nominator_name, ENT_QUOTES, 'UTF-8');
                $nn = htmlspecialchars((string)$nom->nominee_name, ENT_QUOTES, 'UTF-8');
                $ref = htmlspecialchars((string)($nom->reference ?? ''), ENT_QUOTES, 'UTF-8');
                $html = "<p>Hi <strong>{$by}</strong>,</p><p>A quick note that your nomination of <strong>{$nn}</strong>"
                    . ($ref !== '' ? " (ref {$ref})" : '') . " is still with our review team. Thank you for your patience — "
                    . "we review every nomination by hand to keep the awards fair, and you'll hear from us the moment there's a decision.</p>";
                try {
                    $mailer->sendBranded((string)$nom->nominator_email, "Your nomination of {$nom->nominee_name} is still under review",
                        $html, "Hi {$nom->nominator_name},\n\nYour nomination of {$nom->nominee_name} is still under review. You'll hear from us as soon as there's a decision.\n\n— Africa GATES", 'Nominations');
                    NominationFeedbackService::markAcked((int)$nom->id);
                    $sent++;
                } catch (\Throwable $e) { /* try again next run */ }
            }
            $this->log("Nomination acknowledgements sent: {$sent}");
            return $sent;
        } catch (\Throwable $e) { $this->log('Ack error: ' . $e->getMessage()); return 0; }
    }

    private function sendVotingReminders(): int
    {
        $count = 0;
        try {
            $now = Carbon::now();
            $cycles = DB::table('gates_award_cycles')
                ->where('status', 'voting')
                ->whereNotNull('voting_close')
                ->whereBetween('voting_close', [
                    $now->copy()->addHours(24)->toDateTimeString(),
                    $now->copy()->addHours(48)->toDateTimeString(),
                ])
                ->get();
            if ($cycles->isEmpty()) return 0;

            $mailer = $this->container?->get(OtpService::class);
            if (!$mailer || !$mailer->smtpConfigured()) return 0;

            $catIds = DB::table('gates_award_categories')->whereIn('cycle_id', $cycles->pluck('id'))->pluck('id');
            $topNominees = DB::table('gates_nominees')
                ->whereIn('category_id', $catIds)
                ->where('status', 'approved')
                ->orderByDesc('vote_count')
                ->limit(5)->get()->all();

            $cycleNames  = $cycles->map(fn($c) => $c->edition_label ?? '2026 Cycle')->implode(' · ');
            $closingDate = Carbon::parse($cycles->first()->voting_close)->format('D, d M Y');

            $profileEmails    = DB::table('gates_profiles')->where('status', 'approved')->whereNotNull('email')->pluck('email')->all();
            $newsletterEmails = DB::table('gates_newsletter')->whereNull('unsubscribed_at')->whereNotNull('email')->pluck('email')->all();
            $emails = array_values(array_unique(array_filter(array_merge($profileEmails, $newsletterEmails))));

            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $mailer->sendVotingReminder($email, $cycleNames, $closingDate, $topNominees);
                $count++;
            }
            $this->log("Voting reminders sent: {$count}");
        } catch (\Throwable $e) {
            $this->log('Reminder error: ' . $e->getMessage());
        }
        return $count;
    }

    private function recordDigest(): int
    {
        $today = Carbon::now()->startOfDay()->toDateTimeString();
        $stats = [
            'votes_24h' => (int) DB::table('gates_votes')->where('voted_at', '>=', $today)->count(),
            'noms_24h'  => (int) DB::table('gates_nominations')->where('created_at', '>=', $today)->count(),
            'regs_24h'  => (int) DB::table('gates_profiles')->where('registered_at', '>=', $today)->count(),
            'cheers_24h'=> (int) DB::table('gates_cheers')->where('created_at', '>=', $today)->count(),
        ];
        try {
            DB::table('gates_activity')->insert([
                'kind' => 'legacy',
                'actor_label' => 'system',
                'target_type' => null,
                'target_id'   => null,
                'target_label'=> 'Daily digest',
                'meta'        => json_encode($stats),
                'is_public'   => 0,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
            $this->log('Digest: ' . json_encode($stats));
        } catch (\Throwable $e) {}
        return 1;
    }
}
