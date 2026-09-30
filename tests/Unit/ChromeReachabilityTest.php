<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Can anybody actually open the things this phase built?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * §18: A MECHANISM COMPLETE IN EVERY PART EXCEPT THE WAY IN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This repository has shipped that fault at least four times, and the most expensive was
 * over money: `manageUrl()` built the link a donor stops a monthly gift with, `byToken()`
 * resolved it, the route rendered the page, and the controller's docblock explained at
 * length why the cancellation is a link in a receipt rather than a login. No receipt, no
 * template and no page ever contained the URL. Every piece worked. Nobody was ever handed
 * one.
 *
 * A bottom sheet is the same shape with a shorter fuse. It is `inert` at rest, so it is
 * invisible to a screen reader, invisible in a screenshot and invisible to every render
 * test — a sheet nothing can open looks exactly like a sheet nobody happened to open.
 *
 * It was live in this phase, which is why this exists: `quick-settings.twig` was mounted
 * on the ~180 pages of the old layout, and its ONLY trigger is the phone app bar's avatar,
 * which that layout does not have. A dialog in every page of the site that nothing on any
 * of them could open.
 *
 * The question is not "does it work" — it did — but **who is ever handed this**.
 */
final class ChromeReachabilityTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../templates/';

    /**
     * Sheet partial → the attribute that opens it.
     *
     * Deliberately a short, explicit table. It is not an enumeration of past failures:
     * every entry is a dialog that ships `inert`, and the next one added here is one
     * line beside the partial it belongs to.
     */
    /**
     * The attribute has to end where the name ends.
     *
     * `\b` does NOT do that: a word boundary sits between `k` and `-`, so
     * `/\bdata-ag-quick\b/` matches `data-ag-quick-close` — a button INSIDE the sheet,
     * which made the sheet vouch for its own reachability and the first probe of this
     * test passed when it should have failed. `(?![-\w])` is the same rule pinned to the
     * right token, which is the commonest way a sweep here goes quiet.
     */
    private const END = '(?![-\\w])';

    private const SHEETS = [
        'partials/menu-sheet.twig'     => 'data-ag-menu',
        'partials/quick-settings.twig' => 'data-ag-quick',
    ];

    /** @return array<string,string> path relative to templates/ → body, comments stripped */
    private function templates(): array
    {
        $root = realpath(self::ROOT);
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $rel  = ltrim(str_replace($root, '', $f->getPathname()), '/');
            $body = (string) file_get_contents($f->getPathname());
            // A Twig comment reaches nobody, so a trigger named in one is not a trigger.
            $out[$rel] = (string) preg_replace('/\{#.*?#\}/s', '', $body);
        }
        return $out;
    }

    /** Which templates include $path. */
    private function includedBy(string $path, array $all): array
    {
        $by = [];
        foreach ($all as $file => $body) {
            if (str_contains($body, "'" . $path . "'")) $by[] = $file;
        }
        return $by;
    }

    public function test_every_sheet_has_something_that_opens_it(): void
    {
        $all = $this->templates();
        $bad = [];

        foreach (self::SHEETS as $sheet => $trigger) {
            $this->assertArrayHasKey($sheet, $all, "$sheet has gone");

            // 1. Does anything anywhere carry the trigger?
            $triggers = [];
            foreach ($all as $file => $body) {
                if ($file === $sheet) continue;
                if (preg_match('/<(?:button|a)[^>]*\s' . preg_quote($trigger, '/') . self::END . '/', $body)) {
                    $triggers[] = $file;
                }
            }
            if (!$triggers) {
                $bad[] = "$sheet: nothing in any template carries [$trigger] — the sheet is "
                       . 'in the document and no control opens it';
                continue;
            }

            // 2. And is the thing carrying it ever put on a page? A partial nobody
            //    includes is the same dead end one level up.
            $mounted = false;
            foreach ($triggers as $t) {
                if (str_starts_with($t, 'pages/') || str_starts_with($t, 'layout/')) { $mounted = true; break; }
                if ($this->includedBy($t, $all) !== []) { $mounted = true; break; }
            }
            if (!$mounted) {
                $bad[] = "$sheet: [$trigger] exists only in " . implode(', ', $triggers)
                       . ', which nothing includes';
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_a_layout_never_mounts_a_sheet_it_cannot_open(): void
    {
        $all = $this->templates();
        $bad = [];

        // The two layouts, and what each can reach. `layout/shell.twig` leaves the app bar
        // to a `{% block %}` the page fills, so its reach includes the pages that extend
        // it; `layout/gates.twig` mounts its own chrome through `layout/nav.twig`.
        foreach (['layout/gates.twig', 'layout/shell.twig'] as $layout) {
            $reach = $this->reach($layout, $all);

            foreach (self::SHEETS as $sheet => $trigger) {
                if (!in_array($sheet, $reach, true)) continue;

                $found = false;
                foreach ($reach as $r) {
                    if (preg_match('/<(?:button|a)[^>]*\s' . preg_quote($trigger, '/') . self::END . '/', $all[$r] ?? '')) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $bad[] = "$layout mounts $sheet and nothing it draws carries [$trigger]";
                }
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    /**
     * Every template a layout draws: what it includes, transitively, plus — for a layout
     * whose chrome comes from a `{% block %}` — the pages that extend it and what THEY
     * include. Without that second half, `layout/shell.twig` reads as mounting a sheet
     * with no trigger, because the trigger arrives from the page.
     *
     * @return list<string>
     */
    private function reach(string $layout, array $all): array
    {
        $seen = [];
        $walk = function (string $file) use (&$walk, &$seen, $all): void {
            if (isset($seen[$file]) || !isset($all[$file])) return;
            $seen[$file] = true;
            preg_match_all("/\{%-? *(?:include|embed) '([^']+)'/", $all[$file], $m);
            foreach ($m[1] as $inc) $walk($inc);
        };

        $walk($layout);

        foreach ($all as $file => $body) {
            if (!preg_match("/\{%-? *extends '" . preg_quote($layout, '/') . "'/", $body)) continue;
            $walk($file);
        }

        return array_keys($seen);
    }
}
