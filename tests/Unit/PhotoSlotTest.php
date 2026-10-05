<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PhotoCover;
use AfricaGates\Support\Translator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * ONE ANSWER TO "WHAT GOES HERE WITH NO PHOTO" (GAPS §8 Q8, owner 4 Oct 2026):
 * `partials/photo.twig` — a real image, a monogram for a person, a generated cover for a
 * thing — and every event image slot and the home page's slots go through it.
 */
final class PhotoSlotTest extends TestCase
{
    private function render(array $args): string
    {
        $twig = Translator::register(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]));
        return $twig->render('partials/photo.twig', $args);
    }

    public function test_a_real_image_is_shown_and_lazy_unless_it_is_the_hero(): void
    {
        $h = $this->render(['kind' => 'thing', 'src' => '/uploads/x.jpg', 'name' => 'Gala']);
        $this->assertStringContainsString('<img class="ag-photo__img" src="/uploads/x.jpg"', $h);
        $this->assertStringContainsString('loading="lazy"', $h);
        $this->assertStringContainsString('fetchpriority="high"', $this->render(['kind' => 'thing', 'src' => '/x.jpg', 'name' => 'G', 'eager' => true]));
    }

    public function test_a_person_without_a_photo_is_a_monogram_never_an_image(): void
    {
        $h = $this->render(['kind' => 'person', 'src' => '', 'name' => 'Amara Okonkwo']);
        $this->assertStringContainsString('ag-photo--mono', $h);
        $this->assertStringContainsString('>AO<', $h);
        $this->assertStringNotContainsString('<img', $h);
    }

    public function test_a_thing_without_a_photo_is_its_own_accent_cover_with_its_title(): void
    {
        $h = $this->render(['kind' => 'thing', 'src' => '', 'name' => 'Choral Night', 'tone' => PhotoCover::style('#1F6FA3')]);
        $this->assertStringContainsString('ag-photo--cover', $h);
        $this->assertStringContainsString('Choral Night', $h);
        $this->assertMatchesRegularExpression('~style="--ph-top:#[0-9A-F]{6};--ph-bottom:#[0-9A-F]{6};--ph-ink:#[0-9A-F]{6};--ph-rule:#[0-9A-F]{6}"~i', $h);
        $this->assertNotSame(PhotoCover::style('#1F6FA3'), PhotoCover::style('#B4452F'), 'the cover follows the accent');
    }

    public function test_no_stock_photo_and_every_slot_goes_through_the_partial(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['templates/pages/events.twig', 'templates/pages/events/detail.twig', 'templates/pages/home.twig'] as $f) {
            $s = (string) file_get_contents("$root/$f");
            $this->assertStringContainsString("partials/photo.twig", $s, $f);
            $this->assertStringNotContainsString('unsplash', strtolower($s), $f);
        }
        // And each page this phase owns links the slot's sheet (it is not in the base bundle;
        // other phases' pages that include the partial are reported in PHASE-7.md E6, B5).
        foreach (['templates/pages/events.twig', 'templates/pages/events/detail.twig', 'templates/pages/home.twig'] as $f) {
            $this->assertStringContainsString('/assets/css/components/photo.css', (string) file_get_contents("$root/$f"), $f);
        }
        $home = (string) file_get_contents("$root/templates/pages/home.twig");
        $this->assertStringNotContainsString('{{ d.ini }}{% if', $home, 'the ad-hoc initials-under-an-image treatment is gone');
        $this->assertStringNotContainsString('hm-give__ini', $home);
    }
}
