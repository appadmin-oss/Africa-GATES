<?php
/**
 * Carry a live site's ₦1,000 bundle rate over to the percentage ladder that replaced it.
 *
 * ── WHY THIS IS NOT "JUST LET IT FALL BACK TO DEFAULTS" ──────────────────────
 *
 * {@see \AfricaGates\Services\PaidVoteService::tiers()} returns DEFAULT_TIERS when
 * `vote_tiers` is unset, and that is the right behaviour for a fresh install. On a site
 * that is ALREADY SELLING VOTES it is a silent price change: an admin who configured
 * "6 votes per ₦1,000" against a ₦200 per-vote price had set a 17% bulk discount at a
 * quantity of 6, and the defaults offer 5% at 10. Nobody would be told; the first
 * evidence would be a buyer's total.
 *
 * So the old setting is translated once and the result is a ladder the admin can then see
 * and edit — which is the actual point of the change. The maths is just the bundle
 * expressed as what it always implicitly was:
 *
 *     bundle per-vote price = 1000 / N          (N votes for ₦1,000)
 *     discount %            = (1 − (1000/N) / P) × 100     rounded to the nearest %
 *
 * NOT byte-exact, and it cannot be: a whole percentage generally cannot express "exactly
 * ₦1,000". At the common 6-votes-for-₦1,000 / ₦200-a-vote setting the true figure is
 * 16.67%, stored as 17%, and the bundle quantity therefore charges ₦996 instead of
 * ₦1,000 — 0.4% out, in the buyer's favour. Rounding to nearest is chosen over rounding
 * down deliberately: the alternative silently RAISES what supporters pay, which is the
 * direction that generates complaints. Any admin who wants the old figure back to the
 * naira can now see the percentage and adjust it, which they could not do before.
 *
 * A bundle that is not actually a discount (1000/N ≥ P — possible, and it means the
 * "deal" was costing the buyer money) converts to 0% rather than to a negative, because
 * a surcharge is not something the old code could charge either: `price()` took the
 * CHEAPER of the two rules, so the bundle simply never applied.
 *
 * ── RUNS ONCE, AND ONLY WHEN THERE IS NOTHING TO OVERWRITE ───────────────────
 *
 * Guarded on `vote_tiers` being absent or blank. Re-running after an admin has edited
 * the ladder must not resurrect the old bundle, and re-running at all must not undo
 * their edits — so a non-empty `vote_tiers` is treated as "the migration already
 * happened", whoever or whatever wrote it.
 *
 * Idempotent + driver-agnostic (one settings row). NEVER exit/die here (include()d in
 * a loop).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

if (!DB::schema()->hasTable('gates_settings')) {
    echo "gates_settings absent — skipped\n";
    return;
}

$get = static function (string $key): string {
    try { return trim((string) (DB::table('gates_settings')->where('key_name', $key)->value('value') ?? '')); }
    catch (\Throwable) { return ''; }
};

if ($get('vote_tiers') !== '') {
    echo "vote_tiers already configured — left alone\n";
    return;
}

$per1000 = (int) $get('vote_votes_per_1000');
if ($per1000 < 1) {
    // No bundle was ever configured, so there is no pricing to preserve and the
    // reader's DEFAULT_TIERS are exactly right.
    echo "no legacy bundle rate — the default ladder applies\n";
    return;
}

$price = (int) $get('vote_price_naira');
if ($price < 1) $price = \AfricaGates\Services\PaidVoteService::DEFAULT_PRICE_NAIRA;

$bundleUnit = 1000 / $per1000;
$off        = (int) max(0, min(90, (int) round((1 - $bundleUnit / $price) * 100)));

// The 1-vote rung keeps the ladder honest: the ballot renders a chip per tier, and a
// ladder whose smallest entry is the bundle quantity offers no way to buy one vote.
$tiers = [['qty' => 1, 'off' => 0]];
if ($per1000 > 1) $tiers[] = ['qty' => $per1000, 'off' => $off];

try {
    DB::table('gates_settings')->updateOrInsert(
        ['key_name' => 'vote_tiers'],
        ['value' => (string) json_encode($tiers)]
    );
    $now = (int) ceil($per1000 * $price * (100 - $off) / 100);
    echo "vote_tiers seeded from the ₦1,000 bundle: {$per1000} votes at ₦{$price} each → {$off}% off from {$per1000}"
       . " (that quantity now costs ₦" . number_format($now) . ", was ₦1,000)\n";
} catch (\Throwable $e) {
    // Not fatal: the ballot falls back to DEFAULT_TIERS and the admin can set the
    // ladder by hand on the settings page, which is where they will be looking anyway.
    echo 'vote_tiers seed skipped: ' . $e->getMessage() . "\n";
}

echo "vote tiers from bundle OK\n";
