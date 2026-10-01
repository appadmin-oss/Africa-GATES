<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * IS EMAIL WORKING — DECIDED BY THE PLATFORM, NOT DISCOVERED BY A NOMINEE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE, AND WHY IT WAS NOT ENOUGH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every send already wrote a row to `gates_mail_log`, and three screens read it: a
 * settings card with counts, the analytics failure rate and /status. None of them could
 * TELL anybody. Each waited for an operator to open it, and the operator opened it after
 * a member wrote in — so a total outage was learned about from the people it locked out,
 * on a platform whose sign-in is a code sent by email.
 *
 * This holds the missing state, an incident, and moves it:
 *
 *   closed → open    on failures (`TRIP_FAILS` system failures in `WINDOW_MIN`, at a rate
 *                    of `TRIP_RATE` or worse), or on the hourly probe failing — so a
 *                    quiet night with nothing sent is still checked, and the outage is
 *                    known before the first person needs a code in the morning.
 *                    Opening RUNS THE DIAGNOSIS and alerts with its answer.
 *   open → open      re-alerted every `REALERT_HOURS`, re-diagnosed each time, because
 *                    the cause can change under an outage (a key fixed, a port then
 *                    blocked) and the first alert's advice then sends somebody wrong.
 *   open → closed    when sends succeed again (`RECOVER_SENT` since opening, the latest
 *                    one sent), or — on a quiet site — the probe passes.
 *
 * ── WHAT DOES NOT COUNT ──────────────────────────────────────────────────────────
 *
 * A refused RECIPIENT is one person's typo, not an outage (see MailFailure::isSystem).
 * And outside production an unconfigured transport is the expected state — mail is
 * written to a log file on purpose — so it never opens an incident there: a dev box
 * paging the team every hour is how the team learns to ignore the page.
 */
final class MailHealth
{
    public const WINDOW_MIN    = 60;
    public const TRIP_FAILS    = 3;
    public const TRIP_RATE     = 0.8;
    public const REALERT_HOURS = 6;
    public const RECOVER_SENT  = 2;
    public const PROBE_MIN     = 60;

    public const BY_FAILURES = 'failures';
    public const BY_PROBE    = 'probe';

    private const PROBE_AT     = 'mail_probe_last';
    private const PROBE_REPORT = 'mail_probe_report';

    /** @var callable():array */
    private $diagnose;

