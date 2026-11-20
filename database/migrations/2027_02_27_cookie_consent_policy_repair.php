<?php
declare(strict_types=1);

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE COOKIE POLICY DESCRIBED ONE SWITCH. THERE ARE NOW THREE CHOICES.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT CHANGED, AND WHY THE STORED WORDS HAVE TO FOLLOW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * On 3 Oct 2026 the owner answered GAPS Q11: consent is the redesign's four categories in
 * one `ag_consent` cookie — Essential, always on, and Preferences, Analytics and Marketing
 * to allow or refuse (Services\CookiePrefs). The authored half of `/cookies` still said the
 * one thing a visitor could refuse was "the counting", "with the control at the top of this
 * page", and that "the counting can be refused on its own" — true of the old single switch,
 * and now a description of a page that no longer exists: Preferences is refusable too, and
 * what it covers (remembering your language and display settings between visits) is the
 * part a reader is most likely to care about.
 *
 * The generated half — the four choices, the list, the browser storage — was rebuilt in
 * the same change (LegalDocument::cookiesHtml()) and is right on every deployment the
 * moment the code ships. The authored half lives in `gates_legal_docs`, and
 * LegalSeeder::install() never overwrites a document that already exists, so correcting the
 * seeder fixes a fresh install and NOTHING on production. A corrected definition is not a
 * repair (CLAUDE.md, `gates_event_invites.audience`): this is the repair.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONLY WHERE NOBODY HAS EDITED IT — `updated_by IS NULL` IS THE EVIDENCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * LegalService::save() stamps the admin id on every edit and a seeded row has NULL — the
 * platform's own contemporaneous record that no person has claimed these words, the same
 * standard 2027_01_23_cookie_policy_repair.php used. An operator's edited copy is theirs
 * and is left alone; they are not left publishing a falsehood either, because the factual
 * half is generated under its own headings whatever the authored body says.
 *
 * No row is created (a missing document heals itself through LegalService::get(), and
 * creating one would resurrect a policy an operator deliberately unpublished), no schema is
 * touched, and running it twice changes nothing the second time.
 */

\AfricaGates\Support\Clock::boot();

if (!DB::schema()->hasTable('gates_legal_docs')) {
    echo "  · gates_legal_docs absent — nothing to repair\n";
    echo "cookie consent policy OK\n";
    return;
}

$body = (string) (LegalSeeder::documents()['cookies']['body'] ?? '');
$row  = DB::table('gates_legal_docs')->where('slug', 'cookies')->first();

if ($body === '') {
    echo "  · cookies is not a shipped document — skipped\n";
} elseif (!$row) {
    echo "  · cookies has never been installed — left for LegalService\n";
} elseif ($row->updated_by !== null) {
    echo "  · cookies has been edited by an administrator — their words kept\n";
} elseif (trim((string) $row->body_html) === trim($body)) {
    echo "  · cookies already describes the four choices\n";
} else {
    DB::table('gates_legal_docs')
        ->where('slug', 'cookies')
        ->whereNull('updated_by')
        ->update([
            'body_html'  => $body,
            // NOT stamped with an admin id: no administrator wrote this, and claiming one
            // did would make the next repair believe a person had edited it.
            'updated_at' => \Illuminate\Support\Carbon::now()->toDateTimeString(),
        ]);
    echo "  + cookies rewritten for the four choices\n";
}

echo "cookie consent policy OK\n";
