<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeFlier;
use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Services\FlierLayout;
use AfricaGates\Support\SeedRunner;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\TestApp;
use Tests\TestCase;

/**
 * The challenge flier, held against the artwork it was drawn from.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE REFERENCE IS THE ARTWORK, AND THE ROW IS THE SEED'S
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `tests/fixtures/challenge-flier/celebrate-nigeria.png` is the design as supplied. The
 * flier compared against it is fetched from the REAL route for the row the REAL seed
 * writes, so the headline, the portrait, the host's logo and the date all have to arrive
 * by the paths production uses — a fixture shaped like the seed would prove only that
 * the renderer agrees with the fixture.
 *
 * The comparison is per REGION as well as overall. An overall mean of 6 is a flier that
 * matches; it is also a flier whose button has vanished, because a button is 1% of the
 * canvas. Each region carries its own ceiling, so a broken part is named.
 *
 * What is not compared to the pixel: anti-aliasing (GD's and the artwork's renderer
 * differ along every glyph edge, which is most of the remaining difference), and the
 * ground, which is derived from the theme and lands within four levels of the artwork's.
 */
final class ChallengeFlierTest extends TestCase
{
    private const SEED = '2026_10_01_celebrate_nigeria';
    private const SLUG = 'celebrate-nigeria-2026';
    private const REF  = __DIR__ . '/../fixtures/challenge-flier/celebrate-nigeria.png';

