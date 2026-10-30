<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\GatedFormController;
use AfricaGates\Controllers\NomineeProfileController;
use AfricaGates\Admin\Controllers\NominationsController;
use AfricaGates\Services\NomineeDetails;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * A nominee's own page (owner, 30 Oct 2026): approval emails a link, the link makes or signs in
 * the account and puts the nominee on it once, and from then on the nominee keeps their contact,
 * photographs, evidence and bio up to date. The nominator's reason is never theirs to change.
 *
 * What these try to break: a link that works twice, or for a second account; a page another
 * account can read; a reason that a post can rewrite; a phone or email nobody checked; a profile
 * that never says it is complete; and an export that still leaves the contacts out.
 */
final class NomineeDetailsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['login_next'], $_SESSION['nominee_ok'], $_SESSION['nominee_error'], $_SESSION['nominee_open'],
              $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_name']);
        parent::tearDown();
    }

    private function member(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** An approved nomination and its nominee. @return array{0:object,1:int} */
    private function approved(): array
    {
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => 1, 'title' => 'Young innovator', 'slug' => 'yi-' . bin2hex(random_bytes(2)), 'sort_order' => 1]);
        $nid = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => 'Amaka Eze', 'status' => 'approved', 'story' => 'Built a solar lab.']);
        $id = (int) DB::table('gates_nominations')->insertGetId(['cycle_id' => 1, 'category_id' => $cat, 'nominee_name' => 'Amaka Eze', 'nominee_email' => 'amaka@example.test',
            'nominee_phone' => '0803 111 2222', 'nominator_name' => 'Mr Obi', 'nominator_email' => 'obi@example.test', 'nominator_phone' => '0803 999 0000',
            'reason' => 'She taught 300 girls to code.', 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
        return [DB::table('gates_nominations')->where('id', $id)->first(), $nid];
    }

    private function ctrl(string $class): object
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get($class);
    }

    private function token(string $link): string
    {
        return substr($link, strrpos($link, '/') + 1);
    }

    private function page(int $nid): string
    {
        return (string) $this->ctrl(NomineeProfileController::class)->page((new ServerRequestFactory())->createServerRequest('GET', '/account/nomination'), new Response(), ['id' => (string) $nid])->getBody();
    }

    private function post(int $nid, array $b): string
    {
        unset($_SESSION['nominee_ok'], $_SESSION['nominee_error']);
        $this->ctrl(NomineeProfileController::class)->save((new ServerRequestFactory())->createServerRequest('POST', '/account/nomination/' . $nid)->withParsedBody($b), new Response(), ['id' => (string) $nid]);
        return (string) ($_SESSION['nominee_error'] ?? $_SESSION['nominee_ok'] ?? '');
    }

    public function test_the_link_puts_the_nominee_on_one_account_once(): void
    {
        [$nom, $nid] = $this->approved();
        $link = NomineeDetails::invite($nid, $nom);
        $this->assertMatchesRegularExpression('~/nominee/start/[a-f0-9]{40}$~', $link);
        $d = DB::table('gates_nominee_details')->where('nominee_id', $nid)->first();
        $this->assertSame([['amaka@example.test'], ['+2348031112222']], [NomineeDetails::list($d->emails), NomineeDetails::list($d->phones)], 'seeded from what the nominator gave');

        $amaka = $this->member('Amaka Eze');
        $other = $this->member('Somebody Else');
        $this->assertSame(['ok' => true, 'nominee' => $nid], NomineeDetails::bind($this->token($link), $amaka));
        $this->assertTrue(NomineeDetails::bind($this->token($link), $amaka)['ok'], 'her own link, opened again');
        $this->assertFalse(NomineeDetails::bind($this->token($link), $other)['ok'], 'a spent link opens nothing for anybody else');
        $this->assertNull(NomineeDetails::mine($nid, $other));

        // Sent again by staff: a fresh link still cannot move the nominee to another account.
        $again = NomineeDetails::invite($nid, $nom);
        $this->assertStringContainsString('another account', (string) NomineeDetails::bind($this->token($again), $other)['error']);
    }

    public function test_signed_out_the_link_asks_for_one_email_and_comes_back(): void
    {
        [$nom, $nid] = $this->approved();
        $tok = $this->token(NomineeDetails::invite($nid, $nom));
        $html = (string) $this->ctrl(NomineeProfileController::class)->start((new ServerRequestFactory())->createServerRequest('GET', '/nominee/start/' . $tok), new Response(), ['token' => $tok])->getBody();
        $this->assertStringContainsString('Congratulations, Amaka', $html);
        $this->assertMatchesRegularExpression('~action="/account/login/otp".*?name="email" value="amaka@example.test" required~s', $html);
        $this->assertSame('/nominee/start/' . $tok, $_SESSION['login_next']);

        $_SESSION['user_id'] = $this->member('Amaka Eze');
        $r = $this->ctrl(NomineeProfileController::class)->start((new ServerRequestFactory())->createServerRequest('GET', '/nominee/start/' . $tok), new Response(), ['token' => $tok]);
        $this->assertSame('/account/nomination/' . $nid, $r->getHeaderLine('Location'));

        // An old single-use form link goes to the same place.
        $r = $this->ctrl(GatedFormController::class)->show((new ServerRequestFactory())->createServerRequest('GET', '/form/' . $tok), new Response(), ['token' => $tok]);
        $this->assertSame('/nominee/start/' . $tok, $r->getHeaderLine('Location'));
    }

    public function test_the_nominee_completes_and_reviews_but_never_rewrites_the_reason(): void
    {
        [$nom, $nid] = $this->approved();
        $uid = $this->member('Amaka Eze');
        NomineeDetails::bind($this->token(NomineeDetails::invite($nid, $nom)), $uid);
        $_SESSION['user_id'] = $uid;

        $html = $this->page($nid);
        $this->assertStringContainsString('She taught 300 girls to code.', $html, 'the reason is shown');
        $this->assertStringContainsString('It can’t be changed', $html);
        $this->assertDoesNotMatchRegularExpression('~name="reason"~', $html, 'and has no field anywhere');
        $this->assertStringContainsString('1 of 5 done', $html, 'contact came from the nomination');

        $this->assertStringContainsString('doesn’t look like a phone number', $this->post($nid, ['do' => 'contact', 'phones' => ['12'], 'emails' => ['a@example.test']]));
        $this->assertStringContainsString('doesn’t look like an email', $this->post($nid, ['do' => 'contact', 'phones' => ['0803 111 2222'], 'emails' => ['nope']]));
        $this->assertSame('Saved.', $this->post($nid, ['do' => 'contact', 'phones' => ['0803 111 2222', '0803-111-2222', '0805 333 4444', '0807 555 6666'], 'emails' => ['Amaka@Example.test', 'work@example.test']]));
        $d = NomineeDetails::mine($nid, $uid);
        $this->assertSame(['+2348031112222', '+2348053334444', '+2348075556666'], NomineeDetails::list($d->phones), 'the same number twice is one, and three is the most');
        $this->assertSame(['amaka@example.test', 'work@example.test'], NomineeDetails::list($d->emails));

        $this->assertStringContainsString('https://', $this->post($nid, ['do' => 'evidence', 'label' => 'My lab', 'url' => 'javascript:alert(1)']));
        $this->assertSame('Saved.', $this->post($nid, ['do' => 'evidence', 'label' => 'My lab', 'url' => 'https://example.test/lab']));
        $this->assertSame('Saved.', $this->post($nid, ['do' => 'bio', 'bio' => 'I run a weekend coding club for girls in Enugu.']));
        DB::table('gates_nominee_files')->insert(['nominee_id' => $nid, 'kind' => 'photo', 'path' => '/uploads/x.jpg', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(NomineeDetails::mine($nid, $uid)->completed_at, 'not complete before the terms');
        $this->assertSame('Accepted. Thank you.', $this->post($nid, ['do' => 'terms', 'accept' => '1', 'reason' => 'I nominated myself']));

        $this->assertNotNull(NomineeDetails::mine($nid, $uid)->completed_at);
        $html = $this->page($nid);
        $this->assertStringContainsString('Your profile is complete', $html);
        $this->assertMatchesRegularExpression('~<details class="nm-edit">\s*<summary>Update</summary>~', $html, 'a done step folds its form behind Update');
        $this->assertSame('She taught 300 girls to code.', DB::table('gates_nominations')->where('id', $nom->id)->value('reason'), 'no post reached the reason');
        $this->assertSame('Built a solar lab.', DB::table('gates_nominees')->where('id', $nid)->value('story'));

        $this->assertSame('Removed.', $this->post($nid, ['do' => 'remove', 'section' => 'evidence', 'file' => DB::table('gates_nominee_files')->where('kind', 'evidence')->value('id')]));
        $this->assertNotNull(DB::table('gates_nominee_files')->where('kind', 'evidence')->value('removed_at'), 'removed, never deleted');
    }

    public function test_another_account_cannot_open_or_change_the_page(): void
    {
        [$nom, $nid] = $this->approved();
        NomineeDetails::bind($this->token(NomineeDetails::invite($nid, $nom)), $this->member('Amaka Eze'));
        $_SESSION['user_id'] = $this->member('Somebody Else');
        $this->expectException(\Slim\Exception\HttpNotFoundException::class);
        $this->post($nid, ['do' => 'bio', 'bio' => 'Taken over by somebody else entirely.']);
    }

    public function test_the_export_carries_every_contact(): void
    {
        [$nom, $nid] = $this->approved();
        $uid = $this->member('Amaka Eze');
        NomineeDetails::bind($this->token(NomineeDetails::invite($nid, $nom)), $uid);
        NomineeDetails::saveContact(NomineeDetails::mine($nid, $uid), ['0803 111 2222', '0805 333 4444'], ['amaka@example.test', 'work@example.test']);
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin'; $_SESSION['admin_name'] = 'Staff';
        $csv = (string) $this->ctrl(NominationsController::class)->queueExport((new ServerRequestFactory())->createServerRequest('GET', '/admin/nominations/review/export.csv')
            ->withQueryParams(['tab' => 'approved', 'ids' => [(string) $nom->id]]), new Response())->getBody();
        $this->assertStringContainsString('"Nominee email","Nominee phone","Other emails (added by nominee)","Other phones (added by nominee)"', $csv);
        $this->assertStringContainsString('amaka@example.test', $csv);
        $this->assertStringContainsString('work@example.test', $csv);
        $this->assertStringContainsString('+2348053334444', $csv);
        $this->assertStringContainsString('obi@example.test', $csv, 'and the nominator’s');
    }
}
