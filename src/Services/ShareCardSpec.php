<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;

/**
 * The nominee share card, decided once — every size, colour, position and conditional.
 *
 * ── ONE CARD, FOUR SIZES, TWO STYLES, FIVE TONES, TWO GROUNDS ────────────────
 *
 * The share kit used to be one 4:5 graphic in one colour. A nominee posts to a WhatsApp
 * status (9:16), an Instagram feed (4:5 or 1:1) and a link preview (1.91:1), and the
 * awards themselves are different worlds — a choir is not a shop. So the card is a small
 * system, designed on the share-kit canvas (ShareCard.dc.html) and mirrored here:
 *
 *   size   feed 1080×1350 · status 1080×1920 · square 1080×1080 · link 1200×630
 *   style  portrait (the photo leads) · statement (the name and their own line lead)
 *   tone   the site's five cover tones (Accent::COVER) — each award has a default
 *   mode   light (the tone's wash) · bold (the tone's deep ground)
 *
 * The hierarchy is the same in every combination: photo → category → name → the
 * organisation and the standing → one ask (VOTE NOW, the link, the QR code) → the
 * jury line. Nothing is said twice.
 *
 * ── WHY THIS IS A SEPARATE CLASS ─────────────────────────────────────────────
 *
 * {@see ShareCardRenderer} draws it twice, as SVG and through GD, and the two must not
 * drift (FlierService's own history is a list of that bug). So the arithmetic and every
 * suppression rule live here, return plain numbers and strings, and both encodings
 * read them. Nothing here draws or touches the database, so the spec is pinned by
 * ShareCardSpecTest rather than by looking at pictures.
 *
 * ── WHAT VARIES BETWEEN NOMINEES ─────────────────────────────────────────────
 *
 * Only their own facts (D32): name, category, award, organisation, photo, standing,
 * link, and the line on a statement card, which is their own profile tagline or the
 * rally line — never free text from a query string, because these URLs are public and
 * a card that says whatever the URL says is a forgery kit with our logo on it.
 */
final class ShareCardSpec
{
    public const FORMATS = ['feed', 'status', 'square', 'link'];
    public const LAYOUTS = ['portrait', 'statement'];
    public const MODES   = ['light', 'bold'];

    /** The jury line, on every card in every state. */
    public const JURY = 'Public votes are one part of the score. An independent jury decides the award.';

    /** Canvas size per format. */
    public const SIZE = [
        'feed'   => [1080, 1350],
        'status' => [1080, 1920],
        'square' => [1080, 1080],
        'link'   => [1200, 630],
    ];

    /**
     * Each tone's light wash, line and ink are the site's cover tones (Accent::coverHex);
     * the deep ground and the label colour on it are the card's own, chosen so white type
     * and the label both clear 4.5:1 on the deep ground.
     */
    private const DEEP = [
        'gold'  => ['deep' => '#10292c', 'label' => '#f3c94c'],
        'green' => ['deep' => '#0f3d22', 'label' => '#9ad897'],
        'info'  => ['deep' => '#0f2f45', 'label' => '#a6cde6'],
        'live'  => ['deep' => '#3d0c1e', 'label' => '#f6a9c1'],
        'stone' => ['deep' => '#10292c', 'label' => '#e8e5dd'],
    ];

    private const INK   = '#10292c';
    private const MUTED = '#4e5a5d';
    private const WHITE = '#ffffff';
    /** The video's photo scrim: the house ink, darkened, so white type holds over any photo. */
    private const SCRIM = '#081416';

