<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Newsletter\Newsletter;
use AfricaGates\Services\Newsletter\NewsletterSchedule;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The same twelve inbox properties as every other mail skeleton, held against a RENDERED
 * newsletter issue rather than the template file.
 *
 * Rendered, because the template's body is a loop over sections and items: read as a
 * file it contains one placeholder row, and a property that holds for one row — every
 * table carrying role="presentation", the size staying under Gmail's clip — is a different
 * claim from one that holds for a full issue. The fixture is the busiest realistic issue:
 * every section filled to its cap.
 */
final class NewsletterInboxCompatTest extends EmailInboxCompatTest
{
    private static string $rendered = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:30:00', 'UTC'));
        DB::table('gates_newsletter_issues')->delete();

        for ($i = 1; $i <= 6; $i++) {
            $pid = (int) DB::table('gates_award_programmes')->insertGetId([
                'slug' => "award-$i", 'title' => "The Long-Named Continental Recognition Award Number $i", 'is_active' => 1,
            ]);
            DB::table('gates_award_cycles')->insert(['programme_id' => $pid, 'year' => 2026, 'status' => 'voting',
                'voting_open' => '2026-09-20 00:00:00', 'voting_close' => '2026-10-0' . (2 + $i % 5) . ' 22:59:00']);
            DB::table('gates_site_events')->insert(['slug' => "event-$i", 'title' => "Evening $i",
                'event_date' => '2026-10-2' . $i . ' 18:00:00', 'status' => 'published', 'venue' => 'Eko Convention Centre']);
        }

        $issue = Newsletter::compose('w2026-40', NewsletterSchedule::of(['newsletter_mode' => 'review']));
        self::$rendered = (new Newsletter(null, 'https://africagates.test'))
            ->html($issue, 'reader@example.com', '2026-09-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected static function tpl(): string
    {
        return __DIR__ . '/../../templates/emails/newsletter.twig';
    }

    protected static function markup(): string
    {
        return parent::stripComments(self::$rendered);
    }

    /**
     * There is no countdown in a newsletter, so the property is the one it stands for: with
     * every image blocked, each deadline is still there as words.
     */
    public function test_the_deadline_is_readable_with_images_blocked(): void
    {
        $withoutImages = (string) preg_replace('/<img[^>]*>/', '', self::markup());

        $this->assertStringContainsString('Voting closes Sat 3 Oct 2026, 23:59 WAT', $withoutImages);
        $this->assertStringContainsString('Eko Convention Centre', $withoutImages);
        $this->assertStringContainsString('alt="Africa GATES"', self::markup(),
            'with images off the mark must still say whose mail this is');
    }

    public function test_the_footer_says_who_asked_and_how_to_stop(): void
    {
        $m = self::markup();
        $this->assertStringContainsString('confirmed a subscription on 1 September 2026', $m);
        $this->assertMatchesRegularExpression('~href="https://africagates\.test/email/unsubscribe\?e=[^"]+&amp;t=[a-f0-9]{32}"~', $m);
    }
}
