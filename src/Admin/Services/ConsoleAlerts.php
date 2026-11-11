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
        self::$errored = [];
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
        self::$errored = [];
        foreach (self::probes() as $key => $probe) {
            try {
                $a = $probe();
            } catch (\Throwable) {
                self::$errored[] = $key;
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
     * @return array{label:string, tone:string, href:string, items:list<array<string,mixed>>, total:int}|null
     */
    public static function pill(string $role): ?array
    {
        if (!Permissions::canOpen($role, '/admin/alerts')) return null;

        $open = self::open();
        $affects = array_values(array_filter($open, static fn (array $a): bool => ($a['impact'] ?? null) === 'affects'));
        $may     = array_values(array_filter($open, static fn (array $a): bool => ($a['impact'] ?? null) === 'may'));
        // What the bell's menu lists, production first: the alerts reaching people, then
        // the ones that will, then the rest — each linking to its own detail. From the
        // same memoised read as the count, so the menu and the number agree.
        $shape = static fn (array $a): array => [
            'title' => $a['title'], 'severity' => $a['severity'], 'source' => $a['source'], 'impact' => $a['impact'] ?? null,
            'raised' => $a['raised'], 'href' => '/admin/alerts?alert=' . rawurlencode((string) $a['key']),
        ];
        $prod = array_map($shape, array_merge($affects, $may));
        $rest = array_map($shape, array_values(array_filter($open, static fn (array $a): bool => ($a['impact'] ?? null) === null)));
        $base = ['href' => '/admin/alerts', 'production' => $prod, 'items' => array_slice($rest, 0, 5),
                 'total' => count($open), 'high' => count(self::high())];

        // Severity and impact are not the same thing, and the pill says the one that
        // matters to somebody outside: whether the site is failing people now.
        if ($affects !== []) {
            $n = count($affects);
            return ['label' => $n . ' affecting production', 'tone' => 'high'] + $base;
        }
        if ($may !== []) {
            $n = count($may);
            return ['label' => $n . ' may affect production', 'tone' => 'may'] + $base;
        }
        $n = count($open);
        return ['label' => $n > 0 ? $n . ' alert' . ($n === 1 ? '' : 's') : 'All systems normal',
                'tone' => $n > 0 ? 'some' : 'none'] + $base;
    }

    /**
     * Each check, by the key its alert carries. Keyed so a check that THROWS is known by
     * name ({@see errored()}): an alert whose check could not run is not an alert that
     * resolved, and the alert log must not close it.
     *
     * Every alert states two things separately, because they are not the same thing
     * (README §10b): SEVERITY, how bad the fault is; and PRODUCTION IMPACT — `affects`
     * when people using the site are failing right now, `may` when they will if it is
     * left, null when nobody outside the console can tell. `who` names the people, and
     * `steps` are the fix in order.
     *
     * @return array<string, callable():?array<string,mixed>>
     */
    private static function probes(): array
    {
        return [
            // ── email is down — the outage that cannot report itself ─────────────
            'mail' => static function (): ?array {
                $b = \AfricaGates\Services\Mail\MailHealth::banner();
                if ($b === null) return null;
                return self::alert('mail', self::HIGH, 'Email is not sending — ' . $b['title'], 'Email',
                    (string) $b['fix'],
                    array_values(array_filter([
                        $b['since'] ? ['Since', (string) $b['since']] : null,
                        ['Read from', $b['live'] ? 'the last hour of the send log' : 'the open incident'],
                    ])),
                    '/admin/settings/mail', 'See the diagnosis', $b['since'] ?? null,
                    'affects', 'Sign-in codes, receipts, tickets and invitations are not reaching people.',
                    ['Open Email health and read the diagnosis — it names the step that fails.',
                     'Fix that one setting (host, port, sign-in or sender), then press Send a test.',
                     'Once a test arrives, this alert resolves on its own.']);
            },

            // ── the schedule has stopped — reconciliation and refunds with it ────
            'cron' => static function (): ?array {
                $h = \AfricaGates\Support\CronHealth::status();
                if ($h['ok']) return null;
                $title = $h['never'] ? 'Scheduled maintenance has never run'
                       : ($h['stale'] ? 'Scheduled maintenance has stopped'
                                      : 'Scheduled maintenance is failing');
                return self::alert('cron', self::HIGH, $title, 'Platform', (string) ($h['say'] ?? ''),
                    [['Last run', (string) ($h['last'] ?? 'never')], ['Last clean run', (string) ($h['clean'] ?? 'never')]],
                    '/admin/settings', 'Open Automation & cron', $h['last'] ?? null,
                    'affects', 'Payments the browser callback missed are not being confirmed, and refunds owed are not being paid.',
                    ['Open Settings → Automation & cron and press Run maintenance now.',
                     'If it fails, the run\'s own result names the task; if it succeeds, check the webcron job still calls /__cron/run.',
                     'The alert resolves after the next clean run.']);
            },

            // ── the schema is behind the code ───────────────────────────────────
            'migrations' => static function (): ?array {
                $pending = \AfricaGates\Services\MigrationRunner::status()['pending'] ?? [];
                $n = count($pending);
                if ($n === 0) return null;
                return self::alert('migrations', self::HIGH,
                    $n . ' database migration step' . ($n === 1 ? '' : 's') . ' not applied', 'Platform',
                    'If actions that write new data — approving nominations, creating judges, building '
                    . 'forms — fail while pages still load, this is why: the schema is behind the code.',
                    [['Pending', implode(', ', array_slice(array_map('strval', $pending), 0, 6))
                                 . ($n > 6 ? ' +' . ($n - 6) . ' more' : '')]],
                    null, null, null,
                    'may', 'Any page that saves something new may fail while the rest of the site keeps loading.',
                    ['With no shell, open /__setup/migrate?token=YOUR_SETUP_TOKEN in a browser (or run php bin/console db:migrate on the server).',
                     'It is idempotent and safe to re-run; each step prints OK.',
                     'The alert resolves when nothing is pending.']);
            },

            // ── money on a clock: a chargeback Paystack will accept for you ───────
            'chargebacks' => static function (): ?array {
                $n = (int) DB::table('gates_support_tickets')
                    ->whereIn('status', ['open', 'pending'])
                    ->where('subject', 'like', 'Chargeback%')->count();
                if ($n === 0) return null;
                $hours = \AfricaGates\Services\DisputeService::RESPOND_WITHIN_HOURS;
                return self::alert('chargebacks', self::HIGH,
                    $n . ($n === 1 ? ' chargeback awaits' : ' chargebacks await') . ' a response', 'Payments',
                    'Paystack accepts a dispute for you after ' . $hours
                    . ' hours and refunds from your balance. The disputes screen attaches the receipt in one press.',
                    [['Open', (string) $n], ['Respond within', $hours . ' hours']], '/admin/payments/disputes', 'Respond', null,
                    'may', 'Money already paid out of the balance if nobody answers within ' . $hours . ' hours.',
                    ['Open Disputes and choose the oldest.', 'Attach the generated receipt and send the response.']);
            },

            // ── a guarantee the platform advertises is missing from the schema ───
            'schema' => static function (): ?array {
                $w = \AfricaGates\Services\VoteIndexRepair::warnings();
                if ($w === []) return null;
                $critical = array_values(array_filter($w, static fn ($x): bool => ($x['severity'] ?? '') === 'critical'));
                $first = $critical[0] ?? $w[0];
                return self::alert('schema', $critical ? self::HIGH : self::LOW,
                    $critical ? 'The vote table is missing a guarantee' : 'The vote table is missing an index',
                    'Database', (string) ($first['message'] ?? ''),
                    [['Fix', (string) ($first['fix'] ?? '')], ['Warnings', (string) count($w)]],
                    null, null, null,
                    $critical ? 'may' : null,
                    $critical ? 'A vote could be counted twice until the guarantee is restored.' : '',
                    array_values(array_filter([(string) ($first['fix'] ?? ''), 'The alert resolves when the index is in place.'])));
            },

            // ── money arrives and the gateway has gone quiet ─────────────────────
            'callbacks' => static function (): ?array {
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
                    '/admin/payments', 'Ask the gateway', $oldest,
                    'affects', $n . ' supporter' . ($n === 1 ? ' has' : 's have') . ' paid and may not yet see their votes or receipt.',
                    ['Open Payments and press Ask the gateway on the pending rows.',
                     'If the gateway confirms them, the votes are minted and receipts sent.',
                     'If callbacks stay quiet, check the webhook URL in the gateway\'s dashboard.']);
            },

            // ── messages the platform tried to send and could not ───────────────
            'messages' => static function (): ?array {
                $n = (int) DB::table('gates_messages')->where('status', 'failed')
                    ->where('created_at', '>=', Carbon::now()->subDay()->toDateTimeString())->count();
                if ($n === 0) return null;
                return self::alert('messages', self::MEDIUM,
                    $n . ' message' . ($n === 1 ? '' : 's') . ' failed in 24 hours', 'Messaging',
                    'SMS or WhatsApp sends the provider refused. Sign-in codes are among them.',
                    [['Failed in 24 hours', (string) $n]], '/admin/settings/providers', 'Run the checks', null,
                    'may', 'People signing in by text may not get their code.',
                    ['Open Integrations and press Run all checks.', 'Each prints the provider\'s own answer — usually credit or a sender ID.']);
            },

            // ── people the site failed: 500s and refused checkouts ────────────────
            //
            // Every 500 has written a reference to the error log since Phase 9, and the page
            // tells the visitor to quote it — while nothing told anybody running the site
            // that it had happened. The only reader was a token-gated setup page, so a fault
            // reached the console when somebody complained and not before. Counted from the
            // log itself (PublicFault::recent(), the one parser); derived like every alert
            // here, so it closes on its own a day after the last one.
            'faults' => static function (): ?array {
                $r = \AfricaGates\Support\PublicFault::recent(24, 1);
                $n = $r['total'];
                if ($n === 0) return null;
                $hour = $r['last_hour'];
                $sev  = $hour >= 10 ? self::HIGH : ($n >= 3 || $r['money'] > 0 ? self::MEDIUM : self::LOW);
                $top  = array_key_first($r['by_where']);
                $newest = $r['entries'][0] ?? null;
                $facts = [['In 24 hours', (string) $n], ['In the last hour', (string) $hour]];
                if ($top !== null) $facts[] = ['Most often', $top . ' (' . $r['by_where'][$top] . ')'];
                if ($r['money'] > 0) $facts[] = ['While paying or voting', (string) $r['money']];
                if ($newest !== null && $newest['ref'] !== null) $facts[] = ['Newest reference', $newest['ref']];
                return self::alert('faults', $sev,
                    $n . ' error' . ($n === 1 ? '' : 's') . ' shown to visitors in 24 hours', 'Site',
                    'Pages that failed on our side. Each person was shown a reference to quote; this is the same list, before anybody writes in.',
                    $facts, '/admin/errors', 'Read the errors', $newest['at'] ?? null,
                    $hour > 0 ? 'affects' : null,
                    $hour > 0 ? 'Visitors are meeting an error page now.' : '',
                    ['Open the errors and start with the page that fails most often.',
                     'A reference somebody quotes in a ticket opens its full entry there.',
                     'This clears on its own a day after the last error.']);
            },

            // ── the public site is closed for planned work ───────────────────────
            //
            // Closing the site is a setting (MaintenanceGate), and staff walk straight through
            // a closed site — so the one group who can open it again is the one group that
            // cannot see it is shut. Said here until the window ends.
            'closed' => static function (): ?array {
                $p = \AfricaGates\Services\MaintenanceGate::closed();
                if ($p === null) return null;
                return self::alert('closed', self::MEDIUM, 'The public site is closed for planned work', 'Site',
                    'Visitors see the planned-work page until ' . \AfricaGates\Support\DisplayTime::showZoned((string) $p['to'], 'D j M, H:i')
                    . '. Staff, sign-in, payment confirmations and the status page are open.',
                    [['What', (string) $p['title']], ['Back at', \AfricaGates\Support\DisplayTime::showZoned((string) $p['to'], 'D j M, H:i')]],
                    '/admin/settings#planned-work', 'Change the window', (string) $p['from'],
                    'affects', 'Every visitor, until the window ends.',
                    ['To open the site now, untick "Close the public site while it runs" and save.']);
            },

            // ── outbound webhooks that did not land ─────────────────────────────
            'webhooks' => static function (): ?array {
                $n = (int) DB::table('gates_webhook_deliveries')->where('ok', 0)
                    ->where('created_at', '>=', Carbon::now()->subDay()->toDateTimeString())->count();
                if ($n === 0) return null;
                return self::alert('webhooks', self::LOW,
                    $n . ' webhook deliver' . ($n === 1 ? 'y' : 'ies') . ' failed in 24 hours', 'Integrations',
                    'An endpoint this platform sends events to did not accept them.',
                    [['Failed in 24 hours', (string) $n]], '/admin/webhooks', 'Open webhooks', null,
                    null, '', ['Open Webhooks and read the failed deliveries\' responses.']);
            },
        ];
    }

    /** Keys of the checks that threw on this request — unknown, never resolved. */
    private static array $errored = [];

    /** @return list<string> */
    public static function errored(): array
    {
        self::open();
        return self::$errored;
    }

    /** Every key a check can raise, for the alert log and the tests. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::probes());
    }

    /**
     * @param list<array{0:string,1:string}> $facts
     * @param list<string> $steps
     * @return array<string,mixed>
     */
    private static function alert(string $key, string $severity, string $title, string $source,
                                  string $summary, array $facts, ?string $href, ?string $cta,
                                  ?string $raised, ?string $impact = null, string $who = '', array $steps = []): array
    {
        return ['key' => $key, 'severity' => $severity, 'title' => $title, 'source' => $source,
                'summary' => $summary, 'facts' => $facts, 'href' => $href, 'cta' => $cta,
                'raised' => $raised, 'impact' => $impact, 'who' => $who, 'steps' => $steps];
    }
}
