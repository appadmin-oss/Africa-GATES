<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/**
 * The house letterhead and the faces a letter is set in, for every formal letter.
 *
 * ── WHY ONE ──────────────────────────────────────────────────────────────────
 *
 * The invitation ({@see \AfricaGates\Services\InviteLetter}) was the only letter, so its
 * letterhead lived inside it. The nominee's voting letter
 * ({@see \AfricaGates\Services\NomineeVotingLetter}) is the second, and two copies of a
 * letterhead drift the way two copies of anything here drift: one gets the new strapline
 * and the other is the one a head teacher frames. The reasoning for each choice below
 * (the measured lockup, the two rules, DM Sans with DejaVu behind it) was written for the
 * invitation and still applies; it moved here with the code.
 */
final class Letterhead
{
    public const PAGE_W = 210.0;
    public const PAGE_H = 297.0;
    public const MARGIN = 22.0;

    public const INK  = [0x10, 0x29, 0x2C];
    public const MUTE = [0x5D, 0x72, 0x75];
    public const GOLD = [0xF3, 0xB4, 0x16];
    public const RULE = [0xCD, 0xD6, 0xD6];

    /**
     * The printed width of the lockup; its height follows from the artwork's own ratio.
     * Below about 26mm the wordmark inside the outline of Africa reads as a smudge.
     */
    public const LOGO_W = 26.0;

    /**
     * A4 with the letter faces registered.
     *
     * DM Sans is the site's body face; it has no Ọ, ẹ, ṣ or ₦, so every DM Sans face is
     * registered with a DejaVu face behind it and Pdf::font() falls through per character
     * — a Yoruba name sets whole rather than with boxes through it. `fb` is registered
     * first so it exists to be named as a fallback. Playfair has no ₦ either, hence its
     * fallback.
     */
    public static function pdf(): Pdf
    {
        $pdf = new Pdf(self::PAGE_W, self::PAGE_H);
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';

        $pdf->font('fb', $dir . 'AGText-Regular.ttf');
        $pdf->font('fbb', $dir . 'AGText-Bold.ttf');
        $pdf->font('text', $dir . 'DMSans-Regular.ttf', 'fb');
        $pdf->font('semi', $dir . 'DMSans-SemiBold.ttf', 'fbb');
        $pdf->font('bold', $dir . 'DMSans-Bold.ttf', 'fbb');
        $pdf->font('mono', $dir . 'AGMono-Bold.ttf');
        $pdf->font('display', $dir . 'PlayfairDisplay-Bold.ttf', 'fbb');

        return $pdf;
    }

    /**
     * Draw the letterhead at the top margin and return the y below its rules.
     *
     * The mark, then the words set on the mark's own top line (centring an 11mm block on a
     * 30mm mark put the words at a position that matched nothing else on the page), the
     * host closing the same line on the right, and two rules 1.4mm apart, the gold one
     * thin: the one piece of ornament in the document. When the deploy is missing the logo
     * file the name is set in its place, because a letterhead that silently loses its name
     * is worse than one that never had a picture.
     */
    public static function draw(Pdf $pdf): float
    {
        $x = self::MARGIN;
        $w = self::PAGE_W - self::MARGIN * 2;
        $y = self::MARGIN;

        // Sized from the artwork's OWN ratio: Pdf::image() covers and clips its box, which
        // for a logo in a box of the wrong shape crops the outline rather than fitting it.
        $logoH = 0.0;
        $logo  = Brand::logoJpeg((int) round(self::LOGO_W * 3));   // ~300dpi
        if ($logo !== null) {
            $logoH = self::LOGO_W / Brand::LOGO_RATIO;
            $pdf->image($logo, $x, $y, self::LOGO_W, $logoH, 0.5);
        }

        $tx = $logo !== null ? $x + self::LOGO_W + 8.0 : $x;
        $ty = $y + 6.4;
        if ($logo === null) {
            $pdf->text('AFRICA GATES', $tx, $ty, 'mono', 9.5, self::INK, 2.2);
            $ty += 5.6;
        }
        $pdf->text('Continental Cultural Recognition', $tx, $ty, 'semi', 9.5, self::INK);
        $pdf->text('An Afrovanguard Initiative', $tx, $ty + 5.2, 'text', 8.5, self::MUTE);

        $host = self::host();
        $pdf->text($host, $x + $w - $pdf->width($host, 'text', 8.5), $ty, 'text', 8.5, self::MUTE);

        $y += max(17.0, $logoH + 5.0);
        $pdf->line($x, $y, $x + $w, $y, self::INK, 0.5);
        $pdf->line($x, $y + 1.4, $x + $w, $y + 1.4, self::GOLD, 0.5);

        return $y;
    }

    /** The site's own hostname — one resolver, not a literal. */
    public static function host(): string
    {
        $h = parse_url(SiteUrl::base(), PHP_URL_HOST);

        return is_string($h) && $h !== '' ? $h : 'africagates.org';
    }
}
