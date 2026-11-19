<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NomineeBackers;
use AfricaGates\Services\NomineeUpdates;
use AfricaGates\Services\NomineeVoice;
use AfricaGates\Services\SupporterSpotlight;
use AfricaGates\Services\VoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\UploadedFile;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE NOMINEE'S SIDE (owner, 16 Nov 2026: "build them all"): who's behind them and growth
 * (NomineeBackers::dashboard), thanking each mobiliser personally (NomineeBackers::thank), their
 * own updates to the backers who asked (NomineeUpdates), and a voice thank-you (NomineeVoice).
 *
 * What these try to break: a nominee mailing somebody who never mobilised for them, or the same
 * person twice, or nobody being able to try again after a send failed; a supporter counted new
 * every week they vote; a nominee posting every hour, an update mailed before its grace or after
 * it was taken down, or to a backer who never asked; a file that is not audio played under our
 * name; an old clip left on disk; the sandbox's voice served; Safari's range request ignored.
 *
 * Ids below 255 (TINYINT clamp on MySQL); one ballot row per vote.
 */
final class NomineeVoiceAndUpdatesTest extends TestCase
{
    private const P = 95, Y = 9501, C = 9511, A = 9521, B = 9522;
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        SupporterSpotlight::$ttl = 0;
        $this->dir = sys_get_temp_dir() . '/ag-voice-' . bin2hex(random_bytes(4));
        NomineeVoice::$root = $this->dir;
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'voice-award', 'title' => 'Voice Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-20 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+5 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
        DB::table('gates_nominees')->insert(['id' => self::A, 'name' => 'Chiamaka Eze', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_nominees')->insert(['id' => self::B, 'name' => 'Ibrahim Musa', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
    }

    protected function tearDown(): void
    {
        SupporterSpotlight::$ttl = 60;
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($this->dir);
        NomineeVoice::$root = null;
        parent::tearDown();
    }

    private function free(string $email, int $nominee, string $at = '-2 days', string $name = '', int $show = 0): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'standard', 'weight' => 1,
            'voter_email_hash' => VoteService::voterHash($email), 'voter_name' => $name, 'show_name' => $show, 'voted_at' => date('Y-m-d H:i:s', strtotime($at))]);
    }

    private function order(string $email, int $votes, int $nominee, string $at): void
    {
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Supporter', 'donor_email' => $email, 'amount_naira' => $votes * 200,
            'status' => 'confirmed', 'payment_ref' => 'nv-' . bin2hex(random_bytes(5)), 'tier' => 'paid-vote', 'created_at' => date('Y-m-d H:i:s', strtotime($at))]);
        for ($i = 0; $i < $votes; $i++) {
            DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'paid', 'weight' => 1,
                'voter_email_hash' => 'paidvote:' . $id . ':' . bin2hex(random_bytes(5)), 'donation_id' => $id, 'voter_name' => '', 'show_name' => 0,
                'voted_at' => date('Y-m-d H:i:s', strtotime($at))]);
        }
    }

    private function recruit(string $email, int $nominee, int $people): int
    {
        $rid = (int) DB::table('gates_vote_recruits')->insertGetId(['code' => 'NV' . random_int(10000, 99999), 'email_hash' => VoteService::voterHash($email), 'nominee_id' => $nominee]);
        for ($i = 0; $i < $people; $i++) DB::table('gates_vote_recruit_credits')->insert(['recruit_id' => $rid, 'voter_hash' => hash('sha256', $email . $i)]);
        return $rid;
    }

    /** @return array{0:callable, 1:\ArrayObject} */
    private function outbox(bool $ok = true): array
    {
        $box = new \ArrayObject();
        return [static function (string $to, string $s, string $h, string $p) use ($box, $ok): bool { $box[] = ['to' => $to, 'subject' => $s, 'plain' => $p]; return $ok; }, $box];
    }

    private function upload(string $bytes, string $mime = 'audio/webm', string $name = 'thanks.webm'): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'agv');
        file_put_contents($f, $bytes);
        return new UploadedFile($f, $name, $mime, strlen($bytes), UPLOAD_ERR_OK);
    }

    private function webm(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02" . str_repeat("\0", 300);
    }

    // ── who's behind you ─────────────────────────────────────────────────────────

    public function test_growth_counts_each_person_once_in_the_week_they_first_backed(): void
    {
        $this->free('old@example.test', self::A, '-10 days');
        $this->order('old@example.test', 3, self::A, '-1 day');        // the same person again this week: not new
        $this->free('new1@example.test', self::A, '-2 days');
        $this->order('new2@example.test', 4, self::A, '-3 days');
        $this->free('other@example.test', self::B, '-1 day');
        $d = NomineeBackers::dashboard(self::A);
        $this->assertSame(3, $d['people']);
        $this->assertSame(9, $d['votes']);
        $this->assertSame(2, $d['week'], 'a returning supporter counted as new');
        $this->assertSame(1, $d['last_week']);
        $this->assertStringEndsWith('/poster', $d['poster']);
    }

    public function test_mobilisers_are_named_only_as_the_board_names_them(): void
    {
        $this->free('named@example.test', self::A, '-2 days', 'Bisi Ade', 1);
        $this->free('quiet@example.test', self::A, '-2 days', 'Quiet Person', 0);
        $this->recruit('quiet@example.test', self::A, 4);
        $this->recruit('named@example.test', self::A, 2);
        $this->recruit('elsewhere@example.test', self::B, 9);
        $m = NomineeBackers::mobilisers(self::A);
        $this->assertSame([['A supporter', 4], ['Bisi Ade', 2]], array_map(fn ($r) => [$r['name'], $r['people']], $m), 'most first, names by consent, nobody else\'s mobilisers');
        $this->assertFalse($m[0]['reachable'], 'a free voter with no account has no address');
    }

    public function test_a_mobiliser_is_thanked_personally_once_and_only_a_mobiliser(): void
    {
        // A free voter with no account is a hash only and cannot be reached; a checkout gave an address.
        $this->free('hashonly@example.test', self::A);
        $unreachable = $this->recruit('hashonly@example.test', self::A, 2);
        $this->order('mob@example.test', 2, self::A, '-1 day');
        $rid = $this->recruit('mob@example.test', self::A, 7);
        $other = $this->recruit('elsewhere@example.test', self::B, 3);
        [$send, $box] = $this->outbox();

        $this->assertFalse(NomineeBackers::thank(self::A, $other, '', null, 'https://ag.test', $send)['ok'], 'thanked somebody who mobilised for somebody else');
        $this->assertFalse(NomineeBackers::thank(self::A, 99999, '', null, 'https://ag.test', $send)['ok']);
        $this->assertFalse(NomineeBackers::thank(self::A, $unreachable, '', null, 'https://ag.test', $send)['ok'], 'mailed an address we were never given');
        $this->assertFalse(NomineeBackers::mobilisers(self::A)[1]['reachable']);
        $this->assertCount(0, $box);

        // A failed send is not a thank-you said: it can be tried again.
        [$fail, $fbox] = $this->outbox(false);
        $this->assertFalse(NomineeBackers::thank(self::A, $rid, 'Thank you!', null, 'https://ag.test', $fail)['ok']);
        $this->assertCount(1, $fbox);

        $r = NomineeBackers::thank(self::A, $rid, 'You carried Ikorodu for me.', null, 'https://ag.test', $send);
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $box);
        $this->assertSame('mob@example.test', $box[0]['to']);
        $this->assertSame('Chiamaka Eze thanked you personally', $box[0]['subject']);
        $this->assertStringContainsString('7 people', $box[0]['plain']);
        $this->assertStringContainsString('You carried Ikorodu for me.', $box[0]['plain']);
        $this->assertStringContainsString('Unsubscribe', $box[0]['plain']);

        $this->assertFalse(NomineeBackers::thank(self::A, $rid, 'Again', null, 'https://ag.test', $send)['ok'], 'the same person thanked twice');
        $this->assertCount(1, $box);
        $this->assertTrue(NomineeBackers::mobilisers(self::A)[0]['thanked']);
        $this->assertFalse(NomineeBackers::mobilisers(self::A)[1]['thanked']);
    }

    // ── updates ──────────────────────────────────────────────────────────────────

    public function test_one_update_every_three_days_and_a_withdrawn_one_does_not_count(): void
    {
        $now = Carbon::now();
        $this->assertFalse(NomineeUpdates::post(self::A, 1, 'too short', $now)['ok']);
        $this->assertFalse(NomineeUpdates::post(self::A, 1, str_repeat('a', NomineeUpdates::MAX + 1), $now)['ok']);
        $this->assertTrue(NomineeUpdates::post(self::A, 1, 'We opened the second classroom.', $now)['ok']);
        $id = (int) DB::table('gates_nominee_updates')->max('id');
        $this->assertFalse(NomineeUpdates::post(self::A, 1, 'And another thing to say.', $now->copy()->addHours(5))['ok'], 'two updates in five hours');

        // Taken down inside its grace: it never went, so it does not hold up the next one.
        $this->assertTrue(NomineeUpdates::remove(self::A, $id, $now->copy()->addMinutes(2)));
        $this->assertTrue(NomineeUpdates::post(self::A, 1, 'Let me say it better this time.', $now->copy()->addMinutes(3))['ok']);
        $this->assertFalse(NomineeUpdates::remove(self::B, (int) DB::table('gates_nominee_updates')->max('id')), 'took down another nominee\'s update');

        // Changeable only inside the grace.
        $last = (int) DB::table('gates_nominee_updates')->max('id');
        $this->assertTrue(NomineeUpdates::edit(self::A, $last, 'Let me say it better, again.', $now->copy()->addMinutes(5))['ok']);
        $this->assertFalse(NomineeUpdates::edit(self::A, $last, 'Too late to change this.', $now->copy()->addMinutes(30))['ok']);
        $this->assertTrue(NomineeUpdates::post(self::A, 1, 'Three days on, a new update.', $now->copy()->addHours(NomineeUpdates::COOLDOWN_HOURS + 1))['ok']);
    }

    public function test_an_update_goes_to_followers_once_after_its_grace_and_never_once_withdrawn(): void
    {
        $now = Carbon::now();
        DB::table('gates_nominee_follows')->insert([
            ['nominee_id' => self::A, 'cycle_id' => self::Y, 'email' => 'fan@example.test', 'email_hash' => VoteService::voterHash('fan@example.test'), 'created_at' => $now->toDateTimeString(), 'stopped_at' => null],
            ['nominee_id' => self::A, 'cycle_id' => self::Y, 'email' => 'gone@example.test', 'email_hash' => VoteService::voterHash('gone@example.test'), 'created_at' => $now->toDateTimeString(), 'stopped_at' => $now->toDateTimeString()],
            ['nominee_id' => self::B, 'cycle_id' => self::Y, 'email' => 'other@example.test', 'email_hash' => VoteService::voterHash('other@example.test'), 'created_at' => $now->toDateTimeString(), 'stopped_at' => null],
        ]);
        $this->free('backer@example.test', self::A);   // a backer who never asked: not mailed
        NomineeUpdates::post(self::A, 1, 'We opened the second classroom on Saturday.', $now);
        [$send, $box] = $this->outbox();

        $this->assertSame(0, NomineeUpdates::sweep(null, $now->copy()->addMinutes(5), 'https://ag.test', $send), 'sent inside its grace');
        $this->assertSame([], NomineeUpdates::shown(self::A, $now->copy()->addMinutes(5)), 'shown publicly while it can still change');
        $this->assertSame(1, NomineeUpdates::sweep(null, $now->copy()->addMinutes(20), 'https://ag.test', $send));
        $this->assertSame(['fan@example.test'], array_column($box->getArrayCopy(), 'to'));
        $this->assertSame('An update from Chiamaka Eze', $box[0]['subject']);
        $this->assertSame(0, NomineeUpdates::sweep(null, $now->copy()->addMinutes(40), 'https://ag.test', $send), 'sent twice');
        $this->assertCount(1, NomineeUpdates::shown(self::A, $now->copy()->addMinutes(20)));

        // Two ticks overlapping: the address another tick already claimed is not mailed again.
        DB::table('gates_nominee_updates')->delete();
        NomineeUpdates::post(self::A, 1, 'A second note, three days later.', $now->copy()->subDays(4));
        \AfricaGates\Support\BroadcastLog::claim('nominee-update:' . (int) DB::table('gates_nominee_updates')->max('id'), 'fan@example.test', self::A);
        $this->assertSame(0, NomineeUpdates::sweep(null, $now, 'https://ag.test', $send), 'an address another tick holds mailed again');

        // Withdrawn inside its grace: never sent, never shown.
        DB::table('gates_nominee_updates')->delete();
        NomineeUpdates::post(self::A, 1, 'Something I will think better of.', $now);
        NomineeUpdates::remove(self::A, (int) DB::table('gates_nominee_updates')->max('id'), $now->copy()->addMinutes(1));
        $this->assertSame(0, NomineeUpdates::sweep(null, $now->copy()->addMinutes(30), 'https://ag.test', $send));
        $this->assertSame([], NomineeUpdates::shown(self::A, $now->copy()->addMinutes(30)));
    }

    // ── voice ────────────────────────────────────────────────────────────────────

    public function test_only_real_audio_is_kept_and_a_new_take_replaces_the_old_file(): void
    {
        $this->assertFalse(NomineeVoice::record(self::A, 1, $this->upload("<?php echo 'hi';", 'audio/webm'))['ok'], 'a script stored because the browser said audio');
        $this->assertFalse(NomineeVoice::record(self::A, 1, $this->upload(str_repeat('x', NomineeVoice::MAX_BYTES + 1)))['ok']);
        $this->assertSame([], array_values(array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file')), 'a refused file left on disk');

        $this->assertTrue(NomineeVoice::record(self::A, 1, $this->upload($this->webm()), 999)['ok']);
        $v = NomineeVoice::forNominee(self::A);
        $this->assertSame(NomineeVoice::MAX_SECONDS, (int) $v->seconds, 'a length from the browser printed unclamped');
        $first = NomineeVoice::fileFor($v);
        $this->assertFileExists($first);

        $this->assertTrue(NomineeVoice::record(self::A, 1, $this->upload($this->webm()), 12)['ok']);
        $second = NomineeVoice::fileFor(NomineeVoice::forNominee(self::A));
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($first, 'the old take left on disk');

        $this->assertTrue(NomineeVoice::remove(self::A));
        $this->assertFileDoesNotExist($second);
        $this->assertNull(NomineeVoice::forNominee(self::A));
    }

    public function test_the_clip_is_served_with_ranges_and_never_from_the_sandbox(): void
    {
        NomineeVoice::record(self::A, 1, $this->upload($this->webm()), 8);
        $url = '/vote/voice-award/' . self::A . '-chiamaka-eze/voice';
        $whole = ChromeRender::page($url);
        $this->assertSame(200, $whole->getStatusCode());
        $this->assertSame('audio/webm', $whole->getHeaderLine('Content-Type'));
        $this->assertSame('bytes', $whole->getHeaderLine('Accept-Ranges'));
        $part = ChromeRender::page($url, [], [], 'GET', null, ['Range' => 'bytes=0-3']);
        $this->assertSame(206, $part->getStatusCode(), 'Safari\'s first request answered whole');
        $this->assertSame('bytes 0-3/' . strlen($this->webm()), $part->getHeaderLine('Content-Range'));
        $this->assertSame("\x1A\x45\xDF\xA3", (string) $part->getBody());
        $this->assertSame(416, ChromeRender::page($url, [], [], 'GET', null, ['Range' => 'bytes=99999-'])->getStatusCode());

        $this->assertSame(404, ChromeRender::page('/vote/voice-award/' . self::B . '-ibrahim-musa/voice')->getStatusCode());
        DB::table('gates_award_programmes')->where('id', self::P)->update(['is_active' => 0]);
        $this->assertSame(404, ChromeRender::page($url)->getStatusCode(), 'the sandbox\'s voice served');
    }

    // ── the pages ────────────────────────────────────────────────────────────────

    public function test_the_nominee_page_and_the_ballot_carry_it(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Chiamaka Eze', 'email' => 'chiamaka@example.test', 'status' => 'active', 'email_verified' => 1, 'points' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_nominee_details')->insert(['nominee_id' => self::A, 'user_id' => $uid, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->order('mob@example.test', 1, self::A, '-2 days');
        $this->recruit('mob@example.test', self::A, 3);
        $html = ChromeRender::html('/account/nomination/' . self::A, ['user_id' => $uid]);
        foreach (['id="nm-backing"', 'id="nm-update"', 'id="nm-voice"', 'A supporter', 'Brought 3 people', 'name="do" value="thank"', 'data-voice', 'nominee-voice.js'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle . ' missing from the nominee\'s page');
        }

        NomineeVoice::record(self::A, $uid, $this->upload($this->webm()), 9);
        DB::table('gates_nominee_updates')->insert(['nominee_id' => self::A, 'user_id' => $uid, 'body' => 'We opened the second classroom.', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $ballot = ChromeRender::html('/vote/voice-award/' . self::A . '-chiamaka-eze');
        $this->assertStringContainsString('Hear Chiamaka say thank you', $ballot);
        $this->assertMatchesRegularExpression('#<audio class="vn-voice__a" controls preload="none" src="/vote/voice-award/' . self::A . '-chiamaka-eze/voice\?v=\d+">#', $ballot);
        $this->assertStringContainsString('We opened the second classroom.', $ballot);
        // The follow form (drawn after a vote) must promise what it now sends: updates too.
        $this->assertStringContainsString('the updates they write to their supporters', ChromeRender::source('templates/pages/vote-nominee.twig'), 'the follow form still promises milestones only');
    }
}
