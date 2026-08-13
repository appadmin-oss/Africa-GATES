<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiPrivacy;
use AfricaGates\Services\LegalDocument;
use AfricaGates\Services\QuestionnaireChat as C;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\QuestionnaireVoice as V;
use AfricaGates\Services\VoiceService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Voice on the questionnaire — questions read out, answers spoken.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO THINGS THAT MATTER MOST HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. A TRANSCRIPTION IS NEVER AN ANSWER. It goes back to the PAGE, for the nominee to read,
 *    correct and send themselves. Speech recognition on the Englishes actually spoken across
 *    this continent mishears names, places and numbers — and those are exactly the three
 *    things a judge quotes. A transcriber that wrote straight into `answers_json` would put
 *    words in somebody's mouth in the one record whose whole value is that they are theirs.
 *
 * 2. THE PLATFORM ONLY EVER SPEAKS ITS OWN QUESTIONS. The endpoint is addressed by turn
 *    INDEX, resolved against that submission's own conversation, so it cannot be handed a
 *    paragraph to read. That is a cost control (ElevenLabs bills per character, and an
 *    endpoint that speaks anything is an open proxy on the operator's invoice) and it is what
 *    bounds a submission's lifetime spend without any quota table: a conversation holds a
 *    bounded number of turns and the clip cache is keyed by the text, so the worst case is
 *    "each of its own questions, once, ever".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE REST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   3. WITH NO KEY, NOTHING IS OFFERED. Not a disabled button, not an "unavailable" notice —
 *      the page is the working text conversation it was before voice existed.
 *   4. A FAILED TRANSCRIPTION COSTS THE NOMINEE NOTHING, because charging their allowance for
 *      a call that gave them nothing back turns a bad connection into a limit they hit.
 *   5. THE NOTICE TELLS THE TRUTH, in both states, and says the recording is not kept.
 */
final class QuestionnaireVoiceTest extends TestCase
{
    private const PROG = 9600;
    private const CAT  = 9600;
    private const NOM  = 9601;

    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/ag-voice-' . bin2hex(random_bytes(6));

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'title' => 'P', 'slug' => 'p-9600',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9600, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9600, 'title' => 'C', 'slug' => 'c-9600',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 800,
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9600, 'category_id' => self::CAT,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'grace@example.org',
            'country_code' => 'GH', 'reason' => 'Runs a girls coding club.',
            'nominator_name' => 'Kofi', 'nominator_email' => 'k@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9601',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->cacheDir);
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function open(): array
    {
        $r = Q::open(self::NOM);
        return [(int) $r['id'], (string) $r['token']];
    }

    /**
     * A VoiceService with a key and no network.
     *
     * The clip cache is pointed at a per-test directory. It is keyed by TEXT and therefore
     * shared between submissions by design — which makes it shared across a test run too, and
     * a suite whose second run reports "cached" where its first reported "fetched" passes or
     * fails depending on what ran yesterday.
     */
    private function voice(string $audio = "\xFF\xFB\x90ID3-not-really", ?array $stt = null): VoiceService
    {
        return new class ('k-test', 'v-test', null, null, 5, $this->cacheDir, $audio, $stt) extends VoiceService {
            public int $spoke = 0;
            public int $heard = 0;
            /** @var list<string> */
            public array $said = [];

            public function __construct(?string $key, ?string $voice, ?string $tts, ?string $stt,
                                        int $timeout, ?string $cacheRoot,
                                        private readonly string $audio = '',
                                        private readonly ?array $result = null)
            { parent::__construct($key, $voice, $tts, $stt, $timeout, $cacheRoot); }

            protected function httpSpeak(string $text): ?string
            {
                $this->spoke++;
                $this->said[] = $text;
                return $this->audio !== '' ? $this->audio : null;
            }

            protected function httpTranscribe(string $bytes, string $filename, string $mime): ?array
            {
                $this->heard++;
                return $this->result;
            }
        };
    }

    /** A VoiceService with no key at all — the state most deployments run in. */
    private function silent(): VoiceService
    {
        return new VoiceService(null);
    }

    // ══ 1. the words a judge reads are the nominee's ══════════════════════════

    /**
     * THE test in this file.
     *
     * A transcription comes back to the caller and touches nothing. The nominee then sends it
     * — corrected or not — through the ordinary chat turn, and only that writes an answer.
     */
    public function test_a_transcription_does_not_become_an_answer(): void
    {
        [$id, $token] = $this->open();
        C::start($token);

        $before = (string) DB::table('gates_nominee_submissions')->where('id', $id)->value('answers_json');

        $r = V::hear($token, 'audio-bytes', 'a.webm', 'audio/webm',
                     $this->voice('mp3', ['text' => 'I train girls in Accra to write code.']));

        $this->assertTrue($r['ok']);
        $this->assertSame('I train girls in Accra to write code.', $r['text']);
        $this->assertSame($before,
            (string) DB::table('gates_nominee_submissions')->where('id', $id)->value('answers_json'),
            'the transcriber wrote an answer into the record — a judge would read that as a quotation');

        // Nor a turn in the conversation: a spoken answer that appeared as though it had been
        // sent would leave the nominee with no way to correct it.
        $mine = array_filter(C::state($token)['turns'], static fn (array $t): bool => ($t['who'] ?? '') === 'you');
        $this->assertSame([], array_values($mine), 'the transcription posted itself as a turn');
    }

    /**
     * And the nominee's corrected version is what is stored — the transcription is a draft,
     * so a misheard place name is fixable before anybody sees it.
     */
    public function test_the_nominees_corrected_version_is_what_is_stored(): void
    {
        [, $token] = $this->open();
        C::start($token);

        V::hear($token, 'audio', 'a.webm', 'audio/webm',
                $this->voice('mp3', ['text' => 'I train girls in a car to write code.']));
        // "Accra" heard as "a car". The nominee fixes it, then sends.
        C::say($token, 'I train girls in Accra to write code.');

        $answers = json_decode((string) DB::table('gates_nominee_submissions')
            ->where('nominee_id', self::NOM)->value('answers_json'), true);

        $this->assertContains('I train girls in Accra to write code.', array_values($answers));
        $this->assertNotContains('I train girls in a car to write code.', array_values($answers));
    }

    // ══ 2. the platform only speaks its own questions ════════════════════════

    public function test_it_reads_out_a_question_it_actually_asked(): void
    {
        [, $token] = $this->open();
        C::start($token);
        $v = $this->voice();

        $r = V::say($token, 1, $v);   // turn 0 is the greeting, turn 1 the first question

        $this->assertTrue($r['ok']);
        $this->assertSame('audio/mpeg', $r['mime']);
        $this->assertSame(1, $v->spoke);
        $this->assertStringContainsString('what is the work', $v->said[0]);
    }

    /**
     * The load-bearing guard. A caller cannot hand this the text to speak — only a position in
     * a conversation the platform itself wrote. Without it, `/my-work/{token}/speak` is a
     * free text-to-speech API billed to the awards operator.
     */
    public function test_it_will_not_read_out_a_turn_that_does_not_exist(): void
    {
        [, $token] = $this->open();
        C::start($token);
        $v = $this->voice();

        foreach ([-1, 99, 400] as $index) {
            $r = V::say($token, $index, $v);
            $this->assertFalse($r['ok'], "index {$index} was spoken");
        }
        $this->assertSame(0, $v->spoke, 'a request that resolved to nothing still cost a call');
    }

    /**
     * Nor a nominee's own answer. Reading somebody's words back to them in a synthetic voice
     * is not a feature anybody asked for, and providing it would double the bill.
     */
    public function test_it_will_not_read_the_nominees_own_words_back_at_them(): void
    {
        [, $token] = $this->open();
        C::start($token);
        C::say($token, 'I run a coding club for girls in Accra, since 2019.');

        $turns = C::state($token)['turns'];
        $mine  = null;
        foreach ($turns as $i => $t) if (($t['who'] ?? '') === 'you') $mine = $i;
        $this->assertNotNull($mine, 'the nominee\'s turn was not recorded at all');

        $v = $this->voice();
        $this->assertFalse(V::say($token, (int) $mine, $v)['ok']);
        $this->assertSame(0, $v->spoke);
    }

    /** A failure says nothing about WHY — the difference is a way to map a conversation. */
    public function test_a_refusal_does_not_say_which_kind_of_refusal_it_is(): void
    {
        [, $token] = $this->open();
        C::start($token);

        $missing = V::say($token, 99, $this->voice())['message'];
        C::say($token, 'I run a coding club for girls in Accra.');
        $mineIdx = null;
        foreach (C::state($token)['turns'] as $i => $t) if (($t['who'] ?? '') === 'you') $mineIdx = $i;
        $mine = V::say($token, (int) $mineIdx, $this->voice())['message'];

        $this->assertSame($missing, $mine);
    }

    public function test_an_unknown_token_is_refused_without_a_call(): void
    {
        $v = $this->voice();
        $this->assertFalse(V::say(str_repeat('a', 32), 1, $v)['ok']);
        $this->assertFalse(V::hear(str_repeat('a', 32), 'bytes', 'a.webm', 'audio/webm', $v)['ok']);
        $this->assertSame(0, $v->spoke);
        $this->assertSame(0, $v->heard);
    }

    // ══ 3. with no key, nothing is offered ═══════════════════════════════════

    /**
     * Not a disabled microphone, not an "unavailable" banner. A nominee should never be shown
     * the shape of a feature the operator has not bought — it reads as the platform being
     * broken, on a page whose entire job is to make somebody feel able to finish.
     */
    public function test_with_no_key_voice_is_simply_absent(): void
    {
        $this->assertFalse(V::enabled($this->silent()));
        $this->assertFalse($this->silent()->configured());

        [, $token] = $this->open();
        C::start($token);
        $this->assertFalse(V::say($token, 1, $this->silent())['ok']);
        $this->assertFalse(V::hear($token, 'b', 'a.webm', 'audio/webm', $this->silent())['ok']);
    }

    public function test_the_operator_is_told_why_it_is_off_and_the_nominee_is_not(): void
    {
        $why = $this->silent()->why();

        $this->assertStringContainsString('ELEVENLABS_API_KEY', $why, 'not actionable');
        $this->assertStringContainsString('Settings', $why);
        // The nominee-facing refusal carries none of that: "no key is configured" is not
        // information a person filling in their own questionnaire can use.
        [, $token] = $this->open();
        C::start($token);
        $this->assertStringNotContainsString('ELEVENLABS',
            (string) V::say($token, 1, $this->silent())['message']);
    }

    public function test_the_questionnaire_still_works_end_to_end_with_no_voice(): void
    {
        [, $token] = $this->open();
        $st = C::start($token);
        $this->assertTrue($st['ok']);

        $r = C::say($token, 'I run a coding club for 400 girls in Accra, started in 2019.');
        $this->assertTrue($r['ok']);
    }

    // ══ 4. what it costs, and who pays for a failure ═════════════════════════

    /** Cache hits are free, and the counter says so — it answers "what did this cost". */
    public function test_a_replayed_question_is_not_charged_twice(): void
    {
        [$id, $token] = $this->open();
        C::start($token);
        $v = $this->voice();

        $first  = V::say($token, 1, $v);
        $second = V::say($token, 1, $v);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertFalse((bool) $first['cached']);
        $this->assertTrue((bool) $second['cached'], 'the clip was fetched again on replay');
        $this->assertSame(1, $v->spoke, 'a replay cost a second billed call');

        $chars = (int) DB::table('gates_nominee_submissions')->where('id', $id)->value('voice_chars');
        $this->assertGreaterThan(0, $chars);
        $this->assertSame(mb_strlen($v->said[0]), $chars, 'the counter is not the characters sent');
    }

    /**
     * A bad connection must not look like a limit somebody hit. Counting a failed call would
     * spend an allowance on a request that returned nothing.
     */
    public function test_a_failed_transcription_costs_the_nominee_nothing(): void
    {
        [$id, $token] = $this->open();

        $r = V::hear($token, 'audio', 'a.webm', 'audio/webm', $this->voice('mp3', null));

        $this->assertFalse($r['ok']);
        $this->assertSame(0, (int) DB::table('gates_nominee_submissions')->where('id', $id)->value('voice_calls'));
        $this->assertSame(V::MAX_CALLS, $r['left']);
    }

    public function test_spoken_answers_are_counted_and_capped(): void
    {
        [$id, $token] = $this->open();

        $r = V::hear($token, 'audio', 'a.webm', 'audio/webm', $this->voice('mp3', ['text' => 'Hello.']));
        $this->assertTrue($r['ok']);
        $this->assertSame(1, (int) DB::table('gates_nominee_submissions')->where('id', $id)->value('voice_calls'));
        $this->assertSame(V::MAX_CALLS - 1, $r['left']);

        DB::table('gates_nominee_submissions')->where('id', $id)->update(['voice_calls' => V::MAX_CALLS]);
        $v = $this->voice('mp3', ['text' => 'Hello again.']);
        $capped = V::hear($token, 'audio', 'a.webm', 'audio/webm', $v);

        $this->assertFalse($capped['ok']);
        $this->assertSame(0, $v->heard, 'the cap was checked after paying for the call');
        // And the way out is the typing box, not an apology.
        $this->assertStringContainsString('type', strtolower((string) $capped['message']));
        $this->assertStringContainsString('saved', strtolower((string) $capped['message']));
    }

    /**
     * The cap is generous on purpose: somebody re-recording an answer six times because they
     * keep improving it is trying hard, not abusing anything.
     */
    public function test_the_cap_leaves_room_to_re_record_every_question_several_times(): void
    {
        $questions = count(Q::questions(self::PROG));
        $this->assertGreaterThan(0, $questions);
        $this->assertGreaterThan($questions * 6, V::MAX_CALLS);
    }

    public function test_using_voice_is_recorded_as_provenance(): void
    {
        [$id, $token] = $this->open();
        C::start($token);

        $this->assertFalse(V::usage(Q::byId($id))['used']);
        V::say($token, 1, $this->voice());

        $u = V::usage(Q::byId($id));
        $this->assertTrue($u['used'], 'a submission that was read aloud does not say so');
        $this->assertGreaterThan(0, $u['chars']);
        $this->assertSame(V::MAX_CALLS, $u['left']);
    }

    // ══ 5. what actually goes over the wire ══════════════════════════════════

    public function test_an_empty_or_oversized_recording_never_reaches_the_provider(): void
    {
        [, $token] = $this->open();

        $empty = $this->voice('mp3', ['text' => 'x']);
        $this->assertFalse(V::hear($token, '', 'a.webm', 'audio/webm', $empty)['ok']);

        $big = $this->voice('mp3', ['text' => 'x']);
        $this->assertFalse(V::hear($token, str_repeat('x', VoiceService::MAX_AUDIO_BYTES + 1),
                                   'a.webm', 'audio/webm', $big)['ok']);

        $this->assertSame(0, $empty->heard);
        $this->assertSame(0, $big->heard);
    }

    /**
     * A MediaRecorder on an older Android reports container types this platform has no
     * business forwarding, and the rejection would arrive after the upload was already paid
     * for.
     */
    public function test_a_container_we_cannot_read_is_refused_here_not_there(): void
    {
        [, $token] = $this->open();
        $v = $this->voice('mp3', ['text' => 'x']);

        $this->assertFalse(V::hear($token, 'bytes', 'a.txt', 'text/plain', $v)['ok']);
        $this->assertSame(0, $v->heard);

        // And the ones phones really do produce are all accepted.
        foreach (['audio/webm', 'audio/mp4', 'audio/ogg', 'audio/mpeg'] as $mime) {
            $ok = $this->voice('mp3', ['text' => 'x']);
            $this->assertTrue(V::hear($token, 'bytes', 'a', $mime, $ok)['ok'], $mime . ' was refused');
        }
    }

    /** A codec parameter on the type is normal and is not a different container. */
    public function test_a_mime_type_with_a_codec_parameter_is_still_accepted(): void
    {
        [, $token] = $this->open();
        $v = $this->voice('mp3', ['text' => 'x']);

        $this->assertTrue(V::hear($token, 'bytes', 'a.webm', 'audio/webm;codecs=opus', $v)['ok']);
        $this->assertSame(1, $v->heard);
    }

    /**
     * A bad key comes back as a JSON error document, and the proxy in front of this deployment
     * can pass that through with a 200. Handed to an <Audio> element it plays as a burst of
     * static, which reads as the platform breaking rather than a key needing rotating.
     */
    public function test_a_json_error_page_is_not_mistaken_for_audio(): void
    {
        foreach (['{"detail":"invalid api key"}', '<html><body>502</body></html>', '', 'no'] as $body) {
            $this->assertFalse(VoiceService::looksLikeAudio($body), 'accepted as audio: ' . $body);
        }
        // And real MP3 openings — an ID3 tag, and a bare MPEG frame sync — are accepted.
        $this->assertTrue(VoiceService::looksLikeAudio("ID3\x04\x00"));
        $this->assertTrue(VoiceService::looksLikeAudio("\xFF\xFB\x90\x00"));
    }

    public function test_a_long_greeting_is_cut_at_a_sentence_not_mid_word(): void
    {
        $long = str_repeat('This is a complete sentence about the work. ', 200);
        $out  = VoiceService::tidy($long);

        $this->assertLessThanOrEqual(VoiceService::MAX_CHARS, mb_strlen($out));
        $this->assertStringEndsWith('.', $out, 'a voice pronouncing half a word sounds like a fault');
    }

    public function test_whitespace_is_collapsed_so_the_clip_cache_actually_hits(): void
    {
        // The turns are stored with newlines between paragraphs. Two texts that differ only in
        // whitespace are the same question, and must not be two cache entries and two bills.
        $this->assertSame(VoiceService::tidy("One.\n\nTwo."), VoiceService::tidy('One. Two.'));
    }

    public function test_changing_the_voice_does_not_serve_the_old_one_from_cache(): void
    {
        [, $token] = $this->open();
        C::start($token);

        $a = $this->voice("\xFF\xFBvoice-a");
        $b = new class ('k', 'voice-b', null, null, 5, $this->cacheDir) extends VoiceService {
            public int $spoke = 0;
            protected function httpSpeak(string $text): ?string { $this->spoke++; return "\xFF\xFBvoice-b"; }
        };

        V::say($token, 1, $a);
        $second = V::say($token, 1, $b);

        $this->assertSame(1, $b->spoke, 'an operator who changed the voice kept hearing the old one');
        $this->assertFalse((bool) $second['cached']);
    }

    // ══ 6. the notice ════════════════════════════════════════════════════════

    /**
     * This is the only place on the platform where a recording of somebody's VOICE leaves the
     * server. A notice that folded it into "some text was classified" would be technically
     * complete and practically misleading.
     */
    public function test_the_privacy_notice_says_the_recording_is_not_kept(): void
    {
        $html = LegalDocument::voiceHtml(null, true);

        $this->assertStringContainsString('ElevenLabs', $html);
        $this->assertStringContainsString('never written to our server', $html);
        $this->assertStringContainsString('press send yourself', $html);
        $this->assertStringContainsString('currently switched on', $html);
    }

    public function test_the_notice_is_published_whether_voice_is_on_or_off(): void
    {
        // A section that appears and disappears with a settings toggle tells a reader
        // something untrue about tomorrow.
        $off = LegalDocument::voiceHtml(null, false);
        $this->assertStringContainsString('currently switched off', $off);
        $this->assertStringContainsString('no audio', $off);
        $this->assertNotSame('', trim($off));
    }

    public function test_the_voice_notice_is_attached_to_the_privacy_page(): void
    {
        $html = LegalDocument::bodyHtml(['slug' => 'privacy', 'body_html' => '<p>Hello.</p>']);

        $this->assertStringContainsString('id="voice"', $html);
        $this->assertStringContainsString('id="automated-processing"', $html,
            'the model disclosure was displaced rather than added to');

        // And NOT to the others: a terms page carrying a voice notice is noise that makes the
        // real one harder to find.
        $terms = LegalDocument::bodyHtml(['slug' => 'terms', 'body_html' => '<p>Hello.</p>']);
        $this->assertStringNotContainsString('id="voice"', $terms);
    }

    public function test_a_hostile_string_in_the_notice_is_escaped(): void
    {
        $html = LegalDocument::voiceHtml([
            'label' => '<script>alert(1)</script>',
            'capabilities' => [[
                'purpose' => '<img src=x onerror=alert(1)>',
                'sends'   => '"><script>alert(2)</script>',
            ]],
        ], true);

        // The angle brackets are what makes markup: `onerror=` surviving as literal text inside
        // an escaped `&lt;img …&gt;` is a string on the page, not a handler.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html, 'escaped rather than stripped');
    }

    public function test_the_company_name_is_spelled_the_way_the_company_spells_it(): void
    {
        // `ucfirst()` renders "elevenlabs" as "Elevenlabs" in a published legal notice.
        $this->assertSame('ElevenLabs', AiPrivacy::providerLabel('elevenlabs'));
        $this->assertSame('elevenlabs', AiPrivacy::voiceDisclosure()['provider']);
    }

    /**
     * The voice entries are deliberately NOT in the model-capability disclosure: that one is
     * generated from a registry of language-model capabilities with token budgets and provider
     * ladders, and forcing voice into it would have meant inventing a fake capability with a
     * fake model pin so a loop could find it.
     */
    public function test_voice_is_not_smuggled_into_the_language_model_disclosure(): void
    {
        foreach (AiPrivacy::disclosure() as $group) {
            $this->assertNotSame('elevenlabs', $group['provider']);
            foreach ($group['capabilities'] as $cap) {
                $this->assertStringNotContainsString('voice_', (string) $cap['name']);
            }
        }
    }

    public function test_both_voice_entries_describe_their_data_in_plain_words(): void
    {
        $caps = AiPrivacy::voiceDisclosure()['capabilities'];
        // Three, not two: reading a question out, writing down a spoken answer, and the
        // introduction — which is listed separately from the answer because the two are
        // opposite on the only question a reader cares about, whether the recording is kept.
        $this->assertCount(3, $caps, 'every direction must be disclosed, not just the microphone');
        $this->assertSame(['questionnaire.voice_out', 'questionnaire.voice_in', 'questionnaire.voice_intro'],
            array_column($caps, 'name'));

        foreach ($caps as $cap) {
            $this->assertGreaterThan(40, mb_strlen((string) $cap['sends']),
                $cap['name'] . ' — a one-line description is not a disclosure');
            $this->assertNotSame($cap['purpose'], $cap['sends']);
            $this->assertTrue((bool) $cap['advisory'],
                'nothing about voice may decide anything about a nomination');
        }
    }
}
