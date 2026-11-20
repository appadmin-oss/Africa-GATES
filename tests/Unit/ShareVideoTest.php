<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShareVideo;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The share video tells the record's own story, and its proof beat follows LOW-DATA-RULES:
 * a figure below its threshold is not drawn, never drawn as a zero.
 */
final class ShareVideoTest extends TestCase
{
    private static function nominee(int $votes): array
    {
        return ['name' => 'Amara Okonkwo', 'category' => 'Teacher of the Year', 'programme' => 'Education Awards',
                'tagline' => '', 'url' => 'https://example.test/vote/edu/1-amara', 'photo' => '', 'votes' => $votes];
    }

    public function test_a_nominee_with_few_votes_gets_no_vote_proof(): void
    {
        $s = ShareVideo::forNominee(self::nominee(12));
        $this->assertSame('award', $s['kind']);
        $this->assertSame('Amara Okonkwo for Teacher of the Year.', $s['hook']);
        $this->assertNull($s['proof'], '12 votes is below the line, so the video says nothing about votes');
        $this->assertSame('Vote', $s['cta'], 'voting is paid by default, so the button does not say "Vote free"');

        $many = ShareVideo::forNominee(self::nominee(500));
        $this->assertSame(['type' => 'votes', 'votes' => 500], $many['proof']);
    }

    public function test_an_event_video_renders_from_the_published_event(): void
    {
        DB::table('gates_site_events')->insert(['slug' => 'gala-night', 'title' => 'Gala Night', 'status' => 'published',
            'tagline' => 'One night for the people who made the year. Dress well.', 'location' => 'Lagos',
            'venue' => 'Eko Hotel', 'event_date' => date('Y-m-d H:i:s', time() + 20 * 86400)]);

        $s = ShareVideo::forEvent('gala-night', 'https://example.test');
        $this->assertSame('One night for the people who made the year.', $s['hook'], 'the hook is the first sentence');
        $this->assertSame('Event · Lagos', $s['chip']);
        $this->assertSame('https://example.test/events/gala-night', $s['url']);

        $html = ChromeRender::html('/events/gala-night/video');
        $this->assertStringContainsString('data-sv-canvas', $html);
        $this->assertStringContainsString('Gala Night', $html);
        $this->assertNull(ShareVideo::forEvent('no-such-event', 'https://example.test'));
    }

    public function test_an_unknown_record_has_no_video_page(): void
    {
        $r = ChromeRender::page('/events/no-such-event/video');
        $this->assertSame(404, $r->getStatusCode());
    }
}
