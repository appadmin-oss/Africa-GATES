<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiPrivacy;
use AfricaGates\Services\LegalDocument;
use AfricaGates\Services\QuestionnaireIntro as I;
use AfricaGates\Services\QuestionnaireService as Q;
use AfricaGates\Services\VoiceService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * The two things that come before the first question.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY EITHER EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The questionnaire opened with a greeting and then asked about impact. A nominee had no
 * idea how long it would take, what a usable answer looked like, whether a half-finished
 * draft was safe, or what happened to any of it — and the people worst served by not knowing
 * are the ones who will never ask.
 *
 * And a dossier has never contained the PERSON: a nominator's paragraph, a category, a
 * photograph, and now some typed prose, none of which proves who wrote it. An introduction
 * they speak is the one item in it that cannot be ghost-written.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THAT MATTERS MOST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * THIS RECORDING IS KEPT, AND THAT IS THE OPPOSITE OF THE OTHER ONE.
 *
 * A spoken ANSWER is transcribed and the audio thrown away. An INTRODUCTION is stored,
 * because the recording is the artefact a judge is meant to hear. Both facts are now printed,
 * distinguished, in the privacy notice — "we never keep your voice" and "we keep this
 * recording" cannot both appear on one page without one of them being a lie.
 *
 * And three properties follow from keeping it: consent is its own act, the nominee can delete
 * it, and the file never sits at a public web address.
 */
