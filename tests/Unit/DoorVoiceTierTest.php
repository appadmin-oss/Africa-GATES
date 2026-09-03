<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AzureVoice, DoorWelcome};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The free tier's real constraint, and the runaway it caused.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * F0 IS NOT LIMITED BY CHARACTERS, IT IS LIMITED BY REQUESTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Everything written about this feature reasons from the half-million characters a month
 * Azure's free tier allows, and that reasoning is sound: a three-hundred-guest gala spends
 * about thirteen thousand of them. It is also not the limit that bites.
 *
 * F0 allows about **twenty requests a minute**. `DoorWelcome::sweep()` rendered up to sixty
 * clips per tick, back to back, and counted SUCCESSES against that cap — so the
 * twenty-first came back `429`, `$made` stopped rising, the cap was never reached, and the
 * loop carried on through the ENTIRE remaining guest list. Several hundred refused requests,
 * once an hour, for the three days an event sits inside the lead window.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND EVERY PART OF IT WAS INVISIBLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no shell on this deployment. The only record was an `error_log()` line nobody
 * can read, `sweep()` returned a number that looked like ordinary progress, and the symptom
 * at the door was most guests not being greeted — which is exactly what the feature looks
 * like when nobody has switched it on.
 *
 * So there are three halves to this, and all three are asserted below: the run sizes itself
 * from the tier, it stops when it is throttled rather than hammering, and the reason lands
 * somewhere a person can read it.
 */
final class DoorVoiceTierTest extends TestCase
{
    private function set(string $key, string $value): void
    {
        DB::table('gates_settings')->where('key_name', $key)->delete();
        DB::table('gates_settings')->insert(['key_name' => $key, 'value' => $value]);
    }

    private function sweepSource(): string
    {
        $s = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DoorWelcome.php');

        // Comments stripped: the block explaining this bug names `$made`, `429` and the
        // cap, so a raw scan reads the documentation as if it were the code.
        return (string) preg_replace(['~/\*\*.*?\*/~s', '~^\s*//.*$~m'], '', $s);
    }

    // ══ the tier ═════════════════════════════════════════════════════════════

    /** It defaults to the free tier, because that is the one that produces the bug. */
    public function test_the_tier_defaults_to_free(): void
    {
        $this->assertSame('f0', AzureVoice::tier());
        $this->assertSame(AzureVoice::TIERS['f0']['rpm'], AzureVoice::perMinute());
    }

    /** A tier that is not one of ours is not sent to Azure as a budget. */
    public function test_an_unknown_tier_falls_back_rather_than_being_trusted(): void
    {
        foreach (['', '   ', 'F9', 'premium', 'true', '20'] as $bad) {
            $this->set('azure_speech_tier', $bad);
            $this->assertSame('f0', AzureVoice::tier(), 'a nonsense tier was honoured: ' . $bad);
        }
    }

    /** And it is case-insensitive, because the portal writes it as "F0". */
    public function test_the_tier_is_read_the_way_the_portal_writes_it(): void
    {
        $this->set('azure_speech_tier', 'F0');
        $this->assertSame('f0', AzureVoice::tier());

        $this->set('azure_speech_tier', 'S0');
        $this->assertSame('s0', AzureVoice::tier());
        $this->assertSame(AzureVoice::TIERS['s0']['rpm'], AzureVoice::perMinute());
    }

    /** Paying for S0 has to actually raise the budget, or the setting is decoration. */
    public function test_the_paid_tier_renders_more_per_run(): void
    {
        $this->assertGreaterThan(AzureVoice::TIERS['f0']['rpm'], AzureVoice::TIERS['s0']['rpm']);
    }

    /**
     * The free tier's budget stays UNDER Azure's published twenty.
     *
     * A limiter set to exactly the quota does not exist: the clock the sweep is measured
     * against is Azure's, not ours, and two runs either side of a minute boundary overlap.
     */
    public function test_the_free_budget_leaves_headroom_under_the_real_limit(): void
    {
        $this->assertLessThan(20, AzureVoice::TIERS['f0']['rpm'],
            'the free budget is set at or above the limit it exists to stay under');
    }

    // ══ the run sizes itself ═════════════════════════════════════════════════

    /**
     * THE FIX. The per-run budget is the LOWER of the ceiling and the tier's rate.
     *
     * `CAP` alone was the budget, and on F0 that is three times the tier's whole minute.
     */
    public function test_a_run_is_bounded_by_the_tier_and_not_by_the_ceiling(): void
    {
        $this->set('azure_speech_tier', 'f0');
        $this->assertLessThan(DoorWelcome::CAP, AzureVoice::perMinute(),
            'the fixture no longer exercises the case this test is about');

        $src = $this->sweepSource();
        $this->assertStringContainsString(
            'min(self::CAP, AzureVoice::perMinute())', $src,
            'the run takes its budget from the ceiling alone, so on F0 it asks for three '
            . 'times the tier\'s entire minute');
    }

    /**
     * The budget counts ATTEMPTS. This is the difference between a bounded run and a
     * runaway, and it is one word.
     */
    public function test_the_budget_counts_attempts_and_not_successes(): void
    {
        $src = $this->sweepSource();

        $this->assertMatchesRegularExpression('~if \(\$tried >= \$cap\) break;~', $src,
            'the loop is bounded by clips MADE, so a failing run is not bounded at all');
        $this->assertMatchesRegularExpression('~\$tried\+\+;~', $src,
            'nothing counts an attempt');
        $this->assertStringNotContainsString('if ($made >= $cap)', $src,
            'the old success-counted budget is back');
    }

