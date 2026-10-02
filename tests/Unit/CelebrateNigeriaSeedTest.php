<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Services\PromoService;
use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\ProgrammeHost;
use AfricaGates\Support\SeedReview;
use AfricaGates\Support\SeedRunner;
use DI\ContainerBuilder;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE OCTOBER RELEASE'S ACCEPTANCE, AS A TEST RATHER THAN A CHECKLIST.
 *
 * `handoff-oct-2026/README.md` lists what must be true of the Celebrate Nigeria
 * challenge. Every one of those lines is a case here, asserted against the row the REAL
 * SEED writes rather than against a fixture shaped like it — because a fixture that
 * agrees with the test proves the test agrees with itself.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE AWARD IS BUILT HERE AND THE CHALLENGE IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff's rule is "create the edition through admin, never inside the seed", so
 * the seed refuses without one. This fixture plays the operator — it creates the
 * Alimosho Awards and opens an edition — and then lets the seed do its own job. The
 * refusal itself is a case, because it is the behaviour that stops a script inventing an
 * award and taking real nominations into something nobody announced.
 */
final class CelebrateNigeriaSeedTest extends TestCase
{
    private const SEED = '2026_10_01_celebrate_nigeria';
    private const SLUG = 'celebrate-nigeria-2026';

    private int $programme = 0;
    private int $cycle     = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The operator's half: the award and an edition open for nominations.
        $this->programme = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'alimosho-awards', 'title' => 'Alimosho Awards', 'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programme, 'year' => 2026, 'status' => 'nominations',
            'nominations_open'  => '2026-09-01 00:00:00',
            'nominations_close' => '2026-11-30 23:59:59',
        ]);

        foreach (['choral' => 'Choral', 'business' => 'Business', 'impact' => 'Impact'] as $slug => $title) {
            DB::table('gates_award_categories')->insert([
                'cycle_id' => $this->cycle, 'slug' => $slug, 'title' => $title,
            ]);
        }

        // The runner records applied seeds in `gates_settings`, and the suite is one
        // process against one database — so a case that ran earlier would make a later
        // one skip, and the skip looks exactly like a pass.
        //
        // By KEY, not by `LIKE 'seed\\_ran\\_%'`, which is what this was: SQLite has no
        // backslash escape, so on the suite's own database that pattern matched nothing and
        // the purge it describes never happened. The cases passed because each one rolls
        // back, not because this line worked — CLAUDE.md's LIKE trap, inside a cleanup.
        DB::table('gates_settings')
            ->whereIn('key_name', ['seed_ran_' . self::SEED, 'seed_last_' . self::SEED])
            ->delete();
        SeedReview::reset(self::SEED);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // What the seed writes
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_seed_writes_the_challenge_the_handoff_specifies(): void
    {
        $this->assertSame('done', SeedRunner::run(self::SEED)['status']);

        $c = CS::bySlug(self::SLUG);
        $this->assertNotNull($c, 'the seed did not create the challenge');

        // The fixed table from the handoff, value for value.
        $this->assertSame('Celebrate Nigeria', $c->title);
        $this->assertSame('Independence Day challenge', $c->kicker);
        $this->assertSame(E::ACTION_NOMINATE, $c->action);
        $this->assertSame(10, (int) $c->target);
        $this->assertSame(E::MODE_FIRST, $c->mode);
        $this->assertSame(11, (int) $c->cap);
        $this->assertSame(E::PRIZE_CASH_EACH, $c->prize_type);
        $this->assertSame(6000, (int) $c->prize_amount, 'the prize is whole naira, not kobo');
        $this->assertSame('NGN', $c->prize_currency);
        $this->assertSame('Africa/Lagos', $c->timezone);

        // The window, in UTC, which is 1 Oct 00:00 and 15 Oct 23:59:59 in Lagos. WAT
        // has no daylight saving, so this is exact rather than right for half the year.
        $this->assertSame('2026-09-30 23:00:00', (string) $c->starts_at);
        $this->assertSame('2026-10-15 22:59:59', (string) $c->ends_at);

        // The art, with the alt text the handoff specifies — and a `T` would be a
        // datetime MySQL refuses, so the separator is checked too.
        $this->assertSame('/assets/img/challenges/alimosho-celebrates-nigeria.png', $c->art_url);
        $this->assertSame('Àlímọ̀ṣọ́ celebrates Nigeria', $c->art_alt);
        $this->assertStringNotContainsString('T', (string) $c->starts_at);
    }

    public function test_the_art_and_the_host_logo_are_actually_in_the_tree(): void
    {
        SeedRunner::run(self::SEED);

        $root = dirname(__DIR__, 2) . '/public';

        // A row pointing at a file nobody shipped renders a broken image on the hero of
        // the page the whole release is about, and nothing in PHP ever notices.
        foreach ([
            '/assets/img/challenges/alimosho-celebrates-nigeria.png',
            '/assets/img/challenges/alimosho-celebrates-nigeria.webp',
            '/assets/img/hosts/okun-alimosho-logo.png',
            '/assets/img/hosts/okun-alimosho-logo.webp',
        ] as $path) {
            $this->assertFileExists($root . $path, "the seed points at a file that is not in the tree: {$path}");
        }

        // Max 1200px wide, transparency kept. Both were instructions, and a flattened
        // PNG looks identical in a viewer and shows a white box over the art ground.
        [$w, $h] = getimagesize($root . '/assets/img/challenges/alimosho-celebrates-nigeria.png');
        $this->assertLessThanOrEqual(1200, $w);

        $im = imagecreatefrompng($root . '/assets/img/challenges/alimosho-celebrates-nigeria.png');
        $alpha = false;
        for ($x = 0; $x < $w && !$alpha; $x += 7) {
            for ($y = 0; $y < $h; $y += 7) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) { $alpha = true; break; }
            }
        }
        $this->assertTrue($alpha, 'the challenge art lost its transparency');
    }

    public function test_it_is_scoped_to_the_open_edition_and_names_its_host(): void
    {
        SeedRunner::run(self::SEED);

        $c = CS::bySlug(self::SLUG);
        $this->assertSame([$this->cycle], CS::scopeIds($c, E::SCOPE_CYCLE),
            'the challenge is not scoped to the edition that was open');

        $host = ProgrammeHost::forCycle($this->cycle);
        $this->assertSame('Okun Alimosho', $host['name'] ?? null);
        $this->assertSame('/assets/img/hosts/okun-alimosho-logo.png', $host['logo'] ?? null);
    }

    public function test_a_host_an_operator_has_already_named_is_not_overwritten(): void
    {
        DB::table('gates_award_programmes')->where('id', $this->programme)
            ->update(['host_name' => 'Okun Alimosho Development Council']);

        SeedRunner::run(self::SEED);

        $this->assertSame('Okun Alimosho Development Council',
            ProgrammeHost::forProgramme($this->programme)['name'] ?? null,
            'the seed corrected an operator — a settings screen that does not work');
    }

    public function test_the_banners_are_on_the_five_placements_and_no_other_award(): void
    {
        SeedRunner::run(self::SEED);

        foreach (['nominate', 'home', 'vote'] as $placement) {
            $this->assertNotSame([], PromoService::forPlacement($placement, false),
                "no banner on {$placement}");
        }

        $this->assertNotSame([], PromoService::forPlacement('account', true),
            'no banner on the account page for a signed-in member');
        $this->assertSame([], PromoService::forPlacement('account', false),
            'the account banner is shown to somebody signed out');

        // The award placement is SCOPED. This is the half the handoff states as a
        // negative — "and on no other award" — and a negative nobody asserts is the one
        // that ships: a challenge in naira advertised on a Kenyan award's page.
        $this->assertNotSame([], PromoService::forPlacement('award', false, 5, $this->programme),
            'no banner on the Alimosho Awards page');

        $other = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'kcea-' . bin2hex(random_bytes(3)), 'title' => 'Another Award', 'is_active' => 1,
        ]);
        $this->assertSame([], PromoService::forPlacement('award', false, 5, $other),
            'the banner is on an award the challenge does not count inside');

        // And with no award named at all it refuses rather than showing everywhere.
        $this->assertSame([], PromoService::forPlacement('award', false),
            'the award placement without a programme is a wildcard');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Idempotency, and the refusal
    // ══════════════════════════════════════════════════════════════════════════

    public function test_running_it_again_changes_no_row_and_resets_nothing(): void
    {
        SeedRunner::run(self::SEED);

        $id = (int) CS::bySlug(self::SLUG)->id;

        // Somebody is already competing, and the live state is theirs. They join
        // BEFORE the challenge fills — joining a full one is refused, which is the
        // behaviour the case below this one is about.
        $entry = CS::join($id, $this->user('Entrant'), '+2348031110001');
        $this->assertTrue($entry['ok'], 'fixture failed to join: ' . $entry['code']);
        DB::table('gates_challenges')->where('id', $id)->update(['status' => E::ST_FULL]);

        $before = $this->counts();

        // Forced, because the runner would otherwise skip it — and skipping proves
        // nothing about whether a second RUN is safe.
        $this->assertSame('done', SeedRunner::run(self::SEED, true)['status']);
        $this->assertSame('done', SeedRunner::run(self::SEED, true)['status']);

        $this->assertSame($before, $this->counts(), 'a second run changed the row counts');

        $c = CS::bySlug(self::SLUG);
        $this->assertSame($id, (int) $c->id, 'the challenge was recreated under a new id');
        $this->assertSame(E::ST_FULL, $c->status, 'the seed reopened a challenge that had filled');
        $this->assertSame(11, (int) $c->cap, 'the seed rewrote the cap people are competing under');
        $this->assertNotNull(CS::entryFor($id, (int) $entry['entry']->user_id),
            'a second run destroyed an entry');
    }

    public function test_it_refuses_rather_than_inventing_an_award(): void
    {
        DB::table('gates_award_cycles')->where('id', $this->cycle)->delete();
        DB::table('gates_award_programmes')->where('id', $this->programme)->delete();

        $r = SeedRunner::run(self::SEED);

        $this->assertSame('waiting', $r['status'], 'the seed did not refuse without the award');
        // The sentence is the operator's fix, so it has to name what was looked for —
        // "not found" alone sends somebody to check an award they can see in the list.
        $this->assertStringContainsString('"alimosho-awards"', $r['note']);
        $this->assertStringContainsString('"Alimosho Awards"', $r['note']);
        $this->assertNull(CS::bySlug(self::SLUG), 'it created the challenge anyway');

        // And "waiting" must NOT be recorded, or the retry never happens.
        $this->assertFalse(SeedRunner::done(self::SEED),
            'a seed that could not run was marked as applied — it will never be retried');
    }

    public function test_an_edition_closed_for_nominations_is_not_scoped(): void
    {
        DB::table('gates_award_cycles')->where('id', $this->cycle)
            ->update(['nominations_close' => '2026-01-01 00:00:00']);

        $r = SeedRunner::run(self::SEED);
        $this->assertSame('waiting', $r['status'],
            'the seed scoped a challenge to an edition that is not taking nominations');

        // The commonest cause is a window a day off, which "none open" does not show.
        $this->assertStringContainsString('2026 (nominations 2026-09-01 00:00:00 → 2026-01-01 00:00:00)',
            $r['note'], 'the refusal does not show the editions it found and their windows');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The operator can see a waiting seed, and run it
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * `status()` had no caller and answered "pending" for anything not done, so the
     * reason a seed was waiting lived in a migration's output and nowhere else. On a
     * host with no shell that is nowhere — the challenge simply did not appear.
     */
    public function test_a_waiting_seed_keeps_its_reason_where_the_admin_reads_it(): void
    {
        // Approved: the operator has checked its details, so what it is waiting on now is
        // the award, and the reason it gives is about the award.
        SeedReview::approve(self::SEED);
        DB::table('gates_award_programmes')->where('id', $this->programme)->update(['slug' => 'alimosho']);
        DB::table('gates_award_programmes')->where('id', $this->programme)->update(['title' => 'Alimosho Awards 2026']);

        SeedRunner::run(self::SEED);
        $s = SeedRunner::status()[self::SEED] ?? null;

        $this->assertNotNull($s);
        $this->assertSame('waiting', $s['status'], 'the waiting outcome was not kept');
        $this->assertStringContainsString('"alimosho-awards"', $s['note'],
            'the reason is not what the screen shows');
        $this->assertNotNull($s['at'], 'no time — an operator cannot tell a stale reason from a fresh one');
        $this->assertTrue(SeedRunner::anyOutstanding());

        // Fix it, run again: the stored reason is replaced, not left beside a success.
        DB::table('gates_award_programmes')->where('id', $this->programme)
            ->update(['slug' => 'alimosho-awards']);
        $this->assertSame('done', SeedRunner::run(self::SEED)['status']);
        $this->assertSame('done', SeedRunner::status()[self::SEED]['status']);
    }

    public function test_the_admin_can_run_a_waiting_seed_and_cannot_name_a_path(): void
    {
        $ctl = self::controller();

        // A name is checked against the directory, never used as a path.
        $_SESSION = [];
        $ctl->runSeed(self::post(['seed' => '../../config/container']), new Response());
        $this->assertSame('No such seed.', $_SESSION['flash_error'] ?? null);

        // Not checked yet: the button sends the operator to the details instead of
        // publishing figures nobody has looked at.
        $_SESSION = [];
        $res = $ctl->runSeed(self::post(['seed' => self::SEED]), new Response());
        $this->assertSame('/admin/challenges/seeds/' . self::SEED, $res->getHeaderLine('Location'));
        $this->assertNull(CS::bySlug(self::SLUG), 'an unchecked seed was added from a bare button');

        SeedReview::approve(self::SEED);
        $_SESSION = [];
        $res = $ctl->runSeed(self::post(['seed' => self::SEED]), new Response());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith('Applied', (string) ($_SESSION['flash_ok'] ?? ''));
        $this->assertNotNull(CS::bySlug(self::SLUG), 'the button reported success and wrote nothing');
    }

    public function test_the_list_draws_the_waiting_seed_and_its_reason(): void
    {
        SeedReview::approve(self::SEED);
        DB::table('gates_award_programmes')->where('id', $this->programme)
            ->update(['slug' => 'x', 'title' => 'X']);
        SeedRunner::run(self::SEED);

        $html = (string) self::controller()->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/challenges'),
            new Response()
        )->getBody();

        $this->assertStringContainsString('Waiting to be added', $html);
        $this->assertStringContainsString('action="/admin/challenges/seeds/run"', $html);
        $this->assertStringContainsString('&quot;alimosho-awards&quot;', $html,
            'the reason is not on the screen, which is the whole of what this panel is for');
    }

    /** The real controller, out of the real container — with the app's Twig globals and filters. */
    private static function controller(): \AfricaGates\Admin\Controllers\ChallengesController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Admin\Controllers\ChallengesController::class);
    }

    private static function post(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/challenges/seeds/run')->withParsedBody($body);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The five behaviours the handoff names, against the seeded challenge
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_eleventh_qualifier_wins_and_the_twelfth_is_told_it_is_full(): void
    {
        SeedRunner::run(self::SEED);
        $id = (int) CS::bySlug(self::SLUG)->id;

        $places = [];
        for ($i = 1; $i <= 11; $i++) {
            $e = $this->entrant($id, "Winner {$i}", $i);
            $this->tenVerified($e);
            CS::recount($e);
            $places[] = (int) CS::entry($e)->standing;
        }

        $this->assertSame(range(1, 11), $places, 'the eleven places were not 1 to 11');
        $this->assertSame(E::ST_FULL, CS::find($id)->status, 'the challenge did not fill at eleven');

        // The twelfth. They may still JOIN a full challenge in some designs; here the
        // join itself is refused, and the code is the thing the page turns into a
        // sentence — so it is the code that is asserted, not the English.
        $r = CS::join($id, $this->user('Twelfth'), '+2348039990012');
        $this->assertFalse($r['ok'], 'a twelfth entrant was let in after the cap was spent');
        $this->assertSame('FULL', $r['code']);
    }

    public function test_a_self_nomination_does_not_count(): void
    {
        SeedRunner::run(self::SEED);
        $id = (int) CS::bySlug(self::SLUG)->id;

        $userId = $this->user('Self Nominator', '+2348037770001');
        $r = CS::join($id, $userId, '+2348037770001');
        $this->assertTrue($r['ok']);
        $entry = (int) $r['entry']->id;

        // Nine other people, and then themselves — by phone and by email, because a
        // nominee is identified either way and excluding only one of them is a tenth of
        // the prize for a rule that reads as enforced.
        for ($i = 1; $i <= 8; $i++) {
            $this->nomination($entry, "Other {$i}", CS::identityHash('+23480366600' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), null));
        }

        $u = DB::table('gates_users')->where('id', $userId)->first(['phone', 'email']);
        $this->nomination($entry, 'Myself by phone', CS::identityHash((string) $u->phone, null));
        $this->nomination($entry, 'Myself by email', CS::identityHash(null, (string) $u->email));

        CS::recount($entry);

        $this->assertSame(8, (int) CS::entry($entry)->verified,
            'a self-nomination counted towards the prize');
    }

    public function test_the_same_nominee_twice_counts_once(): void
    {
        SeedRunner::run(self::SEED);
        $id = (int) CS::bySlug(self::SLUG)->id;
        $entry = $this->entrant($id, 'Doubler', 20);

        $hash = CS::identityHash('+2348031230001', null);
        $this->nomination($entry, 'Same person, spelling one', $hash);
        $this->nomination($entry, 'Same person, spelling two', $hash);

        CS::recount($entry);

        $this->assertSame(1, (int) CS::entry($entry)->verified);
    }

    public function test_a_nomination_for_another_award_does_not_count(): void
    {
        SeedRunner::run(self::SEED);
        $id = (int) CS::bySlug(self::SLUG)->id;

        $userId = $this->user('Cross Award', '+2348035550001');
        $this->assertTrue(CS::join($id, $userId, '+2348035550001')['ok']);

        // A second award, open for nominations, that this challenge is NOT scoped to.
        $otherProg = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'other-' . bin2hex(random_bytes(3)), 'title' => 'Other Award', 'is_active' => 1,
        ]);
        $otherCycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $otherProg, 'year' => 2026, 'status' => 'nominations',
        ]);

        // This is the WRITER's decision, not the counter's: a nomination into another
        // award is never stamped with the entry, so it can never be counted. Asserting
        // it here rather than on the count is the difference between a rule and a
        // coincidence — the counter sees only rows that were already attached.
        $onOther = $this->bareNomination($otherCycle);
        $this->assertNull(CS::attachNomination($onOther, $userId, $otherCycle),
            'a nomination for another award was attached to this challenge');
        $this->assertNull(DB::table('gates_nominations')->where('id', $onOther)->value('challenge_entry_id'));

        $onThis = $this->bareNomination($this->cycle);
        $this->assertNotNull(CS::attachNomination($onThis, $userId, $this->cycle),
            'a nomination for the scoped award was NOT attached — the counter is unreachable');
    }

    public function test_after_the_window_closes_in_lagos_it_reads_ended(): void
    {
        SeedRunner::run(self::SEED);
        $id = (int) CS::bySlug(self::SLUG)->id;

        $copy = \AfricaGates\Services\ChallengeCopy::for(
            (array) CS::find($id), ['now' => '2026-10-15 22:59:00']);
        $this->assertNotSame('ended', $copy['state'],
            'it read as over one minute before 23:59:59 in Lagos');

        $copy = \AfricaGates\Services\ChallengeCopy::for(
            (array) CS::find($id), ['now' => '2026-10-15 23:00:00']);
        $this->assertSame('ended', $copy['state'],
            'it was still open after 15 Oct 23:59:59 WAT');
    }

    public function test_a_winner_is_published_as_a_first_name_and_an_initial(): void
    {
        SeedRunner::run(self::SEED);
        $id = (int) CS::bySlug(self::SLUG)->id;

        $e = $this->entrant($id, 'Chioma Obi-Nwosu', 40);
        $this->tenVerified($e);
        CS::recount($e);

        $winners = CS::winners($id);
        $this->assertNotSame([], $winners);

        $names = array_map(static fn($w) => $w['name'], $winners);
        $this->assertContains('Chioma O.', $names);

        foreach ($winners as $w) {
            $this->assertStringNotContainsString('Obi-Nwosu', json_encode($w),
                'a surname reached the public winners list');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** @return array<string,int> */
    private function counts(): array
    {
        return [
            'challenges' => DB::table('gates_challenges')->count(),
            'scopes'     => DB::table('gates_challenge_scopes')->count(),
            'promos'     => DB::table('gates_promos')->count(),
            'entries'    => DB::table('gates_challenge_entries')->count(),
            'events'     => DB::table('gates_challenge_events')->count(),
        ];
    }

    private function entrant(int $challengeId, string $name, int $n): int
    {
        $phone = '+23480' . str_pad((string) (41000000 + $n), 8, '0', STR_PAD_LEFT);
        $r = CS::join($challengeId, $this->user($name, $phone), $phone);
        $this->assertTrue($r['ok'], 'fixture failed to join: ' . $r['code']);

        return (int) $r['entry']->id;
    }

    private function tenVerified(int $entry): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->nomination($entry, "Nominee {$entry}-{$i}",
                hash('sha256', "n{$entry}-{$i}"));
        }
    }

    private function nomination(int $entry, string $who, string $hash): void
    {
        DB::table('gates_nominations')->insert([
            'cycle_id' => $this->cycle, 'nominee_name' => $who,
            'nominator_name' => 'Entrant', 'nominator_email' => 'entrant@example.test',
            'status' => 'verified', 'challenge_entry_id' => $entry,
            'nominee_confirmed_at' => '2026-10-02 09:00:00',
            'nominee_identity_hash' => $hash,
        ]);
    }

    private function bareNomination(int $cycleId): int
    {
        return (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => $cycleId, 'nominee_name' => 'Unattached ' . bin2hex(random_bytes(3)),
            'nominator_name' => 'Entrant', 'nominator_email' => 'entrant@example.test',
            'status' => 'verified', 'nominee_confirmed_at' => '2026-10-02 09:00:00',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The strip: "Counts toward Celebrate Nigeria · n/10" (README Part A) and
    // "Part of Celebrate Nigeria · n of 11 prizes left" (the prompt's scoped strip)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_strip_says_part_of_to_everyone_and_counts_toward_to_an_entrant(): void
    {
        SeedRunner::run(self::SEED);
        $c = CS::bySlug(self::SLUG);

        $anon = CS::stripFor($this->programme);
        $this->assertNotNull($anon);
        $this->assertFalse($anon['joined']);
        $this->assertSame('11 of 11 prizes left', $anon['line']);

        $u = $this->user('Strip Entrant', '+2348035550001');
        CS::join((int) $c->id, $u, '+2348035550001');
        $mine = CS::stripFor($this->programme, $u);
        $this->assertTrue($mine['joined']);
        $this->assertSame('0/10', $mine['line'], 'the joined line is VERIFIED of target');

        // Another award: no strip at all.
        $other = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'other', 'title' => 'Other', 'is_active' => 1]);
        $this->assertNull(CS::stripFor($other, $u));
    }

    /** The README's MUST, on the real nomination form — and only for somebody who joined. */
    public function test_the_alimosho_form_shows_counts_toward_for_a_joined_member_only(): void
    {
        SeedRunner::run(self::SEED);
        $form = function (int $user): string {
            $_SESSION = $user ? ['user_id' => $user] : [];
            $b = new ContainerBuilder();
            $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
            return (string) $b->build()->get(\AfricaGates\Controllers\NominationController::class)->award(
                (new ServerRequestFactory())->createServerRequest('GET', '/nominate/alimosho-awards'),
                new Response(), ['slug' => 'alimosho-awards'])->getBody();
        };

        $this->assertStringNotContainsString('class="ch-strip', $form(0),
            'a strip mid-form for somebody who has not joined is an advertisement');

        $u = $this->user('Form Entrant', '+2348035550002');
        CS::join((int) CS::bySlug(self::SLUG)->id, $u, '+2348035550002');
        $h = $form($u);
        $this->assertMatchesRegularExpression(
            '~class="ch-strip"[^>]*>\s*<span class="ch-strip__t">Counts toward Celebrate Nigeria</span>\s*<span class="ch-strip__m"><span aria-hidden="true">·</span> <span class="ch-strip__n">0/10</span></span>~',
            $h);
        $_SESSION = [];
    }

    private function user(string $name, ?string $phone = null): int
    {
        return (int) DB::table('gates_users')->insertGetId([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'phone' => $phone ?? ('+23480' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT)),
            'status' => 'active',
        ]);
    }
}
