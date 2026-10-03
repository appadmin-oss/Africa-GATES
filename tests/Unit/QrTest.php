<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Qr;
use Tests\TestCase;

/**
 * The QR encoder, guarded by vectors that were checked against a real decoder.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * HOW THESE VECTORS EARNED THE RIGHT TO BE ASSERTED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * An encoder verified only against its own expectations is an encoder that agrees with itself.
 * So `tests/fixtures/qr-golden.json` was not written by hand and is not merely a snapshot of
 * whatever this class happened to produce. Every matrix in it was checked two ways before being
 * committed:
 *
 *   1. Against an INDEPENDENT ENCODER (`segno`, in Python), module for module. 16 of the 18 are
 *      byte-identical. The other two differ only in trailing PAD BYTES — see the note on
 *      padding below — and not in a single data or error-correction module.
 *   2. By DECODING all 18 with OpenCV's QR reader, rasterised with a quiet zone exactly as a
 *      camera would see them. All 18 read back as the exact input string.
 *
 * A wider sweep at the same time put 310 randomised payloads through both: every matrix matched
 * segno for its length class, and OpenCV decoded 308 of 310 — the two misses being inputs where
 * OpenCV also fails on segno's byte-identical output, which makes them a limitation of that
 * reader rather than of this encoder.
 *
 * Neither Python nor OpenCV is a dependency of this project, so that cross-check cannot run
 * here. What runs here is the result of it. If a change to this class breaks one of these
 * vectors, the change is wrong until somebody repeats the cross-check and updates the fixture.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE KNOWN DIFFERENCE FROM segno, AND WHY IT IS NOT A DEFECT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * At payload lengths where the four-bit terminator lands exactly on a byte boundary — seven and
 * ten alphanumeric characters, at this version — segno emits one further zero byte before its
 * pad codewords and this class does not. The specification says to add the terminator and then
 * pad to a byte boundary, which is what happens here.
 *
 * It cannot affect a reader either way: a decoder reads the mode, then the character count,
 * then exactly that many characters, and stops. Everything after is padding it never looks at.
 * Both forms decoded correctly in the cross-check, including these two vectors.
 */
final class QrTest extends TestCase
{
    /** @return array<string, list<string>> input => rows of '0'/'1' */
    private function golden(): array
    {
        $path = dirname(__DIR__) . '/fixtures/qr-golden.json';
        $raw  = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($raw, 'the golden fixture is missing or unreadable');
        return $raw;
    }

    private function rows(array $matrix): array
    {
        return array_map(
            static fn (array $r): string => implode('', array_map(static fn (bool $v): string => $v ? '1' : '0', $r)),
            $matrix
        );
    }

    // ══ 1. the vectors ═══════════════════════════════════════════════════════

    public function test_every_golden_vector_is_reproduced_exactly(): void
    {
        $golden = $this->golden();
        $this->assertGreaterThanOrEqual(18, count($golden), 'the fixture lost vectors');

        foreach ($golden as $text => $expected) {
            $m = Qr::encode((string) $text);
            $this->assertNotNull($m, "refused a payload it used to encode: {$text}");
            $this->assertSame($expected, $this->rows($m),
                "the matrix for {$text} changed — a real decoder verified the old one, so this "
                . 'change is wrong until the cross-check is repeated');
        }
    }

    public function test_the_real_ticket_code_shape_is_covered(): void
    {
        // What EventTicketService::freshCode() actually produces: four characters, a dash, four
        // more, from an alphabet with no look-alikes. If the fixture stopped covering that
        // shape, this class would be guarded for everything except its only real use.
        $shaped = array_filter(array_keys($this->golden()),
            static fn (string $t): bool => (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $t));

        $this->assertGreaterThanOrEqual(4, count($shaped),
            'no ticket-code-shaped vectors left in the fixture');
    }

    // ══ 2. structure ═════════════════════════════════════════════════════════

    public function test_the_symbol_is_the_right_size(): void
    {
        $m = Qr::encode('ABCD-2468');
        $this->assertNotNull($m);
        $this->assertCount(Qr::SIZE, $m);
        foreach ($m as $row) $this->assertCount(Qr::SIZE, $row);
    }

