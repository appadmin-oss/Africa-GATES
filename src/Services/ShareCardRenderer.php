<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Qr;

/**
 * Draws the nominee share card ({@see ShareCardSpec}) as SVG and as PNG.
 *
 * ── ONE PLAN, TWO ENCODINGS ──────────────────────────────────────────────────
 *
 * FlierService kept its SVG and its GD raster "line-for-line" in step by discipline, and
 * its docblock records the times that failed. This class does not try. `plan()` lays
 * the card out ONCE — every rectangle, ring, photo, text run and QR module at an absolute
 * position, text already measured and wrapped against the real font files — and `svg()`
 * and `png()` are two serialisers of that list. They cannot disagree about where
 * anything is, because neither of them decides.
 *
 * Text is measured with GD against the bundled faces. Without GD (the SVG is still
 * served) widths are estimated from the size, which only affects where a long line
 * wraps, never what it says.
 */
final class ShareCardRenderer
{
    use FlierRaster;

    private const INK = '#10292c';
    private const LOGO = '/assets/img/logo-africa-gates-alpha.png';

    /** GD sizes type in points at 96 dpi; the plan is in CSS px. Measured: 100pt Playfair "H" is 94px of ink. */
    private const PT = 0.75;

    /** Combining marks → their spacing forms, which the brand faces do carry. */
    private const SPACING = [
        "\u{0300}" => '`', "\u{0301}" => "\u{00B4}", "\u{0302}" => "\u{02C6}", "\u{0303}" => "\u{02DC}",
        "\u{0304}" => "\u{00AF}", "\u{0306}" => "\u{02D8}", "\u{0307}" => "\u{02D9}", "\u{0308}" => "\u{00A8}",
        "\u{030C}" => "\u{02C7}",
    ];

    /** @var array<string,string> role → TTF path */
    private array $faces;

    public function __construct(private string $base = '')
    {
        $this->faces = [
            'display'  => FlierService::fontPath('display'),
            'bold'     => FlierService::fontPath('bold'),
            'semibold' => FlierService::fontPath('semibold'),
            'regular'  => FlierService::fontPath('regular'),
        ];
    }

    // ── the plan ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $s from ShareCardSpec::for()
     * @return list<array<string,mixed>>
     */
    public function plan(array $s): array
    {
        $ops = [];
        $c = $s['colours'];
        $d = $s['d'];
        $W = $s['W']; $H = $s['H'];
        $ops[] = ['op' => 'rect', 'x' => 0, 'y' => 0, 'w' => $W, 'h' => $H, 'fill' => $c['ground']];

        return $s['stacked'] ? $this->stacked($ops, $s, $c, $d, $W, $H) : $this->split($ops, $s, $c, $d, $W, $H);
    }

