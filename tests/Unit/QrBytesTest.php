<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Qr;
use Tests\TestCase;

/**
 * The QR encoder, extended far enough to hold a URL.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS HAD TO EXIST BEFORE THE FLIER COULD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see Qr} was version 1, level Q, alphanumeric, sixteen characters — deliberately, and
 * exactly right for a ticket code. Its own class note explains the narrowness as a safety
 * property: block interleaving and the version-information area are the two places a subtle
 * bug produces a symbol that scans as the WRONG string rather than not scanning at all.
 *
 * The "I will be there" flier's whole mechanism is a QR carrying the sharer's referral link.
 * `https://…/e/gala-2026?r=AB12CD&c=flier` is 56 bytes and contains `?` and `=`, which are
 * not in the alphanumeric alphabet at all. There was no version of the old encoder that
 * could carry it: not a longer string, a different KIND of string.
 *
 * So byte mode and versions 2–6 with real interleaving. Version 7 is where the
 * version-information area starts, and it is not implemented — version 6 holds 74 bytes,
 * which is every URL this platform mints with room over, so the second of the two hard parts
 * buys nothing here and stays unwritten.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * HOW IT WAS CHECKED, AND WHY NOT AGAINST ANOTHER ENCODER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * First against `segno`, and that comparison was ABANDONED on purpose. Twelve of forty-three
 * payloads matched it byte for byte — precisely the twelve whose data exactly fills their
 * version, needing no padding. Every other one differed, because the region after the
 * terminator is not uniquely determined: a decoder reads the mode, the character count, and
 * that many bytes, and stops. Two encoders can pad the remainder differently and both be
 * right. Matching another implementation's arbitrary choice is not the property worth
 * asserting.
 *
 * So the vectors in `tests/fixtures/qr-bytes-golden.json` were each RENDERED AND DECODED by
 * OpenCV's detector before being written — 43/43 including every version boundary and every
 * URL shape — by `tests/Support/qr-bytes-vectors.py`. What this file asserts is that the
 * encoder still produces the matrices a real decoder read correctly.
 */
