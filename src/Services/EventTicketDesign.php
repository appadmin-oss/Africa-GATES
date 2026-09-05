<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;

/**
 * How one event's ticket looks — resolved once, in PHP, so the template never decides.
 *
 * ── WHY THIS IS A SERVICE AND NOT A HANDFUL OF `|default()` FILTERS ──────────
 *
 * Three callers need the same answer: the ticket page, the admin form that edits it, and the
 * confirmation email (which is HTML built in PHP and cannot use the page's stylesheet at
 * all). Left in Twig, "what colour is this ticket" would have three implementations, and the
 * email's would be the one nobody looks at until an attendee forwards a ticket that is the
 * wrong colour.
 *
 * ── AND WHY THE COLOUR IS VALIDATED TWICE ──────────────────────────────────
 *
 * An accent colour ends up inside a `style` attribute. That makes it the one field on this
 * form whose value is executed rather than displayed, so:
 *
 *   • {@see colour()} accepts ONLY `#rgb` / `#rrggbb`, case-insensitively, and returns the
 *     fallback for anything else. Not "escapes" — REFUSES. There is no legitimate accent
 *     value that is not a hex colour, so anything else is either a mistake or an attempt,
 *     and both deserve the same answer.
 *   • It is applied on write (so the database holds nothing odd) AND on read (so a row
 *     written by an older build, a direct SQL edit, or a restored backup cannot reach a
 *     style attribute unchecked). Validating only on write trusts the database to be the
 *     thing it is protecting.
 *
 * The same logic is why {@see theme()} maps to a fixed set rather than passing a string
 * through into a class name, and why {@see rows()} intersects with a known list.
 *
 * ── EVERY DEFAULT REPRODUCES THE TICKET THAT SHIPPED BEFORE ──────────────────
 *
 * An organiser who never opens the panel must not find that their tickets changed. So the
 * fallbacks here are the literal values the old template had hard-coded, and
 * {@see isCustomised()} exists so the admin screen can say "using the standard design"
 * rather than showing a form full of values that look chosen but are not.
 */
final class EventTicketDesign
{
    /** The platform's own ink, and what an unconfigured ticket has always used. */
    public const DEFAULT_ACCENT = '#10292C';

    public const THEMES = ['light', 'dark'];
    public const DEFAULT_THEME = 'dark';

    /**
     * Optional rows, and what each one is for. The key is what goes in `ticket_rows`.
     *
     * `name`, `tier` and `reference` are NOT here: a ticket without a holder or a reference
     * is not a ticket, so they are not switchable.
     */
    public const ROWS = [
        'seats'  => 'Number of seats',
        'seat'   => 'Seat or table label',
        'price'  => 'What was paid',
        'phone'  => 'Phone number',
        'bought' => 'Date booked',
        'email'  => 'Email address',
    ];

    /** What shows when nobody has chosen — the old ticket, exactly. */
    public const DEFAULT_ROWS = ['seats', 'price'];

    /**
     * The whole design for one event, ready for a template or an email.
     *
     * @param object|array|null $event a `gates_site_events` row, or null
     * @return array{
     *   accent: string, accent_soft: string, ink: string, theme: string,
     *   image: string, image_src: string, note: string, rows: list<string>,
     *   show_qr: bool, customised: bool
     * }
     */
    public static function forEvent(object|array|null $event): array
    {
        $e = self::asArray($event);

        $accent = self::colour((string) ($e['ticket_accent'] ?? ''));
        $theme  = self::theme((string) ($e['ticket_theme'] ?? ''));

        return [
            'accent'      => $accent,
            // A translucent wash of the accent, for the hairlines and the tint behind the
            // code. Derived rather than a second field: two colour pickers is how somebody
            // ends up with a ticket whose border does not match its header.
            'accent_soft' => self::soften($accent, 0.14),
            // Which ink stays legible ON the accent. Computed, not chosen: an organiser who
            // picks a pale yellow accent and is then handed white text has been given a
            // ticket nobody can read, and they will not know why.
            'ink'         => self::contrastInk($accent),
            'theme'       => $theme,
            'image'       => self::image($e),
            // The MASTER the crop was cut from, for anything that needs bytes rather than a
            // URL — see TicketPdf::artwork(). Always a same-site path by construction:
            // TicketArtwork keeps the original local even when the delivered crop is on a
            // CDN, precisely so it can be re-read.
            'image_src'   => self::image(['ticket_image' => (string) ($e['ticket_image_src'] ?? '')]),
            'note'        => self::note((string) ($e['ticket_note'] ?? '')),
            'rows'        => self::rows($e['ticket_rows'] ?? null),
            'show_qr'     => self::showQr($e['ticket_show_qr'] ?? null),
            'customised'  => self::isCustomised($e),
        ];
    }

