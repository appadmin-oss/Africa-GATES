<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A member's seasonal greeting (HOLIDAY-THEMES, handoff 5 Oct 2026).
 *
 * One banner at the top of a member's own pages and a 3px line under the bar, on the days an
 * operator has put in `gates_holidays`. Nothing else on the page changes: no recoloured
 * buttons, no type, no animation. Never on a public page, the admin, the judges' console,
 * checkout, a ballot or the door — {@see surfaceAllows()} is the one answer to "may this page
 * carry it", and it is a list of the pages that MAY, so a new page is out until somebody
 * decides otherwise.
 *
 * ── THE DATES ARE TYPED, NEVER COMPUTED ─────────────────────────────────────
 *
 * Eid follows a sighting and is declared days ahead; Easter moves; a national day can be
 * moved by a government. So every window is a row an operator enters each year
 * (`/admin/campaigns/holidays`), and a theme with no row simply does not appear. What the
 * greeting SAYS is computed where it is a fact: Nigeria's age is the year minus 1960, and the
 * teachers' line names the award the row points at, only while that award is taking
 * nominations.
 *
 * ── WHO IS IN WHICH COUNTRY ─────────────────────────────────────────────────
 *
 * Members store no country. A Nigeria-only greeting goes to a member whose phone number is
 * Nigerian, or — with no phone — whose request the CDN placed in Nigeria. Neither known: only
 * the greetings meant for everyone. A wrong national-day greeting is worse than none.
 */
final class HolidayTheme
{
    /**
     * slug => [name, tone, pattern, glyph, greeting, line, action, href, scope].
     * The DC's `TH`, exactly. `scope` is the default country for a new row ('' = everyone).
     */
    public const THEMES = [
        'independence-ng'  => ['Independence Day', 'green', 'stripes', 'flag', 'Happy Independence Day, %first%',
                               'Celebrating %years% years of Nigeria, and the people who keep it moving.', 'Nominate someone', '/nominate', 'NG'],
        'teachers'         => ['World Teachers’ Day', 'gold', 'ruled', 'book', 'Happy Teachers’ Day, %first%',
                               'Someone taught you to aim higher.', 'Nominate a teacher', '/nominate', ''],
        'christmas'        => ['Christmas', 'green', 'snow', 'tree', 'Merry Christmas, %first%',
                               'Wishing you rest, good food and the people you love.', '', '', ''],
        'new-year'         => ['New Year', 'gold', 'confetti', 'spark', 'Happy New Year, %first%',
                               'Here’s to the people you’ll recognise this year.', 'See what’s open', '/awards', ''],
        'eid'              => ['Eid', 'info', 'lattice', 'moon', 'Eid Mubarak, %first%',
                               'Wishing you and your family peace and joy.', '', '', ''],
        'easter'           => ['Easter', 'gold', 'sun', 'sunrise', 'Happy Easter, %first%',
                               'Wishing you a peaceful, joyful weekend.', '', '', ''],
        'africa-day'       => ['Africa Day', 'gold', 'africa', 'globe', 'Happy Africa Day, %first%',
                               '54 countries, one record of who’s making a difference.', 'Explore the awards', '/awards', ''],
        'democracy-ng'     => ['Democracy Day', 'green', 'stripes', 'flag', 'Happy Democracy Day, %first%',
                               'Every vote counts here too.', 'See open votes', '/vote', 'NG'],
        'womens-day'       => ['International Women’s Day', 'live', 'petals', 'flower', 'Happy Women’s Day, %first%',
                               'Know a woman who leads? Put her name forward.', 'Nominate her', '/nominate', ''],
        'childrens-day-ng' => ['Children’s Day', 'info', 'balloons', 'balloon', 'Happy Children’s Day, %first%',
                               'For the teachers, coaches and carers who raise them.', 'Nominate a mentor', '/nominate', 'NG'],
    ];

