<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Console\Commands\DoctorCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The diagnostic that answers "is the code I edited the code that is running?"
 *
 * The incident it was written for: a production console showed eighteen CDN
 * resources refused and the paid-vote form blocked, by a CSP that had already been
 * fixed in the repository. Editing `Csp::policy()` on the server — deliberately
 * breaking its syntax, to force the question — changed nothing: `curl -I` still
 * returned the old header, with a 200 and no fatal. A syntax error in a file PHP
 * loads cannot produce that, so the file was not being loaded, so the running code
 * was not the edited code. Hours can go into reading a policy that is not deployed.
 *
 * A diagnostic that reports the wrong thing confidently is worse than none, so these
 * tests check the two properties that matter: it reads the LIVE runtime rather than
 * repeating assumptions, and it is capable of saying "broken" — most of its checks
 * are all-clear on a healthy tree, which is exactly the condition under which a
 * detector silently rots.
 */
class DoctorCommandTest extends TestCase
{
    private function doctor(array $args = []): array
    {
        $app = new Application();
        $app->add(new DoctorCommand());
        $tester = new CommandTester($app->find('app:doctor'));
        $tester->execute($args + ['--json' => true]);

        return [
            'exit' => $tester->getStatusCode(),
            'json' => json_decode($tester->getDisplay(), true) ?? [],
        ];
    }

    public function test_it_reports_the_live_policy_not_a_remembered_one(): void
    {
        // The whole value proposition. This string is directly comparable with what
        // `curl -I` returns from the running site: if they differ, the web SAPI and
        // the CLI are running different code, and that IS the answer.
        $r = $this->doctor();

        $this->assertSame(\AfricaGates\Support\Csp::policy(), $r['json']['csp']['policy'] ?? null,
            'the reported policy must be generated now, from the deployed class');
    }

    public function test_it_confirms_the_three_facts_the_incident_turned_on(): void
    {
        $csp = $this->doctor()['json']['csp'];

        $this->assertSame('yes', $csp['script_src_has_nonce']);
        $this->assertSame('yes', $csp['script_src_has_cdns'],
            'no CDN hosts in script-src is what refused all eighteen resources');
        $this->assertSame('yes', $csp['form_action_has_gateways'],
            'Chrome applies form-action to the redirect a submission lands on, and '
            . 'POST /vote/paid/start 302s to the gateway — without the gateways every '
            . 'paid vote is blocked after the pending order is already written');
        $this->assertSame('yes', $csp['style_src_elem_present']);
    }

    public function test_it_names_the_classes_whose_absence_explains_a_stale_deploy(): void
    {
        $code = $this->doctor()['json']['code'];

        $this->assertSame('loaded', $code['Csp_class']);
        $this->assertSame('loaded', $code['Env_class']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $code['src_fingerprint'],
            'a content hash over src/ cannot go stale — if it does not change after a '
            . 'deploy, the deploy did not land');
        $this->assertGreaterThan(50, (int) $code['src_files']);
    }

    public function test_the_fingerprint_changes_when_the_source_changes(): void
    {
        // Without this the fingerprint is decoration. Proven by writing a file into
        // src/ and removing it again, because the hash is over file CONTENT and a
        // claim about content has to be tested with content.
        $probe = dirname(__DIR__, 2) . '/src/__doctor_fingerprint_probe.php';
        $before = $this->doctor()['json']['code']['src_fingerprint'];
        try {
            file_put_contents($probe, "<?php\n// transient\n");
            $after = $this->doctor()['json']['code']['src_fingerprint'];
        } finally {
            @unlink($probe);
        }

        $this->assertNotSame($before, $after);
        $this->assertSame($before, $this->doctor()['json']['code']['src_fingerprint'],
            'and it returns to the original once the probe is gone');
    }

    public function test_it_reports_which_source_each_setting_came_from(): void
    {
        // §18: $_ENV is not populated from the process environment under
        // variables_order=GPCS, so "I set DB_PASS and it was ignored" is a real and
        // previously undiagnosable state. Naming the SOURCE is what makes it visible.
        $_SERVER['AFG_DOCTOR_PROBE_URL'] = 'x';
        try {
            $config = $this->doctor()['json']['config'];
        } finally {
            unset($_SERVER['AFG_DOCTOR_PROBE_URL']);
        }

        $this->assertArrayHasKey('variables_order', $config);
        $this->assertArrayHasKey('DB_PASS', $config);
        foreach (['APP_URL', 'DB_NAME', 'TRUST_PROXY', 'PAYSTACK_SECRET_KEY'] as $key) {
            $this->assertContains($config[$key], ['.env file', 'environment', 'NOT SET (default in use)'],
                "{$key} must be attributed to a source");
        }
    }

