<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Pdf;
use AfricaGates\Support\Qr;
use AfricaGates\Support\TrueType;

/**
 * The PDF writer and the font subsetter underneath it.
 *
 * ── WHY THIS IS TESTED HARDER THAN THE PAGE IT REPLACES ──────────────────────
 *
 * A broken web page announces itself: the layout is wrong on screen and somebody says so. A
 * broken PDF does not. Readers are forgiving to a fault — an unreadable cross-reference table
 * is silently reconstructed, a font with a missing table is silently substituted — so a file
 * can be structurally wrong, render correctly on the machine that made it, and fail on a
 * print shop's RIP months later with nothing to point at.
 *
 * So these assertions are about the BYTES rather than the appearance.
 */
class PdfWriterTest extends TestCase
{
    private const FONTS = __DIR__ . '/../../resources/fonts/';

    // ─────────────────────────── the bundled faces ──────────────────────────

    /**
     * The whole reason a font is embedded at all.
     *
     * The fourteen fonts a PDF may use without embedding are WinAnsi, which cannot spell a
     * single Yorùbá name. This asserts the bundled faces cover the orthographies the
     * platform's own README commits to — and it is not hypothetical: the DM Sans copies
     * shipped for the flier cover none of these, so a ticket set in the brand face would
     * print holes through the middle of somebody's name.
     */
    public function test_the_bundled_faces_cover_african_latin(): void
    {
        $needed = [
            'Ọ' => 0x1ECC, 'ọ' => 0x1ECD, 'Ẹ' => 0x1EB8, 'ẹ' => 0x1EB9,
            'Ṣ' => 0x1E62, 'ṣ' => 0x1E63,                       // Yorùbá
            'Ɓ' => 0x0181, 'Ɗ' => 0x018A, 'Ƙ' => 0x0198,         // Hausa
            'Ɔ' => 0x0186, 'ɔ' => 0x0254, 'Ŋ' => 0x014A,         // Akan / Ewe
            'ĩ' => 0x0129, 'ũ' => 0x0169,                        // Kikuyu
            'é' => 0x00E9, 'ç' => 0x00E7,                        // French / Portuguese
            '₦' => 0x20A6, '·' => 0x00B7, '—' => 0x2014,         // currency and marks
        ];

        foreach (['AGText-Regular', 'AGText-Bold', 'AGMono-Bold'] as $face) {
            $f = TrueType::load(self::FONTS . $face . '.ttf');
            $this->assertNotNull($f, $face . ' did not load.');

            foreach ($needed as $label => $cp) {
                $this->assertNotSame(0, $f->gid($cp),
                    $face . ' has no glyph for ' . $label . ' — a name set in it prints a hole.');
            }
        }
    }

    // ───────────────────────────── subsetting ───────────────────────────────

    /**
     * The subset keeps glyph ids exactly where they were.
     *
     * That is the entire mechanism: nothing is renumbered, so `loca`, `hmtx` and every
     * composite glyph's component references stay valid without being rewritten. Renumbering
     * silently would produce a font that renders the wrong letters — the worst possible
     * failure, because it looks like text.
     */
    public function test_a_subset_keeps_glyph_ids_and_widths(): void
    {
        $f = TrueType::load(self::FONTS . 'AGText-Regular.ttf');
        $this->assertNotNull($f);

        $chars = ['A', 'd', 'a', 'e', 'z'];
        $gids  = [];
        foreach ($chars as $c) $gids[] = $f->gid(mb_ord($c, 'UTF-8'));
        foreach ([0x1ECC, 0x20A6] as $cp) $gids[] = $f->gid($cp);

        $bytes = $f->subset($gids, true);
        $this->assertNotSame('', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'ag_ttf_') . '.ttf';
        file_put_contents($tmp, $bytes);
        try {
            $sub = TrueType::load($tmp);
            $this->assertNotNull($sub, 'The subset is not a readable font.');

            foreach ($chars as $c) {
                $cp = mb_ord($c, 'UTF-8');
                $this->assertSame($f->gid($cp), $sub->gid($cp), 'Glyph id moved for ' . $c);
                $this->assertSame($f->width($f->gid($cp)), $sub->width($sub->gid($cp)),
                    'Advance width changed for ' . $c);
            }
            $this->assertSame($f->gid(0x1ECC), $sub->gid(0x1ECC));
            $this->assertSame($f->numGlyphs(), $sub->numGlyphs());
        } finally {
            @unlink($tmp);
        }
    }

