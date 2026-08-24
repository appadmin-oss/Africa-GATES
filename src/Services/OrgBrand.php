<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * An organisation's own look, on their own donation page.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A partner's appeal page used to carry their NAME and nothing else that was theirs: same
 * typeface, same colour, same platform copy around the form, no logo anywhere on it. A
 * supporter following a link from that organisation's own WhatsApp group arrived somewhere
 * that looked like a platform rather than like the people asking them for money — which is
 * the one thing a donation page has to get right, because giving is an act of trust in a
 * specific organisation and not in whoever is processing the card.
 *
 * We provide the donation service: the checkout, the settlement straight into their own
 * account, the receipt, the refund path, the audit trail. What the page LOOKS like belongs
 * to whoever is doing the asking.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT AN ORGANISATION CAN AND CANNOT CHANGE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They choose: a logo, one accent colour, a tagline, a longer story, a website and the
 * sections that appear on their page.
 *
 * They cannot change: the amount field, the fee disclosure, the settlement statement, the
 * refund policy, or the line naming who receives the money. Those are not decoration. A
 * page where the organisation could edit "your donation settles directly to us" is a page
 * where that sentence stops being evidence of anything.
 *
 * ── AND THE ACCENT IS VALIDATED, NOT JUST STORED ─────────────────────────────
 *
 * {@see contrast()} refuses an accent that cannot be read against white. This is the one
 * place a taste question becomes a correctness question: the accent is the colour of the
 * donate button's label and of every link on the page, so #f2f2c0 is not a bold choice, it
 * is a button whose text has vanished. WCAG 2.2 AA wants 4.5:1 for body text and the same
 * ratio governs white-on-accent, so one check covers both uses.
 *
 * An organisation that picks an unreadable colour is TOLD, with the ratio and the
 * threshold, rather than having their choice silently discarded — a setting that appears to
 * save and then does not is worse than a refusal.
 */
final class OrgBrand
{
    /** The house accent, used when an organisation has not chosen one. */
    public const DEFAULT_ACCENT = '#237b22';

    /** WCAG 2.2 AA for normal text, and the floor for white-on-accent buttons. */
    public const MIN_CONTRAST = 4.5;

    public const MAX_TAGLINE = 140;
    public const MAX_STORY   = 4000;

    /**
     * The sections an organisation can switch on and off.
     *
     * Not "plugins" in the sense of third-party code — nothing here executes anything an
     * organisation supplies, and it never will: a donation page that runs somebody else's
     * script is a donation page that can have its amount field rewritten by whoever
     * compromises them. These are OUR blocks, shown or hidden.
     */
    public const SECTIONS = [
        'story'     => 'Your story — a longer description above the form',
        'campaigns' => 'Your open appeals, with their progress',
        'totals'    => 'What has been raised through this page so far',
        'contact'   => 'How to reach you — website and contact email',
        'gallery'   => 'Photographs of the work',
    ];

    /** On by default. A new organisation gets a page that already says something. */
    private const SECTIONS_DEFAULT = ['story' => true, 'campaigns' => true, 'totals' => true,
                                      'contact' => true, 'gallery' => false];

    // ═══════════════════════════════════════════════════════════════════════
    // READING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The brand for one organisation, always complete.
     *
     * Never returns a partial array. Every caller is a template, and a template deciding
     * what an absent accent means is how two pages end up disagreeing about the same
     * organisation's colour.
     *
     * @return array{accent:string, logo:string, tagline:string, story:string,
     *               website:string, gallery:list<string>, sections:array<string,bool>,
     *               is_default:bool}
     */
    public static function of(?object $org): array
    {
        $raw = [];
        if ($org !== null) {
            $j = json_decode((string) ($org->brand_json ?? ''), true);
            if (is_array($j)) $raw = $j;
        }

        $accent = self::normaliseHex((string) ($raw['accent'] ?? ''));
        if ($accent === '' || !self::readable($accent)) $accent = self::DEFAULT_ACCENT;

        $gallery = [];
        foreach ((array) ($raw['gallery'] ?? []) as $g) {
            $g = trim((string) $g);
            if ($g !== '' && self::safePath($g)) $gallery[] = $g;
        }

        $sections = self::SECTIONS_DEFAULT;
        foreach (self::SECTIONS as $key => $_) {
            if (isset($raw['sections'][$key])) $sections[$key] = (bool) $raw['sections'][$key];
        }

        $logo = trim((string) ($raw['logo'] ?? ''));
        if (!self::safePath($logo)) $logo = '';

        return [
            'accent'   => $accent,
            'logo'     => $logo,
            'tagline'  => self::clip((string) ($raw['tagline'] ?? ''), self::MAX_TAGLINE),
            'story'    => self::clip((string) ($raw['story'] ?? ''), self::MAX_STORY),
            'website'  => self::url((string) ($raw['website'] ?? '')),
            'gallery'  => array_slice($gallery, 0, 6),
            'sections' => $sections,
            // Whether this organisation has actually designed anything, so a page can tell
            // "chose our green" from "never opened the screen" and prompt accordingly.
            'is_default' => $raw === [],
        ];
    }

