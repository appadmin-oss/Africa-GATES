<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OtpService;
use Tests\TestCase;

/**
 * Outlook desktop draws an image at its `height` attribute, `height:auto` or not. Every
 * hero was given 260 — right for the 1200×520 illustrations, and a squashed band for the
 * older 1344×768 ones, visible only in the client and never in a browser preview. The
 * height is measured from our own file now, so this test asks the RULE across every
 * shipped illustration rather than pinning one image.
 */
final class EmailHeroHeightTest extends TestCase
{
    private static function heightIn(string $html): int
    {
        preg_match('/<img src="[^"]*illustrations[^"]*" alt="" width="600" height="(\d+)"/', $html, $m);
        return (int) ($m[1] ?? 0);
    }

    public function test_every_shipped_illustration_is_drawn_at_its_own_shape(): void
    {
        $mailer = new OtpService(['username' => 'u', 'password' => 'p']);
        $files = glob(dirname(__DIR__, 2) . '/public/assets/img/illustrations/*.jpg') ?: [];
        $this->assertNotEmpty($files);
        $odd = 0;
        foreach ($files as $f) {
            [$w, $h] = getimagesize($f);
            $want = (int) round(600 * $h / $w);
            if ($want !== 260) $odd++;
            $html = $mailer->brandWrap('S', '<tr><td>b</td></tr>', '', 'https://afg.example/assets/img/illustrations/' . basename($f));
            $this->assertSame($want, self::heightIn($html), basename($f) . ' is drawn at the wrong shape in Outlook');
        }
        $this->assertGreaterThan(0, $odd, 'every illustration is 600×260 now — this test no longer proves anything');
    }

    public function test_somebody_elses_image_keeps_the_callers_height_and_is_never_fetched(): void
    {
        $this->assertSame(300, OtpService::heroHeight('https://cdn.example/cover.jpg', 300));
        $this->assertSame(260, OtpService::heroHeight('https://cdn.example/cover.jpg'));
        $this->assertSame(260, OtpService::heroHeight('https://afg.example/assets/../../etc/passwd'));
    }
}
