<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AttendeeBot;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Configuring the interview bot from the admin screen instead of a .env file.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * All six settings were env-only. This platform deploys to cPanel by upload with no SSH, so
 * "set ATTENDEE_API_KEY" was not an action the operator could take — the interview bot had
 * no configuration surface at all, and the feature was unreachable on the deployment it was
 * written for.
 *
 * The stored value therefore WINS over the environment. The other order looks safer and is
 * worse: a settings screen that silently does nothing whenever an env var happens to be set
 * is the same defect as a sidebar offering a link the guard refuses.
 */
final class AttendeeConfigTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Env values leak between tests through $_ENV, and several of these keys are set in
        // the harness. Snapshot and restore rather than assuming a clean slate.
        foreach (AttendeeBot::SETTINGS as $spec) {
            $this->saved[$spec['env']] = $_ENV[$spec['env']] ?? false;
            unset($_ENV[$spec['env']], $_SERVER[$spec['env']]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === false) unset($_ENV[$k], $_SERVER[$k]);
            else $_ENV[$k] = $v;
        }
        parent::tearDown();
    }

    private function store(string $key, string $value): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ══ precedence ═══════════════════════════════════════════════════════════

    public function test_nothing_configured_falls_back_to_the_documented_default(): void
    {
        $this->assertSame('', AttendeeBot::apiKey());
        $this->assertFalse(AttendeeBot::configured());
        $this->assertSame(AttendeeBot::HOSTED_BASE . '/api/v1', AttendeeBot::base());
        $this->assertSame('Africa GATES Interview Assistant', AttendeeBot::botName());
        $this->assertSame('gpt-4o-transcribe', AttendeeBot::conf('attendee_stt_model'));
    }

    public function test_the_environment_is_used_when_nothing_is_stored(): void
    {
        $_ENV['ATTENDEE_API_KEY']  = 'env-key-123';
        $_ENV['ATTENDEE_BOT_NAME'] = 'Env Bot';

        $this->assertSame('env-key-123', AttendeeBot::apiKey());
        $this->assertSame('Env Bot', AttendeeBot::botName());
        $this->assertTrue(AttendeeBot::configured());
    }

    /** THE ONE THAT MATTERS. An operator with no shell must be able to override a .env. */
    public function test_a_stored_setting_wins_over_the_environment(): void
    {
        $_ENV['ATTENDEE_API_KEY'] = 'env-key-123';
        $this->store('attendee_api_key', 'typed-in-the-admin');

        $this->assertSame('typed-in-the-admin', AttendeeBot::apiKey(),
            'a settings screen that loses to a file the operator cannot edit is not a settings screen');
    }

    /**
     * A blank stored value is "not set", not an empty string.
     *
     * Otherwise clearing a base URL in the form would point the HTTP client at nothing,
     * and clearing the model name would send an empty model to the API.
     */
    public function test_a_blank_stored_value_falls_through_rather_than_forcing_an_empty_string(): void
    {
        $_ENV['ATTENDEE_BASE_URL'] = 'https://mine.example.org';
        $this->store('attendee_base_url', '');

        $this->assertSame('https://mine.example.org/api/v1', AttendeeBot::base());
    }

    public function test_the_base_url_still_gets_its_version_segment_and_no_double_slash(): void
    {
        $this->store('attendee_base_url', 'https://mine.example.org/');

        $this->assertSame('https://mine.example.org/api/v1', AttendeeBot::base());
    }

    public function test_self_hosted_is_detected_from_the_stored_url_too(): void
    {
        $this->assertFalse(AttendeeBot::selfHosted(), 'nothing set means the hosted service');

        $this->store('attendee_base_url', 'https://meetings.afrovanguard.org');
        $this->assertTrue(AttendeeBot::selfHosted());
    }

    // ══ what the screen reads ════════════════════════════════════════════════

    public function test_the_report_names_the_source_of_every_value(): void
    {
        $_ENV['ATTENDEE_BOT_NAME'] = 'From Env';
        $this->store('attendee_api_key', 'sk-abcdefghijklmnop');

        $r = AttendeeBot::configReport();

        $this->assertSame('settings', $r['attendee_api_key']['source']);
        $this->assertSame('env',      $r['attendee_bot_name']['source']);
        $this->assertSame('default',  $r['attendee_stt_model']['source']);
        $this->assertSame('unset',    $r['attendee_base_url']['source']);
    }

    /** A live credential must not be rendered into a page somebody screenshots. */
    public function test_the_key_is_masked_everywhere_it_is_reported(): void
    {
        $this->store('attendee_api_key', 'sk-super-secret-value-9999');

        $shown = AttendeeBot::configReport()['attendee_api_key']['value'];

        $this->assertStringNotContainsString('super-secret', $shown);
        $this->assertStringContainsString('9999', $shown, 'enough to recognise which key it is');
        $this->assertStringContainsString('26 chars', $shown);
    }

    /** An env value hidden behind a stored one is inert, and worth saying so. */
    public function test_a_shadowed_environment_value_is_surfaced_as_shadowed(): void
    {
        $_ENV['ATTENDEE_BOT_NAME'] = 'The Old Name';
        $this->store('attendee_bot_name', 'The New Name');

        $r = AttendeeBot::configReport()['attendee_bot_name'];

        $this->assertSame('The New Name', $r['value']);
        $this->assertSame('The Old Name', $r['shadowed_env'],
            'an operator staring at the wrong name needs to know the env is being ignored');
    }

    public function test_a_shadowed_secret_is_masked_as_well(): void
    {
        $_ENV['ATTENDEE_API_KEY'] = 'sk-the-old-key-1234';
        $this->store('attendee_api_key', 'sk-the-new-key-5678');

        $r = AttendeeBot::configReport()['attendee_api_key'];

        $this->assertStringNotContainsString('old-key', $r['shadowed_env']);
        $this->assertStringContainsString('1234', $r['shadowed_env']);
    }

    public function test_an_unknown_key_reads_as_empty_rather_than_throwing(): void
    {
        $this->assertSame('', AttendeeBot::conf('attendee_nonexistent'));
    }

    // ══ the join notice, and the off switch that is not a blank ══════════════

    /**
     * Clearing the field CANNOT turn the notice off, because a blank stored value falls
     * through to the default. `none` is the only off switch, and the form says so.
     */
    public function test_clearing_the_join_notice_restores_the_default_and_none_turns_it_off(): void
    {
        $this->store('attendee_join_notice', '');
        $this->assertNotSame('', AttendeeBot::joinNotice(), 'blank is "not set", not "off"');

        DB::table('gates_settings')->where('key_name', 'attendee_join_notice')->delete();
        $this->store('attendee_join_notice', 'none');
        $this->assertSame('', AttendeeBot::joinNotice());
    }

    public function test_the_join_notice_still_strips_emoji_when_it_comes_from_settings(): void
    {
        $this->store('attendee_join_notice', 'Recording 🎙️ for the panel');

        $n = AttendeeBot::joinNotice();
        $this->assertSame('Recording for the panel', $n,
            "Attendee's chat endpoint rejects emoji outright — losing the whole notice over one "
            . 'would be a poor trade');
    }

    // ══ the check ════════════════════════════════════════════════════════════

    /**
     * The local checks run first and short-circuit, so a problem needing no network is
     * never reported as a network problem — the distinction an operator with no SSH cannot
     * make for themselves.
     */
    public function test_a_missing_key_is_reported_as_a_missing_key_not_as_unreachable(): void
    {
        $r = AttendeeBot::checkConnection();

        $this->assertFalse($r['ok']);
        $this->assertSame('error', $r['level']);
        $this->assertStringContainsString('Paste an API key', $r['message']);
        $this->assertStringNotContainsString('reach', strtolower($r['message']));

        $names = array_column($r['checks'], 'name');
        $this->assertContains('API key', $names);
        $this->assertNotContains('Reached the API', $names, 'no network call should have been made');
    }

    /**
     * An http:// base URL fails rather than warns. Redirects are deliberately not followed,
     * so it presents as an unreachable host — which is the wrong thing to tell somebody.
     */
    public function test_an_insecure_base_url_is_refused_with_the_reason(): void
    {
        $this->store('attendee_api_key', 'sk-whatever');
        $this->store('attendee_base_url', 'http://mine.example.org');

        $r = AttendeeBot::checkConnection();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('https://', $r['message']);
        $this->assertStringContainsString('replay your API key', $r['message']);
    }

    public function test_the_check_reports_which_instance_it_is_pointed_at(): void
    {
        $r = AttendeeBot::checkConnection();
        $base = array_values(array_filter($r['checks'], fn ($c) => $c['name'] === 'Base URL'))[0];

        $this->assertStringContainsString(AttendeeBot::HOSTED_BASE, $base['detail']);
        $this->assertStringContainsString('nothing was configured', $base['detail'],
            'an operator who thinks they are self-hosted and is not finds out from a bill');
    }

    // ══ the settings map itself ══════════════════════════════════════════════

    /** Every setting the screen offers must be one `conf()` can actually resolve. */
    public function test_every_declared_setting_resolves(): void
    {
        foreach (AttendeeBot::SETTINGS as $key => $spec) {
            $this->assertNotSame('', $spec['env'], "{$key} has no env var name");
            $this->assertNotSame('', $spec['label'], "{$key} has no label");
            $this->assertArrayHasKey($key, AttendeeBot::configReport());
            // conf() must not throw for any declared key, whatever is or is not set.
            AttendeeBot::conf($key);
        }
        $this->assertTrue(true);
    }

    /** Exactly one of them is a credential, and the mask applies only to that one. */
    public function test_only_the_api_key_is_treated_as_a_secret(): void
    {
        $secrets = array_keys(array_filter(AttendeeBot::SETTINGS, fn ($s) => $s['secret']));

        $this->assertSame(['attendee_api_key'], $secrets);
    }
}
