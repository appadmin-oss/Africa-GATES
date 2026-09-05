<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NominationAftercare;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What has to happen after a nomination is stored — through EITHER door.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There are two doors a nomination comes through, and only one did any of this.
 *
 * NominationController — the web form — sent the operator brief, the nominator's
 * confirmation, the nominee's notification, SMS and WhatsApp, queued the AI triage
 * and fired the webhook, all inline. Its own comment called itself "the only door
 * nominations come through".
 *
 * It was not. ApiController::submitNomination is a live public endpoint at
 * POST /api/nominations, and it inserted the row and returned `ok`. Nothing else. So
 * a nomination arriving through the API was invisible: no operator knew it existed,
 * the nominator got no confirmation and no reference, the nominee never learned they
 * had been nominated, the review desk got no triage, and no webhook fired. It waited
 * in the table for somebody to notice it by chance.
 *
 * ── AND THE REFERENCE DID NOT MATCH THE RECORD ───────────────────────────────
 *
 * Found while extracting this, and invisible in the current calendar year.
 * Reference::nomination() takes an optional year defaulting to `date('Y')`.
 * AwardService PERSISTS the reference using the CYCLE's year; the controller
 * recomputed it with no year. Those agree only while the cycle year equals the
 * current year — so a cycle labelled for another year, or a nomination submitted on
 * the 31st of December, told the nominator one reference and stored another.
 *
 * Every surface quotes it: the confirmation email, both SMS messages, the webhook,
 * the success page. A reference that does not match the record is worse than none,
 * because support looks it up, finds nothing, and tells a real nominator their entry
 * does not exist.
 */
