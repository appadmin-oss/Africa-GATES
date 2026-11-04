<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\NomineeVotingNotice;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * THE LETTER MUST STATE THE RULE THE AWARD CHOSE — AND SAY SO WHEN IT GOT IT WRONG.
 *
 * 4 Nov 2026: an edition set up as a target pool (a ₦5,900,000 milestone typed in) was saved
 * with Threshold still in the model picker — the picker showed Threshold for an edition with
 * no rule of its own, and the save took whatever it showed. Every voting letter then told live
 * nominees "50% of each contribution once a nominee has 250 votes of qualifying support", and
 * /integrity printed the same house rule as though it were the only one.
 *
 * Three guards, each held here:
 *   · the save refuses a blank model, and a milestone the chosen model would ignore;
 *   · the letter waits while its community-return section would state a rule nobody chose;
 *   · the letters already sent under a rule the edition has since changed are found from the
 *     letter's own send log and can be corrected — once each, and only those.
 * And /integrity lists every open award's rule rather than one house rule.
 */
final class CommunityReturnLetterRuleTest extends TestCase
{
    private int $programmeId;
    private int $cycleId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-03-01 09:00:00'));
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'teachers-award', 'title' => 'Africa GATES Teachers Award', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2027, 'status' => 'voting',
            'nominations_open'  => Carbon::now()->subDays(60)->toDateTimeString(),
            'nominations_close' => Carbon::now()->subDays(30)->toDateTimeString(),
            'voting_open'       => Carbon::now()->subDays(2)->toDateTimeString(),
            'voting_close'      => Carbon::now()->addDays(20)->toDateTimeString(),
        ]);
        $this->categoryId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'secondary', 'title' => 'Teacher of the Year (Secondary)',
        ]);
        // Votes are sold, so the letter carries a community-return section at all.
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── The save ──────────────────────────────────────────────────────────────

    public function test_a_blank_model_is_refused_not_saved_as_threshold(): void
    {
        $r = Milestone::saveEditionRules($this->cycleId, ['model' => '', 'full_pct' => 50]);
        $this->assertFalse($r['ok'], 'a blank picker used to be saved as Threshold');
        $this->assertSame('default', $this->modelFrom(), 'nothing was written');
    }

    public function test_a_milestone_the_chosen_model_ignores_is_refused(): void
    {
        // The incident, exactly: Threshold in the picker, ₦5,900,000 in the milestone.
        $r = Milestone::saveEditionRules($this->cycleId, ['model' => 'threshold', 'full_pct' => 50, 'milestone_naira' => 5900000]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Threshold', $r['message']);
        $this->assertStringContainsString('₦5,900,000', $r['message']);

        foreach (['flat', 'tiered'] as $m) {
            $this->assertFalse(Milestone::saveEditionRules($this->cycleId, ['model' => $m, 'full_pct' => 50, 'milestone_naira' => 1000])['ok'], $m);
        }
        $this->assertFalse(Milestone::saveEditionRules($this->cycleId, ['model' => 'custom', 'ladder' => '0: 20', 'milestone_naira' => 1000])['ok'],
            'a custom ladder that is not held locked does not read the milestone');

        // Each model that reads it still takes it, and a model with no milestone saves.
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'custom', 'ladder' => '0: 20', 'ladder_locked' => '1', 'milestone_naira' => 1000])['ok']);
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'threshold', 'full_pct' => 50, 'milestone_naira' => 0])['ok']);
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'pool', 'full_pct' => 50, 'milestone_naira' => 5900000])['ok']);
        $this->assertSame('pool', \AfricaGates\Services\CommunityReturnService::modelForCycle($this->cycleId));
    }

    public function test_the_change_is_dated_only_when_what_a_nominee_is_told_changes(): void
    {
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'threshold', 'full_pct' => 40])['ok']);
        $first = $this->changedAt();
        $this->assertSame('2027-03-01 09:00:00', $first);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'threshold', 'full_pct' => 40])['ok']);
        $this->assertSame($first, $this->changedAt(), 're-saving the same rule must not make earlier letters look wrong');

        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'pool', 'full_pct' => 40, 'milestone_naira' => 75000])['ok']);
        $this->assertSame('2027-03-01 10:00:00', $this->changedAt());
    }

    // ── The hold ──────────────────────────────────────────────────────────────

    public function test_the_letter_waits_while_the_award_has_not_chosen_its_rule(): void
    {
        $this->nominee('Ada Obi', 'ada@example.com');
        $m = $this->recorder();

        $this->assertSame(0, NomineeVotingNotice::sweep($m)['sent'], 'the house rule must not be promised by default');
        $this->assertCount(0, $m->sent);
        $this->assertSame(NomineeVotingNotice::HOLD_MESSAGE, NomineeVotingNotice::status($this->cycle())['held']);
        $this->assertArrayHasKey('error', NomineeVotingNotice::sendNow($this->cycle(), $m), 'the button is held too');

        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'pool', 'full_pct' => 20, 'milestone_naira' => 75000])['ok']);
        $this->assertSame(1, NomineeVotingNotice::sweep($m)['sent']);
        $this->assertStringContainsString('750 votes each', $m->sent[0]['plainBody'], 'the rule chosen, not the house rule');
        $this->assertStringNotContainsString('250 votes of qualifying support', $m->sent[0]['plainBody']);
        $this->assertSame('', NomineeVotingNotice::status($this->cycle())['held']);
    }

    public function test_nothing_waits_where_the_letter_promises_no_return(): void
    {
        DB::table('gates_settings')->where('key_name', 'paid_voting_enabled')->delete();
        $this->nominee('Ada Obi', 'ada@example.com');
        $m = $this->recorder();
        $this->assertSame(1, NomineeVotingNotice::sweep($m)['sent'], 'no votes are sold, so no section and nothing to choose');
    }

    // ── The correction ────────────────────────────────────────────────────────

    public function test_letters_sent_under_a_rule_since_changed_are_corrected_once_each(): void
    {
        // The incident: Threshold saved, letters out, then the edition put right as a pool.
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'threshold', 'full_pct' => 50])['ok']);
        $this->nominee('Ada Obi', 'ada@example.com');
        $this->nominee('Bola Ade', 'bola@example.com');
        $m = $this->recorder();
        $this->assertSame(2, NomineeVotingNotice::sweep($m)['sent']);
        $this->assertStringContainsString('250 votes of qualifying support', $m->sent[0]['plainBody'], 'the wrong letter, as sent');
        $this->assertSame(0, NomineeVotingNotice::status($this->cycle())['correction_due'], 'nothing to correct yet');

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'pool', 'full_pct' => 50, 'milestone_naira' => 75000])['ok']);
        $this->assertSame(2, NomineeVotingNotice::status($this->cycle())['correction_due']);

        // A nominee approved after the change gets the right letter, and no correction.
        Carbon::setTestNow(Carbon::now()->addHour());
        $this->nominee('Chidi Eze', 'chidi@example.com');
        $this->assertSame(1, NomineeVotingNotice::sweep($m)['sent']);
        $this->assertStringContainsString('votes each', $m->sent[2]['plainBody']);

        $fix = $this->recorder();
        $r = NomineeVotingNotice::sendCorrections($this->cycle(), $fix);
        $this->assertSame(2, $r['sent']);
        $this->assertSame(['ada@example.com', 'bola@example.com'], array_column($fix->sent, 'to'), 'only those told the old rule');
        $sent = $fix->sent[0];
        $this->assertStringStartsWith('Correction to your nomination letter', $sent['subject']);
        foreach (['htmlBody', 'plainBody'] as $part) {
            $this->assertStringContainsString('1 March 2027', $sent[$part], 'names the letter it corrects');
            $this->assertStringContainsString('described a rule this edition does not run', $sent[$part]);
            $this->assertStringContainsString('sorry', $sent[$part]);
            $this->assertStringContainsString('votes each', $sent[$part], 'the rule that applies, from the same source as the letter');
            $this->assertStringNotContainsString('250 votes of qualifying support', $sent[$part]);
        }
        $this->assertStringStartsWith('%PDF', $sent['attachments'][0]['body'], 'the letter reissued');
        $this->assertSame('', $sent['unsubscribeUrl'], 'about their own nomination, not a campaign');

        $this->assertSame(0, NomineeVotingNotice::status($this->cycle())['correction_due']);
        $again = $this->recorder();
        $this->assertArrayHasKey('error', NomineeVotingNotice::sendCorrections($this->cycle(), $again));
        $this->assertCount(0, $again->sent, 'pressing twice corrects nobody twice');
    }

    /**
     * The live edition was put right on code that did not record the change. The repair dates
     * it from the rule row's own save time, so the correction can be offered — and dates
     * nothing whose letters all came after the save, or that already carries a date.
     */
    public function test_the_repair_dates_a_rule_saved_before_the_change_was_recorded(): void
    {
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'threshold', 'full_pct' => 50])['ok']);
        $this->nominee('Ada Obi', 'ada@example.com');
        NomineeVotingNotice::sweep($this->recorder());

        // The operator's fix, as the old code wrote it: the model changed, no date recorded.
        Carbon::setTestNow(Carbon::now()->addDay());
        $row  = DB::table('gates_rule_sets')->where('scope', 'cycle')->where('scope_id', $this->cycleId)->first();
        $doc  = json_decode((string) $row->rules, true);
        unset($doc[Milestone::CHANGED_AT]);
        $doc = ['community_return_model' => 'pool', 'community_return_milestone_naira' => 75000] + $doc;
        DB::table('gates_rule_sets')->where('id', $row->id)->update(['rules' => json_encode($doc), 'updated_at' => Carbon::now()->toDateTimeString()]);
        $this->assertSame(0, NomineeVotingNotice::status($this->cycle())['correction_due'], 'the fault the repair exists for');

        // A second edition whose only letter went out AFTER its rule was saved: left alone.
        $other = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $this->programmeId, 'year' => 2028, 'status' => 'upcoming']);
        DB::table('gates_rule_sets')->insert(['scope' => 'cycle', 'scope_id' => $other,
            'rules' => json_encode(['community_return_model' => 'pool']), 'updated_at' => '2027-01-01 00:00:00']);
        DB::table('gates_broadcast_log')->insert(['campaign' => NomineeVotingNotice::campaign($other), 'email_hash' => str_repeat('a', 64),
            'email' => 'x@example.com', 'status' => 'sent', 'sent_at' => '2027-02-01 00:00:00']);

        $ruleId = (int) $row->id;
        // In a closure: a migration's top-level variables (its own $row) would otherwise
        // overwrite this test's.
        ob_start();
        (static function (): void { require dirname(__DIR__, 2) . '/database/migrations/2027_05_08_return_rule_changed_repair.php'; })();
        ob_end_clean();

        $this->assertSame('2027-03-02 09:00:00', $this->changedAt(), 'dated from the save itself');
        $this->assertSame(1, NomineeVotingNotice::status($this->cycle())['correction_due']);
        $this->assertSame('2027-03-02 09:00:00', (string) DB::table('gates_rule_sets')->where('id', $ruleId)->value('updated_at'), 'the evidence is kept');
        $otherDoc = json_decode((string) DB::table('gates_rule_sets')->where('scope_id', $other)->value('rules'), true);
        $this->assertArrayNotHasKey(Milestone::CHANGED_AT, $otherDoc, 'no letter predates its save');
    }

    // ── Where the rule is published ───────────────────────────────────────────

    /**
     * Each award publishes its own rule in its award details; /integrity states only what holds
     * for every award and links there. It used to print one house rule as the site's, then a
     * list of every award's rule — neither is the place a nominee reads their award's terms.
     */
    public function test_the_award_details_state_the_editions_own_rule_and_integrity_does_not(): void
    {
        $a = \AfricaGates\Services\AwardsFront::detail('teachers-award');
        $this->assertNotNull($a);
        $this->assertSame([], $a['community_return'], 'no rule chosen: the house default is not presented as this award’s');
        $this->assertTrue($a['community_return_pending']);

        // The two-stage rule as the owner described it: 10% at first, 50% once the milestone is reached.
        $this->assertTrue(Milestone::saveEditionRules($this->cycleId, ['model' => 'milestone', 'locked_pct' => 10, 'full_pct' => 50, 'milestone_naira' => 5900000])['ok']);
        $a = \AfricaGates\Services\AwardsFront::detail('teachers-award');
        $this->assertFalse($a['community_return_pending']);
        $this->assertSame($a['community_return'], NomineeVotingNotice::communityReturn($this->programmeId, $this->cycleId), 'the letter’s rows, so the two cannot differ');
        $rule = $a['community_return'][0][1];
        $this->assertStringContainsString('10% of every eligible contribution', $rule);
        $this->assertStringContainsString('₦5,900,000', $rule, 'the milestone is named, not just referred to');
        $this->assertStringContainsString('50% of every contribution after it', $rule);

        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/integrity.twig');
        foreach (["'Qualifying support'", 'return_threshold', 'return_editions', 'The rule for each award'] as $gone) {
            $this->assertStringNotContainsString($gone, $twig, 'integrity states no award’s rule: ' . $gone);
        }
        $this->assertStringContainsString("href: '/awards'", $twig, 'and says where each award states it');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function cycle(): object
    {
        return DB::table('gates_award_cycles as cy')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('cy.id', $this->cycleId)
            ->select('cy.*', 'p.title as programme_title', 'p.slug as programme_slug')->first();
    }

    private function modelFrom(): string
    {
        return (new \AfricaGates\Services\RuleEngine())->provenance('community_return_model', $this->programmeId, $this->cycleId)['from'];
    }

    private function changedAt(): ?string
    {
        return (new \AfricaGates\Services\RuleEngine())->provenance(Milestone::CHANGED_AT, $this->programmeId, $this->cycleId)['value'];
    }

    private function nominee(string $name, string $email): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
        ]);
        DB::table('gates_nominations')->insert([
            'cycle_id' => $this->cycleId, 'category_id' => $this->categoryId,
            'nominee_name' => $name, 'nominee_email' => $email,
            'nominator_name' => 'Someone', 'nominator_email' => 'n@example.com',
            'status' => 'approved', 'nominee_confirmed_at' => Carbon::now()->toDateTimeString(),
        ]);
        return $id;
    }

    private function recorder(): OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function smtpConfigured(): bool { return true; }
            public function canSend(bool $bulk = false): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'attachments', 'unsubscribeUrl');
                return ['success' => true];
            }
        };
    }
}
