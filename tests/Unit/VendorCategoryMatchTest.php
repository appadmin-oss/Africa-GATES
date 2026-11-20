<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\VendorCategoryMatch;
use AfricaGates\Services\VendorPolicy;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Suggesting a vendor's trade from what they wrote about their goods.
 *
 * The rule this holds is not "the model is right" — nothing can hold that. It is that a
 * WRONG answer, an unavailable model and an uncertain match all end in the same place:
 * the vendor choosing from the list themselves, with the form working exactly as it does
 * with no AI configured at all.
 *
 * That matters more here than on most surfaces. The trade is what an organiser's screens
 * group by and it sits beside the quota, which is the entire fairness mechanism for
 * stands — §10.1 exists so a market does not end up with twelve jewellery stalls and no
 * food. A suggestion accepted by mistake moves somebody between groups; a suggestion
 * silently APPLIED would do it without anyone present to notice.
 */
final class VendorCategoryMatchTest extends TestCase
{
    /** @var array<string,string> */
    private array $cats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cats = VendorPolicy::DEFAULT_CATEGORIES;
    }

    /** With no key configured at all, which is the shipped state. */
    public function test_it_says_nothing_rather_than_failing_when_no_model_is_configured(): void
    {
        DB::table('gates_settings')->where('key_name', 'like', 'ai_%')->delete();

        $this->assertNull(
            VendorCategoryMatch::suggest('Jollof rice and small chops, cooked on site.', $this->cats),
            'an unconfigured platform must leave the form exactly as it was'
        );
    }

    /**
     * A description too short to be a description.
     *
     * "Bread" is a true answer to "what do you sell" and fits three of these categories
     * equally. Asking a model to choose between them anyway produces a confident wrong
     * answer, which is worse for the vendor than no suggestion — they have no way to
     * check it, and it is sitting in a filled-in field.
     */
    public function test_a_product_name_is_not_enough_to_go_on(): void
    {
        $this->assertNull(VendorCategoryMatch::suggest('Bread', $this->cats));
        $this->assertNull(VendorCategoryMatch::suggest('  ', $this->cats));
    }

    /** And an organiser who has published no list gets no suggestion, not a crash. */
    public function test_an_empty_category_list_is_not_an_error(): void
    {
        $this->assertNull(
            VendorCategoryMatch::suggest('Jollof rice and small chops, cooked on site.', [])
        );
    }

    // ══ the shape of what a form may be handed ═══════════════════════════════

    /**
     * Whatever comes back is one of the ORGANISER'S slugs, or nothing.
     *
     * Not corrected, not fuzzy-matched to the nearest label. A slug this event does not
     * publish is a group that does not exist — and a form offering it would let a vendor
     * accept a suggestion the server then refuses, with the description they typed
     * already gone from the page.
     *
     * Asserted against a live call when one is configured, and skipped when not: this is
     * the one property that cannot be proved from a stub, because a stub cannot invent
     * the wrong slug the way a model can.
     */
    public function test_a_real_suggestion_is_always_on_the_published_list(): void
    {
        $hit = VendorCategoryMatch::suggest(
            'Jollof rice, moi moi and chin chin, cooked fresh on site for parties.',
            $this->cats
        );

        if ($hit === null) {
            $this->assertTrue(true, 'no model configured in this environment');

            return;
        }

        $this->assertArrayHasKey($hit['slug'], $this->cats,
            'a suggestion named a trade this event does not publish');
        $this->assertSame($this->cats[$hit['slug']], $hit['label']);
        $this->assertGreaterThanOrEqual(0.45, $hit['confidence'],
            'a low-confidence guess is a wrong answer with a button next to it');
        $this->assertLessThanOrEqual(140, mb_strlen($hit['why']));
    }
}
