<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\UserAccountService;
use AfricaGates\Support\Accent;
use Tests\TestCase;

/**
 * The handoff's emails (design_handoff_letter_email_admin/email), rendered for real.
 *
 * What each body may say is what is TRUE on the live site (owner, 19 Oct 2026). The design
 * typed a 10-minute code, a daily free vote that "comes back at midnight", and figures the
 * platform has never measured. So the tests here are of two kinds: the inbox shape every
 * key must have, and the facts, read from the constant or the rule that makes them true —
 * a body that types its own copy of a number fails the moment the two disagree.
 */
final class EmailsTest extends TestCase
{
    /** One plausible set of variables per key: the sender's real names. */
    private static function sample(string $key): array
    {
        return match ($key) {
            'otp'     => ['code' => '482913', 'minutes' => UserAccountService::OTP_TTL_MINUTES, 'valid_until' => '14:32 WAT'],
            'confirm' => ['first_name' => 'Ada', 'email' => 'ada@x.test', 'confirm_url' => 'https://x.test/account/verify?token=t', 'hours' => UserAccountService::VERIFY_TTL_HOURS],
            'reset'   => ['reset_url' => 'https://x.test/account/reset?token=t', 'ttl' => 'an hour'],
            'welcome' => ['first_name' => 'Ada', 'account_url' => 'https://x.test/account', 'free_voting' => true, 'vote_price' => ''],
            'nom-in'  => ['programme' => 'Teaching Award', 'nominee' => 'Ngozi Eze', 'nominee_first_name' => 'Ngozi', 'category' => 'Primary',
                          'reference' => 'AFG-NOM-0042', 'decide_by' => 'Monday 21 October', 'nominee_contacted' => true,
                          'voting_opens' => '1 November 2026', 'watch_url' => 'https://x.test/leaderboard'],
            'nominated' => ['programme' => 'Teaching Award', 'first_name' => 'Ngozi', 'quote' => 'She runs a reading club for 60 children.',
                          'quote_by' => 'Kofi Mensah, colleague', 'confirm_url' => '', 'confirm_days' => 7,
                          'watch_url' => 'https://x.test/leaderboard', 'help_url' => 'https://x.test/support'],
            'approved' => ['programme' => 'Teaching Award', 'first_name' => 'Ngozi', 'category' => 'Primary', 'due' => '18 November 2026',
                          'voting_opens' => '1 November 2026', 'form_url' => 'https://x.test/f/t', 'page_url' => 'https://x.test/vote/p/1-ngozi'],
            'rejected' => ['nominee' => 'Ngozi Eze', 'reason' => 'Outside the award’s region.', 'rules_url' => 'https://x.test/awards/p',
                          'help_url' => 'https://x.test/support'],
            'vote'     => ['first_name' => 'Ada', 'nominee' => 'Ngozi Eze', 'nominee_first_name' => 'Ngozi', 'category' => 'Primary',
                          'votes' => 20, 'votes_label' => '20', 'amount' => '₦2,000', 'reference' => 'paystack_123', 'page_url' => 'https://x.test/vote/p/1-ngozi'],
            'notminted' => ['first_name' => 'Ada', 'votes_label' => '20', 'nominee' => 'Ngozi Eze', 'amount' => '₦2,000', 'reference' => 'paystack_123',
                          'auto_refund' => true, 'assistant_url' => 'https://x.test/support/assistant', 'ticket_url' => 'https://x.test/support/tickets'],
            'gift'     => ['first_name' => 'Ada', 'amount' => '₦5,000', 'to_name' => 'Bright Futures', 'impact_line' => 'With gratitude.',
                          'reference' => 'AFG-DON-7', 'stop_url' => '', 'progress' => null, 'campaign_url' => ''],
            'refund'   => ['first_name' => 'Ada', 'charged' => '₦2,000', 'back' => '₦2,000', 'settled' => false, 'reference' => 'paystack_123',
                          'help_url' => 'https://x.test/support'],
            'milestone' => ['first_name' => 'Ngozi', 'full_name' => 'Ngozi Eze', 'votes' => '1,000', 'closes' => '20 November, 18:00 WAT',
                          'page_url' => 'https://x.test/vote/p/1-ngozi', 'by_people' => true, 'unsubscribe_url' => 'https://x.test/email/unsubscribe?e=x&t=y'],
            default   => self::fail("no sample for $key — add one when the key is added"),
        };
    }

