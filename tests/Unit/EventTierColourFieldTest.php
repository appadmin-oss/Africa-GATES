<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\EventsController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\CacheService;
use AfricaGates\Services\EventTierPalette;
use AfricaGates\Services\EventTierTone;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The organiser setting a tier's colour — the half of this feature that did not exist.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A WHOLE MECHANISM WITH NO ROUTE IN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `EventTierPalette` shipped six named slots, a redmean separation pass so no two swatches
 * read as the same colour on any accent, and a per-swatch `edge` guaranteed to clear 3:1
 * against white. Its class docblock explains at length why the column stores a slot rather
 * than a hex, and why "no colour" is the ordinary case rather than a missing value. The
 * printed ticket reads it. A migration created the column. A test asserted that changing an
 * event's accent moves the tier's colour with it.
 *
 * **Nothing in the admin could write it.** There was no field on the event form, and
 * `saveTiers()` did not read one — so `gates_event_tiers.colour` was NULL for every tier on
 * the platform, and every surface that reads a tier colour fell back to a default. Each part
 * was complete and correct in isolation.
 *
 * That is the third instance of the pattern in `docs/CODEBASE-INDEX.md` §18, and it is why
 * the registration card's selection light swept the platform green for everybody: the colour
 * it was told to match was a colour nobody could set.
 */
