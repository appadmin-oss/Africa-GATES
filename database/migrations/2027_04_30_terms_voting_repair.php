<?php
declare(strict_types=1);

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE TERMS STOP PROMISING A FREE VOTE THAT AN AWARD MAY NOT OFFER (owner, 2 Nov 2026).
 *
 * The shipped terms said "Free voting is open to the public and is the vote that determines a
 * nominee's community score", and that card contributions close "before free voting does". On
 * an award that runs on contributions alone (paid_voting_disable_free) there is no free vote at
 * all; and on any award the first sentence was false, because contributed votes count in the
 * community score too. The corrected text in LegalSeeder::terms() says both cases plainly.
 *
 * Production holds the terms in gates_legal_docs, so correcting the seeder alone fixes only a
 * fresh install. As with 2027_03_31_terms_money_repair: the stored copy is replaced ONLY where
 * no administrator has edited it (updated_by IS NULL) — an operator's words are theirs.
 */

\AfricaGates\Support\Clock::boot();

if (!DB::schema()->hasTable('gates_legal_docs')) {
    echo "  · gates_legal_docs absent — nothing to repair\n";
    echo "terms voting repair OK\n";
    return;
}

$body = (string) (LegalSeeder::documents()['terms']['body'] ?? '');
$row  = DB::table('gates_legal_docs')->where('slug', 'terms')->first();

if ($body === '') {
    echo "  · terms is not a shipped document — skipped\n";
} elseif (!$row) {
    echo "  · terms has never been installed — LegalService installs the corrected text on first request\n";
} elseif ($row->updated_by !== null) {
    echo "  · terms has been edited by an administrator — their words kept. Correct the free-voting paragraph by hand.\n";
} elseif (trim((string) $row->body_html) === trim($body)) {
    echo "  · terms already carries the corrected text\n";
} else {
    DB::table('gates_legal_docs')->where('slug', 'terms')->update([
        'body_html'     => $body,
        'updated_label' => date('j F Y'),
        'updated_at'    => \Illuminate\Support\Carbon::now()->toDateTimeString(),
    ]);
    echo "  + terms corrected\n";
}

echo "terms voting repair OK\n";
