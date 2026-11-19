<?php
declare(strict_types=1);

namespace Tests\Unit;

use Slim\Views\Twig;
use Tests\TestCase;

final class AnalyticsAccessibilityTest extends TestCase
{
    public function test_a_visual_trend_has_its_complete_daily_values_in_a_table(): void
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates')->getEnvironment();
        $template = $twig->createTemplate("{% import 'admin/partials/analytics-charts.twig' as charts %}{{ charts.spark(series, 'var(--cn-green)', 46, 0.13, 'New accounts') }}");
        $html = $template->render(['series' => [['date' => '2026-10-01', 'value' => 2], ['date' => '2026-10-02', 'value' => 9]]]);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//svg[@role="img"][@aria-label]')->length);
        $this->assertSame(2, $xpath->query('//details//table//th[@scope="col"]')->length);
        $this->assertSame(2, $xpath->query('//details//table//th[@scope="row"]')->length);
        $this->assertStringContainsString('2026-10-02', $html);
        $this->assertStringContainsString('New accounts', $html);
        $this->assertSame('9', $xpath->query('//tbody/tr[2]/td')->item(0)->textContent);
    }
}
