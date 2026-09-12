<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Enough of the TrueType format to embed a font in a PDF, and no more.
 *
 * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────────
 *
 * A PDF may use fourteen "standard" fonts with no embedding, and every one of them is
 * WinAnsi — Latin-1 and nothing else. Africa GATES prints tickets carrying names like
 * Ọlásùnkànmí Ṣẹ́gun and prices in ₦. Under WinAnsi those are not approximated, they are
 * DROPPED: the ticket comes out reading "Olsnknm egun" and the price loses its currency.
 *
 * So the font has to be embedded, which means reading the file, which means this class.
 *
 * ── AND WHY IT SUBSETS SPARSELY ──────────────────────────────────────────────
 *
 * A full DejaVu Sans is 760KB. Six tickets on one sheet use about eighty glyphs. Embedding
 * the whole file per document would make a one-ticket download bigger than most of the
 * photographs on the site.
 *
 * The subset here keeps GLYPH IDS EXACTLY WHERE THEY ARE and simply empties the outlines
 * nobody asked for. That is the whole trick, and it is what makes this ~150 lines instead of
 * ~1500: there is no renumbering, so `loca`, `hmtx` and every composite glyph's component
 * references all stay valid without being rewritten. The tables that remain are mostly
 * repeated bytes, which Flate removes on the way into the PDF — a real subset of DejaVu comes
 * out around 12KB compressed.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ────────────────────────────────────────────
 *
 * No kerning, no ligatures, no shaping, no bidi. A ticket is names, dates and a code set in
 * short runs; GPOS kerning would be a genuine improvement and a much larger class, and its
 * absence costs a fraction of a millimetre per pair. Vertical writing, colour fonts and
 * variable-font axes are absent for the same reason: nothing here needs them.
 */
final class TrueType
{
    /** @var array<string,array{0:int,1:int}> tag => [offset, length] */
    private array $tables = [];

    private int $unitsPerEm    = 1000;
    private int $numGlyphs     = 0;
    private int $indexToLoc    = 0;
    private int $numberOfHMetrics = 0;
    /** @var array<int,int> */
    private array $loca = [];
    /** @var array<int,int> codepoint => glyph id, filled lazily */
    private array $cmapCache = [];
    /** @var array{0:int,1:int,2:int,3:int} */
    private array $bbox = [0, 0, 1000, 1000];
    private int $ascender  = 800;
    private int $descender = -200;
    private string $psName = 'Embedded';

    private function __construct(private readonly string $data) {}

