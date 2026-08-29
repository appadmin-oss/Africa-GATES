<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Where the mark is, and what it is — asked once, answered here.
 *
 * ── WHY THIS IS A CLASS AND NOT A PATH TYPED INTO TWO TEMPLATES ──────────────
 *
 * `public/assets/img/` holds fourteen files with "logo", "mark" or "brand" in the name,
 * and only one of them is the real lockup: `logo-africa-gates.png`, the Africa outline
 * with "Africa G.A.T.E.S." set inside it, which is what the site's own JSON-LD publishes
 * as the organisation's logo. The rest are a favicon badge, two hand-drawn
 * approximations, and a pair of watermarks.
 *
 * A path typed into a template is a decision nobody can find later. The letter and the
 * invitation email now both carry the mark, and they must carry the SAME one — a letter
 * and the email it arrives with showing two different logos is worse than neither having
 * any.
 *
 * ── THE LOCKUP IS WHITE-BACKED, SO THERE ARE TWO OF THEM ─────────────────────
 *
 * The shipped artwork is green on OPAQUE white. Dropped on any ground that is not white
 * it is a white rectangle with a logo in it — and the email's masthead sits on the paper
 * ground, `#f0f2f2`, which is close enough to white that the box is invisible in a
 * screenshot and obvious in an inbox. {@see LOGO_ON_TINT} is the same artwork with the
 * paper turned to alpha, and {@see logoUrl()} takes a flag rather than leaving every
 * caller to pick: the two files are indistinguishable in a filename and unmistakable on
 * a ground.
 */
final class Brand
{
    /** The lockup, relative to `public/`. The same file the site publishes as its logo. */
    public const LOGO = 'assets/img/logo-africa-gates.png';

    /**
     * The same lockup with the paper turned to alpha, for a ground that is not white.
     *
     * ── DERIVED, NOT INVENTED ────────────────────────────────────────────────
     *
     * The artwork is exactly two colours — #FFFFFF and #006634 — so every pixel in it is
     * a blend of that pair, and the RED channel has the widest spread (255 down to 0),
     * which recovers that blend ratio with the least quantisation error. This file is
     * that ratio as alpha, painted in the same green. Keying out the white instead,
     * which is the obvious thing to reach for, leaves a white halo on every antialiased
     * edge — and this mark is a hairline coastline, so it is almost entirely edge.
     *
     * Regenerate it the same way if the lockup is ever replaced.
     */
    public const LOGO_ON_TINT = 'assets/img/logo-africa-gates-alpha.png';

    /** Width ÷ height of {@see LOGO}, so a caller can size a box without loading it. */
    public const LOGO_RATIO = 640 / 734;

    /**
     * The absolute URL, for an email or a page.
     *
     * @param bool $onTint true for any ground that is not white — see {@see LOGO_ON_TINT}.
     *                     Getting this wrong is not subtle: the opaque artwork on a tint
     *                     is a white card with a logo printed on it.
     */
    public static function logoUrl(string $base = '', bool $onTint = false): string
    {
        $base = rtrim($base !== '' ? $base : (string) SiteUrl::base(), '/');

        return $base . '/' . ($onTint ? self::LOGO_ON_TINT : self::LOGO);
    }

    /** The file on disk, or '' when the deploy is missing it. */
    public static function logoFile(): string
    {
        $path = \dirname(__DIR__, 2) . '/public/' . self::LOGO;

        return is_file($path) ? $path : '';
    }

    /**
     * The lockup as JPEG bytes, for {@see Pdf::image()}.
     *
     * PDF speaks DCTDecode natively and nothing else this codebase draws with, so a PNG
     * has to be transcoded before it can be embedded — see the note on Pdf::image().
     *
     * Flattened onto WHITE rather than trusting the file: this particular lockup happens
     * to ship opaque, but a designer replacing it with a transparent export would
     * otherwise get black wherever the alpha was, on the letterhead of every invitation
     * already in the post.
     *
     * @param int $width the pixel width to encode at — 3× the printed millimetres is
     *                   ~300dpi, and past that the file grows for nothing
     */
    public static function logoJpeg(int $width = 480): ?string
    {
        $file = self::logoFile();
        if ($file === '' || !\function_exists('imagecreatefrompng')) return null;

        try {
            $src = @imagecreatefrompng($file);
            if ($src === false) return null;

            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) { imagedestroy($src); return null; }

            $width  = max(48, min(2000, $width));
            $height = (int) round($width * $sh / $sw);

            $out = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($out, 255, 255, 255);
            imagefilledrectangle($out, 0, 0, $width, $height, $white);
            imagecopyresampled($out, $src, 0, 0, 0, 0, $width, $height, $sw, $sh);
            imagedestroy($src);

            ob_start();
            // 95, not the usual 82–88. This is LINE ART — a hairline coastline and a
            // wordmark on flat white — and JPEG's ringing shows on hard edges against
            // white long before it shows on a photograph. The file is a few kilobytes
            // either way, and it is embedded once per letter.
            imagejpeg($out, null, 95);
            $bytes = (string) ob_get_clean();
            imagedestroy($out);

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            // A letterhead with no mark is a letter. A 500 on the download is not.
            return null;
        }
    }
}
