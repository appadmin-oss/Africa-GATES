<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every template the code names exists and compiles, and every admin action has a handler.
 *
 * ── WHY ──────────────────────────────────────────────────────────────────────
 *
 * A route rendering a template that is not there answers 500, and nothing else notices: the
 * route exists, the controller runs, the suite has no reason to render that page. Fifteen
 * page templates are in that state today, all of them removed in the 3 Oct redesign and
 * waiting for their rebuild (docs/handoff/DESTROYED.md). The architecture plan's own list of
 * them (§2, fault 1) had drifted: three on it had been rebuilt, three missing were not on it,
 * one of them the page where a nominee confirms their nomination.
 *
 * So the list is held here, as data that may only SHRINK. A template that goes missing and
 * is not on it fails by name; a page that is rebuilt and left on it fails too, so the list
 * cannot go on describing something that is no longer true.
 *
 * The admin half: `data-ag-do` is how the admin console wires a control without inline
 * script (its CSP has no 'unsafe-inline'). An action nobody handles is a button or field that
 * does nothing, with no error. `select-all` shipped that way on the webhook secret and the
 * interview link.
 */
final class TemplatesExistTest extends TestCase
{
    /**
     * Removed in the 3 Oct redesign, still routed, waiting for their rebuild. May only shrink.
     * Each must also be recorded in docs/handoff/DESTROYED.md.
     */
    private const AWAITING_REBUILD = [
        'pages/claim-dispute.twig', 'pages/form.twig', 'pages/gated-form.twig', 'pages/honour.twig',
        'pages/interview.twig', 'pages/nominee-claim.twig', 'pages/nominee-confirm-done.twig',
        'pages/nominee-confirm.twig', 'pages/registry/register.twig',
        'pages/shop/alert-stopped.twig', 'pages/shop/order.twig', 'pages/stands/apply.twig',
        'pages/stands/call.twig', 'pages/stands/offer.twig',
    ];

    /**
     * Never a page of its own: the architecture plan's fault 7 (§2) — Pulse's "load more"
     * asks for `part=items` while the controller checks `fragment=1` and renders this. May
     * only shrink, like the list above, and goes when that fault is fixed.
     */
    private const KNOWN_FAULTS = ['pages/pulse/_cards.twig'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,true> every template under templates/, by its Twig name */
    private static function templates(): array
    {
        $out = [];
        $base = self::root() . '/templates/';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $f) {
            if ($f->getExtension() === 'twig') $out[substr($f->getPathname(), strlen($base))] = true;
        }
        return $out;
    }

    /**
     * Template names written as string literals in PHP and in other templates.
     *
     * @return array<string,list<string>> name => files that name it
     */
    private static function referenced(): array
    {
        $out = [];
        $dirs = ['src', 'config', 'templates'];
        foreach ($dirs as $d) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $d));
            foreach ($it as $f) {
                if (!in_array($f->getExtension(), ['php', 'twig'], true)) continue;
                $src = (string) file_get_contents($f->getPathname());
                if (!preg_match_all('~[\'"]((?:templates/)?[a-z0-9_/.-]+\.twig)[\'"]~', $src, $m)) continue;
                foreach ($m[1] as $name) {
                    $name = preg_replace('~^templates/~', '', $name);
                    $out[$name][] = substr($f->getPathname(), strlen(self::root()) + 1);
                }
            }
        }
        return $out;
    }

    public function test_every_template_the_code_names_exists_or_is_awaiting_its_rebuild(): void
    {
        $have = self::templates();
        $missing = [];
        foreach (self::referenced() as $name => $files) {
            if (isset($have[$name]) || in_array($name, self::AWAITING_REBUILD, true)
                || in_array($name, self::KNOWN_FAULTS, true)) continue;
            $missing[] = $name . ' (named in ' . implode(', ', array_unique($files)) . ')';
        }
        $this->assertSame([], $missing, 'a template the code renders or includes does not exist');
    }

    public function test_the_rebuild_list_only_holds_what_is_still_missing_and_recorded(): void
    {
        $have = self::templates();
        $destroyed = (string) file_get_contents(self::root() . '/docs/handoff/DESTROYED.md');
        foreach (self::AWAITING_REBUILD as $name) {
            $this->assertArrayNotHasKey($name, $have, "$name has been rebuilt: take it off AWAITING_REBUILD");
            $this->assertStringContainsString('templates/' . $name, $destroyed, "$name is not recorded as destroyed");
        }
        foreach (self::KNOWN_FAULTS as $name) {
            $this->assertArrayNotHasKey($name, $have, "$name exists now: take it off KNOWN_FAULTS");
        }
    }

    public function test_every_template_compiles_with_the_apps_twig(): void
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require self::root() . '/config/container.php');
        $env = $builder->build()->get(\Slim\Views\Twig::class)->getEnvironment();
        $bad = [];
        foreach (array_keys(self::templates()) as $name) {
            try {
                $env->compileSource($env->getLoader()->getSourceContext($name));
            } catch (\Throwable $e) {
                $bad[] = $name . ': ' . $e->getMessage();
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_every_admin_action_has_a_handler(): void
    {
        $js = '';
        foreach (glob(self::root() . '/public/assets/js/*.js') ?: [] as $f) {
            // Comments out first: a comment naming an action (this file's own fix has one)
            // must not count as the code that handles it.
            $code = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($f));
            $js  .= preg_replace('~(^|\s)//[^\n]*~', '$1', (string) $code);
        }
        $dead = [];
        foreach (array_keys(self::templates()) as $name) {
            $src = (string) file_get_contents(self::root() . '/templates/' . $name);
            if (!preg_match_all('~data-ag-do="([a-z0-9_-]+)"~', $src, $m)) continue;
            foreach (array_unique($m[1]) as $action) {
                // Handled by a shared script, or by the page's own nonced script.
                $quoted = '/[\'"]' . preg_quote($action, '/') . '[\'"]/';
                $selector = 'data-ag-do="' . $action . '"]';
                if (preg_match($quoted, $js) || str_contains($js, $selector)) continue;
                if (str_contains($src, '<script nonce') && str_contains($src, $selector)) continue;
                $dead[] = "$action in $name";
            }
        }
        $this->assertSame([], array_values(array_unique($dead)), 'a data-ag-do action nothing handles');
    }
}
