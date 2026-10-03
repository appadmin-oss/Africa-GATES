<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "SOMEONE YOU BACKED WON" — THE MEMBER'S DASHBOARD PANEL, AND WHO IT MAY CONGRATULATE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS LEFT HERE, AND WHERE THE REST WENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This file held the winner's-moment rules: no celebration on a held or delayed result,
 * play once, never instead of the page, no sound, reduced motion means no particles. The
 * pages carrying the celebration were destroyed on 3 Oct 2026, and the engine
 * (`celebrate.js`, its vendored canvas-confetti and `ag-motion.js`'s counter) went with
 * the second orphan wave the same day. Every rule those methods held is written down in
 * `docs/handoff/inventory/_scripts.md` and the result pages' inventories, for the Phase 3
 * rebuild to re-assert against whatever engine ships.
 *
 * What survives is the server half the dashboard panel stands on —
 * {@see \AfricaGates\Services\MemberActivityService::backedWinners()} — and its rule is
 * the same rule in another place: a member told "someone you backed won" before the
 * announcement IS the announcement.
 */
final class CelebrationTest extends TestCase
{
    /** `slug` is NOT NULL and UNIQUE per cycle — a fixture that omits it passes on
     *  neither driver, and a shared literal collides with the next test's. */
    private function category(string $title): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => 1, 'title' => $title, 'slug' => 'c-' . bin2hex(random_bytes(5)),
        ]);
    }

    private function promoted(int $categoryId, string $name, string $status): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name,
            'status' => $status === '' ? 'approved' : $status,
            'vote_count' => 0,
        ]);
    }

    private function vote(string $email, int $categoryId, int $nomineeId): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nomineeId, 'category_id' => $categoryId,
            // The same hash the service looks up by — `sha256(lower(trim(email)))`.
            'voter_email_hash' => hash('sha256', strtolower(trim($email))),
            'vote_type' => 'standard', 'voted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ───────────────────────── the member's dashboard ─────────────────────────

    public function test_the_query_behind_the_dashboard_panel_actually_runs(): void
    {
        // THE POINT OF THIS TEST IS THE CATCH. backedWinners() swallows a database error
        // and returns [], which is right for an ornament on a records screen and is also
        // indistinguishable from "nobody won" — the first version selected `n.slug`, a
        // column gates_nominees does not have, and would have shown an empty panel for
        // ever without a single error anywhere. So this proves a row comes BACK.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        // A category each: one vote per person per category is enforced by a UNIQUE key.
        $cat = $this->category('Teachers’ Choice');
        $win = $this->promoted($cat, 'Oluwagbemiga Dorcas', 'winner');
        $this->vote($email, $cat, $win);

        $other = $this->category('Still Judging');
        $this->vote($email, $other, $this->promoted($other, 'Not Promoted Yet', 'approved'));

        $got = \AfricaGates\Services\MemberActivityService::backedWinners($email);

        $this->assertCount(1, $got, 'the query came back empty — it may not be running at all');
        $this->assertSame('Oluwagbemiga Dorcas', $got[0]['nominee']);
        $this->assertSame('winner', $got[0]['kind']);
        $this->assertSame('Teachers’ Choice', $got[0]['category']);
        $this->assertSame($win, $got[0]['id']);
    }

    public function test_a_nominee_nobody_has_been_told_about_is_not_congratulated(): void
    {
        // 'winner' is written by CycleMaterialiser inside the promotion, so a nominee who
        // is merely leading, or approved, or pending, must never reach this panel. A
        // member told "someone you backed won" before the announcement IS the announcement.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        // A CATEGORY EACH. `gates_votes` is UNIQUE on (voter_email_hash, category_id) —
        // one vote per person per category is the platform's rule — so a fixture that
        // votes twice in one category is not a stricter test, it is an impossible one.
        foreach (['pending', 'approved'] as $status) {
            $cat = $this->category('Undecided ' . $status);
            $this->vote($email, $cat, $this->promoted($cat, 'Someone ' . $status, $status));
        }

        $this->assertSame([], \AfricaGates\Services\MemberActivityService::backedWinners($email));
    }

    public function test_somebody_elses_vote_is_not_your_celebration(): void
    {
        $mine   = 'mine-' . bin2hex(random_bytes(4)) . '@example.test';
        $theirs = 'theirs-' . bin2hex(random_bytes(4)) . '@example.test';
        $cat = $this->category('Craft');
        $this->vote($theirs, $cat, $this->promoted($cat, 'Their Winner', 'winner'));

        $this->assertSame([], \AfricaGates\Services\MemberActivityService::backedWinners($mine));
    }

    public function test_two_categories_backed_is_two_lines_and_one_key(): void
    {
        // Backing the same nominee twice is not reachable — one vote per person per
        // category, enforced by a UNIQUE key — so the case that matters is two
        // categories. Both appear, and the panel's celebrate key carries both ids, so a
        // member who backs a second winner next month gets a second moment rather than
        // one that already counts as seen.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        $ids = [];
        foreach (['Craft', 'Service'] as $title) {
            $cat = $this->category($title);
            $ids[] = $id = $this->promoted($cat, $title . ' Winner', 'winner');
            $this->vote($email, $cat, $id);
        }

        $got = \AfricaGates\Services\MemberActivityService::backedWinners($email);
        $this->assertCount(2, $got);
        $this->assertSame(array_reverse($ids), array_column($got, 'id'),
            'newest first, so a fresh win is the first thing read');
    }
}
