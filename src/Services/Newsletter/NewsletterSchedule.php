<?php
declare(strict_types=1);

namespace AfricaGates\Services\Newsletter;

use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * When the newsletter goes out, and whether it goes out by itself.
 *
 * The one resolver for the four `newsletter_*` settings — the admin screen that writes
 * them and the maintenance tick that acts on them both read through here, because the
 * pair most likely to disagree about a setting is the one that PUBLISHES it and the one
 * that ACTS on it.
 *
 * ── THREE MODES, AND OFF IS THE DEFAULT ──────────────────────────────────────
 *
 *   off     nothing is composed, nothing is sent, nobody is asked to confirm.
 *   review  an issue is composed on schedule and waits for a person to approve it.
 *   auto    an issue is composed on schedule and sends itself.
 *
 * Off by default because switching a deployment on is the act of mailing its whole list,
 * and that is a decision somebody makes on a screen, not something a deploy does to them.
 *
 * ── THE SLOT IS IN THE DISPLAY ZONE ──────────────────────────────────────────
 *
 * "Thursdays at 09:00" is typed by an operator in Lagos and means 09:00 in Lagos.
 * Computing it in UTC sends it an hour early in WAT, which is the `DisplayTime::toStored()`
 * lesson on a schedule instead of a form.
 */
final class NewsletterSchedule
{
    public const MODE_OFF    = 'off';
    public const MODE_REVIEW = 'review';
    public const MODE_AUTO   = 'auto';
    public const MODES = [
        self::MODE_OFF    => 'Off — nothing is composed or sent',
        self::MODE_REVIEW => 'Compose on schedule, send after approval',
        self::MODE_AUTO   => 'Compose and send on schedule',
    ];

    public const CADENCES = [
        'weekly'      => 'Every week',
        'fortnightly' => 'Every two weeks',
        'monthly'     => 'Every month (the first chosen weekday)',
    ];

    /** ISO weekday numbers, Monday = 1, which is what Carbon's `isoWeekday()` returns. */
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                             5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * How long after its slot a missed issue may still go out.
     *
     * A cron that was down at 09:00 Thursday should still send on Thursday afternoon. One
     * that was down for a week should NOT wake up and send last week's issue on Wednesday,
     * the day before this week's — so a slot older than this waits for the next period.
     */
    public const GRACE_HOURS = 48;

    /** A fixed Monday, so "every two weeks" means the same fortnights on every server. */
    private const FORTNIGHT_EPOCH = '2024-01-01';

    public const KEYS = ['newsletter_mode', 'newsletter_cadence', 'newsletter_weekday', 'newsletter_hour'];

    private function __construct(
        public readonly string $mode,
        public readonly string $cadence,
        public readonly int $weekday,
        public readonly int $hour,
    ) {}

    /** @param array<string,mixed>|null $settings an already-loaded settings map */
    public static function load(?array $settings = null): self
    {
        if ($settings === null) {
            $settings = [];
            try {
                $settings = DB::table('gates_settings')->whereIn('key_name', self::KEYS)
                    ->pluck('value', 'key_name')->all();
            } catch (\Throwable) {
                // No settings table is not a reason to start mailing anybody: the
                // defaults below are "off".
            }
        }
        return self::of($settings);
    }

    /** @param array<string,mixed> $v */
    public static function of(array $v): self
    {
        $mode    = (string) ($v['newsletter_mode'] ?? '');
        $cadence = (string) ($v['newsletter_cadence'] ?? '');
        $weekday = (int) ($v['newsletter_weekday'] ?? 4);
        $hour    = (int) ($v['newsletter_hour'] ?? 9);

        return new self(
            isset(self::MODES[$mode]) ? $mode : self::MODE_OFF,
            isset(self::CADENCES[$cadence]) ? $cadence : 'weekly',
            isset(self::WEEKDAYS[$weekday]) ? $weekday : 4,
            ($hour >= 0 && $hour <= 23) ? $hour : 9,
        );
    }

