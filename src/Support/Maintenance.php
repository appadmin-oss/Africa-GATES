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
            $ran[] = ['queue',  $this->drainJobs()];
            $ran[] = ['cycles', $this->advanceCycles()];
            $ran[] = ['cache',  $this->pruneCache()];
            // Every hour
            if ((int)$now->minute < 15) {
                $ran[] = ['otp',        $this->purgeExpiredOtp()];
                $ran[] = ['magic',      $this->purgeExpiredMagic()];
                $ran[] = ['ratelimit',  $this->pruneRateLimits()];
                $ran[] = ['sharelinks', $this->pruneShareLinks()];
                $ran[] = ['triage-backfill', NominationTriageService::backfill(100)];
                try { $ran[] = ['maillog', (int) DB::table('gates_mail_log')->where('created_at', '<', Carbon::now()->subDays(30))->delete()]; } catch (\Throwable $e) {}
            }
            // Every 6 hours: CPI recompute + tamper-evident standings snapshot
            if ($now->hour % 6 === 0 && (int)$now->minute < 15) {
                $ran[] = ['cpi',      $this->recomputeCpi()];
                $ran[] = ['snapshot', $this->captureSnapshots()];
            }
            // 06:00 daily: collusion scan + reminders + acknowledgements + digest + cron-log trim
            if ($now->hour === 6 && (int)$now->minute < 15) {
                $ran[] = ['collusion', $this->scanCollusion()];
                $ran[] = ['reminder',  $this->sendVotingReminders()];
                $ran[] = ['nom-ack',   $this->sendPendingAcknowledgements()];
                $this->recordDigest(); $ran[] = ['digest', 1];
                $ran[] = ['cronlog',   $this->trimCronLog()];
            }
        } else {
            match ($task) {
                'cycles'    => $ran[] = ['cycles', $this->advanceCycles()],
                'cpi'       => $ran[] = ['cpi',   $this->recomputeCpi()],
                'cache'     => $ran[] = ['cache', $this->pruneCache()],
                'queue'     => $ran[] = ['queue', $this->drainJobs()],
                'otp'       => $ran[] = ['otp',   $this->purgeExpiredOtp()],
                'magic'     => $ran[] = ['magic', $this->purgeExpiredMagic()],
                'collusion' => $ran[] = ['collusion', $this->scanCollusion()],
                'digest'    => $this->recordDigest(),
                'all'       => (function () use (&$ran) {
                    $ran[] = ['queue', $this->drainJobs()];
                    $ran[] = ['cycles', $this->advanceCycles()];
                    $ran[] = ['cache', $this->pruneCache()];
                    $ran[] = ['otp',   $this->purgeExpiredOtp()];
                    $ran[] = ['magic', $this->purgeExpiredMagic()];
                    $ran[] = ['ratelimit', $this->pruneRateLimits()];
                    $ran[] = ['cpi',   $this->recomputeCpi()];
                    $ran[] = ['snapshot', $this->captureSnapshots()];
                    $ran[] = ['collusion', $this->scanCollusion()];
                    $ran[] = ['reminder', $this->sendVotingReminders()];
                    $this->recordDigest(); $ran[] = ['digest', 1];
                    $ran[] = ['cronlog', $this->trimCronLog()];
                })(),
                default     => $this->log("Unknown task: $task"),
            };
        }

        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        try {
            DB::table('gates_cron_log')->insert([
                'job_name'   => 'maintenance',
                'status'     => 'success',
                'message'    => json_encode($ran),
                'runtime_ms' => $ms,
                'ran_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {}

        $this->log('Done.');
        return ['task' => $task, 'ran' => $ran, 'lines' => $this->lines, 'runtime_ms' => $ms];
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

    private function advanceCycles(): int
    {
        $now = Carbon::now();
        $changed = 0;
        try {
            $cycles = DB::table('gates_award_cycles')->where('status', '!=', 'archived')->get();
            foreach ($cycles as $c) {
                $hasWindows = $c->nominations_open || $c->nominations_close || $c->voting_open || $c->voting_close || $c->results_date;
                if (!$hasWindows) continue;
                $want = \AfricaGates\Console\Commands\CycleAdvanceCommand::statusFor($c, $now);
                if ($want === $c->status) continue;
                DB::table('gates_award_cycles')->where('id', $c->id)->update(['status' => $want]);
                $this->log("Cycle #{$c->id} (prog {$c->programme_id}, {$c->year}): {$c->status} → {$want}");
                WebhookService::dispatch('cycle.status_changed', [
                    'cycle_id'     => (int) $c->id,
                    'programme_id' => (int) $c->programme_id,
                    'year'         => (int) $c->year,
                    'from'         => (string) $c->status,
                    'to'           => (string) $want,
                ]);
                $changed++;
            }
            if ($changed > 0) {
                DB::table('gates_cache')->where('cache_key', 'like', 'awards:%')->delete();
            }
        } catch (\Throwable $e) { $this->log('Cycle advance error: ' . $e->getMessage()); }
        $this->log("Cycle advance: $changed changed");
        return $changed;
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
                $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
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

    private function recordDigest(): void
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
    }
}
