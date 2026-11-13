<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\Volunteer\Volunteers;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The volunteers' WhatsApp community (owner, 13 Nov 2026): emailed to everybody who applies
 * and offered on their workspace, from one resolver, settable in Settings.
 */
final class VolunteerCommunityTest extends TestCase
{
    public function test_the_default_link_is_used_until_staff_set_another(): void
    {
        $this->assertSame(Volunteers::COMMUNITY_DEFAULT, Volunteers::communityUrl());
        $this->assertStringNotContainsString('?', Volunteers::COMMUNITY_DEFAULT, 'no share-sheet tracking in what we mail');
        DB::table('gates_settings')->insert(['key_name' => 'volunteer_community_url', 'value' => 'https://chat.whatsapp.com/NEWLINK']);
        $this->assertSame('https://chat.whatsapp.com/NEWLINK', Volunteers::communityUrl());
        DB::table('gates_settings')->where('key_name', 'volunteer_community_url')->update(['value' => 'javascript:alert(1)']);
        $this->assertSame(Volunteers::COMMUNITY_DEFAULT, Volunteers::communityUrl(), 'never anything but an https link');
    }

    public function test_the_application_email_carries_the_link(): void
    {
        $m = Emails::compose('volunteer-applied', ['first_name' => 'Chika', 'community_url' => Volunteers::communityUrl(), 'action_url' => 'https://x.test/volunteer']);
        $this->assertStringContainsString(Volunteers::COMMUNITY_DEFAULT, $m['html']);
        $this->assertStringContainsString(Volunteers::COMMUNITY_DEFAULT, $m['text']);
    }

    public function test_the_application_sends_it_and_the_workspace_offers_it(): void
    {
        $ctl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/VolunteerController.php');
        $this->assertStringContainsString("'volunteer-applied'", $ctl);
        $ws = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Volunteer/WorkspacePages.php');
        $this->assertSame(3, substr_count($ws, 'self::communityCard()'), 'the application, getting started and Today');
    }
}
