<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\{CoverVariant, CoverKind};
use AfricaGates\Controllers\CoverImageController;
use Slim\Views\Twig;
use Tests\TestCase;

final class CoverVariantTest extends TestCase
{
    public function test_variation_is_stable_for_a_record_and_preserves_the_cover_family(): void
    {
        $variants = [];
        for ($id = 1; $id <= 32; $id++) {
            $v = CoverVariant::forRecord('event', 'conference', $id);
            $this->assertSame($v, CoverVariant::forRecord('event', 'conference', (string) $id));
            $this->assertGreaterThanOrEqual(0, $v);
            $this->assertLessThan(4, $v);
            $variants[$v] = true;
        }
        $this->assertCount(4, $variants);
        $this->assertSame(0, CoverVariant::forRecord('event', 'conference', ''));
        $this->assertSame('dots', CoverKind::resolve('event', 'conference')['pattern']);
        $this->assertSame('info', CoverKind::resolve('event', 'conference')['tone']);
    }

    public function test_on_page_and_shared_covers_use_the_same_record_identity(): void
    {
        $facts = CoverImageController::facts(['id' => 42, 'title' => 'A conference', 'cover_kind' => 'conference']);
        $expected = CoverVariant::forRecord($facts['subject'], $facts['kind'], $facts['identity']);
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $view = $builder->build()->get(Twig::class);
        $html = $view->fetch('partials/photo.twig', ['kind' => 'thing', 'subject' => 'event', 'identity' => 42,
            'cover_kind' => 'conference', 'name' => 'A conference', 'src' => '']);
        $this->assertStringContainsString('ag-cover--variant-' . $expected, $html);
        $this->assertStringContainsString('ag-cover--info ag-cover--dots', $html);
        $this->assertStringContainsString('ag-cover__pattern-mask', $html);
    }
}
