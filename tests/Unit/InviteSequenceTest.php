<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteReminders;
use AfricaGates\Services\InviteSequence;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The five letters a nominee receives in the last week.
 *
 * ── WHAT IS BEING HELD ───────────────────────────────────────────────────────
 *
 * This is an ARC — WHY → VALUES → RESPONSIBILITY → MESSAGE → ACTION — and each letter
 * only works in its position. So the properties here are the ones that make it an arc
 * rather than five unrelated emails: that day three's letter goes on day three, that a
 * judge never receives one, that every placeholder is filled from the ceremony rather
 * than left standing in somebody's inbox, and that an organiser can replace any letter
 * without the platform quietly sending its own anyway.
 */
final class InviteSequenceTest extends TestCase
{
    private int $eventId = 0;
    private object $event;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'The Incredible Principal Awards 2026',
            'tagline' => 'Accountability and Responsibility',
            'event_date' => Carbon::now()->addDays(3)->setTime(18, 0)->toDateTimeString(),
            'status' => 'published', 'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
        EventInvites::setProgrammes($this->eventId, [$pid]);
        $this->event = DB::table('gates_site_events')->where('id', $this->eventId)->first();
    }

    private int $seq = 0;

    /**
     * A fresh invitee per call.
     *
     * One person cannot stand in for every letter: mint() is idempotent on (event, email)
     * and a reminder is sent once per person per mark, so a shared address makes the
     * SECOND assertion in any test silently compare against nothing.
     */
    private function invited(string $audience = InviteAudience::NOMINEE): object
    {
        $email = 'ada' . (++$this->seq) . '@example.com';
        $inv   = EventInvites::mint($this->eventId, $audience,
            ['name' => 'Ada Obi', 'email' => $email, 'nominee_id' => 0, 'judge_id' => 0]);
        DB::table('gates_event_invites')->where('id', $inv->id)
            ->update(['sent_at' => Carbon::now()->subDays(20)->toDateTimeString()]);

        return DB::table('gates_event_invites')->where('id', $inv->id)->first();
    }

    private function recorder(): OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25,
                          'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function smtpConfigured(): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody');

                return ['success' => true];
            }
        };
    }

    /** The letter that goes at one distance, as HTML and plain text. */
    private function letterAt(int $days, string $audience = InviteAudience::NOMINEE): array
    {
        $m = $this->recorder();
        InviteReminders::send($this->invited($audience), $this->event, $days, $days, $m);

        $this->assertCount(1, $m->sent, 'nothing was sent at ' . $days . ' days');

        return $m->sent[0];
    }

    // ══ THE ARC ═════════════════════════════════════════════════════════════

    /** The default schedule and the letters must describe the same week. */
    public function test_the_schedule_defaults_to_the_days_the_letters_are_written_for(): void
    {
        $this->assertSame(InviteSequence::DAYS, InviteReminders::DEFAULT_MARKS,
            'a schedule that ran on other days would send the ordinary reminder every time '
            . 'while five letters sat unused, and no screen would say so');
        $this->assertSame([5, 4, 3, 2, 1], InviteReminders::marks());
    }

    /** Each day gets its own letter, and each one is the one written for that position. */
    public function test_each_day_sends_its_own_letter_in_order(): void
    {
        foreach ([
            5 => 'begin with your WHY',
            4 => 'find your message',
            3 => 'own your message',
            2 => 'prepare to speak',
        ] as $day => $hinge) {
            $this->assertStringContainsString($hinge, $this->letterAt($day)['subject'],
                'day ' . $day . ' is out of position');
        }

        $eve = $this->letterAt(1);
        $this->assertStringContainsString('tomorrow is', strtolower($eve['subject']));
        $this->assertStringContainsString('The day we have been preparing for is finally here',
            $eve['htmlBody']);
    }

    /**
     * The subject carries the name and the countdown before anything else.
     *
     * A phone shows fewer than sixty characters, and of the two things a countdown letter
     * has to deliver at a glance, neither is the hinge phrase at the end.
     */
    public function test_every_letters_subject_leads_with_the_person_and_the_time(): void
    {
        foreach (InviteSequence::DAYS as $day) {
            $subject = $this->letterAt($day)['subject'];

            $this->assertStringStartsWith('Ada Obi,', $subject, 'day ' . $day);
            $this->assertMatchesRegularExpression('/^.{0,26}(tomorrow|today|in \d+ days)/i', $subject,
                'day ' . $day . ': the countdown must survive a truncated subject');
        }
    }

    // ══ THE PLACEHOLDERS ════════════════════════════════════════════════════

    /**
     * Nothing reaches an inbox with a brace still in it.
     *
     * The whole point of the token system is that the letters are written once and reused
     * for any ceremony. A `{theme}` sitting unresolved in somebody's email is the one
     * failure that makes the reuse visible to the person being honoured.
     */
    public function test_no_placeholder_survives_into_a_sent_letter(): void
    {
        foreach (InviteSequence::DAYS as $day) {
            $sent = $this->letterAt($day);

            foreach (array_keys(InviteSequence::TOKENS) as $token) {
                $this->assertStringNotContainsString('{' . $token . '}', $sent['htmlBody'],
                    'day ' . $day . ' sent {' . $token . '} unresolved');
                $this->assertStringNotContainsString('{' . $token . '}', $sent['plainBody'],
                    'day ' . $day . ' sent {' . $token . '} unresolved in the plain half');
            }
        }
    }

    /** The ceremony fills its own facts — an operator running a second gala types nothing. */
    public function test_the_event_fills_its_own_placeholders(): void
    {
        $sent = $this->letterAt(3);

        $this->assertStringContainsString('The Incredible Principal Awards 2026', $sent['htmlBody']);
        $this->assertStringContainsString('3 days to', $sent['htmlBody'], '{days} is the real distance');
        $this->assertStringContainsString('Eko Convention Centre', $sent['htmlBody']);
    }

    /**
     * A theme is a fact about ONE ceremony.
     *
     * The event's own tagline wins over the settings fallback, because a platform running
     * several programmes would otherwise put last year's theme in this year's letters.
     */
    public function test_the_events_own_theme_beats_the_settings_fallback(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_seq_theme', 'value' => 'A Different Theme']);

        $sent = $this->letterAt(1);

        $this->assertStringContainsString('Accountability and Responsibility', $sent['htmlBody']);
        $this->assertStringNotContainsString('A Different Theme', $sent['htmlBody']);
    }

    public function test_an_event_with_no_tagline_falls_back_to_the_setting(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['tagline' => null]);
        DB::table('gates_settings')->insert(['key_name' => 'invite_seq_theme', 'value' => 'A Different Theme']);
        $this->event = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $this->assertStringContainsString('A Different Theme', $this->letterAt(1)['htmlBody']);
    }

    /** An unknown token is left standing, because a gap is a typo nobody can see. */
    public function test_an_unknown_token_is_left_where_an_operator_can_find_it(): void
    {
        $this->assertSame(
            'Ada speaks about {jury} tomorrow',
            InviteSequence::fill('{name} speaks about {jury} {countdown}',
                                 ['name' => 'Ada', 'countdown' => 'tomorrow'])
        );
    }

    // ══ THE VALUES BLOCK ════════════════════════════════════════════════════

    /** Five short lines that must stay five short lines — the typography is the argument. */
    public function test_the_values_reach_the_letter_as_separate_lines(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_seq_values',
            'value' => "Integrity first.\nCommunity over competition.\nCharacter before success."]);

        $sent = $this->letterAt(2);

        $this->assertStringContainsString('Integrity first.', $sent['htmlBody']);
        $this->assertStringContainsString('Community over competition.', $sent['htmlBody']);
        // A <br> between them, not a paragraph break and not a run-on: the day-two letter
        // sets these as one block of beats.
        $this->assertMatchesRegularExpression('/Integrity first\.\s*<br\s*\/?>/i', $sent['htmlBody']);
    }

    public function test_a_runaway_values_list_cannot_become_the_rest_of_the_letter(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_seq_values',
            'value' => implode("\n", array_map(static fn (int $i): string => 'Value ' . $i, range(1, 40)))]);

        $this->assertSame(6, substr_count(InviteSequence::values(), "\n") + 1);
    }

    // ══ WHO GETS THEM ═══════════════════════════════════════════════════════

    /**
     * A judge is honoured for how they judged, and gets the arc written for that.
     *
     * The same beats — an organiser reading both sets should recognise one voice, and two
     * people standing on the same red carpet should have been asked the same question.
     * What must never cross over is what was TRUSTED: "your nomination represents trust"
     * is a small lie told to somebody who was never nominated.
     */
    public function test_a_judge_gets_the_panel_letter_and_never_the_nominee_one(): void
    {
        $sent = $this->letterAt(5, InviteAudience::JUDGE);

        $this->assertStringContainsString('your seat on that panel', $sent['htmlBody']);
        $this->assertStringContainsString('It represented trust', $sent['htmlBody'],
            'the same beat as the nominee letter, which is the point of writing them together');

        $this->assertStringNotContainsString('your nomination', strtolower($sent['htmlBody']),
            'they were not nominated');
        $this->assertStringNotContainsString('the work you have done', strtolower($sent['htmlBody']));
    }

    /** Every day of the panel arc is written, in the same order as the nominee one. */
    public function test_the_panel_arc_runs_the_same_five_days(): void
    {
        foreach ([5 => 'begin with your WHY', 4 => 'find your message', 3 => 'own your message',
                  2 => 'prepare to speak'] as $day => $hinge) {
            $this->assertStringContainsString($hinge, $this->letterAt($day, InviteAudience::JUDGE)['subject'],
                'judge day ' . $day . ' is out of position');
        }

        $eve = $this->letterAt(1, InviteAudience::JUDGE);
        $this->assertStringContainsString('tomorrow is', strtolower($eve['subject']));
        // The one line that is only true for a judge: they are standing behind a result
        // rather than receiving one.
        $this->assertStringContainsString('the names you weighed will be read aloud', $eve['htmlBody']);
    }

    /** Each audience's letters are separately editable — and separately kept. */
    public function test_replacing_one_audiences_letter_leaves_the_others_alone(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => InviteSequence::bodyKey(3, InviteAudience::JUDGE),
            'value'    => "A panel letter of our own.\n\nSee you at {venue}, {countdown}.",
        ]);

        $this->assertStringContainsString('A panel letter of our own',
            $this->letterAt(3, InviteAudience::JUDGE)['htmlBody']);
        $nominee = $this->letterAt(3)['htmlBody'];
        $this->assertStringNotContainsString('A panel letter of our own', $nominee,
            'a judge edit must not reach the nominees');
        $this->assertStringContainsString('What is your responsibility within your own space', $nominee,
            'the nominee letter is untouched and still the shipped one');
    }

    /**
     * The key gained an audience, and anything already saved under the old one survives.
     *
     * Renaming without this would silently revert every letter an organiser had rewritten
     * — a save that appeared to work, and a send that went out in the shipped wording
     * weeks later.
     */
    public function test_a_letter_saved_under_the_old_key_is_still_sent(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => 'invite_seq_body_2',
            'value'    => "Written before the key had an audience in it.\n\nStill ours, {countdown}.",
        ]);

        $this->assertStringContainsString('Written before the key had an audience in it',
            $this->letterAt(2)['htmlBody']);

        // And the new key wins once it is written, so the next save migrates it.
        DB::table('gates_settings')->insert([
            'key_name' => InviteSequence::bodyKey(2, InviteAudience::NOMINEE),
            'value'    => "The migrated one.\n\nSee you {countdown} at {venue}, truly.",
        ]);
        $this->assertStringContainsString('The migrated one', $this->letterAt(2)['htmlBody']);
    }

    /** A mark outside the arc sends the short reminder rather than nothing. */
    public function test_a_mark_outside_the_week_sends_the_short_reminder(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'invite_reminder_days', 'value' => '21, 3, 1']);

        $m = $this->recorder();
        InviteReminders::send($this->invited(), $this->event, 21, 21, $m);

        $this->assertStringContainsString('Grand Celebration', $m->sent[0]['subject']);
        $this->assertStringNotContainsString('red carpet', strtolower($m->sent[0]['htmlBody']));
    }

    // ══ REPLACING ONE ═══════════════════════════════════════════════════════

    /** An organiser's own letter is sent instead of the shipped one, tokens and all. */
    public function test_an_operator_can_replace_a_letter_and_still_use_the_tokens(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => InviteSequence::bodyKey(3, InviteAudience::NOMINEE),
            'value'    => "Three sleeps to {event}.\n\nWe will see you at {venue}, {countdown}.",
        ]);

        $sent = $this->letterAt(3);

        $this->assertStringContainsString('Three sleeps to The Incredible Principal Awards 2026',
            $sent['htmlBody']);
        $this->assertStringContainsString('Eko Convention Centre', $sent['htmlBody']);
        $this->assertStringNotContainsString('own your message', $sent['htmlBody'],
            'the shipped letter must not be sent alongside the replacement');
    }

    /**
     * An operator's letter is TEXT, and reaches the inbox as text.
     *
     * These bodies are typed into an admin form and rendered into an HTML email. Anything
     * that let markup through would make the settings screen a way to write raw HTML into
     * mail this platform sends in an organiser's name — and would break the message the
     * first time somebody typed an ampersand.
     */
    public function test_a_letter_cannot_smuggle_markup_into_the_message(): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => InviteSequence::bodyKey(3, InviteAudience::NOMINEE),
            'value'    => 'Tea <script>alert(1)</script> & scones <b>now</b>',
        ]);

        $html = $this->letterAt(3)['htmlBody'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>now</b>', $html);
        $this->assertStringContainsString('&amp; scones', $html, 'and an ampersand still reads as one');
    }

    /** Every letter has a field that sets it, on a host with no shell. */
    public function test_every_letter_and_token_setting_has_a_field(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $save = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');

        foreach (['invite_seq_theme', 'invite_seq_outcome', 'invite_seq_values',
                  'invite_seq_action', 'invite_seq_team'] as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $form, $key . ' has no field');
            $this->assertStringContainsString($key, $save, $key . ' is not accepted by the save');
        }

        // The bodies are two nested loops — audience × day — rather than ten hand-written
        // boxes, so there is no literal `name="invite_seq_body_judge_5"` to scan for. The
        // chain is asserted instead: the controller hands both lists to the screen, the
        // screen builds a field name from them, and the save accepts every combination.
        //
        // The BUILDER is matched, not just the prefix. An earlier version of this test
        // looked for `'invite_seq_body_' ~ d` and kept passing after the field names
        // gained an audience — because the legacy fallback lookup elsewhere on the page
        // still contained that exact string. A test that survives the change it exists to
        // catch is worse than no test.
        $this->assertStringContainsString('InviteSequence::DAYS', $save,
            'the screen cannot loop over a day list it is never given');
        $this->assertStringContainsString('InviteAudience::all()', $save,
            'nor over an audience list it is never given');
        $this->assertStringContainsString("'invite_seq_body_' ~ a ~ '_' ~ d", $form,
            'the field name has to be built from those lists, not typed out beside them');

        foreach (InviteAudience::all() as $a) {
            foreach (InviteSequence::DAYS as $d) {
                $this->assertStringContainsString(InviteSequence::bodyKey($d, $a), $save,
                    $a . ' day ' . $d . ' has a field but the save discards it');
            }
        }

        // And the legacy nominee keys are still accepted, or a screen saved before the
        // audience existed becomes a silent revert.
        foreach (InviteSequence::DAYS as $d) {
            $this->assertStringContainsString("'invite_seq_body_" . $d . "'", $save,
                'the pre-audience key for day ' . $d . ' must still be accepted');
        }
    }
}
