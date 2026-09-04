<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AiService, DoorWelcome, NameSays};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The platform working out how a name is said, instead of being taught.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE MECHANISM WAS RIGHT AND BEING THE ONLY MECHANISM WAS THE BUG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `door_welcome_says` let an operator write `Written = Spoken` lines, and it is exactly the
 * right way to correct a name. It was also the ONLY way, so somebody had to write an entry
 * for every guest with no idea which of three hundred the voice would get wrong. Nobody
 * did, and every Nigerian name was read by English rules — silent finals, long and short
 * vowels, schwa where there should be a pure vowel — producing a name its owner would not
 * answer to.
 *
 * Three answers now, in one order, and the ORDER is the whole design:
 *
 *   1. what a person said        — always wins, and nothing may quietly overrule it
 *   2. what the platform worked out — asked once per name, ever, ahead of time
 *   3. the rule                  — offline, no dependency, abstains rather than mangles
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND A MODEL'S ANSWER IS READ ALOUD TO A GUEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Which is why {@see NameSays::tidy()} is a boundary and not a formatter. Everything that
 * comes back goes into an SSML document and then into a hall, in place of somebody's name,
 * so a sentence, an apology, a refusal or a fragment of markup has to be refused here
 * rather than spoken there.
 */
final class NameSaysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ── THESE TESTS ARE ABOUT THE RESPELLING, SO THEY PICK A VOICE THAT NEEDS IT ──
        //
        // `DoorWelcome::saidAs()` only respells for a voice that does not know these names.
        // Azure's `en-NG` voices — the default — say Ada and Ngozi correctly and are handed
        // the name as written, which is the fix for the door sounding stilted. OpenAI's are
        // American and need every syllable, which is the case this whole mechanism exists
        // for. Without this line the assertions below would be checking a respelling
        // through a path that deliberately switches it off.
        DB::table('gates_settings')->where('key_name', \AfricaGates\Services\DoorVoice::SETTING)->delete();
        DB::table('gates_settings')->insert([
            'key_name' => \AfricaGates\Services\DoorVoice::SETTING,
            'value'    => \AfricaGates\Services\DoorVoice::OPENAI,
        ]);
    }

    /** A model that answers with whatever the test hands it. */
    private function ai(?string $reply): AiService
    {
        return new class ($reply) extends AiService {
            public function __construct(private readonly ?string $reply) { parent::__construct(); }
            public function configured(): bool { return true; }
            public function withTimeout(?int $seconds): static { return $this; }
            /** @var list<string> what was actually put on the wire */
            public array $sent = [];

            public function complete(string $system, string $user, int $maxTokens = 512,
                                     bool $json = false, float $temperature = 0.2,
                                     array $route = [], int $maxAttempts = 0): ?string
            {
                $this->sent[] = $system . "\n" . $user;
                return $this->reply;
            }
        };
    }

    private function hand(string $lines): void
    {
        DB::table('gates_settings')->where('key_name', 'door_welcome_says')->delete();
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_says', 'value' => $lines]);
    }

    // ══ the order ════════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. A person's correction outranks everything.
     *
     * Somebody who has heard the clip and fixed it knows something no rule and no model
     * does. A cache that outranked them would silently undo their work, and they would
     * have no way to tell whether they had saved it.
     */
    public function test_a_person_outranks_what_the_platform_worked_out(): void
    {
        NameSays::remember('Ngozi', 'WORKED-out', 'ai');
        $this->hand('Ngozi = A-PERSON-said-this');

        $this->assertStringContainsString('A-PERSON-said-this', DoorWelcome::line('Ngozi Eze'));
        $this->assertStringNotContainsString('WORKED-out', DoorWelcome::line('Ngozi Eze'));
    }

    /** What was worked out outranks the rule, which is the point of keeping it. */
    public function test_what_was_worked_out_outranks_the_rule(): void
    {
        $this->assertStringContainsString('N-goh-zee', DoorWelcome::line('Ngozi Eze'),
            'the fixture no longer exercises the rule it is comparing against');

        NameSays::remember('Ngozi', 'en-GOH-zee', 'ai');

        $this->assertStringContainsString('en-GOH-zee', DoorWelcome::line('Ngozi Eze'));
    }

    /** And the rule answers where nobody and nothing else has. */
    public function test_the_rule_answers_when_nothing_else_does(): void
    {
        $this->assertStringContainsString('Chee-deen-mah', DoorWelcome::line('Chidinma Okonkwo'));
    }

    /**
     * Reading a name must never reach the network.
     *
     * `saidAs()` is on the path of every greeting the door's own check response builds. A
     * lookup that could ask a model would put a round trip in front of a steward with a
     * queue — which is the one thing this whole feature is arranged to avoid.
     */
    public function test_looking_a_name_up_never_asks_anybody(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/NameSays.php');
        $at  = strpos($src, 'public static function known(');
        $this->assertNotFalse($at);

        $body = substr($src, $at, 700);
        $this->assertStringNotContainsString('AiService', $body,
            'the door-time lookup can call a model, so a queue waits on one');
        $this->assertStringNotContainsString('::ask(', $body);
    }

    // ══ working it out ═══════════════════════════════════════════════════════

    /** A model's answers are kept, keyed so every spelling of the name finds them. */
    public function test_what_the_model_says_is_kept_and_found_again(): void
    {
        $n = NameSays::learn(['Ngozi Eze', 'Chidinma Okonkwo'],
            $this->ai('{"Ngozi":"en-GOH-zee","Chidinma":"chee-DEEN-mah"}'));

        $this->assertSame(2, $n);
        $this->assertSame('en-GOH-zee', NameSays::known(DoorWelcome::fold('Ngozi')));

        // Found however it was typed, because the key is folded.
        $this->assertSame('chee-DEEN-mah', NameSays::known(DoorWelcome::fold('CHIDINMA')));
    }

    /** A name a person has already answered is never asked about. */
    public function test_a_name_a_person_answered_is_not_asked_about(): void
    {
        $this->hand('Ngozi = A-PERSON-said-this');

        $this->assertSame([], NameSays::unanswered(['Ngozi Eze']),
            'the platform is spending a call to produce an answer that loses');
    }

    /** Nor is one already worked out — asked once per name, ever. */
    public function test_a_name_already_worked_out_is_not_asked_again(): void
    {
        NameSays::remember('Ngozi', 'en-GOH-zee', 'ai');

        $this->assertSame([], NameSays::unanswered(['Ngozi Eze']));
    }

    /**
     * With no model at all, the rule is kept instead — so a deployment that has bought
     * nothing still greets people properly.
     */
    public function test_with_no_model_the_rule_is_kept_instead(): void
    {
        $n = NameSays::learn(['Chidinma Okonkwo'], $this->ai(null));

        $this->assertSame(1, $n);
        $this->assertSame('Chee-deen-mah', NameSays::known(DoorWelcome::fold('Chidinma')));
    }

    /** A name neither the model nor the rule can answer is left with no row at all. */
    public function test_a_name_nobody_can_answer_is_left_open_rather_than_guessed(): void
    {
        NameSays::learn(['Grace Johnson'], $this->ai('{}'));

        $this->assertNull(NameSays::known(DoorWelcome::fold('Grace')),
            'a wrong row is permanent; an empty one is asked again next run');
    }

    /** An existing answer is not overwritten by a later run that changed its mind. */
    public function test_an_answer_already_given_is_not_quietly_replaced(): void
    {
        NameSays::remember('Ngozi', 'first-answer', 'ai');
        NameSays::remember('Ngozi', 'second-answer', 'ai');

        $this->assertSame('first-answer', NameSays::known(DoorWelcome::fold('Ngozi')));
        $this->assertTrue(NameSays::remember('Ngozi', 'third-answer', 'hand', true),
            'a caller that explicitly replaces cannot');
        $this->assertSame('third-answer', NameSays::known(DoorWelcome::fold('Ngozi')));
    }

    // ══ the boundary around a model's answer ═════════════════════════════════

    /**
     * Everything that comes back is read out loud at somebody's door, so it is refused
     * unless it looks like a pronunciation.
     */
    public function test_an_answer_that_is_not_a_pronunciation_is_refused(): void
    {
        foreach ([
            'I am sorry, I cannot help with that request as it may be inappropriate.',
            '<speak>oh no</speak>',
            'Ngozi is pronounced roughly like en-GOH-zee in Igbo, though it varies',
            'en-GOH-zee (Igbo)',
            'name: en-GOH-zee',
            '12345',
            'a',
            '',
        ] as $bad) {
            $this->assertSame('', NameSays::tidy($bad), 'this reached a hall: ' . $bad);
        }
    }

    /** And a real respelling survives it untouched. */
    public function test_a_real_respelling_passes(): void
    {
        foreach (['en-GOH-zee', 'chee-DEEN-mah', 'Ah-dah-eh-zeh', "N'na-eh-meh-kah"] as $good) {
            $this->assertSame($good, NameSays::tidy($good));
        }
    }

    /** A model that answers with junk leaves the rule to carry the name. */
    public function test_a_junk_answer_falls_through_to_the_rule(): void
    {
        NameSays::learn(['Chidinma Okonkwo'],
            $this->ai('{"Chidinma":"I cannot pronounce that, sorry."}'));

        $this->assertSame('Chee-deen-mah', NameSays::known(DoorWelcome::fold('Chidinma')),
            'a refusal was stored as a pronunciation and read out at a door');
    }

    /** A reply that is not JSON at all is survived rather than thrown. */
    public function test_a_reply_that_is_not_json_is_survived(): void
    {
        $n = NameSays::learn(['Chidinma Okonkwo'], $this->ai('Sure! Here you go:'));

        $this->assertSame(1, $n, 'the rule did not carry the name after a bad reply');
        $this->assertSame('Chee-deen-mah', NameSays::known(DoorWelcome::fold('Chidinma')));
    }

    // ══ through the one door, carrying as little as possible ═════════════════

    /**
     * It goes through the gateway, which is the only door to a provider here.
     *
     * A direct `AiService::complete()` is an unlogged, unbudgeted call: invisible in the
     * spend report, uncounted by the daily ceiling, and unaffected by the switch that stops
     * one misbehaving capability. This is asserted in the source because the failure is
     * silent — everything works, and the accounting is simply wrong.
     */
    public function test_the_model_is_reached_through_the_gateway(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/NameSays.php');
        $src = (string) preg_replace(['~/\*\*.*?\*/~s', '~^\s*//.*$~m'], '', $src);

        $this->assertStringContainsString('(new AiGateway($ai))->run(\'door.name_pronounce\'', $src,
            'the model is called directly, so this call is unlogged and unbudgeted');
        $this->assertStringNotContainsString('$ai->complete(', $src);
    }

    /**
     * OpenAI leads THIS job, and the free-tier providers stand behind it.
     *
     * The pin is a choice about the work rather than about the platform: whether Ngozi is
     * Igbo, and where the stress falls, is a knowledge question, and the answer is KEPT
     * and then read aloud to somebody at their own door. So an operator moving the
     * platform's primary provider — which they can, from Settings — must not silently move
     * this one with it. {@see AiModelDelegationTest} asserts that across every choice.
     *
     * What is asserted HERE, beside the reason, is the other half: the ladder behind the
     * pin is free-tier, so a deployment with no OpenAI key still gets names worked out.
     * Nothing about this feature may become a reason the greeting stops working — and
     * under all of it, {@see DoorWelcome::suggest()} still respells the name with no
     * provider at all.
     */
    public function test_the_name_job_leads_with_openai_and_still_works_without_it(): void
    {
        $cap = \AfricaGates\Services\AiCapability::find('door.name_pronounce');

        $this->assertSame('openai', $cap->provider(),
            'a merely fluent model produces a confident respelling of a name it does not '
            . 'know, and this answer is kept and then said out loud');
        $this->assertNotSame('', $cap->modelId(), 'the pin names no model');

        $behind = array_map(
            static fn (string $hop): string => explode(':', $hop, 2)[0],
            $cap->fallbacks
        );
        $this->assertNotSame([], $behind, 'no OpenAI key would mean no names worked out');
        foreach ($behind as $provider) {
            $this->assertContains($provider, ['gemini', 'groq'],
                "a deployment with no OpenAI key falls to {$provider}, which needs a key "
                . 'it may not have either');
        }

        $this->assertSame(\AfricaGates\Services\AiCapability::FAIL_DEGRADE, $cap->onFailure,
            'the rule answers instead, so there is nothing for an operator to be told and '
            . 'nothing for them to do');
    }

    /** The capability is declared, and declared as what it actually is. */
    public function test_the_capability_says_what_it_sends_and_fences_it(): void
    {
        $cap = \AfricaGates\Services\AiCapability::find('door.name_pronounce');

        $this->assertNotNull($cap, 'the capability is not declared, so the gateway refuses it');
        $this->assertTrue($cap->untrustedInput,
            'guest names come off a public booking form and must be fenced as data');
        $this->assertTrue($cap->advisory,
            'nothing this returns may decide anything on its own');
        $this->assertFalse($cap->minimise,
            'the minimiser rewrites things that look like contact details, which is a good '
            . 'way to corrupt a name — and only first names are sent, so it has no work to do');
    }

    /**
     * ONLY FIRST NAMES LEAVE THE BUILDING.
     *
     * The guest list is the thing this platform is most careful with — the door's own
     * promise is that it "cannot show you the guest list" — so a feature that posts it to a
     * third party would undo that in one line. Surnames, emails, ticket codes and which
     * event anybody is coming to all stay here.
     */
    public function test_only_first_names_are_sent(): void
    {
        $ai = $this->ai('{"Ngozi":"en-GOH-zee"}');

        // A full name, exactly as a booking form hands it over.
        NameSays::learn(['Ngozi Eze'], $ai);

        $wire = implode("\n", $ai->sent);

        $this->assertStringContainsString('Ngozi', $wire, 'nothing was sent at all');
        foreach (['Eze', '@'] as $private) {
            $this->assertStringNotContainsString($private, $wire,
                'this left the building with the guest list: ' . $private);
        }
    }

    // ══ it is asked ahead of time, in the sweep ══════════════════════════════

    /**
     * §the whole design is the word "ahead". The learning happens BEFORE the queue of lines
     * is built, because the pronunciation is baked into the line and the line is the cache
     * key — learning afterwards would render every clip with the old reading and then
     * orphan all of them.
     */
    public function test_the_sweep_works_names_out_before_it_builds_the_lines(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DoorWelcome.php');
        $src = (string) preg_replace(['~/\*\*.*?\*/~s', '~^\s*//.*$~m'], '', $src);

        $at    = strpos($src, 'public static function sweep(');
        $learn = strpos($src, 'NameSays::learn(', (int) $at);
        $build = strpos($src, '$queue = [self::genericLine()];', (int) $at);

        $this->assertNotFalse($learn, 'the sweep never works any name out');
        $this->assertNotFalse($build);
        $this->assertLessThan($build, $learn,
            'the names are worked out after the lines are built, so every clip is rendered '
            . 'with the old reading and then orphaned by the new one');
    }
}
