<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The phone Menu's destinations, and the four "most used" tiles ranked from them.
 *
 * Rebuilt with the Menu on 4 Oct 2026 for the owner's request (GAPS §8e; research and every
 * number below in docs/handoff/MENU-SHEET.md §2). The four squares under the profile card
 * were the Participate tiles for everybody; they are now the visitor's own most-used menu
 * destinations — Facebook's "Your shortcuts" — and the Participate four until there is
 * enough history to say otherwise.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE CATALOGUE, SO A SHORTCUT CANNOT POINT ANYWHERE THE MENU DOES NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every destination the Menu draws — tile, Explore row, Help row, Notifications — is a row of
 * {@see DESTINATIONS}, and the template draws from here. A shortcut is therefore always a
 * destination somebody could have reached by scrolling, and `who` is the one answer to "may
 * this visitor open it": `all`, `member` (behind `UserAuthMiddleware`), or `guest`
 * (`/account/register` sends a signed-in member to `/account`, so offering it to one is a
 * tile that goes somewhere other than where it says). `MenuSheetTest` boots the real router
 * and requests each destination as each audience, so the claim is held against the routes
 * that serve rather than against this comment.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * FRECENCY, AS ONE DECAYED COUNTER PER DESTINATION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Firefox's frecency is an exponential decay with a half-life of a month; storing every
 * visit to re-sum it would be a table and a growing one. A decayed counter is the same
 * curve in one number: on each open `s ← s·2^(−Δt/H) + 1`, and at read time the score is
 * `s·2^(−Δt/H)`. Frequency raises it, absence halves it every {@see HALF_LIFE_DAYS} days.
 *
 * ── THE TIE-BREAK IS PART OF THE RULE ──────────────────────────────────────
 * Score, then the most recent open, then the catalogue's own order. Without the last two a
 * fresh visitor's four equal scores would come out in whatever order PHP's sort chose, and
 * the tile under somebody's thumb would move between page loads for no reason.
 *
 * ── A DEFAULT PUSHED OUT IS NEVER LOST ─────────────────────────────────────
 * Vote, Awards and Events appear nowhere else in the Menu. A shortcut that displaces one
 * would make it unreachable from the Menu precisely because it had not been used, so
 * {@see displaced()} lists the pushed-out defaults at the head of Explore.
 *
 * ── TWO IMPLEMENTATIONS, ONE SET OF NUMBERS ────────────────────────────────
 * A guest's history is in their browser (only when Preferences allows — CookiePrefs), so a
 * guest's tiles are ranked by `menu-sheet.js`. That script holds NO number of its own: it
 * reads {@see params()} off the sheet, and `MenuSheetTest` runs it under Node against this
 * class on sampled histories and fails on any disagreement.
 */
final class MenuShortcuts
{
    public const SLOTS          = 4;
    public const HALF_LIFE_DAYS = 14;
    /** A destination becomes a shortcut at this decayed score — about two recent opens. */
    public const QUALIFY        = 1.5;
    /** Recorded opens in all before anything is personalised. */
    public const WARMUP         = 5;

    /** The Participate four, in their own order: the tiles until there is history. */
    public const DEFAULTS = ['nominate', 'vote', 'awards', 'events'];

    /** REFERENCE §7.4's Explore list, in order (Blog, not the DC's Leaderboard). */
    public const EXPLORE = ['discover', 'pulse', 'giving', 'shop', 'legacy', 'blog', 'register'];

    public const HELP = ['help', 'integrity', 'status'];

    public const ALL = 'all';
    public const MEMBER = 'member';
    public const GUEST = 'guest';

