<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DI\ContainerBuilder;
use Slim\Views\Twig;

/**
 * Screen-reader navigability guard: every public page must expose exactly one
 * <h1> and a heading outline with no skipped levels (e.g. never h1→h3). These
 * are the two structural rules assistive-technology heading navigation relies
 * on. Renders each template through the real Twig (shared banner/nav/main/
 * contentinfo landmarks come from the layout) with representative context.
 */
class HeadingHierarchyTest extends TestCase
{
    private Twig $twig;

    /** Pages fixed for / relevant to heading structure, rendered with real-ish data. */
    private const CASES = [
        'pages/vote.twig'                => ['hub' => [['title' => 'STEM', 'icon_emoji' => '', 'cycle_status' => 'voting', 'total_votes' => 10, 'nominee_count' => 3, 'leader' => null, 'url' => '/x']], 'STATUS' => ['voting' => ['label' => 'Voting', 'cls' => 'ok']], 'FLAGS' => [], 'meta' => ['voting_count' => 1, 'votes_total' => 10, 'voting_close' => '2026-08-01']],
        'pages/vote-program.twig'        => ['programme' => ['title' => 'STEM', 'icon_emoji' => '', 'subtitle' => 's'], 'categories' => [['category' => ['title' => 'Innovation', 'description' => 'd'], 'nominees' => [['name' => 'Ada', 'url' => '/x', 'photo_path' => null, 'tagline' => 't', 'vote_count' => 3]]]], 'voting_open' => true, 'total_nominees' => 1, 'cy' => ['year' => 2026, 'status' => 'voting']],
        'pages/vote-nominee.twig'        => ['n' => ['name' => 'Ada Obi', 'category' => 'Innovation', 'tagline' => 'A leader', 'vote_count' => 5, 'programme_title' => 'STEM'], 'firstName' => 'Ada', 'others' => [], 'AV' => [['#eee', '#333']], 'flag' => '🇳🇬', 'ctry' => 'Nigeria'],
        'pages/blog/index.twig'          => ['posts' => [['slug' => 'p', 'title' => 'First', 'cover_image' => null, 'tag' => 'News', 'published_at' => '2026-01-01', 'excerpt' => 'e', 'author' => 'A']]],
        'pages/blog/index.twig#empty'    => ['posts' => []],
        'pages/events.twig'              => ['upcoming' => [['slug' => 'e', 'title' => 'Gala', 'event_date' => '2026-09-01', 'location' => 'Lagos', 'cover_image' => null]], 'past' => []],
        'pages/legacy/index.twig'        => ['events' => [['slug' => 'e', 'title' => 'Lagos 2025', 'cover_path' => null, 'event_date' => '2025-05-01', 'location' => 'Lagos', 'attendee_count' => 500, 'award_count' => 12]]],
        'pages/legacy/index.twig#empty'  => ['events' => []],
        'pages/judges.twig'              => ['judges' => []],
        'pages/leaderboard.twig'         => ['leaders' => [], 'entries' => []],
        'pages/registry/index.twig'      => ['profiles' => [], 'rows' => []],
        'pages/shop/index.twig'          => ['products' => [], 'items' => []],
        'pages/account/login.twig'       => ['sent' => false],
        'pages/account/register.twig'    => [],
        'pages/account/verify-notice.twig' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['csrf_token' => 'tok'];
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $this->twig = $builder->build()->get(Twig::class);
    }

    /**
     * @return array{0:int,1:string[]} [count of <h1>, list of level-jumps]
     */
    private function outline(string $html): array
    {
        preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER);
        $h1 = 0; $jumps = []; $prev = 0;
        foreach ($m as $h) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($h[2])));
            // The community-join modal is a site-wide dialog rendered with the
            // `hidden` attribute — assistive tech ignores it, so it must not
            // count toward the visible page outline.
            if (stripos($text, 'Join the Africa GATES community') !== false) continue;
            $lv = (int) $h[1];
            if ($lv === 1) $h1++;
            if ($prev > 0 && $lv > $prev + 1) $jumps[] = "h{$prev}->h{$lv}";
            $prev = $lv;
        }
        return [$h1, $jumps];
    }

    public function test_public_pages_have_one_h1_and_no_level_jumps(): void
    {
        foreach (self::CASES as $key => $ctx) {
            $tpl = explode('#', $key)[0];
            $html = $this->twig->fetch($tpl, $ctx);
            [$h1, $jumps] = $this->outline($html);
            $this->assertSame(1, $h1, "$key should have exactly one <h1>, found {$h1}");
            $this->assertSame([], $jumps, "$key has heading-level jump(s): " . implode(', ', $jumps));
        }
    }
}
