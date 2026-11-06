<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\Contacts;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Support\EmailCanon;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Contacts (AdminConsole v2): one row per person, and the rules about who is NOT on it.
 *
 * Each rule here is asserted on what the page would draw, because every one of them fails
 * silently — a refunded gift counted as given, a nominee on a contact list, a ballot shown
 * beside a name — and reads as an ordinary row.
 */
final class ContactsTest extends TestCase
{
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'ct-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $c = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'voting']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $c, 'slug' => 'ct' . bin2hex(random_bytes(3)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    private function find(string $email): ?array
    {
        $key = EmailCanon::hash($email);
        foreach (Contacts::build()['people'] as $c) if ($c['key'] === $key) return $c;
        return null;
    }

    private function vote(string $email, int $nominee, array $o = []): void
    {
        DB::table('gates_votes')->insert($o + ['nominee_id' => $nominee, 'category_id' => $this->cat,
            'voter_email_hash' => hash('sha256', $email . random_bytes(4)), 'voter_canon_hash' => EmailCanon::hash($email),
            'vote_type' => 'standard', 'weight' => 1, 'voted_at' => date('Y-m-d H:i:s')]);
    }

    private function nominee(string $name): int
    {
        return (int) DB::table('gates_nominees')->insertGetId(['category_id' => $this->cat, 'name' => $name, 'status' => 'approved', 'country_code' => 'NG']);
    }

    public function test_one_person_across_sources_and_spellings_is_one_row(): void
    {
        $n = $this->nominee('Grace Mensah');
        $this->vote('ada.obi@gmail.com', $n);
        DB::table('gates_donations')->insert(['donor_name' => 'Ada Obi', 'donor_email' => 'adaobi+gala@gmail.com',
            'amount_naira' => 5000, 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_event_registrations')->insert(['event_id' => 1, 'name' => 'Ada Obi', 'email' => 'ADAOBI@gmail.com',
            'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')] + $this->regExtras());

