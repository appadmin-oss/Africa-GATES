<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\VolunteerController;
use AfricaGates\Services\Volunteer\Rules;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The volunteer application, rebuilt (owner, 29 Oct 2026: "we need all essential info").
 *
 * What these try to break: an application with no way to reach the person or anybody for them
 * in an emergency; a state the form never offered; an emergency contact who is the applicant;
 * the optional answers lost on the way in; a refused form that empties itself; and a form whose
 * choices a screen reader cannot name.
 */
final class VolunteerApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::reset();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['vol_errors'], $_SESSION['vol_apply_old']);
        parent::tearDown();
    }

    private function member(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Ngozi Okafor', 'email' => 'ngozi' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'x', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function form(array $f = []): array
    {
        return $f + ['phone' => '0803 555 0101', 'dob' => '1999-04-02', 'state' => 'Lagos', 'area' => 'Ikotun', 'interests' => ['drive', 'door'],
            'emergency_name' => 'Ada Okafor', 'emergency_phone' => '0803 555 0199', 'ref_name' => 'Mrs Adeyemi', 'ref_contact' => '0803 000 0000', 'conduct' => '1'];
    }

    private function ctrl(): VolunteerController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(VolunteerController::class);
    }

    public function test_the_essentials_are_required_and_checked(): void
    {
        $u = $this->member();
        $no = static fn (array $r): string => implode(' ', $r['errors']);
        $this->assertStringContainsString('phone number', $no(Volunteers::apply($u, $this->form(['phone' => '12']))));
        $this->assertStringContainsString('Pick your state', $no(Volunteers::apply($u, $this->form(['state' => 'Atlantis']))), 'only a state the form offers');
        $this->assertStringContainsString('emergency', $no(Volunteers::apply($u, $this->form(['emergency_phone' => '']))));
        $this->assertStringContainsString('different phone', $no(Volunteers::apply($u, $this->form(['emergency_phone' => '0803-555-0101']))),
            'their emergency contact is somebody else, however the number is typed');
        $this->assertTrue(Volunteers::apply($u, $this->form())['ok']);
    }

    public function test_every_answer_is_kept_in_the_words_the_form_offered(): void
    {
        $u = $this->member();
        $r = Volunteers::apply($u, $this->form(['gender' => 'female', 'occupation' => 'student', 'skills' => ['first_aid', 'photo', 'hacking'], 'tshirt' => 'M',
            'heard_from' => 'school', 'access_needs' => 'Step-free access', 'experience' => 'Red Cross 2024', 'weekdays' => 'any', 'weekends' => 'both',
            'max_hours_week' => '6', 'travel_km' => '15', 'motivation' => 'To help']));
        $this->assertTrue($r['ok']);
        $v = DB::table('gates_volunteers')->where('id', $r['id'])->first();
        $this->assertSame(['0803 555 0101', 'Lagos', 'female', 'student', 'first_aid,photo', 'M', 'school', 'Step-free access', 'any', 'both', 6, 15, 'Ada Okafor, 0803 555 0199'],
            [$v->phone, $v->state, $v->gender, $v->occupation, $v->skills, $v->tshirt, $v->heard_from, $v->access_needs, $v->weekdays, $v->weekends, (int) $v->max_hours_week, (int) $v->travel_km, $v->emergency_contact],
            'an unknown skill is dropped, not stored');
        $this->assertTrue(Volunteers::apply($u, $this->form(['gender' => 'robot']))['ok'], 'applying again while it waits updates it');
        $this->assertNull(DB::table('gates_volunteers')->where('id', $r['id'])->value('gender'), 'an answer the form never offered is stored as no answer');
    }

    public function test_a_refused_form_keeps_what_was_typed_and_every_choice_is_named(): void
    {
        $_SESSION['user_id'] = $this->member();
        $this->ctrl()->apply((new ServerRequestFactory())->createServerRequest('POST', '/volunteer/apply')
            ->withParsedBody($this->form(['phone' => '', 'skills' => ['photo'], 'tshirt' => 'L', 'gender' => 'male'])), new Response());
        $html = (string) $this->ctrl()->applyForm((new ServerRequestFactory())->createServerRequest('GET', '/volunteer/apply'), new Response())->getBody();
        $this->assertStringContainsString('<title>Not sent: please check your answers', $html, 'the outcome is first in the title');
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('value="Ikotun"', $html);
        $this->assertMatchesRegularExpression('~value="photo" checked> Photography or video~', $html);
        $this->assertMatchesRegularExpression('~value="male" checked> Male~', $html);
        $this->assertMatchesRegularExpression('~<option selected>L</option>~', $html);
        $this->assertMatchesRegularExpression('~<fieldset class="ap-f[^"]*"><legend class="ap-f__l">Skills you bring~', $html, 'a group of chips is a named fieldset');
        $this->assertSame(6, substr_count($html, 'class="ap-step"'), 'six steps');
        $this->assertStringNotContainsString('shift', strtolower($html), 'volunteering has no shifts');
    }
}