    /** The DC's `G`: a 24-unit stroke glyph per theme. */
    public const GLYPHS = [
        'flag'    => 'M5 21V4M5 4h11l-2 4 2 4H5',
        'book'    => 'M3 5.5C5.5 4.5 8.5 4.5 12 6.5c3.5-2 6.5-2 9-1V19c-2.5-1-5.5-1-9 1-3.5-2-6.5-2-9-1V5.5Z M12 6.5V20',
        'tree'    => 'M12 3 6.5 10H9l-4 6h14l-4-6h2.5L12 3Z M12 16v5',
        'spark'   => 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18',
        'moon'    => 'M15.5 4.5a8 8 0 1 0 4 12.6A6.5 6.5 0 0 1 15.5 4.5Z M18 7.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z',
        'sunrise' => 'M4 18h16M7 18a5 5 0 0 1 10 0M12 6v3M5.6 10.6l1.8 1.4M18.4 10.6l-1.8 1.4M3 21h18',
        'globe'   => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3.5 9h17M3.5 15h17M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z',
        'flower'  => 'M12 13.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM12 10.5c-2-3.5-1-6.5 0-7.5 1 1 2 4 0 7.5ZM12 13.5c2 3.5 1 6.5 0 7.5-1-1-2-4 0-7.5ZM10.5 12c-3.5 2-6.5 1-7.5 0 1-1 4-2 7.5 0ZM13.5 12c3.5-2 6.5-1 7.5 0-1 1-4 2-7.5 0Z',
        'balloon' => 'M12 3a5 5 0 0 0-5 5.3c0 3.3 2.6 6.2 5 6.7 2.4-.5 5-3.4 5-6.7A5 5 0 0 0 12 3ZM12 15l-1 2h2l-1-2Zm0 2c0 2-2 2.5-1 4',
    ];

    /** The four patterns that are SVG tiles (the rest are CSS gradients in holiday.css). */
    public const SVG_PATTERNS = ['confetti', 'lattice', 'petals', 'balloons'];

    private const INDEPENDENCE_YEAR = 1960;

    /**
     * Where a greeting may appear: a member's own pages, by path prefix. Everything else is
     * out — public pages, admin, the judges' console, checkout, ballots, the door.
     * `/org` and `/my-work` are listed for when their pages are rebuilt on the shell.
     */
    private const SURFACES = ['/account', '/my-work', '/org'];
    private const NOT_SURFACES = ['/account/login', '/account/register', '/account/forgot', '/account/reset',
                                  '/account/verify', '/account/interests', '/org/login'];

