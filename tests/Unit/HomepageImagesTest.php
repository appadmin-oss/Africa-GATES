<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{HomepageImages, ImageCatalog, ImageSelection};
use Tests\TestCase;

final class HomepageImagesTest extends TestCase
{
    private function images(array $plan): array
    {
        $images = array_merge(array_column($plan['hero_slides'], 'image'), array_column($plan['honour'], 'image'),
            array_column($plan['topics'], 'image'), array_column($plan['audiences'], 'image'), $plan['closing_pics']);
        if ($plan['closing_art']) $images[] = $plan['closing_art'];
        return $images;
    }

    public function test_fallback_uses_each_original_photo_once_without_losing_a_category(): void
    {
        $plan = (new HomepageImages(new ImageCatalog()))->build([], '2026-10-31');
        $images = $this->images($plan);
        $ids = array_column($images, 'id');
        $this->assertCount(count(array_unique($ids)), $ids);
        $this->assertCount(6, array_filter($images, static fn ($i) => $i['kind'] === 'photo'));
        $this->assertCount(6, $plan['honour']);
        $this->assertCount(1, $plan['hero_slides']);
        $this->assertFalse($plan['hero_slides'][0]['real']);
        foreach ($plan['honour'] as $tile) {
            $expected = $tile['image']['kind'] === 'utility' ? ImageCatalog::PEOPLE[$tile['key']][3] : $tile['key'];
            $this->assertSame($expected, $tile['image']['key'], 'a category must not borrow an unrelated photograph');
        }
        foreach (array_merge($plan['topics'], $plan['audiences']) as $card) {
            $this->assertSame('cover', $card['image']['kind']);
            $this->assertSame('', $card['image']['alt'], 'adjacent text describes these decorative utility images');
        }
    }

    public function test_daily_rotation_is_stable_and_visits_every_photo_before_repeating(): void
    {
        $service = new HomepageImages(new ImageCatalog());
        $day = new \DateTimeImmutable('2026-10-29');
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $date = $day->modify('+' . $i . ' days')->format('Y-m-d');
            $plan = $service->build([], $date);
            $this->assertSame($plan, $service->build([], $date));
            $ids[] = $plan['hero_slides'][0]['image']['id'];
        }
        $this->assertCount(6, array_unique($ids));
    }

    public function test_resolution_aliases_are_one_identity_and_their_description_travels_with_them(): void
    {
        $catalog = new ImageCatalog();
        $photo = $catalog->photo('creative');
        $this->assertSame(ImageCatalog::identity($photo['src']), ImageCatalog::identity('/assets/img/honour/creative-960.webp?v=123'));
        $this->assertSame('A designer draping indigo fabric in her studio', $photo['alt']);
        $selection = new ImageSelection('2026-10-31');
        $this->assertNotNull($selection->pick([$photo]));
        $this->assertNull($selection->pick([$catalog->photo('creative')]));
    }

    public function test_real_recipients_keep_their_records_and_a_duplicate_photo_gets_no_invented_face(): void
    {
        $records = [];
        for ($i = 0; $i < 7; $i++) $records[] = ['name' => 'Recipient ' . $i, 'photo' => '/uploads/recipient-' . $i . '.jpg',
            'category' => 'Community contribution', 'programme' => 'Awards', 'edition' => '2026', 'url' => '/results/record-' . $i];
        $service = new HomepageImages(new ImageCatalog());
        $plan = $service->build($records, '2026-10-31');
        $this->assertCount(4, $plan['hero_slides']);
        $this->assertCount(3, $plan['closing_pics']);
        $ids = array_column($this->images($plan), 'id');
        $this->assertCount(count(array_unique($ids)), $ids);
        foreach ($plan['hero_slides'] as $i => $slide) {
            $this->assertTrue($slide['real']);
            $this->assertSame($records[$i]['url'], $slide['url']);
            $this->assertSame($records[$i]['photo'], $slide['image']['src']);
        }
        $records[1]['photo'] = $records[0]['photo'];
        $duplicate = $service->build($records, '2026-10-31');
        $this->assertSame('Recipient 1', $duplicate['hero_slides'][1]['name']);
        $this->assertSame('cover', $duplicate['hero_slides'][1]['image']['kind']);
        $this->assertSame('/results/record-1', $duplicate['hero_slides'][1]['url']);
    }

    public function test_missing_asset_files_fall_back_without_broken_image_urls(): void
    {
        $catalog = new ImageCatalog(sys_get_temp_dir() . '/afg-no-assets-' . bin2hex(random_bytes(5)));
        $this->assertNull($catalog->photo('teacher'));
        $plan = (new HomepageImages($catalog))->build([], '2026-10-31');
        foreach ($this->images($plan) as $image) {
            $this->assertSame('cover', $image['kind']);
            $this->assertSame('', $image['src']);
        }
        $this->assertCount(6, $plan['honour']);
    }

    public function test_rejected_utility_art_is_not_selected(): void
    {
        $this->assertNull((new ImageCatalog())->utility('teacher-book'));
        foreach ($this->images((new HomepageImages(new ImageCatalog()))->build([])) as $image) {
            $this->assertNotSame('utility', $image['kind']);
        }
    }

    public function test_a_missing_raster_resolution_does_not_create_a_broken_candidate(): void
    {
        $root = sys_get_temp_dir() . '/afg-raster-' . bin2hex(random_bytes(5));
        $directory = $root . '/assets/img/honour';
        mkdir($directory, 0700, true);
        $filename = $directory . '/teacher-480.webp';
        copy(dirname(__DIR__, 2) . '/public/assets/img/honour/teacher-480.webp', $filename);
        try {
            $image = (new ImageCatalog($root))->photo('teacher');
            $this->assertNotNull($image);
            $this->assertSame('/assets/img/honour/teacher-480.webp', $image['src']);
            $this->assertSame(\AfricaGates\Support\Assets::url('/assets/img/honour/teacher-480.webp') . ' 480w', $image['srcset']);
            $this->assertSame(480, $image['width']);
            unlink($filename);
            $this->assertNull((new ImageCatalog($root))->photo('teacher'));
        } finally {
            if (is_file($filename)) unlink($filename);
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir($root . '/assets');
            rmdir($root);
        }
    }
}
