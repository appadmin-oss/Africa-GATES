<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\EvidenceService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Uploaded evidence: why it did not open, and who is allowed to open it now.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_nominee_evidence.source_url` holds one of two very different things: a real
 * external URL, or the STORED PATH of a file the nominee uploaded —
 * `QuestionnaireService::publishEvidence()` writes `works[].file` straight into it.
 *
 * The ballot rendered both as `<a :href="it.source_url">`. For a path like
 * `uploads/nominee-evidence/2026/08/x.pdf` — no leading slash — the browser resolved it
 * against `/judge/ballot` and requested `/judge/uploads/...`: a 404. Images survived only
 * because they go to Cloudinary and come back absolute. That is the reported "they save as
 * images and cannot be previewed in the judges portal" — the images were simply the ones
 * that happened to work.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE OBVIOUS FIX WOULD HAVE BEEN WORSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Emitting `/uploads/...` would have worked and been wrong. `UploadService` keeps PDFs off
 * the CDN deliberately: nomination evidence is private moderation material, and a public
 * path leaves an unguessable filename as the only protection. So it streams through a
 * route that authorises the judge against THIS nominee — and the authorisation is what the
 * second half of this file is about, because "is a judge signed in" is the version of this
 * check that leaks one panel's dossier to another.
 */
final class JudgeEvidenceFileTest extends TestCase
{
    private JudgeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new JudgeService();

        // Two programmes, two panels, one judge on each.
        foreach ([1, 2] as $p) {
            DB::table('gates_award_programmes')->insert(['id' => $p, 'slug' => 'p' . $p, 'title' => 'P' . $p]);
            DB::table('gates_award_cycles')->insert([
                'id' => $p, 'programme_id' => $p, 'year' => (int) date('Y'), 'status' => 'judging',
                'nominations_open' => '2020-01-01 00:00:00', 'voting_open' => '2020-02-01 00:00:00',
                'voting_close' => '2020-03-01 00:00:00', 'results_date' => '2020-04-01 00:00:00',
            ]);
            DB::table('gates_award_categories')->insert(['id' => $p, 'cycle_id' => $p, 'slug' => 'c' . $p, 'title' => 'C' . $p]);
            DB::table('gates_nominees')->insert([
                'id' => $p, 'category_id' => $p, 'name' => 'Nominee ' . $p, 'status' => 'approved',
                'vote_count' => 0, 'organic_vote_count' => 0,
            ]);
            DB::table('gates_judges')->insert([
                'id' => $p, 'name' => 'J' . $p, 'email' => 'j' . $p . '@x.io',
                'is_active' => 1, 'programme_ids' => json_encode([$p]),
            ]);
        }
    }

    private function evidence(int $id, int $nomineeId, string $sourceUrl, int $visible = 1): void
    {
        DB::table('gates_nominee_evidence')->insert([
            'id' => $id, 'nominee_id' => $nomineeId, 'kind' => 'document',
            'title' => 'Impact report', 'source_url' => $sourceUrl,
            'provenance' => 'nominee_supplied', 'visible_to_judges' => $visible,
        ]);
    }

    // ══ a file is emitted as a file ══════════════════════════════════════════

    public function test_a_stored_pdf_path_becomes_a_streamed_url_not_a_link(): void
    {
        [$url, $kind] = EvidenceService::fileFor('uploads/nominee-evidence/2026/08/abc.pdf', 44);

        $this->assertSame('/judge/evidence/44', $url, 'a relative path was left as a link and 404s');
        $this->assertSame('pdf', $kind);
    }

    public function test_a_stored_image_path_is_also_streamed(): void
    {
        [$url, $kind] = EvidenceService::fileFor('uploads/nominee-evidence/2026/08/abc.png', 7);

        $this->assertSame('/judge/evidence/7', $url);
        $this->assertSame('image', $kind);
    }

    /**
     * Somebody else's link stays a link.
     *
     * It is a source to read, not a file we hold — and an iframe pointed at an arbitrary
     * host is a judge console that can be pointed at one, which is why `frame-src` refuses
     * it too.
     */
    public function test_an_external_source_stays_a_link(): void
    {
        $this->assertSame(['', ''], EvidenceService::fileFor('https://example.org/report.pdf', 9));
        $this->assertSame(['', ''], EvidenceService::fileFor('http://files.example.net/a.png', 9));
    }

    /**
     * OUR OWN CDN is a file, and this is the behaviour that changed.
     *
     * All absolute URLs used to collapse into "that is a link", and the case that paid for
     * it was an upload: once Cloudinary is switched on, an evidence PDF is STORED as a
     * delivery URL. So a document uploaded this week rendered as a bare anchor while an
     * identical one from the week before previewed inline — same screen, same file type,
     * different treatment, and nothing on the page said why.
     *
     * Through the judge-gated route rather than straight to the delivery URL: the route
     * authorises, and the URL never enters the ballot's HTML where a screenshot or a
     * copied DOM would carry a permanent unauthenticated link out of the console.
     */
    public function test_a_cdn_hosted_file_is_previewed_through_the_gated_route(): void
    {
        $this->assertSame(
            ['/judge/evidence/9', 'pdf'],
            EvidenceService::fileFor('https://res.cloudinary.com/demo/image/upload/v1/dossier.pdf', 9));

        $this->assertSame(
            ['/judge/evidence/9', 'image'],
            EvidenceService::fileFor('https://res.cloudinary.com/demo/image/upload/v1/photo.png', 9));

        // Subdomains too — isRemote() accepts them, so anything it will redirect to has to
        // be classified here or the two disagree about what the platform owns.
        $this->assertSame(
            ['/judge/evidence/9', 'pdf'],
            EvidenceService::fileFor('https://eu.res.cloudinary.com/demo/image/upload/v1/a.pdf', 9));
    }

    /**
     * The extension comes from the PATH, not the whole URL.
     *
     * A delivery URL may carry a query, and `pathinfo` over the whole string reads the
     * last thing after a dot in it — so `?v=1.2` would make a PDF a file of kind "2".
     */
    public function test_a_query_string_does_not_decide_the_file_type(): void
    {
        $this->assertSame(
            ['/judge/evidence/9', 'pdf'],
            EvidenceService::fileFor(
                'https://res.cloudinary.com/demo/image/upload/v1/dossier.pdf?_a=BAM&v=1.2', 9));
    }

    // ══ THE ROUTE THE PREVIEW POINTS AT ═════════════════════════════════════

    /**
     * The redirect is to our CDN or nowhere.
     *
     * This route is a FRAME TARGET now, with a signed-in judge on the other end of it. A
     * route that forwards to any URL a nominee happened to store is an open redirect, and
     * the fact that nothing linked to it before is not a property worth relying on.
     */
    public function test_the_stream_refuses_to_forward_anywhere_but_the_cdn(): void
    {
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Judge/Controllers/EvidenceController.php');

        $from = (int) strpos($ctl, 'preg_match(\'~^https?://~i\'');
        $this->assertGreaterThan(0, $from, 'the absolute-URL branch has moved');
        $branch = substr($ctl, $from, 900);

        $this->assertStringContainsString('CloudinaryService::isRemote(', $branch,
            'the branch forwards without asking whose URL it is');
        $this->assertStringContainsString('withStatus(404)', $branch,
            'and a URL that is not ours must be refused, not followed');
    }

    /**
     * `?download=1` survives the hop.
     *
     * The fallback link under the preview is the one route through on a browser with no
     * PDF viewer. Dropped at the redirect, it sent the reader to the same inline render
     * that had already failed them — a dead end reached by pressing the escape hatch.
     */
    public function test_the_download_flag_is_carried_onto_the_cdn_url(): void
    {
        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Judge/Controllers/EvidenceController.php');

        $this->assertStringContainsString("transformed(\$stored, 'fl_attachment')", $ctl,
            'the download flag has to become one the CDN understands, or it is lost');

        // And the transformation the controller asks for has to be one the service can
        // actually apply — a flag that silently returns the URL unchanged would look
        // exactly like it worked.
        $url = 'https://res.cloudinary.com/demo/image/upload/v1/dossier.pdf';
        $this->assertSame(
            'https://res.cloudinary.com/demo/image/upload/fl_attachment/v1/dossier.pdf',
            \AfricaGates\Services\CloudinaryService::transformed($url, 'fl_attachment'));
    }

    /** Nothing outside the uploads tree is ours to stream. */
    public function test_a_path_outside_uploads_is_not_streamed(): void
    {
        $this->assertSame(['', ''], EvidenceService::fileFor('../../etc/passwd', 9));
        $this->assertSame(['', ''], EvidenceService::fileFor('var/logs/app.log', 9));
        $this->assertSame(['', ''], EvidenceService::fileFor('', 9));
    }

    /** The dossier the ballot receives must carry the file, and must not carry the dead link. */
    public function test_the_ballot_dossier_carries_the_file_and_drops_the_broken_link(): void
    {
        $this->evidence(10, 1, 'uploads/nominee-evidence/2026/08/abc.pdf');

        $items = (new EvidenceService())->forBallot([1])[1]['items'] ?? (new EvidenceService())->forBallot([1])[1];
        $doc   = null;
        foreach ($items as $i) if (($i['file_url'] ?? '') !== '') $doc = $i;

        $this->assertNotNull($doc, 'the uploaded file is missing from the dossier');
        $this->assertSame('/judge/evidence/10', $doc['file_url']);
        $this->assertSame('pdf', $doc['file_kind']);
        $this->assertSame('', $doc['source_url'], 'the broken relative link is still being rendered');
    }

    // ══ the authorisation boundary ═══════════════════════════════════════════

    public function test_a_judge_can_read_evidence_on_their_own_panel(): void
    {
        $this->evidence(10, 1, 'uploads/nominee-evidence/2026/08/abc.pdf');

        $row = $this->svc->evidenceFor(1, 10);
        $this->assertNotNull($row);
        $this->assertSame(10, (int) $row->id);
    }

    /** THE ONE THAT MATTERS: incrementing an id must not reach another panel. */
    public function test_a_judge_cannot_read_another_panels_dossier(): void
    {
        $this->evidence(10, 1, 'uploads/nominee-evidence/2026/08/abc.pdf');   // panel 1

        $this->assertNull($this->svc->evidenceFor(2, 10),
            'a judge on panel 2 read panel 1 evidence — Broken Access Control');
    }

    public function test_an_item_withheld_from_the_panel_stays_withheld(): void
    {
        $this->evidence(11, 1, 'uploads/nominee-evidence/2026/08/abc.pdf', visible: 0);

        $this->assertNull($this->svc->evidenceFor(1, 11));
    }

    /** A nominee who left the ballot takes their dossier with them. */
    public function test_a_merged_away_nominee_is_not_readable(): void
    {
        $this->evidence(12, 1, 'uploads/nominee-evidence/2026/08/abc.pdf');
        DB::table('gates_nominees')->where('id', 1)->update(['merged_into' => 2]);

        $this->assertNull($this->svc->evidenceFor(1, 12));
    }

    public function test_a_pending_nominee_is_not_readable(): void
    {
        $this->evidence(13, 1, 'uploads/nominee-evidence/2026/08/abc.pdf');
        DB::table('gates_nominees')->where('id', 1)->update(['status' => 'pending']);

        $this->assertNull($this->svc->evidenceFor(1, 13));
    }

    public function test_a_judge_with_no_assignment_reads_nothing(): void
    {
        $this->evidence(14, 1, 'uploads/nominee-evidence/2026/08/abc.pdf');
        DB::table('gates_judges')->where('id', 1)->update(['programme_ids' => json_encode([])]);

        $this->assertNull($this->svc->evidenceFor(1, 14));
    }

    public function test_nonsense_ids_are_refused_without_a_query(): void
    {
        $this->assertNull($this->svc->evidenceFor(0, 10));
        $this->assertNull($this->svc->evidenceFor(1, 0));
        $this->assertNull($this->svc->evidenceFor(1, 999999));
    }
}
