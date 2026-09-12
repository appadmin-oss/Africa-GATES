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

        // Counted inside the TIER group, not across the page: the flier generator's shape
        // picker is a radiogroup too, and a page-wide count made this test fail the day it
        // arrived — for something it is not about.
        $this->assertSame(2, substr_count($html, 'role="radio" data-ed-tier'),
            'one radio per tier');
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
        // And both hexes are on the row itself, so the ink matches the swatch the
        // organiser chose and the dot on the ticket rather than a fourth palette — plus
        // the two numbers that carry the rank ladder into CSS.
        $this->assertMatchesRegularExpression(
            '/style="--tier-hue:#[0-9a-f]{6};--tier-edge:#[0-9a-f]{6};--tier-heat:[\d.]+;--tier-grow:\d+ms"/i',
            $html);
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
        // Bounded by the BRANCH, not by a character count. A count has to be widened every
        // time anything is added to that branch — it already has been once — and each
        // widening makes the assertion weaker for the same reason it makes it pass.
        $from = strpos($js, 'if(d.success){');
        $this->assertNotFalse($from, 'the success branch could not be located');
        $to = strpos($js, '} else {', $from);
        $this->assertNotFalse($to, 'the success branch has no else');

        $this->assertStringContainsString('this.won = true;', substr($js, $from, $to - $from),
            'the register burst is not inside the success branch');

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

    /**
     * A repeat press must replay, and the two effects reach that guarantee differently.
     *
     * A finished CSS animation does not restart when the same `animation-name` is
     * re-applied, and pressing one tier twice is exactly what people do while comparing.
     * Every keyframe-driven layer therefore exists twice, under an A and a B name, with a
     * counter flipping between them — the NAME CHANGE is the restart.
     *
     * The ripple needs none of that, because each press builds a NEW element and drops it
     * when the fade ends. That is the better answer and it is why the press moved to it;
     * the A/B machinery survives only where a class still drives a keyframe.
     */
    public function test_a_repeat_press_replays_on_both_mechanisms(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertStringContainsString("burst % 2 ? 'is-a' : 'is-b'", $js);
        $this->assertStringContainsString("totalBeat % 2 ? 'is-a' : 'is-b'", $js);
        $this->assertStringContainsString('this.burst++', $js);
        $this->assertStringContainsString('this.totalBeat++', $js);

        preg_match_all('/@keyframes (ed\w+?)([AB])\{/', $js, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        $pairs = [];
        foreach ($m as $k) $pairs[$k[1]][] = $k[2];
        foreach ($pairs as $name => $halves) {
            sort($halves);
            $this->assertSame(['A', 'B'], $halves,
                '@keyframes ' . $name . ' has no paired twin, so it replays only every other press');
        }

        // The ripple's own restart: a fresh element per press, removed when it is done.
        $this->assertStringContainsString("createElement('span')", $js);
        $this->assertStringContainsString('removeChild(el)', $js,
            'a ripple that is never removed stacks one node per press for the life of the page');
    }

    public function test_nothing_animates_before_the_first_press(): void
    {
        $this->tier('General', 5000);
        $html = $this->render();

        // `burst === 0` means neither class is on the card, so a page load is still. An
        // effect that fires on arrival is an effect nobody connects to their own press.
        $this->assertStringContainsString("burst === 0 ? ''", $html);
        $this->assertStringContainsString("totalBeat === 0 ? ''", $html);
    }

    // ══ the press: a state layer and a ripple, on the row that was pressed ═══

    /**
     * The card-wide light does not run on a comparison any more.
     *
     * It fired 22px above and 400px below the 48px row a finger had landed on, for up to
     * 1.4 seconds, on an action the effect's own comment correctly called comparison
     * rather than commitment. `pick()` must not touch `burst`; the arrival is what does.
     */
    public function test_choosing_a_tier_does_not_fire_the_card_wide_light(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $from = strpos($js, 'pick(id, price, max, min, soldOut, wlCount, tone, hue, edge){');
        $this->assertNotFalse($from, 'pick() could not be located');
        $to = strpos($js, "\n      },", $from);
        $body = substr($js, $from, $to - $from);

        $this->assertStringNotContainsString('this.burst++', $body,
            'a comparison must not throw the arrival firework');
        $this->assertStringContainsString('this.totalBeat++', $body,
            'the total is the number that changed and should say so');
    }

    /** And the rows being compared are not dimmed while somebody compares them. */
    public function test_the_unchosen_rows_are_not_dimmed(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // `edDim` took every other row to .42 opacity for 850ms — the rows somebody is
        // reading against are the rows it hid.
        $this->assertStringNotContainsString('@keyframes edDim', $css);
        $this->assertDoesNotMatchRegularExpression('/\.ed-tier:not\(\.is-sel\)\{[^}]*animation/', $css);
    }

    /**
     * The ripple is born where the pointer landed, which is the whole point of it.
     *
     * A ripple that always starts in the middle of the row has given up the one thing it
     * was for: saying that the row you touched is the row that answered.
     */
    public function test_the_ripple_starts_at_the_pointer_and_covers_the_row(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertStringContainsString('e.clientX', $js);
        $this->assertStringContainsString('e.clientY', $js);
        // Sized to the FARTHEST corner: a radius to the nearest one stops short of the
        // price at the other end of the row, which is the thing being compared.
        $this->assertMatchesRegularExpression('/Math\.max\(\s*\n?\s*Math\.hypot/', $js);
        // pointerdown, not click — a ripple that waits for the click has missed the press.
        $this->assertStringContainsString('@pointerdown="down_($event)"', $js);
    }

    /** Every way a press can end has to end the ripple, or it stays on the row. */
    public function test_the_ripple_is_released_on_every_exit_from_a_press(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        foreach (['pointerup', 'pointercancel', 'pointerleave', 'blur'] as $ev) {
            $this->assertStringContainsString("'" . $ev . "'", $js,
                'a press that ends with ' . $ev . ' leaves the ripple on screen');
        }
    }

    /**
     * A keyboard press has no pointer, and it still gets a response.
     *
     * Enter, Space, and the `.click()` an arrow key fires all arrive with no coordinates.
     * Material centres the ripple in that case; the alternative is one crawling out of the
     * top-left corner, or nothing at all — and nothing at all is the state this row was in
     * for keyboard users before any of this.
     */
    public function test_a_keyboard_selection_still_gets_a_ripple(): void
    {
        $this->tier('General', 5000);
        $html = $this->render();

        $this->assertStringContainsString('tap_($event.currentTarget)', $html);

        $js = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');
        $this->assertStringContainsString('r.width / 2', $js, 'the fallback origin is the row centre');
        // And a mouse press must not draw two — the delegated pointerdown marks the row.
        $this->assertStringContainsString("btn.dataset.rippling === '1'", $js);
    }

    /**
     * The row responds to hover and focus, not only to a completed click.
     *
     * There was no hover or focus response at all: a one-shot burst and nothing in
     * between, so on a desktop the list was inert until something was clicked.
     */
    public function test_the_row_has_a_state_layer_for_hover_focus_and_press(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertMatchesRegularExpression('/\.ed-tier:not\(:disabled\):hover \.ed-tier__ink::before\{[^}]*opacity/', $css);
        $this->assertMatchesRegularExpression('/\.ed-tier:not\(:disabled\):focus-visible \.ed-tier__ink::before\{[^}]*opacity/', $css);
        $this->assertMatchesRegularExpression('/\.ed-tier:not\(:disabled\):active \.ed-tier__ink::before\{[^}]*opacity/', $css);

        // `:disabled` is excluded from all three: a sold-out row with no waiting list
        // must not light up under a cursor as though it could be pressed.
        // And hover is behind `hover:hover`, or a tap leaves the row washed on a phone.
        $this->assertMatchesRegularExpression('/@media \(hover:hover\)\{\s*\n?\s*\.ed-tier:not\(:disabled\):hover/', $css);
    }

    /**
     * The ripple is clipped to the row, and the row's outline is not clipped with it.
     *
     * `overflow:hidden` has to go on the ink layer rather than on `.ed-tier`: a focus
     * outline is drawn outside the border box at +2px, and the selected row's rim is an
     * inset shadow. Putting the clip on the button would have taken the focus indicator
     * with it, which is a WCAG 2.4.7 failure introduced by a decoration.
     */
    public function test_the_clip_is_on_the_ink_layer_and_not_on_the_row(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertMatchesRegularExpression('/\.ed-tier__ink\{[^}]*overflow:hidden/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.ed-tier\{[^}]*overflow:\s*hidden/', $css);
        // And it carries neither meaning nor a target.
        $this->assertMatchesRegularExpression('/\.ed-tier__ink\{[^}]*pointer-events:none/', $css);
        $this->assertStringContainsString('<span class="ed-tier__ink" aria-hidden="true"></span>', $css);
    }

    /**
     * The wash must never take the row's small text below AA — for ANY accent.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE REGRESSION THIS CAUGHT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The effect this replaced kept off the row's face on purpose, and its comment said
     * exactly why: `.ed-tier__perk` is 11.5px at #626a6e, which is 5.52:1 on white — AA
     * with almost nothing to spend. A state layer DOES cross the face, so the first
     * version of it put a 12% wash of the tier's own colour behind that text and took a
     * navy peak row to 4.09:1. Below the floor, on a persistent state somebody can sit in
     * with the pointer parked, on the row where the price is.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND WHY THIS SWEEPS INSTEAD OF CHECKING THE HUES THAT EXIST
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The hue is the organiser's, resolved from `ticket_accent` through EventTierPalette.
     * There is no list of them to check. So this does what EventFlierThemeTest does for the
     * flier: it asserts the FLOOR against the worst input the space can produce rather than
     * against colours somebody thought of. For a wash on a light ground the worst case is
     * black, and every other accent is strictly safer.
     *
     * The opacities are read out of the stylesheet rather than written here, so raising one
     * fails this test instead of quietly failing a reader.
     */
    public function test_no_state_layer_takes_the_rows_small_text_below_aa(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        // Every `calc(<n> * var(--tier-w))` the stylesheet declares, times the heaviest
        // weight the ladder can reach — peak, whose HEAT is 1.0, so --tier-w is 1.2.
        preg_match_all('/opacity:calc\(([\d.]+) \* var\(--tier-w\)\)/', $css, $m);
        $this->assertNotEmpty($m[1], 'the state layer opacities are not in the stylesheet');
        $weight = 0.75 + 0.45 * 1.0;

        // The colours that appear on a row at 11.5px, from the stylesheet and from the two
        // inline overrides on the scarcity lines.
        preg_match('/\.ed-tier__perk\{ font-size:11\.5px; color:(#[0-9a-f]{6})/i', $css, $pk);
        $this->assertNotEmpty($pk, 'the perk colour could not be read');
        preg_match_all('/class="ed-tier__perk" style="color:(#[0-9a-f]{6})/i', $css, $inline);
        $smalls = array_merge([$pk[1]], $inline[1]);
        $this->assertGreaterThanOrEqual(3, count($smalls), 'the scarcity lines were not found');

        // The ripple's own opacity, which is declared the same way on `.ed-tier__ripple`.
        preg_match('/\.ed-tier__ripple\{[\s\S]*?opacity:calc\(([\d.]+) \* var\(--tier-w\)\)/', $css, $rp);
        $this->assertNotEmpty($rp, "the ripple's opacity could not be read");
        $ripple = (float) $rp[1] * $weight;

        // Layers do NOT composite, and that is enforced rather than hoped for: every
        // persistent opacity is multiplied by `--tier-state`, which the script takes to 0
        // for as long as a ripple is on the row. The first version had them stacking — a
        // keyboard press was focus UNDER the ripple, about .25 of ink — and checking each
        // opacity in isolation is exactly what missed it. The exclusion itself is asserted
        // in the next test; this one checks each layer at the weight it can actually reach
        // on its own, which is only sound BECAUSE of that exclusion.
        $each = array_map(static fn ($v): float => (float) $v * $weight, $m[1]);
        $each['the ripple'] = $ripple;

        foreach (['#ffffff', '#f6fcf5'] as $ground) {
            foreach ($each as $label => $alpha) {
                $bg = self::blend('#000000', $alpha, $ground);
                foreach ($smalls as $fg) {
                    $r = self::contrast($fg, $bg);
                    $this->assertGreaterThanOrEqual(4.5, round($r, 2),
                        sprintf('%s on %s under a %.1f%% black wash (%s) is %.2f:1 — the '
                              . 'darkest accent an organiser can set puts this text below AA',
                                $fg, $ground, $alpha * 100,
                                is_string($label) ? $label : 'a state layer', $r));
                }
            }
        }
    }

    /**
     * The layers are alternatives, not a pile.
     *
     * Every persistent opacity has to pass through `--tier-state`, and the script has to
     * take it to 0 while a ripple is alive. Miss one rule and that state composites with
     * the ripple; the contrast test above then holds a floor the screen does not, because
     * it checks each layer at its own weight on the strength of this guarantee.
     */
    public function test_no_persistent_layer_escapes_the_ripple_gate(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        preg_match_all('/\.ed-tier[^{]*\.ed-tier__ink::before\{ opacity:([^;}]+)/', $css, $m);
        $this->assertNotEmpty($m[1], 'the state layer opacities are not in the stylesheet');
        foreach ($m[1] as $expr) {
            $this->assertStringContainsString('var(--tier-state)', $expr,
                'this layer is not gated, so it composites with the ripple: ' . trim($expr));
        }

        // And the gate is actually driven, in both directions.
        $this->assertStringContainsString('.ed-tier.is-inking{ --tier-state:0; }', $css);
        $this->assertStringContainsString("btn.classList.add('is-inking')", $css);
        $this->assertStringContainsString("btn.classList.remove('is-inking')", $css);
        // Counted, not a boolean: a second press landing during the first one's fade would
        // otherwise have the first one's cleanup turn the second one's gate off.
        $this->assertStringContainsString('btn._ink = (btn._ink || 0) + 1', $css);
    }

    /**
     * And the pressed wash exists exactly where the ripple does not.
     *
     * `:active` and the ripple are two drawings of one state. Both at once is the stacking
     * the test above now holds against; neither at all leaves a reduced-motion reader with
     * no press feedback on a row they just pressed.
     */
    public function test_the_pressed_wash_lives_only_where_there_is_no_ripple(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $at = strpos($css, '@media (prefers-reduced-motion: reduce){');
        $this->assertNotFalse($at);
        $before = substr($css, 0, $at);
        $inside = substr($css, $at);

        $this->assertDoesNotMatchRegularExpression(
            '/\.ed-tier:not\(:disabled\):active \.ed-tier__ink::before/', $before,
            'the ripple already draws the press — a wash under it doubles the ink');
        $this->assertMatchesRegularExpression(
            '/\.ed-tier:not\(:disabled\):active \.ed-tier__ink::before/', $inside,
            'a reader who asked for less motion still needs to see that the row went down');
    }

    /** @return array{0:float,1:float,2:float} sRGB 0–255 */
    private static function rgb(string $hex): array
    {
        return [(float) hexdec(substr($hex, 1, 2)), (float) hexdec(substr($hex, 3, 2)),
                (float) hexdec(substr($hex, 5, 2))];
    }

    private static function blend(string $fg, float $alpha, string $bgHex): string
    {
        $f = self::rgb($fg); $b = self::rgb($bgHex);
        $o = '#';
        foreach ([0, 1, 2] as $i) {
            $o .= str_pad(dechex((int) round($alpha * $f[$i] + (1 - $alpha) * $b[$i])), 2, '0', STR_PAD_LEFT);
        }
        return $o;
    }

    private static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $out = 0.0;
            foreach ([[0, 0.2126], [1, 0.7152], [2, 0.0722]] as [$i, $k]) {
                $c = self::rgb($hex)[$i] / 255;
                $out += $k * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
            }
            return $out;
        };
        $x = $lum($a); $y = $lum($b);
        return ($x > $y ? ($x + 0.05) / ($y + 0.05) : ($y + 0.05) / ($x + 0.05));
    }

    /**
     * Reduced motion refuses the ripple at the source rather than collapsing it.
     *
     * There is no honest .01ms version of a growing circle. The state layer is a flat wash
     * that appears in place and says the same thing without anything travelling, so it
     * stays — at a shortened transition rather than none, because an instantaneous colour
     * change on hover reads as a rendering fault.
     */
    public function test_reduced_motion_keeps_the_wash_and_drops_the_ripple(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/detail.twig');

        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion: reduce\)\{[\s\S]{0,400}?\.ed-tier__ripple\{ display:none/', $css);
        // Refused before it is built, too: a display:none element still costs a WAAPI
        // animation per press, and the setting means "do less", not "hide more".
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $css);
    }
}
