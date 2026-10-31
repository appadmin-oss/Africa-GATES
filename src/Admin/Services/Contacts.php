<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\Mail\Suppression;
use AfricaGates\Services\Newsletter\NewsletterAudience;
use AfricaGates\Support\EmailCanon;
use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Everybody who has taken part — voted, given, nominated, held a ticket, bought from the
 * shop, joined, or subscribed — as ONE row per person (AdminConsole v2, "Contacts").
 *
 * ── One person is one inbox ──────────────────────────────────────────────────
 * Every source keys on EmailCanon::hash(), the key a vote records as `voter_canon_hash`,
 * so somebody who voted, then gave, then bought a ticket is one row with three things
 * beside their name, and "ada.obi@gmail.com" and "adaobi+gala@gmail.com" are one person.
 * A vote cast before that key existed kept only a fingerprint of the address as typed,
 * so that voter cannot be joined to anything: they are COUNTED, never guessed at.
 *
 * ── What is not here, and why (the CRM push's rules, held the same way) ──────
 *   · Nominees. Some are children, and a nominee did not give us their address to be
 *     contacted. A nominee who also voted or gave appears for THAT, never for being
 *     nominated; and a nominator's row does not say whom they nominated.
 *   · Who anybody voted for. A voter row names the programme, never the nominee.
 *   · Fraud-flagged votes, which VoterReach counts as nobody.
 *   · Addresses at a reserved domain (SendPolicy::reserved()), which keeps the sandbox's
 *     `@demo.invalid` people off a real list.
 *   · A stand-call "email me when it opens" row, which is a request about one event and
 *     not a subscription (NewsletterAudience::isSubscription()).
 *
 * "May we email them" is NewsletterAudience::stateOf()'s answer and nobody else's, so this
 * page cannot call somebody reachable whom the newsletter's own sender would not mail.
 * "Given" is money that STAYED given: confirmed and not refunded — `status` does not go
 * backwards on a refund (CLAUDE.md).
 *
 * Deliberately NOT cached: the cache table is plain text, and a cached copy of this list
 * would be a second store of every address and phone number on the platform, outside
 * every table the data registry and the erasure path know about.
 */
final class Contacts
{
    /** What a person did, in the order the "Took part in" column lists it. */
    public const KINDS = [
        'voter'      => 'Voted',
        'donor'      => 'Gave',
        'nominator'  => 'Nominated',
        'ticket'     => 'Ticket',
        'buyer'      => 'Shop',
        'member'     => 'Member',
        'subscriber' => 'Newsletter',
    ];

    /** The tabs: the design's four sources always, the rest where they have anybody in them. */
    public const TABS = [
        'voter'      => 'Voters',
        'donor'      => 'Donors',
        'nominator'  => 'Nominators',
        'ticket'     => 'Ticket holders',
        'buyer'      => 'Shop',
        'member'     => 'Members',
        'subscriber' => 'Newsletter',
    ];
    private const ALWAYS = ['voter', 'donor', 'nominator', 'ticket'];

    /** Consent words and their pill colour. `none` = an address, and no newsletter row at all. */
    public const CONSENT = [
        'subscribed'   => ['Subscribed', 'green'],
        'unconfirmed'  => ['Not confirmed', 'gold'],
        'none'         => ['Not subscribed', 'grey'],
        'unsubscribed' => ['Opted out', 'grey'],
        'bounced'      => ['Bounced', 'red'],
        'complained'   => ['Complained', 'red'],
        'no_address'   => ['No address', 'grey'],
    ];

    /**
     * @return array{people:list<array<string,mixed>>, anonymous_voters:int}
     */
    public static function build(): array
    {
        $p = [];
        $add = static function (?string $email, string $kind, array $f) use (&$p): void {
            $email = trim((string) $email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || SendPolicy::reserved($email)) return;
            $key = EmailCanon::hash($email);
            if ($key === null) return;
            self::fold($p, $key, $kind, $f + ['email' => $email]);
        };

        self::each('gates_donations', ['donor_name', 'donor_email', 'donor_phone', 'amount_naira', 'status', 'refunded_at', 'created_at'],
            static function (object $r) use ($add): void {
                if ((string) $r->status !== 'confirmed' || !empty($r->refunded_at ?? null)) return;
                $add($r->donor_email, 'donor', ['name' => $r->donor_name, 'phone' => $r->donor_phone ?? null,
                    'given' => (int) $r->amount_naira, 'at' => $r->created_at]);
            });
        self::each('gates_nominations', ['nominator_name', 'nominator_email', 'nominator_phone', 'created_at'],
            static function (object $r) use ($add): void {
                $add($r->nominator_email, 'nominator', ['name' => $r->nominator_name, 'phone' => $r->nominator_phone ?? null, 'at' => $r->created_at]);
            });
        self::each('gates_event_registrations', ['name', 'email', 'phone', 'status', 'cancelled_at', 'created_at'],
            static function (object $r) use ($add): void {
                if ((string) $r->status !== 'confirmed' || !empty($r->cancelled_at ?? null)) return;
                $add($r->email, 'ticket', ['name' => $r->name, 'phone' => $r->phone ?? null, 'at' => $r->created_at]);
            });
        self::each('gates_orders', ['name', 'email', 'phone', 'status', 'refunded_at', 'created_at'],
            static function (object $r) use ($add): void {
                if ((string) $r->status !== 'paid' || !empty($r->refunded_at ?? null)) return;
                $add($r->email, 'buyer', ['name' => $r->name, 'phone' => $r->phone ?? null, 'at' => $r->created_at]);
            });
        self::each('gates_users', ['name', 'email', 'phone', 'status', 'created_at'],
            static function (object $r) use ($add): void {
                if ((string) ($r->status ?? 'active') !== 'active') return;
                $add($r->email, 'member', ['name' => $r->name, 'phone' => $r->phone ?? null, 'at' => $r->created_at]);
            });
        self::each('gates_newsletter', ['email', 'source', 'subscribed_at'],
            static function (object $r) use ($add): void {
                if (!NewsletterAudience::isSubscription($r->source ?? null)) return;
                $add($r->email, 'subscriber', ['at' => $r->subscribed_at]);
            });

        // Voters join by the inbox key their vote recorded: a programme, never a nominee.
        // A vote carries no address, so a person who has only ever voted has none here.
        $anonymous = 0;
        try {
            if (SchemaHas::column('gates_votes', 'voter_canon_hash')) {
                $rows = DB::table('gates_votes as v')
                    ->leftJoin('gates_award_categories as c', 'c.id', '=', 'v.category_id')
                    ->leftJoin('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
                    ->leftJoin('gates_award_programmes as pr', 'pr.id', '=', 'y.programme_id')
                    ->whereNotNull('v.voter_canon_hash')->where('v.fraud_flag', 0)
                    // The sandbox lives in an inactive programme; a vote there is a rehearsal.
                    ->where(static fn ($q) => $q->whereNull('pr.is_active')->orWhere('pr.is_active', 1))
                    ->groupBy('v.voter_canon_hash', 'pr.title')
                    ->get([DB::raw('v.voter_canon_hash as k'), DB::raw('pr.title as programme'), DB::raw('COUNT(*) as n'),
                           DB::raw('MAX(v.voted_at) as at'), DB::raw('MAX(v.voter_name) as name'), DB::raw('MAX(v.voter_phone) as phone')]);
                foreach ($rows as $r) {
                    self::fold($p, (string) $r->k, 'voter', ['name' => $r->name, 'phone' => $r->phone, 'at' => $r->at,
                        'votes' => (int) $r->n, 'programme' => (string) ($r->programme ?? '')]);
                }
                $anonymous = (int) DB::table('gates_votes')->whereNull('voter_canon_hash')
                    ->where('vote_type', 'standard')->where('fraud_flag', 0)
                    ->distinct()->count('voter_email_hash');
            }
        } catch (\Throwable $e) {
            error_log('[contacts] votes: ' . $e->getMessage());
        }

        $opt = []; $sup = []; $subs = [];
        try { $opt = EmailOptOut::suppressedHashes(); } catch (\Throwable) {}
        try { $sup = Suppression::hashes(); } catch (\Throwable) {}
        try {
            foreach (DB::table('gates_newsletter')->get(['email_hash', 'source', 'confirmed_at', 'unsubscribed_at']) as $r) {
                if (NewsletterAudience::isSubscription($r->source ?? null)) $subs[(string) $r->email_hash] = $r;
            }
        } catch (\Throwable) {}

        // A number many people typed is a placeholder ("08000000000"), not anybody's phone:
        // the cap VoterPeople uses to refuse joining strangers on it. Printed beside a dozen
        // names it reads as a dozen reachable people.
        $holders = [];
        foreach ($p as $key => $c) {
            foreach (array_unique(array_filter(array_map(static fn ($raw) => Phone::normalize($raw, 'NG'), $c['phones']))) as $e) $holders[$e][$key] = true;
        }

        $people = [];
        foreach ($p as $key => $c) {
            $email = $c['email'];
            $consent = 'no_address';
            if ($email !== null) {
                $h = EmailOptOut::hash($email);
                $row = $subs[$h] ?? (object) ['email_hash' => $h, 'confirmed_at' => null, 'unsubscribed_at' => null];
                $consent = NewsletterAudience::stateOf($row, $opt, $sup);
                // No newsletter row and no refusal on record: they never subscribed.
                if ($consent === 'unconfirmed' && !isset($subs[$h])) $consent = 'none';
            }
            $phone = null;
            foreach ($c['phones'] as $raw) {
                $e = Phone::normalize($raw, 'NG');
                if ($e !== null && count($holders[$e] ?? []) <= VoterPeople::PHONE_CAP) { $phone = $e; break; }
            }
            $kinds = array_values(array_filter(array_keys(self::KINDS), static fn (string $k): bool => isset($c['kinds'][$k])));
            $people[] = [
                'key'        => $key,
                'name'       => $c['name'] !== '' ? $c['name'] : ($email !== null ? (string) strstr($email, '@', true) : 'A voter'),
                'email'      => $email,
                'phone'      => $phone,
                'kinds'      => $kinds,
                'given'      => $c['given'],
                'votes'      => $c['votes'],
                'programmes' => array_keys($c['programmes']),
                'took'       => self::took($c),
                'last'       => $c['last'],
                'first'      => $c['first'],
                'consent'    => $consent,
            ];
        }
        usort($people, static fn (array $a, array $b): int => strcmp((string) $b['last'], (string) $a['last']) ?: strcmp($a['key'], $b['key']));
        return ['people' => $people, 'anonymous_voters' => $anonymous];
    }

    /**
     * One screen of it: a tab, a search, a page, and the figures over the whole list.
     *
     * @param array{people:list<array<string,mixed>>, anonymous_voters:int} $built
     * @return array<string,mixed>
     */
    public static function page(array $built, string $tab, string $q, int $page, int $per = 50): array
    {
        $people = $built['people'];
        $tabs = ['all' => ['label' => 'All', 'n' => count($people)]];
        foreach (self::TABS as $k => $label) {
            $n = 0;
            foreach ($people as $c) if (in_array($k, $c['kinds'], true)) $n++;
            if ($n > 0 || in_array($k, self::ALWAYS, true) || $tab === $k) $tabs[$k] = ['label' => $label, 'n' => $n];
        }
        if (!isset($tabs[$tab])) $tab = 'all';

        $rows = $tab === 'all' ? $people
            : array_values(array_filter($people, static fn (array $c): bool => in_array($tab, $c['kinds'], true)));
        $q = trim($q);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, static fn (array $c): bool => str_contains(
                mb_strtolower($c['name'] . ' ' . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? '') . ' ' . implode(' ', $c['programmes'])), $needle)));
        }

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(1, min($page, $pages));

        $everyone = count($people);
        $subscribed = 0; $phones = 0; $new = 0;
        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        foreach ($people as $c) {
            if ($c['first'] !== '' && $c['first'] >= $since) $new++;
            if ($c['consent'] === 'subscribed') $subscribed++;
            if ($c['phone'] !== null) $phones++;
        }
        $pct = static fn (int $n): int => $everyone > 0 ? (int) round($n * 100 / $everyone) : 0;

        return [
            'tab' => $tab, 'tabs' => $tabs, 'q' => $q,
            'rows' => array_slice($rows, ($page - 1) * $per, $per),
            'total' => $total, 'page' => $page, 'pages' => $pages,
            'from' => $total > 0 ? ($page - 1) * $per + 1 : 0, 'to' => min($total, $page * $per),
            'stats' => [
                'people'         => $everyone,
                'new_30d'        => $new,
                'subscribed'     => $subscribed,
                'subscribed_pct' => $pct($subscribed),
                'phones'         => $phones,
                'phones_pct'     => $pct($phones),
                'anonymous'      => (int) $built['anonymous_voters'],
            ],
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * "Took part in", as a line: "4 votes · Teachers Prize", "Gave twice", "Ticket".
     * A voter's programmes are named; whom anybody voted for or nominated never is.
     *
     * @param array<string,mixed> $c
     * @return list<string>
     */
    private static function took(array $c): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $n = (int) ($c['kinds'][$k] ?? 0);
            if ($n === 0) continue;
            $out[] = match ($k) {
                'voter'      => $c['votes'] . ' vote' . ($c['votes'] === 1 ? '' : 's')
                                . ($c['programmes'] ? ' · ' . implode(', ', array_slice(array_keys($c['programmes']), 0, 2)) : ''),
                'donor'      => 'Gave' . self::times($n),
                'nominator'  => 'Nominated' . self::times($n),
                'ticket'     => $n === 1 ? 'Ticket' : $n . ' tickets',
                'buyer'      => $n === 1 ? 'Shop order' : $n . ' shop orders',
                'member'     => 'Member',
                'subscriber' => 'Newsletter',
            };
        }
        return $out;
    }

    private static function times(int $n): string
    {
        return match (true) { $n <= 1 => '', $n === 2 => ' twice', default => ' ' . $n . ' times' };
    }

    /** Read a table in chunks — only the columns this install has — calling $fn per row. */
    private static function each(string $table, array $cols, callable $fn): void
    {
        if (!SchemaHas::table($table)) return;
        $cols = array_values(array_filter($cols, static fn (string $c): bool => SchemaHas::column($table, $c)));
        try {
            DB::table($table)->select(array_merge(['id'], $cols))->orderBy('id')->chunk(2000, static function ($rows) use ($fn): void {
                foreach ($rows as $r) $fn($r);
            });
        } catch (\Throwable $e) {
            error_log('[contacts] ' . $table . ': ' . $e->getMessage());
        }
    }

    /** @param array<string,array<string,mixed>> $p */
    private static function fold(array &$p, string $key, string $kind, array $f): void
    {
        $c = $p[$key] ?? ['name' => '', 'email' => null, 'phones' => [], 'kinds' => [], 'given' => 0, 'votes' => 0,
                          'programmes' => [], 'last' => '', 'first' => ''];
        $c['kinds'][$kind] = ($c['kinds'][$kind] ?? 0) + 1;
        // A member's own account name beats whatever was typed into a checkout.
        $name = trim((string) ($f['name'] ?? ''));
        if ($name !== '' && ($c['name'] === '' || $kind === 'member')) $c['name'] = mb_substr($name, 0, 120);
        if (!empty($f['email']) && $c['email'] === null) $c['email'] = (string) $f['email'];
        if (!empty($f['phone'])) $c['phones'][] = (string) $f['phone'];
        $c['given'] += (int) ($f['given'] ?? 0);
        $c['votes'] += (int) ($f['votes'] ?? 0);
        if (!empty($f['programme'])) $c['programmes'][(string) $f['programme']] = true;
        $at = (string) ($f['at'] ?? '');
        if ($at > $c['last']) $c['last'] = $at;
        if ($at !== '' && ($c['first'] === '' || $at < $c['first'])) $c['first'] = $at;
        $p[$key] = $c;
    }
}
