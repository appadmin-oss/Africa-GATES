<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asking a bank whose account a number is — number in, the holder's NAME out — is a
 * register lookup. The live lookup behind the withdrawal form was throttled; the confirm
 * screen asked the bank the same question and counted nothing, so a script posting that
 * form had an unmetered way to put names to account numbers.
 *
 * So the rule, not the instance: every member-facing method that calls
 * `resolveAccount()` must spend the shared budget (`mayResolve()`) in the SAME method.
 * A second door added later is caught by name.
 */
final class WalletResolveThrottleTest extends TestCase
{
    public function test_every_member_facing_bank_lookup_spends_the_shared_budget(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Controllers';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        $bad = [];
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($f->getPathname());
            foreach (self::methods($src) as $name => $body) {
                if (!str_contains($body, '->resolveAccount(')) continue;
                $seen++;
                if (!str_contains($body, 'mayResolve(')) $bad[] = basename($f->getPathname()) . '::' . $name;
            }
        }
        $this->assertGreaterThanOrEqual(2, $seen, 'the sweep found fewer lookups than exist — it is not reading the controllers');
        $this->assertSame([], $bad, 'a bank lookup a member can trigger without spending the budget');
    }

    /** @return array<string,string> method name => body, by brace matching from each `function`. */
    private static function methods(string $src): array
    {
        $out = [];
        if (!preg_match_all('/function\s+(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) return $out;
        foreach ($m[1] as [$name, $at]) {
            $open = strpos($src, '{', $at);
            if ($open === false) continue;
            $depth = 0;
            $n = strlen($src);
            for ($i = $open; $i < $n; $i++) {
                if ($src[$i] === '{') $depth++;
                elseif ($src[$i] === '}' && --$depth === 0) break;
            }
            $out[$name] = substr($src, $open, $i - $open + 1);
        }
        return $out;
    }
}
