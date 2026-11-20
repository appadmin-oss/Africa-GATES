<?php
/**
 * An index on the reference every gateway callback looks a donation up by.
 *
 * ── THE FAULT ────────────────────────────────────────────────────────────────
 *
 * `gates_donations.payment_ref` has carried no key since the live-server fix that created
 * it, and it is read on the hot path of every payment: the webhook, the verify path, the
 * reconciliation sweep and the support lookups — about thirty places. Each one scanned the
 * table, getting slower as giving grew, and RecurringGiving had to avoid FOR UPDATE on it
 * because a lock taken by a scan locks far more than one row. (AUDIT-AND-PLAN §2 fault 4.)
 *
 * Not UNIQUE: rows from before references were minted may share an empty or legacy value,
 * and a unique key added over them would fail on exactly the databases that have history.
 * The stand applications' own payment_ref gets the same key, since the webhook now looks
 * stand payments up by it (StandFee::confirm, for references minted before the ledger).
 *
 * SchemaIndex::ensure, never a raw CREATE INDEX IF NOT EXISTS (CLAUDE.md: MySQL answers
 * that with a 1064 and every later migration stops applying).
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;

echo SchemaIndex::ensure('gates_donations', 'idx_donations_payment_ref', ['payment_ref']) . "\n";
echo SchemaIndex::ensure('gates_stand_applications', 'idx_stand_app_payment_ref', ['payment_ref']) . "\n";

echo "payment_ref index OK\n";
