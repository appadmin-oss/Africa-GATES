<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ReferralService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * "You could earn from this", on the page where somebody is about to share it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GAP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The referral programme worked and nobody knew. A member had to already know it existed,
 * sign in, and find the panel on their account page — so the feature was reachable only by
 * people who did not need to be told about it. The event page is the one place somebody is
 * already thinking about telling a friend, and it said nothing.
 *
 * The two properties that matter here are honesty ones:
 *
 *   · The rate and threshold are printed from LIVE values. A page promising 10% after an
 *     admin changed it to 8% is a promise the ledger will not honour.
 *   · The offer is not shown where it cannot be kept — referrals off, off for this event,
 *     or a past event nobody can buy a ticket to.
 */
final class EventReferralPromptTest extends TestCase
{
    private int $eventId = 0;
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->slug = 'market-' . bin2hex(random_bytes(4));
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Africa GATES Gala', 'slug' => $this->slug,
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'published',
        ]);

        // updateOrInsert, not insert: the base schema already seeds some of these keys and
        // `key_name` is UNIQUE, so an insert fails on whichever one it already has.
        foreach (['referrals_enabled' => '1', 'referral_rate_bps' => '1000',
                  'referral_threshold' => '10'] as $k => $v) {
            $this->setting($k, $v);
        }
    }

    private function setting(string $key, string $value): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $key], ['value' => $value]);
    }

    private function render(?int $userId = null): string
    {
        if ($userId !== null) $_SESSION['user_id'] = $userId;
        else                  unset($_SESSION['user_id']);

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        $res = $b->build()->get(\AfricaGates\Controllers\EventsController::class)->show(
            (new ServerRequestFactory())->createServerRequest('GET', '/events/' . $this->slug),
            (new ResponseFactory())->createResponse(),
            ['slug' => $this->slug]
        );

        $this->assertSame(200, $res->getStatusCode(), 'the event page did not render');
        return (string) $res->getBody();
    }

    /**
     * The page's VISIBLE text, with CSS removed.
     *
     * The honesty assertions below search for a rate like "10%" and must not find one the
     * ledger will not pay. Written against the raw body they searched 109KB of HTML
     * including the inline stylesheet — and a CSS keyframe stop is spelled `10%`, as is a
     * `width:10%` on a progress bar. The tier-selection effect added
     * `@keyframes edLapA{ … 10%{opacity:1} … }` and this failed instantly, on a page that
     * was promising 8% correctly everywhere a reader could see it.
     *
     * So the guard keeps its full strength — any visible mention of a stale rate anywhere on
     * the page still fails it — and stops reading percentages out of stylesheets.
     */
    private function visible(string $html): string
    {
        $html = (string) preg_replace('~<style\b[^>]*>.*?</style>~si', '', $html);
        $html = (string) preg_replace('~<script\b[^>]*>.*?</script>~si', '', $html);
        // Inline `style` attributes too: the sold-progress bar renders `width:{{ pct }}%`,
        // so an event that happens to be 10% full would have failed this the same way.
        return (string) preg_replace('~\sstyle="[^"]*"~i', '', $html);
    }

    private function member(string $email = 'sharer@example.com'): int
    {
        return (int) DB::table('gates_users')->insertGetId([
            'name' => 'Sharer', 'email' => $email,
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
            'status' => 'active',
        ]);
    }

    // ══ signed out ═══════════════════════════════════════════════════════════

    /** The offer is shown to everybody; the LINK needs an owner, so that is the next step. */
    public function test_a_visitor_who_is_not_signed_in_sees_the_offer_and_a_way_to_get_a_link(): void
    {
        $html = $this->render(null);

        $this->assertStringContainsString('Earn from sharing', $html);
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('Sign in to get your link', $html);
        // The ATTRIBUTE, not the bare string: the copy script names it in a querySelector,
        // so matching the name alone would pass whether or not any element carried it.
        $this->assertStringNotContainsString('data-rf-copy="edRefLink"', $html,
            'there is no link to copy until somebody owns one');
    }

    /** Sign-in must come back to the event, not dump them on an account page. */
    public function test_the_sign_in_link_returns_to_this_event(): void
    {
        $this->assertStringContainsString('/account/login?next=/events/' . $this->slug, $this->render(null));
    }

    // ══ signed in ════════════════════════════════════════════════════════════

    public function test_a_signed_in_member_gets_their_own_link_for_this_event(): void
    {
        $uid  = $this->member();
        $html = $this->render($uid);

        $code = ReferralService::codeFor($uid);
        $this->assertNotNull($code);

        $this->assertStringContainsString('ref=' . $code, $html, 'the link must carry their code');
        $this->assertStringContainsString('/events/' . $this->slug, $html,
            'and land on THIS event, not the events index');
        $this->assertStringContainsString('data-rf-copy="edRefLink"', $html);
    }

    /**
     * The field is a real input before it is a copy button, so a browser that refuses the
     * clipboard API still leaves the link there to select. The link is the feature.
     */
    public function test_the_link_is_selectable_even_with_no_javascript(): void
    {
        $html = $this->render($this->member());

        $this->assertMatchesRegularExpression(
            '~<input[^>]*id="edRefLink"[^>]*readonly[^>]*value="[^"]*ref=[A-Z0-9]+~i', $html,
            'the link has to be in the markup, not written in by a script'
        );
    }

    // ══ the live numbers ═════════════════════════════════════════════════════

    /** THE HONESTY ONE. Change the rate in admin and the page changes with it. */
    public function test_the_rate_is_read_live_rather_than_written_into_the_copy(): void
    {
        $uid = $this->member();
        $this->assertStringContainsString('10%', $this->render($uid));

        $this->setting('referral_rate_bps', '800');

        $html = $this->visible($this->render($uid));
        $this->assertStringContainsString('8%', $html);
        $this->assertStringNotContainsString('10%', $html,
            'a page promising a rate the ledger will not pay is worse than no page');
    }

    public function test_the_threshold_is_read_live_and_explains_the_backdating(): void
    {
        $uid  = $this->member();
        $html = $this->render($uid);

        $this->assertStringContainsString('10', $html);
        $this->assertStringContainsString('backdated', $html,
            'the retroactive rule is the difference between "ten before anything" and '
            . '"ten unlocks all ten" — and it is the generous reading');

        $this->setting('referral_threshold', '1');
        $this->assertStringContainsString('from your first referral', $this->render($uid));
    }

    // ══ where it must NOT appear ═════════════════════════════════════════════

    public function test_nothing_is_offered_when_referrals_are_switched_off(): void
    {
        $this->setting('referrals_enabled', '0');

        $this->assertStringNotContainsString('Earn from sharing', $this->render($this->member()));
    }

    /** Per-event disabling exists so an event can opt out; the page has to honour it. */
    public function test_nothing_is_offered_on_an_event_with_referrals_disabled(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['referrals_enabled' => 0]);

        $this->assertFalse(ReferralService::enabledForEvent($this->eventId));
        $this->assertStringNotContainsString('Earn from sharing', $this->render($this->member()));
    }

    /** Nobody can buy a ticket to a past event, so there is nothing to earn from. */
    public function test_nothing_is_offered_on_a_past_event(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['event_date' => date('Y-m-d H:i:s', strtotime('-10 days'))]);

        $this->assertStringNotContainsString('Earn from sharing', $this->render($this->member()));
    }

    // ══ it must not shout over the ticket ════════════════════════════════════

    /**
     * A supporter who came to buy a ticket and left having read about commission instead is
     * a worse outcome than one who never saw this. The prompt therefore lives in the
     * sidebar under Share, not in the flow above the tiers.
     */
    public function test_the_prompt_sits_after_the_share_block_and_not_above_the_tickets(): void
    {
        $html = $this->render($this->member());

        $share = strpos($html, 'Share &amp; save the date');
        $earn  = strpos($html, 'Earn from sharing');

        $this->assertNotFalse($share);
        $this->assertNotFalse($earn);
        $this->assertLessThan($earn, $share, 'the referral offer must not lead the sidebar');
    }
}
