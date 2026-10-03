<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\InterviewBrief;
use AfricaGates\Services\InterviewLive;
use AfricaGates\Services\InterviewService;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Live capture from inside the Meet call — the browser extension's half.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AN EXTENSION AT ALL, AND WHAT IT DOES NOT BUY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Google's own transcription is a paid Workspace feature; its live captions are free on
 * every account. So without this the only route to a transcript on a free account is a
 * person retyping a forty-minute conversation, and a stage that expensive does not happen.
 *
 * It does NOT give the model a voice in the call. That needs a participant seat through the
 * Meet Media API and a persistent media process. The extension reads and displays; a human
 * still speaks.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS DEFEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. THE TOKEN IS SMALL. It reads one sitting's questions and nothing else — no contact
 *      details, no other interview, no scores. A leaked live key must be worth one question
 *      list.
 *   2. NO CONSENT, NO CAPTURE. Not "capture and decide later" — the lines are discarded and
 *      the extension is told why. The publish-time gate alone would mean holding a
 *      recording of somebody who never agreed to one.
 *   3. CAPTION REVISIONS DO NOT TRIPLE THE TRANSCRIPT. A recogniser re-sends each utterance
 *      as it changes its mind, and keeping every version makes the transcript unreadable
 *      and the figure check useless.
 *   4. THE COOLDOWN HOLDS EVEN WHEN THE MODEL FAILS. Otherwise a failing provider becomes a
 *      request every two seconds for the length of the interview.
 */
