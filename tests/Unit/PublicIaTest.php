<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * IS EVERY PUBLIC PAGE REACHABLE WITHOUT TYPING A URL?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ADMIN CONSOLE HAS BEEN ASKED THIS FOR MONTHS. THE PUBLIC SITE NEVER WAS.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `AdminIaTest::test_every_admin_page_is_reachable_without_typing_a_url` guards the
 * OPERATOR console — a few dozen screens used by staff who can be told where things are.
 * The public site has 139 static pages, four items in its navigation, and paying
 * customers, and nothing has ever checked that any of them can be found.
 *
 * What that cost, measured on the day this was written: **the partner organisation's own
 * console was reachable only from pages a partner lands on immediately after an action** —
 * the application success page, a stand offer, the member dashboard. An organisation that
 * closed the tab had to find an old email. That is the paying tenant's front door.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE EXCLUSIONS ARE KINDS WITH REASONS, NEVER A LIST OF PAGES NOBODY LINKED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is the rule `AdminIaTest` records and it is the whole difference between a sweep
 * and a snapshot. A list of unlinked pages grows by one every time somebody adds an
 * unlinked page, and within a year it IS the answer rather than a record of exceptions.
 *
 * So a page is out of scope only if it belongs to a KIND that cannot be navigated to:
 *
 *   · AN ALIAS. `/faq`, `/login`, `/merch` exist to be TYPED and redirect to the real
 *     page. Linking them would be linking a redirect. Read from the one `$aliases` table
 *     in `routes.php`, the same way `AliasRedirectTest` reads it.
 *   · A GATEWAY CALLBACK. `/donate/callback`, `/gift/redirect` are called by Paystack and
 *     by nobody else.
 *   · AN OUTCOME. `/nominate/success` is reached by completing the thing, and a link to a
 *     success page from anywhere else is a link to a lie.
 *   · AN AUTHENTICATION STEP. `/account/verify`, `/account/reset`. Several of these are
 *     deliberately unlinked: `account/login.twig` documents a refusal to advertise the
 *     admin, judge and organisation sign-ins from a shared public page, because separate
 *     trust domains are what stop one of them being a target. That reasoning stands.
 *   · A MACHINE FORMAT. `.csv`, `.md`, `.txt`, `.json`, `.png`, `.ics`, `.xml`.
 *   · A PARAMETERISED ROUTE. `/results/{id}` needs a real id; the LIST that links it is
 *     what this test checks instead.
 *   · A SEPARATE CONSOLE. `/admin`, `/judge`, `/api`, `/__setup`, `/__cron`, the door
 *     scanner, the short-link prefix.
 *
 * Everything else must be linked from a shipped template. A page nobody links is a page
 * that exists only for whoever remembers the URL.
 */
final class PublicIaTest extends TestCase
{
    private const ROUTES = __DIR__ . '/../../src/routes.php';

    /**
     * Consoles and machinery that are not the public site.
     *
     * `/_dev` is the redesign's style page — every token and base component in every
     * state, for whoever is building the next phase. It answers 404 unless APP_ENV is
     * something other than production, so there is nothing on the public site that
     * could link to it and nothing on production to reach. A developer surface is the
     * same kind as the `/__setup` tools beside it, not a public page nobody linked.
     */
    private const NOT_PUBLIC = ['/api', '/admin', '/judge', '/__', '/_dev', '/m/', '/door', '/ping', '/email'];

    /** Reached by the gateway, by completing a flow, or by an authentication step. */
    private const UNNAVIGABLE = [
        '~/(callback|redirect)$~',       // a payment gateway calls these
        '~/success$~',                   // reached by completing the thing
        '~^/pay/~',                      // gateway return paths
        '~^/account/(verify|reset|forgot|logout)~',
        '~^/vote/verify~',
        '~^/(signin|register)$~',        // the member sign-in is linked as /account/login
        // A SIGN-IN FOR A SEPARATE TRUST DOMAIN. `/org` is the destination and is linked;
        // `/org/login` is where it sends somebody who is not signed in. Advertising the
        // form itself is what `account/login.twig` refuses, and for a good reason.
        '~^/org/(login|logout)$~',
        // A LEGACY GIVING PREFIX. `/donate` and `/gift` are 301s onto the canonical
        // `/giving` paths that `Support\\GivingUrl` mints — they exist for links printed
        // before the rename and are not pages. They are registered through a `$bounce`
        // closure rather than the `$aliases` table, so the alias parser cannot see them.
        '~^/(donate|gift)(/|$)~',
    ];

