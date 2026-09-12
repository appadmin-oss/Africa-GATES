<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{OrgAuth, PartnerOrg, RateLimitService};
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Organisations applying to receive gifts through Africa GATES.
 *
 * ── WHY THE GIFT PAGE NEEDED A DOOR ──────────────────────────────────────────
 *
 * Until now every organisation on the platform was typed in by an administrator. That is not
 * a high bar, it is a NARROW one: the only bodies that could raise money here were the ones
 * that already knew somebody. A published form does not lower the standard — the vetting is
 * untouched, CAC and SCUML are still both mandatory, and a settlement account in the
 * organisation's own registered name is still checked with the bank before anything is paid.
 * It changes who gets to reach the standard.
 *
 * ── WHAT AN APPLICANT IS TOLD, PLAINLY, BEFORE THEY START ────────────────────
 *
 * That the money never touches Africa GATES — it splits at the gateway into their own
 * subaccount. That they will be asked for documents. That the answer may be no, and that
 * they will be given a reason. Every one of those is on the page rather than in a policy
 * nobody opens, because an application form that hides its requirements is a form that
 * wastes the time of the people least able to spare it.
 */
final class OrgApplyController
{
    public function __construct(
        private readonly Twig              $view,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function redirect(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    public function form(Request $req, Response $res): Response
    {
        return $this->render($res, [], null);
    }

    /**
     * Render the form, keeping whatever was typed.
     *
     * Every failure comes back through here with the values, because a validation error that
     * empties a ten-field form is a validation error that loses the application.
     */
    private function render(Response $res, array $old, ?string $error): Response
    {
        return $this->view->render($res, 'pages/org-apply.twig', [
            'page_title' => 'Raise gifts through Africa GATES',
            'meta_description' => 'Apply for your organisation to receive gifts through Africa '
                                . 'GATES. Money settles directly to your own account — the '
                                . 'platform never holds it.',
            'gates_page' => 'donate',
            'has_hero'   => false,
            'old'        => $old,
            'error'      => $error,
            'signed_in'  => OrgAuth::user() !== null,
            'totals'     => PartnerOrg::platformTotals(),
        ]);
    }

    public function submit(Request $req, Response $res): Response
    {
        // Somebody already signed in has an organisation. Sending them through this form
        // would mint a second one against the same person, which is a duplicate nobody
        // notices until two half-complete records are sitting in the review queue.
        if (OrgAuth::user() !== null) {
            $_SESSION['org_flash_error'] = 'You are already signed in. Continue from your dashboard.';
            return $this->redirect($res, '/org');
        }

        $b  = (array) $req->getParsedBody();
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        // Account creation is the only half of this worth abusing. Throttled per address
        // rather than per form, because a script does not reuse an email.
        if ($this->rateLimit && $ip !== ''
            && !$this->rateLimit->check(hash('sha256', $ip), 'org_apply', 5, 3600)) {
            return $this->render($res, $b,
                'Too many applications have been started from this connection. Try again in an hour.');
        }

        $r = PartnerOrg::registerPartner($b);
        if (!$r['ok']) return $this->render($res, $b, $r['message']);

        $user = $r['user'];
        if (!$user) {
            return $this->render($res, $b,
                'The account was created but could not be signed in. Try signing in at /org/login.');
        }
        (new OrgAuth($this->rateLimit))->signIn($user);

        // Straight to the dashboard, because what happens next is uploading certificates —
        // and an application without them cannot be reviewed at all.
        $_SESSION['org_flash_ok'] = 'Application received. Upload your CAC and SCUML certificates '
            . 'below — nothing can be reviewed until they are on file. We will be in touch either '
            . 'way, with a reason.';
        return $this->redirect($res, '/org');
    }
}
