<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Console\Commands\RegistryBackfillCommand;
use AfricaGates\Services\{PartnerOrg, RegistryCheck};
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Bringing pre-normalisation CAC numbers into one spelling.
 *
 * ── WHAT THE COMMAND IS ACTUALLY FOR ────────────────────────────────────────
 *
 * Not tidiness. `PartnerOrg::cacOnFileElsewhere()` compares stored strings, so a row
 * written before the write-time rule and a row written after it can carry THE SAME
 * REGISTRATION and never collide. The duplicate check — the only fraud signal here that
 * costs nothing — is blind to exactly the pairs most worth catching, because one half of
 * each pair predates the rule. This is what makes them visible.
 *
 * So the assertions below are about three properties, in order of how much they matter:
 * the collision is FOUND, the dry-run writes NOTHING, and a shape the platform does not
 * recognise is never overwritten with a guess.
 */
final class RegistryBackfillTest extends TestCase
{
    private function org(string $slug, string $name, ?string $cac, string $kind = PartnerOrg::KIND_VENDOR): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => $slug, 'name' => $name, 'legal_name' => $name,
            'kind' => $kind, 'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => $cac, 'status' => PartnerOrg::STATUS_DRAFT,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function backfill(array $opts = []): CommandTester
    {
        $app = new Application();
        $app->add(new RegistryBackfillCommand());
        $t = new CommandTester($app->find('registry:backfill'));
        $t->execute($opts);
        return $t;
    }

    private function stored(int $id): ?string
    {
        $v = DB::table('gates_partner_orgs')->where('id', $id)->value('cac_number');
        return $v === null ? null : (string) $v;
    }

    // ───────────────────────────── the dry run ──────────────────────────────

    /** Nothing is written without `--commit`. The same contract as privacy:purge. */
    public function test_the_dry_run_changes_nothing(): void
    {
        $id = $this->org('a', 'Ada Crafts', 'rc 5550001');

        $t = $this->backfill();

        $this->assertSame('rc 5550001', $this->stored($id), 'a dry-run wrote to the database');
        $this->assertStringContainsString('dry-run', $t->getDisplay());
        $this->assertStringContainsString('RC/5550001', $t->getDisplay(),
            'the report has to show what it would become, or there is nothing to approve');
        $this->assertSame(0, $t->getStatusCode());
    }

    // ──────────────────────────── normalisation ─────────────────────────────

    /** Every spelling of one registration converges on the stored form. */
    public function test_commit_normalises_every_recognised_shape(): void
    {
        $ids = [];
        foreach (['rc 5550001', 'RC-5550001', 'rc/5550001', 'RC5550001'] as $i => $typed) {
            $ids[] = $this->org('o' . $i, 'Org ' . $i, $typed);
        }

        $this->backfill(['--commit' => true]);

        foreach ($ids as $id) {
            $this->assertSame('RC/5550001', $this->stored($id));
        }
    }

    /** A row already in the stored form is left alone and counted as such. */
    public function test_an_already_normalised_row_is_untouched(): void
    {
        $id = $this->org('a', 'Already Fine', 'BN/7654321');

        $t = $this->backfill(['--commit' => true]);

        $this->assertSame('BN/7654321', $this->stored($id));
        $this->assertStringContainsString('1 already in the stored form', $t->getDisplay());
    }

    /**
     * A shape the platform does not recognise is REPORTED and never rewritten.
     *
     * A legacy row may hold something this platform has not seen, and the admin write path
     * is deliberately lenient for that reason. Writing a guess over the only record of what
     * was originally entered — on the record used to decide whether an organisation is real
     * — is worse than an odd-looking number.
     */
    public function test_an_unrecognised_number_is_reported_and_left_verbatim(): void
    {
        $phone = $this->org('a', 'Mystery Trader', '08031234567');
        $odd   = $this->org('b', 'Odd Shape', 'XX/999');

        $t = $this->backfill(['--commit' => true]);

        $this->assertSame('08031234567', $this->stored($phone));
        $this->assertSame('XX/999', $this->stored($odd));
        $this->assertStringContainsString('Not a recognised shape', $t->getDisplay());
        $this->assertStringContainsString('2 not a recognised shape', $t->getDisplay());
    }

    // ──────────────────────── the reason it exists ──────────────────────────

    /**
     * THE ONE THAT MATTERS. Two rows carrying one registration in different spellings are
     * invisible to the duplicate check until this has run, and the command says so.
     */
    public function test_a_collision_hidden_by_spelling_is_found_and_named(): void
    {
        $a = $this->org('a', 'Ada Crafts', 'rc 5550001');
        $b = $this->org('b', 'Ada Crafts Ltd', 'RC-5550001');

        // Before: each row's OWN stored value finds nothing, which is the blindness this
        // command exists to remove. Asked with b's string, the lookup misses a — because a
        // holds a different string for the same registration.
        $this->assertNull(PartnerOrg::cacOnFileElsewhere('RC-5550001', $b),
            'this test proves nothing if they already collided');
        $this->assertNull(PartnerOrg::cacOnFileElsewhere('rc 5550001', $a),
            'this test proves nothing if they already collided');

        $t = $this->backfill(['--commit' => true]);
        $out = $t->getDisplay();

        $this->assertStringContainsString('SAME NUMBER, MORE THAN ONE ORGANISATION', $out);
        $this->assertStringContainsString('RC/5550001', $out);
        $this->assertStringContainsString('Ada Crafts', $out);
        $this->assertStringContainsString('Ada Crafts Ltd', $out);

        // After: the check the platform runs on every new registration can see it.
        $twin = PartnerOrg::cacOnFileElsewhere('RC/5550001', $b);
        $this->assertNotNull($twin);
        $this->assertSame($a, (int) $twin->id);
    }

