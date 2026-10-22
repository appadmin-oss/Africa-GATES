<?php
declare(strict_types=1);

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The terms called the charge on a withdrawal "the published withdrawal fee"; the owner
 * named it the SERVICE fee (22 Oct 2026), because it pays for the bank transfer, the
 * platform and the project, not for the act of withdrawing. LegalSeeder::terms() carries
 * the new word; this puts it on databases that installed the earlier copy — ONLY where
 * `updated_by IS NULL`, exactly as 2027_03_31_terms_money_repair.php does.
 */

\AfricaGates\Support\Clock::boot();

if (!DB::schema()->hasTable('gates_legal_docs')) {
    echo "  · gates_legal_docs absent — nothing to repair\n";
    echo "terms service fee OK\n";
    return;
}

$body = (string) (LegalSeeder::documents()['terms']['body'] ?? '');
$row  = DB::table('gates_legal_docs')->where('slug', 'terms')->first();

if ($body === '') {
    echo "  · terms is not a shipped document — skipped\n";
} elseif (!$row) {
    echo "  · terms has never been installed — LegalService installs the corrected text on first request\n";
} elseif ($row->updated_by !== null) {
    echo "  · terms has been edited by an administrator — their words kept. Correct the money paragraphs by hand.\n";
} elseif (trim((string) $row->body_html) === trim($body)) {
    echo "  · terms already carries the corrected text\n";
} else {
    DB::table('gates_legal_docs')->where('slug', 'terms')->update([
        'body_html'  => $body,
        // Not stamped with an admin id: no administrator wrote this.
        'updated_at' => \Illuminate\Support\Carbon::now()->toDateTimeString(),
    ]);
    echo "  + terms corrected\n";
}

echo "terms service fee OK\n";
