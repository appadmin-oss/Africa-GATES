<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Accent;
use AfricaGates\Support\AvatarMark;
use AfricaGates\Support\CoverKind;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The default graphics (DEFAULT-GRAPHICS, handoff 5 Oct 2026): what an image slot with no
 * upload draws, and the one resolver behind it.
 *
 * Held here: every kind and subject resolves to the spec's label, tone and pattern; the
 * tones are the spec's five names and their colours come from Accent alone; a NULL kind
 * resolves by whether the event is tied to an award; a date tile never appears without a
 * date; the avatar's initials skip the spec's small words and keep a non-Latin first
 * letter; its tone hashes the id, so a rename keeps the colour.
 */
final class CoverKindTest extends TestCase
{
    /** DEFAULT-GRAPHICS §4, the table, verbatim. */
    private const SPEC = [
        'ceremony' => ['Awards ceremony', 'gold', 'arches'], 'gala' => ['Gala', 'gold', 'arches'],
        'conference' => ['Conference', 'info', 'dots'], 'workshop' => ['Workshop', 'green', 'grid'],
        'training' => ['Training', 'green', 'grid'], 'webinar' => ['Webinar', 'info', 'signal'],
        'livestream' => ['Livestream', 'live', 'signal'], 'community' => ['Community', 'green', 'rings'],
        'concert' => ['Concert', 'live', 'weave'], 'sports' => ['Sports', 'green', 'stripes'],
        'fundraiser' => ['Fundraiser', 'live', 'stripes'], 'exhibition' => ['Exhibition', 'stone', 'weave'],
    ];
    private const SPEC_SUBJECTS = [
        'award' => ['Award', 'gold', 'arches'], 'campaign' => ['Giving', 'live', 'stripes'],
        'blog' => ['Story', 'stone', 'dots'], 'challenge' => ['Challenge', 'green', 'games'],
        'product' => ['Shop', 'stone', 'weave'],
    ];

    public function test_every_kind_and_subject_resolves_to_the_spec_table(): void
    {
        foreach (self::SPEC as $k => [$label, $tone, $pattern]) {
            $r = CoverKind::resolve('event', $k);
            $this->assertSame([$label, $tone, $pattern], [$r['label'], $r['tone'], $r['pattern']], $k);
        }
        foreach (self::SPEC_SUBJECTS as $k => [$label, $tone, $pattern]) {
            $r = CoverKind::resolve($k);
            $this->assertSame([$label, $tone, $pattern], [$r['label'], $r['tone'], $r['pattern']], $k);
        }
        $this->assertSame(array_keys(self::SPEC), array_keys(CoverKind::KINDS), 'no kind added or dropped');
    }

    public function test_tones_are_the_five_names_and_every_colour_comes_from_accent(): void
    {
        $this->assertSame(['green', 'gold', 'live', 'info', 'stone'], Accent::coverTones());
        foreach (CoverKind::KINDS + CoverKind::SUBJECTS as $k => [, $tone]) {
            $this->assertContains($tone, Accent::coverTones(), $k);
        }
        // The spec's own hex, slot by slot — the cover shows exactly these.
        $want = ['green' => ['#effaf0', '#237b22', '#1a6118'], 'gold' => ['#fcf4de', '#c99a06', '#7a5600'],
                 'live' => ['#fdecef', '#e0245e', '#b0224f'], 'info' => ['#e6f0f4', '#1f6fa3', '#1f5f8b'],
                 'stone' => ['#f1efe9', '#3a4a4c', '#10292c']];
        foreach ($want as $tone => [$bg, $line, $ink]) {
            $this->assertSame([$bg, $line, $ink],
                [Accent::coverHex($tone, 'bg'), Accent::coverHex($tone, 'line'), Accent::coverHex($tone, 'ink')], $tone);
        }
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/cover.css');
        $this->assertSame(0, ColourLiteralTest::literals($css), 'cover.css types no colour');
        $this->assertStringContainsString('--ag-cover-gold-line', Accent::css(), 'Accent emits the tones');
    }

    public function test_a_null_kind_resolves_by_whether_the_event_is_an_awards_one(): void
    {
        $this->assertSame('ceremony', CoverKind::eventKind(null, true));
        $this->assertSame('community', CoverKind::eventKind(null, false));
        $this->assertSame('webinar', CoverKind::eventKind('Webinar', true), 'a stored kind wins');
        $this->assertSame('community', CoverKind::eventKind('stock-photo', false), 'an unknown stored value is not trusted');
        $this->assertFalse(CoverKind::isKind('award'), 'award is a subject, not an event kind');
        $this->expectException(\InvalidArgumentException::class);
        CoverKind::resolve('poster');
    }

