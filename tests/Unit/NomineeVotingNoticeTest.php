<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\Mail\MailLog;
use AfricaGates\Services\NomineeVotingLetter;
use AfricaGates\Services\NomineeVotingNotice;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\RuleEngine;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The formal letter a verified nominee receives once their award is on the public vote.
 *
 * Held here: who is owed it (verified by either road, voting open by the computed phase),
 * that it goes once and only once, that it never guesses an address, that the operator's
 * switch and signatory are honoured, and that the letter stays one page at the longest
 * inputs. Each test names the half of the rule it would catch breaking.
 */
final class NomineeVotingNoticeTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId = 0;
    private int $categoryId = 0;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    private function nominee(string $name, bool $confirmed = true, string $email = 'ada@example.com'): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->categoryId, 'name' => $name, 'status' => 'approved',
        ]);
        DB::table('gates_nominations')->insert([
            'cycle_id' => $this->cycleId, 'category_id' => $this->categoryId,
            'nominee_name' => $name, 'nominee_email' => $email,
            'nominator_name' => 'Someone', 'nominator_email' => 'n@example.com',
            'status' => 'approved',
            'nominee_confirmed_at' => $confirmed ? Carbon::now()->toDateTimeString() : null,
        ]);
        return $id;
    }

    /** A transport that records, and answers what it is told to. */
    private function recorder(array $answer = ['success' => true]): OtpService
    {
        $m = new class(['host' => 'localhost', 'port' => 25, 'username' => 'u', 'password' => 'p',
                        'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];
            public array $answer = ['success' => true];

            public function smtpConfigured(): bool { return true; }
            public function canSend(bool $bulk = false): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'attachments', 'unsubscribeUrl');
                return $this->answer;
            }
        };
        $m->answer = $answer;
        return $m;
    }

    private static function pages(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf);
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_a_verified_nominee_on_the_public_vote_receives_the_letter_and_the_email(): void
    {
        $id = $this->nominee('Ada Obi');
        $m  = $this->recorder();

        $r = NomineeVotingNotice::sweep($m);

        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $m->sent);
        $sent = $m->sent[0];
        $this->assertSame('ada@example.com', $sent['to']);
        $this->assertStringContainsString('Africa GATES Teachers Award 2027', $sent['subject']);

        $ref = NomineeVotingNotice::reference($this->cycleId, $id);
        foreach ([$sent['htmlBody'], $sent['plainBody']] as $body) {
            $this->assertStringContainsString('Ada Obi', $body);
            $this->assertStringContainsString('Teacher of the Year (Secondary)', $body);
            $this->assertStringContainsString($ref, $body);
            $this->assertStringContainsString('/vote/teachers-award/' . $id . '-', $body, 'their own voting page');
            $this->assertStringContainsString('never asks a nominee to pay', $body);
        }
        $this->assertSame('', $sent['unsubscribeUrl'], 'a notice about their own nomination, not a campaign');

        $this->assertCount(1, $sent['attachments']);
        $pdf = $sent['attachments'][0];
        $this->assertSame('application/pdf', $pdf['mime']);
        $this->assertStringStartsWith('%PDF', $pdf['body']);
        $this->assertSame(2, self::pages($pdf['body']), 'the letter, then the nomination guide with the steps');
        $this->assertStringEndsWith('.pdf', $pdf['name']);
    }

    public function test_it_is_sent_once_however_many_ticks_run(): void
    {
        $this->nominee('Ada Obi');
        $m = $this->recorder();

        NomineeVotingNotice::sweep($m);
        NomineeVotingNotice::sweep($m);
        NomineeVotingNotice::sweep($m);

        $this->assertCount(1, $m->sent);
    }

    public function test_approval_alone_is_not_verification(): void
    {
        $this->nominee('Ada Obi', confirmed: false);
        $m = $this->recorder();

        NomineeVotingNotice::sweep($m);

        $this->assertSame([], $m->sent, 'a moderator approving the form is not word from the person');
    }

    public function test_a_claimed_page_is_verification_and_the_letter_goes_to_the_claimant(): void
    {
        $id  = $this->nominee('Ada Obi', confirmed: false, email: 'typed-by-nominator@example.com');
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Ada Obi', 'email' => 'ada.own@example.com']);
        DB::table('gates_nominee_claims')->insert([
            'nominee_id' => $id, 'user_id' => $uid, 'status' => 'active', 'active_nominee_id' => $id,
        ]);
        $m = $this->recorder();

        NomineeVotingNotice::sweep($m);

        $this->assertCount(1, $m->sent);
        $this->assertSame('ada.own@example.com', $m->sent[0]['to'],
            'the person who proved the page is theirs, not whoever the nominator typed');
    }

    public function test_nothing_is_sent_before_voting_opens_or_after_it_closes(): void
    {
        $this->nominee('Ada Obi');
        $m = $this->recorder();

        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update([
            'status' => 'shortlisting',
            'voting_open' => Carbon::now()->addDays(3)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(30)->toDateTimeString(),
        ]);
        NomineeVotingNotice::sweep($m);
        $this->assertSame([], $m->sent, 'not yet voting');

        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update([
            'status' => 'voting',   // a stale column must not override the dates
            'voting_open' => Carbon::now()->subDays(30)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        NomineeVotingNotice::sweep($m);
        $this->assertSame([], $m->sent, 'a letter saying voting is open is false once it has closed');
    }

    public function test_a_verified_nominee_is_told_when_they_verify_after_voting_opened(): void
    {
        $this->nominee('Ada Obi', confirmed: false);
        $m = $this->recorder();
        NomineeVotingNotice::sweep($m);
        $this->assertSame([], $m->sent);

        DB::table('gates_nominations')->update(['nominee_confirmed_at' => Carbon::now()->toDateTimeString()]);
        NomineeVotingNotice::sweep($m);

        $this->assertCount(1, $m->sent, 'either half may become true second');
    }

    public function test_an_address_that_could_belong_to_two_people_is_never_guessed(): void
    {
        $this->nominee('Ada Obi', email: 'first@example.com');
        // A second nomination under the same name, in the same cycle, with another address.
        DB::table('gates_nominations')->insert([
            'cycle_id' => $this->cycleId, 'category_id' => $this->categoryId,
            'nominee_name' => 'Ada Obi', 'nominee_email' => 'second@example.com',
            'nominator_name' => 'Other', 'nominator_email' => 'o@example.com', 'status' => 'approved',
            'nominee_confirmed_at' => Carbon::now()->toDateTimeString(),
        ]);
        $m = $this->recorder();

        $r = NomineeVotingNotice::sweep($m);

        $this->assertSame([], $m->sent);
        $this->assertSame(1, $r['unreachable']);
    }

    public function test_a_deferred_message_is_tried_again_and_a_refused_one_is_not(): void
    {
        $this->nominee('Ada Obi');

        $m = $this->recorder(['success' => false, 'held' => MailLog::DEFERRED, 'error' => 'cap']);
        NomineeVotingNotice::sweep($m);
        $m->answer = ['success' => true];
        NomineeVotingNotice::sweep($m);
        $this->assertCount(2, $m->sent, 'deferred is not failed: nothing went, so a later tick sends it');

        $this->nominee('Bola Ade', email: 'bola@example.com');
        $m->answer = ['success' => false, 'held' => MailLog::REFUSED, 'error' => 'bounced'];
        NomineeVotingNotice::sweep($m);
        $m->answer = ['success' => true];
        NomineeVotingNotice::sweep($m);
        $this->assertCount(3, $m->sent, 'a refused address is final');
    }

    public function test_the_operator_can_switch_it_off_and_name_the_signatory(): void
    {
        $this->nominee('Ada Obi');
        $engine = new RuleEngine();

        $engine->merge('global', null, ['voting_letter_auto' => false]);
        $m = $this->recorder();
        NomineeVotingNotice::sweep($m);
        $this->assertSame([], $m->sent);

        $engine->merge('global', null, [
            'voting_letter_auto' => true,
            'voting_letter_signatory' => 'Oluwaseun Adeyemi',
            'voting_letter_signatory_title' => 'Secretary, Awards Committee',
        ]);
        NomineeVotingNotice::sweep($m);
        $this->assertCount(1, $m->sent);
        $this->assertStringContainsString('Oluwaseun Adeyemi', $m->sent[0]['htmlBody']);
        $this->assertStringContainsString('Secretary, Awards Committee', $m->sent[0]['plainBody']);
    }

    public function test_the_sandbox_is_never_written_to(): void
    {
        DB::table('gates_award_programmes')->where('id', $this->programmeId)
            ->update(['slug' => DemoSeeder::PROGRAMME_SLUG]);
        $this->nominee('Ada Obi');
        $m = $this->recorder();

        NomineeVotingNotice::sweep($m);

        $this->assertSame([], $m->sent);
    }

    /**
     * The letter is kept for years and the scoring weights are settings, so the body states
     * no figure at all, and the annex's figures are READ: changing the rules must change
     * the annex, which is the only way to tell a computed figure from a typed one.
     */
    public function test_how_the_vote_works_is_read_from_the_rules_and_not_typed(): void
    {
        $body = implode(' ', NomineeVotingLetter::body(self::view()));
        $this->assertDoesNotMatchRegularExpression('~\d+\s*%|per cent~', $body);
        $this->assertStringNotContainsStringIgnoringCase('shortlist', $body,
            'the ballot holds approved nominees; a shortlist is a separate fact the letter must not claim');

        $text = fn (): string => implode(' ', array_column(
            NomineeVotingNotice::howVotingWorks($this->programmeId, $this->cycleId), 'text'));

        $this->assertStringContainsString('carries 55% of the score', $text());
        $this->assertStringContainsString('at least 2 judges', $text());
        $this->assertStringNotContainsString('₦', $text(), 'an award that sells no votes is not told about prices');

        $engine = new RuleEngine();
        $engine->merge('global', null, ['judge_weight' => 0.6, 'community_weight' => 0.4, 'min_judges_per_nominee' => 3]);
        DB::table('gates_settings')->insert([
            ['key_name' => 'paid_voting_enabled', 'value' => '1'],
            ['key_name' => 'vote_price_naira', 'value' => '250'],
        ]);

        $this->assertStringContainsString('carries 60% of the score', $text());
        $this->assertStringContainsString('which carries 40%', $text());
        $this->assertStringContainsString('at least 3 judges', $text());
        $this->assertStringContainsString('₦250 per vote', $text());
        $this->assertStringContainsString('one free vote', $text());

        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_disable_free', 'value' => '1']);
        $this->assertStringNotContainsString('one free vote', $text(), 'never promise a free vote that does not exist');
    }

    public function test_the_annex_reaches_the_nominee_in_the_email_too(): void
    {
        $this->nominee('Ada Obi');
        $m = $this->recorder();

        NomineeVotingNotice::sweep($m);

        foreach (['htmlBody', 'plainBody'] as $part) {
            $this->assertStringContainsString('How the public vote works', $m->sent[0][$part]);
            $this->assertStringContainsString('carries 55% of the score', $m->sent[0][$part]);
        }
    }

    /**
     * Each page fits its paper, at A4 and at US Letter, which is 17.6mm shorter: the longest
     * name, category and award the console allows must still leave the close above the
     * pinned footer on page one, and the guide above it on page two.
     */
    public function test_each_page_fits_at_a4_and_at_letter_at_the_longest_inputs(): void
    {
        $v = self::view() + ['steps' => NomineeVotingNotice::steps()];
        $v['name']     = str_repeat('Ọlásùnkànmí Adébáyọ̀-Williams ', 5);
        $v['category'] = str_repeat('Outstanding Contribution to Rural Education ', 4);
        $v['award']    = str_repeat('Continental Teachers Award ', 3) . '11th Edition';

        // Every step drawn (free AND paid voting), and the longest signatory the form takes.
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        $v['steps'] = NomineeVotingNotice::steps();
        $v['signatory'] = str_repeat('N', 80);
        $v['signatory_title'] = str_repeat('Secretary, Awards Committee ', 3);

        foreach (['A4', 'letter'] as $paper) {
            // The column's real bottom against the footer: a page count cannot fail here,
            // because render() only ever draws one page and an overrun lands on it.
            $m = NomineeVotingLetter::measure($v, $paper);
            $this->assertLessThanOrEqual($m['limit'], $m['bottom'],
                sprintf('the letter runs %.1fmm into the footer on %s', $m['bottom'] - $m['limit'], $paper));
            $pdf = NomineeVotingLetter::render($v, $paper);
            $this->assertSame(2, self::pages($pdf), "the letter and the guide, and nothing spilling past them, on $paper");
            $g = NomineeVotingLetter::measure($v, $paper, 'guide');
            $this->assertLessThanOrEqual($g['limit'], $g['bottom'], "the guide runs into the footer on $paper");
            [$w, $h] = NomineeVotingLetter::PAPER[$paper];
            $this->assertStringContainsString(sprintf('/MediaBox [0 0 %.2F %.2F]', $w * 72 / 25.4, $h * 72 / 25.4), $pdf,
                "the page is not $paper");
        }
        // And the measure can fail: a story-length name does not fit, and it says so.
        $over = $v;
        $over['name'] = str_repeat('A very long name indeed ', 60);
        $m = NomineeVotingLetter::measure($over, 'letter');
        $this->assertGreaterThan($m['limit'], $m['bottom'], 'the check never fires, so it is checking nothing');

        $this->assertSame('letter', NomineeVotingLetter::paperFor('US'));
        $this->assertSame('A4', NomineeVotingLetter::paperFor('NG'));
        $this->assertSame('A4', NomineeVotingLetter::paperFor(''));
    }

    /**
     * The letter carries this edition's prizes by place (EditionPrizes) and the community return
     * as this edition runs it (owners, 30–31 Oct 2026), in the letter, the email and the plain
     * text, and the guide page still fits with the most places it draws at the longest text.
     */
    public function test_the_letter_carries_the_prizes_and_the_community_return(): void
    {
        $this->assertNull(\AfricaGates\Support\EditionPrizes::save($this->cycleId, [
            ['award' => 'A trophy and a certificate', 'cash' => '500,000'], ['award' => '', 'cash' => ''], ['award' => 'A certificate', 'cash' => ''],
        ]));
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        $r = \AfricaGates\Services\CommunityReturnMilestone::saveEditionRules($this->cycleId, ['model' => 'pool', 'full_pct' => 20, 'target_votes' => 750]);
        $this->assertTrue($r['ok'], $r['message']);
        $this->nominee('Ada Obi');
        $m = $this->recorder();
        NomineeVotingNotice::sweep($m);
        foreach (['htmlBody', 'plainBody'] as $part) {
            $this->assertStringContainsString('Prizes to be won', $m->sent[0][$part]);
            $this->assertStringContainsString('₦500,000 and a trophy and a certificate', $m->sent[0][$part]);
            $this->assertStringContainsString('2nd place', $m->sent[0][$part], 'a blank row leaves no gap');
            $this->assertStringNotContainsString('3rd place', $m->sent[0][$part]);
            $this->assertStringContainsString('overall winners of the edition, across every category', $m->sent[0][$part], 'the prizes are overall, never per category');
            $this->assertStringContainsString('Your community return', $m->sent[0][$part]);
            $this->assertStringContainsString('same target: 750 votes', $m->sent[0][$part], 'read from the edition, not typed');
            $this->assertStringContainsString('verified account', $m->sent[0][$part]);
        }

        // One page, at the longest prize text the form allows, and the full pool explanation.
        $v = self::view() + ['steps' => NomineeVotingNotice::steps()];
        $v['prizes'] = array_map(static fn (int $i): array => [\AfricaGates\Support\EditionPrizes::ordinal($i),
            '₦1,000,000 and ' . str_repeat('a trophy, mentoring and a residency ', 8)], range(1, 10));
        $v['community_return'] = NomineeVotingNotice::communityReturn($this->programmeId, $this->cycleId);
        $this->assertCount(5, $v['community_return']);
        $this->assertSame(['The target', 'Every nominee has the same target: 750 votes, free and paid together.'], $v['community_return'][1]);
        $short = $v + ['prizes' => array_map(static fn (int $i): array => [\AfricaGates\Support\EditionPrizes::ordinal($i), '₦1,000,000 and ' . str_repeat('x', 55)], range(1, 10))];
        foreach (['A4', 'letter'] as $paper) {
            $mm = NomineeVotingLetter::measure($short, $paper, 'guide');
            $this->assertLessThanOrEqual($mm['limit'], $mm['bottom'], 'eight one-line places run into the footer on ' . $paper);
            foreach (['letter', 'guide'] as $page) {
                $mm = NomineeVotingLetter::measure($v, $paper, $page);
                $this->assertLessThanOrEqual($mm['limit'], $mm['bottom'], sprintf('the %s page runs %.1fmm into the footer on %s', $page, $mm['bottom'] - $mm['limit'], $paper));
            }
            $this->assertSame(2, self::pages(NomineeVotingLetter::render($v, $paper)), 'the letter, then the guide');
        }
    }

    public function test_an_award_with_no_prize_and_no_paid_voting_says_neither(): void
    {
        $this->nominee('Ada Obi');
        $m = $this->recorder();
        NomineeVotingNotice::sweep($m);
        $this->assertStringNotContainsString('Prizes to be won', $m->sent[0]['htmlBody']);
        $this->assertStringNotContainsString('community return', $m->sent[0]['htmlBody'], 'nothing can be raised in a nominee’s name');
    }

    /** The steps are true of THIS edition: no free vote promised where there is none, no price where none is sold. */
    public function test_the_letters_steps_follow_the_voting_rules(): void
    {
        $steps = implode(' ', NomineeVotingNotice::steps());
        $this->assertStringContainsString('one free vote in your category', $steps);
        $this->assertStringNotContainsString('₦', $steps, 'an award that sells no votes is not told about prices');
        $this->assertStringNotContainsStringIgnoringCase('shortlist', $steps);
        $this->assertStringNotContainsString('a message;', $steps, 'the message is optional on the vote form');

        DB::table('gates_settings')->insert([
            ['key_name' => 'paid_voting_enabled', 'value' => '1'],
            ['key_name' => 'vote_price_naira', 'value' => '250'],
        ]);
        $steps = implode(' ', NomineeVotingNotice::steps());
        $this->assertStringContainsString('₦250 a vote', $steps);
        $this->assertStringContainsString('No one has to pay to vote', $steps);

        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_disable_free', 'value' => '1']);
        $steps = implode(' ', NomineeVotingNotice::steps());
        $this->assertStringNotContainsString('free vote', $steps, 'never promise a free vote that does not exist');
        $this->assertStringNotContainsString('No one has to pay', $steps, 'false where every vote is a contribution');
    }

    /** @return array<string,string> */
    private static function view(): array
    {
        return [
            'name' => 'Ada Obi', 'category' => 'Teacher of the Year', 'award' => 'Teachers Award 2027',
            'reference' => 'AG/VOT/1/00001', 'date' => '18 October 2026',
            'opens' => 'Monday 19 October 2026', 'closes' => 'Friday 20 November 2026 at 23:59 WAT',
            'vote_url' => 'https://example.org/vote/teachers-award/1-ada-obi',
            'rules_url' => 'example.org/integrity', 'signatory' => '', 'signatory_title' => 'Awards Secretariat',
        ];
    }
}
