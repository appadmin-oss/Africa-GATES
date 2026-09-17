<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Support\Permissions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'admin:create', description: 'Create or update an admin account.')]
class AdminCreateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('email',    InputArgument::REQUIRED, 'Admin email')
            ->addArgument('name',     InputArgument::REQUIRED, 'Admin display name')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password')
            ->addOption('role',  null, InputOption::VALUE_REQUIRED, 'Role (superadmin|admin|editor|moderator|viewer)', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string)$input->getArgument('email')));
        $name  = trim((string)$input->getArgument('name'));
        $pass  = (string)$input->getArgument('password');
        $role  = (string)$input->getOption('role');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("Invalid email: $email");
            return Command::FAILURE;
        }
        if (!Permissions::isRole($role)) {
            $io->error("Invalid role: $role — use one of: " . implode(', ', array_keys(Permissions::ROLES)));
            return Command::FAILURE;
        }

        $existing = DB::table('gates_admins')->where('email', $email)->first();
        $data = [
            'email'         => $email,
            'name'          => $name,
            'role'          => $role,
            'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            'is_active'     => 1,
            'updated_at'    => Carbon::now()->toDateTimeString(),
        ];
        if ($existing) {
            DB::table('gates_admins')->where('id', $existing->id)->update($data);
            $io->success("Updated admin: $email ($role)");
        } else {
            $data['created_at'] = Carbon::now()->toDateTimeString();
            DB::table('gates_admins')->insert($data);
            $io->success("Created admin: $email ($role)");
        }
        return Command::SUCCESS;
    }
}
