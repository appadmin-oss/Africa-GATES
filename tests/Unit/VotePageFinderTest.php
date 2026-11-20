<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VoteController;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * FIND, MOVEMENT, YOUR PICK, on an edition's vote page (owner, 16 Nov 2026: "brainstorm … short
 * UX-optimised feature … make it beautiful"). vote.js does the work in the browser; what the
 * server owes it, and what these hold, is the markup it reads and the numbers it patches:
 *
 *   · the finder is drawn (hidden, for the script to reveal) once there are enough nominees for
 *     scanning to be the problem, and not before — six;
 *   · every word the script writes comes from the template, translated, never typed in JS;
 *   · each row's gap carries its NUMBERS, so "your pick" and the live refresh can say it again;
 *   · the live tallies carry the next place up, or the refreshed gap could not name it — the
 *     gap was never refreshed at all before, and a row could read #1 beside "14 behind #1".
 *
 * Ids below 255, because a seeded id above a TINYINT is clamped on MySQL (CLAUDE.md).
 */
final class VotePageFinderTest extends TestCase
{
    private const P = 71, Y = 7101, C = 7111;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'find-award', 'title' => 'Find Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-3 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
    }

    private function nominees(int $n): void
    {
        $names = ['Chiamaka Eze', 'Ibrahim Musa', 'Zainab Lawal', 'Segun Ogunleye', 'Emeka Nwachukwu', 'Funmilayo Adebayo', 'Ngozi Bello'];
        for ($i = 0; $i < $n; $i++) {
            DB::table('gates_nominees')->insert(['name' => $names[$i], 'category_id' => self::C, 'status' => 'approved',
                'vote_count' => 100 - $i * 10, 'organic_vote_count' => 100 - $i * 10]);
        }
    }

    private function ctrl(): VoteController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VoteController::class);
    }

    private function page(): string
    {
        $res = $this->ctrl()->program((new ServerRequestFactory())->createServerRequest('GET', '/vote/find-award'), new Response(), ['program' => 'find-award']);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    public function test_the_finder_appears_when_scanning_is_the_problem(): void
    {
        $this->nominees(5);
        $this->assertStringNotContainsString('data-find-q', $this->page(), 'a search box over five names is clutter');
        DB::table('gates_nominees')->insert(['name' => 'Ngozi Bello', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 1, 'organic_vote_count' => 1]);
        $html = $this->page();
        $this->assertMatchesRegularExpression('~<div class="vp-find" data-find hidden>~', $html, 'drawn visible, the box would do nothing with no script');
        $this->assertStringContainsString('<label class="ag-sr" for="vpFindQ">Find a nominee</label>', $html);
        $this->assertStringContainsString('type="search"', $html);
        $this->assertStringContainsString('role="status" aria-live="polite" data-find-said', $html, 'a filter a screen reader is not told about');
        $this->assertStringContainsString('data-t="No nominee matches “%q%”."', $html);
        $this->assertStringContainsString('data-find-clear>Clear the search</button>', $html);
    }

    public function test_every_word_the_script_writes_is_the_templates(): void
    {
        $this->nominees(6);
        $html = $this->page();
        foreach (['data-w-lead="Leading"', 'data-w-behind="%n% behind #%r%"', 'data-w-up="Up %n%"', 'data-w-since="%what% since your last visit"',
                  'data-w-pick="Your pick"', 'data-w-share="Share their link"', 'data-w-found="%n% nominees match"', 'data-award="Find Award"', 'data-open'] as $attr) {
            $this->assertStringContainsString($attr, $html);
        }
        // Each gap carries its numbers: the second-placed is 10 behind #1; the leader has none.
        $this->assertMatchesRegularExpression('~data-gap data-gap-n="10" data-gap-r="1">10 behind #1<~', $html);
        $this->assertMatchesRegularExpression('~data-gap data-gap-n="" data-gap-r="">Leading<~', $html);

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/vote.js');
        foreach (['finder(root);', 'movement(root, pid);', 'picks(root, pid);', "getAttribute('data-w-behind')", "get('seen:' + pid)"] as $hook) {
            $this->assertStringContainsString($hook, $js, 'vote.js no longer does what this page draws for it');
        }
        $this->assertDoesNotMatchRegularExpression('~textContent\s*=\s*[\'"][A-Za-z]~', $js, 'a word typed into the script, which no translation reaches');
    }

    public function test_the_live_tallies_carry_the_next_place_up(): void
    {
        $this->nominees(3);
        $res = $this->ctrl()->tallies((new ServerRequestFactory())->createServerRequest('GET', '/vote/find-award/tallies'), new Response(), ['program' => 'find-award']);
        $d = json_decode((string) $res->getBody(), true);
        $this->assertTrue($d['ok'] ?? false);
        $rows = array_column($d['categories'][0]['nominees'], null, 'rank');
        $this->assertNull($rows[1]['gap']);
        $this->assertSame(10, $rows[2]['gap']);
        $this->assertSame(1, $rows[2]['next'], 'the refreshed gap could not name the place it is behind');
    }
}