    public function test_every_key_has_both_parts_and_the_inbox_shape(): void
    {
        foreach (array_keys(Emails::KEYS) as $key) {
            $m = Emails::compose($key, self::sample($key));
            $this->assertNotSame('', $m['subject'], "$key has no subject");
            $this->assertNotSame('', $m['preheader'], "$key has no preheader — the inbox shows the masthead's words instead");
            $this->assertNotSame('', $m['category'], "$key has no masthead label");
            $this->assertNotSame('', $m['why'], "$key does not say why it arrived");
            $this->assertNotSame('', $m['text'], "$key has no plain-text part");
            $this->assertStringNotContainsString('{{', $m['html'] . $m['text'], "$key left a variable unrendered");
            // A colour that resolved to nothing is `color:;` — an inbox paints it black.
            $this->assertDoesNotMatchRegularExpression('~(?:color|background)\s*:\s*[;"\']~', $m['html'], "$key has a blank colour");
            $this->assertDoesNotMatchRegularExpression('~#[0-9a-f]{6}~i', str_replace(array_values(Accent::mail()), '', $m['html']),
                "$key draws a colour outside the palette");
            // The plain part is plain: "&amp;" and "&#039;" in a text mail are escaping that leaked.
            $this->assertDoesNotMatchRegularExpression('~&(?:amp|#0?39|quot|lt|gt);~', $m['text'], "$key's plain text is HTML-escaped");
            if ($m['hero'] !== '') {
                $this->assertFileExists(dirname(__DIR__, 2) . '/public' . parse_url($m['hero'], PHP_URL_PATH), "$key names an illustration that is not shipped");
            }
        }
    }

    public function test_account_and_nomination_emails_are_transactional_and_carry_no_unsubscribe(): void
    {
        foreach (['otp', 'confirm', 'reset', 'welcome', 'nom-in', 'nominated', 'approved', 'rejected', 'vote', 'notminted', 'gift', 'refund'] as $key) {
            $this->assertTrue(Emails::compose($key, self::sample($key))['transactional'],
                "$key would carry an Unsubscribe link — a sign-in code with one invites somebody to switch off the way in");
        }
    }

    public function test_the_windows_are_the_constants_that_expire_them(): void
    {
        $otp = Emails::compose('otp', self::sample('otp'));
        $this->assertStringContainsString('for ' . UserAccountService::OTP_TTL_MINUTES . ' minutes', $otp['text']);
        $this->assertStringNotContainsString('10 minutes', $otp['text'], 'the design\'s typed 10 minutes is back');

        $confirm = Emails::compose('confirm', self::sample('confirm'));
        $this->assertStringContainsString('works for ' . UserAccountService::VERIFY_TTL_HOURS . ' hours', $confirm['text']);
    }

    public function test_a_private_nominator_is_never_named_to_the_nominee(): void
    {
        $private = Emails::compose('nominated', ['quote_by' => ''] + self::sample('nominated'));
        $this->assertStringNotContainsString('Kofi', $private['html'] . $private['text'] . $private['preheader']);
        $this->assertStringContainsString('Someone who knows your work', $private['html']);

        $named = Emails::compose('nominated', self::sample('nominated'));
        $this->assertStringContainsString('Kofi Mensah, colleague', $named['html']);
    }

    public function test_the_nominee_is_never_told_to_reply_to_an_unread_inbox(): void
    {
        foreach (['nominated' => ['confirm_url' => ''], 'nominated ' => ['confirm_url' => 'https://x.test/c/t'], 'rejected' => []] as $key => $extra) {
            $key = trim($key);
            $m = Emails::compose($key, $extra + self::sample($key));
            $this->assertStringNotContainsStringIgnoringCase('reply to this email', $m['html'] . $m['text'],
                "$key tells somebody to reply to an inbox nobody reads (owner, 19 Oct 2026: the help desk)");
        }
    }

    public function test_the_confirm_path_states_the_link_window_from_the_constant(): void
    {
        $m = Emails::compose('nominated', ['confirm_url' => 'https://x.test/c/t', 'confirm_days' => \AfricaGates\Services\NomineeConfirmation::TTL_DAYS] + self::sample('nominated'));
        $this->assertStringContainsString('works for ' . \AfricaGates\Services\NomineeConfirmation::TTL_DAYS . ' days', $m['text']);
        $this->assertStringContainsString('https://x.test/c/t', $m['html']);
    }

