<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\BackedCard;
use AfricaGates\Services\NomineeThanks;
use AfricaGates\Services\ResultsDayMail;
use AfricaGates\Services\SupporterSpotlight;
use AfricaGates\Services\VoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * HONOURING THE PEOPLE WHO VOTED (owner, 16 Nov 2026: "make the voter feel good and proud of
 * themselves … to really honour them and make it feel worthwhile"; "build 1, 2 and 4").
 *
 *   1  results day: every backer told what became of the nominee they backed most, and that
 *      they were one of the people behind them (ResultsDayMail)
 *   2  the nominee's own thank-you, sent once to their backers (NomineeThanks)
 *   4  "I backed …", the supporter's card about their own vote (BackedCard)
 *
 * What these try to break: a message to somebody who did not back anybody, or to a free voter
 * the platform only holds as a hash; a results-day message for a release that was never
 * announced, or for somebody else's nominee; "one of N" that is not the board's N; any message
 * sent twice; a thank-you sent before its edit window closes, changeable after it went, or sent
 * to another nominee's backers; a card for a nominee on no live award.
 *
 * Ids below 255 (TINYINT clamp on MySQL, CLAUDE.md); one ballot row per vote, never a counter.
 */
final class VoterHonoursTest extends TestCase
{
    private const P = 81, Y = 8101, C = 8111, A = 8121, B = 8122, X = 8123;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'honour-award', 'title' => 'Honour Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => 2026, 'status' => 'results',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-30 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
        foreach ([self::A => ['Chiamaka Eze', 'winner'], self::B => ['Ibrahim Musa', 'runner_up'], self::X => ['Zainab Lawal', 'approved']] as $id => [$name, $st]) {
            DB::table('gates_nominees')->insert(['id' => $id, 'name' => $name, 'category_id' => self::C, 'status' => $st, 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
    }

    private function order(string $email, int $votes, int $nominee): void
    {
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Supporter', 'donor_email' => $email, 'amount_naira' => $votes * 200,
            'status' => 'confirmed', 'payment_ref' => 'vh-' . bin2hex(random_bytes(5)), 'tier' => 'paid-vote', 'created_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
        for ($i = 0; $i < $votes; $i++) {
            DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'paid', 'weight' => 1,
                'voter_email_hash' => 'paidvote:' . $id . ':' . bin2hex(random_bytes(5)), 'donation_id' => $id, 'voter_name' => 'Supporter', 'show_name' => 0,
                'voted_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
        }
    }

    private function free(string $email, int $nominee): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'standard', 'weight' => 1,
            'voter_email_hash' => VoteService::voterHash($email), 'voter_name' => '', 'show_name' => 0, 'voted_at' => date('Y-m-d H:i:s', strtotime('-9 days'))]);
    }

    private function member(string $email, int $verified = 1): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Member', 'email' => $email, 'status' => 'active', 'email_verified' => $verified,
            'points' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    }

    private function released(int $notify = 1, string $at = '-1 day'): void
    {
        DB::table('gates_cycle_transitions')->insert(['cycle_id' => self::Y, 'from_status' => 'judging', 'to_status' => 'results', 'reason' => 'test',
            'actor' => 'test', 'notify' => $notify, 'created_at' => date('Y-m-d H:i:s', strtotime($at))]);
    }

    /** @return array{0:callable, 1:\ArrayObject} */
    private function outbox(): array
    {
        $box = new \ArrayObject();
        return [static function (string $to, string $s, string $h, string $p) use ($box): bool { $box[] = ['to' => $to, 'subject' => $s, 'html' => $h, 'plain' => $p]; return true; }, $box];
    }

    // ── 1 · results day ──────────────────────────────────────────────────────────

    public function test_every_reachable_backer_hears_what_became_of_their_nominee_once(): void
    {
        $this->order('ada@example.test', 5, self::A);
        $this->order('bisi@example.test', 2, self::A);
        $this->order('bisi@example.test', 9, self::B);       // backed both: told about the one they backed MOST
        $this->member('member@example.test');
        $this->free('member@example.test', self::X);          // a free vote, reached through a verified account
        $this->free('nobody@example.test', self::X);          // a free voter with no account: a hash, unreachable
        $this->member('unverified@example.test', 0);
        $this->free('unverified@example.test', self::X);      // an unverified address proves nothing
        $this->released();
        [$send, $box] = $this->outbox();

        $this->assertSame(3, ResultsDayMail::sweep(null, null, 'https://site.test', $send));
        $by = [];
        foreach ($box as $m) $by[$m['to']] = $m;
        $this->assertSame(['ada@example.test', 'bisi@example.test', 'member@example.test'], array_values(array_keys($by) === [] ? [] : (function ($k) { sort($k); return $k; })(array_keys($by))));

        $this->assertSame('Chiamaka Eze won. You were part of it.', $by['ada@example.test']['subject']);
        $this->assertStringContainsString('Chiamaka Eze won Best Teacher at Honour Award', $by['ada@example.test']['plain']);
        $this->assertStringContainsString('You were one of 2 people who backed Chiamaka Eze.', $by['ada@example.test']['plain'], 'not the board’s count of people');
        $this->assertSame('Ibrahim Musa was runner-up in Best Teacher', $by['bisi@example.test']['subject'], 'told about a nominee they backed less');
        $this->assertStringContainsString('You were the one person who backed Ibrahim Musa.', $by['bisi@example.test']['plain']);
        $this->assertStringContainsString('Zainab Lawal did not win Best Teacher this time.', $by['member@example.test']['plain']);
        $this->assertStringContainsString('That support is on the record', $by['member@example.test']['plain']);
        foreach ($by as $m) {
            $this->assertStringContainsString('/results/' . self::C . '-', $m['plain']);
            $this->assertStringContainsString('/backed.png', $m['plain'], 'the card is not offered');
            $this->assertStringContainsString('Unsubscribe', $m['plain']);
            $this->assertDoesNotMatchRegularExpression('~\b(she|her|he|him|his)\b~i', $m['subject'] . ' ' . $m['plain'], 'a pronoun guessed from a name');
        }

        [$send2, $box2] = $this->outbox();
        $this->assertSame(0, ResultsDayMail::sweep(null, null, 'https://site.test', $send2), 'a second tick mailed somebody again');
        $this->assertCount(0, $box2);
    }

    public function test_nothing_goes_for_a_release_that_was_not_announced_or_is_old_or_is_the_sandbox(): void
    {
        $this->order('ada@example.test', 3, self::A);
        [$send, $box] = $this->outbox();
        $this->released(0);
        $this->assertSame(0, ResultsDayMail::sweep(null, null, 'https://site.test', $send), 'a stale, withheld release mailed congratulations');
        DB::table('gates_cycle_transitions')->delete();
        $this->released(1, '-20 days');
        $this->assertSame(0, ResultsDayMail::sweep(null, null, 'https://site.test', $send), 'a release weeks ago mailed today');
        DB::table('gates_cycle_transitions')->delete();
        $this->released();
        DB::table('gates_award_programmes')->where('id', self::P)->update(['is_active' => 0]);
        $this->assertSame(0, ResultsDayMail::sweep(null, null, 'https://site.test', $send), 'the sandbox mailed real addresses');
        $this->assertCount(0, $box);
    }

    // ── 2 · the nominee's thank-you ───────────────────────────────────────────────

    public function test_a_thank_you_goes_once_to_the_nominees_own_backers_after_its_window(): void
    {
        $this->order('ada@example.test', 2, self::A);
        $this->order('bisi@example.test', 1, self::A);
        $this->order('other@example.test', 4, self::B);      // backed somebody else
        $this->free('nobody@example.test', self::A);         // backed them, unreachable
        $uid = $this->member('chiamaka@example.test');

        $this->assertFalse(NomineeThanks::save(self::A, $uid, 'Thanks!')['ok'], 'a two-word note is not a thank-you');
        $this->assertFalse(NomineeThanks::save(self::A, $uid, str_repeat('a', NomineeThanks::MAX + 1))['ok']);
        $this->assertTrue(NomineeThanks::save(self::A, $uid, 'Thank you all. <b>Every</b> vote meant the world to the girls at the centre.')['ok']);
        $t = NomineeThanks::forNominee(self::A);
        $this->assertSame('Thank you all. Every vote meant the world to the girls at the centre.', $t->message, 'markup kept in a nominee’s message');
        $this->assertSame(['people' => 3, 'reach' => 2], NomineeThanks::audience(self::A));

        [$send, $box] = $this->outbox();
        $this->assertSame(0, NomineeThanks::sweep(null, Carbon::now(), 'https://site.test', $send), 'sent inside the window the nominee was told they had');
        $this->assertTrue(NomineeThanks::save(self::A, $uid, 'Thank you all. Every vote meant the world to the girls at Egbeda centre.')['ok'], 'a change inside the window was refused');

        $later = Carbon::now()->addMinutes(NomineeThanks::GRACE_MINUTES + 1);
        $this->assertSame(2, NomineeThanks::sweep(null, $later, 'https://site.test', $send));
        $to = array_column($box->getArrayCopy(), 'to');
        sort($to);
        $this->assertSame(['ada@example.test', 'bisi@example.test'], $to, 'the wrong people were thanked');
        $this->assertSame('A thank-you from Chiamaka Eze', $box[0]['subject']);
        $this->assertStringContainsString('girls at Egbeda centre', $box[0]['plain'], 'the changed message was not the one sent');
        $sent = NomineeThanks::forNominee(self::A);
        $this->assertNotNull($sent->sent_at);
        $this->assertSame(2, (int) $sent->reached);

        $this->assertSame(0, NomineeThanks::sweep(null, $later->copy()->addHour(), 'https://site.test', $send), 'thanked twice');
        $this->assertFalse(NomineeThanks::save(self::A, $uid, 'A second message, after the first had already gone out.')['ok'], 'changed after it went');
    }

    // ── 4 · "I backed …" ─────────────────────────────────────────────────────────

    public function test_the_card_is_drawn_for_a_live_nominee_and_names_nobody_else(): void
    {
        $this->order('ada@example.test', 2, self::A);
        $this->free('nobody@example.test', self::A);
        $f = BackedCard::facts(self::A);
        $this->assertSame('Chiamaka Eze', $f['name']);
        $this->assertSame(2, $f['people'], 'not the board’s count');
        $this->assertStringContainsString('Honour Award', $f['award']);
        $this->assertNull(BackedCard::facts(99999));
        if (!function_exists('imagecreatetruecolor') || !\AfricaGates\Services\FlierService::fontsPresent()['ok']) {
            $this->markTestIncomplete('GD or the bundled faces are not here; the facts are checked, the drawing is not');
        }
        $png = (new BackedCard())->png($f);
        $this->assertNotNull($png);
        $size = getimagesizefromstring((string) $png);
        $this->assertSame([1200, 630], [$size[0], $size[1]]);

        $res = \Tests\Support\ChromeRender::page('/vote/honour-award/' . self::A . '-chiamaka-eze/backed.png');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));

        DB::table('gates_award_programmes')->where('id', self::P)->update(['is_active' => 0]);
        $this->assertNull(BackedCard::facts(self::A), 'a card drawn for the sandbox');
    }

    /** All three are switched on where they live: the cron tick, the nominee's page, the ballot. */
    public function test_they_are_wired_in(): void
    {
        $root = dirname(__DIR__, 2);
        $m = (string) file_get_contents($root . '/src/Support/Maintenance.php');
        $this->assertStringContainsString("ResultsDayMail::sweep(\$this->mailer())", $m);
        $this->assertStringContainsString("NomineeThanks::sweep(\$this->mailer())", $m);
        $ballot = (string) file_get_contents($root . '/templates/pages/vote-nominee.twig');
        $this->assertStringContainsString('<section class="vb-card"', $ballot);
        $this->assertStringContainsString('{{ ctx.url }}/backed.png', $ballot);
        $this->assertStringContainsString('{% if thanks|default(null) %}', $ballot);
        $this->assertStringContainsString('src="{{ card_url }}"', (string) file_get_contents($root . '/templates/pages/vote-paid-success.twig'));
        $this->assertStringContainsString("NomineeUrl::path((int) \$nominee->id) . '/backed.png'", (string) file_get_contents($root . '/src/Controllers/PaidVoteController.php'), 'the card drawn from the configured host, not this one');
        $this->assertStringContainsString('<section class="nm-card" id="nm-thanks"', (string) file_get_contents($root . '/templates/nominee/profile.twig'));
    }
}
