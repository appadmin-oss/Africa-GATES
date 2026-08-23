<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\QuestionnaireInvites;
use AfricaGates\Services\QuestionnairePolicy;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Bulk questionnaire invitations: who gets written to, and who does not, and why.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE OLD inviteAll() DID, AND WHY EACH IS A TEST HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   · It took every programme at once. → programme scoping.
 *   · It reported the number afterwards. → plan() sends nothing.
 *   · `if ($row['invited']) continue;` made a resend impossible. → include_invited.
 *   · It capped at 500 SILENTLY. → remaining is always reported.
 *
 * And one it never had a chance to get wrong, which matters more than all of them: it must
 * never write to a disqualified nominee. Asking somebody for work they have already been
 * ruled out of is the cruellest bug this feature could have.
 */
final class QuestionnaireInvitesTest extends TestCase
{
    private const CYCLE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insert([
            ['id' => 1, 'slug' => 'gates',  'title' => 'Africa GATES',  'is_active' => 1, 'sort_order' => 1],
            ['id' => 2, 'slug' => 'schools', 'title' => 'Schools Prize', 'is_active' => 1, 'sort_order' => 2],
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging',
            'results_date' => '2026-07-15 12:00:00',
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => 1, 'cycle_id' => self::CYCLE, 'slug' => 'c', 'title' => 'Category', 'sort_order' => 1,
        ]);
    }

    /**
     * A nominee with a nomination carrying their email — which is where
     * `ClaimIndependence::contactsFor()` reads addresses from.
     *
     * The nomination is joined by NAME within the category and not by an id, because
     * `gates_nominations` has no `nominee_id`: the nominee row is created from the
     * nomination at approval and the link was never written back. The fixture has to match
     * that, or it tests a join the application does not do.
     */
    private function nominee(int $id, string $email, int $programmeId = 1,
                             string $status = 'draft', array $over = []): void
    {
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => 1, 'name' => 'Nominee ' . $id, 'status' => 'approved',
        ]);
        if ($email !== '') {
            DB::table('gates_nominations')->insert([
                'cycle_id' => self::CYCLE, 'category_id' => 1,
                'nominee_name' => 'Nominee ' . $id, 'nominee_email' => $email,
                'nominator_name' => 'Nominator', 'nominator_email' => 'nom@example.com',
                'status' => 'approved',
            ]);
        }
        DB::table('gates_nominee_submissions')->insert($over + [
            'id' => $id, 'nominee_id' => $id, 'programme_id' => $programmeId,
            'cycle_id' => self::CYCLE, 'status' => $status,
            'invite_token' => str_pad((string) $id, 32, 'a'),
        ]);
    }

    // ══ scope ════════════════════════════════════════════════════════════════

    public function test_no_programme_chosen_means_every_programme_in_the_cycle(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        $this->nominee(2, 'two@example.com', 2);

        $this->assertSame(2, QuestionnaireInvites::plan(self::CYCLE)['counts']['sendable']);
    }

    public function test_choosing_a_programme_excludes_the_others(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        $this->nominee(2, 'two@example.com', 2);

        $plan = QuestionnaireInvites::plan(self::CYCLE, [2]);

        $this->assertSame(1, $plan['counts']['sendable']);
        $this->assertSame('two@example.com', $plan['rows'][0]['email']);
    }

    // ══ audience ═════════════════════════════════════════════════════════════

    public function test_the_default_audience_skips_anybody_who_already_submitted(): void
    {
        $this->nominee(1, 'draft@example.com', 1, 'draft');
        $this->nominee(2, 'done@example.com', 1, 'submitted');

        $plan = QuestionnaireInvites::plan(self::CYCLE, [], 'not_submitted');

        $this->assertSame(1, $plan['counts']['sendable']);
        $this->assertSame('draft@example.com', $plan['rows'][0]['email']);
    }

    public function test_never_invited_is_narrower_than_not_submitted(): void
    {
        $this->nominee(1, 'fresh@example.com', 1, 'draft');
        $this->nominee(2, 'chased@example.com', 1, 'draft', ['invited_at' => '2026-01-01 00:00:00']);

        $this->assertSame(2, QuestionnaireInvites::plan(self::CYCLE, [], 'not_submitted')['counts']['sendable']);
        $this->assertSame(1, QuestionnaireInvites::plan(self::CYCLE, [], 'never_invited')['counts']['sendable']);
    }

    public function test_all_includes_people_who_have_submitted(): void
    {
        $this->nominee(1, 'draft@example.com', 1, 'draft');
        $this->nominee(2, 'done@example.com', 1, 'submitted');

        $this->assertSame(2, QuestionnaireInvites::plan(self::CYCLE, [], 'all')['counts']['sendable']);
    }

    public function test_an_unknown_audience_falls_back_to_the_safe_one(): void
    {
        $this->nominee(1, 'draft@example.com', 1, 'draft');
        $this->nominee(2, 'done@example.com', 1, 'submitted');

        $plan = QuestionnaireInvites::plan(self::CYCLE, [], 'everybody-right-now');

        $this->assertSame('not_submitted', $plan['audience']);
        $this->assertSame(1, $plan['counts']['sendable']);
    }

    // ══ the buckets nobody may fall out of ═══════════════════════════════════

    /** THE ONE THAT MATTERS MOST. */
    public function test_a_disqualified_nominee_is_never_written_to(): void
    {
        $this->nominee(1, 'out@example.com', 1, 'disqualified');

        $plan = QuestionnaireInvites::plan(self::CYCLE, [], 'all');

        $this->assertSame(0, $plan['counts']['sendable'],
            'asking somebody for work they have been ruled out of is the worst bug this can have');
        $this->assertSame(1, $plan['counts']['disqualified']);
        $this->assertSame('disqualified', $plan['skipped'][0]['reason']);
    }

    /** Not "nothing happened" — somebody an organiser has to reach another way. */
    public function test_a_nominee_with_no_address_is_counted_and_named(): void
    {
        $this->nominee(1, '', 1);

        $plan = QuestionnaireInvites::plan(self::CYCLE);

        $this->assertSame(0, $plan['counts']['sendable']);
        $this->assertSame(1, $plan['counts']['no_email']);
        $this->assertStringContainsString('no email', $plan['skipped'][0]['reason']);
        $this->assertSame('Nominee 1', $plan['skipped'][0]['name'], 'named, so they can be chased by phone');
    }

    public function test_an_unsubscribed_address_is_excluded(): void
    {
        $this->nominee(1, 'gone@example.com', 1);
        EmailOptOut::record('gone@example.com', 'test');

        $plan = QuestionnaireInvites::plan(self::CYCLE);

        $this->assertSame(0, $plan['counts']['sendable']);
        $this->assertSame(1, $plan['counts']['unsubscribed']);
    }

    /** A rehearsal row has no nominee, so counting one shows a number that looks like a person. */
    public function test_a_test_row_is_never_a_recipient(): void
    {
        $this->nominee(1, 'real@example.com', 1);
        DB::table('gates_nominee_submissions')->insert([
            'id' => 99, 'nominee_id' => 0, 'programme_id' => 1, 'cycle_id' => self::CYCLE,
            'status' => 'draft', 'is_test' => 1, 'invite_token' => str_repeat('t', 32),
        ]);

        $plan = QuestionnaireInvites::plan(self::CYCLE, [], 'all');

        $this->assertSame(1, $plan['counts']['nominees']);
        $this->assertSame(1, $plan['counts']['sendable']);
    }

    /** Every matched nominee has to land in exactly one bucket, or a count lies. */
    public function test_the_buckets_partition_the_field_exactly(): void
    {
        $this->nominee(1, 'ok@example.com', 1);
        $this->nominee(2, '', 1);
        $this->nominee(3, 'gone@example.com', 1);
        $this->nominee(4, 'out@example.com', 1, 'disqualified');
        EmailOptOut::record('gone@example.com', 'test');

        $c = QuestionnaireInvites::plan(self::CYCLE, [], 'all')['counts'];

        $this->assertSame(
            $c['nominees'],
            $c['sendable'] + $c['no_email'] + $c['unsubscribed'] + $c['already'] + $c['disqualified'],
            'somebody is in two buckets or none — the numbers on the screen do not add up'
        );
    }

    // ══ idempotency and the resend ═══════════════════════════════════════════

    /**
     * The log is what makes a double-clicked button, a browser retry, or a re-run after a
     * timeout resume rather than repeat.
     */
    public function test_somebody_already_written_to_is_skipped_by_default(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        DB::table('gates_broadcast_log')->insert([
            'campaign' => QuestionnaireInvites::campaignKey(self::CYCLE),
            'email_hash' => EmailOptOut::hash('one@example.com'),
            'email' => 'one@example.com', 'status' => 'sent', 'sent_at' => '2026-01-01 00:00:00',
        ]);

        $plan = QuestionnaireInvites::plan(self::CYCLE);

        $this->assertSame(0, $plan['counts']['sendable']);
        $this->assertSame(1, $plan['counts']['already']);
    }

    /** And the resend is expressed by ASKING for it, not by the log forgetting. */
    public function test_a_resend_includes_them_again(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        DB::table('gates_broadcast_log')->insert([
            'campaign' => QuestionnaireInvites::campaignKey(self::CYCLE),
            'email_hash' => EmailOptOut::hash('one@example.com'),
            'email' => 'one@example.com', 'status' => 'sent', 'sent_at' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame(1, QuestionnaireInvites::plan(self::CYCLE, [], 'not_submitted', true)['counts']['sendable']);
    }

    /** A FAILED send is not "already sent" — the retry is the whole reason the row is kept. */
    public function test_a_previous_failure_is_retried_without_asking_for_a_resend(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        DB::table('gates_broadcast_log')->insert([
            'campaign' => QuestionnaireInvites::campaignKey(self::CYCLE),
            'email_hash' => EmailOptOut::hash('one@example.com'),
            'email' => 'one@example.com', 'status' => 'failed',
            'error' => 'SMTP timeout', 'sent_at' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame(1, QuestionnaireInvites::plan(self::CYCLE)['counts']['sendable']);
    }

    /** The log is per cycle, so last year's send does not suppress this year's. */
    public function test_a_send_in_another_cycle_does_not_suppress_this_one(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        DB::table('gates_broadcast_log')->insert([
            'campaign' => QuestionnaireInvites::campaignKey(99),
            'email_hash' => EmailOptOut::hash('one@example.com'),
            'email' => 'one@example.com', 'status' => 'sent', 'sent_at' => '2025-01-01 00:00:00',
        ]);

        $this->assertSame(1, QuestionnaireInvites::plan(self::CYCLE)['counts']['sendable']);
    }

    // ══ nothing is capped silently ═══════════════════════════════════════════

    public function test_the_plan_reports_the_whole_audience_even_beyond_one_batch(): void
    {
        for ($i = 1; $i <= QuestionnaireInvites::BATCH + 12; $i++) {
            $this->nominee($i, "n{$i}@example.com", 1);
        }

        $plan = QuestionnaireInvites::plan(self::CYCLE);

        $this->assertSame(QuestionnaireInvites::BATCH + 12, $plan['counts']['sendable'],
            'the old implementation capped at 500 and said so nowhere');
        $this->assertSame(QuestionnaireInvites::BATCH, $plan['batch'], 'and the screen knows the batch size');
    }

    // ══ the message ══════════════════════════════════════════════════════════

    public function test_the_email_carries_the_deadline_the_policy_actually_holds(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        QuestionnairePolicy::save(self::CYCLE, ['deadline_at' => '2026-05-20 18:00'], 7);

        $row  = QuestionnaireInvites::plan(self::CYCLE)['rows'][0];
        $html = QuestionnaireInvites::html($row, self::CYCLE, 'https://africagates.org');

        $this->assertStringContainsString('20 May 2026', $html);
        $this->assertStringContainsString('Please send it by', $html);
    }

    public function test_the_email_mentions_no_deadline_when_there_is_none(): void
    {
        DB::table('gates_award_cycles')->where('id', self::CYCLE)
            ->update(['results_date' => null, 'voting_close' => null]);
        $this->nominee(1, 'one@example.com', 1);

        $row  = QuestionnaireInvites::plan(self::CYCLE)['rows'][0];
        $html = QuestionnaireInvites::html($row, self::CYCLE, 'https://africagates.org');

        $this->assertStringNotContainsString('Please send it by', $html);
        $this->assertStringContainsString('Open my questionnaire', $html, 'the rest of the message still renders');
    }

    /**
     * The link IS the nominee's whole credential — they have no account — so it has to be
     * theirs, and it has to appear as text as well as inside the button.
     */
    public function test_the_email_carries_that_nominees_own_link_as_text_too(): void
    {
        $this->nominee(1, 'one@example.com', 1);

        $row  = QuestionnaireInvites::plan(self::CYCLE)['rows'][0];
        $html = QuestionnaireInvites::html($row, self::CYCLE, 'https://africagates.org');
        $tok  = str_pad('1', 32, 'a');

        $this->assertSame(3, substr_count($html, "/my-work/{$tok}"),
            'the VML button, the HTML button and the printed URL — all three');
    }

    /** The inbox-compatibility markers the whole template exists for. */
    public function test_the_email_keeps_every_inbox_compatibility_decision(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        $row  = QuestionnaireInvites::plan(self::CYCLE)['rows'][0];
        $html = QuestionnaireInvites::html($row, self::CYCLE, 'https://africagates.org');

        foreach ([
            '<w:anchorlock/>'       => 'the VML button is not clickable in Outlook without it',
            'v:roundrect'           => 'Outlook ignores CSS on an <a>, so the button needs VML',
            'max-width:560px'       => 'fluid-hybrid: Gmail strips <style> in some configurations',
            '[if mso]'              => 'Outlook needs its own fixed-width table',
            'prefers-color-scheme'  => 'an explicit dark palette beats a force-inverted one',
            'mso-line-height-rule'  => 'Word collapses long paragraphs to single spacing without it',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, $why);
        }

        $this->assertStringNotContainsString('src="data:', $html,
            'Gmail, Outlook and Yahoo all refuse a data: URI in an img src');
        $this->assertLessThan(102 * 1024, strlen($html),
            "past Gmail's clip point the footer is what gets cut, and that is where unsubscribe lives");
    }

    /**
     * The text/plain part is written, not strip_tags'd — which would yield the CSS, the MSO
     * conditionals and a wall of collapsed whitespace.
     */
    public function test_the_plain_text_alternative_is_real_prose(): void
    {
        $this->nominee(1, 'one@example.com', 1);
        $row = QuestionnaireInvites::plan(self::CYCLE)['rows'][0];
        $txt = QuestionnaireInvites::plain($row, self::CYCLE, 'https://africagates.org');

        $this->assertStringContainsString('YOUR PAGE: https://africagates.org/my-work/', $txt);
        $this->assertStringContainsString('Nothing here costs money', $txt,
            'the anti-fraud line is the most important sentence in the message');
        $this->assertStringNotContainsString('mso', $txt);
        $this->assertStringNotContainsString('<', $txt);
    }

    public function test_the_history_reports_what_was_already_sent(): void
    {
        DB::table('gates_broadcast_log')->insert([
            ['campaign' => QuestionnaireInvites::campaignKey(self::CYCLE), 'email_hash' => 'a',
             'email' => 'a@example.com', 'status' => 'sent',   'sent_at' => '2026-02-01 09:00:00'],
            ['campaign' => QuestionnaireInvites::campaignKey(self::CYCLE), 'email_hash' => 'b',
             'email' => 'b@example.com', 'status' => 'failed', 'sent_at' => '2026-02-01 09:01:00'],
        ]);

        $h = QuestionnaireInvites::history(self::CYCLE);

        $this->assertSame(1, $h['count']);
        $this->assertSame(1, $h['failed']);
        $this->assertSame('2026-02-01 09:01:00', $h['last_at']);
    }

    public function test_a_cycle_never_sent_to_reports_nothing_rather_than_erroring(): void
    {
        $this->assertSame(['count' => 0, 'failed' => 0, 'last_at' => null],
            QuestionnaireInvites::history(self::CYCLE));
    }
}