    /** And the cross-era case: a legacy row against one written under the new rule. */
    public function test_a_legacy_row_collides_with_one_written_after_the_rule(): void
    {
        $new = PartnerOrg::registerVendor([
            'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'name' => 'Bright Futures', 'legal_name' => 'Bright Futures Initiative',
            'contact_email' => 'bf-' . bin2hex(random_bytes(3)) . '@example.test',
            'password' => 'correct horse battery', 'cac_number' => 'IT/445566',
        ]);
        $this->assertTrue($new['ok'], $new['message'] ?? '');
        $old = $this->org('legacy', 'Bright Futures Old', 'it 445566');

        $out = $this->backfill(['--commit' => true])->getDisplay();

        $this->assertStringContainsString('IT/445566', $out);
        $this->assertStringContainsString('Bright Futures Old', $out);
        $this->assertSame('IT/445566', $this->stored($old));
    }

    /** A collision is reported and NOT blocked — which of two claimants is real is a judgement. */
    public function test_a_collision_does_not_stop_the_rewrite(): void
    {
        $a = $this->org('a', 'One', 'rc 111');
        $b = $this->org('b', 'Two', 'RC-111');

        $this->assertSame(0, $this->backfill(['--commit' => true])->getStatusCode());
        $this->assertSame('RC/111', $this->stored($a));
        $this->assertSame('RC/111', $this->stored($b));
    }

    // ────────────────────────────── the checks ──────────────────────────────

    /** Queueing is opt-in, because a configured verifier is a paid third party. */
    public function test_registry_checks_are_only_queued_when_asked_for(): void
    {
        $this->org('a', 'Ada Crafts', 'rc 5550001');

        $this->backfill(['--commit' => true]);
        $this->assertSame(0, DB::table('gates_jobs')->where('type', PartnerOrg::JOB_REGISTRY)->count());

        $this->backfill(['--commit' => true, '--queue-checks' => true]);
        $this->assertSame(1, DB::table('gates_jobs')->where('type', PartnerOrg::JOB_REGISTRY)->count());
    }

    /**
     * Every organisation with a number, not only the rewritten ones. The check also derives
     * the duplicate note, and a row that was already in the right shape has never had one.
     */
    public function test_the_queue_covers_rows_that_needed_no_rewrite(): void
    {
        $fine = $this->org('a', 'Already Fine', 'BN/7654321');

        $this->backfill(['--commit' => true, '--queue-checks' => true]);

        $this->assertSame(1, DB::table('gates_jobs')
            ->where('type', PartnerOrg::JOB_REGISTRY)
            ->where('dedupe_key', 'registry:' . $fine)->count());
    }

    /** An organisation with no number is not in scope at all. */
    public function test_rows_without_a_number_are_ignored(): void
    {
        $this->org('a', 'No Number', null);

        $t = $this->backfill(['--commit' => true]);

        $this->assertStringContainsString('No organisation has a CAC number', $t->getDisplay());
        $this->assertSame(0, DB::table('gates_jobs')->where('type', PartnerOrg::JOB_REGISTRY)->count());
    }

    /** `--limit` bounds a first pass on a large table, oldest first. */
    public function test_limit_bounds_the_pass(): void
    {
        $first  = $this->org('a', 'First', 'rc 111');
        $second = $this->org('b', 'Second', 'rc 222');

        $this->backfill(['--commit' => true, '--limit' => '1']);

        $this->assertSame('RC/111', $this->stored($first));
        $this->assertSame('rc 222', $this->stored($second), 'the limit was not respected');
    }

    // ────────────────────── and the note it makes possible ──────────────────

    /**
     * The kind-of-registration note is for donation partners only.
     *
     * `cacFormat` says a non-profit is normally an incorporated trustee and an RC or BN is
     * "worth asking about" — true of a body collecting charitable gifts, and wrong of a food
     * trader, for whom a business name is the right registration. Found by running the
     * backfill against real rows and reading what it wrote: the line appeared on every
     * vendor, which is how a note becomes something reviewers learn to ignore.
     */
    public function test_the_non_profit_note_is_not_attached_to_a_vendor(): void
    {
        $vendor  = $this->org('v', 'Adaeze Foods', 'BN/9988776', PartnerOrg::KIND_VENDOR);
        $partner = $this->org('p', 'Bright Futures', 'BN/9988777', PartnerOrg::KIND_PARTNER);

        PartnerOrg::runRegistryCheck($vendor);
        PartnerOrg::runRegistryCheck($partner);

        $v = (string) DB::table('gates_partner_orgs')->where('id', $vendor)->value('cac_check_note');
        $p = (string) DB::table('gates_partner_orgs')->where('id', $partner)->value('cac_check_note');

        $this->assertStringNotContainsString('incorporated trustee', $v,
            'a business name is exactly the right registration for a trader');
        $this->assertStringContainsString('incorporated trustee', $p,
            'a body collecting charitable gifts with a BN is worth a reviewer noticing');
    }
}
