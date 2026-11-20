<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\VendorPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * What vendors must supply, and what they may sell.
 *
 * Both were constants in PHP, on a deployment with no SSH — so a craft market could not
 * stop demanding company registration certificates, and a book fair could not add
 * "publishing" to the trades it sells stands for. {@see VendorPolicy} carries the reasoning.
 *
 * ── ADMIN, NOT SUPERADMIN ────────────────────────────────────────────────────
 *
 * Deliberately one step wider than the AI and integration screens. This is the job of the
 * person running the market — they are the one who knows whether this event's traders are
 * incorporated businesses or twenty people with a table — and putting it behind the same
 * gate as API keys would mean it is never actually used by the person it is for.
 */
final class VendorPolicyController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }

    private function back(Response $res): Response
    {
        return $res->withHeader('Location', '/admin/vendor-policy')->withStatus(302);
    }

    public function index(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/vendor-policy.twig', [
            'page_title'  => 'Vendor requirements',
            'admin_page'  => 'vendor-policy',
            'require_cac'       => VendorPolicy::requireCac(),
            'require_scuml'     => VendorPolicy::requireScuml(),
            'require_insurance' => VendorPolicy::requireInsurance(),
            'categories'  => VendorPolicy::categories(),
            'defaults'    => VendorPolicy::DEFAULT_CATEGORIES,
            // What each entity type would actually be asked for under the current settings.
            // Shown rather than described, because "CAC off" and "so an individual uploads
            // photo ID and a business uploads photo ID" are different sentences and only
            // the second one answers the question somebody is asking.
            'preview'     => [
                'individual' => VendorPolicy::documentsFor(\AfricaGates\Services\PartnerOrg::ENTITY_INDIVIDUAL),
                'business'   => VendorPolicy::documentsFor(\AfricaGates\Services\PartnerOrg::ENTITY_BUSINESS),
            ],
        ]);
    }

    public function saveRequirements(Request $req, Response $res): Response
    {
        $r = VendorPolicy::saveRequirements((array) $req->getParsedBody(), $this->adminId());

        $_SESSION['flash_ok'] = (string) $r['message'];
        $this->audit?->record($this->adminId(), 'vendor_policy.requirements', null, null, [
            'cac'       => VendorPolicy::requireCac(),
            'scuml'     => VendorPolicy::requireScuml(),
            'insurance' => VendorPolicy::requireInsurance(),
        ]);

        return $this->back($res);
    }

    public function saveCategories(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();

        // Paired arrays rather than one map, because a renamed category must keep its slug:
        // deriving the slug from the label would orphan every stand type already filed under
        // the old one, and the quotas with them.
        $slugs  = is_array($b['slug']  ?? null) ? array_values($b['slug'])  : [];
        $labels = is_array($b['label'] ?? null) ? array_values($b['label']) : [];

        $in = [];
        foreach ($labels as $i => $label) {
            $slug = (string) ($slugs[$i] ?? '');
            // A blank slug is a NEW row the organiser just typed; VendorPolicy derives one.
            $in[$slug !== '' ? $slug : $i] = (string) $label;
        }

        $r = VendorPolicy::saveCategories($in, $this->adminId());

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) {
            $this->audit?->record($this->adminId(), 'vendor_policy.categories', null, null,
                ['count' => $r['count']]);
        }

        return $this->back($res);
    }
}
