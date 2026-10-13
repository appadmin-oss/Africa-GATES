<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\Permissions;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Home, from real figures: the shortcut tiles, "N things need a person", the quiet
 * numbers, and the counts the rail prints beside three pages.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SIX SOURCES THE HANDOFF NAMES, THEN EVERY JOB THE OLD BOARD ALREADY FOUND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * README §3 names six: the review queue, paid with no votes, orders the gateway has not
 * been asked about, a category short of judge quorum, high alerts, and unanswered partner
 * enquiries. They come first, in that order, with the HTML's own words.
 *
 * The dashboard this replaces ({@see AttentionBoard}) had already been finding jobs the
 * handoff does not list — an interview whose time has passed, a transcript nobody
 * published, nominees never told their questionnaire exists, community posts held for a
 * moderator, open support conversations, profiles waiting. Dropping them would be the
 * rebuild losing work the console already did ("keep every feature they already have"),
 * so they follow the six, read from AttentionBoard's own probes — one definition of each
 * — and the owner is asked in PHASE-ADMIN.md whether to keep them. Its chargeback card is
 * the one left out: a chargeback is a HIGH alert now ({@see ConsoleAlerts}), so it
 * arrives inside the alerts card rather than twice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING HERE OFFERS A DOOR THE GUARD WILL CLOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every card, tile and count is filtered by {@see Permissions::canOpen()} on its own
 * href — the guard's function — so "Only jobs you can act on are shown" is true by
 * construction (rule 7). And a zero is never a card: a grid of green zeroes trains an
 * operator to stop reading it.
 */
final class HomeBoard
{
    /** "Over 4 hours" is late (§4.1's target). */
    private const LATE_MINUTES = 240;

    /** Per-request memo for the rail counts, which every console page draws. */
    private static ?array $counts = null;

    public static function reset(): void
    {
        self::$counts = null;
    }

    /**
     * The jobs, for one role.
     *
     * @return list<array{key:string, hub:string, count:int, label:string, why:string,
     *                    cta:string, href:string, tone:string}>
     */
    public static function board(string $role): array
    {
        $out = [];
        foreach (self::sources() as $probe) {
            try {
                $card = $probe();
            } catch (\Throwable) {
                continue;     // an unmigrated table costs one card, never Home
            }
            if ($card === null || $card['count'] < 1) continue;
            if (!Permissions::canOpen($role, $card['href'])) continue;
            $out[] = $card;
        }

        // The jobs the old board found that the handoff does not list — after the six.
        $have = array_column($out, 'key');
        foreach (AttentionBoard::forRole($role) as $i) {
            $key = (string) $i['key'];
            if (in_array($key, ['disputes', 'nominations', 'partners'], true) || in_array($key, $have, true)) continue;
            $out[] = [
                'key'   => $key,
                'hub'   => self::hubFor((string) $i['href']),
                'count' => (int) $i['count'],
                'label' => (string) $i['label'],
                'why'   => (string) $i['why'],
                'cta'   => (string) $i['cta'],
                'href'  => (string) $i['href'],
                'tone'  => match ((string) $i['tone']) { 'urgent' => 'danger', 'warn' => 'warning', default => 'muted' },
            ];
        }
        return $out;
    }

    private static function hubFor(string $href): string
    {
        return match (true) {
            str_starts_with($href, '/admin/support')       => 'Daily work',
            str_starts_with($href, '/admin/moderation')    => 'Daily work',
            str_starts_with($href, '/admin/profiles')      => 'Entries',
            default                                        => 'Entries',
        };
    }

