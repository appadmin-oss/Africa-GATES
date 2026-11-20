<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OtpService;
use Tests\TestCase;

/**
 * The ballot's code screen (pages/vote-nominee.twig + assets/js/vote.js).
 *
 *   ENTER CONFIRMS     The code box sits inside the same form as "Vote for …", so Enter — or
 *                      Go on a phone keyboard — submitted it, and the submit handler sent a NEW
 *                      code. A new code cancels the last, so the code the voter had just typed
 *                      stopped working the moment they pressed the key meant to spend it.
 *   ONE LIFE           The code's ten minutes were typed into the mint and both halves of the
 *                      email, and the screen a voter types it into said nothing. It is
 *                      OtpService::VOTE_CODE_MINUTES now, read by all four.
 *   A WAY OUT          "Use a different email" was the only control besides Confirm, so a slow
 *                      email had no answer. Resend waits 30s on screen first: the server allows
 *                      three codes per address per ten minutes, and each cancels the last.
 *   EVERY WORD TRANSLATED  The refusals were typed in English in the script.
 *
 * The behaviour was driven in a browser (Playwright) when this was written; these hold the
 * source so it cannot quietly drift back.
 */
final class VoteCodeScreenTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    private static function fn(string $js, string $name): string
    {
        $at = strpos($js, $name);
        self::assertNotFalse($at, "$name exists");
        return substr($js, $at, 1800);
    }

    public function test_a_submit_on_the_code_step_confirms_and_never_requests_a_new_code(): void
    {
        $js = self::read('public/assets/js/vote.js');
        $submit = self::fn($js, "form.addEventListener('submit', function (e) {");
        $guard  = strpos($submit, 'if (stepOtp && !stepOtp.hidden) { confirmVote(); return; }');
        $ask    = strpos($submit, 'requestCode(');
        $this->assertNotFalse($guard, 'the code step is checked in the submit handler');
        $this->assertNotFalse($ask);
        $this->assertLessThan($ask, $guard, 'and before anything can ask for a code');
    }

    public function test_six_digits_confirm_themselves_and_resend_waits_where_it_can_be_seen(): void
    {
        $js = self::read('public/assets/js/vote.js');
        $this->assertMatchesRegularExpression("/if \\(digits\\.length === 6\\) confirmVote\\(\\);/", $js);
        $this->assertMatchesRegularExpression("/if \\(sent\\) cooldown\\(30\\);/", $js, 'a sent code starts the wait');
        $this->assertStringContainsString("resend.textContent = fill(resend.getAttribute('data-wait'), { n: secs })", $js, 'the wait is shown in numbers');
        // Twice-pressed Confirm (Enter after the sixth digit) must not post twice.
        $this->assertStringContainsString("if (!confirm || confirm.getAttribute('aria-busy') === 'true') return;", $js);

        $twig = self::read('templates/pages/vote-nominee.twig');
        $this->assertMatchesRegularExpression('/data-resend[^>]*data-wait="\{\{ \'Send a new code in %n%s\'\|trans \}\}"[^>]*disabled>/', $twig,
            'resend starts disabled — the first code has only just been sent');
        $this->assertStringContainsString('enterkeyhint="done"', $twig);
    }

    public function test_the_code_life_is_one_number_read_everywhere(): void
    {
        $svc = self::read('src/Services/OtpService.php');
        $this->assertStringContainsString('addMinutes(self::VOTE_CODE_MINUTES)', $svc);
        $this->assertDoesNotMatchRegularExpression('/(?:expires in|Expires in)[^\n]{0,40}\b10 minutes/', $svc, 'no typed copy in the email');
        $this->assertStringContainsString("constant('AfricaGates\\\\Services\\\\OtpService::VOTE_CODE_MINUTES')", self::read('templates/pages/vote-nominee.twig'));
        $this->assertSame(10, OtpService::VOTE_CODE_MINUTES);
    }

    public function test_no_refusal_in_the_ballot_is_typed_in_one_language(): void
    {
        $js = self::read('public/assets/js/vote.js');
        $free = self::fn($js, "var W = function (k, fallback)");
        // Every refusal the free ballot raises reads its words off the form first.
        preg_match_all("/(?:refuse|say)\\((?:err|errOtp|box), '([^']+)'/", $free, $typed);
        $this->assertSame([], $typed[1], 'a bare English sentence handed straight to the reader');
        $twig = self::read('templates/pages/vote-nominee.twig');
        foreach (['name', 'phone', 'email', 'code', 'net', 'ts', 'agree'] as $k) {
            $this->assertMatchesRegularExpression("/data-e-$k=\"\\{\\{ '[^']+'\\|trans \\}\\}\"/", $twig, "data-e-$k is handed over");
            $this->assertStringContainsString("W('$k'", $js, "and read");
        }
    }
}