    /**
     * A hex the page can drop into a CSS custom property, and a darker one for hover.
     *
     * Returned as a pair because a single accent gives a flat button; the darker shade is
     * computed rather than asked for, since nobody choosing a brand colour also wants to
     * choose its pressed state.
     *
     * @return array{accent:string, accent_dark:string, accent_wash:string}
     */
    public static function css(array $brand): array
    {
        $hex = self::normaliseHex((string) ($brand['accent'] ?? self::DEFAULT_ACCENT))
             ?: self::DEFAULT_ACCENT;
        [$r, $g, $b] = self::rgb($hex);

        $dark = sprintf('#%02x%02x%02x',
            (int) round($r * 0.78), (int) round($g * 0.78), (int) round($b * 0.78));

        return [
            'accent'      => $hex,
            'accent_dark' => $dark,
            // rgba rather than a mixed hex, so it sits correctly on both the paper ground
            // and a white card without being computed twice.
            'accent_wash' => sprintf('rgba(%d,%d,%d,.10)', $r, $g, $b),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save what an organisation typed.
     *
     * Refuses rather than silently correcting, in every case where the organisation would
     * otherwise see a screen that saved and a page that did not change.
     *
     * @return array{ok:bool, message:string, field?:string}
     */
    public static function save(int $orgId, array $in): array
    {
        if ($orgId < 1) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $org = DB::table('gates_partner_orgs')->where('id', $orgId)->first();
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $current = self::of($org);

        // ── the accent ───────────────────────────────────────────────────────
        $accent = self::normaliseHex((string) ($in['accent'] ?? ''));
        if (trim((string) ($in['accent'] ?? '')) !== '' && $accent === '') {
            return ['ok' => false, 'field' => 'accent',
                    'message' => 'That is not a colour we can read. Use a six-digit hex code '
                               . 'like #1a6118 — your designer or your logo file will have one.'];
        }
        if ($accent !== '' && !self::readable($accent)) {
            $ratio = self::contrast($accent, '#ffffff');
            return ['ok' => false, 'field' => 'accent',
                    'message' => 'That colour cannot be read on a white page — it scores '
                               . number_format($ratio, 1) . ':1 against white and needs at least '
                               . number_format(self::MIN_CONTRAST, 1) . ':1. It is the colour of '
                               . 'your donate button and every link, so a lighter shade would '
                               . 'leave people unable to see them. Try a darker version.'];
        }
        if ($accent === '') $accent = self::DEFAULT_ACCENT;

        // ── the words ────────────────────────────────────────────────────────
        $tagline = self::clip((string) ($in['tagline'] ?? ''), self::MAX_TAGLINE);
        $story   = self::clip((string) ($in['story'] ?? ''), self::MAX_STORY);

        $websiteRaw = trim((string) ($in['website'] ?? ''));
        $website    = self::url($websiteRaw);
        if ($websiteRaw !== '' && $website === '') {
            return ['ok' => false, 'field' => 'website',
                    'message' => 'That web address did not look right. It should start with '
                               . 'https:// and be a full address.'];
        }

        // ── the sections ─────────────────────────────────────────────────────
        $sections = [];
        foreach (self::SECTIONS as $key => $_) {
            $sections[$key] = !empty($in['section_' . $key]);
        }

        $brand = [
            // The logo is written by uploadLogo(), never from this form: a path that arrived
            // in a text field is a path somebody chose, and this one addresses a file on our
            // disk.
            'logo'     => $current['logo'],
            'gallery'  => $current['gallery'],
            'accent'   => $accent,
            'tagline'  => $tagline,
            'story'    => $story,
            'website'  => $website,
            'sections' => $sections,
        ];

        try {
            DB::table('gates_partner_orgs')->where('id', $orgId)->update([
                'brand_json' => (string) json_encode($brand, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => 'Saved. Your donation page has been updated.'];
    }

    /**
     * Record a logo (or a gallery photograph) that has already been stored on disk.
     *
     * Takes a PATH rather than an upload, because the upload itself is the controller's
     * job and is already guarded there — the bytes are sniffed with finfo, re-encoded, and
     * written under the public uploads root. What this adds is that the path we KEEP is one
     * of ours: {@see safePath()} refuses anything that is not a relative path beneath
     * `uploads/`, so a stored value can never address a file outside it.
     *
     * @return array{ok:bool, message:string}
     */
    public static function attach(int $orgId, string $path, bool $gallery = false): array
    {
        $path = ltrim(trim($path), '/');
        if (!self::safePath($path)) {
            return ['ok' => false, 'message' => 'That file could not be attached.'];
        }

        $org = DB::table('gates_partner_orgs')->where('id', $orgId)->first();
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $brand = self::of($org);
        $raw = json_decode((string) ($org->brand_json ?? ''), true);
        if (!is_array($raw)) $raw = [];

        if ($gallery) {
            $shots = $brand['gallery'];
            if (count($shots) >= 6) {
                return ['ok' => false, 'message' => 'You already have six photographs. '
                                                  . 'Remove one before adding another.'];
            }
            $shots[] = $path;
            $raw['gallery'] = $shots;
        } else {
            $raw['logo'] = $path;
        }

        try {
            DB::table('gates_partner_orgs')->where('id', $orgId)->update([
                'brand_json' => (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => $gallery ? 'Photograph added.' : 'Logo updated.'];
    }

    /** Remove the logo, or one gallery photograph by its path. */
    public static function detach(int $orgId, string $path = ''): array
    {
        $org = DB::table('gates_partner_orgs')->where('id', $orgId)->first();
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $raw = json_decode((string) ($org->brand_json ?? ''), true);
        if (!is_array($raw)) $raw = [];

        $path = ltrim(trim($path), '/');
        if ($path === '') {
            $raw['logo'] = '';
        } else {
            $raw['gallery'] = array_values(array_filter(
                (array) ($raw['gallery'] ?? []),
                static fn ($g): bool => ltrim(trim((string) $g), '/') !== $path));
        }

        try {
            DB::table('gates_partner_orgs')->where('id', $orgId)->update([
                'brand_json' => (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => 'Removed.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // COLOUR
    // ═══════════════════════════════════════════════════════════════════════

    /** True when this accent can carry white text and be read as a link on white. */
    public static function readable(string $hex): bool
    {
        return self::contrast($hex, '#ffffff') >= self::MIN_CONTRAST;
    }

    /**
     * WCAG 2.2 relative-luminance contrast ratio, 1.0 to 21.0.
     *
     * The sRGB linearisation is the part people leave out, and leaving it out is not a
     * rounding difference: a naive (max+0.05)/(min+0.05) on raw channel values passes
     * mid-yellows that are genuinely unreadable, which is the exact case this guard exists
     * to catch.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la < 0 || $lb < 0) return 0.0;

        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = self::normaliseHex($hex);
        if ($hex === '') return -1.0;

        $out = 0.0;
        [$r, $g, $b] = self::rgb($hex);
        foreach ([[$r, 0.2126], [$g, 0.7152], [$b, 0.0722]] as [$channel, $weight]) {
            $c = $channel / 255;
            $c = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $out += $c * $weight;
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = self::normaliseHex($hex);
        if ($hex === '') return [0, 0, 0];
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /** `#abc`, `abc`, `#AABBCC` and `aabbcc` all become `#aabbcc`. Anything else is ''. */
    public static function normaliseHex(string $raw): string
    {
        $v = strtolower(trim($raw));
        $v = ltrim($v, '#');

        if (preg_match('~^[0-9a-f]{3}$~', $v)) {
            $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
        }

        return preg_match('~^[0-9a-f]{6}$~', $v) ? '#' . $v : '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SMALL GUARDS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * A stored image path must be one of ours.
     *
     * Relative, beneath `uploads/`, no traversal, no scheme. The upload path already decides
     * WHERE a file lands; this decides what we are willing to keep a pointer to, so a value
     * that reached the column some other way still cannot address `/etc/passwd` or an
     * off-site tracker dressed up as a logo.
     */
    public static function safePath(string $path): bool
    {
        $p = trim($path);
        if ($p === '') return false;
        if (str_contains($p, '..') || str_contains($p, '://') || str_starts_with($p, '/')) {
            return false;
        }
        return (bool) preg_match('~^uploads/[A-Za-z0-9._/-]+\.(jpe?g|png|webp|gif)$~i', $p);
    }

    /** https only, and a real host. An http:// logo link on a payment page is a warning. */
    private static function url(string $raw): string
    {
        $v = trim($raw);
        if ($v === '') return '';
        if (!preg_match('~^https://~i', $v)) return '';
        return filter_var($v, FILTER_VALIDATE_URL) && strlen($v) <= 300 ? $v : '';
    }

    private static function clip(string $raw, int $max): string
    {
        $v = trim(preg_replace('~\s+~u', ' ', strip_tags($raw)) ?? '');
        return mb_substr($v, 0, $max);
    }
}
