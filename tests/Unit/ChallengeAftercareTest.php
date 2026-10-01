<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeAftercare as CA;
use AfricaGates\Services\ChallengeFlier;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Telling people what happened — once, and only while it still means anything.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TRIGGER FIRES MANY TIMES AND THE MESSAGE MUST NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `recount()` runs on every confirmation, approval and refund, so the point at which
 * somebody "has a standing" is reached over and over. Sending from that state would
 * text a winner once per nomination that landed after they qualified.
 *
 * The guard is a UNIQUE on `once_key`, claimed BEFORE the send. Without the index the
 * insert always succeeds and the guard silently does nothing — which is the state this
 * shipped in until `2027_02_13_challenge_once_key.php`.
 */
final class ChallengeAftercareTest extends TestCase
{
    public function test_a_winner_is_told_once_however_many_times_the_count_moves(): void
    {
        [$ch, $entry] = $this->qualified();

        $first = CA::tellWinner($entry);
        self::assertNotSame([], $this->events($ch, 'won_notified'),
            'the send was never claimed, so the guard does nothing');

        // Every later pass is a no-op. This is the case `recount()` actually produces.
        for ($i = 0; $i < 5; $i++) CA::tellWinner($entry);

        self::assertCount(1, $this->events($ch, 'won_notified'),
            'a winner would be messaged once per recount');
    }

    /**
     * A congratulation months late reads as an apology for having forgotten.
     *
     * The same window the award side uses, and measured from the CLOSE rather than
     * from when somebody pressed the button — a backfill is exactly the case this
     * exists for, and "now" is always recent from a backfill's point of view.
     */
    public function test_a_stale_challenge_is_recorded_rather_than_announced(): void
    {
        [$ch, $entry] = $this->qualified();

        DB::table('gates_challenges')->where('id', $ch)->update([
            'ends_at' => date('Y-m-d H:i:s', strtotime('-' . (CA::graceDays() + 3) . ' days')),
        ]);

        self::assertSame([], CA::tellWinner($entry), 'a months-old race sent a congratulation');
        self::assertCount(1, $this->events($ch, 'won_notice_withheld'),
            'it was withheld silently, with nothing to say why');
    }

