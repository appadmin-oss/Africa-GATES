<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Services\EmailCampaign;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\NomineeBroadcast;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE ANNOUNCEMENT OUTBOX: QUEUED ONCE, SENT IN THE RIGHT ORDER, AT A PACE THE ACCOUNT CAN TAKE.
 *
 * Owner, 5 Nov 2026: "prioritises emails, optimises sends, is fast and reliable, dynamic, aware
 * of limits". Before this, a campaign went out only while somebody kept pressing Send — forty
 * at a time, a quarter-second apart, inside a web request — so a list of four thousand was a
 * hundred presses, and a list started on Friday evening stopped when the person went home.
 *
 * Now "Start sending" queues every recipient ONCE (UNIQUE(campaign, address), so pressing it
 * again queues nobody twice), sends a first batch at once, and every maintenance tick drains
 * the rest without anybody watching. What decides each tick:
 *
 *   ORDER    priority, from MailEngagement: people who read us first, first-time recipients
 *            next, the never-responding last. An A/B sample goes before all of them, because
 *            the rest of the list is waiting on its result.
 *   WHEN     `not_before`: a person who habitually clicks at 19:00 is sent at 19:00 (within
 *            {@see STO_MAX_HOURS}), a quiet reader is spaced to one announcement per
 *            `mail_quiet_days`, and a deferred message waits out a growing backoff.
 *   HOW MANY the smallest of: the hourly pace (`mail_outbox_per_hour`, a quarter of it per
 *            fifteen-minute tick); what is left of the day's announcement share of the mail
 *            account's allowance (SendPolicy::BULK_SHARE — sign-in codes keep the rest); and
 *            {@see TICK_SECONDS} of wall clock, so a tick never outlives the request driving it.
 *   NOTHING  while mail is failing (MailHealth has an incident open): sending into a broken
 *            transport burns the list. It resumes by itself.
 *
 * Every message still passes SendPolicy at the transport — the outbox decides order and pace,
 * never permission. A held answer is not a failure: DEFERRED is retried with backoff,
 * REFUSED (unsubscribed, bounced) is final. A real failure is retried twice, then left marked.
 */
final class Outbox
{
    public const QUEUED  = 'queued';
    public const SENDING = 'sending';
    public const SENT    = 'sent';
    public const HELD    = 'held';
    public const FAILED  = 'failed';

    public const PACE_KEY     = 'mail_outbox_per_hour';
    public const PACE_DEFAULT = 300;
    public const PACE_MIN     = 20;
    public const PACE_MAX     = 5000;
    public const STO_KEY      = 'mail_send_time';
    public const QUIET_KEY    = 'mail_quiet_days';
    public const QUIET_DEFAULT = 7;

