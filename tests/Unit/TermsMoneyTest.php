<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\LegalSeeder;
use Tests\TestCase;

/**
 * The Terms of Participation, on what money does to a score (2027_03_31_terms_money_repair).
 *
 * Each retired sentence was true of an earlier rule and outlived it on the page a
 * contributor agrees to before paying: bought votes count in the total tally, a dead heat
 * is ordered on the tally, nothing caps bought votes, and the denominator is the edition.
 */
final class TermsMoneyTest extends TestCase
{
    public function test_the_terms_no_longer_say_what_the_scorer_contradicts(): void
    {
        $t = strtolower(strip_tags(html_entity_decode((string) LegalSeeder::documents()['terms']['body'])));
        foreach (['the scorer does not read', 'tiebreak is organic votes', 'normalised within each category',
                  'capped as a proportion of the organic support', 'move the cultural power index'] as $retired) {
            $this->assertStringNotContainsString($retired, $t, "the terms still say “{$retired}”");
        }
    }

    public function test_the_terms_state_the_wallet_vote_rules(): void
    {
        $t = strtolower(strip_tags(html_entity_decode((string) LegalSeeder::documents()['terms']['body'])));
        $this->assertStringContainsString('votes paid from a return earn no return and do not count towards qualifying', $t);
        $this->assertStringContainsString('count in the community part of the score', $t);
    }
}