    public function test_there_is_never_a_date_tile_without_a_date(): void
    {
        $this->assertSame('date', CoverKind::content('auto', true));
        $this->assertSame('none', CoverKind::content('auto', false));
        $this->assertSame('none', CoverKind::content('date', false), 'no "TBC" tile');
        $this->assertSame('title', CoverKind::content('title', false));
    }

    public function test_the_cover_renders_what_its_mode_asks_for(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        \AfricaGates\Support\Translator::register($twig);
        \Tests\Support\AppTwig::equip($twig);
        $date = $twig->render('partials/cover.twig', ['kind' => 'ceremony', 'date' => '2026-12-06', 'ratio' => '4/3']);
        $this->assertStringContainsString('<span class="ag-cover__date"><b>Dec</b><span>6</span></span>', $date);
        $this->assertStringContainsString('style="aspect-ratio:4/3"', $date, 'the ratio is the only inline style');
        $this->assertStringContainsString('aria-hidden="true"', $date, 'decorative: the card names it');
        $this->assertStringNotContainsString('ag-cover__logo', $date);

        $none = $twig->render('partials/cover.twig', ['kind' => 'webinar', 'content' => 'none', 'logo' => '/host.png']);
        $this->assertStringContainsString('<img src="/host.png" alt=""', $none, 'the host logo, not the house mark');
        $this->assertStringNotContainsString('ag-cover__date', $none);

        $title = $twig->render('partials/cover.twig', ['kind' => 'community', 'content' => 'title', 'title' => 'Mathare clean-up day']);
        $this->assertStringContainsString('class="ag-cover__title ag-cover__title--l">Mathare clean-up day<', $title);
    }

    public function test_the_avatar_initials_skip_the_small_words_and_keep_any_script(): void
    {
        $this->assertSame('BG', AvatarMark::of('Bank of Ghana', 1)['initials']);
        $this->assertSame('LS', AvatarMark::of('Lagos State Ministry of Education', 1)['initials']);
        $this->assertSame('ỌA', AvatarMark::of('Ọlá Adébáyọ̀', 1)['initials'], 'a Yorùbá name keeps its own letter');
        $this->assertSame('ف', AvatarMark::of('فاطمة الزهراء', 1, 'person', 32)['initials'], 'one letter at 32px, Arabic kept');
        $this->assertTrue(AvatarMark::of('Amara Okonkwo', 1, 'person', 24)['one']);
        $this->assertSame('?', AvatarMark::of('— · —', 1)['initials']);
    }

    public function test_the_avatar_tone_hashes_the_id_so_a_rename_keeps_it(): void
    {
        $this->assertSame(AvatarMark::of('Amara Okonkwo', 4211)['tone'], AvatarMark::of('Amara Okonkwo-Bello', 4211)['tone']);
        // The DC's hash by hand: "1" → 49 % 5 = 4 (stone); "2" → 50 % 5 = 0 (green);
        // "12" → 49·31 + 50 = 1569 % 5 = 4 (stone).
        $this->assertSame('stone', AvatarMark::tone('1'));
        $this->assertSame('green', AvatarMark::tone('2'));
        $this->assertSame('stone', AvatarMark::tone('12'));
        $tones = array_unique(array_map(static fn ($i) => AvatarMark::tone((string) $i), range(1, 60)));
        $this->assertCount(5, $tones, 'ids spread over all five tones');
        $this->assertTrue(AvatarMark::of('Soko Bank', 3, 'business', 56)['badge'], 'an organisation always shows its kind');
        $this->assertFalse(AvatarMark::of('Neema Joseph', 3, 'person', 56)['badge'], 'a person only when verified');
        $this->assertFalse(AvatarMark::of('Soko Bank', 3, 'business', 32)['badge'], 'no badge under 40px');
    }

    public function test_the_games_pattern_is_served_in_the_tone_from_accent(): void
    {
        $svg = CoverKind::gamesSvg('green');
        $this->assertStringContainsString("stroke='" . Accent::coverHex('green', 'line') . "'", $svg);
        $this->assertStringContainsString("opacity='.42'", $svg);
        $this->assertStringContainsString("/img/patterns/games-green.svg", (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/cover.css'));
    }
}
