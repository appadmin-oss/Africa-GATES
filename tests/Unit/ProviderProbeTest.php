<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ProviderProbe;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A CHECK THAT SENDS SOMETHING IS A CHECK NOBODY PRESSES TWICE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS PAGE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every integration here could say whether a KEY WAS PRESENT and none could say whether
 * it WORKED. The gap between those two questions is the most expensive thing in this
 * codebase: the door voice was reported broken for days with a correct key, a correct
 * provider and a correct request, because the one screen built to test it asked a
 * different provider — and nothing anywhere would have said so.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE THIS FILE GUARDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every probe is a READ. A balance, an account, a model list, a token — never a send,
 * never a charge, never a synthesis. That is why the endpoints look arbitrary: Africa's
 * Talking is asked for its balance rather than to deliver a message, Paystack for its
 * bank list rather than to open a transaction, SMTP is greeted and hung up on.
 *
 * The tempting change later is "let me actually send one, to be sure" — which turns a
 * diagnostic into an SMS to a real phone every time somebody opens the page, on a live
 * system, possibly mid-event. `test_no_probe_can_send_charge_or_synthesise` is the line
 * against it, and it is asserted against the vendors' own send endpoints by name.
 */
final class ProviderProbeTest extends TestCase
{
    private static function source(): string
    {
        // Comments stripped: the notes explain what each probe deliberately does NOT do,
        // naming the send endpoints it avoids — which a naive scan reads as using them.
        return (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ProviderProbe.php'));
    }

    // ══ the rule ═════════════════════════════════════════════════════════════

    /**
     * NOTHING HERE COSTS MONEY, SENDS A MESSAGE, OR MAKES A SOUND.
     *
     * Named endpoints rather than a vague pattern, because the failure this prevents is
     * somebody swapping one URL for another that looks similar and does something else.
     */
    public function test_no_probe_can_send_charge_or_synthesise(): void
    {
        $src = self::source();

        $forbidden = [
            'version1/messaging'          => "Africa's Talking would send a real SMS",
            '/api/sms/send'               => 'Termii would send a real SMS',
            '/Messages.json'              => 'Twilio would send a real SMS',
            'transaction/initialize'      => 'Paystack would open a real transaction',
            '/v3/payments'                => 'Flutterwave would open a real payment',
            '/v1/audio/speech'            => 'OpenAI would synthesise and bill for audio',
            'text-to-speech/'             => 'ElevenLabs would synthesise and bill for audio',
            'cognitiveservices/v1'        => 'Azure would synthesise and bill for audio',
            '/v1/chat/completions'        => 'a chat completion would be billed',
            '/v1/messages'                => 'a completion would be billed',
        ];

        foreach ($forbidden as $needle => $what) {
            $this->assertStringNotContainsString($needle, $src,
                'A provider check now hits a paid or outbound endpoint: ' . $what
                . '. Every probe must be a read — this page is pressed repeatedly, on a '
                . 'live system, sometimes during an event.');
        }
    }

    /** SMTP is greeted and hung up on — it must never authenticate or send. */
    public function test_the_mail_check_stops_at_the_banner(): void
    {
        $src = self::source();
        $at  = (int) strpos($src, 'function smtp(');
        $this->assertGreaterThan(0, $at);
        $body = substr($src, $at, (int) strpos($src, 'function paystack(', $at) - $at);

        foreach (['MAIL FROM', 'RCPT TO', 'DATA', 'AUTH LOGIN', 'sendCustom', 'send('] as $verb) {
            $this->assertStringNotContainsString($verb, $body,
                'the mail check goes past the greeting (' . $verb . '), so opening this page '
                . 'sends mail');
        }
        $this->assertStringContainsString('fsockopen', $body);
        $this->assertStringContainsString('fclose', $body, 'the socket is left open');
    }

    // ══ the three answers ════════════════════════════════════════════════════

    /**
     * "NOT SET" IS NOT A FAILURE, AND THE DIFFERENCE IS THE WHOLE POINT.
     *
     * Most deployments use a handful of these. Reporting an absent credential as a fault
     * teaches an operator to ignore the colour, which is how the one row that matters —
     * configured, and refusing — stops being read.
     */
    public function test_an_absent_credential_is_reported_as_absent_not_as_broken(): void
    {
        $r = ProviderProbe::one('termii');

        $this->assertFalse($r['configured'], 'an unset provider is reported as configured');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase('no api key', $r['detail']);
        $this->assertSame(0, $r['ms'], 'an unset provider made a network call anyway');
    }

    /** A configured provider that refuses is a THIRD state, and says what it said. */
    public function test_the_scheduled_task_check_distinguishes_never_ran_from_stale(): void
    {
        $never = ProviderProbe::one('cron');
        $this->assertTrue($never['configured']);
        $this->assertFalse($never['ok']);
        $this->assertStringContainsString('never run', $never['detail'],
            'a platform whose maintenance has never run — the root cause of the silent '
            . 'door — is not reported as such');

        DB::table('gates_cron_log')->insert([
            'job_name' => 'maintenance', 'status' => 'success',
            'ran_at' => date('Y-m-d H:i:s', time() - 300),
        ]);
        $fresh = ProviderProbe::one('cron');
        $this->assertTrue($fresh['ok'], 'a run five minutes ago is being called stale');

        DB::table('gates_cron_log')->delete();
        DB::table('gates_cron_log')->insert([
            'job_name' => 'maintenance', 'status' => 'success',
            'ran_at' => date('Y-m-d H:i:s', time() - 86400),
        ]);
        $stale = ProviderProbe::one('cron');
        $this->assertFalse($stale['ok'], 'a day-old run is being reported as healthy');
        $this->assertStringContainsString('stopped', $stale['detail']);
    }

    // ══ no dead rows, no leaked secrets ══════════════════════════════════════

    /**
     * EVERY ROW ON THE PAGE HAS A PROBE BEHIND IT.
     *
     * A catalogue entry with no branch in `run()` renders a row that says "Unknown check"
     * forever — a declared field with no reader, which is this codebase's signature bug.
     */
    public function test_every_row_the_page_shows_can_actually_be_run(): void
    {
        foreach (ProviderProbe::catalogue() as $c) {
            $r = ProviderProbe::one($c['id']);
            $this->assertNotSame('Unknown check.', $r['detail'],
                $c['id'] . ' is on the page with no probe behind it');
            $this->assertArrayHasKey($c['group'], ProviderProbe::GROUPS,
                $c['id'] . ' is in a group the page does not render, so it is invisible');
        }
    }

    /**
     * A CREDENTIAL NEVER APPEARS IN THE ANSWER.
     *
     * The detail line carries the vendor's own words, and a vendor that echoes the key
     * back in an error would put it on an admin screen and into the audit log. Checked
     * with a key distinctive enough to find.
     */
    public function test_a_secret_is_never_echoed_into_the_result(): void
    {
        $secret = 'sk-PROBE-SECRET-abcdef0123456789';
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'ai_elevenlabs_key'], ['value' => $secret]);