    public const TICKS_PER_HOUR = 4;
    public const TICK_SECONDS   = 25;
    public const FIRST_BATCH    = 40;
    public const STO_MAX_HOURS  = 12;
    public const AB_SHARE       = 0.2;
    public const AB_MIN         = 20;
    public const AB_WAIT_HOURS  = 4;
    private const MAX_DEFERS    = 6;
    private const MAX_FAILS     = 3;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_mail_outbox');
    }

    /**
     * The three settings, read once and clamped at the read (CLAUDE.md: a number a screen
     * prints needs its floor where it is read). The one resolver for these keys.
     *
     * @return array{per_hour:int, send_time:bool, quiet_days:int}
     */
    public static function settings(?array $rows = null): array
    {
        $rows ??= DB::table('gates_settings')->whereIn('key_name', [self::PACE_KEY, self::STO_KEY, self::QUIET_KEY])
            ->pluck('value', 'key_name')->all();
        $pace = is_numeric($rows[self::PACE_KEY] ?? null) ? (int) $rows[self::PACE_KEY] : self::PACE_DEFAULT;
        $quiet = is_numeric($rows[self::QUIET_KEY] ?? null) ? (int) $rows[self::QUIET_KEY] : self::QUIET_DEFAULT;
        return [
            'per_hour'   => max(self::PACE_MIN, min(self::PACE_MAX, $pace)),
            'send_time'  => !in_array(strtolower(trim((string) ($rows[self::STO_KEY] ?? '1'))), ['0', 'off', 'false', 'no'], true),
            'quiet_days' => max(0, min(30, $quiet)),
        ];
    }

    /** Save the three settings from the console form. @return array{per_hour:int, send_time:bool, quiet_days:int} */
    public static function saveSettings(int $perHour, bool $sendTime, int $quietDays): array
    {
        $s = self::settings([self::PACE_KEY => $perHour, self::STO_KEY => $sendTime ? '1' : '0', self::QUIET_KEY => $quietDays]);
        foreach ([self::PACE_KEY => (string) $s['per_hour'], self::STO_KEY => $s['send_time'] ? '1' : '0',
                  self::QUIET_KEY => (string) $s['quiet_days']] as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
        return $s;
    }

    // ══ queueing ═════════════════════════════════════════════════════════════

    /**
     * Queue a campaign's whole plan. Returns how many were newly queued.
     *
     * @param list<array{nominee:object,cycle:object,email:string}> $rows NomineeBroadcast::plan()['sendable']
     */
    public static function enqueueCampaign(object $campaign, array $rows, ?Carbon $now = null): int
    {
        if (!self::ready() || $rows === []) return 0;
        $now ??= Carbon::now();
        $key = (string) $campaign->slug;
        $set = self::settings();
        $ab  = trim((string) ($campaign->subject_b ?? '')) !== '';

        $hashes = array_map(static fn (array $r): string => EmailOptOut::hash($r['email']), $rows);
        $prof = MailEngagement::profile($hashes, $now);

        // The A/B sample: a share of the list, at least AB_MIN a side, spread across tiers by
        // taking every k-th row of the list in its own order rather than the most engaged —
        // a sample of only the keenest readers would crown whichever subject they liked.
        $sample = [];
        if ($ab) {
            $n = max(self::AB_MIN * 2, (int) ceil(count($rows) * self::AB_SHARE));
            if ($n >= count($rows)) $n = count($rows);
            $step = max(1, (int) floor(count($rows) / max(1, $n)));
            for ($i = 0, $taken = 0; $i < count($rows) && $taken < $n; $i += $step, $taken++) $sample[$i] = $taken % 2 === 0 ? 'A' : 'B';
        }

        $queued = 0;
        $zone = DisplayTime::zone();
        foreach ($rows as $i => $r) {
            $h = $hashes[$i];
            $p = $prof[$h] ?? ['tier' => 'new', 'priority' => 50, 'hour' => null, 'last_bulk' => null];
            $when = $now->copy();
            if ($p['tier'] === 'quiet' && $set['quiet_days'] > 0 && $p['last_bulk']) {
                $next = Carbon::parse((string) $p['last_bulk'])->addDays($set['quiet_days']);
                if ($next->greaterThan($when)) $when = $next;
            }
            if ($set['send_time'] && $p['hour'] !== null && !isset($sample[$i])) {
                $local = $when->copy()->setTimezone($zone);
                $at = $local->copy()->setTime((int) $p['hour'], 0);
                if ($at->lessThan($local)) $at->addDay();
                if ($at->diffInHours($local, true) <= self::STO_MAX_HOURS) $when = $at->setTimezone($now->getTimezone());
            }
            $variant = $sample[$i] ?? null;   // the rest wait for the winner
            try {
                DB::table('gates_mail_outbox')->insert([
                    'campaign_key' => mb_substr($key, 0, 64), 'email' => mb_substr($r['email'], 0, 191), 'email_hash' => $h,
                    'payload' => json_encode(['nominee' => $r['nominee'], 'cycle' => $r['cycle'], 'email' => $r['email']]),
                    'priority' => isset($sample[$i]) ? 200 : (int) $p['priority'], 'tier' => $p['tier'], 'variant' => $variant,
                    'not_before' => $when->toDateTimeString(), 'status' => self::QUEUED, 'attempts' => 0,
                    'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString()]);
                $queued++;
            } catch (\Throwable) {
                // UNIQUE(campaign, address): already queued. Pressing Start twice queues nobody twice.
            }
        }
        if ($queued > 0 && SchemaHas::column('gates_email_campaigns', 'sending_at') && empty($campaign->sending_at)) {
            DB::table('gates_email_campaigns')->where('id', (int) $campaign->id)->update(['sending_at' => $now->toDateTimeString()]);
        }
        return $queued;
    }

    // ══ draining ═════════════════════════════════════════════════════════════

    /** How many this tick may send. 0 means wait. @return array{n:int, why:string} */
    public static function budget(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        if (MailHealth::open() !== null) return ['n' => 0, 'why' => 'Paused while email is failing; it resumes by itself.'];
        $pace = (int) ceil(self::settings()['per_hour'] / self::TICKS_PER_HOUR);
        $limit = MailConfig::load()->dailyLimit();
        if ($limit > 0) {
            $left = (int) floor($limit * SendPolicy::BULK_SHARE) - MailLog::sentSince($now->copy()->subHours(24)->toDateTimeString());
            if ($left <= 0) return ['n' => 0, 'why' => 'Today’s share of the mail account’s allowance is spent; sign-in codes keep the rest. It resumes as the day rolls.'];
            $pace = min($pace, $left);
        }
        return ['n' => $pace, 'why' => ''];
    }

    /**
     * Send what is due. Called by the maintenance tick (and once on Start, for the first batch).
     *
     * @return array{sent:int, held:int, failed:int, deferred:int, note:string}
     */
    public static function drain(OtpService $mailer, string $site, ?int $max = null, ?Carbon $now = null, float $seconds = self::TICK_SECONDS): array
    {
        $tally = ['sent' => 0, 'held' => 0, 'failed' => 0, 'deferred' => 0, 'note' => ''];
        if (!self::ready() || $site === '') return $tally;
        $now ??= Carbon::now();
        $b = self::budget($now);
        $n = $max === null ? $b['n'] : min($max, $b['n']);
        if ($n <= 0) { $tally['note'] = $b['why']; return $tally; }

        self::reclaim($now);
        foreach (self::campaignsWaitingOnAb($now) as $key) self::decideWinner($key, $now);

        $started = microtime(true);
        $rows = DB::table('gates_mail_outbox')->where('status', self::QUEUED)->where('not_before', '<=', $now->toDateTimeString())
            ->orderByDesc('priority')->orderBy('id')->limit($n * 2)->get()->all();
        $svc = $camps = [];
        foreach ($rows as $row) {
            if ($tally['sent'] + $tally['failed'] + $tally['deferred'] + $tally['held'] >= $n) break;
            if (microtime(true) - $started > $seconds) { $tally['note'] = 'Stopped for this tick at the time limit; the next tick carries on.'; break; }

            // Read once per DRAIN, never across drains: the A/B winner is decided above and a
            // campaign remembered from an earlier tick would never see it.
            $c = array_key_exists($row->campaign_key, $camps) ? $camps[$row->campaign_key]
               : ($camps[$row->campaign_key] = DB::table('gates_email_campaigns')->where('slug', $row->campaign_key)->first() ?: null);
            if ($c === null) { self::mark($row, self::FAILED, 'The campaign no longer exists.', $now); continue; }
            $subject = null;
            if (trim((string) ($c->subject_b ?? '')) !== '') {
                $v = $row->variant ?: ($c->ab_winner ?? null);
                if (!$v) continue;                      // waiting on the A/B result
                if ($row->variant === null) DB::table('gates_mail_outbox')->where('id', $row->id)->update(['variant' => $v]);
                $row->variant = $v;
                $subject = $v === 'B' ? (string) $c->subject_b : (string) $c->subject;
            }
            // Claim: only the tick that flips queued → sending sends it.
            if (DB::table('gates_mail_outbox')->where('id', $row->id)->where('status', self::QUEUED)
                    ->update(['status' => self::SENDING, 'updated_at' => $now->toDateTimeString()]) === 0) continue;

            $r = json_decode((string) $row->payload);
            $r = ['nominee' => $r->nominee ?? (object) [], 'cycle' => $r->cycle ?? (object) [], 'email' => (string) $row->email];
            $s = $svc[$row->campaign_key] ??= (new NomineeBroadcast())->forCampaign($c);
            try {
                $res = $s->sendOne($r, $site, $mailer, $subject, $row->variant, $row->email_hash);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'error' => $e->getMessage(), 'held' => null];
            }
            if ($res['ok']) {
                DB::table('gates_mail_outbox')->where('id', $row->id)->update(['status' => self::SENT, 'sent_at' => $now->toDateTimeString(),
                    'attempts' => (int) $row->attempts + 1, 'last_error' => null, 'updated_at' => $now->toDateTimeString()]);
                $tally['sent']++;
            } elseif (($res['held'] ?? null) === SendPolicy::REFUSED) {
                self::mark($row, self::HELD, (string) $res['error'], $now);
                $tally['held']++;
            } elseif (($res['held'] ?? null) === SendPolicy::DEFERRED && (int) $row->attempts < self::MAX_DEFERS) {
                self::retry($row, (string) $res['error'], $now, min(24, 2 ** (int) $row->attempts));
                $tally['deferred']++;
            } elseif (!($res['held'] ?? null) && (int) $row->attempts + 1 < self::MAX_FAILS) {
                self::retry($row, (string) $res['error'], $now, 0.5);
                $tally['failed']++;
            } else {
                self::mark($row, ($res['held'] ?? null) ? self::HELD : self::FAILED, (string) $res['error'], $now);
                $tally['failed']++;
            }
            usleep(100000);
        }
        self::finish($now);
        return $tally;
    }

    private static function mark(object $row, string $status, string $why, Carbon $now): void
    {
        DB::table('gates_mail_outbox')->where('id', $row->id)->update(['status' => $status, 'attempts' => (int) $row->attempts + 1,
            'last_error' => mb_substr($why, 0, 300) ?: null, 'updated_at' => $now->toDateTimeString()]);
    }

    private static function retry(object $row, string $why, Carbon $now, float $hours): void
    {
        DB::table('gates_mail_outbox')->where('id', $row->id)->update(['status' => self::QUEUED, 'attempts' => (int) $row->attempts + 1,
            'last_error' => mb_substr($why, 0, 300) ?: null, 'not_before' => $now->copy()->addMinutes((int) round($hours * 60))->toDateTimeString(),
            'updated_at' => $now->toDateTimeString()]);
    }

    /** A row left `sending` by a request that died is queued again after ten minutes. */
    private static function reclaim(Carbon $now): void
    {
        DB::table('gates_mail_outbox')->where('status', self::SENDING)->where('updated_at', '<', $now->copy()->subMinutes(10)->toDateTimeString())
            ->update(['status' => self::QUEUED, 'updated_at' => $now->toDateTimeString()]);
    }

    /** A campaign whose queue is empty is marked sent, with the log's own count. */
    private static function finish(Carbon $now): void
    {
        if (!SchemaHas::column('gates_email_campaigns', 'sending_at')) return;
        foreach (DB::table('gates_email_campaigns')->whereNotNull('sending_at')->where('status', '!=', 'sent')->get(['id', 'slug']) as $c) {
            $open = DB::table('gates_mail_outbox')->where('campaign_key', $c->slug)->whereIn('status', [self::QUEUED, self::SENDING])->exists();
            if (!$open) EmailCampaign::markSent((int) $c->id, EmailCampaign::sentCount((string) $c->slug));
        }
    }

    // ══ A/B ══════════════════════════════════════════════════════════════════

    /** @return list<string> campaigns with a second subject and no winner yet */
    private static function campaignsWaitingOnAb(Carbon $now): array
    {
        if (!SchemaHas::column('gates_email_campaigns', 'subject_b')) return [];
        return DB::table('gates_email_campaigns')->whereNotNull('sending_at')->whereNotNull('subject_b')->where('subject_b', '!=', '')
            ->where(static fn ($q) => $q->whereNull('ab_winner')->orWhere('ab_winner', ''))->pluck('slug')->map('strval')->all();
    }

    /**
     * Crown a subject once the sample has been out AB_WAIT_HOURS: the one more people pressed
     * a link in, as a share of those it reached. A tie, or no clicks at all, is A — the
     * subject the campaign was approved with is the default, never a coin toss.
     */
    public static function decideWinner(string $key, Carbon $now): ?string
    {
        $sample = DB::table('gates_mail_outbox')->where('campaign_key', $key)->whereIn('variant', ['A', 'B']);
        if ((clone $sample)->whereIn('status', [self::QUEUED, self::SENDING])->exists()) return null;
        $last = (clone $sample)->max('sent_at');
        if ($last === null || Carbon::parse((string) $last)->addHours(self::AB_WAIT_HOURS)->greaterThan($now)) return null;
        $rep = MailTraction::report($key)['variants'];
        $a = $rep['A']['rate'] ?? 0.0;
        $b = $rep['B']['rate'] ?? 0.0;
        $win = $b > $a ? 'B' : 'A';
        DB::table('gates_email_campaigns')->where('slug', $key)->update(['ab_winner' => $win]);
        return $win;
    }

    // ══ reading ══════════════════════════════════════════════════════════════

    /**
     * Where one campaign's queue stands, and when it should be done at the current pace.
     *
     * @return array{queued:int, sent:int, held:int, failed:int, waiting_ab:int, eta:?string, tiers:array<string,int>}
     */
    public static function status(string $key, ?Carbon $now = null): array
    {
        $out = ['queued' => 0, 'sent' => 0, 'held' => 0, 'failed' => 0, 'waiting_ab' => 0, 'eta' => null, 'tiers' => []];
        if (!self::ready()) return $out;
        foreach (DB::table('gates_mail_outbox')->where('campaign_key', $key)->groupBy('status')
                     ->get([DB::raw('status as s'), DB::raw('COUNT(*) as n')]) as $r) {
            $s = (string) $r->s === self::SENDING ? 'queued' : (string) $r->s;
            if (isset($out[$s])) $out[$s] += (int) $r->n;
        }
        foreach (DB::table('gates_mail_outbox')->where('campaign_key', $key)->groupBy('tier')
                     ->get([DB::raw('tier as t'), DB::raw('COUNT(*) as n')]) as $r) {
            $out['tiers'][(string) ($r->t ?: 'normal')] = (int) $r->n;
        }
        $out['waiting_ab'] = (int) DB::table('gates_mail_outbox')->where('campaign_key', $key)->where('status', self::QUEUED)->whereNull('variant')
            ->whereExists(static fn ($q) => $q->from('gates_email_campaigns')->whereColumn('gates_email_campaigns.slug', 'gates_mail_outbox.campaign_key')
                ->whereNotNull('subject_b')->where('subject_b', '!=', '')->where(static fn ($w) => $w->whereNull('ab_winner')->orWhere('ab_winner', '')))
            ->count();
        if ($out['queued'] > 0) {
            $now ??= Carbon::now();
            $hours = $out['queued'] / max(1, self::settings()['per_hour']);
            $out['eta'] = $now->copy()->addMinutes((int) ceil($hours * 60))->setTimezone(DisplayTime::zone())->format('D j M, H:i');
        }
        return $out;
    }

    /** Stop a campaign: whatever has not gone yet is not sent. */
    public static function cancel(string $key): int
    {
        if (!self::ready()) return 0;
        return DB::table('gates_mail_outbox')->where('campaign_key', $key)->where('status', self::QUEUED)
            ->update(['status' => self::HELD, 'last_error' => 'Stopped by an administrator.', 'updated_at' => Carbon::now()->toDateTimeString()]);
    }
}
