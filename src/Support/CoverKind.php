<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * What an image slot with no upload draws: its label, tone and pattern.
 *
 * DEFAULT-GRAPHICS §4 (handoff 5 Oct 2026). Every image slot with no uploaded image renders
 * `partials/cover.twig` — never a stock photo, a grey box or a broken `<img>` — and this is
 * the ONE resolver behind it: the Twig partial asks it through the `cover_kind()` function
 * and the GD share-image renderer (Services\CoverImage) calls it directly, so the card on
 * the page and the image a link unfurls into cannot come to disagree about what an event is.
 *
 * Fixed, and never organiser-editable: an organiser picks the KIND from a list in the admin
 * (`gates_site_events.cover_kind`); they never pick a colour. The tone is a name from
 * `Accent::coverTones()`, never a hex.
 */
final class CoverKind
{
    /** Event kinds: label, tone, pattern. The order is the admin picker's order. */
    public const KINDS = [
        'ceremony'   => ['Awards ceremony', 'gold',  'arches'],
        'gala'       => ['Gala',            'gold',  'arches'],
        'conference' => ['Conference',      'info',  'dots'],
        'workshop'   => ['Workshop',        'green', 'grid'],
        'training'   => ['Training',        'green', 'grid'],
        'webinar'    => ['Webinar',         'info',  'signal'],
        'livestream' => ['Livestream',      'live',  'signal'],
        'community'  => ['Community',       'green', 'rings'],
        'concert'    => ['Concert',         'live',  'weave'],
        'sports'     => ['Sports',          'green', 'stripes'],
        'fundraiser' => ['Fundraiser',      'live',  'stripes'],
        'exhibition' => ['Exhibition',      'stone', 'weave'],
    ];

    /** Every other subject that can have an image slot. */
    public const SUBJECTS = [
        'award'     => ['Award',     'gold',  'arches'],
        'campaign'  => ['Giving',    'live',  'stripes'],
        'blog'      => ['Story',     'stone', 'dots'],
        'challenge' => ['Challenge', 'green', 'games'],
        'product'   => ['Shop',      'stone', 'weave'],
    ];

    public const PATTERNS = ['arches', 'dots', 'grid', 'signal', 'rings', 'weave', 'stripes', 'games'];

    /** The content modes a slot may ask for (§5). */
    public const CONTENT = ['auto', 'none', 'date', 'title'];

    /**
     * The kind a NULL column resolves to (§4): an award ceremony for an event linked to an
     * award, a community event otherwise. Stored NULL rather than defaulted in the schema,
     * so "nobody chose" stays distinguishable from "somebody chose community".
     */
    public static function eventKind(?string $stored, bool $awardLinked = false): string
    {
        $k = strtolower(trim((string) $stored));
        if (isset(self::KINDS[$k])) return $k;

        return $awardLinked ? 'ceremony' : 'community';
    }

    /** True when `$kind` is a kind an organiser may store. The column is VARCHAR, so PHP is the guard. */
    public static function isKind(?string $kind): bool
    {
        return isset(self::KINDS[strtolower(trim((string) $kind))]);
    }

    /**
     * Everything a cover needs, for a subject (and, for an event, its kind).
     *
     * @return array{subject:string, kind:string, label:string, tone:string, pattern:string, glyph:string}
     */
    public static function resolve(string $subject, ?string $kind = null, bool $awardLinked = false, ?string $tone = null): array
    {
        $subject = strtolower($subject);
        if ($subject === 'event') {
            $k = self::eventKind($kind, $awardLinked);
            [$label, $own, $pattern] = self::KINDS[$k];
            // One override, and only to another of the five tones: an event that is live
            // NOW is drawn in `live` whatever its kind (EVENTS-INDEX, cards). Never a colour.
            $tone = $tone !== null && in_array($tone, Accent::coverTones(), true) ? $tone : $own;

            return ['subject' => 'event', 'kind' => $k, 'label' => $label, 'tone' => $tone,
                    'pattern' => $pattern, 'glyph' => $k];
        }
        if (!isset(self::SUBJECTS[$subject])) {
            // A typo in a template is a bug to see, not a slot that silently draws an award.
            throw new \InvalidArgumentException(sprintf('No cover subject "%s". Subjects: event, %s',
                $subject, implode(', ', array_keys(self::SUBJECTS))));
        }
        [$label, $tone, $pattern] = self::SUBJECTS[$subject];

        return ['subject' => $subject, 'kind' => $subject, 'label' => $label, 'tone' => $tone,
                'pattern' => $pattern, 'glyph' => $subject];
    }

