<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\HelpCentre;
use Tests\TestCase;

/**
 * NO SURFACE MAY STILL PROMISE THAT MONEY CANNOT MOVE A RANKING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SWEEP AND NOT A LIST OF FIXES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The community half of the index normalised over `organic_vote_count` — free votes
 * only — and eleven surfaces said so in their own words. When the rule changed, finding
 * them meant grepping for eleven different phrasings across templates, mailers, help
 * articles and docs, and the first pass missed six: the admin settings screen twice, a
 * points-redemption line, the paid-vote receipt EMAIL, and the tiebreak note on both the
 * public result page and the release screen an operator signs results off on.
 *
 * The tiebreak pair is the one that shows why this is a test rather than a task. Both
 * said the tie was broken on "organic support" and printed the two organic counts —
 * while the comparator had been changed to read the full tally. On the live cycle those
 * counts are 0 and 0, so the release screen would have told an operator that a winner
 * "takes it on organic support — 0 votes against 0" at the exact moment they were
 * deciding whether to publish.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS AND IS NOT BEING ASSERTED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not "never mention money". The true separation — judges are never shown a vote count,
 * so no purchase reaches the judging half — is stated deliberately and often, and must
 * stay. What is banned is the specific dead claim: that a purchased vote does not reach
 * the RANKING, or that the index reads only the free ones.
 *
 * Comments are stripped before scanning. Every comment this fix left behind quotes the
 * sentence it replaced, because that is the house style here — and a scanner reading its
 * own repair as the fault has cost this repository six separate debugging sessions.
 */
final class MoneyClaimSweepTest extends TestCase
{
    /**
     * Phrases that can only be the old rule, each with what it would tell a reader.
     *
     * Deliberately narrow. A pattern loose enough to catch every possible phrasing is a
     * pattern that fails on the honest sentence about the judging half, and a sweep that
     * cries wolf gets an exclusion list bolted to it within a month.
     */
    private const DEAD_CLAIMS = [
        'never moved by money'
            => 'says the index is untouched by purchases',
        'public tally only'
            => 'says a paid vote reaches the display total and nothing else',
        'excluded from the cultural power index'
            => 'says purchased votes are not scored',
        'never affect judged/cpi'
            => 'says a redeemed vote cannot move rank',
        'cannot move rank'
            => 'says a purchase cannot change a standing',
        'never the cpi'
            => 'says the index does not read purchased votes',
        'tiebreak reads organic'
            => 'describes a comparator that reads the full tally',
        'takes it on <b>organic support</b>'
            => 'prints an organic tiebreak that is 0 against 0 where free voting is off',
        'organic cpi'
            => 'names a community half that no longer exists',
    ];

    /** @return list<string> */
    private static function files(): array
    {
        $out = [];
        $root = dirname(__DIR__, 2);
        foreach ([$root . '/templates', $root . '/src/Services', $root . '/src/Controllers',
                  $root . '/src/Admin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $ext = $f->getExtension();
                if ($ext === 'twig' || $ext === 'php') $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    /** Prose only: Twig comments, PHP block comments and line comments removed. */
    private static function prose(string $src): string
    {
        return (string) preg_replace(
            ['~\{#.*?#\}~s', '~<!--.*?-->~s', '~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
            ' ', $src);
    }

    public function test_no_shipped_surface_still_says_a_purchase_cannot_move_a_ranking(): void
    {
        $root  = dirname(__DIR__, 2) . '/';
        $found = [];

        foreach (self::files() as $path) {
            $code = mb_strtolower(self::prose((string) file_get_contents($path)));
            foreach (self::DEAD_CLAIMS as $needle => $what) {
                if (str_contains($code, $needle)) {
                    $found[] = str_replace($root, '', $path) . ' — "' . $needle . '": ' . $what;
                }
            }
        }

        $this->assertSame([], $found,
            "A surface still publishes the old methodology:\n  " . implode("\n  ", $found));
    }

    /**
     * AND THE HELP CENTRE, WHICH IS PROSE ALL THE WAY DOWN.
     *
     * Scanned through the reader rather than the file, so a claim reintroduced through a
     * `{placeholder}` or a conditional branch is caught with the literal ones.
     */
    public function test_no_help_article_still_says_it_either(): void
    {
        $found = [];
        foreach (HelpCentre::all() as $a) {
            $text = mb_strtolower(HelpCentre::plainText($a));
            foreach (array_keys(self::DEAD_CLAIMS) as $needle) {
                if (str_contains($text, $needle)) $found[] = $a['slug'] . ' — "' . $needle . '"';
            }
        }

        $this->assertSame([], $found,
            "A help article still publishes the old methodology:\n  " . implode("\n  ", $found));
    }

    /**
     * THE TRUE CLAIM IS STILL BEING MADE.
     *
     * The failure mode of a sweep like this is somebody deleting the sentences to make it
     * pass. What separates money from the award is real and load-bearing — judges are not
     * shown a vote count — so it is asserted present, not merely permitted.
     */
    public function test_the_separation_that_does_hold_is_still_stated_publicly(): void
    {
        $said = 0;
        foreach (['templates/pages/integrity.twig',
                  'templates/pages/results/show.twig'] as $rel) {
            $prose = mb_strtolower(self::prose(
                (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel)));
            if (str_contains($prose, 'judges') && str_contains($prose, 'vote count')) $said++;
        }

        $this->assertGreaterThan(0, $said,
            'no public page says any more that judges are not shown a vote count — the '
            . 'sweep above has been satisfied by deleting the claim rather than by '
            . 'correcting it');
    }
}
