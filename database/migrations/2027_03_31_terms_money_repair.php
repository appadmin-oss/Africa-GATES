<?php
declare(strict_types=1);

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The Terms of Participation said three things about money that the scorer contradicts.
 *
 * "It does not move the Cultural Power Index… purchased votes are held in a separate
 * column the scorer does not read" — under the current rule bought votes are part of the
 * total tally, which is 30% of the community half. "Where two nominees finish level, the
 * tiebreak is organic votes" — the release orders a dead heat on the tally. "Purchased
 * votes on a nominee are capped as a proportion of the organic support" — nothing caps
 * them; the ceiling that exists is on granted and points votes. And "normalised within
 * each category", which is the rule the edition-wide denominator retired.
 *
 * Each was true of an earlier rule and outlived it, on the page a contributor is asked to
 * agree to before paying. The shipped text is corrected in LegalSeeder::terms(), and this
 * puts it on databases that installed the old copy — ONLY where `updated_by IS NULL`: a
 * page an administrator edited is theirs, and they are told here to correct it by hand.
 * Same shape as 2027_01_23_cookie_policy_repair.php.
 */

\AfricaGates\Support\Clock::boot();

if (!DB::schema()->hasTable('gates_legal_docs')) {
    echo "  · gates_legal_docs absent — nothing to repair\n";
    echo "terms money repair OK\n";
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

echo "terms money repair OK\n";
