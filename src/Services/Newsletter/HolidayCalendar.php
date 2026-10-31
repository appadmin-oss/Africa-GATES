<?php
declare(strict_types=1);

namespace AfricaGates\Services\Newsletter;

use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The days Africa GATES writes to its readers to mark, and what it says on each.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE KINDS OF DATE, AND ONLY TWO OF THEM CAN BE WORKED OUT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   fixed   the same day every year — New Year, Africa Day, Democracy Day,
 *           Independence Day, Christmas.
 *   easter  computed (the Anonymous Gregorian algorithm). Not `easter_date()`: that
 *           needs PHP's calendar extension, which a shared host may not have, and a
 *           holiday that silently never arrives because an extension was missing is the
 *           failure this codebase keeps meeting in other shapes.
 *   set     Eid al-Fitr and Eid al-Adha follow the sighting of the moon, and the Federal
 *           Government declares the date days beforehand. Any computed date would be a
 *           guess that is a day wrong in some years — a greeting arriving the day before
 *           Eid reads as not knowing when Eid is. So an operator types the declared date
 *           on the newsletter screen, and an Eid with no date set is simply not sent.
 *
 * ── THE WORDS ARE WRITTEN ONCE, AND THE FACTS IN THEM ARE COMPUTED ──────────
 *
 * Independence Day says how old the country is; that number is worked out from 1960,
 * never typed, so the issue in 2031 does not say what the issue in 2026 said. Nothing
 * here mentions a prize: a prize is stated beside its terms on the challenge's own page.
 */
final class HolidayCalendar
{
    public const ENABLED_KEY = 'newsletter_holidays';

    /**
     * @var array<string,array{name:string, rule:string, greeting:string, message:string}>
     */
    public const HOLIDAYS = [
        'new-year' => [
            'name' => 'New Year’s Day', 'rule' => 'fixed:01-01',
            'greeting' => 'Happy New Year',
            'message' => 'Thank you for a year of celebrating the people who make our communities what they are. Here is what is open on Africa GATES as the new year begins.',
        ],
        'easter' => [
            'name' => 'Easter Sunday', 'rule' => 'easter',
            'greeting' => 'Happy Easter',
            'message' => 'Wishing you and your family a peaceful Easter. Here is what is happening on Africa GATES.',
        ],
        'africa-day' => [
            'name' => 'Africa Day', 'rule' => 'fixed:05-25',
            'greeting' => 'Happy Africa Day',
            'message' => 'Today marks the founding of the Organisation of African Unity in 1963 — a day for the people and the work that make this continent proud.',
        ],
        'democracy-day' => [
            'name' => 'Democracy Day', 'rule' => 'fixed:06-12',
            'greeting' => 'Happy Democracy Day',
            'message' => 'A day to celebrate the voices that shape our country. Here is where yours counts on Africa GATES right now.',
        ],
        'independence-day' => [
            'name' => 'Independence Day', 'rule' => 'fixed:10-01',
            'greeting' => 'Happy Independence Day',
            'message' => 'Nigeria is {years} today. Here is how to celebrate the people who make it proud, on Africa GATES.',
        ],
        'christmas' => [
            'name' => 'Christmas Day', 'rule' => 'fixed:12-25',
            'greeting' => 'Merry Christmas',
            'message' => 'Wishing you joy and rest this Christmas. Here is what is on at Africa GATES over the holidays.',
        ],
        'eid-al-fitr' => [
            'name' => 'Eid al-Fitr', 'rule' => 'set',
            'greeting' => 'Eid Mubarak',
            'message' => 'Wishing you and your loved ones a joyful Eid al-Fitr. Here is what is happening on Africa GATES.',
        ],
        'eid-al-adha' => [
            'name' => 'Eid al-Adha', 'rule' => 'set',
            'greeting' => 'Eid Mubarak',
            'message' => 'Wishing you and your loved ones a joyful Eid al-Adha. Here is what is happening on Africa GATES.',
        ],
    ];

    /** The year Nigeria became independent — what "Nigeria is N today" counts from. */
    private const INDEPENDENCE_YEAR = 1960;

    /** @param array<string,mixed> $settings */
    private function __construct(private readonly array $settings) {}

    /** @param array<string,mixed>|null $settings an already-loaded settings map */
    public static function load(?array $settings = null): self
    {
        if ($settings === null) {
            $settings = [];
            try {
                $settings = DB::table('gates_settings')
                    ->whereIn('key_name', array_merge([self::ENABLED_KEY],
                        array_map([self::class, 'dateKey'], array_keys(self::HOLIDAYS))))
                    ->pluck('value', 'key_name')->all();
            } catch (\Throwable) {
            }
        }
        return new self($settings);
    }

    /** @param array<string,mixed> $settings */
    public static function of(array $settings): self
    {
        return new self($settings);
    }

