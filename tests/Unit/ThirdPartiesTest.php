<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalDocument;
use AfricaGates\Services\ProviderProbe;
use AfricaGates\Services\ThirdParties;
use Tests\TestCase;

/**
 * THE PRIVACY POLICY NAMES EVERY OUTSIDE SERVICE, AND THE CODE DECIDES WHAT "EVERY" MEANS.
 *
 * Asked as RULES, not as a list of today's services: every provider the platform can call is
 * declared, and every origin the browser is allowed to reach is declared or reported unused.
 * A new integration fails here until somebody writes the sentence a reader is owed.
 */
final class ThirdPartiesTest extends TestCase
{
    public function test_every_provider_the_platform_can_call_is_declared(): void
    {
        $ids = array_column(ProviderProbe::catalogue(), 'id');
        $this->assertNotEmpty($ids);
        $this->assertSame([], array_values(array_diff($ids, ThirdParties::declaredProbes())),
            'a provider the platform calls with no declaration on /privacy');
        $this->assertSame([], array_values(array_diff(ThirdParties::declaredProbes(), $ids)),
            'a declaration naming a provider check that does not exist');
    }

    public function test_every_origin_the_browser_may_reach_is_declared_or_reported_unused(): void
    {
        $hosts = ThirdParties::policyHosts();
        $this->assertGreaterThan(10, count($hosts), 'the policy reader found the policy');
        $known = array_merge(ThirdParties::declaredHosts(), array_keys(ThirdParties::ALLOWED_UNUSED));
        $this->assertSame([], array_values(array_diff($hosts, $known)),
            'an origin the Content Security Policy allows that /privacy does not mention');
    }

    public function test_an_origin_reported_unused_is_actually_unused(): void
    {
        // Otherwise the list is an exemption: a host could go live while still filed as
        // "allowed, nothing uses it", and the policy would never name it.
        $root = dirname(__DIR__, 2);
        $used = [];
        $dirs = [$root . '/templates', $root . '/public/assets/js', $root . '/src'];
        foreach ($dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
                $p = $f->getPathname();
                if (!$f->isFile() || str_contains($p, '/min/') || str_contains($p, '/vendor/')
                    || !preg_match('/\.(twig|js|php)$/', $p)
                    || str_ends_with($p, 'Support/Csp.php') || str_ends_with($p, 'Services/ThirdParties.php')) continue;
                $body = (string) file_get_contents($p);
                foreach (array_keys(ThirdParties::ALLOWED_UNUSED) as $origin) {
                    $host = preg_replace('#^https://#', '', $origin);
                    if (str_contains($body, (string) $host)) $used[] = $origin . ' in ' . str_replace($root . '/', '', $p);
                }
            }
        }
        $this->assertSame([], $used, 'a host filed as unused is referenced — declare it as a service');
    }

    public function test_the_privacy_policy_carries_the_list_and_its_files_do_too(): void
    {
        $html = LegalDocument::bodyHtml(['slug' => 'privacy', 'body_html' => '<p>Authored.</p>']);
        $this->assertStringContainsString('id="services"', $html);
        foreach (ThirdParties::services() as $s) {
            $this->assertStringContainsString(htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'), $html, $s['id'] . ' is not printed');
        }
        // Not on the other documents.
        $this->assertStringNotContainsString('id="services"', LegalDocument::bodyHtml(['slug' => 'terms', 'body_html' => '<p>x</p>']));
    }

    public function test_what_it_prints_is_escaped(): void
    {
        $html = LegalDocument::thirdPartiesHtml([['id' => 'x', 'name' => '<script>alert(1)</script>', 'for' => 'a', 'gets' => 'b', 'when' => 'c', 'probes' => [], 'hosts' => []]]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_every_declaration_says_what_it_gets_and_when(): void
    {
        foreach (ThirdParties::services() as $s) {
            foreach (['name', 'for', 'gets', 'when'] as $k) {
                $this->assertNotSame('', trim((string) $s[$k]), $s['id'] . " has no '$k'");
            }
        }
    }
}
