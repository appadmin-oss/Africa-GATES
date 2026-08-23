<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every flash message a controller writes is one a template renders.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `$_SESSION[$r['ok'] ? 'flash' : 'flash_error']` is the shape almost every controller on
 * this platform uses to report the outcome of an action. Sixty-odd sites used it.
 *
 * Nothing read `flash`. Not one template. And the one-shot consume in the container
 * cleared `flash_ok`, `flash_error` and `flash_notice` and never `flash`, so the message
 * was invisible AND sat in the session for the rest of the login.
 *
 * The result is not a cosmetic problem, it is indistinguishable from a broken feature: you
 * add a stand from the catalogue, the page reloads, nothing appears. That is how it was
 * reported — "the vendor stand creation is completely bugged" — and it was also true of
 * building the sandbox, sending questionnaire invitations and saving a payout account.
 *
 * A key with no reader is silent by construction, so no amount of manual testing of the
 * happy path finds it: the action really did work. This test is the only kind of check
 * that catches it, and it is cheap: read the writers, read the readers, compare.
 */
final class FlashKeyTest extends TestCase
{
    /** Root of the repository. */
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> every *.php under a directory */
    private function php(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }
        return $out;
    }

    /**
     * Which session keys does the container hand to Twig, and which does it clear?
     *
     * Read out of the file rather than out of a built container: the point is what the
     * source says, and building the container needs a database.
     *
     * @return array{read:list<string>, cleared:list<string>}
     */
    private function containerKeys(): array
    {
        $src = (string) file_get_contents($this->root() . '/config/container.php');

        // Only the RIGHT-HAND SIDE of a Twig global declaration counts as "read". Scanning
        // the whole file for `$_SESSION['flash…']` would have passed while the bug was live,
        // because the unset() line mentions every key it clears — including, now, ones it
        // would otherwise be clearing without anybody rendering them. The declaration is the
        // only place a session key becomes visible to a template.
        preg_match_all(
            "~'(?:flash[a-z_]*)'\s*=>\s*((?:[^,\n]|\n\s*\?\?)*)~",
            $src, $decls
        );
        $read = [];
        foreach ($decls[1] as $expr) {
            preg_match_all("~\\\$_SESSION\['(flash[a-z_]*)'\]~", $expr, $k);
            foreach ($k[1] as $key) $read[] = $key;
        }

        // The unset() line — the consume.
        $cleared = [];
        if (preg_match('~unset\(([^;]*flash[^;]*)\);~s', $src, $m)) {
            preg_match_all("~'(flash[a-z_]*)'~", $m[1], $c);
            $cleared = $c[1];
        }

        return [
            'read'    => array_values(array_unique($read)),
            'cleared' => array_values(array_unique($cleared)),
        ];
    }

    /**
     * Which flash keys does application code WRITE?
     *
     * Both shapes: the plain assignment and the ternary that picks a key. The ternary form
     * is how the bug hid — grepping for `$_SESSION['flash']` misses
     * `$_SESSION[$ok ? 'flash' : 'flash_error']`, which is most of the call sites.
     *
     * @return array<string,list<string>> key → the files that write it
     */
    private function written(): array
    {
        $out = [];
        foreach ([$this->root() . '/src'] as $dir) {
            foreach ($this->php($dir) as $file) {
                $src = (string) file_get_contents($file);
                // $_SESSION['x'] = …  and  $_SESSION[<expr>'x'<expr>] = …
                //
                // NON-GREEDY and NOT `[^\]]*`. The ternary form is
                // `$_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = …`, whose subscript
                // contains a `]` of its own — a character-class scan stops at it and
                // matches nothing, which is exactly how a scan can look like it is working
                // while missing most of the call sites. `\]\s*=[^=]` anchors on the
                // assignment so `== ` and `=>` are not mistaken for one.
                preg_match_all('~\\$_SESSION\[(.*?)\]\s*=[^=>]~', $src, $m);
                foreach ($m[1] as $subscript) {
                    preg_match_all("~'(flash[a-z_]*)'~", $subscript, $keys);
                    foreach ($keys[1] as $k) {
                        $out[$k][] = str_replace($this->root() . '/', '', $file);
                    }
                }
            }
        }
        foreach ($out as $k => $files) $out[$k] = array_values(array_unique($files));
        ksort($out);

        return $out;
    }

    public function test_every_flash_key_a_controller_writes_is_one_the_container_reads(): void
    {
        $read    = $this->containerKeys()['read'];
        $written = $this->written();

        $this->assertNotSame([], $written, 'the scan found nothing, so it is not scanning');

        $orphans = [];
        foreach ($written as $key => $files) {
            if (!in_array($key, $read, true)) {
                $orphans[] = $key . ' (written in ' . implode(', ', array_slice($files, 0, 3))
                           . (count($files) > 3 ? ' +' . (count($files) - 3) . ' more' : '') . ')';
            }
        }

        $this->assertSame([], $orphans,
            "These session flash keys are written and never rendered, so the messages are "
            . "invisible and the action looks like it failed:\n  " . implode("\n  ", $orphans));
    }

    public function test_every_key_the_container_reads_is_also_cleared_after_one_render(): void
    {
        // A flash that is not consumed is worse than one that is not shown: it reappears on
        // the next page, and then the page after that, attached to an action the reader has
        // long since forgotten. `flash` was read by nothing and cleared by nothing, so it
        // accumulated silently for the whole login.
        ['read' => $read, 'cleared' => $cleared] = $this->containerKeys();

        $this->assertNotSame([], $read);
        foreach ($read as $key) {
            $this->assertContains($key, $cleared, "'{$key}' is rendered but never consumed");
        }
    }

    public function test_the_success_alias_actually_resolves_to_the_rendered_variable(): void
    {
        // The fix is an alias — `flash_ok ?? flash` — rather than sixty renames, because the
        // intent at every call site is unambiguous and a rename across sixty files is sixty
        // chances to miss one. This asserts the alias is present and in that precedence: an
        // explicit flash_ok must win over a legacy flash if both are somehow set.
        $src = (string) file_get_contents($this->root() . '/config/container.php');

        $this->assertMatchesRegularExpression(
            "~'flash_ok'\s*=>\s*\\\$_SESSION\['flash_ok'\]\s*\?\?\s*\\\$_SESSION\['flash'\]~",
            $src
        );
    }

    public function test_the_admin_layout_renders_all_three_kinds(): void
    {
        $layout = (string) file_get_contents($this->root() . '/templates/admin/layout.twig');

        foreach (['flash_ok', 'flash_error', 'flash_notice'] as $var) {
            $this->assertStringContainsString('{% if ' . $var . ' %}', $layout,
                'an admin action reporting ' . $var . ' would report it to nobody');
        }
    }
}
