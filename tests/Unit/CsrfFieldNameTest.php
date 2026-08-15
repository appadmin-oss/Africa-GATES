<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every form's hidden CSRF field must be named what the middleware reads.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \AfricaGates\Middleware\CsrfMiddleware} reads exactly one body field:
 *
 *     $req->getHeaderLine('X-CSRF-Token') ?: $body['_token']
 *
 * Three admin templates shipped with `name="csrf_token"` instead. The token was
 * present, correct, and in the wrong box — so the middleware saw an empty string,
 * compared it against the session token, and rejected every POST with "CSRF
 * validation failed".
 *
 * The three were the admin ticket reply, the refund button, and the vote-delivery
 * repair: the entire set of admin tools built for the unminted-vote incident. All
 * of them looked finished. Every one was inert, and it surfaced only when somebody
 * tried to use one in production:
 *
 *     /admin/vote-delivery/deliver → {"success":false,"message":"CSRF validation failed."}
 *
 * ── WHY A TEST AND NOT CARE ──────────────────────────────────────────────────
 *
 * Same shape as the ?q= handover bug: two halves, each defensible alone. The
 * template writes a plausible field name; the middleware reads a plausible field
 * name. Nothing about editing either one shows you the other, and the failure is
 * invisible until a POST happens — which no page render, no linter and no unit
 * test of either half will ever do.
 *
 * So the pairing itself is asserted, with the expected name READ OUT OF THE
 * MIDDLEWARE rather than restated here. A copy in the test would keep passing
 * after somebody renamed the field.
 */
final class CsrfFieldNameTest extends TestCase
{
    /** The body field name the middleware actually looks for. */
    private function expectedField(): string
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Middleware/CsrfMiddleware.php');

        $this->assertSame(1, preg_match('~getParsedBody\(\)\)\[\'([a-z_]+)\'\]~', $src, $m),
            'Could not find the CSRF body field in CsrfMiddleware — if the way it reads the '
            . 'token changed, this test needs updating rather than deleting.');

