<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\Presence;
use AfricaGates\Admin\Support\ConsoleShell;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The console moves between pages without reloading (public/assets/js/console-nav.js).
 *
 * The swap is only safe because of one rule: a page's own script takes part only when it
 * is marked `data-nav="rerun"`, and a script may only say that while it binds nothing that
 * would outlive its page. A keyboard shortcut left on the document after its page has gone
 * is the failure that matters — on the review list, A approves the open nomination, and a
 * stale listener pressed on the next page would approve one from the last. Unmarked, a
 * page loads the ordinary way, which is slower and never wrong; so the sweep below is the
 * whole of the guarantee, and it has to be seen to fail.
 */
final class ConsoleNavTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** What may not appear in a script that runs again on every visit to its page. */
    private static function rerunFaults(string $js): array
    {
        $out = [];
        // A binding on the document or the window survives the swap. The one allowed is the
        // teardown itself, which removes the rest when its page goes.
        if (preg_match_all('/\b(document|window)\.addEventListener\(\s*([\'"])([^\'"]+)\2/', $js, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) if ($x[3] !== 'cn:unload') $out[] = "{$x[1]}.addEventListener('{$x[3]}') outlives the page — bind it through a teardown on cn:unload";
        }
        if (preg_match('/\b(document|window)\.addEventListener\(\s*[^\'"\s]/', $js)) $out[] = 'a document/window listener with a computed type';
        // #cnMain is never replaced — only its contents — so a listener on it is as permanent
        // as one on the document, and piles up one more copy per visit.
        if (preg_match('/(getElementById\(\s*[\'"]cnMain[\'"]\s*\)|querySelector\(\s*[\'"]\.cn-main[\'"]\s*\))\s*\.addEventListener/', $js)) $out[] = 'a listener on #cnMain, which outlives every page swapped into it';
        if (substr_count($js, 'setInterval(') > substr_count($js, 'clearInterval(')) $out[] = 'a setInterval nothing clears';
        // An unload prompt assigned as a property cannot be taken back by a teardown. (One added
        // through cnPage.on is removed with its page, and the listener rule above catches the
        // direct form.)
        if (preg_match('/\bonbeforeunload\s*=/', $js)) $out[] = 'an unload prompt assigned to window.onbeforeunload';
        // A classic script's top-level const/let/class throws "already declared" the second time.
        if (preg_match('/^(const|let|class)\s/m', $js)) $out[] = 'a top-level const/let/class, which throws when the script runs a second time';
        return $out;
    }

    /** @return array<string,string> label → script source, for every script marked rerun */
    private function rerunScripts(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/templates/admin'));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.twig')) continue;
            $src = (string) file_get_contents($f->getPathname());
            $rel = substr($f->getPathname(), strlen(self::ROOT) + 1);
            preg_match_all('~<script\b([^>]*)>(.*?)</script>~s', $src, $m, PREG_SET_ORDER);
            foreach ($m as $i => $s) {
                if (!str_contains($s[1], 'data-nav="rerun"')) continue;
                if (preg_match("~asset\\('(/assets/js/[^']+)'\\)~", $s[1], $a)) {
                    $out[$rel . ' → ' . $a[1]] = (string) file_get_contents(self::ROOT . '/public' . $a[1]);
                } else {
                    $out[$rel . ' #' . $i] = $s[2];
                }
            }
        }
        return $out;
    }

    public function test_every_script_marked_rerun_binds_nothing_that_outlives_its_page(): void
    {
        $scripts = $this->rerunScripts();
        $this->assertGreaterThan(10, count($scripts), 'the sweep found almost nothing to read — it is looking in the wrong place');
        $bad = [];
        foreach ($scripts as $where => $js) {
            foreach (self::rerunFaults($js) as $why) $bad[] = "$where: $why";
        }
        $this->assertSame([], $bad, "a script swapped in by console-nav.js would keep running after its page had gone");
    }

    public function test_the_sweep_names_each_thing_it_forbids(): void
    {
        // Seen to fail, on each shape — and seen to pass the shape it allows.
        $this->assertNotEmpty(self::rerunFaults("document.addEventListener('keydown', f);"));
        $this->assertNotEmpty(self::rerunFaults("window.addEventListener('resize', f);"));
        $this->assertNotEmpty(self::rerunFaults("setInterval(beat, 45000);"));
        $this->assertNotEmpty(self::rerunFaults("const TOKEN = 'x';"));
        $this->assertNotEmpty(self::rerunFaults("window.addEventListener('beforeunload', f);"));
        $this->assertNotEmpty(self::rerunFaults("window.onbeforeunload = f;"));
        $this->assertNotEmpty(self::rerunFaults("document.getElementById('cnMain').addEventListener('click', f);"));
        $this->assertSame([], self::rerunFaults("cnPage.on(document, 'keydown', f); cnPage.every(f, 1000); cnPage.leave(shut);"));
        $this->assertSame([], self::rerunFaults("document.addEventListener('cn:unload', function bye() {});\nfunction x(){ const y = 1; }\nvar t = setInterval(f, 1); clearInterval(t);"));
        // And the real review list's script, with its teardown removed, is caught.
        $rq = (string) file_get_contents(self::ROOT . '/public/assets/js/review-queue.js');
        $this->assertSame([], self::rerunFaults($rq));
        $this->assertNotEmpty(self::rerunFaults(str_replace("on(document, 'keydown',", "document.addEventListener('keydown',", $rq)),
            'the review list could bind its shortcuts to the document again without the sweep noticing');
    }

    public function test_every_script_the_shell_itself_draws_is_marked_shell(): void
    {
        // An unmarked shell script reads as a PAGE script on every page, so every page
        // would quietly fall back to a full load — the swap switched off, with nothing to
        // say so. The layout's body and the partials it includes are the shell.
        $layout = (string) file_get_contents(self::ROOT . '/templates/admin/layout.twig');
        $bodyAt = strpos($layout, '<body');
        $this->assertNotFalse($bodyAt);
        $shell = substr($layout, (int) $bodyAt);
        foreach (['admin/partials/ui.twig', 'admin/partials/richtext.twig', 'admin/partials/nav-icons.twig'] as $inc) {
            $this->assertStringContainsString("include '$inc'", $layout);
            $shell .= (string) file_get_contents(self::ROOT . '/templates/' . $inc);
        }
        // The page's own block is not the shell.
        $shell = (string) preg_replace('~\{% block (content|foot_scripts) %\}.*?\{% endblock %\}~s', '', $shell);
        preg_match_all('~<script\b[^>]*>~', $shell, $m);
        $this->assertNotEmpty($m[0]);
        $unmarked = array_values(array_filter($m[0], static fn (string $t): bool => !str_contains($t, 'data-nav="shell"')));
        $this->assertSame([], $unmarked, 'a shell script without data-nav="shell" turns the swap off on every page');

        // Loaded last, so everything else has had the chance to handle a click first.
        preg_match_all('~<script\b[^>]*src="\{\{ asset\(\'([^\']+)\'\) \}\}"~', $layout, $src);
        $this->assertSame('/assets/js/console-nav.js', end($src[1]));
    }

    public function test_the_teardown_exists_before_any_page_script_runs(): void
    {
        // Page scripts inside the content run DURING the parse on a full load, before any
        // deferred shell script — so cnPage has to be defined inline in the head.
        $layout = (string) file_get_contents(self::ROOT . '/templates/admin/layout.twig');
        $head = substr($layout, 0, (int) strpos($layout, '</head>'));
        $this->assertMatchesRegularExpression('~<script nonce="\{\{ csp_nonce \}\}" data-nav="shell">[^<]*window\.cnPage\s*=~s', $head);
        foreach (['on:', 'every:', 'leave:', "'cn:unload'"] as $part) $this->assertStringContainsString($part, $head);
        // And every page in the console now takes part: no unmarked page script is left.
        $unmarked = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/templates/admin'));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.twig') || $f->getFilename() === 'layout.twig') continue;
            // A Twig comment reaches nobody; "there is no <script>" in one is not a script.
            $src = (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($f->getPathname()));
            preg_match_all('~<script\b[^>]*>~', $src, $m);
            foreach ($m[0] as $tag) if (!preg_match('~data-nav="(rerun|shell)"|type="(application/(ld\+)?json|text/template)"~', $tag)) $unmarked[] = $f->getFilename() . ': ' . $tag;
        }
        $this->assertSame([], $unmarked, 'a console page with an unmarked script reloads the whole console every time it is opened or left');
    }

    public function test_navigation_listens_on_the_window_so_page_scripts_always_go_first(): void
    {
        // A page script swapped in after this file registers its document listeners LATER,
        // and listeners on one target run in the order they were added. On the window, the
        // navigation hears a click after every document listener whenever it was added.
        $js = (string) file_get_contents(self::ROOT . '/public/assets/js/console-nav.js');
        $this->assertMatchesRegularExpression("~window\\.addEventListener\\('click'~", $js);
        $this->assertMatchesRegularExpression("~window\\.addEventListener\\('submit'~", $js);
        $this->assertDoesNotMatchRegularExpression("~document\\.addEventListener\\('(click|submit)'~", $js);
    }

    public function test_a_page_fetched_ahead_of_a_click_is_not_a_visit(): void
    {
        $_SERVER['HTTP_X_CONSOLE_PREFETCH'] = '1';
        try {
            $this->assertTrue(ConsoleShell::prefetch());
            if (\AfricaGates\Support\SchemaHas::table('gates_admin_presence')) {
                Presence::touch(7, 'review', 3);
                $this->assertSame(0, DB::table('gates_admin_presence')->where('admin_id', 7)->count(),
                    'hovering over a link told colleagues this admin was viewing the page');
            }
        } finally {
            unset($_SERVER['HTTP_X_CONSOLE_PREFETCH']);
        }
        $this->assertFalse(ConsoleShell::prefetch());
        if (\AfricaGates\Support\SchemaHas::table('gates_admin_presence')) {
            Presence::touch(7, 'review', 3);
            $this->assertSame(1, DB::table('gates_admin_presence')->where('admin_id', 7)->count(), 'a real visit stopped being recorded');
        }
    }
}
