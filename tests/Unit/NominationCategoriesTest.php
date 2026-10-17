<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AwardService;
use AfricaGates\Services\NominationRules;
use AfricaGates\Support\NomineeKind;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Two or three categories, each with its own reason, and the evidence kept.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS BEING HELD, AND WHY EACH PART OF IT IS A SEPARATE TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * · THE RULES, at the boundary rather than only in the unit. `NominationRules` is a
 *   pure function and easy to test; what matters to a nominator is that the SERVICE
 *   refuses. A rule enforced in a class nothing calls is this repository's commonest
 *   shape, and the nomination path already has a live instance of it — the API door
 *   asked for six fields where the form asked for thirteen.
 *
 * · THE TRANSACTION. A nomination with no categories is not a smaller nomination, it
 *   is a broken one: nothing for the desk to judge and nothing for the category-fit
 *   analysis to compare. So a failure part-way through must leave no row at all.
 *
 * · THE DENORMALISED COPY. `gates_nominations.category_id` and `.reason` still carry
 *   the primary category, because about twenty readers take them from there. That is
 *   the `vote_count` / `gates_votes` arrangement, and this repository has a scar from
 *   it — five suites wrote the counter, never the ballot rows, and scored a different
 *   rule for years while passing. So the copy is asserted against the row it was
 *   copied FROM, not against the value that was submitted.
 *
 * · THE EVIDENCE EXISTS AT ALL. Before this, a supporting document was uploaded and
 *   its URL put into a string in an email. No column, nothing on the review desk, and
 *   the AI triage — whose job is to help a moderator judge — had never seen one.
 */
final class NominationCategoriesTest extends TestCase
{
    /**
     * THE PROGRAMME ID IS ASSIGNED BY THE DATABASE, NEVER TYPED.
     *
     * `gates_award_programmes.id` is `TINYINT UNSIGNED` — it stops at **255**. This
     * fixture used to seed a literal above that, which SQLite accepts without a word
     * and MySQL answers with `1264 Out of range value for column 'id'`, so every test
     * in this class errored on the only database that matters while the suite was
     * green here. Found by `scripts/mysql-parity.sh`, which is the only thing that
     * sees it.
     *
     * It is the fault `CLAUDE.md` opens with, in its milder form. The dangerous form
     * is `INSERT IGNORE`, which does not refuse an oversized id — it CLAMPS it to 255,
     * so thirty test files seeding `9800` all resolved to the same programme and a
     * nominee's submission silently matched another award's configuration.
     *
     * An `AUTO_INCREMENT` id cannot be out of range and cannot collide with a seeded
     * row, so nothing here needs to know how wide the column is.
     */
    private int $prog = 0;

