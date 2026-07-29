<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Support\Clock;
use AfricaGates\Support\Csp;
use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Answer, on the server, "is the code I edited the code that is running?"
 *
 * WHY THIS EXISTS. A production console report showed eighteen CDN resources refused
 * by a CSP that had no host allowlist, and the paid-vote form blocked by a
 * `form-action` with no payment gateways. The fix was already committed. Someone then
 * edited `Csp::policy()` on the server — introducing a deliberate syntax error, to
 * force the question — and `curl -I` still returned the OLD header, with a 200 and no
 * fatal. A syntax error in a loaded file cannot do that. The file was not being
 * loaded, which meant the running code was not the edited code, and every further
 * minute spent reading the policy was spent reading the wrong copy.
 *
 * That question is unanswerable from a browser and awkward from a shell, so it gets a
 * command. Everything reported here is read from the SAME runtime that serves
 * requests, and every check names what a wrong answer would mean.
 *
 * Read-only. It connects to the database and reads the filesystem; it writes nothing.
 *
 *   php bin/console app:doctor
 *   php bin/console app:doctor --json
 */
#[AsCommand(name: 'app:doctor', description: 'Report what code and configuration are actually live')]
final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = [
            'runtime'  => $this->runtime(),
            'code'     => $this->code(),
            'opcache'  => $this->opcache(),
            'config'   => $this->config(),
            'database' => $this->database(),
            'csp'      => $this->csp(),
        ];
        $problems = $this->problems($report);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                $report + ['problems' => $problems],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
            return $problems === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Africa GATES — what is actually live');

        foreach ($report as $section => $rows) {
            $io->section($section);
            $io->table([], array_map(
                static fn ($k, $v) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v],
                array_keys($rows),
                array_values($rows)
            ));
        }

        if ($problems === []) {
            $io->success('No problems found.');
            return Command::SUCCESS;
        }
        foreach ($problems as $p) {
            $io->warning($p);
        }
        return Command::FAILURE;
    }

    private function runtime(): array
    {
        return [
            'php_version'     => PHP_VERSION,
            'sapi'            => PHP_SAPI,
            'app_env'         => (string) Env::get('APP_ENV', 'production'),
            'app_debug'       => Env::bool('APP_DEBUG') ? 'true' : 'false',
            'app_timezone'    => Clock::timezone(),
            'now'             => \Illuminate\Support\Carbon::now()->toDateTimeString(),
            // A CLI/web timezone disagreement makes cron and the site disagree about
            // whether voting is open, permanently and with no error anywhere.
            'ini_date.timezone' => (string) (ini_get('date.timezone') ?: '(unset)'),
        ];
    }

    /**
     * Identity of the deployed tree.
     *
     * A content hash over the PHP sources is the only identifier that cannot be
     * stale: it is computed from the bytes on disk right now, so if it does not change
     * after a deploy, the deploy did not land. Git metadata is reported when present
     * but is not relied on — plenty of deploys are an upload, not a checkout.
     */
    private function code(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        $h = hash_init('sha256');
        $newest = 0;
        foreach ($files as $f) {
            hash_update($h, (string) file_get_contents($f));
            $newest = max($newest, (int) filemtime($f));
        }
        // Config and templates change behaviour too, so they are part of the identity.
        foreach (['config/container.php', 'config/database.php', 'src/routes.php'] as $extra) {
            if (is_file($root . '/' . $extra)) {
                hash_update($h, (string) file_get_contents($root . '/' . $extra));
            }
        }

        $head = @file_get_contents($root . '/.git/HEAD');
        $ref  = '(no .git — deployed as files)';
        if (is_string($head) && str_starts_with(trim($head), 'ref: ')) {
            $branch = trim(substr(trim($head), 5));
            $sha = @file_get_contents($root . '/.git/' . $branch);
            $ref = $branch . ' @ ' . (is_string($sha) ? substr(trim($sha), 0, 12) : '?');
        } elseif (is_string($head) && trim($head) !== '') {
            $ref = 'detached @ ' . substr(trim($head), 0, 12);
        }

        return [
            'src_files'        => count($files),
            'src_fingerprint'  => substr(hash_final($h), 0, 16),
            'newest_src_mtime' => $newest > 0 ? date('Y-m-d H:i:s', $newest) : '(none)',
            'git'              => $ref,
            // The specific classes whose absence produced the incident above. A file
            // that is not there cannot be edited into working.
            'Csp_class'        => class_exists(Csp::class) ? 'loaded' : 'MISSING',
            'Env_class'        => class_exists(Env::class) ? 'loaded' : 'MISSING',
        ];
    }

    /**
     * The other way an edit fails to take effect.
     *
     * With `opcache.validate_timestamps=0` — a common production setting — PHP never
     * re-reads a changed file, so an edit (or a syntax error) has NO observable effect
     * until the pool is reloaded or the cache is flushed. That looks exactly like a
     * failed deploy and is fixed completely differently.
     */
    private function opcache(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['enabled' => 'no (extension absent)'];
        }
        $status = @opcache_get_status(false);
        $validate = (string) (ini_get('opcache.validate_timestamps') ?: '0');

        return [
            'enabled'             => is_array($status) && ($status['opcache_enabled'] ?? false) ? 'yes' : 'no',
            'validate_timestamps' => $validate === '1' ? '1 (edits are picked up)' : $validate . ' (EDITS ARE NOT PICKED UP until reload)',
            'revalidate_freq'     => (string) (ini_get('opcache.revalidate_freq') ?: '0') . 's',
            'cached_scripts'      => (string) (is_array($status) ? ($status['opcache_statistics']['num_cached_scripts'] ?? 0) : 0),
        ];
    }

    /**
     * Whether configuration is actually visible — the §18 defect, checked at runtime.
     *
     * `$_ENV` is not populated from the process environment under the default
     * `variables_order=GPCS`, so this reports which SOURCE each key was found in.
     * "not set" for a key the operator believes they set is the whole answer.
     */
    private function config(): array
    {
        $where = static function (string $key): string {
            if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '')       return '.env file';
            if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') return 'environment';
            if (is_string(getenv($key)) && trim((string) getenv($key)) !== '') return 'environment';
            return 'NOT SET (default in use)';
        };
        // Secrets are reported as present/absent and by source only. Never printed.
        $out = [
            'variables_order' => (string) (ini_get('variables_order') ?: '?'),
            'dotenv_present'  => is_file(dirname(__DIR__, 3) . '/.env') ? 'yes' : 'no',
        ];
        foreach (['APP_URL', 'APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
                  'TRUST_PROXY', 'SESSION_SECURE', 'CRON_TOKEN', 'SETUP_TOKEN',
                  'PAYSTACK_SECRET_KEY', 'FLUTTERWAVE_WEBHOOK_HASH',
                  'TURNSTILE_SECRET', 'SMTP_HOST', 'SMTP_PASS'] as $key) {
            $out[$key] = $where($key);
        }
        return $out;
    }

    private function database(): array
    {
        try {
            $conn   = DB::connection();
            $driver = $conn->getDriverName();
            $name   = (string) $conn->getDatabaseName();
            $tables = (int) ($conn->selectOne(
                $driver === 'mysql'
                    ? 'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
                    : "SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table'"
            )->n ?? 0);
            $out = ['driver' => $driver, 'database' => $name, 'tables' => (string) $tables];

            if ($driver === 'mysql') {
                // A session timezone that is not the app's makes every TIMESTAMP read
                // back shifted, which silently moves voting deadlines.
                $out['session_time_zone'] = (string) ($conn->selectOne('SELECT @@session.time_zone AS tz')->tz ?? '?');
                $out['expected_offset']   = Clock::databaseTimezone();
            }
            // Migrations that never ran are the other way live behaviour diverges from
            // the code that is deployed.
            try {
                $out['migrations_applied'] = (string) DB::table('gates_migrations')->count();
            } catch (\Throwable) {
                $out['migrations_applied'] = '(ledger table absent)';
            }
            return $out;
        } catch (\Throwable $e) {
            return ['driver' => '(cannot connect)', 'error' => $e->getMessage()];
        }
    }

    /**
     * The live policy, and the three facts the incident turned on.
     *
     * Printing the whole header is the point: it is directly comparable with what
     * `curl -I` returns from the running site. If they differ, the web SAPI and the
     * CLI are running different code — which is itself the answer.
     */
    private function csp(): array
    {
        if (!class_exists(Csp::class)) {
            return ['policy' => '(Csp class not present in this deployment)'];
        }
        $policy = Csp::policy();
        $has = static fn(string $needle): string => str_contains($policy, $needle) ? 'yes' : 'NO';

        return [
            'script_src_has_nonce'   => str_contains($policy, "script-src 'self' 'nonce-") ? 'yes' : 'NO',
            'script_src_has_cdns'    => $has('https://cdn.jsdelivr.net'),
            'style_src_elem_present' => $has('style-src-elem'),
            'form_action_has_gateways' => $has('paystack'),
            'policy'                 => $policy,
        ];
    }

    /** @return list<string> */
    private function problems(array $r): array
    {
        $p = [];
        if (($r['code']['Csp_class'] ?? '') !== 'loaded') {
            $p[] = 'Support\\Csp is not present in this deployment — the running code predates the CSP rebuild.';
        }
        if (($r['code']['Env_class'] ?? '') !== 'loaded') {
            $p[] = 'Support\\Env is not present — configuration supplied as environment variables is being ignored.';
        }
        if (str_contains((string) ($r['opcache']['validate_timestamps'] ?? ''), 'NOT PICKED UP')) {
            $p[] = 'opcache.validate_timestamps is off: edits to PHP files have no effect until the '
                 . 'PHP-FPM pool is reloaded or the opcode cache is flushed. An edit that appears to '
                 . 'do nothing — including a syntax error — is explained by this alone.';
        }
        if (($r['csp']['form_action_has_gateways'] ?? '') === 'NO') {
            $p[] = 'form-action does not list the payment gateways. Chrome applies form-action to the '
                 . 'redirect a submission lands on, and POST /vote/paid/start 302s to the gateway, so '
                 . 'every paid vote is blocked in the browser after the pending order is written.';
        }
        if (($r['csp']['script_src_has_cdns'] ?? '') === 'NO') {
            $p[] = 'script-src lists no CDN hosts, so every third-party script on the page is refused.';
        }
        if (str_contains((string) ($r['config']['DB_NAME'] ?? ''), 'NOT SET')
            && ($r['config']['dotenv_present'] ?? 'no') === 'no') {
            $p[] = 'No .env file and no DB_NAME in the environment — the database name is a hardcoded default.';
        }
        if (($r['runtime']['app_env'] ?? '') !== 'production' && PHP_SAPI !== 'cli') {
            $p[] = 'APP_ENV is not production on a served request.';
        }
        if (($r['runtime']['app_debug'] ?? '') === 'true' && ($r['runtime']['app_env'] ?? '') === 'production') {
            $p[] = 'APP_DEBUG is true in production.';
        }
        if (isset($r['database']['session_time_zone'], $r['database']['expected_offset'])
            && $r['database']['session_time_zone'] !== $r['database']['expected_offset']
            && $r['database']['session_time_zone'] !== 'SYSTEM') {
            $p[] = 'The MySQL session timezone (' . $r['database']['session_time_zone'] . ') is not the '
                 . 'app offset (' . $r['database']['expected_offset'] . '); TIMESTAMP columns read back shifted.';
        }
        return $p;
    }
}
