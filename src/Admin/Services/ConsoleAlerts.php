<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\Permissions;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * WHAT THE PLATFORM NOTICED ON ITS OWN — the console's alerts, derived on read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THERE IS NO ALERTS TABLE (YET)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every alert here is a FACT the platform already records: an open mail incident, the
 * schedule's last run, a chargeback ticket, the migration ledger, the vote table's
 * indexes, the message and webhook logs. Deriving them on read means an alert cannot
 * outlive the thing it is about — the same argument `AlertService` makes for members —
 * and it means nothing has to remember to close one.
 *
 * What a table WOULD add is the human half the admin handoff draws (§4.3): who has taken
 * an alert, why it was closed, and reopening when the check fails again. That is the
 * Alerts screen's own build (stage 2) and it needs a decision about what "closed" means
 * for a fact that is still true. Until then an alert is open exactly while its check
 * fails, which is the honest reading of the data there is.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT REPLACED THREE BANNERS, AND KEEPS THEIR PROMISE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The old layout printed three red banners on every console page — migrations behind,
 * the schedule stopped, email down — because each of those outages is invisible from
 * the public site and the one thing that could announce it is the thing that broke. The
 * rebuilt shell carries the same promise in the top bar's alert pill ("N high alerts",
 * in red, on every page) and on Home's "needs a person" list, both read from here — one
 * reader of each fact, so the pill and the page can never disagree.
 *
 * Every probe is wrapped: a table a deployment has not migrated costs that one alert,
 * never the page — this runs on every console request.
 */
