<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * A QR code, in pure PHP, deliberately narrow.
 *
 * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────────
 *
 * A door queue moves at the speed of the slowest thing in it, and until now that was an
 * organiser typing nine characters off an attendee's phone screen into a text box. Four seconds
 * each does not sound like anything; at three hundred people it is twenty minutes of queue that
 * exists for no reason.
 *
 * There is no QR library in this project's dependencies and adding one to a deployment that
 * ships by zip upload — no composer on the host — means vendoring a package by hand. So this is
 * written here, and it is written SMALL on purpose.
 *
 * ── THE NARROWNESS IS THE SAFETY ─────────────────────────────────────────────
 *
 * Version 1, error-correction level Q, alphanumeric mode. Nothing else. That is a 21×21 grid
 * holding up to 16 characters from `0-9 A-Z $ % * + - . / : space`, and it happens to be
 * exactly what a ticket code is: {@see \AfricaGates\Services\EventTicketService::freshCode()}
 * produces nine characters from an alphabet that is a subset of the above.
 *
 * Restricting to one version removes the two hardest parts of a general encoder — block
 * interleaving across multiple error-correction blocks, and the version-information area — and
 * with them the two places a subtle bug would produce a code that scans as the wrong string
 * rather than not scanning at all. {@see encode()} returns null for anything that does not fit
 * rather than guessing, and every caller shows the code as text beside the image, so a refusal
 * degrades to exactly the door process that worked before.
 *
 * Level Q, not the usual M: this is read off a phone screen at arm's length in a room lit for a
 * ceremony. Q recovers from 25% damage against M's 15%, and at nine characters there is room to
 * spare.
 *
 * ── HOW IT WAS CHECKED ───────────────────────────────────────────────────────
 *
 * Written from the specification and then verified by DECODING it: the test suite renders codes
 * and reads them back with Chromium's BarcodeDetector, so what is asserted is that a real
 * scanner recovers the exact string. An encoder verified only against its own expectations is
 * an encoder that agrees with itself.
 */
final class Qr
{
    /** Modules per side. Version 1 only — see the class note. */
    public const SIZE = 21;

    /** Data codewords in a version-1 level-Q symbol. */
    private const DATA_CODEWORDS = 13;

    /** Error-correction codewords in a version-1 level-Q symbol. */
    private const EC_CODEWORDS = 13;

    /** The most characters that fit. Version 1, level Q, alphanumeric mode. */
    public const MAX_CHARS = 16;

    /** Alphanumeric mode's alphabet, in its specified order — index IS the value. */
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * The modules of a QR code, or null when the payload does not fit.
     *
     * @return list<list<bool>>|null true = dark
     */
    public static function encode(string $text): ?array
    {
        $text = strtoupper(trim($text));
        if ($text === '' || strlen($text) > self::MAX_CHARS) return null;

        // Every character must exist in the alphanumeric alphabet. Refused rather than
        // substituted: a code that scans as a DIFFERENT string is worse than one that does not
        // scan, because somebody acts on it.
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            if (strpos(self::ALPHABET, $text[$i]) === false) return null;
        }

        $bits = self::bitstream($text);
        if ($bits === null) return null;

        $data     = self::codewords($bits);
        $withEc   = array_merge($data, self::ecCodewords($data));

        // One block at version 1, so the codewords go in as they are — no interleaving. That
        // simplification is half the reason this class is version-1 only.
        $stream = [];
        foreach ($withEc as $byte) {
            for ($b = 7; $b >= 0; $b--) $stream[] = ($byte >> $b) & 1;
        }