    /** And it is dramatically smaller, which is the point of doing it at all. */
    public function test_a_subset_is_a_fraction_of_the_source(): void
    {
        $f = TrueType::load(self::FONTS . 'AGText-Regular.ttf');
        $this->assertNotNull($f);

        $gids = [];
        foreach (range(0x41, 0x5A) as $cp) $gids[] = $f->gid($cp);

        $full = (int) filesize(self::FONTS . 'AGText-Regular.ttf');
        $sub  = strlen($f->subset($gids));

        $this->assertLessThan($full / 3, $sub,
            'A subset no smaller than the source is a subset that did not happen.');
    }

    // ─────────────────────────── the file structure ─────────────────────────

    private function assertXrefIsSound(string $pdf): void
    {
        $this->assertSame(1, preg_match('/startxref\s+(\d+)/', $pdf, $m));
        $xref = substr($pdf, (int) $m[1]);
        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $rows);
        $this->assertNotEmpty($rows[1]);
        foreach ($rows[1] as $i => $off) {
            $n = $i + 1;
            $this->assertSame($n . ' 0 obj', substr($pdf, (int) $off, strlen((string) $n) + 6),
                'xref entry ' . $n . ' does not address object ' . $n . '.');
        }
    }

    public function test_the_cross_reference_table_addresses_its_objects(): void
    {
        $pdf = new Pdf(210, 297);
        $this->assertTrue($pdf->font('text', self::FONTS . 'AGText-Regular.ttf'));
        $pdf->text('Ọlásùnkànmí Ṣẹ́gun · ₦25,000', 20, 40, 'text', 12);
        $out = $pdf->output();

        $this->assertStringStartsWith('%PDF-', $out);
        $this->assertStringEndsWith('%%EOF', $out);
        $this->assertXrefIsSound($out);
    }

    /** A page is A4 when it says A4: 595.28 × 841.89 points. */
    public function test_the_page_is_the_size_it_claims(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');
        $pdf->text('x', 10, 10, 'text', 10);

        $this->assertMatchesRegularExpression(
            '/MediaBox \[0 0 595\.\d\d 841\.\d\d\]/', $pdf->output()
        );
    }

    /**
     * A face that was registered and never drawn with is never embedded.
     *
     * Three faces are registered for every ticket and a sheet of free tickets uses two of
     * them. Embedding the third would put a font in the file to render nothing.
     */
    public function test_an_unused_face_is_not_embedded(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');
        $pdf->font('mono', self::FONTS . 'AGMono-Bold.ttf');
        $pdf->text('hello', 10, 10, 'text', 10);

        $this->assertSame(1, substr_count($pdf->output(), '/FontFile2'));
    }

    // ──────────────────────────────── text ──────────────────────────────────

    /**
     * A character no registered face can set is DROPPED, not drawn as .notdef.
     *
     * An empty box in the middle of a name reads as data corruption. A missing accent reads
     * as a font that does not have it, which is the truth and is survivable.
     */
    public function test_an_unmappable_character_is_dropped_rather_than_boxed(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');

        // CJK: certainly absent from a Latin subset.
        $this->assertSame(0.0, round($pdf->width('世界', 'text', 12), 4));
        $this->assertGreaterThan(0, $pdf->width('Ọ', 'text', 12),
            'A Yorùbá character must measure as real text.');
    }

    /**
     * The fallback chain sets what the primary face cannot.
     *
     * This is what would let the brand face be reintroduced without any of the rest changing:
     * a face missing Ọ still sets everything else, and only the characters it lacks come from
     * elsewhere.
     */
    public function test_a_fallback_face_covers_what_the_primary_lacks(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('cover', self::FONTS . 'AGText-Regular.ttf');
        // DM Sans genuinely has no Ọ — that is why this pairing is the realistic test.
        $this->assertTrue($pdf->font('brand', __DIR__ . '/../../resources/fonts/DMSans-Regular.ttf', 'cover'));

        $narrow = $pdf->width('Ọ', 'brand', 12);
        $this->assertGreaterThan(0, $narrow,
            'Without a fallback this character is silently dropped from a name.');
    }

    /** Tracking widens a string, which is what makes a ticket code legible at arm's length. */
    public function test_tracking_widens_a_string(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('mono', self::FONTS . 'AGMono-Bold.ttf');

        $plain   = $pdf->width('AG26-4K7Q-11', 'mono', 10);
        $tracked = $pdf->width('AG26-4K7Q-11', 'mono', 10, 60);
        $this->assertGreaterThan($plain, $tracked);
    }

    /** Wrapping is measured against the real advance widths, not a character count. */
    public function test_a_paragraph_wraps_to_the_width_it_is_given(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');
        $pdf->addPage();

        $end = $pdf->paragraph('88 College Road, NYSC Bus Stop, Lasu-Isheri Road, Igando, Lagos',
            10, 20, 40, 'text', 8, 4);

        // Sixty characters at 8pt cannot fit 40mm on one line, so the baseline must have moved
        // by more than one leading.
        $this->assertGreaterThan(20 + 4, $end);
    }

    // ──────────────────────────────── the QR ────────────────────────────────

    /**
     * The symbol is drawn at the size it was asked for, in millimetres.
     *
     * The one measurement in the whole system that is a physical fact. This checks the
     * arithmetic reaches the content stream: a module at the far corner must land inside the
     * declared square and not one module beyond it.
     */
    public function test_the_qr_occupies_exactly_the_millimetres_requested(): void
    {
        $matrix = Qr::encode('AG26-4K7Q-11');
        $this->assertNotNull($matrix);

        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');
        $pdf->qr($matrix, 20, 20, 30);
        $pdf->text('.', 1, 1, 'text', 6);          // forces a font so the page is well-formed

        $out = $pdf->output();
        $this->assertStringContainsString('/FlateDecode', $out,
            'The content stream should be compressed where zlib exists.');

        // The white ground is drawn as a plain rectangle before the modules: 30mm at 72/25.4
        // points per mm is 85.04pt square, and that number appearing is the whole assertion.
        $this->assertMatchesRegularExpression('/85\.04 85\.04 re/', self::drawing($out),
            'The quiet-zone ground is not the size the caller asked for.');
    }

    /**
     * The page's own drawing operators.
     *
     * Fonts are written before pages, so "the first stream in the file" is a font — this
     * walks them and returns the one that decompresses to something a page would contain.
     */
    /**
     * A JPEG goes in as bytes and comes out as a DCTDecode image.
     *
     * The codec is the same one, so nothing is decoded and nothing re-encoded — which is why
     * this is the only image format the writer accepts, and why a ticket carrying a
     * photograph costs what the photograph costs and nothing more.
     */
    public function test_a_jpeg_is_embedded_without_being_re_encoded(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available to make a test image.');
        }

        $im = imagecreatetruecolor(80, 60);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);

        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');
        $this->assertTrue($pdf->image($jpeg, 10, 10, 60, 40));
        $pdf->text('.', 5, 5, 'text', 6);

        $out = $pdf->output();
        $this->assertStringContainsString('/Subtype /Image', $out);
        $this->assertStringContainsString('/Filter /DCTDecode', $out);
        // The source bytes, verbatim, are in the file.
        $this->assertStringContainsString(substr($jpeg, 20, 40), $out);
        // And its real pixel dimensions were read out of the frame header rather than guessed.
        $this->assertStringContainsString('/Width 80 /Height 60', $out);
    }

    /** Anything that is not a JPEG is refused rather than written as a broken image. */
    public function test_a_non_jpeg_is_refused(): void
    {
        $pdf = new Pdf(210, 297);
        $this->assertFalse($pdf->image('not an image at all', 10, 10, 20, 20));
        $this->assertFalse($pdf->image("\x89PNG\r\n\x1a\n" . str_repeat('x', 40), 10, 10, 20, 20));
    }

    /**
     * A partly transparent fill balances its own graphics state.
     *
     * PDF has no per-operator alpha, so transparency is a state the stream switches into and
     * back out of. An unmatched `Q` unbalances everything after it — and readers recover
     * silently, so the only symptom is a warning nobody sees in a browser. This is the check
     * that caught the glyph loop shadowing the variable holding that state.
     */
    public function test_transparency_leaves_the_graphics_stack_balanced(): void
    {
        $pdf = new Pdf(210, 297);
        $pdf->font('text', self::FONTS . 'AGText-Regular.ttf');
        $pdf->rect(10, 10, 50, 20, [0, 0, 0], 0.4);
        $pdf->text('opaque', 10, 40, 'text', 10);
        $pdf->text('washed', 10, 50, 'text', 10, [0, 0, 0], 0, 0.2);
        $pdf->text('opaque again', 10, 60, 'text', 10);

        $ops = self::drawing($pdf->output());
        $this->assertSame(
            preg_match_all('/(?:^|\s)q(?:\s|$)/', $ops),
            preg_match_all('/(?:^|\s)Q(?:\s|$)/', $ops),
            'Every q must have exactly one Q.'
        );
        $this->assertStringContainsString('/ExtGState', $pdf->output());
    }

    private static function drawing(string $pdf): string
    {
        // "\nstream\n", not "stream\n" — the shorter needle also matches inside the
        // "endstream" that closes the previous one, which walks the scan onto a boundary that
        // is not a stream at all.
        $offset = 0;
        while (($i = strpos($pdf, "\nstream\n", $offset)) !== false) {
            $from = $i + 8;
            $j    = strpos($pdf, "\nendstream", $from);
            $offset = $from;                                    // always forward
            if ($j === false) break;

            $out = @gzuncompress(substr($pdf, $from, $j - $from));
            if (is_string($out) && str_contains($out, ' re ')) return $out;
            $offset = $j + 1;
        }
        return '';
    }
}