    /**
     * Accept a hex colour, or return the fallback. Never a partial clean-up.
     *
     * Shorthand `#abc` is expanded, because a person typing a colour by hand types the short
     * form and a template concatenating it into a gradient needs one predictable length.
     */
    public static function colour(string $raw, string $fallback = self::DEFAULT_ACCENT): string
    {
        $v = trim($raw);
        if ($v === '') {
            return $fallback;
        }
        if ($v[0] !== '#') {
            $v = '#' . $v;                       // a colour picker posts `#aabbcc`; a person types `aabbcc`
        }
        if (preg_match('/^#([0-9a-f]{3})$/i', $v, $m) === 1) {
            $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $v) !== 1) {
            return $fallback;                    // refused, not sanitised — see the class note
        }
        return strtoupper($v);
    }

    /** One of THEMES, or the default. Never the raw string. */
    public static function theme(string $raw): string
    {
        $v = strtolower(trim($raw));
        return in_array($v, self::THEMES, true) ? $v : self::DEFAULT_THEME;
    }

    /**
     * Which optional rows show.
     *
     * NULL means "never chosen" and yields DEFAULT_ROWS. An EMPTY STRING means "chosen, and
     * none of them" and yields an empty list — the distinction matters, because an organiser
     * who deliberately unticked everything must not have the defaults handed back to them on
     * the next save.
     *
     * @return list<string>
     */
    public static function rows(mixed $stored): array
    {
        if ($stored === null) {
            return self::DEFAULT_ROWS;
        }
        $want = array_filter(array_map('trim', explode(',', (string) $stored)));
        // Intersect with the known list, and keep ROWS' order rather than the stored order,
        // so a ticket's rows do not reshuffle because somebody re-ticked a box.
        return array_values(array_filter(
            array_keys(self::ROWS),
            static fn (string $k): bool => in_array($k, $want, true)
        ));
    }

    /** Store what {@see rows()} will read back. `''` is a real answer, so no NULL coalescing. */
    public static function packRows(array $chosen): string
    {
        $keep = array_values(array_filter(
            array_keys(self::ROWS),
            static fn (string $k): bool => in_array($k, $chosen, true)
        ));
        return implode(',', $keep);
    }

    /** Opt-out, not opt-in: NULL and 1 both mean show it. */
    public static function showQr(mixed $stored): bool
    {
        return $stored === null || (int) $stored === 1;
    }

    /**
     * The image on the ticket header: the ticket override, else the event's cover, else none.
     *
     * Only a same-site path or an absolute http(s) URL. A ticket is rendered under a strict
     * content-security policy and inside mail clients, so a `javascript:` or `data:` value
     * here is either blocked or a payload, and neither is a picture of an event.
     */
    public static function image(object|array|null $event): string
    {
        $e = self::asArray($event);
        foreach (['ticket_image', 'cover_image'] as $key) {
            $v = trim((string) ($e[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $v) === 1) {
                return $v;
            }
            // `//host/x` is PROTOCOL-RELATIVE, i.e. off-site. Rejected outright before the
            // branches below, because stripping the slashes would turn somebody else's URL
            // into a same-site path that 404s — which reads as "the field did not save"
            // rather than as "that address was refused".
            if (str_starts_with($v, '//')) {
                continue;
            }
            if (str_starts_with($v, '/')) {
                return $v;
            }
            if (preg_match('#^[A-Za-z0-9._/\-]+$#', $v) === 1 && !str_contains($v, '..')) {
                return '/' . ltrim($v, '/');      // a bare stored path like `uploads/x.jpg`
            }
        }
        return '';
    }

    /** The stub line: one line, trimmed, bounded. Escaping is the template's job. */
    public static function note(string $raw): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', $raw));
        return mb_substr($v, 0, 160);
    }

    /**
     * Has anybody actually configured this ticket?
     *
     * Used by the admin screen to say "standard design" instead of presenting defaults as
     * choices, and by tests to prove the migration changed nothing for existing events.
     */
    public static function isCustomised(object|array|null $event): bool
    {
        $e = self::asArray($event);
        foreach (['ticket_accent', 'ticket_theme', 'ticket_image', 'ticket_note', 'ticket_rows'] as $k) {
            if (trim((string) ($e[$k] ?? '')) !== '') {
                return true;
            }
        }
        return ($e['ticket_show_qr'] ?? null) !== null;
    }

    /**
     * Which of black or white stays readable on a given background.
     *
     * Relative luminance per WCAG, not a naive average of the channels: `#00FF00` and
     * `#0000FF` have the same mean and wildly different brightness, so the naive version
     * hands white text to a bright green header.
     */
    public static function contrastInk(string $hex): string
    {
        [$r, $g, $b] = self::rawChannels(self::colour($hex));
        $lin = static function (float $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((float) $r) + 0.7152 * $lin((float) $g) + 0.0722 * $lin((float) $b);
        // 0.36 rather than 0.5: white-on-mid-tone reads better than black-on-mid-tone at the
        // weights used on the ticket, and the accent is a header fill rather than body text.
        return $l > 0.36 ? '#10292C' : '#FFFFFF';
    }

    /**
     * `rgba(r,g,b,a)` from a hex colour — a wash of the accent for borders and tints.
     *
     * Built from the already-validated hex, so what reaches the style attribute is digits
     * and commas by construction and cannot carry anything else through.
     */
    public static function soften(string $hex, float $alpha): string
    {
        [$r, $g, $b] = self::rawChannels(self::colour($hex));
        $a = max(0.0, min(1.0, $alpha));
        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $a);
    }

    /**
     * A validated hex split into its three channels.
     *
     * Public because {@see EventTierPalette} builds the tier ladder out of the same accent
     * and must not carry a second hex parser. Two implementations of "what are the channels
     * of this colour" is how the ticket and the tier chip end up disagreeing about what the
     * event's colour is.
     *
     * @return array{int,int,int}
     */
    public static function channels(string $hex): array
    {
        $hex = self::colour($hex);
        return self::rawChannels($hex);
    }

    /** @return array{int,int,int} */
    private static function rawChannels(string $hex): array
    {
        $h = ltrim($hex, '#');
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    /**
     * Read what an admin form posted into columns, dropping any this deployment has not
     * migrated yet — the same shape every other writer here uses, so a form that is ahead
     * of the database saves what it can instead of throwing.
     *
     * @return array<string, mixed>
     */
    public static function fromForm(array $post): array
    {
        $accentRaw = trim((string) ($post['ticket_accent'] ?? ''));
        $themeRaw  = trim((string) ($post['ticket_theme'] ?? ''));

        // Every key is optional — this whole panel is behind one migration, and a deployment
        // that has uploaded the code but not run it must save what it can rather than 500.
        return OptionalColumn::filter('gates_site_events', [
            // '' is stored as NULL, not as the default colour: see the migration's note on
            // why a stored default is indistinguishable from a choice.
            'ticket_accent'  => $accentRaw === '' ? null : self::colour($accentRaw),
            'ticket_theme'   => $themeRaw === '' ? null : self::theme($themeRaw),
            'ticket_image'   => self::pathOrNull((string) ($post['ticket_image'] ?? '')),
            'ticket_note'    => self::note((string) ($post['ticket_note'] ?? '')) ?: null,
            // A posted form always means a choice was made, including "none of them".
            'ticket_rows'    => self::packRows(array_map('strval', (array) ($post['ticket_rows'] ?? []))),
            'ticket_show_qr' => empty($post['ticket_show_qr']) ? 0 : 1,
        ], [
            'ticket_accent', 'ticket_theme', 'ticket_image', 'ticket_note',
            'ticket_rows', 'ticket_show_qr',
        ]);
    }

    /** An image path that {@see image()} will accept, or NULL. */
    private static function pathOrNull(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        // Validate through the same gate that renders it, so nothing can be stored that
        // would later be silently dropped and look like the field did not save.
        return self::image(['ticket_image' => $v]) !== '' ? $v : null;
    }

    /** @return array<string, mixed> */
    private static function asArray(object|array|null $event): array
    {
        if ($event === null) {
            return [];
        }
        return is_array($event) ? $event : (array) $event;
    }
}