    public static function load(string $path): ?self
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 12) return null;

        $f = new self($raw);
        return $f->parse() ? $f : null;
    }

    // ─────────────────────────────── parsing ────────────────────────────────

    private function u8(int $o): int  { return ord($this->data[$o]); }
    private function u16(int $o): int { return (ord($this->data[$o]) << 8) | ord($this->data[$o + 1]); }
    private function s16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $o): int
    {
        return (ord($this->data[$o]) << 24) | (ord($this->data[$o + 1]) << 16)
             | (ord($this->data[$o + 2]) << 8) | ord($this->data[$o + 3]);
    }

    private function parse(): bool
    {
        $tag = substr($this->data, 0, 4);
        // 'ttcf' is a collection; refusing is better than reading the wrong face out of one.
        if (!in_array($tag, ["\x00\x01\x00\x00", 'true', 'OTTO'], true)) return false;
        // OTTO means CFF outlines, which this cannot subset — glyf/loca do not exist there.
        if ($tag === 'OTTO') return false;

        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            if ($o + 16 > strlen($this->data)) return false;
            $this->tables[substr($this->data, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }

        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $need) {
            if (!isset($this->tables[$need])) return false;
        }

        [$head] = $this->tables['head'];
        $this->unitsPerEm = $this->u16($head + 18) ?: 1000;
        $this->bbox = [$this->s16($head + 36), $this->s16($head + 38),
                       $this->s16($head + 40), $this->s16($head + 42)];
        $this->indexToLoc = $this->s16($head + 50);

        [$hhea] = $this->tables['hhea'];
        $this->ascender        = $this->s16($hhea + 4);
        $this->descender       = $this->s16($hhea + 6);
        $this->numberOfHMetrics = $this->u16($hhea + 34);

        [$maxp] = $this->tables['maxp'];
        $this->numGlyphs = $this->u16($maxp + 4);

        [$loca, $locaLen] = $this->tables['loca'];
        $this->loca = [];
        if ($this->indexToLoc === 0) {
            for ($i = 0; $i <= $this->numGlyphs && $loca + 2 * $i + 1 < $loca + $locaLen + 2; $i++) {
                $this->loca[$i] = $this->u16($loca + 2 * $i) * 2;
            }
        } else {
            for ($i = 0; $i <= $this->numGlyphs && $loca + 4 * $i + 3 < $loca + $locaLen + 4; $i++) {
                $this->loca[$i] = $this->u32($loca + 4 * $i);
            }
        }

        $this->psName = $this->readPsName() ?: 'Embedded';
        return true;
    }

    /** The PostScript name, which is what the PDF font dictionary is keyed on. */
    private function readPsName(): string
    {
        if (!isset($this->tables['name'])) return '';
        [$base] = $this->tables['name'];
        $count   = $this->u16($base + 2);
        $storage = $base + $this->u16($base + 4);

        for ($i = 0; $i < $count; $i++) {
            $r = $base + 6 + 12 * $i;
            if ($this->u16($r + 6) !== 6) continue;             // nameID 6 = PostScript name
            $len = $this->u16($r + 8);
            $off = $this->u16($r + 10);
            $s   = substr($this->data, $storage + $off, $len);
            // Platform 3 stores UTF-16BE; strip the high bytes rather than pull in iconv.
            if ($this->u16($r) === 3) $s = (string) preg_replace('/\x00/', '', $s);
            $s = (string) preg_replace('/[^A-Za-z0-9\-]/', '', $s);
            if ($s !== '') return $s;
        }
        return '';
    }

    // ─────────────────────────────── the cmap ───────────────────────────────

    /**
     * Glyph id for a Unicode codepoint, or 0 when the font has no such glyph.
     *
     * Zero is the answer callers must act on rather than draw: glyph 0 is `.notdef`, which
     * prints as an empty box. A ticket with a box where a name should be is worse than one
     * that fell back to another face, which is why Pdf splits runs on this.
     */
    public function gid(int $cp): int
    {
        if (isset($this->cmapCache[$cp])) return $this->cmapCache[$cp];

        [$base] = $this->tables['cmap'];
        $n   = $this->u16($base + 2);
        $f4  = 0;
        $f12 = 0;

        for ($i = 0; $i < $n; $i++) {
            $rec = $base + 4 + 8 * $i;
            $pid = $this->u16($rec);
            $eid = $this->u16($rec + 2);
            $sub = $base + $this->u32($rec + 4);
            $fmt = $this->u16($sub);
            // Unicode subtables only. A (1,0) Mac Roman table would map the wrong glyphs
            // for everything above ASCII, which is precisely the range that matters here.
            $unicode = ($pid === 3 && in_array($eid, [1, 10], true)) || $pid === 0;
            if (!$unicode) continue;
            if ($fmt === 12) $f12 = $sub;
            if ($fmt === 4 && $f4 === 0) $f4 = $sub;
        }

        // Format 12 first: it is the one that reaches beyond the basic plane.
        $g = $f12 > 0 ? $this->lookup12($f12, $cp) : 0;
        if ($g === 0 && $f4 > 0 && $cp <= 0xFFFF) $g = $this->lookup4($f4, $cp);

        return $this->cmapCache[$cp] = $g;
    }

    private function lookup4(int $t, int $cp): int
    {
        $segX2 = $this->u16($t + 6);
        $seg   = intdiv($segX2, 2);
        $ends    = $t + 14;
        $starts  = $ends + $segX2 + 2;
        $deltas  = $starts + $segX2;
        $ranges  = $deltas + $segX2;

        for ($i = 0; $i < $seg; $i++) {
            if ($this->u16($ends + 2 * $i) < $cp) continue;
            $start = $this->u16($starts + 2 * $i);
            if ($start > $cp) return 0;

            $ro = $this->u16($ranges + 2 * $i);
            if ($ro === 0) {
                return ($cp + $this->s16($deltas + 2 * $i)) & 0xFFFF;
            }
            $gi = $ranges + 2 * $i + $ro + 2 * ($cp - $start);
            if ($gi + 1 >= strlen($this->data)) return 0;
            $g = $this->u16($gi);
            return $g === 0 ? 0 : (($g + $this->s16($deltas + 2 * $i)) & 0xFFFF);
        }
        return 0;
    }

    private function lookup12(int $t, int $cp): int
    {
        $n = $this->u32($t + 12);
        $lo = 0; $hi = $n - 1;
        while ($lo <= $hi) {                                   // the groups are sorted
            $mid = intdiv($lo + $hi, 2);
            $g   = $t + 16 + 12 * $mid;
            $s   = $this->u32($g);
            $e   = $this->u32($g + 4);
            if ($cp < $s)      $hi = $mid - 1;
            elseif ($cp > $e)  $lo = $mid + 1;
            else               return $this->u32($g + 8) + ($cp - $s);
        }
        return 0;
    }

    // ────────────────────────────── metrics ─────────────────────────────────

    /** Advance width in font units. */
    public function advance(int $gid): int
    {
        [$hmtx] = $this->tables['hmtx'];
        $n = max(1, $this->numberOfHMetrics);
        // Monospaced and semi-monospaced fonts store one metric for a long tail of glyphs;
        // past numberOfHMetrics every glyph shares the last advance.
        $i = $gid < $n ? $gid : $n - 1;
        return $this->u16($hmtx + 4 * $i);
    }

    /** Advance in 1/1000 em, which is the unit a PDF font dictionary speaks. */
    public function width(int $gid): int
    {
        return (int) round($this->advance($gid) * 1000 / $this->unitsPerEm);
    }

    public function unitsPerEm(): int { return $this->unitsPerEm; }
    public function numGlyphs(): int  { return $this->numGlyphs; }
    public function name(): string    { return $this->psName; }
    /** @return array{0:int,1:int,2:int,3:int} in 1/1000 em */
    public function bboxThousandths(): array
    {
        $s = 1000 / $this->unitsPerEm;
        return [(int) round($this->bbox[0] * $s), (int) round($this->bbox[1] * $s),
                (int) round($this->bbox[2] * $s), (int) round($this->bbox[3] * $s)];
    }
    public function ascenderThousandths(): int  { return (int) round($this->ascender * 1000 / $this->unitsPerEm); }
    public function descenderThousandths(): int { return (int) round($this->descender * 1000 / $this->unitsPerEm); }

    // ────────────────────────────── subsetting ──────────────────────────────

    /**
     * A font file carrying only the requested outlines, with every glyph id unchanged.
     *
     * Composite glyphs pull their components in — an "ẹ" is usually "e" plus a dot below,
     * and shipping the composite without its parts is a glyph that renders as nothing.
     *
     * @param array<int,int> $gids
     */
    public function subset(array $gids, bool $standalone = false): string
    {
        $keep = [0 => true];                                   // .notdef always
        foreach ($gids as $g) {
            if ($g > 0 && $g < $this->numGlyphs) $keep[$g] = true;
        }
        foreach (array_keys($keep) as $g) $this->pullComponents($g, $keep);
        ksort($keep);

        [$glyfOff] = $this->tables['glyf'];

        // ── glyf and loca, rebuilt together ─────────────────────────────────
        $glyf = '';
        $loca = [];
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca[$g] = strlen($glyf);
            if (!isset($keep[$g])) continue;                   // emptied: loca[g] == loca[g+1]

            $from = $this->loca[$g] ?? 0;
            $to   = $this->loca[$g + 1] ?? $from;
            if ($to <= $from) continue;

            $bytes = substr($this->data, $glyfOff + $from, $to - $from);
            // Glyph records are 4-byte aligned in a well-formed font; padding here keeps
            // long-format loca offsets legal and costs at most three bytes a glyph.
            $glyf .= $bytes . str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
        }
        $loca[$this->numGlyphs] = strlen($glyf);

        // Long loca throughout. Short format halves the size and cannot address a glyf
        // table above 128KB, and choosing per font is a branch with no upside here — the
        // repeated bytes compress away in the PDF stream anyway.
        $locaBin = '';
        foreach ($loca as $o) $locaBin .= pack('N', $o);

        // ── hmtx, full length, zeroed for glyphs nobody asked for ───────────
        $hmtx = '';
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $hmtx .= pack('nn', isset($keep[$g]) ? $this->advance($g) : 0, 0);
        }

        $head = $this->tableBytes('head');
        // indexToLocFormat, at head+50, must now say "long".
        $head = substr($head, 0, 50) . pack('n', 1) . substr($head, 52);

        $hhea = $this->tableBytes('hhea');
        // numberOfHMetrics, at hhea+34, now covers every glyph.
        $hhea = substr($hhea, 0, 34) . pack('n', $this->numGlyphs) . substr($hhea, 36);

        $out = [
            'head' => $head,
            'hhea' => $hhea,
            'maxp' => $this->tableBytes('maxp'),
            'hmtx' => $hmtx,
            'loca' => $locaBin,
            'glyf' => $glyf,
        ];
        // Hinting instructions, when the font has them. Dropped `cmap`, `name` and `post`
        // on purpose: a CIDFontType2 with /CIDToGIDMap /Identity is addressed by glyph id,
        // so the reader never consults the embedded cmap, and the two name tables are pure
        // weight. `cvt `/`fpgm`/`prep` are kept because dropping them leaves the outlines
        // unhinted at ticket sizes, where hinting is what keeps 7pt labels crisp.
        foreach (['cvt ', 'fpgm', 'prep'] as $t) {
            if (isset($this->tables[$t])) $out[$t] = $this->tableBytes($t);
        }

        // ── A STANDALONE SUBSET KEEPS ITS CMAP ──────────────────────────────
        //
        // Used when the output is a font FILE rather than a PDF stream — the trimmed faces
        // committed under resources/fonts are produced this way, so that this class can turn
        // round and read them again.
        //
        // The original cmap is kept verbatim and stays correct precisely BECAUSE the subset
        // is sparse: every glyph id it points at still exists. A character that was dropped
        // now resolves to an empty outline rather than a wrong one, which is the same thing
        // the font would do for a character it never had.
        if ($standalone) {
            foreach (['cmap', 'name', 'post', 'OS/2'] as $t) {
                if (isset($this->tables[$t])) $out[$t] = $this->tableBytes($t);
            }
        }

        return self::assemble($out);
    }

    /** @param array<int,bool> $keep */
    private function pullComponents(int $gid, array &$keep, int $depth = 0): void
    {
        if ($depth > 5) return;                                 // malformed recursion guard
        $from = $this->loca[$gid] ?? 0;
        $to   = $this->loca[$gid + 1] ?? $from;
        if ($to - $from < 10) return;

        [$glyfOff] = $this->tables['glyf'];
        $p = $glyfOff + $from;
        if ($this->s16($p) >= 0) return;                        // simple glyph, no components

        $p += 10;
        while (true) {
            $flags = $this->u16($p);
            $comp  = $this->u16($p + 2);
            if (!isset($keep[$comp])) {
                $keep[$comp] = true;
                $this->pullComponents($comp, $keep, $depth + 1);
            }
            $p += 4;
            $p += ($flags & 0x0001) ? 4 : 2;                    // ARG_1_AND_2_ARE_WORDS
            if     ($flags & 0x0008) $p += 2;                   // WE_HAVE_A_SCALE
            elseif ($flags & 0x0040) $p += 4;                   // X_AND_Y_SCALE
            elseif ($flags & 0x0080) $p += 8;                   // TWO_BY_TWO
            if (!($flags & 0x0020)) break;                      // MORE_COMPONENTS
            if ($p >= $glyfOff + $to) break;
        }
    }

    private function tableBytes(string $tag): string
    {
        [$o, $l] = $this->tables[$tag];
        return substr($this->data, $o, $l);
    }

    /** @param array<string,string> $tables */
    private static function assemble(array $tables): string
    {
        ksort($tables);                                          // the directory must be sorted
        $n        = count($tables);
        $searchR  = 1;
        $entrySel = 0;
        while ($searchR * 2 <= $n) { $searchR *= 2; $entrySel++; }
        $searchR *= 16;

        $head = pack('Nnnnn', 0x00010000, $n, $searchR, $entrySel, $n * 16 - $searchR);
        $dir  = '';
        $body = '';
        $offset = 12 + $n * 16;

        foreach ($tables as $tag => $data) {
            $pad = str_repeat("\0", (4 - strlen($data) % 4) % 4);
            $dir .= $tag . pack('NNN', self::checksum($data . $pad), $offset, strlen($data));
            $body .= $data . $pad;
            $offset += strlen($data) + strlen($pad);
        }
        return $head . $dir . $body;
    }

    private static function checksum(string $s): int
    {
        $sum = 0;
        foreach (unpack('N*', $s) ?: [] as $w) {
            $sum = ($sum + $w) & 0xFFFFFFFF;
        }
        return $sum;
    }
}
