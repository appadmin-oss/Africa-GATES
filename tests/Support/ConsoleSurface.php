<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * THE ADMIN CONSOLE'S OWN SURFACE, FOR ITS TYPE AND COLOUR SWEEPS.
 *
 * The console left the "held" list on 4 Oct 2026 (GAPS §8d) and is rebuilt from its own
 * handoff in stages. A file is IN here once it has been rebuilt to the console design —
 * the console's stylesheets, and the templates named below — and every later stage adds
 * the templates it rebuilds. A page not yet rebuilt is not swept: its body still carries
 * the destroyed design's inline type, and a sweep that failed on it would teach somebody
 * to patch a page the plan says to destroy.
 *
 * {@see PublicSurface} excludes this whole surface by name; the two never overlap.
 */
final class ConsoleSurface
{
    /** Every console stylesheet lives here, and nothing else does. */
    public const CSS_DIR = 'public/assets/css/console/';

    /** Templates rebuilt to the console design. Each stage appends its own. */
    public const REBUILT = [
        'templates/admin/layout.twig',
        'templates/admin/dashboard.twig',
        'templates/admin/alerts.twig',
        'templates/admin/partials/nav-icons.twig',
    ];

    /** @return array<string,string> rel => absolute */
    public static function files(): array
    {
        $root = PublicSurface::root();
        $out = [];
        foreach (glob($root . '/' . self::CSS_DIR . '*.css') ?: [] as $abs) {
            $out[ltrim(str_replace($root, '', $abs), '/')] = $abs;
        }
        foreach (self::REBUILT as $rel) $out[$rel] = $root . '/' . $rel;
        ksort($out);
        return $out;
    }
}
