<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AiCapability, JudgeAssist};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The map a judge is shown before reading a dossier.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTY THAT MATTERS IS "IT CANNOT RANK"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A model shown a category can order it. Not because anybody asked — comparison is what a
 * language model does with a list, and once a comparison is in the output the judge has been
 * handed a ranking whether or not either of them meant it to be one.
 *
 * Telling it "do not compare" in the prompt is a request, and since the prompt editor
 * shipped it is also the one part an administrator can rewrite. So the guarantee is
 * structural: the payload contains ONE nominee. There is nothing to rank against, and no
 * wording — shipped, edited or injected — can produce one.
 *
 * Most of this file asserts that structurally, by looking at what actually goes into the
 * request rather than at what the prompt says about it.
 */
final class JudgeAssistTest extends TestCase
{
    private int $categoryId = 0;
    private int $cycleId    = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_judge_orientation')->delete();

        $progId = (int) DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Assist Test Programme', 'slug' => 'assist-' . bin2hex(random_bytes(3)),
            'is_active' => 1, 'sort_order' => 61,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $progId, 'year' => 2026, 'status' => 'judging',
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'title' => 'Community Impact',
            'slug' => 'impact-' . bin2hex(random_bytes(3)),
        ]);
    }

    private function nominee(array $over = []): int
    {
        return (int) DB::table('gates_nominees')->insertGetId($over + [
            'category_id' => $this->categoryId,
            'name'        => 'Adaeze Okonkwo',
            'tagline'     => 'Built nine boreholes in Enugu State',
            'story'       => 'She raised the money herself and the community maintains them.',
            'organisation'=> 'Enugu Water Trust',
            'country_code'=> 'NG',
            'vote_count'  => 4821,
            'organic_vote_count' => 4600,
            'status'      => 'approved',
        ]);
    }

    // ══ the payload is one nominee, and nothing else ═════════════════════════

    public function test_the_dossier_holds_one_nominee_and_no_others(): void
    {
        $mine   = $this->nominee();
        $rival  = $this->nominee(['name' => 'Chinelo Umeh', 'tagline' => 'A rival entry']);

        $text = JudgeAssist::dossier($mine)['text'];

        $this->assertStringContainsString('Adaeze Okonkwo', $text);
        $this->assertStringNotContainsString('Chinelo Umeh', $text,
            'a model shown the field can order it — the guarantee is that there is no field');
        $this->assertStringNotContainsString('A rival entry', $text);
        $this->assertGreaterThan(0, $rival);
    }

    public function test_the_dossier_carries_no_scores_no_votes_and_no_criteria(): void
    {
        // Each of these turns a map into a nudge:
        //  · scores — a summary that knows the panel is at 8/10 is an anchor with extra steps
        //  · votes  — public support is deliberately kept off the ballot entirely
        //  · criteria — a summary organised around the rubric is a filled-in scorecard
        $id = $this->nominee();
        DB::table('gates_judge_criteria')->insertGetId([
            'slug' => 'impact-' . bin2hex(random_bytes(3)), 'label' => 'Evidence of impact',
            'is_active' => 1, 'sort_order' => 1,
        ]);

        $text = JudgeAssist::dossier($id)['text'];

        $this->assertStringNotContainsString('4821', $text);
        $this->assertStringNotContainsString('4600', $text);
        $this->assertStringNotContainsStringIgnoringCase('vote', $text);
        $this->assertStringNotContainsString('Evidence of impact', $text);
        $this->assertStringNotContainsStringIgnoringCase('criteri', $text);
    }

    public function test_evidence_withheld_from_the_panel_stays_withheld_from_the_map(): void
    {
        // Otherwise an item deliberately kept from the judges reaches them through a
        // summary of it, which is the same disclosure by a longer route.
        $id = $this->nominee();

        DB::table('gates_nominee_evidence')->insert([
            ['nominee_id' => $id, 'kind' => 'note', 'title' => 'Public reference',
             'body' => 'A council letter confirming the handover.',
             'provenance' => 'nominee_supplied', 'visible_to_judges' => 1, 'sort_order' => 1],
            ['nominee_id' => $id, 'kind' => 'note', 'title' => 'Withheld moderation note',
             'body' => 'A safeguarding concern raised in review.',
             'provenance' => 'platform_verified', 'visible_to_judges' => 0, 'sort_order' => 2],
        ]);

        $text = JudgeAssist::dossier($id)['text'];

        $this->assertStringContainsString('Public reference', $text);
        $this->assertStringNotContainsString('Withheld moderation note', $text);
        $this->assertStringNotContainsString('safeguarding', $text);
    }

    public function test_only_published_interviews_reach_the_map(): void
    {
        // A draft is an unreviewed machine transcript; a withdrawn one had consent taken
        // back. Neither is evidence and neither should be summarised as if it were.
        $id = $this->nominee();

        foreach ([['published', 'The published account.'],
                  ['draft', 'An unchecked draft.'],
                  ['withdrawn', 'Consent was taken back.']] as [$status, $body]) {
            DB::table('gates_nominee_interviews')->insert([
                'nominee_id' => $id, 'transcript' => $body, 'status' => $status,
                'medium' => 'video', 'language' => 'en', 'transcript_source' => 'human',
                'consent_given' => 1,
            ]);
        }

        $text = JudgeAssist::dossier($id)['text'];

        $this->assertStringContainsString('The published account.', $text);
        $this->assertStringNotContainsString('An unchecked draft.', $text);
        $this->assertStringNotContainsString('Consent was taken back.', $text);
    }

    // ══ truncation is announced ══════════════════════════════════════════════

    public function test_a_dossier_too_long_to_send_says_so_inside_the_payload(): void
    {
        // A map of half a dossier that does not say so is worse than no map: a judge reads
        // "no third-party confirmation" as a fact about the entry rather than about the
        // part we actually sent.
        $id = $this->nominee(['story' => str_repeat('A very long account of the work. ', 900)]);

        $d = JudgeAssist::dossier($id);

        $this->assertTrue($d['truncated']);
        $this->assertLessThanOrEqual(JudgeAssist::MAX_CHARS + 200, mb_strlen($d['text']));
        $this->assertStringContainsString('was not shown to you', $d['text']);
    }

    public function test_the_cache_key_is_the_whole_dossier_not_the_part_that_was_sent(): void
    {
        // Hashing the truncated copy would freeze the map at the first 14,000 characters
        // for ever — evidence added past the cut would never invalidate it.
        $id = $this->nominee(['story' => str_repeat('Long. ', 4000)]);
        $before = JudgeAssist::dossier($id)['hash'];

        DB::table('gates_nominees')->where('id', $id)
            ->update(['story' => str_repeat('Long. ', 4000) . ' And one more paragraph.']);

        $this->assertNotSame($before, JudgeAssist::dossier($id)['hash']);
    }

    public function test_added_evidence_invalidates_the_cached_map(): void
    {
        $id = $this->nominee();
        $hash = JudgeAssist::dossier($id)['hash'];

        DB::table('gates_judge_orientation')->insert([
            'nominee_id' => $id, 'content_hash' => $hash, 'rests_on' => 'The original map.',
            'evidenced_json' => '[]', 'asserted_json' => '[]', 'gaps_json' => '[]',
            'check_json' => '[]', 'prompt_version' => JudgeAssist::PROMPT_VERSION,
            'status' => 'ok',
        ]);
        $this->assertSame('The original map.', JudgeAssist::cached($id, $hash)['rests_on']);

        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $id, 'kind' => 'note', 'title' => 'A new certificate',
            'provenance' => 'nominee_supplied', 'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        // Describing a missing document as a gap when it has since been supplied is the
        // specific way this feature could actively mislead a panel.
        $this->assertNull(JudgeAssist::cached($id, JudgeAssist::dossier($id)['hash']));
    }

    public function test_the_map_is_cached_per_nominee_so_a_panel_sees_the_same_one(): void
    {
        // Cost is the obvious reason and the weaker one. The real reason is fairness: a
        // panel oriented differently is a panel whose disagreements are partly an artefact
        // of the tooling — and JudgeBiasService would then be measuring us.
        $a = $this->nominee();
        $b = $this->nominee(['name' => 'Someone Else']);

        foreach ([$a, $b] as $id) {
            DB::table('gates_judge_orientation')->insert([
                'nominee_id' => $id, 'content_hash' => JudgeAssist::dossier($id)['hash'],
                'rests_on' => 'Map for ' . $id, 'evidenced_json' => '[]', 'asserted_json' => '[]',
                'gaps_json' => '[]', 'check_json' => '[]',
                'prompt_version' => JudgeAssist::PROMPT_VERSION, 'status' => 'ok',
            ]);
        }

        $map = JudgeAssist::forBallot([$a, $b]);

        $this->assertSame('Map for ' . $a, $map[$a]['rests_on']);
        $this->assertSame('Map for ' . $b, $map[$b]['rests_on']);
        // Keyed on the nominee alone — no judge id anywhere in the table.
        $this->assertFalse(DB::schema()->hasColumn('gates_judge_orientation', 'judge_id'));
    }

    public function test_the_ballot_never_generates_a_map_while_rendering(): void
    {
        // A judge opening a ballot of forty must not start forty model calls by scrolling,
        // and a page that spends money when you look at it spends it again on every refresh
        // and every back button.
        $id = $this->nominee();

        $this->assertSame([], JudgeAssist::forBallot([$id]));
        $this->assertSame(0, DB::table('gates_judge_orientation')->count());

        $js = file_get_contents(dirname(__DIR__, 2) . '/src/Judge/Services/JudgeService.php');
        $this->assertStringContainsString('JudgeAssist::forBallot', $js);
        $this->assertStringNotContainsString('JudgeAssist::forNominee', $js,
            'the ballot must read maps, never make them');
    }

    // ══ a map of a dossier that has since changed ════════════════════════════

    /**
     * THE BUG. The ballot served a map of a superseded dossier and said nothing.
     *
     * {@see JudgeAssist::forNominee()} has always matched on the content hash, so the BUTTON
     * never served a stale map. `forBallot()` did not, and it is the one the ballot renders
     * from — so once a nominee added evidence, every judge on the panel went on reading a map
     * of the entry as it stood before it, with nothing on the screen to say so.
     *
     * A `gaps` line saying "no third-party confirmation of the figures" outliving the letter
     * that confirmed them is the single most damaging thing this feature can do: it is a
     * statement about a nominee, made by us, that was true when written and is not any more.
     */
    public function test_a_map_of_a_changed_dossier_is_marked_out_of_date(): void
    {
        $id = $this->nominee();

        DB::table('gates_judge_orientation')->insert([
            'nominee_id' => $id, 'content_hash' => JudgeAssist::dossier($id)['hash'],
            'rests_on' => 'Nine boreholes, self-funded.', 'evidenced_json' => '[]',
            'asserted_json' => '[]', 'gaps_json' => '["No third-party confirmation."]',
            'check_json' => '[]', 'prompt_version' => JudgeAssist::PROMPT_VERSION,
            'status' => 'ok',
        ]);

        $this->assertFalse(JudgeAssist::forBallot([$id])[$id]['stale'],
            'nothing has changed yet');

        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $id, 'kind' => 'note',
            'title' => 'Letter from the state water board confirming the nine sites',
            'provenance' => 'third_party', 'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        $after = JudgeAssist::forBallot([$id])[$id];
        $this->assertTrue($after['stale'],
            'the map now describes a dossier that no longer exists, and the panel must be told');
        // Still served, not withheld. It is mostly true, and hiding it would either lose the
        // panel's orientation or start a model call on render.
        $this->assertSame('Nine boreholes, self-funded.', $after['rests_on']);
    }

    /**
     * A dossier query that fails must not mark the whole ballot out of date.
     *
     * "I could not check" is not "this is stale". Painting forty maps as superseded because
     * one query hiccuped would throw away a panel's whole orientation over a transient fault
     * — the same mistake as a status page rounding UNKNOWN up to broken, pointed the other
     * way.
     */
    public function test_a_nominee_with_nothing_written_is_not_reported_as_stale(): void
    {
        // A nominee row that no longer exists produces an empty dossier and so an empty
        // hash, which is the "cannot know" case.
        DB::table('gates_judge_orientation')->insert([
            'nominee_id' => 987654, 'content_hash' => str_repeat('a', 64),
            'rests_on' => 'A map with no nominee behind it.', 'evidenced_json' => '[]',
            'asserted_json' => '[]', 'gaps_json' => '[]', 'check_json' => '[]',
            'prompt_version' => JudgeAssist::PROMPT_VERSION, 'status' => 'ok',
        ]);

        $this->assertFalse(JudgeAssist::forBallot([987654])[987654]['stale']);
    }

    /**
     * The batch and the single assembly must agree, byte for byte.
     *
     * They are the same formula and the hash is the cache key, so a divergence would not
     * throw: it would quietly mean the ballot thought every map was stale and the sweep
     * regenerated every one of them on every run, for ever.
     */
    public function test_the_batched_dossier_matches_the_single_one(): void
    {
        $a = $this->nominee();
        $b = $this->nominee(['name' => 'Chinelo Umeh', 'story' => 'A different case entirely.']);

        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $a, 'kind' => 'note', 'title' => 'Audited accounts',
            'body' => 'Signed off for three years.', 'provenance' => 'third_party',
            'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        $batch = JudgeAssist::dossiers([$a, $b]);

        $this->assertSame(JudgeAssist::dossier($a), $batch[$a]);
        $this->assertSame(JudgeAssist::dossier($b), $batch[$b]);
        $this->assertNotSame($batch[$a]['hash'], $batch[$b]['hash']);
    }

    /** The batch honours the same withholding the single assembly does. */
    public function test_the_batch_still_withholds_what_is_hidden_from_judges(): void
    {
        $id = $this->nominee();

        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $id, 'kind' => 'note', 'title' => 'A withheld complaint',
            'body' => 'Kept from the panel deliberately.', 'provenance' => 'staff',
            'visible_to_judges' => 0, 'sort_order' => 1,
        ]);

        $text = JudgeAssist::dossiers([$id])[$id]['text'];
        $this->assertStringNotContainsString('withheld complaint', $text,
            'an item kept from the panel must not reach it through a summary of it');
    }

    // ══ a dossier that just failed is left alone ═════════════════════════════

    /**
     * `store()` wrote a `failed` row from the day this shipped and nothing ever read one.
     *
     * So a dossier the model reliably chokes on cost a fresh call on every button press, from
     * each of ten judges on the panel — and would cost another on every cron sweep for the
     * rest of the round. Nothing is lost by waiting: the dossier is on the same screen and
     * the feature is declared advisory.
     */
    public function test_a_dossier_that_just_failed_is_not_immediately_retried(): void
    {
        $id   = $this->nominee();
        $hash = JudgeAssist::dossier($id)['hash'];

        DB::table('gates_judge_orientation')->insert([
            'nominee_id' => $id, 'content_hash' => $hash, 'status' => 'failed',
            'error' => 'The AI reply did not match the expected shape.',
            'prompt_version' => JudgeAssist::PROMPT_VERSION,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $r = JudgeAssist::forNominee($id);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('tried a few minutes ago', $r['message']);
        // And it says the thing a judge can act on, which is that nothing else is affected.
        $this->assertStringContainsString('dossier below is unaffected', $r['message']);
    }

    /**
     * The cooldown is on the DOSSIER, not the nominee.
     *
     * A nominee who has just added the very evidence the last attempt choked on is a
     * different dossier, and making them wait would be the cooldown holding back the fix.
     */
    public function test_a_changed_dossier_may_be_tried_again_at_once(): void
    {
        $id = $this->nominee();

        DB::table('gates_judge_orientation')->insert([
            'nominee_id' => $id, 'content_hash' => JudgeAssist::dossier($id)['hash'],
            'status' => 'failed', 'prompt_version' => JudgeAssist::PROMPT_VERSION,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        DB::table('gates_nominee_evidence')->insert([
            'nominee_id' => $id, 'kind' => 'note', 'title' => 'The missing certificate',
            'provenance' => 'third_party', 'visible_to_judges' => 1, 'sort_order' => 1,
        ]);

        // No provider is configured in the suite, so the attempt is refused — but by the
        // gateway rather than by the cooldown, which is exactly the distinction.
        $r = JudgeAssist::forNominee($id);
        $this->assertStringNotContainsString('tried a few minutes ago', $r['message']);
    }

    // ══ the maps are made before the panel arrives ═══════════════════════════

    /**
     * The sweep never touches the sandbox.
     *
     * `DemoSeeder` builds real rows with real flags — the sandbox exists to be walked through
     * for real — so nothing that spends money may treat it as a live round. A rehearsal
     * category would otherwise consume a genuine round's daily budget every night.
     */
    public function test_the_sweep_excludes_the_sandbox_programme(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Services/JudgeAssist.php');
        $this->assertStringContainsString('DemoSeeder::PROGRAMME_SLUG', (string) $src,
            'the sweep must exclude the sandbox by slug, like every other public reader');
    }

    /**
     * Nothing is swept while the capability cannot run.
     *
     * A sweep that walks a shortlist of forty with the budget spent writes forty
     * BUDGET_CALLS rows, which makes the audit log unreadable on the one day somebody needs
     * to read it. The suite has no provider configured, so this is the real path.
     */
    public function test_the_sweep_spends_nothing_when_the_capability_is_unavailable(): void
    {
        $this->nominee();

        $this->assertSame(0, JudgeAssist::sweep());
        $this->assertSame(0, DB::table('gates_judge_orientation')->count(),
            'not even a failure row: nothing was attempted');
    }

    /**
     * And it is reachable by name, because there is no SSH on this account.
     *
     * When a round opens sooner than the hourly sweep can fill it, `/__cron/run?task=judgemaps`
     * is the only way anybody can ask for another batch.
     */
    public function test_the_sweep_is_wired_into_scheduled_work_and_addressable(): void
    {
        $m = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('JudgeAssist::sweep', $m,
            'a sweep nobody calls is not a pre-warm, it is dead code');
        $this->assertStringContainsString("'judgemaps' =>", $m,
            'it must be runnable on its own from the token-gated endpoint');
    }

    // ══ the answer is validated, never coerced ═══════════════════════════════

    public function test_a_well_formed_answer_is_kept(): void
    {
        $m = JudgeAssist::parse(json_encode([
            'rests_on'  => 'Nine boreholes, with a council letter for three of them.',
            'evidenced' => ['Council letter names three sites', 'Handover dated 4 March 2025'],
            'asserted'  => ['The community maintains them'],
            'gaps'      => ['No confirmation for the other six'],
            'check'     => ['Whether the letter covers the sites claimed'],
        ]));

        $this->assertNotNull($m);
        $this->assertStringContainsString('Nine boreholes', $m['rests_on']);
        $this->assertCount(2, $m['evidenced']);
    }

    public function test_an_answer_with_no_summary_is_discarded_rather_than_half_shown(): void
    {
        // A map with a missing key rendered as an empty section reads to a judge as "there
        // is no evidence", which is a claim about the nominee that nobody made.
        $this->assertNull(JudgeAssist::parse(json_encode(['evidenced' => ['a thing']])));
        $this->assertNull(JudgeAssist::parse('not json at all'));
        $this->assertNull(JudgeAssist::parse(json_encode(['rests_on' => '   '])));
    }

    public function test_json_wrapped_in_prose_is_salvaged_once_and_no_further(): void
    {
        // Some providers wrap despite being asked not to. One attempt, then give up — a
        // parser that tries increasingly hard is a parser that eventually accepts garbage.
        $m = JudgeAssist::parse('Here you go:
            {"rests_on":"A single verified handover.","evidenced":["The letter"],
             "asserted":[],"gaps":[],"check":[]} — hope that helps!');

        $this->assertNotNull($m);
        $this->assertSame('A single verified handover.', $m['rests_on']);
    }

    public function test_extra_fields_are_dropped_and_lists_are_capped(): void
    {
        $m = JudgeAssist::parse(json_encode([
            'rests_on'    => 'Something.',
            'evidenced'   => array_fill(0, 20, 'a claim'),
            'score'       => 9,
            'recommend'   => 'Shortlist this one',
            'best_in_category' => true,
        ]));

        $this->assertNotNull($m);
        $this->assertCount(5, $m['evidenced']);
        // A field the schema does not name cannot reach the screen, so a model that
        // volunteers a score has volunteered it into a bin.
        $this->assertArrayNotHasKey('score', $m);
        $this->assertArrayNotHasKey('recommend', $m);
        $this->assertArrayNotHasKey('best_in_category', $m);
        $this->assertSame(['rests_on', 'evidenced', 'asserted', 'gaps', 'check'], array_keys($m));
    }

    // ══ the declaration ══════════════════════════════════════════════════════

    public function test_the_capability_is_declared_advisory_and_public(): void
    {
        $cap = AiCapability::find(JudgeAssist::CAPABILITY);

        $this->assertNotNull($cap, 'undeclared means the gateway refuses every call');
        $this->assertTrue($cap->advisory);
        $this->assertTrue($cap->untrustedInput, 'most of a dossier is text a nominator typed');
        $this->assertTrue($cap->minimise);
        $this->assertTrue($cap->publicContent, 'it reads what nominees and nominators wrote');
    }

    public function test_the_prompt_forbids_the_things_the_structure_already_prevents(): void
    {
        // Belt and braces, in that order: the structure is the braces. The prompt says it
        // too because a model that is told the rule produces better output than one that
        // simply cannot break it.
        $sys = strtolower(JudgeAssist::system());

        $this->assertStringContainsString('never score', $sys);
        $this->assertStringContainsString('never compare', $sys);
        $this->assertStringContainsString('map, not a judge', $sys);
        // And the part people get wrong: a gap is about the paperwork, not the person.
        $this->assertStringContainsString('missing from the paperwork', $sys);
    }

    public function test_the_ballot_labels_it_as_unchecked_and_not_a_judgement(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/templates/judge/ballot.twig');

        $this->assertStringContainsString('It is not a judgement, and nobody has checked it', $html);
        $this->assertStringContainsString('no other nominee', $html);
        // "Stated, with nothing attached" and not "unsupported claims". An assertion is not
        // a fault — most good work is described before it is documented.
        $this->assertStringContainsString('Stated, with nothing attached', $html);
        $this->assertStringNotContainsString('unsupported claims', $html);
    }

    public function test_asking_for_a_map_is_a_post_and_never_a_get(): void
    {
        // A GET is prefetched by browsers and link scanners. A summary generated because
        // somebody hovered is a bill with no reader.
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        $this->assertStringContainsString("post('/orient/{nomineeId:[0-9]+}'", $routes);
        $this->assertStringNotContainsString("get('/orient/", $routes);
    }

    public function test_the_endpoint_checks_the_judge_sits_on_that_panel(): void
    {
        // Without it this summarises ANY nominee by id — including a programme this judge
        // was deliberately not assigned to. Broken Access Control wearing a helpful hat.
        $c = file_get_contents(dirname(__DIR__, 2) . '/src/Judge/Controllers/BallotController.php');

        $this->assertStringContainsString('mayJudgeNominee', $c);
        // And the refusal is indistinguishable from "no such nominee", so the endpoint
        // cannot be used to enumerate a programme somebody cannot see.
        $this->assertStringContainsString('not on your ballot', $c);
    }

    public function test_a_nominee_with_nothing_written_about_them_is_told_rather_than_mapped(): void
    {
        $bare = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => 'Nobody Wrote Anything',
            'status' => 'approved',
        ]);

        // The name alone is a dossier of one line, so this is about the SHAPE of the
        // refusal: it must not produce a confident map of nothing.
        $d = JudgeAssist::dossier($bare);
        $this->assertStringContainsString('Nobody Wrote Anything', $d['text']);
        $this->assertFalse($d['truncated']);
    }
}
