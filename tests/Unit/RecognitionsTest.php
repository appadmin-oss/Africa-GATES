<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Recognitions;
use AfricaGates\Services\ReleasedStanding;
use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * RECOGNITIONS FROM VERIFIED ISSUERS (Phase 6, REFERENCE §11, GAPS §3.1).
 *
 * The rule: a recognition is issued from a SEALED, ANNOUNCED release of a LIVE programme and
 * from nothing else — and after issue it can only be withdrawn, with a public log. Each test
 * asks one half of that against real rows; the sweep at the end asks whether anything else
 * in `src/` writes the table, because "immutable apart from withdrawal" is only true while
 * one class is the only writer.
 */
final class RecognitionsTest extends TestCase
{
    private const PROG = 61;     // TINYINT programme ids: stay under 255 (CLAUDE.md)
    private const SANDBOX = 62;

    protected function setUp(): void
    {
        parent::setUp();
        ReleasedStanding::forget();
        SchemaHas::forget();
        OptionalColumn::forget();
    }

    /** A programme, a cycle in $status, two categories, nominees and (optionally) a release seal. */
    private function edition(int $prog, string $status, bool $seal = true, bool $active = true): int
    {
        DB::table('gates_award_programmes')->insert(['id' => $prog, 'slug' => 'p' . $prog, 'title' => 'Programme ' . $prog,
            'is_active' => $active ? 1 : 0, 'scope' => 'continental']);
        $cy = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $prog, 'year' => 2025, 'status' => $status]);
        $c1 = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cy, 'slug' => 'a' . $cy, 'title' => 'Teacher of the Year']);
        $c2 = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cy, 'slug' => 'b' . $cy, 'title' => 'School of the Year']);
        $n = fn (int $cat, string $name) => (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => $name, 'status' => 'approved']);
        $rows = [
            [$n($c1, 'Ada Win'), 1, 1], [$n($c1, 'Bola Second'), 2, 1], [$n($c1, 'Chi Third'), 3, 1],
            [$n($c2, 'Dayo Win'), 1, 1], [$n($c2, 'Efe Out'), null, 0],
        ];
        if ($seal) {
            foreach ($rows as $i => [$id, $rank, $in]) {
                DB::table('gates_vote_snapshots')->insert([
                    'cycle_id' => $cy, 'nominee_id' => $id, 'vote_count' => 10 - $i, 'cpi_score' => 500 - $i,
                    'capture_kind' => 'release', 'standing_rank' => $rank, 'in_running' => $in,
                    'snapshot_at' => '2026-08-01 10:00:00',
                ]);
            }
        }
        // Chi Third is on the published shortlist; Efe is on a WITHDRAWN one.
        $sl = (int) DB::table('gates_shortlists')->insertGetId(['cycle_id' => $cy, 'category_id' => $c1, 'status' => 'published']);
        DB::table('gates_shortlist_entries')->insert(['shortlist_id' => $sl, 'nominee_id' => $rows[2][0], 'rank_no' => 3]);
        $gone = (int) DB::table('gates_shortlists')->insertGetId(['cycle_id' => $cy, 'category_id' => $c2, 'status' => 'withdrawn']);
        DB::table('gates_shortlist_entries')->insert(['shortlist_id' => $gone, 'nominee_id' => $rows[4][0], 'rank_no' => 2]);
        return $cy;
    }

    private function standings(): array
    {
        $out = [];
        foreach (DB::table('gates_recognitions')->orderBy('id')->get() as $r) $out[$r->recipient_name] = $r->standing . '/' . $r->kind;
        return $out;
    }

    public function test_an_announced_sealed_release_issues_winners_runners_up_and_published_finalists(): void
    {
        $cy = $this->edition(self::PROG, 'results');
        $this->assertSame(4, Recognitions::syncCycle($cy));
        $this->assertSame([
            'Ada Win' => 'winner/award', 'Bola Second' => 'runner_up/honour',
            'Chi Third' => 'finalist/commendation', 'Dayo Win' => 'winner/award',
        ], $this->standings(), 'off a withdrawn shortlist and out of the running is not a finalist');

        // The issuer is the programme, verified because the platform ran the count.
        $iss = DB::table('gates_recognition_issuers')->where('programme_id', self::PROG)->first();
        $this->assertNotNull($iss->verified_at);
        $this->assertSame(Recognitions::BASIS_PLATFORM_COUNT, $iss->verified_basis);
        $this->assertSame('organisation', $iss->issuer_type);

        // Idempotent: the reference is the key, so a re-run, the seed and the release hook agree.
        $this->assertSame(0, Recognitions::syncCycle($cy));
        $this->assertSame(4, Recognitions::count());
    }

    public function test_nothing_is_issued_for_an_unannounced_unsealed_or_sandbox_cycle(): void
    {
        $judging = $this->edition(self::PROG, 'judging');
        $this->assertSame(0, Recognitions::syncCycle($judging), 'a seal without an announcement is not read');

        DB::table('gates_award_cycles')->where('id', $judging)->update(['status' => 'results']);
        DB::table('gates_vote_snapshots')->where('cycle_id', $judging)->update(['capture_kind' => 'routine']);
        ReleasedStanding::forget();
        $this->assertSame(0, Recognitions::syncCycle($judging), 'released before sealing existed: no guess');

        $sandbox = $this->edition(self::SANDBOX, 'results', true, false);
        $this->assertSame(0, Recognitions::syncCycle($sandbox), 'an inactive programme (the sandbox) issues nothing');
        $this->assertSame(0, Recognitions::syncAll());
        $this->assertSame([], $this->standings());
    }

    public function test_the_readers_contain_the_sandbox_even_for_rows_already_written(): void
    {
        $cy = $this->edition(self::PROG, 'results');
        Recognitions::syncCycle($cy);
        $this->assertCount(4, Recognitions::recent(10));
        $this->assertCount(1, Recognitions::issuers());
        $this->assertCount(1, Recognitions::search('Ada'));
        $this->assertCount(0, Recognitions::search('%'), 'a wildcard is a literal, escaped with !');

        // The programme is switched off afterwards: its recognitions leave every public reader.
        DB::table('gates_award_programmes')->where('id', self::PROG)->update(['is_active' => 0]);
        $this->assertSame([], Recognitions::recent(10));
        $this->assertSame(0, Recognitions::count());
        $this->assertSame([], Recognitions::issuers());
    }

    public function test_a_withdrawal_is_the_only_change_and_it_is_logged_publicly(): void
    {
        $cy = $this->edition(self::PROG, 'results');
        Recognitions::syncCycle($cy);
        $id = (int) DB::table('gates_recognitions')->where('recipient_name', 'Ada Win')->value('id');

        $this->assertFalse(Recognitions::withdraw($id, '   '), 'a withdrawal with no reason is an unexplained deletion');
        $this->assertTrue(Recognitions::withdraw($id, 'Merged into another entry', 0));
        $this->assertFalse(Recognitions::withdraw($id, 'Again'), 'withdrawn once, logged once');

        $row = DB::table('gates_recognitions')->where('id', $id)->first();
        $this->assertNotNull($row, 'never deleted');
        $this->assertSame('Merged into another entry', $row->withdrawn_reason);
        $this->assertSame(3, Recognitions::count());

        $log = Recognitions::withdrawals();
        $this->assertCount(1, $log);
        $this->assertSame('Merged into another entry', $log[0]['reason']);
        // Admin 0 is not an admin: stored as null (CLAUDE.md, the FK sentinel trap).
        $this->assertNull(DB::table('gates_recognition_withdrawals')->value('actor_admin_id'));

        // On the person's page it stays — under withdrawn, with its reason.
        $nid = (int) $row->recipient_nominee_id;
        $mine = Recognitions::forNominee($nid);
        $this->assertSame([], $mine['active']);
        $this->assertSame('Merged into another entry', $mine['withdrawn'][0]['withdrawn_reason']);
    }

    public function test_a_profile_reads_recognitions_through_every_nominee_it_stood_as(): void
    {
        $cy = $this->edition(self::PROG, 'results');
        $pid = (int) DB::table('gates_profiles')->insertGetId(['slug' => 'ada-win', 'display_name' => 'Ada Win', 'email' => 'ada@x.invalid', 'status' => 'approved']);
        Recognitions::syncCycle($cy);
        // Linked AFTER the award: the recognition still reaches the profile, and leads to it.
        DB::table('gates_nominees')->where('name', 'Ada Win')->update(['profile_id' => $pid]);
        $mine = Recognitions::forProfile($pid);
        $this->assertCount(1, $mine['active']);
        $this->assertSame('/registry/ada-win', $mine['active'][0]['recipient']['url']);
        $this->assertStringStartsWith('/results/', $mine['active'][0]['award']['url']);
    }

    /**
     * IMMUTABLE APART FROM WITHDRAWAL holds only while Recognitions is the one writer. Any
     * other file that names the table beside insert/update/delete is a second writer.
     * Proven failing: a planted `DB::table('gates_recognitions')->update(...)` in a scratch
     * service was named by this sweep before it was removed (docs/handoff/PHASE-6.md).
     */
    public function test_nothing_but_recognitions_writes_the_table(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            if (str_ends_with($f->getPathname(), '/Services/Recognitions.php')) continue;
            $body = (string) file_get_contents($f->getPathname());
            if (preg_match('/[\'"]gates_recognition(s|_withdrawals|_issuers)[\'"]\)[^;]*->(update|insert|insertOrIgnore|insertGetId|delete|upsert|increment)\s*\(/s', $body)) {
                $bad[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $bad, 'a second writer of the recognitions tables');
        // And the detector can name a break.
        $this->assertMatchesRegularExpression(
            '/[\'"]gates_recognition(s|_withdrawals|_issuers)[\'"]\)[^;]*->(update|insert|insertOrIgnore|insertGetId|delete|upsert|increment)\s*\(/s',
            "DB::table('gates_recognitions')->where('id', 1)->update(['title' => 'x']);"
        );
    }
}