    /**
     * Every destination, in the Menu's order — which is the last tie-break.
     *
     * `tone` is a `.ag-tone--*` class (chrome.css): a tile never chooses a colour.
     *
     * @var array<string, array{href:string,label:string,tone:string,d:string,who:string}>
     */
    public const DESTINATIONS = [
        'nominate'      => ['href' => '/nominate', 'label' => 'Nominate', 'tone' => 'grow', 'who' => self::ALL,
                            'd' => 'M12 5v14M5 12h14'],
        'vote'          => ['href' => '/vote', 'label' => 'Vote', 'tone' => 'act', 'who' => self::ALL,
                            'd' => 'M4 12.5 9 17.5 20 6.5'],
        'awards'        => ['href' => '/awards', 'label' => 'Awards', 'tone' => 'honour', 'who' => self::ALL,
                            'd' => 'M5 16 3 6l5 4 4-6 4 6 5-4-2 10z'],
        'events'        => ['href' => '/events', 'label' => 'Events', 'tone' => 'act', 'who' => self::ALL,
                            'd' => 'M4 5h16v15H4zM4 10h16M9 3v4M15 3v4'],
        'discover'      => ['href' => '/discover', 'label' => 'Discover', 'tone' => 'plain', 'who' => self::ALL,
                            'd' => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14ZM20 20l-3.6-3.6'],
        'pulse'         => ['href' => '/pulse', 'label' => 'Pulse', 'tone' => 'give', 'who' => self::ALL,
                            'd' => 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2ZM10 8.5l5 3.5-5 3.5z'],
        'giving'        => ['href' => '/giving', 'label' => 'Giving', 'tone' => 'give', 'who' => self::ALL,
                            'd' => 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z'],
        'shop'          => ['href' => '/shop', 'label' => 'Shop', 'tone' => 'act', 'who' => self::ALL,
                            'd' => 'M6 7h12l-1 13H7zM9 7a3 3 0 0 1 6 0'],
        'legacy'        => ['href' => '/legacy', 'label' => 'Legacy Vault', 'tone' => 'honour', 'who' => self::ALL,
                            'd' => 'M4 8h16v12H4zM2 4h20v4H2z'],
        'blog'          => ['href' => '/blog', 'label' => 'Blog', 'tone' => 'plain', 'who' => self::ALL,
                            'd' => 'M4 5h16M4 10h16M4 15h10'],
        'register'      => ['href' => '/account/register', 'label' => 'Register a profile', 'tone' => 'honour', 'who' => self::GUEST,
                            'd' => 'M16 11a4 4 0 1 0-8 0M3 21a9 9 0 0 1 18 0'],
        'notifications' => ['href' => '/account/notifications', 'label' => 'Notifications', 'tone' => 'set', 'who' => self::MEMBER,
                            'd' => 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4'],
        'help'          => ['href' => '/help', 'label' => 'Help Centre', 'tone' => 'set', 'who' => self::ALL,
                            'd' => 'M12 17h.01M9.1 9a3 3 0 1 1 4 2.8c-.7.3-1.1 1-1.1 1.7V14'],
        'integrity'     => ['href' => '/integrity', 'label' => 'Integrity Center', 'tone' => 'set', 'who' => self::ALL,
                            'd' => 'M12 2.4l7.6 2.7v5.8c0 4.7-3.1 8.3-7.6 10-4.5-1.7-7.6-5.3-7.6-10V5.1z'],
        'status'        => ['href' => '/status', 'label' => 'Status', 'tone' => 'set', 'who' => self::ALL,
                            'd' => 'M3 12h4l3-7 4 14 3-7h4'],
    ];

    private const TABLE  = 'gates_users';
    private const COLUMN = 'menu_use_json';

    /** May this visitor open this destination? The one answer; unknown keys are no. */
    public static function opens(string $key, bool $member): bool
    {
        $who = self::DESTINATIONS[$key]['who'] ?? null;

        return $who === self::ALL
            || ($who === self::MEMBER && $member)
            || ($who === self::GUEST && !$member);
    }

    /**
     * A history document, made safe: known keys only, finite non-negative numbers, no open
     * in the future. Applied on the way IN and on the way OUT — a stored document survives
     * the code that wrote it, and a guest's arrives from a browser.
     *
     * @return array{n:int, d:array<string, array{0:float,1:int}>}
     */
    public static function normalise(mixed $doc, int $now): array
    {
        $out = ['n' => 0, 'd' => []];
        if (!is_array($doc)) return $out;

        $n = $doc['n'] ?? 0;
        $out['n'] = is_numeric($n) ? max(0, min(1_000_000, (int) $n)) : 0;

        $d = $doc['d'] ?? [];
        if (!is_array($d)) return $out;
        foreach (self::DESTINATIONS as $key => $_) {
            $row = $d[$key] ?? null;
            if (!is_array($row) || !is_numeric($row[0] ?? null) || !is_numeric($row[1] ?? null)) continue;
            $s = (float) $row[0];
            $t = (int) $row[1];
            if (!is_finite($s) || $s <= 0 || $t <= 0) continue;
            // An open "in the future" is a skewed clock, and would decay UPWARDS — clamp it.
            $out['d'][$key] = [round(min($s, 10_000.0), 4), min($t, $now)];
        }

        return $out;
    }

    /** A stored score as of `$now`. */
    public static function decayed(float $score, int $last, int $now): float
    {
        $age = max(0, $now - $last);

        return $score * (2 ** (-$age / (self::HALF_LIFE_DAYS * 86400)));
    }

    /**
     * The history with one more open of `$key`. Unknown keys change nothing.
     *
     * @param array{n:int, d:array<string, array{0:float,1:int}>} $h
     * @return array{n:int, d:array<string, array{0:float,1:int}>}
     */
    public static function record(array $h, string $key, int $now): array
    {
        $h = self::normalise($h, $now);
        if (!isset(self::DESTINATIONS[$key])) return $h;

        [$s, $t] = $h['d'][$key] ?? [0.0, $now];
        $h['d'][$key] = [round(min(self::decayed($s, $t, $now) + 1, 10_000.0), 4), $now];
        $h['n']++;

        return $h;
    }

    /**
     * The four tile keys for this visitor, and whether any of them was earned.
     *
     * @param array{n:int, d:array<string, array{0:float,1:int}>} $h
     * @return array{keys: list<string>, personal: bool}
     */
    public static function rank(array $h, bool $member, int $now): array
    {
        $h = self::normalise($h, $now);
        $order = array_flip(array_keys(self::DESTINATIONS));

        $earned = [];
        if ($h['n'] >= self::WARMUP) {
            foreach ($h['d'] as $key => [$s, $t]) {
                if (!self::opens($key, $member)) continue;
                $score = self::decayed($s, $t, $now);
                if ($score >= self::QUALIFY) $earned[] = [$key, $score, $t];
            }
            usort($earned, static fn (array $a, array $b): int =>
                [$b[1], $b[2], $order[$a[0]]] <=> [$a[1], $a[2], $order[$b[0]]]);
        }

        $keys = array_slice(array_map(static fn (array $e): string => $e[0], $earned), 0, self::SLOTS);
        $personal = $keys !== [];
        foreach (self::DEFAULTS as $d) {
            if (count($keys) >= self::SLOTS) break;
            if (!in_array($d, $keys, true) && self::opens($d, $member)) $keys[] = $d;
        }

        return ['keys' => $keys, 'personal' => $personal];
    }

    /**
     * The Participate defaults that are not among the tiles — listed at the head of Explore so
     * that nothing becomes unreachable from the Menu for not having been used.
     *
     * @param list<string> $tiles
     * @return list<string>
     */
    public static function displaced(array $tiles, bool $member): array
    {
        return array_values(array_filter(self::DEFAULTS,
            static fn (string $k): bool => !in_array($k, $tiles, true) && self::opens($k, $member)));
    }

    /**
     * The numbers `menu-sheet.js` ranks a guest's history with. It types none of its own.
     *
     * @return array<string,mixed>
     */
    public static function params(bool $member): array
    {
        return [
            'half'     => self::HALF_LIFE_DAYS * 86400,
            'qualify'  => self::QUALIFY,
            'warmup'   => self::WARMUP,
            'slots'    => self::SLOTS,
            'defaults' => self::DEFAULTS,
            // Only what this visitor may open, in catalogue order (the last tie-break).
            'order'    => array_values(array_filter(array_keys(self::DESTINATIONS),
                static fn (string $k): bool => self::opens($k, $member))),
        ];
    }

    /** A member's stored history; empty when there is none, or no column yet. */
    public static function forUser(int $userId, ?int $now = null): array
    {
        $now ??= time();
        if ($userId <= 0 || !SchemaHas::column(self::TABLE, self::COLUMN)) return self::normalise(null, $now);
        try {
            $raw = DB::table(self::TABLE)->where('id', $userId)->value(self::COLUMN);
        } catch (\Throwable) {
            return self::normalise(null, $now);
        }

        return self::normalise(is_string($raw) && $raw !== '' ? json_decode($raw, true) : null, $now);
    }

    /**
     * Count one open of `$key` for a member. False when it is not theirs to open, or could not
     * be stored — a lost count costs a shortcut, never a page, so nothing here throws.
     *
     * Read, add, write: two beacons racing can lose one count, which moves a score by one
     * open, and a lock for that is a lock on every member's row on every menu tap.
     */
    public static function recordFor(int $userId, string $key, ?int $now = null): bool
    {
        $now ??= time();
        if ($userId <= 0 || !self::opens($key, true) || !SchemaHas::column(self::TABLE, self::COLUMN)) return false;

        $h = self::record(self::forUser($userId, $now), $key, $now);
        try {
            DB::table(self::TABLE)->where('id', $userId)
                ->update([self::COLUMN => (string) json_encode($h)]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Everything the Menu template draws from this class, for the current visitor.
     *
     * @return array<string,mixed>
     */
    public static function menu(bool $member, int $userId = 0, ?int $now = null): array
    {
        $now ??= time();
        $ranked = $member ? self::rank(self::forUser($userId, $now), true, $now)
                          : self::rank(self::normalise(null, $now), false, $now);

        $pick = static function (array $keys) use ($member): array {
            $out = [];
            foreach ($keys as $k) {
                if (self::opens($k, $member)) $out[] = ['key' => $k] + self::DESTINATIONS[$k];
            }
            return $out;
        };

        return [
            'tiles'     => $pick($ranked['keys']),
            'personal'  => $ranked['personal'],
            'displaced' => $pick(self::displaced($ranked['keys'], $member)),
            'explore'   => $pick(self::EXPLORE),
            'settings'  => $pick(['notifications']),
            'help'      => $pick(self::HELP),
            // A guest's tiles are ranked in the browser: every destination they may open, as
            // a tile and as a row the script can clone, and the numbers to rank with.
            'all'       => $member ? [] : $pick(array_keys(self::DESTINATIONS)),
            'params'    => self::params($member),
        ];
    }
}
