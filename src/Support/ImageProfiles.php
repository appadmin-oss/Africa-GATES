<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * What an uploaded image is FOR, and therefore what shape and sizes it is made into.
 *
 * ── ONE VOCABULARY, LIKE Media's PRESETS ─────────────────────────────────────
 *
 * Every upload used to be "scale down to N px wide and keep whatever shape arrived", and the
 * place that drew it then cropped it in CSS (`object-fit:cover`), centre-weighted, on every
 * screen differently. An ad's square was cut from the middle of a landscape photo with the
 * product at its edge, and nobody uploading it could see that happen. A profile names the
 * shape once, and the same name drives the editor in the browser (image-tool.js, which lets
 * the person choose the square), the server's cut (UploadService::uploadProcessed(), which
 * applies their choice or, with no script, cuts the centre) and the sizes written for srcset.
 *
 * Fields:
 *   aspect    width ÷ height of the result (1.0 = square)
 *   min       the shortest side the CHOSEN area may have, in source pixels — the cut, not the
 *             file, because a 4000px photo zoomed to a corner can still be too small
 *   out       the longest side of the stored image; never upscaled to reach it
 *   variants  narrower copies written beside it, for srcset (`<uuid>-<w>w.webp`)
 *   quality   WebP quality for all of them
 *   label     what the editor calls the shape, to the person cropping
 */
final class ImageProfiles
{
    public const PROFILES = [
        // The ad card's square (partials/ad-slot.twig): drawn up to ~520 CSS px, so 1080
        // covers a 2× phone and 540 a 1× one.
        'ad'      => ['aspect' => 1.0, 'min' => 600, 'out' => 1080, 'variants' => [540], 'quality' => 82, 'label' => 'square'],
        // An advertiser's logo: a 40px circle on the card. 400 for the editor and the
        // library, 96 for the card itself (2× of 40, with room).
        'ad_logo' => ['aspect' => 1.0, 'min' => 200, 'out' => 400, 'variants' => [96], 'quality' => 86, 'label' => 'square'],
    ];

    /** @return array{aspect:float,min:int,out:int,variants:list<int>,quality:int,label:string} */
    public static function get(string $name): array
    {
        if (!isset(self::PROFILES[$name])) throw new \InvalidArgumentException("No image profile '$name'.");
        return self::PROFILES[$name];
    }

    /**
     * The attributes an `<input type="file">` carries for image-tool.js, from the profile —
     * so the editor's square, its minimum and its output size are the server's, not a copy.
     */
    public static function attrs(string $name): string
    {
        $p = self::get($name);
        return sprintf('data-ag-image="%s" data-aspect="%s" data-min="%d" data-out="%d"',
            htmlspecialchars($name, ENT_QUOTES), rtrim(rtrim(number_format($p['aspect'], 4, '.', ''), '0'), '.'), $p['min'], $p['out']);
    }

    /**
     * `srcset` for a stored path, from the variants written beside it, or null.
     *
     * Only for a file on THIS server (a remote/Cloudinary URL is sized by Media's presets),
     * and only the variants that exist — an image uploaded before this was written has none,
     * and a srcset naming a file that is not there is a broken image on exactly the screens
     * it was meant for.
     */
    public static function srcset(?string $stored, ?string $publicRoot = null): ?string
    {
        $p = trim((string) $stored);
        if ($p === '' || !str_starts_with($p, '/uploads/') || !preg_match('~^(/uploads/.+)\.webp$~', $p, $m)) return null;
        $root = $publicRoot ?? dirname(__DIR__, 2) . '/public';
        $parts = [];
        $widths = [];
        foreach (self::PROFILES as $prof) $widths = array_merge($widths, $prof['variants']);
        foreach (array_unique($widths) as $w) {
            $v = $m[1] . '-' . $w . 'w.webp';
            if (is_file($root . $v)) $parts[$w] = $v . ' ' . $w . 'w';
        }
        if ($parts === []) return null;
        $size = @getimagesize($root . $p);
        if (is_array($size)) $parts[(int) $size[0]] = $p . ' ' . (int) $size[0] . 'w';
        ksort($parts);
        return implode(', ', $parts);
    }

    /**
     * The area the person chose, from the editor's hidden field: `x,y,w,h[,r]`, the first four
     * as fractions of the photo AFTER its own orientation is applied, `r` a clockwise turn in
     * whole quarters (0, 90, 180, 270) applied first. Anything malformed is null, and the
     * server then cuts the centre: a bad crop field must never cost somebody their upload.
     *
     * @return array{x:float,y:float,w:float,h:float,r:int}|null
     */
    public static function parseCrop(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('~^(\d*\.?\d+),(\d*\.?\d+),(\d*\.?\d+),(\d*\.?\d+)(?:,(0|90|180|270))?$~', $raw, $m) !== 1) return null;
        [$x, $y, $w, $h] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
        if ($w <= 0 || $h <= 0 || $x < 0 || $y < 0 || $x + $w > 1.0001 || $y + $h > 1.0001) return null;
        return ['x' => $x, 'y' => $y, 'w' => min($w, 1 - $x), 'h' => min($h, 1 - $y), 'r' => (int) ($m[5] ?? 0)];
    }
}
