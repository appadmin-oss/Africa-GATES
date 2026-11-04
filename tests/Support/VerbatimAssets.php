<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * FILES THIS REPOSITORY SHIPS BYTE-IDENTICAL FROM THE REDESIGN HANDOFF, AND NOTHING ELSE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AN EXEMPTION HERE IS PINNED TO A HASH, NOT TO A NAME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff says to ship the celebration engine VERBATIM (PHASE-3 §7.8, §9.3: "don't
 * 'optimise' it"). Verbatim, it breaks house rules — two colour literals in its sheet, and
 * in its script 32 lines of hex and six rgba(), a typed 11px and 12px, a typed-capitals "CONFIRMED" (the
 * one capital the house allows, as the ticket stamp) and the demo event "KCEA Ceremony"
 * as a fallback. Whether to amend it is the owner's question, GAPS Q7, and it is not
 * answered. So the guards that would refuse it skip it — narrowly:
 *
 *   · BY NAME — the two paths below and nothing that merely looks like them;
 *   · AND BY CONTENT — {@see is()} is true only while the file's sha256 is still the
 *     bundle's. An edited copy is no longer the verbatim asset and loses the exemption
 *     at once, so it is swept like any other file. A name-only exemption would have let
 *     anybody "fix" the file and keep every hex in it unseen.
 *
 * Only a guard the file actually fails reads this list (ColourLiteralTest, today). The
 * type and mono/case sweeps read no JavaScript and find nothing in the sheet, so they are
 * not exempted from anything — an exemption that excuses nothing is a hole waiting for
 * something to walk through it. Every disagreement with the house rules is listed for
 * the owner in docs/handoff/PHASE-3.md, "Celebrations".
 */
final class VerbatimAssets
{
    public const REASON = 'shipped byte-identical from the redesign handoff (PHASE-3 §7.8 "SHIP VERBATIM") — '
                        . 'its colours, type and capitals await the owner (GAPS Q7)';

    /** repo path => [the bundle path it is a copy of, its sha256 there] */
    public const FILES = [
        'public/assets/js/celebration.js' => [
            'design/assets/celebration/celebration.js',
            '903d690b6c85f0a8bb9c3837f1a58356035f767084a026db5f0251c7a145f3c8',
        ],
        'public/assets/css/components/celebration.css' => [
            'design/assets/celebration/celebration.css',
            '1e00d2fb90ed58234e9812d8e10384af6016c4cd632619d3ec1e4b016eada06a',
        ],
    ];

    /** True only for a listed path whose bytes are still the bundle's. */
    public static function is(string $relativePath): bool
    {
        if (!isset(self::FILES[$relativePath])) return false;
        $abs = dirname(__DIR__, 2) . '/' . $relativePath;

        return is_file($abs) && hash_file('sha256', $abs) === self::FILES[$relativePath][1];
    }
}
