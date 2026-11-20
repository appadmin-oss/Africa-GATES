<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShareCardRenderer;
use AfricaGates\Services\ShareCardSpec;
use PHPUnit\Framework\TestCase;

/**
 * The share card system (ShareCardSpec + ShareCardRenderer), pinned.
 *
 * Four sizes, two styles, five tones, two grounds, and a nominee who may have no photo,
 * no rank, no organisation or a long accented name. Every combination must say the same
 * things in the same order, and the SVG and PNG must come from one layout plan.
 */
final class ShareCardTest extends TestCase
{
    /** @return array<string,mixed> */
    private function facts(array $over = []): array
    {
        return $over + [
            'id' => 48, 'name' => 'Adaeze Okonkwo', 'tagline' => '', 'rally' => 'Back the people building Alimosho.',
            'category' => 'Retail & Lifestyle', 'programme' => 'Alimosho Business Awards',
            'organisation' => 'Ada’s Pantry, Ikotun', 'photo' => null, 'photo_card' => null,
            'standing' => ['rank' => 3, 'field' => 12, 'gap_ahead' => 38, 'next_rank' => 2],
            'url' => 'https://afg.afrovanguard.org.ng/vote/aba/48-adaeze-okonkwo',
            'short_url' => 'afg.afrovanguard.org.ng/vote/aba/48',
        ];
    }

    /** @return list<string> every text run the plan draws, in order */
    private function texts(array $spec): array
    {
        $r = new ShareCardRenderer('https://example.test');
        return array_values(array_map(static fn ($o) => $o['s'],
            array_filter($r->plan($spec), static fn ($o) => $o['op'] === 'text')));
    }

    public function test_only_known_options_survive_the_query_string(): void
    {
        $o = ShareCardSpec::options(['size' => 'status', 'style' => 'statement', 'tone' => 'live', 'mode' => 'bold']);
        $this->assertSame(['format' => 'status', 'layout' => 'statement', 'tone' => 'live', 'mode' => 'bold'], $o);

        $bad = ShareCardSpec::options(['size' => '<script>', 'style' => ['x'], 'tone' => 'purple', 'mode' => 'neon', 'line' => 'anything']);
        $this->assertSame(['format' => 'feed', 'layout' => 'portrait', 'tone' => null, 'mode' => 'light'], $bad,
            'unknown values fall back, and there is no free-text parameter at all');
    }

    public function test_each_award_has_a_default_tone(): void
    {
        $this->assertSame('gold', ShareCardSpec::defaultTone('Alimosho Business Awards'));
        $this->assertSame('green', ShareCardSpec::defaultTone('Alimosho Impactful Leadership Awards'));
        $this->assertSame('live', ShareCardSpec::defaultTone('Alimosho Choral Awards'));
        $this->assertSame('info', ShareCardSpec::defaultTone('Alimosho Incredible Principal Awards'));
        $this->assertSame('green', ShareCardSpec::defaultTone('Something New'), 'an unknown programme still gets a colour');
    }

    public function test_every_size_has_its_canvas(): void
    {
        foreach (['feed' => [1080, 1350], 'status' => [1080, 1920], 'square' => [1080, 1080], 'link' => [1200, 630]] as $fmt => [$w, $h]) {
            $s = ShareCardSpec::for($this->facts(), ['format' => $fmt]);
            $this->assertSame([$w, $h], [$s['W'], $s['H']], $fmt);
        }
    }

    public function test_the_hierarchy_is_category_name_meta_then_the_ask(): void
    {
        $t = $this->texts(ShareCardSpec::for($this->facts()));
        $order = array_map(static fn ($needle) => array_search($needle, $t, true),
            ['RETAIL & LIFESTYLE', 'Adaeze Okonkwo', 'Ada’s Pantry, Ikotun', '38 votes from #2', 'VOTE NOW', 'afg.afrovanguard.org.ng/vote/aba/48']);
        $this->assertNotContains(false, $order, implode(' | ', $t));
        $sorted = $order; sort($sorted);
        $this->assertSame($sorted, $order, 'read top to bottom in the designed order');
        $this->assertSame(1, count(array_keys($t, 'VOTE NOW', true)), 'the ask is made once');
        $this->assertContains(ShareCardSpec::JURY, $t, 'the jury line is on the card');
    }

    public function test_rank_and_standing_appear_only_when_they_say_something(): void
    {
        $alone = ShareCardSpec::for($this->facts(['standing' => ['rank' => 1, 'field' => 1]]));
        $this->assertFalse($alone['showRank'], '#1 of 1 is not a standing');
        $this->assertSame('', $alone['standing']);

        $lead = ShareCardSpec::for($this->facts(['standing' => ['rank' => 1, 'field' => 9]]));
        $this->assertSame('Leading the field', $lead['standing']);

        $one = ShareCardSpec::for($this->facts(['standing' => ['rank' => 4, 'field' => 9, 'gap_ahead' => 1, 'next_rank' => 3]]));
        $this->assertSame('1 vote from #3', $one['standing']);
    }

    public function test_no_photo_draws_the_tone_mark_and_initials(): void
    {
        $r = new ShareCardRenderer();
        $ops = $r->plan(ShareCardSpec::for($this->facts(['name' => 'Ọlásùnkànmí Adébáyọ̀'])));
        $kinds = array_column($ops, 'op');
        $this->assertNotContains('photo', $kinds);
        $this->assertContains('rings', $kinds);
        $this->assertContains('ỌA', array_column(array_filter($ops, static fn ($o) => $o['op'] === 'text'), 's'));
    }