    /**
     * The settings key holding a declared date. One per holiday — the NEXT one declared —
     * rather than one per year: the operator types it once, when the government announces
     * it, and the screen never asks about an Eid that has already been and gone.
     */
    public static function dateKey(string $key): string
    {
        return 'holiday_date_' . $key;
    }

    /**
     * Which holidays get an issue. Every one, until somebody switches one off: a holiday
     * list an operator has to remember to fill in is a holiday list that stays empty.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $raw = $this->settings[self::ENABLED_KEY] ?? null;
        if ($raw === null || $raw === '') return array_keys(self::HOLIDAYS);
        $list = json_decode((string) $raw, true);
        if (!is_array($list)) return array_keys(self::HOLIDAYS);
        return array_values(array_intersect(array_keys(self::HOLIDAYS), $list));
    }

    /** The holiday's date in a given year as Y-m-d, or null when it is not known. */
    public function dateFor(string $key, int $year): ?string
    {
        $rule = self::HOLIDAYS[$key]['rule'] ?? null;
        if ($rule === null) return null;

        if (str_starts_with($rule, 'fixed:')) {
            return $year . '-' . substr($rule, 6);
        }
        if ($rule === 'easter') {
            return self::easter($year);
        }
        $v = trim((string) ($this->settings[self::dateKey($key)] ?? ''));
        return preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) && str_starts_with($v, $year . '-') ? $v : null;
    }

    /**
     * The holiday falling today in the display zone, if it is one we send for.
     *
     * @return array{key:string, date:string, name:string, greeting:string, message:string}|null
     */
    public function today(?Carbon $now = null): ?array
    {
        $local = ($now ?? Carbon::now())->copy()->setTimezone(DisplayTime::zone());
        $date  = $local->format('Y-m-d');
        foreach ($this->enabled() as $key) {
            if ($this->dateFor($key, (int) $local->year) === $date) {
                return $this->describe($key, (int) $local->year);
            }
        }
        return null;
    }

    /**
     * Every holiday in the next twelve months, for the screen that sets them. A declared
     * holiday whose date is not yet set (or has passed) appears with no date, which is the
     * prompt to enter the next one.
     *
     * @return list<array{key:string, name:string, date:?string, rule:string, enabled:bool}>
     */
    public function upcoming(?Carbon $now = null): array
    {
        $local = ($now ?? Carbon::now())->copy()->setTimezone(DisplayTime::zone())->startOfDay();
        $today = $local->format('Y-m-d');
        $until = $local->copy()->addYear()->format('Y-m-d');
        $on = array_flip($this->enabled());
        $out = [];
        foreach (self::HOLIDAYS as $key => $h) {
            $date = null;
            if ($h['rule'] === 'set') {
                $v = trim((string) ($this->settings[self::dateKey($key)] ?? ''));
                $date = preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) && $v >= $today ? $v : null;
            } else {
                foreach ([(int) $local->year, (int) $local->year + 1] as $y) {
                    $d = $this->dateFor($key, $y);
                    if ($d !== null && $d >= $today && $d <= $until) { $date = $d; break; }
                }
                if ($date === null) continue;
            }
            $out[] = ['key' => $key, 'name' => $h['name'], 'date' => $date, 'rule' => $h['rule'],
                      'enabled' => isset($on[$key])];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['date'] ?? '9999', $b['date'] ?? '9999'));
        return $out;
    }

    /** @return array{key:string, date:string, name:string, greeting:string, message:string} */
    public function describe(string $key, int $year): array
    {
        $h = self::HOLIDAYS[$key];
        $message = str_replace('{years}', (string) ($year - self::INDEPENDENCE_YEAR), $h['message']);
        return ['key' => $key, 'date' => (string) $this->dateFor($key, $year), 'name' => $h['name'],
                'greeting' => $h['greeting'], 'message' => $message];
    }

    /** Easter Sunday, Gregorian — the Anonymous (Meeus/Jones/Butcher) algorithm. */
    public static function easter(int $y): string
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $y, $month, $day);
    }

    /**
     * Save the screen: which holidays are on, and the declared dates.
     *
     * @param list<string> $enabled
     * @param array<string,string> $dates holiday key => Y-m-d, or '' to clear
     */
    public static function save(array $enabled, array $dates): void
    {
        $enabled = array_values(array_intersect(array_keys(self::HOLIDAYS), $enabled));
        DB::table('gates_settings')->updateOrInsert(['key_name' => self::ENABLED_KEY],
            ['value' => json_encode($enabled)]);
        foreach ($dates as $key => $v) {
            // Only a declared holiday takes a typed date; a computed one cannot be overridden
            // into the wrong day by a slip of the keyboard.
            if ((self::HOLIDAYS[$key]['rule'] ?? '') !== 'set') continue;
            $v = trim((string) $v);
            if ($v === '') {
                DB::table('gates_settings')->where('key_name', self::dateKey((string) $key))->delete();
            } elseif (preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                DB::table('gates_settings')->updateOrInsert(['key_name' => self::dateKey((string) $key)], ['value' => $v]);
            }
        }
    }
}