    /**
     * Geometry per format, in CSS px of the canvas. Field names match the DC's `D` table
     * so the two can be read side by side.
     */
    private const D = [
        'feed'   => ['pad' => 72, 'lockTop' => 56, 'photoH' => 800, 'scrimH' => 220, 'logo' => 56, 'award' => 17,
                     'rankNum' => 28, 'rankOf' => 18, 'avatar' => 260, 'avatarText' => 100, 'avatarNudge' => 40,
                     'stmtTop' => 120, 'stmtAvatar' => 220, 'stmtAvatarText' => 84, 'bodyTop' => 52, 'padBottom' => 60,
                     'eyebrow' => 19, 'nameGap' => 14, 'name' => 84, 'metaGap' => 18, 'meta' => 24, 'rallyGap' => 40,
                     'rally' => 50, 'footTop' => 28, 'footGap' => 6, 'footLabel' => 17, 'url' => 34, 'jury' => 15, 'qr' => 136],
        'status' => ['pad' => 80, 'lockTop' => 190, 'photoH' => 1150, 'scrimH' => 400, 'logo' => 64, 'award' => 19,
                     'rankNum' => 32, 'rankOf' => 20, 'avatar' => 320, 'avatarText' => 124, 'avatarNudge' => 150,
                     'stmtTop' => 240, 'stmtAvatar' => 300, 'stmtAvatarText' => 112, 'bodyTop' => 64, 'padBottom' => 230,
                     'eyebrow' => 22, 'nameGap' => 16, 'name' => 100, 'metaGap' => 20, 'meta' => 28, 'rallyGap' => 48,
                     'rally' => 60, 'footTop' => 34, 'footGap' => 8, 'footLabel' => 19, 'url' => 38, 'jury' => 17, 'qr' => 152],
        'square' => ['pad' => 60, 'splitW' => 480, 'logo' => 52, 'award' => 15, 'rankNum' => 26, 'rankOf' => 16,
                     'avatar' => 220, 'avatarText' => 84, 'eyebrow' => 17, 'nameGap' => 12, 'name' => 76, 'metaGap' => 14,
                     'meta' => 22, 'footTop' => 24, 'footGap' => 6, 'footLabel' => 15, 'url' => 20, 'jury' => 13, 'qr' => 128,
                     'splitQr' => true],
        // The photo column is the og_photo preset's width, so Cloudinary crops it once.
        'link'   => ['pad' => 48, 'splitW' => FlierLayout::OG_PHOTO_W, 'logo' => 42, 'award' => 13, 'rankNum' => 22, 'rankOf' => 14,
                     'avatar' => 180, 'avatarText' => 68, 'eyebrow' => 14, 'nameGap' => 10, 'name' => 56, 'metaGap' => 10,
                     'meta' => 18, 'footTop' => 18, 'footGap' => 4, 'footLabel' => 12, 'url' => 19, 'jury' => 12, 'qr' => 0,
                     'splitQr' => false],
    ];

    /**
     * The award's default tone. Business is gold, leadership and community green,
     * anything sung rose, education blue; everything else takes the cover tone
     * the site already gives the programme, so a programme added tomorrow still gets a
     * colour without anyone editing this list.
     */
    public static function defaultTone(string $programme): string
    {
        $p = mb_strtolower($programme);
        return match (true) {
            (bool) preg_match('/\b(choral|choir|music|sing|voices?)\b/u', $p)        => 'live',
            (bool) preg_match('/\b(business|enterprise|entrepreneur|trade)\w*/u', $p) => 'gold',
            (bool) preg_match('/\b(leader|leadership|impact|community)\w*/u', $p)     => 'green',
            (bool) preg_match('/\b(teacher|principal|school|education|child)\w*/u', $p) => 'info',
            default                                                                    => 'green',
        };
    }

    /**
     * Read the options a share-kit link carries, keeping only known values.
     *
     * @param array<string,mixed> $q a query array
     * @return array{format:string,layout:string,tone:?string,mode:string}
     */
    public static function options(array $q): array
    {
        $pick = static fn (string $k, array $allowed, ?string $default) =>
            is_string($q[$k] ?? null) && in_array($q[$k], $allowed, true) ? $q[$k] : $default;

        return [
            'format' => $pick('size', self::FORMATS, 'feed'),
            'layout' => $pick('style', self::LAYOUTS, 'portrait'),
            'tone'   => $pick('tone', Accent::coverTones(), null),
            'mode'   => $pick('mode', self::MODES, 'light'),
        ];
    }

    /**
     * The whole card for one nominee and one set of options.
     *
     * @param array<string,mixed> $f   the flier facts, from FlierService::forNominee()
     * @param array<string,mixed> $opt from options()
     * @return array<string,mixed>
     */
    public static function for(array $f, array $opt = []): array
    {
        $format = in_array($opt['format'] ?? null, self::FORMATS, true) ? $opt['format'] : 'feed';
        $layout = in_array($opt['layout'] ?? null, self::LAYOUTS, true) ? $opt['layout'] : 'portrait';
        $mode   = in_array($opt['mode'] ?? null, self::MODES, true) ? $opt['mode'] : 'light';
        $tone   = in_array($opt['tone'] ?? null, Accent::coverTones(), true)
            ? $opt['tone'] : self::defaultTone((string) ($f['programme'] ?? ''));

        [$W, $H]  = self::SIZE[$format];
        $d        = self::D[$format];
        $stacked  = $format === 'feed' || $format === 'status';
        $portrait = $layout === 'portrait';
        $bold     = $mode === 'bold';

        $name = trim((string) ($f['name'] ?? ''));
        $photo = $format === 'link' || $format === 'square'
            ? ($f['photo_card'] ?? $f['photo'] ?? null)
            : ($f['photo'] ?? null);
        $photo = is_string($photo) && $photo !== '' ? $photo : null;

        $standing = (array) ($f['standing'] ?? []);
        $rank  = (int) ($standing['rank'] ?? 0);
        $field = (int) ($standing['field'] ?? 0);
        $gap   = (int) ($standing['gap_ahead'] ?? 0);
        $next  = (int) ($standing['next_rank'] ?? max(1, $rank - 1));
        $showRank = $field >= 2 && $rank >= 1;
        $standingText = !$showRank ? ''
            : ($rank === 1 ? 'Leading the field'
                : ($gap > 0 ? $gap . ' vote' . ($gap === 1 ? '' : 's') . ' from #' . max(1, $next) : ''));

        $org  = FlierLayout::clip(trim((string) ($f['organisation'] ?? '')), 48);
        $line = trim((string) ($f['tagline'] ?? '')) !== '' ? (string) $f['tagline'] : (string) ($f['rally'] ?? '');
        $line = FlierLayout::clip(trim(preg_replace('/\s+/u', ' ', $line) ?? ''), 120);

        $scale = self::nameScale($name);
        if ($stacked && !$portrait) $scale *= 1.18;

        return [
            'format' => $format, 'layout' => $layout, 'tone' => $tone, 'mode' => $mode,
            'W' => $W, 'H' => $H, 'd' => $d,
            'stacked' => $stacked, 'split' => !$stacked, 'portrait' => $portrait, 'statement' => !$portrait,
            'colours' => self::colours($tone, $bold),
            'name' => $name, 'nameSize' => (int) round($d['name'] * $scale), 'initials' => FlierLayout::monogram($name),
            'category' => (string) ($f['category'] ?? ''),
            'award'    => (string) ($f['programme'] ?? ''),
            'org' => $org, 'photo' => $photo,
            'rank' => $rank, 'field' => $field, 'showRank' => $showRank, 'standing' => $standingText,
            'line' => $portrait ? '' : $line,
            'url'  => (string) ($f['short_url'] ?? $f['url'] ?? ''),
            'link' => (string) ($f['url'] ?? ''),
            'jury' => self::JURY,
            'headline' => (string) ($f['headline'] ?? ''),
        ];
    }

