<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\HelpController;
use AfricaGates\Controllers\JudgesController;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The JSON-LD a page emits, rendered and parsed rather than grepped.
 *
 * ── WHY THIS RENDERS THE REAL TEMPLATE ───────────────────────────────────────
 *
 * The BreadcrumbList in the layout is hand-written JSON in Twig — a `{% for %}` that
 * lays out braces and commas itself, with `|json_encode` on each value. That is the
 * right shape (the alternative is escaping by hand), but it is also the shape whose
 * failure is invisible: a nominee called O'Brien "Jr" & Co, or a judge with a
 * newline in their title, produces a block that Google silently drops. Nothing
 * renders wrong, nothing 500s, and the rich result just never appears.
 *
 * So every assertion here goes through `json_decode`. A test that grepped for
 * `"@type":"BreadcrumbList"` would pass on a block that is not parseable JSON.
 */
final class SeoStructuredDataTest extends TestCase
{
    /** @return list<array<string,mixed>> every ld+json block on the page, decoded */
    private function blocks(string $html): array
    {
        preg_match_all(
            '~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s',
            $html, $m
        );
        $this->assertNotEmpty($m[1], 'the page emitted no structured data at all');

        $out = [];
        foreach ($m[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            $this->assertNotNull(
                $decoded,
                'a JSON-LD block did not parse: ' . json_last_error_msg() . ' — ' . mb_substr($raw, 0, 200)
            );
            $out[] = $decoded;
        }
        return $out;
    }

    /** @return array<string,mixed>|null the first block of a given @type */
    private function ofType(array $blocks, string $type): ?array
    {
        foreach ($blocks as $b) {
            if (($b['@type'] ?? null) === $type) return $b;
        }
        return null;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    private function render(string $class, string $method, array $args): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $res = $this->container()->get($class)->{$method}(
            $req, (new ResponseFactory())->createResponse(), $args
        );
        $this->assertSame(200, $res->getStatusCode(), "{$method} did not render");
        return (string) $res->getBody();
    }

    // ── Help answers ────────────────────────────────────────────────────────

    /**
     * A help answer whose title IS the question is an FAQPage in the literal sense.
     * The answer text is the article's own prose, so the markup can never claim
     * something the page does not visibly say.
     */
    public function test_a_help_answer_emits_an_faqpage_built_from_its_own_words(): void
    {
        $html = $this->render(HelpController::class, 'article', ['slug' => 'paid-but-no-votes']);
        $faq  = $this->ofType($this->blocks($html), 'FAQPage');

        $this->assertNotNull($faq, 'the answer page emitted no FAQPage');
        $q = $faq['mainEntity'][0];
        $this->assertSame('I paid but my votes have not appeared', $q['name']);
        $this->assertStringContainsString('Your money is not lost', $q['acceptedAnswer']['text']);
        // The summary is the first thing plainText() returns, and it was once
        // concatenated with plainText() again — printing the opening sentence twice.
        $this->assertSame(
            1,
            substr_count($q['acceptedAnswer']['text'], 'Almost always fixable in under a minute'),
            'the answer text repeats its own first sentence'
        );
    }

    /**
     * The visible crumb used to link `/help?q={category title}` — a search URL,
     * which is now noindex. A breadcrumb must not walk a crawler into a page marked
     * "do not index" when the category page exists.
     */
    public function test_the_help_breadcrumb_points_at_the_category_page_not_a_search(): void
    {
        $html   = $this->render(HelpController::class, 'article', ['slug' => 'paid-but-no-votes']);
        $trail  = $this->ofType($this->blocks($html), 'BreadcrumbList');

        $this->assertNotNull($trail);
        $items = array_column($trail['itemListElement'], 'item');
        $this->assertNotEmpty(array_filter($items, fn($u) => str_contains((string) $u, '/help/c/payments')));
        $this->assertEmpty(
            array_filter($items, fn($u) => str_contains((string) $u, '/help?q=')),
            'the trail links a noindex search URL'
        );
        // The page itself is the last crumb and is not a link. An entry pointing at a
        // URL that does not exist is invalid, and Google drops the whole trail — not
        // the one entry — when it finds one.
        $last = end($trail['itemListElement']);
        $this->assertArrayNotHasKey('item', $last);
        $this->assertSame(count($trail['itemListElement']), $last['position']);
    }

    public function test_breadcrumb_positions_start_at_one_and_do_not_skip(): void
    {
        $html  = $this->render(HelpController::class, 'article', ['slug' => 'paid-but-no-votes']);
        $trail = $this->ofType($this->blocks($html), 'BreadcrumbList');

        $this->assertSame(
            range(1, count($trail['itemListElement'])),
            array_column($trail['itemListElement'], 'position')
        );
    }

    // ── A person page, with a hostile name ──────────────────────────────────

    /**
     * The escaping test with real teeth. A judge whose name carries an apostrophe, a
     * double quote, an ampersand and a non-ASCII letter is exactly the row that turns
     * hand-laid JSON into a silent drop.
     */
    public function test_a_judge_page_survives_a_name_full_of_json_hostile_characters(): void
    {
        $name = 'Ọlá "The Bridge" O\'Brien & Sons';
        DB::table('gates_judges')->insert([
            'id' => 7701, 'name' => $name, 'email' => 'ola@example.test',
            'title' => "Chair,\nWest Africa",
            'organisation' => 'Ford & Co "Lagos"', 'bio' => "Line one\nLine <two> & 'three'",
            'country_code' => 'NG', 'is_active' => 1,
        ]);

        $slug   = \AfricaGates\Support\Slug::make($name, 60);
        $html   = $this->render(JudgesController::class, 'show', ['slug' => '7701-' . $slug]);
        $blocks = $this->blocks($html);          // every block must parse

        $person = $this->ofType($blocks, 'Person');
        $this->assertNotNull($person, 'a judge page is a name page and must emit Person');
        $this->assertSame($name, $person['name']);
        $this->assertSame('Ford & Co "Lagos"', $person['affiliation']['name']);

        $trail = $this->ofType($blocks, 'BreadcrumbList');
        $this->assertNotNull($trail);
        $this->assertSame($name, end($trail['itemListElement'])['name']);
    }

    /** A judge is not a nominee: the award line belongs only on a ballot. */
    public function test_a_judge_is_not_described_as_a_nominee(): void
    {
        DB::table('gates_judges')->insert([
            'id' => 7702, 'name' => 'Plain Judge', 'email' => 'plain@example.test',
            'is_active' => 1, 'country_code' => 'NG',
        ]);

        $html   = $this->render(JudgesController::class, 'show', ['slug' => '7702-plain-judge']);
        $person = $this->ofType($this->blocks($html), 'Person');

        $this->assertArrayNotHasKey('award', $person);
    }
}
