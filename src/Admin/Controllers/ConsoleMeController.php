<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\ConsolePins;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The signed-in admin's own console: pin a view, unpin it, open or close the sidebar.
 *
 * Every action answers JSON to the console's script and a redirect back to a plain form
 * post, so each works with the script absent — the pin toggle is a real form. The
 * redirect target is the pinned path itself after normalisation, never a raw referer,
 * so this cannot be turned into an open redirect.
 *
 * Nothing here changes the platform, which is why `/admin/me/` is the one prefix a
 * read-only role may post to (AdminAuthMiddleware). Pinning and unpinning are still
 * written to the audit log — README rule 4 says every write — with the sentinel admin id
 * AuditService normalises; the sidebar toggle is view state and is not.
 */
final class ConsoleMeController
{
    public function __construct(private readonly AuditService $audit) {}

    private static function adminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    private static function role(): string
    {
        return (string) ($_SESSION['admin_role'] ?? '');
    }

    private static function wantsJson(Request $req): bool
    {
        return str_contains($req->getHeaderLine('Accept'), 'application/json');
    }

    /** @param array<string,mixed> $body */
    private function answer(Request $req, Response $res, array $body, string $back, int $code = 200,
                            ?string $flash = null): Response
    {
        if (self::wantsJson($req)) {
            $res->getBody()->write((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        }
        // Only on the no-script path: a flash set during a JSON call would toast again on
        // the NEXT page load, about something the script already announced.
        if (!($body['ok'] ?? false) && isset($body['why'])) $_SESSION['flash_error'] = (string) $body['why'];
        elseif ($flash !== null) $_SESSION['flash_ok'] = $flash;
        return $res->withHeader('Location', $back)->withStatus(303);
    }

    public function pin(Request $req, Response $res): Response
    {
        $b    = (array) $req->getParsedBody();
        $href = (string) ($b['href'] ?? '');
        $back = ConsolePins::normalise($href) ?? '/admin/dashboard';

        $r = ConsolePins::add(self::adminId(), self::role(), $href, (string) ($b['label'] ?? ''));
        if ($r['ok']) {
            $this->audit->record(self::adminId(), 'console.pin', 'admin_pin', $r['id'] ?? null, ['href' => $back]);
        }
        return $this->answer($req, $res, $r + ['href' => $back], $back, $r['ok'] ? 200 : 422, 'Pinned to the sidebar');
    }

    /** @param array<string,string> $args */
    public function unpin(Request $req, Response $res, array $args): Response
    {
        $gone = ConsolePins::remove(self::adminId(), (int) ($args['id'] ?? 0));
        $b    = (array) $req->getParsedBody();
        $back = ConsolePins::normalise((string) ($b['back'] ?? '')) ?? ($gone['href'] ?? '/admin/dashboard');

        if ($gone === null) {
            return $this->answer($req, $res, ['ok' => false, 'why' => 'That pin is already gone.'], $back, 404);
        }
        $this->audit->record(self::adminId(), 'console.unpin', 'admin_pin', $gone['id'], ['href' => $gone['href']]);
        // The pin itself comes back, so the toast's Undo can re-pin exactly what was there.
        return $this->answer($req, $res, ['ok' => true, 'pin' => $gone], $back, 200, 'Unpinned');
    }

    public function sidebar(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $closed = in_array((string) ($b['closed'] ?? ''), ['1', 'true'], true);
        $ok = ConsolePins::setSidebarClosed(self::adminId(), $closed);
        $back = ConsolePins::normalise((string) ($b['back'] ?? '')) ?? '/admin/dashboard';

        return $this->answer($req, $res, ['ok' => $ok, 'closed' => $closed], $back);
    }
}
