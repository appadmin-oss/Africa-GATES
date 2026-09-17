<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{EventTierPalette, EventTicketDesign};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The tier's colour on the ticket, and the promise that it matches the event.
 *
 * A tier stores a SLOT, never a hex, so the colour is recomputed from the event's accent on
 * every read. The whole value of that decision only shows up in the last test here: change
 * the event's accent and the ticket's dot moves with it, permanently, without anybody
 * editing a tier.
 */
class TicketTierColourTest extends TestCase
{
    private function render(string $path): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(\AfricaGates\Controllers\EventsController::class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return (string) $ctrl->ticket($req, new Response(), ['ref' => basename($path)])->getBody();
    }

    /** @return array{ref:string,event:int,tier:int} */
    private function seed(string $accent = '#2a6fdb', string $slot = 'deep'): array
    {
        $eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Ogidi Omo', 'slug' => 'ogidi-omo-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'published', 'ticket_accent' => $accent,
        ]);
        $tierId = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $eventId, 'slug' => 'patron', 'name' => 'Patron', 'price_naira' => 250000,
            'capacity' => 50, 'colour' => $slot, 'sort_order' => 1,
        ]);
        $ref = 'AGT-' . bin2hex(random_bytes(6));
        DB::table('gates_event_registrations')->insert([
            'event_id' => $eventId, 'tier_id' => $tierId, 'tier' => 'Patron', 'reference' => $ref,
            'name' => 'Adaeze Okonkwo', 'email' => 'a@example.com',
            'ticket_code' => strtoupper(bin2hex(random_bytes(4))),
            'status' => 'confirmed', 'amount_naira' => 250000,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ref' => $ref, 'event' => $eventId, 'tier' => $tierId];
    }

    public function test_the_tier_dot_is_rendered_from_the_events_accent(): void
    {
        $s    = $this->seed('#2a6fdb', 'deep');
        $html = $this->render('/events/ticket/' . $s['ref']);

        $expected = EventTierPalette::fromAccent('#2a6fdb')['deep'];
        // `class="tk__dot"` and not `tk__dot`: the class NAME is also in the page's own
        // stylesheet, so the bare string is present whether or not a dot was rendered.
        $this->assertStringContainsString('class="tk__dot"', $html, 'The tier dot should render.');
        $this->assertStringContainsString($expected['fill'], $html, 'The dot should carry the slot fill.');
        $this->assertStringContainsString($expected['edge'], $html, 'The dot should carry the slot edge.');
    }

    /**
     * The reason the column holds a slot rather than a hex. A hex chosen against the old
     * accent would still be here; a slot follows the event.
     */
    public function test_changing_the_events_accent_moves_the_tier_colour(): void
    {
        $s = $this->seed('#2a6fdb', 'deep');

        $before = EventTierPalette::fromAccent('#2a6fdb')['deep']['fill'];
        $this->assertStringContainsString($before, $this->render('/events/ticket/' . $s['ref']));

        DB::table('gates_site_events')->where('id', $s['event'])->update(['ticket_accent' => '#b4452f']);

        $after = EventTierPalette::fromAccent('#b4452f')['deep']['fill'];
        $html  = $this->render('/events/ticket/' . $s['ref']);

        $this->assertNotSame($before, $after, 'The two accents must produce different fills.');
        $this->assertStringContainsString($after, $html, 'The dot should follow the new accent.');
        $this->assertStringNotContainsString($before, $html, 'The old accent must not survive.');
    }

    /** A tier with no colour chosen renders the name and no dot — never a grey one. */
    public function test_a_tier_without_a_slot_renders_no_dot(): void
    {
        $s = $this->seed('#2a6fdb', '');
        $html = $this->render('/events/ticket/' . $s['ref']);

        $this->assertStringContainsString('Patron', $html, 'The tier name still shows.');
        $this->assertStringNotContainsString('class="tk__dot"', $html, 'No slot means no dot.');
    }

    /**
     * The palette's own guarantees, which the dot depends on: six separable swatches, each
     * with an edge that is visible against white. A pale fill with no edge is a gap.
     */
    public function test_every_swatch_has_a_visible_edge_and_no_two_collide(): void
    {
        foreach (['#2a6fdb', '#b4452f', '#237b22', '#10292c', '#f3b416'] as $accent) {
            $ladder = EventTierPalette::fromAccent($accent);
            $this->assertCount(6, $ladder, "$accent should give six slots");

            foreach ($ladder as $key => $sw) {
                $this->assertGreaterThanOrEqual(
                    3.0, EventTierPalette::contrast($sw['edge'], '#ffffff'),
                    "$accent/$key edge must clear 3:1 on white (WCAG 1.4.11)."
                );
            }

            $fills = array_column($ladder, 'fill');
            foreach ($fills as $i => $a) {
                foreach (array_slice($fills, $i + 1) as $b) {
                    $this->assertGreaterThan(
                        0.0, EventTierPalette::distance($a, $b),
                        "$accent produced two identical swatches ($a)."
                    );
                }
            }
        }
    }

    /**
     * The ticket's hero is the largest above-the-fold paint. Lazy-loading it defers the
     * request until layout and is the standard way to lose LCP — on the one page whose whole
     * premise is rendering on a phone with one bar of signal at a door.
     */
    public function test_the_ticket_hero_is_not_lazy_loaded(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/ticket.twig');
        // Matched against the <img> tag rather than the file, because the word also appears
        // in the comment explaining why it is not used — a test that reads prose is a test
        // that fails when somebody rewords a comment.
        $this->assertSame(1, preg_match('/<img[^>]*class="tk__shot"[^>]*>/', $src, $m),
            'Expected the ticket hero img.');
        $this->assertStringNotContainsString('loading="lazy"', $m[0],
            'The ticket hero is the LCP element and must not be lazy-loaded.');
        $this->assertStringContainsString('fetchpriority="high"', $m[0]);
    }
}
