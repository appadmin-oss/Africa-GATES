<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\DemoSeeder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Build and remove the rehearsal sandbox.
 *
 * ── WHY THIS IS A BUTTON AND NOT ONLY A CONSOLE COMMAND ──────────────────────
 *
 * `bin/console demo:seed` exists and is the right tool where there is a shell. This
 * platform deploys to cPanel where there frequently is not — the same reason
 * {@see \AfricaGates\Services\LegalSeeder} is reachable at a token-gated URL as well as
 * from the CLI. A capability that only exists behind SSH does not exist for the person
 * running the awards.
 *
 * ── SUPERADMIN, AND CONFIRMED ────────────────────────────────────────────────
 *
 * It creates and destroys data. The build is destructive of its own previous run — pressing
 * it twice is how you get a clean rehearsal, so it tears down first rather than
 * half-updating a nominee somebody has already scored — and the teardown removes a
 * programme and everything under it. Neither should be one click from a mis-aimed cursor.
 */
final class SandboxController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }

    public function index(Request $req, Response $res): Response
    {
        return $this->render($res);
    }

    public function build(Request $req, Response $res): Response
    {
        $r = DemoSeeder::seed($this->adminId());

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit->record($this->adminId(), 'sandbox.build', 'programme',
            (int) ($r['programme_id'] ?? 0));

        return $this->render($res);
    }

    public function remove(Request $req, Response $res): Response
    {
        $r = DemoSeeder::purge();

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit->record($this->adminId(), 'sandbox.remove', 'programme', 0);

        return $this->render($res);
    }

    /**
     * Rendered rather than redirected, so the freshly-built links are on the page that
     * reports the build. A redirect would show the same links a second later with no
     * connection to the act, and the links are most of what makes this useful.
     */
    private function render(Response $res): Response
    {
        return $this->view->render($res, 'admin/sandbox/index.twig', [
            'page_title'  => 'Test data — Admin',
            'admin_page'  => 'sandbox',
            'exists'      => DemoSeeder::exists(),
            'current'     => DemoSeeder::current(),
            'prefix'      => DemoSeeder::PREFIX,
            'slug'        => DemoSeeder::PROGRAMME_SLUG,
        ]);
    }
}