final class QuestionnaireIntroTest extends TestCase
{
    private const CAT = 9900;
    private const NOM = 9901;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 99, 'title' => 'P', 'slug' => 'p-9900']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9900, 'programme_id' => 99, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9900, 'title' => 'C', 'slug' => 'c-9900']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 10]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9900, 'category_id' => self::CAT,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'g@example.org',
            'country_code' => 'GH', 'reason' => 'x', 'nominator_name' => 'K',
            'nominator_email' => 'k@example.org', 'status' => 'approved',
            'reference' => 'AFG-NOM-9901']);
    }

    /** @return array{0:int,1:string} */
    private function open(): array
    {
        $r = Q::open(self::NOM);
        return [(int) $r['id'], (string) $r['token']];
    }

    private function audio(string $bytes = 'FAKE-OPUS-BYTES', string $mime = 'audio/webm'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ag_intro_');
        file_put_contents($tmp, $bytes);
        return new UploadedFile($tmp, 'intro.webm', $mime, strlen($bytes), UPLOAD_ERR_OK);
    }

    /** A voice service with a key that transcribes without a network. */
    private function voice(?string $text = 'I run a coding club for girls in Accra.'): VoiceService
    {
        return new class ('k', 'v', null, null, 5, null, $text) extends VoiceService {
            public function __construct(?string $k, ?string $v, ?string $t, ?string $st,
                                        int $to, ?string $cache, private readonly ?string $text)
            { parent::__construct($k, $v, $t, $st, $to, $cache); }
            protected function httpTranscribe(string $b, string $f, string $m): ?array
            { return $this->text === null ? null : ['text' => $this->text]; }
        };
    }

    private function row(int $id): object
    {
        return (object) (array) DB::table('gates_nominee_submissions')->where('id', $id)->first();
    }

    // ══ 1. the brief ═════════════════════════════════════════════════════════

    public function test_the_brief_says_the_things_a_nominee_needs_to_decide_anything(): void
    {
        // Whitespace-normalised: the brief is prose and its line wrapping is incidental, so
        // asserting against the wrapped form would make every future edit to the copy a test
        // failure for no reason.
        $brief = strtolower((string) preg_replace('/\s+/', ' ', I::brief()));

        // Each of these is a promise the platform actually keeps, and each one changes
        // whether somebody starts at all.
        $this->assertStringContainsString('saved as you go', $brief);
        $this->assertStringContainsString('nothing reaches a judge until', $brief);
        $this->assertStringContainsString('numbers and names', $brief);
        $this->assertStringContainsString('nobody is marking your grammar', $brief);
        // The setback question reads as a trap unless somebody says it is not one.
        $this->assertStringContainsString('not traps', $brief);
        // The commonest way an awards scheme is impersonated.
        $this->assertStringContainsString('costs money', $brief);
    }

    public function test_the_brief_can_be_replaced_per_programme(): void
    {
        // An award for teachers and one for exporters want different examples, and an
        // operator should not need a deploy to change editorial copy mid-cycle.
        DB::table('gates_settings')->insert(['key_name' => 'questionnaire_brief', 'value' => 'Site-wide brief.']);
        $this->assertSame('Site-wide brief.', I::brief());

        DB::table('gates_settings')->insert(['key_name' => 'questionnaire_brief_9900', 'value' => 'Heritage brief.']);
        $this->assertSame('Heritage brief.', I::brief(9900));
        $this->assertSame('Site-wide brief.', I::brief(9901), 'one programme overrode every other');
    }

    public function test_the_brief_arrives_as_paragraphs_rather_than_one_wall(): void
    {
        $paras = I::briefParagraphs();
        $this->assertGreaterThan(3, count($paras));
        foreach ($paras as $p) {
            $this->assertStringNotContainsString("\n", $p, 'a paragraph carried a raw newline into the page');
            $this->assertNotSame('', trim($p));
        }
    }

    public function test_the_questions_do_not_start_until_the_brief_is_read(): void
    {
        [$id, $token] = $this->open();

        $this->assertFalse(I::seen(Q::byId($id)));
        $this->assertTrue(I::markSeen($token));
        $this->assertTrue(I::seen(Q::byId($id)));
        $this->assertNotNull($this->row($id)->intro_seen_at);
    }

    public function test_reading_the_brief_twice_does_not_move_the_timestamp(): void
    {
        // It records WHEN somebody was first told, which is the fact worth having.
        [$id, $token] = $this->open();
        I::markSeen($token);
        $first = (string) $this->row($id)->intro_seen_at;

        DB::table('gates_nominee_submissions')->where('id', $id)
            ->update(['intro_seen_at' => '2020-01-01 00:00:00']);
        I::markSeen($token);

        $this->assertSame('2020-01-01 00:00:00', (string) $this->row($id)->intro_seen_at);
        $this->assertNotSame('', $first);
    }

    // ══ 2. the recording is kept ═════════════════════════════════════════════

    /** THE test in this file. */
    public function test_the_recording_is_stored_and_transcribed(): void
    {
        [$id, $token] = $this->open();

        $r = I::record($token, $this->audio(), 47, $this->voice());

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $row = $this->row($id);
        $this->assertNotNull($row->intro_audio_path, 'the recording was thrown away');
        $this->assertSame(47, (int) $row->intro_seconds);
        $this->assertSame('I run a coding club for girls in Accra.', (string) $row->intro_transcript);
        $this->assertSame('ai', (string) $row->intro_source);

        // And it is on the disk, outside the web root.
        $path = I::fileFor($row);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringNotContainsString('/public/', $path,
            'a nominee\'s voice was written where an unlisted guess is the only thing protecting it');
    }

    public function test_a_failed_transcription_still_leaves_them_with_a_recording(): void
    {
        // The recording is the artefact; the transcript is a convenience. Telling somebody the
        // whole thing failed would make them record it again for nothing.
        [$id, $token] = $this->open();

        $r = I::record($token, $this->audio(), 30, $this->voice(null));

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['has_text']);
        $this->assertNotNull($this->row($id)->intro_audio_path);
        $this->assertNull($this->row($id)->intro_transcript);
    }

    public function test_with_no_voice_key_the_recording_is_still_kept(): void
    {
        [$id, $token] = $this->open();

        $r = I::record($token, $this->audio(), 30, new VoiceService(null));

        $this->assertTrue($r['ok']);
        $this->assertNotNull($this->row($id)->intro_audio_path);
        $this->assertSame('none', (string) $this->row($id)->intro_source);
    }

    public function test_re_recording_replaces_the_file_and_leaves_no_orphan(): void
    {
        [$id, $token] = $this->open();
        I::record($token, $this->audio('FIRST'), 10, $this->voice());
        $first = I::fileFor($this->row($id));

        I::record($token, $this->audio('SECOND'), 20, $this->voice());
        $second = I::fileFor($this->row($id));

        $this->assertNotSame($first, $second);
        $this->assertFileExists((string) $second);
        $this->assertFileDoesNotExist((string) $first, 'the old recording was left on the disk');
    }

    public function test_re_recording_withdraws_the_previous_agreement(): void
    {
        // A panel agreed to hear one recording, not whichever one happens to be there later.
        [$id, $token] = $this->open();
        I::record($token, $this->audio('FIRST'), 10, $this->voice());
        I::consent($token);
        $this->assertNotNull(I::forDossier($this->row($id)));

        I::record($token, $this->audio('SECOND'), 20, $this->voice());

        $this->assertNull($this->row($id)->intro_consent_at,
            'a re-recording inherited consent given for a different one');
        $this->assertNull(I::forDossier($this->row($id)));
    }

    // ══ 3. consent, and deleting it ══════════════════════════════════════════

    /**
     * Pressing "record" is somebody trying the microphone. Agreeing that a panel of
     * strangers will listen to it is a different decision, and the refusal is in the WRITER
     * so no screen and no future caller can route around it.
     */
    public function test_without_consent_no_judge_can_be_shown_it(): void
    {
        [$id, $token] = $this->open();
        I::record($token, $this->audio(), 30, $this->voice());

        $this->assertNull(I::forDossier($this->row($id)), 'a recording reached a panel unasked');

        I::consent($token);
        $ready = I::forDossier($this->row($id));
        $this->assertNotNull($ready);
        $this->assertSame(30, $ready['seconds']);
    }

    public function test_consent_with_nothing_recorded_is_refused(): void
    {
        [, $token] = $this->open();
        $r = I::consent($token);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no recording', strtolower($r['message']));
    }

    public function test_it_can_be_deleted_right_up_to_sending(): void
    {
        // Somebody who recorded a false start and cannot undo it abandons the whole
        // questionnaire rather than send a bad first impression.
        [$id, $token] = $this->open();
        I::record($token, $this->audio(), 30, $this->voice());
        I::consent($token);
        $path = I::fileFor($this->row($id));

        $r = I::remove($token);

        $this->assertTrue($r['ok']);
        $this->assertFileDoesNotExist((string) $path);
        $row = $this->row($id);
        $this->assertNull($row->intro_audio_path);
        $this->assertNull($row->intro_transcript);
        $this->assertNull($row->intro_consent_at, 'consent survived the recording it applied to');
    }

    public function test_a_sent_questionnaire_cannot_have_its_introduction_changed(): void
    {
        [$id, $token] = $this->open();
        I::record($token, $this->audio(), 30, $this->voice());
        DB::table('gates_nominee_submissions')->where('id', $id)->update(['status' => 'submitted']);

        $this->assertFalse(I::record($token, $this->audio('NEW'), 30, $this->voice())['ok']);
        $this->assertFalse(I::remove($token)['ok']);
        $this->assertNotNull($this->row($id)->intro_audio_path);
    }

    // ══ 4. into the dossier ══════════════════════════════════════════════════

    public function test_a_consented_introduction_is_published_before_the_answers(): void
    {
        [$id, $token] = $this->open();
        I::markSeen($token);
        I::record($token, $this->audio(), 65, $this->voice());
        I::consent($token);

        $answers = [];
        foreach (Q::questions(9900) as $q) $answers[(string) $q['slug']] = 'An answer with 40 people, since 2019.';
        Q::saveDraft($token, $answers, []);
        Q::submit($token, 'Grace Mensah');

        $rows = DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->orderBy('sort_order')->get();
        $first = (array) $rows->first();

        $this->assertStringContainsString('own voice', (string) $first['title']);
        $this->assertStringContainsString('1m 5s', (string) $first['title'], 'the length was not shown');
        $this->assertSame('media', (string) $first['kind'],
            'kind must be a value the ENUM actually holds, or the row silently vanishes');
        $this->assertStringContainsString('intro.audio', (string) $first['source_url']);
        $this->assertSame(0, (int) $first['verified']);
        // The transcript is in the body so a judge with no headphones can still read it, and
        // it is labelled as machine-made rather than as the nominee's own writing.
        $this->assertStringContainsString('coding club', (string) $first['body']);
        $this->assertStringContainsString('not corrected', (string) $first['source_label']);
    }

    public function test_an_introduction_without_consent_is_not_published(): void
    {
        [$id, $token] = $this->open();
        I::record($token, $this->audio(), 30, $this->voice());

        $answers = [];
        foreach (Q::questions(9900) as $q) $answers[(string) $q['slug']] = 'An answer with 40 people, since 2019.';
        Q::saveDraft($token, $answers, []);
        Q::submit($token, 'Grace Mensah');

        $intro = DB::table('gates_nominee_evidence')->where('nominee_id', self::NOM)
            ->where('title', 'like', '%own voice%')->count();
        $this->assertSame(0, $intro, 'a recording nobody agreed to share reached a panel');
    }

    // ══ 5. the notice tells both halves of the truth ═════════════════════════

    /**
     * The important one. "We never keep your voice" and "we keep this recording" cannot both
     * be printed on one page without one of them being a lie, so the notice distinguishes
     * them by name.
     */
    public function test_the_privacy_notice_distinguishes_the_answer_from_the_introduction(): void
    {
        $html = LegalDocument::voiceHtml(null, true);

        $this->assertStringContainsString('never written to our server', $html,
            'the promise about spoken ANSWERS is gone');
        $this->assertStringContainsString('IS the thing the judges are meant to hear', $html,
            'the notice does not admit that the introduction is kept');
        $this->assertStringContainsString('delete it or record it again', $html);
        $this->assertStringContainsString('agreed that they may hear', $html);
    }

    public function test_the_introduction_is_its_own_line_in_the_disclosure(): void
    {
        $caps = AiPrivacy::voiceDisclosure()['capabilities'];
        $names = array_column($caps, 'name');

        $this->assertContains('questionnaire.voice_intro', $names);
        $this->assertContains('questionnaire.voice_in', $names);

        $intro = $caps[array_search('questionnaire.voice_intro', $names, true)];
        $this->assertStringContainsString('IS kept', (string) $intro['sends'],
            'the one fact a reader cares about is missing from the line about it');
        foreach ($caps as $c) {
            $this->assertGreaterThan(40, mb_strlen((string) $c['sends']));
            $this->assertTrue((bool) $c['advisory']);
        }
    }

    // ══ 6. the ordinary refusals ════════════════════════════════════════════

    public function test_an_empty_or_oversized_recording_is_refused(): void
    {
        [, $token] = $this->open();

        $this->assertFalse(I::record($token, $this->audio(''), 0, $this->voice())['ok']);
        $big = str_repeat('x', VoiceService::MAX_AUDIO_BYTES);
        $this->assertFalse(I::record($token, $this->audio($big . $big), 30, $this->voice())['ok']);
    }

    public function test_a_file_that_is_not_audio_is_refused(): void
    {
        [, $token] = $this->open();
        $r = I::record($token, $this->audio('%PDF-1.4', 'application/pdf'), 30, $this->voice());
        $this->assertFalse($r['ok']);
    }

    public function test_a_length_claimed_by_the_browser_is_clamped(): void
    {
        // The client is the only party with a clock on the recording, so the number comes from
        // it — but it decides what a judge is told the length is, so it is bounded.
        [$id, $token] = $this->open();
        I::record($token, $this->audio(), 99999, $this->voice());

        $this->assertLessThanOrEqual(I::MAX_SECONDS + 10, (int) $this->row($id)->intro_seconds);
    }

    public function test_an_unknown_token_writes_nothing(): void
    {
        $r = I::record(str_repeat('a', 32), $this->audio(), 30, $this->voice());
        $this->assertFalse($r['ok']);
        $this->assertFalse(I::markSeen(str_repeat('a', 32)));
    }

    public function test_a_stored_path_is_never_read_from_outside_its_own_directory(): void
    {
        // The column is written by this class alone, but a path assembled for a file-serving
        // route is exactly where a traversal would matter.
        [$id, $token] = $this->open();
        I::record($token, $this->audio(), 10, $this->voice());
        DB::table('gates_nominee_submissions')->where('id', $id)
            ->update(['intro_audio_path' => '../../../.env']);

        $this->assertNull(I::fileFor($this->row($id)));
    }
}