final class InterviewLiveTest extends TestCase
{
    private const CAT = 9300;
    private const NOM = 9301;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 93, 'title' => 'P', 'slug' => 'p-9300']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9300, 'programme_id' => 93, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9300, 'title' => 'Cat', 'slug' => 'c-9300',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Ada Obi',
            'status' => 'approved', 'vote_count' => 900,
            'story' => 'Her club reaches 400 pupils across 9 schools.',
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9300, 'category_id' => self::CAT,
            'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@example.org',
            'country_code' => 'NG', 'reason' => 'Her club reaches 400 pupils across 9 schools.',
            'nominator_name' => 'N', 'nominator_email' => 'n@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9301',
        ]);
        foreach ([['impact', 'Impact'], ['reach', 'Reach']] as $i => [$slug, $label]) {
            DB::table('gates_judge_criteria')->insertOrIgnore([
                'id' => 9310 + $i, 'programme_id' => null, 'slug' => $slug, 'label' => $label,
                'description' => 'x', 'weight' => 50, 'sort_order' => $i + 1, 'is_active' => 1,
            ]);
        }
    }

    private function open(bool $consent = true): array
    {
        $r = InterviewService::create(self::NOM, [
            'scheduled_at' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
            'meet_url'     => 'https://meet.google.com/qqq-wwww-eee',
        ]);
        $id = (int) $r['id'];
        if ($consent) {
            InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');
        }
        InterviewBrief::build($id);
        return [$id, InterviewLive::tokenFor($id)];
    }

    // ══ 1. the credential ════════════════════════════════════════════════════

    public function test_the_live_key_is_minted_once_and_reused(): void
    {
        [$id, $key] = $this->open();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
        $this->assertSame($key, InterviewLive::tokenFor($id), 'a new key per call would break the extension mid-interview');
    }

    public function test_rotating_the_key_kills_the_old_one(): void
    {
        [$id, $old] = $this->open();
        $new = InterviewLive::rotate($id);

        $this->assertNotSame($old, $new);
        $this->assertFalse(InterviewLive::hello($old)['ok'], 'the old key still works');
        $this->assertTrue(InterviewLive::hello($new)['ok']);
    }

    public function test_a_rubbish_key_is_refused_without_touching_the_database(): void
    {
        foreach (['', 'nope', str_repeat('z', 32), '../../etc/passwd'] as $bad) {
            $r = InterviewLive::hello($bad);
            $this->assertFalse($r['ok'], 'accepted: ' . $bad);
            $this->assertStringContainsString('not valid', (string) $r['message']);
        }
    }

    /**
     * The whole read surface of this credential. A live key sits in a browser extension on
     * somebody's laptop; it must be worth one interview's question list and nothing more.
     */
    public function test_hello_carries_no_contact_details_and_no_other_nominee(): void
    {
        [, $key] = $this->open();
        $r = InterviewLive::hello($key, 'qqq-wwww-eee');
        $json = (string) json_encode($r);

        $this->assertTrue($r['ok']);
        $this->assertSame('Ada Obi', $r['nominee']);
        $this->assertNotEmpty($r['questions']);

        $this->assertStringNotContainsString('ada@example.org', $json, 'the nominee\'s email leaked');
        $this->assertStringNotContainsString('n@example.org', $json, 'the nominator\'s email leaked');
        $this->assertStringNotContainsString('900', $json, 'a vote count leaked into the call');
        // The nominee's own invite token is a different credential and must not travel with this one.
        $this->assertStringNotContainsString((string) InterviewService::tokenFor((int) $r['id']), $json);
    }

    public function test_a_different_meeting_warns_but_still_captures(): void
    {
        [, $key] = $this->open();
        $r = InterviewLive::hello($key, 'zzz-yyyy-xxx');

        $this->assertTrue($r['capture'], 'refusing here strands an operator who moved the call');
        $this->assertStringContainsString('not the room saved', (string) $r['warning']);
    }

    // ══ 2. consent ═══════════════════════════════════════════════════════════

    /**
     * The publish gate alone would mean collecting an unconsented recording and holding it
     * until somebody decided. Capture must not start at all.
     */
    public function test_without_consent_nothing_is_captured_and_the_reason_is_given(): void
    {
        [$id, $key] = $this->open(false);

        $hello = InterviewLive::hello($key);
        $this->assertFalse($hello['capture']);
        $this->assertStringContainsString('has not given permission', (string) $hello['reason']);

        $r = InterviewLive::append($key, [['speaker' => 'Ada Obi', 'text' => 'We began in 2019 with thirty pupils.']]);
        $this->assertSame(0, $r['kept']);
        $this->assertSame([], InterviewLive::buffer($id), 'a line was kept anyway');
        $this->assertSame('', InterviewLive::assemble($id));
    }

    public function test_consent_given_mid_call_starts_capture(): void
    {
        [$id, $key] = $this->open(false);
        InterviewLive::append($key, [['text' => 'nothing should be kept']]);
        $this->assertSame([], InterviewLive::buffer($id));

        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Ada Obi', true, '');

        InterviewLive::append($key, [['speaker' => 'Ada Obi', 'text' => 'Now it can be kept.']]);
        $this->assertCount(1, InterviewLive::buffer($id));
    }

    // ══ 3. the captions themselves ═══════════════════════════════════════════

    /**
     * A recogniser re-sends an utterance as it hears more of it, so the same sentence
     * arrives four times, each a prefix of the next. Keeping them all triples the transcript
     * and buries the figure check; keeping only the first truncates every sentence.
     */
    public function test_a_revised_caption_line_replaces_rather_than_repeats(): void
    {
        [$id, $key] = $this->open();

        foreach (['We began', 'We began in 2019', 'We began in 2019 with thirty pupils'] as $t) {
            InterviewLive::append($key, [['speaker' => 'Ada Obi', 'text' => $t]]);
        }

        $buf = InterviewLive::buffer($id);
        $this->assertCount(1, $buf);
        $this->assertSame('We began in 2019 with thirty pupils', $buf[0]['text']);
    }

    public function test_an_exact_repeat_and_a_shorter_resend_are_both_dropped(): void
    {
        [$id, $key] = $this->open();
        InterviewLive::append($key, [['speaker' => 'A', 'text' => 'The full sentence as heard.']]);
        InterviewLive::append($key, [['speaker' => 'A', 'text' => 'The full sentence as heard.']]);
        InterviewLive::append($key, [['speaker' => 'A', 'text' => 'The full']]);

        $this->assertCount(1, InterviewLive::buffer($id));
    }

    /**
     * Meet keeps ONE caption element per speaker and rewrites it when the recogniser
     * finalises a sentence and starts the next. Replacing on that would silently delete the
     * finished sentence — a transcript that looks complete and is missing whole answers,
     * which is the worst bug this feature could have. Four utterances arrived as two lines
     * until this was fixed.
     */
    public function test_a_recycled_caption_element_does_not_overwrite_a_finished_sentence(): void
    {
        [$id, $key] = $this->open();

        // One block id throughout, as Meet does for one speaker.
        foreach ([
            'About three hundred',
            'About three hundred and twenty girls.',      // a revision — replaces
            'I keep a sign-in book.',                     // a NEW sentence in the same element
            'I keep a sign-in book and the teachers sign it.',
            'We built it from donated parts.',            // and another
        ] as $t) {
            InterviewLive::append($key, [['id' => 'b1', 'speaker' => 'Ada Obi', 'text' => $t]]);
        }

        $texts = array_column(InterviewLive::buffer($id), 'text');
        $this->assertSame([
            'About three hundred and twenty girls.',
            'I keep a sign-in book and the teachers sign it.',
            'We built it from donated parts.',
        ], $texts);
    }

    /**
     * A block id identifies the caption element, so a revision replaces its OWN earlier
     * version wherever it sits. Adjacency alone got this wrong the moment two people talked
     * over each other.
     */
    public function test_a_revision_finds_its_own_line_even_after_somebody_interrupts(): void
    {
        [$id, $key] = $this->open();

        InterviewLive::append($key, [['id' => 'a', 'speaker' => 'Ada Obi', 'text' => 'We started with']]);
        InterviewLive::append($key, [['id' => 'b', 'speaker' => 'Dr Femi', 'text' => 'Sorry, go on.']]);
        InterviewLive::append($key, [['id' => 'a', 'speaker' => 'Ada Obi', 'text' => 'We started with thirty pupils.']]);

        $buf = InterviewLive::buffer($id);
        $this->assertCount(2, $buf, 'the interrupted sentence was left in two halves');
        $this->assertSame('We started with thirty pupils.', $buf[0]['text']);
        $this->assertSame('Sorry, go on.', $buf[1]['text']);
    }

    /**
     * A nominee saying "three hundred and twenty" against a nomination claiming 320 must read
     * as CONFIRMED. The first version mapped each number word separately, so that sentence
     * contributed 100 and 20 to the pool and the check reported the 320 as a discrepancy —
     * a false accusation, in front of the panel deciding the award.
     */
    public function test_a_number_spoken_in_words_confirms_rather_than_contradicts(): void
    {
        $this->assertSame([320, 6],
            \AfricaGates\Services\InterviewReview::wordNumbers('about three hundred and twenty girls across six schools'));
        $this->assertSame([2500],
            \AfricaGates\Services\InterviewReview::wordNumbers('two thousand five hundred'));
        $this->assertSame([50], \AfricaGates\Services\InterviewReview::wordNumbers('fifty pupils'));
        $this->assertSame([], \AfricaGates\Services\InterviewReview::wordNumbers('no numbers at all'));

        DB::table('gates_nominations')->where('id', self::NOM)
            ->update(['reason' => 'Her club reaches 320 pupils across 9 schools.']);
        DB::table('gates_nominees')->where('id', self::NOM)->update(['story' => '']);

        [$id, $key] = $this->open();
        InterviewLive::append($key, [['id' => 'a', 'speaker' => 'Ada Obi',
            'text' => 'It is about three hundred and twenty pupils now, across nine schools, and I '
                    . 'keep the register myself each term so the head teachers can sign it.']]);
        $r = InterviewLive::finish($key);
        $this->assertTrue($r['ok'], (string) $r['message']);

        $figures = [];
        foreach (\AfricaGates\Services\InterviewReview::forInterview($id)['figures'] as $f) {
            $figures[$f['figure']] = $f['status'];
        }
        $this->assertSame('confirmed', $figures['320'] ?? '',
            'a figure spoken in words was reported as a discrepancy');
    }

    public function test_a_new_speaker_starts_a_new_line(): void
    {
        [$id, $key] = $this->open();
        InterviewLive::append($key, [
            ['speaker' => 'Dr Femi', 'text' => 'How many pupils?'],
            ['speaker' => 'Ada Obi', 'text' => 'About four hundred.'],
        ]);
        $this->assertCount(2, InterviewLive::buffer($id));
    }

    /**
     * Caption lines break every few words. A transcript of six-word lines is unreadable, and
     * InterviewReview splits on sentences — which needs sentences to exist.
     */
    public function test_assembling_joins_one_speakers_run_into_a_paragraph(): void
    {
        [$id, $key] = $this->open();
        InterviewLive::append($key, [
            ['speaker' => 'Dr Femi', 'text' => 'Tell us about the club.'],
            ['speaker' => 'Ada Obi', 'text' => 'We started in 2019.'],
            ['speaker' => 'Ada Obi', 'text' => 'Now it runs in nine schools.'],
            ['speaker' => 'Dr Femi', 'text' => 'Who keeps the register?'],
        ]);

        $text = InterviewLive::assemble($id);
        $this->assertSame(
            "Dr Femi: Tell us about the club.\n"
            . "Ada Obi: We started in 2019. Now it runs in nine schools.\n"
            . "Dr Femi: Who keeps the register?",
            $text
        );
    }

    public function test_capture_marks_the_sitting_live_and_stamps_the_time(): void
    {
        [$id, $key] = $this->open();
        $this->assertSame('confirmed', InterviewService::byId($id)->status);

        InterviewLive::append($key, [['speaker' => 'A', 'text' => 'Something was said here.']]);

        $row = InterviewService::byId($id);
        $this->assertSame('live', $row->status);
        $this->assertNotEmpty($row->live_at);
        $this->assertSame('captions', (string) $row->live_source);

        $s = InterviewLive::status($id);
        $this->assertTrue($s['live'], 'the interview screen would show capture as stalled');
        $this->assertSame(1, $s['lines']);
    }

    /**
     * "Running" and "silently broken by a Google markup change" are indistinguishable from
     * the server unless the last caption's arrival time is recorded and shown.
     */
    public function test_a_stalled_capture_is_visible_as_stalled(): void
    {
        [$id, $key] = $this->open();
        InterviewLive::append($key, [['speaker' => 'A', 'text' => 'A line from twenty minutes ago.']]);
        DB::table('gates_interviews')->where('id', $id)
            ->update(['live_at' => Carbon::now()->subMinutes(20)->toDateTimeString()]);

        $s = InterviewLive::status($id);
        $this->assertFalse($s['live']);
        $this->assertSame(20, $s['minutes']);
    }

    // ══ 4. closing ═══════════════════════════════════════════════════════════

    public function test_finishing_publishes_through_the_same_consent_gate(): void
    {
        [$id, $key] = $this->open();
        InterviewLive::append($key, [
            ['speaker' => 'Ada Obi', 'text' => 'We began with thirty pupils in one classroom in 2019.'],
            ['speaker' => 'Ada Obi', 'text' => 'Today the club runs in nine schools and reaches about 380 children.'],
            ['speaker' => 'Ada Obi', 'text' => 'I keep the register myself and the head teachers sign it each term.'],
        ]);

        $r = InterviewLive::finish($key);
        $this->assertTrue($r['ok'], (string) $r['message']);

        $row = DB::table('gates_nominee_interviews')->where('id', (int) $r['transcript_id'])->first();
        $this->assertSame('machine', $row->transcript_source, 'a judge must know a model wrote it');
        $this->assertStringContainsString('live captions', (string) $row->transcriber);
        $this->assertSame(1, (int) $row->consent_given);
        $this->assertSame('done', InterviewService::byId($id)->status);
    }

    public function test_finishing_with_no_captions_says_what_to_check(): void
    {
        [, $key] = $this->open();
        $r = InterviewLive::finish($key);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('CC button', (string) $r['message']);
        $this->assertStringContainsString('needs updating', (string) $r['message'],
            'the operator must be told Google may have changed the page');
    }

    /** The figure check reads a captured transcript exactly as it reads a pasted one. */
    public function test_a_captured_transcript_is_read_like_any_other(): void
    {
        [$id, $key] = $this->open();
        InterviewLive::append($key, [
            ['speaker' => 'Ada Obi', 'text' => 'It is only about 40 pupils at the moment.'],
            ['speaker' => 'Ada Obi', 'text' => 'We lost several schools when I could not travel there.'],
            ['speaker' => 'Ada Obi', 'text' => 'I keep the register myself and the head teachers sign it each term.'],
        ]);
        $r = InterviewLive::finish($key);
        $this->assertTrue($r['ok'], (string) $r['message']);

        $statuses = array_column(\AfricaGates\Services\InterviewReview::forInterview($id)['figures'], 'status');
        $this->assertContains('differs', $statuses,
            '400 in the record against 40 in the call must not pass unremarked');
    }

    // ══ 5. the follow-up cooldown ════════════════════════════════════════════

    /**
     * With no AI provider configured the follow-up simply never appears — and the important
     * property is that FAILING to get one does not turn into a request per caption batch.
     */
    public function test_no_followup_without_a_provider_and_no_hammering(): void
    {
        [$id, $key] = $this->open();
        $pack = InterviewBrief::forInterview($id);
        $qkey = (string) $pack['questions'][0]['key'];

        $long = 'We began in 2019 with thirty pupils in a single classroom and today the club '
              . 'runs in nine different schools across the state.';

        $first = InterviewLive::append($key, [['speaker' => 'Ada Obi', 'text' => $long]], $qkey);
        $this->assertArrayNotHasKey('followup', $first, 'no provider is configured in tests');

        // The cooldown must have been stamped anyway — stamping only on success is how a
        // failing provider becomes a call every two seconds for the whole interview.
        $meta = json_decode((string) InterviewService::byId($id)->live_meta, true);
        $this->assertArrayHasKey('fu', $meta);
        $this->assertArrayHasKey($qkey, $meta['fu']);
    }

    public function test_a_short_answer_is_not_worth_a_followup(): void
    {
        [$id, $key] = $this->open();
        $qkey = (string) InterviewBrief::forInterview($id)['questions'][0]['key'];

        InterviewLive::append($key, [['speaker' => 'Ada Obi', 'text' => 'Yes.']], $qkey);

        $meta = json_decode((string) (InterviewService::byId($id)->live_meta ?? ''), true);
        $this->assertTrue(empty($meta['fu']), 'the model was asked about a two-word answer');
    }

    // ══ 6. the endpoints, through the real router ════════════════════════════

    /**
     * The extension's service worker sends no cookie and its Origin is chrome-extension://…,
     * so these must work without a session AND without being same-origin. If CSRF ever
     * starts refusing them the extension silently stops capturing.
     */
    public function test_the_endpoints_work_with_no_session_and_a_foreign_origin(): void
    {
        [$id, $key] = $this->open();

        $hello = $this->post('/api/interview/live/hello', ['token' => $key, 'meet_code' => 'qqq-wwww-eee']);
        $this->assertSame(200, $hello[0]);
        $this->assertTrue($hello[1]['ok']);
        $this->assertSame($id, $hello[1]['id']);

        $say = $this->post('/api/interview/live/say', ['token' => $key, 'lines' => [
            ['speaker' => 'Ada Obi', 'text' => 'A sentence captured from the call.'],
        ]]);
        $this->assertSame(200, $say[0]);
        $this->assertSame(1, $say[1]['kept']);
        $this->assertCount(1, InterviewLive::buffer($id));
    }

    public function test_a_bad_token_on_the_endpoint_is_a_422_and_not_a_crash(): void
    {
        [$code, $body] = $this->post('/api/interview/live/say', ['token' => 'x', 'lines' => []]);
        $this->assertSame(422, $code);
        $this->assertFalse($body['ok']);
    }

    /** A runaway loop in somebody's browser must not hand this process a megabyte to walk. */
    public function test_an_absurd_batch_is_trimmed_rather_than_parsed_whole(): void
    {
        [$id, $key] = $this->open();
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = ['speaker' => 'A' . $i, 'text' => 'Line number ' . $i . ' of the captions.'];
        }
        $r = $this->post('/api/interview/live/say', ['token' => $key, 'lines' => $lines]);

        $this->assertSame(200, $r[0]);
        $this->assertLessThanOrEqual(200, $r[1]['kept']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function post(string $path, array $payload): array
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            // The extension's real origin. Anything that made this same-origin would be
            // testing a request the extension cannot send.
            ->withHeader('Origin', 'chrome-extension://abcdefghijklmnopabcdefghijklmnop');
        $req->getBody()->write((string) json_encode($payload));
        $req->getBody()->rewind();

        $res = $app->handle($req);
        $body = json_decode((string) $res->getBody(), true);
        return [$res->getStatusCode(), is_array($body) ? $body : []];
    }
}
