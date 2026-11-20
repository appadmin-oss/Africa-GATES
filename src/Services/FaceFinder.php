<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Where the face is in an uploaded photo, so the crop keeps it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The flier handoff asks for a "face-centre crop to the slot", and the first build did not
 * do it: with no focal point the photo was cover-cropped around a fixed anchor, which
 * centres the middle of the frame. Almost nobody's face is in the middle of the frame of a
 * photo they took of themselves — it is in the upper third, and a phone selfie is taller
 * than every slot on every one of the three formats. So the crop took the chin and the
 * shoulders and cut the eyes off the top, on the exact graphic whose whole purpose is
 * showing somebody's face.
 *
 * The handoff already names the cost: "a mis-cropped selfie is the main reason a generated
 * flier gets binned." A reframe step lets somebody FIX it, which is not the same as it
 * being right when they first see it — most people will not adjust a default, they will
 * decide the thing looks wrong and close the tab.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS SKIN CHROMINANCE AND NOT A CASCADE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no OpenCV on this host, no imagick, no dlib, and no shell to install one with.
 * `php -m` here is GD and nothing else that can see. A Viola-Jones cascade in pure PHP is
 * possible and would be the wrong trade: a 24×24 cascade over an integral image is tens of
 * thousands of feature evaluations per scale per position, in an interpreter, inside a web
 * request somebody is waiting on.
 *
 * So this finds skin, not faces. That is a weaker claim and the code is built to hold only
 * the weaker one: it returns NULL rather than a guess whenever what it found does not look
 * like a head, and null means the layout's own anchor — which is the behaviour that shipped
 * before this file existed. It can only improve on that or tie it; it cannot make a photo
 * worse than the fixed anchor already did.
 *
 * ── AND WHY CHROMINANCE SPECIFICALLY, ON THIS PLATFORM ───────────────────────
 *
 * Skin varies enormously in LUMA and very little in CHROMINANCE. That is the whole reason
 * the YCbCr test is the one worth using here rather than an RGB threshold: an RGB rule is
 * really a brightness rule wearing a colour rule's clothes, and it fails on dark skin
 * first. On a platform whose audience is African, a detector that works on pale faces and
 * shrugs at everyone else is not a partial feature, it is an insult with a stack trace.
 * The Cb/Cr window below is deliberately wider than Chai & Ngan's published one (77–127,
 * 133–173): theirs was fitted to a light-skinned corpus and clips the warm end.
 *
 * The cost of a wide window is false positives — wood, terracotta, sand, a warm wall, a
 * hand. Those are rejected by SHAPE further down, not by narrowing the colour test, because
 * narrowing it is what excludes people.
 */
final class FaceFinder
{
    /**
     * The mask is built at this width, not the photo's.
     *
     * Everything here is O(pixels) with a per-pixel interpreter cost, so the only number
     * that matters for whether this is affordable is this one. 96×128 is ~12k pixels, which
     * is nothing — and a head that survives being 96px wide is a head, while detail below
     * that scale is exactly the noise the shape gate exists to throw away.
     */
    private const W = 96;

    /** Chrominance window. Wider than the published range at the warm end — see above. */
    private const CB_LO = 77;
    private const CB_HI = 136;
    private const CR_LO = 130;
    private const CR_HI = 182;

    /**
     * Luma floor and ceiling.
     *
     * Not a skin test — a "this pixel has a colour at all" test. Near-black and near-white
     * pixels have unstable chrominance (the Cb/Cr of a shadow is noise), so they pass or
     * fail the window on rounding error. Excluding them removes speckle from hair and from
     * a blown-out window; it does NOT exclude dark skin, which sits far above the floor.
     */
    private const Y_LO = 32;
    private const Y_HI = 250;

    /** A blob smaller than this fraction of the frame is speckle, not a head. */
    private const MIN_AREA = 0.006;

    /**
     * A blob larger than this is the background, not a head.
     *
     * A sand wall, a wooden door, or a warm-lit room fills the frame; a face does not. A
     * very tight selfie can reach 45%, so this is set well above that — the point is only
     * to refuse the case where "the skin region" IS the photo and its centroid is therefore
     * the centre, which is what the fixed anchor already gives and with more honesty.
     */
    private const MAX_AREA = 0.72;

    /**
     * How much of its own bounding box a head fills.
     *
     * An ellipse fills π/4 ≈ 0.785 of its box. A head with a neck and some shoulder fills
     * less; an L-shaped run of wall behind a shoulder fills far less. This is the gate that
     * throws away most of what the wide colour window lets in.
     */
    private const MIN_FILL = 0.42;

    /** Width ÷ height of the box. A head is roughly square; an arm or a skirting board is not. */
    private const AR_LO = 0.42;
    private const AR_HI = 1.75;

