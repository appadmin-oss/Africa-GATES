<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
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

    /** Consoles and machinery that are not the public site. */
    private const NOT_PUBLIC = ['/api', '/admin', '/judge', '/__', '/m/', '/door', '/ping', '/email'];

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

            $out[rtrim($p, '/') ?: '/'] = true;
        }

        ksort($out);

        return array_keys($out);
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

    public function test_the_partner_console_is_reachable_from_the_public_site(): void
    {
        // Named on its own, because it is the finding this test was written for and the
        // one with a customer on the other end of it.
        //
        // AND THE ASSERTION THAT MATTERS IS *WHERE*, NOT *WHETHER*. `/org` was already
        // linked from four templates before this test existed — org-apply, the stand
        // application, the stand offer and the member dashboard — every one of them a
        // page a partner lands on in the minute AFTER an action they just took. So
        // "is it linked anywhere" was true the whole time the console was unreachable in
        // practice: close the tab, come back on Monday, and the way back in was an old
        // email. A front door is a page somebody can arrive at cold.
        //
        // The two that qualify are the footer, which is on every page of the site, and
        // `/partner`, which is the page organisations are sent to before they are
        // anything. Both, not either: the footer alone is a link nobody reads on the one
        // page written for this reader, and `/partner` alone is unreachable from the rest
        // of the site.
        //
        // NOT by putting organisation sign-in on the member sign-in page:
        // `account/login.twig` refuses that deliberately, because separate trust domains
        // on separate routes are what stop one of them becoming a target. A findable
        // public DESTINATION is a different thing from a shared login form.
        $root = dirname(__DIR__, 2);

        foreach ([
            'templates/layout/footer.twig'  => 'the site footer, which is the one place on every page a partner can get back from',
            'templates/pages/partner.twig'  => 'the page that tells organisations about the platform, where an organisation who already joined needs a way back in',
        ] as $rel => $why) {
            $body = $this->visible((string) file_get_contents($root . '/' . $rel));

            $this->assertMatchesRegularExpression('~href="/org"~', $body,
                $rel . ' does not link the organisation console — ' . $why . ".\n\n"
              . 'Note this asks for an `href`, and asks it of the file with its comments '
              . 'removed: a sentence in a comment saying the page points at /org is not a '
              . 'link, and passed this assertion once.');
        }
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