    public function __construct(?callable $diagnose = null, private ?MailAlert $alert = null)
    {
        $this->diagnose = $diagnose ?? static fn (): array => (new MailDiagnosis(MailConfig::load()))->run();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Reading
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * What the log says about the last `$minutes`.
     *
     * @return array{sent:int, system:int, recipient:int, dev:int, rate:float, cause:?string, last_error:?string,
     *               causes:array<string,int>}
     */
    public static function window(int $minutes = self::WINDOW_MIN): array
    {
        $out = ['sent' => 0, 'system' => 0, 'recipient' => 0, 'dev' => 0, 'rate' => 0.0,
                'cause' => null, 'last_error' => null, 'causes' => []];
        try {
            $since = Carbon::now()->subMinutes($minutes)->toDateTimeString();
            $rows  = DB::table('gates_mail_log')->where('created_at', '>=', $since)
                ->orderByDesc('id')->limit(500)->get(['status', 'error']);
        } catch (\Throwable) {
            return $out;
        }

        foreach ($rows as $r) {
            if ($r->status === 'sent')       { $out['sent']++; continue; }
            if ($r->status === 'logged_dev') { $out['dev']++;  continue; }

            $cause = MailFailure::classify($r->error);
            if (!MailFailure::isSystem($cause)) { $out['recipient']++; continue; }

            $out['system']++;
            $out['causes'][$cause] = ($out['causes'][$cause] ?? 0) + 1;
            // Rows are newest first, so the first system error seen is the latest.
            $out['last_error'] ??= (string) $r->error;
        }

        $attempts    = $out['sent'] + $out['system'];
        $out['rate'] = $attempts > 0 ? $out['system'] / $attempts : 0.0;
        if ($out['causes']) {
            arsort($out['causes']);
            $out['cause'] = (string) array_key_first($out['causes']);
        }
        return $out;
    }

    /** The open incident, or null. */
    public static function open(): ?object
    {
        try {
            return DB::table('gates_mail_incidents')->whereNull('resolved_at')
                ->orderByDesc('id')->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * For the admin layout's banner: the open incident — or, if the schedule that opens
     * incidents has itself stalled, what the log says right now.
     *
     * The second half is the CronHealth lesson applied here: a stalled run cannot alert
     * about itself, so a banner that only read the incident table would go quiet at the
     * exact moment both things were broken. The live check is one aggregate over an
     * indexed hour of rows, and only admin pages ask.
     *
     * @return array{since:?string, title:string, fix:string, live:bool}|null
     */
    public static function banner(): ?array
    {
        $i = self::open();
        if ($i) {
            $report = json_decode((string) $i->report_json, true) ?: [];
            return ['since' => (string) $i->opened_at,
                    'title' => (string) ($report['title'] ?? MailFailure::title((string) $i->cause)),
                    'fix'   => (string) ($report['fix'] ?? MailFailure::fix((string) $i->cause)),
                    'live'  => false];
        }
        // No login is an outage whether or not anything has tried to send yet — a quiet
        // site has no failures to count, and the first person to find out would be the
        // first person to ask for a code. This absorbed the console's older "Email
        // delivery is OFF" banner, which told an operator to set SMTP_USER in `.env` on a
        // host with no shell; two mail banners disagreeing on one page was its own fault.
        if (!MailConfig::load()->hasCredentials()) {
            return ['since' => null, 'title' => MailFailure::title(MailFailure::CONFIG),
                    'fix' => MailFailure::fix(MailFailure::CONFIG), 'live' => true];
        }
        $w = self::window();
        if (self::trips($w)) {
            $cause = $w['cause'] ?? MailFailure::UNKNOWN;
            return ['since' => null, 'title' => MailFailure::title($cause),
                    'fix' => MailFailure::fix($cause), 'live' => true];
        }
        return null;
    }

    /** The last automatic diagnosis, from whichever ran most recently. */
    public static function lastReport(): ?array
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', self::PROBE_REPORT)->value('value');
            $r = json_decode((string) $v, true);
            return is_array($r) ? $r : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<object> the most recent incidents, newest first */
    public static function history(int $limit = 10): array
    {
        try {
            return DB::table('gates_mail_incidents')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Acting — the maintenance task
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * One pass. On every maintenance tick: it is a single aggregate when nothing is
     * wrong, and the probe inside it rate-limits itself to `PROBE_MIN`.
     *
     * @return int what changed: 1 opened, re-alerted or resolved; 0 nothing to do
     */
    public function check(): int
    {
        $w = self::window();
        $i = self::open();

        return $i === null ? $this->maybeOpen($w) : $this->tend($i, $w);
    }

    /** Run the diagnosis now and keep its answer — the admin's "Run it now" button. */
    public function diagnoseNow(): array
    {
        $report = ($this->diagnose)();
        self::put(self::PROBE_AT, Carbon::now()->toDateTimeString());
        self::put(self::PROBE_REPORT, (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $report;
    }

    private function maybeOpen(array $w): int
    {
        $by = null;
        $report = null;

        if (self::trips($w)) {
            $by = self::BY_FAILURES;
        } elseif ($this->probeDue()) {
            $report = $this->diagnoseNow();
            if (!$report['ok'] && $this->counts($report)) $by = self::BY_PROBE;
        }

        if ($by === null) return 0;

        $report ??= $this->diagnoseNow();
        // The log tripped but the diagnosis passes: the transport is fine and the fault
        // is per-message (a sender refused for one category, a content filter). Still an
        // outage for whoever is not getting mail — so it opens, under the log's cause.
        $cause = $report['ok'] ? ($w['cause'] ?? MailFailure::UNKNOWN) : (string) $report['cause'];
        if ($report['ok']) {
            $report['title'] = MailFailure::title($cause);
            $report['fix']   = MailFailure::fix($cause)
                . ' (The automatic check connected and signed in successfully, so the server is reachable;'
                . ' the failures are being refused later, per message.)';
            $report['cause'] = $cause;
        }

        $now = Carbon::now()->toDateTimeString();
        $id  = (int) DB::table('gates_mail_incidents')->insertGetId([
            'opened_at'   => $now,
            'opened_by'   => $by,
            'cause'       => mb_substr($cause, 0, 20),
            'failures'    => (int) $w['system'],
            'last_error'  => $w['last_error'] !== null ? mb_substr((string) $w['last_error'], 0, 400) : null,
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'  => $now,
        ]);

        $this->alert($id, $report, still: false);
        return 1;
    }

    private function tend(object $i, array $w): int
    {
        if ($this->recovered($i)) {
            DB::table('gates_mail_incidents')->where('id', $i->id)->update([
                'resolved_at' => Carbon::now()->toDateTimeString(),
                'updated_at'  => Carbon::now()->toDateTimeString(),
            ]);
            try { ($this->alert ?? new MailAlert())->recovered($i); } catch (\Throwable) {}
            return 1;
        }

        // Keep the count honest while it is open — the alert quotes it.
        DB::table('gates_mail_incidents')->where('id', $i->id)->update([
            'failures'   => self::failuresSince((string) $i->opened_at, (int) $i->failures),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $last = $i->alerted_at ? Carbon::parse((string) $i->alerted_at) : null;
        if ($last !== null && $last->gt(Carbon::now()->subHours(self::REALERT_HOURS))) return 0;

        $report = $this->diagnoseNow();
        if ($report['ok']) {
            // Signed in fine, sends still failing — keep the original cause, it is about
            // the messages and not the transport.
            $report['cause'] = (string) $i->cause;
            $report['title'] = MailFailure::title((string) $i->cause);
            $report['fix']   = MailFailure::fix((string) $i->cause);
        }
        DB::table('gates_mail_incidents')->where('id', $i->id)->update([
            'cause'       => mb_substr((string) $report['cause'], 0, 20),
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->alert((int) $i->id, $report, still: true);
        return 1;
    }

    private function alert(int $id, array $report, bool $still): void
    {
        $row = DB::table('gates_mail_incidents')->where('id', $id)->first();
        if (!$row) return;

        $reached = [MailAlert::CONSOLE];
        try { $reached = ($this->alert ?? new MailAlert())->failing($row, $report, $still); }
        catch (\Throwable) {}

        DB::table('gates_mail_incidents')->where('id', $id)->update([
            'alerted_at'     => Carbon::now()->toDateTimeString(),
            'alert_channels' => mb_substr(implode(',', $reached), 0, 200),
        ]);
    }

    /**
     * Over: sends are succeeding again since it opened, and the latest attempt was one of
     * them. Or nothing has been attempted since it opened and the probe now passes —
     * on a quiet site there may be no send to prove it, and an incident that can only
     * close on traffic stays open over a weekend that was fixed on Friday.
     */
    private function recovered(object $i): bool
    {
        try {
            $since = DB::table('gates_mail_log')->where('created_at', '>=', (string) $i->opened_at)
                ->whereIn('status', ['sent', 'failed'])->orderByDesc('id')->limit(50)->pluck('status')->all();
        } catch (\Throwable) {
            return false;
        }

        if ($since !== []) {
            $sent = count(array_filter($since, static fn ($s) => $s === 'sent'));
            return $since[0] === 'sent' && $sent >= self::RECOVER_SENT;
        }

        if (!$this->probeDue()) return false;
        return (bool) ($this->diagnoseNow()['ok'] ?? false);
    }

    /**
     * System failures since the incident opened — plus whatever it opened with, which
     * were in the window BEFORE `opened_at` and so are not after it. Counted from the
     * log rather than incremented, so a missed tick cannot lose any.
     */
    private static function failuresSince(string $openedAt, int $atOpen): int
    {
        try {
            $errs = DB::table('gates_mail_log')->where('status', 'failed')
                ->where('created_at', '>', $openedAt)->limit(5000)->pluck('error')->all();
        } catch (\Throwable) {
            return $atOpen;
        }
        $n = 0;
        foreach ($errs as $e) {
            if (MailFailure::isSystem(MailFailure::classify((string) $e))) $n++;
        }
        return max($atOpen, $atOpen + $n);
    }

    /** Does this window amount to an outage? */
    public static function trips(array $w): bool
    {
        return $w['system'] >= self::TRIP_FAILS && $w['rate'] >= self::TRIP_RATE;
    }

    /** An unconfigured transport off production is the dev log file working as intended. */
    private function counts(array $report): bool
    {
        if (($report['cause'] ?? null) !== MailFailure::CONFIG) return true;
        return strtolower((string) Env::get('APP_ENV', 'production')) === 'production';
    }

    private function probeDue(): bool
    {
        try {
            $at = DB::table('gates_settings')->where('key_name', self::PROBE_AT)->value('value');
        } catch (\Throwable) {
            return false;
        }
        return $at === null || Carbon::parse((string) $at)->lte(Carbon::now()->subMinutes(self::PROBE_MIN));
    }

    private static function put(string $key, string $value): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $key],
                ['value' => $value, 'updated_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {}
    }
}
