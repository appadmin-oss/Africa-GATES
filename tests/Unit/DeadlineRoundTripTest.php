<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A deadline survives being shown to an operator and saved back.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE IMPLEMENTATIONS, AND THE CANONICAL ONE WAS DEAD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `Support\DisplayTime` exists to do exactly one thing, and both halves of it had
 * ZERO callers: `toStored()` and `forInput()` were unreferenced anywhere in
 * production, including from each other's documentation, which still asserted the
 * round trip happened. In their place:
 *
 *   • `EventsController` carried a private Carbon pair formatting `'Y-m-d\TH:i'`.
 *   • `programmes/cycle.twig` did it inline, five times, as
 *     `|replace({' ': 'T'})|slice(0,16)`.
 *   • `cycleSave()` wrote the raw POST value straight through.
 *
 * So `2026-01-01T09:00` landed in `gates_award_cycles.voting_close`: a `T`
 * separator and no seconds, on the column that decides whether a vote counted.
 *
 * WHY THE `T` IS NOT COSMETIC. MySQL normalises a T-separated value on its way into
 * a DATETIME, so production survived it. SQLite — dev, and this whole harness —
 * stores the string verbatim, and `'2026-01-01T09:00'` sorts AFTER every
 * space-separated stamp of the same day, because 'T' is 0x54 and ' ' is 0x20. A
 * phase comparison that passes every test and silently rejects real input.
 *
 * WHY THE SECONDS ARE NOT COSMETIC EITHER. `slice(0,16)` truncated them, so a close
 * stored at 23:59:59 came back 23:59:00 every time somebody opened the form and
 * pressed save without touching the field. Fifty-nine seconds of drift per save, in
 * the one column where a vote is or is not counted.
 *
 * ── ON THE ZONE, AND WHY NO DATA MIGRATION CAME WITH THIS ────────────────────
 *
 * The cycle form used to be labelled with `Clock::timezone()` — the PROCESS zone,
 * UTC — and stored what was typed. So an operator in Lagos had to convert every
 * deadline in their head, and the rows they produced are correct UTC. `toStored()`
 * converts from the DISPLAY zone to UTC, so the rows it produces are correct UTC
 * too. Both conventions write the same column correctly; only what the operator
 * types and sees changes. That is the whole reason this needed no backfill.
 */
final class DeadlineRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DisplayTime::forget();
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'display_timezone'], ['value' => 'Africa/Lagos']
        );
        DisplayTime::forget();
    }

    protected function tearDown(): void
    {
        DisplayTime::forget();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════

    /** The `T` never reaches a column. On SQLite nothing else would catch it. */
    public function test_a_browser_value_is_stored_without_its_T_separator(): void
    {
        $stored = DisplayTime::toStored('2026-01-01T09:00:00');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('T', $stored,
            "a T-separated stamp sorts after every space-separated one on SQLite");
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored);
    }

    /** Seconds survive the trip out and back. This is the 59-second drift. */
    public function test_the_seconds_survive_a_round_trip(): void
    {
        $stored = '2026-03-01 22:59:59';           // 23:59:59 in Lagos

        $shown = DisplayTime::forInput($stored);
        $this->assertStringEndsWith(':59', $shown, 'forInput dropped the seconds');

        $this->assertSame($stored, DisplayTime::toStored($shown),
            'opening the form and saving without touching the field moved the deadline');
    }

    /** And it is stable, not merely lossless once — a save is not a one-off. */
    public function test_repeated_saves_do_not_walk_the_deadline(): void
    {
        $value = '2026-03-01 22:59:59';
        for ($i = 0; $i < 5; $i++) {
            $value = (string) DisplayTime::toStored(DisplayTime::forInput($value));
        }

        $this->assertSame('2026-03-01 22:59:59', $value,
            'five opens-and-saves moved a deadline that nobody edited');
    }

    /** Storage is UTC; the operator types their own wall clock. */
    public function test_the_operator_types_their_own_zone_and_utc_is_stored(): void
    {
        // Lagos is UTC+1 with no DST, so this is a fixed hour either way.
        $this->assertSame('2026-03-01 22:59:59', DisplayTime::toStored('2026-03-01T23:59:59'));
        $this->assertSame('2026-03-01T23:59:59', DisplayTime::forInput('2026-03-01 22:59:59'));
    }

    /** A blank box is a skipped phase, not 1 January 1970. */
    public function test_a_blank_field_stays_blank(): void
    {
        $this->assertNull(DisplayTime::toStored(''));
        $this->assertNull(DisplayTime::toStored(null));
        $this->assertSame('', DisplayTime::forInput(null));
        $this->assertSame('', DisplayTime::forInput(''));
    }

    // ════════════════════════════════════════════════════════════════════════
    //  One implementation
    // ════════════════════════════════════════════════════════════════════════

    /**
     * No template may round-trip a datetime by hand. This is the exact expression
     * that put the `T` into the column, and it read as ordinary Twig plumbing.
     */
    public function test_no_template_converts_a_datetime_by_hand(): void
    {
        $offenders = [];
        foreach ($this->templates() as $file) {
            $body = (string) file_get_contents($file);
            if (preg_match("~replace\(\s*\{\s*'\s'\s*:\s*'T'\s*\}~", $body) === 1) {
                $offenders[] = substr($file, strlen(dirname(__DIR__, 2)) + 1);
            }
        }

        $this->assertSame([], $offenders,
            'a template is building a datetime-local value itself — use DisplayTime::forInput()');
    }

    /**
     * Any input that can be handed a value carrying seconds must ask the browser to
     * keep them. Without `step="1"` the control renders at minute precision and hands
     * back a value one minute coarser than the one it was given — which is the drift
     * above, reintroduced by markup rather than by PHP.
     */
    public function test_every_datetime_input_asks_for_seconds(): void
    {
        $offenders = [];
        foreach ($this->templates() as $file) {
            $body = (string) file_get_contents($file);
            // Whole tags, across newlines: several of these inputs are written over two
            // or three lines, and a line-at-a-time scan reports the opening line of a
            // tag whose step="1" is on the next one.
            preg_match_all('~<input\b[^>]*>~s', $body, $m);
            foreach ($m[0] as $tag) {
                if (!str_contains($tag, 'type="datetime-local"')) continue;
                if (str_contains($tag, 'step=')) continue;
                $line = substr_count(substr($body, 0, (int) strpos($body, $tag)), "\n") + 1;
                $offenders[] = substr($file, strlen(dirname(__DIR__, 2)) + 1) . ':' . $line;
            }
        }

        $this->assertSame([], $offenders,
            'these datetime inputs round to the minute — pair them with step="1"');
    }

    /** @return list<string> */
    private function templates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates')
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'twig') $out[] = $f->getPathname();
        }
        return $out;
    }
}
