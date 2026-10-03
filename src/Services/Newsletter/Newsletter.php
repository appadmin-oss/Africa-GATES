<?php
declare(strict_types=1);

namespace AfricaGates\Services\Newsletter;

use AfricaGates\Services\EmailInboxGuard;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Mail\MailHealth;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\Accent;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The newsletter's issues: composed on a schedule, approved or not, sent in batches.
 *
 * ── THE LIFE OF AN ISSUE ─────────────────────────────────────────────────────
 *
 *   draft     composed, waiting for a person (review mode)
 *   approved  cleared to send — by a person, or by the schedule in auto mode
 *   sending   the first batch has gone; later ticks continue it
 *   sent      everybody on the list at the time has been dealt with
 *   skipped   nothing to say, or a person decided not to send it
 *
 * Composition FREEZES the content on the row. The sender renders from that row, so what a
 * person approved is what arrives, however long the send takes and whatever the award
 * pages say by its last batch.
 *
 * ── WHEN IT DOES NOT SEND, AND SAYS SO ───────────────────────────────────────
 *
 * Every reason a scheduled send holds is written to the issue's `note`, because a send
 * that stops without saying why is the failure this codebase keeps paying for:
 *
 *   · an open mail incident ({@see MailHealth::open()}) — sending a list into a broken
 *     transport turns one outage into a few thousand failures charged against the domain;
 *   · no transport configured, or no site address to build links from;
 *   · three failures in a row inside a batch, which stops the batch there.
 *
 * Nothing about any of these loses the issue. The next tick picks it up where it stopped.
 */
final class Newsletter
{
    public const ST_DRAFT    = 'draft';
    public const ST_APPROVED = 'approved';
    public const ST_SENDING  = 'sending';
    public const ST_SENT     = 'sent';
    public const ST_SKIPPED  = 'skipped';

    public const STATUSES = [
        self::ST_DRAFT    => 'Waiting for approval',
        self::ST_APPROVED => 'Approved',
        self::ST_SENDING  => 'Sending',
        self::ST_SENT     => 'Sent',
        self::ST_SKIPPED  => 'Skipped',
    ];

    /**
     * Per tick. The webcron ticks every few minutes, so this is a few hundred an hour —
     * slow enough that a provider's hourly cap is not met in one burst, fast enough that a
     * list of thousands is done the same morning.
     */
    public const BATCH = 40;
    /** Legacy subscribers asked to confirm, per tick. */
    public const ASK_BATCH = 20;
    /** Consecutive failures that stop a batch. */
    public const STOP_AFTER = 3;

    /**
     * @param OtpService|null $mailer the transport, or null when there is none
     * @param string          $site   the absolute site address every link is built on
     */
    public function __construct(private readonly ?OtpService $mailer, private readonly string $site) {}

    // ══ reading ══════════════════════════════════════════════════════════════

