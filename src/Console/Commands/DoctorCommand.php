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
            'media'    => $this->media(),
            'assets'   => $this->assets(),
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
            // The flier's og:image is rendered by GD from bundled TrueType files. Without
            // them the PNG route redirects to the SVG — which no chat app previews — so
            // link previews silently stop working and nothing else reports it.
            'gd'               => function_exists('imagettftext') ? 'with FreeType' : 'MISSING',
            'flier_fonts'      => $this->flierFonts(),
        ];
    }

    /** Bundled-font state for the flier renderer. */
    private function flierFonts(): string
    {
        if (!class_exists(\AfricaGates\Services\FlierService::class)) return '(FlierService absent)';
        $f = \AfricaGates\Services\FlierService::fontsPresent();
        return $f['ok'] ? 'all present' : 'MISSING: ' . implode(', ', $f['missing']);
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

    /**
     * Where images are stored, and how much of the old estate has moved.
     *
     * Worth a section of its own because both halves fail QUIETLY. Cloudinary
     * credentials that are absent (or misspelled — `CLOUDINARY_API_SECRET` vs
     * `..._SECRET_KEY` is the obvious slip) do not break anything: uploads keep landing
     * on local disk, exactly as they did before, so an operator who believes they
     * switched the CDN on has no signal that they did not. And a bulk migration that
     * stopped halfway leaves a database serving some photos from a CDN and some from
     * disk with no visible difference until the disk is the one that goes away.
     */
    private function media(): array
    {
        $out = ['cloudinary' => \AfricaGates\Services\CloudinaryService::enabled() ? 'configured' : 'not configured (storing locally)'];
        if (\AfricaGates\Services\CloudinaryService::enabled()) {
            $out['cloudinary_cloud']  = \AfricaGates\Services\CloudinaryService::cloudName();
            $out['cloudinary_folder'] = \AfricaGates\Services\CloudinaryService::rootFolder();
        }
        try {
            $s = (new \AfricaGates\Services\MediaMigrationService())->status();
            $out['local_images_referenced'] = (string) $s['total']
                . ($s['total'] > 0 ? ' (run: bin/console media:cloudinary)' : '');
            $out['migrated']   = (string) $s['migrated'];
            if ($s['missing'] > 0) $out['missing_on_disk'] = (string) $s['missing'] . ' — referenced but not present';
            if ($s['failed'] > 0)  $out['failed_uploads']  = (string) $s['failed'];
        } catch (\Throwable $e) {
            $out['local_images_referenced'] = 'unknown (' . $e->getMessage() . ')';
        }
        return $out;
    }

    /**
     * Is the CSS bundle current?
     *
     * Here because it is the one performance setting that is invisible from the outside
     * and reverts silently. `public/assets/dist/` is gitignored build output, so a fresh
     * deploy has no bundle at all and the site serves fifteen render-blocking
     * stylesheets — measured at ~2.4s of blocking requests on a mid-range Android. It
     * still WORKS, which is exactly why nobody notices.
     */
    private function assets(): array
    {
        $url = \AfricaGates\Support\AssetBundle::url();
        $count = count(\AfricaGates\Support\AssetBundle::STYLESHEETS);

        return [
            'css_bundle' => $url ?? 'NOT BUILT — serving ' . $count . ' separate stylesheets (run: bin/console assets:build)',
            'css_files_bundled' => $url !== null ? (string) $count : '0',
        ];
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
            // Deliberately LAST and deliberately over the network: everything above
            // describes this code, and only this describes the running server.
        ] + $this->liveCsp();
    }

    /**
     * THE CHECK THAT ANSWERS THE QUESTION THAT KEEPS COSTING DAYS.
     *
     * Fetches APP_URL and compares the `Content-Security-Policy` header the site
     * actually returns against the one {@see Csp::policy()} produces in this process.
     *
     * Everything above reports what the code WOULD do. This is the only check that
     * reports what the server IS doing, and it is the one that matters: production has
     * twice been serving code that predates this repository. The proof, from
     * docs/VOTING-NOMINATIONS-STATE-AUDIT.md, is that a deliberate syntax error planted
     * in `Csp::policy()` on the server changed nothing — the site kept returning 200
     * with the old header, so PHP was not loading the file at all.
     *
     * Every CSP refusal reported from production since is that same problem: CDN
     * stylesheets and scripts blocked because the running `script-src`/`style-src` carry
     * no host allowlist, and EVERY PAID VOTE refused because the running `form-action`
     * has no gateway hosts — after the pending order row already exists. All fixed here.
     * None of it deployed.
     *
     * A mismatch has three plausible causes and the message names all three, because
     * they are fixed completely differently:
     *   1. DocumentRoot still pointing at an older copy of the app (the best fit for the
     *      observed header, which carries a `permissions-policy` no version here emits);
     *   2. opcache with validate_timestamps=0 holding the previous compile;
     *   3. a proxy or CDN replacing the header downstream.
     *
     * Skipped when APP_URL is unset, and never fatal on a network error — `app:doctor`
     * must stay useful on a host with no outbound HTTP.
     *
     * @return array<string,string>
     */
    private function liveCsp(): array
    {
        $url = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        if ($url === '') {
            return ['live_check' => 'skipped — APP_URL is not set, so there is nothing to compare against'];
        }
        if (!class_exists(Csp::class)) {
            return ['live_check' => 'skipped — Support\\Csp is absent from this tree'];
        }

        $expected = Csp::policy();
        $ctx = stream_context_create(['http' => [
            'method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true,
            // A redirect would compare the wrong response.
            'follow_location' => 0,
            'header' => "User-Agent: africa-gates-doctor\r\n",
        ]]);
        $headers = @get_headers($url . '/ping', true, $ctx);
        if (!is_array($headers)) {
            return ['live_check' => 'could not reach ' . $url . ' from this host (not necessarily a problem)'];
        }

        // Header names are case-insensitive and get_headers preserves the wire casing.
        $live = '';
        $count = 0;
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Security-Policy') !== 0) continue;
            // An ARRAY means two CSP headers, which browsers enforce as the INTERSECTION
            // — a case worth naming, because each looks fine alone.
            $count = is_array($value) ? count($value) : 1;
            $live = is_array($value) ? implode(' || ', $value) : (string) $value;
        }

        if ($live === '') {
            return [
                'live_check'  => 'MISMATCH — the live response carries NO Content-Security-Policy',
                'live_policy' => '(absent)',
            ];
        }

        // NORMALISE THE NONCE OUT BEFORE COMPARING. It is per-request by design, so the
        // CLI process and the HTTP response can never share one — comparing the raw
        // strings reported MISMATCH on a perfectly healthy deployment, which is worse
        // than no check at all: a diagnostic that always fires teaches people to ignore
        // it, and this is the one they most need to trust.
        $same = self::normalise($live) === self::normalise($expected);
        $out = [
            'live_check'        => $same ? 'OK — the live header matches this code' : 'MISMATCH — see below',
            'live_headers_seen' => (string) $count . ($count > 1 ? ' (two policies are ANDed by the browser)' : ''),
        ];
        if (!$same) {
            $out['live_has_nonce'] = str_contains($live, "'nonce-") ? 'yes' : 'NO — the running code predates the CSP rewrite';
            $out['live_policy']    = $live;
            $out['this_code_would_send'] = $expected;
        }
        return $out;
    }

    /**
     * A policy with the per-request nonce and incidental whitespace removed, so two
     * policies can be compared for MEANING rather than for bytes.
     */
    private static function normalise(string $policy): string
    {
        $p = (string) preg_replace("~'nonce-[A-Za-z0-9+/=]+'~", "'nonce-*'", $policy);
        return trim((string) preg_replace('~\s+~', ' ', $p));
    }

    /** @return list<string> */
    private function problems(array $r): array
    {
        $p = [];
        if (str_contains((string) ($r['csp']['live_check'] ?? ''), 'MISMATCH')) {
            $p[] = "THE DEPLOYED CODE IS NOT THIS CODE. The Content-Security-Policy on the live "
                 . "response differs from the one this tree produces"
                 . (str_contains((string) ($r['csp']['live_has_nonce'] ?? ''), 'NO')
                     ? ", and the live one is not nonce-based — i.e. it predates the CSP rewrite entirely" : '')
                 . ". Editing PHP will not change the headers until this is resolved. Three causes, "
                 . "fixed differently: (1) DocumentRoot still points at an older copy of the app — "
                 . "check it against the `root` hash on /ping; (2) opcache with "
                 . "validate_timestamps=0 is holding the previous compile — reload PHP-FPM or flush "
                 . "it; (3) a proxy or CDN is replacing the header downstream — compare the origin "
                 . "directly, bypassing the CDN. This is the same unresolved problem behind every "
                 . "CSP refusal reported from production: blocked CDN scripts and stylesheets, and "
                 . "every paid vote refused by `form-action 'self'`. All of it is already fixed here.";
        }
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
        if (str_contains((string) ($r['assets']['css_bundle'] ?? ''), 'NOT BUILT')) {
            $p[] = 'The CSS bundle is missing or stale, so every page is loading '
                 . count(\AfricaGates\Support\AssetBundle::STYLESHEETS) . ' separate render-blocking '
                 . 'stylesheets instead of one — roughly 2.4s of blocking requests on a mid-range '
                 . 'Android. The site is CORRECT, just slow: run `bin/console assets:build` (or open '
                 . '/__setup/assets?token=… on a host with no shell) and add it to the deploy steps.';
        }
        if ((int) ($r['media']['failed_uploads'] ?? 0) > 0) {
            $p[] = 'Cloudinary uploads have failed for ' . $r['media']['failed_uploads'] . ' image(s). '
                 . 'Query gates_media_migrations WHERE status = \'failed\' for the reason — the rows still '
                 . 'point at local files, so nothing is broken, but the sweep will not finish until it is fixed.';
        }
        if (str_contains((string) ($r['media']['missing_on_disk'] ?? ''), 'not present')) {
            $p[] = 'Some rows reference image files that are not on disk (' . $r['media']['missing_on_disk'] . '). '
                 . 'Those images were already broken before any migration; the sweep left them alone rather '
                 . 'than rewriting them to a CDN URL that would also 404. See gates_media_migrations.';
        }
        if (($r['csp']['form_action_has_gateways'] ?? '') === 'NO') {
            $p[] = 'form-action does not list the payment gateways. Chrome applies form-action to the '
                 . 'redirect a submission lands on, and POST /vote/paid/start 302s to the gateway, so '
                 . 'every paid vote is blocked in the browser after the pending order is written.';
        }
        if (str_starts_with((string) ($r['code']['flier_fonts'] ?? ''), 'MISSING')) {
            $p[] = 'The flier\'s bundled fonts are missing, so the PNG cannot be rendered and the '
                 . 'route falls back to SVG — which no chat app previews. Every nominee\'s link '
                 . 'preview is silently broken. Check resources/fonts/ survived the deploy.';
        }
        if (($r['code']['gd'] ?? '') === 'MISSING') {
            $p[] = 'GD with FreeType is unavailable, so no flier PNG can be rendered and link '
                 . 'previews will show nothing.';
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
