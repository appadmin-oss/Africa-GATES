<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiPrivacy;
use AfricaGates\Services\EvidenceAnalysis;
use AfricaGates\Services\EvidenceService;
use AfricaGates\Services\GeminiFiles;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Reading a nominee's uploaded documents with a model.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE FAILURES THIS FEATURE CAN HAVE, IN ORDER OF HOW BAD THEY ARE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · A DESCRIPTION ATTACHED TO THE WRONG DOCUMENT. Six files go up in one request. If the
 *     answers are matched by array position and the model returns five, every description
 *     from that point on lands on the wrong file — and it lands there looking exactly as
 *     credible as a correct one. A judge cannot detect this. Nothing can, afterwards. So
 *     the matching is by an explicit reference and {@see EvidenceAnalysis::applyAnswers()}
 *     is public precisely so this can be tested without a network.
 *
 * 2 · A DESCRIPTION OF A FILE NOBODY SENT. The generic provider chain starts at Groq, which
 *     is text-only: hand it a prompt about an attachment it cannot see and it will describe
 *     the document confidently from the filename. That is why the capability declares an
 *     EMPTY fallback ladder, and why that emptiness is asserted here rather than left as a
 *     comment somebody tidies away.
 *
 * 3 · A STALE DESCRIPTION OF REPLACED BYTES. A nominee who re-uploads keeps the same
 *     evidence row. Keying the analysis on the row alone would serve the old summary for
 *     the new file.
 *
 * Everything else here — budgets, page caps, .docx refusals — is cost and manners.
 */
final class EvidenceAnalysisTest extends TestCase
{
    private int $nomineeId = 4242;
    private string $tmp    = '';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_evidence_analysis')->delete();
        DB::table('gates_nominee_evidence')->where('nominee_id', $this->nomineeId)->delete();

        $this->tmp = sys_get_temp_dir() . '/ag-evan-' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** A file row, with a stable fake hash so the cache key is under the test's control. */
    private function file(string $ref, int $evidenceId, string $hash, int $pages = 1): array
    {
        return ['evidence_id' => $evidenceId, 'title' => 'Document ' . $ref, 'path' => '',
                'hash' => $hash, 'pages' => $pages, 'ok' => true, 'reason' => ''];
    }

    /** A minimal but genuinely parseable PDF with $n pages. */
    private function pdf(int $n): string
    {
        $path = $this->tmp . '/doc' . $n . '.pdf';
        $body = "%PDF-1.4\n";
        for ($i = 1; $i <= $n; $i++) {
            $body .= $i . " 0 obj\n<< /Type /Page /Parent 100 0 R >>\nendobj\n";
        }
        $body .= "100 0 obj\n<< /Type /Pages /Count " . $n . " >>\nendobj\n%%EOF\n";
        file_put_contents($path, $body);
        return $path;
    }

    // ══ 1 · the answer lands on the right document ═══════════════════════════

    public function test_an_answer_is_matched_by_its_reference_and_not_by_its_position(): void
    {
        $refs = [
            'DOC1' => $this->file('DOC1', 11, 'h1'),
            'DOC2' => $this->file('DOC2', 12, 'h2'),
            'DOC3' => $this->file('DOC3', 13, 'h3'),
        ];

        // Deliberately out of order, which a model is entitled to do.
        $answer = json_encode([
            ['ref' => 'DOC3', 'doc_type' => 'press cutting', 'summary' => 'THREE', 'legibility' => 70],
            ['ref' => 'DOC1', 'doc_type' => 'council letter', 'summary' => 'ONE',  'legibility' => 90],
            ['ref' => 'DOC2', 'doc_type' => 'photograph',     'summary' => 'TWO',  'legibility' => 50],
        ]);

        $r = EvidenceAnalysis::applyAnswers($answer, $refs, 'gemini-3.6-flash', $this->nomineeId, 300, 60);

        $this->assertSame(3, $r['analysed']);
        $this->assertSame(0, $r['failed']);
        $this->assertSame('ONE',   EvidenceAnalysis::forEvidence(11)['summary']);
        $this->assertSame('TWO',   EvidenceAnalysis::forEvidence(12)['summary']);
        $this->assertSame('THREE', EvidenceAnalysis::forEvidence(13)['summary']);
    }