    /**
     * Where the eyes are down a head, as a fraction of the HEAD's height.
     *
     * The returned point is a CROP CENTRE. Eyes at ~0.42 of the head is the framing every
     * portrait convention agrees on, and it is what makes the difference between a photo that
     * looks composed and one that looks like it slipped.
     *
     * ── AND IT IS THE HEAD'S HEIGHT, NOT THE BLOB'S ──────────────────────────
     *
     * This was wrong in the first pass and only visible in a render. Skin is CONTIGUOUS from
     * the forehead to the collarbone: a face, a neck and any bare shoulder or open collar
     * flood-fill into ONE region, so the bounding box runs from the crown to the chest and
     * 0.42 down it lands on the chin. On a 900×1600 test portrait the detector answered 0.297
     * where the eyes were at 0.188 — a third of the way off, in the direction that crops a
     * forehead.
     *
     * So the head's height is estimated from its WIDTH instead ({@see HEAD_RATIO}), which
     * shoulders cannot inflate. For a head-only blob the two agree; for a head-and-torso blob
     * only the width-based one is right.
     */
    private const EYE_LINE = 0.42;

    /**
     * A head is about this much taller than it is wide, hair included.
     *
     * Anthropometric head height ÷ breadth is ~1.3; with hair and the jaw it reads nearer
     * 1.35 in a photograph. The number does not need to be precise — it is multiplied by a
     * width and then by 0.42, and it is clamped by the blob's own height, so being 10% out
     * moves the crop centre by a few percent of a head.
     */
    private const HEAD_RATIO = 1.35;

    /**
     * The fraction of the region's box that is measured to find the head's width.
     *
     * The head is at the TOP of a head-and-torso region, so its width has to be measured
     * there — measuring the whole box would measure the shoulders, which is the error this
     * is here to avoid. Two-fifths, because a head with shoulders below it occupies roughly
     * the top 45% of the box and a head on its own occupies all of it: in the first case this
     * stays inside the head, and in the second it reaches the widest part of it.
     */
    private const HEAD_BAND = 0.4;

    /**
     * The focal point for a photo, or null when nothing convincing was found.
     *
     * Returns `['x' => 0..1, 'y' => 0..1]` — the same 0..1 pair the reframe step posts, and
     * consumed by the same `cover()` parameters, so a detected face and a dragged one are
     * indistinguishable downstream. That is deliberate: one code path for where the crop
     * goes, whoever decided it.
     *
     * @param \GdImage|resource|null $im
     * @return array{x:float,y:float}|null
     */
    public static function focus(mixed $im): ?array
    {
        if ($im === null || !function_exists('imagesx')) return null;

        $sw = @imagesx($im);
        $sh = @imagesy($im);
        if (!is_int($sw) || !is_int($sh) || $sw < 8 || $sh < 8) return null;

        // Downscale first. Bilinear rather than nearest-neighbour on purpose: nearest
        // sampling of a 4000px photo down to 96 takes one pixel in forty and turns fine
        // texture into salt, which the mask then reads as skin-coloured speckle.
        $w = self::W;
        $h = max(8, (int) round($sh * ($w / $sw)));
        if ($sw <= $w) { $w = $sw; $h = $sh; $small = $im; $own = false; }
        else {
            $scaled = @imagescale($im, $w, $h, IMG_BILINEAR_FIXED);
            if ($scaled === false) return null;
            $small = $scaled; $own = true;
        }

        $mask = self::mask($small, $w, $h);
        if ($own) imagedestroy($small);
        if ($mask === null) return null;

        $best = self::bestBlob($mask, $w, $h);
        if ($best === null) return null;

        // ── THE HEAD INSIDE THE REGION ───────────────────────────────────────
        //
        // Measured in the top band only, for the reason HEAD_BAND gives: the region may run
        // from the crown to the chest, and the head is the part of it up top.
        $bh   = $best['y1'] - $best['y0'] + 1;
        $band = max(1, (int) round($bh * self::HEAD_BAND));

        $headW = 0;
        $sumX  = 0;
        $sumN  = 0;
        for ($y = $best['y0']; $y < $best['y0'] + $band; $y++) {
            $r = $best['rows'][$y] ?? null;
            if ($r === null) continue;
            $rw = $r[1] - $r[0] + 1;
            if ($rw > $headW) $headW = $rw;
            // Centroid of the BAND, not of the region: a bare arm down one side pulls a
            // whole-region centroid off the face, and it is below the band.
            $sumX += ($r[0] + $r[1]) / 2 * $r[2];
            $sumN += $r[2];
        }
        if ($headW < 1 || $sumN < 1) return null;

        // Clamped by the region's own height, so a wide-but-short region — a headband of
        // skin across the frame that somehow passed the shape gate — cannot project an eye
        // line below itself.
        $headH = min($bh, self::HEAD_RATIO * $headW);

        $x = ($sumX / $sumN + 0.5) / $w;
        $y = ($best['y0'] + $headH * self::EYE_LINE) / $h;

        // Clamped away from the edges. A focal point AT an edge means the cover crop has
        // nothing to trim on that side, so the frame stops tracking the face and the guard
        // is what stops a face at the very top of a photo from pinning the crop.
        return [
            'x' => max(0.06, min(0.94, $x)),
            'y' => max(0.06, min(0.94, $y)),
        ];
    }

