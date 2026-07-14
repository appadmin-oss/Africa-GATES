<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Editable legal/policy docs: DB-backed (never hardcoded), publish gating,
 * slug normalisation, and fault-tolerant reads so a fresh DB never white-screens.
 */
final class LegalServiceTest extends TestCase
{
    public function test_save_then_get_round_trip(): void
    {
        LegalService::save('privacy', [
            'title' => 'Privacy Policy', 'body_html' => '<h2>Data</h2><p>We hash emails.</p>',
            'updated_label' => '5 July 2026', 'is_published' => 1, 'sort_order' => 0,
        ], 7);
        $doc = LegalService::get('privacy');
        $this->assertNotNull($doc);
        $this->assertSame('Privacy Policy', $doc['title']);
        $this->assertStringContainsString('hash emails', $doc['body_html']);
    }

    public function test_unpublished_doc_is_hidden_from_public_get(): void
    {
        LegalService::save('draft-policy', ['title' => 'Draft', 'body_html' => '<p>wip</p>', 'is_published' => 0], 7);
        $this->assertNull(LegalService::get('draft-policy'), 'unpublished doc must not render publicly');
        $this->assertNotNull(LegalService::find('draft-policy'), 'but admin can still find it');
    }

    public function test_published_list_is_ordered_and_filtered(): void
    {
        LegalService::save('cookies', ['title' => 'Cookies', 'is_published' => 1, 'sort_order' => 2, 'body_html' => '<p>c</p>'], 7);
        LegalService::save('privacy', ['title' => 'Privacy', 'is_published' => 1, 'sort_order' => 0, 'body_html' => '<p>p</p>'], 7);
        LegalService::save('hidden',  ['title' => 'Hidden', 'is_published' => 0, 'sort_order' => 1, 'body_html' => '<p>h</p>'], 7);
        $slugs = array_column(LegalService::published(), 'slug');
        $this->assertSame(['privacy', 'cookies'], $slugs);
    }

    public function test_slug_is_normalised(): void
    {
        LegalService::save('Community Guidelines!', ['title' => 'Community Guidelines', 'body_html' => '<p>be kind</p>', 'is_published' => 1], 7);
        $this->assertNotNull(LegalService::get('community-guidelines'));
    }

    public function test_blank_slug_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        LegalService::save('!!!', ['title' => 'X', 'body_html' => '<p>x</p>'], 7);
    }

    public function test_updated_label_defaults_when_blank(): void
    {
        LegalService::save('terms', ['title' => 'Terms', 'body_html' => '<p>t</p>', 'is_published' => 1], 7);
        $this->assertNotEmpty(LegalService::get('terms')['updated_label']);
    }
}
