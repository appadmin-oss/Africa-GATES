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
        $this->assertSame(3, substr_count($ws, 'self::communityCard($v)'), 'the application, getting started and Today');
    }

    /**
     * The group is for adults. Applicants are 16 and up, and the link opens a group where
     * people the platform has not vetted can message each other — which the platform's own
     * rules keep under-18s out of (AUDIT-AND-PLAN VO-4).
     */
    public function test_the_link_goes_to_adults_only(): void
    {
        $at = fn (string $years) => (object) ['dob' => date('Y-m-d', strtotime($years))];
        $this->assertNull(Volunteers::communityFor($at('-16 years')), 'a 16-year-old was sent into the group');
        $this->assertNull(Volunteers::communityFor($at('-18 years +1 day')), 'the day before an 18th birthday is still 17');
        $this->assertSame(Volunteers::communityUrl(), Volunteers::communityFor($at('-18 years')));
        $this->assertNull(Volunteers::communityFor((object) ['dob' => null]), 'no date of birth, no link');

        $m = Emails::compose('volunteer-applied', ['first_name' => 'Tobi', 'community_url' => '', 'action_url' => 'https://x.test/volunteer']);
        $this->assertStringNotContainsString('Join on WhatsApp', $m['html']);
        $this->assertStringNotContainsString('chat.whatsapp.com', $m['text']);
    }

    public function test_only_a_whatsapp_invite_is_accepted_as_the_link(): void
    {
        $this->assertTrue(Volunteers::validCommunityUrl('https://chat.whatsapp.com/ABC'));
        $this->assertFalse(Volunteers::validCommunityUrl('https://chat.whatsapp.com.evil.example/ABC'));
        $this->assertFalse(Volunteers::validCommunityUrl('https://example.com/join'));
    }

    public function test_age_is_counted_by_the_calendar(): void
    {
        $now = strtotime('2026-11-14 12:00:00');
        $this->assertSame(15, Volunteers::age('2010-11-15', $now), 'a day before the 16th birthday');
        $this->assertSame(16, Volunteers::age('2010-11-14', $now), 'on the birthday');
    }
}
