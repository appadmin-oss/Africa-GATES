<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Apply ALL schema files (public + admin + community + judging rubric) against
 * the configured database — MySQL or SQLite, idempotent.
 *
 *   php bin/console db:migrate
 *   php bin/console db:migrate --with-seed-admin
 *
 * --with-seed-admin creates a first superadmin if none exists. Email defaults to
 * SEED_ADMIN_EMAIL (else admin@afrovanguard.org.ng); the password is taken from
 * SEED_ADMIN_PASSWORD if set, otherwise a strong random one is generated and
 * printed ONCE — no hardcoded default credential is ever shipped.
 */
#[AsCommand(name: 'db:migrate', description: 'Apply ALL DB schemas (public + admin + community).')]
class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('with-seed-admin', null, InputOption::VALUE_NONE,
            'Also seed a first superadmin account if none exists.');
        $this->addOption('with-seed-rubric', null, InputOption::VALUE_NONE,
            'Also seed the default 4-criterion judge rubric if empty.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $driver = Env::get('DB_DRIVER', 'mysql');
        $root = dirname(__DIR__, 3);

        // Delegate to MigrationRunner — the SINGLE source of truth also used by the
        // web trigger (/__setup/migrate). It ledger-tracks BOTH the schema files and
        // the dated migrations, and — crucially — treats benign MySQL errors
        // ("table already exists", "duplicate column/key": 1050/1060/1061…) as no-ops.
        //
        // The old inline applier used raw PDO::exec on every schema statement and
        // returned FAILURE on ANY throw, BEFORE the dated migrations ran. On a
        // re-deploy that re-applied schema.sql, a benign "duplicate index" aborted
        // the whole command, so new columns (e.g. gates_nominations.decision_reason)
        // never got added and admin write-actions 500'd. Converging via the runner
        // fixes that class of bug for good.
        $io->writeln('Applying schema + tracked migrations …');
        $guard = 0;
        do {
            $r = \AfricaGates\Services\MigrationRunner::run(1000); // effectively "all" per call
            foreach ($r['lines'] as $line) {
                $io->writeln('  ' . (str_starts_with(ltrim($line), 'WARN') ? '<comment>' . $line . '</comment>' : $line));
            }
            if (!$r['ok']) {
                $io->error('Migration failed: ' . ($r['error'] ?? 'unknown error'));
                return Command::FAILURE;
            }
        } while ($r['pending'] > 0 && ++$guard < 100);
        if ($r['pending'] > 0) {
            $io->error('Migrations did not converge after ' . $guard . ' batches — check var/logs/setup-migrate.log.');
            return Command::FAILURE;
        }

        // Optionally seed first admin
        if ($input->getOption('with-seed-admin')) {
            $count = (int)DB::table('gates_admins')->count();
            if ($count > 0) {
                $io->writeln("Admin account already exists ($count rows) — skipping seed.");
            } else {
                $email = getenv('SEED_ADMIN_EMAIL') ?: 'admin@afrovanguard.org.ng';
                // Never ship a hardcoded default password (a known credential is a
                // backdoor). Use SEED_ADMIN_PASSWORD if set; otherwise generate a
                // strong random one and print it ONCE for the operator to capture.
                $envPass = (string) (getenv('SEED_ADMIN_PASSWORD') ?: '');
                $pass    = $envPass !== '' ? $envPass : bin2hex(random_bytes(9));
                DB::table('gates_admins')->insert([
                    'email'         => strtolower($email),
                    'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                    'name'          => 'Afrovanguard Admin',
                    'role'          => 'superadmin',
                    'is_active'     => 1,
                    'created_at'    => Carbon::now()->toDateTimeString(),
                    'updated_at'    => Carbon::now()->toDateTimeString(),
                ]);
                if ($envPass !== '') {
                    $io->success("Seeded superadmin: $email (used SEED_ADMIN_PASSWORD — rotate after first login).");
                } else {
                    $io->warning("Seeded superadmin: $email");
                    $io->writeln("  One-time generated password: $pass");
                    $io->writeln("  Save it now, then change it after first login. Set SEED_ADMIN_PASSWORD to pick your own.");
                }
            }
        }

        // Optionally seed rubric
        if ($input->getOption('with-seed-rubric')) {
            $count = (int)DB::table('gates_judge_criteria')->count();
            if ($count > 0) {
                $io->writeln("Rubric already exists ($count criteria) — skipping seed.");
            } else {
                $crit = [
                    ['impact',     'Impact',     'Measurable difference made for the community or industry.',           25, 1],
                    ['originality','Originality','Inventiveness, creativity, novelty of approach.',                      25, 2],
                    ['reach',      'Reach',      'Breadth of influence — local, regional, continental, global.',       25, 3],
                    ['integrity',  'Integrity',  'Consistency of values, ethics, and accountability.',                  25, 4],
                ];
                foreach ($crit as [$slug, $label, $desc, $w, $order]) {
                    DB::table('gates_judge_criteria')->insert([
                        'programme_id' => null,
                        'slug'         => $slug,
                        'label'        => $label,
                        'description'  => $desc,
                        'weight'       => $w,
                        'sort_order'   => $order,
                        'is_active'    => 1,
                    ]);
                }
                $io->success('Seeded 4 default judge criteria.');
            }
        }

        $io->success('Migrations complete.');
        return Command::SUCCESS;
    }

    /**
     * Apply the dated PHP migrations in database/migrations/ that haven't run
     * yet, tracked in a `gates_migrations` ledger so each runs exactly once and
     * in filename (date-prefixed) order.
     *
     * The 3 historical .sql migrations are MySQL-only catch-up scripts already
     * folded into the consolidated schema files applied above, so they are not
     * re-executed here (their procedure bodies also can't be split safely).
     */
    private function runFileMigrations(SymfonyStyle $io, string $root, string $driver): int
    {
        $this->ensureLedger($driver);

        $applied = [];
        foreach (DB::table('gates_migrations')->pluck('migration') as $m) {
            $applied[(string) $m] = true;
        }

        $files = glob($root . '/database/migrations/*.php') ?: [];
        sort($files); // date-prefixed filenames sort chronologically

        $skippedSql = count(glob($root . '/database/migrations/*.sql') ?: []);
        if ($skippedSql > 0) {
            $io->writeln("  ($skippedSql historical .sql migration(s) skipped — superseded by the base schema)");
        }

        $ran = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) continue;

            $io->writeln("  → $name");
            try {
                // Isolated scope so the migration's top-level vars don't leak here;
                // each script self-bootstraps its own connection and is idempotent.
                (static function (string $__migrationFile) { include $__migrationFile; })($file);
                DB::table('gates_migrations')->insert([
                    'migration'  => $name,
                    'applied_at' => Carbon::now()->toDateTimeString(),
                ]);
                $ran++;
            } catch (\Throwable $e) {
                $io->error("Migration $name failed: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $io->writeln($ran > 0 ? "  applied $ran migration(s)." : '  no new migrations.');
        return Command::SUCCESS;
    }

    /** Create the migration-tracking ledger if it doesn't exist (driver-aware). */
    private function ensureLedger(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::statement('CREATE TABLE IF NOT EXISTS gates_migrations ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'migration TEXT NOT NULL UNIQUE, '
                . 'applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        } else {
            DB::statement('CREATE TABLE IF NOT EXISTS gates_migrations ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . 'migration VARCHAR(191) NOT NULL, '
                . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'PRIMARY KEY(id), UNIQUE KEY uq_migration(migration)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    /**
     * Split a MySQL .sql file into individual statements.
     * Strips line comments, joins continuation lines, splits on terminating ';'.
     * Handles strings (no semicolons inside quotes are split points).
     */
    private function splitMysql(string $sql): array
    {
        $out = []; $buf = ''; $len = strlen($sql);
        $inSingle = false; $inDouble = false; $inLine = false; $inBlock = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i]; $n = $i + 1 < $len ? $sql[$i+1] : '';
            if ($inLine) {
                if ($c === "\n") $inLine = false;
                continue;
            }
            if ($inBlock) {
                if ($c === '*' && $n === '/') { $inBlock = false; $i++; }
                continue;
            }
            if (!$inSingle && !$inDouble) {
                if ($c === '-' && $n === '-') { $inLine = true; $i++; continue; }
                if ($c === '#') { $inLine = true; continue; }
                if ($c === '/' && $n === '*') { $inBlock = true; $i++; continue; }
                if ($c === ';') { $out[] = $buf; $buf = ''; continue; }
            }
            if ($c === "'" && !$inDouble) $inSingle = !$inSingle;
            elseif ($c === '"' && !$inSingle) $inDouble = !$inDouble;
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }
}