    /** Downloads and machine formats — content, not destinations. */
    private const MACHINE = '~\.(png|svg|txt|xml|json|ics|csv|md|webmanifest|pdf)$~';

    /** @return list<string> every GET path a human could be expected to reach */
    private function publicPages(): array
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        // Needed so `redirects()` below can actually dispatch. Added after the routes are
        // declared and before anything reads them: the collector is unaffected, so the
        // route table this method walks is the same one either way.
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        $aliases = $this->aliases();
        // A DATA ENDPOINT IS NOT A PAGE. `/activity/search` returns JSON to the script on
        // `/activity`; there is nothing to navigate to. Detected rather than listed — any
        // path a template fetches is one the page consumes rather than one a reader opens.
        $fetched = $this->fetched();
        $out     = [];

        // ── A DEEP LINK INTO A VIEW OF ANOTHER PAGE IS NOT A PAGE ────────────
        //
        // `/pulse/reels` and `/pulse` are the SAME controller action: Reels is a tab on
        // the pulse page, and the route exists so that tab state is shareable. The page
        // reaches it with its own control; nothing else should link it.
        //
        // Detected by comparing callables rather than listed, so it covers the next one
        // somebody writes. A route is a view of another when it shares an action with a
        // SHORTER path — the shorter one is the page, this one is a state of it.
        $byCallable = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (!in_array('GET', $r->getMethods(), true)) continue;
            $c = $r->getCallable();
            if (is_string($c)) $byCallable[$c][] = $r->getPattern();
        }
        $viewOf = [];
        foreach ($byCallable as $paths) {
            if (count($paths) < 2) continue;
            usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
            $page = array_shift($paths);
            foreach ($paths as $deep) {
                if (str_starts_with($deep, rtrim($page, '/') . '/')) $viewOf[$deep] = $page;
            }
        }

        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (!in_array('GET', $r->getMethods(), true)) continue;

            $p = $r->getPattern();
            if (isset($viewOf[$p])) continue;

            foreach (self::NOT_PUBLIC as $prefix) {
                if (str_starts_with($p, $prefix)) continue 2;
            }
            // A parameterised route needs a real id; the list that links it is what is
            // checked instead. `[/{page}]` optional segments are the same case.
            if (str_contains($p, '{') || str_contains($p, '[')) continue;
            if (preg_match(self::MACHINE, $p)) continue;
            if (isset($aliases[$p])) continue;
            if (isset($fetched[$p])) continue;

            foreach (self::UNNAVIGABLE as $re) {
                if (preg_match($re, $p)) continue 2;
            }

            // ── A PATH THAT IS PERMANENTLY SOMEWHERE ELSE IS NOT A PAGE ─────
            //
            // A retired address kept as a 301 has nothing to link TO: its whole job is to
            // hand somebody on to the page that replaced it, and linking it would cost
            // every reader a round trip while splitting the ranking signal across two
            // URLs. `/giving/apply` is the first — the organisation application is the
            // `?as=organisation` branch of `/account/register` now.
            //
            // ASKED, not listed. The `$aliases` table above is one way a redirect gets
            // declared and a hand-written route is another, so a test that knew only about
            // the table would need this exception added by hand every time — which is how
            // a list of kinds becomes a list of pages nobody linked, the thing this
            // class's own failure message forbids.
            //
            // ── AND 301 SPECIFICALLY, NEVER ANY 3xx ─────────────────────────
            //
            // The first cut of this asked "does it redirect", and the answer quietly
            // excused `/org`, `/community/new` and `/support/tickets` — three real pages
            // that bounce to a sign-in because this test holds no session. One of them is
            // the partner console, which the test directly below exists to keep reachable,
            // so the sweep would have gone silent on its own headline finding.
            //
            // The codes already carry the distinction and it is not a heuristic: 301 says
            // this address is not the page and never will be again, 302 says not right
            // now. A login bounce is the second. Only the first is out of scope here.
            if ($this->movedPermanently($app, $p)) continue;

            // ── A DESTROYED PAGE AWAITING ITS REBUILD IS NOT A PAGE ─────────
            //
            // On 3 Oct 2026 the old public pages were destroyed and their routes left
            // standing (docs/handoff/DESTROYED.md); each comes back when its phase
            // rebuilds it. Until then the route renders a template that is not in the
            // tree — there is no page to find, so asking whether it is linked asks about
            // nothing. ASKED, not listed: the handler's own source names the template, and
            // the route drops out of scope only while every template it names is missing.
            // The day the rebuild lands the template, this route is back in the sweep.
            if ($this->rendersOnlyDestroyedTemplates($r->getCallable())) continue;

            // ── A ROUTE THAT ANSWERS JSON IS A DATA ENDPOINT, WHOEVER CALLS IT ──
            //
            // `fetched()` above recognises a data endpoint by its CALLER, and that went
            // blind on 3 Oct 2026: `/activity/search` was fetched only by `ag-search.js`,
            // destroyed as an orphan, so the endpoint — live, and the server half of the
            // search palette the rebuild owes — came back into scope as "a public page
            // nobody linked". Nothing about the route changed; only who happened to call
            // it. So the route is ASKED what it is: a GET that answers
            // `application/json` has nothing to navigate to. Asked last, so only a path
            // every other kind has already let through is dispatched a second time.
            if ($this->answersJson($app, $p)) continue;

            $out[rtrim($p, '/') ?: '/'] = true;
        }

        ksort($out);

        return array_keys($out);
    }

    /**
     * Does this handler render page templates, every one of which is missing from the tree?
     *
     * Read from the handler's source (and the private methods it calls, one level down;
     * for a closure, what it captured, one level down),
     * because a destroyed page and a sign-in bounce both answer without ever reaching the
     * render, so dispatching cannot tell them apart. A handler naming no template at all
     * answers false: that is a redirect or data, and the checks above own it.
     */
    private function rendersOnlyDestroyedTemplates(mixed $callable): bool
    {
        $source = static function (\ReflectionFunctionAbstract $m): string {
            $lines = file((string) $m->getFileName());
            return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        };
        // A method's own source plus the private methods it calls, one level down.
        $method = static function (\ReflectionClass $cls, string $name) use ($source): string {
            $body = $source($cls->getMethod($name));
            if (preg_match_all('~\$this->([A-Za-z_]+)\(~', $body, $calls)) {
                foreach (array_unique($calls[1]) as $n) {
                    if ($cls->hasMethod($n)) $body .= $source($cls->getMethod($n));
                }
            }
            return $body;
        };

        if ($callable instanceof \Closure) {
            // ── A CLOSURE IS A HANDLER TOO, AND MOST PUBLIC PAGES ARE ONE ────
            //
            // 132 public GETs are closures — `/support`, `/philosophy`, the legal
            // documents — and several only delegate: `fn(...) => $legalRender(..., 'refunds')`
            // or `fn(...) => $challenges->index(...)`. Reading only class handlers left
            // every one of them in scope after its template was destroyed, and that went
            // unseen for as long as the orphaned `layout/footer.twig` was still in the
            // tree linking them: the sweep passed on a link from a file nothing renders.
            // So the closure's own source is read, and what it captured, one level down —
            // a captured closure's source, or the method it calls on a captured object.
            $fn   = new \ReflectionFunction($callable);
            $body = $source($fn);
            foreach ($fn->getClosureUsedVariables() as $var => $value) {
                if ($value instanceof \Closure) {
                    $body .= $source(new \ReflectionFunction($value));
                } elseif (is_object($value)
                    && preg_match_all('~\$' . preg_quote($var, '~') . '->([A-Za-z_]+)\(~', $body, $calls)) {
                    $cls = new \ReflectionClass($value);
                    foreach (array_unique($calls[1]) as $n) {
                        if ($cls->hasMethod($n)) $body .= $method($cls, $n);
                    }
                }
            }
        } else {
            if (is_string($callable) && str_contains($callable, ':')) $callable = explode(':', $callable, 2);
            if (!is_array($callable) || !is_string($callable[0]) || !class_exists($callable[0])) return false;

            $cls = new \ReflectionClass($callable[0]);
            if (!$cls->hasMethod($callable[1])) return false;
            $body = $method($cls, $callable[1]);
        }

        if (!preg_match_all("~['\"](pages/[A-Za-z0-9_/.-]+\\.twig)['\"]~", $body, $m)) return false;
        foreach (array_unique($m[1]) as $tpl) {
            if (is_file(dirname(__DIR__, 2) . '/templates/' . $tpl)) return false;
        }
        return true;
    }

    /**
     * Is this path retired — a 301 to whatever replaced it?
     *
     * A throw counts as NOT retired: a handler that needs a session or a real id blows up
     * here, and swallowing that as "redirects, so skip it" would quietly drop pages out of
     * the sweep — a clean pass over the half it read, which is the failure this file
     * already documents for a different sweep.
     */
    /**
     * Does this path answer with a JSON document? Asked of the router, never listed.
     *
     * A sweep for pages a reader can find must not demand a link to a data endpoint, and
     * "which template fetches it" is a property of the caller, not of the endpoint — it
     * disappears with the caller (see the call site).
     */
    private function answersJson(\Slim\App $app, string $path): bool
    {
        try {
            $res = $app->handle(
                (new ServerRequestFactory())->createServerRequest('GET', $path));
        } catch (\Throwable) {
            return false;
        }

        return str_starts_with(strtolower($res->getHeaderLine('Content-Type')), 'application/json');
    }

    private function movedPermanently(\Slim\App $app, string $path): bool
    {
        try {
            $res = $app->handle(
                (new ServerRequestFactory())->createServerRequest('GET', $path));
        } catch (\Throwable) {
            return false;
        }

        return $res->getStatusCode() === 301;
    }

    /**
     * The alias table, read from the one place it is declared.
     *
     * Same parse as `AliasRedirectTest`, deliberately: two tests disagreeing about what
     * counts as an alias is worse than either being loose.
     *
     * @return array<string,string>
     */
    private function aliases(): array
    {
        $src = (string) file_get_contents(self::ROUTES);
        if (!preg_match('/\$aliases = \[(.*?)\n        \];/s', $src, $m)) {
            self::fail('Could not find the $aliases table in src/routes.php');
        }
        preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $m[1], $rows, PREG_SET_ORDER);

        $out = [];
        foreach ($rows as $r) $out[$r[1]] = $r[2];

        return $out;
    }

    /**
     * Every path a template FETCHES rather than links.
     *
     * A data endpoint has nothing to navigate to. Read from the calls themselves so a new
     * one is covered the day it is written, instead of being added to a list later by
     * somebody who has just watched this test fail.
     *
     * @return array<string,true>
     */
    private function fetched(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        foreach ([$root . '/templates', $root . '/public/assets/js'] as $dir) {
            if (!is_dir($dir)) continue;

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                if (!preg_match('/\.(twig|js)$/', $f->getPathname())) continue;

                $body = (string) file_get_contents($f->getPathname());
                if (preg_match_all('~(?:fetch|axios\.\w+)\(\s*[\'"`](/[a-z0-9/_-]+)~i', $body, $m)) {
                    foreach ($m[1] as $h) $out[rtrim($h, '/') ?: '/'] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Strip everything a READER never sees, so a sweep cannot be satisfied by prose.
     *
     * This repository has paid twice for the shape: a comment explaining a removal that
     * quoted the retired label tripped the sweep documenting it, and — here, while this
     * class was being written — a CSS comment on `/partner` saying in as many words
     * "it points at /org" made the partner-page assertion below pass with the link
     * itself deleted. A Twig comment reaches nobody and a CSS comment reaches nobody;
     * neither is navigation.
     */
    private function visible(string $body): string
    {
        $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);

        return (string) preg_replace('~/\*.*?\*/~s', '', $body);
    }

    /** Every path any shipped public template links. */
    private function linked(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates'));

        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getPathname(), '.twig')) continue;

            $rel = str_replace($root . '/', '', $f->getPathname());
            // A link from the admin console or the judge panel does not make a page
            // findable by the public.
            if (str_contains($rel, 'templates/admin/') || str_contains($rel, 'templates/judge/')) continue;

            $body = $this->visible((string) file_get_contents($f->getPathname()));

            if (preg_match_all('~href="(/[a-z0-9/_-]*)~i', $body, $m)) {
                foreach ($m[1] as $h) $out[rtrim($h, '/') ?: '/'] = true;
            }
        }

        return $out;
    }

    public function test_every_public_page_is_reachable_without_typing_a_url(): void
    {
        $linked  = $this->linked();
        $orphans = [];

        foreach ($this->publicPages() as $p) {
            if (!isset($linked[$p])) $orphans[] = $p;
        }

        $this->assertSame([], $orphans,
            "these public pages are reachable only by somebody who already knows the URL:\n  "
          . implode("\n  ", $orphans)
          . "\n\nLink them from a template, or — if one belongs to a KIND that cannot be "
          . "navigated to — add the kind, with its reason, to this class. Never add the "
          . "page itself: a list of pages nobody linked becomes the answer rather than a "
          . "record of exceptions.");
    }

    public function test_the_sweep_would_notice_an_orphan(): void
    {
        // Proving it fails before trusting it passing. Every sweep in this repository
        // exists because something shipped, and several passed over the thing they were
        // written for.
        $linked = $this->linked();

        $this->assertNotSame([], $linked, 'the link scan found nothing at all');
        $this->assertArrayHasKey('/help', $linked, 'the scan is not seeing ordinary links');
        $this->assertArrayNotHasKey('/definitely-not-a-real-page', $linked);
    }
}
