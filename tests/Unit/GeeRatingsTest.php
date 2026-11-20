<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\GuideController;
use AfricaGates\Services\GeeRatings;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Gee's answers: a visitor's thumb on one, and how the answer is drawn.
 *
 *   WHAT A RATING KEEPS   mode, source, the thumb and a PATH — never words, a query string or
 *                         anything about who pressed it. The privacy note says what is typed
 *                         stays in the tab; a rating that carried the words would make it false.
 *   WHAT THE OPERATOR SEES  30 days of thumbs, and the pages answered worst — only where there
 *                         are enough to be more than one unhappy visitor.
 *   HOW AN ANSWER IS DRAWN  gee.js format(), run through Node: lists and `code`, escaped first,
 *                         and nothing inside backticks becomes a link.
 */
final class GeeRatingsTest extends TestCase
{
    private function controller(): GuideController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(GuideController::class);
    }

    private function rate(array $body, string $ip = '203.0.113.9'): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/api/guide/rate', ['REMOTE_ADDR' => $ip])->withParsedBody($body);
        $res = $this->controller()->rate($req, (new ResponseFactory())->createResponse());
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true)];
    }

    public function test_a_rating_keeps_the_path_and_nothing_after_it(): void
    {
        [$code, $out] = $this->rate(['mode' => 'support', 'source' => 'support', 'helpful' => false, 'path' => '/support?ref=AFG-ABC123-9F2Q#x']);
        $this->assertSame(200, $code);
        $this->assertTrue($out['ok']);
        $row = DB::table('gates_gee_ratings')->orderByDesc('id')->first();
        $this->assertSame('/support', $row->path, 'a query on this site can carry a payment reference');
        $this->assertSame('support', $row->mode);
        $this->assertSame(0, (int) $row->helpful);

        $cols = array_map('strtolower', DB::schema()->getColumnListing('gates_gee_ratings'));
        foreach (['message', 'reply', 'text', 'body', 'ip', 'ip_hash', 'user_id', 'session'] as $never) {
            $this->assertNotContains($never, $cols, "a rating must not be able to hold `$never`");
        }
    }

    public function test_an_unknown_word_is_not_stored_as_one(): void
    {
        GeeRatings::record('admin', '<script>', true, 'https://evil.example/x');
        $row = DB::table('gates_gee_ratings')->orderByDesc('id')->first();
        $this->assertSame('guide', $row->mode);
        $this->assertSame('', $row->source);
        $this->assertSame('', $row->path, 'a host is not a path on this site');
        $this->assertSame('', GeeRatings::path('//evil.example/x'));
        $this->assertSame('/vote/12', GeeRatings::path('/vote/12?c=3'));
    }

    public function test_a_thumb_that_is_neither_way_is_refused(): void
    {
        $before = DB::table('gates_gee_ratings')->count();
        foreach ([[], ['helpful' => 'yes'], ['helpful' => 2], ['helpful' => null]] as $body) {
            [$code] = $this->rate($body + ['mode' => 'guide', 'path' => '/vote']);
            $this->assertSame(422, $code);
        }
        $this->assertSame($before, DB::table('gates_gee_ratings')->count());
        [$code] = $this->rate(['mode' => 'guide', 'helpful' => '1', 'path' => '/vote']);
        $this->assertSame(200, $code);
    }

    public function test_the_worst_pages_need_more_than_one_unhappy_visitor(): void
    {
        DB::table('gates_gee_ratings')->delete();
        // /shop: two of three down → named. /vote: one of one down → an anecdote.
        // /events: two of four down → not MORE down than up, not named.
        foreach ([['/shop', 0], ['/shop', 0], ['/shop', 1], ['/vote', 0],
                  ['/events', 0], ['/events', 0], ['/events', 1], ['/events', 1]] as [$p, $h]) {
            GeeRatings::record('guide', 'ai', (bool) $h, $p);
        }
        // Older than the window: not counted at all.
        DB::table('gates_gee_ratings')->insert(['mode' => 'guide', 'source' => 'ai', 'helpful' => 0, 'path' => '/vote',
            'created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);

        $s = GeeRatings::summary();
        $this->assertSame(8, $s['rated']);
        $this->assertSame(3, $s['helpful']);
        $this->assertSame([['path' => '/shop', 'down' => 2, 'rated' => 3]], $s['worst']);
    }

    public function test_the_client_sends_no_words_with_a_rating(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gee.js');
        $this->assertSame(1, preg_match("~post\('/api/guide/rate', \{([^}]*)\}~", $js, $m), 'the rating call is where it is expected');
        preg_match_all('/(\w+):/', $m[1], $keys);
        $this->assertSame(['mode', 'source', 'helpful', 'path'], $keys[1]);
    }

    // ── how an answer is drawn ───────────────────────────────────────────────

    private function format(string $text): string
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') $this->markTestSkipped('no Node.js on this machine to run gee.js');
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gee.js');
        $from = strpos($js, '  var ROUTES = {');
        $to   = strpos($js, '  function el(tag, cls, text) {');
        $this->assertNotFalse($from); $this->assertNotFalse($to);
        $src = "function M(n){ return n === 'help-link' ? 'the Help Centre answer' : ''; }\n"
             . substr($js, $from, $to - $from)
             . "\nprocess.stdout.write(format(" . json_encode($text) . "));";
        $proc = proc_open([$node, '-e', $src], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->assertSame('', $err);
        return (string) $out;
    }

    public function test_steps_are_a_list_and_a_lead_line_stays_a_sentence(): void
    {
        $this->assertSame('<p>To vote:</p><ol><li>Open <a href="/vote">Vote</a></li><li>Pick a <strong>nominee</strong></li></ol>',
            $this->format("To vote:\n1. Open /vote\n2. Pick a **nominee**"));
        $this->assertSame('<ul><li>one</li><li>two</li></ul>', $this->format("- one\n* two"));
        $this->assertSame('<ol><li>one</li><li>two</li></ol>', $this->format("1. one\n2) two"));
        $this->assertSame('<p>a<br>b</p><p>c</p>', $this->format("a\nb\n\nc"));
    }

    public function test_nothing_typed_survives_as_markup_and_code_is_shown_as_typed(): void
    {
        $html = $this->format('Paste `open /vote` here <img src=x onerror=alert(1)> [x](javascript:alert(1))');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<code>open /vote</code>', $html, 'a path inside backticks is not a link');
        $this->assertSame('<p><code>&lt;b&gt;</code></p>', $this->format('`<b>`'));
    }
}
