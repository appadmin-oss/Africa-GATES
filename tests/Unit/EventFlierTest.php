<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\EventsController;
use AfricaGates\Services\{EventFlier, EventFlierLayout, EventFlierToken};
use AfricaGates\Support\Qr;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The "I will be there" flier.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS ACTUALLY AT STAKE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Three things, and none of them is the layout.
 *
 * **It prints a name.** So the address cannot be enumerable, or anybody who can count could
 * render a stranger's name and tier over this event's branding and post it — and the platform
 * would have generated it. That is not a leak in the usual sense; it is worse, because the
 * artefact is designed to be shared and looks official.
 *
 * **It must never print the negative.** No "ticket not confirmed", no "pending", no dimmed
 * badge. Absence of the mark is the signal. A flier that states its own weakness is a flier
 * nobody sends, and it takes the reach with it — so the tests below check for the absence of
 * those strings as hard as for the presence of the mark.
 *
 * **It refuses a finished event.** A live QR on an evening that has already happened is worse
 * than no flier: somebody scans it, finds nothing, and concludes the platform is broken.
 */
final class EventFlierTest extends TestCase
{
    private int $eventId = 0;
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = 'gala-' . bin2hex(random_bytes(4));
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Africa GATES Gala', 'slug' => $this->slug,
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'published', 'location' => 'Lagos',
        ]);
    }

    private function base(): string { return 'https://afg.example.org'; }

    /** @param array<string,mixed> $over */
    private function registration(array $over = []): int
    {
        return (int) DB::table('gates_event_registrations')->insertGetId($over + [
            'event_id' => $this->eventId, 'tier' => 'Patron',
            'reference' => 'AFG-EVT-' . bin2hex(random_bytes(4)),
            'name' => 'Ada Nwosu', 'email' => 'ada@example.test',
            'status' => 'confirmed', 'amount_naira' => 380000,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ══ the token is the whole security model ════════════════════════════════

    public function test_a_token_round_trips(): void
    {
        $t = EventFlierToken::mint($this->eventId, 'Ada Nwosu', 42);
        $r = EventFlierToken::read($t);

        $this->assertSame(['event' => $this->eventId, 'registration' => 42, 'name' => 'Ada Nwosu'], $r);
    }

    public function test_a_tampered_token_is_refused(): void
    {
        $t = EventFlierToken::mint($this->eventId, 'Ada Nwosu', 42);
        [$payload, $sig] = explode('.', $t, 2);

        // Re-encode the payload with somebody else's name and keep the signature. This is the
        // attack the signature exists for, and it is the one worth naming in a test.
        $forged = rtrim(strtr(base64_encode(
            $this->eventId . '|42|' . rtrim(strtr(base64_encode('Someone Else'), '+/', '-_'), '=')
            . '|' . (time() + 600)
        ), '+/', '-_'), '=');

        $this->assertNull(EventFlierToken::read($forged . '.' . $sig));
        // And a flipped signature, which is the lazier version of the same thing.
        $this->assertNull(EventFlierToken::read($payload . '.' . strrev($sig)));
    }

    public function test_an_expired_token_is_refused(): void
    {
        // Built by hand at an expiry in the past, signed correctly — so what is under test is
        // the expiry check and not the signature.
        $r = new \ReflectionMethod(EventFlierToken::class, 'sign');
        $r->setAccessible(true);
        $payload = $this->eventId . '|0|'
                 . rtrim(strtr(base64_encode('Ada Nwosu'), '+/', '-_'), '=')
                 . '|' . (time() - 5);
        $token = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
               . '.' . $r->invoke(null, $payload);

        $this->assertNull(EventFlierToken::read($token));
    }

    public function test_malformed_tokens_are_refused_without_throwing(): void
    {
        foreach (['', '.', 'x', 'x.y', '....', 'YWJj', 'YWJj.', '!!!.???',
                  str_repeat('A', 4000)] as $junk) {
            $this->assertNull(EventFlierToken::read($junk), 'accepted: ' . $junk);
        }
    }

    public function test_a_name_with_a_separator_in_it_cannot_shift_the_fields(): void
    {
        // The payload is pipe-delimited, so a name containing a pipe would be read as a
        // registration id if the name were not encoded. It is base64url for that reason.
        $t = EventFlierToken::mint($this->eventId, 'Ada|999|Nwosu', 7);
        $r = EventFlierToken::read($t);

        $this->assertSame(7, $r['registration'], 'the name shifted the fields');
        $this->assertSame('Ada|999|Nwosu', $r['name']);
    }

    public function test_a_name_carrying_control_characters_is_cleaned(): void
    {
        // This string is drawn onto an image by GD. A newline inside it draws nothing visible
        // and pushes the rest off the canvas — a blank flier, with no error anywhere.
        $this->assertSame('Ada Nwosu', EventFlierToken::cleanName("Ada\nNwosu"));
        $this->assertSame('Ada Nwosu', EventFlierToken::cleanName("  Ada \t Nwosu  "));
        $this->assertSame(EventFlierToken::NAME_MAX,
            mb_strlen(EventFlierToken::cleanName(str_repeat('a', 400))));
    }

    // ══ the two states ═══════════════════════════════════════════════════════

    public function test_a_confirmed_ticket_earns_the_mark_and_the_referral_qr(): void
    {
        $reg = $this->registration();
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu', $reg), $this->base());

        $this->assertNotNull($f);
        $this->assertTrue($f['confirmed']);
        $this->assertSame('Patron', $f['tier']);
        $this->assertSame(EventFlierLayout::QR_LABEL_CONFIRMED, $f['qr_label']);
        $this->assertStringContainsString('c=flier', $f['qr'], 'the flier must be attributable');
    }

    public function test_the_open_state_needs_only_a_name(): void
    {
        // No account, no ticket, no sign-in. This is the entry point the whole feature exists
        // for: the referral programme already sat behind a sign-in as a read-only field, which
        // is exactly why nobody used it.
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'A Friend'), $this->base());

        $this->assertNotNull($f);
        $this->assertFalse($f['confirmed']);
        $this->assertSame('', $f['tier']);
        $this->assertSame(EventFlierLayout::QR_LABEL_OPEN, $f['qr_label']);
        $this->assertStringContainsString('/events/' . $this->slug, $f['qr']);
        $this->assertStringNotContainsString('ref=', $f['qr']);
    }

    public function test_a_pending_registration_is_not_confirmed(): void
    {
        // A held seat is not a ticket. The flier would otherwise print "Ticket confirmed" over
        // a payment that has not landed.
        $reg = $this->registration(['status' => 'pending']);
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu', $reg), $this->base());

        $this->assertFalse($f['confirmed']);
    }

    public function test_a_token_cannot_claim_a_registration_from_another_event(): void
    {
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Other', 'slug' => 'other-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+20 days')), 'status' => 'published',
        ]);
        $reg = $this->registration();

        // Same registration id, different event in the token. The tier and the mark are read
        // from the registration, so without the event check this would print somebody's Patron
        // mark on a flier for an event they are not going to.
        $f = EventFlier::forToken(EventFlierToken::mint($other, 'Ada Nwosu', $reg), $this->base());

        $this->assertNotNull($f);
        $this->assertFalse($f['confirmed']);
    }

    // ══ the tier chip appears only where it flatters ══════════════════════════

    public function test_the_chip_is_an_allow_list_and_general_does_not_get_one(): void
    {
        // A chip reading "General" on a flier somebody is posting about themselves is a badge
        // that says "the cheapest one". Deliberate omission, not a missing case.
        foreach (['Patron' => true, 'Supporter' => true, 'patron' => true,
                  'VIP table' => true, 'General' => false, 'General admission' => false,
                  'Standard' => false, '' => false] as $tier => $earns) {
            $this->assertSame($earns, EventFlierLayout::chipFor((string) $tier),
                'chip decision wrong for ' . ($tier === '' ? '(blank)' : $tier));
        }
    }

    public function test_an_open_flier_has_no_chip_even_with_a_tier_name(): void
    {
        $reg = $this->registration(['status' => 'pending', 'tier' => 'Patron']);
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu', $reg), $this->base());

        $this->assertFalse($f['chip'], 'an unconfirmed flier must not wear a tier badge');
    }

    // ══ a finished event refuses ═════════════════════════════════════════════

    public function test_a_past_event_renders_nothing(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['event_date' => date('Y-m-d H:i:s', strtotime('-8 days'))]);

        $this->assertNull(EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu'), $this->base()));
    }

    public function test_a_cancelled_event_renders_nothing(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['status' => 'cancelled']);

        $this->assertNull(EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu'), $this->base()));
    }

    public function test_the_route_answers_410_and_not_an_image(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['status' => 'cancelled']);

        $res = $this->hit(EventFlierToken::mint($this->eventId, 'Ada Nwosu'), 'story');

        // 410, not 404: the address was valid and the thing behind it has ended, which is the
        // distinction a cache respects.
        $this->assertSame(410, $res->getStatusCode());
        $this->assertStringNotContainsString('image/png', $res->getHeaderLine('Content-Type'));
    }

    public function test_a_token_for_one_event_will_not_render_under_anothers_slug(): void
    {
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Other', 'slug' => 'other-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+20 days')), 'status' => 'published',
        ]);

        // The image would be right and the address would be a lie, which is the kind of thing
        // that gets screenshotted.
        $res = $this->hit(EventFlierToken::mint($other, 'Ada Nwosu'), 'story');

        $this->assertSame(410, $res->getStatusCode());
    }

    // ══ every format renders, and is the size it says ════════════════════════

    /** A real 3:2 JPEG on disk, so the photo formats have something to crop. */
    private function photo(): string
    {
        $path = sys_get_temp_dir() . '/ag-flier-' . bin2hex(random_bytes(4)) . '.jpg';
        $im = imagecreatetruecolor(900, 600);
        imagefilledrectangle($im, 0, 0, 899, 299, (int) imagecolorallocate($im, 190, 120, 60));
        imagefilledrectangle($im, 0, 300, 899, 599, (int) imagecolorallocate($im, 40, 70, 110));
        imagejpeg($im, $path, 90);
        imagedestroy($im);
        return $path;
    }

    public function test_all_three_formats_render_at_their_stated_size(): void
    {
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu', $this->registration()),
            $this->base());

        // WITH a photo, or `story` and `square` fall back to `plain` — see the next test,
        // which is the behaviour rather than a caveat about this one.
        $photo = $this->photo();
        try {
            foreach (EventFlierLayout::FORMATS as $fmt) {
                $png = (new EventFlier())->png($f, $fmt, $photo);
                $this->assertNotNull($png, $fmt . ' did not render');

                $im = imagecreatefromstring($png);
                $this->assertNotFalse($im, $fmt . ' is not a readable PNG');
                [$w, $h] = EventFlierLayout::size($fmt);
                $this->assertSame($w, imagesx($im), $fmt . ' width');
                $this->assertSame($h, imagesy($im), $fmt . ' height');
                imagedestroy($im);
            }
        } finally { @unlink($photo); }
    }

    public function test_no_photo_produces_plain_and_never_a_dark_layout_with_a_hole(): void
    {
        // Straight off the handoff's verify list, and it is what the first build got wrong:
        // `story` without a photo was 1120px of flat dark green above the type. `story` and
        // `square` are built around a face in the upper slot; `plain` is not a degraded
        // version of them, it is the design for the case where there is no face — and it is
        // the common case, because most people upload nothing.
        //
        // The fallback lives in the renderer rather than only in the generator, so a
        // hand-written URL cannot talk a format into drawing the hole.
        $f = EventFlier::forToken(EventFlierToken::mint($this->eventId, 'Ada Nwosu'), $this->base());

        foreach (['story', 'square'] as $fmt) {
            $im = imagecreatefromstring((string) (new EventFlier())->png($f, $fmt));
            $this->assertNotFalse($im);
            $this->assertSame(1080, imagesy($im),
                $fmt . ' without a photo should have rendered the plain design');

            // And it is the WARM ground, not the dark one with the slot filled in.
            $c = imagecolorsforindex($im, imagecolorat($im, 20, 20));
            $this->assertGreaterThan(200, $c['red'], $fmt . ' fell back to a dark layout');
            imagedestroy($im);
        }
    }

    public function test_an_unreadable_photo_falls_back_rather_than_leaving_a_gap(): void
    {
        $f = EventFlier::forToken(EventFlierToken::mint($this->eventId, 'Ada'), $this->base());

        $im = imagecreatefromstring(
            (string) (new EventFlier())->png($f, 'story', '/nonexistent/not-a-photo.jpg'));

        $this->assertNotFalse($im);
        $this->assertSame(1080, imagesy($im), 'a failed photo fetch should land on plain');
        imagedestroy($im);
    }

    public function test_a_photo_actually_lands_in_the_slot(): void
    {
        // Otherwise the fallback above would hide a broken cover-crop: every render would
        // quietly be `plain` and every test would pass.
        $f = EventFlier::forToken(EventFlierToken::mint($this->eventId, 'Ada'), $this->base());
        $photo = $this->photo();

        try {
            $im = imagecreatefromstring((string) (new EventFlier())->png($f, 'story', $photo));
            $this->assertSame(1920, imagesy($im), 'the photo format did not survive');

            // The picture's top half is orange; sample inside the slot, above the scrim.
            $c = imagecolorsforindex($im, imagecolorat($im, 540, 200));
            $this->assertGreaterThan($c['blue'] + 40, $c['red'],
                'the top of the slot is not the photo');
            imagedestroy($im);
        } finally { @unlink($photo); }
    }

    public function test_an_unknown_format_is_refused_rather_than_guessed(): void
    {
        $f = EventFlier::forToken(EventFlierToken::mint($this->eventId, 'Ada'), $this->base());

        $this->assertNull((new EventFlier())->png($f, 'billboard'));
        $this->assertFalse(EventFlierLayout::valid('billboard'));
    }

    public function test_a_long_name_does_not_break_any_layout(): void
    {
        // 40+ characters, from the handoff's own verify list. The claim and the name are
        // wrapped against the measured face rather than a character count, so this is the
        // test that the measuring is actually wired up.
        $long = 'Oluwaseun Adebayo-Okonkwo Chukwuemeka Nwachukwu';
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, $long, $this->registration()), $this->base());

        foreach (EventFlierLayout::FORMATS as $fmt) {
            $this->assertNotNull((new EventFlier())->png($f, $fmt), $fmt . ' broke on a long name');
        }
    }

    public function test_plain_is_a_different_design_and_not_a_dark_one_with_a_hole(): void
    {
        // The most common case by far — most people upload no photo. Its ground is warm paper,
        // so the top-left pixel is light where story's is dark. Sampled rather than asserted
        // from the constants, because the whole claim is about what somebody SEES.
        $f = EventFlier::forToken(EventFlierToken::mint($this->eventId, 'Ada'), $this->base());

        $photo = $this->photo();
        try {
            $plain = imagecreatefromstring((string) (new EventFlier())->png($f, 'plain'));
            $story = imagecreatefromstring((string) (new EventFlier())->png($f, 'story', $photo));

            $lum = static function ($im, int $x, int $y): float {
                $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                return 0.2126 * $c['red'] + 0.7152 * $c['green'] + 0.0722 * $c['blue'];
            };

            $this->assertGreaterThan(170, $lum($plain, 20, 20), 'plain should be a light ground');
            $this->assertLessThan(80, $lum($story, 20, 1900), 'story should be a dark plate');
            imagedestroy($plain); imagedestroy($story);
        } finally { @unlink($photo); }
    }

    public function test_nothing_is_drawn_outside_the_margins(): void
    {
        // The stack is measured and centred, and an unclamped centre pushes the kicker off
        // the top the moment a title wraps to two lines — on `square` in particular, where
        // the block is taller than the room below the photo. Off-canvas type does not throw;
        // it is simply not there.
        //
        // A display picture is also rendered as a CIRCLE, so `square` has a 90px keep-out on
        // every edge and the corners are not shown at all.
        $long = 'The Africa GATES Continental Recognition Gala and Awards Evening 2026';
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['title' => $long]);

        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Oluwaseun Adebayo-Okonkwo Chukwuemeka', $this->registration()),
            $this->base());
        $photo = $this->photo();

        try {
            foreach (EventFlierLayout::FORMATS as $fmt) {
                $im = imagecreatefromstring((string) (new EventFlier())->png($f, $fmt, $photo));
                $this->assertNotFalse($im, $fmt);
                $w = imagesx($im); $h = imagesy($im);
                $keep = $fmt === 'square' ? EventFlierLayout::SQ_SAFE : 40;

                // Ink is anything markedly lighter or darker than the ground at the same
                // height, sampled down each margin band.
                for ($y = 0; $y < $keep; $y++) {
                    $this->assertFalse($this->hasInk($im, $y, $w),
                        $fmt . ': something is drawn in the top ' . $keep . 'px, at y=' . $y);
                    $this->assertFalse($this->hasInk($im, $h - 1 - $y, $w),
                        $fmt . ': something is drawn in the bottom ' . $keep . 'px');
                }
                imagedestroy($im);
            }
        } finally { @unlink($photo); }
    }

    /**
     * Is there ink on this row, inside the horizontal keep-out?
     *
     * "Ink" is a pixel that differs sharply from that row's own leftmost background pixel, so
     * this works on a gradient, on a photo, and on the paper ground without knowing which.
     */
    private function hasInk($im, int $y, int $w): bool
    {
        $bg = imagecolorsforindex($im, imagecolorat($im, 4, $y));
        for ($x = 40; $x < $w - 40; $x += 3) {
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            $d = abs($c['red'] - $bg['red']) + abs($c['green'] - $bg['green'])
               + abs($c['blue'] - $bg['blue']);
            if ($d > 90) return true;
        }
        return false;
    }

    // ══ and the QR in the rendered image is the right QR ═════════════════════

    public function test_the_qr_drawn_into_the_png_encodes_the_target(): void
    {
        // Read back out of the RASTER, not from the encoder: the layout could place the
        // modules at the wrong scale, clip the pattern, or paint it over the ground, and every
        // one of those produces an image that looks completely correct.
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu', $this->registration()),
            $this->base());

        $photo = $this->photo();
        try {
            foreach (EventFlierLayout::FORMATS as $fmt) {
                $png = (string) (new EventFlier())->png($f, $fmt, $photo);
                $im  = imagecreatefromstring($png);
                $this->assertNotFalse($im);

                $m = Qr::encodeBytes($f['qr']);
                $this->assertNotNull($m);

                // The plate's position is COMPUTED now — the stack is bottom-anchored and
                // centred — so it is found in the image rather than read off a constant.
                // Finding it is also part of what is under test: a plate drawn off-canvas
                // would make a coordinate-based read silently sample the background.
                [$x0, $y0, $mod] = $this->findPlate($im, $fmt, count($m));

                $read = [];
                foreach ($m as $r => $row) {
                    $line = [];
                    foreach ($row as $col => $unused) {
                        $c = imagecolorsforindex($im,
                            imagecolorat($im, $x0 + $col * $mod + intdiv($mod, 2),
                                              $y0 + $r * $mod + intdiv($mod, 2)));
                        $line[] = ($c['red'] + $c['green'] + $c['blue']) < 384;
                    }
                    $read[] = $line;
                }

                $this->assertSame($m, $read,
                    $fmt . ': the QR in the image is not the QR that was encoded');
                imagedestroy($im);
            }
        } finally { @unlink($photo); }
    }

    public function test_the_quiet_zone_is_four_modules_on_every_format(): void
    {
        // Four because the specification says four. An attempt to justify it by simulating a
        // messaging app's recompression is kept at tests/Support/qr-recompression-check.py
        // and explicitly does NOT support a threshold: a one-pixel shift of the plate flips
        // the result. See EventFlierLayout's note.
        foreach (EventFlierLayout::QR as $fmt => $spec) {
            $this->assertGreaterThanOrEqual((int) $spec['module'] * 4, (int) $spec['pad'],
                $fmt . ': the quiet zone is under four modules');
        }
    }

    public function test_the_pattern_never_sits_on_the_ground(): void
    {
        // The plate is not decoration. A scanner finds the symbol by its edge, and a QR flush
        // against a dark field is a QR that does not read — which on the two dark formats is
        // most of them.
        $f = EventFlier::forToken(EventFlierToken::mint($this->eventId, 'Ada'), $this->base());
        $photo = $this->photo();

        try {
            foreach (EventFlierLayout::FORMATS as $fmt) {
                $im = imagecreatefromstring((string) (new EventFlier())->png($f, $fmt, $photo));
                $m  = Qr::encodeBytes($f['qr']);
                [$x0, $y0, $mod] = $this->findPlate($im, $fmt, count($m));
                $pad = (int) EventFlierLayout::QR[$fmt]['pad'];

                // Two pixels inside the plate's own corners, on all four sides.
                $side = count($m) * $mod + $pad * 2;
                foreach ([[2, 2], [$side - 3, 2], [2, $side - 3], [$side - 3, $side - 3]] as [$dx, $dy]) {
                    $c = imagecolorsforindex($im,
                        imagecolorat($im, $x0 - $pad + $dx, $y0 - $pad + $dy));
                    $this->assertGreaterThan(240, min($c['red'], $c['green'], $c['blue']),
                        $fmt . ': the quiet zone is not white at ' . $dx . ',' . $dy);
                }
                imagedestroy($im);
            }
        } finally { @unlink($photo); }
    }

    /**
     * Find the white QR plate in a rendered flier.
     *
     * The plate's LEFT edge is an invariant of the layout — the whole stack is left-aligned
     * at the format's margin — so only y has to be searched. A free two-dimensional search
     * was the first version and it locked onto the photo's light band instead, which is a
     * test failing for a reason that has nothing to do with what it is testing.
     *
     * @return array{0:int,1:int,2:int} the first module's x, y, and the module size
     */
    private function findPlate($im, string $fmt, int $modules): array
    {
        $mod  = (int) EventFlierLayout::QR[$fmt]['module'];
        $pad  = (int) EventFlierLayout::QR[$fmt]['pad'];
        $side = $modules * $mod + $pad * 2;
        $x    = $fmt === 'square' ? EventFlierLayout::SQ_SAFE : EventFlierLayout::PAD;

        $white = function ($im, int $px, int $py): bool {
            $c = imagecolorsforindex($im, imagecolorat($im, $px, $py));
            return min($c['red'], $c['green'], $c['blue']) >= 250;
        };

        for ($y = imagesy($im) - $side; $y >= 0; $y--) {
            if ($white($im, $x + 2, $y + 2)
                && $white($im, $x + $side - 3, $y + 2)
                && $white($im, $x + 2, $y + $side - 3)
                && $white($im, $x + $side - 3, $y + $side - 3)
                // And the row just above the plate is NOT white, so a light ground cannot
                // masquerade as the plate's top edge.
                && ($y === 0 || !$white($im, $x + intdiv($side, 2), $y - 2))) {
                return [$x + $pad, $y + $pad, $mod];
            }
        }

        $this->fail($fmt . ': no white QR plate found in the rendered image');
    }

    // ══ never print the negative ═════════════════════════════════════════════

    public function test_no_negative_language_exists_anywhere_in_the_design(): void
    {
        // Asserted against the SOURCE, because the strings are drawn as pixels and cannot be
        // read back out of a PNG. Absence of the mark is the signal; a flier that states its
        // own weakness is a flier nobody sends.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/EventFlierLayout.php')
             . (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/EventFlier.php');
        $src = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $src);
        $src = (string) preg_replace('~^\s*//.*$~m', '', $src);

        foreach (['not confirmed', 'Not confirmed', 'unconfirmed', 'Unconfirmed',
                  'pending', 'Pending', 'no ticket', 'No ticket'] as $never) {
            $this->assertStringNotContainsString($never, $src,
                'the flier must never print its own weakness: ' . $never);
        }
    }

    public function test_the_claim_is_identical_in_both_states(): void
    {
        // One template, one boolean. The state changes the mark and the second line, and
        // nothing else — including the claim.
        $this->assertSame('I will be there', EventFlierLayout::CLAIM);
        $this->assertSame('Come with me.', EventFlierLayout::INVITE);
        $this->assertSame('Ticket confirmed', EventFlierLayout::MARK);
    }

    private function hit(string $token, string $fmt): \Psr\Http\Message\ResponseInterface
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(EventsController::class);

        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/events/' . $this->slug . '/flier.png')
            ->withQueryParams(['t' => $token, 'fmt' => $fmt]);

        return $ctrl->flier($req, (new ResponseFactory())->createResponse(), ['slug' => $this->slug]);
    }

    // ══ the generator's POST ═════════════════════════════════════════════════

    /** @param array<string,mixed> $body */
    private function post(array $body, ?string $photoPath = null): \Psr\Http\Message\ResponseInterface
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(EventsController::class);

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/events/' . $this->slug . '/flier.png')
            ->withParsedBody($body);

        if ($photoPath !== null) {
            // sapi=false, so the stream is a real file this process can read — which is what
            // decodeUpload() reads, and it never writes anywhere.
            $req = $req->withUploadedFiles(['photo' => new \Slim\Psr7\UploadedFile(
                $photoPath, basename($photoPath), 'image/jpeg',
                filesize($photoPath) ?: null, UPLOAD_ERR_OK, false
            )]);
        }

        return $ctrl->flierMake($req, (new ResponseFactory())->createResponse(),
                                ['slug' => $this->slug]);
    }

    public function test_a_typed_name_alone_makes_a_flier(): void
    {
        // The ungated path, and the whole point of the feature: no account, no ticket, no
        // sign-in. The referral programme already sat behind a sign-in as a read-only field,
        // which is exactly why nobody used it.
        $res = $this->post(['name' => 'A Friend', 'fmt' => 'plain']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertNotSame('', $res->getHeaderLine('X-Flier-Token'),
            'the browser needs the token back to reshare or change format without retyping');
        $this->assertNotFalse(imagecreatefromstring((string) $res->getBody()));
    }

    public function test_a_blank_name_is_refused_with_a_sentence(): void
    {
        $res = $this->post(['name' => '   ', 'fmt' => 'plain']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('name', (string) $res->getBody());
    }

    public function test_the_post_honours_a_confirmed_token(): void
    {
        // The only way to be confirmed: a browser cannot assert a registration id, so the
        // token minted by the register response is the credential.
        $res = $this->post([
            't' => EventFlierToken::mint($this->eventId, 'Ada Nwosu', $this->registration()),
            'fmt' => 'plain',
        ]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertNotFalse(imagecreatefromstring((string) $res->getBody()));
    }

    public function test_the_post_refuses_a_finished_event(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['event_date' => date('Y-m-d H:i:s', strtotime('-9 days'))]);

        $res = $this->post(['name' => 'A Friend', 'fmt' => 'plain']);

        $this->assertSame(410, $res->getStatusCode());
        $this->assertStringNotContainsString('image/png', $res->getHeaderLine('Content-Type'));
    }

    public function test_an_uploaded_photo_is_never_written_to_disk(): void
    {
        // The handoff asks for this to be confirmed "at the storage layer, not by reading the
        // code". There is no storage layer to confirm: the file is decoded from the upload
        // temp, drawn, and the request ends. So what is asserted is the absence of any new
        // file anywhere the platform writes — and that the photo DID reach the image, because
        // an upload that was silently ignored would pass a "nothing was written" test
        // perfectly.
        $photo = $this->photo();
        $dirs = [sys_get_temp_dir(), dirname(__DIR__, 2) . '/var',
                 dirname(__DIR__, 2) . '/public/uploads'];

        $before = [];
        foreach ($dirs as $d) $before[$d] = is_dir($d) ? $this->listing($d) : [];

        $res = $this->post(['name' => 'Ada Nwosu', 'fmt' => 'story',
                            'focus_x' => '0.5', 'focus_y' => '0.2'], $photo);

        $this->assertSame(200, $res->getStatusCode());
        $im = imagecreatefromstring((string) $res->getBody());
        $this->assertNotFalse($im);
        // It reached the image: 1920 tall means the photo layout ran rather than the plain
        // fallback, and the slot's top is the picture's orange half.
        $this->assertSame(1920, imagesy($im), 'the upload did not reach the flier');
        $c = imagecolorsforindex($im, imagecolorat($im, 540, 180));
        $this->assertGreaterThan($c['blue'] + 40, $c['red'], 'the slot is not the photo');
        imagedestroy($im);

        foreach ($dirs as $d) {
            if (!is_dir($d)) continue;
            $new = array_diff($this->listing($d), $before[$d]);
            // The fixture's own file is expected; nothing else may appear.
            $new = array_values(array_filter($new, static fn (string $f): bool => $f !== basename($photo)));
            $this->assertSame([], $new, 'the upload left something behind in ' . $d);
        }

        @unlink($photo);
    }

    /** @return list<string> file names directly inside $dir */
    private function listing(string $dir): array
    {
        $out = [];
        foreach ((array) @scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') $out[] = (string) $f;
        }
        sort($out);
        return $out;
    }

    public function test_a_reframe_actually_changes_the_crop(): void
    {
        // Otherwise the focal point would be accepted, ignored, and every flier would use the
        // default anchor — and the reframe screen would be a control with no effect, which is
        // indistinguishable from one that works until somebody looks closely.
        $photo = $this->photo();
        try {
            $top = (string) $this->post(['name' => 'Ada', 'fmt' => 'square',
                                         'focus_x' => '0.5', 'focus_y' => '0'], $photo)->getBody();
            $bot = (string) $this->post(['name' => 'Ada', 'fmt' => 'square',
                                         'focus_x' => '0.5', 'focus_y' => '1'], $photo)->getBody();

            $this->assertNotSame($top, $bot, 'the focal point had no effect on the crop');
        } finally { @unlink($photo); }
    }

    public function test_an_unreadable_upload_falls_back_to_plain_rather_than_failing(): void
    {
        // Somebody uploads a PDF renamed .jpg. That is not an error worth a screen: the
        // renderer has a design for "no photo" and it is the common case anyway.
        $junk = sys_get_temp_dir() . '/ag-not-an-image-' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($junk, 'this is not an image');

        try {
            $res = $this->post(['name' => 'Ada', 'fmt' => 'story'], $junk);

            $this->assertSame(200, $res->getStatusCode());
            $im = imagecreatefromstring((string) $res->getBody());
            $this->assertSame(1080, imagesy($im), 'a junk upload should land on plain');
            imagedestroy($im);
        } finally { @unlink($junk); }
    }

    public function test_the_generated_flier_is_never_cached_or_indexed(): void
    {
        // It carries somebody's name, and the next press may carry a different crop.
        $res = $this->post(['name' => 'Ada Nwosu', 'fmt' => 'plain']);

        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
    }

    // ══ the third entry point, and the printed address ═══════════════════════

    public function test_a_confirmed_ticket_carries_a_flier_link_in_the_account_area(): void
    {
        // The handoff's third entry point: regenerate, switch format, re-share. It links at
        // the event page's own generator with the credential in hand, rather than a second
        // smaller generator living in the account area.
        $this->registration(['email' => 'ada@example.test', 'name' => 'Ada Nwosu']);

        $rows = \AfricaGates\Services\MemberActivityService::ticketsFor('ada@example.test', 10);
        $this->assertNotEmpty($rows);

        $link = (string) ($rows[0]['flier'] ?? '');
        $this->assertStringContainsString('/events/' . $this->slug, $link);
        // The token rides in the FRAGMENT, which is never sent to a server — so it is not in
        // an access log, a Referer header, or anything an intermediary keeps.
        $this->assertStringContainsString('#flier=', $link);
        $this->assertStringNotContainsString('?flier=', $link);

        // And it is a real token for this event and this registration.
        $t = EventFlierToken::read(rawurldecode(explode('#flier=', $link)[1]));
        $this->assertNotNull($t);
        $this->assertSame($this->eventId, $t['event']);
        $this->assertSame('Ada Nwosu', $t['name']);
    }

    public function test_an_unconfirmed_ticket_carries_no_flier_link(): void
    {
        // A held seat is not a ticket, and a flier reading "Ticket confirmed" over a payment
        // that has not landed is a claim the door would refuse.
        $this->registration(['email' => 'held@example.test', 'status' => 'pending']);

        $rows = \AfricaGates\Services\MemberActivityService::ticketsFor('held@example.test', 10);
        $this->assertNotEmpty($rows);
        $this->assertSame('', (string) ($rows[0]['flier'] ?? 'missing'));
    }

    public function test_the_printed_address_is_typeable_and_has_no_scheme(): void
    {
        // A QR is unreachable to a screen reader and unusable to anybody who cannot scan, so
        // the address is printed in words too. The scheme is dropped because nobody types one.
        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada'), 'https://afg.example.org');

        $this->assertSame('afg.example.org/events/' . $this->slug, $f['host']);
        $this->assertStringNotContainsString('https://', $f['host']);
    }

    public function test_a_long_address_is_truncated_by_measurement_and_stays_on_the_canvas(): void
    {
        // Off-canvas text does not throw — it is simply not there. So the line is wrapped
        // against the real face and the room actually left, and this is the case that proves
        // the wrapping is wired up rather than the string just happening to fit.
        $long = 'continental-recognition-gala-and-awards-evening-2026-lagos';
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['slug' => $long]);
        $this->slug = $long;

        $f = EventFlier::forToken(
            EventFlierToken::mint($this->eventId, 'Ada'), 'https://afg.afrovanguard.org.ng');
        $this->assertNotNull($f);
        $this->assertGreaterThan(60, strlen($f['host']), 'the fixture is not actually long');

        $im = imagecreatefromstring((string) (new EventFlier())->png($f, 'plain'));
        $this->assertNotFalse($im);

        // Nothing in the right-hand margin.
        $w = imagesx($im);
        for ($y = 0; $y < imagesy($im); $y += 3) {
            $this->assertFalse($this->hasInkRight($im, $y, $w),
                'the address ran into the right margin at y=' . $y);
        }
        imagedestroy($im);
    }

    /** Is there ink in the right-hand margin on this row? */
    private function hasInkRight($im, int $y, int $w): bool
    {
        $bg = imagecolorsforindex($im, imagecolorat($im, 4, $y));
        for ($x = $w - 40; $x < $w - 2; $x += 2) {
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            $d = abs($c['red'] - $bg['red']) + abs($c['green'] - $bg['green'])
               + abs($c['blue'] - $bg['blue']);
            if ($d > 90) return true;
        }
        return false;
    }

    public function test_the_route_serves_a_png_for_a_good_token(): void
    {
        $res = $this->hit(
            EventFlierToken::mint($this->eventId, 'Ada Nwosu', $this->registration()), 'story');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        // Never indexed: it carries somebody's name.
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('private', $res->getHeaderLine('Cache-Control'));

        $im = imagecreatefromstring((string) $res->getBody());
        $this->assertNotFalse($im);
        // 1080, not 1920: the route supplies no photo, and a photo format without a photo IS
        // the plain design — see test_no_photo_produces_plain_and_never_a_dark_layout_with_a_hole.
        $this->assertSame(1080, imagesy($im));
        imagedestroy($im);
    }
}
