<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CloudinaryService;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Anything operational can be set from /admin/settings.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE, AND WHY IT KEEPS NEEDING A TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no SSH on production. So a value read only from `.env` is not "configured
 * in the environment" — it is a value that cannot be set at all, by anybody, ever.
 *
 * `GAS_URL` and `GAS_SECRET` were the first: the whole Google Calendar and Meet
 * integration sat dead while every screen explained itself correctly and told the
 * operator to edit a file they cannot open. Two more were still doing it afterwards,
 * on screens that had the lesson written on them:
 *
 *   SMTP       `CheckoutMailer` grew a settings-aware $pick() and used it for the
 *              from-name while host, username and password stayed on Env::get. The
 *              settings card printed "SMTP not set — mail is written to
 *              var/logs/outgoing-mail.log" directly above a form with no field that
 *              could set it. OTP codes gate voting, so that is the ballot.
 *
 *   Cloudinary  no settings lookup at all, and the Media panel's remedy read "add
 *              CLOUDINARY_URL=cloudinary://key:secret@cloud to .env".
 *
 * Six places built the SMTP array by hand and two of them had already drifted, which
 * is the other half of the rule: ONE resolver per value. Two readers of one setting is
 * how the halves of an integration come to disagree about whether it is configured.
 *
 * So this file holds three things: the resolvers prefer settings, nothing builds its
 * own copy, and the form that sets them actually exists — a resolver with no field in
 * front of it is the same bug wearing a different hat.
 */