    public function test_a_rejection_with_no_reason_does_not_invent_one(): void
    {
        $m = Emails::compose('rejected', ['reason' => ''] + self::sample('rejected'));
        $this->assertStringNotContainsString('Reason', $m['html']);
        $this->assertStringNotContainsString('eligibility', $m['html'], 'a reason nobody gave was supplied');
    }

    public function test_a_receipt_never_describes_a_daily_free_vote(): void
    {
        foreach (['vote', 'refund', 'welcome'] as $key) {
            $m = Emails::compose($key, self::sample($key));
            foreach (['daily', 'midnight', 'each day', 'every day'] as $bad) {
                $this->assertStringNotContainsStringIgnoringCase($bad, $m['html'] . $m['text'],
                    "$key describes a free vote that comes back daily — it is one per category");
            }
        }
    }

    public function test_votes_not_added_never_promises_the_votes_and_states_the_refund_that_runs(): void
    {
        $auto = Emails::compose('notminted', self::sample('notminted'));
        $this->assertStringNotContainsStringIgnoringCase('adds your votes', $auto['html'], 'the votes cannot be added after a category closes');
        $this->assertStringContainsString('refund it automatically', $auto['html']);
        $this->assertStringNotContainsString('/support/assistant', $auto['html'], 'an automatic refund was sent to the assistant to ask for one');

        $manual = Emails::compose('notminted', ['auto_refund' => false] + self::sample('notminted'));
        $this->assertStringNotContainsString('automatically', $manual['html']);
        $this->assertStringContainsString('/support/assistant', $manual['html']);
    }

    public function test_a_monthly_gift_always_says_so_with_its_stop_link(): void
    {
        $m = Emails::compose('gift', ['stop_url' => 'https://x.test/giving/stop?r=AFG-DON-7'] + self::sample('gift'));
        $this->assertStringContainsString('https://x.test/giving/stop?r=AFG-DON-7', $m['html']);
        $this->assertStringContainsString('https://x.test/giving/stop?r=AFG-DON-7', $m['text']);
        $this->assertStringContainsString('monthly gift', $m['html']);
        $one = Emails::compose('gift', self::sample('gift'));
        $this->assertStringNotContainsString('monthly', $one['html'], 'a one-off gift was called monthly');
    }

    public function test_a_refund_does_not_describe_votes_that_were_never_added(): void
    {
        $m = Emails::compose('refund', self::sample('refund'));
        $this->assertStringNotContainsString('removed from the tally', $m['html']);
        $this->assertStringContainsString('no votes were added', $m['html']);
        $quiet = Emails::compose('refund', ['back' => ''] + self::sample('refund'));
        $this->assertStringContainsString('In full', $quiet['html'], 'an unreported amount was printed as a figure');
    }

    public function test_the_milestone_counts_votes_and_is_an_announcement(): void
    {
        $m = Emails::compose('milestone', self::sample('milestone'));
        $this->assertFalse($m['transactional'], 'the milestone must carry Unsubscribe and List-Unsubscribe');
        $this->assertStringNotContainsString('people stood up', $m['html'], 'votes were called people');
        $this->assertStringContainsString('https://x.test/email/unsubscribe', $m['text']);
        $flat = Emails::compose('milestone', ['by_people' => false] + self::sample('milestone'));
        $this->assertStringNotContainsString('separate people', $flat['html'], 'a basis that does not count people was said to');
    }

    public function test_the_welcome_states_the_voting_rule_that_runs(): void
    {
        $free = Emails::compose('welcome', ['free_voting' => true] + self::sample('welcome'));
        $this->assertStringContainsString('One free vote in each category', $free['html']);

        $paid = Emails::compose('welcome', ['free_voting' => false, 'vote_price' => '₦100'] + self::sample('welcome'));
        $this->assertStringNotContainsString('free vote', strtolower($paid['html']), 'a free vote was promised on an edition that has none');
        $this->assertStringContainsString('₦100 a vote', $paid['text']);

        foreach ([$free, $paid] as $m) {
            $this->assertStringNotContainsString('midnight', $m['html'], 'the design\'s daily free vote is not the rule');
            $this->assertStringNotContainsString('each day', $m['html'], 'the design\'s daily free vote is not the rule');
        }
    }
}