    /** Feed and Status: the photo (or the nominee's mark) above, the words below. */
    private function stacked(array $ops, array $s, array $c, array $d, int $W, int $H): array
    {
        $pad = $d['pad'];
        $hasPhoto = $s['photo'] !== null;

        if ($s['portrait']) {
            $ph = $d['photoH'];
            if ($hasPhoto) {
                $ops[] = ['op' => 'photo', 'x' => 0, 'y' => 0, 'w' => $W, 'h' => $ph, 'url' => $s['photo'], 'shape' => 'rect'];
                $ops[] = ['op' => 'scrim', 'x' => 0, 'y' => 0, 'w' => $W, 'h' => $d['scrimH']];
            } else {
                $this->mark($ops, $s, $c, 0, 0, $W, $ph, $d['avatar'], $d['avatarText'], $d['avatarNudge']);
            }
            $this->lockup($ops, $s, $hasPhoto ? '#ffffff' : $c['eyebrow'], $pad, $d['lockTop'], $W - $pad * 2, $d, true);
            $top = $ph + $d['bodyTop'];
        } else {
            $ops[] = ['op' => 'rings', 'cx' => $W, 'cy' => 0, 'step' => 58, 'from' => 56, 'to' => 1500,
                      'colour' => $c['pattern'], 'fade' => [300, 820], 'clip' => [0, 0, $W, $H]];
            $this->lockup($ops, $s, $c['eyebrow'], $pad, $d['lockTop'], $W - $pad * 2, $d, false);
            $ay = $d['lockTop'] + $d['logo'] + $d['stmtTop'];
            $a  = $d['stmtAvatar'];
            if ($hasPhoto) {
                $ops[] = ['op' => 'photo', 'x' => $pad, 'y' => $ay, 'w' => $a, 'h' => $a, 'url' => $s['photo'], 'shape' => 'circle'];
            } else {
                $ops[] = ['op' => 'circle', 'cx' => $pad + $a / 2, 'cy' => $ay + $a / 2, 'r' => $a / 2, 'fill' => $c['avatarBg']];
                $ops[] = $this->t($s['initials'], $pad + $a / 2, $ay + $a / 2 + $d['stmtAvatarText'] * 0.35,
                    $d['stmtAvatarText'], 'display', $c['avatarText'], 0, 'middle');
            }
            $top = $ay + $a + $d['bodyTop'];
        }

        // The footer is fixed to the bottom; the body has whatever is above it.
        $qr   = $d['qr'];
        $colW = $W - $pad * 2 - $qr - 28;
        $jury = $this->wrap($s['jury'], $colW, $d['jury'], 'regular', 2);
        $blockH = $d['footLabel'] + $d['footGap'] + $d['url'] * 1.15 + $d['footGap'] + count($jury) * $d['jury'] * 1.35;
        $blockH = max($blockH, $qr);
        $footBottom = $H - $d['padBottom'];
        $ruleY = $footBottom - $blockH - $d['footTop'];

        $this->body($ops, $s, $c, $d, $pad, $top, $W - $pad * 2, $ruleY - 24);

        $ops[] = ['op' => 'line', 'x1' => $pad, 'y1' => $ruleY, 'x2' => $W - $pad, 'y2' => $ruleY, 'colour' => $c['rule']];
        $ty = $ruleY + $d['footTop'] + ($blockH - ($d['footLabel'] + $d['footGap'] + $d['url'] * 1.15 + $d['footGap'] + count($jury) * $d['jury'] * 1.35)) / 2;
        $this->footer($ops, $s, $c, $d, $pad, $ty, $colW, $jury);
        $ops[] = ['op' => 'qr', 'x' => $W - $pad - $qr, 'y' => $ruleY + $d['footTop'] + ($blockH - $qr) / 2, 'size' => $qr,
                  'data' => $this->qrData($s), 'edge' => $c['rule']];
        return $ops;
    }

    /** Square and Link: the photo is a full-height column, the words beside it. */
    private function split(array $ops, array $s, array $c, array $d, int $W, int $H): array
    {
        $pw  = $d['splitW'];
        $pad = $d['pad'];
        if ($s['photo'] !== null) {
            $ops[] = ['op' => 'photo', 'x' => 0, 'y' => 0, 'w' => $pw, 'h' => $H, 'url' => $s['photo'], 'shape' => 'rect'];
        } else {
            $this->mark($ops, $s, $c, 0, 0, $pw, $H, $d['avatar'], $d['avatarText'], 0);
        }
        if ($s['showRank']) $this->chip($ops, $s, $d, 24, 24, '#ffffff', self::INK, '#3a4a4c', false);
        if ($d['splitQr']) {
            $q = $d['qr'];
            $ops[] = ['op' => 'qr', 'x' => $pw - 24 - $q, 'y' => $H - 24 - $q, 'size' => $q, 'data' => $this->qrData($s), 'edge' => null, 'shadow' => true];
        }

        $x  = $pw + $pad;
        $cw = $W - $pw - $pad * 2;
        $this->lockup($ops, $s, $c['eyebrow'], $x, $pad, $cw, $d, false, false);

        $jury = $this->wrap($s['jury'], $cw, $d['jury'], 'regular', 2);
        $blockH = $d['footLabel'] + $d['footGap'] + $d['url'] * 1.15 + $d['footGap'] + count($jury) * $d['jury'] * 1.35;
        $ruleY = $H - $pad - $blockH - $d['footTop'];

        // The name block sits centred in the space between the lockup and the rule.
        $probe = [];
        $h = $this->body($probe, $s, $c, $d, $x, 0, $cw, PHP_INT_MAX);
        $areaTop = $pad + $d['logo'] + 24;
        $top = max($areaTop, $areaTop + (($ruleY - 24 - $areaTop) - $h) / 2);
        $this->body($ops, $s, $c, $d, $x, $top, $cw, $ruleY - 24);

        $ops[] = ['op' => 'line', 'x1' => $x, 'y1' => $ruleY, 'x2' => $W - $pad, 'y2' => $ruleY, 'colour' => $c['rule']];
        $this->footer($ops, $s, $c, $d, $x, $ruleY + $d['footTop'], $cw, $jury);
        return $ops;
    }