    /**
     * One tone's colours for the share VIDEO (share-video-award.js), handed to the page in
     * its story so the script types no palette of its own. The handoff's script carried a
     * private hex table of the five tones, and it had already drifted — its stone edge was
     * a hairline grey the palette does not use — which is the two-sources fault Accent
     * exists to end. Same values as the card: Accent's cover tones, and this class's deep
     * ground and label.
     *
     * @return array<string,string>
     */
    public static function videoPalette(string $tone): array
    {
        if (!in_array($tone, Accent::coverTones(), true)) $tone = 'green';
        $out = [];
        foreach (['bg', 'line', 'ink', 'edge'] as $slot) $out[$slot] = Accent::coverHex($tone, $slot);
        return $out + self::DEEP[$tone] + [
            'text' => self::INK, 'muted' => self::MUTED, 'white' => self::WHITE,
            'sub' => Accent::coverHex('stone', 'line'), 'scrim' => self::SCRIM,
        ];
    }

    /** An opaque blend, so a disc over the rings hides them rather than showing through. */
    private static function mix(string $a, string $b, float $p): string
    {
        [$r1, $g1, $b1] = FlierLayout::rgb($a);
        [$r2, $g2, $b2] = FlierLayout::rgb($b);
        return sprintf('#%02x%02x%02x', (int) round($r1 + ($r2 - $r1) * $p), (int) round($g1 + ($g2 - $g1) * $p), (int) round($b1 + ($b2 - $b1) * $p));
    }

    /** The name steps down as it lengthens, measured without combining marks. */
    public static function nameScale(string $name): float
    {
        $bare = mb_strlen(FlierLayout::stripMarks($name));
        return match (true) {
            $bare <= 14 => 1.0,
            $bare <= 20 => 0.88,
            $bare <= 28 => 0.76,
            $bare <= 36 => 0.66,
            default     => 0.58,
        };
    }

    /**
     * Every colour a card uses, by role, as six-digit hex. Alpha roles are given as
     * [hex, alpha] so SVG and GD can each express them their own way.
     *
     * @return array<string,string|array{0:string,1:float}>
     */
    public static function colours(string $tone, bool $bold): array
    {
        $bg   = Accent::coverHex($tone, 'bg');
        $line = Accent::coverHex($tone, 'line');
        $ink  = Accent::coverHex($tone, 'ink');
        $edge = Accent::coverHex($tone, 'edge');
        $deep = self::DEEP[$tone]['deep'];
        $lab  = self::DEEP[$tone]['label'];

        return $bold ? [
            'ground' => $deep, 'text' => self::WHITE, 'eyebrow' => $lab, 'muted' => [self::WHITE, 0.68],
            'frame' => [self::WHITE, 0.06], 'avatarBg' => self::mix($deep, self::WHITE, 0.12), 'avatarText' => $lab,
            'rule' => [self::WHITE, 0.22], 'chipBg' => self::WHITE, 'chipText' => self::INK, 'pattern' => [$lab, 0.22],
        ] : [
            'ground' => $bg, 'text' => self::INK, 'eyebrow' => $ink, 'muted' => self::MUTED,
            'frame' => $edge, 'avatarBg' => self::WHITE, 'avatarText' => $ink,
            'rule' => [self::INK, 0.16], 'chipBg' => self::INK, 'chipText' => self::WHITE, 'pattern' => [$line, 0.28],
        ];
    }
}
