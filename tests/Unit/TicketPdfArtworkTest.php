<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventTicketDesign, TicketPdf};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The photograph on the printed ticket, and the two ways it used to go missing.
 *
 * A PDF is bytes, so these assertions are about bytes: a JPEG embedded in a PDF appears as a
 * `/DCTDecode` image XObject, and the absence of one is the difference between a ticket with
 * the event's artwork on it and a ticket with a gold rectangle. Counting them is the only way
 * to tell those apart without a human looking at a page.
 */
class TicketPdfArtworkTest extends TestCase
{
    private string $root;
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2) . '/public';
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $f) { @unlink($f); }
        parent::tearDown();
    }

    /** A real JPEG on disk under public/, returned as its same-site path. */
    private function poster(int $w = 1200, int $h = 800): string
    {
        $dir = $this->root . '/uploads/test-artwork';
        @mkdir($dir, 0777, true);
        $rel = '/uploads/test-artwork/p-' . bin2hex(random_bytes(5)) . '.jpg';
        $im  = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 120, 40, 90));
        imagejpeg($im, $this->root . $rel, 85);
        imagedestroy($im);
        $this->made[] = $this->root . $rel;
        return $rel;
    }

    private function event(array $over = []): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId($over + [
            'title' => 'Ogidì Ọmọ', 'slug' => 'ogidi-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'venue' => '88 College Road, NYSC Bus Stop', 'location' => 'Igando, Lagos',
            'status' => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    private function reg(int $eventId): array
    {
        $id = (int) DB::table('gates_event_registrations')->insertGetId([
            'event_id' => $eventId, 'name' => 'Adaeze Okonkwo',
            'email' => 'a-' . bin2hex(random_bytes(3)) . '@example.test',
            'reference' => 'AGT-' . bin2hex(random_bytes(5)),
            'ticket_code' => strtoupper(bin2hex(random_bytes(4))),
            'status' => 'confirmed', 'tier' => 'Standard', 'amount_naira' => 25000,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (array) DB::table('gates_event_registrations')->where('id', $id)->first();
    }

    private function pdf(object $event): string
    {
        return TicketPdf::one($this->reg((int) $event->id), (array) $event,
            EventTicketDesign::forEvent($event),
            'https://afg.example.test/events/ticket/AGT-TEST');
    }

    private function images(string $pdf): int
    {
        return substr_count($pdf, '/DCTDecode');
    }

    // ────────────────────────────────────────────────────────────────────────

    public function test_a_local_cover_is_embedded(): void
    {
        $with = $this->pdf($this->event(['cover_image' => $this->poster()]));
        $bare = $this->pdf($this->event());

        $this->assertSame(1, $this->images($with), 'the event has artwork and the ticket should carry it');
        $this->assertSame(0, $this->images($bare), 'and an event with none still produces a ticket');
    }

    public function test_a_cdn_hosted_cover_is_embedded_from_the_copy_on_disk(): void
    {
        // THE BUG THIS FILE EXISTS FOR. With Cloudinary configured every stored URL is
        // remote, TicketPdf refuses to fetch remote URLs — correctly — and it used to stop
        // there, so every ticket on those deployments printed with no photograph at all.
        // The local file was on disk the whole time and `gates_uploads.local_path` said where.
        $rel    = $this->poster();
        $remote = 'https://res.cloudinary.com/demo/image/upload/v1/agates/events/poster.jpg';
        DB::table('gates_uploads')->insert([
            'uploader_type' => 'admin', 'path' => $remote, 'mime' => 'image/jpeg',
            'size_bytes' => 4321, 'width' => 1200, 'height' => 800,
            'provider' => 'cloudinary', 'public_id' => 'agates/events/poster',
            'local_path' => $rel, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, $this->images($this->pdf($this->event(['cover_image' => $remote]))));
    }

    public function test_a_remote_cover_nobody_has_a_copy_of_is_simply_left_out(): void
    {
        // Still refused, and still without a network call: putting a third party's server in
        // the path of a ticket download is how a slow host becomes a hung request.
        $pdf = $this->pdf($this->event(['cover_image' => 'https://example.org/theirs/poster.jpg']));
        $this->assertSame(0, $this->images($pdf));
        $this->assertStringStartsWith('%PDF', $pdf, 'and the ticket is still produced');
    }

    public function test_the_framed_crop_is_preferred_over_the_master_it_was_cut_from(): void
    {
        // `ticket_image` is the 3:2 crop the organiser framed in the admin editor and
        // `ticket_image_src` is the poster behind it. The crop is what should print; the
        // master is the fallback for when the crop has gone missing from disk.
        $crop   = $this->poster(1200, 800);
        $master = $this->poster(800, 1200);

        $design = EventTicketDesign::forEvent((object) [
            'ticket_image' => $crop, 'ticket_image_src' => $master,
        ]);
        $this->assertSame($crop, $design['image']);
        $this->assertSame($master, $design['image_src']);

        // With the crop deleted, the master still gets the ticket a photograph.
        @unlink($this->root . $crop);
        $event = $this->event(['ticket_image' => $crop, 'ticket_image_src' => $master]);
        $this->assertSame(1, $this->images($this->pdf($event)),
            'a ticket with the whole poster beats a ticket with a gold rectangle');
    }

    public function test_the_date_and_the_venue_always_reach_the_stub(): void
    {
        // Both used to be dropped silently when the field column ran short — the ticket
        // looked complete and had no date on it, which is somebody at a gate on the wrong
        // evening. Read back out of the PDF's own text operators.
        $pdf  = $this->pdf($this->event(['cover_image' => $this->poster()]));
        $text = self::strings($pdf);

        $this->assertStringContainsString('DATE', $text);
        $this->assertStringContainsString('LOCATION', $text);
        $this->assertStringContainsString('HOLDER', $text);
        $this->assertStringContainsString('IGANDO', $text,
            'the town is the half of an address somebody navigates by');
    }

    public function test_the_recovery_address_is_on_the_ticket_not_on_the_offcut(): void
    {
        // It used to be drawn on the page below the card. Cut the ticket out — which is what
        // the corner marks ask for — and the one line that recovers a lost ticket is binned.
        $pdf  = $this->pdf($this->event());
        $text = self::strings($pdf);
        $this->assertStringContainsString('events/ticket/AGT-TEST', $text);
        $this->assertStringNotContainsString('https://', $text,
            'the scheme is eleven characters nobody has typed since 2012');
    }

    /**
     * Every string the PDF's text operators draw, decoded back to readable text.
     *
     * The fonts are SUBSET, so a content stream holds `<0084008C…> Tj` — glyph ids in the
     * embedded subset, which mean nothing on their own. What makes them readable is the
     * `/ToUnicode` CMap {@see \AfricaGates\Support\Pdf} writes beside each font, which is
     * the same thing that lets a reader copy text out of the finished file. Reading the
     * document the way a reader would is the point: it asserts what a person can actually
     * see on the page, not what the drawing code was asked to do.
     */
    private static function strings(string $pdf): string
    {
        $streams = self::inflateAll($pdf);

        // 1 · ONE MAP PER FONT, in the order the file declares them.
        //
        // Not one merged map. Each subset numbers its own glyphs from scratch, so the id
        // that is `A` in the display face is `E` in the mono one — merging them decoded the
        // ticket as `LOCETION` and `EGT-TEST`, which reads like a bug in the PDF rather
        // than in the test looking at it.
        $maps = [];
        foreach ($streams as $chunk) {
            if (!str_contains($chunk, 'beginbfchar')) continue;
            preg_match_all('~<([0-9A-Fa-f]{4})>\s*<([0-9A-Fa-f]{4,})>~', $chunk, $pairs, PREG_SET_ORDER);
            $one = [];
            foreach ($pairs as $pair) {
                $one[strtoupper($pair[1])] = mb_convert_encoding(
                    (string) hex2bin(str_pad($pair[2], 8, '0', STR_PAD_LEFT)), 'UTF-8', 'UTF-32BE');
            }
            $maps[] = $one;
        }

        // 2 · every drawn literal, through whichever map decodes it.
        //
        // The content stream names its font as `/Ftext`, `/Fmono` and so on, and nothing in
        // the CMap stream carries that alias back — so rather than parse the resource
        // dictionary to pair them, each run is decoded by the map that can account for ALL
        // of its glyphs. A run drawn from one subset is only fully covered by that subset's
        // map, so the pairing falls out of the data.
        $out = '';
        foreach ($streams as $chunk) {
            if (!str_contains($chunk, 'Tj')) continue;
            preg_match_all('~<([0-9A-Fa-f]+)>\s*Tj~', $chunk, $runs);
            foreach ($runs[1] as $hex) {
                $gids = str_split(strtoupper($hex), 4);
                foreach ($maps as $map) {
                    $missing = 0;
                    foreach ($gids as $gid) { if (!isset($map[$gid])) $missing++; }
                    if ($missing > 0) continue;
                    foreach ($gids as $gid) { $out .= $map[$gid]; }
                    $out .= ' ';
                    break;
                }
            }
        }
        return $out;
    }

    /** @return list<string> every stream in the file that inflates */
    private static function inflateAll(string $pdf): array
    {
        $out = [];
        $at  = 0;
        while (($s = strpos($pdf, 'stream', $at)) !== false) {
            $from = $s + 6;
            while ($from < strlen($pdf) && ($pdf[$from] === "\r" || $pdf[$from] === "\n")) $from++;
            $to = strpos($pdf, 'endstream', $from);
            if ($to === false) break;
            $raw = @gzuncompress(substr($pdf, $from, $to - $from));
            if ($raw !== false) $out[] = $raw;
            $at = $to + 9;
        }
        return $out;
    }
}
