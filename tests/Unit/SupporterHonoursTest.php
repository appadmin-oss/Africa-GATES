<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\SupporterHonours;
use AfricaGates\Services\CycleAnnouncer;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Telling supporters that what they did mattered — exactly once.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Both triggers re-run by design. A mint is retried by the reconciler and replayed
 * by webhooks; winner promotion re-enters CycleMaterialiser every time the
 * scheduler wakes on a cycle already at 'results'. So the interesting failure here
 * is not "the email did not send" — it is the same person being congratulated four
 * times for the same win, which turns the warmest message this platform sends into
 * something that reads like a fault.
 *
 * Most of the file is therefore about the claim, not the prose. The rest is about
 * consent: nobody is written to who did not give us an address for this purpose,
 * and nobody is NAMED who did not tick the box.
 */
final class SupporterHonoursTest extends TestCase
{
    /** @var list<array{to:string, subject:string, body:string, unsub:string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => Carbon::now()->subDays(5)->toDateTimeString(),
            'voting_close' => Carbon::now()->addDays(5)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Ada Obi',
            'status' => 'approved', 'vote_count' => 120,
        ]);

        // A mailer that records instead of sending, so the tests can read what a
        // supporter would actually have received rather than only that something
        // was attempted.
        $this->sent = [];
        SupporterHonours::using(new class($this->sent) extends OtpService {
            /** @param list<array{to:string,subject:string,body:string,unsub:string}> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject,
                                 'body' => $htmlBody . ' ' . $plainBody, 'unsub' => $unsubscribeUrl];
                return ['success' => true];
            }
        });
    }

    protected function tearDown(): void
    {
        SupporterHonours::using(null);
        parent::tearDown();
    }

    private function order(string $email, int $votes = 5, array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name' => 'Chidi Eze', 'donor_email' => $email,
            'amount_naira' => $votes * 1000, 'tier' => 'paid-vote', 'bonus_votes' => $votes,
            'votes_used' => $votes, 'intent_nominee_id' => 1,
            'payment_ref' => 'AFG-' . strtoupper(substr(md5($email . $votes . json_encode($over)), 0, 8)),
            'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString(),
        ], $over));
    }

    // ── Thanks ───────────────────────────────────────────────────────────────

    public function test_a_supporter_is_told_what_their_contribution_did(): void
    {
        $r = SupporterHonours::thank($this->order('chidi@example.com', 5));

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $this->sent);
        $this->assertSame('chidi@example.com', $this->sent[0]['to']);
        $this->assertStringContainsString('5 votes', $this->sent[0]['subject']);
        $this->assertStringContainsString('Ada Obi', $this->sent[0]['subject']);

        // The thing they cannot see anywhere else: the standing, and the company.
        $this->assertStringContainsString('120', $this->sent[0]['body'], 'their tally now');
        // And the separation, said to them rather than buried on a policy page.
        $this->assertStringContainsString('/integrity#money', $this->sent[0]['body']);
    }

    /**
     * THE CLAIM. A retried mint or a replayed webhook must not thank twice.
     */
    public function test_a_supporter_is_thanked_exactly_once_per_contribution(): void
    {
        $id = $this->order('chidi@example.com');

        $this->assertTrue(SupporterHonours::thank($id)['ok']);
        $this->assertSame('ALREADY_THANKED', SupporterHonours::thank($id)['code']);
        $this->assertSame('ALREADY_THANKED', SupporterHonours::thank($id)['code']);
        $this->assertCount(1, $this->sent);
    }

