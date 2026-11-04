<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Like;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A search box is a literal, not a pattern.
 *
 * `->where($col, 'like', "%$q%")` hands the operator's `%` and `_` to the database as
 * wildcards: searching nominees for `100%` matched every name with "100" in it, `_` matched
 * every name, and a query of `%%%%%%%%` made the database do the most work it can for nothing.
 * Support\Like escapes with `!`, the one escape both MySQL and SQLite read the same way.
 */
final class LikeSearchSweepTest extends TestCase
{
    public function test_an_operators_percent_and_underscore_are_matched_literally(): void
    {
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => 1, 'slug' => 'c', 'title' => 'C', 'sort_order' => 1]);
        foreach (['100% Organic Farms', '1000 Voices', 'snake_case Club', 'Snakes Club'] as $n) {
            DB::table('gates_nominees')->insert(['category_id' => $cat, 'name' => $n, 'status' => 'approved', 'vote_count' => 0]);
        }
        $find = static fn(string $q): array => DB::table('gates_nominees')
            ->whereRaw(Like::clause('name'), [Like::contains($q)])->orderBy('name')->pluck('name')->all();

        $this->assertSame(['100% Organic Farms'], $find('100%'));
        $this->assertSame(['snake_case Club'], $find('_'));
        $this->assertSame([], $find('%%%%'));
    }

    /** The sweep: no query in src/ builds a LIKE pattern from a variable by hand. */
    public function test_no_like_pattern_is_built_from_a_variable_without_escaping(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.php')) continue;
            foreach (file($f->getPathname()) ?: [] as $i => $line) {
                // 'like', "%$q%" / "%{$q}%" / '%' . $x . '%' — a variable inside the pattern, unescaped.
                if (preg_match('/[\'"]like[\'"]\s*,\s*("[^"]*\$[^"]*"|\'%\'\s*\.\s*\$)/i', $line)) {
                    $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . ($i + 1);
                }
            }
        }
        $this->assertSame([], $bad, "LIKE built from a variable without Support\\Like:\n" . implode("\n", $bad));
    }
}
