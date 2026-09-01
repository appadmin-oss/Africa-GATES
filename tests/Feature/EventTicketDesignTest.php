<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\EventTicketDesign as D;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The ticket's appearance.
 *
 * Two things are being proved here, and they matter for different reasons.
 *
 * FIRST, THAT AN UNCONFIGURED EVENT IS UNCHANGED. Somebody who has never opened the new panel
 * must not discover that their tickets look different. Every default below is the literal
 * value the old template had hard-coded, and a test that fails here is a migration that
 * changed the look of live tickets.
 *
 * SECOND, THAT THE ACCENT COLOUR CANNOT CARRY ANYTHING BUT A COLOUR. It ends up inside a
 * `style` attribute, which makes it the one field on the organiser's form whose value is
 * executed rather than displayed. It is REFUSED rather than escaped, and refused on the way
 * in AND on the way out — validating only on write would be trusting the database to be the
 * thing the validation is protecting.
 */
final class EventTicketDesignTest extends TestCase
{
    // ══ 1. an unconfigured event is exactly what it was ══════════════════════

    public function test_an_event_nobody_configured_gets_the_original_ticket(): void
    {
        $d = D::forEvent(null);

        $this->assertSame('#10292C', $d['accent'], 'the default accent changed — live tickets would change colour');
        $this->assertSame('dark', $d['theme']);
        $this->assertSame('', $d['image']);
        $this->assertSame('', $d['note']);
        $this->assertSame(['seats', 'price'], $d['rows'], 'the default rows changed');
        $this->assertTrue($d['show_qr'], 'the QR must be opt-out, never opt-in');
        $this->assertFalse($d['customised']);
    }

    public function test_an_empty_row_reads_the_same_as_no_row(): void
    {
        // The same event fetched as an array, as an object, and as a row of empty strings —
        // three shapes the callers actually pass — must give one answer.
        $blank = ['ticket_accent' => '', 'ticket_theme' => '', 'ticket_rows' => null];
        $this->assertSame(D::forEvent(null), D::forEvent($blank));
        $this->assertSame(D::forEvent(null), D::forEvent((object) $blank));
    }

    public function test_customised_is_false_until_somebody_actually_chooses_something(): void
    {
        // The admin screen says "using the standard design" on the strength of this, rather
        // than presenting defaults as choices somebody made.
        $this->assertFalse(D::isCustomised(['ticket_accent' => '', 'ticket_note' => '   ']));
        $this->assertTrue(D::isCustomised(['ticket_accent' => '#ff0000']));
        $this->assertTrue(D::isCustomised(['ticket_note' => 'Doors 5pm']));
        // A stored 0 for the QR is a CHOICE — the tri-state the migration deliberately kept.
        $this->assertTrue(D::isCustomised(['ticket_show_qr' => 0]));
        $this->assertFalse(D::isCustomised(['ticket_show_qr' => null]));
    }

    // ══ 2. the colour ════════════════════════════════════════════════════════

    public function test_a_hex_colour_is_accepted_in_the_forms_people_type(): void
    {
        $this->assertSame('#FF6B00', D::colour('#ff6b00'));
        $this->assertSame('#FF6B00', D::colour('  #FF6B00  '));
        $this->assertSame('#FF6B00', D::colour('ff6b00'), 'a pasted hex without the hash');
        $this->assertSame('#AABBCC', D::colour('#abc'), 'shorthand must expand');
    }

    public function test_anything_that_is_not_a_colour_is_refused_rather_than_cleaned_up(): void
    {
        // Refused, not sanitised: there is no legitimate accent value that is not a hex
        // colour, so a half-cleaned string is a value nobody intended reaching a style
        // attribute. Every one of these must come back as the default.
        foreach ([
            'red',
            'rgb(255,0,0)',
            '#12345',
            '#1234567',
            '#gggggg',
            'url(javascript:alert(1))',
            '#fff;background:url(//evil.test/x)',
            'expression(alert(1))',
            '#fff" onload="alert(1)',
            "#fff\n;color:red",
            '</style><script>alert(1)</script>',
            'var(--x)',
        ] as $bad) {
            $this->assertSame(D::DEFAULT_ACCENT, D::colour($bad),
                "accepted something that is not a colour: {$bad}");
        }
    }