        return $m[1];
    }

    public function test_the_middleware_reads_one_named_field(): void
    {
        $this->assertSame('_token', $this->expectedField(),
            'If this ever changes, every template below has to change with it.');
    }

    /**
     * No template may ship a hidden CSRF input under any other name.
     *
     * Deliberately a hard failure listing the offenders, because the alternative
     * is what happened: three finished-looking admin screens, all inert.
     */
    public function test_no_template_names_the_csrf_field_something_the_middleware_ignores(): void
    {
        $field = $this->expectedField();
        $root  = dirname(__DIR__, 2) . '/templates';
        $bad   = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($file->getPathname());
            $rel  = str_replace($root . '/', '', $file->getPathname());

            // Any hidden input whose VALUE is the csrf token — whatever it is named.
            preg_match_all('~<input[^>]*type="hidden"[^>]*>~', $body, $inputs);
            foreach ($inputs[0] as $tag) {
                if (!str_contains($tag, 'csrf_token }}') && !str_contains($tag, 'csrf_token}}')) continue;
                if (preg_match('~name="([^"]+)"~', $tag, $n) !== 1) {
                    $bad[] = "{$rel}: hidden CSRF input with no name at all";
                    continue;
                }
                if ($n[1] !== $field) {
                    $bad[] = "{$rel}: name=\"{$n[1]}\" — the middleware only reads \"{$field}\"";
                }
            }
        }

        $this->assertSame([], $bad,
            "These forms carry a correct token in a box nothing opens, so every POST they "
            . "make is rejected as a CSRF failure:\n  " . implode("\n  ", $bad));
    }

    /**
     * A `fetch` POST needs the token too, and forgetting it looks like nothing.
     *
     * ── WHY THIS WAS ADDED ───────────────────────────────────────────────────
     *
     * The test above scans for hidden INPUTS, so it only ever sees form posts. The
     * account-free ticket reply is a `fetch`; it shipped without a token, and the
     * button reported "CSRF validation failed" — the same inert-button failure this
     * whole file exists because of, reappearing in a new shape inside one change.
     *
     * The middleware accepts an `X-CSRF-Token` header or a `_token` body field, and
     * `/api/` is exempt from the same-origin rule. So a template that POSTs via
     * fetch must show one of those three things.
     *
     * Deliberately COARSE, and the first draft was too clever about it. It tried to
     * recognise the fetch target — `fetch('/api/…')` — and flagged three innocent
     * templates that build the URL in a variable (`fetch(API + '/cheer')`,
     * `post('/api/v1/support/reply')`). A guard with false positives gets weakened
     * or deleted, so the rule is now the crudest thing that still catches the real
     * mistake: a template that POSTs, mentions no `/api/` path anywhere, and carries
     * no token, cannot possibly be sending one.
     */
    /**
     * Pages that POST with no session at all, and therefore no token to carry.
     *
     * ── WHY THE DOOR IS ON THIS LIST, AND WHAT PAYS FOR IT ───────────────────
     *
     * `/door/<token>` is worked by volunteers on a link, with no login. A CSRF token would
     * come from a PHP session — and the door page is opened once and left up for the whole
     * evening, so a session that rotates or expires would start refusing scans mid-event, at a
     * gate, with a queue. That is a worse failure than the one CSRF prevents here, and CSRF
     * prevents very little: the 32-byte door token IS the credential, and anybody holding it
     * can call the endpoint directly.
     *
     * An entry here is a claim that the middleware genuinely exempts the path, so
     * {@see test_every_no_session_page_is_actually_exempt()} checks that rather than trusting
     * it — an unproven exemption is how a page ends up silently rejected in production.
     */
    private const NO_SESSION = ['pages/events/door.twig'];

    public function test_a_template_that_posts_by_fetch_carries_a_token(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $bad  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($file->getPathname());

            // Only files that actually issue a POST from JavaScript.
            if (!preg_match('~method\s*:\s*[\'"]POST[\'"]~i', $body)) continue;

            $hasToken = str_contains($body, 'X-CSRF-Token') || str_contains($body, '_token');
            $usesApi  = str_contains($body, '/api/');   // exempt namespace

            if (!$hasToken && !$usesApi && !in_array(
                    str_replace($root . '/', '', $file->getPathname()), self::NO_SESSION, true)) {
                $bad[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $bad,
            "These templates POST from JavaScript with no CSRF token and no /api/ path, "
            . "so every one of those requests is rejected and the button silently does "
            . "nothing:\n  " . implode("\n  ", $bad));
    }

    /**
     * An exemption claimed above must actually exist in the middleware.
     *
     * The list is a promise that a page is allowed to POST without a token. If the middleware
     * disagrees, the page is silently rejected in production and the test that should have
     * caught it is the very thing waving it through — so the claim is checked against the
     * real middleware, with a real request, rather than believed.
     */
    public function test_every_no_session_page_is_actually_exempt(): void
    {
        $mw = new \AfricaGates\Middleware\CsrfMiddleware();

        // The door, at a path shaped exactly as the route matches: 64 hex characters.
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/door/' . str_repeat('a', 64) . '/check');

        $passed = false;
        $handler = new class ($passed) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private bool &$hit) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
            {
                $this->hit = true;
                return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(200);
            }
        };

        $_SESSION['csrf_token'] = 'a-token-the-door-will-never-send';
        $mw($req, $handler);

        $this->assertTrue($passed,
            'The door claims a CSRF exemption it does not have — every scan at a live gate '
            . 'would be rejected with no visible reason.');
    }

    /** And the exemption is anchored, so a crafted path cannot widen it. */
    public function test_the_door_exemption_cannot_be_widened_by_a_crafted_path(): void
    {
        $mw = new \AfricaGates\Middleware\CsrfMiddleware();
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $_SESSION['csrf_token'] = 'real-token';

        foreach ([
            '/admin/settings?x=/door/' . str_repeat('a', 64) . '/check',
            '/door/' . str_repeat('a', 64) . '/check/../../admin/settings',
            '/door/' . str_repeat('a', 63) . '/check',      // one character short
            '/door/' . str_repeat('a', 64) . '/revoke',     // a verb it must not cover
        ] as $path) {
            $hit = false;
            $handler = new class ($hit) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private bool &$hit) {}
                public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
                {
                    $this->hit = true;
                    return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(200);
                }
            };
            $mw($factory->createServerRequest('POST', $path), $handler);
            $this->assertFalse($hit, 'CSRF was skipped for ' . $path);
        }
    }

    /**
     * The account-free ticket reply specifically, by name.
     *
     * The scan above is a net; this is the hook. That page is the only route on the
     * platform that accepts a write from somebody with no session at all, it POSTs
     * outside `/api/`, and it shipped without a token — so it is named here the same
     * way the three admin incident tools are, rather than trusted to a heuristic that
     * a later refactor could slip past.
     */
    public function test_the_account_free_ticket_reply_can_actually_post(): void
    {
        $body = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/support-ticket-link.twig');

        $this->assertStringContainsString('X-CSRF-Token', $body,
            'Without this header the reply is rejected, and the one group who cannot '
            . 'fall back to an account is the group left unable to answer.');
        $this->assertStringContainsString('meta[name="csrf-token"]', $body,
            'The token must come from the tag the layout already emits, not a copy.');
    }

    /**
     * And the admin screens for the incident specifically.
     *
     * Named one by one because these three are the tools somebody reaches for when
     * a supporter is owed money or votes, and an inert button there costs more than
     * an inert button anywhere else on the platform.
     */
    public function test_the_incident_tools_can_actually_post(): void
    {
        $field = $this->expectedField();

        foreach ([
            'admin/vote-delivery.twig' => 'deliver the votes somebody has paid for',
            'admin/refunds.twig'       => 'issue a refund',
            'admin/support/show.twig'  => 'reply to a ticket',
        ] as $tpl => $what) {
            $body = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $tpl);

            $this->assertStringContainsString('name="' . $field . '"', $body,
                "{$tpl} has no usable CSRF field, so you cannot {$what}.");
            $this->assertStringNotContainsString('name="csrf_token"', $body,
                "{$tpl} is back on the field name the middleware ignores.");
        }
    }
}