    /**
     * Category, name, organisation · standing and, on a statement card, their line.
     * Returns the height used. When the block would cross `$limit`, the name steps down
     * and then the line is dropped — never the name, never the category.
     */
    private function body(array &$ops, array $s, array $c, array $d, float $x, float $top, float $w, float $limit): float
    {
        $size = $s['nameSize'];
        $maxNameLines = $s['format'] === 'link' ? 2 : 3;
        $withLine = $s['line'] !== '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $run = [];
            $y = $top + $d['eyebrow'];
            $run[] = $this->t(mb_strtoupper($s['category']), $x, $y, $d['eyebrow'], 'bold', $c['eyebrow'], $d['eyebrow'] * 0.12, 'start', $w);
            $names = $this->wrap($s['name'], $w, $size, 'display', $maxNameLines);
            // Room above the cap height for tone marks (Ọ́, Ẹ̀), on every name, so an
            // accented name sits on the same rows as a plain one and never touches the
            // category line above it.
            $y += $d['nameGap'] + $size * 0.14;
            foreach ($names as $i => $ln) {
                $y += $i === 0 ? $size * 0.98 : $size * 1.04;
                $run[] = $this->t($ln, $x, $y, $size, 'display', $c['text']);
            }
            // Organisation and standing share a line when they fit, and stack when not.
            $metaParts = array_values(array_filter([$s['org'], $s['standing']], static fn ($v) => $v !== ''));
            if ($metaParts) {
                $y += $d['metaGap'] + $d['meta'];
                $orgW = $s['org'] !== '' ? $this->measure($s['org'], $d['meta'], 'regular') : 0;
                $stW  = $s['standing'] !== '' ? $this->measure($s['standing'], $d['meta'], 'bold') : 0;
                $together = $s['format'] !== 'square' && $s['format'] !== 'link' && $orgW + $stW + 40 <= $w;
                if ($s['org'] !== '') $run[] = $this->t($s['org'], $x, $y, $d['meta'], 'regular', $c['muted'], 0, 'start', $w);
                if ($s['standing'] !== '') {
                    if ($s['org'] !== '' && $together) {
                        $run[] = ['op' => 'circle', 'cx' => $x + $orgW + 20, 'cy' => $y - $d['meta'] * 0.32, 'r' => 3, 'fill' => $c['rule']];
                        $run[] = $this->t($s['standing'], $x + $orgW + 40, $y, $d['meta'], 'bold', $c['eyebrow']);
                    } else {
                        if ($s['org'] !== '') $y += $d['meta'] * 1.35;
                        $run[] = $this->t($s['standing'], $x, $y, $d['meta'], 'bold', $c['eyebrow']);
                    }
                }
            }
            if ($withLine) {
                $ls = $this->wrap('“' . $s['line'] . '”', min($w, 900), $d['rally'] ?? 40, 'display', 4);
                $y += $d['rallyGap'] ?? 30;
                foreach ($ls as $ln) { $y += ($d['rally'] ?? 40) * 1.3; $run[] = $this->t($ln, $x, $y - ($d['rally'] ?? 40) * 0.3, $d['rally'] ?? 40, 'display', $c['text']); }
            }
            $used = $y + $d['meta'] * 0.35 - $top;
            if ($top + $used <= $limit || $attempt === 7) {
                foreach ($run as $op) $ops[] = $op;
                return $used;
            }
            if ($withLine && $attempt >= 2) { $withLine = false; continue; }
            $size = (int) round($size * 0.9);
        }
        return 0.0;
    }

    private function footer(array &$ops, array $s, array $c, array $d, float $x, float $y, float $w, array $jury): void
    {
        $y += $d['footLabel'];
        $ops[] = $this->t('VOTE NOW', $x, $y, $d['footLabel'], 'bold', $c['eyebrow'], $d['footLabel'] * 0.16);
        $y += $d['footGap'] + $d['url'] * 1.05;
        // The link is the one thing on the card somebody may TYPE, so it steps down to fit
        // rather than being cut: "…/vote/lagos-y…" is a link to nowhere. Ellipsis only
        // below 60% of the size, where a link that long is not typeable anyway.
        $urlSize = (float) $d['url'];
        while ($urlSize > $d['url'] * 0.6 && $this->measure($s['url'], $urlSize, 'bold') > $w) $urlSize -= 1;
        $ops[] = $this->t($s['url'], $x, $y, $urlSize, 'bold', $c['text'], 0, 'start', $w);
        $y += $d['footGap'];
        foreach ($jury as $ln) { $y += $d['jury'] * 1.35; $ops[] = $this->t($ln, $x, $y, $d['jury'], 'regular', $c['muted']); }
    }

    /** The Africa GATES mark and the award's name, plus the rank chip on the right. */
    private function lockup(array &$ops, array $s, $colour, float $x, float $y, float $w, array $d, bool $chipRight, bool $allowChip = true): void
    {
        $L = $d['logo'];
        $ops[] = ['op' => 'circle', 'cx' => $x + $L / 2, 'cy' => $y + $L / 2, 'r' => $L / 2, 'fill' => '#ffffff'];
        $ops[] = ['op' => 'logo', 'x' => $x + $L * 0.15, 'y' => $y + $L * 0.125, 'w' => $L * 0.7, 'h' => $L * 0.75];
        $chipW = 0.0;
        if ($allowChip && $s['showRank'] && $s['stacked']) {
            $onPhoto = $s['portrait'];
            $chipW = $this->chip($ops, $s, $d, $x + $w, $y + $L / 2, $onPhoto ? '#ffffff' : $s['colours']['chipBg'],
                $onPhoto ? self::INK : $s['colours']['chipText'], $onPhoto ? '#3a4a4c' : $s['colours']['chipText'], true);
        }
        $tx = $x + $L + 14;
        $ops[] = $this->t(mb_strtoupper($s['award']), $tx, $y + $L / 2 + $d['award'] * 0.36, $d['award'], 'bold', $colour,
            $d['award'] * 0.14, 'start', $w - $L - 14 - ($chipW > 0 ? $chipW + 24 : 0));
    }

    /** "#3 of 12". `$right` anchors x to its right edge and y to its vertical centre. */
    private function chip(array &$ops, array $s, array $d, float $x, float $y, string $bg, string $fg, string $sub, bool $right): float
    {
        $n = '#' . $s['rank']; $o = 'of ' . $s['field'];
        $nw = $this->measure($n, $d['rankNum'], 'display');
        $ow = $this->measure($o, $d['rankOf'], 'semibold');
        $w = 36 + $nw + 6 + $ow; $h = $d['rankNum'] + 22;
        $x0 = $right ? $x - $w : $x; $y0 = $right ? $y - $h / 2 : $y;
        $ops[] = ['op' => 'rrect', 'x' => $x0, 'y' => $y0, 'w' => $w, 'h' => $h, 'r' => $h / 2, 'fill' => $bg];
        $base = $y0 + $h / 2 + $d['rankNum'] * 0.36;
        $ops[] = $this->t($n, $x0 + 18, $base, $d['rankNum'], 'display', $fg);
        $ops[] = $this->t($o, $x0 + 18 + $nw + 6, $base, $d['rankOf'], 'semibold', $sub);
        return $w;
    }

    /** The nominee's mark when there is no photo: the tone's rings and their initials. */
    private function mark(array &$ops, array $s, array $c, float $x, float $y, float $w, float $h, int $avatar, int $text, int $nudge): void
    {
        $ops[] = ['op' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fill' => $c['frame']];
        $cx = $x + $w / 2; $cy = $y + ($h + $nudge) / 2;
        $pat = [$c['pattern'][0], min(1.0, $c['pattern'][1] * 1.1)];
        $ops[] = ['op' => 'rings', 'cx' => $cx, 'cy' => $cy, 'step' => 40, 'from' => 38, 'to' => (int) max($w, $h), 'colour' => $pat, 'fade' => null, 'clip' => [$x, $y, $w, $h]];
        $ops[] = ['op' => 'circle', 'cx' => $cx, 'cy' => $cy, 'r' => $avatar / 2, 'fill' => $c['avatarBg']];
        $ops[] = $this->t($s['initials'], $cx, $cy + $text * 0.35, $text, 'display', $c['avatarText'], 0, 'middle');
    }

    private function t(string $s, float $x, float $y, float $size, string $face, $fill, float $tracking = 0, string $anchor = 'start', ?float $maxW = null): array
    {
        if ($maxW !== null && $maxW > 0) $s = $this->fit($s, $maxW, $size, $face, $tracking);
        return ['op' => 'text', 's' => $s, 'x' => $x, 'y' => $y, 'size' => $size, 'face' => $face, 'fill' => $fill, 'tracking' => $tracking, 'anchor' => $anchor];
    }

    /** Shorten with an ellipsis until it fits. */
    private function fit(string $s, float $maxW, float $size, string $face, float $tracking): string
    {
        if ($this->measure($s, $size, $face, $tracking) <= $maxW) return $s;
        while (mb_strlen($s) > 1 && $this->measure($s . '…', $size, $face, $tracking) > $maxW) $s = mb_substr($s, 0, -1);
        return rtrim($s) . '…';
    }

    /** @return list<string> */
    private function wrap(string $text, float $maxW, float $size, string $face, int $maxLines): array
    {
        if ($this->gd()) return $this->wrapMeasured($text, $maxW, $size * self::PT, $this->faces[$face], $maxLines) ?: [''];
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = []; $cur = '';
        foreach ($words as $wd) {
            $try = $cur === '' ? $wd : $cur . ' ' . $wd;
            if ($cur === '' || $this->measure($try, $size, $face) <= $maxW) { $cur = $try; continue; }
            $lines[] = $cur; $cur = $wd;
            if (count($lines) === $maxLines - 1) { $rest = implode(' ', array_slice($words, array_search($wd, $words, true))); $cur = $this->fit($rest, $maxW, $size, $face, 0); break; }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    private function measure(string $s, float $size, string $face, float $tracking = 0): float
    {
        if ($this->gd()) {
            return $this->width($s, $size * self::PT, $this->faces[$face]) + $tracking * max(0, mb_strlen($s) - 1);
        }
        $k = $face === 'display' ? 0.52 : 0.56;
        return mb_strlen($s) * $size * $k + $tracking * max(0, mb_strlen($s) - 1);
    }

    private function gd(): bool
    {
        static $ok = null;
        return $ok ??= function_exists('imagettfbbox') && FlierService::fontsPresent()['ok'];
    }

    /** The QR always encodes the short, id-only link: fewer modules, a cleaner scan. */
    private function qrData(array $s): string
    {
        $u = (string) $s['url'];
        return preg_match('#^https?://#', $u) ? $u : 'https://' . ltrim($u, '/');
    }

    // ── SVG ──────────────────────────────────────────────────────────────────

    public function svg(array $s): string
    {
        $W = $s['W']; $H = $s['H'];
        $ops = $this->plan($s);
        $out = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $W . '" height="' . $H
             . '" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="fTitle fDesc">'
             . '<title id="fTitle">' . $this->x('Vote for ' . $s['name'] . ' — ' . $s['category']) . '</title>'
             // The headline ("#3 of 24 — 12 votes from #2") is what a screen reader and a
             // share-sheet caption read; the card itself shows it as chip and standing.
             . '<desc id="fDesc">' . $this->x(trim(($s['headline'] !== '' ? $s['headline'] . '. ' : '') . $s['award'] . '. Vote at ' . $s['url'])) . '</desc>'
             . '<defs><linearGradient id="scScrim" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0" stop-color="#081416" stop-opacity="0.62"/><stop offset="0.45" stop-color="#081416" stop-opacity="0.34"/>'
             . '<stop offset="1" stop-color="#081416" stop-opacity="0"/></linearGradient></defs>';
        $n = 0;
        foreach ($ops as $o) {
            $n++;
            switch ($o['op']) {
                case 'rect':
                    $out .= '<rect x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $this->n($o['w']) . '" height="' . $this->n($o['h']) . '"' . $this->fill($o['fill']) . '/>';
                    break;
                case 'rrect':
                    $out .= '<rect x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $this->n($o['w']) . '" height="' . $this->n($o['h']) . '" rx="' . $this->n($o['r']) . '"' . $this->fill($o['fill']) . '/>';
                    break;
                case 'circle':
                    $out .= '<circle cx="' . $this->n($o['cx']) . '" cy="' . $this->n($o['cy']) . '" r="' . $this->n($o['r']) . '"' . $this->fill($o['fill']) . '/>';
                    break;
                case 'line':
                    [$hex, $a] = $this->ha($o['colour']);
                    $out .= '<line x1="' . $this->n($o['x1']) . '" y1="' . $this->n($o['y1']) . '" x2="' . $this->n($o['x2']) . '" y2="' . $this->n($o['y2']) . '" stroke="' . $hex . '" stroke-opacity="' . $a . '" stroke-width="1"/>';
                    break;
                case 'scrim':
                    $out .= '<rect x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $this->n($o['w']) . '" height="' . $this->n($o['h']) . '" fill="url(#scScrim)"/>';
                    break;
                case 'photo':
                    $id = 'scP' . $n;
                    $shape = $o['shape'] === 'circle'
                        ? '<circle cx="' . $this->n($o['x'] + $o['w'] / 2) . '" cy="' . $this->n($o['y'] + $o['h'] / 2) . '" r="' . $this->n($o['w'] / 2) . '"/>'
                        : '<rect x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $this->n($o['w']) . '" height="' . $this->n($o['h']) . '"/>';
                    $href = $this->x($this->abs($o['url']));
                    $out .= '<clipPath id="' . $id . '">' . $shape . '</clipPath><g clip-path="url(#' . $id . ')">'
                          . '<image href="' . $href . '" xlink:href="' . $href . '" x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $this->n($o['w']) . '" height="' . $this->n($o['h']) . '" preserveAspectRatio="xMidYMin slice"/></g>';
                    break;
                case 'logo':
                    $href = $this->x($this->abs(self::LOGO));
                    $out .= '<image href="' . $href . '" xlink:href="' . $href . '" x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $this->n($o['w']) . '" height="' . $this->n($o['h']) . '" preserveAspectRatio="xMidYMid meet"/>';
                    break;
                case 'rings':
                    $id = 'scR' . $n;
                    [$cx0, $cy0, $cw0, $ch0] = $o['clip'];
                    $out .= '<clipPath id="' . $id . '"><rect x="' . $this->n($cx0) . '" y="' . $this->n($cy0) . '" width="' . $this->n($cw0) . '" height="' . $this->n($ch0) . '"/></clipPath><g clip-path="url(#' . $id . ')" fill="none" stroke-width="2">';
                    [$hex, $a] = $this->ha($o['colour']);
                    for ($r = $o['from']; $r < $o['to']; $r += $o['step']) {
                        $al = $a * $this->fade($r, $o['fade']);
                        if ($al <= 0.004) continue;
                        $out .= '<circle cx="' . $this->n($o['cx']) . '" cy="' . $this->n($o['cy']) . '" r="' . $r . '" stroke="' . $hex . '" stroke-opacity="' . round($al, 3) . '"/>';
                    }
                    $out .= '</g>';
                    break;
                case 'text':
                    [$hex, $a] = $this->ha($o['fill']);
                    $fam = $o['face'] === 'display' ? 'Playfair Display, Georgia, serif' : 'DM Sans, system-ui, sans-serif';
                    $wt  = $o['face'] === 'regular' ? 400 : ($o['face'] === 'semibold' ? 600 : 700);
                    $out .= '<text x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" font-family="' . $fam . '" font-size="' . $this->n($o['size']) . '" font-weight="' . $wt . '" fill="' . $hex . '"'
                          . ($a < 1 ? ' fill-opacity="' . $a . '"' : '')
                          . ($o['tracking'] > 0 ? ' letter-spacing="' . $this->n($o['tracking']) . '"' : '')
                          . ($o['anchor'] === 'middle' ? ' text-anchor="middle"' : '') . '>' . $this->x($o['s']) . '</text>';
                    break;
                case 'qr':
                    $out .= $this->svgQr($o);
                    break;
            }
        }
        return $out . '</svg>';
    }

    private function svgQr(array $o): string
    {
        $m = Qr::encodeBytes($o['data']);
        $out = '<rect x="' . $this->n($o['x']) . '" y="' . $this->n($o['y']) . '" width="' . $o['size'] . '" height="' . $o['size'] . '" rx="14" fill="#ffffff"/>';
        if ($m === null) return $out;
        [$mx, $my, $u] = $this->qrGrid($o, count($m));
        $p = '';
        foreach ($m as $r => $row) foreach ($row as $col => $dark) if ($dark) $p .= 'M' . $this->n($mx + $col * $u) . ' ' . $this->n($my + $r * $u) . 'h' . $u . 'v' . $u . 'h-' . $u . 'z';
        return $out . '<path d="' . $p . '" fill="' . self::INK . '" shape-rendering="crispEdges"/>';
    }

    /** @return array{0:float,1:float,2:int} module origin and size */
    private function qrGrid(array $o, int $n): array
    {
        $u = max(1, (int) floor(($o['size'] - 16) / ($n + 2)));
        $span = $u * $n;
        return [$o['x'] + ($o['size'] - $span) / 2, $o['y'] + ($o['size'] - $span) / 2, $u];
    }

    private function fade(float $r, ?array $fade): float
    {
        if ($fade === null) return 1.0;
        [$a, $b] = $fade;
        return $r <= $a ? 1.0 : ($r >= $b ? 0.0 : 1 - ($r - $a) / ($b - $a));
    }

    private function fill($f): string
    {
        [$hex, $a] = $this->ha($f);
        return ' fill="' . $hex . '"' . ($a < 1 ? ' fill-opacity="' . $a . '"' : '');
    }

    /** @return array{0:string,1:float} */
    private function ha($f): array
    {
        return is_array($f) ? [(string) $f[0], (float) $f[1]] : [(string) $f, 1.0];
    }

    private function abs(string $u): string
    {
        return str_starts_with($u, '/') && $this->base !== '' ? rtrim($this->base, '/') . $u : $u;
    }

    private function n(float|int $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }

    private function x(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── PNG ──────────────────────────────────────────────────────────────────

    /** The card through GD, or null when GD or a face is missing (the caller serves the SVG). */
    public function png(array $s): ?string
    {
        if (!$this->gd() || !function_exists('imagecreatetruecolor')) return null;
        $W = $s['W']; $H = $s['H'];
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imagesavealpha($im, false);

        foreach ($this->plan($s) as $o) {
            switch ($o['op']) {
                case 'rect':
                    imagefilledrectangle($im, (int) round($o['x']), (int) round($o['y']), (int) round($o['x'] + $o['w']) - 1, (int) round($o['y'] + $o['h']) - 1, $this->col($im, $o['fill']));
                    break;
                case 'rrect':
                    $this->roundRect($im, $o['x'], $o['y'], $o['w'], $o['h'], $o['r'], $this->col($im, $o['fill']));
                    break;
                case 'circle':
                    imagefilledellipse($im, (int) round($o['cx']), (int) round($o['cy']), (int) round($o['r'] * 2), (int) round($o['r'] * 2), $this->col($im, $o['fill']));
                    break;
                case 'line':
                    imageline($im, (int) $o['x1'], (int) $o['y1'], (int) $o['x2'], (int) $o['y2'], $this->col($im, $o['colour']));
                    break;
                case 'scrim':
                    for ($i = 0; $i < $o['h']; $i++) {
                        $u = $i / max(1, $o['h'] - 1);
                        $a = $u < 0.45 ? 0.62 - (0.62 - 0.34) * ($u / 0.45) : 0.34 * (1 - ($u - 0.45) / 0.55);
                        imageline($im, (int) $o['x'], (int) $o['y'] + $i, (int) ($o['x'] + $o['w']) - 1, (int) $o['y'] + $i, $this->col($im, ['#081416', $a]));
                    }
                    break;
                case 'photo':
                    $this->pngPhoto($im, $o);
                    break;
                case 'logo':
                    $logo = $this->loadPhoto(self::LOGO);
                    if ($logo !== null && $logo !== false) {
                        $lw = imagesx($logo); $lh = imagesy($logo);
                        $r = min($o['w'] / $lw, $o['h'] / $lh);
                        $dw = (int) round($lw * $r); $dh = (int) round($lh * $r);
                        imagecopyresampled($im, $logo, (int) round($o['x'] + ($o['w'] - $dw) / 2), (int) round($o['y'] + ($o['h'] - $dh) / 2), 0, 0, $dw, $dh, $lw, $lh);
                        imagedestroy($logo);
                    }
                    break;
                case 'rings':
                    [$x0, $y0, $cw, $ch] = $o['clip'];
                    imagesetclip($im, (int) $x0, (int) $y0, (int) ($x0 + $cw) - 1, (int) ($y0 + $ch) - 1);
                    imagesetthickness($im, 2);
                    [$hex, $a] = $this->ha($o['colour']);
                    for ($r = $o['from']; $r < $o['to']; $r += $o['step']) {
                        $al = $a * $this->fade($r, $o['fade']);
                        if ($al <= 0.004) continue;
                        imagearc($im, (int) round($o['cx']), (int) round($o['cy']), $r * 2, $r * 2, 0, 360, $this->col($im, [$hex, $al]));
                    }
                    imagesetthickness($im, 1);
                    imagesetclip($im, 0, 0, $W - 1, $H - 1);
                    break;
                case 'text':
                    $font = $this->faces[$o['face']];
                    $x = $o['anchor'] === 'middle' ? $o['x'] - $this->measure($o['s'], $o['size'], $o['face'], $o['tracking']) / 2 : $o['x'];
                    $this->markedText($im, $o['s'], $o['size'] * self::PT, $font, $this->col($im, $o['fill']), $x, $o['y'], $o['tracking']);
                    break;
                case 'qr':
                    $this->pngQr($im, $o);
                    break;
            }
        }

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /**
     * Text that may carry combining marks no brand face has.
     *
     * Yoruba tone marks over an under-dotted vowel (ọ̀, ẹ́) have no precomposed form, and
     * Playfair and DM Sans carry no combining grave or acute. FlierRaster::text() falls
     * back per CODE POINT, which draws the mark as its own character with its own advance:
     * "Adébáyọ̀" came out as "Adébáyọ` ". So: compose what can be composed (NFC), and for
     * each grapheme whose marks the face cannot draw, draw the base in the brand face and
     * centre the mark over it from the PDF face, which has them. The SVG needs none of
     * this — a browser composes the cluster itself.
     */
    private function markedText($im, string $s, float $pt, string $font, int $colour, float $x, float $y, float $tracking): void
    {
        if (class_exists('\Normalizer')) $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_C);
        if (!preg_match('/\p{M}/u', $s)) { $this->text($im, $s, $pt, $font, $colour, $x, $y, $tracking); return; }

        $markFace = dirname(__DIR__, 2) . '/resources/fonts/AGText-Bold.ttf';
        preg_match_all('/\X/u', $s, $m);
        foreach ($m[0] as $cluster) {
            $chars = preg_split('//u', $cluster, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $base = '';
            $marks = [];
            foreach ($chars as $ch) {
                if (preg_match('/\p{M}/u', $ch)) $marks[] = $ch; else $base .= $ch;
            }
            $this->text($im, $base, $pt, $font, $colour, $x, $y);
            $bw = $this->width($base, $pt, $font);
            $bb = imagettfbbox($pt, 0, $font, $base);
            $baseMid = $bb === false ? $bw / 2 : ($bb[0] + $bb[2]) / 2;
            foreach ($marks as $mk) {
                // GD draws no ink for a lone combining glyph, so each mark is drawn as its
                // SPACING twin (` for U+0300, ´ for U+0301 …), centred over the base's ink
                // and lifted for a capital.
                $sp = self::SPACING[$mk] ?? null;
                if ($sp === null) continue;
                $face = $this->canDraw($font, $sp) ? $font : $markFace;
                $mb = imagettfbbox($pt, 0, $face, $sp);
                $markMid = $mb === false ? 0 : ($mb[0] + $mb[2]) / 2;
                $lift = preg_match('/^\p{Lu}/u', $base) ? $pt * 0.30 : 0;
                imagettftext($im, $pt, 0, (int) round($x + $baseMid - $markMid), (int) round($y - $lift), $colour, $face, $sp);
            }
            $x += $bw + $tracking;
        }
    }

    private function pngPhoto($im, array $o): void
    {
        $src = $this->loadPhoto((string) $o['url']);
        if ($src === null || $src === false) {
            $this->roundRect($im, $o['x'], $o['y'], $o['w'], $o['h'], $o['shape'] === 'circle' ? $o['w'] / 2 : 0, $this->col($im, '#e8e5dd'));
            return;
        }
        $w = (int) round($o['w']); $h = (int) round($o['h']);
        if ($o['shape'] !== 'circle') {
            $this->cover($im, $src, (int) round($o['x']), (int) round($o['y']), $w, $h);
            imagedestroy($src);
            return;
        }
        // A circle: crop into a square, then copy only the pixels inside the radius.
        $sq = imagecreatetruecolor($w, $h);
        $this->cover($sq, $src, 0, 0, $w, $h);
        imagedestroy($src);
        $r = $w / 2; $r2 = $r * $r;
        for ($yy = 0; $yy < $h; $yy++) {
            $dy = $yy + 0.5 - $r;
            $span = $r2 - $dy * $dy;
            if ($span <= 0) continue;
            $dx = sqrt($span);
            $x0 = (int) max(0, floor($r - $dx)); $x1 = (int) min($w - 1, ceil($r + $dx) - 1);
            imagecopy($im, $sq, (int) round($o['x']) + $x0, (int) round($o['y']) + $yy, $x0, $yy, $x1 - $x0 + 1, 1);
        }
        imagedestroy($sq);
    }

    private function pngQr($im, array $o): void
    {
        if (!empty($o['shadow'])) {
            for ($i = 6; $i >= 1; $i--) $this->roundRect($im, $o['x'] - $i, $o['y'] - $i + 4, $o['size'] + $i * 2, $o['size'] + $i * 2, 14 + $i, $this->col($im, [self::INK, 0.03]));
        }
        $this->roundRect($im, $o['x'], $o['y'], $o['size'], $o['size'], 14, $this->col($im, '#ffffff'));
        $m = Qr::encodeBytes($o['data']);
        if ($m === null) return;
        [$mx, $my, $u] = $this->qrGrid($o, count($m));
        $ink = $this->col($im, self::INK);
        foreach ($m as $r => $row) foreach ($row as $c => $dark) {
            if ($dark) imagefilledrectangle($im, (int) round($mx + $c * $u), (int) round($my + $r * $u), (int) round($mx + $c * $u) + $u - 1, (int) round($my + $r * $u) + $u - 1, $ink);
        }
    }

    private function col($im, $f): int
    {
        [$hex, $a] = $this->ha($f);
        [$r, $g, $b] = FlierLayout::rgb($hex);
        return $a >= 1 ? (int) imagecolorallocate($im, $r, $g, $b)
                       : (int) imagecolorallocatealpha($im, $r, $g, $b, (int) round(127 * (1 - max(0.0, min(1.0, $a)))));
    }
}
