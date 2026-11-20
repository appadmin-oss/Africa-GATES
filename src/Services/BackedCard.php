<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\AwardCampaign;
use AfricaGates\Support\EditionName;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "I BACKED …" — the card a supporter shares about their own vote (owner, 16 Nov 2026: "make
 * the voter feel good and proud of themselves … to really honour them").
 *
 * The nominee's card says "vote for me". This one is the SUPPORTER's: "I backed Chiamaka Eze",
 * the award and the edition, the community celebrating its own where the host named one, and
 * how many people stand with them — the board's own count (VoterReach), never an estimate.
 * Something to be proud to post, which is also the most persuasive invitation there is.
 *
 * It names NO voter. "I" is whoever shares it, so there is nothing to consent to and nothing a
 * stranger can learn about anybody by opening the URL; it is a public fact about a public
 * nominee, the same as their vote page.
 *
 * 1200×630, the link-preview shape (ResultCard says why). GD from the bundled faces, through
 * FlierRaster's one set of hands; null when GD or a face is missing, so the caller can fall
 * back rather than serve a broken image. Sentence case: no capitals (CLAUDE.md, type).
 */
final class BackedCard
{
    use FlierRaster;

    public const W = 1200;
    public const H = 630;

    /** The facts drawn, or null for a nominee that is not on a live award. @return array<string,mixed>|null */
    public static function facts(int $nomineeId): ?array
    {
        try {
            $n = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
                ->where('n.id', $nomineeId)->where('p.is_active', 1)->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->first(['n.id', 'n.name', 'n.photo_path', 'c.title as category', 'p.id as programme_id', 'p.title as programme', 'y.year', 'y.id as cycle_id']);
        } catch (\Throwable) {
            return null;
        }
        if (!$n) return null;
        $camp = AwardCampaign::forProgramme((int) $n->programme_id);
        return ['name' => (string) $n->name, 'photo' => (string) ($n->photo_path ?? ''), 'category' => (string) $n->category,
                'award' => trim((string) $n->programme . ' ' . EditionName::label(DB::table('gates_award_cycles')->where('id', (int) $n->cycle_id)->first() ?? (object) ['year' => $n->year])),
                'place' => (string) $camp['community'], 'people' => VoterReach::forNominee((int) $n->id)];
    }

    public function png(array $f): ?string
    {
        // The count: why this is not one person's whim. Only said when it is more than them.
        $first = (string) (preg_split('/\s+/', trim($f['name']))[0] ?? $f['name']);
        return $this->draw([
            'photo' => $f['photo'], 'initials_of' => $f['name'],
            // Kicker: the community where the host named one, else the award.
            'kicker' => $f['place'] !== '' ? $f['place'] . ' is celebrating its own' : $f['award'],
            'lead' => 'I backed', 'title' => $f['name'], 'sub' => $f['category'] . ' · ' . $f['award'],
            'foot' => (int) $f['people'] > 1 ? 'One of ' . number_format((int) $f['people']) . ' people standing with ' . $first : 'Standing with ' . $first,
        ]);
    }

    /**
     * The one layout both supporter-side cards share (this one and MilestoneCard): a gold
     * kicker, a small lead line, the title in the display face, a line under it, a gold foot,
     * and the nominee's photo framed on the right (their initials where there is none).
     *
     * @param array{photo:string, initials_of:string, kicker:string, lead:string, title:string, sub:string, foot:string} $s
     */
    public function draw(array $s): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !FlierService::fontsPresent()['ok']) return null;
        $W = self::W; $H = self::H;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $c = static function (string $hex) use ($im): int { [$r, $g, $b] = FlierLayout::rgb($hex); return (int) imagecolorallocate($im, $r, $g, $b); };
        $white = $c(FlierLayout::C_WHITE); $gold = $c(FlierLayout::C_GOLD); $mist = $c(FlierLayout::C_MIST); $muted = $c(FlierLayout::C_MUTED);
        $this->vGradient($im, 0, 0, $W, $H, FlierLayout::rgb(FlierLayout::C_BG_TOP), FlierLayout::rgb(FlierLayout::C_BG_BOTTOM));
        $bold = FlierService::fontPath('bold'); $disp = FlierService::fontPath('display'); $semi = FlierService::fontPath('semibold');

        // The photo, right, framed in gold: the person the card is about.
        $px = 812; $py = 120; $ps = 320;
        $this->roundRect($im, $px - 8, $py - 8, $ps + 16, $ps + 16, 30, $gold);
        $src = $s['photo'] !== '' ? $this->loadPhoto($s['photo']) : null;
        if ($src) { $this->cover($im, $src, $px, $py, $ps, $ps); imagedestroy($src); }
        else {
            $this->roundRect($im, $px, $py, $ps, $ps, 24, $c(FlierLayout::C_PANEL));
            $ini = mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($s['initials_of'])) ?: [], 0, 2))));
            // Centred by measurement: imagettftext() takes a baseline (FlierRaster).
            $this->text($im, $ini, 110, $disp, $gold, $px + ($ps - $this->width($ini, 110, $disp)) / 2, $py + $ps / 2 + 40);
        }

        $x = 80; $cw = 680;
        $this->text($im, $this->clip($s['kicker'], 52), 26, $semi, $gold, $x, 132);
        $this->text($im, $this->clip($s['lead'], 40), 44, $semi, $mist, $x, 226);
        [$size, $lines] = $this->fit($s['title'], $cw, $disp);
        $y = 226 + (int) round($size * 1.18); $last = $y;
        foreach ($lines as $l) { $this->text($im, $l, $size, $disp, $white, $x, $y); $last = $y; $y += (int) round($size * 1.08); }

        // Baselines, not boxes (FlierRaster): the line sits a fixed step under the title's LAST baseline.
        // Trimmed by MEASURED width, not characters: a character budget let a long award name
        // run under the photo, since "Lagos Youth Awards 2026" and "Ada" are not the same width.
        $this->text($im, $this->fitWidth($s['sub'], $cw, 24, $semi), 24, $semi, $mist, $x, $last + 64);
        $this->text($im, $this->fitWidth($s['foot'], $cw, 26, $bold), 26, $bold, $gold, $x, $H - 92);
        $this->text($im, 'Africa GATES · celebrate your own', 20, $semi, $muted, $x, $H - 52);

        ob_start();
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        return $png === '' ? null : $png;
    }

    /** @return array{0:int,1:list<string>} */
    private function fit(string $name, float $maxW, string $font): array
    {
        foreach ([76, 66, 56, 48, 40] as $size) {
            $lines = $this->wrapMeasured($name, $maxW, $size, $font, 2);
            if ($lines === []) continue;
            $w = 0.0;
            foreach ($lines as $l) $w = max($w, $this->width($l, $size, $font));
            if ($w <= $maxW) return [$size, $lines];
        }
        return [40, $this->wrapMeasured($name, $maxW, 40, $font, 2)];
    }

    /** The longest prefix of $s, with an ellipsis, that fits $maxW at this size and face. */
    private function fitWidth(string $s, float $maxW, float $size, string $font): string
    {
        if ($this->width($s, $size, $font) <= $maxW) return $s;
        $n = mb_strlen($s);
        while ($n > 1 && $this->width(mb_substr($s, 0, $n) . '…', $size, $font) > $maxW) $n--;
        return rtrim(mb_substr($s, 0, $n)) . '…';
    }

    private function clip(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
    }
}
