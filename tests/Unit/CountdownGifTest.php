<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Controllers\CountdownController;
use AfricaGates\Services\CountdownGif;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The live countdown in an email hero: the picture, and the deadline it draws.
 *
 * Two separable things are asserted here because they fail differently. CountdownGif
 * has to emit a real animated GIF89a — PHP cannot write one natively, so the stream is
 * assembled by hand and a mistake there produces a file that is either a still or
 * corrupt. CountdownController has to pick the right CYCLE, and the failure mode there
 * is worse than an ugly image: a plausible countdown to the wrong date.
 */
class CountdownGifTest extends TestCase
{
    // ── The image ────────────────────────────────────────────────────────────

    public function test_renders_a_looping_animation_not_a_still(): void
    {
        $gif = CountdownGif::render(2 * 86400 + 14 * 3600 + 33 * 60 + 9);

        $this->assertSame('GIF89a', substr($gif, 0, 6), 'must be GIF89a — GIF87a has no animation blocks');
        $this->assertStringContainsString('NETSCAPE2.0', $gif,
            'without the Netscape loop extension the animation plays once and then freezes');
        $this->assertSame("\x3B", substr($gif, -1), 'missing GIF trailer');
        $this->assertSame(CountdownGif::FRAMES, substr_count($gif, "\x21\xF9\x04\x04"),
            'one graphic-control extension per frame');

        // It is a real image as far as the world is concerned.
        $info = getimagesizefromstring($gif);
        $this->assertIsArray($info);
        $this->assertSame('image/gif', $info['mime']);
    }

    public function test_every_frame_differs_so_the_clock_actually_moves(): void
    {
        // The bug this catches: a container with the right frame count whose frames are
        // all identical still validates as a GIF and still looks like a still image.
        // Counted by the LZW payload of each frame, which is what a decoder draws.
        $gif    = CountdownGif::render(3661);
        $blocks = self::frameBlocks($gif);

        $this->assertCount(CountdownGif::FRAMES, $blocks);
        $this->assertCount(CountdownGif::FRAMES, array_unique($blocks),
            'frames are identical — the countdown is animated in name only');
    }

    public function test_closed_state_is_a_single_repeated_frame(): void
    {
        // Nothing is ticking once voting has closed, so every frame is the same by
        // design — the inverse of the assertion above, stated so it cannot rot.
        $blocks = self::frameBlocks(CountdownGif::render(0));
        $this->assertCount(1, array_unique($blocks));
    }

    public function test_never_renders_a_negative_countdown(): void
    {
        // A deadline in the past must read as closed, not as minus three days.
        $this->assertSame(
            self::frameBlocks(CountdownGif::render(0)),
            self::frameBlocks(CountdownGif::render(-99999))
        );
    }

    public function test_url_builder_carries_the_cycle(): void
    {
        $this->assertSame('https://africagates.org/email/countdown.gif?cycle=12',
            CountdownGif::urlFor('https://africagates.org/', 12));
        // No cycle: the endpoint's own fallback applies. Valid, but only right by luck
        // for a multi-programme send — which is why the sender always passes one.
        $this->assertSame('https://africagates.org/email/countdown.gif',
            CountdownGif::urlFor('https://africagates.org', null));
        $this->assertSame('https://africagates.org/email/countdown.gif',
            CountdownGif::urlFor('https://africagates.org', 0));
    }

    // ── The deadline ─────────────────────────────────────────────────────────

