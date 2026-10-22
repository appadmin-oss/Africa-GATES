<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\AiHelper;

/**
 * AI slug helper — proves the deterministic fallback path (no AI provider is
 * configured in tests, so slugBase() must always resolve to a safe slug) and
 * the uniqueness loop.
 */
class AiHelperTest extends TestCase
{
    public function test_slugify_produces_safe_ascii(): void
    {
        $this->assertSame('ada-obi', AiHelper::slugify('Ada Obi'));
        $this->assertSame('ay-beats', AiHelper::slugify('AY  Beats 🔥'));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]*$/', AiHelper::slugify('Chinwé Okónkwò'));
    }

    public function test_slugify_transliterates_accents_when_iconv_available(): void
    {
        if (!function_exists('iconv')) { $this->markTestSkipped('iconv not available'); }
        $this->assertSame('chinwe-okonkwo', AiHelper::slugify('Chinwé Okónkwò'));
    }

    public function test_slugBase_falls_back_to_deterministic_without_ai(): void
    {
        // No provider configured in tests → AiService::boot()->configured() is false.
        $this->assertSame('ada-obi', AiHelper::slugBase('Ada Obi'));
    }

    public function test_slugBase_uses_default_when_name_yields_nothing(): void
    {
        // A fully non-ASCII name iconv can't transliterate collapses to '' → default.
        $out = AiHelper::slugBase('陈伟', 'profile');
        $this->assertNotSame('', $out);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $out);
    }

    public function test_uniqueSlug_appends_suffix_on_collision(): void
    {
        $taken = ['ada-obi' => true, 'ada-obi-2' => true];
        $slug = AiHelper::uniqueSlug('Ada Obi', fn(string $s) => isset($taken[$s]), 'profile');
        $this->assertSame('ada-obi-3', $slug);
    }

    public function test_uniqueSlug_returns_base_when_free(): void
    {
        $this->assertSame('ada-obi', AiHelper::uniqueSlug('Ada Obi', fn() => false));
    }
}
