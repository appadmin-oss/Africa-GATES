<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Every position, size and conditional on the share graphics — decided once.
 *
 * ── WHY THIS IS A SEPARATE CLASS ─────────────────────────────────────────────
 *
 * {@see FlierService} draws the same design twice: once as SVG (viewing, download,
 * print) and once through GD (the raster the platforms need, because no major chat
 * app previews an SVG and no crawler runs JavaScript). Its own docblock records what
 * happened last time those two drifted — a monogram rule fixed in one renderer kept
 * rendering the old way in the other — and the fix at the time was "keep them a
 * line-for-line mirror", which is a discipline, not a mechanism.
 *
 * The new spec makes that discipline impossible to hold by hand. Almost nothing on
 * the card is at a fixed y any more:
 *
 *   • the photo panel is 1020 tall, or 1120 when there is no rank pill;
 *   • the scrim, the name block and the category line all move with it;
 *   • the name block grows UPWARD from a fixed bottom edge, so its top depends on
 *     the font size AND the line count, both of which depend on the name;
 *   • four elements disappear under conditions that interact.
 *
 * Two hand-maintained copies of that arithmetic would disagree within a week. So the
 * arithmetic lives here, returns plain numbers and strings, and both renderers read
 * it. Nothing in this class draws anything or touches the database, which is also why
 * the spec can be pinned by unit tests rather than by looking at a picture.
 *
 * Field names match the design document that specified them, so the two can be read
 * side by side.
 */
final class FlierLayout
{
    // ── Canvas ───────────────────────────────────────────────────────────────
    public const W = 1080;
    public const H = 1350;
    public const OG_W = 1200;
    public const OG_H = 630;

    /** Width of the OG card's portrait column. Media's `og_photo` preset must match. */
    public const OG_PHOTO_W = 520;

    /** Photo panel height, with and without the rank pill. */
    public const PANEL_H_RANKED   = 1020;
    public const PANEL_H_UNRANKED = 1120;

    /**
     * The name block's bottom edge sits this far above the panel base, and the
     * category line this far. Both are measured from the panel, not the canvas,
     * which is what lets the panel grow without either being restated.
     */
    private const NAME_BOTTOM_INSET = 108;
    private const CAT_INSET         = 90;

    /** The scrim is always this tall and always ends flush with the panel base. */
    public const SCRIM_H = 380;

    /** Fixed rows in the bottom third — identical in every state, so the card always ends the same way. */
    public const STANDING_Y  = 1068;
    public const PILL_Y      = 1140;
    public const PILL_H      = 112;
    public const FOOTNOTE_Y  = 1284;

    /** The OG card's name is bottom-anchored here. */
    private const OG_NAME_BOTTOM = 496;

    /** Line height and the pad that keeps combining marks clear of the cap height. */
    public const NAME_LINE_H = 1.22;
    public const NAME_PAD    = 12;

    /** The one sentence that is on every card in every state. */
    public const FOOTNOTE = 'Public votes are one part of the score. An independent jury decides the award.';

    // ── Palette ──────────────────────────────────────────────────────────────
    public const C_BG_TOP    = '#123b2f';
    public const C_BG_BOTTOM = '#08201c';
    public const C_PANEL     = '#0f3329';
    public const C_MONOGRAM  = '#237b22';
    public const C_SCRIM     = '#0a2721';
    public const C_GOLD      = '#c9a227';
    public const C_ON_GOLD   = '#1a1204';
    public const C_WHITE     = '#ffffff';
    public const C_MIST      = '#e8f2ec';
    public const C_MUTED     = '#a9c7bd';
    public const C_LEAF      = '#7fc87c';
    public const C_DEEP      = '#237b22';
    public const C_ON_WHITE  = '#123b2f';

