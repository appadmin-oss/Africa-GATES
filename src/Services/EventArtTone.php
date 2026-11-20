<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Contrast;

/**
 * THE PANEL TAKES THE ARTWORK'S COLOUR (owner, 17 Nov 2026, the Alimosho Carol Concert: "it
 * feels off. The colours do not match", then, of a neutral panel: "No! The colour must match
 * the art").
 *
 * The events spotlight paints its text side in the event TYPE's colour — gold for an awards
 * ceremony — which knows nothing about the picture the organiser uploaded, so a gold panel
 * sat beside green-and-red artwork. This reads the artwork itself and returns the slots the
 * slide is painted with, the panel BEING the artwork's dominant colour (see slots()), with
 * every word on it measured: nothing here can produce text below 4.5:1.
 *
 * THE DOMINANT COLOUR IS THE MOST COLOURFUL HUE, NOT THE AVERAGE. An average of green and red
 * is mud; a poster's white card is not its colour. So the image is shrunk to 32×32, every
 * pixel is weighted by its chroma (grey, white and black weigh nothing), hues are binned in
 * 15° steps, and the heaviest bin wins. An artwork with almost no colour anywhere (a black-
 * and-white photograph) has no dominant hue and returns null: the caller falls back to the
 * neutral panel rather than inventing a tint.
 *
 * Read once per image and cached for a week, keyed by the image address; a failure is cached
 * too, so a missing or unreachable file costs one attempt, not one per page view. Local files
 * are read from disk (no request to ourselves); a remote image is fetched over https only,
 * with a short timeout and a size cap, because this runs while a page is being drawn.
 *
 * The hexes are derived and validated here, so a template may write them into a style
 * attribute raw — the same contract as EventTierTone::card().
 */
final class EventArtTone
{
    public const TTL = 604800;       // a week
    private const SIDE = 32;
    private const BINS = 24;         // 15° each
    private const MIN_CHROMA = 0.06; // below this share of colourful weight, the art is neutral
    private const MAX_BYTES = 6_000_000;
    private const FETCH_SECONDS = 3;

    /** The slide's four slots from an artwork address, or null to use the neutral panel. */
    public static function of(string $image): ?array
    {
        $image = trim($image);
        if ($image === '') return null;
        $calc = static function () use ($image): array {
            $im = self::load($image);
            if ($im === null) return ['none' => true];
            $seed = self::dominant($im);
            imagedestroy($im);
            return $seed === null ? ['none' => true] : self::slots($seed);
        };
        try {
            $r = (array) (new CacheService())->remember('event-art-tone:v2:' . sha1($image), self::TTL, $calc, ['events']);
        } catch (\Throwable) {
            $r = $calc();
        }
        return isset($r['wash']) ? $r : null;
    }

    /** Whether the panel is dark (white text) or light (dark text); '' for none. */
    public static function mode(?array $t): string
    {
        return in_array($t['mode'] ?? '', ['dark', 'light'], true) ? (string) $t['mode'] : '';
    }

    /** The CSS custom properties for a slide, or '' — validated hexes only. */
    public static function style(?array $t): string
    {
        if ($t === null) return '';
        $out = [];
        foreach (['wash', 'ink', 'dot', 'edge'] as $k) {
            $v = (string) ($t[$k] ?? '');
            if (!preg_match('/^#[0-9A-F]{6}$/i', $v)) return '';
            $out[] = '--ei-' . $k . ':' . strtoupper($v);
        }
        return implode(';', $out);
    }

    /**
     * The slots from a seed colour. Public so the derivation can be measured on its own.
     *
     * THE PANEL IS THE ARTWORK'S OWN COLOUR (owner, of a pale tint beside a deep poster:
     * "this does not really match"). A light tint of a deep green is a different colour from
     * the poster, and it met the picture in a hard seam. So the panel is the seed itself, and
     * the TEXT changes to suit it: white on a dark colour (`dark`), the house ink on a light
     * one (`light`). The seed's lightness is moved only when neither can be read on it — a
     * mid-tone — and only as far as it takes: towards white text if it was darker than middle,
     * towards dark text if lighter.
     *
     *   wash  the panel: the artwork's colour
     *   ink   the date line: a tint (dark) or shade (light) of it, reached to 4.5:1 on the panel
     *   dot   the type's dot, reached to 3:1 on the panel
     *   edge  a hairline a little off the panel
     *
     * @return array{wash:string,ink:string,dot:string,edge:string,mode:string}
     */
    public static function slots(string $seed): array
    {
        [$h, $s, $l] = EventTierPalette::toHsl($seed);
        $white = '#FFFFFF';
        $body  = \AfricaGates\Support\Accent::hex('ink-2');   // the meta lines on a light panel
        $bg = strtoupper(EventTierPalette::fromHsl($h, $s, $l));
        $okDark  = static fn (string $c): bool => Contrast::ratio($white, $c) >= Contrast::TEXT;
        $okLight = static fn (string $c): bool => Contrast::ratio($body, $c) >= Contrast::TEXT;
        if (!$okDark($bg) && !$okLight($bg)) {
            $step = $l < 0.5 ? -0.01 : 0.01;
            for ($cl = $l; $cl >= 0.0 && $cl <= 1.0; $cl += $step) {
                $try = strtoupper(EventTierPalette::fromHsl($h, $s, $cl));
                if ($okDark($try) || $okLight($try)) { $bg = $try; break; }
            }
        }
        $dark = $okDark($bg) && (!$okLight($bg) || Contrast::ratio($white, $bg) >= Contrast::ratio($body, $bg));
        [, , $bl] = EventTierPalette::toHsl($bg);
        $sat = min(0.9, max(0.3, $s));
        $ink  = Contrast::reach(EventTierPalette::fromHsl($h, $sat, $dark ? 0.86 : 0.28), [$bg], Contrast::TEXT);
        $dot  = Contrast::reach(EventTierPalette::fromHsl($h, $sat, $dark ? 0.72 : 0.40), [$bg], 3.0);
        $edge = EventTierPalette::fromHsl($h, $s, $dark ? min(1.0, $bl + 0.18) : max(0.0, $bl - 0.14));
        return ['wash' => $bg, 'ink' => strtoupper($ink), 'dot' => strtoupper($dot), 'edge' => strtoupper($edge), 'mode' => $dark ? 'dark' : 'light'];
    }