        $r = ProviderProbe::one('elevenlabs');

        $this->assertStringNotContainsString($secret, $r['detail'],
            'the provider echoed the key back and it was printed on an admin screen');
        $this->assertStringNotContainsString('PROBE-SECRET', json_encode($r) ?: '');
    }

    // ══ the page ═════════════════════════════════════════════════════════════

    /** The admin CSP has no 'unsafe-inline', so an onclick= here is a dead button. */
    public function test_the_page_uses_delegated_handlers_not_inline_ones(): void
    {
        // Twig AND JavaScript comments stripped. The line explaining why there is no
        // onclick= contains the string `onclick=`, so a raw scan reports the note as the
        // fault it is warning about — the seventh time in this repository.
        $twig = (string) preg_replace(['~\{#.*?#\}~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/providers.twig'));

        $this->assertStringNotContainsString('onclick=', $twig,
            'the admin CSP has no unsafe-inline, so this button does nothing at all');
        $this->assertStringContainsString('data-ag-do="pv-all"', $twig);
        $this->assertStringContainsString('nonce="{{ csp_nonce }}"',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/providers.twig'),
            'the script block has no nonce, so the CSP drops it silently');
    }

    /**
     * THE TEMPLATE ACTUALLY COMPILES.
     *
     * Every other assertion in this file reads the template as TEXT, and text cannot tell
     * you that `{% for c in catalogue if … %}` is a syntax error in this Twig — which it
     * is, and which shipped past all of them. A page that throws on render is a page that
     * is not there, and this file is about not being able to see that.
     */
    public function test_the_page_compiles(): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ChainLoader([
            new \Twig\Loader\ArrayLoader(['admin/layout.twig' => '{% block content %}{% endblock %}']),
            new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]));
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $out = $twig->render('admin/providers.twig', [
            'catalogue' => ProviderProbe::catalogue(),
            'groups'    => ProviderProbe::GROUPS,
            'msg'       => ['sms' => true, 'sms_provider' => 'africastalking',
                            'whatsapp' => false, 'wa_provider' => null],
            'msg_left'  => 6,
            'msg_cap'   => \AfricaGates\Services\MessageSendTest::PER_HOUR,
        ]);

        // Every catalogue entry reaches the page. A group whose rows silently vanish is
        // the same invisibility this whole page exists to end.
        foreach (ProviderProbe::catalogue() as $c) {
            $this->assertStringContainsString('data-pv-row="' . $c['id'] . '"', $out,
                $c['id'] . ' is in the catalogue and not on the page');
        }
    }

    /**
     * AND IT RUNS THEM ONE AT A TIME.
     *
     * Sixteen probes at eight seconds each is two minutes of one held-open request, which
     * a proxy cuts in the middle — leaving a blank screen and no clue which provider hung.
     */
    public function test_the_page_runs_the_checks_in_series(): void
    {
        $twig = (string) preg_replace('~\{#.*?#\}~s', ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/providers.twig'));

        $this->assertStringContainsString('reduce(', $twig,
            'the page fires every probe at once, so one slow vendor decides the whole page');
        $this->assertStringContainsString('Promise.resolve()', $twig);
    }
}
