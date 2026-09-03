<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\EventsController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\CacheService;
use AfricaGates\Services\EventTicketService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Putting the ticket tiers in the order the organiser wants them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE COLUMN WAS AUTHORITATIVE AND NOTHING COULD CHANGE IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `sort_order` decides the ladder. Every reader selects `orderBy('sort_order')->orderBy('id')`
 * — the public event page, the registration card, the admin list, `EventTicketService` and
 * the support context — and `saveTiers()` has always written it as `$order++` over the
 * submitted rows.
 *
 * So the stored order was whatever order the rows happened to be created in, and the event
 * form had no way to change it: the repeater could add a row and remove a row and not move
 * one. An organiser who wanted their premium tier at the top had exactly one route — delete
 * every tier and retype them in the right order — and that route is barred, because a tier
 * with a sale against it is deactivated rather than deleted. In practice the first order
 * typed was permanent.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTY THAT MAKES THIS SAFE RATHER THAN MERELY POSSIBLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A tier's id is pointed at by `gates_event_registrations.tier_id` — by every ticket
 * already sold. Reordering must therefore move the ROWS and never renumber the TIERS. If a
 * move were implemented by rewriting names and prices down a fixed set of ids, everybody
 * holding a Patron ticket would silently be holding a General one, the ticket already in
 * their inbox would be wrong, and the door would admit them to the wrong thing.
 *
 * The repeater posts each row's `tier_id[]` alongside its name, so the ids travel with the
 * rows and `saveTiers()` matches on them. {@see test_moving_a_tier_does_not_renumber_the_tiers}
 * is the assertion that keeps that true.
 */
