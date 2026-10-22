<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\NominationCategoryFit;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The category-fit analysis is for the desk and the panel, and reaches no visitor.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE, AND WHY IT IS A SWEEP RATHER THAN AN INTENTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Design handoff §8.16, in as many words: *"No AI wording. Category-fit analysis runs
 * server-side for admins and judges only."*
 *
 * The reason is not squeamishness about the word. A nominator who is told a machine
 * scored their reason at 41 is being graded by a form, and the person that discourages
 * first is the one writing in their second language about somebody they admire. The
 * analysis exists so a panel opens a nomination in the right race; it has no business
 * on the page where somebody is still typing.
 *
 * "We will remember not to print it" is not a mechanism. So: every public template is
 * swept for a reader of the fit table or of the words that would give it away, and the
 * capability itself declares `public_content: false`.
 */
final class NominationCategoryFitTest extends TestCase
{
    private const NOM   = 5110;
    private const CYCLE = 5100;
    private const CAT_A = 51001;
    private const CAT_B = 51002;

    protected function setUp(): void
    {
        parent::setUp();

        $prog = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'fit-prog', 'title' => 'Fit Awards', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => $prog, 'year' => 2026, 'status' => 'nominations',
        ]);
        DB::table('gates_award_categories')->insert([
            ['id' => self::CAT_A, 'cycle_id' => self::CYCLE, 'slug' => 'f-a', 'title' => 'Teaching'],
            ['id' => self::CAT_B, 'cycle_id' => self::CYCLE, 'slug' => 'f-b', 'title' => 'Leadership'],
        ]);
        DB::table('gates_nominations')->insert([
            'id' => self::NOM, 'cycle_id' => self::CYCLE, 'nominee_name' => 'Ada Lovelace',
            'nominator_name' => 'Grace Hopper', 'nominator_email' => 'g@example.com',
            'status' => 'pending', 'reason' => 'A reason long enough to be a real one for this fixture.',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Nothing public may read it
    // ══════════════════════════════════════════════════════════════════════════

    public function test_no_public_template_reads_the_fit(): void
    {
        $root = realpath(__DIR__ . '/../../templates');
        $bad  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');

            // The admin console and the judges' console are exactly who this is for.
            if (str_starts_with($rel, 'pages/admin/') || str_starts_with($rel, 'admin/')
                || str_starts_with($rel, 'pages/judge')) {
                continue;
            }

            // A Twig comment reaches nobody, so a mention in one is not a leak.
            $body = (string) preg_replace('/\{#.*?#\}/s', '',
                (string) file_get_contents($f->getPathname()));

            foreach (['category_fit', 'ai_fit', 'nomination_fit'] as $needle) {
                if (str_contains($body, $needle)) $bad[] = "$rel reads $needle";
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_the_capability_is_disclosed_and_fenced(): void
    {
        $cap = AiCapability::all()[NominationCategoryFit::CAPABILITY] ?? null;

        $this->assertNotNull($cap, 'the capability is not declared, so the gateway will refuse every call');
        // TRUE: the flag means "processes content submitted by the public, and so
        // belongs in the published privacy disclosure". `AiPrivacy::disclosure()`
        // filters on it, so `false` would leave a capability that sends a visitor's own
        // words to a third party out of the page that lists exactly that. It was
        // written `false` first, read as "not shown publicly" — which is a different
        // claim, held by the template sweep above.
        $this->assertTrue($cap->publicContent,
            'the privacy page is generated from this flag, and this capability sends the '
            . 'nominator\'s own text to a provider');
        $this->assertTrue($cap->untrustedInput,
            'every word of the input was typed by a stranger — the fence is not optional');
        $this->assertTrue($cap->advisory,
            'a fit ranks a queue; a panel scores a nominee');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // What the desk reads
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_desk_sees_the_best_fit_first_with_the_nominator_s_own_choice_marked(): void
    {
        DB::table('gates_nomination_category_fit')->insert([
            ['nomination_id' => self::NOM, 'category_id' => self::CAT_A, 'fit' => 41, 'chosen' => 1, 'note' => 'some teaching'],
            ['nomination_id' => self::NOM, 'category_id' => self::CAT_B, 'fit' => 88, 'chosen' => 0, 'note' => 'mostly leadership'],
        ]);

        $rows = NominationCategoryFit::forNomination(self::NOM);

        // The first question a reader can answer is "did they file this in the right
        // place?" — so the best fit is first, and their own choice is labelled.
        $this->assertSame(self::CAT_B, $rows[0]['category_id']);
        $this->assertSame(88, $rows[0]['fit']);
        $this->assertFalse($rows[0]['chosen'], 'the top fit here is one the nominator did NOT choose');
        $this->assertTrue($rows[1]['chosen']);
        $this->assertSame('Teaching', $rows[1]['title']);
    }

    public function test_a_category_removed_since_the_analysis_is_named_rather_than_blank(): void
    {
        DB::table('gates_nomination_category_fit')->insert([
            'nomination_id' => self::NOM, 'category_id' => 987654, 'fit' => 50, 'chosen' => 0,
        ]);

        $rows = NominationCategoryFit::forNomination(self::NOM);

        // An empty cell on a review desk reads as a missing score, which is a different
        // fact from "that category is gone".
        $this->assertStringContainsString('removed', $rows[0]['title']);
    }

    public function test_nothing_is_written_when_the_nomination_has_no_categories(): void
    {
        // No provider is configured in the suite, so this also pins the other half: a
        // deployment with no AI produces no rows and no error.
        $this->assertSame(0, NominationCategoryFit::generate(self::NOM));
        $this->assertSame(0, (int) DB::table('gates_nomination_category_fit')
            ->where('nomination_id', self::NOM)->count());
    }

    public function test_an_unknown_nomination_is_not_an_error(): void
    {
        $this->assertSame(0, NominationCategoryFit::generate(999_999));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The queue
    // ══════════════════════════════════════════════════════════════════════════

    public function test_both_doors_queue_it_because_the_aftercare_does(): void
    {
        // The triage was once queued from the web form only, so a nomination arriving
        // at the API sat in the table with no score, no summary and no duplicate check.
        // This is queued from the same place for the same reason — asserted on the
        // aftercare, which is the thing both doors call.
        $code = (string) file_get_contents(__DIR__ . '/../../src/Services/NominationAftercare.php');

        $this->assertStringContainsString('NominationCategoryFit::enqueue', $code);
    }
}
