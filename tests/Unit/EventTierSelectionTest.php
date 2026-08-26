<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\EventsController;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Choosing a ticket tier: what it says, to whom, and what happens if none of it runs.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ACCESSIBILITY FAULT THAT WAS ALREADY THERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The tier list was a row of plain `<button>`s whose only selected signal was a CSS class.
 * So to a screen reader the chosen tier was conveyed by COLOUR ALONE — WCAG 1.4.1 — and
 * there was no name, role or value for the selection at all (4.1.2): every row announced as
 * an unremarkable button, none of them as chosen, none of them as one of a set of three.
 * Somebody using a screen reader could press a tier and get no confirmation that anything
 * had happened, on the screen where the confirmation is the price they are about to pay.
 *
 * The cinematic effect handoff kept that shape and specified `aria-pressed` on top of it.
 * `aria-pressed` announces a TOGGLE — "General admission, toggle button, pressed" — which is
 * the wrong idea, and it leaves Tab visiting every tier. A single-select list of options is
 * a radio group everywhere else on the web, and users arrive knowing that: Arrow moves
 * between the options, Tab leaves the group.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE EFFECT ITSELF IS TESTED FOR ITS ABSENCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The whole design rests on one claim: that nothing is carried by the animation. If it is
 * true, `prefers-reduced-motion` can delete the entire effect layer and lose nothing, and
 * the selection stays legible in a screenshot and with CSS off. Most of the tests below
 * check that claim rather than the animation, because the animation is the part that cannot
 * break anybody's evening.
 */