    /** Inside the window it goes out. */
    public function test_a_recent_challenge_is_announced(): void
    {
        [$ch, $entry] = $this->qualified();

        DB::table('gates_challenges')->where('id', $ch)->update([
            'ends_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        CA::tellWinner($entry);
        self::assertSame([], $this->events($ch, 'won_notice_withheld'));
    }

    /** A removed entry is told, with the operator's own reason. */
    public function test_a_disqualified_entrant_is_told_why(): void
    {
        [$ch, $entry] = $this->qualified();
        CS::disqualify($entry, 'Nominated ten people who do not exist');

        CA::tellDisqualified($entry);
        self::assertCount(1, $this->events($ch, 'dq_notified'));

        CA::tellDisqualified($entry);
        self::assertCount(1, $this->events($ch, 'dq_notified'), 'told twice');
    }

    /** A disqualified entry never gets the winner's message. */
    public function test_a_removed_entry_is_not_congratulated(): void
    {
        [$ch, $entry] = $this->qualified();
        CS::disqualify($entry, 'Duplicate accounts');

        self::assertSame([], CA::tellWinner($entry));
        self::assertSame([], $this->events($ch, 'won_notified'));
    }

    /**
     * The Pulse post happens once per challenge.
     *
     * `entry_id` is NULL for a challenge-level event and NULL never collides with NULL
     * in a UNIQUE index on either engine — which is why `once_key` carries its own
     * scope rather than the index covering (challenge_id, entry_id, kind).
     */
    public function test_the_winners_are_posted_to_the_pulse_once(): void
    {
        [$ch, $entry] = $this->qualified();

        CA::postWinners($ch);
        self::assertSame('ALREADY', CA::postWinners($ch)['code'],
            'the feed would carry the winners twice');
    }

    public function test_a_challenge_with_no_winners_posts_nothing(): void
    {
        $ch = $this->challenge();

        self::assertSame('NO_WINNERS', CA::postWinners($ch)['code']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The flier
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * It renders at the design's exact canvas size, from a real row.
     *
     * The size is the check that matters: `imagettftext` takes POINTS and the design
     * is in CSS pixels, so a renderer that forgets the 4/3 produces a 1080 square with
     * everything in it a third too large — which is what the first render here did.
     */
    public function test_the_flier_renders_at_the_designs_canvas_size(): void
    {
        if (!function_exists('imagettftext')) {
            self::markTestSkipped('no FreeType on this build');
        }

        $ch  = $this->challenge();
        $png = ChallengeFlier::png((array) CS::find($ch), ['claimed' => 0]);

        self::assertNotNull($png);

        $size = getimagesizefromstring($png);
        self::assertSame([ChallengeFlier::W, ChallengeFlier::H], [$size[0], $size[1]]);
    }

    /**
     * ── THE CURRENCY SYMBOL ACTUALLY DRAWS ──────────────────────────────────
     *
     * DM Sans has no ₦. Asked for one GD draws NOTHING — no warning, no exception —
     * and `imagettfbbox` still returns a width, so a renderer that measures before
     * drawing is told it fits and then silently omits it. The first flier here read
     * "6k" with a gap where the currency should be.
     *
     * ── AND THE FIRST VERSION OF THIS TEST WAS A FALSE PASS ─────────────────
     *
     * It counted ink in the leading 150px of the figure column. The invisible ₦ still
     * ADVANCES its own width, so the "6" lands inside that window — the test was
     * measuring the digit. Removing the fallback left it green.
     *
     * So it drives the primitive instead: draw the symbol alone, in the design face,
     * through `text()`, and count ink. There is nothing else in the frame to pass on.
     */
    public function test_the_currency_symbol_is_not_silently_dropped(): void
    {
        if (!function_exists('imagettftext')) {
            self::markTestSkipped('no FreeType on this build');
        }

        $font = dirname(__DIR__, 2) . '/resources/fonts/DMSans-Bold.ttf';
        self::assertFileExists($font);

        // The premise. If DM Sans ever gains the glyph the fallback is harmless, but
        // this should say so rather than pass on a guarantee that moved.
        self::assertSame(0, $this->ink($font, '₦'),
            'DM Sans gained a naira glyph — the fallback is now belt and braces');

        $r = new class { use \AfricaGates\Services\FlierRaster; public function run(string $f): int {
            $im = imagecreatetruecolor(160, 160);
            imagefilledrectangle($im, 0, 0, 159, 159, (int) imagecolorallocate($im, 255, 255, 255));
            $this->text($im, '₦', 60, $f, (int) imagecolorallocate($im, 0, 0, 0), 20, 120);

            $n = 0;
            for ($x = 0; $x < 160; $x++) for ($y = 0; $y < 160; $y++) {
                if ((imagecolorat($im, $x, $y) & 0xFF) < 200) $n++;
            }
            imagedestroy($im);

            return $n;
        } };

        self::assertGreaterThan(300, $r->run($font),
            'text() dropped a character the design face cannot draw, silently');

        // And the same for the other symbols an operator can type into `prize_currency`.
        foreach (['₵', '₹', '€'] as $symbol) {
            $cur = new class { use \AfricaGates\Services\FlierRaster;
                public function face(string $f, string $c): string { return $this->faceFor($f, $c); } };

            self::assertNotSame('', $cur->face($font, $symbol));
        }
    }

    /**
     * And the figure on the real flier carries it.
     *
     * Measured against the SAME challenge with no currency, so the comparison is the
     * symbol itself rather than a pixel window that a digit can wander into.
     */
    public function test_the_flier_figure_is_wider_with_a_currency_than_without(): void
    {
        if (!function_exists('imagettftext')) {
            self::markTestSkipped('no FreeType on this build');
        }

        $with    = $this->figureInk($this->challenge(['prize_currency' => '₦', 'prize_amount' => 6000]));
        $without = $this->figureInk($this->challenge(['prize_type' => E::PRIZE_POINTS, 'prize_amount' => 6000]));

        self::assertGreaterThan($without + 1500, $with,
            'the currency symbol put no ink on the flier — it advertises a bare number');
    }

    /** A flier for a challenge with no art does not render half an empty square. */
    public function test_a_challenge_with_no_art_still_fills_its_canvas(): void
    {
        if (!function_exists('imagettftext')) {
            self::markTestSkipped('no FreeType on this build');
        }

        $ch  = $this->challenge(['art_url' => null]);
        $png = ChallengeFlier::png((array) CS::find($ch), ['claimed' => 0]);

        $im = imagecreatefromstring((string) $png);

        // The art box is left 30 / top 120 / 560 square. The question is "is anything
        // there", so the test counts pixels that DIFFER FROM THE GROUND — not dark
        // ones. The fallback's disc is white, which is brighter than the `#f6f2ea`
        // ground, so a darkness threshold sees the letter and misses the disc entirely
        // and reports an almost-empty canvas as empty.
        [$gr, $gg, $gb] = \AfricaGates\Services\FlierLayout::rgb('#f6f2ea');

        $drawn = 0;
        for ($x = 60; $x < 560; $x += 3) {
            for ($y = 160; $y < 660; $y += 3) {
                $c = imagecolorat($im, $x, $y);
                if (abs((($c >> 16) & 0xFF) - $gr) + abs((($c >> 8) & 0xFF) - $gg)
                    + abs(($c & 0xFF) - $gb) > 12) $drawn++;
            }
        }
        imagedestroy($im);

        // The disc alone is ~100k px; sampled every 3px that is ~11k. Anything in the
        // thousands means something real was drawn rather than a stray glyph.
        self::assertGreaterThan(5000, $drawn, 'the left half of the flier is empty');
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** Ink in the figure's own column, for one challenge. */
    private function figureInk(int $challengeId): int
    {
        $png = ChallengeFlier::png((array) CS::find($challengeId), ['claimed' => 0]);
        $im  = imagecreatefromstring((string) $png);

        $n = 0;
        for ($x = 596; $x < 1016; $x++) {
            for ($y = 200; $y < 345; $y++) {
                if (((imagecolorat($im, $x, $y) >> 16) & 0xFF) < 100) $n++;
            }
        }
        imagedestroy($im);

        return $n;
    }

    private function ink(string $font, string $ch): int
    {
        $im = imagecreatetruecolor(96, 96);
        imagefilledrectangle($im, 0, 0, 95, 95, (int) imagecolorallocate($im, 255, 255, 255));
        @imagettftext($im, 48, 0, 10, 70, (int) imagecolorallocate($im, 0, 0, 0), $font, $ch);

        $n = 0;
        for ($x = 0; $x < 96; $x++) for ($y = 0; $y < 96; $y++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) < 200) $n++;
        }
        imagedestroy($im);

        return $n;
    }

    private function events(int $challengeId, string $kind): array
    {
        return DB::table('gates_challenge_events')
            ->where('challenge_id', $challengeId)->where('kind', $kind)->get()->all();
    }

    private function challenge(array $over = []): int
    {
        return (int) DB::table('gates_challenges')->insertGetId($over + [
            'slug' => 'ac-' . bin2hex(random_bytes(4)), 'title' => 'Aftercare probe',
            'kicker' => 'A challenge',
            'action' => E::ACTION_NOMINATE, 'target' => 1, 'mode' => E::MODE_FIRST, 'cap' => 3,
            'prize_type' => E::PRIZE_CASH_EACH, 'prize_amount' => 6000, 'prize_currency' => '₦',
            'theme' => E::THEME_GREEN, 'status' => E::ST_OPEN,
            'ends_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0:int,1:int} a challenge, and an entry holding a place in it */
    private function qualified(): array
    {
        $ch   = $this->challenge();
        $user = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Winning Person', 'email' => 'w' . bin2hex(random_bytes(4)) . '@example.test',
            'phone' => '+2348031234567', 'status' => 'active',
        ]);

        $r = CS::join($ch, $user, '+23480' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
        self::assertTrue($r['ok'], 'fixture failed to join: ' . $r['code']);

        $entry = (int) $r['entry']->id;

        DB::table('gates_nominations')->insert([
            'cycle_id' => 1, 'nominee_name' => 'N', 'nominator_name' => 'W',
            'nominator_email' => 'w@example.test', 'status' => 'verified',
            'nominee_confirmed_at' => date('Y-m-d H:i:s'), 'challenge_entry_id' => $entry,
            'nominee_identity_hash' => hash('sha256', 'n' . $entry),
        ]);
        CS::recount($entry);

        self::assertNotNull(CS::entry($entry)->standing, 'fixture did not qualify');

        return [$ch, $entry];
    }
}
