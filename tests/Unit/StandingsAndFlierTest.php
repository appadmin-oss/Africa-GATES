<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\FlierService;
use AfricaGates\Services\StandingsService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The competitive ballot, and the flier a nominee shares.
 *
 * WHY THIS FILE IS MOSTLY ABOUT NUMBERS NOT BEING SHOWN. Adding a scoreboard to a
 * ballot is easy; the hard part is that every figure on it is a claim about a
 * competition people are paying to enter. This codebase already says of its Pulse page
 * "no fabricated likes, views or member counts", and the standard is stricter here. So
 * the assertions below are weighted toward the cases where a number must be ABSENT:
 *
 *   • momentum on a category with no timestamped votes → null, not 0. "0 votes in 24
 *     hours" printed as a measurement is an argument against voting, and it makes a
 *     quiet day indistinguishable from a broken counter.
 *   • "Top N%" on a field of three → omitted. Two nominees and "top 50%" is arithmetic
 *     wearing a badge.
 *   • a gap when the nominee leads → null, not 0.
 *
 * And one that was caught by looking at the first real render rather than by reasoning:
 * a nominee at #192 of 379 was shown "Top 51%", which in the visual language of a badge
 * says "slightly worse than average" while looking like praise.
 */
class StandingsAndFlierTest extends TestCase
{
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = 7700;

        // The flier query joins nominee → category → cycle → programme, so the chain has
        // to exist or forNominee() returns null and every flier assertion passes
        // vacuously against a null. Seeded here rather than assumed, because the
        // standings half needs only the nominee rows and would not have caught it.
        DB::table('gates_award_programmes')->insertOrIgnore([
            // `is_active`, not `status` — programmes carry a flag, cycles carry a phase.
            'id' => 770, 'slug' => 'test-programme', 'title' => 'Test Programme', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 771, 'programme_id' => 770, 'year' => 2026, 'status' => 'voting',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            // `slug` is NOT NULL. insertOrIgnore swallowed the constraint failure, so the
            // row simply was not there and every flier assertion ran against a null
            // return — passing, or erroring three layers away from the cause. Worth
            // stating: insertOrIgnore in a fixture hides exactly this.
            'id' => $this->cat, 'cycle_id' => 771, 'slug' => 'test-category', 'title' => 'Test Category',
        ]);

