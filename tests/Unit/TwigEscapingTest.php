<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Documents the embedding technique for data in <script> blocks.
 *
 * Reality check: Twig's DEFAULT json_encode escapes forward slashes, so a
 * literal "</script>" can't form (the close tag is the actual breakout vector).
 * That makes the default already safe against script-block breakout. However it
 * leaves raw "<" characters; the JSON_SAFE flags (exposed to templates as the
 * JSON_SAFE global) additionally hex-escape "<", which is strictly stronger and
 * future-proofs against anyone later enabling JSON_UNESCAPED_SLASHES.
 */
class TwigEscapingTest extends TestCase
{
    private const JSON_SAFE = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    private function twig(): Environment
    {
        $twig = new Environment(new ArrayLoader([
            'safe'    => "{{ v|json_encode(JSON_SAFE)|raw }}",
            'default' => "{{ v|json_encode|raw }}",
        ]));
        $twig->addGlobal('JSON_SAFE', self::JSON_SAFE);
        return $twig;
    }

    public function test_json_safe_hex_escapes_angle_brackets(): void
    {
        $value = ['name' => '<b></script>'];
        $out = trim($this->twig()->render('safe', ['v' => $value]));

        $this->assertStringNotContainsString('</script>', $out);
        $this->assertStringNotContainsString('<', $out);                // no raw angle brackets remain
        $this->assertSame(json_encode($value, self::JSON_SAFE), $out);  // matches the hardened encoding
    }

    public function test_default_escapes_slashes_but_leaves_angle_brackets(): void
    {
        $out = trim($this->twig()->render('default', ['v' => ['name' => '<b></script>']]));

        $this->assertStringNotContainsString('</script>', $out); // slash-escaped: no literal close tag
        $this->assertStringContainsString('<', $out);            // but raw '<' remains (weaker)
    }
}