        // Every mask is a valid symbol; the penalty rules only choose the most readable one.
        $best = null; $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $m = self::draw($stream, $mask);
            $score = self::penalty($m);
            if ($score < $bestScore) { $bestScore = $score; $best = $m; }
        }

        return $best;
    }

    // ══ 1b. BYTE MODE, VERSIONS 1–6 ══════════════════════════════════════════

    /**
     * A QR holding arbitrary bytes — a URL, in practice.
     *
     * ── WHY THIS IS SEPARATE FROM encode() ───────────────────────────────────
     *
     * {@see encode()} is for a TICKET CODE and folds case, because a ticket code is
     * case-insensitive and somebody reading one off a screen may type it either way. A URL is
     * not: `/r/Ab12` and `/r/AB12` are two different paths, and uppercasing one produces a
     * code that scans perfectly as a link to nothing. Two payloads with genuinely different
     * rules, so two entry points, each refusing what it cannot carry.
     *
     * It also does not share encode()'s 16-character ceiling. Byte mode at version 6 level Q
     * holds {@see MAX_BYTES} bytes, which is every URL this platform mints with room over.
     *
     * ── AND WHY IT STOPS AT VERSION 6 ────────────────────────────────────────
     *
     * Version 7 adds the version-information area — eighteen BCH-coded bits in two more
     * places a scanner reads BEFORE the data. The class note explains why this file was
     * version-1 only to begin with: block interleaving and version information are the two
     * places a subtle bug produces a symbol that scans as the WRONG string rather than not
     * scanning at all. Interleaving is now implemented, because a URL cannot fit without it
     * and it is verified by cross-check. Version information buys capacity nothing here needs,
     * so it is not implemented, and asking for more than version 6 returns null.
     *
     * @return list<list<bool>>|null true = dark
     */
    public static function encodeBytes(string $text): ?array
    {
        $text = trim($text);
        $len  = strlen($text);
        if ($len === 0 || $len > self::MAX_BYTES) return null;

        $version = self::versionForBytes($len);
        if ($version === null) return null;

        $spec = self::SPEC[$version];
        $size = 17 + 4 * $version;

        // ── the bit stream ──────────────────────────────────────────────────
        $bits = [];
        // Mode indicator: 0100 = byte.
        self::push($bits, 0b0100, 4);
        // Character count. Eight bits for byte mode at versions 1–9 — the width depends on
        // BOTH the mode and the version, and getting it wrong shifts every following bit.
        self::push($bits, $len, 8);
        for ($i = 0; $i < $len; $i++) self::push($bits, ord($text[$i]), 8);

        $capacity = $spec['data'] * 8;
        if (count($bits) > $capacity) return null;

        // Terminator, then pad to a byte boundary, then the specified pad codewords.
        for ($i = 0; $i < 4 && count($bits) < $capacity; $i++) $bits[] = 0;
        while (count($bits) % 8 !== 0) $bits[] = 0;

        $bytes = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) $byte = ($byte << 1) | $bits[$i + $b];
            $bytes[] = $byte;
        }
        $pad = [0xEC, 0x11];
        $k = 0;
        while (count($bytes) < $spec['data']) $bytes[] = $pad[$k++ % 2];

        // ── blocks, and the interleave ──────────────────────────────────────
        //
        // THE part this class avoided until now. Data is split into blocks of two possible
        // sizes, each gets its own error correction, and then the codewords are woven
        // together: every block's first data codeword, then every block's second, and so on,
        // followed by the same pass over the error-correction codewords. A short block
        // contributes nothing to the final data round, which is the step that is easy to get
        // wrong and produces a symbol that decodes as shifted rubbish.
        $blocks = [];
        $at = 0;
        foreach ($spec['blocks'] as [$count, $perBlock]) {
            for ($b = 0; $b < $count; $b++) {
                $blocks[] = array_slice($bytes, $at, $perBlock);
                $at += $perBlock;
            }
        }

        $ecBlocks = [];
        foreach ($blocks as $block) $ecBlocks[] = self::ecFor($block, $spec['ec']);

        $stream = [];
        $longest = 0;
        foreach ($blocks as $b) $longest = max($longest, count($b));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[$i])) self::push($stream, $b[$i], 8);
            }
        }
        // Every EC block is the same length, so this pass needs no such guard.
        for ($i = 0; $i < $spec['ec']; $i++) {
            foreach ($ecBlocks as $b) self::push($stream, $b[$i], 8);
        }

        // Remainder bits: seven at versions 2–6, none at version 1. The symbol has that many
        // data modules more than the codewords fill, and they are left zero. Omitting them
        // does not shift anything — the zigzag simply runs out — but they are stated here so
        // the arithmetic is checkable against the table.
        for ($i = 0; $i < $spec['remainder']; $i++) $stream[] = 0;

        $best = null; $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $m = self::draw($stream, $mask, $size);
            $score = self::penalty($m);
            if ($score < $bestScore) { $bestScore = $score; $best = $m; }
        }

        return $best;
    }

    /**
     * Versions 1–6 at level Q: how the codewords are grouped.
     *
     * `data` is the total data codewords; `blocks` is [count, codewords each] pairs in the
     * specified order; `ec` is error-correction codewords PER BLOCK; `remainder` is the
     * trailing zero bits. Every row satisfies `sum(count × each) === data` and
     * `data + blocks × ec === total`, which {@see \Tests\Unit\QrBytesTest} asserts rather
     * than trusting that the table was typed correctly.
     */
    private const SPEC = [
        1 => ['data' => 13,  'blocks' => [[1, 13]],           'ec' => 13, 'remainder' => 0, 'bytes' => 11],
        2 => ['data' => 22,  'blocks' => [[1, 22]],           'ec' => 22, 'remainder' => 7, 'bytes' => 20],
        3 => ['data' => 34,  'blocks' => [[2, 17]],           'ec' => 18, 'remainder' => 7, 'bytes' => 32],
        4 => ['data' => 48,  'blocks' => [[2, 24]],           'ec' => 26, 'remainder' => 7, 'bytes' => 46],
        5 => ['data' => 62,  'blocks' => [[2, 15], [2, 16]],  'ec' => 18, 'remainder' => 7, 'bytes' => 60],
        6 => ['data' => 76,  'blocks' => [[4, 19]],           'ec' => 24, 'remainder' => 7, 'bytes' => 74],
    ];

    /** The most bytes a version-6 level-Q symbol holds in byte mode. */
    public const MAX_BYTES = 74;

    /** The smallest version that holds $len bytes, or null. */
    private static function versionForBytes(int $len): ?int
    {
        foreach (self::SPEC as $version => $spec) {
            if ($len <= $spec['bytes']) return $version;
        }
        return null;
    }

    /**
     * Error correction for one block of any length.
     *
     * {@see ecCodewords()} is the version-1 form and is left exactly as it was: it is covered
     * by golden vectors that a real decoder verified, and re-pointing it at this would be a
     * behaviour change to the ticket QR in exchange for nothing.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function ecFor(array $data, int $count): array
    {
        $gen = self::generator($count);
        $rem = array_fill(0, $count, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            for ($i = 0; $i < $count; $i++) $rem[$i] ^= self::mul($gen[$i], $factor);
        }

        return $rem;
    }

    /**
     * An SVG for arbitrary bytes. {@see svg()} with {@see encodeBytes()} behind it.
     *
     * The quiet zone is four modules on every side, as specified — and it is the measurement
     * the flier handoff singled out, because an undersized quiet zone still LOOKS correct and
     * stops scanning once a messaging app has recompressed the image.
     */
    public static function svgBytes(string $text, int $scale = 6, string $label = ''): ?string
    {
        $m = self::encodeBytes($text);
        return $m === null ? null : self::render($m, $scale, $label);
    }

    // ══ 1. the data bits ═════════════════════════════════════════════════════

    /** @return list<int>|null the encoded bit sequence, before padding */
    private static function bitstream(string $text): ?array
    {
        $bits = [];

        // Mode indicator: 0010 = alphanumeric.
        self::push($bits, 0b0010, 4);
        // Character count. Nine bits for alphanumeric at versions 1–9.
        self::push($bits, strlen($text), 9);

        // Pairs pack into 11 bits as 45 * first + second. A trailing odd character takes 6.
        for ($i = 0, $n = strlen($text); $i < $n; $i += 2) {
            $a = strpos(self::ALPHABET, $text[$i]);
            if ($i + 1 < $n) {
                $b = strpos(self::ALPHABET, $text[$i + 1]);
                self::push($bits, 45 * (int) $a + (int) $b, 11);
            } else {
                self::push($bits, (int) $a, 6);
            }
        }

        // Refuse rather than truncate. A silently shortened ticket code would scan cleanly as
        // the wrong ticket.
        if (count($bits) > self::DATA_CODEWORDS * 8) return null;

        return $bits;
    }

    /** @param list<int> $bits @return list<int> the 13 data codewords, padded */
    private static function codewords(array $bits): array
    {
        $capacity = self::DATA_CODEWORDS * 8;

        // Terminator: up to four zero bits, but never past the capacity.
        for ($i = 0; $i < 4 && count($bits) < $capacity; $i++) $bits[] = 0;
        // Pad to a byte boundary.
        while (count($bits) % 8 !== 0) $bits[] = 0;

        $bytes = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) $byte = ($byte << 1) | $bits[$i + $b];
            $bytes[] = $byte;
        }

        // The specified pad codewords, alternating, until the block is full.
        $pad = [0xEC, 0x11];
        $k = 0;
        while (count($bytes) < self::DATA_CODEWORDS) $bytes[] = $pad[$k++ % 2];

        return $bytes;
    }

    private static function push(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
    }

    // ══ 2. Reed-Solomon over GF(256) ═════════════════════════════════════════

    /** @return array{0:list<int>,1:list<int>} [exp, log] tables for GF(256), primitive 0x11D */
    private static function tables(): array
    {
        static $exp = null, $log = null;
        if ($exp !== null) return [$exp, $log];

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            // The QR standard's primitive polynomial, x^8 + x^4 + x^3 + x^2 + 1.
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];

        return [$exp, $log];
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        [$exp, $log] = self::tables();
        return $exp[$log[$a] + $log[$b]];
    }

    /**
     * The error-correction codewords for one block.
     *
     * Straight polynomial long division of the data (shifted up by the number of EC codewords)
     * by the generator polynomial. The remainder IS the error correction.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function ecCodewords(array $data): array
    {
        $gen = self::generator(self::EC_CODEWORDS);
        $rem = array_fill(0, self::EC_CODEWORDS, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            for ($i = 0; $i < self::EC_CODEWORDS; $i++) {
                $rem[$i] ^= self::mul($gen[$i], $factor);
            }
        }

        return $rem;
    }

    /**
     * The generator polynomial for n error-correction codewords: ∏ (x − α^i), i = 0…n−1.
     *
     * Returned without its leading 1, which is what the division above expects.
     *
     * @return list<int>
     */
    private static function generator(int $n): array
    {
        [$exp] = self::tables();
        $poly = [1];
        for ($i = 0; $i < $n; $i++) {
            // Multiply by (x − α^i).
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coeff) {
                $next[$j]     ^= self::mul($coeff, $exp[$i]);
                $next[$j + 1] ^= $coeff;
            }
            $poly = $next;
        }
        // Highest-degree term first, minus the leading coefficient.
        return array_slice(array_reverse($poly), 1);
    }

    // ══ 3. the matrix ════════════════════════════════════════════════════════

    /**
     * Lay out one candidate symbol with a given mask.
     *
     * @param list<int> $stream
     * @return list<list<bool>>
     */
    private static function draw(array $stream, int $mask, int $size = self::SIZE): array
    {
        $m    = array_fill(0, $size, array_fill(0, $size, false));
        // Which modules are function patterns and must not receive data.
        $fixed = array_fill(0, $size, array_fill(0, $size, false));

        // ── finder patterns, with their separators ───────────────────────────
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $r0 + $r; $cc = $c0 + $c;
                    if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) continue;
                    $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                           || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                    $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $m[$rr][$cc] = $inRing || $inCore;
                    $fixed[$rr][$cc] = true;
                }
            }
        }

        // ── alignment pattern, versions 2 and up ─────────────────────────────
        //
        // Versions 2–6 have exactly ONE, at (size-7, size-7): the specified centres for those
        // versions are [6, size-7], and three of the four combinations land on a finder and
        // are skipped. That is the whole reason this class stops at version 6 — version 7
        // introduces a third centre AND the version-information area, which is the second of
        // the two hard parts the class note describes.
        if ($size > self::SIZE) {
            $ac = $size - 7;
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $m[$ac + $r][$ac + $c] = max(abs($r), abs($c)) !== 1;
                    $fixed[$ac + $r][$ac + $c] = true;
                }
            }
        }

        // ── timing patterns: row 6 and column 6, alternating from the origin ─
        //
        // AFTER the alignment pattern, and it matters: the alignment pattern at (size-7,size-7)
        // sits on neither timing line at these versions, but writing timing first and letting
        // alignment overwrite it would be correct only by coincidence. Written second, timing
        // skips what is already fixed.
        for ($i = 8; $i < $size - 8; $i++) {
            if (!$fixed[6][$i]) { $m[6][$i] = $i % 2 === 0;  $fixed[6][$i] = true; }
            if (!$fixed[$i][6]) { $m[$i][6] = $i % 2 === 0;  $fixed[$i][6] = true; }
        }

        // ── the format-information area, reserved before data is placed ──────
        //
        // Reserved first and written last: these modules sit in the middle of the data path,
        // and letting the zigzag consume them would shift every subsequent bit.
        for ($i = 0; $i <= 8; $i++) {
            if (!$fixed[8][$i]) $fixed[8][$i] = true;
            if (!$fixed[$i][8]) $fixed[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $fixed[8][$size - 1 - $i] = true;
            $fixed[$size - 1 - $i][8] = true;
        }

        // ── the data, in two-module columns, zigzagging ──────────────────────
        $i = 0;
        $col = $size - 1;
        $up  = true;
        while ($col > 0) {
            // Column 6 is the vertical timing pattern and is stepped over entirely.
            if ($col === 6) $col--;
            for ($r = 0; $r < $size; $r++) {
                $row = $up ? ($size - 1 - $r) : $r;
                foreach ([$col, $col - 1] as $c) {
                    if ($c < 0 || $fixed[$row][$c]) continue;
                    $bit = $i < count($stream) ? $stream[$i] : 0;
                    $i++;
                    $m[$row][$c] = ($bit ^ (self::maskBit($mask, $row, $c) ? 1 : 0)) === 1;
                }
            }
            $col -= 2;
            $up = !$up;
        }

        self::writeFormat($m, $mask, $size);

        return $m;
    }

    /** True where the mask inverts a module. The eight specified patterns, in order. */
    private static function maskBit(int $mask, int $i, int $j): bool
    {
        return match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => (($i * $j) % 2) + (($i * $j) % 3) === 0,
            6 => ((($i * $j) % 2) + (($i * $j) % 3)) % 2 === 0,
            7 => ((($i + $j) % 2) + (($i * $j) % 3)) % 2 === 0,
            default => false,
        };
    }

    /**
     * The fifteen format bits, twice, plus the one module that is always dark.
     *
     * BCH(15,5) over the five bits of "error-correction level, then mask", masked with 0x5412
     * so an all-zero format is still distinguishable from blank space.
     *
     * @param list<list<bool>> $m
     */
    private static function writeFormat(array &$m, int $mask, int $size = self::SIZE): void
    {
        // Level Q is 0b11. Five bits: two of level, three of mask.
        $data = (0b11 << 3) | $mask;

        $bch = $data << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ($bch & (1 << ($i + 10))) $bch ^= 0x537 << $i;
        }
        $format = (($data << 10) | $bch) ^ 0x5412;

        // ── THE ORDER IS MOST-SIGNIFICANT-BIT FIRST ──────────────────────────
        //
        // Both copies are written from bit 14 down to bit 0, and the cells are listed
        // explicitly rather than derived by arithmetic. The arithmetic version of this had the
        // ordering reversed, which produces a symbol that LOOKS right — correct finders, correct
        // timing, plausible data — and decodes as nothing at all, because a scanner reads the
        // format first and cannot get past it. An explicit list is checkable by eye against the
        // specification; a loop with two off-by-one opportunities is not.
        //
        // Copy one wraps the top-left finder, skipping row 6 and column 6 where the timing
        // patterns run. Copy two is split between the bottom-left and the top-right, so a
        // symbol with one corner damaged still reports its own error-correction level and mask.
        $copies = [
            [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
             [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]],
            [[$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
             [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
             [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
             [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]],
        ];

        foreach ($copies as $cells) {
            foreach ($cells as $i => [$r, $c]) {
                $m[$r][$c] = (($format >> (14 - $i)) & 1) === 1;
            }
        }

        // The dark module: always set, always immediately above copy two's vertical run.
        $m[$size - 8][8] = true;
    }

    // ══ 4. mask selection ════════════════════════════════════════════════════

    /**
     * How bad a mask is, by the four specified penalty rules.
     *
     * None of this affects whether the symbol decodes — every mask produces a valid QR. It
     * affects whether a scanner finds it quickly on a phone screen held at an angle, which at a
     * door is the whole point.
     *
     * @param list<list<bool>> $m
     */
    private static function penalty(array $m): int
    {
        // From the matrix, not a constant: this is scored for versions 2–6 as well now, and a
        // hardcoded 21 would read rows that are not there and score every larger symbol the
        // same — silently choosing an arbitrary mask rather than the most readable one.
        $size = count($m);
        $score = 0;

        // Rule 1 — runs of five or more of one colour, in rows then columns.
        foreach ([true, false] as $byRow) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $prev = $byRow ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    $cur  = $byRow ? $m[$a][$b]     : $m[$b][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) $score += 3 + ($run - 5);
                        $run = 1;
                    }
                }
                if ($run >= 5) $score += 3 + ($run - 5);
            }
        }

        // Rule 2 — every 2×2 block of one colour.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($m[$r][$c + 1] === $v && $m[$r + 1][$c] === $v && $m[$r + 1][$c + 1] === $v) {
                    $score += 3;
                }
            }
        }

        // Rule 3 — the finder-like 1:1:3:1:1 sequence with four light modules beside it, which
        // is what makes a scanner mistake data for a finder pattern.
        $needle = [true, false, true, true, true, false, true];
        foreach ([true, false] as $byRow) {
            for ($a = 0; $a < $size; $a++) {
                for ($b = 0; $b <= $size - 7; $b++) {
                    $hit = true;
                    for ($k = 0; $k < 7; $k++) {
                        $v = $byRow ? $m[$a][$b + $k] : $m[$b + $k][$a];
                        if ($v !== $needle[$k]) { $hit = false; break; }
                    }
                    if (!$hit) continue;

                    $before = true; $after = true;
                    for ($k = 1; $k <= 4; $k++) {
                        $i = $b - $k;
                        if ($i >= 0 && ($byRow ? $m[$a][$i] : $m[$i][$a])) { $before = false; break; }
                    }
                    for ($k = 0; $k < 4; $k++) {
                        $i = $b + 7 + $k;
                        if ($i < $size && ($byRow ? $m[$a][$i] : $m[$i][$a])) { $after = false; break; }
                    }
                    if ($before || $after) $score += 40;
                }
            }
        }

        // Rule 4 — how far the dark proportion strays from half.
        $dark = 0;
        foreach ($m as $row) foreach ($row as $v) if ($v) $dark++;
        $pct = (int) floor($dark * 100 / ($size * $size));
        $score += intdiv(abs($pct - 50), 5) * 10;

        return $score;
    }

    // ══ 5. rendering ═════════════════════════════════════════════════════════

    /**
     * An SVG, or null when the payload does not fit.
     *
     * SVG rather than a raster: it needs no image library, stays sharp on every screen and at
     * every print size, and is small enough to inline — which matters because this platform's
     * content-security policy blocks external resources and a ticket has to work on a phone
     * with no signal at a door.
     *
     * The quiet zone is four modules, as specified. It is not decoration: a scanner uses it to
     * find the symbol's edge, and a QR flush against dark text is a QR that does not read.
     */
    public static function svg(string $text, int $scale = 6, string $label = ''): ?string
    {
        $m = self::encode($text);
        return $m === null ? null : self::render($m, $scale, $label);
    }

    /**
     * One matrix, one SVG. Shared by {@see svg()} and {@see svgBytes()}.
     *
     * The side comes from the matrix and not from {@see SIZE}: byte mode reaches version 6,
     * where the symbol is 41 modules across, and a hardcoded 21 would draw a 41-module code
     * into a 21-module box — every module clipped or overlapping, and nothing about the output
     * announcing which.
     *
     * @param list<list<bool>> $m
     */
    private static function render(array $m, int $scale, string $label): string
    {
        $quiet = 4;
        $side  = (count($m) + $quiet * 2) * $scale;

        // One path for every dark module rather than a rectangle each: a fifth of the bytes,
        // and one paint operation instead of two hundred.
        $path = '';
        foreach ($m as $r => $row) {
            foreach ($row as $c => $on) {
                if (!$on) continue;
                $x = ($c + $quiet) * $scale;
                $y = ($r + $quiet) * $scale;
                $path .= 'M' . $x . ' ' . $y . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
            }
        }

        $title = $label !== ''
            ? '<title>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</title>'
            : '';

        // ── NO shape-rendering="crispEdges" ──────────────────────────────────
        //
        // It looks sharper and it is a trap here. A page displays this at whatever size its
        // layout wants — the ticket scales it to 200px from an intrinsic 174 — and at a
        // fractional scale crispEdges snaps every module edge to a whole device pixel
        // INDEPENDENTLY, so modules come out unequal widths and a decoder loses the grid.
        // Antialiasing keeps every module's centre where it belongs, which is what a scanner
        // measures, and is what a QR on a phone screen looks like anyway.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $side . '" height="' . $side . '"'
             . ' viewBox="0 0 ' . $side . ' ' . $side . '"'
             . ' role="img" aria-label="' . htmlspecialchars($label !== '' ? $label : 'QR code', ENT_QUOTES, 'UTF-8') . '">'
             . $title
             . '<rect width="' . $side . '" height="' . $side . '" fill="#ffffff"/>'
             . '<path d="' . $path . '" fill="#000000"/>'
             . '</svg>';
    }
}
