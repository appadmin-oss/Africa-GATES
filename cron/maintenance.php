<?php
/**
 * Africa GATES — Single automation hub.
 *
 * Hooked from a single cron entry (every 15 min, or hourly) — picks
 * the right operation to run based on the current minute/hour so a
 * single cron line covers everything.
 *
 *   * /15 * * * *  /usr/bin/php /path/to/cron/maintenance.php
 *
 * Manual run:  php cron/maintenance.php [task]
 *   tasks: cycles | cpi | cache | otp | magic | digest | all  (default: auto)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

$capsule = new DB();
$capsule->addConnection(require $root . '/config/database.php');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Single-instance guard — a run that overruns its 15-min window must not overlap
// the next tick (double reminders, double job-drains, SQLite write contention).
if (!\AfricaGates\Support\CronGuard::acquire('maintenance', $root . '/var/data')) {
    fwrite(STDERR, "[maintenance] another run is still in progress — exiting.\n");
    exit(0);
}

// DI container so the cron's queue worker + reminder sender can resolve services
// (GoogleSheetsService, OtpService). Without it, the functions' `global $container`
// was null and the Google Sheets vote-sync + voting-reminder emails silently no-opped.
$builder = new \DI\ContainerBuilder();
$builder->addDefinitions(require $root . '/config/container.php');
$container = $builder->build();

// Wall-clock for gates_cron_log.runtime_ms (previously always written null).
$startedAt = microtime(true);

$now = Carbon::now();
$task = $argv[1] ?? 'auto';
$ran = [];

function log_line(string $msg): void { echo '[' . date('c') . "] $msg\n"; }

function purgeExpiredOtp(): int {
    $c = (int)DB::table('gates_otp_tokens')->where('expires_at', '<', Carbon::now()->subDays(7))->delete();
    log_line("OTP purge: $c rows");
    return $c;
}
function purgeExpiredMagic(): int {
    try {
        $c = (int)DB::table('gates_magic_links')->where('expires_at', '<', Carbon::now()->subDays(3))->delete();
        log_line("Magic-link purge: $c rows");
        return $c;
    } catch (\Throwable $e) { return 0; }
}
function pruneCache(): int {
    $c = (int)DB::table('gates_cache')->where('expires_at', '<', Carbon::now())->delete();
    log_line("Cache prune: $c rows");
    return $c;
}
function pruneRateLimits(): int {
    try {
        $c = (int)DB::table('gates_rate_limits')->where('window_start', '<', Carbon::now()->subDay())->delete();
        log_line("Rate-limit prune: $c rows");
        return $c;
    } catch (\Throwable $e) { return 0; }
}
function pruneShareLinks(): int {
    // Expired prefill links carry nominee contact details — remove the payload
    // promptly (7-day grace after expiry for "my link stopped working" support).
    try {
        $c = (int)DB::table('gates_nomination_links')->where('expires_at', '<', Carbon::now()->subDays(7))->delete();
        log_line("Share-link prune: $c rows");
        return $c;
    } catch (\Throwable $e) { return 0; }
}
function trimCronLog(): int {
    try {
        $c = (int)DB::table('gates_cron_log')->where('ran_at', '<', Carbon::now()->subDays(90))->delete();
        log_line("Cron-log trim: $c rows");
        return $c;
    } catch (\Throwable $e) { return 0; }
}
/**
 * Auto-advance award cycle statuses from their date windows so the platform
 * runs its own lifecycle (upcoming → nominations → voting → judging → results)
 * — no admin needs to flip a dropdown, and the public site never goes dark.
 * Reuses the exact logic in CycleAdvanceCommand::statusFor() (single source).
 */
