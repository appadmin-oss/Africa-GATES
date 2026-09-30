<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AwardService;
use AfricaGates\Services\NominationRules;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The two nomination doors accept and refuse the same things.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THIS IS THE THIRD TIME THEY HAVE BEEN FOUND APART
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `POST /nominate` and `POST /api/nominations` both write to `gates_nominations` and
 * both feed the same review desk. They have diverged twice already:
 *
 *   1. EVERYTHING AFTER THE INSERT. The API door told no operator, sent the nominator
 *      no confirmation and no reference, never notified the nominee, queued no AI
 *      triage and fired no webhook. A nomination arriving there sat in the table until
 *      somebody happened to look. Fixed by {@see \AfricaGates\Services\NominationAftercare}.
 *
 *   2. EVERYTHING BEFORE IT. The form required thirteen fields and the API six, so an
 *      API nomination landed with no state, no LGA, no nominator phone, no age range
 *      and a one-word name the form would have refused. Found by reading them side by
 *      side while adding a third rule to one of them.
 *
 * The lesson both times is the same and it is not "check the other door": it is that a
 * rule living in a controller is a rule the next controller does not have. So the shared
 * rules live in {@see NominationRules}, they are enforced inside `AwardService` where
 * every door must pass, and this test holds the property rather than the arrangement —
 * if somebody moves the rules again, this still fails when the doors disagree.
 *
 * ── WHAT IS DELIBERATELY *NOT* ASSERTED ─────────────────────────────────────
 *
 * The doors are allowed to differ on what only a browser form can collect. The web form
 * asks for the nominator's state, LGA and age range and the API does not, because an
 * API caller is a system integration and those fields exist so a moderator can place a
 * person. That is a real difference, it is documented in both places, and pinning them
 * identical would force the API to demand fields no integration has.
 *
 * What may NOT differ is any rule about the nomination itself: who it is about, which
 * categories it names, what each reason says, and what evidence rides along.
 */
final class NominationDoorsAgreeTest extends TestCase
{
    private const PROG  = 411;
    private const CYCLE = 4110;
    private const CAT_A = 41101;
    private const CAT_B = 41102;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insert([
            'id' => self::PROG, 'slug' => 'doors-prog', 'title' => 'Doors Awards', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => self::PROG, 'year' => 2026, 'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        DB::table('gates_award_categories')->insert([
            ['id' => self::CAT_A, 'cycle_id' => self::CYCLE, 'slug' => 'd-a', 'title' => 'One'],
            ['id' => self::CAT_B, 'cycle_id' => self::CYCLE, 'slug' => 'd-b', 'title' => 'Two'],
        ]);
    }

    private function long(string $s): string
    {
        return $s . ' — documented, sustained work that other people can go and verify.';
    }

    /** @return array<string,mixed> */
    private function body(array $over = []): array
    {
        return $over + [
            'programme_id'    => self::PROG,
            'nominee_name'    => 'Ada Lovelace',
            'country_code'    => 'NG',
            'nominator_name'  => 'Grace Hopper',
            'nominator_email' => 'grace@example.com',
            'nominee_email'   => 'ada@example.com',
            'categories'      => [
                self::CAT_A => $this->long('First'),
                self::CAT_B => $this->long('Second'),
            ],
        ];
    }

    /**
     * Put a body through the shared path and say whether it was accepted.
     *
     * Both controllers reach exactly this call, which is the property being tested:
     * neither of them can accept something this refuses, because neither of them has a
     * way past it.
     */
    private function verdict(array $body): ?string
    {
        try {
            (new AwardService())->submitNomination($body, '127.0.0.1');
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Every case that must come out the same whichever door it arrives at.
     *
     * @return array<string,array{0:array<string,mixed>,1:bool}>
     */
    public static function cases(): array
    {
        $long = static fn (string $s): string => $s . ' — documented, sustained work that other people can go and verify.';

        return [
            'two categories, both reasoned' => [[], true],
            'only one category'             => [['categories' => [self::CAT_A => $long('Only')]], false],
            'a reason of 39 characters'     => [['categories' => [
                self::CAT_A => $long('Fine'), self::CAT_B => str_repeat('x', 39),
            ]], false],
            'a reason of exactly 40'        => [['categories' => [
                self::CAT_A => $long('Fine'), self::CAT_B => str_repeat('x', 40),
            ]], true],
            'a one-word person'             => [['nominee_name' => 'Ada'], false],
            'a one-word organisation'       => [['nominee_name' => 'Andela', 'nominee_kind' => 'organisation'], true],
            'a category from nowhere'       => [['categories' => [
                self::CAT_A => $long('Fine'), 987654 => $long('Alien'),
            ]], false],
            'six links'                     => [['evidence_links' => [
                'https://a.example/1', 'https://b.example/2', 'https://c.example/3',
                'https://d.example/4', 'https://e.example/5', 'https://f.example/6',
            ]], false],
            'a javascript link'             => [['evidence_links' => ['javascript:alert(1)']], false],
            'no evidence at all'            => [['evidence_links' => []], true],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string,mixed> $over
     */
    public function test_the_shared_path_decides_every_case(array $over, bool $shouldPass): void
    {
        $why = $this->verdict($this->body($over));

        if ($shouldPass) {
            $this->assertNull($why, 'refused something it should have taken: ' . (string) $why);
        } else {
            $this->assertNotNull($why, 'accepted something it should have refused');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // And neither controller may keep a private copy of a shared rule
    // ══════════════════════════════════════════════════════════════════════════

    public function test_no_controller_re_implements_the_category_or_reason_rules(): void
    {
        // The numbers, spelled out in a controller, are how the two doors came to
        // disagree in the first place. They belong to NominationRules, which is the
        // only place any of them may appear — and the sweep names the constant to
        // reach for rather than only complaining.
        $offenders = [];

        foreach (glob(__DIR__ . '/../../src/Controllers/*.php') ?: [] as $file) {
            // Comments explain the rule and must be allowed to state it — a guard that
            // forbids describing what it guards is a guard that makes the code
            // undocumentable, and this repository has that scar on a colour sweep.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', (string) file_get_contents($file));
            $name = basename($file);

            foreach ([
                '/\bcount\s*\(\s*\$\w*categor/i'          => 'counts categories itself — NominationRules::checkCategories()',
                '/mb_strlen[^;]{0,60}<\s*40\b/'           => 'spells the 40-character floor — NominationRules::MIN_REASON',
                '/\bat least 40 characters\b/i'           => 'writes its own short-reason copy — NominationRules::SHORT_REASON',
            ] as $pattern => $why) {
                if (preg_match($pattern, $code)) $offenders[] = "$name $why";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_the_forty_is_stated_once_in_the_code(): void
    {
        // The template shows a live counter and the server refuses below the same
        // number. Two literals is how a form comes to count to one rule and refuse by
        // another — so the template reads the constant too.
        $this->assertSame(40, NominationRules::MIN_REASON);
        $this->assertStringContainsString('40', NominationRules::SHORT_REASON,
            'the sentence and the number must agree, because a person reads both');
    }
}