    /**
     * @dataProvider cycleCases
     */
    public function test_countdown_reads_the_cycle_it_is_told_to(
        string $status, ?string $opensIn, ?string $closesIn, bool $expectCountdown, string $why
    ): void {
        DB::table('gates_award_programmes')->insert(
            ['id' => 1, 'slug' => 'p1', 'title' => 'P1', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert([
            'id' => 7, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => $status,
            'voting_open'  => $opensIn  === null ? null : self::at($opensIn),
            'voting_close' => $closesIn === null ? null : self::at($closesIn),
        ]);

        $gif = $this->gifFor(['cycle' => '7']);

        $this->assertSame($expectCountdown, self::isTicking($gif), $why);
    }

    /** @return array<string, array{0:string,1:?string,2:?string,3:bool,4:string}> */
    public static function cycleCases(): array
    {
        return [
            'voting, window open' => ['voting', '-2 days', '+2 days', true,
                'the ordinary case: a live ballot must count down'],

            'window live but status not yet advanced' => ['nominations', '-1 hour', '+1 day', true,
                'phases advance on an hourly job, so a live window can carry a stale status — '
                . 'telling people voting had ended while the ballot was open would be the worse error'],

            'status voting, no window dates' => ['voting', null, '+3 days', true,
                'an operator moved it by hand and left the dates blank'],

            'still taking nominations, no voting_open' => ['nominations', null, '+19 days', false,
                'a close date says when voting would END, never that it has begun — this rendered '
                . '"voting closes in 19 days" for a programme nobody could vote in'],

            'voting has not opened yet' => ['upcoming', '+30 days', '+60 days', false,
                'a future start is explicit: not open, whatever the status says'],

            'already closed' => ['archived', '-400 days', '-370 days', false,
                'a past deadline is the closed state, not a negative countdown'],

            'legacy zero date' => ['archived', null, null, false,
                'no usable close date at all'],
        ];
    }

    public function test_unknown_cycle_falls_back_to_the_closed_state(): void
    {
        $this->assertFalse(self::isTicking($this->gifFor(['cycle' => '4242'])),
            'a missing cycle must render calmly rather than 404 into a broken image in an inbox');
    }

    public function test_with_no_cycle_named_it_picks_the_soonest_closing_live_vote(): void
    {
        DB::table('gates_award_programmes')->insert([
            ['id' => 1, 'slug' => 'p1', 'title' => 'Education', 'is_active' => 1, 'sort_order' => 1],
            ['id' => 2, 'slug' => 'p2', 'title' => 'Choral',    'is_active' => 1, 'sort_order' => 2],
        ]);
        // Cycles are per PROGRAMME, so several can be in voting at once.
        DB::table('gates_award_cycles')->insert([
            ['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
             'voting_open' => self::at('-9 days'), 'voting_close' => self::at('+2 days')],
            ['id' => 2, 'programme_id' => 2, 'year' => (int) date('Y'), 'status' => 'voting',
             'voting_open' => self::at('-4 days'), 'voting_close' => self::at('+11 hours')],
        ]);

        // Which is why the per-recipient email passes its own cycle rather than relying
        // on this: the fallback is the soonest close, and for a Choral nominee that is
        // right only by luck.
        $this->assertTrue(self::isTicking($this->gifFor([])));
        $this->assertNotSame(
            self::frameBlocks($this->gifFor(['cycle' => '1'])),
            self::frameBlocks($this->gifFor(['cycle' => '2'])),
            'two programmes closing at different times must not draw the same picture'
        );
    }

    public function test_response_is_never_cacheable(): void
    {
        // A cached countdown is a photograph of a countdown, and the failure is silent:
        // every recipient sees whatever time the first one saw.
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'),
            'status' => 'voting', 'voting_open' => self::at('-1 day'), 'voting_close' => self::at('+1 day')]);

        $res = (new CountdownController())->gif(
            self::request([]), new \Slim\Psr7\Response()
        );

        $this->assertSame('image/gif', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame((string) $res->getBody()->getSize(), $res->getHeaderLine('Content-Length'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function at(string $rel): string
    {
        return Carbon::now()->modify($rel)->toDateTimeString();
    }

    /** @param array<string,string> $query */
    private function gifFor(array $query): string
    {
        $res = (new CountdownController())->gif(self::request($query), new \Slim\Psr7\Response());
        $res->getBody()->rewind();

        return (string) $res->getBody()->getContents();
    }

    /** @param array<string,string> $query */
    private static function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/email/countdown.gif')
            ->withQueryParams($query);
    }

    /**
     * The LZW payload of each frame, which is what a decoder actually draws.
     *
     * @return list<string>
     */
    private static function frameBlocks(string $gif): array
    {
        // Frames are separated by their graphic-control extensions; splitting on that
        // marker gives one chunk per frame without decoding LZW.
        $parts = explode("\x21\xF9\x04\x04", $gif);
        array_shift($parts);            // header + global colour table + loop extension
        if ($parts === []) return [];

        // The LAST chunk carries the file's 0x3B trailer, which made it differ from every
        // other frame by one byte — so a static image measured as 2 unique frames and
        // every closed-state assertion here passed for the wrong reason. Dropped, so the
        // comparison is frame content against frame content.
        $lastKey = array_key_last($parts);
        $parts[$lastKey] = rtrim((string) $parts[$lastKey], "\x3B");

        return array_values($parts);
    }

    /** True when the image is a moving countdown rather than the closed state. */
    private static function isTicking(string $gif): bool
    {
        return count(array_unique(self::frameBlocks($gif))) > 1;
    }
}
