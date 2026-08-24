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
     * Mint a sign-in code for the sandbox judge and show it on the page.
     *
     * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────
     *
     * Judges sign in with a six-digit code EMAILED to them, and the sandbox judge is at
     * `demo.invalid` — a domain RFC 2606 reserves precisely so that no mail server will ever
     * accept it. So the demo judge could not sign in at all, and every screen in the judge
     * portal was unreachable in the one environment built for trying them. The documented
     * workaround was to open a log file on a host with no shell.
     *
     * Nothing is bypassed: {@see DemoSeeder::judgeSignInCode()} writes the same OTP row the
     * real request writes, with the same fifteen-minute expiry, and the operator types it
     * into the real form. The code simply does not go to a mailbox that cannot exist. The
     * address is fixed inside the seeder rather than passed in — a parameter here is the
     * thing that would eventually be pointed at a real judge.
     */
    public function judgeCode(Request $req, Response $res): Response
    {
        $r = DemoSeeder::judgeSignInCode();

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) $_SESSION['sandbox_judge_code'] = (string) $r['code'];

        // Audited because it creates a credential. The code itself is NOT recorded — an
        // audit trail that stores live sign-in codes is a worse artefact than the one it
        // was written to protect.
        $this->audit->record($this->adminId(), 'sandbox.judge_code', 'judge', 0,
                             ['ok' => (bool) ($r['ok'] ?? false)]);

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
            // The portals, and the keys to them — see judgeCode() for why the judge one
            // had to exist at all.
            'doors'       => DemoSeeder::doors(),
            // Shown once. A sign-in code that survives a refresh is one still on screen
            // when somebody else walks past the desk, and it is valid for fifteen minutes.
            'judge_code'  => (static function (): ?string {
                $c = $_SESSION['sandbox_judge_code'] ?? null;
                unset($_SESSION['sandbox_judge_code']);
                return is_string($c) && $c !== '' ? $c : null;
            })(),
        ]);
    }
}
