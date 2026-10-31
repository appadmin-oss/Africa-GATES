<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Translator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * ONE ANSWER TO "WHAT GOES HERE WITH NO PHOTO" (GAPS §8 Q8; DEFAULT-GRAPHICS, 5 Oct 2026):
 * `partials/photo.twig` — a real image, or the default graphics: the avatar for a person,
 * the cover for a thing — and every event image slot and the home page's slots go through it.
 *
 * The 4 Oct answer painted a thing's cover in the organiser's own accent. The 5 Oct handoff
 * replaced that: a cover's tone is decided by the thing's KIND and is never organiser-
 * editable (DEFAULT-GRAPHICS §4), so the old test that the cover "follows the accent" is
 * now its opposite — two events of the same kind with different accents draw one cover.
 */
final class PhotoSlotTest extends TestCase
{
    private function render(array $args): string
    {
        $twig = Translator::register(new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]));
        \Tests\Support\AppTwig::equip($twig);
        return $twig->render('partials/photo.twig', $args);
    }

    public function test_a_real_image_is_shown_and_lazy_unless_it_is_the_hero(): void
    {
        $h = $this->render(['kind' => 'thing', 'src' => '/uploads/x.jpg', 'name' => 'Gala']);
        $this->assertStringContainsString('<img class="ag-photo__img" src="/uploads/x.jpg"', $h);
        $this->assertStringContainsString('loading="lazy"', $h);
        $this->assertStringContainsString('fetchpriority="high"', $this->render(['kind' => 'thing', 'src' => '/x.jpg', 'name' => 'G', 'eager' => true]));
    }

    public function test_a_person_without_a_photo_is_the_default_avatar_never_an_image(): void
    {
        $h = $this->render(['kind' => 'person', 'src' => '', 'name' => 'Amara Okonkwo', 'id' => 7]);
        $this->assertStringContainsString('ag-photo--mono', $h);
        $this->assertStringContainsString('class="ag-avatar ag-avatar--', $h);
        $this->assertStringContainsString('>AO<', $h);
        $this->assertStringNotContainsString('<img', $h);
    }

    public function test_a_thing_without_a_photo_is_the_default_cover_of_its_kind(): void
    {
        $h = $this->render(['kind' => 'thing', 'subject' => 'event', 'cover_kind' => 'webinar', 'src' => '', 'name' => 'Choral Night']);
        $this->assertStringContainsString('ag-photo--cover', $h);
        $this->assertStringContainsString('ag-cover ag-cover--info ag-cover--signal ag-cover--none', $h);
        $this->assertStringContainsString('>Webinar<', $h, 'the type pill names the kind');
        $this->assertStringNotContainsString('<img', $h, 'with no host logo the tile draws the type glyph');
        $this->assertStringNotContainsString('style="--ph-', $h, 'the organiser accent is no longer the cover');
        // An award reads as an award wherever it is drawn.
        $this->assertStringContainsString('ag-cover--gold ag-cover--arches', $this->render(['kind' => 'thing', 'subject' => 'award', 'name' => 'X']));
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
