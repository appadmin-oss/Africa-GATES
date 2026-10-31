<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CycleLetter;
use AfricaGates\Services\NomineeVotingLetter;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The voting letter, written per edition (owner, 28 Oct 2026).
 *
 * What is held, and why each is worth a test:
 *   · the no-payment notice is on every letter, whatever the words say (plan D34): a letter
 *     like this is what a "pay to be honoured" scheme forges;
 *   · {fields} are filled per nominee, and a field the letter does not know is refused at
 *     publish (kept in a draft, so nothing typed is lost);
 *   · publishing is a new version that every letter from then reads, and restoring is a
 *     copy into the draft, never an edit of the past;
 *   · the editor page draws, and its autosave answers with the one-page fit at both sizes.
 */
final class CycleLetterTest extends TestCase
{
    private const CYCLE = 4401;

    private function view(): array
    {
        return CycleLetter::sample() + ['name' => 'Ngozi Adaeze Eze', 'reference' => 'AG/VOT/1/00001', 'date' => '28 October 2026',
            'signatory' => '', 'signatory_title' => 'Programme Director', 'steps' => []];
    }

    public function test_the_no_payment_notice_is_on_every_letter_whatever_the_words(): void
    {
        $paras = CycleLetter::render("Hello {first_name}.\n\nSee you soon.", $this->view());
        $this->assertSame(['Hello Ngozi.', CycleLetter::LOCKED, 'See you soon.'], $paras, 'before the closing line, never dropped');
        $this->assertContains(CycleLetter::LOCKED, CycleLetter::render('Only one paragraph.', $this->view()));
        $this->assertContains(CycleLetter::LOCKED, NomineeVotingLetter::body($this->view()), 'and the default carries it too');
    }

    public function test_fields_are_filled_and_an_unknown_one_is_refused_at_publish(): void
    {
        $v = $this->view();
        $this->assertSame('Dear Ngozi, voting closes ' . $v['closes'] . '.', CycleLetter::fill('Dear {first_name}, voting closes {closes}.', $v));
        $this->assertStringNotContainsString('https://', CycleLetter::fill('{vote_url}', $v + ['vote_url' => 'https://x.test/vote/a']));

        $r = CycleLetter::publish(self::CYCLE, 'Hi {nickname}', 'Words.', 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('{nickname}', implode(' ', $r['errors']));
        $d = CycleLetter::saveDraft(self::CYCLE, 'Hi {nickname}', 'Words.', 1);
        $this->assertTrue($d['ok'], 'a draft keeps what was typed, errors and all');
        $this->assertSame('Hi {nickname}', CycleLetter::editing(self::CYCLE)['headline']);
        $this->assertFalse(CycleLetter::current(self::CYCLE)['custom'], 'and nominees still get the letter in use');
    }

    public function test_publishing_makes_a_version_and_restore_copies_an_old_one_back(): void
    {
        $this->assertSame(CycleLetter::DEFAULT_HEADLINE, CycleLetter::current(self::CYCLE)['headline'], 'the default until something is published');
        $this->assertTrue(CycleLetter::publish(self::CYCLE, 'First words', 'One.', 1)['ok']);
        $this->assertTrue(CycleLetter::publish(self::CYCLE, 'Second words', 'Two.', 1)['ok']);
        $this->assertSame('Second words', CycleLetter::current(self::CYCLE)['headline']);
        $h = CycleLetter::history(self::CYCLE);
        $this->assertSame(['Second words', 'First words'], array_column($h, 'headline'));

        $this->assertTrue(CycleLetter::restore(self::CYCLE, $h[1]['id'], 1));
        $this->assertSame('First words', CycleLetter::editing(self::CYCLE)['headline'], 'back in the draft');
        $this->assertSame('Second words', CycleLetter::current(self::CYCLE)['headline'], 'until it is published again');
        $this->assertCount(2, CycleLetter::history(self::CYCLE), 'the past is not edited');
        $this->assertFalse(CycleLetter::restore(self::CYCLE + 1, $h[1]['id'], 1), 'never another edition\'s version');
    }

    public function test_the_letter_and_the_email_carry_the_editions_words(): void
    {
        CycleLetter::publish(self::CYCLE, 'Your town is behind you', "Dear friend of {category}.\n\nGo well.", 1);
        $w = \AfricaGates\Services\NomineeVotingNotice::words(self::CYCLE);
        $v = $this->view() + $w;
        $this->assertSame(['Dear friend of Community Health.', CycleLetter::LOCKED, 'Go well.'], NomineeVotingLetter::body($v));
        $this->assertStringStartsWith('%PDF', NomineeVotingLetter::render($v));
        $m = NomineeVotingLetter::measure($v + ['vote_url' => 'https://x.test/vote/a', 'rules_url' => 'x.test/integrity']);
        $this->assertLessThanOrEqual($m['limit'], $m['bottom']);
    }

    public function test_the_editor_draws_and_its_autosave_answers_with_the_fit(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Teaching Award', 'slug' => 'teaching-' . bin2hex(random_bytes(2)),
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $pid, 'year' => 2026, 'status' => 'voting', 'created_at' => date('Y-m-d H:i:s')]);
        $admin = (int) DB::table('gates_admins')->insertGetId(['name' => 'Ada', 'email' => 'ada-' . bin2hex(random_bytes(2)) . '@example.test',
            'password_hash' => 'x', 'role' => 'superadmin', 'created_at' => date('Y-m-d H:i:s')]);
        $sess = ['admin_id' => $admin, 'admin_name' => 'Ada', 'admin_role' => 'superadmin', 'admin_email' => 'ada@example.test'];

        $html = (string) ChromeRender::page('/admin/programmes/' . $pid . '/cycle/letter?cycle=' . $cid, $sess)->getBody();
        $this->assertStringContainsString('Use this letter', $html);
        $this->assertStringContainsString('Details we fill in for you', $html);
        $this->assertStringContainsString(CycleLetter::LOCKED, $html, 'the locked notice is on the sheet');
        $this->assertStringContainsString('name="headline"', $html, 'and the plain fields are there with no script');

        $res = ChromeRender::page('/admin/programmes/' . $pid . '/cycle/letter', $sess + ['csrf_token' => 'test-token'], [], 'POST',
            ['_token' => 'test-token', 'cycle' => (string) $cid, 'action' => 'draft', 'headline' => 'A new title', 'body' => 'Hello {first_name}.'],
            ['X-Requested-With' => 'fetch', 'X-CSRF-Token' => 'test-token']);
        $j = json_decode((string) $res->getBody(), true);
        $this->assertTrue($j['ok'] ?? false, (string) $res->getBody());
        $this->assertSame('draft', $j['state']);
        $this->assertArrayHasKey('A4', $j['fit']);
        $this->assertTrue($j['fit']['letter']['fits']);
        $this->assertSame('A new title', CycleLetter::editing($cid)['headline']);
    }
}
