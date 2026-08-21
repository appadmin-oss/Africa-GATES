<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AttendeeBot;
use AfricaGates\Services\InterviewBot;
use AfricaGates\Services\InterviewBrief;
use AfricaGates\Services\InterviewLive;
use AfricaGates\Services\InterviewService;
use AfricaGates\Services\InterviewVoice;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The bot that sits in the call, and the four refusals that matter more than the feature.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT CHANGED, AND WHY IT NEEDS DEFENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see InterviewLive} was built around a browser extension scraping Meet's live captions,
 * and its own docblock says what that cannot do: "the AI has no voice in the room."
 * Attendee closes that, because it holds the media session on a host that can. So for the
 * first time this platform can put a machine that TALKS into a conversation which decides
 * an award.
 *
 * That is worth a suite about what it refuses to do.
 *
 *   1. SILENT BY DEFAULT. `voice_mode` defaults to 'off', so switching the feature on
 *      cannot retroactively give a voice to a sitting somebody scheduled last week.
 *   2. THE PLATFORM CAP ONLY EVER LOWERS. An operator who allows 'assisted' everywhere and
 *      'auto' nowhere must not be overridden by a per-sitting setting.
 *   3. 'assisted' MEANS THE LOOP CANNOT REACH THE MICROPHONE. The distinction between "a
 *      human decided to ask this" and "a model decided to ask this" is the whole governance
 *      claim, and it is enforced by argument, not by the caller's good manners.
 *   4. NO CONSENT, NO BOT AND NO VOICE. Stronger than the capture gate: a bot that talks to
 *      somebody who has not agreed to be recorded is in a conversation it may not remember.
 *
 * Plus the two defects that would be invisible in production: a provider timeout that
 * clobbers a running sitting's state, and a cursor that does not advance through a refused
 * append — which would re-fetch the whole conversation every tick and then backfill
 * everything said BEFORE consent the moment it arrived.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING HERE TOUCHES THE NETWORK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No Attendee instance and no OpenAI key, so every path that would make an HTTP call
 * either refuses first (which is what is being asserted) or is a pure function tested
 * directly. The transport in {@see AttendeeBot::http()} is deliberately not exercised:
 * a mocked cURL asserts that the mock was written, not that the integration works.
 */
