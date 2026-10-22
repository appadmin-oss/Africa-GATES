<?php
declare(strict_types=1);

use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * PASSWORDLESS SIGN-IN BY PHONE, AND THE TWO STEPS AFTER JOINING (Phase 8, SignIn.dc.html).
 *
 * ── `phone_e164`: THE NUMBER A CODE IS SENT TO, IN ONE SHAPE ─────────────────
 *
 * `phone` is whatever somebody typed — "0803 000 0000", "+234 803…", "803-000-0000" — and a
 * sign-in that matches on it matches nothing reliably: the same handset arrives in four
 * spellings. So the number a code may be sent to is stored a second time, normalised by
 * `Support\Phone::normalize()` (the one normaliser every messaging path already uses), and
 * looked up by that alone. `phone` stays as typed: it is what the member wrote, and the
 * account page shows it back to them.
 *
 * NOT UNIQUE, deliberately. Accounts already exist with a shared number — a family, an
 * office line — and a UNIQUE key would refuse this migration on any database holding two.
 * `UserAccountService::byPhone()` signs in by phone only where exactly one active account
 * holds the number, and says so to the one person who has proved they own the handset.
 *
 * Indexed through `SchemaIndex::ensure()` — never `CREATE INDEX IF NOT EXISTS`, which MySQL
 * answers with a 1064 and which has cost this codebase three migrations (CLAUDE.md).
 *
 * ── `headline`, `based_in`, `interests_json`: STEPS 3 AND 4 OF JOINING ────────
 *
 * "What you do", "Where you're based" and "What do you care about?". Each has its readers in
 * the same change: the account page shows and edits all three, and the nomination hub
 * orders awards by the interests (`MemberInterests::rank()`), which is what the screen
 * that asks for them promises. A column added for a screen nothing reads back is the
 * shape this codebase has paid for six times.
 *
 * Added only where absent; a database built from the corrected schema files skips cleanly,
 * and there is no constraint here for a later repair to correct.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_users')) {
    echo "  = gates_users absent — nothing to do\n";
    return;
}

$cols = [
    'phone_e164'     => $sqlite ? 'TEXT' : 'VARCHAR(20)',
    'headline'       => $sqlite ? 'TEXT' : 'VARCHAR(120)',
    'based_in'       => $sqlite ? 'TEXT' : 'VARCHAR(120)',
    // Ten keys at most from MemberInterests::FIELDS — under 200 ASCII bytes.
    'interests_json' => $sqlite ? 'TEXT' : 'VARCHAR(255)',
];
foreach ($cols as $col => $type) {
    if (!DB::schema()->hasColumn('gates_users', $col)) {
        DB::statement("ALTER TABLE gates_users ADD COLUMN {$col} {$type} NULL DEFAULT NULL");
        echo "  + gates_users.{$col} added\n";
    } else {
        echo "  = gates_users.{$col} already present\n";
    }
}

echo '  ' . SchemaIndex::ensure('gates_users', 'idx_users_phone_e164', ['phone_e164']) . "\n";

// Backfill from what members already typed. Nigeria resolves a trunk-0 number because it is
// where nearly every member here is; anything that does not normalise stays NULL, so a
// number nobody can be sure of is never one a code is sent to.
$n = 0;
foreach (DB::table('gates_users')->whereNull('phone_e164')->whereNotNull('phone')->get(['id', 'phone']) as $u) {
    $e = Phone::normalize((string) $u->phone, 'NG');
    if ($e === null) continue;
    DB::table('gates_users')->where('id', $u->id)->update(['phone_e164' => $e]);
    $n++;
}
echo "  backfilled phone_e164 on {$n} member(s)\n";
echo "member passwordless OK\n";
