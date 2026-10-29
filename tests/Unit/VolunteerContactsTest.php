<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\CrmAudiencePush;
use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Contacts;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * Contacts volunteers make, logged in the workspace and pushed to the CACENTRE CRM (owner,
 * 29 Oct 2026).
 *
 * What these try to break: a contact logged without the person's agreement, or a child's;
 * one volunteer seeing another's contacts, or claiming a place they do not help at; a typed
 * entry lost to a refusal; the volunteer's note reaching the CRM; a minor volunteer named in
 * it; and "they asked not to be contacted" never reaching the CRM.
 */
final class VolunteerContactsTest extends TestCase
{
    private const SECRET = 'gggggggggggggggggggggggggggggggggggggggggggggg';
    private array $sent = [];
    private int $cycle = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
        Training::reset();
        $this->sent = [];
        CrmAudiencePush::$transport = function (string $url, string $body): array {
            $tok = (string) (json_decode($body, true)['token'] ?? '');
            $seg = explode('.', $tok)[1] ?? '';
            $this->sent[] = json_decode((string) base64_decode(strtr($seg, '-_', '+/') . str_repeat('=', (4 - strlen($seg) % 4) % 4)), true)['rows'] ?? [];
            return ['code' => 200, 'body' => '{"ok":true}'];
        };
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'crm_intake_secret'], ['value' => self::SECRET]);
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'tp-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'voting']);
    }

    protected function tearDown(): void
    {
        CrmAudiencePush::$transport = null;
        unset($_SESSION['user_id'], $_SESSION['vol_toast'], $_SESSION['vol_error'], $_SESSION['vol_old']);
        parent::tearDown();
    }

    private function vol(string $name, string $dob = '1999-05-01', array $caps = []): object
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => strtolower(strtok($name, ' ')) . bin2hex(random_bytes(2)) . '@vol-mail.ng',
            'password_hash' => 'x', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $r = Volunteers::apply($uid, ['area' => 'Ikotun', 'dob' => $dob, 'interests' => ['drive'], 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1', 'guardian' => '1']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));
        Volunteers::approve((int) $r['id'], 1);
        foreach ($caps as $c) Grants::grant('member', $uid, $c, 'cycle', $this->cycle, date('Y-m-d', strtotime('+20 days')), 1);
        return Volunteers::of($uid);
    }

    private function ok(array $f = []): array
    {
        return $f + ['name' => 'Funmi Bello', 'phone' => '0803 123 4567', 'interest' => 'partner', 'place' => 'cycle:' . $this->cycle, 'consent' => '1', 'adult' => '1', 'note' => 'Runs a bakery; may sponsor a prize'];
    }

    private function rows(): array
    {
        $out = [];
        foreach ($this->sent as $push) foreach ($push as $r) $out[$r['ref']] = $r;
        return $out;
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    public function test_a_contact_needs_their_agreement_an_adult_and_a_way_to_reach_them(): void
    {
        $v = $this->vol('Chika Nwosu', '1999-05-01', ['vol.voter_drive']);
        $this->assertFalse(Contacts::log($v, $this->ok(['consent' => '']))['ok'], 'no agreement, no contact');
        $this->assertFalse(Contacts::log($v, $this->ok(['adult' => '']))['ok'], 'nobody under 18');
        $this->assertFalse(Contacts::log($v, $this->ok(['phone' => '', 'email' => '']))['ok'], 'a way to reach them');
        $this->assertFalse(Contacts::log($v, $this->ok(['place' => 'cycle:999999']))['ok'], 'only one of the volunteer’s own places');
        $this->assertTrue(Contacts::log($v, $this->ok())['ok']);
        $this->assertFalse(Contacts::log($v, $this->ok(['name' => 'F. Bello']))['ok'], 'the same phone twice is the same person');
        $this->assertSame('08031234567', DB::table('gates_vol_contacts')->value('phone'), 'stored as digits, so a duplicate is found however it was typed');
    }

    public function test_a_volunteer_sees_only_their_own_and_a_refusal_keeps_what_was_typed(): void
    {
        $a = $this->vol('Chika Nwosu', '1999-05-01', ['vol.voter_drive']);
        $b = $this->vol('Bola Ade', '1998-01-01', ['vol.voter_drive']);
        Contacts::log($b, $this->ok(['name' => 'Someone Bola Met', 'phone' => '0809 999 0000']));

        $_SESSION['user_id'] = $a->user_id;
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/volunteer/act')->withParsedBody($this->ok(['do' => 'contact', 'adult' => '', 'back' => '/volunteer/contacts/log']));
        $this->ctrl()->act($req, new Response());
        $this->assertNotEmpty($_SESSION['vol_error'] ?? '');
        $form = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/contacts/log'), new Response(), ['page' => 'contacts', 'tab' => 'log'])->getBody();
        $this->assertStringContainsString('value="Funmi Bello"', $form, 'the name typed is still there');
        $this->assertMatchesRegularExpression('~value="partner" checked~', $form, 'and the choice made');
        $this->assertDoesNotMatchRegularExpression('~name="adult" value="1" checked~', $form, 'the confirmations are asked afresh');
        $this->assertMatchesRegularExpression('~<label class="sh7-choice sh7-choice--check" for="f_consent"><input type="checkbox" id="f_consent" name="consent" value="1" required>~', $form, 'a labelled, required checkbox');

        $mine = (string) $this->ctrl()->root((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/contacts'), new Response(), ['page' => 'contacts'])->getBody();
        $this->assertStringNotContainsString('Someone Bola Met', $mine, 'another volunteer’s contacts are never shown');
    }

    public function test_the_crm_gets_the_contact_without_the_note_and_hears_when_they_ask_not_to_be_contacted(): void
    {
        $adult = $this->vol('Chika Nwosu', '1999-05-01', ['vol.voter_drive']);
        $minor = $this->vol('Tobi Ade', date('Y-m-d', strtotime('-17 years')), ['vol.voter_drive']);
        $this->assertTrue(Contacts::log($adult, $this->ok())['ok']);
        $this->assertTrue(Contacts::log($minor, $this->ok(['name' => 'Kemi Obi', 'phone' => '0807 765 4321', 'note' => 'PRIVATE NOTE']))['ok']);
        // The push never takes rows from the current second, so a change made later in that
        // second cannot hide behind its cursor; these are a minute old.
        DB::table('gates_vol_contacts')->update(['updated_at' => date('Y-m-d H:i:s', time() - 60)]);
        CrmAudiencePush::run();
        $rows = array_filter($this->rows(), static fn ($r) => str_starts_with($r['ref'], 'met:'));
        $this->assertCount(2, $rows);
        $funmi = array_values(array_filter($rows, static fn ($r) => $r['name'] === 'Funmi Bello'))[0];
        $kemi = array_values(array_filter($rows, static fn ($r) => $r['name'] === 'Kemi Obi'))[0];
        $this->assertSame('enquiry', $funmi['kind']);
        $this->assertSame('Sponsoring or partnering · met by Chika Nwosu at Teachers Prize · 2026', $funmi['detail']);
        $this->assertStringContainsString('met by an Africa GATES volunteer', $kemi['detail'], 'a volunteer under 18 is not named');
        $flat = (string) json_encode($this->sent);
        foreach (['bakery', 'PRIVATE NOTE', 'Tobi'] as $never) $this->assertStringNotContainsString($never, $flat);

        $id = (int) DB::table('gates_vol_contacts')->where('name', 'Funmi Bello')->value('id');
        $this->assertTrue(Contacts::forget($adult, $id));
        $this->assertFalse(Contacts::forget($minor, $id), 'only the volunteer who logged it');
        CrmAudiencePush::run();
        $this->assertSame('active', $this->rows()['met:' . $id]['status'], 'a change this second waits for the next tick');
        sleep(1);
        CrmAudiencePush::run();
        $this->assertSame('unsubscribed', $this->rows()['met:' . $id]['status'], 'the stop reaches the same CRM row');
    }
}
