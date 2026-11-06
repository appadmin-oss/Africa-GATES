<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/** Approved imagery and its metadata. Original photo files are never rewritten. */
final class ImageCatalog
{
    public const PEOPLE = [
        'teacher' => ['A teacher', 'A teacher guiding two students at a classroom table', 'Teachers who changed a classroom', 'teacher-book'],
        'nurse' => ['A nurse or carer', 'A community nurse checking an elderly woman’s blood pressure', 'Carers who keep a community well', 'nurse-care'],
        'community-leader' => ['A community leader', 'A community leader speaking at a neighbourhood meeting', 'Leaders their street already celebrates', 'community-linked'],
        'creative' => ['A creative', 'A designer draping indigo fabric in her studio', 'Creatives bringing ideas to life', 'creative-tools'],
        'business' => ['A business', 'A shop founder at the counter of her boutique', 'Businesses making a difference', 'business-shop'],
        'young-leader' => ['A young leader', 'A young volunteer holding a clipboard at a youth event', 'Young people building what comes next', 'young-leader-spark'],
    ];

    private readonly string $publicDir;

    public function __construct(?string $publicDir = null)
    {
        $this->publicDir = $publicDir ?? dirname(__DIR__, 2) . '/public';
    }

    public function photo(string $key): ?array
    {
        if (!isset(self::PEOPLE[$key])) return null;
        $sources = [];
        foreach ([480, 960] as $width) {
            $src = '/assets/img/honour/' . $key . '-' . $width . '.webp';
            if (is_file($this->publicDir . $src)) $sources[$width] = $src;
        }
        if ($sources === []) return null;
        [$label, $alt, $headline] = self::PEOPLE[$key];
        $width = (int) array_key_first($sources);
        return ['id' => 'photo:' . $key, 'key' => $key, 'kind' => 'photo', 'label' => $label,
            'alt' => $alt, 'headline' => $headline, 'src' => $sources[$width],
            'srcset' => implode(', ', array_map(static fn ($w, $src) => \AfricaGates\Support\Assets::url($src) . ' ' . $w . 'w', array_keys($sources), $sources)),
            'width' => $width, 'height' => $width];
    }

    public function photos(): array
    {
        return array_values(array_filter(array_map($this->photo(...), array_keys(self::PEOPLE))));
    }

    public function utility(string $key): ?array
    {
        // The manifest is the allowlist; no user-supplied URL or filesystem path is read.
        static $approved = null;
        if ($approved === null) {
            $manifest = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/public/assets/img/utility/manifest.json'), true) ?? [];
            // Experimental/rejected artwork must not become live merely because its files exist.
            $approved = ($manifest['approved_for_production'] ?? false) === true ? ($manifest['assets'] ?? []) : [];
        }
        if (!isset($approved[$key])) return null;
        $sources = [];
        foreach ([256, 512, 960] as $width) {
            $src = '/assets/img/utility/' . $key . '-' . $width . '.webp';
            if (is_file($this->publicDir . $src)) $sources[$width] = $src;
        }
        if ($sources === []) return null;
        $width = isset($sources[512]) ? 512 : (int) array_key_first($sources);
        return ['id' => 'utility:' . $key, 'kind' => 'utility', 'key' => $key,
            'src' => $sources[$width],
            'srcset' => implode(', ', array_map(static fn ($w, $src) => \AfricaGates\Support\Assets::url($src) . ' ' . $w . 'w', array_keys($sources), $sources)),
            'alt' => '', 'label' => $approved[$key]['title'], 'width' => $width, 'height' => $width];
    }

    /** Treat the two shipped resolutions as one photograph; uploaded records keep their stored identity. */
    public static function identity(string $src): string
    {
        $path = (string) (parse_url($src, PHP_URL_PATH) ?? $src);
        if (preg_match('~^/assets/img/honour/([a-z-]+)-(480|960)\.webp$~', $path, $m) && isset(self::PEOPLE[$m[1]])) {
            return 'photo:' . $m[1];
        }
        return 'media:' . hash('sha256', trim($src));
    }

    public static function cover(string $context): array
    {
        return ['id' => 'cover:' . $context, 'kind' => 'cover', 'key' => $context,
            'src' => '', 'srcset' => '', 'alt' => '', 'label' => 'Recognition', 'width' => 160, 'height' => 128];
    }
}
