<?php
/**
 * Dispute, freeze and cooling-off for nominee claims — docs/CLAIM-FAIRNESS-AND-FRAUD.md §5.
 *
 * ── WHAT WAS MISSING, AND WHY IT MATTERED ────────────────────────────────────
 *
 * Steps 1–3 of the build order shipped: the independence check, the notification
 * fan-out, and claiming by OTP with `held` as a first-class outcome. Step 5 —
 * "dispute, revoke, cooling-off" — did not, and two consequences followed.
 *
 * FIRST. The fan-out email tells every contact on the nomination: "If this was not
 * you, reply to this email … we will stop it while a person looks." The claim is
 * ALREADY ACTIVE at that point. Between activation and a human reading support mail,
 * whoever claimed controls the page — and the real nominee's only lever is composing
 * an email and waiting. On a platform whose nominees include children, that window is
 * the whole attack.
 *
 * SECOND, and worse. The same email promises: "No money moves on a claim less than 7
 * days old." `ClaimNotifier::COOLING_OFF_DAYS = 7` existed as a number interpolated
 * into that sentence and NOWHERE ELSE in the codebase. Nothing enforced it.
 * CommunityReturnService::release() — the money-out path — has never looked at claim
 * state at all. The platform was making a safety promise to the exact people it was
 * asking to trust the claim process, with no code behind it.
 *
 * ── WHAT THIS ADDS ───────────────────────────────────────────────────────────
 *
 * `dispute_token`  a per-claim secret carried in every fan-out message, so any
 *                  notified channel can freeze the claim in one action without an
 *                  account, without composing an email, and without waiting.
 * `disputed_at`    when it was frozen, and
 * `disputed_by`    which masked channel raised it — a support agent needs to know
 *                  whether the objection came from the nominee's own phone or from
 *                  the nominator's inbox, and those mean very different things.
 * `dispute_note`   what they said, if they said anything.
 * `cooling_off_until` materialised at activation rather than derived on read.
 *
 * ── WHY COOLING-OFF IS A STORED TIMESTAMP ────────────────────────────────────
 *
 * `activated_at + 7 days` is trivial to compute, so storing it looks redundant. It is
 * not: the window length is a policy that will change, and a claim must be governed by
 * the policy in force WHEN IT WAS MADE. Deriving it on read means editing the constant
 * silently re-opens or re-closes the window on every claim already in flight —
 * including ones a nominee has already been told a date for. The stored date is the
 * date in the email.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_nominee_claims')) {
    echo "  ! gates_nominee_claims not present — run 2026_08_12_nominee_claims first\n";
    return;
}

foreach ([
    'dispute_token'     => $sqlite ? 'TEXT' : 'CHAR(32) NULL',
    'disputed_at'       => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
    'disputed_by'       => $sqlite ? 'TEXT' : 'VARCHAR(120) NULL',
    'dispute_note'      => $sqlite ? 'TEXT' : 'VARCHAR(400) NULL',
    'cooling_off_until' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_nominee_claims', $col)) {
        DB::statement("ALTER TABLE gates_nominee_claims ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_nominee_claims.{$col} added\n";
    } else {
        echo "  = gates_nominee_claims.{$col} already present\n";
    }
}

// The dispute link resolves by token, on a path reachable from an email — so it is a
// single-row lookup on a public endpoint and wants an index rather than a scan.
echo \AfricaGates\Support\SchemaIndex::ensure(
    'gates_nominee_claims', 'uq_claim_dispute_token', ['dispute_token'], unique: true
) . "\n";

// ── backfill: every already-active claim gets a token and a window ───────────
//
// Without this, claims made before today have no dispute link — and they are exactly
// the ones nobody has been able to challenge. The window is measured from activation,
// so a claim activated three weeks ago is already past cooling-off and stays that way;
// this is not a retroactive freeze, it is a retroactive ANSWER to "when did it end".
try {
    $rows = DB::table('gates_nominee_claims')
        ->whereNull('dispute_token')
        ->select('id', 'activated_at', 'created_at')
        ->get();

    $done = 0;
    foreach ($rows as $r) {
        $from = (string) ($r->activated_at ?: $r->created_at ?: '');
        $until = $from !== ''
            ? \Carbon\Carbon::parse($from)->addDays(7)->toDateTimeString()
            : null;
        DB::table('gates_nominee_claims')->where('id', $r->id)->update([
            'dispute_token'     => bin2hex(random_bytes(16)),
            'cooling_off_until' => $until,
        ]);
        $done++;
    }
    echo "  = dispute tokens minted for {$done} existing claim(s)\n";
} catch (\Throwable $e) {
    echo '  ! backfill skipped: ' . $e->getMessage() . "\n";
}

echo "claim dispute OK\n";