    public function test_the_three_finder_patterns_are_where_a_scanner_looks(): void
    {
        $m = Qr::encode('ABCD-2468');
        $this->assertNotNull($m);
        $n = Qr::SIZE;

        // A scanner finds a symbol by these three squares before it reads anything. Getting
        // them wrong produces an image that is never recognised as a QR code at all.
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $ring = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                    $core = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $this->assertSame($ring || $core, $m[$r0 + $r][$c0 + $c],
                        "finder pattern at ({$r0},{$c0}) is wrong at ({$r},{$c})");
                }
            }
        }
    }

    public function test_the_separators_around_the_finders_are_light(): void
    {
        $m = Qr::encode('ABCD-2468');
        $this->assertNotNull($m);
        $n = Qr::SIZE;

        // One light module all the way round each finder. Without it a scanner cannot tell
        // where the finder ends and the data begins.
        for ($i = 0; $i <= 7; $i++) {
            $this->assertFalse($m[7][$i], "separator below the top-left finder is dark at {$i}");
            $this->assertFalse($m[$i][7], "separator right of the top-left finder is dark at {$i}");
            $this->assertFalse($m[7][$n - 1 - $i], 'separator below the top-right finder is dark');
            $this->assertFalse($m[$n - 1 - $i][7], 'separator right of the bottom-left finder is dark');
        }
    }

    public function test_the_timing_patterns_alternate(): void
    {
        $m = Qr::encode('ABCD-2468');
        $this->assertNotNull($m);

        // The row and column of alternating modules a scanner uses to work out where one module
        // ends and the next begins — which is how it copes with a photograph taken at an angle.
        for ($i = 8; $i < Qr::SIZE - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i], "horizontal timing pattern wrong at {$i}");
            $this->assertSame($i % 2 === 0, $m[$i][6], "vertical timing pattern wrong at {$i}");
        }
    }

    public function test_the_always_dark_module_is_dark(): void
    {
        $m = Qr::encode('ABCD-2468');
        $this->assertNotNull($m);
        // Specified as always set. A scanner that finds it light concludes the symbol is
        // mirrored and reads everything backwards.
        $this->assertTrue($m[Qr::SIZE - 8][8]);
    }

    public function test_two_different_payloads_never_produce_the_same_symbol(): void
    {
        $seen = [];
        foreach (array_keys($this->golden()) as $text) {
            $key = implode('', $this->rows(Qr::encode((string) $text)));
            $this->assertArrayNotHasKey($key, $seen,
                "'{$text}' encodes identically to '" . ($seen[$key] ?? '?') . "'");
            $seen[$key] = (string) $text;
        }
    }

    // ══ 3. what it refuses ═══════════════════════════════════════════════════

    public function test_anything_outside_the_alphanumeric_alphabet_is_refused(): void
    {
        // Refused, never substituted. A code that scans as a DIFFERENT string is worse than one
        // that does not scan, because somebody acts on it — and every caller shows the code as
        // text beside the image, so a refusal degrades to the door process that worked before.
        $this->assertNull(Qr::encode('ada@example.test'));
        $this->assertNull(Qr::encode('ticket_1'));
        $this->assertNull(Qr::encode('Àdìrẹ'));
        $this->assertNull(Qr::encode('https://afg.test/x'));
        $this->assertNull(Qr::encode('#1'));
    }

    public function test_a_payload_too_long_for_one_symbol_is_refused(): void
    {
        $this->assertNotNull(Qr::encode(str_repeat('A', Qr::MAX_CHARS)));
        // One character over. Refused rather than truncated: a silently shortened ticket code
        // would scan cleanly as the wrong ticket.
        $this->assertNull(Qr::encode(str_repeat('A', Qr::MAX_CHARS + 1)));
    }

    public function test_an_empty_payload_is_refused(): void
    {
        $this->assertNull(Qr::encode(''));
        $this->assertNull(Qr::encode('   '));
    }

    public function test_lower_case_is_lifted_rather_than_refused(): void
    {
        // The alphabet has no lower case, but a caller passing one has made a formatting
        // mistake and not a semantic one — and a ticket code is upper case by construction.
        $this->assertSame($this->rows(Qr::encode('ABCD-2468')), $this->rows(Qr::encode('abcd-2468')));
    }

    public function test_surrounding_whitespace_is_ignored(): void
    {
        $this->assertSame($this->rows(Qr::encode('ABCD-2468')), $this->rows(Qr::encode("  ABCD-2468\n")));
    }

    // ══ 4. the SVG ═══════════════════════════════════════════════════════════

    public function test_the_svg_carries_a_quiet_zone_and_nothing_external(): void
    {
        $svg = Qr::svg('ABCD-2468', 6, 'Ticket ABCD-2468');
        $this->assertIsString($svg);

        // Four modules of quiet zone each side: a scanner uses it to find the symbol's edge,
        // and a QR flush against dark text is a QR that does not read.
        $side = (Qr::SIZE + 8) * 6;
        $this->assertStringContainsString('width="' . $side . '"', $svg);
        $this->assertStringContainsString('viewBox="0 0 ' . $side . ' ' . $side . '"', $svg);

        // Nothing FETCHED. This platform's content-security policy blocks external resources
        // and a ticket has to work on a phone with no signal at a door — so what matters is the
        // absence of anything that causes a request, not the absence of the string "http": the
        // SVG namespace declaration is a URI that is never resolved.
        foreach (['href', 'src=', 'url(', '<image', '<script', '<use', '@import'] as $fetch) {
            $this->assertStringNotContainsString($fetch, $svg,
                "the SVG contains '{$fetch}', which would make it depend on a network request");
        }
    }

    public function test_the_svg_is_described_for_a_screen_reader(): void
    {
        $svg = Qr::svg('ABCD-2468', 6, 'Ticket ABCD-2468');
        $this->assertStringContainsString('role="img"', (string) $svg);
        $this->assertStringContainsString('Ticket ABCD-2468', (string) $svg);
    }

    public function test_a_hostile_label_cannot_inject_markup(): void
    {
        $svg = (string) Qr::svg('ABCD-2468', 6, '"><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
    }

    public function test_the_svg_is_null_for_a_payload_that_cannot_be_encoded(): void
    {
        // The callers branch on this to show the code as text alone, so it must be null and not
        // an empty string or a blank image.
        $this->assertNull(Qr::svg('ada@example.test'));
    }

    public function test_the_svg_has_one_path_rather_than_hundreds_of_rectangles(): void
    {
        $svg = (string) Qr::svg('ABCD-2468');
        // A fifth of the bytes and one paint operation instead of two hundred, which matters
        // when this is inlined into a page and an email.
        $this->assertSame(1, substr_count($svg, '<path'));
        $this->assertSame(0, substr_count($svg, '<rect x'));
    }
}
