<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GoogleMeetService;
use AfricaGates\Services\GoogleSheetsService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Where the Apps Script address and secret are read from.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Both values were read from `.env` and from nowhere else. There is no SSH on this
 * production host — that is stated at the top of CLAUDE.md and it is the constraint the
 * whole cron design bends around — so the only way to configure the calendar was to edit
 * a file the operator cannot open.
 *
 * The failure was worse than "does not work", because every screen explained itself
 * correctly: the schedule said the calendar could not be checked, the interview screen
 * offered a paste box, and Settings said "set GAS_SECRET in .env". All true, all
 * unactionable, and none of them said "and here is where to type it". The integration
 * looked like a deliberate manual workflow rather than a dead one, which is why it stayed
 * dead. The report was "the site is not reading the GAS secret"; the site was reading it
 * faithfully from the one place it could never be written.
 *
 * So: `gates_settings` first, `.env` still honoured as the fallback so a host that does
 * have the file keeps working untouched.
 */
final class GoogleSettingsSourceTest extends TestCase
{
    private function put(string $key, string $value): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $key], ['value' => $value]);
    }

    protected function tearDown(): void
    {
        // These are read live from $_ENV/$_SERVER by Env, and a leaked GAS_SECRET would
        // make the next test's "not configured" path configured. The suite already has one
        // documented instance of exactly that shape — a dev .env carrying OPENAI_API_KEY
        // breaking fourteen no-provider tests.
        foreach (['GAS_URL', 'GAS_SECRET'] as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        parent::tearDown();
    }

    // ── the settings row is the first place looked ──────────────────────────

    public function test_the_url_and_secret_come_from_settings(): void
    {
        $this->put('gas_url', 'https://script.google.com/macros/s/FROM_SETTINGS/exec');
        $this->put('gas_secret', 'shhh-from-settings');

        $this->assertSame('https://script.google.com/macros/s/FROM_SETTINGS/exec', GoogleMeetService::gasUrl());
        $this->assertSame('shhh-from-settings', GoogleMeetService::gasSecret());

        // And the service built from them can actually do the privileged actions, which is
        // the only assertion an operator cares about.
        $this->assertTrue(GoogleMeetService::boot()->canSchedule());
    }

    public function test_settings_beat_env(): void
    {
        $_ENV['GAS_URL']    = 'https://script.google.com/macros/s/FROM_ENV/exec';
        $_ENV['GAS_SECRET'] = 'from-env';
        $this->put('gas_url', 'https://script.google.com/macros/s/FROM_SETTINGS/exec');
        $this->put('gas_secret', 'from-settings');

        // An operator who pastes a new deployment's URL into the admin has to win over a
        // stale value in a file they cannot edit — otherwise the field they just filled in
        // does nothing and there is no way to tell from the screen.
        $this->assertStringContainsString('FROM_SETTINGS', GoogleMeetService::gasUrl());
        $this->assertSame('from-settings', GoogleMeetService::gasSecret());
    }

    public function test_env_is_still_the_fallback(): void
    {
        $_ENV['GAS_URL']    = 'https://script.google.com/macros/s/FROM_ENV/exec';
        $_ENV['GAS_SECRET'] = 'from-env';

        // A deployment that configured this before there was a field must not lose it.
        $this->assertStringContainsString('FROM_ENV', GoogleMeetService::gasUrl());
        $this->assertSame('from-env', GoogleMeetService::gasSecret());
    }

    public function test_a_blank_settings_row_falls_through_rather_than_blanking_the_integration(): void
    {
        $_ENV['GAS_SECRET'] = 'from-env';
        $this->put('gas_secret', '   ');

        // '' in a settings row is how "cleared" is stored, and it must not read as a
        // configured empty secret — that would refuse every action while reporting itself
        // as set, which is the least debuggable state available.
        $this->assertSame('from-env', GoogleMeetService::gasSecret());
    }

    public function test_nothing_anywhere_is_empty_and_not_a_crash(): void
    {
        $this->assertSame('', GoogleMeetService::gasUrl());
        $this->assertSame('', GoogleMeetService::gasSecret());
        $this->assertFalse(GoogleMeetService::boot()->canSchedule());
    }

    // ── one resolver, not two ───────────────────────────────────────────────

    public function test_the_sheet_sync_reads_the_same_address(): void
    {
        $this->put('gas_url', 'https://script.google.com/macros/s/ONE_DOOR/exec');

        // Sheets and Calendar are two actions on ONE deployment. Two resolvers for one
        // value is how the two halves come to disagree about whether it is configured —
        // and the sheet sync is the half that fails silently, into a spreadsheet nobody
        // is watching.
        $this->assertTrue(GoogleSheetsService::boot()->isConfigured());
    }

    public function test_the_sheet_sync_is_off_when_the_address_is_not_set(): void
    {
        $this->assertFalse(GoogleSheetsService::boot()->isConfigured());
    }

    // ── the advice on screen names a place that exists ──────────────────────

    public function test_the_explanation_points_at_the_admin_and_not_only_at_a_file(): void
    {
        foreach ([['', ''], ['https://script.google.com/macros/s/x/exec', '']] as [$url, $secret]) {
            $why = (new GoogleMeetService($url, $secret))->why();

            $this->assertNotSame('', $why);
            $this->assertStringContainsString('Settings', $why,
                'the operator cannot open .env, so a message that only names .env is a dead end');
        }
    }

    public function test_the_probe_fixes_name_the_field_as_well_as_the_file(): void
    {
        $rows = [];
        foreach ((new GoogleMeetService('', ''))->probeAll() as $r) $rows[(string) $r['key']] = $r;

        // "Set GAS_SECRET in .env" was the whole fix text. Both are named now: the box for
        // the operator, the variable for whoever does have a shell.
        $this->assertStringContainsString('box below', $rows['secret']['fix']);
        $this->assertStringContainsString('GAS_SECRET', $rows['secret']['fix']);
        $this->assertStringContainsString('GAS_URL', $rows['url']['fix']);
    }

    // ── the screen itself ───────────────────────────────────────────────────

    public function test_the_settings_screen_has_a_field_for_each_value(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/admin/settings.twig');

        $this->assertStringContainsString('name="gas_url"', $tpl);
        $this->assertStringContainsString('name="gas_secret"', $tpl);
        // Marker-gated like every other block on this page, so a save from another group
        // cannot blank the integration.
        $this->assertStringContainsString('name="google_settings"', $tpl);
    }

    public function test_the_secret_is_never_rendered_back_to_the_page(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/admin/settings.twig');

        // The URL is echoed on purpose — a stale /exec address is the commonest way this
        // integration half-works and it is invisible if the field is blanked for tidiness.
        // The secret is not, like every other credential on this page.
        $this->assertStringContainsString("value=\"{{ gas_url|default('') }}\"", $tpl);
        $this->assertDoesNotMatchRegularExpression('/name="gas_secret"[^>]*value=/', $tpl);
    }

    public function test_the_google_card_belongs_to_a_group(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/admin/settings.twig');

        // Every other card on this page carries data-sec and x-show. This one did not, so
        // it rendered on all six tabs at once — including under headings it has nothing to
        // do with, which is how a grouped page stops being trusted as grouped.
        $this->assertMatchesRegularExpression(
            '/id="sync-probe"[\s\S]{0,400}?data-sec="[a-z]+"/',
            $tpl
        );
        $this->assertMatchesRegularExpression('/id="sync-probe"[\s\S]{0,400}?x-show="show\(\$el\)"/', $tpl);
    }
}
