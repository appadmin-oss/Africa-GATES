<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\AiFilterService;

/**
 * AI filter sanitiser — proves that only whitelisted fields/values survive
 * (the model can propose anything; nothing unvalidated reaches a query) and
 * that parse returns null without an AI provider (caller falls back to search).
 */
class AiFilterServiceTest extends TestCase
{
    public function test_keeps_only_whitelisted_values(): void
    {
        $out = AiFilterService::sanitize([
            'status'  => 'PENDING',
            'country' => 'ke',
            'range'   => 'month',
            'sort'    => 'oldest',
            'q'       => 'robotics',
        ]);
        $this->assertSame(['status' => 'pending', 'country' => 'KE', 'range' => 'month', 'sort' => 'oldest', 'q' => 'robotics'], $out);
    }

    public function test_drops_invalid_and_injected_fields(): void
    {
        $out = AiFilterService::sanitize([
            'status'    => 'deleted',                 // not an allowed status
            'country'   => 'Nigeria',                 // not ISO2 (4+ letters) → dropped
            'range'     => 'decade',                  // not allowed
            'sort'      => 'random',                   // not allowed
            'drop_table'=> '1; DROP TABLE gates_votes',// unknown field → ignored
            'programme' => '5',                        // not accepted from AI (ids aren't guessable)
        ]);
        $this->assertSame([], $out);
    }

    public function test_country_name_not_iso_is_dropped_but_valid_iso_kept(): void
    {
        $this->assertSame([], AiFilterService::sanitize(['country' => 'Kenya']));
        $this->assertSame(['country' => 'ZA'], AiFilterService::sanitize(['country' => 'za']));
    }

    public function test_q_is_length_capped(): void
    {
        $out = AiFilterService::sanitize(['q' => str_repeat('a', 200)]);
        $this->assertSame(80, mb_strlen($out['q']));
    }

    public function test_parse_returns_null_without_ai_provider(): void
    {
        // No provider is configured in tests → the feature is inert (caller
        // falls back to a plain text search).
        $this->assertNull(AiFilterService::parseNominationFilter('pending from Kenya'));
    }
}