    /**
     * The content a slot shows (§5): `auto` is the date when there is one, otherwise none.
     * There is never a date tile without a date — no "TBC".
     */
    public static function content(string $want, bool $hasDate): string
    {
        $want = in_array($want, self::CONTENT, true) ? $want : 'auto';
        if ($want === 'auto') return $hasDate ? 'date' : 'none';
        if ($want === 'date' && !$hasDate) return 'none';

        return $want;
    }

    /**
     * The `full` variant's title size, in cqmin, by length and shape (the DC's `tk`). The
     * GD renderer turns it into points against the image's shorter side.
     */
    public static function titleScale(string $title, string $shape, bool $hasDate): float
    {
        $len = mb_strlen($title);
        if ($shape === 'banner') return $len <= 36 ? 17.0 : ($len <= 64 ? 13.5 : 11.0);
        if ($shape === 'tall')   return $len <= 36 ? 12.5 : ($len <= 64 ? 10.4 : 8.6);
        if ($len <= 36) return $hasDate ? 10.5 : 13.0;
        if ($len <= 64) return $hasDate ? 8.4 : 10.2;

        return $hasDate ? 6.8 : 8.2;
    }

    /**
     * The challenge cover's `games` tile (§7a): five tiny icons — star, dice, target, bolt,
     * controller — at 42% opacity in the tone's line colour, a 96-unit tile the CSS repeats
     * at 30cqmin. The DC's own markup, verbatim.
     *
     * Served from `/img/patterns/games-{tone}.svg` (CSP `img-src 'self'`, never a data URI)
     * and built HERE rather than shipped as five static files: a static SVG would be a sixth
     * place a colour is typed, and the first palette change would leave the pattern on the
     * old tone while the cover around it moved.
     */
    public static function gamesSvg(string $tone): string
    {
        $c = Accent::coverHex($tone, 'line');

        return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96' fill='none' stroke='{$c}' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round' opacity='.42'>"
            . "<g transform='translate(6 8) scale(.6)'><path d='M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.6 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z'/></g>"
            . "<g transform='translate(60 4) rotate(14 12 12) scale(.6)'><rect x='4' y='4' width='16' height='16' rx='3.5'/><path d='M9 9h.01M15 15h.01M15 9h.01M9 15h.01M12 12h.01'/></g>"
            . "<g transform='translate(34 40) scale(.6)'><circle cx='12' cy='12' r='8'/><circle cx='12' cy='12' r='3.5'/></g>"
            . "<g transform='translate(70 58) rotate(-12 12 12) scale(.6)'><path d='M13 2 4 14h7l-1 8 9-12h-7z'/></g>"
            . "<g transform='translate(4 62) rotate(-8 12 12) scale(.62)'><path d='M6 9h12a4 4 0 0 1 4 4v1a3 3 0 0 1-5.3 1.9L15 14H9l-1.7 1.9A3 3 0 0 1 2 14v-1a4 4 0 0 1 4-4ZM7 11v3M5.5 12.5h3M16 12h.01M18 13.5h.01'/></g>"
            . '</svg>';
    }

    /** The on-page cover's title size, in cqmin (the DC's `gTitleK`). */
    public static function graphicTitleScale(string $title): float
    {
        $len = mb_strlen($title);

        return $len <= 28 ? 11.0 : ($len <= 56 ? 9.0 : 7.6);
    }
}
