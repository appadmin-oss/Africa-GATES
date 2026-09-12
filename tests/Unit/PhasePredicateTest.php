<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\CyclePolicy;

/**
 * Gating decisions must read the policy's PREDICATE, never the phase label.
 *
 * `cycle_status` is the computed phase NAME. Comparing it to `'nominations'` or
 * `'voting'` works today, which is exactly why it survived: it is a second
 * implementation of {@see \AfricaGates\Services\CyclePhase::isNominationsOpen()} that
 * happens to agree. The whole restructure this branch performs exists because the
 * phase was decided in several places that quietly disagreed, and a label comparison
 * is that same defect in a cheaper disguise.
 *
 * Five sites did it. The two that mattered:
 *
 *  • `NominationController` filtered the wizard's programme list by label — and did
 *    it TWICE, in `form()` and in the POST re-render, so the list a user saw after a
 *    validation error was derived independently of the one they first saw. That is
 *    F7's exact failure surface: the wizard offering programmes it should not, or
 *    hiding ones it should.
 *  • `/vote` built `votingIds` by label, and that value is the DENOMINATOR of the
 *    per-device ballot tracker — a divergence would have it read "2 of 3" against a
 *    different 3 than the guard uses.
 */
class PhasePredicateTest extends TestCase
{
    private function seedProgramme(string $slug, array $cycle): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => ucfirst($slug), 'is_active' => 1, 'sort_order' => 1,
            'description' => 'A programme.',
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId(array_merge(
            ['programme_id' => $pid, 'year' => (int) date('Y')], $cycle
        ));
        DB::table('gates_award_categories')->insert([
            'cycle_id' => $cid, 'slug' => 'c-' . $slug, 'title' => 'Cat', 'sort_order' => 1,
        ]);
    }

    public function test_no_source_gates_behaviour_on_the_phase_label(): void
    {
        // The regression guard. Comments are stripped because the fixed files explain
        // the trap in prose and would otherwise flag themselves — the same false
        // positive the CSP and SQL scans hit.
        $offenders = [];
        foreach ([__DIR__ . '/../../src', __DIR__ . '/../../templates'] as $dir) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($rii as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) continue;
                $body = (string) preg_replace(
                    ['~/\*.*?\*/~s', '~//[^\n]*~', '~\{#.*?#\}~s'],
                    '',
                    (string) file_get_contents($file->getPathname())
                );
                if (preg_match(
                    "~cycle_status'?\]?\s*(===?|==)\s*'(nominations|voting|judging|results|shortlisting)'"
                    . "|in_array\(\\\$p\['cycle_status'\]~",
                    $body,
                    $m
                )) {
                    $offenders[] = basename($file->getPathname()) . ': ' . trim($m[0]);
                }
            }
        }

        $this->assertSame([], $offenders,
            "use the policy predicate (is_nominations_open / is_voting_open), not the phase label");
    }

    public function test_the_view_model_exposes_the_predicate_the_call_sites_need(): void
    {
        // If these keys ever disappear the call sites fall back to empty() and silently
        // close every programme, so the contract is asserted rather than assumed.
        $this->seedProgramme('open-noms', [
            'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);
        $c = DB::table('gates_award_cycles')->first();

        $state = CyclePolicy::stateFor($c);

        $this->assertArrayHasKey('is_nominations_open', $state);
        $this->assertArrayHasKey('is_voting_open', $state);
        $this->assertTrue($state['is_nominations_open']);
        $this->assertFalse($state['is_voting_open'], 'a page must never claim both at once');
    }

    public function test_the_wizard_offers_a_programme_open_for_nominations(): void
    {
        // The positive control. A guard that only proves nothing is offered would pass
        // just as happily with the feature entirely broken.
        $this->seedProgramme('open-noms', [
            'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);

        $progs = (new \AfricaGates\Services\AwardService())->getActiveProgrammesWithStatus();
        $open  = array_values(array_filter($progs, fn ($p) => !empty($p['phase']['is_nominations_open'])));

        $this->assertCount(1, $open);
        $this->assertSame('open-noms', $open[0]['slug']);
    }

    public function test_a_programme_in_voting_is_not_offered_for_nominations(): void
    {
        $this->seedProgramme('voting-now', [
            'status' => 'voting',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'voting_open'       => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close'      => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);

        $progs = (new \AfricaGates\Services\AwardService())->getActiveProgrammesWithStatus();

        $this->assertSame([], array_values(array_filter(
            $progs, fn ($p) => !empty($p['phase']['is_nominations_open'])
        )));
        $this->assertCount(1, array_values(array_filter(
            $progs, fn ($p) => !empty($p['phase']['is_voting_open'])
        )), 'and it IS offered for voting');
    }

    public function test_the_predicate_agrees_with_the_label_on_every_phase(): void
    {
        // They agree today — that is why the label comparison survived review. Pinning
        // the agreement means a future phase that accepts nominations under a
        // different name cannot silently split the two apart.
        foreach (\AfricaGates\Services\CyclePhase::cases() as $phase) {
            $this->assertSame(
                $phase->value === 'nominations',
                $phase->isNominationsOpen(),
                "{$phase->value}: if this ever stops matching, every label comparison is a bug"
            );
            $this->assertSame(
                $phase->value === 'voting',
                $phase->isVotingOpen(),
                "{$phase->value}: same"
            );
        }
    }
}
