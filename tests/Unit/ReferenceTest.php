<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Reference;
use PHPUnit\Framework\TestCase;

/**
 * Enterprise nomination reference: AGN-YYYY-XXXXXX-C
 *  - AGN prefix + cycle year for human context,
 *  - 6 Crockford-base32 chars derived bijectively from the row id (no vowel
 *    confusables, non-sequential-looking, collision-free),
 *  - trailing Crockford mod-37 check character so a mistyped reference is
 *    detected at the desk instead of resolving to the wrong nomination.
 * Legacy NOM-{id} references stay parseable.
 */
final class ReferenceTest extends TestCase
{
    public function test_format_shape(): void
    {
        $ref = Reference::nomination(42, 2026);
        $this->assertMatchesRegularExpression('/^AGN-2026-[0-9A-HJKMNP-TV-Z]{6}-[0-9A-HJKMNP-TV-Z*~$=U]$/', $ref);
    }

    public function test_deterministic_and_unique_for_sequential_ids(): void
    {
        $seen = [];
        for ($id = 1; $id <= 500; $id++) {
            $ref = Reference::nomination($id, 2026);
            $this->assertSame($ref, Reference::nomination($id, 2026), 'must be deterministic');
            $this->assertArrayNotHasKey($ref, $seen, 'must be collision-free');
            $seen[$ref] = true;
        }
    }

    public function test_sequential_ids_do_not_look_sequential(): void
    {
        $a = Reference::nomination(100, 2026);
        $b = Reference::nomination(101, 2026);
        // The 6-char code blocks must differ in more than the last character.
        $this->assertNotSame(substr($a, 9, 5), substr($b, 9, 5));
    }

    public function test_is_valid_accepts_generated_and_rejects_typos(): void
    {
        $ref = Reference::nomination(1234, 2026);
        $this->assertTrue(Reference::isValid($ref));

        // Corrupt one code character — the check char must catch it.
        $bad = $ref;
        $pos = 9; // first char of the code block
        $bad[$pos] = $bad[$pos] === 'A' ? 'B' : 'A';
        $this->assertFalse(Reference::isValid($bad));

        $this->assertFalse(Reference::isValid('AGN-2026-ZZZZZZ'));   // missing check char
        $this->assertFalse(Reference::isValid('random-string'));
        $this->assertFalse(Reference::isValid(''));
    }

    public function test_parse_id_round_trips(): void
    {
        foreach ([1, 42, 999, 123456, 900000] as $id) {
            $this->assertSame($id, Reference::parseId(Reference::nomination($id, 2026)));
        }
    }

    public function test_parse_id_accepts_legacy_nom_format(): void
    {
        $this->assertSame(42, Reference::parseId('NOM-42'));
        $this->assertSame(42, Reference::parseId('nom-42'));
        $this->assertNull(Reference::parseId('NOM-abc'));
    }

    public function test_parse_id_rejects_corrupted_reference(): void
    {
        $ref = Reference::nomination(77, 2026);
        $bad = substr($ref, 0, -1) . ($ref[-1] === '0' ? '1' : '0');
        $this->assertNull(Reference::parseId($bad));
    }
}
