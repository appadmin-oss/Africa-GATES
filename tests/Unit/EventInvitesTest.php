<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\EventInvites;
use AfricaGates\Services\EventScanPass;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InvitePass;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The guests of honour a ceremony invites, and the pass each of them carries.
 *
 * Every property here is one an operator cannot check by eye before the letters go out:
 * that the quota in the letter is the quota the code allows, that a person nobody can
 * write to is REPORTED rather than skipped, that a second run does not mint a second
 * reference for somebody who already has one in writing, and that a photograph of a
 * pass stops working.
 */
final class EventInvitesTest extends TestCase
{
    private int $eventId = 0;
    private int $cycleId = 0;
    private int $catId   = 0;
    private int $programmeId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'judging', 'edition_label' => 'Rehearsal',
        ]);
        $this->catId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'excellence', 'title' => 'Academic Excellence', 'sort_order' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'Africa GATES Gala 2026',
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published',
        ]);
        // Through the join table: one ceremony honours several awards.
        EventInvites::setProgrammes($this->eventId, [$pid]);
        $this->programmeId = $pid;
    }

    /** A shortlisted nominee reachable at one address. */
    private function nominee(string $name, string $email = '', ?int $profileId = null): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->catId, 'name' => $name, 'status' => 'approved',
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
            'profile_id' => $profileId,
        ]);
        if ($email !== '') {
            DB::table('gates_nominations')->insert([
                'cycle_id' => $this->cycleId, 'category_id' => $this->catId, 'status' => 'approved',
                'nominee_name' => $name, 'nominee_email' => $email,
                // nominator_name is NOT NULL with no default. Checked against the schema,
                // not inferred from another fixture — see the note in CLAUDE.md.
                'nominator_name' => 'A Nominator', 'nominator_email' => 'nom@example.com',
                'created_at' => '2026-02-01 10:00:00',
            ]);
        }
        return $id;
    }

    private function tier(int $naira, string $name): int
    {
        return (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => \AfricaGates\Support\Slug::make($name, 40),
            'name' => $name, 'price_naira' => $naira, 'is_active' => 1, 'sort_order' => 1,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_the_ceremony_is_found_from_its_programme(): void
    {
        $e = EventInvites::eventForProgramme($this->programmeId);

        $this->assertNotNull($e, 'the programme has no ceremony linked to it');
        $this->assertSame('gala-2026', (string) $e->slug);
    }

    /**
     * The minimum support an invitation asks for is the cheapest tier on sale, not a
     * figure typed into the copy — an organiser moves prices up to the week of the event.
     */
    public function test_the_minimum_support_is_the_cheapest_tier_on_sale(): void
    {
        $this->tier(20000, 'Table of Ten');
        $this->tier(5000,  'Supporter');
        // A free tier is the ordinary case — press, sponsors, students — and ordering by
        // price alone would make THIS the "minimum support" an invitation asks for.
        $this->tier(0,     'Press');

        $t = EventInvites::lowestTier($this->eventId);

        $this->assertNotNull($t);
        $this->assertSame('Supporter', (string) $t->name,
            'the ask must point at the cheapest tier somebody can actually buy');
        $this->assertSame(5000, (int) $t->price_naira);
    }

    public function test_the_plan_lists_the_shortlist_and_names_who_cannot_be_reached(): void
    {
        $ada  = $this->nominee('Ada Obi',    'ada@example.com');
        $nobody = $this->nominee('Silent Sam');                  // no address anywhere
        $this->publishShortlist($this->cycleId, $this->catId, [$ada, $nobody]);

        $plan = EventInvites::plan($this->eventId)[InviteAudience::NOMINEE];

        $this->assertSame(['Ada Obi'], array_column($plan['ready'], 'name'));
        $this->assertSame(['Silent Sam'], array_column($plan['unreachable'], 'name'));
        $this->assertStringContainsString('No address', $plan['unreachable'][0]['why'],
            'an unreachable invitee must say WHY, not just be missing');
    }

    /**
     * Two nominees approved under one name is ambiguous, and picking one would send a
     * person somebody else's name, category and personal reference.
     */
    public function test_an_ambiguous_name_is_reported_rather_than_guessed(): void
    {
        $one = $this->nominee('Chinelo Eze', 'chinelo.a@example.com');
        $this->nominee('Chinelo Eze', 'chinelo.b@example.com');   // same name, second address
        $this->publishShortlist($this->cycleId, $this->catId, [$one]);

        $plan = EventInvites::plan($this->eventId)[InviteAudience::NOMINEE];

        $this->assertSame([], $plan['ready'], 'an ambiguous address must never be picked');
        $this->assertStringContainsString('More than one', $plan['unreachable'][0]['why']);
    }

    /** A nominee is not invited to a ceremony they were not shortlisted for. */
    public function test_an_unshortlisted_nominee_is_not_invited(): void
    {
        $this->nominee('Not Shortlisted', 'no@example.com');

        $plan = EventInvites::plan($this->eventId)[InviteAudience::NOMINEE];

        $this->assertSame([], $plan['ready']);
        $this->assertSame([], $plan['unreachable']);
    }

    // ── why there is nobody, when there is nobody ───────────────────────────

    /**
     * Five ways to have an empty list, and they all used to look the same.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE BUG THIS FILE GAINED THESE TESTS FOR
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `shortlisted()` walks the linked programmes and `continue`s past a programme with
     * no cycle and past a cycle with no PUBLISHED shortlist. `judges()` returns nothing
     * the moment no programme is linked. The picker that links them is hidden outright
     * before the migration runs. And the invitations page itself was gated on
     * `gates_site_events.programme_id` — the single-programme column the multi-programme
     * migration folds INTO the join table, so on a migrated install it is null and the
     * page said "no award programme is linked to this event" for every event, including
     * the ones that were.
     *
     * Every one of those renders as a table of zeroes, and there is no shell on this host
     * to go and find out which. So `readiness()` names the actual one, and these hold it.
     */
    public function test_an_event_with_no_awards_says_so_and_says_where_to_fix_it(): void
    {
        EventInvites::setProgrammes($this->eventId, []);

        $b = EventInvites::readiness($this->eventId);

        $this->assertCount(1, $b, 'one blocker at a time, in the order they are fixed');
        $this->assertStringContainsString('not linked to any award', $b[0]['what']);
        $this->assertTrue($b[0]['hard'], 'nothing can be invited without an award');
        $this->assertSame('/admin/events/' . $this->eventId, $b[0]['href'],
            'the fix has to name WHERE — that is the part an operator cannot guess');
    }

    /**
     * A drawn-but-unpublished shortlist is the expensive one: everything looks done, and
     * the invitation run is silently drawing from nothing.
     */
    public function test_an_unpublished_shortlist_is_named_as_the_reason(): void
    {
        $ada = $this->nominee('Ada Obi', 'ada@example.com');
        $sl  = $this->publishShortlist($this->cycleId, $this->catId, [$ada]);
        DB::table('gates_shortlists')->where('id', $sl)->update(['status' => 'draft']);

        $b = EventInvites::readiness($this->eventId);
        $said = implode(' ', array_column($b, 'what'));

        $this->assertStringContainsString('PUBLISHED shortlist', $said);
        $this->assertStringContainsString('Incredible Principal Awards', $said,
            'on a four-award night it has to say WHICH award is not ready');
        // Not a hard stop: the other awards on the night should still be invited.
        foreach ($b as $one) {
            if (str_contains($one['what'], 'shortlist')) $this->assertFalse($one['hard']);
        }
    }

    /** A programme with no cycle at all — the state a brand new award is in. */
    public function test_a_programme_with_no_cycle_is_named(): void
    {
        $fresh = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'newcomers', 'title' => 'Newcomer Awards', 'is_active' => 1,
        ]);
        EventInvites::setProgrammes($this->eventId, [$fresh]);

        $said = implode(' ', array_column(EventInvites::readiness($this->eventId), 'what'));
        $this->assertStringContainsString('Newcomer Awards has no award cycle', $said);
    }

    /** And when everything is in place it says nothing at all. */
    public function test_a_ready_run_reports_no_blockers(): void
    {
        $ada = $this->nominee('Ada Obi', 'ada@example.com');
        $this->publishShortlist($this->cycleId, $this->catId, [$ada]);
        DB::table('gates_judges')->insert([
            'name' => 'Judge Ada', 'email' => 'judge@example.com', 'is_active' => 1,
        ]);

        $this->assertSame([], EventInvites::readiness($this->eventId),
            'a warning on a screen where nothing is wrong is the reason nobody reads them');
    }

    /**
     * The page must not be gated on the legacy single-programme column.
     *
     * `gates_site_events.programme_id` is what the join table replaced. Reading it here
     * is how a fully-configured multi-award ceremony came to report that it had no award
     * linked at all — and it is the specific line the operator hit.
     */
    public function test_readiness_does_not_depend_on_the_retired_programme_column(): void
    {
        $ada = $this->nominee('Ada Obi', 'ada@example.com');
        $this->publishShortlist($this->cycleId, $this->catId, [$ada]);
        DB::table('gates_judges')->insert([
            'name' => 'Judge Ada', 'email' => 'judge@example.com', 'is_active' => 1,
        ]);
        // `gates_site_events.programme_id` is not merely empty — it is in no schema file
        // and no migration adds it. It was only ever read, never created, which is why the
        // invitations page's `{% if not event.programme_id %}` gate was true for EVERY
        // event from the day it shipped: the page has never once rendered the run.
        $this->assertFalse(\Illuminate\Database\Capsule\Manager::schema()
            ->hasColumn('gates_site_events', 'programme_id'),
            'if this column is ever added, the retired gate becomes plausible again');

        $this->assertSame([], EventInvites::readiness($this->eventId));
        $this->assertNotSame([], EventInvites::plan($this->eventId)[InviteAudience::NOMINEE]['ready'],
            'the shortlist is reachable through the join table alone');

        // And no screen may reintroduce the dependency.
        //
        // Matched with the COMMENTS STRIPPED. Both files name the column in a note saying
        // why they no longer read it, and a scanner that cannot tell a comment from code
        // is a scanner that punishes the explanation — the same mistake, and the same fix,
        // as the settings-credential scan in OperationalCredentialsTest.
        foreach ([
            'src/Admin/Controllers/InvitesController.php' => '/->\s*programme_id/',
            'templates/admin/events/invites.twig'         => '/event\.programme_id/',
        ] as $rel => $access) {
            $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
            $code = str_ends_with($rel, '.twig')
                ? (string) preg_replace('/\{#[\s\S]*?#\}/', '', $src)
                : self::phpWithoutComments($src);

            $this->assertDoesNotMatchRegularExpression($access, $code,
                $rel . ' reads the retired single-programme column, which is in no schema');
        }
    }

    /** Source with every comment token removed, so a scan cannot match an explanation. */
    private static function phpWithoutComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    /**
     * The page itself renders the run, and not the stuck warning.
     *
     * The unit tests above hold `readiness()`. This holds the SCREEN, because the fault
     * the operator hit was not in the resolver — it was a template gate on a column that
     * does not exist, which no test of the resolver could ever have caught.
     */
    public function test_the_invitations_page_shows_the_run_for_a_configured_ceremony(): void
    {
        $ada = $this->nominee('Ada Obi', 'ada@example.com');
        $this->publishShortlist($this->cycleId, $this->catId, [$ada]);
        DB::table('gates_judges')->insert([
            'name' => 'Judge Ada', 'email' => 'judge@example.com', 'is_active' => 1,
        ]);

        $_SESSION['admin_id'] = $this->admin();
        $html = (string) $this->get('/admin/events/' . $this->eventId . '/invites')->getBody();

        $this->assertStringNotContainsString('No award programme is linked to this event', $html,
            'the page said this for every ceremony, configured or not, since the day it shipped');
        $this->assertStringContainsString('Who is invited', $html);
        $this->assertStringContainsString('Incredible Principal Awards', $html,
            'the awards are named, from the join table');
        $this->assertStringContainsString('Build the invitation list', $html);
    }

    /** And when it is NOT configured, it says which of the five things is wrong. */
    public function test_the_invitations_page_names_the_blocker_instead_of_a_table_of_zeroes(): void
    {
        EventInvites::setProgrammes($this->eventId, []);

        $_SESSION['admin_id'] = $this->admin();
        $html = (string) $this->get('/admin/events/' . $this->eventId . '/invites')->getBody();

        $this->assertStringContainsString('not linked to any award programme', $html);
        $this->assertStringContainsString('/admin/events/' . $this->eventId, $html,
            'the notice has to carry the way to fix it');
        $this->assertStringNotContainsString('Build the invitation list', $html,
            'nothing to build, so nothing to press');
    }

    /**
     * "Run the migration" is the wrong answer when they already have.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE DEAD END
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The setup ledger is written AFTER a step is included, so a step that THREW is not
     * recorded and /__setup/migrate retries it. That is the ordinary case, and the fix is
     * to finish the run — 151 steps, four per request, chained by a meta refresh, so a
     * closed tab stops it part way and nothing anywhere says so.
     *
     * The other case is a step recorded as applied whose table is not there. The migrate
     * endpoint skips it forever, and with no shell on this host there is no way back at
     * all. The notice said "run the migration" for BOTH — which is the one instruction
     * that provably cannot work in the second.
     */
    public function test_the_notice_tells_an_unfinished_run_from_a_dead_end(): void
    {
        DB::schema()->drop('gates_event_programmes');

        // 1 · Not yet applied: setup is simply unfinished.
        DB::table('gates_migrations')->where('migration', EventInvites::MIGRATION)->delete();
        $b = EventInvites::readiness($this->eventId)[0];
        $this->assertStringContainsString('/__setup/migrate', $b['fix']);
        $this->assertStringContainsString('LEAVE THE TAB OPEN', $b['fix'],
            'the run applies four steps a request and refreshes itself — a closed tab is '
            . 'the commonest way it stops half way');
        $this->assertSame('', $b['rerun'], 'the ordinary route still works here');

        // 2 · Recorded as applied, table absent: the migrate endpoint will skip it.
        DB::table('gates_migrations')->updateOrInsert(
            ['migration' => EventInvites::MIGRATION], ['applied_at' => '2026-01-01 00:00:00']
        );
        $b = EventInvites::readiness($this->eventId)[0];
        $this->assertStringContainsString('recorded as already applied', $b['fix']);
        $this->assertStringNotContainsString('/__setup/migrate', $b['fix'],
            'it will skip the step — sending an operator there again is the bug');
        $this->assertSame(EventInvites::MIGRATION, $b['rerun'],
            'the only way out has to be offered');
    }

    /** And the way out actually creates the table. */
    public function test_the_repair_applies_the_step_the_ledger_would_skip(): void
    {
        DB::schema()->drop('gates_event_programmes');
        // The harness has already applied every migration, so the ledger entry is there —
        // which is exactly the state this exists for.
        DB::table('gates_migrations')->updateOrInsert(
            ['migration' => EventInvites::MIGRATION], ['applied_at' => '2026-01-01 00:00:00']
        );

        $r = \AfricaGates\Services\MigrationRunner::rerun(EventInvites::MIGRATION);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertTrue(DB::schema()->hasTable('gates_event_programmes'),
            'the whole point is the table, not the return value');
    }

    /**
     * "Nothing to migrate" over a table that is not there has to be describable.
     *
     * The old status readout was a count and a pending list, and in this state the
     * pending list is empty — so it looked exactly like a healthy install, which is
     * where the operator and I both got stuck. `known` says how many steps the deploy
     * actually carries and `absent` names any the ledger claims but the files do not
     * back, which is the incomplete-upload case.
     */
    public function test_the_setup_status_can_describe_a_deploy_missing_its_files(): void
    {
        DB::table('gates_migrations')->insert([
            'migration' => '2099_01_01_not_in_this_deploy.php', 'applied_at' => '2026-01-01 00:00:00',
        ]);

        $st = \AfricaGates\Services\MigrationRunner::status();

        $this->assertGreaterThan(0, $st['known'], 'the runner can see no steps at all');
        $this->assertContains('2099_01_01_not_in_this_deploy.php', $st['absent'],
            'a step the ledger claims and the deploy does not carry is invisible');
        // And a step that IS present is not reported as absent.
        $this->assertNotContains(EventInvites::MIGRATION, $st['absent']);
    }

    /** A name that is not a step is refused rather than included. */
    public function test_the_repair_will_not_include_an_arbitrary_path(): void
    {
        foreach (['../../public/index.php', '/etc/passwd', 'nope.php', ''] as $bad) {
            $r = \AfricaGates\Services\MigrationRunner::rerun($bad);
            $this->assertFalse($r['ok'], $bad . ' was accepted');
            $this->assertStringContainsString('no setup step', $r['message']);
        }
    }

    /** A signed-in admin, for the two page tests above. */
    private function admin(): int
    {
        return (int) DB::table('gates_admins')->insertGetId([
            'email' => 'ops-' . bin2hex(random_bytes(4)) . '@example.test',
            'password_hash' => password_hash('x', PASSWORD_BCRYPT),
            'name' => 'Ops', 'role' => 'superadmin', 'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * The picker is hidden before the migration, and the REASON is not.
     *
     * Hiding a field whose column does not exist is this form's convention and it is
     * right — a form that posts an unknown column 500s. But this field is the only way to
     * link an award to a ceremony, and gone with no explanation it does not read as "not
     * migrated yet", it reads as "that feature does not exist". Which is what it read as.
     */
    public function test_the_events_form_explains_the_missing_link_rather_than_hiding_it(): void
    {
        // ── WHY THIS RENDERS THE PAGE INSTEAD OF READING THE TEMPLATE ────────
        //
        // It used to assert that form.twig contained the string "/__setup/migrate" inside
        // a `{% if not programme_link_ready %}` branch. That passed for as long as the
        // notice existed — including while the notice was a HARD-CODED sentence that
        // ignored the diagnosis entirely and told an operator to run the one endpoint
        // that provably cannot help in the dead-end case. The screen was useless and the
        // test was green, because the test was checking that some words were in a file.
        //
        // Rendering is the only thing that can tell those apart.
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        DB::schema()->drop('gates_event_programmes');
        DB::table('gates_migrations')->updateOrInsert(
            ['migration' => EventInvites::MIGRATION], ['applied_at' => '2026-01-01 00:00:00']
        );

        $html = $this->renderEventForm();

        $this->assertStringContainsString('Awards cannot be linked to this event yet', $html);
        $this->assertStringContainsString('recorded as already applied', $html,
            'the screen is still repeating the generic instruction rather than the diagnosis');
        $this->assertStringContainsString('Create the awards link now', $html,
            'the only way out of this state is not offered where the operator is standing');
        $this->assertStringContainsString('back=form', $html,
            'the repair would land them on a different screen from the one they pressed it on');
    }

    /** And when setup is simply unfinished, it says THAT — with no repair button. */
    public function test_an_unfinished_run_is_not_offered_a_repair_it_does_not_need(): void
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        DB::schema()->drop('gates_event_programmes');
        DB::table('gates_migrations')->where('migration', EventInvites::MIGRATION)->delete();

        $html = $this->renderEventForm();

        $this->assertStringContainsString('/__setup/migrate', $html,
            'there is no shell on this host — the notice has to name the route that fixes it');
        $this->assertStringContainsString('LEAVE THE TAB OPEN', $html);
        $this->assertStringNotContainsString('Create the awards link now', $html,
            'a repair offered where the ordinary route works invites somebody to reach for '
            . 'the sharp tool first');
    }

    /** And with the table present, the tick boxes are there and the notice is not. */
    public function test_a_migrated_deployment_gets_the_control_and_no_notice(): void
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $html = $this->renderEventForm();

        $this->assertStringContainsString('Awards presented at this event', $html);
        $this->assertStringNotContainsString('Awards cannot be linked', $html);
    }

    /** The event form, rendered through the real controller. */
    private function renderEventForm(): string
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');

        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/admin/events/' . $this->eventId);

        return (string) $b->build()
            ->get(\AfricaGates\Admin\Controllers\EventsController::class)
            ->form($req, new \Slim\Psr7\Response(), ['id' => $this->eventId])
            ->getBody();
    }

    /**
     * A mailer that records instead of sending. The same shape InviteMailerTest uses —
     * duplicated rather than shared because the two files seed different worlds, and a
     * base class holding one anonymous class for both would be more machinery than the
     * eleven lines it saves.
     */
    private function recorderMailer(): \AfricaGates\Services\OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25,
                          'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X'])
            extends \AfricaGates\Services\OtpService {
            public function smtpConfigured(): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                return ['success' => true];
            }
        };
    }

    // ── the three things you do BEFORE the run ──────────────────────────────

    /**
     * Everything an operator needs to check the run is reachable before it exists.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THREE MECHANISMS WITH NO ROUTE IN
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The invitation run is irreversible and personal, and none of the three ways to look
     * at it before pressing send could be reached:
     *
     *   · the LETTER — InviteLetter::render() was called only from inside the attachment
     *     builder, so the one document this run puts in front of a nominee could not be
     *     opened by the person sending it;
     *   · a TEST SEND — there was no route at all, so the only way to see what lands in an
     *     inbox was to send to a real nominee;
     *   · the PICTURE — the field feeding it is on the event form as `type="url"`, and
     *     InviteMailer::cover() refuses anything beginning `http` because an attachment
     *     must be a file on this disk. The only value the form accepted was the one value
     *     the mailer discarded.
     *
     * The preview existed but needed a real row, so it was unreachable on a fresh ceremony
     * too — and before building the list is exactly when you want to look.
     */
    public function test_the_preview_and_the_letter_work_before_anything_is_minted(): void
    {
        $_SESSION['admin_id'] = $this->admin();

        foreach (['preview', 'letter.pdf'] as $what) {
            $r = $this->get('/admin/events/' . $this->eventId . '/invites/sample/' . $what);
            $this->assertSame(200, $r->getStatusCode(),
                $what . ' cannot be opened on a ceremony whose list has not been built');
        }
    }

    /** The letter route returns a real PDF, inline, and not a redirect to a flash. */
    public function test_the_letter_route_returns_the_pdf_that_is_attached(): void
    {
        $_SESSION['admin_id'] = $this->admin();

        $r = $this->get('/admin/events/' . $this->eventId . '/invites/sample/letter.pdf');
        $body = (string) $r->getBody();

        $this->assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $body, 'that is not a PDF');
        // `inline`, because this is a thing to read now rather than a file to keep.
        $this->assertStringContainsString('inline;', $r->getHeaderLine('Content-Disposition'));
    }

    /** And the sample is never written — a press of Preview must not mint anything. */
    public function test_the_sample_is_not_saved_and_does_not_burn_a_reference(): void
    {
        $before = DB::table('gates_event_invites')->count();
        $a = EventInvites::sample($this->eventId);
        $b = EventInvites::sample($this->eventId);

        $this->assertSame($before, DB::table('gates_event_invites')->count(),
            'previewing minted a row');
        $this->assertSame($a->reference, $b->reference,
            'the sample draws a fresh reference each time, out of the space real '
            . 'invitations use');
        // The secret IS fresh, so a previewed pass rotates exactly as a real one does.
        $this->assertNotSame($a->id_secret, $b->id_secret);
    }

    /**
     * A test send changes nothing: no row is marked, no log is written, and pressing it
     * twice works.
     *
     * `send()` refuses an address the run has already written to, records the attempt and
     * stamps `sent_at`. A test that did any of those would either refuse the second press
     * or take a real invitee out of the run — the operator checks their own letter and a
     * nominee never receives one.
     */
    public function test_a_test_send_records_nothing_against_the_run(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);
        $this->assertNotNull($inv);
        $event = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $logBefore = DB::table('gates_broadcast_log')->count();

        // Twice, to the same address — the second press must not be refused.
        for ($i = 0; $i < 2; $i++) {
            \AfricaGates\Services\InviteMailer::sendTest($inv, $event, 'ops@example.test',
                $this->recorderMailer());
        }

        $this->assertNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('sent_at'),
            'a test marked a real invitee as already invited');
        $this->assertSame($logBefore, DB::table('gates_broadcast_log')->count(),
            'a test wrote to the campaign log, so the run now thinks it has sent one');
    }

    /** A junk address is refused, and an opted-out one is refused for the right reason. */
    public function test_a_test_send_still_honours_an_opt_out(): void
    {
        $inv   = EventInvites::sample($this->eventId);
        $event = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $bad = \AfricaGates\Services\InviteMailer::sendTest($inv, $event, 'not-an-address',
            $this->recorderMailer());
        $this->assertFalse($bad['ok']);

        \AfricaGates\Services\EmailOptOut::record('gone@example.test', 'test');
        $out = \AfricaGates\Services\InviteMailer::sendTest($inv, $event, 'gone@example.test',
            $this->recorderMailer());
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('opted out', $out['error'],
            'an operator whose own address is suppressed needs to be told that, not left '
            . 'wondering why nothing arrived');
    }

    /**
     * The picture can be attached at all.
     *
     * `cover()` refuses `http…` on purpose — an attachment is a file on this disk. The
     * only input that fed it was url-typed, so the two halves could never agree. The
     * event form takes text now, and the invitations screen uploads.
     */
    public function test_the_cover_field_can_hold_the_local_path_the_mailer_needs(): void
    {
        $form = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*type="url"[^>]*name="cover_image"/', $form,
            'a url-typed input refuses the local path that is the only form the mailer '
            . 'can attach');

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/events/invites.twig');
        $this->assertStringContainsString('enctype="multipart/form-data"', $tpl,
            'without enctype the file never reaches the server and the page reports success');
        $this->assertStringContainsString('name="cover"', $tpl);
    }

    // ── minting ─────────────────────────────────────────────────────────────

    public function test_minting_stores_the_quota_and_mints_a_code_that_allows_exactly_it(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $this->assertNotNull($inv);
        $this->assertSame(25, (int) $inv->guest_quota);

        $code = DB::table('gates_event_codes')
            ->where('event_id', $this->eventId)->where('code', $inv->reference)->first();

        $this->assertNotNull($code, 'the invite promised a discount and no code was minted');
        $this->assertSame('percent', (string) $code->kind);
        $this->assertSame(10, (int) $code->amount);
        $this->assertSame(25, (int) $code->max_uses,
            'the letter promises 25 guests — the code must allow 25, not 20');
        $this->assertSame(1, (int) $code->max_per_email,
            'the quota counts people, so one guest must not spend two of it');
    }

    public function test_the_quota_and_the_discount_are_admin_configurable(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'invite_quota_judge', 'value' => '14'],
            ['key_name' => 'invite_discount_percent', 'value' => '15'],
        ]);

        $inv = EventInvites::mint($this->eventId, InviteAudience::JUDGE,
            ['name' => 'Hon. Judge', 'email' => 'judge@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $this->assertSame(14, (int) $inv->guest_quota);
        $this->assertSame(14, (int) DB::table('gates_event_codes')->where('code', $inv->reference)->value('max_uses'));
        $this->assertSame(15, (int) DB::table('gates_event_codes')->where('code', $inv->reference)->value('amount'));
    }

    /**
     * Two audiences, not three. It began as principal / child / judge, which was a taxonomy
     * invented out of two example programmes — a nominee is a nominee whichever award they
     * are shortlisted for.
     */
    public function test_there_are_two_audiences_with_the_briefed_quotas(): void
    {
        $this->assertSame(['nominee', 'judge'], InviteAudience::all());
        $this->assertSame(25, InviteAudience::spec(InviteAudience::NOMINEE)['quota']);
        $this->assertSame(10, InviteAudience::spec(InviteAudience::JUDGE)['quota']);
    }

    /**
     * The sentence naming WHY the hall is being filled is editable, and that is the point:
     * an Incredible Principal Award and a Carol Award honour completely different things,
     * so a sentence written to cover both honours neither.
     */
    public function test_the_reason_is_editable_per_audience(): void
    {
        $default = InviteAudience::spec(InviteAudience::NOMINEE)['witness'];

        DB::table('gates_settings')->insert([
            'key_name' => 'invite_witness_nominee',
            'value'    => 'to witness a decade of choral discipline',
        ]);

        $this->assertSame('to witness a decade of choral discipline',
            InviteAudience::spec(InviteAudience::NOMINEE)['witness']);
        $this->assertNotSame($default, InviteAudience::spec(InviteAudience::NOMINEE)['witness']);
    }

    /** A gala honours several awards, and every shortlist among them is invited. */
    public function test_every_linked_programme_contributes_its_shortlist(): void
    {
        $ada = $this->nominee('Ada Obi', 'ada@example.com');
        $this->publishShortlist($this->cycleId, $this->catId, [$ada]);

        // A second award on the same night, with its own cycle, category and shortlist.
        $pid2 = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'carol', 'title' => 'Carol Awards', 'is_active' => 1,
        ]);
        $cy2 = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid2, 'year' => 2026, 'status' => 'judging', 'edition_label' => 'C',
        ]);
        $cat2 = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cy2, 'slug' => 'choir', 'title' => 'Choir of the Year', 'sort_order' => 1,
        ]);
        $eze = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat2, 'name' => 'Eze Choir', 'status' => 'approved',
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        DB::table('gates_nominations')->insert([
            'cycle_id' => $cy2, 'category_id' => $cat2, 'status' => 'approved',
            'nominee_name' => 'Eze Choir', 'nominee_email' => 'eze@example.com',
            'nominator_name' => 'A Nominator', 'nominator_email' => 'nom@example.com',
            'created_at' => '2026-02-01 10:00:00',
        ]);
        $this->publishShortlist($cy2, $cat2, [$eze]);

        EventInvites::setProgrammes($this->eventId, [$this->programmeId, $pid2]);

        $ready = EventInvites::plan($this->eventId)[InviteAudience::NOMINEE]['ready'];
        $names = array_column($ready, 'name');

        $this->assertContains('Ada Obi', $names);
        $this->assertContains('Eze Choir', $names, 'the second award on the night was not invited');

        // The award is named beside the category: on a multi-programme night "Choir of the
        // Year" alone does not say which award it belongs to.
        $byName = array_column($ready, null, 'name');
        $this->assertStringContainsString('Carol Awards', $byName['Eze Choir']['category']);
    }

    /** Unticking every award leaves nobody to invite, and says so rather than guessing. */
    public function test_an_event_with_no_awards_invites_nobody(): void
    {
        $ada = $this->nominee('Ada Obi', 'ada@example.com');
        $this->publishShortlist($this->cycleId, $this->catId, [$ada]);
        EventInvites::setProgrammes($this->eventId, []);

        $plan = EventInvites::plan($this->eventId);

        $this->assertSame([], $plan[InviteAudience::NOMINEE]['ready']);
        $this->assertSame([], $plan[InviteAudience::JUDGE]['ready']);
    }

    /**
     * A second run must not mint a second reference. The first one is already in a letter
     * somebody has read, and its code is the one their guests are spending.
     */
    public function test_minting_twice_returns_the_same_invitation(): void
    {
        $who = ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0];

        $first  = EventInvites::mint($this->eventId, InviteAudience::NOMINEE, $who);
        $second = EventInvites::mint($this->eventId, InviteAudience::NOMINEE, $who);

        $this->assertSame((string) $first->reference, (string) $second->reference);
        $this->assertSame(1, (int) DB::table('gates_event_invites')->where('event_id', $this->eventId)->count());
        $this->assertSame(1, (int) DB::table('gates_event_codes')->where('event_id', $this->eventId)->count());
    }

    public function test_the_sandbox_judge_is_not_invited_to_a_real_ceremony(): void
    {
        DB::table('gates_judges')->insert([
            ['name' => 'Real Panellist', 'email' => 'real@example.com', 'is_active' => 1],
            ['name' => 'DEMO — Judge',   'email' => 'judge@' . DemoSeeder::MAIL_DOMAIN, 'is_active' => 1],
        ]);

        $names = array_column(EventInvites::plan($this->eventId)[InviteAudience::JUDGE]['ready'], 'name');

        $this->assertContains('Real Panellist', $names);
        $this->assertNotContains('DEMO — Judge', $names);
    }

    // ── the rotating pass ───────────────────────────────────────────────────

    public function test_the_pass_rotates_and_a_photograph_of_it_stops_working(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $now  = InvitePass::window();
        $live = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, $now);

        $ok = InvitePass::verify($live);
        $this->assertTrue($ok['ok'], $ok['reason']);
        $this->assertSame((int) $inv->id, (int) $ok['invite']->id);

        // The window before is still accepted — a steward lining up a camera takes longer
        // than one window, and a code that dies mid-scan reads as a broken pass.
        $prev = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, $now - 1);
        $this->assertTrue(InvitePass::verify($prev)['ok']);

        // Two windows back is a photograph.
        $old = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, $now - 2);
        $stale = InvitePass::verify($old);
        $this->assertFalse($stale['ok']);
        $this->assertStringContainsString('expired', $stale['reason']);
    }

    /** A future window must never verify, however skewed the phone's clock. */
    public function test_a_future_code_is_refused(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $ahead = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, InvitePass::window() + 4);

        $this->assertFalse(InvitePass::verify($ahead)['ok']);
    }

    /**
     * The reference is printed in the letter, shown in the email, displayed on the ID AND
     * handed to twenty-five guests as their discount code. So holding it must not be
     * enough to mint a pass — that is the entire reason for the per-invite secret.
     */
    public function test_holding_the_reference_is_not_enough_to_forge_a_pass(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $forged = InvitePass::code((string) $inv->reference, 'a-guess-at-the-secret');

        $this->assertFalse(InvitePass::verify($forged)['ok']);
        $this->assertNotSame((string) $inv->id_secret, 'a-guess-at-the-secret');
    }

    public function test_a_scan_is_counted_once_at_the_door_not_per_refresh(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        InvitePass::code((string) $inv->reference, (string) $inv->id_secret);   // a refresh
        $this->assertSame(0, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'));

        InvitePass::touch((int) $inv->id);
        $this->assertSame(1, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'));
    }

    // ════════════════════════════════════════════════════════════════════════
    //  THE MOBILE ID, through the real routing stack
    // ════════════════════════════════════════════════════════════════════════

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        return $app;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    private function invited(): object
    {
        $this->tier(5000, 'Supporter');

        return EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);
    }

    public function test_the_id_page_shows_the_pass_the_ask_and_the_evening(): void
    {
        $inv  = $this->invited();
        $res  = $this->get('/honour/' . $inv->reference);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString((string) $inv->reference, $html, 'the reference is read aloud at the door');
        $this->assertStringContainsString('25', $html, 'the quota promised must be on the pass');
        $this->assertStringContainsString('10% off', $html);
        $this->assertStringContainsString('Supporter', $html, 'the ask names the cheapest paid tier');
        $this->assertStringContainsString('Africa GATES Gala 2026', $html);
    }

    /**
     * The code on screen is valid for one window. A cached copy of this page is an
     * expired pass rendered as though it were live, and a search engine holding it is a
     * directory of who was shortlisted before the ceremony announced it.
     */
    public function test_the_id_page_is_never_cached_and_never_indexed(): void
    {
        $res = $this->get('/honour/' . $this->invited()->reference);

        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
    }

    /** A scannable symbol before any script runs — the door has the worst signal. */
    public function test_the_qr_endpoint_returns_a_symbol(): void
    {
        $inv = $this->invited();
        $res = $this->get('/honour/' . $inv->reference . '/qr.svg');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('image/svg+xml', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<svg', (string) $res->getBody());
    }

    /** The countdown is asked for, because a phone that slept has a confidently wrong clock. */
    public function test_the_tick_endpoint_reports_the_window_from_the_server(): void
    {
        $res = $this->get('/honour/' . $this->invited()->reference . '/tick');
        $d   = json_decode((string) $res->getBody(), true);

        $this->assertSame(InvitePass::STEP_SECONDS, $d['step']);
        $this->assertGreaterThan(0, $d['seconds_left']);
        $this->assertLessThanOrEqual(InvitePass::STEP_SECONDS, $d['seconds_left']);
    }

    public function test_an_unknown_reference_is_a_404_on_all_three_paths(): void
    {
        foreach (['', '/qr.svg', '/tick'] as $tail) {
            $this->assertSame(404, $this->get('/honour/AGI-NOTAREAL' . $tail)->getStatusCode(),
                'AGI-NOTAREAL' . $tail . ' should not resolve');
        }
    }

    /** First open is the only signal an operator has that an invitation landed. */
    public function test_opening_the_id_is_recorded_once(): void
    {
        $inv = $this->invited();
        $this->assertNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('opened_at'));

        $this->get('/honour/' . $inv->reference);
        $first = DB::table('gates_event_invites')->where('id', $inv->id)->value('opened_at');
        $this->assertNotNull($first);

        $this->get('/honour/' . $inv->reference);
        $this->assertSame($first, DB::table('gates_event_invites')->where('id', $inv->id)->value('opened_at'),
            'opened_at records the FIRST open, not the most recent one');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  THE DOOR
    // ════════════════════════════════════════════════════════════════════════

    private function doorToken(): string
    {
        return (string) EventScanPass::issue(
            $this->eventId, '2099-01-01 00:00:00', null, 'Main gate', 1
        );
    }

    /** @return array<string,mixed> */
    private function scan(string $token, string $code): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/door/' . $token . '/check')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        $req->getBody()->write((string) json_encode(['code' => $code]));
        $req->getBody()->rewind();

        $app = $this->app();
        $app->addBodyParsingMiddleware();

        return (array) json_decode((string) $app->handle($req)->getBody(), true);
    }

    public function test_the_door_admits_a_guest_of_honour_and_says_so(): void
    {
        $inv = $this->invited();
        $v   = $this->scan($this->doorToken(),
            InvitePass::code((string) $inv->reference, (string) $inv->id_secret));

        $this->assertSame('admit', $v['verdict'] ?? '', json_encode($v));
        $this->assertTrue($v['honour'] ?? false, 'the page celebrates on this flag');
        $this->assertSame('Guest of honour', $v['title'] ?? '');
        $this->assertSame('Ada Obi', $v['name'] ?? '');
        $this->assertSame(1, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'));
    }

    /**
     * A nominee who steps out to take a call and comes back is the ordinary case. Turning
     * them away from their own ceremony over a re-scan is a worse failure than a second
     * admission, so it is reported rather than refused.
     */
    public function test_a_second_scan_welcomes_them_back_rather_than_refusing(): void
    {
        $inv   = $this->invited();
        $token = $this->doorToken();
        $code  = InvitePass::code((string) $inv->reference, (string) $inv->id_secret);

        $this->scan($token, $code);
        $v = $this->scan($token, $code);

        $this->assertSame('admit', $v['verdict'] ?? '');
        $this->assertSame('Welcome back', $v['title'] ?? '');
        $this->assertStringContainsString('already admitted 1 time', $v['detail'] ?? '');
    }

    /** An invitation to another evening reads exactly like one that does not exist. */
    public function test_an_invitation_to_another_event_is_refused_without_saying_it_exists(): void
    {
        $inv   = $this->invited();
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'other-night', 'title' => 'Another Night',
            'event_date' => '2026-11-11 18:00:00', 'status' => 'published',
        ]);
        $token = (string) EventScanPass::issue($other, '2099-01-01 00:00:00', null, 'Other gate', 1);

        $v = $this->scan($token, InvitePass::code((string) $inv->reference, (string) $inv->id_secret));

        $this->assertSame('refuse', $v['verdict'] ?? '');
        $this->assertSame('No invitation here has that reference.', $v['detail'] ?? '',
            'a door that distinguishes the two is an oracle for which references are real');
    }

    public function test_a_forged_pass_is_refused_at_the_door(): void
    {
        $inv = $this->invited();

        $v = $this->scan($this->doorToken(), InvitePass::code((string) $inv->reference, 'guessed'));

        $this->assertSame('refuse', $v['verdict'] ?? '');
        $this->assertFalse($v['honour'] ?? true);
        $this->assertSame(0, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'),
            'a refused scan must not count as an admission');
    }

    /**
     * The regression that matters: adding a second kind of pass must not disturb the
     * ticket door. A ticket code has no dots, which is what routes the two apart.
     */
    public function test_a_ticket_code_still_reaches_the_ticket_door(): void
    {
        $v = $this->scan($this->doorToken(), 'NOTATICKETCODE99');

        $this->assertSame('refuse', $v['verdict'] ?? '');
        $this->assertSame('Not a ticket for this event', $v['title'] ?? '',
            'a dotless code must go to EventTicketService, not to the invitation check');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  THE PASS, AS A DESIGNED OBJECT
    // ════════════════════════════════════════════════════════════════════════

    private function pass(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/honour.twig');
    }

    /**
     * The first version was three near-identical rounded cards stacked down a dark page.
     * Everything had the same weight, so nothing was the pass. The structure IS the design:
     * a stub carrying the evening, a perforation, and a paper plate holding the code.
     */
    public function test_the_pass_is_built_like_a_pass(): void
    {
        $css = $this->pass();

        $this->assertStringContainsString('hn__stub', $css, 'no stub — the evening has nowhere to sit');
        $this->assertStringContainsString('hn__perf', $css, 'no perforation — the shape reads as a panel');
        $this->assertStringContainsString('hn__plate', $css, 'no plate — the code has no home');

        // The plate is PAPER on an ink page: at a door it is the only thing that matters
        // and the eye has to land on it with no help.
        $this->assertMatchesRegularExpression('~\.hn__plate\{[^}]*background:var\(--paper\)~', $css);
    }

    /**
     * The countdown is a depleting ring, not a spinner. A spinner says "something is
     * happening"; the remaining life of the code on screen is a fact a guest and a steward
     * both need, and it is the one piece of motion on the page that earns itself.
     */
    public function test_the_countdown_is_informative_motion_not_decoration(): void
    {
        $css = $this->pass();

        $this->assertStringContainsString('stroke-dashoffset', $css, 'the ring does not deplete');
        $this->assertStringNotContainsString('hn-spin', $css, 'a spinner is decoration, not a countdown');
        $this->assertStringContainsString('prefers-reduced-motion', $css,
            'a full-page animation with no reduced-motion path');
    }

    /** The two things that actually happen at a door: no signal, and a stale code. */
    public function test_the_unhappy_states_exist(): void
    {
        $css = $this->pass();

        $this->assertStringContainsString('hn--offline', $css, 'no offline state — at a venue, on venue wifi');
        $this->assertStringContainsString('hn--stale', $css, 'no state for a code that failed to refresh');
        $this->assertStringContainsString("classList.add('hn--offline')", $css,
            'the offline class is styled but never applied');
    }

    /** Hierarchy is subtraction: two actions on the surface, and the rest on the event page. */
    public function test_the_pass_carries_two_actions_and_no_more(): void
    {
        preg_match_all('~<a class="hn__(?:cta|alt)"~', $this->pass(), $m);

        $this->assertCount(2, $m[0],
            'the pass grew a third action — the schedule and the map belong on the event page');
    }

    /** Touch targets. A pass is used one-handed, in a queue, in the dark. */
    public function test_every_action_clears_the_touch_floor(): void
    {
        $css = $this->pass();

        foreach (['.hn__cta', '.hn__alt'] as $sel) {
            $this->assertMatchesRegularExpression(
                '~' . preg_quote($sel, '~') . '\{[^}]*(min-height:44px|line-height:44px)~',
                $css,
                $sel . ' is under the 44px touch floor'
            );
        }
    }

    /**
     * The site already loads Playfair Display, DM Sans and JetBrains Mono and exposes them
     * as tokens. A pass that ships a fourth face is a pass that does not belong to the site
     * it is part of.
     */
    public function test_the_pass_uses_the_sites_own_type_tokens(): void
    {
        $css = $this->pass();

        foreach (['--ag-font-display', '--ag-font-mono'] as $token) {
            $this->assertStringContainsString($token, $css, $token . ' is not used');
        }
        $this->assertStringNotContainsString('fonts.googleapis.com', $css,
            'the layout already loads the faces — a second link is a second render-blocking request');
    }

}
