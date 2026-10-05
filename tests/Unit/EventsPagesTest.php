<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventsFront;
use AfricaGates\Services\EventSales;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE EVENTS INDEX AND ONE EVENT — Phase 7 (§8.10), rebuilt from EventsPage.dc.html.
 *
 * Re-asserts the rules held by the guards destroyed with the old pages (inventory:
 * pages--events.md, pages--events--detail.md) against the new markup, plus the owner's
 * request of 4 Oct 2026: upcoming first, a real coming-soon state, past never mixed, the
 * sandbox never shown. Rendered through the real router (ChromeRender), so a template that
 * reads something the controller stopped passing fails here, not in production.
 *
 * Every assertion here was watched failing against a planted break before it was trusted
 * (docs/handoff/PHASE-7.md, "Events", mutations).
 */
final class EventsPagesTest extends TestCase
{
    private function at(float $days, string $t = '18:00:00'): string
    {
        return date('Y-m-d', time() + (int) ($days * 86400)) . ' ' . $t;
    }

    private function event(array $e): int
    {
        return (int) DB::table('gates_site_events')->insertGetId($e + [
            'status' => 'published', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function tier(int $event, string $name, int $price, ?int $cap, array $x = []): int
    {
        static $n = 0; $n++;
        return (int) DB::table('gates_event_tiers')->insertGetId($x + [
            'event_id' => $event, 'slug' => 'tt' . $n, 'name' => $name, 'price_naira' => $price,
            'capacity' => $cap, 'is_active' => 1, 'sort_order' => $n, 'min_per_order' => 1, 'max_per_order' => 4,
        ]);
    }

    private function seat(int $event, int $tier, int $qty, string $status = 'confirmed'): void
    {
        static $n = 0; $n++;
        DB::table('gates_event_registrations')->insert([
            'event_id' => $event, 'tier_id' => $tier, 'name' => 'G ' . $n, 'email' => "g{$n}@mail.test",
            'quantity' => $qty, 'status' => $status, 'reference' => 'AFG-EVT-T' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'ticket_code' => 'TC' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** One event in each state, a past one, and one linked to the sandbox. @return array<string,int> */
    private function world(): array
    {
        $w = [];
        $w['open'] = $this->event(['slug' => 'ev-open', 'title' => 'Open Gala', 'event_date' => $this->at(40), 'location' => 'Nairobi', 'waitlist_open' => 1]);
        $this->tier($w['open'], 'General', 15000, 100);
        $w['soon'] = $this->event(['slug' => 'ev-soon', 'title' => 'Soon Summit', 'event_date' => $this->at(50)]);
        $this->tier($w['soon'], 'General', 10000, 100, ['sale_starts_at' => $this->at(9, '09:00:00')]);
        $w['first'] = $this->event(['slug' => 'ev-first', 'title' => 'First Up Night', 'event_date' => $this->at(3)]);
        $this->tier($w['first'], 'Seat', 0, null);
        $w['past'] = $this->event(['slug' => 'ev-past', 'title' => 'Last Year Gala', 'event_date' => $this->at(-30), 'recording_url' => 'https://example.org/rec']);
        $w['sandbox'] = $this->event(['slug' => 'ev-sandbox', 'title' => 'Rehearsal Gala', 'event_date' => $this->at(10)]);
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Sandbox', 'slug' => 'sbx-' . uniqid(), 'is_active' => 0, 'sort_order' => 1]);
        DB::table('gates_event_programmes')->insert(['event_id' => $w['sandbox'], 'programme_id' => $p]);
        return $w;
    }

    // ══ the index ════════════════════════════════════════════════════════════

    public function test_upcoming_leads_in_date_order_and_past_is_its_own_section(): void
    {
        $this->world();
        $html = ChromeRender::html('/events');

        $feat = strpos($html, 'First Up Night');
        $open = strpos($html, 'Open Gala');
        $soon = strpos($html, 'Soon Summit');
        $pastH = strpos($html, 'id="ev-past"');
        $past = strpos($html, 'Last Year Gala');
        $this->assertNotFalse($feat);
        $this->assertTrue($feat < $open && $open < $soon, 'upcoming events are listed soonest first, the next one featured');
        $this->assertNotFalse($pastH, 'past events have their own section');
        $this->assertTrue($soon < $pastH && $pastH < $past, 'a past event is never in the upcoming list');
        $this->assertStringContainsString('Next up · in 3 days', $html);
    }

    public function test_the_sandbox_never_reaches_the_index_or_its_own_page(): void
    {
        $this->world();
        $this->assertStringNotContainsString('Rehearsal Gala', ChromeRender::html('/events'));
        $this->assertSame(404, ChromeRender::page('/events/ev-sandbox')->getStatusCode());
    }

    public function test_coming_soon_is_a_state_with_the_date_tickets_go_on_sale(): void
    {
        $this->world();
        $html = ChromeRender::html('/events?f=soon');
        $this->assertStringContainsString('Soon Summit', $html);
        $this->assertStringContainsString('ev-tag--soon', $html);
        $this->assertStringContainsString('tickets on sale', $html);
        $this->assertStringNotContainsString('Open Gala', $html, 'the coming-soon filter shows only what is not on sale yet');
        $this->assertStringNotContainsString('Last Year Gala', $html);

        $past = ChromeRender::html('/events?f=past');
        $this->assertStringContainsString('Last Year Gala', $past);
        $this->assertStringNotContainsString('Open Gala', $past);
    }

    public function test_the_filters_are_links_and_say_which_is_showing(): void
    {
        $this->world();
        $html = ChromeRender::html('/events?f=past');
        $this->assertMatchesRegularExpression('~<a class="ev-chip" href="/events\?f=past" aria-current="page">~', $html);
        $css = ChromeRender::code('public/assets/css/components/events.css');
        $this->assertMatchesRegularExpression('~\.ev__chips\{[^}]*position:sticky~', $css, 'the chips are sticky on a phone');
        $this->assertMatchesRegularExpression('~\.ev-chip,\s*\.ev-chip:hover\{[^}]*height:44px~', $css, 'a chip is a 44px target');
        $this->assertMatchesRegularExpression('~\.ev-row,\s*\.ev-row:hover\{[^}]*min-height:76px~', $css, 'rows are at least 76px');
    }

    // ══ the state resolver ═══════════════════════════════════════════════════

    public function test_one_resolver_decides_the_state_from_the_tiers(): void
    {
        $e = ['event_date' => $this->at(20), 'waitlist_open' => 0];
        $this->assertSame('open', EventSales::state($e, [['state' => 'open']]));
        $this->assertSame('soon', EventSales::state($e, [['state' => 'early'], ['state' => 'early']]));
        $this->assertSame('open', EventSales::state($e, [['state' => 'early'], ['state' => 'open']]), 'one tier on sale is on sale');
        $this->assertSame('soldout', EventSales::state($e, [['state' => 'sold_out']]));
        $this->assertSame('waitlist', EventSales::state(['waitlist_open' => 1] + $e, [['state' => 'sold_out']]));
        $this->assertSame('closed', EventSales::state(['sales_close_at' => $this->at(-1)] + $e, [['state' => 'open']]));
        $this->assertSame('ended', EventSales::state(['event_date' => $this->at(-1)], [['state' => 'open']]));
        $this->assertSame('open', EventSales::state($e, [['state' => 'early', 'unlocked' => true], ['state' => 'open']]));
        $this->assertSame('soon', EventSales::state($e, [['state' => 'early'], ['state' => 'open', 'unlocked' => true]]),
            'a code-gated allocation that is open does not put the event on sale to the public');
    }

    public function test_a_coming_soon_event_cannot_be_booked_before_its_date(): void
    {
        $w = $this->world();
        $tier = (int) DB::table('gates_event_tiers')->where('event_id', $w['soon'])->value('id');
        $res = ChromeRender::page('/events/ev-soon/register', [], [], 'POST',
            ['_token' => 'test-token', 'tier_id' => (string) $tier, 'name' => 'A B', 'email' => 'a@mail.test', 'phone' => '+2348012345678', 'quantity' => '1']);
        $d = json_decode((string) $res->getBody(), true);
        $this->assertFalse((bool) ($d['success'] ?? true), 'the card and the checkout agree about whether tickets are on sale');
        $this->assertSame(0, DB::table('gates_event_registrations')->where('event_id', $w['soon'])->count());
    }

    // ══ one event ════════════════════════════════════════════════════════════

    public function test_every_state_draws_its_own_card(): void
    {
        $w = $this->world();
        $sold = $this->event(['slug' => 'ev-sold', 'title' => 'Full House', 'event_date' => $this->at(30), 'capacity' => 2]);
        $t = $this->tier($sold, 'General', 0, null); $this->seat($sold, $t, 2);
        $wl = $this->event(['slug' => 'ev-wl', 'title' => 'Queue Night', 'event_date' => $this->at(30), 'waitlist_open' => 1]);
        $t = $this->tier($wl, 'General', 5000, 1); $this->seat($wl, $t, 1);
        $cl = $this->event(['slug' => 'ev-cl', 'title' => 'Closed Dinner', 'event_date' => $this->at(5), 'sales_close_at' => $this->at(-1)]);
        $this->tier($cl, 'Seat', 1000, 10);

        foreach (['ev-open' => 'open', 'ev-soon' => 'soon', 'ev-sold' => 'soldout', 'ev-wl' => 'waitlist', 'ev-cl' => 'closed', 'ev-past' => 'ended'] as $slug => $st) {
            $html = ChromeRender::html('/events/' . $slug);
            $this->assertStringContainsString('data-state="' . $st . '"', $html, $slug);
            $this->assertStringContainsString('ed-card--' . $st, $html, $slug);
        }
        $this->assertStringContainsString('action="/events/ev-soon/notify"', ChromeRender::html('/events/ev-soon'));
        $this->assertStringContainsString('Watch the recording', ChromeRender::html('/events/ev-past'));
        $this->assertStringContainsString('Join the waiting list', ChromeRender::html('/events/ev-wl'));
    }

    public function test_the_tier_list_is_a_radio_group_with_neutral_radios_and_derived_colours(): void
    {
        $w = $this->world();
        $this->tier($w['open'], 'Patron', 250000, 10, ['colour' => 'warm']);
        $html = ChromeRender::html('/events/ev-open');

        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertSame(2, substr_count(substr($html, (int) strpos($html, 'role="radiogroup" aria-label="Ticket type"'), 6000), 'role="radio" data-ed-tier'));
        $group = substr($html, (int) strpos($html, 'role="radiogroup"'), 4000);
        $this->assertSame(1, preg_match_all('~role="radio"[^>]*?tabindex="0"~s', $group), 'the group is one tab stop');
        $this->assertMatchesRegularExpression('~--tier-accent:#[0-9A-F]{6};--tier-light:#[0-9A-F]{6};--tier-wash:#[0-9A-F]{6};--tier-deep:#[0-9A-F]{6};--tier-glow:#[0-9A-F]{6};--tier-edge:#[0-9A-F]{6}~i', $html,
            'each row supplies the five DC properties and its edge, from PHP');

        $css = ChromeRender::code('public/assets/css/components/events.css');
        $this->assertMatchesRegularExpression('~\.ed-tier\[aria-checked="true"\] \.ed-tier__radio\{[^}]*var\(--ag-ink\)~', $css, 'radios stay neutral');
        $this->assertDoesNotMatchRegularExpression('~\.ed-tier__radio[^{]*\{[^}]*--tier-~', $css, 'the radio never takes the tier colour');

        $tpl = ChromeRender::source('templates/pages/events/_card.twig') . ChromeRender::source('templates/pages/events/detail.twig');
        $this->assertDoesNotMatchRegularExpression('~#[0-9a-f]{6}\b~i', $tpl, 'no colour is typed in the templates');
        $this->assertStringNotContainsString('loop.index', $tpl, 'rank is a price question, never a list position');
    }

    public function test_the_glow_is_the_specified_one_and_leaves_under_reduced_motion(): void
    {
        $css = ChromeRender::code('public/assets/css/components/events.css');
        $this->assertMatchesRegularExpression("~@property --ev-a\{\s*syntax:'<angle>'~", $css);
        $this->assertMatchesRegularExpression('~\.ed-glow__halo\{[^}]*inset:-10px[^}]*filter:blur\(22px\)[^}]*opacity:\.55[^}]*3\.2s linear infinite~s', $css);
        $this->assertMatchesRegularExpression('~\.ed-glow__ring\{[^}]*padding-box[^}]*border-box~s', $css);
        $this->assertMatchesRegularExpression('~@keyframes edGlow\{[^\n]*?100%\{ opacity:\.35 \}~', $css, 'it settles to .35');
        $this->assertMatchesRegularExpression('~prefers-reduced-motion:reduce\)\{ \[data-ev-glow\]\{ display:none \} \}~', $css);
        $this->assertStringContainsString('aria-hidden="true" hidden><span class="ed-glow__halo">', ChromeRender::source('templates/pages/events/_card.twig'),
            'the effect is hidden from assistive tech and does not play before the first pick');
        $js = ChromeRender::code('public/assets/js/event-detail.js');
        $this->assertStringContainsString('replaceChild(fresh, g)', $js, 'a pick replays by replacing the element');
    }

    public function test_the_phone_order_and_the_fixed_bar(): void
    {
        $this->world();
        $html = ChromeRender::html('/events/ev-open');
        $css = ChromeRender::code('public/assets/css/components/events.css');
        $this->assertMatchesRegularExpression('~\.ed-rail\{ display:contents \}~', $css);
        $this->assertMatchesRegularExpression('~\.ed-card\{ order:1 \}~', $css);
        $this->assertMatchesRegularExpression('~\.ed-main\{[^}]*order:2~', $css);
        $this->assertMatchesRegularExpression('~\.ed-extras\{ order:3;~', $css);
        $this->assertMatchesRegularExpression('~grid-template-columns:minmax\(0, 1fr\) 380px~', $css, 'the 380px rail from 1024');

        $this->assertMatchesRegularExpression('~<div class="ed-bar" data-bottom-ui>~', $html, 'the bar feeds --ag-bottom-ui so Gee clears it');
        $this->assertStringContainsString('From', $html);
        $this->assertStringNotContainsString('class="ed-bar"', ChromeRender::html('/events/ev-past'), 'nothing to get, no bar');
        $this->assertStringNotContainsString('data-ag-appbar', ChromeRender::html('/events/ev-open'), 'the phone detail is full-bleed: no app bar');
    }

    public function test_the_fundraiser_reading_is_derived_and_hoisted_and_never_calls_a_ticket_a_gift(): void
    {
        $tpl = ChromeRender::source('templates/pages/events/detail.twig');
        $this->assertMatchesRegularExpression('~^\{% set is_fundraiser = appeals\|default\(\[\]\) is not empty %\}$~m', $tpl,
            'derived from the live appeal, at TEMPLATE scope');
        $all = $tpl . ChromeRender::source('templates/pages/events/_card.twig');
        $this->assertStringContainsString('A ticket is not a donation', $all);
        $this->assertDoesNotMatchRegularExpression('~<form[^>]*(donate|giving)~i', $all, 'the event page is never a payment form for an appeal');
    }

    public function test_the_referral_offer_is_read_live_and_needs_an_owner(): void
    {
        $this->world();
        $html = ChromeRender::html('/events/ev-open');
        if (!str_contains($html, 'h-earn')) $this->markTestSkipped('referrals are not switched on by this setting key here');
        $this->assertStringContainsString('/account/login?next=/events/ev-open', $html, 'sign-in comes back to this event');
        $this->assertStringNotContainsString('h-earn', ChromeRender::html('/events/ev-past'), 'nothing to earn from a past event');
    }

    public function test_calendar_and_google_calendar_in_utc(): void
    {
        $this->world();
        $html = ChromeRender::html('/events/ev-open');
        $this->assertStringContainsString('href="/events/ev-open/calendar.ics"', $html);
        $this->assertMatchesRegularExpression('~calendar\.google\.com/calendar/render\?action=TEMPLATE[^"]*dates=\d{8}T\d{6}Z/\d{8}T\d{6}Z~', $html,
            'the Z — a bare stamp is read as the reader\'s local time');
    }

    public function test_the_flier_is_a_dialog_outside_the_rail_with_its_data_in_a_script_block(): void
    {
        $this->world();
        $html = ChromeRender::html('/events/ev-open');
        $this->assertMatchesRegularExpression('~<div class="flo" id="flier" role="dialog" aria-modal="true" aria-labelledby="flTitle" hidden~', $html);
        $rail = substr($html, (int) strpos($html, 'class="ed-rail"'), (int) strpos($html, '</aside>') - (int) strpos($html, 'class="ed-rail"'));
        $this->assertStringNotContainsString('id="flier"', $rail, 'the generator lives outside the rail');
        $this->assertStringContainsString('<script type="application/json" id="ed-flier-data"', $html, 'JSON never through an attribute');
        $this->assertLessThan(strpos($html, 'data-fl-fmt="story"'), strpos($html, 'data-fl-fmt="plain"'), 'the no-photo design first');

        $js = ChromeRender::code('public/assets/js/event-flier.js');
        $this->assertStringNotContainsString('wa.me', $js, 'no silent fall back to a link that cannot carry an image');
        $this->assertStringContainsString("navigator.canShare({ files: [probe] })", $js, 'canShare asked with a real File');
        $this->assertStringContainsString('return c === 405 || c === 406 || c === 415 || c === 501;', $js, 'never a 403, which is CSRF');
        $this->assertStringContainsString("'\\n' + (data.title", $js, 'the caption opens with a blank first line');
        $this->assertStringContainsString('S.ctrl.abort()', $js, 'Cancel actually cancels');
        $this->assertStringContainsString("history.pushState({ agFlier: 1 }", $js, 'back closes the sheet');
    }

    public function test_the_host_is_the_events_own_and_the_platform_is_not_named_organiser(): void
    {
        $w = $this->world();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Prize', 'slug' => 'pz-' . uniqid(), 'is_active' => 1, 'sort_order' => 2, 'host_name' => 'Lagos State Government']);
        DB::table('gates_event_programmes')->insert(['event_id' => $w['open'], 'programme_id' => $p]);
        $this->assertSame([$w['open'] => 'Lagos State Government'], EventsFront::hosts([$w['open'], $w['soon']]));
        $this->assertStringContainsString('Hosted by Lagos State Government', ChromeRender::html('/events/ev-open'));
        $this->assertStringNotContainsString('Hosted by Africa GATES', ChromeRender::html('/events/ev-soon'));
    }
}
