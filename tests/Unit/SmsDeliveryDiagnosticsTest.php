<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SmsService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "AFRICA'S TALKING SMS IS NOT SENDING", AND NOTHING SAID WHY.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO FAULTS, AND THE SECOND IS THE ONE THAT COST THE TIME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. THE HOST WAS HARDCODED. Africa's Talking issues every account a sandbox alongside
 *    the live one, and the sandbox has its OWN endpoint. Sandbox credentials sent to the
 *    live host are rejected outright — so an operator who set the platform up with the
 *    sandbox key they were given first had SMS that never sent.
 *
 * 2. THE REFUSAL WAS RECORDED WHERE NOBODY LOOKED. Every failed send has gone into
 *    `gates_messages.error` since the service shipped, and the only reader was a generic
 *    table dump under Data. The settings screen said "active", named a provider, and had
 *    no way to tell anybody the gateway had been answering "Invalid sender id" for a week.
 *
 * That is the same shape as the door voice's silent failure, in the same session: written
 * correctly, into a row nothing displayed. It is this codebase's most expensive class of
 * bug and the house rule is to grep for a reader before believing a declaration.
 */
final class SmsDeliveryDiagnosticsTest extends TestCase
{
    // ══ the host ═════════════════════════════════════════════════════════════

    /**
     * A SANDBOX USERNAME GOES TO THE SANDBOX HOST.
     *
     * The username IS the switch, because that is how Africa's Talking itself decides — a
     * sandbox account is the literal username `sandbox`. Deriving it means there is no
     * second setting to get out of step with the first.
     */
    public function test_sandbox_credentials_go_to_the_sandbox_host(): void
    {
        $this->assertSame(SmsService::AT_SANDBOX, SmsService::atEndpoint('sandbox'));
        $this->assertSame(SmsService::AT_SANDBOX, SmsService::atEndpoint('  SANDBOX '),
            'a username typed with different case or spacing missed the sandbox host');
    }

    public function test_a_live_username_goes_to_the_live_host(): void
    {
        foreach (['africagates', 'afrovanguard', 'sandboxes', 'my-sandbox'] as $u) {
            $this->assertSame(SmsService::AT_LIVE, SmsService::atEndpoint($u),
                $u . ' was sent to the sandbox');
        }
    }

    /** And the two are actually different hosts, or the switch is decoration. */
    public function test_the_two_endpoints_are_not_the_same(): void
    {
        $this->assertNotSame(SmsService::AT_LIVE, SmsService::AT_SANDBOX);
        $this->assertStringContainsString('sandbox', SmsService::AT_SANDBOX);
        $this->assertStringNotContainsString('sandbox', SmsService::AT_LIVE);
    }

    // ══ the reader ═══════════════════════════════════════════════════════════

    private function logged(string $status, string $error, string $provider = 'africastalking'): void
    {
        DB::table('gates_messages')->insert([
            'channel' => 'sms', 'to_hash' => str_repeat('a', 64), 'to_masked' => '+234••••1234',
            'template' => 'generic', 'status' => $status, 'provider' => $provider,
            'error' => $error !== '' ? $error : null,
            'created_at' => '2026-09-04 06:00:00',
        ]);
    }

    public function test_the_last_refusal_is_readable(): void
    {
        $this->logged('failed', 'Invalid sender id');

        $f = SmsService::lastFailure();

        $this->assertNotNull($f, 'a failed send was recorded and nothing can read it back');
        $this->assertSame('Invalid sender id', $f['error']);
        $this->assertSame('africastalking', $f['provider']);
        // Masked at the point it was stored. A diagnostic must not put a supporter's
        // number on an admin screen to answer a question about a gateway.
        $this->assertSame('+234••••1234', $f['to']);
    }

    /** The LAST one, not the first — an operator is asking about the message just now. */
    public function test_it_is_the_most_recent_refusal(): void
    {
        $this->logged('failed', 'older problem');
        $this->logged('failed', 'the one that matters');

        $this->assertSame('the one that matters', SmsService::lastFailure()['error']);
    }

    /** A working gateway reports nothing, so the notice cannot become wallpaper. */
    public function test_a_delivered_message_is_not_a_failure(): void
    {
        $this->logged('sent', '');

        $this->assertNull(SmsService::lastFailure());
    }

    // ══ and it reaches a screen ══════════════════════════════════════════════

    /**
     * THE READER HAS A CALL SITE.
     *
     * `lastFailure()` with nothing rendering it would be the identical bug one layer up —
     * a record with no reader, replaced by a reader with no screen.
     */
    public function test_the_settings_screen_shows_it_and_names_the_real_provider(): void
    {
        $root = dirname(__DIR__, 2);

        $svc = (string) file_get_contents($root . '/src/Admin/Controllers/SettingsController.php');
        $this->assertStringContainsString('SmsService::boot()->status()', $svc,
            'the settings screen no longer asks the one status resolver');

        // COMMENTS STRIPPED. The note explaining the fix below quotes the string it
        // removed, so a scan that reads comments reports the fix as the fault — which is
        // exactly what happened on the first run of this assertion, and is now the sixth
        // time a scanner in this repository has been fooled by the comment describing the
        // bug it was written to find.
        $tpl = (string) preg_replace('~\{#.*?#\}~s', ' ',
            (string) file_get_contents($root . '/templates/admin/settings.twig'));
        $this->assertStringContainsString('sms_status.last_failure', $tpl,
            'the refusal is readable and no screen reads it');
        $this->assertStringContainsString('sandbox', $tpl,
            'nothing on the screen mentions the sandbox/live host mismatch, which is the '
            . 'first thing to check when Africa\'s Talking authenticates and never sends');

        // And the status line names the provider actually in use. It said "(Twilio)"
        // unconditionally — the wrong thing to tell somebody working out why their texts
        // are not arriving through Africa's Talking.
        $this->assertStringNotContainsString('active (Twilio)', $tpl,
            'the SMS status line hardcodes Twilio again');
    }

    /** `status()` carries it, so every caller of the one status resolver gets it. */
    public function test_the_status_resolver_carries_the_failure(): void
    {
        $this->logged('failed', 'Invalid sender id');

        $this->assertArrayHasKey('last_failure', SmsService::boot()->status());
        $this->assertSame('Invalid sender id',
            SmsService::boot()->status()['last_failure']['error']);
    }
}
