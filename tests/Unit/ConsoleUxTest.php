<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\CookieRegistry;
use Tests\TestCase;

/**
 * How the admin console BEHAVES — the half the 4 Oct rebuild did not touch.
 *
 * The rebuild changed every page's look and none of its behaviour, so the console kept
 * 39 tables of which 4 could be searched and none sorted, a save that sent twice on a
 * second press, and — the one that matters — a list of nominees where "Winner" was a bare
 * button in every row: one stray tap crowned somebody, with nothing to say who or why.
 *
 * What is held here:
 *   · `console-ux.js` loads on every console page, AFTER `admin.js` (the confirm dialog and
 *     the viewer's read-only refusal must get the submit first, or a refused submit would
 *     still be marked as sending);
 *   · its one storage key is declared in the cookie registry;
 *   · every console action that CROWNS, PUBLISHES, ANNOUNCES or BREAKS A LINK asks first.
 *     AdminDestructiveConfirmTest holds the verbs that destroy; it was never asked about
 *     the ones that decide, and a crowned nominee or a sent campaign is no easier to take
 *     back than a deleted row.
 */
final class ConsoleUxTest extends TestCase
{
    /**
     * The last path segment of a console action that decides, publishes, announces or
     * breaks something already handed out. Matched on the ACTION — what the server will do
     * — for the reason AdminDestructiveConfirmTest gives: a label is a design decision.
     */
    private const CONSEQUENTIAL = ['winner', 'runner_up', 'publish', 'promote', 'rotate', 'send', 'close', 'reset'];

    /**
     * A form on one of those paths that is deliberately one press, and why. Uncomfortable to
     * add to on purpose: every entry is a judgement somebody has to be able to disagree with.
     *
     * @var array<string, string> "template|button text" => reason
     */
    private const ONE_PRESS = [
        'interviews/show.twig|It was held' =>
            'records the ordinary outcome of a sitting that happened; "Nobody came" beside it confirms',
    ];

    private static function root(): string { return dirname(__DIR__, 2); }

    public function test_the_behaviour_layer_loads_on_every_console_page_after_admin_js(): void
    {
        $layout = (string) file_get_contents(self::root() . '/templates/admin/layout.twig');
        $a = strpos($layout, "/assets/js/admin.js");
        $u = strpos($layout, "/assets/js/console-ux.js");
        $this->assertNotFalse($u, 'console-ux.js is not loaded by the console layout');
        $this->assertGreaterThan($a, $u, 'console-ux.js must come after admin.js, whose submit handlers decide first');
        $this->assertFileExists(self::root() . '/public/assets/js/console-ux.js');
    }

    public function test_its_storage_key_is_declared(): void
    {
        $js = (string) file_get_contents(self::root() . '/public/assets/js/console-ux.js');
        preg_match_all("/(?:var|const|let)\\s+SCROLL\\s*=\\s*'([^']+)'/", $js, $m);
        $this->assertSame(['cn-ux:scroll'], $m[1]);
        $keys = array_column(CookieRegistry::storage(), 'key');
        $this->assertContains('cn-ux:scroll', $keys);
    }

    /** @return list<array{file:string,line:int,action:string,button:string,asks:bool}> */
    private static function consequentialForms(): array
    {
        $out = [];
        $base = self::root() . '/templates/admin/';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.twig')) continue;
            $src = (string) file_get_contents($f->getPathname());
            if (!preg_match_all('~<form\b[^>]*method="post"[^>]*>.*?</form>~is', $src, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[0] as [$form, $at]) {
                if (!preg_match('~action="([^"]+)"~', $form, $a)) continue;
                $path = rtrim((string) preg_replace('~\{\{.*?\}\}~', 'X', $a[1]), '/');
                $last = substr($path, (int) strrpos($path, '/') + 1);
                if (!in_array($last, self::CONSEQUENTIAL, true)) continue;
                preg_match('~<button\b[^>]*>(.*?)</button>~is', $form, $b);
                $out[] = [
                    'file'   => substr($f->getPathname(), strlen($base)),
                    'line'   => substr_count(substr($src, 0, $at), "\n") + 1,
                    'action' => $a[1],
                    'button' => trim((string) preg_replace('~\s+~', ' ', strip_tags($b[1] ?? ''))),
                    // data-confirm (the confirm-with-reason dialog), or the house two-press
                    // pattern (`x-data="{ sure: false }"`) that release-seat and settle use.
                    'asks'   => str_contains($form, 'data-confirm') || str_contains($form, '{ sure: false }'),
                ];
            }
        }
        return $out;
    }

    public function test_every_action_that_crowns_publishes_or_announces_asks_first(): void
    {
        $found = self::consequentialForms();
        $this->assertGreaterThanOrEqual(15, count($found), 'the sweep found too few forms to be reading the templates');
        $missing = [];
        foreach ($found as $f) {
            if ($f['asks'] || isset(self::ONE_PRESS[$f['file'] . '|' . $f['button']])) continue;
            $missing[] = $f['file'] . ':' . $f['line'] . '  ' . $f['action'] . '  [' . $f['button'] . ']';
        }
        $this->assertSame([], $missing, "these act at once, on one press:\n  " . implode("\n  ", $missing));
    }

    /** The exemption list may only name forms that exist — a stale entry excuses nothing it can see. */
    public function test_every_one_press_exemption_still_exists(): void
    {
        $seen = array_map(static fn ($f) => $f['file'] . '|' . $f['button'], self::consequentialForms());
        foreach (array_keys(self::ONE_PRESS) as $k) $this->assertContains($k, $seen, 'stale exemption: ' . $k);
    }

    /** Crowning from the nominee list was the one-tap version of this; it is held by name too. */
    public function test_crowning_from_the_nominee_list_confirms_with_a_reason(): void
    {
        $t = (string) file_get_contents(self::root() . '/templates/admin/nominees/index.twig');
        foreach (['/winner"', '/runner_up"'] as $p) {
            $this->assertMatchesRegularExpression('~action="/admin/nominees/\{\{ r\.id \}\}' . preg_quote($p, '~') . '[^>]*>.*?data-confirm="[^"]{40,}"~s', $t, $p);
        }
    }

    /** A rule only stops anything if it can be seen failing: plant a bare crowning form and watch it named. */
    public function test_the_sweep_names_a_planted_one_press_crowning(): void
    {
        $dir = self::root() . '/templates/admin/zz-planted';
        @mkdir($dir);
        file_put_contents($dir . '/planted.twig', '<form method="post" action="/admin/nominees/{{ id }}/winner"><button type="submit">Winner</button></form>');
        try {
            $hit = array_values(array_filter(self::consequentialForms(), static fn ($f) => str_starts_with($f['file'], 'zz-planted/')));
            $this->assertCount(1, $hit);
            $this->assertFalse($hit[0]['asks']);
        } finally {
            @unlink($dir . '/planted.twig');
            @rmdir($dir);
        }
    }
}