    /** Nothing was delivered, so there is nothing to thank anybody for yet. */
    public function test_an_order_whose_votes_never_minted_is_not_thanked(): void
    {
        $this->assertSame('NOTHING_DELIVERED',
            SupporterHonours::thank($this->order('stuck@example.com', 5, ['votes_used' => 0]))['code']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_refunded_or_unconfirmed_order_is_not_thanked(): void
    {
        $this->assertSame('REFUNDED', SupporterHonours::thank(
            $this->order('a@example.com', 5, ['refunded_at' => Carbon::now()->toDateTimeString()]))['code']);
        $this->assertSame('NOT_CONFIRMED', SupporterHonours::thank(
            $this->order('b@example.com', 5, ['status' => 'pending']))['code']);
        $this->assertSame([], $this->sent);
    }

    /** Minting runs it, and a re-mint does not run it again. */
    public function test_the_mint_path_thanks_the_supporter(): void
    {
        $id = $this->order('minted@example.com', 4, ['votes_used' => 0]);

        $this->assertTrue(PaidVoteService::mint($id)['ok']);
        $this->assertCount(1, $this->sent);
        $this->assertSame('minted@example.com', $this->sent[0]['to']);

        PaidVoteService::mint($id);
        $this->assertCount(1, $this->sent, 'a replayed mint must not write again');
    }

    // ── Lateness: the same message in five voices ────────────────────────────

    /**
     * A DELIVERY DUG OUT OF THE BACKLOG MUST NOT SOUND LIKE A NORMAL ONE.
     *
     * With no webhook configured, orders were charged and left unconfirmed for
     * days. Sending those people "your votes are counted!" when the sweep finally
     * runs is an insult to somebody who has spent a week believing they were
     * robbed. The message has to know how long it took — measured from when THEY
     * paid, not from when we noticed, which is the thing that went wrong.
     */
    public function test_a_prompt_delivery_does_not_mention_time_at_all(): void
    {
        SupporterHonours::thank($this->order('quick@example.com'));

        $this->assertStringContainsString('are counted', $this->sent[0]['subject']);
        $this->assertStringNotContainsString('sorry', strtolower($this->sent[0]['body']));
    }

    public function test_a_delivery_delayed_by_hours_apologises_and_says_how_long(): void
    {
        SupporterHonours::thank($this->order('slowish@example.com', 5,
            ['created_at' => Carbon::now()->subHours(6)->toDateTimeString()]));

        $body = $this->sent[0]['body'];
        $this->assertStringStartsWith('Delivered:', $this->sent[0]['subject']);
        $this->assertStringContainsString('6 hours', $body);
        $this->assertStringContainsString('We are sorry', $body);
        // The reassurance that actually matters to somebody who was waiting.
        $this->assertStringContainsString('dated from when you paid', $body);
    }

    /** Days deserve an explanation of the cause, not just an apology for the delay. */
    public function test_a_delivery_delayed_by_days_explains_what_went_wrong(): void
    {
        SupporterHonours::thank($this->order('waited@example.com', 5,
            ['created_at' => Carbon::now()->subDays(3)->toDateTimeString()]));

        $body = $this->sent[0]['body'];
        $this->assertStringContainsString('3 days', $body);
        $this->assertStringContainsString('never arrived', $body, 'the confirmation, not the payment');
        $this->assertStringContainsString('fixed the cause', $body);
    }

    /**
     * And the worst case owns it completely, including the part where they were
     * told to wait by a support desk that could not see the payment either.
     */
    public function test_the_longest_delay_takes_the_blame_squarely(): void
    {
        SupporterHonours::thank($this->order('abandoned@example.com', 5,
            ['created_at' => Carbon::now()->subDays(21)->toDateTimeString()]));

        $body = $this->sent[0]['body'];
        $this->assertStringContainsString('3 weeks', $body);
        $this->assertStringContainsString('our failure', $body);
        $this->assertStringContainsString('wrote to us about it', $body);
    }

    /** The band is recorded, so "who did we apologise to, and how badly" is answerable. */
    public function test_the_delay_band_is_recorded_on_the_honour(): void
    {
        SupporterHonours::thank($this->order('waited@example.com', 5,
            ['created_at' => Carbon::now()->subDays(3)->toDateTimeString()]));

        $this->assertSame('delay:days',
            (string) DB::table('gates_supporter_honours')->where('kind', 'thanks')->value('detail'));
    }

    /** Bands are wide on purpose — six and nine hours want the same apology. */
    public function test_the_bands_are_orders_of_magnitude_not_a_stopwatch(): void
    {
        $at = static fn (int $secs): string => SupporterHonours::lateness(
            Carbon::now()->subSeconds($secs)->toDateTimeString())['band'];

        $this->assertSame('prompt', $at(30));
        $this->assertSame('prompt', $at(4 * 60));
        $this->assertSame('slow',   $at(20 * 60));
        $this->assertSame('hours',  $at(6 * 3600));
        $this->assertSame('hours',  $at(9 * 3600));
        $this->assertSame('days',   $at(3 * 86400));
        $this->assertSame('long',   $at(21 * 86400));
    }

    /**
     * The delay is measured from the payment, NOT from the moment we caught up.
     * Dating it from our own discovery would report zero delay on precisely the
     * orders that were stranded longest — the ones this exists for.
     */
    public function test_the_clock_starts_when_they_paid_not_when_we_noticed(): void
    {
        $paid      = Carbon::now()->subDays(9)->toDateTimeString();
        $delivered = Carbon::now()->toDateTimeString();

        $this->assertSame('long', SupporterHonours::lateness($paid, $delivered)['band']);
        $this->assertSame('prompt', SupporterHonours::lateness($delivered, $delivered)['band'],
            'and a same-moment delivery is still prompt');
    }

    // ── Victory ──────────────────────────────────────────────────────────────

    public function test_every_backer_hears_when_their_nominee_wins(): void
    {
        foreach (['one@example.com', 'two@example.com', 'three@example.com'] as $e) $this->order($e);

        $r = SupporterHonours::celebrate(1, 'winner');

        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['sent']);
        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString('Ada Obi won. You helped.', $this->sent[0]['subject']);
        // Addressed to them as part of the result, not as a customer who was billed.
        $this->assertStringContainsString('You were one of', $this->sent[0]['body']);
    }

    /**
     * THE ONE THAT MATTERS. Promotion re-enters on every scheduler wake-up, so
     * without a claim per recipient this is the message that arrives four times.
     */
    public function test_nobody_is_congratulated_twice_however_often_promotion_reruns(): void
    {
        $this->order('one@example.com');
        $this->order('two@example.com');

        $first = SupporterHonours::celebrate(1, 'winner');
        $this->assertSame(2, $first['sent']);

        $again = SupporterHonours::celebrate(1, 'winner');
        $this->assertSame(0, $again['sent']);
        $this->assertSame(2, $again['skipped']);
        $this->assertCount(2, $this->sent);
    }

    /**
     * Somebody who unsubscribed is not congratulated.
     *
     * This was the only bulk sender in the codebase that consulted neither the opt-out
     * list nor carried a way out — QuestionnaireInvites, StandNotice, NomineeBroadcast
     * and the campaign screen all do both. And it is not a style inconsistency:
     * `pages/email-unsubscribe.twig` tells somebody who has just unsubscribed, in as many
     * words, that only what they specifically asked for still reaches them — a receipt, a
     * sign-in code, a support reply. This is the largest send the platform performs and it
     * is none of those.
     */
    public function test_an_unsubscribed_backer_is_not_congratulated(): void
    {
        $this->order('quiet@example.com');
        $this->order('loud@example.com');
        \AfricaGates\Services\EmailOptOut::record('quiet@example.com', 'test');

        $r = SupporterHonours::celebrate(1, 'winner');

        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['unsubscribed'], 'the count has to say why somebody was left out');
        $this->assertSame(['loud@example.com'], array_column($this->sent, 'to'));
    }

