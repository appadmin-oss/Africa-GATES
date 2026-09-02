<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\AuditTargets;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The audit log, asked questions.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_audit_log` is written from 124 places. It had two readers and neither was a
 * reader in the sense that matters: the dashboard's last twelve rows, and the generic
 * table dump where the admin is an integer, the target is an integer, `meta` is not a
 * list column and `ip_hash` is stripped by DataRegistry's `_hash` rule.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE PROPERTIES THIS FILE EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. **The LIKE escape works on both drivers.** MySQL's default LIKE escape is a
 *    backslash; SQLite has none at all. Several areas in this log contain an underscore
 *    — `stand_call`, `vendor_policy` — so a backslash-escaped filter matches on
 *    production and silently returns zero rows in dev and in this suite. That is this
 *    codebase's most expensive bug shape pointing the wrong way round, and the only
 *    thing that catches it is asserting the row COUNT rather than that a query ran.
 *
 * 2. **The aliases fold.** `gates_site_events` is written as `site_event` by one
 *    controller and `event` by another, so one event's history sits in two buckets. A
 *    reader that returned only the half matching the word you happened to type would be
 *    a worse answer than none, because it looks like a complete answer.
 *
 * 3. **The dashboard and the screen cannot disagree.** `recent()` is `search()` with a
 *    limit. If it grew its own query the two would drift, and nobody would know which
 *    was right.
 */
final class AuditLogReaderTest extends TestCase
{
    private AuditService $audit;
    private int $adaeze = 0;
    private int $tunde  = 0;
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = new AuditService();
        DB::table('gates_audit_log')->delete();

        $this->adaeze = (int) DB::table('gates_admins')->insertGetId([
            'name' => 'Adaeze Umeh', 'email' => 'adaeze@example.test',
            'password_hash' => 'x', 'role' => 'admin', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->tunde = (int) DB::table('gates_admins')->insertGetId([
            'name' => 'Tunde Bello', 'email' => 'tunde@example.test',
            'password_hash' => 'x', 'role' => 'superadmin', 'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'lagos-gala', 'title' => 'Lagos Gala 2026',
            'event_date' => '2026-03-01 18:00:00', 'status' => 'published',
        ]);
    }

    private function row(array $o = []): int
    {
        return (int) DB::table('gates_audit_log')->insertGetId($o + [
            'admin_id' => $this->adaeze, 'action' => 'settings.update',
            'target_type' => null, 'target_id' => null, 'meta' => null,
            'ip_hash' => str_repeat('a', 64), 'ua' => 'Mozilla/5.0 (X11; Linux) Chrome/120',
            'created_at' => '2026-06-01 09:00:00',
        ]);
    }

    // ── 1. THE ESCAPE ────────────────────────────────────────────────────────

    /**
     * An area containing an underscore must filter on BOTH drivers.
     *
     * This is the whole reason `like()` spells out `ESCAPE '!'`. With the obvious
     * `->where('action','like','stand\_call.%')` this returns 0 on SQLite and 2 on
     * MySQL, and the suite — which is SQLite — would have reported the failure as the
     * feature simply not working while production was fine.
     */
    public function test_an_area_with_an_underscore_filters_on_this_driver(): void
    {
        $this->row(['action' => 'stand_call.save']);
        $this->row(['action' => 'stand_call.open']);
        $this->row(['action' => 'standXcall.save']); // the false positive an unescaped `_` lets in
        $this->row(['action' => 'settings.update']);

        $r = $this->audit->search(['area' => 'stand_call']);

        $this->assertSame(2, $r['total'],
            'an area containing an underscore matched ' . $r['total'] . ' rows, not 2 — the LIKE '
            . 'escape is wrong for ' . DB::connection()->getDriverName());
    }

    /** A literal `%` in the search box must not become "match everything". */
    public function test_a_percent_in_the_search_box_is_a_literal(): void
    {
        $this->row(['action' => 'discount.set', 'meta' => json_encode(['off' => '100%'])]);
        $this->row(['action' => 'settings.update']);
        $this->row(['action' => 'event.create']);

        $this->assertSame(1, $this->audit->search(['q' => '100%'])['total'],
            'searching for a literal percent sign matched more than the row containing it');
    }

    /** An action with no dot at all still belongs to a selectable area. */
    public function test_a_dotless_action_is_reachable_by_area(): void
    {
        $this->row(['action' => 'login']);
        $this->row(['action' => 'login']);
        $this->row(['action' => 'legacy.create']);

        $this->assertSame(2, $this->audit->search(['area' => 'login'])['total'],
            '`login` has no dot, so filtering by its area found nothing');
    }

    // ── 2. THE ALIASES ───────────────────────────────────────────────────────

    /**
     * One event's history is written under two target types. Asking for either must
     * return all of it.
     */
    public function test_both_spellings_of_one_target_type_come_back_together(): void
    {
        $this->row(['action' => 'event.update',     'target_type' => 'site_event', 'target_id' => $this->eventId]);
        $this->row(['action' => 'stand_call.open',  'target_type' => 'event',      'target_id' => $this->eventId]);
        $this->row(['action' => 'nominee.approve',  'target_type' => 'nominee',    'target_id' => 9]);

        foreach (['event', 'site_event'] as $asked) {
            $this->assertSame(2, $this->audit->search(['target_type' => $asked])['total'],
                "filtering on '{$asked}' returned only the rows written with that exact word — "
                . 'one event\'s history is split across two spellings and this reader must fold them');
        }
    }

    /** The facet list folds them too, or the filter offers two entries for one subject. */
    public function test_the_facet_list_folds_the_aliases(): void
    {
        $this->row(['target_type' => 'site_event', 'target_id' => $this->eventId]);
        $this->row(['target_type' => 'event',      'target_id' => $this->eventId]);

        $types = array_column($this->audit->facets()['types'], 'n', 'type');

        $this->assertArrayHasKey('site_event', $types);
        $this->assertArrayNotHasKey('event', $types, 'the alias appeared as its own filter entry');
        $this->assertSame(2, $types['site_event']);
    }

    // ── 3. ONE QUERY BUILDER ─────────────────────────────────────────────────

    /**
     * The dashboard strip and the audit screen must be the same query.
     *
     * Asserted structurally: `recent()` returns rows carrying the resolved fields, which
     * only `search()` adds. A `recent()` that grew its own SELECT would return rows
     * without them and the dashboard would silently go back to printing raw ids.
     */
    public function test_the_dashboard_strip_is_the_same_query_as_the_screen(): void
    {
        $this->row(['target_type' => 'site_event', 'target_id' => $this->eventId]);

        $rows = $this->audit->recent(5);

        $this->assertCount(1, $rows);
        $this->assertSame('Lagos Gala 2026', $rows[0]['target_name'],
            'recent() returned a row the target resolver had not touched — the dashboard is '
            . 'reading the log through a second query');
        $this->assertSame('Adaeze Umeh', $rows[0]['admin_name']);
    }

    // ── NAMING ───────────────────────────────────────────────────────────────

    public function test_a_target_is_named_not_numbered(): void
    {
        $nomineeId = $this->seedNominee('Chidinma Eze');
        $this->row(['action' => 'nominee.approve', 'target_type' => 'nominee', 'target_id' => $nomineeId]);

        $r = $this->audit->search([]);
        $this->assertSame('Chidinma Eze', $r['rows'][0]['target_name']);
        $this->assertSame('Nominee', $r['rows'][0]['target_label']);
    }

    /**
     * A target that no longer exists is the audit trail working, not failing.
     */
    public function test_a_deleted_target_degrades_to_the_bare_id(): void
    {
        $this->row(['action' => 'nominee.delete', 'target_type' => 'nominee', 'target_id' => 987654]);

        $r = $this->audit->search([]);
        $this->assertNull($r['rows'][0]['target_name'], 'a vanished record invented a name');
        $this->assertSame(987654, $r['rows'][0]['target_id'], 'the id it was recorded against was lost');
    }

    /**
     * Resolution is O(types), not O(rows).
     *
     * Asserted by measuring the SAME work at two sizes rather than against a fixed
     * budget: a fixed number would have to absorb whatever schema probes the driver
     * makes on a cold memo, and would then pass a resolver that had quietly gone
     * per-row on a driver that probes less. Equal cost at 12 rows and at 40 is the
     * property; the absolute number is the driver's business.
     */
    public function test_naming_a_page_of_rows_is_batched_not_per_row(): void
    {
        $ids = [];
        for ($i = 0; $i < 40; $i++) $ids[] = $this->seedNominee('Nominee ' . $i);

        $rows = static fn (array $ids): array => array_map(
            static fn (int $id): array => ['target_type' => 'nominee', 'target_id' => $id], $ids);

        $count = static function (array $page): int {
            DB::connection()->flushQueryLog();
            AuditTargets::resolve($page);
            return count(DB::connection()->getQueryLog());
        };

        DB::connection()->enableQueryLog();
        $count($rows(array_slice($ids, 0, 3)));            // warm the column memo
        $small = $count($rows(array_slice($ids, 0, 12)));
        $large = $count($rows($ids));
        DB::connection()->disableQueryLog();

        $this->assertSame($small, $large,
            "naming 12 rows took {$small} queries and 40 took {$large} — the resolver scales with "
            . 'rows, and this screen shows sixty at a time');
        $this->assertLessThanOrEqual(2, $large, "one type should cost one query, not {$large}");
        $this->assertCount(40, AuditTargets::resolve($rows($ids)));
    }

    // ── THE TWO COLUMNS NOTHING HAS EVER RENDERED ────────────────────────────

    /**
     * `ua` has been written on every row since the table shipped and shown nowhere: the
     * data browser's column list never included it, so it was never even in an export.
     */
    public function test_the_user_agent_is_said_in_words(): void
    {
        // Order matters and is the whole risk here: Edge and Opera both carry the word
        // "Chrome", and Chrome carries "Safari". A naive chain reports every Edge
        // session as Chrome, which is exactly the detail somebody checks a login against.
        $this->assertSame('Edge on Windows', AuditService::agent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0'));
        $this->assertSame('Chrome on Android', AuditService::agent(
            'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36'));
        $this->assertSame('Safari on iPhone', AuditService::agent(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1'));
        $this->assertNull(AuditService::agent(''), 'an absent agent invented a browser');
    }

    /**
     * `ip_hash` is stripped from the data browser by the `_hash` suffix rule, so it has
     * never been rendered at all. Six characters is a label for sameness and cannot be
     * turned back into an address.
     */
    public function test_the_device_fingerprint_is_short_and_not_an_address(): void
    {
        $full = hash('sha256', '10.0.0.1');
        $this->assertSame(substr($full, 0, 6), AuditService::device($full));
        $this->assertSame(6, strlen((string) AuditService::device($full)));
        $this->assertNull(AuditService::device(null));
    }

    /**
     * The count of distinct networks one admin acted from. This is what `ip_hash` was
     * recorded for, and nothing has ever counted it.
     */
    public function test_an_admins_distinct_networks_are_counted(): void
    {
        foreach (['1.1.1.1', '1.1.1.1', '2.2.2.2', '3.3.3.3'] as $ip) {
            $this->row(['ip_hash' => hash('sha256', $ip)]);
        }
        $this->row(['admin_id' => $this->tunde, 'ip_hash' => hash('sha256', '9.9.9.9')]);

        $s = $this->audit->actorSummary($this->adaeze);

        $this->assertSame(4, $s['total']);
        $this->assertSame(3, $s['devices'], 'the distinct-network count included another admin, or double-counted a repeat');
    }

    // ── DATES ────────────────────────────────────────────────────────────────

    /**
     * A bare date as the upper bound means the END of that day.
     *
     * Without it `to=2026-06-01` excludes everything that happened on the 1st, which
     * reads as a quiet day rather than an off-by-one — the failure mode where somebody
     * concludes an action never happened.
     */
    public function test_a_bare_end_date_includes_that_whole_day(): void
    {
        $this->row(['created_at' => '2026-06-01 00:01:00']);
        $this->row(['created_at' => '2026-06-01 23:59:00']);
        $this->row(['created_at' => '2026-06-02 00:01:00']);

        $this->assertSame(2, $this->audit->search(['from' => '2026-06-01', 'to' => '2026-06-01'])['total'],
            'a single-day window dropped the evening');
    }

    /**
     * A `T`-separated bound must compare correctly.
     *
     * MySQL normalises it into a TIMESTAMP column and SQLite stores the string verbatim,
     * so an unnormalised bound works on one driver and matches nothing on the other.
     */
    public function test_a_t_separated_bound_is_normalised(): void
    {
        $this->row(['created_at' => '2026-06-01 09:00:00']);
        $this->row(['created_at' => '2026-06-03 09:00:00']);

        $this->assertSame(1, $this->audit->search(['from' => '2026-06-02T00:00'])['total']);
    }

    // ── UNATTRIBUTED ROWS ────────────────────────────────────────────────────

    /**
     * Rows with no admin — cron, the console, an expired session — must be reachable.
     * They are the ones most worth isolating, so they get a filter value rather than
     * being the residue nothing can select.
     */
    public function test_unattributed_rows_can_be_isolated(): void
    {
        $this->row(['admin_id' => null, 'action' => 'votes.deliver']);
        $this->row(['admin_id' => $this->adaeze]);

        $r = $this->audit->search(['admin' => 0]);
        $this->assertSame(1, $r['total']);
        $this->assertSame('votes.deliver', $r['rows'][0]['action']);
    }

    /**
     * ══ EVERY ACTION TAKEN WITHOUT A SESSION WAS DROPPED ON PRODUCTION ══════
     *
     * `gates_audit_log.admin_id` carries `fk_audit_admin` to `gates_admins`, and there is
     * no admin with id 0. Seventy-one call sites pass `(int) ($_SESSION['admin_id'] ?? 0)`,
     * so an action taken without a live session — scheduled work, the console, a session
     * that expired between the page and the post — inserted a 0. MySQL refuses that row on
     * the constraint and `record()`'s catch swallows it, so the log has been failing at
     * exactly the moment it matters most: the action nobody was logged in for.
     *
     * Measured directly against MySQL while writing this:
     *
     *     INSERT INTO probe_audit (admin_id, action) VALUES (0, 'cron') → ERROR 1452
     *
     * Doubly invisible. The harness runs `PRAGMA foreign_keys = OFF` (TestCase), so the
     * suite has always been green; and an audit write is deliberately not allowed to
     * surface an error, so production could not report it either.
     *
     * Asserted on the STORED VALUE rather than on the insert succeeding, so this holds on
     * SQLite too — where the constraint is off and the wrong value would sail straight in.
     */
    public function test_an_action_with_no_session_is_stored_as_unattributed(): void
    {
        $this->audit->record(0, 'votes.deliver');
        $this->audit->record(null, 'cron.run');
        $this->audit->record(-1, 'console.thing');
        $this->audit->record($this->adaeze, 'settings.update');

        $stored = DB::table('gates_audit_log')->orderBy('id')->pluck('admin_id')->all();

        $this->assertSame([null, null, null, $this->adaeze], array_map(
            static fn ($v) => $v === null ? null : (int) $v, $stored),
            'a non-positive admin id reached the column — on MySQL the foreign key rejects '
            . 'it and the catch in record() swallows the row, so the action goes unrecorded');
    }

    /**
     * The filter and the per-admin summary must select the SAME population.
     *
     * Two spellings of "nobody": NULL is what `record()` writes now, a literal 0 is what it
     * wrote before and what survives anywhere the key was not enforcing. If the summary
     * counted one and the table under it listed the other, both numbers would look
     * plausible and disagree — which is the failure nobody spots.
     */
    public function test_the_unattributed_summary_counts_what_the_table_lists(): void
    {
        DB::table('gates_audit_log')->insert([
            ['admin_id' => null, 'action' => 'cron.run',      'created_at' => '2026-06-01 09:00:00'],
            ['admin_id' => 0,    'action' => 'console.thing', 'created_at' => '2026-06-01 09:01:00'],
            ['admin_id' => $this->adaeze, 'action' => 'settings.update', 'created_at' => '2026-06-01 09:02:00'],
        ]);

        $listed  = $this->audit->search(['admin' => 0])['total'];
        $summary = $this->audit->actorSummary(null)['total'];

        $this->assertSame(2, $listed, 'the filter missed one spelling of "no admin"');
        $this->assertSame($listed, $summary,
            "the summary counted {$summary} unattributed actions and the table listed {$listed}");
    }

    // ── PAGING ───────────────────────────────────────────────────────────────

    public function test_the_total_is_the_unpaged_count(): void
    {
        for ($i = 0; $i < 25; $i++) $this->row(['created_at' => '2026-06-01 09:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00']);

        $r = $this->audit->search(['per' => 10, 'page' => 2]);

        $this->assertSame(25, $r['total'], 'the count was taken after the limit, so the pager cannot exist');
        $this->assertSame(3, $r['pages']);
        $this->assertCount(10, $r['rows']);
    }

    /** A page past the end returns the last page, not an empty one. */
    public function test_a_page_past_the_end_lands_on_the_last(): void
    {
        for ($i = 0; $i < 5; $i++) $this->row();

        $r = $this->audit->search(['per' => 2, 'page' => 99]);
        $this->assertSame(3, $r['page']);
        $this->assertNotEmpty($r['rows']);
    }

    /**
     * The per-record trail must be indexed, on whichever driver is running.
     *
     * `gates_audit_log` shipped with indexes on `admin_id`, `action` and `created_at`, and
     * that was right for the only reader it had — the dashboard's `ORDER BY id DESC LIMIT
     * 12`. The screen this file is about asks a different question, and its most important
     * one is `WHERE target_type = ? AND target_id = ?` over a table that grows by every
     * admin action forever.
     *
     * Asserted rather than assumed because an index has no reader by definition: nothing
     * fails when it is missing, the query simply scans, and the moment somebody notices is
     * a year of history later with an operator in a hurry.
     */
    public function test_the_per_record_trail_is_indexed(): void
    {
        $driver  = DB::connection()->getDriverName();
        $columns = [];

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('gates_audit_log')") as $idx) {
                $name = (string) ($idx->name ?? '');
                $cols = array_map(static fn ($c): string => (string) $c->name,
                                  DB::select("PRAGMA index_info('" . $name . "')"));
                $columns[$name] = $cols;
            }
        } else {
            foreach (DB::select('SHOW INDEX FROM gates_audit_log') as $r) {
                $columns[(string) $r->Key_name][(int) $r->Seq_in_index] = (string) $r->Column_name;
            }
            foreach ($columns as $k => $v) { ksort($v); $columns[$k] = array_values($v); }
        }

        $serving = array_filter($columns,
            static fn (array $cols): bool => ($cols[0] ?? null) === 'target_type');

        $this->assertNotSame([], $serving,
            "no index on {$driver} leads with target_type, so the per-record trail — the "
            . 'reason /admin/audit exists — full-scans the whole log. Indexes present: '
            . implode(', ', array_keys($columns)));

        $this->assertContains('target_id', reset($serving),
            'the index leads with target_type but does not cover target_id, so the '
            . 'per-record lookup still scans every row of that type');
    }

    // ── SINGLETONS ───────────────────────────────────────────────────────────

    /**
     * Settings, the rubric and the AI prompts are recorded with a null or meaningless
     * id. Printing `#1` beside them says something untrue about which record it was.
     */
    public function test_a_singleton_target_carries_no_record_number(): void
    {
        $this->assertTrue(AuditTargets::isSingleton('settings'));
        $this->assertTrue(AuditTargets::isSingleton('setting'), 'the singular spelling did not fold');
        $this->assertFalse(AuditTargets::isSingleton('nominee'));
        $this->assertSame('/admin/settings', AuditTargets::href('setting', 1));
    }

    /** Every table named in the target map must exist, or a name silently never resolves. */
    public function test_every_mapped_table_exists(): void
    {
        $missing = [];
        foreach (AuditTargets::mappedTables() as $type => $table) {
            if (!DB::schema()->hasTable($table)) $missing[] = "{$type} → {$table}";
        }
        $this->assertSame([], $missing,
            "the target map names tables this schema does not have, so those targets can only "
            . "ever render as a bare id: " . implode(', ', $missing));
    }

    /** Every name column in the map must exist on its table, for the same reason. */
    public function test_every_mapped_name_column_exists(): void
    {
        $missing = [];
        foreach (AuditTargets::mappedNameColumns() as $type => [$table, $cols]) {
            if (!DB::schema()->hasTable($table)) continue;
            foreach ($cols as $c) {
                if (!DB::schema()->hasColumn($table, $c)) $missing[] = "{$type} → {$table}.{$c}";
            }
        }
        $this->assertSame([], $missing,
            'the target map names columns that do not exist: ' . implode(', ', $missing));
    }

    private function seedNominee(string $name): int
    {
        static $categoryId = 0;
        if ($categoryId === 0) {
            $programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
                'slug' => 'aud-prog', 'title' => 'Audit Programme', 'sort_order' => 1,
            ]);
            $cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
                'programme_id' => $programmeId, 'year' => 2026, 'status' => 'voting',
            ]);
            $categoryId = (int) DB::table('gates_award_categories')->insertGetId([
                'cycle_id' => $cycleId, 'slug' => 'aud-cat', 'title' => 'Audit Category', 'sort_order' => 1,
            ]);
        }
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name, 'status' => 'approved',
        ]);
    }
}
