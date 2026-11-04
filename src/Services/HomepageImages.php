<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/** The complete homepage image plan, allocated together rather than shuffled in each template. */
final class HomepageImages
{
    private const HERO_ART = ['recognition-star', 'recognition-laurel', 'recognition-frame', 'recognition-seal'];
    private const TOPICS = [
        ['events', 'Find an event near you', 'Galas, ceremonies and community days, with tickets in one place.', 'Events', '/events', ['events-calendar', 'events-ticket']],
        ['organisers', 'Run your awards on Africa GATES', 'Editions, judging and public voting in one place.', 'For organisers', '/partner', ['awards-certificate', 'awards-podium']],
        ['shop', 'Frame your recognition', 'Certificates and frames for the honours on your profile.', 'Shop', '/shop', ['shop-frame', 'shop-ribbon']],
    ];
    private const AUDIENCES = [
        ['Communities', 'Nominate someone', '/nominate', ['community-network', 'community-leaves']],
        ['Organisations', 'Host an award', '/partner', ['organisation-building', 'organisation-checklist']],
        ['Businesses', 'Recognise your team', '/partner', ['business-briefcase', 'business-growth']],
        ['Governments', 'Talk to us', '/partner', ['government-civic', 'government-document']],
    ];

    public function __construct(private readonly ImageCatalog $catalog) {}

    public function build(array $decided, ?string $date = null): array
    {
        $selection = new ImageSelection($date ?? date('Y-m-d'));
        $art = fn (array $keys, string $context): array => $selection->pick(
            array_map($this->catalog->utility(...), $keys), true, $context
        ) ?? ImageCatalog::cover($context);

        $hero = [];
        foreach (array_slice($decided, 0, 4) as $index => $record) {
            $photo = trim((string) ($record['photo'] ?? ''));
            $image = $photo !== '' && $selection->claim(ImageCatalog::identity($photo))
                ? ['id' => ImageCatalog::identity($photo), 'kind' => 'media', 'src' => $photo,
                   'alt' => (string) ($record['name'] ?? ''), 'width' => 0, 'height' => 0, 'srcset' => '']
                : $art(self::HERO_ART, 'hero-' . $index);
            $hero[] = ['real' => true, 'name' => (string) ($record['name'] ?? ''),
                'what' => (string) ($record['category'] ?? ''),
                'issuer' => trim(($record['programme'] ?? '') . ' ' . ($record['edition'] ?? '')),
                'url' => (string) ($record['url'] ?? '/results'), 'image' => $image];
        }
        if ($hero === []) {
            $image = $selection->pick($this->catalog->photos(), true, 'home-hero') ?? $art(self::HERO_ART, 'hero');
            $hero[] = ['real' => false, 'name' => $image['headline'] ?? 'Recognise the people who make a difference',
                'what' => 'Nominate someone like this', 'issuer' => 'Africa GATES', 'url' => '/nominate', 'image' => $image];
        }

        $honour = [];
        foreach (ImageCatalog::PEOPLE as $key => [$label, $alt, $headline, $utility]) {
            $image = $selection->pick([$this->catalog->photo($key), $this->catalog->utility($utility)]) ?? ImageCatalog::cover($key);
            $honour[] = ['key' => $key, 'label' => $label, 'image' => $image];
        }
        $topics = [];
        foreach (self::TOPICS as [$key, $title, $description, $tag, $href, $keys]) {
            $topics[] = ['key' => $key, 'title' => $title, 'description' => $description, 'tag' => $tag,
                'href' => $href, 'image' => $art($keys, 'topic-' . $key)];
        }
        $audiences = [];
        foreach (self::AUDIENCES as [$title, $cta, $href, $keys]) {
            $audiences[] = ['title' => $title, 'cta' => $cta, 'href' => $href, 'image' => $art($keys, 'audience-' . $title)];
        }

        // Closing portraits must be genuine, distinct and absent from the hero.
        $closing = [];
        foreach (array_slice($decided, 4) as $record) {
            $photo = trim((string) ($record['photo'] ?? ''));
            if ($photo === '' || !$selection->claim(ImageCatalog::identity($photo))) continue;
            $closing[] = ['id' => ImageCatalog::identity($photo), 'kind' => 'media', 'src' => $photo, 'alt' => '', 'width' => 0, 'height' => 0, 'srcset' => ''];
            if (count($closing) === 3) break;
        }
        return ['hero_slides' => $hero, 'honour' => $honour, 'topics' => $topics, 'audiences' => $audiences,
            'closing_pics' => count($closing) === 3 ? $closing : [],
            'closing_art' => count($closing) === 3 ? null : $art(['recognition-path', 'recognition-steps'], 'closing')];
    }
}