    private const CYCLE = 3110;
    private const CAT_A = 31101;
    private const CAT_B = 31102;
    private const CAT_C = 31103;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'nc-prog', 'title' => 'Categories Awards', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => $this->prog, 'year' => 2026, 'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        DB::table('gates_award_categories')->insert([
            ['id' => self::CAT_A, 'cycle_id' => self::CYCLE, 'slug' => 'nc-a', 'title' => 'Teaching'],
            ['id' => self::CAT_B, 'cycle_id' => self::CYCLE, 'slug' => 'nc-b', 'title' => 'Leadership'],
            ['id' => self::CAT_C, 'cycle_id' => self::CYCLE, 'slug' => 'nc-c', 'title' => 'Community'],
        ]);
    }

    private function reason(string $seed): string
    {
        // 40 is the floor, so every fixture reason clears it by writing something a
        // person might actually write rather than by repeating a letter.
        return $seed . ' — sustained, documented work over several years that others can verify.';
    }

    /** @param array<string,mixed> $over */
    private function nominate(array $over = []): int
    {
        return (new AwardService())->submitNomination($over + [
            'programme_id'    => $this->prog,
            'nominee_name'    => 'Ada Lovelace',
            'nominee_kind'    => NomineeKind::PERSON,
            'country_code'    => 'NG',
            'nominator_name'  => 'Grace Hopper',
            'nominator_email' => 'grace@example.com',
            'nominee_email'   => 'ada@example.com',
            'categories'      => [
                self::CAT_A => $this->reason('Taught nine hundred pupils'),
                self::CAT_B => $this->reason('Rebuilt the school'),
            ],
        ], '127.0.0.1');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The rules, enforced where a nominator meets them
    // ══════════════════════════════════════════════════════════════════════════

    public function test_one_category_is_refused_by_the_service_and_not_only_the_rules(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least two/i');

        $this->nominate(['categories' => [self::CAT_A => $this->reason('Only one')]]);
    }

    public function test_four_categories_are_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/up to 3/i');

        $this->nominate(['categories' => [
            self::CAT_A => $this->reason('One'),   self::CAT_B => $this->reason('Two'),
            self::CAT_C => $this->reason('Three'), 999 => $this->reason('Four'),
        ]]);
    }

    public function test_a_reason_under_forty_characters_is_refused_with_the_designed_sentence(): void
    {
        $this->expectException(\RuntimeException::class);
        // The SAME string the live counter shows. Two sentences for one rule is how a
        // form comes to tell somebody two different things about one field.
        $this->expectExceptionMessage(NominationRules::SHORT_REASON);

        $this->nominate(['categories' => [
            self::CAT_A => $this->reason('Long enough'),
            self::CAT_B => 'Too short.',
        ]]);
    }

    public function test_a_category_from_another_edition_is_refused_in_the_list_too(): void
    {
        // `categoryInCycle()` guards the single column; this list never reaches it.
        $other = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => self::CYCLE + 7, 'slug' => 'nc-alien', 'title' => 'Alien',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not part of this award/i');

        $this->nominate(['categories' => [
            self::CAT_A => $this->reason('Fine'),
            $other      => $this->reason('From another edition'),
        ]]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // What lands in the database
    // ══════════════════════════════════════════════════════════════════════════

    public function test_both_categories_are_stored_with_their_own_reasons_in_order(): void
    {
        $id = $this->nominate();

        $rows = DB::table('gates_nomination_categories')
            ->where('nomination_id', $id)->orderBy('sort_order')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([self::CAT_A, self::CAT_B], array_map(
            static fn ($r): int => (int) $r->category_id, $rows->all()
        ));
        $this->assertStringContainsString('Taught nine hundred pupils', $rows[0]->reason);
        $this->assertStringContainsString('Rebuilt the school', $rows[1]->reason);
        // The order is the order the nominator chose, which is the only ranking anybody
        // stated. Nothing re-ranks it by a guess.
        $this->assertSame([0, 1], array_map(static fn ($r): int => (int) $r->sort_order, $rows->all()));
    }

    public function test_the_denormalised_copy_matches_the_row_it_was_copied_from(): void
    {
        $id  = $this->nominate();
        $nom = DB::table('gates_nominations')->where('id', $id)->first();

        $primary = DB::table('gates_nomination_categories')
            ->where('nomination_id', $id)->orderBy('sort_order')->first();

        // Against the ROW, not against what was submitted: a copy that agrees with the
        // input but not with the table is the failure this assertion exists for.
        $this->assertSame((int) $primary->category_id, (int) $nom->category_id);
        $this->assertSame(trim($primary->reason), trim((string) $nom->reason));
    }

    public function test_the_same_category_cannot_be_named_twice(): void
    {
        // PHP array keys make this unreachable from the wire shape, which is half the
        // reason the wire shape is `categories[<id>] = <reason>`. The UNIQUE is the
        // other half — a rule in a validator is a rule the next door skips.
        $id = $this->nominate();

        $this->expectException(\Throwable::class);
        DB::table('gates_nomination_categories')->insert([
            'nomination_id' => $id, 'category_id' => self::CAT_A, 'reason' => $this->reason('again'),
            'sort_order' => 9,
        ]);
    }

    public function test_a_refused_nomination_leaves_no_row(): void
    {
        // Caught by the RULES, before any insert — so this is about the validator
        // running first, not about the transaction. The transaction is held below.
        $before = (int) DB::table('gates_nominations')->count();

        try {
            $this->nominate(['categories' => [self::CAT_A => 'short']]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, (int) DB::table('gates_nominations')->count(),
            'a refused nomination left a row behind');
    }

    public function test_a_failure_writing_the_children_leaves_no_parent(): void
    {
        // THE TRANSACTION, tested by breaking the second write rather than the first.
        // The obvious probe — a bad link, a short reason — never reaches the insert at
        // all, because the rules run in front of it; a test written that way passes on
        // an unwrapped insert and proves nothing. Measured: with the transaction
        // removed, this leaves a nomination with no categories, which is a row the
        // review desk shows with nothing to judge.
        //
        // ── AND THE MECHANISM MAY NOT BE DDL ────────────────────────────────────
        //
        // This used to force the failure with `DROP TABLE gates_nomination_evidence`,
        // which is certain to break the child write and is WRONG ON THE ONLY DATABASE
        // THAT MATTERS: **DDL implicitly COMMITs on MySQL**, so the parent insert was
        // already permanent before any rollback could run. The test therefore FAILED on
        // MySQL while passing here — against a transaction that was working perfectly.
        // The guard was broken, not the code. `scripts/mysql-parity.sh` found it.
        //
        // A UNIQUE violation needs no DDL and is enforced by both drivers, so the
        // transaction is left intact and the failure lands where it is meant to.
        $first = $this->nominate();

        // The id the next nomination will take. Both drivers hand out max+1 here, and
        // the assertion below fails loudly if that ever stops being true rather than
        // passing over a collision that never happened.
        $next = $first + 1;

        DB::table('gates_nomination_categories')->insert([
            'nomination_id' => $next,
            'category_id'   => self::CAT_A,
            'reason'        => $this->reason('Planted by the test to collide'),
            'sort_order'    => 0,
        ]);

        $before = (int) DB::table('gates_nominations')->count();

        try {
            $this->nominate();
            $this->fail('the child write did not fail, so this proves nothing');
        } catch (\Throwable) {
            // expected — the UNIQUE on (nomination_id, category_id)
        }

        $this->assertSame($before, (int) DB::table('gates_nominations')->count(),
            'the nomination survived a failed child write — it has no categories');
        $this->assertSame($first, (int) DB::table('gates_nominations')->max('id'),
            'the parent row was not rolled back');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Evidence
    // ══════════════════════════════════════════════════════════════════════════

    public function test_evidence_links_are_stored_rather_than_emailed_and_forgotten(): void
    {
        $id = $this->nominate(['evidence_links' => [
            'https://example.org/the-award',
            'https://news.example/profile',
        ]]);

        $ev = DB::table('gates_nomination_evidence')->where('nomination_id', $id)->get();

        $this->assertCount(2, $ev);
        $this->assertSame(['link', 'link'], array_map(static fn ($r): string => $r->kind, $ev->all()));
        $this->assertSame('example.org', $ev[0]->label, 'the host is kept so a desk row reads as something');
    }

    public function test_a_link_that_is_not_a_web_address_never_reaches_the_table(): void
    {
        // This value ends up in an `href` a moderator clicks. `javascript:` parses
        // perfectly well as a URL.
        $this->expectException(\RuntimeException::class);

        $this->nominate(['evidence_links' => ['javascript:alert(1)']]);
    }

    public function test_more_than_five_pieces_of_evidence_are_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/up to 5/i');

        $this->nominate(['evidence_links' => [
            'https://a.example/1', 'https://b.example/2', 'https://c.example/3',
            'https://d.example/4', 'https://e.example/5', 'https://f.example/6',
        ]]);
    }

    public function test_a_stored_file_is_recorded_against_the_nomination(): void
    {
        $id = $this->nominate();

        $this->assertTrue(AwardService::recordEvidenceFile(
            $id, '/uploads/nominations/proof.pdf', 'proof.pdf', 'application/pdf', 91_234
        ));

        $file = DB::table('gates_nomination_evidence')
            ->where('nomination_id', $id)->where('kind', 'file')->first();

        $this->assertNotNull($file, 'the upload was recorded nowhere — the fault this replaced');
        $this->assertSame('/uploads/nominations/proof.pdf', $file->path);
        $this->assertSame(91_234, (int) $file->bytes);
    }

    public function test_the_byte_count_survives_a_real_file_size(): void
    {
        // TINYINT would have capped this at 255 and MySQL would have stored 255 without
        // complaint — the ceiling this codebase has already paid for once on sort_order.
        $id = $this->nominate();
        AwardService::recordEvidenceFile($id, '/uploads/n/big.pdf', 'big.pdf', 'application/pdf',
            NominationRules::MAX_FILE_BYTES);

        $file = DB::table('gates_nomination_evidence')
            ->where('nomination_id', $id)->where('kind', 'file')->first();

        $this->assertSame(NominationRules::MAX_FILE_BYTES, (int) $file->bytes);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Nominee kind
    // ══════════════════════════════════════════════════════════════════════════

    public function test_an_organisation_is_stored_as_one_and_needs_no_second_word(): void
    {
        $id = $this->nominate([
            'nominee_kind' => NomineeKind::ORGANISATION,
            'nominee_name' => 'Andela',
        ]);

        $row = DB::table('gates_nominations')->where('id', $id)->first();
        $this->assertSame(NomineeKind::ORGANISATION, $row->nominee_kind);
    }

    public function test_an_unreadable_kind_becomes_person_rather_than_landing_outside_the_enum(): void
    {
        // A value outside an ENUM is `Data truncated` on MySQL — not an error anybody
        // notices — and every row that existed before this column did IS a person.
        $id  = $this->nominate(['nominee_kind' => 'sasquatch']);
        $row = DB::table('gates_nominations')->where('id', $id)->first();

        $this->assertSame(NomineeKind::PERSON, $row->nominee_kind);
    }
}
