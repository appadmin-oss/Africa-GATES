<?php
declare(strict_types=1);

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE TERMS STATED TWO THINGS ABOUT MONEY THAT THE PLATFORM DOES NOT DO.
 *
 * "There is no … referral commission" — while ReferralService pays members a published
 * percentage of the tickets and shop orders bought through their link, advertised on the
 * events page. And the community return was described as a share kept "win or lose" with
 * "nothing payable until the results have been announced" as the only rule — while an
 * edition on the target pool pays nothing to a nominee below the target, and is settled
 * when voting closes, before any results.
 *
 * Same shape and same standard as 2027_04_30_terms_voting_repair.php: the document lives
 * in gates_legal_docs and LegalSeeder never overwrites an installed one, so correcting the
 * seeder fixes only deployments that never installed it. Replaced only where `updated_by`
 * is NULL — the platform's own record that no person has edited these words. An edited
 * copy is the operator's, and is left with a line saying what to correct by hand. No row is
 * created: an absent document installs the corrected text on first request.
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
    echo "  · terms has been edited by an administrator — their words kept. Correct the community-return paragraph and the referral-commission line by hand.\n";
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

echo "terms money repair OK\n";
