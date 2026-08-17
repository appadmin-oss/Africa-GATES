<?php
/**
 * A vendor can be a person.
 *
 * ── WHY THIS IS ONE COLUMN AND NOT A SECOND TABLE ────────────────────────────
 *
 * The overwhelming majority of the people who will actually sell at an Africa GATES market
 * are not companies. They are a woman with a jollof stall, a man who prints t-shirts, a
 * sister-and-brother pair who make leather sandals. Requiring a CAC registration before any
 * of them can hold a pitch would not raise the standard of the market; it would hand every
 * place to whoever already has a lawyer, and it would push the rest to borrow somebody
 * else's registration — which is worse than no registration, because it puts the wrong name
 * on the paperwork when something goes wrong.
 *
 * So `entity_type` says whether this party is a registered business or a natural person, and
 * the REQUIREMENTS branch on it. Everything else — the settlement account, the subaccount
 * created without storing the account number, the dashboard, the documents with expiries,
 * the vetting state machine — is identical, which is exactly why this is a column and not a
 * parallel `gates_vendor_people` table.
 *
 * ── AND WHY THERE IS NO NIN COLUMN HERE ──────────────────────────────────────
 *
 * The obvious instinct is to collect a National Identification Number. It is resisted on
 * purpose, and the reasoning is the same one that keeps bank account numbers out of this
 * schema: a table of Nigerians' NINs is a permanent liability under the Nigeria Data
 * Protection Act 2023, and it would buy nothing this platform does not already have.
 *
 * Opening a Nigerian bank account requires a BVN, which requires identity documents. So when
 * `/bank/resolve` answers "this account belongs to NGOZI OKAFOR", a regulated institution has
 * already done the identity check, and the answer is fresher and better evidenced than a
 * number typed into a form. The name comparison against that answer IS the identity control
 * — see PartnerOrg::personNameSimilarity(), which is order-insensitive because a bank returns
 * the surname first and a person writes it last.
 *
 * What is still asked for is a photo ID document, and for an operational reason rather than a
 * regulatory one: on the morning of the market somebody has to check that the person standing
 * at the pitch is the person it was allocated to.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

try {
    if (!DB::schema()->hasColumn('gates_partner_orgs', 'entity_type')) {
        // Defaults to 'business', so every row that already exists keeps meaning exactly what
        // it meant before this migration ran. An NGO is always a business in this sense: an
        // Incorporated Trustee is a registered body, never a natural person.
        $pdo->exec("ALTER TABLE gates_partner_orgs ADD COLUMN entity_type "
                 . ($sqlite ? "TEXT NOT NULL DEFAULT 'business'"
                            : "VARCHAR(16) NOT NULL DEFAULT 'business'"));
    }
} catch (\Throwable $e) {
    echo "*** could not add gates_partner_orgs.entity_type: {$e->getMessage()}\n";
}

// Where the application came from. A vendor who filled the public form in themselves is a
// different review problem from one an administrator typed in after meeting them: nobody has
// laid eyes on the first, and the screen should say so rather than presenting both as
// equally vouched-for.
try {
    if (!DB::schema()->hasColumn('gates_partner_orgs', 'self_registered')) {
        $pdo->exec("ALTER TABLE gates_partner_orgs ADD COLUMN self_registered "
                 . ($sqlite ? "INTEGER NOT NULL DEFAULT 0"
                            : "TINYINT(1) NOT NULL DEFAULT 0"));
    }
} catch (\Throwable $e) {
    echo "*** could not add gates_partner_orgs.self_registered: {$e->getMessage()}\n";
}

foreach (['entity_type', 'self_registered'] as $col) {
    echo DB::schema()->hasColumn('gates_partner_orgs', $col)
        ? "gates_partner_orgs.$col OK\n"
        : "*** gates_partner_orgs.$col FAILED ***\n";
}
