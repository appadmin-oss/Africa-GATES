<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * WHAT EVERY PUBLIC PAGE SAYS ABOVE ITS CONTENT — an operator's announcement, and planned
 * work while it is close (owner, 11 Nov 2026: "fix the announcement and scheduled maintenance
 * messages and alerts").
 *
 * ── THE TWO FAULTS IT REPLACED ───────────────────────────────────────────────
 *
 * 1. THE ANNOUNCEMENT BANNER WAS A FORM WITH NOTHING BEHIND IT. Settings has offered
 *    "Announcement banner text", a link and a label since before the public pages were
 *    rebuilt, and `config/container.php` turned them into three Twig globals — which no
 *    template has read since the old layout was destroyed (3 Oct 2026). An operator typing
 *    "Voting closes Friday" saved, was told "Settings saved.", and nobody ever saw it. And
 *    the globals' DEFAULT was a claim nobody typed ("Nominations open — live in …,
 *    building toward 54"), so reconnecting them naively would have put an unchosen sentence
 *    on every page of a site that may not be taking nominations at all. The banner shows
 *    only what an operator wrote, and nothing when they wrote nothing.
 *
 * 2. PLANNED WORK WAS ANNOUNCED WHERE NOBODY LOOKS. {@see PlannedWork} was read by /status
 *    alone. Somebody about to pay at 13:55 for a window that pauses card payments at 14:00
 *    learned about it from the failure. The site-wide notice appears
 *    {@see PlannedWork::SITE_LEAD_HOURS} before the start — a week on every page is a
 *    banner people have stopped reading by the day it matters — and stays until the end.
 *
 * ── HIDING ONE ───────────────────────────────────────────────────────────────
 * A notice can be hidden, and the choice is kept in the SESSION the visitor already has
 * (no new cookie, so nothing for /cookies to declare). It is keyed by the notice's
 * CONTENT, so an announcement that is rewritten, or planned work that moves or begins,
 * shows again: hiding "starts Friday" must not hide "under way now". The control is a plain
 * form that posts, because a dismiss button that needs a script is missing for exactly the
 * people likeliest to have one blocked.
 *
 * ── WHAT IT NEVER DOES ───────────────────────────────────────────────────────
 * Puts an announcement on a FLOW page (a ballot, a checkout): somebody mid-vote is not
 * an audience for news. Planned work does show there — a payment page is exactly where a
 * pause in payments matters. Nor on /status, which states planned work itself.
 */
final class SiteNotice
{
    /** Announcement settings: the three the form always had, and when it stops. */
    public const KEYS = ['text' => 'announce_text', 'url' => 'announce_url', 'cta' => 'announce_cta',
                         'until' => 'announce_until'];

    /** Where hidden notices are remembered, in the visitor's own session. */
    public const SESSION = 'notice_hidden';

    /** Longest announcement shown. A banner is a sentence, not a page. */
    public const MAX_TEXT = 200;

    /**
     * The notices this page draws, most urgent first.
     *
     * @return list<array<string,mixed>>
     */
    public static function forPage(string $path = '/', bool $flow = false): array
    {
        $path = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: '/'), '/');
        if ($path === '/status' || str_starts_with($path, '/status/')) return [];

        $settings = self::settings();
        $hidden   = self::hidden();
        $out = [];

        $p = PlannedWork::current($settings);
        if ($p !== null && $p['close'] && $p['started'] && MaintenanceGate::isStaff()) {
            // Staff walk through a closed site (MaintenanceGate). Without this they would
            // see a normal page and could reasonably conclude the closure had not taken.
            $out[] = ['kind' => 'closed', 'id' => '', 'dismiss' => false, 'when' => self::countdown($p)] + $p;
        } elseif ($p !== null && $p['site']) {
            $id = 'p' . substr(sha1($p['title'] . '|' . $p['from'] . '|' . $p['to'] . '|' . ($p['started'] ? 1 : 0)), 0, 12);
            if (!in_array($id, $hidden, true)) $out[] = ['kind' => 'planned', 'id' => $id, 'dismiss' => true, 'when' => self::countdown($p)] + $p;
        }

        if (!$flow) {
            $a = self::announcement($settings);
            if ($a !== null && !in_array($a['id'], $hidden, true)) $out[] = $a;
        }
        return $out;
    }

    /**
     * How far off it is, in the words a person plans by: "Starts in 20 hours", "Starts in 45
     * minutes", "Ends in 1 h 40 min". Relative, because "Thu 12 Nov, 12:47" asks the reader to
     * work out whether that is tomorrow; the absolute time sits beside it for the planner.
     * Rounded DOWN, so it never promises more time than there is.
     *
     * @param array{from:string, to:string, started:bool} $p
     */
    public static function countdown(array $p, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();
        $at  = Carbon::parse($p['started'] ? $p['to'] : $p['from']);
        $min = max(0, intdiv($at->getTimestamp() - $now->getTimestamp(), 60));
        $lead = $p['started'] ? 'Ends in ' : 'Starts in ';
        if ($min < 1)  return $p['started'] ? 'Ending now' : 'Starting now';
        if ($min < 60) return $lead . $min . ' minute' . ($min === 1 ? '' : 's');
        $h = intdiv($min, 60);
        if ($h < 6 && $min % 60 >= 5) return $lead . $h . ' h ' . ($min % 60) . ' min';
        if ($h < 48) return $lead . $h . ' hour' . ($h === 1 ? '' : 's');
        return $lead . intdiv($h, 24) . ' days';
    }

    /**
     * The operator's announcement, or null — never a default sentence.
     *
     * @param array<string,string>|null $settings
     * @return array{kind:string, id:string, dismiss:bool, text:string, url:?string, cta:string, until:?string}|null
     */
    public static function announcement(?array $settings = null): ?array
    {
        $s    = $settings ?? self::settings();
        $text = trim((string) ($s[self::KEYS['text']] ?? ''));
        if ($text === '') return null;
        $until = trim((string) ($s[self::KEYS['until']] ?? ''));
        if ($until !== '') {
            try {
                // The END decides it, as with planned work: a banner somebody has to remember
                // to take down is a banner still up next month.
                if (Carbon::now()->gte(Carbon::parse($until))) return null;
            } catch (\Throwable) {
                $until = '';
            }
        }
        $url = self::safeUrl((string) ($s[self::KEYS['url']] ?? ''));
        $cta = trim((string) ($s[self::KEYS['cta']] ?? ''));
        // An arrow typed into the label is the old default's styling; the page draws its own.
        $cta = trim((string) preg_replace('/\s*(→|->|&rarr;)\s*$/u', '', $cta));
        $text = mb_substr($text, 0, self::MAX_TEXT);
        return [
            'kind' => 'announce', 'dismiss' => true,
            'id'   => 'a' . substr(sha1($text . '|' . $url . '|' . $cta), 0, 12),
            'text' => $text, 'url' => $url,
            'cta'  => $url === null ? '' : ($cta !== '' ? mb_substr($cta, 0, 40) : 'Find out more'),
            'until' => $until !== '' ? $until : null,
        ];
    }

    /**
     * A link an operator typed, or null when it is not one this page will print: a path on
     * this site, or an https address. Never `javascript:`, never protocol-relative `//`,
     * which reads as a path and goes to another host.
     */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) {
            return preg_match('~^/[^\s<>"\']*$~', $url) ? $url : null;
        }
        if (preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}(?:[/?#][^\s<>"\']*)?$~i', $url)) return $url;
        return null;
    }

    /** Remember that this visitor hid a notice. Kept short: an id is twelve characters. */
    public static function hide(string $id): void
    {
        if (!preg_match('/^[ap][a-f0-9]{12}$/', $id) || session_status() === PHP_SESSION_DISABLED) return;
        $list = self::hidden();
        if (!in_array($id, $list, true)) $list[] = $id;
        $_SESSION[self::SESSION] = array_slice($list, -20);
    }

    /** @return list<string> */
    private static function hidden(): array
    {
        $v = $_SESSION[self::SESSION] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /**
     * One query, asked once per page by the layout. Not memoised: a long-running process (the
     * test suite is one) would otherwise go on drawing the first page's banner.
     *
     * @return array<string,string>
     */
    private static function settings(): array
    {
        try {
            return DB::table('gates_settings')
                ->whereIn('key_name', array_merge(array_values(self::KEYS), array_values(PlannedWork::KEYS)))
                ->pluck('value', 'key_name')->map(fn ($x) => (string) $x)->all();
        } catch (\Throwable) {
            // A settings table that cannot be read costs the banner, never the page.
            return [];
        }
    }
}
