<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\NomineeProfileController;
use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\NomineeDetails;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The nominee's own page from the moment they are nominated (owner, 2 Nov 2026): "You've been
 * nominated" asks for what THIS nomination lacks — evidence first, then the places the award
 * still needs, then the blanks — and links to the same /account/nomination page approval hands
 * them later. Each test holds one promise:
 *   · the email names what is missing and the places still needed, with a link that opens the page;
 *   · nothing the nominator typed reaches the nominee before review, in the email or on the page;
 *   · no link goes to the nominator's own inbox;
 *   · what the nominee adds before review is kept, and approval moves it under the nominee;
 *   · a nomination turned down takes no more, and a repeat combined in today is not mailed again.
 */
final class NomineeDetailsEarlyTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;
    private int $seq = 0;
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = 'tp-' . bin2hex(random_bytes(3));
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => $this->slug, 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . bin2hex(random_bytes(2)), 'title' => 'Teacher of the Year', 'sort_order' => 0]);
        DB::table('gates_settings')->whereIn('key_name', ['nomination_eligibility_enabled', 'nomination_min_locations'])->delete();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['login_next'], $_SESSION['nominee_ok'], $_SESSION['nominee_error'], $_SESSION['nominee_open']);
        parent::tearDown();
    }

    private function nom(array $o = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($o + [
            'cycle_id' => $this->cycle, 'category_id' => $this->cat, 'nominee_name' => 'Grace Mensah', 'country_code' => 'NG',
            'nominee_email' => 'grace@school.ng', 'nominator_name' => 'Nominator ' . (++$this->seq),
            'nominator_email' => 'n' . $this->seq . '@friends.ng', 'nominator_country' => 'NG', 'nominator_state' => 'Lagos',
            'reason' => 'Pay the processing fee to receive your award.', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function member(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Grace Mensah', 'email' => 'grace' . bin2hex(random_bytes(3)) . '@school.ng',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function ctrl(): NomineeProfileController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(NomineeProfileController::class);
    }

    private function token(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }

    private function page(int $nid): string
    {
        return (string) $this->ctrl()->pending((new ServerRequestFactory())->createServerRequest('GET', '/account/nomination/pending/' . $nid), new Response(), ['nid' => (string) $nid])->getBody();
    }

    private function post(int $nid, array $b): string
    {
        unset($_SESSION['nominee_ok'], $_SESSION['nominee_error']);
        $this->ctrl()->save((new ServerRequestFactory())->createServerRequest('POST', '/account/nomination/pending/' . $nid)->withParsedBody($b), new Response(), ['nid' => (string) $nid]);
        return (string) ($_SESSION['nominee_error'] ?? $_SESSION['nominee_ok'] ?? '');
    }

    /** Signed in as the nominee, holding the page for this nomination. */
    private function holding(int $id): int
    {
        $uid = $this->member();
        $this->assertTrue(NomineeDetails::bind($this->token(NomineeDetails::forEmail($id)['complete_url']), $uid)['ok']);
        $_SESSION['user_id'] = $uid;
        return $uid;
    }

    public function test_the_email_asks_for_what_this_nomination_lacks_and_the_link_opens_the_page(): void
    {
        $id = $this->nom();
        $v = NomineeDetails::forEmail($id);
        $this->assertMatchesRegularExpression('~/nominee/start/[a-f0-9]{40}$~', $v['complete_url']);
        $this->assertSame('Add one piece of evidence', $v['steps'][0][0], 'evidence first: none is what stops an approval');
        $titles = array_column($v['steps'], 0);
        $this->assertContains('Add a clear photo of yourself', $titles);
        $this->assertContains('Add a phone number', $titles, 'the nominator gave no phone');
        $this->assertContains('Say a few words about your work', $titles);

        $mail = Emails::compose('nominated', $v + ['programme' => 'Teachers Prize', 'first_name' => 'Grace', 'quote' => '', 'quote_by' => '',
            'confirm_url' => '', 'watch_url' => 'https://x.test/awards', 'help_url' => 'https://x.test/support', 'site_url' => 'https://x.test']);
        $this->assertStringContainsString($v['complete_url'], (string) $mail['text'], 'the plain-text part carries the link too');
        $this->assertStringContainsString('Complete your nomination', (string) $mail['html']);
        $this->assertStringContainsString('Add one piece of evidence', (string) $mail['html']);
        $this->assertStringNotContainsString('processing fee', (string) $mail['html'], 'nothing the nominator wrote is relayed');
        $this->assertStringContainsString('never asks a nominee to pay', (string) $mail['html']);

        // Signing in with the link lands on the page waiting for review.
        $_SESSION['user_id'] = $this->member();
        $tok = $this->token($v['complete_url']);
        $r = $this->ctrl()->start((new ServerRequestFactory())->createServerRequest('GET', '/nominee/start/' . $tok), new Response(), ['token' => $tok]);
        $this->assertSame('/account/nomination/pending/' . $id, $r->getHeaderLine('Location'));
    }

    public function test_the_places_still_needed_are_named_and_evidence_on_file_becomes_more(): void
    {
        DB::table('gates_settings')->insert([['key_name' => 'nomination_eligibility_enabled', 'value' => '1'], ['key_name' => 'nomination_min_locations', 'value' => '3']]);
        $id = $this->nom(['nominee_phone' => '0803 000 0000']);
        DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'link', 'url' => 'https://example.org/a', 'created_at' => date('Y-m-d H:i:s')]);
        $v = NomineeDetails::forEmail($id);
        $this->assertSame(2, $v['places_short'], 'one place of three: two more');
        $this->assertSame(3, $v['places_need']);
        $this->assertSame('/nominate/' . $this->slug, (string) parse_url($v['nominate_url'], PHP_URL_PATH), 'the page people nominate on');
        $this->assertSame('Add more evidence', $v['steps'][0][0]);
        $this->assertStringContainsString('1 piece', $v['steps'][0][1]);
        $this->assertNotContains('Add a phone number', array_column($v['steps'], 0), 'only what is missing is asked for');

        $mail = Emails::compose('nominated', $v + ['programme' => 'Teachers Prize', 'first_name' => 'Grace', 'quote' => '', 'quote_by' => '',
            'confirm_url' => '', 'watch_url' => 'https://x.test/awards', 'help_url' => 'https://x.test/support', 'site_url' => 'https://x.test']);
        $this->assertStringContainsString('Yours needs 2 more', (string) $mail['html']);

        $this->holding($id);
        $html = $this->page($id);
        $this->assertStringContainsString('It needs nominations from 2 more places', $html);
        $this->assertStringContainsString('href="' . $v['nominate_url'] . '"', $html);
        $this->assertStringContainsString('sent 1 piece of evidence', $html, 'counted, not shown');
    }

    public function test_no_link_goes_to_the_nominators_own_inbox(): void
    {
        $id = $this->nom(['nominee_email' => 'self@friends.ng', 'nominator_email' => 'self@friends.ng']);
        $this->assertSame('', NomineeDetails::forEmail($id)['complete_url']);
        $this->assertFalse(DB::table('gates_nominee_details')->where('nomination_id', $id)->exists());
    }

    public function test_what_the_nominee_adds_before_review_is_kept_and_approval_moves_it_across(): void
    {
        $id = $this->nom();
        $uid = $this->holding($id);
        $html = $this->page($id);
        $this->assertStringContainsString('waiting for review', $html);
        $this->assertStringNotContainsString('processing fee', $html, 'the reason is not shown before review');
        $this->assertStringContainsString('action="/account/nomination/pending/' . $id . '"', $html);

        $this->assertSame('Saved.', $this->post($id, ['do' => 'evidence', 'label' => 'My classroom', 'url' => 'https://example.org/class']));
        $this->assertSame('Saved.', $this->post($id, ['do' => 'contact', 'phones' => ['0803 000 0000'], 'emails' => ['grace@school.ng']]));
        $d = NomineeDetails::minePending($id, $uid);
        $this->assertCount(1, NomineeDetails::filesOf($d, 'evidence'));
        $this->assertSame('Pay the processing fee to receive your award.', DB::table('gates_nominations')->where('id', $id)->value('reason'));

        // Approval: the same row and its evidence become the nominee's.
        $nomineeId = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat, 'name' => 'Grace Mensah', 'status' => 'approved']);
        DB::table('gates_nominations')->where('id', $id)->update(['status' => 'approved']);
        $link = NomineeDetails::invite($nomineeId, DB::table('gates_nominations')->find($id));
        $this->assertNotSame('', $link);
        $this->assertSame(1, DB::table('gates_nominee_details')->where('nomination_id', $id)->count(), 'one page, not a second');
        $mine = NomineeDetails::mine($nomineeId, $uid);
        $this->assertNotNull($mine, 'still held by the same account');
        $this->assertSame(['https://example.org/class'], array_column(NomineeDetails::files($nomineeId, 'evidence'), 'url'), 'nothing has to be sent again');
        $this->assertSame(['ok' => true, 'nominee' => $nomineeId, 'href' => '/account/nomination/' . $nomineeId], NomineeDetails::bind($this->token($link), $uid));
        $r = $this->ctrl()->pending((new ServerRequestFactory())->createServerRequest('GET', '/account/nomination/pending/' . $id), new Response(), ['nid' => (string) $id]);
        $this->assertSame('/account/nomination/' . $nomineeId, $r->getHeaderLine('Location'), 'the old address follows the page');
    }

    public function test_a_turned_down_nomination_takes_no_more(): void
    {
        $id = $this->nom();
        $uid = $this->holding($id);
        DB::table('gates_nominations')->where('id', $id)->update(['status' => 'rejected']);
        $this->assertStringContainsString('no longer waiting for review', $this->post($id, ['do' => 'bio', 'bio' => 'I teach mathematics in Ikotun.']));
        $this->assertNull(NomineeDetails::minePending($id, $uid)->bio);
        $this->assertStringContainsString('This nomination is closed', $this->page($id));
        $this->assertSame([], NomineeDetails::forUser($uid), 'the account no longer asks them to finish it');
    }

    public function test_a_repeat_combined_in_today_is_not_mailed_again(): void
    {
        $id = $this->nom();
        NomineeDetails::forEmail($id);
        $repeat = $this->nom();
        DB::table('gates_nominations')->where('id', $repeat)->update(['merged_into' => $id]);
        $this->assertTrue(NomineeDetails::forEmail($repeat)['skip']);
    }
}