    /**
     * Write the settings. Values are re-resolved through of() first, so whatever is
     * stored is something load() reads back unchanged.
     *
     * @param array<string,mixed> $in
     */
    public static function save(array $in): self
    {
        $s = self::of($in);
        foreach (['newsletter_mode' => $s->mode, 'newsletter_cadence' => $s->cadence,
                  'newsletter_weekday' => (string) $s->weekday,
                  'newsletter_hour' => (string) $s->hour] as $k => $val) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $val]);
        }
        return $s;
    }

    public function on(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    public function sendsItself(): bool
    {
        return $this->mode === self::MODE_AUTO;
    }

    /**
     * The schedule in a sentence, for the public page and the admin screen alike — so the
     * page that promises a day and the setting that decides it cannot disagree.
     */
    public function describe(): string
    {
        $day  = self::WEEKDAYS[$this->weekday];
        $time = sprintf('%02d:00 %s', $this->hour, DisplayTime::abbr());
        return match ($this->cadence) {
            'fortnightly' => "Every other {$day} at {$time}",
            'monthly'     => "The first {$day} of each month at {$time}",
            default       => "Every {$day} at {$time}",
        };
    }

    /** "this week", "this fortnight", "this month" — the headline's own words. */
    public function periodNoun(): string
    {
        return match ($this->cadence) {
            'fortnightly' => 'fortnight',
            'monthly'     => 'month',
            default       => 'week',
        };
    }

    /**
     * The slot that has most recently come round, if it is still inside its grace window.
     * This is "is an issue due": the caller then asks whether one exists for its period.
     */
    public function dueSlot(?Carbon $now = null): ?Carbon
    {
        $now  = ($now ?? Carbon::now())->copy()->setTimezone(DisplayTime::zone());
        $day  = $now->copy()->startOfDay();
        for ($i = 0; $i <= 35; $i++) {
            $slot = $day->copy()->subDays($i)->setTime($this->hour, 0);
            if ($slot->gt($now) || !$this->isSlotDay($slot)) continue;
            return $now->diffInHours($slot, true) <= self::GRACE_HOURS ? $slot : null;
        }
        return null;
    }

    /** The next slot strictly after now — what the admin screen prints. */
    public function nextSlot(?Carbon $now = null): Carbon
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone(DisplayTime::zone());
        $day = $now->copy()->startOfDay();
        for ($i = 0; $i <= 70; $i++) {
            $slot = $day->copy()->addDays($i)->setTime($this->hour, 0);
            if ($slot->gt($now) && $this->isSlotDay($slot)) return $slot;
        }
        return $day->addDays(7)->setTime($this->hour, 0);   // unreachable with a valid weekday
    }

    /**
     * One issue per period. The key comes from the SLOT, never from "now", so a tick at
     * 23:59 and one at 00:01 the next morning agree on which issue they are talking about.
     */
    public function periodKey(Carbon $slot): string
    {
        $slot = $slot->copy()->setTimezone(DisplayTime::zone());
        return match ($this->cadence) {
            'fortnightly' => 'f' . intdiv($this->daysSinceEpoch($slot), 14),
            'monthly'     => 'm' . $slot->format('Y-m'),
            default       => 'w' . $slot->format('o-W'),
        };
    }

    private function isSlotDay(Carbon $d): bool
    {
        if ($d->isoWeekday() !== $this->weekday) return false;
        return match ($this->cadence) {
            'fortnightly' => intdiv($this->daysSinceEpoch($d), 7) % 2 === 0,
            'monthly'     => $d->day <= 7,
            default       => true,
        };
    }

    private function daysSinceEpoch(Carbon $d): int
    {
        $epoch = Carbon::parse(self::FORTNIGHT_EPOCH, $d->getTimezone());
        return (int) $epoch->diffInDays($d->copy()->startOfDay(), false);
    }
}