    /** @return list<callable():?array<string,mixed>> */
    private static function sources(): array
    {
        return [
            // 1 · the review queue
            static function (): ?array {
                $q = DB::table('gates_nominations')->where('status', 'pending');
                $n = (int) (clone $q)->count();
                if ($n === 0) return null;
                $oldest = (string) (clone $q)->min('created_at');
                $late = $oldest !== '' && strtotime($oldest) < time() - self::LATE_MINUTES * 60;
                return self::card('review', 'Entries', $n,
                    $n === 1 ? 'nomination waiting for a decision' : 'nominations waiting for a decision',
                    'Nothing is public until a person approves it.', 'Review', '/admin/nominations/review',
                    $late ? 'danger' : 'warning');
            },
            // 2 · paid, no votes, no refund
            static function (): ?array {
                $n = self::triage()['refund_owed'] ?? 0;
                return self::card('owed', 'Money', $n,
                    $n === 1 ? 'paid order with no votes and no refund' : 'paid orders with no votes and no refund',
                    'Someone paid and got nothing. This is the one that becomes a complaint.',
                    'Resolve', '/admin/payments', 'danger');
            },
            // 3 · orders the gateway has not been asked about
            static function (): ?array {
                $n = self::triage()['recoverable'] ?? 0;
                return self::card('unasked', 'Money', $n,
                    $n === 1 ? 'order the gateway has not been asked about' : 'orders the gateway has not been asked about',
                    'Pending on our side. One question to the gateway settles each.',
                    'Ask', '/admin/payments', 'warning');
            },
            // 4 · a category short of judge quorum
            static function (): ?array {
                $short = self::shortOfQuorum();
                if ($short === null) return null;
                return self::card('quorum', 'Awards · ' . $short['edition'], $short['count'],
                    $short['count'] === 1 ? 'category short of judge quorum' : 'categories short of judge quorum',
                    implode(', ', array_slice($short['names'], 0, 3))
                        . ($short['count'] > 3 ? ' and ' . ($short['count'] - 3) . ' more' : '')
                        . ' can\'t be released until enough judges have completed a scorecard.',
                    'Open', '/admin/result-release?cycle=' . $short['cycle'], 'warning');
            },
            // 5 · high alerts
            static function (): ?array {
                $high = ConsoleAlerts::high();
                $n = count($high);
                return self::card('alerts', 'Health', $n,
                    $n === 1 ? 'high-severity alert open' : 'high-severity alerts open',
                    implode('. ', array_map(static fn (array $a): string => rtrim((string) $a['title'], '.'), $high)) . '.',
                    'Triage', '/admin/alerts?tab=high', 'danger');
            },
            // 6 · partner enquiries unanswered
            static function (): ?array {
                $n = (int) DB::table('gates_partner_enquiries')->whereIn('status', ['new', 'in_review'])->count();
                return self::card('partners', 'Publishing', $n,
                    $n === 1 ? 'partner enquiry unanswered' : 'partner enquiries unanswered',
                    'An organisation offered to help and has heard nothing back.', 'Reply', '/admin/partners', 'muted');
            },
        ];
    }

    /** @return array<string,mixed>|null */
    private static function card(string $key, string $hub, int $count, string $label, string $why,
                                 string $cta, string $href, string $tone): ?array
    {
        if ($count < 1) return null;
        return compact('key', 'hub', 'count', 'label', 'why', 'cta', 'href', 'tone');
    }

    /** @var array<string,int>|null */
    private static ?array $triage = null;

    /**
     * Payment triage's own buckets — one definition of "owed" and "not asked about".
     * Thirty days, the triage screen's own default window.
     *
     * @return array<string,int>
     */
    private static function triage(): array
    {
        if (self::$triage !== null) return self::$triage;
        $b = \AfricaGates\Services\PaymentTriage::buckets(30);
        return self::$triage = [
            'refund_owed' => (int) ($b['counts']['refund_owed'] ?? 0),
            'recoverable' => count(\AfricaGates\Services\PaymentTriage::recoverableFrom($b['buckets'])),
        ];
    }

    /**
     * The first cycle in judging with a category no nominee of which meets the judge
     * quorum — ResultRelease's own `blocked`, so Home and the release screen agree.
     *
     * @return array{cycle:int, edition:string, count:int, names:list<string>}|null
     */
    private static function shortOfQuorum(): ?array
    {
        $cycles = DB::table('gates_award_cycles as c')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->where('c.status', '!=', 'archived')
            ->orderByDesc('c.id')->limit(12)
            ->get(['c.*', 'p.title as programme']);
        foreach ($cycles as $c) {
            $phase = \AfricaGates\Services\CyclePolicy::stateFor($c)['phase'] ?? null;
            if ($phase !== 'judging') continue;
            $names = [];
            foreach (\AfricaGates\Services\ResultRelease::forCycle((int) $c->id) as $cat) {
                if (($cat['blocked'] ?? null) === null) continue;
                $cc = $cat['category'] ?? null;
                $title = is_object($cc) ? (string) ($cc->title ?? '') : (string) (is_array($cc) ? ($cc['title'] ?? '') : '');
                $names[] = $title !== '' ? $title : 'A category';
            }
            if ($names !== []) {
                $edition = trim((string) ($c->programme ?? 'Award') . ' ' . (string) ($c->edition_label ?: $c->year));
                return ['cycle' => (int) $c->id, 'edition' => $edition, 'count' => count($names), 'names' => $names];
            }
        }
        return null;
    }

    // ══ the shortcut tiles ════════════════════════════════════════════════════