    public function test_a_missing_answer_fails_only_its_own_document_and_shifts_nothing(): void
    {
        // THE failure this whole design exists for. Positional matching would give document
        // two the description of document three and document three nothing — and the wrong
        // description would be stored as a successful analysis.
        $refs = [
            'DOC1' => $this->file('DOC1', 21, 'h1'),
            'DOC2' => $this->file('DOC2', 22, 'h2'),
            'DOC3' => $this->file('DOC3', 23, 'h3'),
        ];

        $answer = json_encode([
            ['ref' => 'DOC1', 'doc_type' => 'letter', 'summary' => 'ONE',   'legibility' => 90],
            ['ref' => 'DOC3', 'doc_type' => 'letter', 'summary' => 'THREE', 'legibility' => 90],
        ]);

        $r = EvidenceAnalysis::applyAnswers($answer, $refs, 'gemini-3.6-flash', $this->nomineeId, 0, 0);

        $this->assertSame(2, $r['analysed']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame('ONE',   EvidenceAnalysis::forEvidence(21)['summary']);
        $this->assertSame('THREE', EvidenceAnalysis::forEvidence(23)['summary']);
        // Document two has NO analysis rather than document three's.
        $this->assertNull(EvidenceAnalysis::forEvidence(22));

        $row = DB::table('gates_evidence_analysis')->where('evidence_id', 22)->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('did not answer', (string) $row->error);
    }

    public function test_a_reference_nobody_sent_is_discarded_rather_than_stored(): void
    {
        $refs   = ['DOC1' => $this->file('DOC1', 31, 'h1')];
        $answer = json_encode([
            ['ref' => 'DOC1',  'doc_type' => 'letter', 'summary' => 'real',     'legibility' => 80],
            ['ref' => 'DOC99', 'doc_type' => 'letter', 'summary' => 'invented', 'legibility' => 80],
        ]);

        EvidenceAnalysis::applyAnswers($answer, $refs, 'gemini-3.6-flash', $this->nomineeId, 0, 0);

        $this->assertSame(1, DB::table('gates_evidence_analysis')->count());
        $this->assertSame('real', EvidenceAnalysis::forEvidence(31)['summary']);
    }

    public function test_an_answer_that_is_not_a_list_fails_every_document_and_stores_none_as_ok(): void
    {
        $refs = ['DOC1' => $this->file('DOC1', 41, 'h1'), 'DOC2' => $this->file('DOC2', 42, 'h2')];

        $r = EvidenceAnalysis::applyAnswers('sorry, I cannot do that', $refs,
                                            'gemini-3.6-flash', $this->nomineeId, 10, 4);

        $this->assertSame(0, $r['analysed']);
        $this->assertSame(2, $r['failed']);
        $this->assertNotSame('', $r['error']);
        $this->assertSame(0, DB::table('gates_evidence_analysis')->where('status', 'ok')->count());
    }

    public function test_the_tokens_for_one_request_are_divided_between_its_documents(): void
    {
        // Recording the full request cost against each row would report six times the spend
        // for a batch of six, and the daily budget is enforced from these numbers.
        $refs = ['DOC1' => $this->file('DOC1', 51, 'h1'), 'DOC2' => $this->file('DOC2', 52, 'h2')];
        $answer = json_encode([
            ['ref' => 'DOC1', 'doc_type' => 'a', 'summary' => 'a', 'legibility' => 10],
            ['ref' => 'DOC2', 'doc_type' => 'b', 'summary' => 'b', 'legibility' => 10],
        ]);

        EvidenceAnalysis::applyAnswers($answer, $refs, 'gemini-3.6-flash', $this->nomineeId, 1000, 200);

        $this->assertSame(1000, (int) DB::table('gates_evidence_analysis')->sum('tokens_in'));
        $this->assertSame(200,  (int) DB::table('gates_evidence_analysis')->sum('tokens_out'));
    }

    // ══ 2 · it can never reach a model that cannot see a file ════════════════

    public function test_the_capability_has_no_ladder_to_a_text_only_provider(): void
    {
        $cap = AiCapability::find(EvidenceAnalysis::CAPABILITY);
        $this->assertNotNull($cap, 'the capability must be declared, or the gateway refuses every call');

        $this->assertSame([], $cap->fallbacks);
        $this->assertSame(1, $cap->maxAttempts);
        $this->assertSame('gemini', $cap->provider());
        $this->assertNotSame('gemini', $cap->modelId(), 'a bare provider pin is not a pin');
    }

    public function test_it_is_advisory_and_says_so_where_the_public_can_read_it(): void
    {
        $cap = AiCapability::find(EvidenceAnalysis::CAPABILITY);

        $this->assertTrue($cap->advisory, 'a document summary must never decide anything');
        $this->assertTrue($cap->publicContent, 'these are files the public uploaded');
        $this->assertTrue($cap->minimise);

        $found = false;
        foreach (AiPrivacy::disclosure() as $group) {
            foreach ($group['capabilities'] as $c) {
                if ($c['name'] === EvidenceAnalysis::CAPABILITY) $found = true;
            }
        }
        $this->assertTrue($found, 'a capability that receives uploaded files must be in the notice');
    }

    public function test_the_prompt_forbids_the_two_things_it_must_never_produce(): void
    {
        $sys = strtolower(EvidenceAnalysis::system());
        $this->assertStringContainsString('never score', $sys);
        $this->assertStringContainsString('forged', $sys, 'it must be told not to allege forgery');
    }

    public function test_legibility_is_asked_for_on_the_scale_the_screens_render(): void
    {
        // Both screens print it as a percentage. An earlier version of them said "/5"
        // against a field the schema defines as 0-100, which would have rendered "82/5".
        $schema = EvidenceAnalysis::schema();
        $desc   = $schema['items']['properties']['legibility']['description'];
        $this->assertStringContainsString('0-100', $desc);

        foreach (['templates/judge/ballot.twig', 'templates/admin/questionnaires/show.twig'] as $t) {
            $html = file_get_contents(dirname(__DIR__, 2) . '/' . $t);
            $this->assertStringNotContainsString('legibility ' . "'" . ' + it.analysis.legibility', $html);
            $this->assertStringContainsString('% legible', $html, $t);
        }
    }

    // ══ 3 · replaced bytes do not inherit the old description ════════════════

    public function test_replacing_the_file_behind_a_row_invalidates_its_analysis(): void
    {
        $refs = ['DOC1' => $this->file('DOC1', 61, 'hash-of-the-first-file')];
        EvidenceAnalysis::applyAnswers(
            json_encode([['ref' => 'DOC1', 'doc_type' => 'letter', 'summary' => 'the first one',
                          'legibility' => 90]]),
            $refs, 'gemini-3.6-flash', $this->nomineeId, 0, 0
        );

        // The same evidence row, different bytes.
        $this->assertNull(EvidenceAnalysis::forEvidence(61, 'hash-of-the-second-file'));
        $this->assertSame('the first one', EvidenceAnalysis::forEvidence(61, 'hash-of-the-first-file')['summary']);
    }

    public function test_the_newest_analysis_wins_and_the_older_one_survives_for_comparison(): void
    {
        $refs = ['DOC1' => $this->file('DOC1', 71, 'h')];
        foreach (['first reading', 'second reading'] as $summary) {
            EvidenceAnalysis::applyAnswers(
                json_encode([['ref' => 'DOC1', 'doc_type' => 'letter', 'summary' => $summary,
                              'legibility' => 90]]),
                $refs, 'gemini-3.6-flash', $this->nomineeId, 0, 0
            );
        }

        $this->assertSame('second reading', EvidenceAnalysis::forEvidence(71)['summary']);
        $this->assertSame(2, DB::table('gates_evidence_analysis')->where('evidence_id', 71)->count());
    }

    // ══ what is sent, and what is refused ════════════════════════════════════

    public function test_a_file_type_the_model_cannot_read_is_refused_with_a_reason(): void
    {
        $g = GeminiFiles::boot();
        $docx = $this->tmp . '/report.docx';
        file_put_contents($docx, 'PK' . str_repeat('x', 400));

        $r = $g->inspect($docx);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('.docx', $r['reason']);
    }

    public function test_an_empty_or_missing_file_is_a_stated_reason_rather_than_a_crash(): void
    {
        $g = GeminiFiles::boot();

        $empty = $this->tmp . '/empty.pdf';
        file_put_contents($empty, '');
        $this->assertFalse($g->inspect($empty)['ok']);
        $this->assertStringContainsString('empty', strtolower($g->inspect($empty)['reason']));

        $gone = $g->inspect($this->tmp . '/never-existed.pdf');
        $this->assertFalse($gone['ok']);
        $this->assertNotSame('', $gone['reason']);
    }

    public function test_a_pdf_is_counted_by_its_pages_and_a_huge_one_is_refused_not_truncated(): void
    {
        $g = GeminiFiles::boot();

        $small = $this->pdf(3);
        $this->assertSame(3, GeminiFiles::pdfPages($small));
        $this->assertTrue($g->inspect($small)['ok']);
        $this->assertSame(3, $g->inspect($small)['pages']);

        // Over the cap. Refused, and the reason names the number — a summary of the first
        // 120 pages of a 400-page report that does not say so is the dangerous outcome.
        $huge = $this->pdf(GeminiFiles::MAX_PAGES + 5);
        $r = $g->inspect($huge);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString((string) (GeminiFiles::MAX_PAGES + 5), $r['reason']);
    }

    public function test_a_link_somebody_typed_is_never_fetched_as_if_it_were_our_file(): void
    {
        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $this->nomineeId, 'kind' => 'link', 'title' => 'A news story',
            'source_url' => 'https://example.org/story', 'provenance' => 'nominator_claim',
            'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        // Not "skipped with a reason" — absent. Following it would put an arbitrary host in
        // the path of an admin button, on a nominee's say-so.
        $this->assertSame([], EvidenceAnalysis::filesFor($this->nomineeId));
    }

    public function test_a_file_the_database_remembers_and_the_disk_does_not_is_reported(): void
    {
        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $this->nomineeId, 'kind' => 'document', 'title' => 'Handover letter',
            'source_url' => 'uploads/nominee-evidence/2026/08/gone.pdf',
            'provenance' => 'nominee_supplied', 'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        $files = EvidenceAnalysis::filesFor($this->nomineeId);
        $this->assertCount(1, $files);
        $this->assertFalse($files[0]['ok']);
        $this->assertStringContainsString('disk', $files[0]['reason']);
    }

    // ══ what the screens are given ═══════════════════════════════════════════

    public function test_the_button_explains_itself_when_it_cannot_be_pressed(): void
    {
        // No Gemini key in the test environment, so this is the real path an operator hits
        // on a fresh install — and "unavailable" on its own tells them nothing to do.
        $s = EvidenceAnalysis::status($this->nomineeId);

        $this->assertFalse($s['available']);
        $this->assertNotSame('', $s['reason']);
        $this->assertStringContainsString('gemini', strtolower($s['reason']) . strtolower($s['model']));
    }

    public function test_a_failed_reading_never_reaches_a_judge_as_a_fact_about_the_document(): void
    {
        $id = (int) DB::table('gates_nominee_evidence')->insertGetId([
            'nominee_id' => $this->nomineeId, 'kind' => 'document', 'title' => 'Certificate',
            'source_url' => 'uploads/x.pdf', 'provenance' => 'nominee_supplied',
            'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        // A failure is OUR plumbing. On a ballot "could not be read" reads as a finding
        // about the nominee's document, which it is not.
        EvidenceAnalysis::applyAnswers('not json', ['DOC1' => $this->file('DOC1', $id, 'h')],
                                       'gemini-3.6-flash', $this->nomineeId, 0, 0);

        $items = (new EvidenceService())->forJudge($this->nomineeId)['items'];
        $mine  = array_values(array_filter($items, fn ($i) => ($i['title'] ?? '') === 'Certificate'));
        $this->assertCount(1, $mine);
        $this->assertArrayNotHasKey('analysis', $mine[0]);
    }

    public function test_a_successful_reading_is_handed_to_the_ballot_with_its_provenance(): void
    {
        $id = (int) DB::table('gates_nominee_evidence')->insertGetId([
            'nominee_id' => $this->nomineeId, 'kind' => 'document', 'title' => 'Borehole handover',
            'source_url' => 'uploads/y.pdf', 'provenance' => 'nominee_supplied',
            'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        EvidenceAnalysis::applyAnswers(
            json_encode([['ref' => 'DOC1', 'doc_type' => 'council letter',
                          'summary' => 'A letter acknowledging handover.',
                          'claims' => ['Handed over on 4 March 2025'],
                          'concerns' => ['The second page is cut off'],
                          'legibility' => 72]]),
            ['DOC1' => $this->file('DOC1', $id, 'h')], 'gemini-3.6-flash', $this->nomineeId, 40, 12
        );

        $items = (new EvidenceService())->forJudge($this->nomineeId)['items'];
        $mine  = array_values(array_filter($items, fn ($i) => ($i['title'] ?? '') === 'Borehole handover'));

        $this->assertArrayHasKey('analysis', $mine[0]);
        $this->assertSame('council letter', $mine[0]['analysis']['doc_type']);
        $this->assertSame(72, $mine[0]['analysis']['legibility']);
        // The model is carried through, because the ballot prints it. An analysis with no
        // provenance is a claim, and this one is shown to a panel.
        $this->assertSame('gemini-3.6-flash', $mine[0]['analysis']['model']);
        $this->assertSame(['The second page is cut off'], $mine[0]['analysis']['concerns']);
    }

    public function test_a_whole_ballot_of_analyses_is_one_query_not_one_per_nominee(): void
    {
        foreach ([901, 902] as $n) {
            EvidenceAnalysis::applyAnswers(
                json_encode([['ref' => 'DOC1', 'doc_type' => 't', 'summary' => 'for ' . $n,
                              'legibility' => 50]]),
                ['DOC1' => $this->file('DOC1', $n * 10, 'h' . $n)], 'gemini-3.6-flash', $n, 0, 0
            );
        }

        $map = EvidenceAnalysis::forNomineesMap([901, 902, 903]);

        $this->assertSame('for 901', $map[901][9010]['summary']);
        $this->assertSame('for 902', $map[902][9020]['summary']);
        // Present and empty rather than absent, so a caller can index it without a guard.
        $this->assertSame([], $map[903]);
    }

    public function test_the_admin_screen_reads_the_analysis_and_never_starts_one(): void
    {
        // The button posts; the page does not. A screen that spends money on render is a
        // screen that spends it again on every refresh, and on every back button.
        $twig = file_get_contents(dirname(__DIR__, 2) . '/templates/admin/questionnaires/show.twig');
        $this->assertStringContainsString('/analyse', $twig);
        $this->assertMatchesRegularExpression(
            '~<form method="post" action="/admin/questionnaires/\{\{ row\.id \}\}/analyse">~', $twig
        );

        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertStringContainsString("post('/{id:[0-9]+}/analyse'", $routes);
        $this->assertStringNotContainsString("get('/{id:[0-9]+}/analyse'", $routes,
            'reading files must not be reachable by following a link');
    }

    public function test_the_upload_cache_is_pruned_by_the_scheduler(): void
    {
        // prune() shipped tested and uncalled once before on this platform, and a pruner
        // nobody calls is not a retention policy — it is a table that grows for ever and
        // hands out URIs for files Google deleted two days ago.
        $m = file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');
        $this->assertStringContainsString('GeminiFiles::prune()', $m);
    }

    public function test_an_expired_upload_handle_is_dropped(): void
    {
        DB::table('gates_ai_files')->insert([
            ['content_hash' => str_repeat('a', 64), 'file_uri' => 'files/old',
             'expires_at' => '2020-01-01 00:00:00'],
            ['content_hash' => str_repeat('b', 64), 'file_uri' => 'files/new',
             'expires_at' => '2099-01-01 00:00:00'],
        ]);

        $this->assertSame(1, GeminiFiles::prune());
        $this->assertSame(1, DB::table('gates_ai_files')->count());
        $this->assertSame('files/new', DB::table('gates_ai_files')->value('file_uri'));
    }
}
