<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GoogleMeetService;
use Tests\TestCase;

/**
 * The contract between this platform and `config/AfricaGATES_AppScript.gs`.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A TEST READS A .gs FILE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Apps Script runs on Google's infrastructure as the operator's own account. Nothing
 * here can execute it, and the suite does not mock cURL on principle — a mocked transport
 * asserts the mock was written. What CAN be held is the half of the contract that lives in
 * this repository: the field names each side uses, and two specific mistakes that were live
 * in the script and produced no error either time.
 *
 * Both failures had the same shape, which is the shape worth naming: **a wrong answer and
 * an empty answer are indistinguishable here.** A transcript that cannot be found reports
 * "nobody turned transcription on", which is the ORDINARY answer — so a filter that matched
 * nothing looked exactly like a normal Tuesday.
 */
final class GoogleAppsScriptContractTest extends TestCase
{
    private static function gs(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/config/AfricaGATES_AppScript.gs');
    }

    private static function manifest(): array
    {
        $j = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/config/appsscript.json'), true);
        return is_array($j) ? $j : [];
    }

    // ══ the meeting code ═════════════════════════════════════════════════════

    /**
     * Meet's discovery document gives `meetingCode` the format `[a-z]+-[a-z]+-[a-z]+` and
     * its filter example verbatim as `space.meeting_code = "abc-mnop-xyz"`. The script
     * stripped the hyphens, so the filter matched nothing on every call and every fetch
     * fell through to the Drive branch.
     */
    public function test_the_meet_filter_keeps_the_hyphens_in_the_meeting_code(): void
    {
        $gs = self::gs();

        $this->assertStringNotContainsString(
            "code.replace(/-/g,'')",
            $gs,
            'the meeting code is [a-z]+-[a-z]+-[a-z]+ — stripping hyphens makes the filter match nothing'
        );
        $this->assertStringContainsString("'space.meeting_code=\"' + code + '\"'", $gs);
    }

    /** The platform only ever sends a hyphenated code, which is what the filter needs. */
    public function test_the_platform_refuses_a_code_that_is_not_hyphenated(): void
    {
        $svc = new GoogleMeetService('https://script.google.com/macros/s/x/exec', 'a-secret');

        $r = $svc->transcript('abcdefghij');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No Meet code', $r['message']);
    }

    // ══ the Drive fallback ═══════════════════════════════════════════════════

    /**
     * It used to take the newest "Transcript" document in the whole Drive, so two sittings
     * on one day meant the second one's transcript answered a fetch for the first — one
     * nominee's answers attached to another's judging record, in the feature that feeds the
     * expert half of the score.
     */
    public function test_the_drive_fallback_is_scoped_and_refuses_to_guess(): void
    {
        $gs = self::gs();

        $this->assertStringContainsString('titleHint', $gs, 'the Drive branch has nothing to scope by');
        $this->assertMatchesRegularExpression(
            '/if\(!hint\)\s*return respond\(true,\s*\'No transcript found\'/',
            $gs,
            'with no hint the script must return nothing rather than take the newest file it can find'
        );
    }