    public function test_no_secret_value_is_ever_printed(): void
    {
        // It exists to be pasted into a chat or an issue. A diagnostic that leaks the
        // Paystack secret key is a worse problem than the one it diagnoses.
        $_ENV['PAYSTACK_SECRET_KEY']      = 'sk_live_DOCTOR_MUST_NOT_PRINT_THIS';
        $_ENV['DB_PASS']                  = 'DOCTOR_MUST_NOT_PRINT_THIS_EITHER';
        $_ENV['FLUTTERWAVE_WEBHOOK_HASH'] = 'FLW_DOCTOR_MUST_NOT_PRINT';
        try {
            $app = new Application();
            $app->add(new DoctorCommand());
            $tester = new CommandTester($app->find('app:doctor'));
            $tester->execute(['--json' => true]);
            $out = $tester->getDisplay();
        } finally {
            unset($_ENV['PAYSTACK_SECRET_KEY'], $_ENV['DB_PASS'], $_ENV['FLUTTERWAVE_WEBHOOK_HASH']);
        }

        $this->assertStringNotContainsString('sk_live_DOCTOR_MUST_NOT_PRINT_THIS', $out);
        $this->assertStringNotContainsString('DOCTOR_MUST_NOT_PRINT_THIS_EITHER', $out);
        $this->assertStringNotContainsString('FLW_DOCTOR_MUST_NOT_PRINT', $out);
        // …but their presence IS reported, which is the useful half.
        $this->assertStringContainsString('PAYSTACK_SECRET_KEY', $out);
    }

    public function test_it_reports_whether_an_edit_would_even_be_picked_up(): void
    {
        // opcache.validate_timestamps=0 is the other explanation for "I changed the
        // file and nothing happened", and it is fixed completely differently from a
        // failed deploy. Reporting it is how the two stop being confused.
        $opcache = $this->doctor()['json']['opcache'];

        $this->assertArrayHasKey('enabled', $opcache);
        if (isset($opcache['validate_timestamps'])) {
            $this->assertMatchesRegularExpression(
                '/(edits are picked up|EDITS ARE NOT PICKED UP)/',
                (string) $opcache['validate_timestamps'],
                'the value must be interpreted, not just printed — "0" means nothing to a reader'
            );
        }
    }

    public function test_it_reports_the_database_it_is_actually_connected_to(): void
    {
        // "Which database is live" is the same class of question as "which code is
        // live", and just as easy to be wrong about across environments.
        $db = $this->doctor()['json']['database'];

        $this->assertContains($db['driver'], ['mysql', 'sqlite'], 'driver must be reported');
        $this->assertArrayHasKey('tables', $db);
        $this->assertGreaterThan(0, (int) $db['tables']);
    }

    public function test_a_healthy_tree_exits_zero_and_is_machine_readable(): void
    {
        // Usable from a deploy script or a cron guard, not only by eye.
        $r = $this->doctor();

        $this->assertIsArray($r['json']);
        $this->assertArrayHasKey('problems', $r['json']);
        $this->assertSame($r['exit'] === 0, $r['json']['problems'] === [],
            'the exit code and the problems list must agree');
    }

    public function test_it_flags_debug_enabled_in_production(): void
    {
        // The detector's negative control: something must actually trip it, or every
        // green run above means nothing.
        $_ENV['APP_ENV']   = 'production';
        $_ENV['APP_DEBUG'] = 'true';
        try {
            $r = $this->doctor();
        } finally {
            unset($_ENV['APP_DEBUG']);
            $_ENV['APP_ENV'] = 'testing';
        }

        $this->assertNotSame(0, $r['exit'], 'a real problem must fail the command');
        $this->assertNotEmpty(array_filter(
            $r['json']['problems'],
            static fn (string $p) => str_contains($p, 'APP_DEBUG')
        ), 'APP_DEBUG=true in production must be reported: ' . json_encode($r['json']['problems']));
    }
}