final class OperationalCredentialsTest extends TestCase
{
    private const ENV_KEYS = [
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS',
        'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_REPLY_TO',
        'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET',
        'CLOUDINARY_URL', 'CLOUDINARY_FOLDER',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // A host .env leaking in would decide these tests for them.
        foreach (self::ENV_KEYS as $k) unset($_ENV[$k]);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $k) unset($_ENV[$k]);
        parent::tearDown();
    }

    /** The resolved transport array, which is private because callers must not build one. */
    private function smtp(): array
    {
        $p = new \ReflectionProperty(OtpService::class, 'smtp');
        $p->setAccessible(true);

        return (array) $p->getValue(OtpService::boot());
    }

    private function set(string $key, string $value): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $key], ['value' => $value]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  SMTP
    // ════════════════════════════════════════════════════════════════════════

    public function test_smtp_is_settable_from_the_admin_and_beats_the_environment(): void
    {
        $_ENV['SMTP_HOST'] = 'env.example.com';
        $_ENV['SMTP_USER'] = 'env-user';
        $_ENV['SMTP_PASS'] = 'env-pass';
        $_ENV['SMTP_PORT'] = '2525';

        $this->set('mail_smtp_host', 'smtp.settings.example');
        $this->set('mail_smtp_user', 'settings-user');
        $this->set('mail_smtp_pass', 'settings-pass');
        $this->set('mail_smtp_port', '465');

        $smtp = $this->smtp();

        $this->assertSame('smtp.settings.example', $smtp['host']);
        $this->assertSame('settings-user', $smtp['username']);
        $this->assertSame('settings-pass', $smtp['password'],
            'the password is the value that could not be set at all — it must win');
        $this->assertSame(465, $smtp['port'], 'the port must arrive as an int, not a settings string');
    }

    public function test_the_environment_still_works_when_nothing_is_stored(): void
    {
        $_ENV['SMTP_HOST'] = 'env.example.com';
        $_ENV['SMTP_USER'] = 'env-user';
        $_ENV['SMTP_PASS'] = 'env-pass';

        $smtp = $this->smtp();

        $this->assertSame('env.example.com', $smtp['host'], '.env is the FALLBACK, not the casualty');
        $this->assertSame('env-user', $smtp['username']);
        $this->assertTrue(OtpService::boot()->smtpConfigured());
    }

    /**
     * With neither source set, the port must still be a usable int rather than 0.
     * `(int) ''` is 0, and a PHPMailer told to dial port 0 fails in a way that reads
     * as the mail server refusing rather than as a missing default.
     */
    public function test_the_port_defaults_rather_than_collapsing_to_zero(): void
    {
        $this->assertSame(587, $this->smtp()['port']);

        $this->set('mail_smtp_port', '');
        $this->assertSame(587, $this->smtp()['port'], 'a blank setting must not become port 0');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Cloudinary
    // ════════════════════════════════════════════════════════════════════════

    public function test_cloudinary_is_settable_from_the_admin_and_beats_the_environment(): void
    {
        $_ENV['CLOUDINARY_CLOUD_NAME'] = 'env-cloud';
        $_ENV['CLOUDINARY_API_KEY']    = 'env-key';
        $_ENV['CLOUDINARY_API_SECRET'] = 'env-secret';

        $this->set('cloudinary_cloud_name', 'settings-cloud');
        $this->set('cloudinary_api_key',    'settings-key');
        $this->set('cloudinary_api_secret', 'settings-secret');

        $this->assertSame(
            ['cloud' => 'settings-cloud', 'key' => 'settings-key', 'secret' => 'settings-secret'],
            CloudinaryService::config()
        );
        $this->assertTrue(CloudinaryService::enabled(),
            'the Media panel reads this light — it must go green from the settings form');
    }

    /** The single pasted URL is what Cloudinary's own dashboard hands you. */
    public function test_the_pasted_cloudinary_url_is_accepted_from_settings(): void
    {
        $this->set('cloudinary_url', 'cloudinary://12345:s3cr3t@my-cloud');

        $this->assertSame(
            ['cloud' => 'my-cloud', 'key' => '12345', 'secret' => 's3cr3t'],
            CloudinaryService::config()
        );
    }

    public function test_the_cloudinary_folder_is_settable_and_still_defaults(): void
    {
        $this->assertSame('africa-gates', CloudinaryService::rootFolder());

        $this->set('cloudinary_folder', '/second-site/');
        $this->assertSame('second-site', CloudinaryService::rootFolder());
    }

    // ════════════════════════════════════════════════════════════════════════
    //  One resolver, and a form in front of it
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Six places built this array by hand. Two had already drifted apart. A seventh is
     * how the next one drifts, so the count is asserted rather than the behaviour.
     */
    public function test_nothing_builds_its_own_smtp_configuration(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            if (str_ends_with($file, 'src/Services/OtpService.php')) continue;   // the resolver itself
            if (str_ends_with($file, 'src/Console/Commands/DoctorCommand.php')) continue; // reports env sources, by design
            // Tokenised, not grepped: the comments in these files legitimately quote the
            // old `Env::get('SMTP_…')` calls to record what was removed and why, and a
            // scanner that cannot tell code from prose punishes the explanation.
            $body = '';
            foreach (token_get_all((string) file_get_contents($file)) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $body .= is_array($t) ? $t[1] : $t;
            }
            if (preg_match("~Env::(get|int|has)\(\s*'SMTP_~", $body) === 1) {
                $offenders[] = substr($file, strlen(dirname(__DIR__, 2)) + 1);
            }
        }

        $this->assertSame([], $offenders,
            'these read SMTP config directly instead of OtpService::boot() — see the note on boot()');
    }

    /**
     * A resolver nothing can reach is the same bug in a different place. Asserted
     * against the template because the field NAME is the contract between the form and
     * the resolver: rename one without the other and the form silently stops working.
     */
    public function test_the_settings_form_can_set_every_one_of_them(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        foreach (['mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user', 'mail_smtp_pass',
                  'cloudinary_cloud_name', 'cloudinary_api_key', 'cloudinary_api_secret',
                  'cloudinary_url', 'cloudinary_folder'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $form,
                "$field is resolvable but there is no field that sets it");
        }
    }

    /** And the secrets are write-only: never rendered back into the page source. */
    public function test_no_secret_is_echoed_back_into_the_page(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        foreach (['mail_smtp_pass', 'cloudinary_api_secret', 'cloudinary_url'] as $secret) {
            $this->assertStringNotContainsString('values.' . $secret, $form,
                "$secret is rendered back to the page — a stored credential in every settings render");
        }
    }

    /**
     * The generalisation of the whole finding: no admin screen may answer "not
     * configured" with an instruction only a shell can carry out.
     */
    public function test_no_admin_screen_tells_the_operator_to_edit_env(): void
    {
        $offenders = [];
        foreach ($this->adminTemplates() as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (str_contains($line, '{#') || str_starts_with(ltrim($line), '#')) continue; // Twig comments record the history
                if (preg_match('~(add|set|put|edit).{0,40}<code>\.env</code>~i', $line) === 1
                 || preg_match('~[A-Z_]{4,}=.{0,60}\.env~', $line) === 1) {
                    $offenders[] = substr($file, strlen(dirname(__DIR__, 2)) + 1) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders,
            'an admin screen prescribes editing .env — there is no shell on production');
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $out = [];
        foreach ([dirname(__DIR__, 2) . '/src', dirname(__DIR__, 2) . '/config'] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function adminTemplates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates/admin')
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'twig') $out[] = $f->getPathname();
        }
        return $out;
    }
}