function advanceCycles(): int {
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
            log_line("Cycle #{$c->id} (prog {$c->programme_id}, {$c->year}): {$c->status} → {$want}");
            \AfricaGates\Services\WebhookService::dispatch('cycle.status_changed', [
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
    } catch (\Throwable $e) { log_line('Cycle advance error: ' . $e->getMessage()); }
    log_line("Cycle advance: $changed changed");
    return $changed;
}
function recomputeCpi(): int {
    // Re-use the console command's logic by shelling out.
    $bin = dirname(__DIR__) . '/bin/console';
    $out = [];
    @exec("php " . escapeshellarg($bin) . " cpi:recompute 2>&1", $out, $rc);
    foreach ($out as $line) log_line('cpi: ' . $line);
    return $rc === 0 ? 1 : 0;
}
function captureSnapshots(): int {
    try {
        $n = (new \AfricaGates\Services\SnapshotService())->capture();
        log_line("Snapshots captured: $n");
        return $n;
    } catch (\Throwable $e) {
        log_line("Snapshot error: " . $e->getMessage());
        return 0;
    }
}
function scanCollusion(): int {
    try {
        $r = (new \AfricaGates\Services\CollusionService())->scan();
        if ($r['findings'] > 0) {
            log_line("Collusion: {$r['findings']} finding(s) — " . json_encode($r['by_kind']));
        }
        return $r['findings'];
    } catch (\Throwable $e) {
        log_line("Collusion error: " . $e->getMessage());
        return 0;
    }
}
function drainJobs(): int {
    global $container;
    try {
        $q = new \AfricaGates\Services\QueueService();
        $sheets = $container?->get(\AfricaGates\Services\GoogleSheetsService::class);
        $q->on('vote.sheets_push', function (array $p) use ($sheets) { $sheets?->pushVote($p); });
        // SMS / WhatsApp retries — deliver() throws on failure so QueueService
        // owns the retry/backoff schedule (5 attempts, then failed).
        $sms = \AfricaGates\Services\SmsService::boot();
        $q->on(\AfricaGates\Services\SmsService::JOB_SMS, function (array $p) use ($sms) {
            $sms->deliver('sms', (string)($p['to'] ?? ''), (string)($p['body'] ?? ''), (string)($p['template'] ?? 'generic'));
        });
        $q->on(\AfricaGates\Services\SmsService::JOB_WHATSAPP, function (array $p) use ($sms) {
            $sms->deliver('whatsapp', (string)($p['to'] ?? ''), (string)($p['body'] ?? ''), (string)($p['template'] ?? 'generic'));
        });
        // Review-at-scale: advisory AI triage per nomination (score + summary +
        // duplicate hints). Advisory only — never approves/rejects anything.
        $q->on(\AfricaGates\Services\NominationTriageService::JOB_TRIAGE, function (array $p) {
            \AfricaGates\Services\NominationTriageService::generate((int)($p['nomination_id'] ?? 0));
        });
        // Community v2: tell a member their thread got a reply (queued so the
        // posting request never waits on SMTP; silently skipped for guests).
        $mailer = $container?->get(\AfricaGates\Services\OtpService::class);
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
            log_line("Queue: {$r['done']} done, {$r['retried']} retried, {$r['failed']} failed");
        }
        return $r['done'];
    } catch (\Throwable $e) {
        log_line("Queue error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Send a voting-reminder email to all newsletter subscribers when a cycle
 * closes in exactly 48 hours. Runs as part of the daily maintenance cron.
 */
/**
 * Nominator "still under review" acknowledgement — the no-silence guarantee.
 * For nominations pending past the review SLA that have had NO response yet,
 * email the nominator once (nominator_ack_at guards against repeats) so a slow
 * queue never reads as being ignored.
 */
function sendPendingAcknowledgements(): int {
    global $container;
    try {
        $sla = 48;
        try { $v = DB::table('gates_settings')->where('key_name', 'review_sla_hours')->value('value'); if ($v !== null) $sla = max(1, (int)$v); } catch (\Throwable $e) {}
        // Only start acknowledging once a nomination is well past the SLA (2×),
        // so this is a genuine "sorry it's taking a while", not premature noise.
        $pending = \AfricaGates\Services\NominationFeedbackService::pendingNeedingAck($sla * 2, 200);
        if (!$pending) return 0;
        $mailer = $container?->get(\AfricaGates\Services\OtpService::class);
        if (!$mailer || !$mailer->smtpConfigured()) return 0;
        $sent = 0;
        foreach ($pending as $nom) {
            if (!filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
                \AfricaGates\Services\NominationFeedbackService::markAcked((int)$nom->id); // no email → don't re-scan forever
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
                \AfricaGates\Services\NominationFeedbackService::markAcked((int)$nom->id);
                $sent++;
            } catch (\Throwable $e) { /* try again next run */ }
        }
        log_line("Nomination acknowledgements sent: {$sent}");
        return $sent;
    } catch (\Throwable $e) { log_line('Ack error: ' . $e->getMessage()); return 0; }
}

function sendVotingReminders(): int {
    global $container;
    $count = 0;
    try {
        $now = \Illuminate\Support\Carbon::now();
        // Cycles whose voting closes in the next 24–48h. Run once daily, this band
        // catches each closing cycle exactly once as it enters the window — idempotent
        // without needing a per-cycle "reminded" flag.
        $cycles = DB::table('gates_award_cycles')
            ->where('status', 'voting')
            ->whereNotNull('voting_close')
            ->whereBetween('voting_close', [
                $now->copy()->addHours(24)->toDateTimeString(),
                $now->copy()->addHours(48)->toDateTimeString(),
            ])
            ->get();

        if ($cycles->isEmpty()) return 0;

        $mailer = $container?->get(\AfricaGates\Services\OtpService::class);
        if (!$mailer || !$mailer->smtpConfigured()) return 0;

        // Top nominees in the closing cycles. Nominees link to a cycle via their
        // category (gates_nominees has no cycle_id column), so resolve through it.
        $catIds = DB::table('gates_award_categories')->whereIn('cycle_id', $cycles->pluck('id'))->pluck('id');
        $topNominees = DB::table('gates_nominees')
            ->whereIn('category_id', $catIds)
            ->where('status', 'approved')
            ->orderByDesc('vote_count')
            ->limit(5)->get()->all();

        $cycleNames  = $cycles->map(fn($c) => $c->edition_label ?? '2026 Cycle')->implode(' · ');
        $closingDate = \Illuminate\Support\Carbon::parse($cycles->first()->voting_close)->format('D, d M Y');

        // Recipients: approved profiles + active newsletter subscribers (deduped).
        $profileEmails    = DB::table('gates_profiles')->where('status', 'approved')->whereNotNull('email')->pluck('email')->all();
        $newsletterEmails = DB::table('gates_newsletter')->whereNull('unsubscribed_at')->whereNotNull('email')->pluck('email')->all();
        $emails = array_values(array_unique(array_filter(array_merge($profileEmails, $newsletterEmails))));

        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $mailer->sendVotingReminder($email, $cycleNames, $closingDate, $topNominees);
            $count++;
        }
        log_line("Voting reminders sent: {$count}");
    } catch (\Throwable $e) {
        log_line("Reminder error: " . $e->getMessage());
    }
    return $count;
}

function recordDigest(): void {
    // Snapshot a tiny daily summary into gates_activity (admin sees it on the dashboard)
    $today = Carbon::now()->startOfDay()->toDateTimeString();
    $stats = [
        'votes_24h' => (int)DB::table('gates_votes')->where('voted_at', '>=', $today)->count(),
        'noms_24h'  => (int)DB::table('gates_nominations')->where('created_at', '>=', $today)->count(),
        'regs_24h'  => (int)DB::table('gates_profiles')->where('registered_at', '>=', $today)->count(),
        'cheers_24h'=> (int)DB::table('gates_cheers')->where('created_at', '>=', $today)->count(),
    ];
    try {
        DB::table('gates_activity')->insert([
            'kind' => 'legacy', // reuse type
            'actor_label' => 'system',
            'target_type' => null,
            'target_id'   => null,
            'target_label'=> 'Daily digest',
            'meta'        => json_encode($stats),
            'is_public'   => 0,
            'created_at'  => Carbon::now()->toDateTimeString(),
        ]);
        log_line('Digest: ' . json_encode($stats));
    } catch (\Throwable $e) {}
}

if ($task === 'auto') {
    // Every run: drain the job queue + advance cycle lifecycle + cache prune
    $ran[] = ['queue',  drainJobs()];
    $ran[] = ['cycles', advanceCycles()];
    $ran[] = ['cache',  pruneCache()];
    // Every hour
    if ((int)$now->minute < 15) {
        $ran[] = ['otp',       purgeExpiredOtp()];
        $ran[] = ['magic',     purgeExpiredMagic()];
        $ran[] = ['ratelimit', pruneRateLimits()];
        $ran[] = ['sharelinks', pruneShareLinks()];
        // Queue AI triage for pending nominations that don't have insights yet
        // (backlogs from before the feature, or periods when AI was off).
        $ran[] = ['triage-backfill', \AfricaGates\Services\NominationTriageService::backfill(100)];
        // Mail delivery audit: keep 30 days.
        try { $ran[] = ['maillog', (int) DB::table('gates_mail_log')->where('created_at', '<', Carbon::now()->subDays(30))->delete()]; } catch (\Throwable $e) {}
    }
    // Every 6 hours: CPI recompute + tamper-evident standings snapshot
    if ($now->hour % 6 === 0 && (int)$now->minute < 15) {
        $ran[] = ['cpi', recomputeCpi()];
        $ran[] = ['snapshot', captureSnapshots()];
    }
    // 06:00 daily: collusion scan + voting reminders + digest snapshot + cron-log trim
    if ($now->hour === 6 && (int)$now->minute < 15) {
        $ran[] = ['collusion', scanCollusion()];
        $ran[] = ['reminder', sendVotingReminders()];
        $ran[] = ['nom-ack', sendPendingAcknowledgements()];
        recordDigest(); $ran[] = ['digest', 1];
        $ran[] = ['cronlog', trimCronLog()];
    }
} else {
    match ($task) {
        'cycles' => $ran[] = ['cycles', advanceCycles()],
        'cpi'    => $ran[] = ['cpi',   recomputeCpi()],
        'cache'  => $ran[] = ['cache', pruneCache()],
        'queue'  => $ran[] = ['queue', drainJobs()],
        'otp'    => $ran[] = ['otp',   purgeExpiredOtp()],
        'magic'  => $ran[] = ['magic', purgeExpiredMagic()],
        'collusion' => $ran[] = ['collusion', scanCollusion()],
        'digest' => recordDigest(),
        'all'    => (function () use (&$ran) {
            $ran[] = ['queue', drainJobs()];
            $ran[] = ['cycles', advanceCycles()];
            $ran[] = ['cache', pruneCache()];
            $ran[] = ['otp',   purgeExpiredOtp()];
            $ran[] = ['magic', purgeExpiredMagic()];
            $ran[] = ['ratelimit', pruneRateLimits()];
            $ran[] = ['cpi',   recomputeCpi()];
            $ran[] = ['snapshot', captureSnapshots()];
            $ran[] = ['collusion', scanCollusion()];
            $ran[] = ['reminder', sendVotingReminders()];
            recordDigest(); $ran[] = ['digest', 1];
            $ran[] = ['cronlog', trimCronLog()];
        })(),
        default => log_line("Unknown task: $task"),
    };
}

try {
    DB::table('gates_cron_log')->insert([
        'job_name'   => 'maintenance',
        'status'     => 'success',
        'message'    => json_encode($ran),
        'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'ran_at'     => Carbon::now()->toDateTimeString(),
    ]);
} catch (\Throwable $e) {}

log_line('Done.');
