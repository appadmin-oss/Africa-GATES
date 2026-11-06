<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Filters;

class FiltersTest extends TestCase
{
    public function test_page_window_includes_first_last_and_ellipsis(): void
    {
        $this->assertSame([1], Filters::pageWindow(1, 1));
        $this->assertSame([1, 2, 0, 9], Filters::pageWindow(1, 9, 1));
        $this->assertSame([1, 2, 3, 4, 5, 0, 9], Filters::pageWindow(3, 9, 2));
        $this->assertSame([1, 0, 8, 9], Filters::pageWindow(9, 9, 1));
    }

    public function test_qs_drops_page_empty_and_all(): void
    {
        $this->assertSame('q=amara&range=30d', Filters::qs([
            'q' => 'amara', 'page' => 5, 'range' => '30d', 'from' => '', 'to' => '', 'status' => 'all',
        ]));
        $this->assertSame('', Filters::qs(['page' => 3, 'range' => 'all', 'q' => '']));
    }

    public function test_clamp_page(): void
    {
        $this->assertSame(3, Filters::clampPage(5, 3));
        $this->assertSame(1, Filters::clampPage(0, 3));
        $this->assertSame(2, Filters::clampPage(2, 3));
        $this->assertSame(1, Filters::clampPage(4, 0)); // no pages → page 1
    }

    public function test_date_column_resolution(): void
    {
        $this->assertSame('voted_at', Filters::dateColumn(['id', 'voted_at', 'name'], 'voted_at'));
        $this->assertSame('created_at', Filters::dateColumn(['id', 'created_at', 'name'])); // known candidate
        $this->assertSame('signed_at', Filters::dateColumn(['id', 'signed_at'])); // *_at fallback
        $this->assertNull(Filters::dateColumn(['id', 'name', 'email'])); // no time column
    }
}