    /** And a throttle ends the run rather than being retried once per remaining guest. */
    public function test_a_throttle_stops_the_run(): void
    {
        $this->assertMatchesRegularExpression(
            '~if \(AzureVoice::throttled\(\)\) break;~', $this->sweepSource(),
            'a 429 does not stop the sweep, so the rest of the guest list is asked for one '
            . 'refusal at a time');
    }

    /** Only a 429 counts as throttled; a one-off fault must not end the whole run. */
    public function test_only_a_rate_limit_reads_as_throttled(): void
    {
        $this->assertFalse(AzureVoice::throttled(),
            'a class that has made no call at all reports itself throttled');
    }

    // ══ the reason reaches a person ══════════════════════════════════════════

    /**
     * A recorded failure is published on the screen where the key was typed.
     *
     * `error_log()` was the only record, and this host has no shell — so "the key was
     * refused", "the quota is spent" and "that region does not host this voice" were the
     * same event to anybody who could look: a door that did not speak.
     */
    public function test_the_last_failure_is_readable_where_the_key_was_set(): void
    {
        $this->set('azure_speech_key', 'a-key-that-exists');
        $this->assertSame('', AzureVoice::why(), 'a configured voice is complaining about nothing');

        $this->set(AzureVoice::LAST_ERROR, '2026-09-03 06:00 · Azure refused the key.');

        $this->assertStringContainsString('Azure refused the key.', AzureVoice::why(),
            'the failure is recorded where only a shell could read it, and there is no shell');
    }

    /** No key is still the first thing said, because it is the likeliest and the simplest. */
    public function test_no_key_outranks_a_stale_complaint(): void
    {
        $this->set(AzureVoice::LAST_ERROR, 'something from last month');

        $this->assertStringContainsString('No Azure Speech key', AzureVoice::why());
    }

    // ══ it can actually be set up ════════════════════════════════════════════

    /** §18 · a setting with no field is a setting nobody can change. */
    public function test_the_tier_can_be_set_from_the_settings_screen(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');

        $this->assertStringContainsString('name="azure_speech_tier"', $tpl,
            'there is no field for the tier, so it can only be set in a file nobody can open');
        $this->assertStringContainsString("'azure_speech_tier'", $ctl,
            'the field posts a value the controller will not save');

        // And the screen says what choosing it does, in clips rather than in jargon.
        $this->assertStringContainsString('azure_per_run', $tpl,
            'the tier is offered as a word with no consequence attached to it');
    }

    // ══ the worklist can be heard, one row at a time ═════════════════════════

    /**
     * Every row carries its own field and its own Hear, wired to each other.
     *
     * A suggestion nobody can listen to is a guess an operator has no way to check, and
     * these are guesses by construction — a rule over letters with no tone in them. The
     * field is EDITABLE and the button reads it live, so pressing Hear after a correction
     * speaks the correction rather than the guess it replaced.
     */
    public function test_each_row_can_be_heard_and_the_button_reads_the_edited_field(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        // The field the operator types into, keyed per row.
        $this->assertMatchesRegularExpression(
            '~<input type="text" id="vp-\{\{ loop\.index0 \}\}" class="vp-say"~', $tpl,
            'the suggestion is not editable, so a wrong one cannot be corrected where it is read');

        // The button beside it, pointed at that row and nothing else.
        $this->assertMatchesRegularExpression(
            '~data-ag-do="voice-try"\s+data-target="vp-\{\{ loop\.index0 \}\}"~', $tpl,
            'the row has no Hear button, or it is not wired to its own field');

        // It reuses the preview handler, which already posts the field's CURRENT value —
        // that is what makes the row dynamic rather than a rendering of the suggestion.
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
        $this->assertStringContainsString("box ? box.value.trim() : ''", $js,
            'the preview sends something other than what is in the field right now');
    }

    /**
     * And the fill button takes what is in the rows, not a copy the server rendered.
     *
     * The rows are editable, so a second copy would be a second source of truth — and the
     * one that got pasted would be the one nobody had corrected, silently discarding the
     * exact work this list exists to collect.
     */
    public function test_the_fill_button_takes_the_live_rows_and_not_a_server_copy(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');
        $js  = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');

        $this->assertStringContainsString('data-rows="vp-say"', $tpl);
        $this->assertStringNotContainsString('data-lines=', $tpl,
            'the suggestions are rendered a second time, so an edited row can be overwritten '
            . 'by the version nobody read');
        $this->assertStringNotContainsString('voice_pending_lines', $ctl,
            'the server still builds its own copy of the lines');

        $this->assertStringContainsString("querySelectorAll('.' + (btn.getAttribute('data-rows')", $js,
            'the fill does not read the rows');
        $this->assertStringContainsString("el.getAttribute('data-name')", $js);
    }

    /**
     * A throttle on this screen says so, because on this screen it is the likeliest fault.
     *
     * Somebody working a worklist may press Hear twenty times in a minute, which is F0's
     * entire budget. "Check the key and the region" would send them to a box that is
     * perfectly correct.
     */
    public function test_a_failed_preview_reports_the_recorded_reason(): void
    {
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');

        $at = strpos($ctl, 'if (!\AfricaGates\Services\DoorWelcome::render($line))');
        $this->assertNotFalse($at, 'the preview no longer renders; this test must follow it');

        $this->assertStringContainsString('AzureVoice::lastError()', substr($ctl, $at, 600),
            'a rate-limited preview blames the key, which is the one thing that is right');
    }

    /** Every tier offered on the screen is one the budget knows about. */
    public function test_the_screen_offers_only_tiers_the_budget_understands(): void
    {
        foreach (array_keys(AzureVoice::TIERS) as $id) {
            $this->set('azure_speech_tier', $id);
            $this->assertSame($id, AzureVoice::tier());
            $this->assertGreaterThan(0, AzureVoice::perMinute());
        }
    }
}