    /**
     * One byte per pixel: 1 where the chrominance is skin-shaped, 0 elsewhere.
     *
     * A packed string rather than an array of ints — 12k single-character offsets in a PHP
     * array is ~1MB of zvals for a bitmap, and the flood fill below reads it far more often
     * than it writes it.
     */
    private static function mask(mixed $im, int $w, int $h): ?string
    {
        $m   = str_repeat("\0", $w * $h);
        $hit = 0;

        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $rgb = @imagecolorat($im, $x, $y);
                if ($rgb === false) return null;

                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // BT.601, the same conversion JPEG itself uses — so for a JPEG this is
                // reading back the chrominance planes the camera wrote, not inventing a
                // colour space.
                $Y  = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                if ($Y < self::Y_LO || $Y > self::Y_HI) continue;

                $cb = 128 - 0.168736 * $r - 0.331264 * $g + 0.5 * $b;
                $cr = 128 + 0.5 * $r - 0.418688 * $g - 0.081312 * $b;

                if ($cb < self::CB_LO || $cb > self::CB_HI) continue;
                if ($cr < self::CR_LO || $cr > self::CR_HI) continue;

                // Cr must lead Cb. Skin is warm: its red-difference sits above its
                // blue-difference in every tone. This one comparison is what rejects the
                // grey-blues and mid-greens that both windows above happen to admit at
                // their corners, and it costs nothing.
                if ($cr <= $cb) continue;

                $m[$row + $x] = "\1";
                $hit++;
            }
        }

        return $hit > 0 ? $m : null;
    }

    /**
     * The best head-shaped connected region, or null.
     *
     * Iterative flood fill with an explicit stack. Recursion here would be a stack overflow
     * on a photo that is mostly skin: 12k frames deep in the worst case, and PHP has no tail
     * calls and no configurable depth — the failure mode is a fatal, in a request, with the
     * photo still in memory.
     *
     * @return array{x0:int,y0:int,x1:int,y1:int,rows:array<int,array{0:int,1:int,2:int}>}|null
     */
    private static function bestBlob(string $mask, int $w, int $h): ?array
    {
        $total = $w * $h;
        $seen  = str_repeat("\0", $total);
        $best  = null;
        $bestScore = 0.0;

        for ($i = 0; $i < $total; $i++) {
            if ($mask[$i] !== "\1" || $seen[$i] === "\1") continue;

            $stack = [$i];
            $seen[$i] = "\1";
            $area = 0;
            $x0 = $x1 = $i % $w;
            $y0 = $y1 = intdiv($i, $w);
            // Per-row extents and counts, kept for every candidate rather than recomputed for
            // the winner: recomputing would mean a second flood fill, and one that could
            // disagree with the first if either ever changed.
            $rows = [];

            while ($stack) {
                $p  = array_pop($stack);
                $px = $p % $w;
                $py = intdiv($p, $w);
                $area++;

                if ($px < $x0) $x0 = $px;
                if ($px > $x1) $x1 = $px;
                if ($py < $y0) $y0 = $py;
                if ($py > $y1) $y1 = $py;

                if (!isset($rows[$py])) $rows[$py] = [$px, $px, 1];
                else {
                    if ($px < $rows[$py][0]) $rows[$py][0] = $px;
                    if ($px > $rows[$py][1]) $rows[$py][1] = $px;
                    $rows[$py][2]++;
                }

                // 4-connectivity, not 8. Eight joins a cheek to a hand that merely passes
                // near it on the diagonal, and a merged blob fails the shape gate and takes
                // the real face down with it.
                if ($px > 0)      { $n = $p - 1; if ($mask[$n] === "\1" && $seen[$n] !== "\1") { $seen[$n] = "\1"; $stack[] = $n; } }
                if ($px < $w - 1) { $n = $p + 1; if ($mask[$n] === "\1" && $seen[$n] !== "\1") { $seen[$n] = "\1"; $stack[] = $n; } }
                if ($py > 0)      { $n = $p - $w; if ($mask[$n] === "\1" && $seen[$n] !== "\1") { $seen[$n] = "\1"; $stack[] = $n; } }
                if ($py < $h - 1) { $n = $p + $w; if ($mask[$n] === "\1" && $seen[$n] !== "\1") { $seen[$n] = "\1"; $stack[] = $n; } }
            }

            $frac = $area / $total;
            if ($frac < self::MIN_AREA || $frac > self::MAX_AREA) continue;

            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $fill = $area / ($bw * $bh);
            if ($fill < self::MIN_FILL) continue;

            $ar = $bw / $bh;
            if ($ar < self::AR_LO || $ar > self::AR_HI) continue;

            // Higher in the frame wins ties. Every photo somebody uploads of themselves has
            // the head above the middle, and the competing blob is usually a forearm or a
            // knee below it — the same size, the same colour, and the wrong thing to centre.
            $centre = ($y0 + $bh / 2) / $h;
            $score  = $area * $fill * (1.6 - $centre);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1, 'rows' => $rows];
            }
        }

        return $best;
    }
}
