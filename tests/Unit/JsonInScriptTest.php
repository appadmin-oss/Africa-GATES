<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every value a template writes into a script as JSON escapes `<`.
 *
 * `json_encode` leaves `</script>` and `<!--` intact, and `|raw` stops Twig escaping them, so
 * one value carrying either ends the script element early. On the door scanner that value was
 * a guest's NAME: one arrival typed as `Ada <!--<script>` broke the scanner page for every
 * steward at that event, mid-queue. On an article's JSON-LD it was the title an author typed.
 *
 * The rule is the flag, not the place: JSON_SAFE (JSON_HEX_TAG and friends, a Twig global in
 * config/container.php), or JSON_HEX_TAG spelled out. Swept over every template so the next
 * `|json_encode|raw` written into a script is caught here rather than at an event.
 */
final class JsonInScriptTest extends TestCase
{
    private const PATTERN = '/json_encode(\((?<args>[^)]*(?:\([^)]*\)[^)]*)*)\))?\s*\|\s*raw/';

    /** @return list<string> */
    private static function offenders(string $twig): array
    {
        preg_match_all(self::PATTERN, $twig, $m, PREG_SET_ORDER);
        $bad = [];
        foreach ($m as $hit) {
            $args = (string) ($hit['args'] ?? '');
            if (!str_contains($args, 'JSON_SAFE') && !str_contains($args, 'JSON_HEX_TAG')) $bad[] = $hit[0];
        }
        return $bad;
    }

    public function test_no_template_writes_json_into_html_without_escaping_tags(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.twig')) continue;
            foreach (self::offenders((string) file_get_contents($f->getPathname())) as $hit) {
                $found[] = substr($f->getPathname(), strlen($root) + 1) . ': ' . $hit;
            }
        }
        $this->assertSame([], $found, "json_encode|raw without JSON_SAFE:\n" . implode("\n", $found));
    }

    public function test_the_sweep_names_a_break_when_it_sees_one(): void
    {
        $this->assertSame(['json_encode|raw'], self::offenders('var log = {{ arrivals|json_encode|raw }};'));
        $this->assertSame(['json_encode(constant(\'JSON_UNESCAPED_SLASHES\'))|raw'],
            self::offenders("{{ x|json_encode(constant('JSON_UNESCAPED_SLASHES'))|raw }}"));
        $this->assertSame([], self::offenders('{{ x|json_encode(JSON_SAFE)|raw }}'));
        $this->assertSame([], self::offenders("{{ x|json_encode(constant('JSON_UNESCAPED_SLASHES') b-or constant('JSON_HEX_TAG'))|raw }}"));
    }

    public function test_json_safe_really_neutralises_a_closing_tag(): void
    {
        $out = json_encode(['name' => 'Ada <!--<script></script>'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $this->assertStringNotContainsString('<', (string) $out);
        $this->assertSame('Ada <!--<script></script>', json_decode((string) $out, true)['name']);
    }
}