    /**
     * The whole layout for one nominee.
     *
     * @param array<string,mixed> $f a {@see FlierService::forNominee()} payload
     * @return array<string,mixed>
     */
    public static function for(array $f): array
    {
        $name     = (string) ($f['name'] ?? '');
        $standing = (array) ($f['standing'] ?? []);

        $rank      = (int) ($standing['rank'] ?? 0);
        $fieldSize = (int) ($standing['field'] ?? 0);
        // `gap_ahead` skips EQUAL totals, so on a tie it is the distance to the
        // position actually above rather than zero — and `next_rank` is that
        // position. Both come from StandingsService; the card never recomputes a
        // standing, it only decides whether there is room to print one.
        $gapVotes  = (int) ($standing['gap_ahead'] ?? 0);
        $nextRank  = (int) ($standing['next_rank'] ?? max(1, $rank - 1));
        // Momentum is only a number when the category has timestamped votes to measure
        // it from; unavailable and zero are the same thing to the card, and both mean
        // the clause is dropped rather than printed as "0 in 24h".
        $momentum  = ($standing['momentum_available'] ?? false) ? (int) ($standing['momentum_24h'] ?? 0) : 0;

        $nameSize  = self::nameSize($name);
        $nameLines = self::splitName($name);
        $lines     = count($nameLines);

        // Ranked or not decides the panel, and the panel decides four other positions.
        $showRank = $fieldSize >= 2;
        $panelH   = $showRank ? self::PANEL_H_RANKED : self::PANEL_H_UNRANKED;

        $blockH   = self::NAME_PAD + $lines * self::NAME_LINE_H * $nameSize;
        $nameTop  = (int) round($panelH - self::NAME_BOTTOM_INSET - $blockH);

        $ogNameSize = $nameSize;
        $ogNameTop  = (int) round(self::OG_NAME_BOTTOM - (self::NAME_PAD + $lines * self::NAME_LINE_H * $ogNameSize));

        // The three standing clauses. Each is a string or '', and '' means "not drawn" —
        // no separate boolean to fall out of step with the text it guards.
        $gapText  = ($showRank && $gapVotes > 0 && $rank > 1)
            ? $gapVotes . ' vote' . ($gapVotes === 1 ? '' : 's') . ' from #' . max(1, $nextRank)
            : '';
        $leadText = ($showRank && $rank === 1) ? 'Leading the field' : '';
        $momText  = $momentum > 0 ? $momentum . ' in 24h' : '';

        $url = (string) ($f['short_url'] ?? $f['url'] ?? '');

        return [
            'name'        => $name,
            'nameLines'   => $nameLines,
            'nameSize'    => $nameSize,
            'lineCount'   => $lines,
            'nameTop'     => $nameTop,
            'nameBottom'  => $panelH - self::NAME_BOTTOM_INSET,

            'panelH'      => $panelH,
            'scrimTop'    => $panelH - self::SCRIM_H,
            'catTop'      => $panelH - self::CAT_INSET,

            'showRank'    => $showRank,
            'rank'        => $rank,
            'fieldSize'   => $fieldSize,

            'gapText'     => $gapText,
            'leadText'    => $leadText,
            'momText'     => $momText,
            'showMiddot'  => ($gapText !== '' || $leadText !== '') && $momText !== '',
            'showStanding'=> $gapText !== '' || $leadText !== '' || $momText !== '',

            'url'         => $url,
            // One step, not a fit loop: the pill is a fixed 952px and 34px clears the
            // longest URL the shortener can produce, so measuring would only be a
            // slower way to reach the same two answers.
            'urlSize'     => mb_strlen($url) > 30 ? 34 : 40,

            'ogNameSize'  => $ogNameSize,
            'ogNameTop'   => $ogNameTop,

            'monogram'    => self::monogram($name),
            'category'    => (string) ($f['category'] ?? ''),
            'countryCode' => strtoupper(trim((string) ($f['country'] ?? ''))),

            // The school or organisation, on its own line under the category.
            //
            // It goes BETWEEN two fixed rows — the category baseline and the standing
            // line at 1068 — which leaves roughly 140px, so one 26px line fits with
            // room to spare and nothing below it has to move. Truncated rather than
            // wrapped: a second line would eat that margin and start colliding with
            // the standing on exactly the longest names, which is the worst case to
            // discover in production.
            'organisation' => self::clip(trim((string) ($f['organisation'] ?? '')), 42),
            'orgTop'       => $panelH - self::CAT_INSET + 40,
        ];
    }

