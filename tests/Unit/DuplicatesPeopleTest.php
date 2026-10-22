<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\IdentityVerdicts;
use AfricaGates\Admin\Services\NomineePeople;
use AfricaGates\Admin\Services\VoterPeople;
use AfricaGates\Services\MergeService;
use AfricaGates\Services\VoteService;
use AfricaGates\Support\EmailCanon;
use AfricaGates\Support\Slug;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Duplicates and net counts (`/admin/nominees/duplicates`): people, not entries.
 *
 * Each case here is a claim the screen prints, asserted on the arithmetic behind it —
 * and the merge preview is asserted to be the merge (same totals) AND to leave nothing
 * behind, because a preview that writes is a merge nobody confirmed.
 */
final class DuplicatesPeopleTest extends TestCase
{
    private int $cycle = 0;
    private int $catA = 0;
    private int $catB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'dupes-awards', 'title' => 'Dupes', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'voting', 'edition_label' => 'dupes',
        ]);
        $this->catA = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'a', 'title' => 'Teacher', 'sort_order' => 1]);
        $this->catB = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'b', 'title' => 'Mentor', 'sort_order' => 2]);
    }

    private function nominee(int $cat, string $name, array $extra = []): int
    {
        return (int) DB::table('gates_nominees')->insertGetId($extra + [
            'category_id' => $cat, 'name' => $name, 'status' => 'approved',
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
    }

    private function vote(int $nominee, int $cat, string $hash, ?string $canon = null, string $type = 'standard'): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nominee, 'category_id' => $cat, 'voter_email_hash' => $hash,
            'vote_type' => $type, 'voter_canon_hash' => $canon,
        ]);
        DB::table('gates_nominees')->where('id', $nominee)->increment('vote_count');
    }

    // ── EmailCanon ──────────────────────────────────────────────────────────

    public function test_one_inbox_is_one_key_and_only_gmail_drops_dots(): void
    {
        $this->assertSame('ada@gmail.com', EmailCanon::key(' A.Da+votes@GoogleMail.com '));
        $this->assertSame('ada@example.org', EmailCanon::key('ada+x@example.org'));
        // A dot is a different mailbox everywhere but Gmail — joining them would join strangers.
        $this->assertSame('a.da@example.org', EmailCanon::key('a.da@example.org'));
        $this->assertSame('', EmailCanon::key('not-an-address'));
        $this->assertNull(EmailCanon::hash(''));
        $this->assertSame(EmailCanon::hash('ada@gmail.com'), EmailCanon::hash('a.d.a+2@gmail.com'));
    }

    // ── nominated people ────────────────────────────────────────────────────

    public function test_one_person_in_two_categories_is_one_person_and_not_a_merge(): void
    {
        $this->nominee($this->catA, 'Chinwe Okafor');
        $this->nominee($this->catB, 'Okafor, Chinwe');
        $this->nominee($this->catA, 'Thabo Mbeki');

        $m = NomineePeople::forCycle($this->cycle);
        $this->assertSame(3, $m['entries']);
        $this->assertSame(2, $m['people'], 'the same name across two categories was counted twice');
        $this->assertCount(1, $m['clusters']);
        $this->assertSame([], $m['clusters'][0]['mergeable'], 'two categories were offered as a merge');
    }

    public function test_split_votes_in_one_category_are_mergeable(): void
    {
        $a = $this->nominee($this->catA, 'Kwame Nkrumah');
        $b = $this->nominee($this->catA, 'kwame  nkrumah');
        $m = NomineePeople::forCycle($this->cycle);
        $this->assertSame(1, $m['people']);
        $this->assertSame([min($a, $b), max($a, $b)], $m['clusters'][0]['mergeable'][0]['ids']);
    }

    public function test_a_near_match_is_listed_but_not_counted_until_somebody_rules(): void
    {
        $a = $this->nominee($this->catA, 'Kwame Nkrumah');
        $b = $this->nominee($this->catB, 'K. Nkrumah');
        $m = NomineePeople::forCycle($this->cycle);
        $this->assertSame(2, $m['people'], 'a near match was counted as one person on its own');
        $this->assertSame(1, $m['people_low']);
        $this->assertSame(1, $m['possible']);

        IdentityVerdicts::record('nominee', [$a, $b], IdentityVerdicts::SAME, 'human', null, 'checked', 1);
        $this->assertSame(1, NomineePeople::forCycle($this->cycle)['people'], 'a person ruled them the same and the count ignored it');
    }

    public function test_the_ai_counts_only_above_the_bar_and_never_over_a_person(): void
    {
        $a = $this->nominee($this->catA, 'Kwame Nkrumah');
        $b = $this->nominee($this->catB, 'K. Nkrumah');

        IdentityVerdicts::record('nominee', [$a, $b], IdentityVerdicts::SAME, 'ai', 0.6, 'maybe');
        $this->assertSame(2, NomineePeople::forCycle($this->cycle)['people'], 'an unsure AI answer was counted');

        IdentityVerdicts::record('nominee', [$a, $b], IdentityVerdicts::SAME, 'ai', 0.95, 'same school');
        $this->assertSame(1, NomineePeople::forCycle($this->cycle)['people']);

        IdentityVerdicts::record('nominee', [$a, $b], IdentityVerdicts::DIFFERENT, 'human', null, 'two people', 1);
        $this->assertSame(0, IdentityVerdicts::record('nominee', [$a, $b], IdentityVerdicts::SAME, 'ai', 0.99, 'sure'),
            'the AI overwrote a person\'s ruling');
        $this->assertSame(2, NomineePeople::forCycle($this->cycle)['people']);
    }

    public function test_a_merged_entry_is_not_counted(): void
    {
        $a = $this->nominee($this->catA, 'Ada Obi');
        $b = $this->nominee($this->catA, 'Ada Obi');
        $this->assertTrue(MergeService::mergeNominees($a, [$b])['ok']);
        $m = NomineePeople::forCycle($this->cycle);
        $this->assertSame(1, $m['entries']);
        $this->assertSame([], $m['clusters']);
    }

    // ── voters ──────────────────────────────────────────────────────────────

    public function test_net_voters_join_one_inbox_and_nothing_else(): void
    {
        $n = $this->nominee($this->catA, 'Ada Obi');
        $m = $this->nominee($this->catB, 'Bisi Ade');
        $canon = EmailCanon::hash('ada@gmail.com');
        // Two addresses for one inbox, voting in two categories; one unrelated voter.
        $this->vote($n, $this->catA, VoteService::voterHash('ada+1@gmail.com'), $canon);
        $this->vote($m, $this->catB, VoteService::voterHash('a.da@gmail.com'), $canon);
        $this->vote($n, $this->catB, VoteService::voterHash('ola@example.org'), EmailCanon::hash('ola@example.org'));
        // A grant is nobody.
        $this->vote($n, $this->catA, 'bonus:9:abc', null, 'bonus');

        $v = VoterPeople::count($this->cycle);
        $this->assertTrue($v['ok']);
        $this->assertSame(4, $v['recorded_ids']);
        $this->assertSame(3, $v['people']);
        $this->assertSame(2, $v['net'], 'one inbox under two addresses was counted twice');
        $this->assertSame(1, $v['alias_joins']);
        $this->assertSame(1, $v['grant_rows']);
    }

    // ── the merge: preview, kept fields, undo, and the public link ─────────────

    public function test_the_preview_is_the_merge_and_leaves_nothing_behind(): void
    {
        $a = $this->nominee($this->catA, 'Ada Obi', ['story' => '']);
        $b = $this->nominee($this->catA, 'Ada  Obi', ['story' => 'Taught 400 girls to code.']);
        $this->vote($a, $this->catA, 'h1');
        $this->vote($b, $this->catA, 'h2');
        $this->vote($b, $this->catA, 'h3');
        $before = DB::table('gates_votes')->orderBy('id')->get(['id', 'nominee_id'])->toArray();

        $p = MergeService::preview($a, [$b]);
        $this->assertTrue($p['ok'], $p['error'] ?? '');
        $this->assertTrue($p['dry_run']);
        $this->assertSame(3, $p['votes']);
        $this->assertSame(2, $p['moved']['gates_votes'] ?? 0);
        $this->assertSame('Taught 400 girls to code.', $p['survivor']['story'], 'an empty story was not filled from the other entry');

        $this->assertEquals($before, DB::table('gates_votes')->orderBy('id')->get(['id', 'nominee_id'])->toArray(), 'the preview moved votes');
        $this->assertNull(DB::table('gates_nominees')->where('id', $b)->value('merged_into'), 'the preview merged');
        $this->assertSame(0, DB::table('gates_merge_log')->count(), 'the preview wrote a journal');
        $this->assertSame('', (string) DB::table('gates_nominees')->where('id', $a)->value('story'));

        $r = MergeService::mergeNominees($a, [$b]);
        $this->assertSame($p['votes'], $r['votes'], 'the preview and the merge disagree');
    }

    public function test_the_survivor_keeps_the_field_the_admin_chose_and_an_undo_puts_it_back(): void
    {
        $a = $this->nominee($this->catA, 'Ada Obi');
        $b = $this->nominee($this->catA, 'Adaeze Obi');

        $r = MergeService::mergeNominees($a, [$b], null, false, ['name' => $b]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame('Adaeze Obi', DB::table('gates_nominees')->where('id', $a)->value('name'));

        $this->assertTrue(MergeService::unmerge($b)['ok']);
        $this->assertSame('Ada Obi', DB::table('gates_nominees')->where('id', $a)->value('name'), 'undo left the borrowed name');
    }

    public function test_a_merged_entrys_public_link_goes_to_the_survivor(): void
    {
        $a = $this->nominee($this->catA, 'Ada Obi');
        $b = $this->nominee($this->catA, 'ada obi');
        $this->assertTrue(MergeService::mergeNominees($a, [$b])['ok']);
        $this->assertSame($a, MergeService::survivorOf($b));
        $this->assertNull(MergeService::survivorOf($a));

        $res = $this->get('/vote/dupes-awards/' . Slug::idSegment($b, 'ada obi'));
        $this->assertSame(302, $res->getStatusCode(), 'a merged entry\'s campaign link 404s');
        $this->assertStringContainsString('/vote/dupes-awards/' . $a, $res->getHeaderLine('Location'));

        $legacy = $this->get('/vote/' . $b);
        $this->assertStringContainsString('/vote/dupes-awards/' . $a, $legacy->getHeaderLine('Location'));
    }

    public function test_a_redirect_never_reaches_into_the_sandbox(): void
    {
        $a = $this->nominee($this->catA, 'Ada Obi');
        $b = $this->nominee($this->catA, 'ada obi');
        $this->assertTrue(MergeService::mergeNominees($a, [$b])['ok']);
        DB::table('gates_award_programmes')->where('slug', 'dupes-awards')->update(['is_active' => 0]);
        $res = $this->get('/vote/' . $b);
        $this->assertSame('/vote', $res->getHeaderLine('Location'));
    }

    // ── the vote writes the key the count reads ───────────────────────────────

    public function test_an_organic_vote_writes_its_inbox_key(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/VoteService.php');
        $this->assertMatchesRegularExpression('/voter_canon_hash.{0,40}EmailCanon::hash\(\$email\)/s', $src,
            'the organic vote no longer records the inbox key its migration promises');
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }
}
