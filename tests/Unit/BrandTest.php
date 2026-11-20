<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\AwardDetails;
use AfricaGates\Support\Brand;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Change the Africa G8 to Africa GATES" (owner, 16 Oct 2026), and keep it changed in the
 * award details and terms, which operators type and paste.
 */
final class BrandTest extends TestCase
{
    public function test_every_retired_spelling_becomes_the_name_and_nothing_else_moves(): void
    {
        foreach (['African G8', 'Africa G8', 'AfricaG8', 'africa-g8', 'AFRICAN G8', 'African GATES', 'african gates'] as $old) {
            $this->assertSame('Join ' . Brand::NAME . ' today', Brand::normalise("Join {$old} today"), $old);
        }
        // Left exactly as typed: the real G8, the right name, and the platform's own
        // addresses — a rewritten link is a broken one.
        foreach (['the G8 summit in 2005', 'Africa GATES', 'https://africa-gates.org/awards',
                  'write to info@africagates.org', 'info@africa-g8.org'] as $keep) {
            $this->assertSame($keep, Brand::normalise($keep), $keep);
        }
    }

    /** A sweep, so the next paste into a template or an article cannot bring it back. */
    public function test_no_shipped_source_or_template_says_the_retired_name(): void
    {
        $root = dirname(__DIR__, 2);
        $bad = [];
        foreach (['src', 'templates'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                // Brand.php defines the rule; ReplyLinks matches the wrong DOMAINS a model may
                // guess for this site (`africag8.…`) in order to refuse them, so it must name them.
                if (!preg_match('/\.(php|twig)$/', $f->getFilename())
                    || preg_match('~Support/(Brand|ReplyLinks)\.php$~', $f->getPathname())) continue;
                if (Brand::needsRepair((string) file_get_contents($f->getPathname()))) $bad[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertSame([], $bad);
        $this->assertGreaterThan(100, iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/templates", \FilesystemIterator::SKIP_DOTS))), 'the sweep read nothing');
    }

    public function test_award_details_are_stored_capped_and_named_right(): void
    {
        $id = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'brand-a', 'title' => 'Brand A', 'is_active' => 1]);
        $this->assertNull(AwardDetails::save($id, [
            'eligibility' => 'Teachers in public schools, nominated through African G8.',
            'judging_note' => '<b>Five</b> judges',
            'faq_q' => ['Can I nominate myself?', 'Half a pair', ''],
            'faq_a' => ['No — somebody who knows your work must.', '', ''],
        ]));
        $d = AwardDetails::of(DB::table('gates_award_programmes')->where('id', $id)->first());
        $this->assertSame('Teachers in public schools, nominated through Africa GATES.', $d['eligibility']);
        $this->assertSame('Five judges', $d['judging_note'], 'markup reached the page');
        $this->assertSame([['q' => 'Can I nominate myself?', 'a' => 'No — somebody who knows your work must.']], $d['faq'],
            'a half-filled pair was kept');
        $this->assertSame('', $d['voting_note'], 'an unset section is not blank-safe');
        $this->assertArrayNotHasKey('prize', $d, 'prizes live on the edition now (EditionPrizes)');

        $this->assertNotNull(AwardDetails::save($id, ['judging_note' => str_repeat('x', 1001)]), 'the cap was not enforced');
        $many = array_fill(0, 9, 'Q?');
        $this->assertNotNull(AwardDetails::save($id, ['faq_q' => $many, 'faq_a' => array_fill(0, 9, 'A.')]), 'nine questions were accepted');
    }

    /** Terms are what people accepted: the name is right in every version published from now on. */
    public function test_published_terms_carry_the_name(): void
    {
        $id = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'brand-t', 'title' => 'Brand T', 'is_active' => 1]);
        \AfricaGates\Services\AwardTerms::publish($id, 'These terms govern the African G8 awards.');
        \AfricaGates\Services\AwardTerms::forget();
        $this->assertSame('These terms govern the Africa GATES awards.', \AfricaGates\Services\AwardTerms::current($id)['body']);
    }
}