    /** The most colourful hue in an image, as a hex, or null for an image with no colour to speak of. */
    public static function dominant(\GdImage $src): ?string
    {
        $n = self::SIDE;
        $im = imagecreatetruecolor($n, $n);
        imagecopyresampled($im, $src, 0, 0, 0, 0, $n, $n, imagesx($src), imagesy($src));
        $bins = array_fill(0, self::BINS, ['w' => 0.0, 'x' => 0.0, 'y' => 0.0, 's' => 0.0, 'l' => 0.0]);
        $total = 0.0;
        $colour = 0.0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = imagecolorat($im, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                if ($alpha > 100) continue;               // transparent: not part of the art
                $hex = sprintf('#%02X%02X%02X', ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
                [$h, $s, $l] = EventTierPalette::toHsl($hex);
                $chroma = $s * (1 - abs(2 * $l - 1));      // 0 for grey, white and black
                $total += 1;
                if ($chroma < 0.08) continue;
                $colour += $chroma;
                $b = (int) floor(EventTierPalette::hue($h) / (360 / self::BINS)) % self::BINS;
                $bins[$b]['w'] += $chroma;
                $bins[$b]['x'] += $chroma * cos(deg2rad($h));
                $bins[$b]['y'] += $chroma * sin(deg2rad($h));
                $bins[$b]['s'] += $chroma * $s;
                $bins[$b]['l'] += $chroma * $l;
            }
        }
        imagedestroy($im);
        if ($total < 1 || $colour / $total < self::MIN_CHROMA) return null;
        // The heaviest bin, together with its neighbours, so a hue on a bin edge is not split.
        $best = 0; $bestW = -1.0;
        for ($i = 0; $i < self::BINS; $i++) {
            $w = $bins[$i]['w'] + 0.5 * ($bins[($i + self::BINS - 1) % self::BINS]['w'] + $bins[($i + 1) % self::BINS]['w']);
            if ($w > $bestW) { $bestW = $w; $best = $i; }
        }
        $acc = ['w' => 0.0, 'x' => 0.0, 'y' => 0.0, 's' => 0.0, 'l' => 0.0];
        foreach ([$best - 1, $best, $best + 1] as $j) {
            $bn = $bins[($j + self::BINS) % self::BINS];
            foreach ($acc as $k => $_) $acc[$k] += $bn[$k];
        }
        if ($acc['w'] <= 0) return null;
        $hue = EventTierPalette::hue(rad2deg(atan2($acc['y'], $acc['x'])));
        return EventTierPalette::fromHsl($hue, $acc['s'] / $acc['w'], $acc['l'] / $acc['w']);
    }

    /** The artwork, from this disk when it is ours, else over https with a timeout and a cap. */
    private static function load(string $image): ?\GdImage
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $path = parse_url($image, PHP_URL_PATH);
        $host = parse_url($image, PHP_URL_HOST);
        if (is_string($path) && str_starts_with($path, '/') && !str_contains($path, '..') && ($host === null || $host === '')) {
            $file = dirname(__DIR__, 2) . '/public' . rawurldecode($path);
            if (!is_file($file) || filesize($file) > self::MAX_BYTES) return null;
            $im = @imagecreatefromstring((string) @file_get_contents($file));
            return $im instanceof \GdImage ? $im : null;
        }
        if (!preg_match('#^https://#i', $image)) return null;
        $ctx = stream_context_create(['http' => ['timeout' => self::FETCH_SECONDS, 'follow_location' => 1, 'max_redirects' => 3,
            'header' => "Accept: image/*\r\n"]]);
        $data = @file_get_contents($image, false, $ctx, 0, self::MAX_BYTES + 1);
        if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BYTES) return null;
        $im = @imagecreatefromstring($data);
        return $im instanceof \GdImage ? $im : null;
    }
}