    /**
     * README §3's tiles, filtered by what this role can open. Hosts is not built (owner,
     * 4 Oct 2026) and is omitted, not drawn disabled.
     *
     * @return list<array{label:string, icon:string, href:string, badge:?int, tone:string}>
     */
    public static function shortcuts(string $role): array
    {
        $c = self::counts($role);
        $tiles = [
            ['Review',       'moderation', '/admin/nominations/review', $c['review'],      'ink'],
            ['Payments',     'payments',   '/admin/payments',           $c['payments'],    'danger'],
            ['Results',      'programmes', '/admin/result-release',     null,              'ink'],
            ['Alerts',       'bell',       '/admin/alerts',             $c['alerts_high'], 'danger'],
            ['Integrations', 'webhooks',   '/admin/settings/providers', null,              'ink'],
            ['Audit log',    'audit',      '/admin/audit',              null,              'ink'],
            ['People',       'admins',     '/admin/admins',             null,              'ink'],
        ];
        $out = [];
        foreach ($tiles as [$label, $icon, $href, $badge, $tone]) {
            if (!Permissions::canOpen($role, $href)) continue;
            $out[] = ['label' => $label, 'icon' => $icon, 'href' => $href,
                      'badge' => ($badge !== null && $badge > 0) ? $badge : null, 'tone' => $tone];
        }
        return $out;
    }

    // ══ the counts the rail prints ════════════════════════════════════════════

    /**
     * Review queue, Payment issues and Alerts carry a live count in the rail (the HTML's
     * `viewCount`), and Home's tiles badge from the same numbers. Null for a page this
     * role cannot open, so a count can never leak a figure from behind a gate.
     *
     * @return array{review:?int, payments:?int, alerts:?int, alerts_high:?int}
     */
    public static function counts(string $role): array
    {
        if (self::$counts !== null && (self::$counts['_role'] ?? null) === $role) {
            $c = self::$counts; unset($c['_role']); return $c;
        }
        $safe = static function (callable $f): ?int { try { return (int) $f(); } catch (\Throwable) { return null; } };

        $c = [
            'review'      => Permissions::canOpen($role, '/admin/nominations/review')
                ? $safe(static fn () => DB::table('gates_nominations')->where('status', 'pending')->count()) : null,
            'payments'    => Permissions::canOpen($role, '/admin/payments')
                ? $safe(static function () { $t = self::triage(); return $t['refund_owed'] + $t['recoverable']; }) : null,
            'alerts'      => Permissions::canOpen($role, '/admin/alerts') ? count(ConsoleAlerts::open()) : null,
            'alerts_high' => Permissions::canOpen($role, '/admin/alerts') ? count(ConsoleAlerts::high()) : null,
        ];
        self::$counts = $c + ['_role' => $role];
        return $c;
    }

    // ══ the quiet numbers ═════════════════════════════════════════════════════

    /**
     * README §3's six cells, from {@see AttentionBoard::pulse()} — one definition of each
     * figure — under the HTML's labels. Notes are the HTML's where they are true of the
     * data; "of N this edition" became "of N ever" because a vote count spans editions
     * and this platform can run several at once (recorded as a deviation).
     *
     * @return list<array{k:string, v:string, n:string}>
     */
    public static function quiet(): array
    {
        $pulse = [];
        foreach (AttentionBoard::pulse() as $p) $pulse[$p['k']] = $p;
        $num = static fn (string $k): string => number_format((int) ($pulse[$k]['v'] ?? 0));

        $totalNominations = 0;
        try { $totalNominations = (int) DB::table('gates_nominations')->count(); } catch (\Throwable) {}

        return [
            ['k' => 'Votes in 24 hours',     'v' => $num('Votes in 24 hours'),     'n' => (string) ($pulse['Votes in 24 hours']['n'] ?? '')],
            ['k' => 'Nominations this week', 'v' => $num('Nominations this week'), 'n' => number_format($totalNominations) . ' in total'],
            ['k' => 'Nominees on the site',  'v' => $num('Nominees on the public site'), 'n' => 'able to receive votes'],
            ['k' => 'With the judges',       'v' => $num('With the judges'),       'n' => 'questionnaires in dossiers'],
            ['k' => 'Interviews published',  'v' => $num('Interviews published'),  'n' => 'transcripts a panel can read'],
            ['k' => 'Judges',                'v' => $num('Judges'),                'n' => 'active on a panel'],
        ];
    }

    /** For tests. */
    public static function resetAll(): void
    {
        self::$counts = null;
        self::$triage = null;
    }
}
