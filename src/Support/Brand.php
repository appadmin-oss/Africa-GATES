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
 * ── THE LOCKUP IS WHITE-BACKED, NOT TRANSPARENT ──────────────────────────────
 *
 * Which is why it goes on paper and never on the ink band. Reversed-out artwork does not
 * exist in this repository, and inventing one by inverting a PNG produces a grey block
 * with a hole in it. The email's masthead is a white band above its ink hero for exactly
 * that reason — see the template.
 */
final class Brand
{
    /** The lockup, relative to `public/`. The same file the site publishes as its logo. */
    public const LOGO = 'assets/img/logo-africa-gates.png';

    /** Width ÷ height of {@see LOGO}, so a caller can size a box without loading it. */
    public const LOGO_RATIO = 640 / 734;

    /** The absolute URL, for an email or a page. */
    public static function logoUrl(string $base = ''): string
    {
        $base = rtrim($base !== '' ? $base : (string) SiteUrl::base(), '/');

        return $base . '/' . self::LOGO;
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
            imagejpeg($out, null, 88);
            $bytes = (string) ob_get_clean();
            imagedestroy($out);

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            // A letterhead with no mark is a letter. A 500 on the download is not.
            return null;
        }
    }
}