    /**
     * And the claim is not spent on them, so resubscribing still reaches them.
     *
     * The suppression check runs BEFORE the claim deliberately: claiming first would burn
     * the one-send-per-supporter mutex on a message that was never composed, and the
     * person would then be permanently unreachable for this win.
     */
    public function test_the_send_is_still_available_if_they_come_back(): void
    {
        $this->order('back@example.com');
        \AfricaGates\Services\EmailOptOut::record('back@example.com', 'test');
        SupporterHonours::celebrate(1, 'winner');
        $this->assertSame([], $this->sent);

        DB::table('gates_email_optout')->delete();
        $this->assertSame(1, SupporterHonours::celebrate(1, 'winner')['sent']);
    }

    /** Every congratulation carries a way out — the header one and the visible one. */
    public function test_the_congratulation_carries_an_unsubscribe_link(): void
    {
        $this->order('one@example.com');
        SupporterHonours::celebrate(1, 'winner');

        $this->assertStringContainsString('/email/unsubscribe?e=', $this->sent[0]['unsub'],
            'no List-Unsubscribe header URL was passed to the mailer');
        $this->assertStringContainsString('/email/unsubscribe?e=', $this->sent[0]['body'],
            'the plain-text part has no way out — the branded footer only covers the HTML');
    }