final class EventTierSelectionTest extends TestCase
{
    private string $slug = '';
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug    = 'gala-' . bin2hex(random_bytes(4));
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Africa GATES Gala', 'slug' => $this->slug,
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'published', 'ticket_accent' => '#2a6fdb',
        ]);
    }

    /** @param array<string,mixed> $over */
    private function tier(string $name, int $price, array $over = []): int
    {
        // `$over +` and not `+ $over`: PHP's array union keeps the LEFT operand for a
        // duplicate key, so defaults written first silently discard every override.
        return (int) DB::table('gates_event_tiers')->insertGetId($over + [
            'event_id'   => $this->eventId,
            'slug'       => strtolower($name) . '-' . bin2hex(random_bytes(2)),
            'name'       => $name,
            'price_naira'=> $price,
            'capacity'   => 100,
            'is_active'  => 1,
            'sort_order' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
        ]);
    }

    private function render(): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(EventsController::class);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/events/' . $this->slug);
        $res = $ctrl->show($req, (new ResponseFactory())->createResponse(), ['slug' => $this->slug]);

        $this->assertSame(200, $res->getStatusCode(), 'the event page did not render');
        return (string) $res->getBody();
    }

    // ══ the accessibility floor ══════════════════════════════════════════════

    public function test_the_tier_list_is_a_named_radio_group(): void
    {
        $this->tier('General', 5000);
        $this->tier('Patron', 380000);
        $html = $this->render();

        $this->assertStringContainsString('role="radiogroup"', $html);
        // Named, or a screen reader announces "group" with nothing to say what of.
        $this->assertStringContainsString('aria-label="Ticket type"', $html);
        $this->assertSame(2, substr_count($html, 'role="radio"'), 'one radio per tier');
    }

    public function test_the_selection_is_announced_and_not_only_coloured(): void
    {
        $this->tier('General', 5000);
        $html = $this->render();

        // The binding, not a literal: the value follows `tierId`, which is the state the
        // border and the background follow too. One source, so they cannot disagree.
        $this->assertStringContainsString(':aria-checked=', $html);
        $this->assertMatchesRegularExpression('/:aria-checked="tierId === \d+ \? \'true\' : \'false\'"/', $html);
    }

    public function test_the_group_is_one_tab_stop_and_arrow_keys_move_inside_it(): void
    {
        $this->tier('General', 5000);
        $this->tier('Patron', 380000);
        $html = $this->render();

        // Roving tabindex. Without it Tab visits every tier, which on a six-tier event is
        // six stops between the name field and the pay button.
        $this->assertStringContainsString(':tabindex=', $html);
        foreach (['arrow-down', 'arrow-up', 'arrow-left', 'arrow-right', 'home', 'end'] as $key) {
            $this->assertStringContainsString('keydown.' . $key, $html, $key . ' does not move');
        }
    }

    public function test_the_first_tier_is_reachable_before_anything_is_chosen(): void
    {
        $this->tier('General', 5000);
        $html = $this->render();

        // `tierId === 0` is the initial state. With a pure roving tabindex and nothing
        // selected, every row would be tabindex="-1" and the group unreachable by keyboard
        // entirely — which is how a roving tabindex is usually got wrong.
        $this->assertStringContainsString('tierId === 0 &&', $html);
    }

    public function test_a_tier_row_clears_a_48px_target(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // A one-line row came to about 43.6px from padding and line-height alone: past
        // WCAG 2.5.8's 24px AA floor, short of 2.5.5's 44px and of Android's 48dp. This is
        // a row people press three or four times with a thumb while comparing prices.
        $this->assertMatchesRegularExpression('/\.ed-tier\{[^}]*min-height:48px/', $css);
    }

    public function test_focus_is_visible_and_survives_the_selected_state(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // An outline, not a border swap: `.is-sel` changes the border colour, so a focus
        // ring drawn as a border would be cancelled by selecting the row it is on.
        $this->assertMatchesRegularExpression('/\.ed-tier:focus-visible\{[^}]*outline:/', $css);
    }

    // ══ nothing is carried by the animation ══════════════════════════════════

    public function test_the_effect_layer_is_hidden_from_assistive_tech_and_untouchable(): void
    {
        $this->tier('General', 5000);
        $html = $this->render();

        $this->assertMatchesRegularExpression('/class="ed-fx"\s+aria-hidden="true"/', $html);

        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');
        $this->assertMatchesRegularExpression('/\.ed-fx\{[^}]*pointer-events:none/', $css);
    }

    public function test_reduced_motion_removes_the_whole_effect(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        // The claim is that nothing is lost by deleting it. If that is true this line is
        // safe; if it ever stops being true, this line is the bug.
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion: reduce\)\{[\s\S]{0,200}?\.ed-fx\{ display:none/', $css);
    }

    public function test_the_selected_state_is_static_and_needs_no_animation(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // Border, background, and a FILLED radio dot — a shape, not a colour, so the
        // selection survives greyscale and colour blindness as well as CSS being off.
        //
        // The border takes `--tier-edge`, not `--tier-hue`: it is a non-text indicator of
        // state and owes 3:1 against white (WCAG 1.4.11), and a pale fill as a 1.5px line
        // is an absence rather than a mark. The colour the organiser chose is what runs
        // the rim as light, where nothing is owed.
        $this->assertMatchesRegularExpression('/\.ed-tier\.is-sel\{ border-color:var\(--tier-edge\); background:#f6fcf5/', $css);
        $this->assertStringContainsString('.ed-tier.is-sel .ed-tier__radio::after', $css);
        // fill inside an edge ring — the printed ticket's own dot.
        $this->assertMatchesRegularExpression(
            '/\.ed-tier\.is-sel \.ed-tier__radio::after\{[^}]*background:var\(--tier-hue\)[^}]*var\(--tier-edge\)/', $css);
    }

    // ══ the light cannot overflow the page ═══════════════════════════════════

    public function test_nothing_in_the_effect_is_positioned_outside_the_card(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // The design put the bloom on discs extending ~22px past the card. At 390px the
        // sidebar's gutter is not 22px, and an element that wide is a horizontal scrollbar
        // on the whole document. `box-shadow` never contributes to scroll width, so the
        // bloom is a shadow on a layer pinned to the card's own bounds.
        //
        // The ring is the one exception at -2px, and it carries `overflow:hidden`, so its
        // own children cannot escape either.
        preg_match_all('/\.ed-fx__\w+\{([^}]*)\}/', $css, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'the effect layers are not in the stylesheet');

        foreach ($m as $rule) {
            if (!preg_match('/inset:\s*-(\d+(?:\.\d+)?)/', $rule[1], $neg)) continue;
            $this->assertLessThanOrEqual(2.0, (float) $neg[1],
                'a layer reaches ' . $neg[1] . 'px past the card: ' . trim($rule[0]));
        }

        $this->assertMatchesRegularExpression(
            '/\.ed-fx__ring\{[^}]*inset:-2px[^}]*overflow:hidden/', $css);

        // The sparks are the one layer that MOVES outside its own box, and they are
        // positioned with top/right rather than `inset`, so the loop above cannot see
        // them. They travel up and to the LEFT: negative --sx keeps them inside the card's
        // width, and vertical bleed cannot scroll anything horizontally.
        $tpl = $css;
        $this->assertMatchesRegularExpression("/--sx:' \+ \(-\d/", $tpl,
            'the sparks fan outward past the right edge again — that is wider than the '
            . 'sidebar gutter at 390px');
    }

    public function test_the_card_is_not_given_overflow_hidden(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // It would clip the entire effect, and the symptom is "the animation does not
        // work" rather than anything pointing at this line.
        $this->assertDoesNotMatchRegularExpression('/\.ed-rsvp\{[^}]*overflow:\s*hidden/', $css);
        $this->assertMatchesRegularExpression('/\.ed-rsvp\{ position:relative/', $css);
    }

    // ══ the tone reaching the row ════════════════════════════════════════════

    public function test_each_row_carries_its_own_tone_and_hue(): void
    {
        $this->tier('General', 5000, ['sort_order' => 1]);
        $this->tier('Patron', 380000, ['sort_order' => 2, 'colour' => 'deep']);
        $html = $this->render();

        // The peak is the ₦380,000 row. `pick(...)` carries the tone name as its 7th
        // argument, so a row whose tone never arrived would fall back to `calm` rather
        // than to nothing.
        $this->assertStringContainsString("'peak'", $html);
        $this->assertStringContainsString("'calm'", $html);
        // And both hexes are on the row itself, so the light matches the swatch the
        // organiser chose and the dot on the ticket rather than a fourth palette.
        $this->assertMatchesRegularExpression(
            '/style="--tier-hue:#[0-9a-f]{6};--tier-edge:#[0-9a-f]{6}"/i', $html);
    }

    public function test_the_premium_row_at_the_top_of_the_list_still_gets_the_peak(): void
    {
        // sort_order puts Patron FIRST. Under the handoff's `loop.last` rule, General
        // admission would have swept white-hot. This is the same guarantee as
        // EventTierToneTest, asserted through the page so the wiring is covered too.
        $this->tier('Patron', 380000, ['sort_order' => 1]);
        $this->tier('General', 5000, ['sort_order' => 2]);
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            "/380,000[\s\S]{0,400}?/", $html, 'the Patron row should render');
        // The peak tone appears exactly once, and on the row whose price is the highest.
        $this->assertSame(1, preg_match_all("/, 'peak', '/", $html, $unused),
            'exactly one row may be the peak');
        $this->assertMatchesRegularExpression("/pick\(\d+, 380000,[^)]*'peak'/", $html);
    }

    public function test_a_sold_out_tier_is_held_rather_than_celebrated(): void
    {
        $this->tier('General', 5000, ['sort_order' => 1]);
        $patron = $this->tier('Patron', 380000, ['sort_order' => 2, 'capacity' => 1]);
        DB::table('gates_event_registrations')->insert([
            'event_id' => $this->eventId, 'tier_id' => $patron, 'tier' => 'Patron',
            'reference' => 'AFG-EVT-' . bin2hex(random_bytes(4)),
            'name' => 'A Guest', 'email' => 'g@example.test',
            'status' => 'confirmed', 'amount_naira' => 380000,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $html = $this->render();

        $this->assertMatchesRegularExpression("/pick\(\d+, 380000,[^)]*'hold'/", $html);
        $this->assertStringNotContainsString("'peak'", $html,
            'a sold-out top tier must not sweep white — never celebrate joining a queue');
    }

    // ══ the burst that is not a comparison ═══════════════════════════════════

    public function test_the_register_burst_fires_on_a_successful_response(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // The handoff left this "designed but not wired", and named the risk itself: a
        // burst followed by a payment error is worse than no burst. So it sits inside the
        // `d.success` branch, after the paid path has already returned to the gateway.
        // Bounded rather than open-ended: `this.won = true` anywhere in the file would
        // satisfy an unbounded match, including inside submit()'s failure branch. The
        // window is generous because the branch carries the explanation with it.
        $this->assertMatchesRegularExpression(
            '/if\(d\.success\)\{[\s\S]{0,900}?this\.won = true;/', $js);

        // And never on the paid hand-off, which returns before reaching that branch:
        // nothing has succeeded when the browser is on its way to a payment page.
        $this->assertMatchesRegularExpression(
            '/if\(d\.success && d\.pay\)\{[^}]*return;[^}]*\}/', $js);

        // And NOT in joinList(): a waiting list is not a win.
        preg_match('/async joinList\(\)\{[\s\S]*?\n      \}/', $js, $m);
        $this->assertNotEmpty($m, 'joinList() could not be located');
        $this->assertStringNotContainsString('won = true', $m[0],
            'joining a waiting list must not fire a celebration');
    }

    public function test_the_burst_counter_is_what_makes_a_repeat_press_replay(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // A finished CSS animation does not restart when the same `animation-name` is
        // re-applied, and pressing one tier twice is what people actually do. The class
        // alternates on `burst % 2` and the NAME CHANGE is the restart — so both halves
        // of every keyframe pair have to exist.
        $this->assertStringContainsString("burst % 2 ? 'is-a' : 'is-b'", $js);
        $this->assertStringContainsString('this.burst++', $js);

        preg_match_all('/@keyframes (ed\w+?)([AB])\{/', $js, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        $pairs = [];
        foreach ($m as $k) $pairs[$k[1]][] = $k[2];
        foreach ($pairs as $name => $halves) {
            sort($halves);
            $this->assertSame(['A', 'B'], $halves,
                '@keyframes ' . $name . ' has no paired twin, so it replays only every other press');
        }
    }

    public function test_nothing_animates_before_the_first_press(): void
    {
        $this->tier('General', 5000);
        $html = $this->render();

        // `burst === 0` means neither class is on the card, so a page load is still. An
        // effect that fires on arrival is an effect nobody connects to their own press.
        $this->assertStringContainsString("burst === 0 ? ''", $html);
    }
}
