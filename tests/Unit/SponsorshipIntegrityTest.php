<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ProgrammeSponsor;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SPONSORSHIP MONEY MUST NOT BE ABLE TO REACH THE JUDGING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE IS STRUCTURAL AND NOT BEHAVIOURAL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The whole claim of this platform is that an award cannot be bought. Sponsorship is money
 * changing hands beside that claim, so it is the most dangerous revenue line here — and
 * what makes it safe is not restraint, which is a habit, but SHAPE, which is not.
 *
 * A behavioural test can only check the screens that exist today. The guarantee has to
 * survive the screen somebody adds next year, so it is asserted against the schema and the
 * call graph:
 *
 *   · there is no column attaching a sponsor to a category, a nominee or a judge, so there
 *     is nowhere to put the association even if somebody wanted to;
 *   · the scorer never mentions this class, so no figure it produces can depend on one.
 *
 * A test that says "the scorer does not read sponsors" is worth more than a hundred that
 * check a particular page, because the fault it guards is one nobody would write on
 * purpose — it arrives as a convenience on a busy afternoon.
 */
final class SponsorshipIntegrityTest extends TestCase
{
    /** Every file that decides a published figure. */
    private const SCORING = [
        'src/Services/NomineeScoringService.php',
        'src/Services/CpiService.php',
        'src/Services/ResultRelease.php',
        'src/Services/VoterReach.php',
        'src/Services/RuleEngine.php',
        'src/Services/SnapshotService.php',
        'src/Services/ReleasedStanding.php',
    ];

