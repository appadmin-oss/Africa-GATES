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
