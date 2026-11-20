<?php
declare(strict_types=1);

/**
 * Regenerate `public/assets/img/logo-africa-gates-alpha.png` from the shipped lockup.
 *
 * Run from the repository root:   php scripts/gen-mark-alpha.php
 *
 * ── WHY THE FILE IS DERIVED AND NOT DRAWN ────────────────────────────────────
 *
 * The shipped lockup is green on OPAQUE white. The email's masthead sits on the paper
 * ground `#f0f2f2`, which is close enough to white that the opaque file's box is
 * invisible in a screenshot and obvious in an inbox. So the served mark is the same
 * artwork with its paper turned to alpha.
 *
 * This exists as a script rather than as something somebody did once by hand, because a
 * derived asset nobody can regenerate is a dead end the first time the lockup changes —
 * and the recipe below is not one anybody would guess.
 *
 * ── THE RECIPE, AND WHY EACH STEP IS THERE ───────────────────────────────────
 *
 * 1. COVERAGE FROM RED. The artwork is exactly two colours, #FFFFFF and #006634, so every
 *    pixel is a blend of that pair. Red has the widest spread (255 down to 0) and so
 *    recovers the blend ratio with the least quantisation error. Keying out the white
 *    instead — the obvious thing to reach for — leaves a white halo on every antialiased
 *    edge, and this mark is a hairline coastline: it is almost entirely edge.
 *
 * 2. BOX DOWNSAMPLE TO THE SERVED SIZE. Serving the 640px master and letting the client
 *    reduce it 8x is what made the mark look dirty: each output pixel is an unweighted
 *    average of a source region here, which is what a box filter is for, and the client
 *    is then only doing 2x -> 1x or nothing at all.
 *
 * 3. THE COVERAGE LIFT. A ~2.5px stroke reduced 4.4x covers a third of an output pixel,
 *    so it arrives at alpha 0.33 and reads as a grey wash rather than as a line. The
 *    curve `a ** GAMMA` lifts thin coverage and leaves solid ink alone — 1.0 stays 1.0 —
 *    which restores the stroke's WEIGHT without thickening the wordmark beside it.
 *    Without it the coastline is visibly paler than the master at the same size.
 *
 * The served raster is 2x the CSS box in `OtpService::brandWrap()`, so it is 1:1 on a
 * phone and a clean 2:1 everywhere else. Change one and change the other.
 */

const MASTER = __DIR__ . '/../public/assets/img/logo-africa-gates.png';
const OUT    = __DIR__ . '/../public/assets/img/logo-africa-gates-alpha.png';

/** 2x the 72px CSS box the masthead declares. */
const OUT_W = 144;

/** The ink, as the site publishes it. */
const INK = [0, 102, 52];

/** Below 1.0 lifts thin coverage. 0.62 matches the master's stroke weight by eye at 1x. */
const GAMMA = 0.62;

$src = @imagecreatefrompng(MASTER);
if ($src === false) {
    fwrite(STDERR, "cannot read " . MASTER . "\n");
    exit(1);
}

$sw = imagesx($src);
$sh = imagesy($src);
$outW = OUT_W;
$outH = (int) round($outW * $sh / $sw);

// 1 · coverage
$cov = [];
for ($y = 0; $y < $sh; $y++) {
    for ($x = 0; $x < $sw; $x++) {
        $cov[$y][$x] = (255 - ((imagecolorat($src, $x, $y) >> 16) & 255)) / 255;
    }
}
imagedestroy($src);

$out = imagecreatetruecolor($outW, $outH);
imagealphablending($out, false);
imagesavealpha($out, true);

for ($oy = 0; $oy < $outH; $oy++) {
    $y0 = (int) floor($oy * $sh / $outH);
    $y1 = max($y0 + 1, (int) floor(($oy + 1) * $sh / $outH));

    for ($ox = 0; $ox < $outW; $ox++) {
        $x0 = (int) floor($ox * $sw / $outW);
        $x1 = max($x0 + 1, (int) floor(($ox + 1) * $sw / $outW));

        // 2 · box average
        $sum = 0.0;
        $n   = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $sum += $cov[$y][$x];
                $n++;
            }
        }
        $a = $n > 0 ? $sum / $n : 0.0;

        // 3 · lift
        $a = min(1.0, $a ** GAMMA);

        imagesetpixel($out, $ox, $oy, imagecolorallocatealpha(
            $out, INK[0], INK[1], INK[2], (int) round(127 * (1 - $a))
        ));
    }
}

imagepng($out, OUT);
imagedestroy($out);

printf("wrote %s at %dx%d\n", OUT, $outW, $outH);
