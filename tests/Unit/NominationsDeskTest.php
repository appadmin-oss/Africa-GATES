<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DI\ContainerBuilder;
use AfricaGates\Admin\Controllers\NominationsController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * Review-desk SPA back-end: the fragment endpoint returns the next pending
 * nomination as JSON+HTML, and approve/reject over fetch answer JSON (so the
 * desk swaps in the next one) while the classic no-JS flow still redirects.
 */
class NominationsDeskTest extends TestCase
{
    private Twig $twig;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'tok';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'stem', 'title' => 'STEM', 'icon_emoji' => '🔬']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'nominations']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'innovation', 'title' => 'Innovation', 'description' => 'd', 'sort_order' => 1]);
        DB::table('gates_nominations')->insert([
            'id' => 10, 'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'country_code' => 'NG',
            'nominator_name' => 'Ben', 'nominator_email' => 'ben@x.io', 'reason' => 'Great work',
            'status' => 'pending', 'created_at' => '2026-07-01 10:00:00',
        ]);

        // Real container-built Twig so the fetched partial has all globals/filters.
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $this->twig = $builder->build()->get(Twig::class);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function controller(): NominationsController
    {
        return new NominationsController($this->twig, new AuditService());
    }

    private function json(Response $res): array
    {
        return (array) json_decode((string) $res->getBody(), true);
    }

    public function test_desk_fragment_returns_next_pending_as_html(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/nominations/review/next');
        $res = $this->controller()->deskFragment($req, new Response());

        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $j = $this->json($res);
        $this->assertTrue($j['ok']);
        $this->assertFalse($j['done']);
        $this->assertSame(10, $j['id']);
        $this->assertSame('Ada Obi', $j['nominee']);
        $this->assertSame(1, $j['position']);
        $this->assertSame(1, $j['total']);
        $this->assertStringContainsString('Ada Obi', $j['html']);
        $this->assertStringContainsString('data-dk-form="decide"', $j['html']);   // the one decision form
        $this->assertStringContainsString('Innovation', $j['html']);              // the category to approve into
        // The four reasons a rejection gives — under their own key, because the nomination
        // already has a `reasons` field (the case per category) and it used to win.
        foreach (\AfricaGates\Admin\Services\ReviewDesk::REASONS as $label) $this->assertStringContainsString($label, $j['html']);
        // The design's "Nominator phone verified" is not drawn: nothing here verifies one.
        $this->assertStringNotContainsString('phone verified', strtolower($j['html']));
    }

    public function test_desk_fragment_reports_done_once_the_last_one_is_decided(): void
    {
        DB::table('gates_nominations')->where('id', 10)->update(['status' => 'approved']);
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/nominations/review/next?after=10')
            ->withQueryParams(['after' => '10']);
        $j = $this->json($this->controller()->deskFragment($req, new Response()));
        $this->assertTrue($j['ok']);
        $this->assertTrue($j['done']);
        $this->assertNotEmpty($j['message']);
    }

    public function test_a_decided_item_opens_the_one_after_it_and_the_end_wraps(): void
    {
        DB::table('gates_nominations')->insert([
            ['id' => 11, 'cycle_id' => 1, 'nominee_name' => 'Bola Ade', 'country_code' => 'NG', 'nominator_name' => 'Cy',
             'nominator_email' => 'cy@x.io', 'reason' => 'r', 'status' => 'pending', 'created_at' => '2026-07-02 10:00:00'],
            ['id' => 12, 'cycle_id' => 1, 'nominee_name' => 'Chi Eze', 'country_code' => 'NG', 'nominator_name' => 'Di',
             'nominator_email' => 'di@x.io', 'reason' => 'r', 'status' => 'pending', 'created_at' => '2026-07-03 10:00:00'],
        ]);
        $frag = fn (array $q): array => $this->json($this->controller()->deskFragment(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/nominations/review/next')->withQueryParams($q), new Response()));
        DB::table('gates_nominations')->where('id', 11)->update(['status' => 'approved']);
        $this->assertSame(12, $frag(['item' => '11'])['id'], 'a decided item must open the one after it');
        $this->assertSame(10, $frag(['after' => '12'])['id'], 'the end of the queue wraps, so a skipped one comes round again');
        $this->assertSame(12, $frag(['item' => '12'])['id']);
    }

    public function test_the_desk_rejects_only_with_one_of_its_four_reasons_and_the_nominator_reads_it(): void
    {
        $post = fn (array $body): Response => $this->controller()->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/10/decide')
                ->withHeader('X-Requested-With', 'fetch')->withParsedBody($body + ['desk' => '1']),
            new Response(), ['id' => 10, 'action' => 'decide']);

        $res = $post(['choice' => 'reject']);
        $this->assertSame(422, $res->getStatusCode(), 'a desk rejection with no reason picked must be refused, not drafted');
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status'));

        $res = $post(['choice' => 'reject', 'reject_reason' => 'thin', 'team_note' => 'Asked twice already.']);
        $this->assertTrue($this->json($res)['ok']);
        $row = DB::table('gates_nominations')->where('id', 10)->first();
        $this->assertSame('rejected', $row->status);
        $this->assertSame('Still not enough information after we asked.', $row->decision_reason);
        if (\AfricaGates\Support\SchemaHas::table('gates_nomination_notes')) {
            $this->assertSame('Asked twice already.', DB::table('gates_nomination_notes')->where('nomination_id', 10)->value('body'));
        }
    }

    public function test_decide_refuses_a_choice_it_does_not_know(): void
    {
        $res = $this->controller()->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/10/decide')
                ->withHeader('X-Requested-With', 'fetch')->withParsedBody(['choice' => 'pending', 'desk' => '1']),
            new Response(), ['id' => 10, 'action' => 'decide']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status'));
    }

    /** @return array{0: \AfricaGates\Services\OtpService, 1: \ArrayObject} */
    private function mailer(bool $works = true): array
    {
        $sink = new \ArrayObject();
        $m = new class($sink, $works) extends \AfricaGates\Services\OtpService {
            public function __construct(private \ArrayObject $sink, private bool $works) { parent::__construct([]); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            {
                if (!$this->works) return ['success' => false];
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody, 'plain' => $plainBody];
                return ['success' => true];
            }
        };
        return [$m, $sink];
    }

    private function ask(\AfricaGates\Services\OtpService $m, string $message): Response
    {
        return (new NominationsController($this->twig, new AuditService(), $m))->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/10/decide')
                ->withHeader('X-Requested-With', 'fetch')
                ->withParsedBody(['choice' => 'ask', 'asked_message' => $message, 'desk' => '1']),
            new Response(), ['id' => 10, 'action' => 'decide']);
    }

    public function test_ask_for_more_emails_one_link_that_answers_it_and_sends_it_back_to_the_queue(): void
    {
        [$m, $sink] = $this->mailer();
        $res = $this->ask($m, 'Please add one photo of the class.');
        $this->assertTrue($this->json($res)['ok'], (string) $res->getBody());
        $row = DB::table('gates_nominations')->where('id', 10)->first();
        $this->assertSame('needs_details', $row->status);
        $this->assertSame('Please add one photo of the class.', $row->asked_message);

        $this->assertCount(1, $sink);
        $this->assertSame('ben@x.io', $sink[0]['to']);
        $this->assertStringContainsString('Please add one photo of the class.', $sink[0]['plain']);
        $this->assertMatchesRegularExpression('~/nominate/answer/([a-f0-9]{64})~', $sink[0]['plain']);
        preg_match('~/nominate/answer/([a-f0-9]{64})~', $sink[0]['plain'], $t);
        $this->assertNotSame($t[1], $row->ask_token_hash, 'the working link must never be stored');

        $nom = \AfricaGates\Services\NominationAsk::find($t[1]);
        $this->assertNotNull($nom, 'the emailed link must open the nomination it asks about');
        $this->assertSame(0, \AfricaGates\Services\NominationAsk::answer($nom, ['javascript:alert(1)'], []),
            'a link that is not http(s) must not be kept');
        $this->assertSame('needs_details', DB::table('gates_nominations')->where('id', 10)->value('status'));

        $this->assertSame(1, \AfricaGates\Services\NominationAsk::answer($nom, ['https://example.org/class-photo'], []));
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status'), 'an answer goes back to the queue');
        $this->assertSame(1, (int) DB::table('gates_nomination_evidence')->where('nomination_id', 10)->count());
        $this->assertNull(\AfricaGates\Services\NominationAsk::find($t[1]), 'one answer per ask: the link is spent');
    }

    public function test_an_ask_that_does_not_reach_the_nominator_changes_nothing(): void
    {
        [$m] = $this->mailer(false);
        $res = $this->ask($m, 'Please add a link.');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status'));

        DB::table('gates_nominations')->where('id', 10)->update(['nominator_email' => '']);
        [$m2, $sink] = $this->mailer();
        $this->assertSame(422, $this->ask($m2, 'Please add a link.')->getStatusCode(), 'with no address there is nobody to ask');
        $this->assertCount(0, $sink);
    }

    public function test_an_expired_link_opens_nothing(): void
    {
        [$m, $sink] = $this->mailer();
        $this->ask($m, 'Please add a link.');
        preg_match('~/nominate/answer/([a-f0-9]{64})~', $sink[0]['plain'], $t);
        DB::table('gates_nominations')->where('id', 10)->update([
            'asked_at' => date('Y-m-d H:i:s', time() - (\AfricaGates\Services\NominationAsk::TTL_DAYS + 1) * 86400)]);
        $this->assertNull(\AfricaGates\Services\NominationAsk::find($t[1]));
    }

    public function test_merge_joins_the_earlier_approved_entry_and_never_mints_a_second_nominee(): void
    {
        DB::table('gates_nominations')->insert(['id' => 9, 'cycle_id' => 1, 'category_id' => 1, 'nominee_name' => 'Ada Obi',
            'country_code' => 'NG', 'nominator_name' => 'Eko', 'nominator_email' => 'eko@x.io', 'reason' => 'r',
            'status' => 'approved', 'created_at' => '2026-06-01 10:00:00']);
        DB::table('gates_nominees')->insert(['category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0]);

        $html = $this->json($this->controller()->deskFragment(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/nominations/review/next')->withQueryParams(['item' => '10']), new Response()))['html'];
        $this->assertStringContainsString('Possible duplicate', $html);
        $this->assertStringContainsString('Merge into earlier', $html);
        $this->assertStringContainsString('Resolve the duplicate first', $html);

        $res = $this->controller()->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/10/merge')
                ->withHeader('X-Requested-With', 'fetch')->withParsedBody(['merge_into' => '9', 'desk' => '1']),
            new Response(), ['id' => 10, 'action' => 'merge']);
        $this->assertTrue($this->json($res)['ok'], (string) $res->getBody());
        $row = DB::table('gates_nominations')->where('id', 10)->first();
        $this->assertSame('approved', $row->status, 'a merge is an approval into the earlier entry, never a rejection');
        $this->assertSame(1, (int) $row->category_id);
        $this->assertSame(9, (int) $row->merged_into);
        $this->assertSame(1, (int) DB::table('gates_nominees')->where('category_id', 1)->count(), 'a merge must not mint a second nominee');
    }

    public function test_merge_into_an_entry_that_is_not_approved_is_refused(): void
    {
        DB::table('gates_nominations')->insert(['id' => 9, 'cycle_id' => 1, 'category_id' => 1, 'nominee_name' => 'Ada Obi',
            'country_code' => 'NG', 'nominator_name' => 'Eko', 'nominator_email' => 'eko@x.io', 'reason' => 'r',
            'status' => 'pending', 'created_at' => '2026-06-01 10:00:00']);
        $res = $this->controller()->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/10/merge')
                ->withHeader('X-Requested-With', 'fetch')->withParsedBody(['merge_into' => '9', 'desk' => '1']),
            new Response(), ['id' => 10, 'action' => 'merge']);
        $this->assertSame(422, $res->getStatusCode());
        // Refused by the rule, before anything is written — not by the later safety net
        // that finds no nominee to join.
        $this->assertStringContainsString('Only an approved entry', (string) $this->json($res)['error']);
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status'));
    }

    public function test_not_a_duplicate_takes_the_pair_off_both_rows(): void
    {
        DB::table('gates_nominations')->insert(['id' => 9, 'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'country_code' => 'NG',
            'nominator_name' => 'Eko', 'nominator_email' => 'eko@x.io', 'reason' => 'r', 'status' => 'pending',
            'created_at' => '2026-06-01 10:00:00']);
        $keys = fn (int $id): array => \AfricaGates\Admin\Services\ReviewQueue::peek($id)['keys'];
        $this->assertContains('dup', $keys(10));
        $this->assertContains('dup', $keys(9));

        $res = $this->controller()->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/10/apart')
                ->withHeader('X-Requested-With', 'fetch')->withParsedBody(['desk' => '1']),
            new Response(), ['id' => 10, 'action' => 'apart']);
        $this->assertTrue($this->json($res)['ok']);
        $this->assertNotContains('dup', $keys(10));
        $this->assertNotContains('dup', $keys(9), 'the ruling must clear the earlier row too, or the same pair is flagged for ever');
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status'), 'the ruling is not a decision');
    }

    public function test_approve_over_fetch_returns_json_and_creates_nominee(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/approve')
            ->withHeader('X-Requested-With', 'fetch')
            ->withParsedBody(['category_id' => 1, 'desk' => '1']);
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'approve']);

        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $j = $this->json($res);
        $this->assertTrue($j['ok']);
        $this->assertNotEmpty($j['message']);
        $this->assertSame('approved', DB::table('gates_nominations')->where('id', 10)->value('status'));
        $this->assertSame(1, (int) DB::table('gates_nominees')->where('category_id', 1)->count());
    }

    public function test_approve_over_fetch_without_category_returns_422(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/approve')
            ->withHeader('X-Requested-With', 'fetch')
            ->withParsedBody(['desk' => '1']);
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'approve']);

        $this->assertSame(422, $res->getStatusCode());
        $j = $this->json($res);
        $this->assertFalse($j['ok']);
        $this->assertNotEmpty($j['error']);
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', 10)->value('status')); // untouched
    }

    public function test_reject_over_fetch_returns_json(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/reject')
            ->withHeader('X-Requested-With', 'fetch')
            ->withParsedBody(['desk' => '1', 'decision_reason' => 'Not enough detail.']);
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'reject']);

        $j = $this->json($res);
        $this->assertTrue($j['ok']);
        $this->assertSame('rejected', DB::table('gates_nominations')->where('id', 10)->value('status'));
    }

    public function test_classic_approve_still_redirects(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/nominations/10/approve')
            ->withParsedBody(['category_id' => 1]); // no X-Requested-With
        $res = $this->controller()->action($req, new Response(), ['id' => 10, 'action' => 'approve']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_ok'] ?? '');
    }
}