    private function programme(string $slug = 'aipa'): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug . '-' . bin2hex(random_bytes(3)),
            'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
    }

    private function sponsor(int $programmeId, array $over = []): int
    {
        return ProgrammeSponsor::save($over + [
            'programme_id' => $programmeId,
            'name'   => 'Bright Futures Foundation',
            'tier'   => ProgrammeSponsor::TIER_SUPPORTING,
            'status' => ProgrammeSponsor::STATUS_PUBLISHED,
        ]);
    }

    // ══ the guarantee ════════════════════════════════════════════════════════

    /**
     * NOTHING THAT DECIDES A SCORE KNOWS SPONSORS EXIST.
     *
     * The one assertion in this file that would still be worth making if every other test
     * here were deleted.
     */
    public function test_no_scoring_code_reads_the_sponsor_table(): void
    {
        $root = dirname(__DIR__, 2) . '/';
        $offenders = [];

        foreach (self::SCORING as $rel) {
            $path = $root . $rel;
            if (!is_file($path)) continue;

            // Comments blanked: this repository documents a fault in the words of the
            // fault, and a scan that reads comments finds the thing it just forbade.
            $src = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
                (string) file_get_contents($path));

            if (str_contains($src, 'ProgrammeSponsor')
                || str_contains($src, ProgrammeSponsor::TABLE)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            "A scorer that can see who paid is a scorer somebody can argue with. Whatever\n"
            . "this was for, it is not worth the claim it costs.\n\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * AND THE SCHEMA HAS NOWHERE TO PUT THE ASSOCIATION.
     *
     * Belt and braces, and the braces are the ones that hold: a column that does not exist
     * cannot be populated by a well-meaning feature. `programme_id` and `cycle_id` are the
     * only links, and neither of them narrows to a person.
     */
    public function test_a_sponsor_cannot_be_attached_to_a_nominee_a_category_or_a_judge(): void
    {
        $cols = array_map('strtolower',
            DB::schema()->getColumnListing(ProgrammeSponsor::TABLE));

        foreach (['nominee_id', 'category_id', 'judge_id', 'criterion_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $cols,
                "`{$forbidden}` on the sponsor table is a place to record that somebody's "
                . 'money was about a particular person, and a column that exists is a '
                . 'column something will eventually fill');
        }

        $this->assertContains('programme_id', $cols);
        $this->assertContains('cycle_id', $cols);
    }

    // ══ what is published, and what is not ═══════════════════════════════════

    /** A draft is not a sponsorship a page may announce. */
    public function test_only_published_sponsors_reach_a_public_page(): void
    {
        $p = $this->programme();
        $this->sponsor($p, ['name' => 'Published Co', 'status' => ProgrammeSponsor::STATUS_PUBLISHED]);
        $this->sponsor($p, ['name' => 'Draft Co',     'status' => ProgrammeSponsor::STATUS_DRAFT]);
        $this->sponsor($p, ['name' => 'Ended Co',     'status' => ProgrammeSponsor::STATUS_ENDED]);

        $names = array_map(static fn (object $r): string => (string) $r->name,
            ProgrammeSponsor::forCycle($p, null));

        $this->assertSame(['Published Co'], $names);
    }

    /**
     * AND A SPONSORSHIP COMES OFF THE PAGE WHEN IT ENDS, WITHOUT ANYBODY REMEMBERING.
     *
     * A page still naming a sponsor whose agreement expired is making a claim about a
     * relationship that no longer exists — which is the sponsor's complaint to make, not
     * ours, and nobody is watching for it.
     */
    public function test_dates_take_a_sponsor_off_the_page_by_themselves(): void
    {
        $p = $this->programme();
        $this->sponsor($p, ['name' => 'Expired Co',
            'ends_at' => Carbon::now()->subDay()->toDateTimeString()]);
        $this->sponsor($p, ['name' => 'Future Co',
            'starts_at' => Carbon::now()->addMonth()->toDateTimeString()]);
        $this->sponsor($p, ['name' => 'Current Co',
            'starts_at' => Carbon::now()->subMonth()->toDateTimeString(),
            'ends_at'   => Carbon::now()->addMonth()->toDateTimeString()]);

        $names = array_map(static fn (object $r): string => (string) $r->name,
            ProgrammeSponsor::forCycle($p, null));

        $this->assertSame(['Current Co'], $names);
    }

    /**
     * A PROGRAMME-WIDE SPONSOR AND AN EDITION'S OWN BOTH BELONG ON THAT EDITION.
     *
     * `cycle_id IS NULL` backs every edition; an id backs one. Two rows rather than one row
     * with a flag, so an announced edition's page keeps naming the people who were on it
     * after a programme-wide sponsor leaves.
     */
    public function test_both_kinds_of_sponsor_appear_on_an_edition(): void
    {
        $p = $this->programme();
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2026, 'status' => 'judging',
        ]);
        $other = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2025, 'status' => 'archived',
        ]);

        $this->sponsor($p, ['name' => 'Every Edition Co']);
        $this->sponsor($p, ['name' => 'This Edition Co',  'cycle_id' => $cycle]);
        $this->sponsor($p, ['name' => 'Last Edition Co',  'cycle_id' => $other]);

        $names = array_map(static fn (object $r): string => (string) $r->name,
            ProgrammeSponsor::forCycle($p, $cycle));
        sort($names);

        $this->assertSame(['Every Edition Co', 'This Edition Co'], $names,
            'a sponsor of a different edition is on that edition, not this one');
    }

    /**
     * THE HEADLINE SPONSOR IS FIRST, WHATEVER THE DATABASE THINKS.
     *
     * 'headline' sorts after 'partner' in every collation, so ordering by the column prints
     * the arrangement upside down — which is the one arrangement that reliably produces a
     * phone call.
     */
    public function test_tiers_rank_by_standing_and_not_alphabetically(): void
    {
        $p = $this->programme();
        $this->sponsor($p, ['name' => 'Partner Co',    'tier' => ProgrammeSponsor::TIER_PARTNER]);
        $this->sponsor($p, ['name' => 'Supporting Co', 'tier' => ProgrammeSponsor::TIER_SUPPORTING]);
        $this->sponsor($p, ['name' => 'Headline Co',   'tier' => ProgrammeSponsor::TIER_HEADLINE]);

        $names = array_map(static fn (object $r): string => (string) $r->name,
            ProgrammeSponsor::forCycle($p, null));

        $this->assertSame(['Headline Co', 'Supporting Co', 'Partner Co'], $names);
    }

    // ══ the conflict that actually happens ═══════════════════════════════════

    /**
     * A SPONSOR WHO IS ALSO IN THE RUNNING IS REPORTED, NOT BLOCKED.
     *
     * Ordinary, innocent, and indefensible if nobody noticed. Whether it is acceptable is a
     * judgement for a person, and a silent refusal teaches people to stop asking.
     */
    public function test_a_sponsor_who_is_also_a_nominee_is_surfaced(): void
    {
        $p = $this->programme();
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2026, 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'primary', 'title' => 'Primary School Principal',
        ]);
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Bright Futures Academy', 'status' => 'approved',
        ]);

        // "Bright Futures Foundation" sponsoring, "Bright Futures Academy" nominated. The
        // suffixes differ and the match still has to fire — without the fold this control
        // never once reports anything.
        $this->sponsor($p);

        $found = ProgrammeSponsor::conflicts($p, $cycle);

        $this->assertCount(1, $found, 'the conflict check is matching nothing, so it is not a control');
        $this->assertSame('Bright Futures Foundation', $found[0]['sponsor']);
        $this->assertSame('Bright Futures Academy', $found[0]['nominee']);
        $this->assertSame('Primary School Principal', $found[0]['category']);

        // And it did not prevent the sponsorship from existing or publishing.
        $this->assertCount(1, ProgrammeSponsor::forCycle($p, $cycle));
    }

    /** An unrelated sponsor is not reported — a control that fires on everything is noise. */
    public function test_an_unrelated_sponsor_is_not_a_conflict(): void
    {
        $p = $this->programme();
        $cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2026, 'status' => 'judging',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycle, 'slug' => 'primary', 'title' => 'Primary',
        ]);
        DB::table('gates_nominees')->insert([
            'category_id' => $cat, 'name' => 'Adegboyega Aborode', 'status' => 'approved',
        ]);
        $this->sponsor($p);

        $this->assertSame([], ProgrammeSponsor::conflicts($p, $cycle));
    }

    // ══ and a website is a link we are willing to put in an href ═════════════

    /**
     * `javascript:` IN A SPONSOR'S WEBSITE IS A SCRIPT ON A PAGE THAT RANKS AWARDS.
     *
     * An operator pasting a link they were emailed is exactly how it arrives. Parsed, never
     * substring-matched, and only http(s).
     */
    public function test_a_website_that_is_not_a_web_address_is_dropped(): void
    {
        $p = $this->programme();

        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'not a url at all', ''] as $bad) {
            $id = $this->sponsor($p, ['name' => 'X', 'website' => $bad]);
            $this->assertNull(
                DB::table(ProgrammeSponsor::TABLE)->where('id', $id)->value('website'),
                "'{$bad}' was stored as a link");
        }

        // A bare host is a website somebody typed, not a fault — the scheme is added.
        $id = $this->sponsor($p, ['name' => 'Y', 'website' => 'brightfutures.ng']);
        $this->assertSame('https://brightfutures.ng',
            DB::table(ProgrammeSponsor::TABLE)->where('id', $id)->value('website'));
    }

    /** The public page carries `rel="sponsored"`, which is what these links are. */
    public function test_the_public_template_marks_paid_links_as_sponsored(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/awards/programme.twig');

        $this->assertStringContainsString('rel="noopener noreferrer nofollow sponsored"', $tpl,
            'a paid link without rel="sponsored" is the thing that costs a site its ranking');
    }
}