        // And asserted, rather than assumed. A fixture that silently did not insert is
        // how the flier tests first passed against null.
        $this->assertSame(1, DB::table('gates_award_categories')->where('id', $this->cat)->count(),
            'the category chain the flier joins through must exist');
    }

    /** @return int nominee id */
    private function nominee(string $name, int $votes, array $extra = []): int
    {
        $id = (int) (DB::table('gates_nominees')->max('id') ?? 0) + 1;
        DB::table('gates_nominees')->insert($extra + [
            'id' => $id, 'category_id' => $this->cat, 'name' => $name, 'status' => 'approved',
            'vote_count' => $votes, 'nominated_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        return $id;
    }

    private function standing(int $id): array
    {
        // Uncached path: the cache is keyed by nominee and a test that seeded a
        // different field would otherwise read a previous test's answer.
        DB::table('gates_cache')->delete();
        return (new StandingsService())->forNominee($id, $this->cat);
    }

    // ── Rank ─────────────────────────────────────────────────────────────────

    public function test_ties_share_a_position(): void
    {
        // COMPETITION rank. A list index would tell two nominees on 40 votes that one is
        // 2nd and the other 3rd, which is not true and is exactly the detail a nominee
        // screenshots and disputes.
        $this->nominee('Leader', 100);
        $a = $this->nominee('Tied A', 40);
        $b = $this->nominee('Tied B', 40);
        $c = $this->nominee('Last', 10);

        $this->assertSame(2, $this->standing($a)['rank']);
        $this->assertSame(2, $this->standing($b)['rank'], 'a tie is the same position, not the next one');
        // And the nominee below a tie is 4th, not 3rd — the tie consumed two places.
        $this->assertSame(4, $this->standing($c)['rank']);
    }

    public function test_the_leader_has_no_gap_ahead_and_a_real_gap_behind(): void
    {
        $lead = $this->nominee('Leader', 100);
        $this->nominee('Second', 88);

        $s = $this->standing($lead);
        $this->assertTrue($s['is_leader']);
        $this->assertNull($s['gap_ahead'], 'there is nothing ahead — null, not zero');
        $this->assertSame(12, $s['gap_behind']);
    }

    public function test_a_tie_behind_a_leader_reports_the_gap_to_the_leader(): void
    {
        // NOT the row above. With two nominees tied on 40 behind a leader on 100, the row
        // above has the same total, so a naive "gap to the previous row" is zero — which
        // would tell a jointly-second nominee they are level with the position they
        // already hold. The useful facts are "joint 2nd" and "60 from 1st".
        $this->nominee('Leader', 100);
        $a = $this->nominee('Tied A', 40);
        $this->nominee('Tied B', 40);

        $s = $this->standing($a);
        $this->assertSame(2, $s['rank']);
        $this->assertSame(60, $s['gap_ahead'], 'the gap is to the next HIGHER total');
        $this->assertSame(1, $s['next_rank']);
        $this->assertFalse($s['shared_top']);
    }

    public function test_a_tie_at_the_top_is_stated_as_joint_first(): void
    {
        // The only case where "level" is the right word — and the case a "gap to the row
        // above" would have reported as zero for the wrong reason.
        $a = $this->nominee('Joint A', 90);
        $this->nominee('Joint B', 90);
        $this->nominee('Third', 20);

        $s = $this->standing($a);
        $this->assertSame(1, $s['rank']);
        $this->assertTrue($s['is_leader']);
        $this->assertTrue($s['shared_top']);
        $this->assertNull($s['gap_ahead'], 'nothing is higher');
        $this->assertStringContainsString('joint first', StandingsService::headline($s));
        $this->assertStringContainsString('breaks the tie', StandingsService::callToAction($s));
    }

    public function test_the_gap_skips_past_tied_neighbours(): void
    {
        // Two nominees on 40 and one on 55: the gap from 40 is 15, not 0. Walking one
        // row up would report a gap to a nominee on the same total.
        $this->nominee('Leader', 100);
        $this->nominee('Third rail', 55);
        $a = $this->nominee('Tied A', 40);
        $this->nominee('Tied B', 40);

        $this->assertSame(15, $this->standing($a)['gap_ahead']);
    }

    // ── Figures that must be absent ──────────────────────────────────────────

    public function test_momentum_is_null_not_zero_when_nothing_is_timestamped(): void
    {
        // A quiet day and a broken counter must not look the same. The flag is what
        // lets the template omit the element instead of printing a zero.
        $id = $this->nominee('No Votes Recorded', 5);

        $s = $this->standing($id);
        $this->assertFalse($s['momentum_available']);
        $this->assertNull($s['momentum_24h'], 'unmeasurable is not zero');
    }

    public function test_momentum_counts_only_the_last_day(): void
    {
        $id = $this->nominee('Rallying', 3);
        foreach ([2, 5, 40] as $hoursAgo) {
            DB::table('gates_votes')->insert([
                'nominee_id' => $id, 'category_id' => $this->cat,
                'voter_email_hash' => hash('sha256', 'v' . $hoursAgo),
                'voted_at' => Carbon::now()->subHours($hoursAgo)->toDateTimeString(),
            ]);
        }

        $s = $this->standing($id);
        $this->assertTrue($s['momentum_available']);
        $this->assertSame(2, $s['momentum_24h'], 'the 40-hour-old vote is outside the window');
    }

    public function test_top_percent_is_withheld_on_a_small_field(): void
    {
        $a = $this->nominee('A', 30);
        $this->nominee('B', 20);
        $this->nominee('C', 10);

        $s = $this->standing($a);
        $this->assertNull($s['top_pct'], 'three nominees and "top 34%" measures nothing');
        $this->assertFalse($s['top_notable']);
    }

    public function test_a_median_position_is_not_dressed_as_an_achievement(): void
    {
        // The defect seen on the first real render: #192 of 379 displayed "Top 51%",
        // which looks like a badge and says "slightly worse than average".
        for ($i = 0; $i < 20; $i++) $this->nominee('N' . $i, 100 - $i);
        $mid = $this->nominee('Middle', 50);   // sits near the bottom of a field of 21

        $s = $this->standing($mid);
        $this->assertNotNull($s['top_pct'], 'the figure is computed');
        $this->assertGreaterThan(25, $s['top_pct']);
        $this->assertFalse($s['top_notable'], 'but it is not surfaced');
    }

    public function test_a_genuinely_high_position_is_surfaced(): void
    {
        // The other half — a suppression rule that suppresses everything is not a rule.
        $top = $this->nominee('Top', 500);
        for ($i = 0; $i < 20; $i++) $this->nominee('N' . $i, 10 + $i);

        $s = $this->standing($top);
        $this->assertTrue($s['top_notable']);
        $this->assertLessThanOrEqual(25, $s['top_pct']);
    }

    public function test_top_percent_means_what_it_says(): void
    {
        // ceil(rank / field × 100). The earlier expression subtracted a percentile from
        // 100 and added one, an off-by-one that happened to agree at the one value it
        // was first seen at.
        for ($i = 0; $i < 10; $i++) $this->nominee('N' . $i, 100 - $i);
        $rows = DB::table('gates_nominees')->where('category_id', $this->cat)
            ->orderByDesc('vote_count')->get(['id'])->all();
        $second = (int) $rows[1]->id;

        $s = $this->standing($second);
        $this->assertSame(2, $s['rank']);
        $this->assertSame(20, $s['top_pct'], '2 of 10 is the top 20%');
    }

    public function test_a_nominee_outside_the_category_gets_a_blank_standing(): void
    {
        // Every key present with a safe value, because the template indexes them
        // unconditionally and Twig renders a missing key as empty — which would show an
        // empty progress bar rather than omitting the block.
        $s = (new StandingsService())->forNominee(999999, $this->cat);

        foreach (['rank', 'field', 'votes', 'top_pct', 'top_notable', 'gap_ahead',
                  'gap_behind', 'next_rank', 'shared_top', 'momentum_24h', 'momentum_available',
                  'leader_votes', 'progress_pct', 'is_leader', 'is_top_three'] as $k) {
            $this->assertArrayHasKey($k, $s, $k);
        }
        $this->assertSame(0, $s['field'], 'and field 0 is what makes the template omit it');
    }

    public function test_the_progress_bar_never_reads_as_a_rendering_fault(): void
    {
        $this->nominee('Runaway', 10000);
        $tiny = $this->nominee('Just Started', 1);

        $s = $this->standing($tiny);
        $this->assertGreaterThanOrEqual(2, $s['progress_pct'],
            'a nominee on one vote must see a sliver, not an empty track');
        $this->assertLessThanOrEqual(100, $s['progress_pct']);
    }

    // ── The words ────────────────────────────────────────────────────────────

    public function test_the_headline_never_names_the_nominee_ahead(): void
    {
        // "You are 12 behind" is the actionable fact. "You are 12 behind Ada" invites a
        // campaign against a person, on a platform whose integrity model depends on
        // that not happening.
        $this->nominee('Ada Obi', 100);
        $me = $this->nominee('Me', 88);

        $h = StandingsService::headline($this->standing($me));
        $this->assertStringNotContainsString('Ada', $h);
        $this->assertStringContainsString('12', $h);
    }

    public function test_the_call_to_action_changes_with_the_standing(): void
    {
        // One "Vote now" ignores that the useful ask differs. A leader needs defence,
        // someone three votes off needs urgency.
        $lead = $this->nominee('Leader', 100);
        $near = $this->nominee('Near', 97);
        $far  = $this->nominee('Far', 10);

        $a = StandingsService::callToAction($this->standing($lead));
        $b = StandingsService::callToAction($this->standing($near));
        $c = StandingsService::callToAction($this->standing($far));

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
        $this->assertStringContainsString('3', $b, 'a close gap is stated as a number');
    }

    public function test_no_call_to_action_invents_a_figure(): void
    {
        // Every number in the copy must come from the standing.
        $this->nominee('Leader', 100);
        $id = $this->nominee('Mid', 60);
        $s  = $this->standing($id);

        preg_match_all('/\d+/', StandingsService::callToAction($s), $m);
        foreach ($m[0] as $n) {
            $this->assertContains((int) $n, [$s['gap_ahead'], $s['gap_behind'], $s['votes'], $s['rank'], $s['field']],
                "the copy contains {$n}, which is not a figure from the standing");
        }
    }

    // ── The flier ────────────────────────────────────────────────────────────

    private function flierFor(int $id): ?array
    {
        DB::table('gates_cache')->delete();
        return (new FlierService())->forNominee($id);
    }

    public function test_the_flier_svg_is_well_formed_xml(): void
    {
        // It is served as image/svg+xml. Malformed XML is not a cosmetic problem — the
        // browser refuses to render it and the nominee gets a broken image.
        $id = $this->nominee('Ngozi Okonjo-Iweala', 42);
        $f  = $this->flierFor($id);
        $this->assertNotNull($f);

        $svg = (new FlierService())->svg($f);
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($svg);
        $errs = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $this->assertNotFalse($doc, 'the SVG must parse: ' . implode('; ', array_map(
            static fn ($e): string => trim($e->message), $errs)));
    }

    public function test_a_nominee_name_cannot_inject_into_the_svg(): void
    {
        // The sharpest edge on this feature. A nominee name is public-submitted text and
        // the response is image/svg+xml — a content type the browser EXECUTES — so an
        // unescaped `<` is stored XSS that no HTML sanitiser is anywhere near.
        $id = $this->nominee('</text><script>alert(1)</script><text>', 5);
        $f  = $this->flierFor($id);
        $svg = (new FlierService())->svg($f);

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('</text><text>', $svg);
        $this->assertStringContainsString('&lt;script', $svg, 'escaped, not stripped');

        $prev = libxml_use_internal_errors(true);
        $this->assertNotFalse(simplexml_load_string($svg), 'and it still parses');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }

    public function test_the_url_on_the_flier_fits_the_space_it_is_drawn_in(): void
    {
        // The pill is a fixed-width rounded rect with centred, non-wrapping text, so an
        // overlong URL runs straight out of it. Seen on the first real render, where the
        // full slug already reached the edge.
        $id = $this->nominee('A Nominee With A Considerably Longer Name Than Usual', 9);
        $f  = $this->flierFor($id);

        $this->assertLessThanOrEqual(46, mb_strlen($f['short_url']),
            'the printed URL must fit: ' . $f['short_url']);
        // And it must still be a usable address, not a truncated one.
        $this->assertStringNotContainsString('…', $f['short_url']);
    }

    public function test_the_monogram_never_uses_a_digit(): void
    {
        // "Nominee 48 Surname" produced "N4" on the first render. A digit as an initial
        // reads as a rendering fault, and any name carrying a cohort year, an edition or
        // a team number does the same.
        $id = $this->nominee('Nominee 48 Surname', 3);
        $svg = (new FlierService())->svg($this->flierFor($id));

        // The monogram is the only 260px text on the card.
        preg_match('~font-size="260"[^>]*>([^<]*)<~', $svg, $m);
        $this->assertNotEmpty($m, 'a photo-less nominee must get a monogram');
        $this->assertMatchesRegularExpression('~^\p{L}{1,2}$~u', $m[1],
            'letters only, got: ' . $m[1]);
    }

    public function test_the_flier_states_that_votes_are_not_the_result(): void
    {
        // A rank on a graphic reads as an outcome. 55% of the CPI is an independent jury,
        // so omitting that would make a share card a misleading claim — and it is the one
        // artefact from this platform that circulates without any surrounding context.
        $id = $this->nominee('Ada Obi', 42);
        $svg = (new FlierService())->svg($this->flierFor($id));

        $this->assertStringContainsString('independent jury', $svg);
    }

    public function test_the_flier_omits_momentum_rather_than_printing_zero(): void
    {
        // "0 votes in the last 24 hours" on a graphic you are about to post is an
        // argument against voting.
        $id = $this->nominee('Quiet', 20);
        $svg = (new FlierService())->svg($this->flierFor($id));

        $this->assertStringNotContainsString('0 votes in the last 24 hours', $svg);
        $this->assertStringNotContainsString('in the last 24 hours', $svg);
    }

    public function test_the_flier_is_accessible_as_a_graphic(): void
    {
        // An SVG opened directly, or embedded anywhere, is "image" to a screen reader
        // without these. The description carries the standing and the URL, which is the
        // whole content of the card.
        $id = $this->nominee('Ada Obi', 42);
        $svg = (new FlierService())->svg($this->flierFor($id));

        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('aria-labelledby="fTitle fDesc"', $svg);
        $this->assertMatchesRegularExpression('~<title id="fTitle">Vote for Ada Obi~', $svg);
        $this->assertMatchesRegularExpression('~<desc id="fDesc">[^<]*Vote at ~', $svg);
    }

    public function test_the_rally_line_is_usable_with_no_ai_configured(): void
    {
        // No provider key in tests, so this is the fallback — and it must be a line a
        // nominee would actually post. A nominee pressing "download" must never get a
        // graphic with an apology on it.
        $this->nominee('Leader', 100);
        $id = $this->nominee('Chaser', 88);
        $f  = $this->flierFor($id);

        $this->assertFalse($f['ai'], 'no key configured in tests');
        $this->assertGreaterThan(40, mb_strlen($f['rally']));
        $this->assertStringNotContainsStringIgnoringCase('error', $f['rally']);
        $this->assertStringNotContainsStringIgnoringCase('unavailable', $f['rally']);
        $this->assertStringContainsString($f['category'], $f['rally'],
            'the fallback names the category, so it reads as written rather than generic');
    }

    public function test_the_fallback_line_matches_the_standing(): void
    {
        // One sentence used for a leader and for someone twelve votes off is wrong for
        // both.
        $lead = $this->nominee('Leader', 100);
        $tie1 = $this->nominee('Tied A', 50);
        $this->nominee('Tied B', 50);

        $a = $this->flierFor($lead)['rally'];
        $b = $this->flierFor($tie1)['rally'];

        $this->assertNotSame($a, $b, 'a leader and a chaser must not get the same line');
        $this->assertStringContainsStringIgnoringCase('lead', $a);
        $this->assertStringContainsStringIgnoringCase('gap', $b,
            'jointly-second behind a leader is a gap to close, not a tie to break');
    }

    public function test_a_nominee_that_is_not_voteable_has_no_flier(): void
    {
        $pending = $this->nominee('Pending Person', 0, ['status' => 'pending']);
        $keep    = $this->nominee('Keeper', 10);
        $merged  = $this->nominee('Folded Away', 4, ['merged_into' => $keep]);

        $this->assertNull($this->flierFor($pending), 'an unreviewed nominee has no public flier');
        $this->assertNull($this->flierFor($merged), 'nor does a tombstone');
        $this->assertNotNull($this->flierFor($keep));
    }

    // ── The raster, which is what a link preview needs ───────────────────────

    public function test_the_bundled_fonts_are_present(): void
    {
        // GD's imagettftext() needs a TrueType file on disk, and a shared cPanel host
        // frequently has none — so relying on the system is how the flier silently
        // becomes DejaVu on the deployment nobody can inspect. If these are lost in a
        // deploy the PNG route falls back to SVG, which no chat app previews, and every
        // nominee's link preview breaks with nothing reporting it.
        $f = FlierService::fontsPresent();

        $this->assertTrue($f['ok'], 'missing: ' . implode(', ', $f['missing']));
    }

    public function test_the_bundled_fonts_are_the_faces_the_site_actually_loads(): void
    {
        // Not the ones the CSS mentions. Montserrat appears in some stylesheets but the
        // layout never loads it, so it was already rendering as a fallback everywhere —
        // and the first version of this flier bundled it, which would have made the
        // graphic use a typeface that appears nowhere else on the site.
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/gates.twig');

        $this->assertStringContainsString('family=DM+Sans', $layout);
        $this->assertStringContainsString('family=Playfair+Display', $layout);
        $this->assertFileExists(dirname(__DIR__, 2) . '/resources/fonts/DMSans-Bold.ttf');
        $this->assertFileExists(dirname(__DIR__, 2) . '/resources/fonts/PlayfairDisplay-Bold.ttf');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/resources/fonts/Montserrat-Bold.ttf',
            'Montserrat is not a face this site loads');
    }

    public function test_the_fonts_cover_the_orthographies_this_platform_serves(): void
    {
        // Subsetting is where a font quietly stops working for a whole language. Every
        // one of these is a real African Latin orthography, and a missing glyph would
        // render a nominee's name with a box in it on the graphic they share.
        $cases = [
            'Yoruba'  => 'Ọlásùnkànmí Ṣẹ́gun',
            'Akan'    => 'Ɔdɔ Nyankopɔn Ŋwae',
            'Hausa'   => 'Ɓalarabe Ɗanjuma Ƙano',
            'Kikuyu'  => 'Wangarĩ Mũthoni',
            'French'  => 'Aïssatou Diané Coté',
            'Marks'   => '· — “x” … ₦ ₵',
        ];
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        foreach (['DMSans-Bold.ttf', 'DMSans-Regular.ttf', 'DMSans-SemiBold.ttf', 'PlayfairDisplay-Bold.ttf'] as $face) {
            foreach ($cases as $label => $text) {
                foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                    if (trim($ch) === '') continue;
                    $box = imagettfbbox(48, 0, $dir . $face, $ch);
                    $this->assertNotFalse($box, "{$face} could not measure {$ch}");
                    $this->assertNotSame(0, $box[2] - $box[0],
                        "{$face} has no glyph for “{$ch}” ({$label}) — a name in that language would render with a box");
                }
            }
        }
    }

    public function test_the_png_is_a_real_image_of_the_declared_size(): void
    {
        // The og:image contract: WhatsApp, Facebook and X are told 1080x1350 in the meta
        // tags, and a mismatch means a cropped or letterboxed preview.
        $id  = $this->nominee('Ngozi Okonjo-Iweala', 42);
        $png = (new FlierService())->png($this->flierFor($id));

        $this->assertNotNull($png, 'GD and the fonts are present, so a PNG must be produced');
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'PNG magic');

        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(FlierService::W, $info[0]);
        $this->assertSame(FlierService::H, $info[1]);
        $this->assertSame('image/png', $info['mime']);
    }

    public function test_the_png_has_no_seam_where_the_scrim_meets_the_background(): void
    {
        // A one-pixel bright line at y=820 across the whole card, because
        // imagefilledrectangle() is INCLUSIVE of both corners while the scrim loop runs
        // 0..h-1 — so that row was painted with the panel colour and never darkened.
        // Measured rgb(15,51,41) between two rows of rgb(12,43,35). It reads as a
        // rendering fault, which on a graphic a nominee posts is the whole impression.
        $id  = $this->nominee('Seam Check', 12);
        $png = (new FlierService())->png($this->flierFor($id));
        $im  = imagecreatefromstring((string) $png);
        $this->assertNotFalse($im);

        $worst = 0; $at = 0;
        $prev = null;
        // x=1000 is clear of every text block, so any step is the background itself.
        for ($y = 780; $y <= 870; $y++) {
            $c = imagecolorat($im, 1000, $y);
            $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            if ($prev !== null) {
                $d = abs($rgb[0] - $prev[0]) + abs($rgb[1] - $prev[1]) + abs($rgb[2] - $prev[2]);
                if ($d > $worst) { $worst = $d; $at = $y; }
            }
            $prev = $rgb;
        }
        imagedestroy($im);

        $this->assertLessThanOrEqual(3, $worst,
            "a visible tonal step of {$worst} at y={$at} — the scrim and the background must meet seamlessly");
    }

    public function test_the_png_and_the_svg_render_the_same_design(): void
    {
        // Two encodings of one design. A full pixel comparison is not what this can
        // assert; what it can is that both carry the same content and the same
        // suppression rules, which is where they drifted before.
        $this->nominee('Leader', 900);
        $id = $this->nominee('Chidinma Eze', 42);
        $f  = $this->flierFor($id);
        $svc = new FlierService();

        $svg = $svc->svg($f);
        $png = $svc->png($f);
        $this->assertNotNull($png);

        // The SVG's text is inspectable; the PNG's is not, so the shared source is $f.
        foreach ([$f['name'], $f['category'], $f['headline'], $f['short_url']] as $needed) {
            $this->assertStringContainsString(htmlspecialchars($needed, ENT_QUOTES | ENT_XML1), $svg);
        }
        // Both name the same font families / files.
        $this->assertStringContainsString('DM Sans', $svg);
        $this->assertStringContainsString('Playfair Display', $svg);
    }

    public function test_the_svg_and_the_canvas_do_not_drift_apart(): void
    {
        // The SVG and the PNG are two renderings of ONE design — server-side XML and
        // browser-side canvas — so a rule fixed in one and not the other means the
        // graphic a nominee downloads differs from the one they previewed. That already
        // happened once: the letters-only monogram rule was corrected in
        // FlierService::initials() and the canvas kept producing "N4".
        //
        // A full pixel comparison is not what this can assert. What it can is that both
        // sides carry the same GEOMETRY constants and the same guarded rules, so a change
        // to one without the other is visible here.
        // THE CANVAS IS GONE, and its removal is the point. The PNG was drawn in the
        // browser to avoid GD needing a font file — defensible while it was only a
        // download, indefensible once the graphic had to be an og:image, because a
        // crawler cannot run JavaScript. It also produced a real bug: a monogram rule
        // fixed in the SVG kept rendering the old way on the canvas.
        //
        // So the drift risk is now between two methods in ONE class, and this asserts
        // the template no longer contains a second renderer at all.
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-flier.twig');

        $this->assertStringNotContainsString('getContext(\'2d\')', $twig,
            'the flier must have exactly one renderer, and it is server-side');
        $this->assertStringNotContainsString('toBlob', $twig);
        // Both downloads are plain links, so they work with JavaScript off.
        $this->assertMatchesRegularExpression('~href="\{\{ png_url \}\}"[^>]*download~', $twig);
        $this->assertMatchesRegularExpression('~href="\{\{ svg_url \}\}"[^>]*download~', $twig);

        // And the jury footnote, which must never be dropped from either encoding.
        $svg = (new FlierService())->svg($this->flierFor($this->nominee('Footnote Check', 3)));
        $this->assertStringContainsString('An independent jury decides the award', $svg);
        $this->assertStringContainsString('An independent jury decides the award',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/FlierService.php'));
    }

    public function test_the_flier_carries_no_contact_detail(): void
    {
        // It is a public graphic that circulates outside the site. The aggregation of
        // nominee, standing and programme is exactly where an email column gets joined
        // in by accident.
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/FlierService.php');
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);

        foreach (['email', 'phone', 'voter_email_hash', 'REMOTE_ADDR'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, $forbidden);
        }
    }
}