final class ConsoleAlerts
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** Sorted High → Low, as README §4.3 orders the table. */
    private const RANK = [self::HIGH => 0, self::MEDIUM => 1, self::LOW => 2];

    /** Per-request memo: the layout's pill, the rail's count and Home all ask. */
    private static ?array $memo = null;

    /** For tests: forget the memo so a seeded fact is read. */
    public static function reset(): void
    {
        self::$memo = null;
    }

    /**
     * Every open alert, most severe first.
     *
     * @return list<array{key:string, severity:string, title:string, source:string,
     *                    summary:string, facts:list<array{0:string,1:string}>,
     *                    href:?string, cta:?string, raised:?string}>
     */
    public static function open(): array
    {
        if (self::$memo !== null) return self::$memo;

        $out = [];
        foreach (self::probes() as $probe) {
            try {
                $a = $probe();
            } catch (\Throwable) {
                continue;
            }
            if ($a !== null) $out[] = $a;
        }
        usort($out, static fn (array $a, array $b): int =>
            (self::RANK[$a['severity']] ?? 9) <=> (self::RANK[$b['severity']] ?? 9));

        return self::$memo = $out;
    }

    /** @return list<array<string,mixed>> */
    public static function high(): array
    {
        return array_values(array_filter(self::open(), static fn (array $a): bool => $a['severity'] === self::HIGH));
    }

    /**
     * The top bar's pill: what it says and how loudly. Null for a role that cannot open
     * the Alerts page — the pill is a link to it, and rule 7 forbids offering a door the
     * guard will close.
     *
     * @return array{label:string, tone:string, href:string}|null
     */
    public static function pill(string $role): ?array
    {
        if (!Permissions::canOpen($role, '/admin/alerts')) return null;

        $open = self::open();
        $high = count(self::high());
        if ($high > 0) {
            return ['label' => $high . ' high alert' . ($high === 1 ? '' : 's'), 'tone' => 'high',
                    'href' => '/admin/alerts?tab=high'];
        }
        $n = count($open);
        return ['label' => $n > 0 ? $n . ' alert' . ($n === 1 ? '' : 's') : 'No alerts',
                'tone' => $n > 0 ? 'some' : 'none', 'href' => '/admin/alerts'];
    }

    /** @return list<callable():?array<string,mixed>> */
    private static function probes(): array
    {
        return [
            // ── email is down — the outage that cannot report itself ─────────────
            static function (): ?array {
                $b = \AfricaGates\Services\Mail\MailHealth::banner();
                if ($b === null) return null;
                return self::alert('mail', self::HIGH, 'Email is not sending — ' . $b['title'], 'Email',
                    (string) $b['fix'],
                    array_values(array_filter([
                        $b['since'] ? ['Since', (string) $b['since']] : null,
                        ['Read from', $b['live'] ? 'the last hour of the send log' : 'the open incident'],
                    ])),
                    '/admin/settings/mail', 'See the diagnosis', $b['since'] ?? null);
            },

            // ── the schedule has stopped — reconciliation and refunds with it ────
            static function (): ?array {
                $h = \AfricaGates\Support\CronHealth::status();
                if ($h['ok']) return null;
                $title = $h['never'] ? 'Scheduled maintenance has never run'
                       : ($h['stale'] ? 'Scheduled maintenance has stopped'
                                      : 'Scheduled maintenance is failing');
                return self::alert('cron', self::HIGH, $title, 'Platform', (string) ($h['say'] ?? ''),
                    [['Last run', (string) ($h['last'] ?? 'never')], ['Last clean run', (string) ($h['clean'] ?? 'never')]],
                    '/admin/settings', 'Open Automation & cron', $h['last'] ?? null);
            },

            // ── the schema is behind the code ───────────────────────────────────
            static function (): ?array {
                $pending = \AfricaGates\Services\MigrationRunner::status()['pending'] ?? [];
                $n = count($pending);
                if ($n === 0) return null;
                return self::alert('migrations', self::HIGH,
                    $n . ' database migration step' . ($n === 1 ? '' : 's') . ' not applied', 'Platform',
                    'If actions that write new data — approving nominations, creating judges, building '
                    . 'forms — fail while pages still load, this is why: the schema is behind the code. '
                    . 'Run `php bin/console db:migrate` on the server or, with no shell, open '
                    . '/__setup/migrate?token=YOUR_SETUP_TOKEN. It is idempotent and safe to re-run.',
                    [['Pending', implode(', ', array_slice(array_map('strval', $pending), 0, 6))
                                 . ($n > 6 ? ' +' . ($n - 6) . ' more' : '')]],
                    null, null, null);
            },

            // ── money on a clock: a chargeback Paystack will accept for you ───────
            static function (): ?array {
                $n = (int) DB::table('gates_support_tickets')
                    ->whereIn('status', ['open', 'pending'])
                    ->where('subject', 'like', 'Chargeback%')->count();
                if ($n === 0) return null;
                return self::alert('chargebacks', self::HIGH,
                    $n . ($n === 1 ? ' chargeback awaits' : ' chargebacks await') . ' a response', 'Payments',
                    'Paystack accepts a dispute for you after '
                    . \AfricaGates\Services\DisputeService::RESPOND_WITHIN_HOURS
                    . ' hours and refunds from your balance. The disputes screen attaches the receipt in one press.',
                    [['Open', (string) $n]], '/admin/payments/disputes', 'Respond', null);
            },

            // ── a guarantee the platform advertises is missing from the schema ───
            static function (): ?array {
                $w = \AfricaGates\Services\VoteIndexRepair::warnings();
                if ($w === []) return null;
                $critical = array_values(array_filter($w, static fn ($x): bool => ($x['severity'] ?? '') === 'critical'));
                $first = $critical[0] ?? $w[0];
                return self::alert('schema', $critical ? self::HIGH : self::LOW,
                    $critical ? 'The vote table is missing a guarantee' : 'The vote table is missing an index',
                    'Database', (string) ($first['message'] ?? ''),
                    [['Fix', (string) ($first['fix'] ?? '')], ['Warnings', (string) count($w)]],
                    null, null, null);
            },

            // ── money arrives and the gateway has gone quiet ─────────────────────
            static function (): ?array {
                $hour = Carbon::now()->subHour()->toDateTimeString();
                $stuck = DB::table('gates_donations')->where('status', 'pending')
                    ->where('created_at', '<', $hour)
                    ->where('created_at', '>=', Carbon::now()->subDays(2)->toDateTimeString());
                $n = (int) (clone $stuck)->count();
                if ($n === 0) return null;
                $oldest = (string) (clone $stuck)->min('created_at');
                $h = \AfricaGates\Services\GatewayEventLog::health();
                $last = (string) ($h['last_at'] ?? '');
                if ($last !== '' && strtotime($last) >= strtotime($oldest)) return null;
                return self::alert('callbacks', self::MEDIUM, 'Payment callbacks have gone quiet', 'Payments',
                    'Payments are still pending here an hour after they started and the gateway has not called '
                    . 'back since before the oldest of them. The money may be at the gateway; asking it settles each one.',
                    [['Pending over an hour', (string) $n], ['Last gateway event', $last !== '' ? $last : 'never']],
                    '/admin/payments', 'Ask the gateway', $oldest);
            },

            // ── messages the platform tried to send and could not ───────────────
            static function (): ?array {
                $n = (int) DB::table('gates_messages')->where('status', 'failed')
                    ->where('created_at', '>=', Carbon::now()->subDay()->toDateTimeString())->count();
                if ($n === 0) return null;
                return self::alert('messages', self::MEDIUM,
                    $n . ' message' . ($n === 1 ? '' : 's') . ' failed in 24 hours', 'Messaging',
                    'SMS or WhatsApp sends the provider refused. Sign-in codes are among them. The integrations '
                    . 'check asks each provider a real question and prints its answer.',
                    [['Failed in 24 hours', (string) $n]], '/admin/settings/providers', 'Run the checks', null);
            },

            // ── outbound webhooks that did not land ─────────────────────────────
            static function (): ?array {
                $n = (int) DB::table('gates_webhook_deliveries')->where('ok', 0)
                    ->where('created_at', '>=', Carbon::now()->subDay()->toDateTimeString())->count();
                if ($n === 0) return null;
                return self::alert('webhooks', self::LOW,
                    $n . ' webhook deliver' . ($n === 1 ? 'y' : 'ies') . ' failed in 24 hours', 'Integrations',
                    'An endpoint this platform sends events to did not accept them.',
                    [['Failed in 24 hours', (string) $n]], '/admin/webhooks', 'Open webhooks', null);
            },
        ];
    }

    /**
     * @param list<array{0:string,1:string}> $facts
     * @return array<string,mixed>
     */
    private static function alert(string $key, string $severity, string $title, string $source,
                                  string $summary, array $facts, ?string $href, ?string $cta,
                                  ?string $raised): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'source' => $source,
                'summary' => $summary, 'facts' => $facts, 'href' => $href, 'cta' => $cta,
                'raised' => $raised];
    }
}
