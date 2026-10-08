<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\SettingsService;
use AfricaGates\Services\AttendeeBot;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Setting up the interview recording bot.
 *
 * ── WHY THIS SCREEN HAD TO EXIST ─────────────────────────────────────────────
 *
 * {@see AttendeeBot} read every one of its six settings from the environment. This platform
 * deploys to cPanel by upload with no SSH, so "set ATTENDEE_API_KEY" was not something an
 * operator could do — which means the interview bot had no configuration surface at all and
 * the whole feature was unreachable on the deployment it was written for. It was configured
 * in the only place a developer had access to, and nowhere the person running the awards did.
 *
 * ── THE KEY IS WRITE-ONLY ────────────────────────────────────────────────────
 *
 * Same rule as every provider key in Settings: a blank field leaves the stored value alone,
 * and only the explicit "clear" box removes it. The stored key is never rendered back —
 * the screen shows its first three characters, its last four and its length, which is
 * enough to tell "the key I pasted" from "some other key" without putting a live credential
 * into a page somebody will screenshot into a support thread.
 *
 * ── AND THE TEST CREATES NOTHING ─────────────────────────────────────────────
 *
 * {@see AttendeeBot::checkConnection()} reads the bot list. A test that created a bot would
 * put one in a meeting, and a "test" with a side effect is a test nobody presses twice.
 */
final class AttendeeController
{
    public function __construct(
        private readonly Twig            $view,
        private readonly SettingsService $settings,
        private readonly AuditService    $audit,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }

    public function index(Request $req, Response $res): Response
    {
        return $this->render($res, null);
    }

    public function save(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $clear = (array) ($b['clear'] ?? []);
        $saved = [];

        foreach (AttendeeBot::SETTINGS as $key => $spec) {
            if (!empty($clear[$key])) {
                $this->settings->set($key, '', $this->adminId());
                $saved[] = $key;
                continue;
            }

            if (!array_key_exists($key, $b)) continue;
            $val = trim((string) $b[$key]);

            // A blank SECRET means "leave it alone" — the field is never pre-filled, so an
            // operator saving the form to change the bot name must not wipe the API key.
            // A blank PLAIN field is a real instruction to fall back to the env/default,
            // because that is the only way to undo a value typed here.
            if ($spec['secret'] && $val === '') continue;

            $this->settings->set($key, $val, $this->adminId());
            $saved[] = $key;
        }

        if ($saved !== []) {
            // The audit records WHICH keys moved and never their values — the API key is a
            // credential and an audit log is read by more people than the settings screen.
            $this->audit->record($this->adminId(), 'attendee.settings', 'settings', 0,
                ['keys' => $saved]);
        }

        $_SESSION['flash'] = $saved === []
            ? 'Nothing changed.'
            : count($saved) . ' setting(s) saved. Test the connection below.';

        return $res->withHeader('Location', '/admin/attendee')->withStatus(302);
    }

    /** Run the check and show the result on the same page. */
    public function test(Request $req, Response $res): Response
    {
        $result = AttendeeBot::checkConnection();
        $this->audit->record($this->adminId(), 'attendee.test', 'settings', 0,
            ['ok' => $result['ok'], 'level' => $result['level']]);

        // Rendered rather than redirected: the result is a diagnostic list, and a flash
        // message cannot carry six checks. A redirect would also mean re-running the test
        // on every refresh, which is a network call per keypress on F5.
        return $this->render($res, $result);
    }

    /** @param array<string,mixed>|null $test */
    private function render(Response $res, ?array $test): Response
    {
        return $this->view->render($res, 'admin/attendee/index.twig', [
            'page_title'  => 'Interview bot — Admin',
            'admin_page'  => 'attendee',
            'settings'    => AttendeeBot::SETTINGS,
            'report'      => AttendeeBot::configReport(),
            'configured'  => AttendeeBot::configured(),
            'self_hosted' => AttendeeBot::selfHosted(),
            'hosted_base' => AttendeeBot::HOSTED_BASE,
            'test'        => $test,
        ]);
    }
}