    /** The hint is useless if the platform never sends it. */
    public function test_the_platform_sends_the_title_hint(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(GoogleMeetService::class))->getFileName()
        );
        $this->assertStringContainsString("'titleHint'", $src);

        $ctrl = (string) file_get_contents(
            (new \ReflectionClass(\AfricaGates\Admin\Controllers\InterviewsController::class))->getFileName()
        );
        $this->assertStringContainsString('GoogleMeetService::eventTitle', $ctrl);
    }

    /**
     * The title is used twice, a day apart: once naming the calendar event, once searching
     * for the document Google named after it. Two literals would drift, and the symptom
     * would be "no transcript" — the ordinary answer again.
     */
    public function test_the_event_title_has_one_definition(): void
    {
        $this->assertSame(
            'Africa GATES interview — Ada Nwosu',
            GoogleMeetService::eventTitle('Ada Nwosu')
        );

        $ctrl = (string) file_get_contents(
            (new \ReflectionClass(\AfricaGates\Admin\Controllers\InterviewsController::class))->getFileName()
        );
        $this->assertStringNotContainsString(
            "'title'       => 'Africa GATES interview — '",
            $ctrl,
            'the title is built inline again — it will drift from eventTitle()'
        );
    }

    // ══ the manifest ═════════════════════════════════════════════════════════

    /**
     * The Meet call goes out through UrlFetchApp with ScriptApp.getOAuthToken(), so the
     * editor sees an arbitrary HTTPS request and cannot infer the scope. Nothing prompts
     * for it, the token arrives without it, Meet answers 403, and the script falls through
     * to Drive looking exactly like "nobody turned transcription on". Declaring it is the
     * only fix.
     */
    public function test_the_manifest_declares_the_meet_scope_apps_script_cannot_infer(): void
    {
        $scopes = self::manifest()['oauthScopes'] ?? [];

        $this->assertContains('https://www.googleapis.com/auth/meetings.space.readonly', $scopes);
    }

    /** Every Google surface the script touches needs its scope, since pinning stops inference. */
    public function test_the_manifest_covers_what_the_script_calls(): void
    {
        $scopes = self::manifest()['oauthScopes'] ?? [];

        foreach ([
            'calendar.events'          => 'Calendar.Events.insert',
            'script.external_request'  => 'UrlFetchApp',
            'drive.readonly'           => 'DriveApp',
            'documents.readonly'       => 'DocumentApp',
            'script.send_mail'         => 'MailApp',
            'script.scriptapp'         => 'ScriptApp.newTrigger',
        ] as $scope => $why) {
            $matched = array_filter($scopes, static fn ($s) => str_ends_with((string) $s, '/' . $scope));
            $this->assertNotEmpty($matched, "no scope for {$why} — it will fail once oauthScopes is pinned");
        }
    }

    /** `meetings.conference.readonly` is not a scope. The script's comment used to ask for it. */
    public function test_no_invented_meet_scope_survives(): void
    {
        $real = ['meetings.space.created', 'meetings.space.readonly', 'meetings.space.settings'];

        foreach (self::manifest()['oauthScopes'] ?? [] as $s) {
            if (!str_contains((string) $s, 'meetings.')) continue;
            $leaf = substr((string) $s, strrpos((string) $s, '/') + 1);
            $this->assertContains($leaf, $real, "{$leaf} is not a scope the Meet API defines");
        }

        // The script may still NAME it — the comment corrects the earlier version that
        // asked for it — but it must never present it as a scope to add.
        $gs = self::gs();
        if (str_contains($gs, 'meetings.conference.readonly')) {
            $this->assertStringContainsString(
                'which is not a scope that exists',
                $gs,
                'the script mentions meetings.conference.readonly without saying it is not real'
            );
        }
        $this->assertTrue(true);
    }

    // ══ the secret ═══════════════════════════════════════════════════════════

    /**
     * The web app is deployed "access: anyone". Booking events in the operator's calendar
     * and reading their meeting text must not be reachable by anyone holding the URL, so
     * both actions refuse while SECRET is empty — and the shipped file must ship empty.
     */
    public function test_the_shipped_script_carries_no_secret_and_refuses_without_one(): void
    {
        $gs = self::gs();

        $this->assertMatchesRegularExpression('/const SECRET = \'\';/', $gs, 'a secret was committed');
        $this->assertMatchesRegularExpression(
            '/if\(!SECRET\) return respond\(false/',
            $gs,
            'the privileged actions must refuse outright with no secret set'
        );
        $this->assertStringContainsString("body.token !== SECRET", $gs);
    }

    public function test_the_platform_will_not_call_a_privileged_action_without_a_secret(): void
    {
        $svc = new GoogleMeetService('https://script.google.com/macros/s/x/exec', '');

        $this->assertFalse($svc->canSchedule());
        $this->assertStringContainsString('secret is not set', $svc->why());

        // And the advice names a place the operator can actually reach. There is no SSH on
        // production, so "set GAS_SECRET in .env" — which is what this used to say — was
        // an instruction nobody could follow, and the integration stayed dead for it.
        $this->assertStringContainsString('Settings', $svc->why());

        // And it says so rather than attempting the call.
        $this->assertStringContainsString('secret', $svc->transcript('abc-mnop-xyz')['message']);
    }
}
