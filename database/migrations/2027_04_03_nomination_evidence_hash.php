<?php
/**
 * A fingerprint of every uploaded evidence file (Services\NominationSignals).
 *
 *   gates_nomination_evidence.sha256   SHA-256 of the file AS IT WAS UPLOADED, before the
 *                                      upload service resized or re-encoded it. NULL for a
 *                                      link, and for files stored before this column — a
 *                                      stored image was re-encoded on the way in, so its
 *                                      bytes are not the upload's and hashing them after the
 *                                      fact would match nothing a new upload hashes.
 *
 * Why: a stored file's path is a fresh UUID every time, so the same photograph submitted
 * for two different people looked like two different files to every reader. The review
 * desk now says when one file backs nominations for two people.
 *
 * Idempotent + driver-aware; the index through SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_nomination_evidence') && !$schema->hasColumn('gates_nomination_evidence', 'sha256')) {
    DB::statement('ALTER TABLE gates_nomination_evidence ADD COLUMN sha256 ' . ($sqlite ? 'TEXT NULL' : 'CHAR(64) NULL DEFAULT NULL'));
    echo "  + gates_nomination_evidence.sha256\n";
}
if ($schema->hasTable('gates_nomination_evidence')) {
    echo '  ' . SchemaIndex::ensure('gates_nomination_evidence', 'idx_nomev_sha', ['sha256']) . "\n";
}
