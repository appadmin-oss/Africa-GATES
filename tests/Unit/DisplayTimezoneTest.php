<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\DisplayTime;
use Tests\TestCase;

/**
 * Every date the platform prints is in ONE timezone.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Twig's `|date` filter falls back to PHP's default timezone, which is UTC on this
 * deployment because `APP_TIMEZONE` is unset. The custom `|when_zoned` filter converts to
 * {@see DisplayTime::zone()}, which is Africa/Lagos.
 *
 * So the SAME stored timestamp printed as two different dates depending on which filter a
 * template happened to reach for:
 *
 *     /vote      closes  …|when_zoned  →  "4 Sep 2026, 00:12"   (Lagos)
 *     /nominate  opens   …|date        →  "3 Sep 2026"          (UTC)
 *
 * One hour a day, every day, on pages a visitor moves between. On a stand call it is worse
 * than confusing: a vendor reads "applications close 3 September" on one page while the
 * deadline the system enforces falls on the 4th in their own timezone.
 *
 * Found by a test that failed only between 23:00 and midnight UTC — which is exactly how
 * long this would have gone unnoticed in production, since nobody is looking at 23:12.
 */
final class DisplayTimezoneTest extends TestCase
{
    /**
     * THE ONE THAT MATTERS: the two filters agree.
     *
     * Asserted on a timestamp inside the window where they used to differ — late enough in
     * UTC that Lagos has already rolled over to the next day. A midday timestamp would
     * pass with the bug fully present.
     */
    public function test_date_and_when_zoned_print_the_same_day(): void
    {
        $env = $this->twig()->getEnvironment();
        $late = '2026-09-03 23:12:00';   // 00:12 the next day in Lagos

        $plain  = $env->createTemplate('{{ d|date("j M Y") }}')->render(['d' => $late]);
        $zoned  = $env->createTemplate('{{ d|when_zoned("j M Y") }}')->render(['d' => $late]);

        $this->assertSame('4 Sep 2026', $plain,
            'the plain filter is still printing the UTC day');
        // `when_zoned` appends the abbreviation on purpose — a time with no zone beside it
        // is the ambiguity it exists to remove — so the DAY is what has to match.
        $this->assertStringStartsWith('4 Sep 2026', $zoned);
    }

    /** And the configured zone is the display zone, not whatever PHP defaulted to. */
    public function test_twig_uses_the_display_zone(): void
    {
        $tz = $this->twig()->getEnvironment()
            ->getExtension(\Twig\Extension\CoreExtension::class)->getTimezone();

        $this->assertSame(DisplayTime::zone(), $tz->getName());
    }

    /**
     * `|date('c')` stays a valid ISO-8601 string.
     *
     * It feeds `<time datetime="…">`, and a machine-readable stamp that lost its offset —
     * or gained a wrong one — would be worse than the inconsistency this change fixed.
     */
    public function test_the_machine_readable_stamp_keeps_a_correct_offset(): void
    {
        $out = $this->twig()->getEnvironment()
            ->createTemplate('{{ d|date("c") }}')->render(['d' => '2026-09-03 23:12:00']);

        $this->assertSame('2026-09-04T00:12:00+01:00', $out);
        // Round-trips to the instant it started as, which is the only thing that matters.
        $this->assertSame('2026-09-03 23:12:00',
            (new \DateTimeImmutable($out))->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'));
    }

    /** `|date('U')` is a Unix timestamp and must not have moved. */
    public function test_a_unix_timestamp_is_unaffected(): void
    {
        $out = $this->twig()->getEnvironment()
            ->createTemplate('{{ d|date("U") }}')->render(['d' => '2026-09-03 23:12:00']);

        $this->assertSame((string) strtotime('2026-09-03 23:12:00 UTC'), $out);
    }

    private function twig(): \Slim\Views\Twig
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require __DIR__ . '/../../config/container.php');

        return $b->build()->get(\Slim\Views\Twig::class);
    }
    /**
     * No `datetime-local` field is filled with `|date`.
     *
     * ── WHY THIS IS A DIFFERENT RULE FROM THE ONE ABOVE ─────────────────────
     *
     * Everything a VISITOR reads should be in the display zone — that is the fix this file
     * exists for. An admin `datetime-local` field is the opposite case: whatever it shows
     * is parsed straight back by `Carbon::parse()` on save, with no zone attached, so it
     * lands in the stored zone. Display and parse have to agree, and they agree on the
     * STORED value.
     *
     * Twenty-seven of the twenty-eight fields already use the raw string transform. The one
     * that used `|date` was identical while Twig had no timezone configured, and stopped
     * being identical the moment one was set — the field would show an hour later than it
     * stored, and saving without touching it would push the deadline forward an hour every
     * time. That is the quietest possible data-corruption bug: it only moves when somebody
     * opens the form, and it moves in the direction nobody checks.
     */
    public function test_no_datetime_local_field_is_filled_through_the_date_filter(): void
    {
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('templates'));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.twig')) continue;

            $src = (string) file_get_contents($f->getPathname());
            // The value attribute of a datetime-local input, however it is spread over
            // lines — the attribute frequently sits two lines below the type.
            if (!preg_match_all('~<input[^>]*type="datetime-local"[^>]*>~is', $src, $m)) continue;

            foreach ($m[0] as $tag) {
                if (preg_match('~value="\{\{[^"]*\|\s*date\(~i', $tag)) {
                    $offenders[] = str_replace(getcwd() . '/', '', $f->getPathname());
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "A datetime-local field filled with |date shifts by the display offset on every "
            . "save:\n  " . implode("\n  ", array_unique($offenders)));
    }
}