    public function test_what_reaches_a_style_attribute_is_only_ever_hex_or_digits(): void
    {
        // The two derived values are built FROM the validated hex, so their shape is
        // guaranteed by construction rather than by escaping. Asserted because both are
        // interpolated into CSS.
        foreach (['#ff0000', 'not a colour', '#fff;x', 'abc'] as $in) {
            $d = D::forEvent(['ticket_accent' => $in]);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $d['accent']);
            $this->assertMatchesRegularExpression('/^rgba\(\d{1,3},\d{1,3},\d{1,3},[01]\.\d{2}\)$/',
                $d['accent_soft']);
            $this->assertContains($d['ink'], ['#10292C', '#FFFFFF']);
        }
    }

    public function test_the_text_colour_is_computed_so_a_pale_accent_stays_readable(): void
    {
        // An organiser who picks a pale yellow and is handed white text has been given a
        // ticket nobody can read, and no way to find out why. So this is derived, not chosen.
        $this->assertSame('#FFFFFF', D::contrastInk('#10292C'), 'dark ink on a dark accent');
        $this->assertSame('#FFFFFF', D::contrastInk('#7d2b24'));
        $this->assertSame('#10292C', D::contrastInk('#ffe066'), 'white on pale yellow is unreadable');
        $this->assertSame('#10292C', D::contrastInk('#ffffff'));
        // Bright green: the case a naive channel average gets wrong, because #00FF00 and
        // #0000FF have the same mean and nothing like the same brightness.
        $this->assertSame('#10292C', D::contrastInk('#00ff00'));
        $this->assertSame('#FFFFFF', D::contrastInk('#0000ff'));
    }

    // ══ 3. the theme and the rows ════════════════════════════════════════════

    public function test_the_theme_is_one_of_a_fixed_set(): void
    {
        // Not passed through into a class name. A free string here would put whatever an
        // organiser typed into the markup.
        $this->assertSame('light', D::theme('light'));
        $this->assertSame('dark', D::theme('DARK'));
        $this->assertSame(D::DEFAULT_THEME, D::theme('neon'));
        $this->assertSame(D::DEFAULT_THEME, D::theme('dark" onload="x'));
    }

    public function test_choosing_no_rows_is_different_from_never_choosing(): void
    {
        // NULL = never opened the panel → the defaults. '' = deliberately unticked
        // everything → nothing. Flattening those together would hand the defaults back to
        // an organiser every time they saved, and they would never work out why.
        $this->assertSame(['seats', 'price'], D::rows(null));
        $this->assertSame([], D::rows(''));
        $this->assertSame(['seat'], D::rows('seat'));
    }

    public function test_unknown_rows_are_dropped_and_the_order_is_fixed(): void
    {
        // Intersected with the known list, so a stale or hand-edited value cannot ask the
        // template for a field that does not exist.
        $this->assertSame(['seats', 'price'], D::rows('price,seats,nonsense,<script>'));
        // ROWS' own order, not the stored order — otherwise a ticket's rows reshuffle
        // because somebody re-ticked a box.
        $this->assertSame(D::rows('price,seat'), D::rows('seat,price'));
    }

    public function test_packing_and_reading_rows_round_trips(): void
    {
        foreach ([[], ['seat'], ['seats', 'price'], array_keys(D::ROWS)] as $chosen) {
            $this->assertSame(
                D::rows(D::packRows($chosen)),
                array_values(array_filter(array_keys(D::ROWS),
                    static fn (string $k): bool => in_array($k, $chosen, true))),
                'a chosen set did not survive a save/load cycle'
            );
        }
    }

    public function test_the_qr_is_opt_out(): void
    {
        $this->assertTrue(D::showQr(null), 'an unconfigured event must still print a QR');
        $this->assertTrue(D::showQr(1));
        $this->assertTrue(D::showQr('1'));
        $this->assertFalse(D::showQr(0));
        $this->assertFalse(D::showQr('0'));
    }

    // ══ 4. the image ═════════════════════════════════════════════════════════

    public function test_the_ticket_image_falls_back_to_the_events_cover(): void
    {
        // cover_image existed all along and the ticket never looked at it — most of the
        // visual difference is this one line.
        $this->assertSame('/uploads/cover.jpg',
            D::image(['cover_image' => '/uploads/cover.jpg']));
        // And the override wins, because a wide hero crops badly into a ticket header and an
        // organiser should not have to choose between a good page and a good ticket.
        $this->assertSame('/uploads/ticket.jpg',
            D::image(['cover_image' => '/uploads/cover.jpg', 'ticket_image' => '/uploads/ticket.jpg']));
    }

    public function test_a_bare_stored_path_is_made_absolute(): void
    {
        // Older rows hold `uploads/x.jpg` with no leading slash, which resolves relative to
        // whatever page is rendering — so it works on /events/x and 404s on /events/ticket/y.
        $this->assertSame('/uploads/x.jpg', D::image(['cover_image' => 'uploads/x.jpg']));
    }

    public function test_only_a_same_site_path_or_a_real_web_url_is_used(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
            '//evil.test/x.jpg',              // protocol-relative, i.e. off-site
            '../../../etc/passwd',
            'file:///etc/passwd',
            'uploads/../../secret.jpg',
        ] as $bad) {
            $this->assertSame('', D::image(['ticket_image' => $bad]),
                "accepted an image source it should refuse: {$bad}");
        }
        $this->assertSame('https://cdn.test/a.jpg', D::image(['ticket_image' => 'https://cdn.test/a.jpg']));
    }

    // ══ 5. reading the form ══════════════════════════════════════════════════

    public function test_the_form_stores_null_for_blank_rather_than_the_default(): void
    {
        // A stored default is indistinguishable from a choice. If '' were saved as
        // '#10292C', then the first time the platform's house colour changed, every event
        // nobody had ever touched would stay pinned to the old one.
        $got = D::fromForm(['ticket_accent' => '', 'ticket_theme' => '', 'ticket_show_qr' => '1']);
        $this->assertNull($got['ticket_accent']);
        $this->assertNull($got['ticket_theme']);
    }

    public function test_the_form_validates_the_colour_before_it_is_stored(): void
    {
        $got = D::fromForm(['ticket_accent' => '#fff;background:url(//evil.test/x)']);
        $this->assertSame(D::DEFAULT_ACCENT, $got['ticket_accent'],
            'a non-colour reached the database');

        $ok = D::fromForm(['ticket_accent' => 'ff6b00']);
        $this->assertSame('#FF6B00', $ok['ticket_accent']);
    }

    public function test_an_unticked_qr_box_is_stored_as_a_deliberate_no(): void
    {
        // An unchecked checkbox posts NOTHING, so the absence has to be read as 0 and not as
        // "leave it alone" — otherwise the box could be ticked and never unticked.
        $this->assertSame(0, D::fromForm([])['ticket_show_qr']);
        $this->assertSame(1, D::fromForm(['ticket_show_qr' => '1'])['ticket_show_qr']);
    }

    public function test_the_stub_note_is_collapsed_and_bounded(): void
    {
        $got = D::fromForm(['ticket_note' => "  Doors   5pm\n·  Black tie  "]);
        $this->assertSame('Doors 5pm · Black tie', $got['ticket_note']);
        $this->assertNull(D::fromForm(['ticket_note' => '   '])['ticket_note']);
        $this->assertSame(160, mb_strlen((string) D::fromForm(['ticket_note' => str_repeat('x', 400)])['ticket_note']));
    }

    public function test_an_image_that_would_be_refused_on_render_is_not_stored(): void
    {
        // Otherwise the field appears to save and the ticket shows nothing, which reads as a
        // broken editor rather than a rejected value.
        $this->assertNull(D::fromForm(['ticket_image' => 'javascript:alert(1)'])['ticket_image']);
        $this->assertSame('/uploads/a.jpg', D::fromForm(['ticket_image' => '/uploads/a.jpg'])['ticket_image']);
    }

    // ══ 6. end to end against the real table ═════════════════════════════════

    public function test_a_saved_design_comes_back_out_of_the_database(): void
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'design-rt-' . bin2hex(random_bytes(3)),
            'title' => 'Design round trip',
            'event_date' => '2026-11-01 18:00:00',
            'status' => 'published',
        ]);

        DB::table('gates_site_events')->where('id', $id)->update(
            D::fromForm([
                'ticket_accent'  => '#7D2B24',
                'ticket_theme'   => 'light',
                'ticket_note'    => 'Doors 5pm · Black tie',
                'ticket_rows'    => ['seat', 'price', 'bogus'],
                'ticket_show_qr' => '1',
            ])
        );

        $d = D::forEvent(DB::table('gates_site_events')->where('id', $id)->first());
        $this->assertSame('#7D2B24', $d['accent']);
        $this->assertSame('light', $d['theme']);
        $this->assertSame('Doors 5pm · Black tie', $d['note']);
        $this->assertSame(['seat', 'price'], $d['rows'], 'an unknown row survived the round trip');
        $this->assertTrue($d['show_qr']);
        $this->assertTrue($d['customised']);
        $this->assertSame('#FFFFFF', $d['ink'], 'dark red needs white text');
    }

    public function test_a_row_written_by_something_other_than_the_form_is_still_refused(): void
    {
        // The reason the colour is validated on READ as well: a direct SQL edit, a restored
        // backup, or an older build can all put something in this column that never went
        // through fromForm(). Validating only on write trusts the database to be the thing
        // the validation exists to protect.
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'design-raw-' . bin2hex(random_bytes(3)),
            'title' => 'Raw write',
            'event_date' => '2026-11-01 18:00:00',
            'status' => 'published',
        ]);
        // ── SEVEN CHARACTERS, BECAUSE THAT IS ALL THE COLUMN HOLDS ───────────
        //
        // `ticket_accent` is VARCHAR(7) on MySQL — exactly `#RRGGBB` — so the 30-character
        // script tag this used to write was refused by the database and the test errored
        // before asserting anything. The realistic hostile row is therefore a SHORT one
        // that is simply not a colour, and the reader has to refuse it on shape rather
        // than on length.
        DB::table('gates_site_events')->where('id', $id)
            ->update(['ticket_accent' => 'red;}', 'ticket_theme' => 'neon']);

        $d = D::forEvent(DB::table('gates_site_events')->where('id', $id)->first());
        $this->assertSame(D::DEFAULT_ACCENT, $d['accent']);
        $this->assertSame(D::DEFAULT_THEME, $d['theme']);
    }
}
