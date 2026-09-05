<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\RegistryController;
use AfricaGates\Services\{CacheService, ProfileService, RateLimitService, RuleEngine};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * THE PAGE DESCRIBED A CALCULATION THAT HAD NOT RUN.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS PUBLISHED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A person's public profile prints `cpi_score` under the heading "Cultural Power Index",
 * with a gold-starred tier badge beside their name, over two bars reading "Community vote
 * 45%" and "Independent jury panel 55%", above a sentence saying the score "recomputes
 * every 6 hours from organic verified votes (45%) and the independent judge panel (55%)".
 *
 * For most of the registry, none of that produced the number. A profile with no judged
 * nomination falls to `CpiService::baselineScore()` — 50% verification tier, 30% profile
 * completeness, 20% page views — and takes a tier off the same ladder. So a person who
 * filled in their profile and collected views was published as GOLD, under a paragraph
 * crediting a jury panel that had never seen them, on a platform whose entire promise is
 * that an award here is judged rather than bought or clicked for.
 *
 * The figure was never wrong for what it is. The page was wrong about what it is.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE SPLIT WAS TYPED, NOT READ
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 45 and 55 were literals, in three places in one card. `RuleEngine` makes the weighting
 * configurable per programme and per cycle — so an organiser who moved it left the page
 * telling every nominee a split their own award was not using, and nothing anywhere would
 * have disagreed.
 */
final class ProfileCpiClaimTest extends TestCase
{
    private function render(string $basis, int $score = 640): string
    {
        DB::table('gates_profiles')->insert([
            'slug' => 'ada-obi', 'display_name' => 'Ada Obi', 'email' => 'ada@example.test',
            'country_code' => 'NG', 'status' => 'approved', 'category' => 'Music',
            'verification_tier' => 'verified', 'completeness_pct' => 80, 'view_count' => 120,
            'cpi_score' => $score, 'cpi_tier' => 'gold', 'cpi_basis' => $basis,
            'cpi_last_computed' => '2026-09-01 06:00:00',
        ]);

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $b->build();

        $ctrl = new RegistryController($c->get(Twig::class), new CacheService(),
                                       new ProfileService(), new RateLimitService());
        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/registry/ada-obi');

        return (string) $ctrl->profile($req, new Response(), ['slug' => 'ada-obi'])->getBody();
    }

    /** Whitespace collapsed: the sentences under test wrap, and a wrap is not a difference. */
    private static function flat(string $html): string
    {
        return (string) preg_replace('~\s+~', ' ', $html);
    }

    // ══ the claim ════════════════════════════════════════════════════════════

    public function test_a_judged_profile_is_described_as_judged(): void
    {
        $html = self::flat($this->render('judged'));

        $this->assertStringContainsString('Independent jury panel', $html);
        $this->assertStringContainsString('independent judge panel', $html);
        $this->assertStringNotContainsString('not an award score', $html,
            'a judged profile was disclaimed as though no panel had scored it');
    }

    /**
     * The one that shipped wrong, and the reason this file exists.
     *
     * A profile nobody has nominated must not be credited to a jury. Asserted as the
     * ABSENCE of the panel language rather than the presence of a disclaimer, because a
     * page can carry both and the harm is the claim, not the missing caveat.
     */
    public function test_an_unnominated_profile_is_not_credited_to_a_jury(): void
    {
        $html = self::flat($this->render('baseline'));

        $this->assertStringNotContainsString('Independent jury panel', $html,
            'a profile no judge has seen was published under a jury panel heading');
        $this->assertStringNotContainsString('independent judge panel', $html,
            'the sentence still credits a panel that never scored this person');
        $this->assertStringNotContainsString('organic verified votes', $html,
            'the sentence still credits votes that were never counted for this figure');

        $this->assertStringContainsString('not an award score', $html,
            'nothing on the page says the number is measuring something else');
        $this->assertStringContainsString('verification, how complete it is', $html,
            'the page does not say what the number actually is');
    }

    /**
     * Nominated-but-unjudged is its OWN answer, and that distinction is for the person
     * reading their own page: "nobody has finished judging you" is not "you were never
     * put forward", and a page that says the second to somebody in the first case is
     * telling them their nomination did not happen.
     */
    public function test_a_pending_nomination_is_told_apart_from_never_having_one(): void
    {
        $pending  = self::flat($this->render('pending'));

        $this->assertStringContainsString('has been nominated and the judging is not finished',
            $pending, 'a nominee waiting on a panel is told they were never nominated');
        $this->assertStringNotContainsString('Independent jury panel', $pending,
            'a panel that has not finished was credited with the figure anyway');
    }

    // ══ the split ════════════════════════════════════════════════════════════

    /**
     * The weights come from the rule engine. Set to 30/70 and the page must say 30/70 —
     * a card that keeps saying 45/55 is stating a split this award is not using.
     */
    public function test_the_split_is_read_from_the_rules_and_not_typed_into_the_page(): void
    {
        (new RuleEngine())->set('global', null,
            ['community_weight' => 0.30, 'judge_weight' => 0.70]);

        $html = self::flat($this->render('judged'));

        $this->assertStringContainsString('30%', $html,
            'the page states a community weight the rule engine does not hold');
        $this->assertStringContainsString('70%', $html);
        $this->assertStringNotContainsString('45%', $html,
            'the old split is still written into the page');
    }

    /** And with nothing overridden it states the platform default, rather than nothing. */
    public function test_with_no_override_the_page_states_the_default_split(): void
    {
        $html = self::flat($this->render('judged'));

        $this->assertStringContainsString('45%', $html);
        $this->assertStringContainsString('55%', $html);
    }
}