final class NominationAftercareTest extends TestCase
{
    /** @return array{0:int, 1:array<string,mixed>} */
    private function nomination(array $over = []): array
    {
        $data = $over + [
            'programme_id'     => 1,
            'category_id'      => 1,
            'nominee_name'     => 'ADA OKONKWO',
            'nominee_email'    => 'Ada@Example.com',
            'country_code'     => 'ng',
            'reason'           => 'She rebuilt the community theatre single-handed.',
            'nominator_name'   => 'okun alimosho',
            'nominator_email'  => 'Okun@Example.com',
            'programme_title'  => 'Cultural Power Awards',
        ];

        // A real category to resolve, created rather than assumed: the whole point
        // of the category line is that it names something that exists.
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => (int) $data['category_id'] ?: 1, 'cycle_id' => 1,
            'slug' => 'aftercare-fixture', 'title' => 'Community Builder of the Year',
        ]);

        $id = (int) DB::table('gates_nominations')->insertGetId([
            // No programme_id column: a nomination belongs to a CYCLE, and the
            // programme is reached through it. The form field of that name is only
            // used to label the message.
            'cycle_id'        => 1,
            'category_id'     => (int) $data['category_id'],
            'nominee_name'    => (string) $data['nominee_name'],
            'nominator_name'  => (string) $data['nominator_name'],
            'nominator_email' => (string) $data['nominator_email'],
            'reason'          => (string) $data['reason'],
            'country_code'    => 'NG',
            'status'          => 'pending',
            'created_at'      => '2026-08-01 10:00:00',
        ]);

        return [$id, $data];
    }

    // ── the reference ───────────────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS. It returns the reference ON THE ROW, not a freshly
     * computed one that may disagree with it.
     *
     * The stored value here is deliberately a year that is NOT the current one,
     * which is exactly the case the old code got wrong and the current calendar
     * year hides.
     */
    public function test_it_reports_the_reference_that_is_actually_on_the_record(): void
    {
        [$id, $data] = $this->nomination();
        $stored = 'AGN-2029-ZZZZZZ-Q';
        DB::table('gates_nominations')->where('id', $id)->update(['reference' => $stored]);

        $out = NominationAftercare::run($data, $id, 'https://example.test');

        $this->assertSame($stored, $out['reference'],
            'a recomputed reference can disagree with the stored one, and support cannot '
            . 'find a nomination by a reference that is not on it');
    }

    /**
     * With nothing stored — an unmigrated database, the only case where there is
     * nothing to disagree with — it still produces something usable rather than an
     * empty string in the middle of an email.
     */
    public function test_it_falls_back_to_computing_one_when_the_column_is_empty(): void
    {
        [$id, $data] = $this->nomination();
        DB::table('gates_nominations')->where('id', $id)->update(['reference' => null]);

        $out = NominationAftercare::run($data, $id, 'https://example.test');

        $this->assertStringStartsWith('AGN-', $out['reference']);
    }

    // ── normalisation, once, for every door ─────────────────────────────────

    /**
     * Forms get filled in on phones with caps lock on. The same person arrived as
     * ADA OKONKWO, ada okonkwo and Ada Okonkwo, and the ballot rendered all three —
     * so the tidying happens at the one place all doors pass through.
     */
    public function test_names_are_normalised_and_the_email_lower_cased(): void
    {
        [$id, $data] = $this->nomination();

        $out = NominationAftercare::run($data, $id, 'https://example.test');

        $this->assertSame('Ada Okonkwo', $out['nominee']);
        $this->assertSame('ada@example.com', $out['nominee_email']);
    }

    /** The category is named, not just the programme — they are different questions. */
    public function test_the_category_is_resolved_and_appended_to_the_programme(): void
    {
        [$id, $data] = $this->nomination();
        $title = (string) DB::table('gates_award_categories')
            ->where('id', (int) $data['category_id'])->value('title');
        $this->assertNotSame('', $title, 'the fixture needs a category for this to mean anything');

        $out = NominationAftercare::run($data, $id, 'https://example.test');

        $this->assertStringContainsString('Cultural Power Awards', $out['category']);
        $this->assertStringContainsString($title, $out['category']);
    }

    /** No category chosen: the programme alone, with no dangling separator. */
    public function test_with_no_category_it_names_the_programme_alone(): void
    {
        [$id, $data] = $this->nomination(['category_id' => 0]);

        $out = NominationAftercare::run($data, $id, 'https://example.test');

        $this->assertSame('Cultural Power Awards', $out['category']);
        $this->assertStringNotContainsString('·', $out['category']);
    }

    // ── the review desk ─────────────────────────────────────────────────────

    /**
     * AI triage is queued. This is what puts a score, a summary and a duplicate
     * check in front of the moderator; an API nomination used to arrive with none of
     * it, so it was reviewed with less information than a web one for no reason.
     */
    public function test_it_queues_the_triage_that_the_review_desk_reads(): void
    {
        [$id, $data] = $this->nomination();
        $before = DB::table('gates_jobs')->count();

        NominationAftercare::run($data, $id, 'https://example.test');

        $this->assertGreaterThan($before, DB::table('gates_jobs')->count(),
            'nothing was enqueued, so the moderator sees an untriaged nomination');
    }

    // ── the operator's own spreadsheet ──────────────────────────────────────

    /**
     * The `nominations` tab finally gets written to.
     *
     * GoogleSheetsService has had a pushNomination() since it was written and nothing
     * ever called it, while pushRegistration() IS called from the registry form. An
     * operator who followed the setup note in that class — deploy the Apps Script, put
     * its /exec URL in GAS_URL — watched registrations arrive and the nominations tab
     * stay empty forever, with no error to explain it. The tab is declared in
     * config/AfricaGATES_AppScript.gs; only the writer was missing.
     */
    public function test_it_pushes_the_nomination_to_the_operators_sheet(): void
    {
        [$id, $data] = $this->nomination();
        $spy = new class ('https://example.test/exec') extends \AfricaGates\Services\GoogleSheetsService {
            public array $pushed = [];
            public function push(string $sheet, array $row): bool
            {
                $this->pushed[] = ['sheet' => $sheet, 'row' => $row];
                return true;   // never a real HTTPS call from a test
            }
        };

        NominationAftercare::run($data, $id, 'https://example.test', null, [], $spy);

        $this->assertCount(1, $spy->pushed);
        $this->assertSame('nominations', $spy->pushed[0]['sheet'],
            'the Apps Script routes on the sheet name');
    }

    /**
     * And it sends only what the deployed script actually reads.
     *
     * config/AfricaGATES_AppScript.gs takes six fields for this sheet. Sending the
     * whole submission would put the nominator's phone, address and age range on the
     * wire to be discarded on arrival — a disclosure with no purpose. `reference` is
     * the one extra: the script ignores unknown keys, so it is harmless today and
     * there if the operator ever adds the column.
     */
    public function test_the_sheet_row_carries_no_more_than_the_script_reads(): void
    {
        [$id, $data] = $this->nomination([
            'nominator_phone'     => '+2348031234567',
            'nominator_age_range' => '25-34',
            'nominee_phone'       => '+2348039999999',
        ]);
        $spy = new class ('https://example.test/exec') extends \AfricaGates\Services\GoogleSheetsService {
            public array $pushed = [];
            public function push(string $sheet, array $row): bool { $this->pushed[] = $row; return true; }
        };

        NominationAftercare::run($data, $id, 'https://example.test', null, [], $spy);

        $row = $spy->pushed[0] ?? [];
        $this->assertSame([
            'programme_id', 'nominee_name', 'country_code', 'reason',
            'nominator_name', 'nominator_email', 'reference',
        ], array_keys($row));

        // Spelled out, because a future field added to the form must not silently
        // start travelling to a third party.
        foreach (['nominator_phone', 'nominator_age_range', 'nominee_phone', 'nominee_email'] as $private) {
            $this->assertArrayNotHasKey($private, $row, $private . ' is being sent to the sheet');
        }
        $this->assertSame('Ada Okonkwo', $row['nominee_name'], 'and it is the tidied name');
    }

    /** No sheet configured is the normal case, and must be silent. */
    public function test_no_sheet_configured_is_not_an_error(): void
    {
        [$id, $data] = $this->nomination();

        $out = NominationAftercare::run($data, $id, 'https://example.test', null, [], null);

        $this->assertArrayHasKey('reference', $out);
    }

    // ── it must never break a nomination that is already stored ─────────────

    /**
     * Every side effect is best-effort. The row is already committed by the time
     * this runs, so a dead SMTP server, an unreachable SMS gateway or a webhook
     * timeout must not turn a successful submission into an error for the person who
     * made it — they would submit again, and the platform would hold two.
     */
    public function test_it_never_throws_even_with_nothing_configured(): void
    {
        [$id, $data] = $this->nomination();

        $out = NominationAftercare::run($data, $id, 'https://example.test', null);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('reference', $out);
    }

    /** A missing nomination is not a crash either — just nothing to report. */
    public function test_an_unknown_nomination_id_does_not_throw(): void
    {
        [, $data] = $this->nomination();

        $out = NominationAftercare::run($data, 999999, 'https://example.test');

        $this->assertIsArray($out);
    }

    // ── both doors, one behaviour ───────────────────────────────────────────

    /**
     * The guard against this ever drifting apart again: both controllers must call
     * the shared service, and neither may keep its own copy of the notifications.
     *
     * Asserted on the source because that is where the duplication would reappear —
     * the fault was never a wrong value, it was ninety lines existing in one file
     * and not the other.
     */
    public function test_both_nomination_doors_call_the_shared_service(): void
    {
        foreach ([
            'src/Controllers/NominationController.php',
            'src/Controllers/ApiController.php',
        ] as $rel) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
            $this->assertStringContainsString('NominationAftercare::run', $src,
                $rel . ' does not run the after-submit work');
        }

        // And the web form no longer carries its own copy.
        $web = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/NominationController.php');
        $this->assertStringNotContainsString("You've been nominated", $web,
            'the nominee email is back inline in the controller — the two doors will drift again');
        $this->assertStringNotContainsString('nomination.submitted', $web,
            'the webhook is back inline in the controller');
    }

    /**
     * A numeric field in a JSON body must not crash the endpoint.
     *
     * ── THE BUG THIS PINS ────────────────────────────────────────────────────
     *
     * The required-field loop was `empty(trim($b[$f] ?? ''))`, and `programme_id`
     * arrives from a JSON body as an INTEGER, because that is how JSON encodes
     * numbers. trim() rejects an int under PHP 8, so POST /api/nominations answered
     * the most natural request anybody could send —
     *
     *     {"programme_id": 1, "nominee_name": "...", ...}
     *
     * — with a 500 and "An internal error occurred", while a caller who happened to
     * quote the id as a string succeeded. Found by actually POSTing to the endpoint,
     * not by reading it.
     *
     * Asserted on the source because the crash is in a validation guard that runs
     * before anything testable, and the fix is one cast that is easy to lose.
     */
    public function test_the_api_does_not_crash_on_a_numeric_json_field(): void
    {
        foreach (['src/Controllers/ApiController.php', 'src/Controllers/RegistryController.php'] as $rel) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
            $this->assertStringNotContainsString("empty(trim(\$b[\$f]??''))", $src,
                $rel . ' trims a raw body value: a JSON number will throw a TypeError '
                . 'and return 500 instead of a validation message');
        }

        // And the same shape, proven directly: trim() on an int is fatal, the cast is not.
        $body = ['programme_id' => 1];
        $this->assertSame('1', trim((string) ($body['programme_id'] ?? '')));
    }

    /**
     * And the API answers with the reference.
     *
     * An API caller has no inbox to read a confirmation in. Without the reference in
     * the response there is nothing to quote to support and nothing to look the
     * nomination up by, which is the same silence the endpoint had before, moved one
     * step later.
     */
    public function test_the_api_returns_the_reference_to_its_caller(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/ApiController.php');

        $this->assertMatchesRegularExpression(
            "/'reference'\s*=>\s*\\\$after\['reference'\]/", $src,
            'the API accepts a nomination without telling the caller how to refer to it');
    }
}