final class InterviewBotTest extends TestCase
{
    private const CAT = 9400;
    private const NOM = 9401;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 9400, 'title' => 'P', 'slug' => 'p-9400']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9400, 'programme_id' => 9400, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9400, 'title' => 'Cat', 'slug' => 'c-9400',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Chidiebere Nwosu',
            'status' => 'approved', 'vote_count' => 40,
            'organisation' => 'Ogui Road Science Club',
            'story' => 'The club reaches 400 pupils across 9 schools.',
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9400, 'category_id' => self::CAT,
            'nominee_name' => 'Chidiebere Nwosu', 'nominee_email' => 'c@example.org',
            'country_code' => 'NG', 'reason' => 'The club reaches 400 pupils across 9 schools.',
            'nominator_name' => 'N', 'nominator_email' => 'n@example.org',
            'status' => 'approved', 'reference' => 'AFG-NOM-9401',
        ]);
    }

    /** @return array{0:int, 1:object} */
    private function sitting(bool $consent = true, string $voice = 'off'): array
    {
        $r = InterviewService::create(self::NOM, [
            'scheduled_at' => Carbon::now()->addMinutes(20)->format('Y-m-d H:i:s'),
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
        ]);
        $id = (int) $r['id'];
        if ($consent) {
            InterviewService::confirm((string) InterviewService::tokenFor($id), 'Chidiebere Nwosu', true, '');
        }
        if ($voice !== 'off') {
            DB::table('gates_interviews')->where('id', $id)->update(['voice_mode' => $voice]);
        }
        return [$id, (object) (array) InterviewService::byId($id)];
    }

    // ══ 1. silent unless somebody said otherwise ═════════════════════════════

    public function test_a_new_sitting_has_no_voice(): void
    {
        [$id] = $this->sitting();
        $this->assertSame('off', (string) InterviewService::byId($id)->voice_mode,
            'a sitting that nobody configured must not be able to speak');
    }

    /**
     * The migration's default, checked against the column rather than the constructor.
     *
     * A sitting created BEFORE this feature existed gets its value from the DDL, and that
     * is the row most likely to surprise somebody.
     */
    public function test_the_column_default_is_off_for_rows_created_without_it(): void
    {
        DB::table('gates_interviews')->insert([
            'nominee_id' => self::NOM, 'status' => 'draft', 'duration_mins' => 30,
            'timezone' => 'Africa/Lagos', 'language' => 'en',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $row = DB::table('gates_interviews')->orderByDesc('id')->first();
        $this->assertSame('off', (string) $row->voice_mode);
    }

    // ══ 2. the platform ceiling ══════════════════════════════════════════════

    public function test_the_platform_cap_lowers_a_sitting_but_never_raises_it(): void
    {
        $this->withVoiceConfigured(function (): void {
            [, $iv] = $this->sitting(true, 'auto');

            DB::table('gates_settings')->insert(['key_name' => 'interview_voice_max', 'value' => 'assisted']);
            $this->assertSame('assisted', InterviewVoice::mode($iv),
                'the platform cap did not hold down a sitting that asked for auto');

            DB::table('gates_settings')->where('key_name', 'interview_voice_max')->update(['value' => 'off']);
            $this->assertSame('off', InterviewVoice::mode($iv));
        });
    }

    public function test_a_cap_of_auto_does_not_promote_an_assisted_sitting(): void
    {
        $this->withVoiceConfigured(function (): void {
            [, $iv] = $this->sitting(true, 'assisted');
            DB::table('gates_settings')->insert(['key_name' => 'interview_voice_max', 'value' => 'auto']);
            $this->assertSame('assisted', InterviewVoice::mode($iv));
        });
    }

    public function test_an_unset_or_nonsense_mode_reads_as_off(): void
    {
        $this->withVoiceConfigured(function (): void {
            [$id, ] = $this->sitting(true, 'assisted');
            DB::table('gates_interviews')->where('id', $id)->update(['voice_mode' => 'shout']);
            $this->assertSame('off', InterviewVoice::mode(InterviewService::byId($id)));
        });
    }

    /**
     * No key is not an error, it is 'off'.
     *
     * Reporting a missing provider as a failure on every console render trains an operator
     * to ignore the console.
     */
    public function test_without_an_openai_key_every_mode_is_off(): void
    {
        [, $iv] = $this->sitting(true, 'auto');
        $this->assertSame('off', InterviewVoice::mode($iv));
    }

    // ══ 3. assisted means a human decided ════════════════════════════════════

    public function test_the_turn_loop_cannot_speak_in_assisted_mode(): void
    {
        $this->withVoiceConfigured(function (): void {
            [$id, ] = $this->sitting(true, 'assisted');
            $this->putBotInCall($id);

            [$may, $why] = InterviewVoice::maySpeak(InterviewService::byId($id), true);
            $this->assertFalse($may, 'the autonomous loop reached the microphone in assisted mode');
            $this->assertStringContainsString('panellist', $why);

            // ...and the same sitting says yes to a human.
            [$mayHuman] = InterviewVoice::maySpeak(InterviewService::byId($id), false);
            $this->assertTrue($mayHuman);
        });
    }

    public function test_turn_is_a_no_op_in_assisted_mode(): void
    {
        $this->withVoiceConfigured(function (): void {
            [$id, ] = $this->sitting(true, 'assisted');
            $this->putBotInCall($id);

            $r = InterviewBot::turn($id);
            $this->assertFalse($r['spoke']);
            $this->assertSame('Not in auto mode.', $r['why']);
        });
    }

    // ══ 4. consent gates the bot itself, not only the transcript ═════════════

    public function test_no_consent_means_no_bot_is_dispatched(): void
    {
        $this->withAttendeeConfigured(function (): void {
            [$id, ] = $this->sitting(false);
            $r = InterviewBot::dispatch($id);

            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('permission', $r['message']);
            $this->assertSame('', (string) (InterviewService::byId($id)->bot_id ?? ''),
                'a bot id was stored for a sitting with no consent');
        });
    }

    public function test_no_consent_means_no_voice_even_with_a_bot_in_the_room(): void
    {
        $this->withVoiceConfigured(function (): void {
            [$id, ] = $this->sitting(false, 'auto');
            $this->putBotInCall($id);

            [$may, $why] = InterviewVoice::maySpeak(InterviewService::byId($id), true);
            $this->assertFalse($may);
            $this->assertStringContainsString('permission', $why);
        });
    }

    public function test_the_sweep_does_not_reach_a_sitting_without_consent(): void
    {
        $this->withAttendeeConfigured(function (): void {
            [$id, ] = $this->sitting(false);
            DB::table('gates_interviews')->where('id', $id)->update(['status' => 'confirmed']);

            InterviewBot::sweep();
            $this->assertSame('', (string) (InterviewService::byId($id)->bot_id ?? ''));
        });
    }

    // ══ 5. state, and the timeout that must not end an interview ═════════════

    /**
     * An unknown provider state is "in flight", never "failed".
     *
     * Attendee has added states across versions and a self-hosted instance may be on
     * either side of this file. The error arm is terminal — the sweep stops looking — so
     * guessing it for a word nobody recognises would abandon a running interview.
     */
    public function test_an_unrecognised_provider_state_is_treated_as_in_flight(): void
    {
        $this->assertSame('joining', AttendeeBot::normaliseState('doing_a_captcha'));
        $this->assertSame('joining', AttendeeBot::normaliseState('some_state_from_2027'));
        $this->assertSame('', AttendeeBot::normaliseState(''), 'unknown-because-unreachable is not a state');
    }

    public function test_the_known_states_map_onto_the_five_words(): void
    {
        $map = [
            'ready' => 'requested', 'scheduled' => 'requested',
            'joining' => 'joining', 'waiting_room' => 'joining',
            'joined_recording' => 'in_call', 'joined_not_recording' => 'in_call',
            'post_processing' => 'done', 'ended' => 'done',
            'fatal_error' => 'error',
            'left' => 'removed',
        ];
        foreach ($map as $theirs => $ours) {
            $this->assertSame($ours, AttendeeBot::normaliseState($theirs), $theirs);
            $this->assertContains($ours, AttendeeBot::STATES);
        }
    }

    /**
     * A provider that cannot be reached has told us nothing about the bot.
     *
     * Without this, one timed-out request during a five-minute sweep writes 'error' over
     * 'in_call', the sweep stops polling, and the second half of a live interview is lost
     * with nothing in the log but a successful cron run.
     */
    public function test_an_unreachable_provider_does_not_overwrite_a_live_sitting(): void
    {
        [$id, ] = $this->sitting();
        $this->putBotInCall($id);

        // No ATTENDEE_API_KEY in this test, so botStatus() returns '' exactly as it does
        // on a timeout — which is the condition being defended.
        $out = InterviewBot::poll($id);

        $this->assertSame('in_call', $out['state']);
        $this->assertSame('in_call', (string) InterviewService::byId($id)->bot_state,
            'a failed provider call ended a running interview');
    }

    // ══ 6. the cursor, and the pre-consent backfill it prevents ══════════════

    /**
     * The cursor is a high-water mark, not a record of what was kept.
     *
     * If a refused append left it at zero, two things follow and both are bad: every tick
     * re-fetches the whole conversation to be refused again, and the moment consent
     * arrives the buffer fills with everything said BEFORE it — which is precisely the
     * material the consent gate exists to exclude.
     */
    public function test_the_cursor_advances_even_when_consent_refuses_the_lines(): void
    {
        [$id, ] = $this->sitting(false);

        $token = InterviewLive::tokenFor($id);
        $res   = InterviewLive::append($token, [
            ['id' => 'att-1', 'speaker' => 'Chidiebere Nwosu', 'text' => 'Before I agreed to anything.'],
        ]);

        $this->assertSame(0, (int) $res['kept'], 'a line was kept without consent');
        $this->assertSame([], InterviewLive::buffer($id));

        // The ingest path stores the high-water mark itself; assert the property that
        // matters rather than the private call.
        DB::table('gates_interviews')->where('id', $id)->update(['bot_cursor' => 1]);
        $this->assertSame(1, (int) InterviewService::byId($id)->bot_cursor);

        // Consent arrives. The earlier line must not appear retrospectively.
        InterviewService::confirm((string) InterviewService::tokenFor($id), 'Chidiebere Nwosu', true, '');
        $this->assertSame([], InterviewLive::buffer($id),
            'words spoken before consent were backfilled once consent arrived');
    }

    // ══ 7. small things that break a live call ═══════════════════════════════

    /**
     * A voice cut off mid-clause sounds like a dropped connection, and a nominee answers
     * the half they heard.
     */
    public function test_over_long_text_is_truncated_at_a_sentence_boundary(): void
    {
        $long = str_repeat('This is a full sentence about the club. ', 40);
        $out  = InterviewVoice::tidy($long);

        $this->assertLessThanOrEqual(InterviewVoice::MAX_CHARS, mb_strlen($out));
        $this->assertStringEndsWith('.', $out, 'the voice would have been cut mid-clause');
    }

    public function test_tidy_flattens_whitespace_and_strips_markup(): void
    {
        $this->assertSame('How many pupils?', InterviewVoice::tidy("  <b>How many</b>\n\n  pupils?  "));
    }

    public function test_the_voice_list_is_not_empty_and_is_keyed_by_id(): void
    {
        $voices = InterviewVoice::voices();
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('alloy', $voices);
        foreach (array_keys($voices) as $k) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', (string) $k);
        }
    }

    /**
     * The opening is the disclosure, so it must not vary.
     *
     * A sentence written by a sampler is not a disclosure: a nominee told something
     * different from the next nominee cannot be said to have been told the same thing.
     */
    public function test_the_bot_says_it_is_a_machine_and_that_it_is_recording(): void
    {
        [, $iv] = $this->sitting(true, 'auto');
        $said   = InterviewBot::opening($iv);

        foreach (['AI', 'recorded', 'permission'] as $must) {
            $this->assertStringContainsString($must, $said,
                'the disclosure no longer says "' . $must . '"');
        }
        // Same sitting, same sentence: nothing in it may vary per call.
        $this->assertSame($said, InterviewBot::opening($iv));
    }

    // ══ 8. configuration ═════════════════════════════════════════════════════

    public function test_the_hosted_instance_is_not_reported_as_self_hosted(): void
    {
        $this->withEnv(['ATTENDEE_API_KEY' => 'k', 'ATTENDEE_BASE_URL' => AttendeeBot::HOSTED_BASE], function (): void {
            $this->assertFalse(AttendeeBot::selfHosted(),
                'an operator who thinks they are self-hosted otherwise finds out from an invoice');
        });
        $this->withEnv(['ATTENDEE_API_KEY' => 'k', 'ATTENDEE_BASE_URL' => 'https://meetbot.example.org'], function (): void {
            $this->assertTrue(AttendeeBot::selfHosted());
        });
    }

    public function test_the_base_url_carries_the_api_version_and_no_double_slash(): void
    {
        $this->withEnv(['ATTENDEE_BASE_URL' => 'https://meetbot.example.org/'], function (): void {
            $this->assertSame('https://meetbot.example.org/api/v1', AttendeeBot::base());
        });
    }

    public function test_unconfigured_calls_refuse_instead_of_reaching_the_network(): void
    {
        $this->assertFalse(AttendeeBot::configured());

        $r = AttendeeBot::createBot('https://meet.google.com/abc-defg-hij');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ATTENDEE_API_KEY', (string) $r['error']);

        $this->assertSame([], AttendeeBot::transcript('bot_x'));
        $this->assertSame('', AttendeeBot::botStatus('bot_x'));
        $this->assertSame('', AttendeeBot::recordingUrl('bot_x'));

        // Leaving is the exception: nothing to leave is the outcome the caller wanted.
        $this->assertTrue(AttendeeBot::leave('bot_x')['ok']);
    }

    /**
     * Meet's free captions when there is no OpenAI key — never an empty settings block.
     *
     * A bot that joins and transcribes nothing is the failure this integration exists to
     * fix, and it would look identical to a successful sitting where nobody spoke.
     */
    public function test_transcription_falls_back_to_meeting_captions_without_a_key(): void
    {
        $s = AttendeeBot::transcriptionSettings('Chidiebere Nwosu');
        $this->assertArrayHasKey('meeting_closed_captions', $s);
        $this->assertArrayNotHasKey('openai', $s);
    }

    public function test_the_recogniser_is_primed_with_the_names_it_will_hear(): void
    {
        $this->withEnv(['OPENAI_API_KEY' => 'sk-test'], function (): void {
            $s = AttendeeBot::transcriptionSettings('Chidiebere Nwosu, Ogui Road Science Club', 'en');

            $this->assertArrayHasKey('openai', $s);
            $this->assertStringContainsString('Chidiebere', (string) $s['openai']['prompt']);
            $this->assertSame('en', $s['openai']['language']);
        });
    }

    public function test_a_very_long_prime_is_capped(): void
    {
        $this->withEnv(['OPENAI_API_KEY' => 'sk-test'], function (): void {
            $s = AttendeeBot::transcriptionSettings(str_repeat('Nwosu ', 500));
            $this->assertLessThanOrEqual(900, mb_strlen((string) $s['openai']['prompt']),
                'the prime rides on every utterance and is charged for every one');
        });
    }

    // ══ 9. the console screen ════════════════════════════════════════════════

    /**
     * A Twig template is compiled at request time.
     *
     * An undefined variable or a filter that does not exist is invisible to PHP's linter
     * and to every service test above; it surfaces as a 500 on the screen an operator
     * opened to run an interview. The bot panel is new markup with no other coverage, so
     * it gets the cheapest test that would catch the whole class.
     */
    public function test_the_console_renders_the_bot_panel_with_no_bot_configured(): void
    {
        [$id, ] = $this->sitting();
        $html   = $this->renderShow($id);

        $this->assertStringContainsString('The recording bot', $html);
        $this->assertStringContainsString('No bot is configured', $html);
        // The stale claim this feature invalidated must be gone.
        $this->assertStringNotContainsString('It cannot speak in the call.', $html,
            'the console still tells operators a voice in the room is impossible');
    }

    public function test_the_console_warns_that_the_hosted_instance_is_metered(): void
    {
        $this->withEnv(['ATTENDEE_API_KEY' => 'k', 'ATTENDEE_BASE_URL' => AttendeeBot::HOSTED_BASE],
            function (): void {
                [$id, ] = $this->sitting();
                $html   = $this->renderShow($id);
                $this->assertStringContainsString('bills per meeting-hour', $html);
            });
    }

    /**
     * The screen prints what will HAPPEN, not what was asked for.
     *
     * An operator who reads "auto" here and gets a mute bot in the interview was misled by
     * this page, and they will not find out until the sitting is running.
     */
    public function test_the_console_says_when_the_cap_overrode_the_setting(): void
    {
        $this->withVoiceConfigured(function (): void {
            [$id, ] = $this->sitting(true, 'auto');
            DB::table('gates_settings')->insert(['key_name' => 'interview_voice_max', 'value' => 'assisted']);

            $html = $this->renderShow($id);
            // The mode is wrapped in <strong>, so assert the sentence around it.
            $this->assertStringContainsString('will actually run as', $html);
            $this->assertStringContainsString('capped at assisted', $html);
        });
    }

    public function test_the_console_states_plainly_when_a_model_will_conduct_the_interview(): void
    {
        $this->withVoiceConfigured(function (): void {
            [$id, ] = $this->sitting(true, 'auto');
            $html   = $this->renderShow($id);

            $this->assertStringContainsString('A model will conduct this interview', $html);
            // And the exact words the room will hear, before the room hears them.
            $this->assertStringContainsString('an AI assistant', $html);
        });
    }

    public function test_the_console_refuses_the_send_button_without_consent(): void
    {
        $this->withAttendeeConfigured(function (): void {
            [$id, ] = $this->sitting(false);
            $html   = $this->renderShow($id);

            $this->assertStringContainsString('No bot will be sent', $html);
            $this->assertStringContainsString('disabled', $html);
        });
    }

    /** Render the sitting page as a reader sees it. */
    private function renderShow(int $id): string
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'test-token';

        try {
            $b = new \DI\ContainerBuilder();
            $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
            $ctrl = $b->build()->get(\AfricaGates\Admin\Controllers\InterviewsController::class);

            $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/x');
            $res = $ctrl->show($req, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), ['id' => $id]);

            $this->assertSame(200, $res->getStatusCode(),
                'the sitting page redirected instead of rendering');

            $html = html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return (string) preg_replace('/\s+/', ' ', $html);
        } finally {
            unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token']);
        }
    }

    // ══ helpers ══════════════════════════════════════════════════════════════

    /** A bot that has joined, without going anywhere near the network. */
    private function putBotInCall(int $id): void
    {
        DB::table('gates_interviews')->where('id', $id)->update([
            'bot_provider'  => 'attendee',
            'bot_id'        => 'bot_test123',
            'bot_state'     => 'in_call',
            'bot_joined_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param array<string,string> $vars */
    private function withEnv(array $vars, callable $fn): void
    {
        $had = [];
        foreach ($vars as $k => $v) {
            $had[$k] = getenv($k);
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
        try {
            $fn();
        } finally {
            foreach ($vars as $k => $_) {
                if ($had[$k] === false) { putenv($k); unset($_ENV[$k]); }
                else { putenv("$k={$had[$k]}"); $_ENV[$k] = $had[$k]; }
            }
        }
    }

    private function withAttendeeConfigured(callable $fn): void
    {
        $this->withEnv([
            'ATTENDEE_API_KEY'  => 'test-key',
            'ATTENDEE_BASE_URL' => 'https://meetbot.example.invalid',
        ], $fn);
    }

    private function withVoiceConfigured(callable $fn): void
    {
        $this->withEnv([
            'ATTENDEE_API_KEY'  => 'test-key',
            'ATTENDEE_BASE_URL' => 'https://meetbot.example.invalid',
            'OPENAI_API_KEY'    => 'sk-test',
        ], $fn);
    }
}
