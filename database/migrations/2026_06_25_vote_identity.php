<?php
/**
 * Voting now captures the voter's full name + phone alongside the (still hashed)
 * email, for accountability and contact. Adds gates_votes.voter_name and
 * gates_votes.voter_phone — both NULLable (historical votes have neither).
 * Idempotent + driver-aware. The email stays hashed; these are plaintext contact
 * fields, so they are covered by the data-retention purge (see SECURITY-HARDENING-V3).
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$driver = DB::connection()->getDriverName();

if (!$schema->hasColumn('gates_votes', 'voter_name')) {
    DB::statement('ALTER TABLE gates_votes ADD COLUMN voter_name ' . ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(120) DEFAULT NULL'));
    echo "  + gates_votes.voter_name added\n";
} else {
    echo "  = gates_votes.voter_name already present\n";
}

if (!$schema->hasColumn('gates_votes', 'voter_phone')) {
    DB::statement('ALTER TABLE gates_votes ADD COLUMN voter_phone ' . ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40) DEFAULT NULL'));
    echo "  + gates_votes.voter_phone added\n";
} else {
    echo "  = gates_votes.voter_phone already present\n";
}

echo "vote identity (name + phone) OK\n";
