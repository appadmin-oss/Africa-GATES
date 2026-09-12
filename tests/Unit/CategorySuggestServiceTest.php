<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CategorySuggestService;
use AfricaGates\Services\AiService;

/**
 * AI category suggestion: the model may only ever return an id from the offered
 * list (pick() enforces it), and the feature stands aside cleanly when there's
 * too little to judge, too few options, or no AI provider.
 */
class CategorySuggestServiceTest extends TestCase
{
    public function test_pick_only_accepts_an_offered_id(): void
    {
        $this->assertSame(7, CategorySuggestService::pick(['category_id' => 7], [3, 7, 9]));
        $this->assertSame(7, CategorySuggestService::pick(['category_id' => '7'], [7, 9]));   // string id coerced
        $this->assertNull(CategorySuggestService::pick(['category_id' => 99], [3, 7, 9]));    // not offered
        $this->assertNull(CategorySuggestService::pick(['category_id' => 0], [3, 7]));        // zero/invalid
        $this->assertNull(CategorySuggestService::pick([], [3, 7]));                          // missing
    }

    public function test_suggest_returns_null_for_thin_story(): void
    {
        $cats = [['id' => 1, 'title' => 'Music'], ['id' => 2, 'title' => 'STEM']];
        $this->assertNull(CategorySuggestService::suggest('too short', $cats, new AiService()));
    }

    public function test_suggest_returns_null_with_fewer_than_two_categories(): void
    {
        $story = str_repeat('She built rural libraries and trained hundreds of teachers. ', 3);
        $this->assertNull(CategorySuggestService::suggest($story, [['id' => 1, 'title' => 'Only one']], new AiService()));
    }

    public function test_suggest_returns_null_without_ai_provider(): void
    {
        $story = str_repeat('She built rural libraries and trained hundreds of teachers. ', 3);
        $cats = [['id' => 1, 'title' => 'Education'], ['id' => 2, 'title' => 'STEM']];
        // new AiService() has no keys → not configured → advisory feature stands aside.
        $this->assertNull(CategorySuggestService::suggest($story, $cats, new AiService()));
    }
}
