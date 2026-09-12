<?php
declare(strict_types=1);

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE COOKIE POLICY SAID "ONE COOKIE" AND "NO ANALYTICS". NEITHER WAS TRUE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A MIGRATION AND NOT A CORRECTED DEFINITION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The policy lives in `gates_legal_docs`, and {@see LegalSeeder::install()} deliberately
 * never overwrites a document that already exists — an operator's edits are theirs. So
 * rewriting the seeder fixes the text for a deployment that has never installed it, and
 * fixes NOTHING for production, which installed it long ago and has been serving the false
 * version ever since. This repository has already paid for that exact shape once, on an
 * ENUM: `gates_event_invites.audience` was corrected one commit later and production kept
 * the first definition for ever, green in dev the whole time.
 *
 * What was wrong, in bold, on a published legal page:
 *
 *   "We set ONE cookie."  There were three. `ag_region` and `ag_currency` are written by
 *   `document.cookie` from the shop's region and currency selects and last a year. They
 *   were added long after the policy was written, and the policy's own note pointed a
 *   reader at `session_set_cookie_params()` — so checking the named evidence confirmed
 *   the wrong answer, because the second writer is a line of JavaScript in a template.
 *
 *   "We run no analytics."  {@see \AfricaGates\Services\VisitTracker} records every
 *   arrival's source, campaign, landing page, device and country, on by default, into a
 *   report an operator reads every week. It is first-party, it keeps no IP address and it
 *   tells nobody else, all of which makes it defensible and none of which makes that
 *   sentence true.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONLY WHERE NOBODY HAS EDITED IT, AND `updated_by` IS THE EVIDENCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `LegalService::save()` stamps the admin id on every edit; a seeded row has NULL. So a
 * NULL is this platform's own contemporaneous record that no person has ever touched
 * these words — evidence, not a heuristic, the same standard the unannounced-seal repair
 * used for `gates_cycle_transitions.notify`.
 *
 * Where an operator HAS edited the document, their words stand and this leaves them
 * alone. They are not left publishing a falsehood either: the factual half of the page is
 * generated on every render from {@see \AfricaGates\Support\CookieRegistry} by
 * {@see \AfricaGates\Services\LegalDocument::cookiesHtml()}, and appears under its own
 * heading whatever the authored body says. The prose is the promise; the generated
 * section is the fact. This migration only stops the two contradicting each other on a
 * deployment where nobody has claimed the prose.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NO ROW IS CREATED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A missing document heals itself on first request — `LegalService::get()` seeds a
 * shipped policy that has never been installed, which is why /refunds stopped 404ing.
 * Creating one here would resurrect a document an operator deliberately unpublished.
 * Absence of a record is not a record of absence.
 */

\AfricaGates\Support\Clock::boot();

if (!DB::schema()->hasTable('gates_legal_docs')) {
    echo "  · gates_legal_docs absent — nothing to repair\n";
    echo "cookie policy OK\n";
    return;
}

$shipped = LegalSeeder::documents();
$fixed   = 0;
$kept    = 0;

foreach (['cookies', 'privacy'] as $slug) {
    $body = (string) ($shipped[$slug]['body'] ?? '');
    if ($body === '') {
        echo "  · {$slug} is not a shipped document — skipped\n";
        continue;
    }

    $row = DB::table('gates_legal_docs')->where('slug', $slug)->first();
    if (!$row) {
        // Heals itself on the first request that asks for it. See the docblock.
        echo "  · {$slug} has never been installed — left for LegalService\n";
        continue;
    }

    if ($row->updated_by !== null) {
        echo "  · {$slug} has been edited by an administrator — their words kept\n";
        $kept++;
        continue;
    }

    if (trim((string) $row->body_html) === trim($body)) {
        echo "  · {$slug} already carries the corrected text\n";
        continue;
    }

    DB::table('gates_legal_docs')->where('slug', $slug)->update([
        'body_html'  => $body,
        // NOT stamped with an admin id: no administrator wrote this, and claiming one did
        // would make the next repair believe a person had edited it and leave it alone.
        'updated_at' => \Illuminate\Support\Carbon::now()->toDateTimeString(),
    ]);

    echo "  + {$slug} corrected\n";
    $fixed++;
}

echo "  · {$fixed} corrected, {$kept} left to their author\n";
echo "cookie policy OK\n";
