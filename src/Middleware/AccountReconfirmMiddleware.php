<?php
declare(strict_types=1);

namespace AfricaGates\Middleware;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\Account\Roles;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as Psr7Response;

/**
 * MONEY AND CONFIGURATION ASK AGAIN, FOR A ROLE OPENED FROM THE ACCOUNT (owner, 29 Oct 2026:
 * no code to move into a console; a code before sensitive actions — GitHub's "sudo mode").
 *
 *   'console'  a write (POST) in the staff console's Finance or Configuration sections, by a
 *              console opened from the account and not confirmed within Roles::CONFIRM_MINUTES,
 *              is sent to /account/confirm with a code already emailed.
 *   'org'      an organisation payout, by an organisation opened from the account, needs the
 *              organisation's OWN password — an email is never enough to move a charity's money.
 *
 * A session signed in on the role's own page is not touched: it already gave that door's proof.
 * The section is the guard's own answer (Permissions::sectionForPath), so a page moving between
 * sections moves its confirmation with it. A script caller is answered 403 in JSON, never a
 * redirect it would follow into an HTML page.
 */
final class AccountReconfirmMiddleware
{
    private const SECTIONS = ['finance', 'configuration'];

    public function __construct(private readonly string $mode) {}

    public function __invoke(Request $req, Handler $handler): Response
    {
        if ($req->getMethod() !== 'POST') return $handler->handle($req);
        if ($this->mode === 'console') {
            if (!in_array(Permissions::sectionForPath($req->getUri()->getPath()), self::SECTIONS, true) || !Roles::consoleNeedsConfirm()) {
                return $handler->handle($req);
            }
            $user = DB::table('gates_users')->where('id', (int) ($_SESSION['user_id'] ?? 0))->first();
            $sent = $user ? Roles::sendCode($user) : ['ok' => false, 'error' => 'Sign in to your account again.'];
            $_SESSION[$sent['ok'] ? 'acct_confirm_note' : 'acct_confirm_error'] = $sent['ok']
                ? 'We emailed you a code. Enter it, then press the button again.' : (string) $sent['error'];
            return $this->refuse($req, 'console', '/admin');
        }
        if ($this->mode === 'org' && Roles::orgNeedsPassword()) return $this->refuse($req, 'org', '/org');
        return $handler->handle($req);
    }

    private function refuse(Request $req, string $for, string $home): Response
    {
        $ref = (string) parse_url($req->getHeaderLine('Referer'), PHP_URL_PATH);
        $back = str_starts_with($ref, $home) ? $ref : $home;
        $to = '/account/confirm?for=' . $for . '&next=' . rawurlencode($back);
        $res = new Psr7Response();
        if (str_contains($req->getHeaderLine('Accept'), 'application/json') || $req->getHeaderLine('X-Requested-With') !== '') {
            $res->getBody()->write((string) json_encode(['ok' => false, 'error' => 'confirm', 'confirm' => $to], JSON_UNESCAPED_SLASHES));
            return $res->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        return $res->withHeader('Location', $to)->withStatus(303);
    }
}
