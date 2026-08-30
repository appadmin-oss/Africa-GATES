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
 * the collusion scan, reminders, acknowledgements, digest and the standings-chain
 * verification (the one task that exists to FAIL — see verifyChain). Named tasks
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
            // Partner payouts, on every tick. Webhooks are the primary signal and are not a
            // guarantee: Paystack retries a failed endpoint for up to 72 hours and then STOPS,
            // and its own status history records an incident of degraded webhook delivery. A
            // charity's payout left in an unknown state is exactly the thing nobody notices
            // until they ask where their money is.
            $ran[] = ['payouts',       $this->task('payouts',       fn() => $this->sweepPartnerPayouts())];
            // Stand offers nobody accepted in time. On every tick rather than daily, because
            // the place an expired offer is holding was promised to a waiting list that was
            // told it would move — and a list that only moves at 06:00 is a list that does
            // not move on the day the organiser is filling the last three pitches.
            $ran[] = ['standoffers',   $this->task('standoffers',   fn() => $this->expireStandOffers())];
            // The interview recording bot. On every tick, and it has to be: this is the
            // path that sends a bot to a sitting starting in ten minutes, reads the
            // transcript out of one that is running, and pulls a bot out of one that
            // finished. Attendee will also call the webhook, which is faster — but a
            // cPanel host cannot be relied on to receive it (see InterviewBotController),
            // so polling is the primary path and the callback is the optimisation.
            $ran[] = ['interviewbot',  $this->task('interviewbot',
                fn() => \AfricaGates\Services\InterviewBot::sweep())];
            // What the platform looked like on this tick, for /status to show a history
            // with. LAST in the every-tick block on purpose: it records the state AFTER the
            // queue has drained and payments have reconciled, which is the state a visitor
            // would have found. Recording first would report a backlog this very run was
            // about to clear.
            //
            // A GAP in that table is itself the evidence that scheduled work stopped — the
            // one outage no self-report can cover, because the thing that would report it is
            // the thing that stopped.
            $ran[] = ['status', $this->task('status',
                fn() => \AfricaGates\Services\SystemStatus::record())];
            // Every hour
            if ((int)$now->minute < 15) {
                $ran[] = ['otp',        $this->task('otp',        fn() => $this->purgeExpiredOtp())];
                $ran[] = ['magic',      $this->task('magic',      fn() => $this->purgeExpiredMagic())];
                $ran[] = ['ratelimit',  $this->task('ratelimit',  fn() => $this->pruneRateLimits())];
                $ran[] = ['sharelinks', $this->task('sharelinks', fn() => $this->pruneShareLinks())];
                // Ticket links, alongside the other expiring-token tables above. The
                // service shipped with prune() written, documented and tested — and with
                // no caller anywhere, so nothing had ever run it and every dead link was
                // permanent. A pruner nobody calls is not a retention policy.
                $ran[] = ['ticketlinks', $this->task('ticketlinks',
                    fn() => \AfricaGates\Services\TicketLinkService::prune())];
                // Abandoned FREE registrations, tidied off the attendee list. Priced holds are
                // deliberately excluded — those belong to the reconciliation sweep above,
                // which asks the gateway before writing anything off. Same shape as the
                // ticket-link pruner beside it: written, documented, and with no caller for
                // its whole life, so nothing had ever run it.
                $ran[] = ['event-holds', $this->task('event-holds',
                    fn() => \AfricaGates\Services\EventTicketService::releaseExpired())];
                // Inbound gateway deliveries, past every retry schedule and dispute window.
                $ran[] = ['gwevents', $this->task('gwevents',
                    fn() => \AfricaGates\Services\GatewayEventLog::prune())];
                $ran[] = ['triage-backfill', $this->task('triage-backfill', fn() => NominationTriageService::backfill(100))];
                // Dossier maps for ballots that are open, made before the panel arrives.
                //
                // The map is cached per NOMINEE and shared by the whole panel, so one made
                // here is byte-identical to the one the first judge to press the button
                // would have waited up to thirty seconds for — and it is made once instead
                // of once per judge who gets there first. The cost is the same; only the
                // waiting moves, and a judge opening a shortlist of forty was otherwise
                // looking at forty presses and forty waits.
                //
                // HOURLY rather than every tick, and capped per run: this is the only task
                // here that spends money, and six maps is about three minutes of a cPanel
                // host's execution time at the worst case. Six an hour finishes a large
                // shortlist inside a day, which is well before a round opens — the only
                // deadline this has. See JudgeAssist::sweep().
                $ran[] = ['judgemaps', $this->task('judgemaps',
                    fn() => \AfricaGates\Services\JudgeAssist::sweep())];
                // Refused bot questions. Machine output, but generated FROM a nominee's
                // recorded speech, so it expires like everything else here rather than
                // being the one table kept forever.
                $ran[] = ['guardlog', $this->task('guardlog',
                    fn() => \AfricaGates\Services\InterviewGuard::prune())];
                $ran[] = ['maillog', $this->task('maillog', fn() => (int) DB::table('gates_mail_log')->where('created_at', '<', Carbon::now()->subDays(30))->delete())];
                // Status snapshots, on the same 30-day retention and beside the log they
                // are kept alongside. A recorder with no pruner is a table that grows for
                // the life of the deployment.
                $ran[] = ['statuslog', $this->task('statuslog',
                    fn() => \AfricaGates\Services\SystemStatus::prune())];
                // Expired Gemini file-upload handles. The provider deletes the file itself
                // after 48 hours; this drops OUR record of it, which is the half that
                // matters — a stale row would be handed to generateContent as a live
                // reference and the call would fail on a file that no longer exists.
                $ran[] = ['aifiles', $this->task('aifiles',
                    fn() => \AfricaGates\Services\GeminiFiles::prune())];
            }
            // ── REMINDERS FOR A CEREMONY'S GUESTS OF HONOUR ──────────────────
            //
            // HOURLY ONCE THE DAY'S WINDOW HAS OPENED, not once beside the voting
            // reminder at 06:00, and the reason is arithmetic rather than taste. The
            // sweep is capped per tick (InviteReminders::CAP) because a shared host's
            // max_execution_time is the real ceiling on an unattended run — and at one
            // tick a day a four-hundred-person hall would take ten days to remind, by
            // which time the mark it was reminding them about is long behind us. Ticking
            // on through the day clears it the same day.
            //
            // AND NOT BEFORE THE TIME THE OPERATOR SET. This is the only mail on this
            // platform whose moment is chosen rather than triggered by something the
            // recipient did — so it is the only one that can arrive at 03:00 for no
            // reason, and the only one where "what time do these go out?" deserves an
            // answer other than "whenever the cron reaches it". The window opens at that
            // time and runs to the end of the day, because the sweep is capped per tick
            // and a large hall needs several of them. See InviteReminders::dueNow().
            if ((int)$now->minute < 15 && \AfricaGates\Services\InviteReminders::dueNow($now)) {
                $ran[] = ['invite-reminders', $this->task('invite-reminders',
                    fn() => \AfricaGates\Services\InviteReminders::sweep())];
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
                // Questionnaire disqualification. DAILY at 06:00 and never on the
                // every-tick path: this takes nominations away, and a rule that acts on
                // people should fire on a schedule an organiser can predict and be awake
                // for — not at 03:12 because that is when a cron happened to land.
                $ran[] = ['qdisqualify', $this->task('qdisqualify', fn() => $this->enforceQuestionnaireDeadlines())];
                $ran[] = ['cronlog',   $this->task('cronlog',   fn() => $this->trimCronLog())];
                $ran[] = ['chain',     $this->task('chain',     fn() => $this->verifyChain())];
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
                'payouts'   => $ran[] = ['payouts', $this->task('payouts', fn() => $this->sweepPartnerPayouts())],
                'standoffers' => $ran[] = ['standoffers', $this->task('standoffers', fn() => $this->expireStandOffers())],
                'digest'    => $ran[] = ['digest', $this->task('digest', fn() => $this->recordDigest())],
                'qdisqualify' => $ran[] = ['qdisqualify', $this->task('qdisqualify', fn() => $this->enforceQuestionnaireDeadlines())],
                'chain'     => $ran[] = ['chain', $this->task('chain', fn() => $this->verifyChain())],
                // Addressable by name because there is no SSH on this account: when a round
                // opens sooner than the hourly sweep can fill it, `/__cron/run?task=judgemaps`
                // is the only way anybody can ask for another batch.
                'judgemaps' => $ran[] = ['judgemaps', $this->task('judgemaps',
                    fn() => \AfricaGates\Services\JudgeAssist::sweep())],
                // Addressable for the same reason judgemaps is: an operator who has just
                // sent a run and wants the first reminders to go now cannot wait for the
                // next tick, and there is no shell on this host to make them go.
                'invite-reminders' => $ran[] = ['invite-reminders', $this->task('invite-reminders',
                    fn() => \AfricaGates\Services\InviteReminders::sweep())],
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
                    $ran[] = ['chain', $this->task('chain', fn() => $this->verifyChain())];
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
     * ── AND IT TURNS ITSELF ON WHEN NOTHING ELSE IS RUNNING ──────────────────
     *
     * "Off by default so it never surprises a host that already has real cron" is
     * the right default and the wrong outcome, because it assumes somebody
     * configured cron. This platform is deployed by uploading a zip through cPanel
     * File Manager; adding a cron job is a separate manual step in a different
     * screen, and if it is skipped there is no symptom. Pages serve, votes are
     * cast, checkouts complete.
     *
     * What silently does not happen is every automatic money decision on the
     * platform: reconciliation confirming payments whose callback was dropped,
     * the refund sweep returning money for votes that could not be minted, and the
     * assistant repairing payment tickets. Supporters who are owed money are simply
     * not paid, and it is discovered when they complain.
     *
     * {@see CronHealth} exists precisely because that failure is invisible, and it
     * can answer the one question that makes this decision safe: has the schedule
     * PROVABLY missed work? If it has, then whatever the operator intended, real
     * cron is not running — so the fallback is not surprising anybody. It engages.
     *
     * Three things make that defensible rather than reckless:
     *
     *   · The work happens AFTER the response is flushed, and is skipped outright on
     *     a SAPI where it cannot detach (see public/index.php). A visitor never
     *     waits for it.
     *   · An explicit `webcron_auto` of off/0/no always wins. An operator who has
     *     decided against this keeps their decision.
     *   · It LATCHES, by writing the setting once. Without that it would oscillate:
     *     engaging on a stale schedule, running, thereby making the schedule fresh,
     *     un-engaging, and going stale again six hours later — which is a run every
     *     six hours dressed up as a run every fifteen minutes. Latching also makes
     *     the decision visible in Admin → Settings and reversible there, rather than
     *     being recomputed invisibly on every request.
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
        if (!self::autoEnabled() && !self::adoptWhenNothingElseRuns()) {
            return ['skipped' => 'disabled'];
        }

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
        return self::settingIs(['1', 'true', 'on', 'yes']);
    }

    /**
     * Has an operator explicitly said NO to opportunistic maintenance?
     *
     * Distinct from "not enabled". An unset setting is an absence of a decision,
     * which {@see adoptWhenNothingElseRuns()} may act on; an explicit off is a
     * decision, and it is final. Collapsing the two would mean a fallback that
     * cannot be switched off, which is a worse fault than the one it fixes.
     */
    public static function autoRefused(): bool
    {
        return self::settingIs(['0', 'false', 'off', 'no']);
    }

    /** @param list<string> $truthy */
    private static function settingIs(array $truthy): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'webcron_auto')->value('value');
            return in_array(strtolower(trim((string) $v)), $truthy, true);
        } catch (\Throwable) { return false; }
    }

    /**
     * Take over the schedule when nothing else is running it.
     *
     * Returns true when this request should go on to run maintenance despite the
     * setting being unset — and, when it does, it PERSISTS the decision so the
     * schedule keeps ticking rather than oscillating. See the note on {@see tick()}
     * for why that latch is required and why this is safe.
     *
     * Never overrides an explicit refusal, and never fires on a fresh install:
     * {@see CronHealth::neverRun()} is true on day zero before anything has had a
     * chance to run, so it is paired with an installation old enough for a missed
     * run to be real information rather than a race with the first cron tick.
     */
    private static function adoptWhenNothingElseRuns(): bool
    {
        if (!self::shouldAdopt()) return false;

        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => 'webcron_auto'],
                ['value' => '1', 'updated_at' => Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable $e) {
            // Could not latch. Still run this time — the work is more important than
            // the bookkeeping — but say so, because an unlatched fallback will look
            // like a schedule that runs every six hours.
            error_log('[webcron tick] adopting the schedule but could not persist '
                    . 'webcron_auto: ' . $e->getMessage());
        }
        error_log('[webcron tick] no completed maintenance run in the last '
                . CronHealth::STALE_HOURS . 'h — running maintenance from web traffic '
                . 'instead. Set webcron_auto=off in Settings to stop this.');
        return true;
    }

    /**
     * Should this site run its own schedule, given that nobody enabled it?
     *
     * The decision only — no writes, no logging — so it can be asked by a
     * diagnostic page and asserted by a test without changing anything. The latch
     * and the log live in {@see adoptWhenNothingElseRuns()}, which is the one place
     * that acts on the answer.
     */
    public static function shouldAdopt(): bool
    {
        // An explicit decision by an operator is final. An UNSET setting is the
        // absence of a decision, which is a different thing and the only case this
        // acts on.
        if (self::autoRefused() || self::autoEnabled()) return false;

        if (CronHealth::isStale()) return true;

        // Never run at all — but only once the installation is old enough that a
        // working cron would certainly have fired. On day zero `neverRun()` is true
        // simply because nothing has had its turn yet, and adopting then would be a
        // race with the operator's first real cron tick rather than a diagnosis.
        // STALE_HOURS is the platform's own definition of "work has provably been
        // missed", so the same number answers both shapes of the question.
        if (!CronHealth::neverRun()) return false;
        $age = self::installAgeHours();
        return $age !== null && $age >= CronHealth::STALE_HOURS;
    }

    /**
     * How long this installation has existed, in hours, or null if unknowable.
     *
     * Read from the oldest installer-created row rather than a marker we would have
     * to remember to write: the installer creates a programme, a cycle and an admin,
     * and no ordinary operation deletes them. A database that cannot answer returns
     * null, which reads as "do not adopt" — failing toward the previous behaviour
     * rather than toward acting on a guess.
     *
     * Every source is a CREATION timestamp, deliberately. `gates_settings.updated_at`
     * was the first choice and is wrong for exactly the reason its name gives: it
     * moves. An operator who had just saved a setting would make a year-old
     * installation read as minutes old, and adoption would be suppressed on the
     * install that needed it most.
     */
    private static function installAgeHours(): ?float
    {
        foreach ([
            ['gates_award_cycles', 'created_at'],
            ['gates_award_programmes', 'created_at'],
            ['gates_admins', 'created_at'],
        ] as [$table, $col]) {
            try {
                $at = DB::table($table)->min($col);
            } catch (\Throwable) { continue; }
            if ($at === null) continue;
            try {
                return max(0.0, Carbon::parse((string) $at)->diffInMinutes(Carbon::now(), false) / 60);
            } catch (\Throwable) { continue; }
        }
        return null;
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
    /**
     * Ask the gateway what became of partner payouts it has not told us about.
     *
     * The backstop for the webhook path. Only touches payouts that are not terminal and are
     * old enough that asking is not a race against the transfer existing — Verify Transfer
     * returns an error for a transfer Paystack has not created yet, which reads exactly like
     * a failure and would mark a healthy payout dead.
     */
    private function sweepPartnerPayouts(): int
    {
        try {
            $r = \AfricaGates\Services\OrgPayout::sweep(new \AfricaGates\Services\PaymentService());
            $this->log('payouts: checked ' . $r['checked'] . ', resolved ' . $r['changed']);
            return $r['changed'];
        } catch (\Throwable $e) {
            $this->log('payouts error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Release stand offers whose acceptance window has run out.
     *
     * The window is one of the call's published terms, so an offer that never expires is a
     * pitch held indefinitely by somebody who stopped replying — against a waiting list that
     * was told it would move. See StandApplication::OFFER_HOURS.
     */
    private function expireStandOffers(): int
    {
        try {
            $n = \AfricaGates\Services\StandApplication::expireStaleOffers();
            $this->log('stands: ' . $n . ' expired offer(s) returned to the waiting list');
            return $n;
        } catch (\Throwable $e) {
            $this->log('stands error: ' . $e->getMessage());
            return 0;
        }
    }

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

    /**
     * Re-walk the standings hash chain and FAIL THE RUN if it no longer holds.
     *
     * ── WHY THIS IS A CRON TASK AND NOT A REPORT ─────────────────────────────────
     *
     * The chain has been written on every capture since it was built, and until now
     * nothing ever read it back: SnapshotService::verify() had no caller outside the
     * unit tests. Tamper evidence nobody collects is not evidence — the record could
     * have been edited, the hashes would have registered it exactly as designed, and
     * the finding would have sat in a column no query selected.
     *
     * It throws rather than returning a number, deliberately. A task that throws is
     * recorded in $this->failures, written to gates_cron_log, and returned in the
     * webcron response body — the operator surface that already exists. A task that
     * quietly returns 0 looks identical to a healthy run with nothing to do, which is
     * precisely how the original silence happened.
     *
     * Daily, not six-hourly: it is a full walk of the archive, and a break does not
     * heal, so finding it four times a day tells nobody anything extra.
     */
    private function verifyChain(): int
    {
        $r = (new SnapshotService())->verify();

        if (!$r['ok']) {
            // Not phrased as "tampering". The overwhelmingly likelier cause is two
            // captures forking the chain, which UNIQUE(prev_hash) now forbids —
            // accusing somebody in a cron log would be both alarming and usually wrong.
            throw new \RuntimeException(sprintf(
                'THE STANDINGS CHAIN IS BROKEN at snapshot row #%d (%d row(s) before it verify). '
                . 'The record of how standings moved no longer follows from itself past that point. '
                . 'Run `bin/console standings:verify` for the full reading.',
                (int) $r['broken_at'], (int) $r['checked']));
        }

        $this->log(sprintf('Standings chain: %d row(s) verified%s',
            (int) $r['checked'],
            $r['unchained'] > 0 ? ', ' . (int) $r['unchained'] . ' pre-chain row(s) unverifiable' : ''));

        return (int) $r['checked'];
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
            // ── QUESTIONNAIRE INVITATIONS ──────────────────────────────────────
            //
            // Queued rather than sent in the request that asked for them. There is no
            // worker process on this host, so the tick IS the worker — and a cycle with
            // four hundred nominees was otherwise seven presses of a button, the last of
            // which happens twenty minutes later when somebody remembers.
            //
            // The mailer comes from the container so a deployment with no SMTP fails the
            // job honestly rather than looking delivered. deliver() re-checks that the
            // nominee has not submitted or been disqualified since it was queued.
            $mailer = $this->container?->get(\AfricaGates\Services\OtpService::class);
            $q->on(\AfricaGates\Services\QuestionnaireInvites::JOB_INVITE, function (array $p) use ($mailer) {
                \AfricaGates\Services\QuestionnaireInvites::deliver($p, $mailer);
            });
            // ── WHAT HAPPENED TO A STAND APPLICATION ───────────────────────────
            //
            // Queued by the decision, sent here. Nothing sent these before they existed: a
            // pitch was offered with a 72-hour clock, the vendor was never told, and the
            // expiry sweep below then gave the place away — silently, to somebody who had
            // gathered certificates on the strength of "you hear either way, with a reason".
            //
            // deliver() re-reads the row, so a decision changed or accepted between the
            // press and this tick does not send the superseded message.
            $q->on(\AfricaGates\Services\StandNotice::JOB_NOTICE, function (array $p) use ($mailer) {
                \AfricaGates\Services\StandNotice::deliver($p, $mailer);
            });
            // "Email me when it opens", falling due. deliver() re-reads the call, so a
            // message queued for a call that has since been closed again is dropped rather
            // than sent — announcing an open call to somebody who then follows the link to
            // a closed one is worse than silence.
            $q->on(\AfricaGates\Services\StandCallNotice::JOB_NOTICE, function (array $p) use ($mailer) {
                \AfricaGates\Services\StandCallNotice::deliver($p, $mailer);
            });
            // ── the register, asked away from the form ─────────────────────────
            //
            // A vendor's submit creates an account AND an application in one request, on a
            // phone, against a closing date. RegistryCheck allows ten seconds to connect and
            // ten to read, so a verifier having a bad afternoon would cost that person
            // twenty seconds and then a failure with nothing saved — and would invert the
            // rule the vetting design rests on, that unreachable is UNCHECKED and never a
            // refusal. Here it retries five times and its failure costs nobody an
            // application. Safe to run twice: it overwrites its own three columns and will
            // not touch a verdict a person recorded.
            $q->on(\AfricaGates\Services\PartnerOrg::JOB_REGISTRY, function (array $p) {
                \AfricaGates\Services\PartnerOrg::runRegistryCheck((int) ($p['org_id'] ?? 0));
            });
            // ── the two jobs that keep a gateway webhook inside its budget ────
            //
            // Paystack allows roughly 30 seconds for a whole webhook delivery. Sending
            // a receipt over SMTP (up to 12s) and posting to every configured outbound
            // integration (up to 8s each) inside that budget is what its docs
            // specifically tell you not to do. Both are idempotent, so a job that runs
            // twice sends one email and re-notifies at most one integration.
            $q->on(\AfricaGates\Services\CheckoutMailer::JOB_RECEIPT, function (array $p) {
                \AfricaGates\Services\CheckoutMailer::receipt((int) ($p['donation_id'] ?? 0));
            });
            $q->on(WebhookService::JOB_DISPATCH, function (array $p) {
                WebhookService::dispatch((string) ($p['event'] ?? ''),
                                         is_array($p['data'] ?? null) ? $p['data'] : []);
            });
            // The same two jobs for the other two revenue streams. Both became necessary the
            // moment `/pay/webhook` learned to confirm a shop order and an event ticket: their
            // receipts were being sent inline, which is fine on a page a human is waiting for
            // and is 12 seconds of SMTP inside a ~30-second gateway budget here. Both are
            // claimed on the row, so a job that runs twice sends one email.
            $q->on(\AfricaGates\Services\ShopOrderService::JOB_RECEIPT, function (array $p) {
                \AfricaGates\Services\ShopOrderService::receipt(
                    (int) ($p['order_id'] ?? 0), $this->container?->get(OtpService::class));
            });
            $q->on(\AfricaGates\Services\EventTicketMailer::JOB, function (array $p) {
                \AfricaGates\Services\EventTicketMailer::send(
                    (int) ($p['registration_id'] ?? 0), $this->container?->get(OtpService::class));
            });
            // A chargeback, against a 16-hour deadline after which Paystack concedes it
            // on your behalf and refunds from your balance. The mailer comes from the
            // container here, which is why this cannot happen in the webhook.
            $q->on(\AfricaGates\Services\DisputeAlert::JOB, function (array $p) {
                \AfricaGates\Services\DisputeAlert::send($p, $this->container?->get(OtpService::class));
            });
            // ── the judging interview ──────────────────────────────────────────
            //
            // The question pack reads a nominee's whole dossier and may call a model, so it
            // is built here rather than on the admin form submit that created the sitting.
            // The reading of the transcript is the same shape and much heavier.
            //
            // Both are safe to run twice: each overwrites its own column.
            $q->on(\AfricaGates\Services\InterviewBrief::JOB, function (array $p) {
                \AfricaGates\Services\InterviewBrief::build((int) ($p['interview_id'] ?? 0));
            });
            $q->on(\AfricaGates\Services\InterviewReview::JOB, function (array $p) {
                \AfricaGates\Services\InterviewReview::run((int) ($p['interview_id'] ?? 0));
            });
            $q->on(\AfricaGates\Services\InterviewService::JOB_REMIND, function (array $p) use ($sms) {
                \AfricaGates\Services\InterviewService::remind(
                    $p, $this->container?->get(OtpService::class), $sms);
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

    /**
     * Disqualify nominees who never sent their questionnaire, where an organiser asked for it.
     *
     * Opt-in per cycle and off by default, so this walks only the cycles that armed it.
     * {@see QuestionnairePolicy::enforce()} owns the decision, including the grace period
     * and the reversible record — this is only the clock.
     */
    private function enforceQuestionnaireDeadlines(): int
    {
        $n = 0;
        foreach (\AfricaGates\Services\QuestionnairePolicy::armedCycles() as $cycleId) {
            $r = \AfricaGates\Services\QuestionnairePolicy::enforce($cycleId, false);
            $n += (int) ($r['done'] ?? 0);
        }
        return $n;
    }
}
