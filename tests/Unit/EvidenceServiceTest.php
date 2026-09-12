<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Services\EvidenceService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What a judge is allowed to see, and what they must be told about it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE THAT OUTRANKS THE REST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A dossier never carries popularity. The justification for weighting an expert panel at
 * 55% against a public vote at 45% is that they are independent measurements of
 * different things; the moment a judge can see who is winning, they are one measurement
 * counted twice and the weighting is decoration.
 *
 * That was not hypothetical. The ballot printed "judge on documented impact, not
 * popularity" and then ordered the nominees by vote count, most-voted first — the number
 * hidden, the ranking handed over intact, identically for every judge on the panel.
 *
 * ── AND THE OPPOSITE FAILURE, WHICH IS QUIETER ───────────────────────────────
 *
 * An evidence system rewards whoever is easiest to document. A nominee with a press
 * archive and a filmed interview reads as substantial next to a weaver whose nominator
 * wrote four sentences, and the weaver may be the better candidate. So the coverage
 * summary has to name what is MISSING, in words that put the absence on the platform
 * rather than on the nominee. Half the tests here are about that.
 */
final class EvidenceServiceTest extends TestCase
{
    private const CAT = 9500;
    private int $nomineeId = 0;
    private int $rivalId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_nominations')->delete();
        DB::table('gates_nominee_evidence')->delete();
        DB::table('gates_nominee_interviews')->delete();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 95, 'title' => 'Craft', 'slug' => 'craft-95']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 9500, 'programme_id' => 95, 'year' => 2026,
            'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => 9500,
            'title' => 'Weaving', 'slug' => 'weaving-9500']);

        $this->nomineeId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Baba Sule', 'status' => 'approved',
            'vote_count' => 900, 'organic_vote_count' => 900]);
        $this->rivalId = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => self::CAT, 'name' => 'Ngozi Eze', 'status' => 'approved',
            'vote_count' => 3, 'organic_vote_count' => 3]);
    }

    private function evidence(array $over = []): int
    {
        return (int) DB::table('gates_nominee_evidence')->insertGetId($over + [
            'nominee_id' => $this->nomineeId,
            'kind'       => 'document',
            'title'      => 'Cooperative registration',
            'body'       => 'Registered the weavers co-operative in 2019.',
            'provenance' => 'platform_verified',
            'verified'   => 1,
            'visible_to_judges' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function interview(array $over = []): int
    {
        return (int) DB::table('gates_nominee_interviews')->insertGetId($over + [
            'nominee_id'        => $this->nomineeId,
            'interviewed_at'    => '2026-07-01 10:00:00',
            'interviewer'       => 'Programme team',
            'medium'            => 'video',
            'language'          => 'en',
            'transcript'        => 'I started teaching apprentices because nobody else would.',
            'transcript_source' => 'human',
            'consent_given'     => 1,
            'status'            => 'published',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    // ══ popularity never reaches a judge ═════════════════════════════════════

    /** THE CASE THIS EXISTS FOR. */
    public function test_a_dossier_carries_no_popularity_of_any_kind(): void
    {
        $this->evidence();
        $this->interview();

        $blob = json_encode((new EvidenceService())->forJudge($this->nomineeId));

        foreach (EvidenceService::FORBIDDEN_FIELDS as $banned) {
            $this->assertStringNotContainsString('"' . $banned . '"', (string) $blob,
                "A dossier is carrying '{$banned}' — the 55% panel can see the 45% vote.");
        }
        $this->assertStringNotContainsString('900', (string) $blob, 'the vote total leaked into the dossier');
    }

    /**
     * The ballot strips popularity from the nominee row itself.
     *
     * Not just "the template does not print it": the field is removed at the service
     * boundary, so a nicer card built later cannot reintroduce it by accident.
     */
    public function test_the_ballot_does_not_hand_the_template_a_vote_count(): void
    {
        $judgeId = (int) DB::table('gates_judges')->insertGetId([
            'name' => 'A Judge', 'email' => 'judge@example.test',
            'programme_ids' => json_encode([95]), 'is_active' => 1]);

        $ballot = (new JudgeService())->ballot($judgeId, 95);
        $blob   = json_encode($ballot);

        foreach (['vote_count', 'organic_vote_count'] as $banned) {
            $this->assertStringNotContainsString('"' . $banned . '"', (string) $blob,
                "the ballot is still handing the template {$banned}");
        }
    }

    /**
     * And the ORDER is not the vote order — nor the same order for every judge.
     *
     * Position is an anchor even when the number is hidden, and a single shared order
     * points the whole panel the same way. Per-judge shuffling makes it cancel; making
     * it deterministic keeps a judge's ballot stable between page loads, because a list
     * that reshuffles is how somebody scores the wrong person.
     */
    public function test_the_ballot_order_is_stable_per_judge_and_differs_between_judges(): void
    {
        // Enough nominees that two independent shuffles agreeing would be a coincidence.
        for ($i = 0; $i < 8; $i++) {
            DB::table('gates_nominees')->insert([
                'category_id' => self::CAT, 'name' => 'Nominee ' . $i, 'status' => 'approved',
                'vote_count' => 1000 - $i * 10, 'organic_vote_count' => 1000 - $i * 10]);
        }
        // The panel judges the shortlist, so every one of them has to be on it — published
        // AFTER the loop, or the eight that make the shuffle measurable are not on the ballot.
        $this->publishShortlist(9500, self::CAT, DB::table('gates_nominees')
            ->where('category_id', self::CAT)->pluck('id')->all());

        $svc = new JudgeService();
        $a = (int) DB::table('gates_judges')->insertGetId(['name' => 'A', 'email' => 'a@example.test',
            'programme_ids' => json_encode([95]), 'is_active' => 1]);
        $b = (int) DB::table('gates_judges')->insertGetId(['name' => 'B', 'email' => 'b@example.test',
            'programme_ids' => json_encode([95]), 'is_active' => 1]);

        $order = static fn(array $ballot): array
            => array_column($ballot['categories'][0]['nominees'] ?? [], 'id');

        $aFirst  = $order($svc->ballot($a, 95));
        $aSecond = $order($svc->ballot($a, 95));
        $bFirst  = $order($svc->ballot($b, 95));

        $this->assertSame($aFirst, $aSecond, 'one judge must see a stable order');
        $this->assertNotSame($aFirst, $bFirst, 'two judges must not share one anchor');

        // And specifically not descending by votes, which is what it used to be.
        $byVotes = DB::table('gates_nominees')->where('category_id', self::CAT)
            ->orderByDesc('vote_count')->pluck('id')->all();
        $this->assertNotSame($byVotes, $aFirst, 'the ballot is still in popularity order');
    }

    // ══ provenance ═══════════════════════════════════════════════════════════

    /**
     * A nominator's case is labelled as a nominator's case.
     *
     * It is usually the best-written text in the dossier and it is advocacy. Rendered
     * indistinguishably from a checked record, it invites a panel to score the prose.
     */
    public function test_the_nomination_case_is_carried_but_marked_as_the_nominators_claim(): void
    {
        DB::table('gates_nominations')->insert([
            'cycle_id' => 9500, 'category_id' => self::CAT, 'nominee_name' => 'Baba Sule',
            'nominator_name' => 'A Customer', 'nominator_email' => 'c@example.test',
            'reference' => 'AFG-NOM-EV1', 'reason' => 'He taught four apprentices for nothing.',
            'status' => 'approved', 'reason_status' => 'approved']);

        $items = (new EvidenceService())->forJudge($this->nomineeId)['items'];
        $case  = array_values(array_filter($items, static fn($i) => $i['kind'] === 'nomination'));

        $this->assertCount(1, $case);
        $this->assertSame('nominator_claim', $case[0]['provenance']);
        $this->assertStringContainsString('not independently checked', $case[0]['provenance_label']);
        $this->assertFalse($case[0]['verified']);
    }

    /** An unmoderated nomination reason is not evidence. */
    public function test_an_unreviewed_nomination_reason_is_withheld(): void
    {
        DB::table('gates_nominations')->insert([
            'cycle_id' => 9500, 'category_id' => self::CAT, 'nominee_name' => 'Baba Sule',
            'nominator_name' => 'A Customer', 'nominator_email' => 'c@example.test',
            'reference' => 'AFG-NOM-EV2', 'reason' => 'Unreviewed allegation about a named person.',
            'status' => 'approved', 'reason_status' => 'pending']);

        $items = (new EvidenceService())->forJudge($this->nomineeId)['items'];
        $this->assertSame([], array_values(array_filter($items, static fn($i) => $i['kind'] === 'nomination')));
    }

    /** Every item says who is speaking. */
    public function test_every_item_carries_a_readable_provenance(): void
    {
        $this->evidence();
        $this->evidence(['title' => 'Press piece', 'provenance' => 'third_party', 'verified' => 0]);

        foreach ((new EvidenceService())->forJudge($this->nomineeId)['items'] as $i) {
            $this->assertNotSame('', $i['provenance_label'] ?? '');
        }
    }

    /**
     * An unrecognised provenance reads as a warning, not as a blank.
     *
     * Asserted against the labeller directly rather than by storing a bogus value: on
     * MySQL `provenance` is an ENUM and the column rejects it outright — which is the
     * behaviour we want and which the SQLite TEXT column silently permits. The fallback
     * still has to exist for a row written before the vocabulary settled, or by a looser
     * driver, and a judge must never see an unlabelled claim.
     */
    public function test_an_unrecognised_provenance_still_warns_the_judge(): void
    {
        $label = EvidenceService::provenanceLabel('nonsense-value');

        $this->assertStringContainsString('caution', $label);
        $this->assertNotSame('', $label);
    }

    // ══ interviews ═══════════════════════════════════════════════════════════

    /** Consent is a precondition, not a footnote. */
    public function test_an_interview_without_consent_is_never_shown(): void
    {
        $this->interview(['consent_given' => 0]);
        $this->assertSame([], (new EvidenceService())->forJudge($this->nomineeId)['interviews']);
    }

    /** Draft and withdrawn transcripts stay out of the panel's hands. */
    public function test_only_published_transcripts_reach_a_judge(): void
    {
        $this->interview(['status' => 'draft']);
        $this->interview(['status' => 'withdrawn', 'transcript' => 'Consent retracted.']);
        $this->assertSame([], (new EvidenceService())->forJudge($this->nomineeId)['interviews']);

        $this->interview();
        $this->assertCount(1, (new EvidenceService())->forJudge($this->nomineeId)['interviews']);
    }

    /**
     * A judge reading a translation is told so, and told what it was translated from.
     *
     * Interviews here will be in Yoruba, Hausa, Igbo and Pidgin as well as English. A
     * translation is somebody's reading of what a person said, and the hedges and
     * emphasis a panel scores may belong to the translator.
     */
    public function test_a_translated_transcript_says_so(): void
    {
        $this->interview(['language' => 'en', 'translated_from' => 'yo']);

        $i = (new EvidenceService())->forJudge($this->nomineeId)['interviews'][0];

        $this->assertSame('yo', $i['translated_from']);
        $this->assertStringContainsString('translated from yo', $i['caveat']);
        $this->assertStringContainsString('meaning may have shifted', $i['caveat']);
    }

    /** And a machine transcript is flagged for the errors machines actually make. */
    public function test_a_machine_transcript_warns_about_names_and_numbers(): void
    {
        $this->interview(['transcript_source' => 'machine', 'transcriber' => 'model:whisper-1']);

        $i = (new EvidenceService())->forJudge($this->nomineeId)['interviews'][0];

        $this->assertStringContainsString('transcribed automatically', $i['caveat']);
        $this->assertStringContainsString('names, places and numbers', $i['caveat']);
    }

    /** A clean human transcript in the spoken language gets no scary caveat. */
    public function test_a_human_transcript_in_the_spoken_language_is_not_hedged(): void
    {
        $this->interview();
        $i = (new EvidenceService())->forJudge($this->nomineeId)['interviews'][0];

        $this->assertStringNotContainsString('translated', $i['caveat']);
        $this->assertStringNotContainsString('automatically', $i['caveat']);
    }

    // ══ a thin dossier is not a weak nominee ═════════════════════════════════

    /**
     * THE FAIRNESS TEST. Absence is reported as OUR gap, not theirs.
     *
     * Without this the system quietly rewards proximity to a camera: whoever the
     * programme managed to interview reads as the serious candidate.
     */
    public function test_a_thin_dossier_says_what_is_missing_and_whose_fault_it_is_not(): void
    {
        $cov = (new EvidenceService())->forJudge($this->rivalId)['coverage'];

        $this->assertFalse($cov['has_interview']);
        $this->assertContains('no interview on file', $cov['missing']);
        $this->assertStringContainsString('not the quality of the work', $cov['note']);
        $this->assertStringContainsString('Score what is here', $cov['note']);
    }

    /**
     * A full dossier stops apologising.
     *
     * "Complete" now includes the nominee having sent something themselves. That became part
     * of the definition when the questionnaire shipped: a dossier assembled entirely by other
     * people, however well documented, is missing the one voice with first-hand knowledge —
     * and a judge should be told which of those they are holding.
     */
    public function test_a_complete_dossier_reports_itself_complete(): void
    {
        $this->evidence();                                     // platform_verified
        $this->evidence(['title' => 'Press', 'provenance' => 'third_party', 'verified' => 0]);
        $this->evidence(['title' => 'Planting register', 'provenance' => 'nominee_supplied', 'verified' => 0]);
        $this->interview();

        $cov = (new EvidenceService())->forJudge($this->nomineeId)['coverage'];

        $this->assertSame([], $cov['missing']);
        $this->assertTrue($cov['has_interview']);
        $this->assertTrue($cov['has_verified']);
        $this->assertTrue($cov['has_nominee']);
    }

    /**
     * The wording of this line matters. It used to read "nothing from a source outside the
     * nomination", which became untrue the moment nominees could submit their own evidence —
     * a dossier holding six things the nominee sent is plainly not "nothing outside the
     * nomination", and a judge reading that would conclude nothing had been gathered.
     */
    public function test_a_nominees_own_evidence_is_not_reported_as_nothing_gathered(): void
    {
        $this->evidence(['title' => 'Planting register 2024', 'provenance' => 'nominee_supplied',
                         'verified' => 0]);

        $cov = (new EvidenceService())->forJudge($this->nomineeId)['coverage'];

        $this->assertTrue($cov['has_nominee']);
        $this->assertNotContains('the nominee has not sent anything themselves', $cov['missing']);
        // Still not independent — the nominee is a second interested party, not a neutral one.
        $this->assertContains('nothing from an independent source', $cov['missing']);
        $this->assertNotContains('nothing from a source outside the nomination', $cov['missing'],
            'the old wording is back, and it now misleads');
    }

    /** Evidence hidden by the programme team stays hidden. */
    public function test_an_item_marked_invisible_is_not_served(): void
    {
        $this->evidence(['title' => 'Internal safeguarding note', 'visible_to_judges' => 0]);

        $titles = array_column((new EvidenceService())->forJudge($this->nomineeId)['items'], 'title');
        $this->assertNotContains('Internal safeguarding note', $titles);
    }

    /** One nominee's dossier never contains another's. */
    public function test_dossiers_do_not_bleed_between_nominees(): void
    {
        $this->evidence(['title' => 'Sule only']);
        $svc = new EvidenceService();

        $mine  = array_column($svc->forJudge($this->nomineeId)['items'], 'title');
        $their = array_column($svc->forJudge($this->rivalId)['items'], 'title');

        $this->assertContains('Sule only', $mine);
        $this->assertNotContains('Sule only', $their);
    }

    /** The batch path and the single path agree. */
    public function test_the_ballot_batch_matches_the_single_lookup(): void
    {
        $this->evidence();
        $this->interview();
        $svc = new EvidenceService();

        $batch = $svc->forBallot([$this->nomineeId, $this->rivalId]);

        $this->assertEquals($svc->forJudge($this->nomineeId), $batch[$this->nomineeId]);
        $this->assertEquals($svc->forJudge($this->rivalId), $batch[$this->rivalId]);
    }
}
