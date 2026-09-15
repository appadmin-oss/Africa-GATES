<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\DonationController;
use AfricaGates\Services\PaymentService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * THE FUND HAD NO TARGET, AND A RAISED FIGURE ALONE IS NOT AN ASK.
 *
 * A partner's appeal on this same template has had a goal and a progress bar since it
 * shipped. The Africa GATES fund had "₦2,400,000 raised" and nothing to measure it
 * against — a fact with no shape. A stranger cannot tell from it whether the fund is
 * nearly there or barely started, and a number that cannot be read as progress does not
 * read as an ask.
 *
 * Two things this file exists to hold. First, that the target is OPTIONAL and unset by
 * default: an invented target is worse than none, so a deployment that has not decided what
 * it is raising for keeps exactly the page it had. Second, that the masthead does not say
 * the same number twice — adding the bar put the raised figure forty pixels above a tile
 * printing it again, which is the sort of thing that ships because each half was written
 * separately and neither was wrong.
 */
final class DonationGoalTest extends TestCase
{
    private function render(): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $b->build();

        $ctrl = new DonationController($c->get(PaymentService::class), $c->get(Twig::class));
        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/donate');

        return (string) $ctrl->page($req, new Response())->getBody();
    }

    /** Whitespace collapsed: the sentences under test wrap, and a wrap is not a difference. */
    private static function flat(string $html): string
    {
        // The stylesheet names every class this file looks for, so it goes first — a scan
        // that reads it reports the CSS as the markup. This page has 370 lines of it.
        $html = (string) preg_replace('~<style.*?</style>~s', ' ', $html);

        return (string) preg_replace('~\s+~', ' ', $html);
    }

    private function goal(int $naira): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'donation_goal_naira'], ['value' => (string) $naira]);
    }

    private function gift(string $name, int $naira, int $minutesAgo = 5): void
    {
        static $n = 0;
        DB::table('gates_donations')->insert([
            'donor_name' => $name, 'donor_email' => 'g' . (++$n) . '@example.test',
            'amount_naira' => $naira, 'status' => 'confirmed', 'provider' => 'paystack',
            'payment_ref' => 'REF' . $n,
            'created_at' => Carbon::now()->subMinutes($minutesAgo)->toDateTimeString(),
        ]);
    }

    // ══ the target ═══════════════════════════════════════════════════════════

    /** With no target set the page is exactly what it was — no bar, and the raised tile. */
    public function test_with_no_target_set_the_page_is_unchanged(): void
    {
        $this->gift('Amara Okonkwo', 250000);

        $html = self::flat($this->render());

        $this->assertStringNotContainsString('dn-goal__bar', $html,
            'a bar was drawn against a target nobody chose');
        $this->assertStringContainsString('Raised in community donations', $html,
            'the raised figure disappeared on a page with no target to carry it');
    }

    public function test_a_target_draws_a_bar_and_the_distance_left(): void
    {
        $this->goal(1000000);
        $this->gift('Amara Okonkwo', 250000);

        $html = self::flat($this->render());

        $this->assertStringContainsString('width:25%', $html,
            '250,000 of 1,000,000 is a quarter, and the bar said otherwise');
        $this->assertStringContainsString('₦750,000', $html,
            'the distance left is the half of this that moves somebody');
    }

    /**
     * THE MASTHEAD MUST NOT SAY THE SAME NUMBER TWICE.
     *
     * The target line carries the raised figure. The tile below it printed the same figure
     * again, forty pixels down, because each half was written separately and neither was
     * wrong on its own.
     */
    public function test_the_raised_figure_is_stated_once(): void
    {
        $this->goal(1000000);
        $this->gift('Amara Okonkwo', 250000);

        // VISIBLE text, tags stripped. The bar carries the same figure in its aria-label,
        // correctly — the sighted reader gets it from the line beside the bar, and a screen
        // reader gets nothing from a bar without it. Counting raw HTML would call that
        // duplication and push somebody to delete the accessible name.
        $seen = (string) preg_replace('~\s+~', ' ', strip_tags(self::flat($this->render())));

        $this->assertSame(1, substr_count($seen, '₦250,000'),
            'the masthead prints the raised figure twice');

        $html = self::flat($this->render());
        $this->assertStringNotContainsString('Raised in community donations', $html,
            'the tile duplicating the target line is still drawn');
        // The count is the half the bar cannot carry, so it stays.
        $this->assertStringContainsString('Donation', $html);
    }

    /**
     * Passing the target is good news and the page says so — but the BAR is capped.
     *
     * A fill running past its own track reads as a rendering fault rather than as success.
     */
    public function test_passing_the_target_is_reported_and_the_bar_does_not_overrun(): void
    {
        $this->goal(100000);
        $this->gift('Amara Okonkwo', 250000);

        $html = self::flat($this->render());

        $this->assertStringContainsString('width:100%', $html);
        $this->assertStringNotContainsString('width:250%', $html,
            'the bar ran past its own track');
        $this->assertStringContainsString('target reached, and still open', $html,
            'the fund hit its target and the page did not say so — or worse, closed itself');
        // The true figure is still printed. Capping the bar must not cap the number.
        $this->assertStringContainsString('₦250,000', $html);
    }

    /** A target with nothing raised is still an ask, and must not divide by anything odd. */
    public function test_a_target_with_nothing_raised_does_not_break(): void
    {
        $this->goal(1000000);

        $html = self::flat($this->render());

        $this->assertStringContainsString('width:0%', $html);
        $this->assertStringContainsString('₦1,000,000', $html);
        // And the page's own rule still holds: with no gifts it says so rather than
        // printing a nil return beside an ask.
        $this->assertStringContainsString('Be among the first', $html);
    }

    // ══ the ledger ═══════════════════════════════════════════════════════════

    /**
     * WHEN each gift arrived, which was collected and rendered nowhere.
     *
     * The timestamp has been selected, carried and handed to the template since this ledger
     * shipped, and the template printed a name and an amount. A page whose whole job is to
     * show that other people are giving could not say whether the last one came this morning
     * or last year — the difference between a live appeal and an archive.
     */
    public function test_the_ledger_says_when_each_gift_arrived(): void
    {
        $this->gift('Amara Okonkwo', 250000, 2);
        $this->gift('Chinedu Balogun', 50000, 90);
        $this->gift('Fatima Sule', 15000, 400);

        $html = self::flat($this->render());

        $this->assertStringContainsString('2 minutes ago', $html);
        $this->assertStringContainsString('1 hour ago', $html);
        $this->assertStringContainsString('6 hours ago', $html);
    }

    /**
     * ORDERED BY TIME, NOT BY INSERTION.
     *
     * It ordered by id alone, which only coincides with chronology while nothing is ever
     * written late — a reconciled gift, a webhook after a retry, a backfill. The mismatch
     * was invisible while the ledger printed no dates. The moment it started printing them,
     * "Recent donations" could show an April gift above a two-minute-old one.
     */
    public function test_the_ledger_is_ordered_by_when_the_gift_arrived(): void
    {
        // Inserted newest-first, so insertion order is the REVERSE of chronological and an
        // id sort would put the oldest at the top.
        $this->gift('Newest Giver', 111000, 1);
        $this->gift('Middle Giver', 222000, 600);
        $this->gift('Oldest Giver', 333000, 90000);

        $html = self::flat($this->render());

        $first  = strpos($html, 'Newest G.');
        $second = strpos($html, 'Middle G.');
        $third  = strpos($html, 'Oldest G.');

        $this->assertIsInt($first);
        $this->assertIsInt($second);
        $this->assertIsInt($third);
        $this->assertLessThan($second, $first, 'the newest gift is not at the top');
        $this->assertLessThan($third, $second, 'the ledger is not in time order');
    }

    // ══ passing it on ════════════════════════════════════════════════════════

    /**
     * The one thing a fundraising page cannot do for itself.
     *
     * Rendered through the shared partial, so the destinations, the copy and the
     * no-JavaScript fallback cannot drift apart between a ballot and an appeal.
     */
    public function test_the_page_asks_to_be_shared(): void
    {
        $this->gift('Amara Okonkwo', 250000);

        $html = self::flat($this->render());

        $this->assertStringContainsString('Share the fund', $html);
        $this->assertStringContainsString('ag-share', $html,
            'the share row is not the shared partial, so it will drift');
        // And it is placed after the case has been made, never before it: asking a favour
        // of somebody who has not been given a reason is how a share row gets ignored.
        $this->assertGreaterThan(strpos($html, 'Where donations go'),
                                 strpos($html, 'Share the fund'),
            'the page asks to be shared before it says what it is for');
    }
}