    /**
     * An unreadable opt-out list stops the fan-out instead of proceeding without it.
     *
     * Fail-open here means mailing everybody who ever asked not to be mailed, in one
     * burst, on the day of the results. Fail-closed means nobody is congratulated until
     * somebody notices — recoverable, and the claim rows are not spent either.
     */
    public function test_an_unreadable_optout_list_stops_the_send(): void
    {
        $this->order('one@example.com');
        DB::statement('ALTER TABLE gates_email_optout RENAME TO _optout_hidden');

        try {
            $r = SupporterHonours::celebrate(1, 'winner');

            $this->assertFalse($r['ok']);
            $this->assertSame('OPTOUT_UNREADABLE', $r['code']);
            $this->assertSame([], $this->sent);
        } finally {
            DB::statement('ALTER TABLE _optout_hidden RENAME TO gates_email_optout');
        }

        $this->assertSame(1, SupporterHonours::celebrate(1, 'winner')['sent'],
            'the claim was spent on a send that never happened');
    }

    /**
     * A thank-you still reaches somebody who unsubscribed, and that is the opposite
     * call on purpose.
     *
     * They paid for votes; this message is the confirmation that what they bought is on
     * the board, and when it is late it is the apology for that. The unsubscribe page's
     * own wording — "anything you specifically asked for still reaches you" — covers
     * exactly this and not a broadcast about somebody else winning.
     */
    public function test_a_thank_you_still_reaches_someone_who_unsubscribed(): void
    {
        \AfricaGates\Services\EmailOptOut::record('paid@example.com', 'test');

        $r = SupporterHonours::thank($this->order('paid@example.com', 4));

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('/email/unsubscribe?e=', $this->sent[0]['unsub'],
            'transactional or not, the reader should not have to go looking for the way out');
    }

    /** One person, several orders, one congratulations. */
    public function test_a_backer_who_gave_several_times_hears_once(): void
    {
        $this->order('loyal@example.com', 3);
        $this->order('loyal@example.com', 7);
        $this->order('LOYAL@Example.com ', 2);

        $this->assertSame(1, SupporterHonours::celebrate(1, 'winner')['sent']);
        $this->assertCount(1, $this->sent);
    }

    /** A refunded backer did not back anybody in the end. */
    public function test_a_refunded_backer_is_not_congratulated(): void
    {
        $this->order('gone@example.com', 5, ['refunded_at' => Carbon::now()->toDateTimeString()]);
        $this->order('here@example.com', 5);

        SupporterHonours::celebrate(1, 'winner');

        $this->assertCount(1, $this->sent);
        $this->assertSame('here@example.com', $this->sent[0]['to']);
    }

    /** A runner-up is a real result and gets its own wording, not the winner's. */
    public function test_a_runner_up_is_described_accurately(): void
    {
        $this->order('two@example.com');

        SupporterHonours::celebrate(1, 'runner_up');

        $this->assertStringContainsString('placed', $this->sent[0]['subject']);
        $this->assertStringNotContainsString('won', $this->sent[0]['subject']);
    }

