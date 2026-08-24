<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\CronHealth;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What is actually working, measured rather than asserted.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE STATUS PAGE USED TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Four of its six components were the literal string 'Operational', unconditionally. Voting,
 * the leaderboard, the registry and the feed could not report anything else if the database
 * were on fire. The other two checked whether an ENVIRONMENT VARIABLE WAS SET — so
 * "Payments: Operational" meant `PAYSTACK_SECRET_KEY` exists, which is true of a revoked
 * key, a typo'd key and a key for somebody else's account.
 *
 * Nothing consulted the database, the scheduled task, the queue, the mail log or the
 * gateway. It was a picture of a status page.
 *
 * That is worse than having no status page, and specifically worse for this platform: a
 * page asserting health it has not checked is the same failure as a cached fundraising
 * total or a summary of a document nobody read. The whole product argument here is that we
 * do not do that.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT CHECKS NOW, AND WHAT IT REFUSES TO GUESS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Everything below is a real measurement taken at request time, from evidence this platform
 * already records. Nothing calls a third party — a status page that makes six outbound
 * requests is a status page that times out under exactly the load it exists to report on,
 * and it would hand anyone a way to make us hammer our own providers.
 *
 * So the sources are all local and all things that MOVE when something breaks:
 *
 *  · The database, with a real query and its latency.
 *  · The scheduled task, which is the single most important signal on a cPanel deployment:
 *    when cron stops, payment reconciliation, refunds, offer expiry, questionnaire
 *    invitations and every queued message stop silently and nothing else notices.
 *  · The job queue — depth, and how long the oldest pending job has waited.
 *  · Mail, from the delivery log's recent failure rate.
 *  · Payments, from recent gateway events rather than from a key existing.
 *
 * ── AND THE UNKNOWN STATE IS A REAL STATE ────────────────────────────────────
 *
 * {@see UNKNOWN} exists because "we have not been able to check this" is different from
 * "this is fine" and different again from "this is broken". A component with no evidence
 * either way says so. Collapsing it into Operational is exactly the lie the old page told.
 */
final class SystemStatus
{
    public const OK       = 'operational';
    public const DEGRADED = 'degraded';
    public const DOWN     = 'down';
    public const UNKNOWN  = 'unknown';

    /** Human labels, in the platform's voice rather than in status-page dialect. */
    public const LABELS = [
        self::OK       => 'Working',
        self::DEGRADED => 'Slower than usual',
        self::DOWN     => 'Not working',
        self::UNKNOWN  => 'Not checked',
    ];

    /** A database query slower than this is a real signal, not noise. */
    private const DB_SLOW_MS = 400;

    /** Queue depth above which people are waiting for things that should have happened. */
    private const QUEUE_DEEP = 200;

    /** A pending job older than this means the drain is not draining. */
    private const QUEUE_STALE_MIN = 90;

    /** Share of recent mail that may fail before it is worth saying so. */
    private const MAIL_FAIL_PCT = 20;

