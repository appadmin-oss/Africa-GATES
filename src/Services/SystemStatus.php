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

    /** How far back the history strip reaches. */
    public const HISTORY_DAYS = 14;

    /** How long a recorded snapshot is kept. Same as the mail log, for the same reason. */
    public const KEEP_DAYS = 30;

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

    // ═══════════════════════════════════════════════════════════════════════
    // THE HISTORY
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Record what the platform looked like on this tick.
     *
     * ── WHY A LIVE PAGE STILL NEEDS THIS ────────────────────────────────────
     *
     * Measuring at request time is what makes {@see report()} honest, and it is also what
     * makes it blind. It can say what is true this second and nothing at all about the two
     * hours somebody could not check out — so a supporter whose payment failed at nine in
     * the morning loads a green page at noon and concludes the fault was theirs.
     *
     * "Was it broken earlier?" is the question a status page exists to answer. "It is fine
     * now" is the less useful half of it.
     *
     * ── AND WHY THE CRON WRITES IT, NOT THE REQUEST ─────────────────────────
     *
     * A row per visitor would make this a traffic log, and would let anybody grow the table
     * by holding down refresh. The tick runs on a schedule nobody outside can influence —
     * and the schedule is itself one of the things being recorded, so a GAP in this table is
     * the evidence that scheduled work stopped. That is the one outage no self-report can
     * ever cover, because the thing that would report it is the thing that stopped.
     *
     * @return int 1 when a row was written, 0 otherwise. Never throws: a status recorder
     *             that can break the maintenance run is worse than no recorder.
     */
    public static function record(): int
    {
        try {
            $r = self::report();

            // Only the shape, not the prose. The detail sentences are written for somebody
            // reading them NOW ("we are already looking at it") and would be quietly wrong
            // a week later; the state and the name are what a history is for.
            $slim = [];
            foreach ($r['components'] as $c) {
                $slim[] = ['name' => (string) $c['name'], 'status' => (string) $c['status']];
            }

            DB::table('gates_status_log')->insert([
                'taken_at'        => (string) $r['checked_at'],
                'overall'         => (string) $r['overall'],
                'components_json' => (string) json_encode($slim, JSON_UNESCAPED_UNICODE),
                'created_at'      => Carbon::now()->toDateTimeString(),
            ]);
            return 1;
        } catch (\Throwable $e) {
            error_log('[status] could not record: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The board as data, for anything that is not a person.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A STATUS PAGE NEEDS AN ENDPOINT AND NOT JUST A PAGE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Every status page people are used to publishes one — Statuspage at
     * `/api/v2/status.json`, and the rest by convention. It is not decoration: an uptime
     * monitor, a dashboard, or a person's phone cannot scrape a Twig template without
     * breaking the next time somebody moves a `<div>`, and on this deployment there is no
     * shell, so a machine-readable state is the only thing an external watcher can act on.
     *
     * The Cloudflare Worker that already drives `/__cron/run` is the obvious first consumer:
     * it can read this and stop guessing from an HTTP code.
     *
     * ── IT CARRIES THE PROSE, DELIBERATELY ───────────────────────────────────
     *
     * `detail` is the sentence a human would read, and a monitor that pages somebody at 3am
     * should be able to put it in the alert rather than making them open a browser to find
     * out which of six things broke.
     *
     * ── AND NO SECRETS, BY CONSTRUCTION ──────────────────────────────────────
     *
     * This is the same report the public page renders, so there is nothing here a visitor
     * could not already read. Nothing is added for the endpoint — a status API that exposes
     * more than the status page is an information leak wearing a helpful hat.
     *
     * @return array<string,mixed>
     */
    public static function payload(): array
    {
        $report   = self::report();
        $timeline = self::timeline();

        return [
            // `status`/`indicator` rather than only the label, so a consumer switches on a
            // stable token and a human reads the words beside it. Renaming a LABEL must not
            // break somebody's alerting.
            'status'      => (string) $report['overall'],
            'description' => (string) $report['overall_label'],
            'note'        => (string) $report['note'],
            'checked_at'  => Carbon::parse((string) $report['checked_at'])->toIso8601String(),
            'components'  => array_map(static function (array $c) use ($timeline): array {
                $h = $timeline['components'][$c['name']] ?? null;
                return [
                    'name'    => (string) $c['name'],
                    'about'   => (string) $c['what'],
                    'status'  => (string) $c['status'],
                    'label'   => (string) $c['label'],
                    'detail'  => (string) $c['detail'],
                    'metric'  => (string) $c['metric'],
                    // Null rather than 100 when there is nothing to divide. A consumer
                    // alerting on `uptime < 99` must not be handed an invented number.
                    'uptime'  => $h['uptime'] ?? null,
                    'checks'  => (int) ($h['samples'] ?? 0),
                ];
            }, $report['components']),
            'uptime'      => $timeline['uptime'],
            'incidents'   => $timeline['incidents'],
            'window_days' => self::HISTORY_DAYS,
        ];
    }

    /** Drop snapshots past {@see KEEP_DAYS}. */
    public static function prune(): int
    {
        try {
            return (int) DB::table('gates_status_log')
                ->where('taken_at', '<', Carbon::now()->subDays(self::KEEP_DAYS)->toDateTimeString())
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE RECORD, READ FOUR WAYS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every recorded snapshot in the window, decoded once.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * `components_json` WAS WRITTEN EVERY FIFTEEN MINUTES AND READ BY NOTHING
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see record()} has stored the per-component state of every snapshot since the log
     * existed. Only `overall` was ever read — so the page could say "something was wrong on
     * the 14th" and not which thing, while the answer sat in the next column.
     *
     * That is the sixth instance of the pattern in `docs/CODEBASE-INDEX.md` §17, and the most
     * visible: a per-component history bar is the single most recognisable element of a status
     * page, and the data for it was already on disk.
     *
     * ── ONE LOAD, NOT FOUR ───────────────────────────────────────────────────
     *
     * The four views below (days, components, incidents, uptime) are all folds over the same
     * rows. A window of fourteen days at a fifteen-minute cadence is ~1,340 rows, each with a
     * small JSON payload, so decoding them four times on a public page that is hit whenever
     * anybody wonders if the site is down is worth avoiding. {@see timeline()} loads once and
     * hands the same pass to all of them.
     *
     * Deliberately NOT memoised in a static. The test harness builds a fresh database per
     * test in one process, so a cached first answer would be served to every later test — a
     * whole class of green tests asserting nothing.
     *
     * @return list<array{at:string, overall:string, parts:array<string,string>}>
     */
    private static function snapshots(): array
    {
        $from = Carbon::now()->startOfDay()->subDays(self::HISTORY_DAYS - 1)->toDateTimeString();

        try {
            $rows = DB::table('gates_status_log')
                ->where('taken_at', '>=', $from)
                ->orderBy('taken_at')
                ->get(['taken_at', 'overall', 'components_json']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $overall = (string) $r->overall;
            if (!isset(self::RANK[$overall])) continue;

            $parts   = [];
            $decoded = json_decode((string) ($r->components_json ?? ''), true);
            if (is_array($decoded)) {
                foreach ($decoded as $c) {
                    $name = trim((string) ($c['name'] ?? ''));
                    $st   = (string) ($c['status'] ?? '');
                    // An unrecognised state is dropped rather than defaulted. A snapshot
                    // written by a future version of this class must not be silently
                    // counted as operational.
                    if ($name !== '' && isset(self::RANK[$st])) $parts[$name] = $st;
                }
            }

            $out[] = ['at' => (string) $r->taken_at, 'overall' => $overall, 'parts' => $parts];
        }

        return $out;
    }

    /**
     * Worst-wins ordering. A day with one broken hour is not a good day, and an average
     * would turn a real outage into a pale shade of green.
     *
     * UNKNOWN sits ABOVE operational and BELOW degraded on purpose: "we could not check"
     * is worse than a clean check and not as bad as a measured fault, and collapsing it
     * either way is the lie this whole class exists to avoid.
     */
    private const RANK = [self::OK => 0, self::UNKNOWN => 1, self::DEGRADED => 2, self::DOWN => 3];

    /**
     * Everything the page and the JSON endpoint need from the record, from one pass.
     *
     * @return array{days:list<array<string,mixed>>, components:array<string,array<string,mixed>>,
     *               incidents:list<array<string,mixed>>, uptime:array<string,mixed>}
     */
    public static function timeline(): array
    {
        $snaps = self::snapshots();

        return [
            'days'       => self::days($snaps),
            'components' => self::componentHistory($snaps),
            'incidents'  => self::incidents($snaps),
            'uptime'      => self::uptime($snaps),
        ];
    }

    /**
     * Per-component daily history and uptime — the standard status-page bar.
     *
     * Keyed by the component NAME as it was recorded. A component renamed since a snapshot
     * was taken therefore appears under its old key and no current row matches it, so it is
     * simply not drawn. That is the right failure: attributing one component's history to
     * another because the labels are adjacent would be worse than a missing bar.
     *
     * @param  list<array{at:string, overall:string, parts:array<string,string>}> $snaps
     * @return array<string,array{days:list<array<string,mixed>>, uptime:?float, samples:int}>
     */
    private static function componentHistory(array $snaps): array
    {
        /** @var array<string,array<string,array{status:string,n:int}>> $byName */
        $byName = [];
        /** @var array<string,array{ok:int,known:int}> $tally */
        $tally = [];

        foreach ($snaps as $s) {
            $day = substr($s['at'], 0, 10);
            foreach ($s['parts'] as $name => $st) {
                if (!isset($byName[$name][$day])) $byName[$name][$day] = ['status' => self::OK, 'n' => 0];
                $byName[$name][$day]['n']++;
                if (self::RANK[$st] > self::RANK[$byName[$name][$day]['status']]) {
                    $byName[$name][$day]['status'] = $st;
                }

                $tally[$name] ??= ['ok' => 0, 'known' => 0];
                if ($st !== self::UNKNOWN) {
                    $tally[$name]['known']++;
                    if ($st === self::OK) $tally[$name]['ok']++;
                }
            }
        }

        $out = [];
        foreach ($byName as $name => $days) {
            $out[$name] = [
                'days'    => self::fill($days),
                'uptime'  => self::pct($tally[$name]['ok'] ?? 0, $tally[$name]['known'] ?? 0),
                'samples' => (int) ($tally[$name]['known'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The share of checks that came back working, or null when there is nothing to divide.
     *
     * ── TWO RULES, BOTH ABOUT NOT LYING WITH A NUMBER ────────────────────────
     *
     * The denominator is checks whose state was KNOWN. Time we could not measure is not time
     * we were up, and putting it in either half of the fraction would be an invention — the
     * same discipline `SitemapService` applies to a `lastmod` it cannot vouch for.
     *
     * And the result is FLOORED, never rounded, so a window containing a real outage can
     * never print `100.00%`. That specific rounding is the most common dishonesty on a status
     * page: 1 failure in 20,000 checks is 99.995%, which rounds to a clean hundred and erases
     * the outage somebody is on this page looking for.
     */
    private static function pct(int $ok, int $known): ?float
    {
        if ($known < 1) return null;
        if ($ok >= $known) return 100.0;

        return min(99.99, floor($ok * 10000 / $known) / 100);
    }

    /**
     * Runs of consecutive checks where one component was not working, newest first.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS EXISTS ALONGSIDE THE BARS
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A bar answers "was there a bad day". Somebody on this page is usually asking something
     * narrower and more urgent — "my payment failed at nine this morning, was that you" — and
     * a square covering a whole day cannot answer it. An incident with a start, an end and a
     * duration can.
     *
     * ── UNKNOWN IS NOT AN INCIDENT ───────────────────────────────────────────
     *
     * A gap in the record means the scheduled task missed a beat; it does not mean the
     * platform was broken, and listing it as an outage would manufacture incidents out of
     * cron jitter. So UNKNOWN neither opens a run nor extends one — it CLOSES it, because
     * once we stopped being able to see the fault we can no longer claim it was still there.
     *
     * ── AND THE DURATION IS OBSERVED, NOT ESTIMATED ──────────────────────────
     *
     * `minutes` is the span between the first and last failing check, so a fault seen once is
     * reported as one check rather than as fifteen minutes of downtime we did not witness.
     * Rounding a single sample up to the sampling interval would inflate every blip.
     *
     * @param  list<array{at:string, overall:string, parts:array<string,string>}> $snaps
     * @return list<array{name:string, status:string, from:string, to:string, minutes:int,
     *                    checks:int, ongoing:bool}>
     */
    private static function incidents(array $snaps, int $limit = 8): array
    {
        /** @var array<string,array{status:string, from:string, to:string, checks:int}|null> $open */
        $open   = [];
        $closed = [];
        $lastAt = $snaps === [] ? '' : (string) $snaps[count($snaps) - 1]['at'];

        $shut = static function (string $name) use (&$open, &$closed): void {
            if (($open[$name] ?? null) !== null) {
                $closed[] = ['name' => $name] + $open[$name];
                $open[$name] = null;
            }
        };

        foreach ($snaps as $s) {
            foreach ($s['parts'] as $name => $st) {
                if ($st === self::DEGRADED || $st === self::DOWN) {
                    if (($open[$name] ?? null) === null) {
                        $open[$name] = ['status' => $st, 'from' => $s['at'],
                                        'to' => $s['at'], 'checks' => 1];
                        continue;
                    }
                    $open[$name]['to'] = $s['at'];
                    $open[$name]['checks']++;
                    // The worst state seen during the run is the one it is reported as: a
                    // wobble that became an outage is an outage.
                    if (self::RANK[$st] > self::RANK[$open[$name]['status']]) {
                        $open[$name]['status'] = $st;
                    }
                    continue;
                }
                $shut($name);
            }
        }

        foreach (array_keys($open) as $name) {
            if (($open[$name] ?? null) === null) continue;
            // Still open at the last check we have, so it has not been seen to recover.
            $closed[] = ['name' => $name, 'ongoing' => $open[$name]['to'] === $lastAt] + $open[$name];
        }

        $out = [];
        foreach ($closed as $i) {
            // From the timestamps, not from `diffInMinutes()`. That method is SIGNED and its
            // argument order changed meaning between Carbon major versions, so it silently
            // returned a negative span here and every duration clamped to zero — every
            // incident reported as "seen once". Subtracting epoch seconds cannot be got
            // wrong by an upgrade.
            $minutes = intdiv(max(0, Carbon::parse($i['to'])->getTimestamp()
                                   - Carbon::parse($i['from'])->getTimestamp()), 60);
            $checks  = (int) $i['checks'];
            $out[] = [
                'name'     => (string) $i['name'],
                'status'   => (string) $i['status'],
                'from'     => (string) $i['from'],
                'to'       => (string) $i['to'],
                'minutes'  => $minutes,
                'checks'   => $checks,
                'ongoing'  => (bool) ($i['ongoing'] ?? false),
                // Formatted here rather than in Twig so the wording is testable and cannot
                // differ between the page and the JSON endpoint.
                'duration' => self::spanInWords($minutes, $checks),
            ];
        }

        // Newest first: the thing somebody is on this page about happened recently.
        usort($out, static fn (array $a, array $b): int => strcmp($b['from'], $a['from']));

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * How long a fault was observed for, in words.
     *
     * A single failing check reports as one check, NOT as fifteen minutes: we saw it once
     * and cannot honestly claim the interval around it. Rounding every blip up to the
     * sampling cadence is how a status page accumulates downtime it never witnessed.
     */
    private static function spanInWords(int $minutes, int $checks): string
    {
        if ($minutes < 1) {
            return $checks > 1 ? $checks . ' checks' : 'seen once';
        }
        if ($minutes < 60) return $minutes . ' min';

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0
            ? $h . ' hr'
            : $h . ' hr ' . $m . ' min';
    }

    /**
     * The window's overall uptime, and how much of it we can actually vouch for.
     *
     * @param  list<array{at:string, overall:string, parts:array<string,string>}> $snaps
     * @return array{pct:?float, samples:int, days:int}
     */
    private static function uptime(array $snaps): array
    {
        $ok = $known = 0;
        foreach ($snaps as $s) {
            if ($s['overall'] === self::UNKNOWN) continue;
            $known++;
            if ($s['overall'] === self::OK) $ok++;
        }

        return ['pct' => self::pct($ok, $known), 'samples' => $known, 'days' => self::HISTORY_DAYS];
    }

    /**
     * A day per slot across the whole window, so a gap renders as a gap.
     *
     * @param  array<string,array{status:string,n:int}> $byDay
     * @return list<array{date:string, label:string, status:string, state_label:string, samples:int}>
     */
    private static function fill(array $byDay): array
    {
        $from = Carbon::now()->startOfDay()->subDays(self::HISTORY_DAYS - 1);

        $out = [];
        for ($i = 0; $i < self::HISTORY_DAYS; $i++) {
            $d   = $from->copy()->addDays($i);
            $key = $d->toDateString();
            $hit = $byDay[$key] ?? null;
            $st  = $hit === null ? self::UNKNOWN : (string) $hit['status'];

            $out[] = [
                'date'        => $key,
                'label'       => $d->format('j M'),
                'status'      => $st,
                'state_label' => $hit === null ? 'Not recorded' : self::LABELS[$st],
                'samples'     => (int) ($hit['n'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Overall per-day history, worst-state-wins.
     *
     * @param  list<array{at:string, overall:string, parts:array<string,string>}> $snaps
     * @return list<array{date:string, label:string, status:string, state_label:string, samples:int}>
     */
    private static function days(array $snaps): array
    {
        $byDay = [];
        foreach ($snaps as $s) {
            $day = substr($s['at'], 0, 10);
            if (!isset($byDay[$day])) $byDay[$day] = ['status' => self::OK, 'n' => 0];
            $byDay[$day]['n']++;
            if (self::RANK[$s['overall']] > self::RANK[$byDay[$day]['status']]) {
                $byDay[$day]['status'] = $s['overall'];
            }
        }

        return self::fill($byDay);
    }

    /**
     * The last {@see HISTORY_DAYS} days, one entry per day, worst-state-wins.
     *
     * Worst wins because a day with one broken hour is not a good day, and averaging would
     * turn a real outage into a pale shade of green. A day with NO snapshots reports
     * UNKNOWN — which is the truthful reading and, on a cPanel deployment, usually means the
     * scheduled task was not running that day either.
     *
     * Kept as its own entry point for the callers and tests that only want the overall
     * strip; the page itself uses {@see timeline()}, which gets this and the three other
     * views from a single pass over the same rows.
     *
     * @return list<array{date:string, label:string, status:string, state_label:string, samples:int}>
     */
    public static function history(): array
    {
        return self::days(self::snapshots());
    }

    /**
     * One line summarising the strip, so the page is not asking anybody to read squares.
     */
    public static function historyNote(array $history): string
    {
        if ($history === []) return '';

        $bad     = 0;
        $unknown = 0;
        foreach ($history as $d) {
            if ($d['status'] === self::DOWN || $d['status'] === self::DEGRADED) $bad++;
            if ($d['status'] === self::UNKNOWN) $unknown++;
        }

        if ($unknown === count($history)) {
            return 'Nothing has been recorded yet. This fills in as the scheduled task runs.';
        }

        $days = count($history) - $unknown;

        if ($bad === 0) {
            $note = 'No problems recorded on any of the last ' . $days
                  . ' day' . ($days === 1 ? '' : 's') . ' we have a record for.';
            return $unknown > 0
                ? $note . ' A dashed square is a day we have no record for.'
                : $note;
        }

        $note = $bad . ' of the last ' . $days . ' recorded day' . ($days === 1 ? '' : 's')
              . ' had a problem.';

        // Only explained when there IS one. Describing a grey square on a strip that has
        // none is an instruction about something the reader cannot see.
        return $unknown > 0
            ? $note . ' A dashed square is a day we have no record for, which usually means '
                    . 'the scheduled task was not running.'
            : $note;
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
            // "0ms" reads as "not measured" rather than as "fast", which is the opposite of
            // what it means and the one number on this page somebody might disbelieve.
            $shown = $ms < 1 ? 'under 1ms' : $ms . 'ms';
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
                $shown)
            : self::component('Voting, profiles and the leaderboard',
                'Everything that reads or writes a record', self::OK, '', $shown);
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

    /** Share of ATTEMPTED calls that may fail before it is worth saying so. */
    private const AI_FAIL_PCT = 40;

    /**
     * The AI features.
     *
     * Listed because they are visible to nominees and judges, and DEGRADED rather than DOWN
     * when they fail: every one of them is declared advisory, so the platform works without
     * them. A status page that shows an outage in red for a feature nobody needs to complete
     * a task teaches people to distrust the red.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS COUNTS TWO THINGS AND NOT ONE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * It used to be one number: OK rows over all rows, under 60% reads "the writing and
     * summary helpers are not answering reliably". That sentence was shown for weeks on a
     * deployment reading 0%, and it was wrong in the way that costs the most — it described a
     * flaky provider, so that is what was investigated, twice.
     *
     * `gates_ai_calls` holds a row for every REFUSAL too, by design: a call the gateway
     * stopped is exactly as auditable as one it made. But a refusal never asked a provider
     * anything, so it cannot be evidence that providers are unreliable. Three of the six
     * words in {@see AiGateway::REFUSALS} are ordinary configuration — no key, a capability
     * switched off, the day's budget spent — and each of them, alone, drove the ratio to
     * zero. The list lives beside the code that WRITES those words, so this check and the
     * admin console's failure list cannot drift into disagreeing about what a failure is.
     *
     * So: refusals are separated from ATTEMPTS, the percentage is over attempts, and where a
     * refusal is the dominant story the row says which one and what clears it. An operator
     * reading "today's budget is spent, it resets at midnight" needs no investigation at all.
     */
    private static function ai(): array
    {
        $name = 'AI assistance';
        $what = 'Summaries and drafting help';

        if (!AiGateway::globallyEnabled()) {
            return self::component($name, $what, self::UNKNOWN, '', 'switched off');
        }

        $since = Carbon::now()->subHours(6)->toDateTimeString();

        try {
            $rows = DB::table('gates_ai_calls')
                ->where('created_at', '>=', $since)
                ->groupBy('outcome')
                // Latency alongside the count because "slower than usual" is the label this
                // row wears when it degrades, and the page had no measurement behind it.
                ->selectRaw('outcome, COUNT(*) n, COALESCE(AVG(latency_ms), 0) ms')
                ->get();
        } catch (\Throwable) {
            return self::component($name, $what, self::UNKNOWN, 'We could not check.');
        }

        $ok = $failed = 0;
        $okMs = 0.0;
        /** @var array<string,int> $refused */
        $refused = [];

        foreach ($rows as $r) {
            $outcome = strtoupper(trim((string) $r->outcome));
            $n       = (int) $r->n;

            if ($outcome === 'OK') {
                $ok  += $n;
                $okMs = (float) $r->ms;
            } elseif (in_array($outcome, AiGateway::REFUSALS, true)) {
                $refused[$outcome] = ($refused[$outcome] ?? 0) + $n;
            } else {
                // PROVIDER_ERROR, EMPTY, SCHEMA_REJECTED, FAILED, and anything a future
                // capability invents. All of them mean a provider WAS asked.
                $failed += $n;
            }
        }

        $attempts = $ok + $failed;

        // ── NOTHING TRIED: SAY SO, AND DO NOT CALL IT HEALTHY BY ACCIDENT ────
        if ($attempts === 0) {
            // A refusal is still the reason nobody got an answer, so it is reported even
            // though no provider was involved.
            if ($refused !== []) {
                [$status, $detail, $metric] = self::aiRefusal($refused);
                return self::component($name, $what, $status, $detail, $metric);
            }
            return self::component($name, $what, self::OK, '', 'not used in the last six hours');
        }

        $pct    = (int) round($ok * 100 / max(1, $attempts));
        $metric = $pct . '% answering';
        // Only worth printing once there is enough of it to be a fact rather than a sample.
        if ($ok > 0 && $okMs >= 1000) {
            $metric .= ', ' . number_format($okMs / 1000, 1) . 's typical';
        }

        if ($pct < (100 - self::AI_FAIL_PCT)) {
            return self::component($name, $what, self::DEGRADED,
                'The writing and summary helpers are not answering reliably. Everything they '
                . 'help with can still be done without them.',
                $metric);
        }

        // Working, but something is being refused alongside it — most often one capability's
        // budget while the rest of the platform is fine. Said in the metric, not as a fault:
        // the features people are using are answering.
        if ($refused !== []) {
            return self::component($name, $what, self::OK, '',
                $metric . ', ' . array_sum($refused) . ' held back');
        }

        return self::component($name, $what, self::OK, '', $metric);
    }

    /**
     * The dominant refusal, in words that name what clears it.
     *
     * Ordered by what an operator can do about it rather than by count: a missing key is a
     * five-minute fix and a spent budget fixes itself at midnight, so saying which one it is
     * matters more than saying which is commoner. `UNKNOWN` rather than `DEGRADED` for the
     * two that are configuration: nothing is malfunctioning, and a red-adjacent row for a
     * feature deliberately left unconfigured is noise on a page whose whole value is that its
     * colours mean something.
     *
     * @param  array<string,int> $refused
     * @return array{0:string, 1:string, 2:string}
     */
    private static function aiRefusal(array $refused): array
    {
        if (isset($refused['NO_PROVIDER'])) {
            return [self::UNKNOWN,
                'The writing and summary helpers are not set up on this site, so they are not '
                . 'available. Nothing else is affected.',
                'no provider configured'];
        }
        if (isset($refused['BUDGET_CALLS']) || isset($refused['BUDGET_TOKENS'])) {
            return [self::DEGRADED,
                "Today's allowance for the writing and summary helpers is used up. It resets at "
                . 'midnight, and everything they help with can be done without them.',
                'daily allowance spent'];
        }
        if (isset($refused['DISABLED_CAPABILITY'])) {
            return [self::UNKNOWN, '', 'some helpers switched off'];
        }

        // UNDECLARED is a programming error rather than an operational state, and the one
        // refusal a visitor can do nothing with. Named plainly so it reaches somebody.
        return [self::DEGRADED,
            'Some of the writing and summary helpers are misconfigured. Everything they help '
            . 'with can still be done without them.',
            'misconfigured'];
    }
}