    /**
     * The whole fan-out sits behind the same $announce gate as the nominee's own
     * congratulations — a backlog of cycles reaching 'results' at once must correct
     * the standings without writing to anybody about a competition that ended
     * months ago.
     */
    public function test_a_silent_promotion_writes_to_nobody(): void
    {
        $this->order('quiet@example.com');

        CycleAnnouncer::record(1, 'winner', false);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, DB::table('gates_supporter_honours')->count(),
            'and nothing is claimed, so a later real announcement can still send');
    }

    public function test_announcing_for_real_reaches_the_backers(): void
    {
        $this->order('loud@example.com');

        CycleAnnouncer::record(1, 'winner', true);

        $this->assertCount(1, $this->sent);
        $this->assertSame('loud@example.com', $this->sent[0]['to']);
    }

    // ── Consent ──────────────────────────────────────────────────────────────

    /**
     * The roll of honour publishes CONSENT, not names the platform happens to hold.
     * It reads through SupportersService for exactly this reason — a second
     * implementation of the rule would eventually publish somebody who did not
     * agree to be published.
     */
    public function test_the_roll_of_honour_names_only_people_who_asked_to_be_named(): void
    {
        DB::table('gates_votes')->insert([
            ['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => hash('sha256', 'p1@x.io'),
             'voter_name' => 'Named Ngozi', 'show_name' => 1, 'vote_type' => 'paid', 'weight' => 10,
             'voted_at' => Carbon::now()->toDateTimeString()],
            ['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => hash('sha256', 'p2@x.io'),
             'voter_name' => 'Private Peter', 'show_name' => 0, 'vote_type' => 'paid', 'weight' => 50,
             'voted_at' => Carbon::now()->toDateTimeString()],
        ]);

        $names = array_column(SupporterHonours::rollOfHonour(1), 'name');

        $this->assertContains('Named Ngozi', $names);
        $this->assertNotContains('Private Peter', $names,
            'the biggest backer stays off the wall because they did not consent');
    }

    /** Free voters left no address, deliberately, so there is nobody to write to. */
    public function test_free_voters_are_not_written_to_because_we_hold_no_address(): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => hash('sha256', 'free@x.io'),
            'voted_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->assertSame('NOBODY_REACHABLE', SupporterHonours::celebrate(1, 'winner')['code']);
        $this->assertSame([], $this->sent);
    }

    /** Whether it actually left is recorded, so a claimed count can be checked. */
    public function test_delivery_is_recorded_rather_than_assumed(): void
    {
        SupporterHonours::thank($this->order('chidi@example.com'));

        $row = DB::table('gates_supporter_honours')->where('kind', 'thanks')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->delivered);
        $this->assertSame(hash('sha256', 'chidi@example.com'), (string) $row->recipient_hash,
            'the address itself is never stored here');
    }

    // ── The page the celebration lives on ────────────────────────────────────

    /**
     * WINNING MUST NOT DELETE THE PAGE.
     *
     * The nominee lookup filtered on `status = 'approved'`, which is the state a
     * nominee holds only while the cycle is unfinished. CycleMaterialiser writes
     * 'winner' the moment the standings are sealed — so promotion 404'd the page,
     * every link shared during the campaign broke at the moment it finally meant
     * something, and the celebration email's "see the roll of honour" button
     * pointed at a not-found.
     *
     * Asserted against the real router, because the bug was in a query the template
     * never gets to see.
     */
    public function test_a_promoted_nominee_still_has_a_public_page(): void
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($builder->build());
        $app = \Slim\Factory\AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $get = static function (string $path) use ($app): int {
            $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', $path);
            return $app->handle($req)->getStatusCode();
        };

        $url = '/vote/p1/1-ada-obi';
        $this->assertSame(200, $get($url), 'an approved nominee has a page');

        foreach (['winner', 'runner_up'] as $promoted) {
            DB::table('gates_nominees')->where('id', 1)->update(['status' => $promoted]);
            $this->assertSame(200, $get($url),
                "a nominee promoted to '{$promoted}' must keep the page their supporters were sent to");
        }

        // …and the filter still does the job it was there for. The bare app has no
        // error middleware, so a miss arrives as the exception rather than a 404.
        DB::table('gates_nominees')->where('id', 1)->update(['status' => 'pending']);
        $this->expectException(\Slim\Exception\HttpNotFoundException::class);
        $get($url);
    }
}
