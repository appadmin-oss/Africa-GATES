<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The column that decides whether a nominee can be invited at all.
 *
 * ── THE BUG THESE EXIST BECAUSE OF ───────────────────────────────────────────
 *
 * "Build the list" minted judges and no nominees on production, and could not be
 * reproduced anywhere else. `2026_11_01_event_invites.php` first shipped
 * `audience ENUM('principal','child','judge')` and was corrected one commit later to
 * `ENUM('nominee','judge')` — but it only rebuilds the table when the table is EMPTY,
 * so any database that had minted one invitation kept the withdrawn set forever.
 *
 * 'judge' is in both sets. 'nominee' is in neither the old one nor anything it accepts.
 * So the split fell exactly where an operator saw it, and {@see EventInvites::mint()}
 * caught the truncation and returned null, which the build screen reported as a count.
 *
 * A fresh SQLite database — dev, and this suite — builds the table from the CORRECTED
 * migration, so the constraint under test was never the constraint in production. That
 * is what made a green suite compatible with a broken build, and it is why the first
 * test here INSTALLS the production shape rather than trusting the one it is handed.
 */
final class InviteAudienceSchemaTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_11_06_invite_audience_widen.php';

    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug'       => 'gala-2026',
            'title'      => 'Africa GATES Gala 2026',
            'event_date' => '2026-12-12 18:00:00',
            'status'     => 'published',
        ]);
    }

    /**
     * The audience values the LIVE schema will actually accept.
     *
     * Read out of the database rather than out of the migration's source, because the
     * whole defect was a schema that no longer matched the migration that describes it.
     *
     * @return list<string>
     */
    private function acceptedAudiences(): array
    {
        if (self::usingMysql()) {
            $type = (string) (DB::selectOne(
                'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['gates_event_invites', 'audience']
            )->t ?? '');
        } else {
            $ddl  = (string) DB::table('sqlite_master')->where('type', 'table')
                ->where('name', 'gates_event_invites')->value('sql');
            $type = preg_match('/CHECK\s*\(\s*audience\s+IN\s*\(([^)]*)\)/i', $ddl, $m) === 1
                ? $m[1]
                : '';
        }

        preg_match_all("/'([^']*)'/", $type, $found);
        $out = array_values(array_unique($found[1] ?? []));
        sort($out);

        return $out;
    }

    /** Put the table back into the shape production is in: the withdrawn three-value set. */
    private function installWithdrawnAudienceSet(): void
    {
        if (self::usingMysql()) {
            DB::statement(
                "ALTER TABLE gates_event_invites
                   MODIFY audience ENUM('principal','child','judge') NOT NULL"
            );

            return;
        }

        $pdo = DB::connection()->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS gates_event_invites');
        $pdo->exec("CREATE TABLE gates_event_invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            cycle_id INTEGER NULL,
            audience TEXT NOT NULL CHECK(audience IN ('principal','child','judge')),
            nominee_id INTEGER NULL,
            judge_id INTEGER NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            reference TEXT NOT NULL UNIQUE,
            id_secret TEXT NOT NULL,
            discount_code TEXT NULL,
            guest_quota INTEGER NOT NULL DEFAULT 0,
            sent_at TEXT NULL,
            opened_at TEXT NULL,
            scans INTEGER NOT NULL DEFAULT 0,
            last_scan_at TEXT NULL,
            created_at TEXT NOT NULL
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_invite_person ON gates_event_invites (event_id, email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invite_event ON gates_event_invites (event_id, audience)');
    }

    /** Output captured: a migration reports its progress, and the suite fails on stray output. */
    private function runWiden(): void
    {
        ob_start();
        try {
            require self::MIGRATION;
        } finally {
            ob_end_clean();
        }
    }

    /** One row minted the way the old set allowed, so the remap has something to carry. */
    private function legacyRow(string $audience, string $email): int
    {
        return (int) DB::table('gates_event_invites')->insertGetId([
            'event_id'   => $this->eventId,
            'audience'   => $audience,
            'name'       => 'Somebody Already Written To',
            'email'      => $email,
            'reference'  => 'AGI-' . strtoupper(substr(md5($email), 0, 7)),
            'id_secret'  => str_repeat('a', 40),
            'created_at' => '2026-11-02 09:00:00',
        ]);
    }

    /**
     * The regression, end to end and through the real service.
     *
     * Asserts the FAILURE first. A test that only checks the fix would pass just as
     * happily against a table that never had the defect, which is precisely how this
     * one stayed green while production could not invite a nominee.
     */
    public function test_a_nominee_can_be_minted_on_a_database_that_kept_the_withdrawn_set(): void
    {
        $this->installWithdrawnAudienceSet();
        $this->legacyRow('judge', 'panel@example.org');

        // The log is redirected rather than silenced, so the deliberate failure below is
        // quiet in the suite AND its diagnostic is asserted: with no shell on production
        // the error log is the only place the reason survives.
        $log  = tempnam(sys_get_temp_dir(), 'ag-invite-log-');
        $prev = (string) ini_get('error_log');
        ini_set('error_log', (string) $log);

        try {
            $before = EventInvites::mint($this->eventId, InviteAudience::NOMINEE, [
                'name' => 'Ada Lovelace', 'email' => 'ada@example.org',
                'nominee_id' => 0, 'judge_id' => 0,
            ]);
        } finally {
            ini_set('error_log', $prev);
        }

        $this->assertNull($before, 'the withdrawn set has to reject a nominee, or this proves nothing');
        $this->assertNotSame('', EventInvites::lastMintError(),
            'mint() must say WHY it produced nothing — a bare null is the unanswerable report');
        $this->assertStringContainsString('ada@example.org', (string) file_get_contents((string) $log),
            'the swallowed failure has to reach the error log, naming who it was for');
        @unlink((string) $log);

        $this->runWiden();

        $after = EventInvites::mint($this->eventId, InviteAudience::NOMINEE, [
            'name' => 'Ada Lovelace', 'email' => 'ada@example.org',
            'nominee_id' => 0, 'judge_id' => 0,
        ]);

        $this->assertNotNull($after, 'a nominee still cannot be minted: ' . EventInvites::lastMintError());
        $this->assertSame(InviteAudience::NOMINEE, (string) $after->audience);
    }

    /**
     * The judge survives untouched and the withdrawn values land where they belong.
     *
     * 'principal' and 'child' were both nominees under the taxonomy that was withdrawn,
     * so this is undoing a rewrite rather than performing one — and the rows are the one
     * record of who has already been written to, which is why nothing is dropped.
     */
    public function test_the_withdrawn_values_become_nominees_and_judges_are_left_alone(): void
    {
        $this->installWithdrawnAudienceSet();
        $principal = $this->legacyRow('principal', 'head@example.org');
        $child     = $this->legacyRow('child', 'pupil@example.org');
        $judge     = $this->legacyRow('judge', 'panel@example.org');

        $this->runWiden();

        $audience = static fn (int $id): string => (string) DB::table('gates_event_invites')
            ->where('id', $id)->value('audience');

        $this->assertSame(InviteAudience::NOMINEE, $audience($principal));
        $this->assertSame(InviteAudience::NOMINEE, $audience($child));
        $this->assertSame(InviteAudience::JUDGE, $audience($judge));
        $this->assertSame(3, (int) DB::table('gates_event_invites')->count(),
            'the rows are the record of who was written to — the repair must not drop one');
    }

    /**
     * The drift guard.
     *
     * `InviteAudience::all()` is the list the platform mints from; the column is the list
     * the database will take. Nothing kept them in step, and one commit moved one of them.
     * Any future value added to the class without a migration fails here rather than in
     * production, where it presents as "that audience is missing from the build".
     */
    public function test_the_schema_accepts_exactly_the_audiences_the_platform_mints(): void
    {
        $expected = InviteAudience::all();
        sort($expected);

        $this->assertSame($expected, $this->acceptedAudiences(),
            'the audience column and InviteAudience::all() disagree — a value the platform '
            . 'mints that the column rejects is minted for nobody');
    }

    /**
     * The screen has to name it, because nothing else on that screen can.
     *
     * Every other way this build comes back empty — no linked award, no cycle, an
     * unpublished shortlist — renders as the same table of zeroes, and this one renders
     * as a SUCCESS: a true count of judges. An operator with no shell cannot get from
     * that to a column definition, so the readiness panel has to make the trip for them.
     */
    public function test_readiness_names_the_column_and_offers_the_repair(): void
    {
        $this->installWithdrawnAudienceSet();

        $blockers = EventInvites::readiness($this->eventId);
        $audience = array_values(array_filter(
            $blockers,
            static fn (array $b): bool => ($b['rerun'] ?? '') === EventInvites::REPAIR_AUDIENCE
        ));

        $this->assertCount(1, $audience, 'the withdrawn column has to be reported, not walked past');
        $this->assertTrue($audience[0]['hard'], 'nothing can be invited past this — it stops the run');
        $this->assertStringContainsString('nominee', strtolower($audience[0]['what']));

        $this->runWiden();

        $after = array_filter(
            EventInvites::readiness($this->eventId),
            static fn (array $b): bool => ($b['rerun'] ?? '') === EventInvites::REPAIR_AUDIENCE
        );
        $this->assertSame([], $after, 'a repaired column must stop being reported, or the button never ends');
    }

    /** A correct database must not be told to repair itself. */
    public function test_readiness_is_silent_about_a_column_that_is_already_right(): void
    {
        $this->assertTrue(EventInvites::audienceAcceptsNominee());

        $audience = array_filter(
            EventInvites::readiness($this->eventId),
            static fn (array $b): bool => ($b['rerun'] ?? '') === EventInvites::REPAIR_AUDIENCE
        );

        $this->assertSame([], $audience);
    }

    /** Running it twice must not undo it — the runner re-applies on any database it is unsure of. */
    public function test_the_widen_is_idempotent(): void
    {
        $this->installWithdrawnAudienceSet();
        $this->legacyRow('principal', 'head@example.org');

        $this->runWiden();
        $this->runWiden();

        $expected = InviteAudience::all();
        sort($expected);

        $this->assertSame($expected, $this->acceptedAudiences());
        $this->assertSame(1, (int) DB::table('gates_event_invites')->count());
    }
}