    private string|false $appUrl = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagettftext')) self::markTestSkipped('no FreeType on this build');
        $this->appUrl = getenv('APP_URL');
        putenv('APP_URL=https://afg.afrovanguard.org.ng');
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv($this->appUrl === false ? 'APP_URL' : 'APP_URL=' . $this->appUrl);
        parent::tearDown();
    }

    /** The operator's half — the award and its edition — then the seed's own run. */
    private function seed(): void
    {
        $p = (int) DB::table('gates_award_programmes')->insertGetId(
            ['slug' => 'alimosho-awards', 'title' => 'Alimosho Awards', 'is_active' => 1]);
        $cy = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2026, 'status' => 'nominations',
            'nominations_open' => '2026-09-01 00:00:00', 'nominations_close' => '2026-11-30 23:59:59',
        ]);
        foreach (['choral' => 'Choral', 'business' => 'Business', 'impact' => 'Impact'] as $slug => $t) {
            DB::table('gates_award_categories')->insert(['cycle_id' => $cy, 'slug' => $slug, 'title' => $t]);
        }
        DB::table('gates_settings')->whereIn('key_name', ['seed_ran_' . self::SEED, 'seed_last_' . self::SEED])->delete();
        self::assertSame('done', SeedRunner::run(self::SEED)['status']);
    }

    private function fetch(): \GdImage
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET',
            'https://afg.afrovanguard.org.ng/challenges/' . self::SLUG . '/flier.png');
        $res = TestApp::build()->handle($req);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $im = imagecreatefromstring((string) $res->getBody());
        self::assertInstanceOf(\GdImage::class, $im);
        return $im;
    }

    /** @param array<string,mixed> $over */
    private function render(array $over = []): \GdImage
    {
        $c = $over + [
            'slug' => 'probe', 'title' => 'Celebrate Nigeria', 'kicker' => 'Independence Day challenge',
            'action' => E::ACTION_NOMINATE, 'target' => 10, 'mode' => E::MODE_FIRST, 'cap' => 11,
            'prize_type' => E::PRIZE_CASH_EACH, 'prize_amount' => 6000, 'prize_currency' => 'NGN',
            'theme' => E::THEME_GREEN, 'flag' => 1, 'status' => E::ST_OPEN,
            'headline' => 'Know {target} people who make Alimosho proud?',
            'portrait_url' => '/assets/img/challenges/celebrate-nigeria-portrait.png',
            'ends_at' => '2026-10-15 22:59:59', 'timezone' => 'Africa/Lagos',
        ];
        $im = imagecreatefromstring((string) ChallengeFlier::png($c, ['claimed' => 0, 'state' => E::ST_OPEN]));
        self::assertInstanceOf(\GdImage::class, $im);
        return $im;
    }

    /** Mean absolute difference per channel over a box, sampled every `$step` px. */
    private static function diff(\GdImage $a, \GdImage $b, int $x0, int $y0, int $x1, int $y1, int $step = 1): float
    {
        $s = 0; $n = 0;
        for ($y = $y0; $y < $y1; $y += $step) {
            for ($x = $x0; $x < $x1; $x += $step) {
                $p = imagecolorat($a, $x, $y); $q = imagecolorat($b, $x, $y);
                $s += abs((($p >> 16) & 255) - (($q >> 16) & 255)) + abs((($p >> 8) & 255) - (($q >> 8) & 255))
                    + abs(($p & 255) - ($q & 255));
                $n += 3;
            }
        }
        return $s / max(1, $n);
    }

    private static function hexAt(\GdImage $im, int $x, int $y): string
    {
        return sprintf('#%06x', imagecolorat($im, $x, $y) & 0xFFFFFF);
    }

    private static function near(string $want, string $got, int $tol): bool
    {
        [$a, $b, $c] = FlierLayout::rgb($want); [$d, $e, $f] = FlierLayout::rgb($got);
        return abs($a - $d) <= $tol && abs($b - $e) <= $tol && abs($c - $f) <= $tol;
    }

    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_seeded_flier_matches_the_artwork_region_by_region(): void
    {
        $this->seed();
        $ours = $this->fetch();
        $ref  = imagecreatefrompng(self::REF);
        self::assertSame([1080, 1080], [imagesx($ours), imagesy($ours)]);

        // Each ceiling sits between what the render measures today and what the region
        // measures with its content MISSING (measured by painting it with the ground):
        // room for a font build's hinting to move a glyph, none for a part to vanish.
        //                                          today   missing
        $regions = [
            'the logos'               => [[40, 80, 330, 190], 12.0],     //  5.8    25.0
            'the flag sticker'        => [[770, 140, 895, 245], 10.0],   //  2.3    33.3
            'the kicker and headline' => [[190, 255, 690, 400], 20.0],   // 12.2    32.7
            'the standfirst'          => [[190, 400, 690, 465], 26.0],   // 16.1    50.4
            'the coupon sticker'      => [[40, 595, 145, 705], 8.0],     //  2.0    19.9
            'the prize card'          => [[190, 688, 405, 905], 25.0],   // 17.0    39.2
            'the portrait'            => [[640, 422, 1080, 949], 10.0],  //  2.1    45.3
            'the footer'              => [[0, 949, 800, 1080], 8.0],     //  5.1    11.5
            'the button'              => [[800, 980, 1005, 1048], 25.0], //  8.2   133.5
        ];
        foreach ($regions as $name => [[$x0, $y0, $x1, $y1], $ceiling]) {
            $d = self::diff($ref, $ours, $x0, $y0, $x1, $y1);
            self::assertLessThan($ceiling, $d, sprintf('%s is %.2f levels from the artwork', $name, $d));
        }
        $all = self::diff($ref, $ours, 0, 0, 1080, 1080, 2);
        self::assertLessThan(7.0, $all, sprintf('the flier is %.2f levels from the artwork overall', $all));
    }

    /** The colours that carry meaning, sampled where the artwork has them. */
    public function test_the_card_the_tab_and_the_button_are_the_artworks_colours(): void
    {
        $this->seed();
        $im = $this->fetch();

        self::assertTrue(self::near('#ffffff', self::hexAt($im, 210, 760), 1), 'the card is white');
        self::assertTrue(self::near('#f4f9f3', self::hexAt($im, 297, 880), 3), 'the card\'s lower tint');
        self::assertTrue(self::near('#10292c', self::hexAt($im, 245, 706), 6), 'the dark tab');
        self::assertTrue(self::near('#237b22', self::hexAt($im, 830, 1014), 1), 'the button is the action green');
        self::assertTrue(self::near('#ffffff', self::hexAt($im, 40, 1060), 1), 'the footer band is white');
        self::assertTrue(self::near('#e6f2e8', self::hexAt($im, 520, 60), 4), 'the ground');
    }

    /**
     * The flier's palette is the challenge page's own, value for value: a blue challenge
     * whose page is blue and whose flier is green is two products.
     */
    public function test_the_themes_are_the_challenge_pages_tokens(): void
    {
        $root   = dirname(__DIR__, 2) . '/public/assets/css';
        $tokens = (string) file_get_contents($root . '/tokens.css');
        $css    = (string) file_get_contents($root . '/components/challenge.css');
        $token  = static function (string $name) use ($tokens): string {
            self::assertMatchesRegularExpression('~' . preg_quote($name, '~') . '\s*:\s*(#[0-9a-f]{6})~i', $tokens, $name);
            preg_match('~' . preg_quote($name, '~') . '\s*:\s*(#[0-9a-f]{6})~i', $tokens, $m);
            return strtolower($m[1]);
        };

        foreach (ChallengeFlier::THEMES as $theme => $want) {
            self::assertSame(1, preg_match('~\[data-theme="' . $theme . '"\]\s*\{([^}]*)\}~', $css, $m), "no $theme block");
            foreach (['fill', 'edge', 'solid'] as $role) {
                self::assertSame(1, preg_match('~--ch-' . $role . '\s*:\s*var\((--[a-z0-9-]+)\)~', $m[1], $v), "$theme.$role");
                self::assertSame($token($v[1]), strtolower($want[$role]), "$theme.$role is not the page's {$v[1]}");
            }
        }
    }

    /** The ground follows the theme, so a blue challenge gets a blue flier. */
    public function test_the_ground_follows_the_theme(): void
    {
        foreach ([E::THEME_GREEN, E::THEME_BLUE, E::THEME_GOLD, E::THEME_ROSE] as $theme) {
            $im = $this->render(['theme' => $theme]);
            self::assertTrue(self::near(ChallengeFlier::palette($theme)['ground'], self::hexAt($im, 520, 60), 1), $theme);
        }
        self::assertNotSame(ChallengeFlier::palette(E::THEME_GREEN)['ground'], ChallengeFlier::palette(E::THEME_BLUE)['ground']);
    }

    /**
     * The button's label is tracked tight, which GD cannot do, so it is drawn a character
     * at a time — and the first cut of that summed per-glyph boxes, which drop the space,
     * and printed "JOINNOW". Counted as ink columns: two words, one gap.
     */
    public function test_the_button_label_keeps_its_word_gap(): void
    {
        $im = $this->render();
        $cols = [];
        for ($x = 812; $x < 995; $x++) {
            $ink = false;
            for ($y = 1000; $y < 1026 && !$ink; $y++) {
                $p = imagecolorat($im, $x, $y);
                $ink = (($p >> 16) & 255) > 200 && (($p >> 8) & 255) > 200;
            }
            $cols[] = $ink;
        }
        $gaps = 0; $run = 0; $seen = false;
        foreach ($cols as $ink) {
            if ($ink) { if ($seen && $run >= 5) $gaps++; $run = 0; $seen = true; } else { $run++; }
        }
        self::assertSame(1, $gaps, 'the label is not two words with a space between them');
    }

    /**
     * The shadow is a glow, not an outline. Stacked translucent shapes overlap and lay
     * the colour down twice — drawn that way it came out as hard grey bars along the
     * card's foot. Beneath the card it must only ever lighten, and be even along the edge.
     */
    public function test_the_card_shadow_fades_and_has_no_band(): void
    {
        $im = $this->render();
        $lum = static fn (int $x, int $y): int => (imagecolorat($im, $x, $y) >> 8) & 255;

        $prev = 0;
        for ($y = 900; $y < 930; $y++) {
            $g = $lum(297, $y);
            self::assertGreaterThanOrEqual($prev - 1, $g, "the shadow darkens again at y=$y");
            $prev = $g;
        }
        $ground = (FlierLayout::rgb(ChallengeFlier::palette(E::THEME_GREEN)['ground']))[1];
        self::assertLessThan($ground - 6, $lum(297, 901), 'there is no shadow under the card');
        self::assertGreaterThanOrEqual($ground - 1, $lum(297, 935), 'the shadow does not end');

        $row = array_map(static fn (int $x): int => $lum($x, 903), range(240, 354, 6));
        self::assertLessThanOrEqual(2, max($row) - min($row), 'the shadow is uneven along the card\'s foot');
    }

    /** A sticker is decoration, but it follows a fact: the flag for a flag, the coupon for cash. */
    public function test_the_stickers_follow_the_challenge(): void
    {
        $ground = ChallengeFlier::palette(E::THEME_GREEN)['ground'];
        $drawn = static function (\GdImage $im, int $x0, int $y0, int $x1, int $y1) use ($ground): int {
            $n = 0;
            for ($y = $y0; $y < $y1; $y += 2) for ($x = $x0; $x < $x1; $x += 2) {
                if (!self::near($ground, self::hexAt($im, $x, $y), 3)) $n++;
            }
            return $n;
        };

        $both = $this->render();
        self::assertGreaterThan(400, $drawn($both, 772, 146, 890, 238), 'no flag on a flag challenge');
        self::assertGreaterThan(400, $drawn($both, 42, 598, 140, 702), 'no coupon on a cash prize');

        $none = $this->render(['flag' => 0, 'prize_type' => E::PRIZE_POINTS, 'prize_amount' => 500]);
        self::assertSame(0, $drawn($none, 772, 146, 890, 238), 'a flag on a challenge that flies none');
        self::assertSame(0, $drawn($none, 42, 598, 140, 702), 'a coupon on a prize that is not money');
    }

    /** The prize is printed in the flag's green only on a challenge that flies the flag. */
    public function test_the_prize_colour_is_the_flags_only_with_the_flag(): void
    {
        $count = static function (\GdImage $im, string $hex): int {
            $n = 0;
            for ($y = 740; $y < 782; $y++) for ($x = 210; $x < 385; $x++) {
                if (self::near($hex, self::hexAt($im, $x, $y), 2)) $n++;
            }
            return $n;
        };
        self::assertGreaterThan(500, $count($this->render(), '#008751'));
        $plain = $this->render(['flag' => 0]);
        self::assertSame(0, $count($plain, '#008751'));
        self::assertGreaterThan(500, $count($plain, ChallengeFlier::THEMES[E::THEME_GREEN]['solid']));
    }

    /**
     * No picture — or a picture whose file is gone — draws the challenge's initial on a
     * disc where the portrait would stand, rather than leaving half the flier bare.
     */
    public function test_a_challenge_with_no_picture_still_fills_the_portraits_place(): void
    {
        foreach (['', '/assets/img/challenges/no-such-file.png'] as $url) {
            $im = $this->render(['portrait_url' => $url, 'art_url' => '']);
            self::assertTrue(self::near('#ffffff', self::hexAt($im, 700, 685), 1), "no disc for '$url'");
            $ink = 0;
            for ($y = 600; $y < 770; $y += 2) for ($x = 770; $x < 910; $x += 2) {
                if (((imagecolorat($im, $x, $y) >> 16) & 255) < 120) $ink++;
            }
            self::assertGreaterThan(500, $ink, "no initial on the disc for '$url'");
        }
    }
}
