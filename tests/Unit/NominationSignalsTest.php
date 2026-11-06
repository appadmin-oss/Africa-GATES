<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ReviewConflict;
use AfricaGates\Admin\Services\ReviewDesk;
use AfricaGates\Admin\Services\ReviewQueue;
use AfricaGates\Services\NominationSignals;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * What the review desk checks beyond the nomination itself (26 Oct 2026): the signals, the
 * reviewer named on their own nomination, the nominee's form link that would have reached
 * the nominator, and a merge across a near-miss spelling.
 *
 * Every one of these fails silently — a row that reads "All clear", a form link that
 * arrives in the wrong inbox, an approval nobody should have made — so each is asserted on
 * what the reviewer or the database actually ends up with.
 */
final class NominationSignalsTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;
    private int $seq = 0;

    private const LONG = 'She started a Saturday reading club in a borrowed classroom, trained six volunteers to run it, '
        . 'and now more than two hundred children in the ward read at their level because of it every single week.';

    protected function setUp(): void
    {
        parent::setUp();
        ReviewConflict::reset();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'ns-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'ns' . bin2hex(random_bytes(3)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
        DB::table('gates_settings')->where('key_name', 'nomination_eligibility_enabled')->delete();
    }

    private function nom(string $name, array $o = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($o + [
            'cycle_id' => $this->cycle, 'category_id' => $this->cat, 'nominee_name' => $name, 'country_code' => 'NG',
            'nominator_name' => 'Bola Ade', 'nominator_email' => 'bola' . (++$this->seq) . '@school.ng',
            'reason' => 'Runs a weekend club.', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<string> */
    private function keys(int $id): array
    {
        return array_column(NominationSignals::forIds([$id])[$id] ?? [], 'key');
    }

    // ── the signals ──────────────────────────────────────────────────────────

    public function test_a_clean_nomination_has_no_signals(): void
    {
        $this->assertSame([], $this->keys($this->nom('Grace Mensah', ['nominee_email' => 'grace@school.ng'])));
    }

    public function test_the_nominee_contact_that_is_a_nominators_own_is_named_and_the_form_link_is_held(): void
    {
        // Gmail dots and a +tag are one inbox.
        $a = $this->nom('Grace Mensah', ['nominator_email' => 'kofi.mensah@gmail.com', 'nominee_email' => 'kofimensah+mum@gmail.com']);
        $this->assertContains('own_contact', $this->keys($a));
        $this->assertTrue(NominationSignals::contactIsNominators(DB::table('gates_nominations')->find($a)));

        // ANOTHER nominator's inbox counts too, or the second nominator slips by.
        $b = $this->nom('Tunde Bakare', ['nominee_email' => 'tunde@school.ng']);
        $this->nom('Tunde Bakare', ['nominator_email' => 'tunde@school.ng']);
        $this->assertTrue(NominationSignals::contactIsNominators(DB::table('gates_nominations')->find($b)));

        $c = $this->nom('Ruth Achieng', ['nominee_email' => 'ruth@school.ke']);
        $this->assertFalse(NominationSignals::contactIsNominators(DB::table('gates_nominations')->find($c)));
    }

    public function test_one_inbox_behind_several_nominations_is_one_person(): void
    {
        $a = $this->nom('Grace Mensah', ['nominator_email' => 'ama.owusu@gmail.com']);
        $b = $this->nom('Grace Mensah', ['nominator_email' => 'amaowusu+2@gmail.com']);
        $s = NominationSignals::forIds([$a])[$a];
        $this->assertSame('one_person', $s[0]['key']);
        $this->assertSame([$b], $s[0]['with']);
        $this->assertSame('2 nominations from this one nominator', $s[0]['t']);
    }

    public function test_one_device_behind_several_nominators_is_named_and_one_person_on_one_device_is_not(): void
    {
        $a = $this->nom('Grace Mensah', ['device_fp' => 'dev-1']);
        $this->nom('Grace Mensah', ['device_fp' => 'dev-1']);
        $this->assertContains('one_device', $this->keys($a));

        $c = $this->nom('Tunde Bakare', ['device_fp' => 'dev-2', 'nominator_email' => 'same@school.ng']);
        $this->nom('Tunde Bakare', ['device_fp' => 'dev-2', 'nominator_email' => 'same@school.ng']);
        $this->assertNotContains('one_device', $this->keys($c), 'that is one person, already named once');
        $this->assertContains('one_person', $this->keys($c));
    }

    public function test_copied_words_are_named_for_the_same_nominee_and_across_nominees(): void
    {
        $a = $this->nom('Grace Mensah', ['reason' => self::LONG]);
        $this->nom('Grace Mensah', ['reason' => 'Truly: ' . self::LONG . ' Thank you.']);
        $this->assertContains('copied', $this->keys($a));

        // The same text with the name swapped, for somebody else entirely.
        $t1 = $this->nom('Ngozi Eze', ['reason' => 'Ngozi Eze ' . self::LONG]);
        $t2 = $this->nom('Halima Sule', ['reason' => 'Halima Sule ' . self::LONG]);
        $this->assertContains('template', $this->keys($t1));
        // Grace's first nomination carries the same text, so it is named too.
        $this->assertSame([$a, $t1], NominationSignals::forIds([$t2])[$t2][array_search('template', $this->keys($t2), true)]['with']);

        // A short reason is too generic to call copied.
        $s1 = $this->nom('Femi Ogun', ['reason' => 'A great teacher who cares.']);
        $this->nom('Femi Ogun', ['reason' => 'A great teacher who cares.']);
        $this->assertSame([], $this->keys($s1));
    }

    public function test_evidence_sent_for_somebody_else_is_named(): void
    {
        $sha = hash('sha256', 'one certificate');
        $a = $this->nom('Grace Mensah');
        $b = $this->nom('Ngozi Eze');
        foreach ([$a, $b] as $id) {
            DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'file', 'path' => '/uploads/' . $id . '.jpg',
                'sha256' => $sha, 'created_at' => date('Y-m-d H:i:s')]);
            DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'link',
                'url' => $id === $a ? 'https://NEWS.example.org/story/' : 'https://news.example.org/story#top', 'created_at' => date('Y-m-d H:i:s')]);
        }
        $this->assertContains('reused_file', $this->keys($a));
        $this->assertContains('reused_link', $this->keys($a), 'host case, a trailing slash and a fragment are one address');

        // The same file twice for ONE person is not reuse.
        $c = $this->nom('Tunde Bakare');
        $d = $this->nom('Tunde Bakare');
        foreach ([$c, $d] as $id) DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'file', 'path' => '/x', 'sha256' => hash('sha256', 'tunde'), 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNotContains('reused_file', $this->keys($c));
    }

    public function test_a_nominator_nobody_can_email_and_a_self_nomination_are_named(): void
    {
        $this->assertContains('unreachable', $this->keys($this->nom('Grace Mensah', ['nominator_email' => 'grace@demo.invalid'])));
        $this->assertContains('self', $this->keys($this->nom('Kemi Adebayo', ['nominator_name' => 'kemi  ADEBAYO'])));
    }

    public function test_a_ruling_of_different_people_takes_the_entry_out_of_its_namesakes_signals(): void
    {
        $a = $this->nom('Grace Mensah', ['device_fp' => 'dev-9']);
        $b = $this->nom('Grace Mensah', ['device_fp' => 'dev-9', 'not_duplicate' => 1]);
        $this->assertNotContains('one_device', $this->keys($a));
        $this->assertNotContains('one_device', $this->keys($b));
    }

    public function test_the_upload_is_fingerprinted_as_it_arrived(): void
    {
        $bytes = random_bytes(3000);
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), 'photo.jpg', 'image/jpeg', strlen($bytes), UPLOAD_ERR_OK);
        $this->assertSame(hash('sha256', $bytes), NominationSignals::uploadHash($file));
        $this->assertSame($bytes, (string) $file->getStream(), 'the stream is handed back unread for the upload to store');
        $this->assertNull(NominationSignals::uploadHash(new UploadedFile((new StreamFactory())->createStream(''), 'x', null, 0, UPLOAD_ERR_NO_FILE)));
    }

    // ── on the list and the desk ─────────────────────────────────────────────

    public function test_a_signal_keeps_a_row_out_of_ready_to_approve(): void
    {
        $clean = $this->nom('Grace Mensah');
        $flagged = $this->nom('Ngozi Eze', ['nominator_email' => 'n@demo.invalid']);
        foreach ([$clean, $flagged] as $id) DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'link', 'url' => "https://example.org/$id", 'created_at' => date('Y-m-d H:i:s')]);
        $rows = [];
        foreach (ReviewQueue::build(ReviewQueue::params(['tab' => 'pending']))['rows'] as $r) $rows[(int) $r['id']] = $r;
        $this->assertTrue(ReviewQueue::inView($rows[$clean], 'ready'));
        $this->assertFalse(ReviewQueue::inView($rows[$flagged], 'ready'));
        $this->assertTrue(ReviewQueue::inView($rows[$flagged], 'check'));
        $this->assertSame('The nominator cannot be emailed', $rows[$flagged]['checks'][0]['t']);
    }

    public function test_the_desk_sets_a_duplicate_side_by_side_and_says_what_the_contact_details_mean(): void
    {
        $earlier = $this->nom('Grace Mensah', ['nominee_email' => 'grace.m@gmail.com', 'status' => 'approved']);
        $this->nom('Grace Mensah', ['nominee_email' => 'gracem+x@gmail.com']);
        $later = (int) DB::table('gates_nominations')->max('id');
        $v = ReviewDesk::view($later);
        $this->assertSame($earlier, $v['earlier']['id']);
        $this->assertSame('green', $v['compare']['tone']);
        $email = array_values(array_filter($v['compare']['rows'], static fn (array $r): bool => $r['k'] === 'Email'))[0];
        $this->assertSame('same', $email['v'], 'one inbox, however it was typed');

        $other = $this->nom('Grace Mensah', ['nominee_email' => 'someone.else@yahoo.com']);
        $this->assertSame('gold', ReviewDesk::view($other)['compare']['tone']);
    }

    // ── the reviewer named on the nomination ─────────────────────────────────

    private function controller(): \AfricaGates\Admin\Controllers\NominationsController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Admin\Controllers\NominationsController::class);
    }

    private function as(string $email): int
    {
        $id = (int) DB::table('gates_admins')->insertGetId(['name' => 'Chioma Obi', 'email' => $email, 'password_hash' => 'x', 'role' => 'superadmin']);
        $_SESSION['admin_id'] = $id; $_SESSION['admin_role'] = 'superadmin'; $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = '/admin/nominations/review';
        ReviewConflict::reset();
        return $id;
    }

    private function post(int $id, string $action, array $body = []): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/nominations/$id/$action")
            ->withParsedBody($body + ['_token' => 'x'])->withHeader('X-Requested-With', 'fetch');
        $res = $this->controller()->action($req, (new ResponseFactory())->createResponse(), ['id' => $id, 'action' => $action]);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?: []];
    }

    public function test_a_reviewer_cannot_decide_a_nomination_they_made_and_a_colleague_can(): void
    {
        $id = $this->nom('Grace Mensah', ['nominator_email' => 'chioma.obi@gmail.com']);
        $this->as('chiomaobi+admin@gmail.com');
        foreach (['approve', 'reject', 'apart', 'contact'] as $action) {
            [$code, $j] = $this->post($id, $action, ['category_id' => $this->cat, 'decision_reason' => 'x']);
            $this->assertSame(403, $code, $action);
            $this->assertStringContainsString('You nominated this person', (string) ($j['error'] ?? ''));
        }
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', $id)->value('status'));
        $this->assertSame('A colleague decides this one', ReviewDesk::view($id, null, (int) $_SESSION['admin_id'])['suggestion']['title']);

        $this->as('colleague@afrovanguard.example');
        [$code] = $this->post($id, 'approve', ['category_id' => $this->cat]);
        $this->assertSame(200, $code);
        $this->assertSame('approved', DB::table('gates_nominations')->where('id', $id)->value('status'));
    }

    public function test_the_nominees_contact_can_be_corrected_but_not_to_a_nominators_inbox(): void
    {
        $id = $this->nom('Grace Mensah', ['nominator_email' => 'kofi@school.ng', 'nominee_email' => 'kofi@school.ng']);
        $this->as('reviewer@afrovanguard.example');

        [$code, $j] = $this->post($id, 'contact', ['nominee_email' => 'KOFI@school.ng']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('somebody who nominated them', (string) $j['error']);

        [$code] = $this->post($id, 'contact', ['nominee_email' => 'grace@school.ng', 'nominee_phone' => '0803 111 0001']);
        $this->assertSame(200, $code);
        $row = DB::table('gates_nominations')->find($id);
        $this->assertSame(['grace@school.ng', '+2348031110001'], [$row->nominee_email, $row->nominee_phone]);
        $log = DB::table('gates_audit_log')->where('action', 'nomination.contact')->where('target_id', $id)->value('meta');
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('grace@school.ng', (string) $log, 'which fields moved, never the address');
    }

    public function test_approval_does_not_send_the_nominees_form_link_to_the_nominators_inbox(): void
    {
        $this->as('reviewer@afrovanguard.example');
        $held = $this->nom('Grace Mensah', ['nominator_email' => 'kofi@school.ng', 'nominee_email' => 'kofi@school.ng']);
        [$code, $j] = $this->post($held, 'approve', ['category_id' => $this->cat]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('form link was not sent', (string) $j['message']);
        $this->assertSame(0, DB::table('gates_form_tokens')->where('purpose', 'nominee')->count(), 'no link was even minted');

        $own = $this->nom('Ngozi Eze', ['nominee_email' => 'ngozi@school.ng']);
        [, $j] = $this->post($own, 'approve', ['category_id' => $this->cat]);
        $this->assertStringNotContainsString('form link was not sent', (string) $j['message']);
        $this->assertSame(1, DB::table('gates_form_tokens')->where('purpose', 'nominee')->count(), 'the nominee’s own address gets theirs');
    }

    // ── a merge across a near-miss spelling ──────────────────────────────────

    public function test_a_near_miss_spelling_merges_into_the_earlier_entry_and_mints_no_second_nominee(): void
    {
        $this->as('reviewer@afrovanguard.example');
        $earlier = $this->nom('Muhammed Bello');
        $this->assertSame(200, $this->post($earlier, 'approve', ['category_id' => $this->cat])[0]);
        $nominees = DB::table('gates_nominees')->where('category_id', $this->cat)->count();

        $later = $this->nom('Mohammed Bello');
        $v = ReviewDesk::view($later);
        $this->assertTrue($v['similar']);
        $this->assertSame($earlier, $v['earlier']['id']);
        $this->assertTrue($v['earlier']['mergeable']);

        [$code, $j] = $this->post($later, 'merge', ['merge_into' => $earlier]);
        $this->assertSame(200, $code, (string) ($j['error'] ?? ''));
        $this->assertSame('approved', DB::table('gates_nominations')->where('id', $later)->value('status'));
        $this->assertSame($nominees, DB::table('gates_nominees')->where('category_id', $this->cat)->count(), 'one person, one ballot entry');

        // And a name that is merely close is still refused.
        $stranger = $this->nom('Ada Obi');
        $this->assertSame(422, $this->post($stranger, 'merge', ['merge_into' => $earlier])[0]);
    }
}