    // ═══════════════════════════════════════════════════════════════════════
    // THE REPORT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array{overall:string, overall_label:string, checked_at:string,
     *               components:list<array<string,mixed>>, note:string}
     */
    public static function report(): array
    {
        $components = [
            self::database(),
            self::scheduledWork(),
            self::queue(),
            self::payments(),
            self::mail(),
            self::ai(),
        ];

        return [
            'overall'       => self::worst($components),
            'overall_label' => self::LABELS[self::worst($components)],
            // Printed on the page. A status page with no timestamp is one nobody can tell
            // is stale, which is how a cached "all fine" outlives the outage it missed.
            'checked_at'    => Carbon::now()->toDateTimeString(),
            'components'    => $components,
            'note'          => self::note($components),
        ];
    }

    /**
     * One line an operator or a visitor can act on.
     *
     * Names the worst thing rather than summarising everything, because a status page is
     * read in ten seconds by somebody who wants to know whether the problem is theirs.
     */
    private static function note(array $components): string
    {
        foreach ([self::DOWN, self::DEGRADED] as $level) {
            foreach ($components as $c) {
                if ($c['status'] === $level && trim((string) $c['detail']) !== '') {
                    return (string) $c['detail'];
                }
            }
        }

        $unknown = array_filter($components, fn (array $c): bool => $c['status'] === self::UNKNOWN);
        if ($unknown !== []) {
            return 'Everything we can measure is working. Some things below have not been '
                 . 'checked — that is not the same as them being fine, and the page says which.';
        }

        return 'Everything we can measure is working.';
    }

    /** The worst status present. UNKNOWN never outranks a real problem. */
    private static function worst(array $components): string
    {
        $rank = [self::OK => 0, self::UNKNOWN => 1, self::DEGRADED => 2, self::DOWN => 3];

        $worst = self::OK;
        foreach ($components as $c) {
            if (($rank[$c['status']] ?? 0) > ($rank[$worst] ?? 0)) $worst = $c['status'];
        }

        return $worst;
    }

    /** @return array<string,mixed> */
    private static function component(string $name, string $what, string $status,
                                      string $detail = '', string $metric = ''): array
    {
        return [
            'name'   => $name,
            'what'   => $what,
            'status' => $status,
            'label'  => self::LABELS[$status],
            'detail' => $detail,
            'metric' => $metric,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE CHECKS
    // ═══════════════════════════════════════════════════════════════════════

    private static function database(): array
    {
        $t0 = microtime(true);

        try {
            // A real read against a real table, not `SELECT 1`. A connection can be alive
            // while the schema is missing or a migration is half-applied, and that is a
            // state somebody needs to hear about rather than a green tick.
            DB::table('gates_settings')->count();
            $ms = (int) round((microtime(true) - $t0) * 1000);
        } catch (\Throwable $e) {
            return self::component(
                'Voting, profiles and the leaderboard',
                'Everything that reads or writes a record',
                self::DOWN,
                'The database is not answering, so nothing on the site can save or load. '
                . 'We are already looking at it.',
            );
        }

        return $ms > self::DB_SLOW_MS
            ? self::component('Voting, profiles and the leaderboard',
                'Everything that reads or writes a record', self::DEGRADED,
                'Pages are loading more slowly than usual. Nothing is lost — a vote or a '
                . 'form that takes a moment longer still counts.',
                $ms . 'ms')
            : self::component('Voting, profiles and the leaderboard',
                'Everything that reads or writes a record', self::OK, '', $ms . 'ms');
    }

    /**
     * The scheduled task.
     *
     * The most important check on this page and the one the old version had no notion of.
     * On a cPanel deployment with no worker process, the cron tick IS the worker: payment
     * reconciliation, automatic refunds, stand-offer expiry, questionnaire invitations,
     * stand decision emails and every queued job run from it. When it stops, all of that
     * stops silently and every other component still looks perfectly healthy.
     */
    private static function scheduledWork(): array
    {
        try {
            $h = CronHealth::status();
        } catch (\Throwable) {
            return self::component('Scheduled work', 'Refunds, reminders and queued email',
                self::UNKNOWN, 'We could not check when this last ran.');
        }

        $when = $h['hours'] !== null ? CronHealth::humanGap((float) $h['hours']) . ' ago' : '';

        if (!empty($h['never'])) {
            return self::component('Scheduled work', 'Refunds, reminders and queued email',
                self::DOWN,
                'Scheduled work has never run on this installation, so refunds are not being '
                . 'sent and queued messages are not going out.');
        }

        if (!empty($h['stale'])) {
            return self::component('Scheduled work', 'Refunds, reminders and queued email',
                self::DOWN,
                'Scheduled work last ran ' . $when . '. Refunds, reminders and queued email '
                . 'are delayed until it runs again.',
                $when);
        }

        return self::component('Scheduled work', 'Refunds, reminders and queued email',
            self::OK, '', $when !== '' ? 'ran ' . $when : '');
    }

    private static function queue(): array
    {
        try {
            $pending = (int) DB::table('gates_jobs')->where('status', 'pending')->count();
            $oldest  = DB::table('gates_jobs')->where('status', 'pending')
                ->orderBy('created_at')->value('created_at');
        } catch (\Throwable) {
            return self::component('Messages waiting to go out', 'Email and notifications in the queue',
                self::UNKNOWN, 'We could not read the queue.');
        }

        $waited = null;
        if (is_string($oldest) && trim($oldest) !== '') {
            try { $waited = Carbon::parse($oldest)->diffInMinutes(Carbon::now()); }
            catch (\Throwable) { $waited = null; }
        }

        $metric = $pending . ' waiting';

        // A stale HEAD matters more than a deep queue: a hundred jobs that arrived a minute
        // ago is a busy afternoon, while one job that has waited two hours means the drain
        // has stopped and nothing behind it will move either.
        if ($waited !== null && $waited > self::QUEUE_STALE_MIN) {
            return self::component('Messages waiting to go out', 'Email and notifications in the queue',
                self::DEGRADED,
                'Some messages have been waiting longer than usual. Nothing is lost — they '
                . 'send when the queue moves again.',
                $metric);
        }

        if ($pending > self::QUEUE_DEEP) {
            return self::component('Messages waiting to go out', 'Email and notifications in the queue',
                self::DEGRADED,
                'There is a backlog of messages going out. They will arrive, a little later '
                . 'than usual.',
                $metric);
        }

        return self::component('Messages waiting to go out', 'Email and notifications in the queue',
            self::OK, '', $metric);
    }

    /**
     * Payments, from what the gateway actually did.
     *
     * The old check asked whether a secret key was SET, which is true of a revoked key, a
     * typo'd key, and a key belonging to somebody else's account. This asks whether recent
     * payments worked — which is the question, and which no environment variable can answer.
     */
    private static function payments(): array
    {
        $since = Carbon::now()->subHours(6)->toDateTimeString();

        try {
            $rows = DB::table('gates_donations')
                ->where('created_at', '>=', $since)
                ->selectRaw("COUNT(*) n, "
                    . "SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) ok")
                ->first();
        } catch (\Throwable) {
            return self::component('Payments', 'Donations, tickets and paid votes',
                self::UNKNOWN, 'We could not check recent payments.');
        }

        $n  = (int) ($rows->n ?? 0);
        $ok = (int) ($rows->ok ?? 0);

        // No attempts in six hours is not a fault. Most of the day, on most days, nobody is
        // paying — and reporting that as a problem would train everybody to ignore the page.
        if ($n === 0) {
            // Quiet is not broken — but a quiet page with NO provider configured is, and
            // those are different sentences. `enabledProviderIds()` is the same list the
            // checkout itself asks, so this cannot say "ready" about a gateway checkout
            // would refuse.
            $configured = [];
            try { $configured = (new PaymentService())->enabledProviderIds(); }
            catch (\Throwable) { $configured = []; }

            return $configured !== []
                ? self::component('Payments', 'Donations, tickets and paid votes', self::OK,
                    '', 'no payments in the last six hours')
                : self::component('Payments', 'Donations, tickets and paid votes', self::DOWN,
                    'No payment provider is set up, so nothing can be paid for right now.');
        }

        $pct = (int) round($ok * 100 / max(1, $n));

        if ($pct < 40) {
            return self::component('Payments', 'Donations, tickets and paid votes', self::DOWN,
                'Payments are failing. If you have been charged and it did not go through, '
                . 'nothing is lost — write to us and we will return it.',
                $pct . '% completing');
        }

        if ($pct < 75) {
            return self::component('Payments', 'Donations, tickets and paid votes', self::DEGRADED,
                'Some payments are not completing. If yours fails, do not try repeatedly — '
                . 'write to us instead.',
                $pct . '% completing');
        }

        return self::component('Payments', 'Donations, tickets and paid votes', self::OK,
            '', $pct . '% completing');
    }

    private static function mail(): array
    {
        $since = Carbon::now()->subHours(6)->toDateTimeString();

        try {
            $rows = DB::table('gates_mail_log')
                ->where('created_at', '>=', $since)
                ->selectRaw("COUNT(*) n, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) bad")
                ->first();
        } catch (\Throwable) {
            return self::component('Email', 'Sign-in codes, receipts and notifications',
                self::UNKNOWN, 'We could not check recent email.');
        }

        $n   = (int) ($rows->n ?? 0);
        $bad = (int) ($rows->bad ?? 0);

        if ($n === 0) {
            return self::component('Email', 'Sign-in codes, receipts and notifications',
                self::OK, '', 'nothing sent in the last six hours');
        }

        $pct = (int) round($bad * 100 / max(1, $n));

        if ($pct >= self::MAIL_FAIL_PCT) {
            return self::component('Email', 'Sign-in codes, receipts and notifications',
                $pct > 60 ? self::DOWN : self::DEGRADED,
                'Some email is not being delivered. If you are waiting for a sign-in code, '
                . 'check your spam folder and give it a few minutes.',
                $pct . '% failing');
        }

        return self::component('Email', 'Sign-in codes, receipts and notifications',
            self::OK, '', $n . ' sent recently');
    }

    /**
     * The AI features.
     *
     * Listed because they are visible to nominees and judges, and DEGRADED rather than DOWN
     * when they fail: every one of them is declared advisory, so the platform works without
     * them. A status page that shows an outage in red for a feature nobody needs to complete
     * a task teaches people to distrust the red.
     */
    private static function ai(): array
    {
        if (!AiGateway::globallyEnabled()) {
            return self::component('AI assistance', 'Summaries and drafting help',
                self::UNKNOWN, '', 'switched off');
        }

        $since = Carbon::now()->subHours(6)->toDateTimeString();

        try {
            $rows = DB::table('gates_ai_calls')
                ->where('created_at', '>=', $since)
                ->selectRaw("COUNT(*) n, SUM(CASE WHEN outcome = 'OK' THEN 1 ELSE 0 END) ok")
                ->first();
        } catch (\Throwable) {
            return self::component('AI assistance', 'Summaries and drafting help',
                self::UNKNOWN, 'We could not check.');
        }

        $n = (int) ($rows->n ?? 0);
        if ($n === 0) {
            return self::component('AI assistance', 'Summaries and drafting help',
                self::OK, '', 'not used in the last six hours');
        }

        $pct = (int) round(((int) ($rows->ok ?? 0)) * 100 / max(1, $n));

        return $pct < 60
            ? self::component('AI assistance', 'Summaries and drafting help', self::DEGRADED,
                'The writing and summary helpers are not answering reliably. Everything they '
                . 'help with can still be done without them.',
                $pct . '% answering')
            : self::component('AI assistance', 'Summaries and drafting help', self::OK,
                '', $pct . '% answering');
    }
}