    public function test_a_statement_card_uses_the_profile_line_not_a_query(): void
    {
        $s = ShareCardSpec::for($this->facts(['tagline' => 'Twenty years of singing for Alimosho.']), ['layout' => 'statement']);
        $this->assertSame('Twenty years of singing for Alimosho.', $s['line']);
        $this->assertSame('', ShareCardSpec::for($this->facts(['tagline' => 'x']))['line'], 'the photo style carries no line');
        $this->assertStringContainsString('“Twenty years of singing for Alimosho.”', implode(' ', $this->texts($s)), 'drawn, in quotes, however it wraps');
    }

    public function test_long_names_step_down_and_stay_inside_the_card(): void
    {
        $short = ShareCardSpec::for($this->facts());
        $long  = ShareCardSpec::for($this->facts(['name' => 'Ọlásùnkànmí Adébáyọ̀-Williams Oluwaseun']));
        $this->assertLessThan($short['nameSize'], $long['nameSize']);

        foreach (ShareCardSpec::FORMATS as $fmt) {
            $s = ShareCardSpec::for($this->facts(['name' => 'Ọlásùnkànmí Adébáyọ̀-Williams Oluwaseun Ayọ̀dèjì']), ['format' => $fmt]);
            foreach ((new ShareCardRenderer())->plan($s) as $o) {
                if ($o['op'] !== 'text') continue;
                $this->assertLessThanOrEqual($s['H'], $o['y'], $fmt . ': "' . $o['s'] . '" falls off the card');
            }
        }
    }

    public function test_bold_mode_keeps_type_white_on_the_deep_ground(): void
    {
        foreach (['gold', 'green', 'info', 'live', 'stone'] as $tone) {
            $c = ShareCardSpec::colours($tone, true);
            $this->assertSame('#ffffff', $c['text']);
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($c['ground'], '#ffffff'), $tone);
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($c['ground'], $c['eyebrow']), $tone . ' label');
            $light = ShareCardSpec::colours($tone, false);
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($light['ground'], $light['eyebrow']), $tone . ' light label');
        }
    }

    public function test_the_svg_escapes_a_hostile_name(): void
    {
        $svg = (new ShareCardRenderer())->svg(ShareCardSpec::for($this->facts(['name' => '<script>alert(1)</script>'])));
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
    }

    /** The link is typed off a status by somebody without a camera: never cut short. */
    public function test_the_link_is_never_cut_short_in_any_size(): void
    {
        $f = $this->facts(['short_url' => 'afg.afrovanguard.org.ng/vote/lagos-youth-awards/1048']);
        foreach (ShareCardSpec::FORMATS as $size) {
            foreach (ShareCardSpec::LAYOUTS as $style) {
                $t = $this->texts(ShareCardSpec::for($f, ['format' => $size, 'layout' => $style]));
                $this->assertContains($f['short_url'], $t, "$size/$style: the link was clipped");
            }
        }
    }

    /**
     * The video is the card in motion, so it paints with the card's colours — handed over
     * in the story, never typed into the script. The handoff's script carried its own
     * table of the five tones and it had already drifted from Accent (stone's edge).
     */
    public function test_the_video_takes_its_colours_from_the_one_palette(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/share-video-award.js');
        $this->assertSame(0, preg_match_all('/#[0-9a-f]{6}\b|rgb\(\s*\d/i', $js), 'a colour typed in the video script is a second palette');
        foreach (\AfricaGates\Support\Accent::coverTones() as $tone) {
            $p = ShareCardSpec::videoPalette($tone);
            $this->assertSame(\AfricaGates\Support\Accent::coverHex($tone, 'edge'), $p['edge'], $tone);
            $this->assertSame(ShareCardSpec::colours($tone, true)['ground'], $p['deep'], "$tone: the video's action beat is not the bold card's ground");
            $this->assertSame(ShareCardSpec::colours($tone, true)['eyebrow'], $p['label'], $tone);
        }
        $this->assertSame(ShareCardSpec::videoPalette('green'), ShareCardSpec::videoPalette('<script>'), 'an unknown tone must fall back, not throw on a public URL');
    }

    /** Leaving the page in place (site-nav.js) ends playback and any recording. */
    public function test_the_video_stops_when_its_page_is_left(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/share-video-award.js');
        $this->assertMatchesRegularExpression('~pg\.leave\(function \(\) \{\s*cancelAnimationFrame\(raf\);~', $js);
        $this->assertStringContainsString("rec.state === 'recording'", $js);
        $this->assertSame([], SiteNavTest::offences($js));
    }

    public function test_the_png_is_a_real_image_at_the_right_size(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD is not available');
        foreach (ShareCardSpec::FORMATS as $fmt) {
            $png = (new ShareCardRenderer())->png(ShareCardSpec::for($this->facts(), ['format' => $fmt, 'mode' => 'bold']));
            $this->assertNotNull($png);
            $this->assertStringStartsWith("\x89PNG", $png);
            $info = getimagesizefromstring($png);
            $this->assertSame(ShareCardSpec::SIZE[$fmt], [$info[0], $info[1]], $fmt);
        }
    }

    private function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static fn ($v) => $v / 255, sscanf(ltrim($hex, '#'), '%02x%02x%02x'));
            $c = array_map(static fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [$lum($a), $lum($b)];
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}
