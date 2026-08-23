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
            'mail'     => $this->mail(),
            'assets'   => $this->assets(),
            'csp'      => $this->csp(),
            'judging'  => $this->judging(),
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
            // Answerable only from the web SAPI (there is no DOCUMENT_ROOT on the CLI),
            // which is why the same value is on /ping — the endpoint an operator with no
            // shell can actually reach. See Build::documentRoot() for what it cost.
            'document_root' => \AfricaGates\Support\Build::documentRoot(),
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
            // Can this host fetch a CDN-hosted nominee photo at all?
            //
            // A photo that cannot be downloaded renders as the MONOGRAM, which is
            // indistinguishable from "this nominee never uploaded one" — so a whole
            // site's worth of faces can vanish from every share card and og:image with
            // no error anywhere. The usual cause is `allow_url_fopen=Off`, which is
            // common on shared cPanel and used to be the only transport the renderer had.
            'photo_fetch'      => $this->photoFetch(),
        ];
    }

    /** How, if at all, this host can pull a remote nominee photo. */
    private function photoFetch(): string
    {
        $curl = function_exists('curl_init');
        $fopen = (bool) ini_get('allow_url_fopen');

        if ($curl)  return 'curl' . ($fopen ? ' + allow_url_fopen' : ' (allow_url_fopen off — curl covers it)');
        if ($fopen) return 'allow_url_fopen only (no ext-curl — slower, no redirect control)';

        return 'NONE — remote photos cannot be fetched, so every CDN-hosted nominee '
             . 'photo will render as a monogram on the flier and og:image. '
             . 'Enable ext-curl or allow_url_fopen.';
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
            // APP_URL gets its own line because it is the one setting whose absence
            // breaks PAYMENTS rather than cosmetics: every gateway callback URL is built
            // from it, and a relative callback is not something a payment provider can
            // redirect a browser to. See Support\SiteUrl.
            'app_url_usable'  => \AfricaGates\Support\SiteUrl::isConfigured()
                ? 'yes'
                : 'NO — unset or missing its scheme; gateway callbacks fall back to the request host',
        ];
        foreach (['APP_URL', 'APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
                  'TRUST_PROXY', 'SESSION_SECURE', 'CRON_TOKEN', 'SETUP_TOKEN',
                  'PAYSTACK_SECRET_KEY', 'FLUTTERWAVE_WEBHOOK_HASH',
                  'TURNSTILE_SECRET', 'TURNSTILE_SITE_KEY', 'SMTP_HOST', 'SMTP_PASS'] as $key) {
            $out[$key] = $where($key);
        }

        // Turnstile needs BOTH keys, and the broken half is invisible in a per-key
        // listing: a secret with no site key renders no widget, so no browser can
        // produce a token and every OTP request would 403 — which reads in the log
        // as the protection working. Hence its own line.
        $tsSite   = trim((string) \AfricaGates\Support\Env::get('TURNSTILE_SITE_KEY', ''));
        $tsSecret = trim((string) \AfricaGates\Support\Env::get('TURNSTILE_SECRET', ''));
        $out['turnstile_pair'] = match (true) {
            $tsSite !== '' && $tsSecret !== '' => 'both set — bot check active',
            $tsSite === '' && $tsSecret === '' => 'both unset — bot check off (fine)',
            $tsSecret !== ''                   => 'BROKEN — secret set, SITE KEY EMPTY: no widget can render, so '
                                                . 'enforcement is skipped and logged per request. Set both, or clear both.',
            default                            => 'site key set, secret empty — the widget is decorative, nothing verifies it',
        };

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
     * IS ANY EMAIL ACTUALLY BEING DELIVERED?
     *
     * Reported from production as "emails are not being sent to voters", and there was
     * no way to check. Every send path is best-effort by design — a mail failure must
     * never break a vote or a payment — so a total delivery outage is INDISTINGUISHABLE
     * from normal operation from the outside. Two configuration mistakes produce it:
     *
     *   • SMTP_USER / SMTP_PASS unset. Every send writes to var/logs/outgoing-mail.log
     *     and returns failure. The site works perfectly and nothing ever arrives.
     *   • Credentials present but wrong. PHPMailer's reason reaches gates_mail_log and
     *     nowhere else, so it is only ever seen by someone who thinks to query it.
     *
     * `last_successful_send` is the check that settles it: a NEVER there means no email
     * has left this installation, and no amount of reading application code will explain
     * it. Beside it, `receipts_owed` counts buyers who have paid for votes and been told
     * nothing — the population this whole area exists to serve.
     */
    private function mail(): array
    {
        try {
            return \AfricaGates\Services\CheckoutMailer::status();
        } catch (\Throwable $e) {
            return ['smtp_configured' => 'unknown (' . $e->getMessage() . ')'];
        }
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

    /**
     * Can the panel actually score?
     *
     * ── WHY THIS IS A DOCTOR CHECK ───────────────────────────────────────────
     *
     * The rubric is seeded by an OPTIONAL migrate flag (`--with-seed-rubric`). A
     * deployment that ran plain `db:migrate` has `gates_judge_criteria` empty, and until
     * this was fixed the consequence was invisible from both sides: judges got a ballot
     * with no score inputs on which every nominee already read as complete, and saving
     * answered ok:true while storing nothing.
     *
     * Both halves are fixed, so a judge now sees the real reason. But the person who can
     * FIX it is an organiser, and nothing told them. That is what this is for — the
     * failure is a setup omission, which is exactly what a doctor command is meant to
     * find before a round rather than during one.
     *
     * @return array<string,string>
     */
    private function judging(): array
    {
        try {
            if (!DB::schema()->hasTable('gates_judge_criteria')) {
                return ['rubric' => 'NO — gates_judge_criteria does not exist; run db:migrate'];
            }

            $active = (int) DB::table('gates_judge_criteria')->where('is_active', 1)->count();
            $out = ['rubric_criteria_active' => (string) $active];

            if ($active === 0) {
                $out['rubric'] = 'NO ACTIVE CRITERIA — the panel cannot score anything';
                return $out;
            }

            // A programme in the judging phase with no rubric of its own AND no global
            // rubric is the case that actually bites, so name the programmes.
            $global = (int) DB::table('gates_judge_criteria')
                ->where('is_active', 1)->whereNull('programme_id')->count();

            $blind = [];
            foreach (DB::table('gates_award_cycles as cy')
                         ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                         ->where('cy.status', 'judging')
                         ->get(['p.id', 'p.title']) as $row) {
                $own = (int) DB::table('gates_judge_criteria')
                    ->where('is_active', 1)->where('programme_id', (int) $row->id)->count();
                if ($own === 0 && $global === 0) $blind[] = (string) $row->title;
            }

            $out['rubric'] = $blind === []
                ? 'OK'
                : 'NO RUBRIC for a programme in the judging phase: ' . implode(', ', $blind);
            return $out;
        } catch (\Throwable $e) {
            return ['rubric' => '(could not check: ' . $e->getMessage() . ')'];
        }
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
    /**
     * Does the live policy's script-src actually carry every host the class declares?
     *
     * Compares against Csp::SCRIPT_HOSTS rather than a literal, so vendoring a library
     * (which correctly SHRINKS the list) cannot make this report a fault. What it still
     * catches is the thing it was written for: a deployed policy whose script-src lost
     * hosts the code says it needs — a stale .htaccess, a proxy rewriting the header, a
     * half-finished edit.
     */
    private static function scriptHostsPresent(string $policy): string
    {
        if (preg_match('/script-src ([^;]+);/', $policy, $m) !== 1) return 'NO';

        foreach (preg_split('/\s+/', trim(Csp::SCRIPT_HOSTS)) ?: [] as $host) {
            if ($host === '') continue;
            if (!str_contains($m[1], $host)) return 'NO';
        }
        return 'yes';
    }

    private function csp(): array
    {
        if (!class_exists(Csp::class)) {
            return ['policy' => '(Csp class not present in this deployment)'];
        }
        $policy = Csp::policy();
        $has = static fn(string $needle): string => str_contains($policy, $needle) ? 'yes' : 'NO';

        return [
            'script_src_has_nonce'   => str_contains($policy, "script-src 'self' 'nonce-") ? 'yes' : 'NO',
            // Derived from Csp::SCRIPT_HOSTS, not a hardcoded host. It named
            // cdn.jsdelivr.net, which was correct until those assets were vendored — after
            // which this reported "NO" and printed "every third-party script is refused" at
            // an operator whose policy was in fact complete. A diagnostic that cries wolf
            // is worse than no diagnostic, because the next real alarm gets ignored too.
            'script_src_has_cdns'    => self::scriptHostsPresent($policy),
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
        $rubric = (string) ($r['judging']['rubric'] ?? '');
        if (str_starts_with($rubric, 'NO')) {
            $p[] = 'THE JUDGING PANEL CANNOT SCORE. ' . $rubric . '. The rubric lives in '
                 . 'gates_judge_criteria and is seeded by an OPTIONAL migrate flag, so a plain '
                 . '`db:migrate` leaves it empty — run `php bin/console db:migrate '
                 . '--with-seed-rubric`, or add criteria under /admin/programmes. Until then every '
                 . 'ballot is locked with that reason shown to the judge, which is the honest '
                 . 'behaviour but not a working round.';
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
        if (str_starts_with((string) ($r['config']['app_url_usable'] ?? ''), 'NO')) {
            $p[] = 'APP_URL is not set to an absolute URL (with https://). Every payment '
                 . 'gateway callback is built from it, so a blank value produced a RELATIVE '
                 . 'return URL — which Paystack and Flutterwave cannot redirect a browser to, '
                 . 'so the buyer never comes back and the order stays PENDING with their money '
                 . 'taken. Support\\SiteUrl now falls back to the request host, which is correct '
                 . 'on a single-host deployment, but set APP_URL explicitly: it is the only value '
                 . 'that is right behind a TLS-terminating proxy, and cron and the console have '
                 . 'no request to derive it from.';
        }
        if (str_contains((string) ($r['assets']['css_bundle'] ?? ''), 'NOT BUILT')) {
            $p[] = 'The CSS bundle is missing or stale, so every page is loading '
                 . count(\AfricaGates\Support\AssetBundle::STYLESHEETS) . ' separate render-blocking '
                 . 'stylesheets instead of one — roughly 2.4s of blocking requests on a mid-range '
                 . 'Android. The site is CORRECT, just slow: run `bin/console assets:build` (or open '
                 . '/__setup/assets?token=… on a host with no shell) and add it to the deploy steps.';
        }
        if (str_contains((string) ($r['runtime']['document_root'] ?? ''), 'WRONG')) {
            $p[] = 'THE DOCUMENT ROOT IS NOT ./public (' . $r['runtime']['document_root'] . '). This one '
                 . 'fact caused two outages: the project-root .htaccess was `Require all denied` as '
                 . 'defence-in-depth for this exact misconfiguration, so the WHOLE SITE returned 403; '
                 . 'and replacing that file with a copy of public/.htaccess returned 500, because its '
                 . 'front-controller rule names an index.php that is not beside it. The project root is '
                 . 'now a forwarder, so the site works either way — but the entire tree (.env, the '
                 . 'database, logs, vendor/) is sitting inside the web root, protected only by '
                 . '.htaccess rules instead of by being unreachable. Fix it in cPanel: Domains → '
                 . 'Manage → Document Root → append `/public`. See docs/DOCUMENT-ROOT.md.';
        }
        if (($r['mail']['smtp_configured'] ?? '') === 'NO') {
            $p[] = 'SMTP IS NOT CONFIGURED, so NO email is being delivered — not the voting OTP, not a '
                 . 'paid-vote receipt, not a nomination acknowledgement. Every send returns failure and '
                 . 'writes to var/logs/outgoing-mail.log instead, which is why the site looks healthy and '
                 . 'nothing arrives. Set SMTP_USER and SMTP_PASS in .env (Brevo relay credentials by '
                 . 'default), then prove it end to end with the "Send test email" button in admin '
                 . 'Settings. With free voting disabled, this also means a supporter can never receive a '
                 . 'voting code at all.';
        }
        if (str_starts_with((string) ($r['mail']['last_successful_send'] ?? ''), 'NEVER')) {
            $p[] = 'No email has EVER been delivered from this installation (gates_mail_log holds no '
                 . 'successful send). Whatever else is reported here, treat every email-dependent flow — '
                 . 'the voting OTP, magic-link sign-in, receipts, judge invitations — as non-functional '
                 . 'until one test email lands.';
        }
        if ((int) ($r['mail']['mail_failed_24h'] ?? 0) > 0 && (int) ($r['mail']['mail_sent_24h'] ?? 0) === 0) {
            $p[] = 'Every email attempted in the last 24 hours FAILED (' . $r['mail']['mail_failed_24h']
                 . ' attempts, 0 delivered). Last reason: ' . (string) ($r['mail']['last_failure_reason'] ?? '(none recorded)')
                 . '. This is a live outage, not a backlog.';
        }
        if ((int) ($r['mail']['receipts_owed'] ?? 0) > 0) {
            $p[] = $r['mail']['receipts_owed'] . ' confirmed paid-vote order(s) have had no receipt sent. '
                 . 'Those buyers paid for votes and have been told nothing at all, which is a chargeback '
                 . 'waiting to happen. Backfill with `bin/console mail:checkout --receipts` once SMTP is '
                 . 'proven working.';
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
            $p[] = 'script-src is missing hosts that Csp::SCRIPT_HOSTS declares, so some '
                 . 'third-party script on the page is refused.';
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
