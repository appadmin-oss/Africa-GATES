<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

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
 * --with-seed-admin will create a first superadmin if no admin exists.
 * Defaults: SEED_ADMIN_EMAIL=admin@afrovanguard.org.ng / SEED_ADMIN_PASSWORD=AfricaGates!2025
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
        $driver = $_ENV['DB_DRIVER'] ?? 'mysql';
        $root = dirname(__DIR__, 3);

        $files = $driver === 'sqlite' ? [
            'public'    => $root.'/database/sqlite-schema.sql',
            'admin'     => $root.'/database/sqlite-admin-schema.sql',
            'community' => $root.'/database/sqlite-community-schema.sql',
        ] : [
            'public'    => $root.'/database/schema.sql',
            'admin'     => $root.'/database/admin-schema.sql',
            'community' => $root.'/database/community-schema.sql',
        ];

        $pdo = DB::connection()->getPdo();
        foreach ($files as $label => $f) {
            if (!is_file($f)) { $io->warning("Missing $label: $f"); continue; }
            $sql = file_get_contents($f);
            $io->writeln("Applying <info>$label</info> (" . basename($f) . ") …");
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec($sql);
                } else {
                    // MySQL PDO::exec can handle multi-statement SQL when the driver permits;
                    // split-and-run for maximum compatibility.
                    foreach ($this->splitMysql($sql) as $stmt) {
                        if (trim($stmt) === '') continue;
                        $pdo->exec($stmt);
                    }
                }
            } catch (\Throwable $e) {
                $io->error("Failed on $label: " . $e->getMessage());
                return Command::FAILURE;
            }
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