final class EventTierOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'admin';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'],
              $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function controller(): EventsController
    {
        return new EventsController(
            \Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'),
            new AuditService(), new CacheService(), null,
            new UploadService(sys_get_temp_dir()),
        );
    }

    private function event(): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Founders Night', 'slug' => 'founders-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+40 days')),
            'status' => 'published', 'ticket_accent' => '#2A6FDB',
        ]);
    }

    /** @param array<string,mixed> $tiers */
    private function save(int $id, array $tiers): void
    {
        $row = (array) DB::table('gates_site_events')->where('id', $id)->first();
        $body = [
            'title' => $row['title'], 'slug' => $row['slug'],
            'event_date' => $row['event_date'], 'end_date' => '', 'status' => 'published',
            'cover_image' => (string) ($row['cover_image'] ?? ''),
        ] + $tiers;

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/events/' . $id)
            ->withParsedBody($body);
        $this->controller()->save($req, new Response(), ['id' => (string) $id]);
    }

    /** @return list<array<string,mixed>> */
    private function tiers(int $eventId): array
    {
        return DB::table('gates_event_tiers')->where('event_id', $eventId)
            ->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Three tiers, cheapest first, as an organiser would first type them. */
    private function seedThree(int $id): void
    {
        $this->save($id, [
            'tier_name'  => ['General', 'Supporter', 'Patron'],
            'tier_price' => ['5000', '25000', '380000'],
        ]);
    }

    // ══ the order can be changed at all ══════════════════════════════════════

    /** The submitted row order is the stored ladder. */
    public function test_the_submitted_order_becomes_the_stored_order(): void
    {
        $id = $this->event();
        $this->seedThree($id);

        $this->assertSame(['General', 'Supporter', 'Patron'],
            array_column($this->tiers($id), 'name'));
        $this->assertSame([0, 1, 2],
            array_map('intval', array_column($this->tiers($id), 'sort_order')));

        $ids = array_column($this->tiers($id), 'id', 'name');

        // Patron moved to the top — the same three rows, resubmitted in a new order with
        // their ids travelling with them, which is exactly what the repeater posts.
        $this->save($id, [
            'tier_id'    => [(string) $ids['Patron'], (string) $ids['General'], (string) $ids['Supporter']],
            'tier_name'  => ['Patron', 'General', 'Supporter'],
            'tier_price' => ['380000', '5000', '25000'],
        ]);

        $this->assertSame(['Patron', 'General', 'Supporter'],
            array_column($this->tiers($id), 'name'),
            'the ladder did not follow the order the form submitted');
    }

    /**
     * THE ONE THAT MATTERS. A move must not renumber the tiers.
     *
     * `gates_event_registrations.tier_id` points at these ids. Reordering by rewriting the
     * names down a fixed set of ids would hand every Patron ticket-holder a General ticket,
     * retroactively, with the wrong ticket already sitting in their inbox — and every row
     * on this screen would still look exactly right.
     */
    public function test_moving_a_tier_does_not_renumber_the_tiers(): void
    {
        $id = $this->event();
        $this->seedThree($id);

        $before = array_column($this->tiers($id), 'id', 'name');

        $this->save($id, [
            'tier_id'    => [(string) $before['Patron'], (string) $before['General'], (string) $before['Supporter']],
            'tier_name'  => ['Patron', 'General', 'Supporter'],
            'tier_price' => ['380000', '5000', '25000'],
        ]);

        $after = array_column($this->tiers($id), 'id', 'name');

        $this->assertSame($before['Patron'], $after['Patron'],
            'the Patron tier changed id when it moved — every ticket sold against it now '
            . 'points at a different tier');
        $this->assertSame($before['General'], $after['General']);
        $this->assertSame($before['Supporter'], $after['Supporter']);

        // And no row was recreated behind the scenes: three in, three out.
        $this->assertCount(3, $this->tiers($id));
    }

    /** A moved tier keeps its price, its perk and its capacity — this moves rows, not values. */
    public function test_a_moved_tier_carries_its_own_figures_with_it(): void
    {
        $id = $this->event();
        $this->save($id, [
            'tier_name'     => ['General', 'Patron'],
            'tier_price'    => ['5000', '380000'],
            'tier_capacity' => ['200', '12'],
            'tier_perk'     => ['Admission', 'Front table and the after-party'],
        ]);

        $ids = array_column($this->tiers($id), 'id', 'name');

        $this->save($id, [
            'tier_id'       => [(string) $ids['Patron'], (string) $ids['General']],
            'tier_name'     => ['Patron', 'General'],
            'tier_price'    => ['380000', '5000'],
            'tier_capacity' => ['12', '200'],
            'tier_perk'     => ['Front table and the after-party', 'Admission'],
        ]);

        $t = $this->tiers($id);
        $this->assertSame('Patron', $t[0]['name']);
        $this->assertSame(380000, (int) $t[0]['price_naira'],
            'the name moved and the price stayed behind');
        $this->assertSame(12, (int) $t[0]['capacity']);
        $this->assertSame('Front table and the after-party', $t[0]['description']);
    }

    // ══ what the buyer actually sees ═════════════════════════════════════════

    /**
     * The public reader follows. Storing an order nobody renders would be the same bug in
     * a different column.
     */
    public function test_the_public_tier_list_follows_the_organisers_order(): void
    {
        $id = $this->event();
        $this->seedThree($id);
        $ids = array_column($this->tiers($id), 'id', 'name');

        $this->save($id, [
            'tier_id'    => [(string) $ids['Patron'], (string) $ids['Supporter'], (string) $ids['General']],
            'tier_name'  => ['Patron', 'Supporter', 'General'],
            'tier_price' => ['380000', '25000', '5000'],
        ]);

        $this->assertSame(['Patron', 'Supporter', 'General'],
            array_column(EventTicketService::tiers($id), 'name'),
            'the organiser reordered the ladder and the buyer still sees the old one');
    }

    // ══ the mechanism has a route in ═════════════════════════════════════════

    /**
     * §18 · the control exists on the screen, and it is a button that does NOT submit.
     *
     * The tier list sits inside the event's own <form>, where a <button> with no `type`
     * defaults to `submit`. Without `type="button"` on both arrows, nudging a tier down
     * would save and redirect the whole event — the same family as the nested <form> that
     * ate the questionnaire's outcome list, and just as invisible, because saving is
     * exactly what the page looks like it should do.
     */
    public function test_the_move_control_is_on_the_form_and_cannot_submit_it(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        $this->assertStringContainsString('move(r.id, -1)', $tpl, 'no control moves a tier up');
        $this->assertStringContainsString('move(r.id, 1)', $tpl, 'no control moves a tier down');

        // Every button on the screen declares its type. A bare <button> here submits.
        //
        // Twig comments are stripped first, and that is not incidental tidying: the block
        // explaining this very rule contains the words "<button> with no type", and
        // scanning raw source made the prose fail the assertion its own code satisfied.
        // A test that reads documentation as markup breaks on the next person who writes
        // a comment about buttons.
        $markup = (string) preg_replace('/\{#.*?#\}/s', '', $tpl);

        preg_match_all('/<button\b[^>]*>/i', $markup, $m);
        $this->assertNotEmpty($m[0], 'the button scan matched nothing — it is asserting air');
        foreach ($m[0] as $tag) {
            $this->assertMatchesRegularExpression('/\btype=/i', $tag,
                'a <button> inside the event form with no type= defaults to submit: ' . $tag);
        }

        // The mechanism itself, so a control wired to a method nobody defined is caught.
        $this->assertStringContainsString('move(id, delta)', $tpl,
            'the arrows call a method the repeater does not define');
    }

    /** Both ends of the ladder are pinned, so an arrow never walks a row off the list. */
    public function test_the_arrows_are_disabled_at_the_ends(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        $this->assertStringContainsString(':disabled="i === 0"', $tpl,
            'the top row can be moved up');
        $this->assertStringContainsString(':disabled="i === rows.length - 1"', $tpl,
            'the bottom row can be moved down');
    }
}
