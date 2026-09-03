<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AzureVoice, DoorWelcome, NameSays};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Nigerian voice at the door — and the rule that keeps it free and instant.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING IS SYNTHESISED WHILE SOMEBODY IS STANDING THERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * That is the whole design and it is the thing these tests exist to hold. A door is a
 * queue; putting an HTTPS round trip to a datacentre between the scan and the verdict costs
 * several hundred milliseconds on venue wifi, on a phone with one bar, in front of forty
 * people — and it would fail hardest exactly when the queue is longest.
 *
 * So every clip is rendered hours early by a maintenance sweep and the door does a filename
 * lookup. {@see DoorWelcome::keyToPlay()} must never reach the network, and there is a test
 * below that fails if it ever starts to.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY AZURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The two engines already here have no Nigerian English voice. An American voice reading
 * "Chidinma Okonkwo" at a Lagos gala is the platform sounding like it was built somewhere
 * else at the moment it is welcoming somebody by name. Azure publishes `en-NG-EzinneNeural`
 * and `en-NG-AbeoNeural`, on a free tier of 0.5M characters a month — about twenty thousand
 * welcomes, which is why this can be a standing feature rather than a budget line.
 */
final class DoorWelcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    private function on(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'door_welcome_enabled', 'value' => '1'],
            ['key_name' => 'azure_speech_key', 'value' => 'test-key-not-real'],
        ]);
    }

    /** A clip already on disk, without going anywhere near the network. */
    private function plant(string $line): string
    {
        $key  = DoorWelcome::keyFor($line);
        $path = DoorWelcome::pathFor($key);
        $this->assertNotNull($path);
        file_put_contents($path, "ID3" . str_repeat("\x00", 128));

        return $key;
    }

    // ══ what it says ═════════════════════════════════════════════════════════

    /** A realistic gate: enough names to exercise every phrasing more than once. */
    private const GUESTS = [
        'Ada Obi', 'Chidinma Okonkwo', 'Tunde Cole', 'Ngozi Adaeze', 'Emeka Nwosu',
        'Folake Adeyemi', 'Ifeanyi Eze', 'Bisi Alabi', 'Uche Mba', 'Yemi Sanni',
        'Kelechi Duru', 'Amaka Nnadi',
    ];

    /**
     * "You are welcome", not "welcome" — that is how the greeting is actually said here,
     * and this is a Nigerian evening.
     *
     * Asserted as a PROPERTY of every phrasing rather than as one exact sentence: the door
     * varies what it says (see the next test for why), so an equality check would pin one
     * form and quietly stop covering the other three the day a fifth is added.
     */
    /**
     * What the door will actually SAY for a first name.
     *
     * Since the respelling rule is applied rather than merely offered, the raw spelling is
     * no longer what appears in the line — `Ngozi` is spoken as `N-goh-zee`. The property
     * these tests protect is that the line NAMES THE PERSON, so they ask for the spoken
     * form rather than the written one.
     */
    private function spoken(string $first): string
    {
        $said = DoorWelcome::suggest($first);

        return $said !== '' ? $said : $first;
    }

    public function test_the_greeting_is_the_nigerian_one(): void
    {
        foreach (self::GUESTS as $g) {
            $l = DoorWelcome::line($g);
            $first = DoorWelcome::firstName($g);

            $this->assertNotSame('', $l, 'said nothing for: ' . $g);
            $this->assertStringContainsString($this->spoken($first), $l, 'did not name: ' . $g);
            $this->assertMatchesRegularExpression('/you are (most )?welcome/i', $l,
                'not the Nigerian form: ' . $l);
            // The surname is never spoken — warmer, and it halves the chance of the voice
            // mangling a name it has not met.
            $surname = trim(substr($g, strlen(explode(' ', $g)[0])));
            $this->assertStringNotContainsString($surname, $l, 'spoke the surname: ' . $l);
        }
    }

    /**
     * More than one phrasing, and the SAME one for the same person every time.
     *
     * ── WHY BOTH HALVES ──────────────────────────────────────────────────────
     *
     * A steward hears this four hundred times in an evening and so does everybody near the
     * gate; one sentence on repeat stops being a welcome by the fortieth guest and becomes
     * a turnstile noise. But the choice cannot be random: a guest who steps out and
     * re-scans would hear a different sentence and wonder what changed, and — the
     * expensive half — the cache key IS the text, so a random pick would miss the clip
     * rendered this morning and mint a new one, and a new bill, on every single scan.
     */
    public function test_the_phrasing_varies_by_guest_but_never_by_scan(): void
    {
        $seen = [];
        foreach (self::GUESTS as $g) {
            $once  = DoorWelcome::line($g);
            $twice = DoorWelcome::line($g);

            $this->assertSame($once, $twice, 'a re-scan said something different: ' . $g);
            $seen[$once] = true;
        }

        $this->assertGreaterThan(1, count(array_unique(
            array_map(static fn (string $g): string => str_replace(DoorWelcome::firstName($g), '', DoorWelcome::line($g)), self::GUESTS)
        )), 'every guest got the identical sentence — the door is a turnstile');
    }

    /** First name only: warmer, and it halves the chance of mangling a surname. */
    public function test_only_the_first_name_is_spoken(): void
    {
        $this->assertStringContainsString($this->spoken('Chidinma'), DoorWelcome::line('chidinma okonkwo'));
        $this->assertStringNotContainsString('Okonkwo', DoorWelcome::line('chidinma okonkwo'));
        $this->assertSame('Ngozi', DoorWelcome::firstName('  NGOZI   ADAEZE  '));
    }

    /** A guest of honour is met differently, because their arrival is different. */
    public function test_a_guest_of_honour_is_greeted_as_one(): void
    {
        $l = DoorWelcome::honourLine('Tunde Cole', 'nominee');

        $this->assertStringContainsString($this->spoken('Tunde'), $l);
        $this->assertStringContainsString('you are most welcome', $l);
        $this->assertStringContainsString('nominee', $l);
        // Never varied. Somebody arriving at an evening held for them gets the sentence
        // that names why they are there, every time, including on the way back from a call.
        $this->assertSame($l, DoorWelcome::honourLine('Tunde Cole', 'nominee'));
    }

    // ══ the beat, and the markup around it ═══════════════════════════════════

    /**
     * The pause after the name is a MARKER in the phrase and SSML only on the wire.
     *
     * Raw `<break/>` in the phrase would put angle brackets into the cache key, so the day
     * somebody changed the break from 260ms to 300ms every clip already on disk would be
     * orphaned — silently, and discovered at a door.
     */
    public function test_the_beat_is_a_marker_in_the_text_and_ssml_on_the_wire(): void
    {
        $line = DoorWelcome::line('Ada Obi');

        $this->assertStringContainsString(AzureVoice::PAUSE, $line,
            'no beat after the name — the name runs into the greeting');
        $this->assertStringNotContainsString('<', $line, 'markup leaked into the cache key');

        $ssml = AzureVoice::ssml($line);
        $this->assertStringContainsString('<break time="260ms"/>', $ssml);
        $this->assertStringNotContainsString(AzureVoice::PAUSE, $ssml,
            'the marker would be READ ALOUD at a door');
    }

    /**
     * Escaped BEFORE the marker is swapped, never after.
     *
     * The other order would let a name carrying the marker's own characters place real
     * markup inside the request. Names cannot reach here with braces in them — firstName()
     * refuses those — but ssml() is public and takes any text, and a guard that depends on
     * a caller two files away is one refactor from being a hole.
     */
    public function test_nothing_in_a_name_can_become_markup(): void
    {
        $ssml = AzureVoice::ssml('Ada & <b>Obi</b>' . AzureVoice::PAUSE . ' welcome.');

        $this->assertStringContainsString('&amp;', $ssml);
        $this->assertStringContainsString('&lt;b&gt;', $ssml);
        $this->assertStringNotContainsString('<b>', $ssml);
        $this->assertStringContainsString('<break', $ssml, 'the real beat was escaped away');
    }

    /**
     * Slower and a little lower, and nothing Azure will ignore.
     *
     * Bare text read like a railway announcement: even, fast, and hard to catch in a hall.
     * `<mstts:express-as>` is the obvious next reach and it is WRONG here — Microsoft's own
     * voice list says styles and roles are not supported for either Nigerian English voice,
     * so sending one gets the markup dropped at best and the request refused at worst,
     * during a sweep nobody is watching.
     */
    public function test_the_voice_carries_its_pacing_in_core_ssml_only(): void
    {
        $ssml = AzureVoice::ssml('Ada, welcome.');

        $this->assertStringContainsString('xml:lang="en-NG"', $ssml);
        $this->assertStringContainsString(AzureVoice::voice(), $ssml);
        $this->assertStringNotContainsString('mstts', $ssml,
            'styles and roles are not supported for en-NG — this is silently dropped');

        // A small slowdown by default: a door is noisy and the listener is not expecting
        // to be spoken to. Azure's floor is 0.5x and this is nowhere near it.
        $this->assertMatchesRegularExpression('/rate="-\d+%"/', $ssml,
            'no rate at all — it reads as an announcement');
        preg_match('/rate="-(\d+)%"/', $ssml, $r);
        $this->assertLessThanOrEqual(50, (int) ($r[1] ?? 99), 'below Azure\'s 0.5x floor');
    }

    /**
     * NO PITCH SHIFT BY DEFAULT, and this is the change that answered "it sounds robotic".
     *
     * It used to ship `pitch="-2st"` for warmth. A neural voice already has its own
     * intonation and contour — `<prosody pitch>` does not make the model speak lower, it
     * RESAMPLES the audio it produced. So the warmth arrived as artefact, on every clip,
     * and the only way to hear what Azure actually sounds like was to edit a source file.
     */
    public function test_there_is_no_pitch_shift_unless_somebody_asks_for_one(): void
    {
        $this->assertStringNotContainsString('pitch=', AzureVoice::ssml('Ada, welcome.'),
            'the neural voice is being resampled by default, which is what robotic was');

        DB::table('gates_settings')->insert(
            ['key_name' => 'azure_speech_pitch', 'value' => '-2st']);

        $this->assertStringContainsString('pitch="-2st"', AzureVoice::ssml('Ada, welcome.'),
            'somebody who wants the old sound back cannot have it');
    }

    /**
     * And with everything neutral there is no wrapper at all — the cleanest audio Azure
     * will give us, rather than a no-op element around it.
     */
    public function test_neutral_settings_emit_no_prosody_element(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'azure_speech_rate',  'value' => '0%'],
            ['key_name' => 'azure_speech_pitch', 'value' => '0st'],
        ]);

        $this->assertStringNotContainsString('<prosody', AzureVoice::ssml('Ada, welcome.'));
    }

    /** A value outside the offered set is not passed through to Azure as markup. */
    public function test_a_prosody_value_we_do_not_offer_is_refused(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'azure_speech_rate',  'value' => '-400%'],
            ['key_name' => 'azure_speech_pitch', 'value' => '"><script>'],
        ]);

        $ssml = AzureVoice::ssml('Ada, welcome.');

        $this->assertStringNotContainsString('-400%', $ssml,
            'an out-of-range rate reaches Azure as a 400 during an unattended run');
        $this->assertStringNotContainsString('script', $ssml);
    }

    /**
     * The pacing is part of the cache key, or the setting silently does nothing.
     *
     * `have()` finds a clip by this key. An operator who turned the pitch shift off and
     * saved would otherwise get the identical audio for every guest already rendered, and
     * no way to tell whether the setting or their ears were at fault.
     */
    public function test_changing_the_pacing_retires_the_clips_it_changes(): void
    {
        $before = DoorWelcome::keyFor('Ada, you are welcome.');

        DB::table('gates_settings')->insert(
            ['key_name' => 'azure_speech_rate', 'value' => '-15%']);

        $this->assertNotSame($before, DoorWelcome::keyFor('Ada, you are welcome.'),
            'the pacing changed and every clip already on disk answers for the old one');
    }

    /** A cap landing mid-marker would leave "{{br" to be read aloud at a door. */
    public function test_a_marker_cut_by_the_character_cap_is_not_spoken(): void
    {
        $long = str_repeat('a', 238) . AzureVoice::PAUSE . ' welcome.';

        $this->assertStringNotContainsString('{', AzureVoice::tidy($long));
    }

    /**
     * A booking form takes free text. Sending "N/A" or an address to a voice engine spends
     * characters and produces something nobody wants to hear at a door.
     */
    public function test_things_that_are_not_names_are_not_spoken(): void
    {
        foreach (['', '   ', 'ada@example.com', 'X', '12345', 'Guest#4'] as $junk) {
            $this->assertSame('', DoorWelcome::line($junk), 'spoke: ' . $junk);
        }
    }

    // ══ the time of day, taken from the EVENT ════════════════════════════════

    /** @param array<string,mixed> $over */
    private function anEvent(array $over = []): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId($over + [
            'slug' => 'gala-' . bin2hex(random_bytes(4)),
            'title' => 'A gala',
            // 19:00 in Lagos, 21:00 in Nairobi, stored UTC like everything here.
            'event_date' => '2026-12-05 18:00:00',
            'status' => 'published',
        ]);

        return (object) DB::table('gates_site_events')->where('id', $id)->first();
    }

    /**
     * "Good evening" comes from when the EVENT starts, in the EVENT's own zone.
     *
     * ── WHY NOT FROM THE CLOCK ───────────────────────────────────────────────
     *
     * Every clip is made by a maintenance sweep hours or days ahead, so "now" at render
     * time is the middle of the night as often as not. Greeting a room of people arriving
     * at a seven o'clock ceremony with "good morning" is the kind of wrong that gets
     * screenshotted.
     *
     * ── AND WHY NOT FROM THE PLATFORM'S ZONE ─────────────────────────────────
     *
     * The same instant is 18:00 in Lagos and 20:00 in Nairobi. A platform that calls itself
     * continental cannot greet a Nairobi gala by the hour in Lagos.
     */
    public function test_the_time_of_day_comes_from_the_events_own_clock(): void
    {
        // 05:00 UTC is 06:00 in Lagos — a breakfast launch, and a real one.
        $this->assertStringStartsWith('Good morning.', DoorWelcome::greeting(
            $this->anEvent(['event_date' => '2026-12-05 05:00:00', 'timezone' => 'Africa/Lagos'])));

        // THE ONE THAT PROVES THE ZONE IS READ. 14:00 UTC is a 15:00 afternoon in Lagos and
        // a 17:00 evening in Nairobi — the same instant, two rooms, two greetings.
        $afternoon = ['event_date' => '2026-12-05 14:00:00'];
        $this->assertStringStartsWith('Good afternoon.',
            DoorWelcome::greeting($this->anEvent($afternoon + ['timezone' => 'Africa/Lagos'])));
        $this->assertStringStartsWith('Good evening.',
            DoorWelcome::greeting($this->anEvent($afternoon + ['timezone' => 'Africa/Nairobi'])),
            'the same instant is an evening in Nairobi and the door greeted it by Lagos hours');

        // 18:00 UTC: 19:00 in Lagos — the ordinary gala.
        $this->assertStringStartsWith('Good evening.',
            DoorWelcome::greeting($this->anEvent(['timezone' => 'Africa/Lagos'])));
    }

    /**
     * Nothing rather than a guess.
     *
     * An event with no start, or one starting at 02:00, gets no greeting at all. "Good
     * night" is not something anybody says to a person who has just arrived, and a wrong
     * time of day is worse than none — it is the platform announcing that it does not know
     * when the evening is.
     */
    public function test_a_time_of_day_it_cannot_know_is_left_unsaid(): void
    {
        $this->assertSame('', DoorWelcome::greeting(null));
        $this->assertSame('', DoorWelcome::greeting((object) ['event_date' => '']));
        $this->assertSame('', DoorWelcome::greeting(
            $this->anEvent(['event_date' => '2026-12-05 01:30:00', 'timezone' => 'Africa/Lagos'])));

        // And a line built without an event is still a working welcome, just a plainer one.
        $bare = DoorWelcome::line('Ada Obi');
        $this->assertStringNotContainsString('Good ', $bare);
        $this->assertMatchesRegularExpression('/you are (most )?welcome/i', $bare);
    }

    /**
     * THE REGRESSION THIS WHOLE CHANGE IS ABOUT.
     *
     * The sweep renders `linesFor($eventId)`; the door asks `keyToPlay(line($name, $event))`.
     * The key IS the text. Build one of them without the event and the two sentences differ
     * by "Good evening. " — different hash, no file, and every single guest silently gets
     * the generic clip on the one night it matters. No error, no log line, nothing to see
     * on any screen.
     */
    public function test_what_the_sweep_renders_is_what_the_door_asks_for(): void
    {
        $this->on();
        $event = $this->anEvent(['timezone' => 'Africa/Lagos']);
        DB::table('gates_event_registrations')->insert([
            'event_id' => $event->id, 'name' => 'Ada Obi',
            'email' => 'ada@example.com', 'status' => 'confirmed',
        ]);

        $lines = DoorWelcome::linesFor((int) $event->id);
        $this->assertNotEmpty($lines, 'the sweep found nothing to render for a confirmed guest');

        // Exactly what the sweep would put on disk, without a network call.
        foreach ($lines as $l) $this->plant($l);

        $asked = DoorWelcome::line('Ada Obi', $event);
        $this->assertContains($asked, $lines,
            'the door asks for a sentence the sweep never rendered');
        $this->assertSame(DoorWelcome::keyFor($asked), DoorWelcome::keyToPlay($asked),
            'the clip was on disk and the door still played the fallback');
    }

    /**
     * And the controller must actually hand the event over — the half a unit test cannot see.
     *
     * Structural, because the failure is invisible from every other angle: the door still
     * returns 200, still admits, still plays a clip. It plays the wrong one, for everybody.
     */
    public function test_the_door_passes_the_event_into_both_greetings(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DoorController.php');

        // ── PARENTHESES ARE MATCHED, NOT PATTERNED ───────────────────────────
        //
        // The first version of this test used a lazy `\(.*?\);` and it PASSED against a
        // deliberately broken honour call — the call ends `))),` so the pattern ran on to
        // the next statement and found an event argument belonging to something else. A
        // test that reads past the end of the call it is checking is worse than no test:
        // it reports green on precisely the fault it was written for.
        $found = [];
        foreach (['line', 'honourLine'] as $fn) {
            $at = 0;
            while (($at = strpos($c, 'DoorWelcome::' . $fn . '(', $at)) !== false) {
                $open = $at + strlen('DoorWelcome::' . $fn . '(');
                $depth = 1;
                $i = $open;
                while ($i < strlen($c) && $depth > 0) {
                    if ($c[$i] === '(') $depth++;
                    elseif ($c[$i] === ')') $depth--;
                    $i++;
                }
                $found[] = [$fn, substr($c, $open, $i - $open - 1)];
                $at = $i;
            }
        }

        $this->assertCount(2, $found, 'expected exactly the two greeting call sites at the door');

        // Every call, not merely one of them: a door that greets ticket holders by the
        // event's clock and guests of honour by nothing at all is half-broken in the way
        // that is hardest to notice, because both still play something.
        foreach ($found as [$which, $args]) {
            $this->assertMatchesRegularExpression('/eventOfPass\(|\$event\b/', $args,
                $which . '() at the door is built WITHOUT the event, so its key can never '
                . 'match what the sweep rendered — every guest silently gets the fallback. '
                . 'Args seen: ' . $args);
        }
    }

    // ══ saying a name properly ═══════════════════════════════════════════════

    /**
     * The only fix available for a name the voice gets wrong.
     *
     * There is no shell on this host and nothing to edit, so `Written = Spoken` in settings
     * is it. Getting a person's name wrong, out loud, at their own event, in front of a
     * queue is worse than silence — which is why this exists and why it is matched on the
     * FIRST name, the part that actually gets spoken.
     */
    public function test_a_name_can_be_told_how_to_be_said(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says',
            'value' => "Ol\u{101}\u{1E63}ub\u{1ECD}mi = Ola-shu-BOR-mi\nChidinma = Chi-DEEN-ma"]);

        $l = DoorWelcome::line('chidinma okonkwo');
        $this->assertStringContainsString('Chi-DEEN-ma', $l);
        $this->assertStringNotContainsString('Chidinma', $l);

        // Case-insensitive on the written form: an operator types the name as they know it,
        // and a booking form takes it however the guest typed it.
        $this->assertStringContainsString('Chi-DEEN-ma', DoorWelcome::line('CHIDINMA OKONKWO'));

        // A guest of honour is corrected too — the same name, the same mouth.
        $this->assertStringContainsString('Chi-DEEN-ma', DoorWelcome::honourLine('Chidinma Okonkwo', 'nominee'));
    }

    /**
     * THE ONE THAT MADE THE TABLE WORTH HAVING. One entry covers every spelling.
     *
     * Entries were matched on the exact lower-cased string, so an operator who wrote
     * `Ọláṣubọ̀mí = Ola-shu-BOR-mi` corrected that name only for guests who had typed it
     * WITH the sub-dots and the tone marks — and most people type `Olasubomi`, because
     * most keyboards do not have ọ. The correction silently did nothing for almost
     * everybody it was written for, and the failure looked exactly like the voice being
     * bad at Nigerian names.
     */
    public function test_one_entry_covers_every_way_the_name_gets_typed(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says',
            'value' => "\u{1ECC}l\u{E1}\u{1E63}ub\u{1ECD}\u{300}m\u{ED} = Ola-shu-BOR-mi"]);

        foreach (['Olasubomi Adewale',            // the plain form a booking form receives
                  "\u{1ECC}l\u{E1}\u{1E63}ub\u{1ECD}\u{300}m\u{ED} Adewale",  // exactly as written
                  "Ol\u{E1}subomi Adewale",       // half the marks, which is what people do
                  'OLASUBOMI ADEWALE'] as $typed) {
            $this->assertStringContainsString('Ola-shu-BOR-mi', DoorWelcome::line($typed),
                'the correction missed "' . $typed . '", which is how the name arrives');
        }
    }

    /**
     * THE ONE THAT COST THE MOST, and it was not a mispronunciation.
     *
     * `firstName()` validated on `\p{L}` plus two apostrophes and a hyphen, and `\p{L}`
     * does not match a COMBINING mark — which is what a Yoruba tone mark is. So a name
     * spelled properly was REJECTED, `line()` returned '', and that guest got the generic
     * clip while the same name typed plainly was welcomed by name.
     *
     * No dictionary here on purpose: this is about whether the name is usable at all, not
     * about whether it is corrected.
     */
    public function test_a_name_spelled_with_its_tone_marks_is_still_greeted(): void
    {
        foreach (["\u{1ECC}l\u{E1}\u{1E63}ub\u{1ECD}\u{300}m\u{ED} Adewale",
                  "Nna\u{1EB9}m\u{1EB9}ka Obi",
                  "Ol\u{289}\u{300}wat\u{1ECD}\u{301}s\u{ED}n Bello"] as $typed) {
            $line = DoorWelcome::line($typed);
            $this->assertNotSame('', $line,
                'the guest who spells their own name properly is the one who gets no '
                . 'greeting: ' . $typed);
            // The greeting form varies deterministically by name, so the invariant is
            // that the person is named and welcomed — not which of the four they get.
            $this->assertMatchesRegularExpression('/welcome/i', $line);
            $this->assertStringContainsString($this->spoken(explode(' ', $typed)[0]), $line,
                'the greeting no longer contains the name it is greeting');
        }
    }

    /** And the other direction: a plain entry corrects a guest who spells it properly. */
    public function test_a_plainly_written_entry_still_catches_the_marked_spelling(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says',
            'value' => 'Nnaemeka = Nna-EH-me-ka']);

        $this->assertStringContainsString('Nna-EH-me-ka',
            DoorWelcome::line("Nna\u{1EB9}m\u{1EB9}ka Obi"));
    }

    /** Hausa's hooked letters decompose to nothing, so they need naming outright. */
    public function test_the_hausa_hooked_letters_fold_too(): void
    {
        $this->assertSame(DoorWelcome::fold('Bello'),  DoorWelcome::fold("\u{253}ello"));
        $this->assertSame(DoorWelcome::fold('Danladi'), DoorWelcome::fold("\u{257}anladi"));
        $this->assertSame(DoorWelcome::fold("Ya\u{2BC}u"), DoorWelcome::fold('Yau'));
    }

    /**
     * Folding must not merge two different people.
     *
     * It strips marks, not letters. A table that corrected Ada and answered for Adaeze
     * would be worse than no table — a guest greeted by somebody else's name at their own
     * door is the one failure here nobody would forgive.
     */
    public function test_folding_does_not_collapse_different_names(): void
    {
        $names = ['Ada', 'Adaeze', 'Chidi', 'Chidinma', 'Ola', 'Olamide', 'Ngozi', 'Ngo'];

        $folded = array_map([DoorWelcome::class, 'fold'], $names);
        $this->assertSame(count($names), count(array_unique($folded)),
            'two different names fold to one key, so one of them is greeted as the other');
    }

    // ══ the worklist, which is what made the table usable ════════════════════

    /**
     * A respelling an English voice can read, for the names it otherwise cannot.
     *
     * These languages are written in a Latin alphabet an English voice reads by ENGLISH
     * rules — silent finals, long and short vowels, schwa in unstressed syllables — and
     * almost none of that applies. The vowels are pure and every syllable is sounded.
     */
    public function test_a_nigerian_name_is_respelled_the_way_an_english_voice_reads_it(): void
    {
        $this->assertSame('N-goh-zee',      DoorWelcome::suggest('Ngozi'));
        $this->assertSame('Chee-deen-mah',  DoorWelcome::suggest('Chidinma'));
        $this->assertSame('Ah-dah-eh-zeh',  DoorWelcome::suggest('Adaeze'));
        $this->assertSame('Oh-lah-mee-deh', DoorWelcome::suggest('Olamide'));
        $this->assertSame('M-bah',          DoorWelcome::suggest('Mba'));

        // The digraphs are one consonant each; split them and the voice says two.
        $this->assertSame('Choo-kwoo-eh-meh-kah', DoorWelcome::suggest('Chukwuemeka'));
    }

    /** A nasal closes its syllable — at the end of a word as well as inside one. */
    public function test_a_closing_nasal_does_not_become_its_own_syllable(): void
    {
        $this->assertSame('Oh-loo-wah-seh-oon', DoorWelcome::suggest('Oluwaseun'));
        $this->assertSame('Oh-loo-wah-toh-seen', DoorWelcome::suggest('Oluwatosin'));
        $this->assertStringNotContainsString('-n', DoorWelcome::suggest('Oluwaseun') . 'x');
    }

    /**
     * AND IT KNOWS WHEN NOT TO GUESS.
     *
     * A Nigerian guest list is full of Graces and Johns. Offering "G-rah-ceh" and
     * "Joh-h-n" costs the whole worklist its credibility, and the operator stops reading
     * the suggestions that were right.
     */
    public function test_a_name_that_does_not_fit_the_pattern_gets_no_suggestion(): void
    {
        foreach (['Grace', 'John', 'Christopher', 'Blessing', 'Precious'] as $english) {
            $this->assertSame('', DoorWelcome::suggest($english),
                $english . ' was offered a Nigerian respelling');
        }
    }

    /**
     * THE WORKLIST. The names actually coming, that have no pronunciation yet.
     *
     * The table was the only mechanism that could fix a name and also the reason nobody
     * used it: an operator was asked to write an entry for every guest with no way of
     * knowing which the voice would get wrong, so it stayed empty and every name was
     * mispronounced.
     */
    /**
     * The sheet shows how every name WILL be said, and who decided.
     *
     * It used to list the names with no pronunciation — homework an operator had to do
     * before the voice was any good. Every name here already has an answer, so the screen
     * asks somebody to listen rather than to teach.
     */
    public function test_the_sheet_shows_how_each_name_will_be_said_and_who_decided(): void
    {
        $this->soonEventWithGuests(
            ['Ngozi Eze', 'Chidinma Okonkwo', 'Ngozi Adeyemi', 'Yetunde Cole', 'Ngozi Bello']);

        DB::table('gates_settings')->insert(
            ['key_name' => 'door_welcome_says', 'value' => 'Yetunde = Yeh-TOON-deh']);
        NameSays::remember('Chidinma', 'chee-DEEN-mah', 'ai');

        $sheet = DoorWelcome::nameSheet();
        $by    = array_column($sheet, null, 'name');

        // Commonest first: the name three guests share is heard before the one nobody does.
        $this->assertSame('Ngozi', $sheet[0]['name']);
        $this->assertSame(3, $sheet[0]['count']);

        // Every row carries what the door will actually say, and its provenance.
        $this->assertSame('N-goh-zee',      $by['Ngozi']['said']);
        $this->assertSame('rule',           $by['Ngozi']['source']);
        $this->assertSame('Yeh-TOON-deh',   $by['Yetunde']['said']);
        $this->assertSame('you',            $by['Yetunde']['source']);
        $this->assertSame('chee-DEEN-mah',  $by['Chidinma']['said']);
        $this->assertSame('worked out',     $by['Chidinma']['source']);
    }

    /** Only the events about to be rendered — a list of every name ever is not a job. */
    public function test_the_sheet_ignores_events_that_are_not_close(): void
    {
        $this->soonEventWithGuests(['Chidinma Okonkwo']);

        $far = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Next year', 'slug' => 'far-' . bin2hex(random_bytes(3)),
            'status' => 'published',
            'event_date' => Carbon::now()->addDays(DoorWelcome::LEAD_DAYS + 30)->toDateTimeString(),
        ]);
        DB::table('gates_event_registrations')->insert([
            'event_id' => $far, 'name' => 'Obiageli Nwosu', 'email' => 'far@example.test',
            'tier' => 'x', 'quantity' => 1, 'amount_naira' => 1, 'ticket_code' => 'FAR-1',
            'status' => 'confirmed', 'reference' => 'FR', 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $names = array_column(DoorWelcome::nameSheet(), 'name');

        $this->assertContains('Chidinma', $names);
        $this->assertNotContains('Obiageli', $names,
            'a guest list three days out is a worklist; one three months out is a distraction');
    }

    /**
     * NOTHING IS APPLIED. A suggestion reaches a guest only after a person has read it.
     *
     * The rule works over bare letters, and most names are written without the tone marks
     * that would tell it which name it is looking at — so it is a starting point and never
     * a correction. A confident wrong pronunciation said at somebody's own door is worse
     * than an English one.
     */
    public function test_nothing_writes_into_the_operators_own_list(): void
    {
        $this->soonEventWithGuests(['Chidinma Okonkwo']);

        DoorWelcome::nameSheet();
        NameSays::learn(['Chidinma Okonkwo']);

        // `door_welcome_says` is the operator's. What the platform works out lives in its
        // own table, so a person opening that box sees only what a person put there — and
        // clearing it never wipes the platform's own work.
        $this->assertSame([], DoorWelcome::dictionary(),
            'the machine wrote into the box a person types into');
    }

    /** One upcoming event with a guest list, for the worklist tests. */
    private function soonEventWithGuests(array $names): int
    {
        $ev = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'soon-' . bin2hex(random_bytes(3)),
            'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);

        foreach ($names as $i => $n) {
            DB::table('gates_event_registrations')->insert([
                'event_id' => $ev, 'name' => $n, 'email' => 'g' . $i . bin2hex(random_bytes(2)) . '@example.test',
                'tier' => 'Standard', 'quantity' => 1, 'amount_naira' => 5000,
                'ticket_code' => 'TK-' . $i . bin2hex(random_bytes(2)), 'status' => 'confirmed',
                'reference' => 'R' . $i, 'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        return $ev;
    }

    /** A name with no entry is spoken as it was written, not as an empty string. */
    /**
     * A name nobody has written an entry for is still said properly.
     *
     * THE POINT OF THE WHOLE CHANGE. This used to assert the opposite — that an unentered
     * name was "left alone" — and being left alone meant being read by English rules,
     * which for a Yoruba or Igbo name is not a neutral default but a wrong answer chosen
     * on purpose. Nobody has an afternoon to teach three hundred names, so the rule
     * answers where a person has not.
     */
    public function test_a_name_nobody_entered_is_still_said_properly(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says',
            'value' => 'Chidinma = Chi-DEEN-ma']);

        $line = DoorWelcome::line('Ngozi Eze');

        $this->assertStringContainsString('N-goh-zee', $line,
            'a name with no entry fell back to being read as English');
        $this->assertStringNotContainsString('Ngozi', $line);

        // And a name the rule cannot improve is genuinely left as written — an English
        // voice already says Grace correctly, and respelling it would make it worse.
        $this->assertStringContainsString('Grace', DoorWelcome::line('Grace Johnson'));
    }

    /**
     * A half-written line is ignored, never applied.
     *
     * `Chidinma =` with nothing after it would replace the name with an empty string and the
     * door would say ", you are welcome." — erasing the person rather than correcting them,
     * which is the worst outcome available here.
     */
    public function test_a_half_written_pronunciation_is_ignored(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says',
            'value' => "Chidinma =\n= Chi-DEEN-ma\nnot a rule at all\n\nAda = Ah-DAH"]);

        // The malformed line is dropped, so Chidinma falls through to the rule rather
        // than to a half-written entry that would have erased the name.
        $this->assertStringContainsString('Chee-deen-mah', DoorWelcome::line('Chidinma Okonkwo'));
        $this->assertStringContainsString('Ah-DAH', DoorWelcome::line('Ada Obi'));
        $this->assertSame([], array_filter(DoorWelcome::dictionary(), static fn ($v) => trim($v) === ''));
    }

    /** Billed per character and typed into a free-text box: bounded like everything else. */
    public function test_a_pronunciation_cannot_be_a_paragraph(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says',
            'value' => 'Ada = ' . str_repeat('la', 200)]);

        $this->assertLessThanOrEqual(60, mb_strlen(DoorWelcome::dictionary()['ada'] ?? ''));
    }

    // ══ the rule ═════════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. The door's lookup never synthesises.
     *
     * Asserted structurally as well as behaviourally: a future edit that "helpfully" renders
     * a missing clip on demand would pass every other test in this file while putting a
     * datacentre round trip into a queue.
     */
    public function test_the_door_lookup_never_reaches_the_network(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DoorWelcome.php');
        $from = (int) strpos($src, 'function keyToPlay');
        $body = substr($src, $from, (int) strpos($src, 'function genericLine') - $from);

        $this->assertStringNotContainsString('render(', $body,
            'keyToPlay() renders on demand — that is a synthesis call inside a queue');
        $this->assertStringNotContainsString('AzureVoice::say', $body);
    }

    /** With nothing rendered there is simply no sound. A silent door is a working door. */
    public function test_a_missing_clip_is_silence_and_not_an_error(): void
    {
        $this->on();

        $this->assertSame('', DoorWelcome::keyToPlay('Ada, you are welcome.'));
    }

    /** The generic clip covers every walk-up and every unusable name. */
    public function test_somebody_with_no_clip_still_gets_the_voice(): void
    {
        $this->on();
        $this->plant(DoorWelcome::genericLine());

        $key = DoorWelcome::keyToPlay('Somebody Nobody Rendered, you are welcome.');

        $this->assertSame(DoorWelcome::keyFor(DoorWelcome::genericLine()), $key,
            'a walk-up got silence when the fallback was sitting on disk');
    }

    public function test_a_rendered_name_is_preferred_to_the_fallback(): void
    {
        $this->on();
        $this->plant(DoorWelcome::genericLine());
        $mine = $this->plant('Ada, you are welcome.');

        $this->assertSame($mine, DoorWelcome::keyToPlay('Ada, you are welcome.'));
    }

    /** Off means off — not a fallback, not a generic clip, nothing. */
    public function test_nothing_plays_when_the_feature_is_off(): void
    {
        $this->plant(DoorWelcome::genericLine());

        $this->assertSame('', DoorWelcome::keyToPlay('Ada, you are welcome.'));
    }

    // ══ the cache ════════════════════════════════════════════════════════════

    /** Changing the voice must not serve half an evening in the old one. */
    public function test_the_key_is_scoped_to_the_voice(): void
    {
        $before = DoorWelcome::keyFor('Ada, you are welcome.');
        DB::table('gates_settings')->insert(['key_name' => 'azure_speech_voice', 'value' => 'en-NG-AbeoNeural']);

        $this->assertNotSame($before, DoorWelcome::keyFor('Ada, you are welcome.'));
    }

    /** The key comes off a URL, so it cannot be allowed to walk out of the cache directory. */
    public function test_a_crafted_key_cannot_escape_the_cache(): void
    {
        foreach (['../../../etc/passwd', 'nope', '', str_repeat('g', 40), '/etc/passwd'] as $bad) {
            $this->assertNull(DoorWelcome::pathFor($bad), 'accepted: ' . $bad);
        }
        $this->assertNotNull(DoorWelcome::pathFor(str_repeat('a', 40)));
    }

    // ══ the engine ═══════════════════════════════════════════════════════════

    /** A voice name typed into a settings box is a 400 from Azure on an unwatched sweep. */
    public function test_an_unknown_voice_falls_back_rather_than_being_sent(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'azure_speech_voice', 'value' => 'en-US-JennyNeural']);

        $this->assertSame(AzureVoice::DEFAULT_VOICE, AzureVoice::voice());
    }

    /** Both voices Azure actually publishes for Nigerian English, and only those. */
    public function test_the_offered_voices_are_the_nigerian_ones(): void
    {
        $this->assertArrayHasKey('en-NG-EzinneNeural', AzureVoice::VOICES);
        $this->assertArrayHasKey('en-NG-AbeoNeural', AzureVoice::VOICES);
        $this->assertCount(2, AzureVoice::VOICES);
        foreach (array_keys(AzureVoice::VOICES) as $v) {
            $this->assertStringStartsWith('en-NG-', $v);
        }
    }

    /** Control characters are a 400 on a sweep nobody is watching, and it is billed per char. */
    public function test_what_is_sent_is_bounded_and_clean(): void
    {
        $this->assertSame('Ada Obi', AzureVoice::tidy("  Ada\x00  Obi \n"));
        $this->assertSame(240, mb_strlen(AzureVoice::tidy(str_repeat('a', 900))));
    }

    /** With no key nothing is attempted at all — the sweep is a no-op, never an error. */
    public function test_nothing_is_rendered_without_a_key(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_enabled', 'value' => '1']);

        $this->assertFalse(AzureVoice::configured());
        $this->assertFalse(DoorWelcome::ready());
        $this->assertSame(0, DoorWelcome::sweep());
    }

    // ══ the routes in ════════════════════════════════════════════════════════

    /** §18: a sweep nothing calls is a feature that never runs, on a host with no shell. */
    public function test_the_sweep_is_scheduled_and_addressable(): void
    {
        $m = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('DoorWelcome::sweep()', $m);
        $this->assertStringContainsString("'welcome'   =>", $m,
            'an organiser who imports a guest list at 4pm cannot wait for tomorrow at 06:00');
    }

    /** And the key is a credential: write-only, never rendered back into the page. */
    public function test_the_azure_key_is_never_echoed_to_the_page(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        $this->assertStringContainsString("'azure_speech_key' => 'azure_key'", $c,
            'the key is not on the write-only path');
        $this->assertStringNotContainsString('values.azure_speech_key', $t,
            'a credential in the page source of every settings render');
    }

    /**
     * §17: every guest's NAME, said aloud, on disk — with nothing that ever deleted it.
     *
     * `prune()` has always existed and its own docblock has always claimed "last month's
     * guest list is of no use to anybody". It had no caller, which made that sentence
     * false in the most expensive direction: a gala's whole attendee list stayed in
     * var/cache in audio, indefinitely, on a host with a fixed disk quota and no shell to
     * clear it from.
     */
    public function test_the_greeting_cache_is_actually_pruned(): void
    {
        $m = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('DoorWelcome::prune()', $m,
            'a cache of guests\' names with no caller that clears it');

        // And it does the deleting rather than merely being called.
        $dir = DoorWelcome::dir();
        $this->assertNotNull($dir);
        $old = $dir . '/' . str_repeat('b', 40) . '.mp3';
        file_put_contents($old, 'ID3');
        touch($old, time() - 40 * 86400);

        $this->assertGreaterThan(0, DoorWelcome::prune());
        $this->assertFileDoesNotExist($old);
    }

    /**
     * §17 again: "For the admin screen" — which had to actually exist.
     *
     * Greetings are rendered ahead, so on the afternoon of a gala the only honest answer
     * to "will the door say their names" is a count. Without a screen showing it, an
     * organiser who imported ninety guests at four o'clock finds out at the door — where
     * the miss is silent by design and all ninety get the generic clip.
     */
    public function test_whether_the_greetings_are_ready_is_visible_before_the_night(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/EventsController.php');
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/events/tickets.twig');

        $this->assertStringContainsString('DoorWelcome::costOf(', $c);
        $this->assertStringContainsString('welcome_ready', $t,
            'the count is computed and rendered nowhere');
    }

    /**
     * §18: the pronunciation list is the only fix for a mangled name, and it is written
     * blind unless somebody can hear the result.
     *
     * The preview is also the ONE place permitted to synthesise inside a request — a
     * superadmin who pressed "hear it", never an attendee in a queue — so it is asserted
     * here rather than left to a reader to notice it is not the door.
     */
    public function test_a_name_can_be_heard_before_the_night(): void
    {
        $r = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        $this->assertStringContainsString("'/voice-preview'", $r, 'nothing routes to the preview');
        $this->assertStringContainsString("'/voice-sample/{key}'", $r);
        $this->assertStringContainsString('door_welcome_says', $t, 'no box to write a pronunciation in');
        $this->assertStringContainsString("'door_welcome_says',", $c, 'the box is not saved');

        // The preview builds its line through the door's own resolver. One that wrote its
        // own sentence would be a preview of something nobody will ever hear — and the
        // dictionary is matched on the FIRST name, which is the part it would get wrong.
        $this->assertStringContainsString('DoorWelcome::line(', $c);

        // ── AND IT MUST NOT ANSWER WITH A DATA URI ───────────────────────────
        //
        // `media-src` in Csp is 'self' plus two video hosts — no data:, no blob:. Returning
        // the MP3 inline would be blocked by the browser with nothing an operator could
        // see: a play button that does nothing, permanently, on the one control whose whole
        // job is to prove the voice works.
        $csp = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Csp.php');
        $this->assertDoesNotMatchRegularExpression('/media-src[^;]*\bdata:/', $csp);
        $this->assertStringNotContainsString('data:audio', $c);
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
        $this->assertStringNotContainsString('data:audio', $js);
    }

    /**
     * And the sample is behind the same gate as the rest of the console.
     *
     * The cache holds every guest's name for the next three days as audio. A path serving
     * it to anybody who could guess a hash would be the attendee list, read aloud — which
     * is the exact promise the door itself makes to the person holding it.
     */
    public function test_the_sample_is_superadmin_only_and_never_renders(): void
    {
        $r = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $at = (int) strpos($r, "'/voice-sample/{key}'");
        $this->assertGreaterThan(0, $at);
        $after = substr($r, $at, 400);
        $this->assertStringContainsString("RoleMiddleware('superadmin')", $after,
            'the guest list, in audio, outside the superadmin gate');

        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');
        $from = (int) strpos($c, 'function voiceSample');
        $body = substr($c, $from, 900);
        $this->assertStringNotContainsString('render(', $body, 'the file server synthesises');
        $this->assertStringNotContainsString('AzureVoice::say', $body);
    }
}