    /** @var array<string,?array<string,mixed>> per request: member|day → banner */
    private static array $memo = [];

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function surfaceAllows(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::NOT_SURFACES as $no) {
            if ($path === $no || str_starts_with($path, $no . '/')) return false;
        }
        foreach (self::SURFACES as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) return true;
        }

        return false;
    }

    /**
     * The country a member's greetings are chosen for: their phone's, else the CDN's
     * reading of the request, else null (everyone-greetings only).
     */
    public static function memberCountry(?string $phoneE164, ?string $cdnCountry = null): ?string
    {
        $c = Phone::country((string) $phoneE164);
        if ($c !== null) return $c;
        $h = strtoupper(trim((string) $cdnCountry));

        return preg_match('/^[A-Z]{2}$/', $h) && $h !== 'XX' && $h !== 'T1' ? $h : null;
    }

    /**
     * The greeting for this member today, or null.
     *
     * Today is the platform's display zone (members store no zone of their own). Of two
     * windows both open, the one that started latest wins — a week-long season gives way to
     * the day inside it.
     *
     * @return array<string,mixed>|null the banner's view model ({@see view()})
     */
    public static function forMember(int $userId, ?string $cdnCountry = null, ?Carbon $now = null): ?array
    {
        if ($userId <= 0 || !SchemaHas::table('gates_holidays')) return null;
        $today = ($now ?? Carbon::now())->copy()->setTimezone(DisplayTime::zone())->toDateString();
        $key = $userId . '|' . $today . '|' . (string) $cdnCountry;
        if (array_key_exists($key, self::$memo)) return self::$memo[$key];

        return self::$memo[$key] = self::resolve($userId, $today, $cdnCountry);
    }

    /** @return array<string,mixed>|null */
    private static function resolve(int $userId, string $today, ?string $cdnCountry): ?array
    {
        try {
            $cols = ['id', 'name'];
            foreach (['phone_e164', 'seasonal_greetings'] as $c) if (SchemaHas::column('gates_users', $c)) $cols[] = $c;
            $u = DB::table('gates_users')->where('id', $userId)->first($cols);
            if (!$u) return null;
            if (property_exists($u, 'seasonal_greetings') && (int) $u->seasonal_greetings === 0) return null;
            $country = self::memberCountry($u->phone_e164 ?? null, $cdnCountry);

            $rows = DB::table('gates_holidays')
                ->where('active', 1)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)
                ->whereIn('slug', array_keys(self::THEMES))
                ->where(static function ($w) use ($country): void {
                    $w->whereNull('country_code')->orWhere('country_code', '');
                    if ($country !== null) $w->orWhere('country_code', $country);
                })
                ->orderByDesc('starts_on')->orderByDesc('id')->get();
            if ($rows->isEmpty()) return null;

            $year = (int) substr($today, 0, 4);
            $gone = DB::table('gates_holiday_dismissals')->where('member_id', $userId)->where('year', $year)
                ->pluck('slug')->all();
            foreach ($rows as $r) {
                if (in_array($r->slug, $gone, true)) continue;
                return self::view((string) $r->slug, self::firstName((string) $u->name), $year,
                    isset($r->cta_programme_id) ? (int) $r->cta_programme_id : null);
            }
        } catch (\Throwable) {
            // A greeting is decoration: no table, no column, no database — no banner.
        }

        return null;
    }

    /**
     * What the banner draws for one theme. Public so the board and the tests draw exactly
     * what a member is shown.
     *
     * @return array<string,mixed>
     */
    public static function view(string $slug, string $first, int $year, ?int $programmeId = null): array
    {
        [$name, $tone, $pattern, $glyph, $greeting, $line, $cta, $href] = self::THEMES[$slug];
        $first = $first !== '' ? $first : Translator::t('friend');
        $line  = Translator::t($line, ['%years%' => (string) ($year - self::INDEPENDENCE_YEAR)]);

        // The teachers' line names the award the row points at — only while it is taking
        // nominations, because "are open" is a claim about today.
        if ($slug === 'teachers' && $programmeId) {
            $p = self::openProgramme($programmeId);
            if ($p !== null) {
                $line .= ' ' . Translator::t('Nominations are open for the %award%.', ['%award%' => $p['title']]);
                $href = '/nominate/' . rawurlencode($p['slug']);
            }
        }

        return [
            'slug'     => $slug,
            'name'     => Translator::t($name),
            'tone'     => $tone,
            'pattern'  => $pattern,
            'glyph'    => self::GLYPHS[$glyph],
            'greeting' => Translator::t($greeting, ['%first%' => $first]),
            'line'     => $line,
            'cta'      => $cta !== '' ? Translator::t($cta) : '',
            'href'     => $href,
            'svg'      => in_array($pattern, self::SVG_PATTERNS, true) ? '/img/holiday/' . $pattern . '-' . $tone . '.svg' : '',
        ];
    }

    /** Record "not this year" for one theme. Idempotent. */
    public static function dismiss(int $userId, string $slug, ?Carbon $now = null): bool
    {
        if ($userId <= 0 || !isset(self::THEMES[$slug]) || !SchemaHas::table('gates_holiday_dismissals')) return false;
        $year = (int) ($now ?? Carbon::now())->copy()->setTimezone(DisplayTime::zone())->format('Y');
        try {
            DB::table('gates_holiday_dismissals')->insertOrIgnore([
                'member_id' => $userId, 'slug' => $slug, 'year' => $year, 'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            return false;
        }
        self::forget();

        return true;
    }

    /** Is this member's switch on? On unless they turned it off (and on before the column exists). */
    public static function greetingsOn(object $user): bool
    {
        if (property_exists($user, 'seasonal_greetings')) return (int) $user->seasonal_greetings !== 0;
        try {
            if (!SchemaHas::column('gates_users', 'seasonal_greetings')) return true;
            return (int) DB::table('gates_users')->where('id', (int) $user->id)->value('seasonal_greetings') !== 0;
        } catch (\Throwable) {
            return true;
        }
    }

    public static function setGreetings(int $userId, bool $on): bool
    {
        if ($userId <= 0 || !SchemaHas::column('gates_users', 'seasonal_greetings')) return false;
        try {
            DB::table('gates_users')->where('id', $userId)->update(['seasonal_greetings' => $on ? 1 : 0]);
        } catch (\Throwable) {
            return false;
        }
        self::forget();

        return true;
    }

    /** The first word of a name, or '' — "Chioma" from "Chioma Obi". */
    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return (string) ($parts[0] ?? '');
    }

    /** @return array{title:string, slug:string}|null the programme, while it takes nominations */
    private static function openProgramme(int $id): ?array
    {
        try {
            foreach ((new AwardService())->getActiveProgrammesWithStatus() as $p) {
                if ((int) $p['id'] === $id && !empty($p['phase']['is_nominations_open'])) {
                    return ['title' => (string) $p['title'], 'slug' => (string) $p['slug']];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * One SVG pattern tile in one tone — the DC's `PAT`, with the tone's line colour from
     * Accent. Served by a route rather than shipped as files, so the colour has one source
     * (the cover's `games` tile does the same, DG-5). Null for anything else.
     */
    public static function patternSvg(string $pattern, string $tone): ?string
    {
        $c = \AfricaGates\Support\Accent::holidayHex($tone, 'line');
        if ($c === null) return null;

        return match ($pattern) {
            'confetti' => "<svg xmlns='http://www.w3.org/2000/svg' width='72' height='72' fill='{$c}' fill-opacity='.32'><rect x='8' y='10' width='6' height='2.4' rx='1.2' transform='rotate(-24 11 11)'/><circle cx='46' cy='14' r='1.8'/><rect x='54' y='44' width='6' height='2.4' rx='1.2' transform='rotate(38 57 45)'/><circle cx='22' cy='52' r='1.5'/><rect x='32' y='30' width='5' height='2' rx='1' transform='rotate(70 34 31)'/></svg>",
            'lattice'  => "<svg xmlns='http://www.w3.org/2000/svg' width='44' height='44' fill='none' stroke='{$c}' stroke-opacity='.3' stroke-width='1'><path d='M22 6l4.6 11.4L38 22l-11.4 4.6L22 38l-4.6-11.4L6 22l11.4-4.6z'/></svg>",
            'petals'   => "<svg xmlns='http://www.w3.org/2000/svg' width='56' height='56' fill='none' stroke='{$c}' stroke-opacity='.3' stroke-width='1.1'><circle cx='28' cy='28' r='2.4'/><path d='M28 25.6c-2-4-1-8 0-9.6 1 1.6 2 5.6 0 9.6ZM28 30.4c2 4 1 8 0 9.6-1-1.6-2-5.6 0-9.6ZM25.6 28c-4 2-8 1-9.6 0 1.6-1 5.6-2 9.6 0ZM30.4 28c4-2 8-1 9.6 0-1.6 1-5.6 2-9.6 0Z'/></svg>",
            'balloons' => "<svg xmlns='http://www.w3.org/2000/svg' width='64' height='64' fill='none' stroke='{$c}' stroke-opacity='.3' stroke-width='1.1'><ellipse cx='18' cy='18' rx='6' ry='7.5'/><path d='M18 25.5c0 4 3 6 2 12'/><ellipse cx='46' cy='38' rx='5' ry='6.4'/><path d='M46 44.4c0 3.4-2.4 5-1.6 10'/></svg>",
            default    => null,
        };
    }
}