        $rows = array_values(array_filter(Contacts::build()['people'], static fn (array $c): bool => $c['key'] === EmailCanon::hash('ada.obi@gmail.com')));
        $this->assertCount(1, $rows);
        $this->assertSame(['voter', 'donor', 'ticket'], $rows[0]['kinds']);
        $this->assertSame(5000, $rows[0]['given']);
        $this->assertSame('1 vote · Teachers Prize', $rows[0]['took'][0], 'the programme, never the nominee');
        $this->assertStringNotContainsString('Grace', implode(' ', $rows[0]['took']));
    }

    public function test_a_number_many_people_typed_is_nobodys_phone(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $who) {
            DB::table('gates_donations')->insert(['donor_name' => $who, 'donor_email' => "$who@x.org", 'donor_phone' => '08000000000',
                'amount_naira' => 100, 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        }
        DB::table('gates_donations')->insert(['donor_name' => 'Kemi', 'donor_email' => 'kemi@x.org', 'donor_phone' => '08031110001',
            'amount_naira' => 100, 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($this->find('a@x.org')['phone']);
        $this->assertSame('+2348031110001', $this->find('kemi@x.org')['phone']);
    }

    public function test_money_given_back_is_not_given(): void
    {
        DB::table('gates_donations')->insert([
            ['donor_name' => 'Tunde', 'donor_email' => 'tunde@x.org', 'amount_naira' => 2000, 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s'), 'refunded_at' => null],
            ['donor_name' => 'Tunde', 'donor_email' => 'tunde@x.org', 'amount_naira' => 9000, 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s'), 'refunded_at' => date('Y-m-d H:i:s')],
            ['donor_name' => 'Tunde', 'donor_email' => 'tunde@x.org', 'amount_naira' => 7000, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'), 'refunded_at' => null],
        ]);
        $this->assertSame(2000, $this->find('tunde@x.org')['given']);
    }

    public function test_nominees_fraud_and_the_sandbox_are_not_contacts(): void
    {
        $n = $this->nominee('Chidi Nwosu');
        DB::table('gates_nominations')->insert(['cycle_id' => 1, 'nominee_name' => 'Chidi Nwosu', 'nominee_email' => 'chidi.nwosu@school.ng',
            'nominee_phone' => '08031234567', 'nominator_name' => 'Ngozi', 'nominator_email' => 'ngozi@x.org',
            'created_at' => date('Y-m-d H:i:s')] + $this->nominationExtras());
        $this->assertNull($this->find('chidi.nwosu@school.ng'), 'a nominee is not on the list for being nominated');
        $this->assertNotNull($this->find('ngozi@x.org'), 'their nominator is');
        $this->assertNull($this->find('ngozi@x.org')['phone'], "the nominee's phone is not the nominator's");

        $this->vote('flagged@x.org', $n, ['fraud_flag' => 1]);
        $this->assertNull($this->find('flagged@x.org'));

        DB::table('gates_donations')->insert(['donor_name' => 'Demo', 'donor_email' => 'giver@demo.invalid', 'amount_naira' => 100,
            'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($this->find('giver@demo.invalid'));

        DB::table('gates_newsletter')->insert(['email' => 'stand@x.org', 'email_hash' => EmailOptOut::hash('stand@x.org'),
            'source' => 'stands:42', 'subscribed_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($this->find('stand@x.org'), 'a stand-call request is not a subscription');
    }

    public function test_consent_is_the_newsletters_own_answer(): void
    {
        foreach (['in@x.org' => date('Y-m-d H:i:s'), 'pending@x.org' => null] as $email => $confirmed) {
            DB::table('gates_newsletter')->insert(['email' => $email, 'email_hash' => EmailOptOut::hash($email),
                'source' => 'footer', 'subscribed_at' => date('Y-m-d H:i:s'), 'confirmed_at' => $confirmed]);
        }
        DB::table('gates_donations')->insert(['donor_name' => 'Halima', 'donor_email' => 'out@x.org', 'amount_naira' => 100,
            'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_donations')->insert(['donor_name' => 'Femi', 'donor_email' => 'never@x.org', 'amount_naira' => 100,
            'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        EmailOptOut::record('out@x.org');

        $this->assertSame('subscribed', $this->find('in@x.org')['consent']);
        $this->assertSame('unconfirmed', $this->find('pending@x.org')['consent']);
        $this->assertSame('unsubscribed', $this->find('out@x.org')['consent']);
        $this->assertSame('none', $this->find('never@x.org')['consent']);
    }

    public function test_a_voter_from_before_matching_is_counted_not_listed(): void
    {
        $n = $this->nominee('Adaeze Okafor');
        DB::table('gates_votes')->insert(['nominee_id' => $n, 'category_id' => $this->cat, 'voter_email_hash' => hash('sha256', 'old@x.org'),
            'voter_canon_hash' => null, 'vote_type' => 'standard', 'weight' => 1, 'voted_at' => date('Y-m-d H:i:s')]);
        $built = Contacts::build();
        $this->assertSame(1, $built['anonymous_voters']);
        $this->assertSame(1, Contacts::page($built, 'all', '', 1)['stats']['anonymous']);
    }

    public function test_tabs_search_and_paging_agree_with_the_list(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            DB::table('gates_donations')->insert(['donor_name' => 'Donor ' . $i, 'donor_email' => "d{$i}@x.org", 'amount_naira' => 100,
                'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s', time() - $i * 60)]);
        }
        DB::table('gates_nominations')->insert(['cycle_id' => 1, 'nominee_name' => 'Somebody', 'nominator_name' => 'Kemi', 'nominator_email' => 'kemi@x.org',
            'created_at' => date('Y-m-d H:i:s')] + $this->nominationExtras());
        $built = Contacts::build();

        $p = Contacts::page($built, 'donor', '', 2, 5);
        $this->assertSame(7, $p['tabs']['donor']['n']);
        $this->assertSame(1, $p['tabs']['nominator']['n']);
        $this->assertSame([2, 2, 6, 7], [$p['pages'], count($p['rows']), $p['from'], $p['to']]);

        $s = Contacts::page($built, 'all', 'KEMI', 1, 5);
        $this->assertSame(1, $s['total']);
        $this->assertSame('Kemi', $s['rows'][0]['name']);
        $this->assertSame('all', Contacts::page($built, 'nonsense', '', 1)['tab']);
    }

    public function test_the_page_draws_for_an_admin(): void
    {
        DB::table('gates_donations')->insert(['donor_name' => 'Yaw Boateng', 'donor_email' => 'yaw@x.org', 'amount_naira' => 2500,
            'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = '/admin/contacts';
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(\AfricaGates\Admin\Controllers\ContactsController::class)->index(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/admin/contacts'),
            (new \Slim\Psr7\Factory\ResponseFactory())->createResponse());
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Yaw Boateng', $html);
        $this->assertStringContainsString('₦2,500', $html);
        $this->assertStringContainsString('Not subscribed', $html);
    }

    // ── fixtures for NOT NULL columns this test is not about ─────────────────

    private function regExtras(): array
    {
        $out = [];
        foreach (['ticket_ref' => 'T' . bin2hex(random_bytes(5)), 'reference' => 'R' . bin2hex(random_bytes(5)), 'ticket_code' => strtoupper(bin2hex(random_bytes(8)))] as $col => $v) {
            if (\AfricaGates\Support\SchemaHas::column('gates_event_registrations', $col)) $out[$col] = $v;
        }
        return $out;
    }

    private function nominationExtras(): array
    {
        return \AfricaGates\Support\SchemaHas::column('gates_nominations', 'reason') ? ['reason' => 'x'] : [];
    }
}
