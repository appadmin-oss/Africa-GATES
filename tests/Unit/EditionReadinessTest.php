<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EditionReadiness;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The edition readiness checklist (11 Nov 2026). Computed, never ticked: each case below
 * changes the edition's rows and requires the list to move with them.
 */
final class EditionReadinessTest extends TestCase
{
    private function cycle(array $over = []): object
    {
        if ($over) DB::table('gates_award_cycles')->where('id', 6126)->update($over);
        return DB::table('gates_award_cycles')->where('id', 6126)->first();
    }

    /** @return array<string,string> title => state */
    private function items(object $c): array
    {
        $out = [];
        foreach (EditionReadiness::for($c) as $i) $out[$i['title']] = $i['state'];
        return $out;
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 61, 'slug' => 'rd-prog', 'title' => 'Readiness Prize', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 6126, 'programme_id' => 61, 'year' => 2026, 'status' => 'upcoming']);
    }

    public function test_an_empty_edition_says_what_is_missing(): void
    {
        $items = $this->items($this->cycle());
        $this->assertSame('todo', $items['Dates to set'] ?? null);
        $this->assertSame('todo', $items['No categories yet'] ?? null);
    }

    public function test_a_thin_ballot_is_a_note_before_voting_and_a_task_from_voting_on(): void
    {
        DB::table('gates_award_categories')->insert(['id' => 6101, 'cycle_id' => 6126, 'slug' => 'arts', 'title' => 'Arts', 'description' => 'For artists.']);
        DB::table('gates_nominees')->insert(['category_id' => 6101, 'name' => 'Ada', 'status' => 'approved']);
        DB::table('gates_nominees')->insert(['category_id' => 6101, 'name' => 'Bola', 'status' => 'pending']);
        $now = Carbon::now();
        $dates = ['nominations_open' => $now->copy()->subDays(10)->toDateTimeString(), 'nominations_close' => $now->copy()->addDays(5)->toDateTimeString(),
            'voting_open' => $now->copy()->addDays(6)->toDateTimeString(), 'voting_close' => $now->copy()->addDays(20)->toDateTimeString(),
            'results_date' => $now->copy()->addDays(30)->toDateTimeString(), 'status' => 'nominations'];
        $items = $this->items($this->cycle($dates));
        $this->assertSame('ok', $items['Every date is set, in order'] ?? null);
        $this->assertSame('note', $items['1 category has fewer than 2 approved nominees'] ?? null);
        $this->assertSame('note', $items['1 nominee waiting for approval'] ?? null);

        $voting = ['nominations_open' => $now->copy()->subDays(20)->toDateTimeString(), 'nominations_close' => $now->copy()->subDays(10)->toDateTimeString(),
            'voting_open' => $now->copy()->subDay()->toDateTimeString(), 'status' => 'voting'] + $dates;
        $items = $this->items($this->cycle($voting));
        $this->assertSame('todo', $items['1 category has fewer than 2 approved nominees'] ?? null, 'a ballot with one name is no choice');
        $this->assertSame('todo', $items['1 nominee waiting for approval'] ?? null);

        DB::table('gates_nominees')->where('name', 'Bola')->update(['status' => 'approved']);
        $items = $this->items($this->cycle());
        $this->assertSame('ok', $items['Every category has a choice on its ballot'] ?? null, 'fixing it moves the list');
    }

    public function test_a_category_with_no_description_and_no_rubric_are_named(): void
    {
        DB::table('gates_award_categories')->insert(['cycle_id' => 6126, 'slug' => 'sport', 'title' => 'Sport']);
        DB::table('gates_judge_criteria')->update(['is_active' => 0]);   // the harness seeds a global rubric
        $items = $this->items($this->cycle());
        $this->assertSame('note', $items['1 category has no description'] ?? null);
        $this->assertSame('todo', $items['No judging criteria'] ?? null);
        DB::table('gates_judge_criteria')->insert(['programme_id' => 61, 'slug' => 'impact', 'label' => 'Impact', 'weight' => 100, 'sort_order' => 1, 'is_active' => 1]);
        $this->assertSame('ok', $this->items($this->cycle())['1 judging criterion'] ?? null);
    }
}
