<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Text;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The nomination story must survive approval, and the ballot must be able to show it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A nominator writes up to 3,000 characters explaining why somebody deserves
 * recognition. Approval did this:
 *
 *     'tagline' => mb_substr((string) $nom->reason, 0, 200)
 *
 * and `tagline` is what every public surface reads. So "Why X is nominated" showed
 * the first 200 characters, cut mid-word, with no way to read on — not because the
 * rest was collapsed behind a control, but because the nominee row never had it. The
 * full text stayed in `gates_nominations.reason`, which nothing public joins to.
 *
 * The tests below pin the three halves of the fix: the story is stored in full, the
 * summary ends at a sentence rather than a byte, and the ballot prints the story while
 * still coping with rows approved before the column existed.
 */
final class NomineeStoryTest extends TestCase
{
    private const CAT = 7700;
    private const NOM = 7700;

    private const STORY =
        'Chinelo has run the parish choir for nineteen years without a single naira of pay. '
        . 'She started with twelve people and no instruments, rehearsing in her own front room '
        . 'until they could hold four parts without a piano. When the diocese would not fund '
        . 'robes she sewed them herself, and when the bus to the regional festival fell through '
        . 'she paid for a lorry out of her teaching salary and told nobody at all about it.';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::CAT, 'title' => 'P', 'slug' => 'prog-7700']);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::CAT, 'programme_id' => self::CAT, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::CAT, 'cycle_id' => self::CAT, 'title' => 'Cat', 'slug' => 'cat-7700']);
    }

    private function nominee(array $over = []): int
    {
        DB::table('gates_nominees')->insertOrIgnore($over + [
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Chinelo Adaeze',
            'status' => 'approved', 'vote_count' => 3,
        ]);
        return self::NOM;
    }

    private function ballot(): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        return (string) $app->handle((new ServerRequestFactory())
            ->createServerRequest('GET', '/vote/prog-7700/' . self::NOM . '-chinelo-adaeze'))->getBody();
    }

    // ── the summary ──────────────────────────────────────────────────────────

    /**
     * THE REGRESSION. A summary cut at a byte count ends mid-word, and a reader cannot
     * tell whether that is a summary or a fault in the platform.
     */
    public function test_a_summary_ends_at_a_sentence_not_mid_word(): void
    {
        $short = Text::firstSentence(self::STORY, 200);

        $this->assertNotNull($short);
        $this->assertStringEndsWith('.', $short);
        $this->assertSame(
            'Chinelo has run the parish choir for nineteen years without a single naira of pay.',
            $short
        );
        $this->assertStringNotContainsString('rehearsing', $short, 'the summary ran past its first sentence');
    }

    /** An abbreviation is not a sentence end. "Dr." must not become the whole summary. */
    public function test_an_abbreviation_does_not_end_the_summary(): void
    {
        $s = Text::firstSentence(
            'Dr. Amina Bello has led the school since 2011 and rebuilt the science block with her '
            . 'own savings, which she has never once mentioned to anybody at all. She retires in June.',
            120
        );

        $this->assertNotSame('Dr.', $s);
        $this->assertStringContainsString('Amina Bello', (string) $s);
    }

    /**
     * A nominator writing one long unpunctuated paragraph is common, and "no full stop
     * found" must not mean "return the whole essay".
     */
    public function test_text_with_no_sentence_break_falls_back_to_a_marked_word_boundary(): void
    {
        $s = (string) Text::firstSentence(str_repeat('praise ', 80), 100);

        $this->assertLessThanOrEqual(101, mb_strlen($s));
        $this->assertStringEndsWith('…', $s, 'a cut with no ellipsis reads as damage rather than a summary');
        $this->assertStringEndsNotWith(' …', $s);
    }

    public function test_short_text_is_returned_untouched(): void
    {
        $this->assertSame('Short and complete.', Text::firstSentence('Short and complete.', 200));
        $this->assertNull(Text::firstSentence('   ', 200));
    }

    // ── the ballot ───────────────────────────────────────────────────────────

    /**
     * THE WHOLE POINT. The full story reaches the page — all of it, in the HTML, not
     * behind a request — so a reader without JavaScript and a crawler both get it.
     */
    public function test_the_ballot_prints_the_whole_story(): void
    {
        $this->nominee(['story' => self::STORY, 'tagline' => Text::firstSentence(self::STORY, 200)]);

        $html = $this->ballot();

        $this->assertStringContainsString('Why Chinelo is nominated', $html);
        // The LAST sentence, which is what a 200-character cut destroyed.
        $this->assertStringContainsString('told nobody at all about it', $html,
            'the ballot is still showing a truncation of the story');
        // And the control that collapses it exists, so a long story does not swamp the page.
        $this->assertStringContainsString('vn-story', $html);
        $this->assertStringContainsString('Read the full nomination', $html);
    }

    /**
     * A nominee approved before the column existed has a tagline and no story. The
     * ballot must still show what it always showed rather than an empty card — the
     * backfill cannot reach a nomination whose name is ambiguous.
     */
    public function test_a_nominee_with_no_story_falls_back_to_its_tagline(): void
    {
        $this->nominee(['story' => null, 'tagline' => 'A short line from before the story column existed.']);

        $html = $this->ballot();

        $this->assertStringContainsString('A short line from before the story column existed.', $html);
        $this->assertStringContainsString('Why Chinelo is nominated', $html);
    }

    /** No story, no tagline, no bio: the card is absent rather than empty. */
    public function test_a_nominee_with_nothing_written_gets_no_card(): void
    {
        $this->nominee(['story' => null, 'tagline' => null]);

        $this->assertStringNotContainsString('Why Chinelo is nominated', $this->ballot());
    }

    // ── the supporters page ──────────────────────────────────────────────────

    /**
     * "and 40 more" used to lead nowhere. Every one of those people ticked a box asking
     * to be named in public, so the phrase now has a page behind it.
     */
    public function test_every_named_supporter_is_reachable(): void
    {
        $this->nominee(['story' => self::STORY]);

        $names = ['Ifeoma Nwachukwu', 'Emeka Obi', 'Adaeze Umeh', 'Chidi Eze', 'Blessing Ade',
                  'Kelechi Nwosu', 'Funmi Adeyemi', 'Segun Oyelaran', 'Yusuf Lawal', 'Amara Okonkwo',
                  'Tunde Bakare', 'Ngozi Balogun'];
        foreach ($names as $i => $name) {
            DB::table('gates_votes')->insert([
                'nominee_id' => self::NOM, 'category_id' => self::CAT, 'voter_email_hash' => 'sup' . $i,
                'voter_name' => $name, 'show_name' => 1, 'weight' => 12 - $i,
                'voted_at' => '2026-08-01 10:00:00',
            ]);
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $page = (string) $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET', '/vote/prog-7700/' . self::NOM . '-chinelo-adaeze/supporters'))->getBody();

        // The ballot shows ten; this page has to show the ones it did not.
        foreach ($names as $name) {
            $this->assertStringContainsString($name, $page, $name . ' is not reachable anywhere');
        }
        $this->assertStringContainsString('12 people asked to be named', $page);

        // The ballot links here rather than dead-ending on "and N more".
        $this->assertStringContainsString('/supporters', $this->ballot());
    }

    /**
     * And the rule that governs the whole feature holds on the new page too: what each
     * person contributed decides the order and is never printed.
     */
    public function test_the_supporters_page_never_prints_what_anyone_gave(): void
    {
        $this->nominee();
        DB::table('gates_votes')->insert([
            'nominee_id' => self::NOM, 'category_id' => self::CAT, 'voter_email_hash' => 'big',
            'voter_name' => 'Adaeze Umeh', 'show_name' => 1, 'weight' => 250, 'vote_type' => 'paid',
            'voted_at' => '2026-08-01 10:00:00',
        ]);

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $page = (string) $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET', '/vote/prog-7700/' . self::NOM . '-chinelo-adaeze/supporters'))->getBody();

        $this->assertStringContainsString('Adaeze Umeh', $page);

        // Scoped to the LIST, because "250" occurs in the page's own stylesheet and a
        // whole-document search would pass or fail for reasons unrelated to the claim.
        $this->assertSame(1, preg_match('#<ul class="vsu__grid".*?</ul>#s', $page, $m),
            'the supporters list did not render');
        $list = $m[0];

        $this->assertStringContainsString('Adaeze Umeh', $list);
        $this->assertDoesNotMatchRegularExpression('/\d/', strip_tags($list),
            'the list published a number beside a supporter — what someone contributed '
            . 'decides the order and must never be printed, or a thank-you becomes a '
            . 'table of who spent most');
    }

    /** A voter who did not consent is on neither list, on either page. */
    public function test_a_voter_who_did_not_consent_is_never_listed(): void
    {
        $this->nominee();
        DB::table('gates_votes')->insert([
            'nominee_id' => self::NOM, 'category_id' => self::CAT, 'voter_email_hash' => 'priv',
            'voter_name' => 'Private Person', 'show_name' => 0, 'weight' => 40,
            'voted_at' => '2026-08-01 10:00:00',
        ]);

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $page = (string) $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET', '/vote/prog-7700/' . self::NOM . '-chinelo-adaeze/supporters'))->getBody();

        $this->assertStringNotContainsString('Private Person', $page);
        $this->assertStringNotContainsString('Private Person', $this->ballot());
    }
}
