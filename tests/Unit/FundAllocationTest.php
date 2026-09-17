<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\FundAllocation;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * WHAT AFRICA GATES TELLS A DONOR THEIR MONEY IS FOR.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT WAS THREE LINES IN A TEMPLATE, AND BOTH THINGS WRONG WITH THAT WERE SERIOUS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `donate.twig` carried a `{% set ALLOC = [...] %}` naming three destinations under "Where
 * donations go", immediately above a line promising the fund is independently audited.
 *
 *   · **It could not be changed.** There is no shell on production, so a representation
 *     about the use of charitable funds was editable only by somebody who could deploy —
 *     not by the people answerable for it.
 *   · **It was rendered on other people's appeals.** The block was ungated, so
 *     `/giving/{partner}` told a donor their money funds Africa GATES' scholarships, for a
 *     payment that settles into the partner's own subaccount and which Africa GATES never
 *     holds. A false statement about somebody else's money on the page collecting it.
 */
final class FundAllocationTest extends TestCase
{
    private function store(array $rows): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => FundAllocation::KEY], ['value' => json_encode($rows)]);
    }

    // ══ the gate ═════════════════════════════════════════════════════════════

    /**
     * NOTHING OF OURS IS PUBLISHED ON SOMEBODY ELSE'S APPEAL.
     *
     * The assertion this file exists for.
     */
    public function test_a_partner_appeal_never_carries_our_allocation(): void
    {
        $this->store([['title' => 'Scholarships', 'body' => 'School fees.']]);

        $org = (object) ['id' => 1, 'name' => 'Borehole Trust'];

        $this->assertSame([], FundAllocation::forOrg($org),
            "a donor on a partner's page was told their money funds our scholarships, for "
            . 'a payment that settles into the partner\'s own account');

        $this->assertNotSame([], FundAllocation::forOrg(null),
            'and it must still publish on our own page');
    }

    /** The public template gates it, and gates the mission quote with it. */
    public function test_the_template_gates_both_blocks(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/donate.twig');

        $this->assertStringNotContainsString('{% set ALLOC', $tpl,
            'the allocation is hardcoded again, so nobody without a deploy can change what '
            . 'this page promises about charitable funds');
        $this->assertStringContainsString('{% if allocation %}', $tpl);
        $this->assertStringContainsString('{% if not org %}', $tpl,
            'the mission quote is a statement about Africa GATES\' purpose and was '
            . 'displacing the thing a donor came to a partner\'s page to read');
    }

    // ══ what it publishes ════════════════════════════════════════════════════

    /**
     * AN UNCONFIGURED DEPLOYMENT PUBLISHES NOTHING, AND NOT THE OLD COPY.
     *
     * Carrying the three hardcoded lines as a fallback would keep exactly the problem: a
     * fund that has never decided what it funds would go on publishing a claim nobody
     * chose, and the operator could not tell a default from their own words.
     */
    public function test_nothing_configured_publishes_nothing(): void
    {
        DB::table('gates_settings')->where('key_name', FundAllocation::KEY)->delete();

        $this->assertSame([], FundAllocation::rows());
        $this->assertSame([], FundAllocation::forOrg(null));
    }

    public function test_it_publishes_what_an_operator_typed(): void
    {
        FundAllocation::save(
            ['Scholarships', 'Mentorship'],
            ['School and training fees.', 'Pairing children with verified leaders.']);

        $this->assertSame([
            ['title' => 'Scholarships', 'body'  => 'School and training fees.'],
            ['title' => 'Mentorship',   'body'  => 'Pairing children with verified leaders.'],
        ], FundAllocation::rows());
    }

    /** A destination with no name is not one; a row with no body is simply undescribed. */
    public function test_a_nameless_destination_is_dropped_and_takes_its_body_with_it(): void
    {
        FundAllocation::save(['', 'Grants', '  '], ['orphaned body', '', 'also orphaned']);

        $this->assertSame([['title' => 'Grants', 'body' => '']], FundAllocation::rows());
    }

    /**
     * RE-VALIDATED ON THE WAY OUT, NOT ONLY IN.
     *
     * A stored document survives the code that wrote it — an import, a restore, an earlier
     * version of the form. Trusting it because it was checked when it was saved is trusting
     * a past version of this file.
     */
    public function test_a_document_that_did_not_come_from_the_form_is_still_cleaned(): void
    {
        $this->store([
            ['title' => str_repeat('x', 400), 'body' => str_repeat('y', 900)],
            ['title' => '', 'body' => 'no name'],
            'not even an array',
        ]);

        $rows = FundAllocation::rows();

        $this->assertCount(1, $rows);
        $this->assertSame(FundAllocation::MAX_TITLE, mb_strlen($rows[0]['title']));
        $this->assertSame(FundAllocation::MAX_BODY,  mb_strlen($rows[0]['body']));
    }

    public function test_a_broken_document_publishes_nothing_rather_than_throwing(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => FundAllocation::KEY], ['value' => '{not json']);

        $this->assertSame([], FundAllocation::rows(),
            'a page that 500s because a setting will not parse is worse than one with no '
            . 'allocation block');
    }

    public function test_it_is_capped(): void
    {
        FundAllocation::save(array_fill(0, 20, 'Destination'), array_fill(0, 20, 'Body'));

        $this->assertCount(FundAllocation::MAX_ROWS, FundAllocation::rows());
    }

    /**
     * DECIDING TO PUBLISH NOTHING IS DIFFERENT FROM NEVER HAVING BEEN ASKED.
     *
     * Saving an empty list stores an empty list rather than deleting the row, so the form
     * can tell the two apart.
     */
    public function test_saving_nothing_records_the_decision(): void
    {
        FundAllocation::save(['Scholarships'], ['fees']);
        FundAllocation::save([], []);

        $this->assertSame([], FundAllocation::rows());
        $this->assertSame('[]',
            DB::table('gates_settings')->where('key_name', FundAllocation::KEY)->value('value'));
    }
}
