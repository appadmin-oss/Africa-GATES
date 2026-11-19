<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Name;
use PHPUnit\Framework\TestCase;

/**
 * The ballot speaks to a nominee in short phrases ("Back Ada", "Vote for Ada"). Taken as the
 * first word of the name it said "Vote for Chief" to a titled guest and "Vote for Glow" to an
 * organisation — on the button that casts the vote. Name::addressAs() is the one rule, and
 * the nominee page reads it from the controller rather than splitting the name in Twig.
 */
final class NomineeAddressTest extends TestCase
{
    public function test_a_person_is_their_first_name_with_titles_dropped(): void
    {
        self::assertSame('Ada', Name::addressAs('Ada Okonkwo'));
        self::assertSame('Bola', Name::addressAs('Chief Bola Ige'));
        self::assertSame('Ngozi', Name::addressAs('Dr. Ngozi  Eze'));
        self::assertSame('Adeyemi', Name::addressAs('HRM Oba Adeyemi'));
        self::assertSame('Grace', Name::addressAs('Grace'));
        self::assertSame('Chief', Name::addressAs('Chief'), 'a title with nothing after it is the name');
    }

    public function test_an_organisation_keeps_its_whole_name(): void
    {
        self::assertSame('Glow Foundation', Name::addressAs('Glow Foundation'));
        self::assertSame('Bright Futures Initiative', Name::addressAs('Bright  Futures Initiative'));
        self::assertSame('Kano Youth Choir', Name::addressAs('Kano Youth Choir'));
    }

    public function test_the_page_takes_the_rule_from_the_controller(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/pages/vote-nominee.twig');
        self::assertStringNotContainsString("n.name|split(' ')|first", $tpl);
        $ctl = (string) file_get_contents(__DIR__ . '/../../src/Controllers/VoteController.php');
        self::assertStringContainsString("Name::addressAs((string) \$nom->name)", $ctl);
    }
}