    /**
     * Type size for a name, from its length with combining marks stripped.
     *
     * MARKS STRIPPED IS THE POINT. `Ọlásùnkànmí Adébáyọ̀` is 19 glyphs and 24 code
     * points in NFD; sizing on code points would drop it two steps below a Latin name
     * of the same visual width, so exactly the names this platform exists for would
     * render smallest. Diacritics add height, not width, and the 12px pad above the
     * block is what makes room for them.
     */
    public static function nameSize(string $name): int
    {
        $bare = mb_strlen(self::stripMarks($name));
        return match (true) {
            $bare <= 14 => 96,
            $bare <= 20 => 82,
            $bare <= 28 => 68,
            $bare <= 36 => 58,
            default     => 50,
        };
    }

    /**
     * At most two lines, broken at the space nearest the middle.
     *
     * Not a greedy wrap. Greedy fills line one and leaves a stub — "Ọlásùnkànmí
     * Adébáyọ̀ / Ogundipe" — which on a poster reads as a mistake. Balancing the two
     * halves is what makes a two-line name look set rather than overflowed.
     *
     * @return list<string> one or two lines
     */
    public static function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [''];
        if (mb_strlen(self::stripMarks($name)) <= 14) return [$name];

        $parts = preg_split('/\s+/u', $name) ?: [$name];
        if (count($parts) < 2) return [$name];

        $best = 1;
        $bestDiff = PHP_INT_MAX;
        for ($i = 1; $i < count($parts); $i++) {
            $a = mb_strlen(implode(' ', array_slice($parts, 0, $i)));
            $b = mb_strlen(implode(' ', array_slice($parts, $i)));
            $diff = abs($a - $b);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $i; }
        }
        return [
            implode(' ', array_slice($parts, 0, $best)),
            implode(' ', array_slice($parts, $best)),
        ];
    }

    /**
     * First letter of the first word plus first letter of the last — the stand-in when
     * a nominee has no photo.
     *
     * WORDS THAT DO NOT START WITH A LETTER ARE SKIPPED. "Nominee 48 Surname" rendered
     * as "N4" on an early build, and a digit at 400px reads as a rendering fault rather
     * than as somebody's initials — any name carrying a cohort year, an edition number
     * or a team number does the same thing.
     *
     * Uppercased with the marks kept, because `Ọ` is a different letter from `O` to the
     * person whose name it is.
     */
    public static function monogram(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $parts = array_values(array_filter(
            $parts,
            static fn ($p) => $p !== '' && preg_match('/^\p{L}/u', $p) === 1
        ));
        if (!$parts) return '?';
        $first = mb_substr($parts[0], 0, 1);
        if (count($parts) === 1) return mb_strtoupper($first);
        return mb_strtoupper($first . mb_substr($parts[count($parts) - 1], 0, 1));
    }

    /** Truncate on a word boundary where possible, with an ellipsis. */
    public static function clip(string $s, int $max): string
    {
        if ($s === '' || mb_strlen($s) <= $max) return $s;
        $cut = mb_substr($s, 0, $max - 1);
        $sp  = mb_strrpos($cut, ' ');
        // Only break at a space if it leaves most of the budget used — otherwise a
        // long first word would collapse the line to almost nothing.
        if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
        return rtrim($cut) . '…';
    }

    /** NFD then drop the combining range — the measurement basis for {@see nameSize()}. */
    public static function stripMarks(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $s = (string) (\Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s);
        }
        return (string) preg_replace('/\p{Mn}+/u', '', $s);
    }

    /** '#123b2f' → [18, 59, 47], for GD's colour allocator. */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
