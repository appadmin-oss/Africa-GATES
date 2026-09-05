<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GoogleMeetService;
use Tests\TestCase;

/**
 * The screen that says whether the Google sync is really working.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A CONFIG CHECK WAS NOT ENOUGH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Five different failures look identical from the .env file: the deployment was never
 * published, it was published as "only me", SECRET and GAS_SECRET are different strings,
 * the Calendar advanced service was never added, or the script was edited and never
 * re-deployed — Apps Script serves the last DEPLOYED version, not the last saved one.
 *
 * `isConfigured()` says yes to all five, which is why an operator can set everything
 * correctly and watch interviews fail to schedule with nothing on any screen to read.
 */
final class GoogleSyncProbeTest extends TestCase
{
    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function byKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) $out[(string) $r['key']] = $r;
        return $out;
    }

    /**
     * With nothing configured, the live rows say NOT TESTED rather than failing.
     *
     * Four red crosses whose real cause is the two rows above them is a screen that sends
     * an operator to check their Calendar service when the problem is an empty variable.
     */
    public function test_nothing_configured_reports_untested_rather_than_broken(): void
    {
        $rows = $this->byKey((new GoogleMeetService('', ''))->probeAll());

        $this->assertFalse($rows['url']['ok']);
        $this->assertTrue($rows['url']['tested'], 'whether a variable is set is knowable without asking anybody');
        $this->assertNotSame('', $rows['url']['fix']);

        foreach (['reach', 'auth', 'calendar', 'freebusy'] as $k) {
            $this->assertFalse($rows[$k]['tested'], $k . ' claimed to have been tested with no address to test');
            $this->assertStringContainsString('Not attempted', $rows[$k]['detail']);
        }
    }

    /** A URL with no secret is still not a working sync, and the row names which half. */
    public function test_a_missing_secret_is_reported_as_its_own_fault(): void
    {
        $rows = $this->byKey((new GoogleMeetService('https://script.google.com/macros/s/x/exec', ''))->probeAll());

        $this->assertTrue($rows['url']['ok']);
        $this->assertFalse($rows['secret']['ok']);
        $this->assertStringContainsString('GAS_SECRET', $rows['secret']['fix']);
        $this->assertFalse($rows['reach']['tested']);
    }

    /**
     * The write path is REPORTED, never run.
     *
     * A confidence check that leaves a real event in the operator's diary and mails
     * everybody on it is one they run once and then avoid.
     */
    public function test_creating_an_event_is_never_probed(): void
    {
        foreach ([['', ''], ['https://script.google.com/macros/s/x/exec', 'shh']] as [$url, $secret]) {
            $row = $this->byKey((new GoogleMeetService($url, $secret))->probeAll())['create'];

            $this->assertFalse($row['tested'], 'the probe tried to create a real calendar event');
            $this->assertStringContainsString('calendar', strtolower($row['detail']));
        }
    }

    /** Every row a reader sees carries the four things the screen renders. */
    public function test_every_row_is_renderable(): void
    {
        foreach ((new GoogleMeetService('', ''))->probeAll() as $r) {
            foreach (['key', 'label', 'ok', 'tested', 'detail', 'fix'] as $k) {
                $this->assertArrayHasKey($k, $r);
            }
            $this->assertNotSame('', (string) $r['label']);
        }
    }

    /** A failing row that gives no fix is a verdict, which is what this screen replaces. */
    public function test_a_failing_row_says_what_to_change(): void
    {
        $rows = $this->byKey((new GoogleMeetService('', ''))->probeAll());

        foreach (['url', 'secret'] as $k) {
            $this->assertFalse($rows[$k]['ok']);
            $this->assertNotSame('', $rows[$k]['fix'], $k . ' failed without saying what to do');
        }
    }

    /** The panel is on the settings screen, wired to the read-only endpoint. */
    public function test_the_settings_screen_carries_the_panel(): void
    {
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        $this->assertStringContainsString('id="sync-probe"', $t);
        $this->assertStringContainsString('Check the sync', $t);
        // The button posts to the main save route carrying `probe=sync`, which saves the
        // page and then runs the check. It used to be a nested <form> pointing straight at
        // /admin/settings/probe-sync — and an HTML parser discards a <form> opened inside
        // an open one, so it reached neither: it posted to /admin/settings and returned no
        // rows at all. See NestedFormTest.
        $this->assertMatchesRegularExpression('/name="probe"\s+value="sync"/', $t);
        // Never colour alone, and the admin CSP has no 'unsafe-inline'.
        $this->assertStringContainsString('Not working', $t);
        $this->assertStringNotContainsString('onclick=', $t);
    }
}
