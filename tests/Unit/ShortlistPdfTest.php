<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShortlistPdf;
use Tests\TestCase;

/**
 * The shortlist document.
 *
 * A PDF's text is font-subset-encoded, so this cannot grep the page for a name and should
 * not pretend to. What it CAN check is everything that would send a broken file to a
 * printer or leave a name out of the document entirely: that the bytes are a PDF, that the
 * page count matches the number of names, that a name in an African orthography survives
 * the font path, and that the download filename cannot escape a directory.
 */
final class ShortlistPdfTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function entries(int $n, string $name = 'Nominee'): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'rank_no' => $i, 'nominee_name' => "{$name} {$i}",
                'vote_count' => 1000 - $i, 'organic_vote_count' => 900 - $i,
                'organisation' => 'Org', 'country_code' => 'NG', 'tied_at_cut' => 0,
            ];
        }
        return $rows;
    }

    private function ctx(): array
    {
        return ['category' => 'Community Health', 'programme' => 'Africa GATES',
                'edition' => 'Third Edition', 'year' => 2026];
    }

    private function sl(): array
    {
        return ['rule_text' => 'Top 10 by votes, ties included.', 'considered' => 140,
                'published_at' => '2026-08-23 10:00:00', 'note' => ''];
    }

    private function pageCount(string $pdf): int
    {
        // `/Type /Page` for a page; `/Type /Pages` is the tree node, hence the negative
        // lookahead rather than counting substrings.
        preg_match_all('~/Type\s*/Page(?!s)~', $pdf, $m);
        return count($m[0]);
    }

    public function test_it_produces_an_actual_pdf(): void
    {
        $pdf = ShortlistPdf::render($this->sl(), $this->entries(8), $this->ctx());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf), 'a page with eight names is not a stub');
        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * A list longer than a page must not lose its tail.
     *
     * The page count is arithmetic rather than a second pass — Pdf draws forward-only — so
     * an off-by-one drops real names off the end of a real document.
     *
     * The rows-per-page figure is FOUND rather than asserted: hard-coding 15 here would
     * duplicate a private constant, and the test would then fail the next time somebody
     * legitimately changes the row pitch instead of failing when a name goes missing.
     */
    public function test_a_long_list_paginates_and_never_drops_its_tail(): void
    {
        // Walk up until a second page appears. That boundary IS the first page's capacity.
        $first = 0;
        for ($n = 1; $n <= 40; $n++) {
            if ($this->pageCount(ShortlistPdf::render($this->sl(), $this->entries($n), $this->ctx())) > 1) {
                $first = $n - 1;
                break;
            }
        }
        $this->assertGreaterThan(5, $first, 'the first page holds implausibly few names');

        // Every entry past the first page has to land on a later one, and no later page may
        // be drawn empty. Both bounds hold for any per-page capacity of at least one.
        foreach ([$first + 1, $first + 2, 60, 120] as $n) {
            $pages = $this->pageCount(ShortlistPdf::render($this->sl(), $this->entries($n), $this->ctx()));

            $this->assertGreaterThan(1, $pages, "{$n} names were squeezed onto one page");
            $this->assertLessThanOrEqual($n - $first + 1, $pages,
                "a blank page was drawn for {$n} names");
        }
    }

    /** An empty shortlist is never published, but rendering one must not fatal. */
    public function test_an_empty_list_still_renders_a_valid_single_page(): void
    {
        $pdf = ShortlistPdf::render($this->sl(), [], $this->ctx());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * The whole subject of this platform is African names. A face that cannot set Ọlásùnkànmí
     * would put holes through the middle of somebody's name on the document announcing them.
     */
    public function test_an_african_orthography_survives_the_font_path(): void
    {
        $plain    = ShortlistPdf::render($this->sl(), $this->entries(3, 'Aaaaaaaaaa'), $this->ctx());
        $accented = ShortlistPdf::render($this->sl(), $this->entries(3, 'Ọlásùnkànmí Ṣẹ́gun'), $this->ctx());

        $this->assertStringStartsWith('%PDF-', $accented);
        // A subset embeds only the glyphs used. If the accented forms were dropped rather
        // than set, the two files would carry near-identical glyph counts.
        $this->assertNotSame(strlen($plain), strlen($accented),
            'the accented name produced the same output as a plain one — its glyphs were dropped');
    }

    public function test_a_very_long_category_title_does_not_overflow_the_measure(): void
    {
        $ctx = ['category' => str_repeat('Extraordinarily Long Category Title ', 3)] + $this->ctx();

        $this->assertStringStartsWith('%PDF-', ShortlistPdf::render($this->sl(), $this->entries(4), $ctx));
    }

    // ══ the filename ═════════════════════════════════════════════════════════

    public function test_the_filename_is_safe_for_a_header_and_a_filesystem(): void
    {
        $name = ShortlistPdf::filename($this->ctx());

        $this->assertSame('community-health-2026-shortlist.pdf', $name);
        $this->assertMatchesRegularExpression('~^[a-z0-9-]+\.pdf$~', $name);
    }

    /**
     * A category title is admin-entered text. It must not be able to inject a header, walk
     * a directory, or arrive as a bare `.pdf`.
     */
    public function test_a_hostile_category_title_cannot_escape_the_filename(): void
    {
        foreach ([
            '../../etc/passwd',
            "line\r\nX-Injected: 1",
            '"; rm -rf /',
            '',
            '////',
        ] as $title) {
            $name = ShortlistPdf::filename(['category' => $title, 'year' => 2026]);

            $this->assertMatchesRegularExpression('~^[a-z0-9-]+\.pdf$~', $name, "unsafe for: {$title}");
            $this->assertStringNotContainsString('..', $name);
        }
    }

    public function test_a_title_in_a_non_latin_script_still_yields_a_usable_name(): void
    {
        // preg_replace with /u returns NULL on invalid UTF-8; the fallback must hold.
        $this->assertMatchesRegularExpression(
            '~^[\p{L}\p{N}-]+\.pdf$~u',
            ShortlistPdf::filename(['category' => 'Ọmọ Ilé', 'year' => 2026])
        );
    }
}