final class QrBytesTest extends TestCase
{
    /** @return array<string,list<string>> payload → rows of '0'/'1' */
    private function vectors(): array
    {
        $raw = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/qr-bytes-golden.json'),
            true
        );
        $this->assertIsArray($raw['vectors'] ?? null, 'the golden vectors are missing');
        return $raw['vectors'];
    }

    // ══ the vectors ══════════════════════════════════════════════════════════

    public function test_every_decoded_vector_still_encodes_the_same_way(): void
    {
        foreach ($this->vectors() as $text => $rows) {
            $m = Qr::encodeBytes((string) $text);

            $this->assertNotNull($m, 'refused a payload that used to encode: ' . $text);
            $got = array_map(
                static fn (array $row): string => implode('', array_map(
                    static fn (bool $b): string => $b ? '1' : '0', $row)),
                $m
            );
            $this->assertSame($rows, $got,
                "the matrix for '{$text}' changed — OpenCV decoded the old one, so this is a "
                . 'regression until a decoder says otherwise. Re-run '
                . 'tests/Support/qr-bytes-vectors.py to re-verify and re-record.');
        }
    }

    public function test_the_vectors_cover_every_version_boundary(): void
    {
        // A golden file that only covers version 1 would pass while interleaving was broken.
        $sizes = [];
        foreach (array_keys($this->vectors()) as $text) {
            $sizes[count(Qr::encodeBytes((string) $text) ?? [])] = true;
        }
        // Versions 1–6 are 21, 25, 29, 33, 37, 41 modules.
        foreach ([21, 25, 29, 33, 37, 41] as $side) {
            $this->assertArrayHasKey($side, $sizes,
                'no vector exercises a ' . $side . '-module symbol');
        }
    }

    // ══ the table is arithmetically consistent ═══════════════════════════════

    public function test_the_version_table_adds_up(): void
    {
        // Every row of the SPEC table has three independent facts in it, and a typo in any of
        // them produces a symbol that is the right SIZE and the wrong contents. Checked here
        // against the totals from the standard rather than trusted.
        $total = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172];

        $spec = new \ReflectionClass(Qr::class);
        $table = $spec->getConstant('SPEC');
        $this->assertIsArray($table);

        foreach ($table as $version => $row) {
            $blocks = 0; $data = 0;
            foreach ($row['blocks'] as [$count, $each]) { $blocks += $count; $data += $count * $each; }

            $this->assertSame($row['data'], $data,
                "version {$version}: the blocks hold {$data} data codewords, not {$row['data']}");
            $this->assertSame($total[$version], $data + $blocks * $row['ec'],
                "version {$version}: data plus error correction is not {$total[$version]} codewords");
        }
    }

    public function test_the_byte_capacity_matches_what_the_bit_budget_allows(): void
    {
        // 4 bits of mode, 8 of character count at these versions, then 8 per byte. Derived
        // rather than trusted, because `bytes` is the number that decides which version a
        // payload lands in, and one too high produces a refusal at encode time — or worse, a
        // truncation, if the guard were ever removed.
        $table = (new \ReflectionClass(Qr::class))->getConstant('SPEC');

        foreach ($table as $version => $row) {
            $this->assertSame(
                intdiv($row['data'] * 8 - 12, 8),
                $row['bytes'],
                "version {$version}: the stated byte capacity does not match its bit budget"
            );
        }
    }

    // ══ version selection ═══════════════════════════════════════════════════

    public function test_the_smallest_version_that_fits_is_chosen(): void
    {
        // A larger-than-needed symbol has smaller modules at a fixed pixel size, which is
        // exactly what stops scanning after a messaging app recompresses it.
        foreach ([[11, 21], [12, 25], [20, 25], [21, 29], [32, 29], [33, 33],
                  [46, 33], [47, 37], [60, 37], [61, 41], [74, 41]] as [$len, $side]) {
            $m = Qr::encodeBytes(str_repeat('A', $len));

            $this->assertNotNull($m, $len . ' bytes was refused');
            $this->assertCount($side, $m, $len . ' bytes should be a ' . $side . '-module symbol');
        }
    }

    public function test_more_than_version_six_is_refused_and_not_truncated(): void
    {
        // The refusal is the safety. A silently shortened URL scans perfectly and goes
        // somewhere else, and the person holding the flier has no way to know.
        $this->assertNull(Qr::encodeBytes(str_repeat('A', Qr::MAX_BYTES + 1)));
        $this->assertNotNull(Qr::encodeBytes(str_repeat('A', Qr::MAX_BYTES)));
        $this->assertSame(74, Qr::MAX_BYTES, 'version 6 level Q holds 74 bytes in byte mode');
    }

    public function test_nothing_and_whitespace_are_refused(): void
    {
        foreach (['', '   ', "\n"] as $empty) {
            $this->assertNull(Qr::encodeBytes($empty));
        }
    }

    // ══ case is preserved, which is the whole reason for a second entry point ═

    public function test_byte_mode_does_not_fold_case(): void
    {
        // `encode()` uppercases, because a ticket code is case-insensitive and somebody
        // reading one off a screen may type it either way. A URL path is not: `/r/Ab12` and
        // `/r/AB12` are two different addresses, and the uppercased one scans perfectly as a
        // link to nothing.
        $lower = Qr::encodeBytes('https://x.ng/r/aB12');
        $upper = Qr::encodeBytes('HTTPS://X.NG/R/AB12');

        $this->assertNotNull($lower);
        $this->assertNotNull($upper);
        $this->assertNotSame($lower, $upper, 'byte mode folded case — a URL cannot survive that');
    }

    public function test_characters_the_alphanumeric_mode_cannot_hold(): void
    {
        // `?` and `=` are not in the alphanumeric alphabet, so `encode()` refuses this
        // outright. It is also every referral link the flier mints.
        $url = 'https://afg.afrovanguard.org.ng/e/gala?r=AB12CD&c=flier';

        $this->assertNull(Qr::encode($url), 'the ticket-code encoder should still refuse a URL');
        $this->assertNotNull(Qr::encodeBytes($url));
    }

    // ══ the old path is untouched ════════════════════════════════════════════

    public function test_the_ticket_code_encoder_is_unchanged(): void
    {
        // `Qr::SIZE` is a public constant that TicketPdf and the ticket page rely on, and it
        // is only true of version 1. `encode()` therefore stays version-1-only rather than
        // gaining the new versions: a longer code would silently produce a matrix that does
        // not match SIZE, and the PDF would draw a 41-module symbol into a 21-module box.
        $this->assertSame(21, Qr::SIZE);
        $this->assertSame(16, Qr::MAX_CHARS);
        $this->assertCount(Qr::SIZE, Qr::encode('ABCD-2468') ?? []);
        $this->assertNull(Qr::encode(str_repeat('A', 17)));
    }

    // ══ the rendered SVG ════════════════════════════════════════════════════

    public function test_the_svg_sizes_itself_from_the_matrix(): void
    {
        // Four modules of quiet zone on every side, and the side derived from the symbol —
        // not from SIZE. A version-6 code drawn into a 21-module box is every module clipped,
        // and nothing in the output announces it.
        $svg = Qr::svgBytes('https://afg.afrovanguard.org.ng/events/gala-2026', 7);
        $this->assertNotNull($svg);

        $m = Qr::encodeBytes('https://afg.afrovanguard.org.ng/events/gala-2026');
        $side = (count($m) + 8) * 7;
        $this->assertStringContainsString('width="' . $side . '"', $svg);
        $this->assertStringContainsString('viewBox="0 0 ' . $side . ' ' . $side . '"', $svg);
    }

    public function test_the_quiet_zone_is_four_modules_and_that_is_load_bearing(): void
    {
        // Measured, not assumed: the same symbol at the flier's own module size was pasted
        // onto a 1080-wide ground and put through what a messaging app does — downscale to
        // 75% and JPEG at quality 50, twice, for "sent" then "forwarded".
        //
        //   4-module quiet zone: every format decoded after both passes.
        //   2-module quiet zone: `square` and `plain` decoded when sent and FAILED when
        //   forwarded — which is exactly the defect the handoff described, a quiet zone that
        //   still looks correct and stops scanning after recompression.
        //
        // So this asserts the geometry the measurement justified.
        $scale = 7;
        $svg = Qr::svgBytes('https://x.ng/r/aB12', $scale);
        $m   = Qr::encodeBytes('https://x.ng/r/aB12');

        $this->assertNotNull($svg);
        $this->assertNotNull($m);
        $this->assertSame(1, preg_match('/width="(\d+)"/', $svg, $x));
        $this->assertSame((count($m) + 4 * 2) * $scale, (int) $x[1],
            'the quiet zone is no longer four modules a side');
    }

    public function test_a_refused_payload_renders_nothing_rather_than_a_broken_code(): void
    {
        $this->assertNull(Qr::svgBytes(str_repeat('A', Qr::MAX_BYTES + 1)));
        $this->assertNull(Qr::svgBytes(''));
    }
}
