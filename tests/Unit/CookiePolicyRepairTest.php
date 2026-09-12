<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalSeeder;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The repair that has to reach production, and the edits it must not touch.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A MIGRATION NEEDED A TEST OF ITS OWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_legal_docs` starts empty in the harness, so the migration runs on every suite
 * execution, prints "never been installed", and does nothing. It would therefore have
 * shipped completely untested — green here and load-bearing there, which is the shape of
 * the `gates_event_invites.audience` fault: a corrected definition that dev built fresh and
 * production never applied, with the suite green throughout.
 *
 * Both branches are exercised here against rows planted to look like production's.
 */
final class CookiePolicyRepairTest extends TestCase
{
    private const STALE = '<h2>The short version</h2><p>We set <strong>one cookie</strong>. '
                        . 'We run <strong>no analytics, no advertising and no third-party '
                        . 'trackers</strong>.</p>';

    private function runRepair(): void
    {
        ob_start();
        require dirname(__DIR__, 2) . '/database/migrations/2027_01_23_cookie_policy_repair.php';
        ob_end_clean();
    }

    private function plant(string $slug, string $body, ?int $updatedBy): void
    {
        DB::table('gates_legal_docs')->where('slug', $slug)->delete();
        DB::table('gates_legal_docs')->insert([
            'slug'       => $slug,
            'title'      => 'Cookies',
            'body_html'  => $body,
            'is_published' => 1,
            'sort_order' => 3,
            'updated_by' => $updatedBy,
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function body(string $slug): string
    {
        return (string) DB::table('gates_legal_docs')->where('slug', $slug)->value('body_html');
    }

    public function test_a_seeded_row_still_carrying_the_false_claims_is_corrected(): void
    {
        $this->plant('cookies', self::STALE, null);
        $this->runRepair();

        $now = strtolower($this->body('cookies'));

        $this->assertStringNotContainsString('we set <strong>one cookie</strong>', $now,
            'production would still be publishing "one cookie" while setting four');
        $this->assertStringNotContainsString('no analytics', $now,
            'production would still be publishing "no analytics" while counting every arrival');
        $this->assertSame(trim(LegalSeeder::documents()['cookies']['body']), trim($this->body('cookies')));
    }

    public function test_a_document_an_administrator_has_edited_is_left_alone(): void
    {
        // `updated_by` is stamped by LegalService::save() on every edit and is NULL on a
        // seeded row — this platform's own contemporaneous record that no person has
        // claimed these words. Evidence, not a heuristic.
        $mine = '<h2>Our cookies</h2><p>Words an operator wrote and meant.</p>';
        $this->plant('cookies', $mine, 7);
        $this->runRepair();

        $this->assertSame($mine, $this->body('cookies'),
            'a migration overwrote an administrator\'s own published words');
    }

    public function test_the_generated_facts_reach_an_edited_document_anyway(): void
    {
        // The half that makes leaving an edit alone safe: the prose is the promise and the
        // generated section is the fact, so even a document nobody corrected still carries
        // the real list of cookies under its own heading.
        $page = \AfricaGates\Services\LegalDocument::bodyHtml([
            'slug' => 'cookies', 'body_html' => self::STALE,
        ]);

        foreach (\AfricaGates\Support\CookieRegistry::names() as $name) {
            $this->assertStringContainsString($name, $page);
        }
        $this->assertStringContainsString('Counting arrivals', $page);
    }

    public function test_a_missing_document_is_not_created_by_the_repair(): void
    {
        // A missing policy heals itself on the first request (LegalService::get() seeds a
        // shipped document that has never been installed — that is why /refunds stopped
        // 404ing). Creating one here would resurrect a document an operator deliberately
        // unpublished: absence of a record is not a record of absence.
        DB::table('gates_legal_docs')->where('slug', 'cookies')->delete();
        $this->runRepair();

        $this->assertNull(DB::table('gates_legal_docs')->where('slug', 'cookies')->first());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->plant('cookies', self::STALE, null);
        $this->runRepair();
        $once = $this->body('cookies');

        $this->runRepair();
        $this->assertSame($once, $this->body('cookies'));

        // And it never claims an administrator wrote the correction, or the next repair
        // would read the stamp as somebody's edit and leave a stale document alone.
        $this->assertNull(DB::table('gates_legal_docs')->where('slug', 'cookies')->value('updated_by'));
    }
}