final class EventTierColourFieldTest extends TestCase
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

    private function event(string $accent = '#2A6FDB'): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Founders Night', 'slug' => 'founders-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+40 days')),
            'status' => 'published', 'ticket_accent' => $accent,
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
            ->orderBy('sort_order')->get()->map(fn ($r) => (array) $r)->all();
    }

    // ══ the route in ═════════════════════════════════════════════════════════

    public function test_an_organiser_can_set_a_tier_colour_at_all(): void
    {
        $id = $this->event();
        $this->save($id, [
            'tier_name'   => ['General', 'Patron'],
            'tier_price'  => ['5000', '380000'],
            'tier_colour' => ['soft', 'bold'],
        ]);

        $t = $this->tiers($id);
        $this->assertCount(2, $t, 'the tiers did not save at all');
        $this->assertSame('soft', $t[0]['colour']);
        $this->assertSame('bold', $t[1]['colour']);
    }

    public function test_no_colour_is_stored_as_null_and_not_as_an_empty_string(): void
    {
        // NULL is the default and a real answer: a ladder where every row is coloured
        // because the field had to be filled in is noisier than one where the organiser
        // marked the two that matter. `EventTierPalette::forTier()` returns null for it and
        // the ticket renders no dot, which is different from rendering a grey one.
        $id = $this->event();
        $this->save($id, [
            'tier_name'   => ['General'],
            'tier_price'  => ['5000'],
            'tier_colour' => [''],
        ]);

        $this->assertNull($this->tiers($id)[0]['colour']);
    }

    public function test_a_colour_survives_a_later_save_that_does_not_change_it(): void
    {
        // saveTiers() writes the whole row, so a field the form forgets to post is a field
        // that gets blanked. This is the assertion that the colour is genuinely round-tripped
        // through the editor rather than written once and lost on the next price change.
        $id = $this->event();
        $this->save($id, ['tier_name' => ['Patron'], 'tier_price' => ['380000'],
                          'tier_colour' => ['deep']]);
        $tid = $this->tiers($id)[0]['id'];

        $this->save($id, ['tier_id' => [(string) $tid], 'tier_name' => ['Patron'],
                          'tier_price' => ['420000'], 'tier_colour' => ['deep']]);

        $t = $this->tiers($id)[0];
        $this->assertSame('deep', $t['colour']);
        $this->assertSame(420000, (int) $t['price_naira'], 'the price change did not land');
    }

    public function test_a_colour_can_be_cleared_again(): void
    {
        $id = $this->event();
        $this->save($id, ['tier_name' => ['Patron'], 'tier_price' => ['380000'],
                          'tier_colour' => ['deep']]);
        $tid = $this->tiers($id)[0]['id'];

        $this->save($id, ['tier_id' => [(string) $tid], 'tier_name' => ['Patron'],
                          'tier_price' => ['380000'], 'tier_colour' => ['']]);

        $this->assertNull($this->tiers($id)[0]['colour']);
    }

    // ══ it is a slot, and only ever a slot ═══════════════════════════════════

    public function test_anything_that_is_not_a_slot_is_refused(): void
    {
        // The value reaches a `style` attribute on the public page. A slot column can only
        // ever produce output EventTierPalette computed; a hex column is a string somebody
        // posted. `EventTierPalette::slot()` is the one gate and it is applied on save.
        $id = $this->event();
        $this->save($id, ['tier_name' => ['General'], 'tier_price' => ['5000'],
                          'tier_colour' => ['deep']]);
        $tid = (string) $this->tiers($id)[0]['id'];

        foreach (['#ff0000', 'red', 'javascript:alert(1)', 'DEEP; --x:1', '../../etc'] as $bad) {
            $this->save($id, ['tier_id' => [$tid], 'tier_name' => ['General'],
                              'tier_price' => ['5000'], 'tier_colour' => [$bad]]);

            // Refused AND the previous good value replaced with null rather than kept: the
            // row is written whole, and a rejected value must not leave a colour the
            // organiser did not choose.
            $this->assertNull($this->tiers($id)[0]['colour'], $bad . ' was stored');
        }
    }

    public function test_every_offered_slot_is_actually_storable(): void
    {
        // The form renders its options from EventTierPalette::SLOTS. A slot offered in the
        // picker and refused on save is a field that silently discards a choice.
        $id = $this->event();
        $this->save($id, ['tier_name' => ['General'], 'tier_price' => ['5000'],
                          'tier_colour' => ['']]);
        // The id matters: without it saveTiers() INSERTS, and the new row collides with the
        // existing one on UNIQUE(event_id, slug) — the insert is caught and logged, the tier
        // is lost, and the assertion fails on the collision rather than on the colour. The
        // editor always posts tier_id for a row that exists.
        $tid = (string) $this->tiers($id)[0]['id'];

        foreach (array_keys(EventTierPalette::SLOTS) as $slot) {
            $this->save($id, ['tier_id' => [$tid], 'tier_name' => ['General'],
                              'tier_price' => ['5000'], 'tier_colour' => [$slot]]);

            $this->assertSame($slot, $this->tiers($id)[0]['colour'], $slot . ' was refused');
        }
    }

    // ══ and it reaches the two surfaces that read it ═════════════════════════

    public function test_the_saved_colour_is_what_the_registration_card_sweeps(): void
    {
        // The point of the whole change. One choice by the organiser, and the light on the
        // card is the swatch they picked — not a platform default, and not a derived
        // variant of it.
        $id = $this->event('#2A6FDB');
        $this->save($id, ['tier_name' => ['Patron'], 'tier_price' => ['380000'],
                          'tier_colour' => ['bold']]);

        $tier  = $this->tiers($id)[0];
        $event = (array) DB::table('gates_site_events')->where('id', $id)->first();

        $expected = EventTierPalette::forTier($tier, $event);
        $this->assertNotNull($expected, 'the slot did not resolve to a swatch');
        $this->assertSame($expected['fill'], EventTierTone::hue($tier, $event));
        $this->assertNotSame(EventTierTone::DEFAULT_HUE, EventTierTone::hue($tier, $event));
    }

    public function test_changing_the_events_accent_moves_the_light_too(): void
    {
        // The reason the column holds a slot. A hex chosen against the old accent would
        // still be here; the tier ladder, the printed ticket AND the selection light all
        // move together because all three read one resolver.
        $id = $this->event('#2A6FDB');
        $this->save($id, ['tier_name' => ['Patron'], 'tier_price' => ['380000'],
                          'tier_colour' => ['bold']]);
        $tier   = $this->tiers($id)[0];
        $before = EventTierTone::hue($tier, (array) DB::table('gates_site_events')->where('id', $id)->first());

        DB::table('gates_site_events')->where('id', $id)->update(['ticket_accent' => '#B4452F']);
        $after = EventTierTone::hue($tier, (array) DB::table('gates_site_events')->where('id', $id)->first());

        $this->assertNotSame($before, $after);
    }

    // ══ the form ═════════════════════════════════════════════════════════════

    public function test_the_editor_actually_renders_the_picker(): void
    {
        // Reading the template as a string proves the markup is written. It does not prove
        // Twig compiles it, and `tier_palette` is a new variable iterated in two places —
        // a missing key renders as an empty loop with a clean 200, which looks exactly like
        // an event whose accent has no colours.
        $id = $this->event('#2A6FDB');
        $this->save($id, ['tier_name' => ['Patron'], 'tier_price' => ['380000'],
                          'tier_colour' => ['bold']]);

        // Through the CONTAINER, not the bare controller the POST tests build: a plain
        // Twig::create() has none of the app's extensions, so `asset()` in the admin layout
        // throws before the tier editor is reached. The POSTs do not render, which is why
        // they can get away with it.
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(EventsController::class);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/events/' . $id . '/edit');
        $res = $ctrl->form($req, new Response(), ['id' => (string) $id]);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="tier_colour[]"', $html);
        // All six slots offered, plus "No colour".
        $this->assertSame(
            count(EventTierPalette::SLOTS) + 1,
            substr_count(substr($html, strpos($html, 'name="tier_colour[]"') ?: 0, 1400), '<option'),
            'the picker is not offering every slot'
        );
        // And the swatch strip resolved real hexes for THIS accent rather than printing
        // the slot names alone.
        foreach (EventTierPalette::fromAccent('#2A6FDB') as $sw) {
            $this->assertStringContainsString($sw['fill'], $html, 'a swatch did not resolve');
        }
        // The saved slot comes back in the seed Alpine binds to, or editing a price
        // silently clears the colour. Entities decoded first: the seed goes through
        // `|e('html_attr')`, which turns every quote into `&quot;` — asserting on the raw
        // bytes would be asserting against the escaper.
        $this->assertStringContainsString(
            '"colour":"bold"',
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    public function test_the_event_form_has_the_field_and_names_the_colours(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        $this->assertStringContainsString('name="tier_colour[]"', $tpl);
        // Not a bare word list: the six slots are rendered with the hex they resolve to for
        // THIS event, so "Warm" is a colour an organiser can recognise before choosing it.
        $this->assertStringContainsString('tier_palette', $tpl);
        $this->assertStringContainsString('data-fill=', $tpl);
        $this->assertStringContainsString('data-edge=', $tpl);
    }

    public function test_the_live_swatch_needs_no_inline_handler(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/form.twig');
        $js  = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/js/admin.js');

        // The admin CSP has no 'unsafe-inline', so an inline onchange does not merely get
        // discouraged — it silently never runs, and CspTest fails the build over it.
        $this->assertStringContainsString('data-ag-do="tier-colour"', $tpl);
        $this->assertDoesNotMatchRegularExpression('/name="tier_colour\[\]"[^>]*onchange=/', $tpl);
        $this->assertStringContainsString('[data-ag-do="tier-colour"]', $js);
        // The hex comes off the option, never from a palette copied into JS — a second
        // palette would drift the first time somebody changed the accent.
        $this->assertStringContainsString("getAttribute('data-fill')", $js);
    }

    public function test_the_field_is_hidden_when_the_column_is_not_migrated(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        // `colour` arrived on its own dated migration. On a deployment that has uploaded
        // this code and not run /__setup/migrate, writing it would throw inside saveTiers()'
        // try/catch — losing the WHOLE tier silently, which is far worse than not offering
        // the field. Every other block on this screen is guarded the same way.
        $this->assertStringContainsString('tier_colour_missing is empty', $tpl);
        $this->assertStringContainsString(
            "OptionalColumn::on('gates_event_tiers', 'colour')",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/EventsController.php')
        );
    }

    public function test_the_editor_shows_back_the_colour_that_was_saved(): void
    {
        // The seed feeds Alpine's x-model. Without the key the select would post nothing on
        // the next save and the colour would be silently cleared by the act of editing a
        // price — which is the shape of bug this whole file exists to catch.
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/EventsController.php');

        $this->assertMatchesRegularExpression("/'colour' => \\(string\\) \\(\\\\AfricaGates\\\\Services\\\\EventTierPalette::slot/", $src);
        // And the blank row the "+ Add tier" button creates carries it too, or a new tier's
        // select binds onto an undefined property.
        $this->assertStringContainsString(
            "colour:''",
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/events/form.twig')
        );
    }
}