    public static function find(int $id): ?object
    {
        try {
            return DB::table('gates_newsletter_issues')->where('id', $id)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<object> */
    public static function recent(int $limit = 12): array
    {
        try {
            return DB::table('gates_newsletter_issues')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function lastSent(): ?object
    {
        try {
            return DB::table('gates_newsletter_issues')->where('status', self::ST_SENT)
                ->orderByDesc('id')->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The issue a tick should be sending, if any — oldest first, one at a time. */
    public static function active(): ?object
    {
        try {
            return DB::table('gates_newsletter_issues')
                ->whereIn('status', [self::ST_APPROVED, self::ST_SENDING])
                ->orderBy('id')->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public static function content(object $issue): array
    {
        $c = json_decode((string) $issue->content_json, true);
        return is_array($c) ? $c : ['sections' => [], 'keys' => []];
    }

    public static function campaignKey(object $issue): string
    {
        return 'newsletter-' . (int) $issue->id;
    }

    // ══ composing ════════════════════════════════════════════════════════════

    /**
     * Compose the issue for a period, once.
     *
     * The UNIQUE `period_key` is the claim: a second tick composing the same period fails
     * the insert and gets the first one's row back. An issue with nothing in it, or with
     * exactly what the last sent issue had, is stored as `skipped` with the reason — so
     * the screen can say "nothing new this week" rather than leaving a gap in the history
     * that reads as the schedule having broken.
     */
    public static function compose(string $periodKey, NewsletterSchedule $schedule, ?Carbon $now = null,
                                   ?array $holiday = null): ?object
    {
        $now  = $now ?? Carbon::now();
        $last = self::lastSent();
        $prevKeys = $last ? (array) (self::content($last)['keys'] ?? []) : [];
        $since = $last ? Carbon::parse((string) $last->composed_at) : $now->copy()->subDays(30);

        $c = NewsletterComposer::compose($now, $since, $prevKeys);
        $c['period']   = $schedule->periodNoun();
        $c['dateline'] = DisplayTime::show($now, 'l j F Y');

        if ($holiday !== null) {
            // A holiday issue is the greeting first and the news second. Its subject is the
            // greeting — the thing a reader opens it for on the day — and its preheader is
            // the most time-sensitive line of news, or the greeting's own sentence when the
            // site has nothing open.
            $c['holiday']   = $holiday;
            $c['preheader'] = $c['keys'] !== [] ? $c['subject'] : mb_substr($holiday['message'], 0, 250);
            $c['subject']   = $holiday['greeting'] . ' from Africa GATES';
        }

        $recentHoliday = $holiday === null ? self::recentHolidayIssue($now) : null;

        [$status, $note] = match (true) {
            // A greeting is worth sending on its day even in a week with nothing open.
            $holiday !== null && $schedule->sendsItself()        => [self::ST_APPROVED, 'Approved by the schedule.'],
            $holiday !== null                                    => [self::ST_DRAFT, 'A holiday issue — approve it today, or it misses its day.'],
            $recentHoliday !== null                              => [self::ST_SKIPPED, 'Issue #' . (int) $recentHoliday->id
                                                                      . ' went out for a holiday ' . self::HOLIDAY_SPACING_HOURS
                                                                      . ' hours ago or less; two issues in two days is one too many.'],
            $c['keys'] === []                                     => [self::ST_SKIPPED, 'Nothing open, announced or coming up to report.'],
            $last !== null && $c['fingerprint'] === $last->fingerprint => [self::ST_SKIPPED, 'Nothing has changed since issue #' . (int) $last->id . '.'],
            $schedule->sendsItself()                              => [self::ST_APPROVED, 'Approved by the schedule.'],
            default                                               => [self::ST_DRAFT, null],
        };

        try {
            $id = (int) DB::table('gates_newsletter_issues')->insertGetId([
                'period_key'   => $periodKey,
                'subject'      => $c['subject'],
                'preheader'    => $c['preheader'],
                'content_json' => json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'fingerprint'  => $c['fingerprint'],
                'status'       => $status,
                'note'         => $note,
                'composed_at'  => $now->toDateTimeString(),
                'approved_at'  => $status === self::ST_APPROVED ? $now->toDateTimeString() : null,
            ]);
            return self::find($id);
        } catch (\Throwable) {
            return DB::table('gates_newsletter_issues')->where('period_key', $periodKey)->first() ?: null;
        }
    }

    /** Holiday issues are keyed `h-<holiday>-<year>`; the regular cadence never is. */
    public const HOLIDAY_PREFIX = 'h-';

    /**
     * The widest `period_key` the column holds. It was VARCHAR(20), and
     * `h-independence-day-2026` is 23: strict MySQL refused the issue, the composer's catch
     * swallowed it, and the Independence Day greeting never went out — on SQLite, which
     * stores TEXT, the suite was green. `2027_02_23_newsletter_period_widen.php` repairs
     * the column; `HolidayNewsletterTest` holds every key to this width.
     */
    public const PERIOD_KEY_MAX = 40;

    /**
     * How close a regular issue may follow a holiday one. A holiday on a Wednesday and the
     * weekly issue on Thursday would be two newsletters in two days with the same news in
     * both — and the second is the one that gets "report spam".
     */
    public const HOLIDAY_SPACING_HOURS = 48;

    public static function holidayKey(string $holiday, int $year): string
    {
        return self::HOLIDAY_PREFIX . $holiday . '-' . $year;
    }

    /** A holiday issue cleared to go, or gone, within the spacing window. */
    private static function recentHolidayIssue(Carbon $now): ?object
    {
        try {
            $since = $now->copy()->subHours(self::HOLIDAY_SPACING_HOURS)->toDateTimeString();
            foreach (DB::table('gates_newsletter_issues')->where('composed_at', '>=', $since)
                         ->whereIn('status', [self::ST_APPROVED, self::ST_SENDING, self::ST_SENT])
                         ->orderByDesc('id')->get() as $i) {
                if (str_starts_with((string) $i->period_key, self::HOLIDAY_PREFIX)) return $i;
            }
        } catch (\Throwable) {
        }
        return null;
    }

    // ══ a person's decisions ═════════════════════════════════════════════════

    /** @return array{ok:bool, message:string} */
    public static function approve(int $id, int $by): array
    {
        $i = self::find($id);
        if (!$i) return ['ok' => false, 'message' => 'No such issue.'];
        if ((string) $i->status !== self::ST_DRAFT) {
            return ['ok' => false, 'message' => 'Only an issue waiting for approval can be approved.'];
        }
        DB::table('gates_newsletter_issues')->where('id', $id)->where('status', self::ST_DRAFT)->update([
            'status' => self::ST_APPROVED, 'approved_by' => $by > 0 ? $by : null,
            'approved_at' => Carbon::now()->toDateTimeString(), 'note' => null,
        ]);
        return ['ok' => true, 'message' => 'Approved. It goes out over the next few minutes, in batches, '
                                          . 'and nobody is mailed twice.'];
    }

    /**
     * Skip an issue that has not started sending. One that has started cannot be skipped:
     * some people already have it, and "skipped" would be a false record of what went out.
     *
     * @return array{ok:bool, message:string}
     */
    public static function skip(int $id): array
    {
        $i = self::find($id);
        if (!$i) return ['ok' => false, 'message' => 'No such issue.'];
        // The first batch moves an issue to `sending`, so the status alone answers it.
        if (!in_array((string) $i->status, [self::ST_DRAFT, self::ST_APPROVED], true)) {
            return ['ok' => false, 'message' => 'That issue has already started sending, so it cannot be skipped.'];
        }
        DB::table('gates_newsletter_issues')->where('id', $id)->update([
            'status' => self::ST_SKIPPED, 'note' => 'Skipped by an administrator.',
        ]);
        return ['ok' => true, 'message' => 'Skipped. Nothing was sent.'];
    }

    // ══ rendering ════════════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    public function vars(object $issue, string $email, string $since = ''): array
    {
        $c = self::content($issue);
        $sections = [];
        foreach ((array) ($c['sections'] ?? []) as $s) {
            $items = [];
            foreach ((array) ($s['items'] ?? []) as $it) {
                $it['href'] = rtrim($this->site, '/') . (string) ($it['path'] ?? '/');
                $items[] = $it;
            }
            $sections[] = ['title' => (string) ($s['title'] ?? ''), 'items' => $items];
        }

        return [
            'subject'         => (string) $issue->subject,
            'preheader'       => (string) ($issue->preheader ?? ''),
            'headline'        => isset($c['holiday']['greeting'])
                ? (string) $c['holiday']['greeting']
                : 'This ' . (string) ($c['period'] ?? 'week') . ' at Africa GATES',
            'greeting'        => (string) ($c['holiday']['message'] ?? ''),
            'dateline'        => (string) ($c['dateline'] ?? ''),
            'sections'        => $sections,
            'since'           => $since !== '' ? DisplayTime::show($since, 'j F Y') : '',
            'site_url'        => rtrim($this->site, '/'),
            'unsubscribe_url' => EmailOptOut::url($this->site, $email),
            'postal_address'  => \AfricaGates\Services\Mail\MailConfig::postal(),
            'c'               => self::palette(),
        ];
    }

    /**
     * The issue's colours, as VALUES from {@see Accent}.
     *
     * An inbox cannot read a custom property — Gmail strips the <style> block that would
     * declare one and most clients never resolve `var()` — so mail has to carry literal
     * colours. It does not have to TYPE them: the template prints these, so the newsletter
     * is drawn from the same ramp as the site, and moving a role moves the mail with it.
     * The older skeletons typed their own greys, and one of them (#9a9c95 on white, the
     * footer's unsubscribe line) is 2.8:1 — under the floor for the one line on a bulk
     * mail that must be readable.
     *
     * @return array<string,string>
     */
    public static function palette(): array
    {
        return [
            'ground'     => Accent::hex('ground'),
            'card'       => Accent::hex('surface'),
            'ink'        => Accent::hex('ink'),
            'ink2'       => Accent::hex('ink-2'),
            'soft'       => Accent::hex('soft'),
            'line'       => Accent::hex('line'),
            'surface2'   => Accent::hex('tint'),
            'action'     => Accent::hex('green'),
            // The header bar and the dark-mode ground: ink as a field.
            'bar'        => Accent::hex('ink'),
            'dark'       => Accent::hex('ink'),
            'bar_accent' => Accent::hex('green-wash'),
            'bar_soft'   => Accent::hex('line'),
        ];
    }

    public function html(object $issue, string $email, string $since = ''): string
    {
        // A bare environment, as NomineeBroadcast does: plain variables only, so it renders
        // from a cron tick with no HTTP request and none of the app's extensions — bar
        // `trans`, which needs no request (off one it answers in English) and without
        // which the first `|trans` in a shared email partial is a compile error here.
        static $twig = null;
        $twig ??= \AfricaGates\Support\Translator::register(
            new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), ['autoescape' => 'html'])
        );
        return $twig->render('emails/newsletter.twig', $this->vars($issue, $email, $since));
    }

    /**
     * The plain-text part, written rather than stripped: a stripped newsletter is link
     * text with no sentences in it, and that is what a plain-text client shows.
     */
    public function plain(object $issue, string $email, string $since = ''): string
    {
        $v = $this->vars($issue, $email, $since);
        $out = [$v['headline'], $v['dateline'], ''];
        if ($v['greeting'] !== '') array_push($out, $v['greeting'], '');
        foreach ($v['sections'] as $s) {
            $out[] = strtoupper($s['title']);
            foreach ($s['items'] as $it) {
                $out[] = '- ' . $it['title'] . ($it['new'] ?? false ? ' (new)' : '');
                $out[] = '  ' . $it['line'];
                $out[] = '  ' . $it['href'];
            }
            $out[] = '';
        }
        $out[] = $v['since'] !== ''
            ? 'You are receiving this because you confirmed a subscription on ' . $v['since'] . '.'
            : 'You are receiving this because you subscribed to the Africa GATES newsletter.';
        $out[] = 'Unsubscribe: ' . $v['unsubscribe_url'];
        $out[] = $v['postal_address'];
        return implode("\n", $out);
    }

    // ══ sending ══════════════════════════════════════════════════════════════

    /**
     * Why a send cannot happen right now, or null when it can.
     */
    public function blocker(): ?string
    {
        if ($this->mailer === null || !$this->mailer->canSend()) {
            return 'Paused: email is not configured. Settings → Email & sender.';
        }
        if ($this->site === '')   return 'Paused: the site address (APP_URL) is not set, and every link in an issue is absolute.';
        if (MailHealth::open() !== null) {
            return 'Paused: email is failing right now. It resumes by itself once mail recovers — see Settings → Email.';
        }
        return null;
    }

    /**
     * Send up to $limit more copies of an approved issue.
     *
     * @return array{sent:int, failed:int, left:int, note:?string}
     */
    public function sendBatch(object $issue, int $limit = self::BATCH): array
    {
        $key = self::campaignKey($issue);
        if (!in_array((string) $issue->status, [self::ST_APPROVED, self::ST_SENDING], true)) {
            return ['sent' => 0, 'failed' => 0, 'left' => 0, 'note' => 'That issue is not approved.'];
        }
        if (($why = $this->blocker()) !== null) {
            $this->note($issue, $why);
            return ['sent' => 0, 'failed' => 0, 'left' => -1, 'note' => $why];
        }

        $problems = EmailInboxGuard::problems($this->html($issue, 'reader@example.com'));
        if ($problems !== []) {
            $why = 'Paused: this issue would not render properly in an inbox. ' . implode(' ', $problems);
            $this->note($issue, $why);
            return ['sent' => 0, 'failed' => 0, 'left' => -1, 'note' => $why];
        }

        // Everybody this issue has dealt with, FAILED included. A failure is not retried on
        // the next tick: a bounce retried every five minutes is a complaint, and the count
        // on the issue is the record of who did not get it.
        $done  = BroadcastLog::handled($key);
        $queue = array_values(array_filter(NewsletterAudience::recipients(),
            static fn(array $r) => !isset($done[$r['hash']])));

        if ($issue->started_at === null) {
            DB::table('gates_newsletter_issues')->where('id', (int) $issue->id)->update([
                'status' => self::ST_SENDING, 'started_at' => Carbon::now()->toDateTimeString(),
                'recipients' => count($queue) + count($done),
            ]);
        }

        $sent = $failed = $streak = 0;
        $note = null;
        // Walk the queue until $limit messages have actually been ATTEMPTED. A reader the
        // send rules hold costs no connection, so it must not cost a slot either — or a
        // morning where half the list has already had its daily announcements would spend
        // every batch on them and send nobody.
        $deferred = 0;
        foreach ($queue as $r) {
            if ($sent + $failed >= $limit) break;
            if (!BroadcastLog::claim($key, $r['email'])) continue;   // another tick has it
            $res = ['success' => false, 'error' => ''];
            try {
                $res = $this->mailer->sendRawHtml($r['email'], (string) $issue->subject,
                    $this->html($issue, $r['email'], $r['since']), $this->plain($issue, $r['email'], $r['since']),
                    'newsletter', EmailOptOut::url($this->site, $r['email']));
            } catch (\Throwable $e) {
                $res = ['success' => false, 'error' => $e->getMessage()];
            }

            // HELD BY THE RULES. Deferred (the daily cap) is released, so a later tick sends
            // it once the window has passed; refused (a dead or complaining address) is
            // recorded as what it is. Neither is a failure, and neither counts toward the
            // run of failures that pauses a send — a cap is not an outage.
            $held = (string) ($res['held'] ?? '');
            if ($held === SendPolicy::DEFERRED) {
                BroadcastLog::release($key, $r['email']);
                $deferred++;
                continue;
            }
            if ($held === SendPolicy::REFUSED) {
                BroadcastLog::settle($key, $r['email'], false, (string) ($res['error'] ?? ''), BroadcastLog::REFUSED);
                continue;
            }

            $ok = (bool) ($res['success'] ?? false);
            BroadcastLog::settle($key, $r['email'], $ok, (string) ($res['error'] ?? ''));
            if ($ok) { $sent++; $streak = 0; continue; }

            $failed++;
            if (++$streak >= self::STOP_AFTER) {
                $note = 'Paused after ' . self::STOP_AFTER . ' failures in a row: '
                      . mb_substr((string) ($res['error'] ?? 'unknown'), 0, 160);
                break;
            }
        }

        $done2 = BroadcastLog::handled($key);
        $left  = count(array_filter($queue, static fn(array $r) => !isset($done2[$r['hash']])));
        if ($left > 0 && $note === null && $deferred > 0 && $sent + $failed === 0) {
            $note = sprintf('%d reader%s already had the day\'s announcements; they are sent once that window passes.',
                            $deferred, $deferred === 1 ? '' : 's');
        }
        $tally = BroadcastLog::tally($key);
        $upd   = ['sent' => $tally['sent'], 'failed' => $tally['failed'], 'note' => $note];
        if ($left === 0 && $note === null) {
            $upd += ['status' => self::ST_SENT, 'finished_at' => Carbon::now()->toDateTimeString()];
        }
        DB::table('gates_newsletter_issues')->where('id', (int) $issue->id)->update($upd);

        return ['sent' => $sent, 'failed' => $failed, 'left' => $left, 'note' => $note];
    }

    private function note(object $issue, string $why): void
    {
        DB::table('gates_newsletter_issues')->where('id', (int) $issue->id)->update(['note' => mb_substr($why, 0, 300)]);
    }

    // ══ the schedule ═════════════════════════════════════════════════════════

    /**
     * One maintenance tick: compose if due, send a batch if one is approved, ask a few
     * legacy subscribers to confirm. Returns the number of messages that left.
     */
    public function tick(?NewsletterSchedule $schedule = null, ?Carbon $now = null): int
    {
        $schedule = $schedule ?? NewsletterSchedule::load();
        if (!$schedule->on()) return 0;
        $now = $now ?? Carbon::now();

        // A HOLIDAY FIRST, so the regular slot on the same day finds it and steps aside.
        // On its day, from the newsletter's hour in the display zone; a holiday is not
        // carried over to the next morning, because a greeting a day late is not one.
        $h = HolidayCalendar::load()->today($now);
        if ($h !== null) {
            $local = $now->copy()->setTimezone(DisplayTime::zone());
            $key = self::holidayKey($h['key'], (int) $local->year);
            if ((int) $local->hour >= $schedule->hour
                && !DB::table('gates_newsletter_issues')->where('period_key', $key)->exists()) {
                self::compose($key, $schedule, $now, $h);
            }
        }

        $slot = $schedule->dueSlot($now);
        if ($slot !== null) {
            $key = $schedule->periodKey($slot);
            $exists = DB::table('gates_newsletter_issues')->where('period_key', $key)->exists();
            if (!$exists) self::compose($key, $schedule, $now);
        }

        $count = 0;
        if (($issue = self::active()) !== null) {
            $count += $this->sendBatch($issue)['sent'];
        }
        $count += $this->askUnconfirmed(self::ASK_BATCH);
        return $count;
    }

    /** Ask subscribers from before confirmation existed — each of them once. */
    public function askUnconfirmed(int $limit): int
    {
        if ($this->blocker() !== null) return 0;
        $n = 0;
        foreach (NewsletterAudience::unasked($limit) as $email) {
            if (NewsletterAudience::askToConfirm($email, $this->site,
                    NewsletterAudience::transport($this->mailer), true)) {
                $n++;
            }
        }
        return $n;
    }
}
