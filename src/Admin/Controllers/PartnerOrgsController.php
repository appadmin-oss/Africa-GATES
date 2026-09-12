<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\{AuditService, UploadService};
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\{OrgAuth, OrgCampaign, PartnerOrg, PaymentService, RegistryCheck};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Onboarding and vetting a partner organisation.
 *
 * ── THE SCREEN IS THE VETTING RECORD ─────────────────────────────────────────
 *
 * Everything a reviewer needs in order to decide sits on one page, and every decision they
 * take is written down with their id against it. That is not bureaucracy: listing an
 * organisation lends Africa GATES' credibility to a third party, and the only thing that
 * answers "how did they get on your site?" afterwards is a record of what was checked and
 * by whom.
 *
 * ── AND IT NEVER CLAIMS MORE THAN IT KNOWS ───────────────────────────────────
 *
 * There is no free public API for the CAC register — the Commission runs a search page for
 * humans. So a registration number is `unchecked` until somebody either presses "I checked
 * this" after following the one-click search link, or a configured verifier answers. The
 * screen shows which of those happened. A vetting record that cannot tell "we looked" from
 * "we typed it in" is worthless in the argument it exists for.
 *
 * The one thing that IS verified automatically is the settlement account: Paystack is asked
 * who owns the number before a subaccount is created, and the answer is compared against the
 * registered name and shown here for a human to judge.
 */
final class PartnerOrgsController
{
    /**
     * Certificates a party can be asked for.
     *
     * Taken from PartnerOrg rather than redeclared, because this list and
     * PartnerOrg::requiredDocuments() disagreeing about a slug means somebody uploads the
     * right certificate and is still told it is missing.
     */
    private const DOC_KINDS = PartnerOrg::DOCUMENT_KINDS;

    public function __construct(
        private readonly Twig            $view,
        private readonly AuditService    $audit,
        private readonly PaymentService  $payments,
        private readonly ?UploadService  $uploads = null,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }
    private function role(): string { return (string) ($_SESSION['admin_role'] ?? ''); }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    /**
     * Money-moving changes are admin+ only.
     *
     * Attaching a settlement account decides where donations land, and approving decides
     * whether an organisation may collect at all. Both are integrity decisions rather than
     * moderation, so they sit behind the same gate as deleting a nominee.
     */
    private function mayDecide(): bool
    {
        return Permissions::canManageIntegrity($this->role());
    }

    // ──────────────────────────────── listing ───────────────────────────────

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_partner_orgs')->orderByDesc('id')->get()->all();

        $out = [];
        foreach ($rows as $r) {
            $t = PartnerOrg::totals((int) $r->id);
            $out[] = ['org' => $r, 'totals' => $t, 'live' => PartnerOrg::canReceive($r)];
        }

        return $this->view->render($res, 'admin/partner-orgs/index.twig', [
            'page_title' => 'Partner organisations — Admin',
            'admin_page' => 'partner-orgs',
            'rows'       => $out,
            'statuses'   => PartnerOrg::STATUSES,
            // The same two numbers the public application page shows, so nobody has to
            // reconcile an internal figure against a external one — there is only one.
            'totals'     => PartnerOrg::platformTotals(),
        ]);
    }

    public function show(Request $req, Response $res, array $args = []): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $org = PartnerOrg::find($id);
        if (!$org) return $this->back($res, '/admin/partner-orgs');

        $cac = RegistryCheck::cacFormat((string) ($org->cac_number ?? ''));

        // Defaults to the number already on file, so the common case — "is this real?" — is
        // one click rather than a retype, which is the retype that introduces the typo.
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));

        return $this->view->render($res, 'admin/partner-orgs/show.twig', [
            'page_title'  => $org->name . ' — Admin',
            'admin_page'  => 'partner-orgs',
            'org'         => $org,
            'totals'      => PartnerOrg::totals($id),
            'live'        => PartnerOrg::canReceive($org),
            'users'       => DB::table('gates_org_users')->where('org_id', $id)->orderBy('id')->get()->all(),
            'documents'   => DB::table('gates_org_documents')->where('org_id', $id)->orderByDesc('id')->get()->all(),
            'doc_kinds'   => self::DOC_KINDS,
            'banks'       => $this->payments->banks(),
            'cac_format'  => $cac,
            'cac_search'  => RegistryCheck::cacSearchUrl((string) ($org->cac_number ?? '')),
            // ── THE REGISTER, SEARCHED FROM THIS PAGE ────────────────────
            //
            // Run on the GET so it needs no script and survives a reload with the results
            // still on screen. Only when a query was actually typed — an unconditional
            // search would put an outbound HTTP call in front of every page view of every
            // vetting record.
            'cac_query'   => $q,
            'cac_results' => $q !== '' ? RegistryCheck::searchCac($q) : null,
            'verifier_on' => RegistryCheck::verifierAvailable(),
            'check_states'=> RegistryCheck::STATES,
            // The stored comparison from onboarding: what the BANK said the account name is,
            // beside what the organisation calls itself. The single most useful thing on the
            // page for spotting somebody collecting into a personal account.
            'name_match'  => trim((string) ($org->account_name_resolved ?? '')) !== ''
                // matchScore, not nameSimilarity: a person's account is compared by a
                // different rule, and a screen that recomputed it with the other one would
                // show a number nobody could reproduce.
                ? PartnerOrg::matchScore($org, (string) $org->account_name_resolved)
                : null,
            'entities'    => PartnerOrg::ENTITIES,
            'kinds'       => PartnerOrg::KINDS,
            'individual'  => PartnerOrg::isIndividual($org),
            'required'    => PartnerOrg::requiredDocuments($id),
            'missing'     => \AfricaGates\Services\StandApplication::missingDocuments($id),
            'may_decide'  => $this->mayDecide(),
            'campaigns'   => $this->campaignRows($id),
            'camp_states' => OrgCampaign::STATUSES,
            'shortfall'   => OrgCampaign::SHORTFALL,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function campaignRows(int $orgId): array
    {
        $out = [];
        foreach (OrgCampaign::allFor($orgId) as $c) {
            $out[] = ['row' => $c, 'progress' => OrgCampaign::progress((int) $c->id),
                      'open' => OrgCampaign::isOpen($c)];
        }
        return $out;
    }

    // ──────────────────────────── reviewing appeals ─────────────────────────

    /**
     * Publish an appeal.
     *
     * Refused unless the organisation itself may receive money, which is checked inside
     * OrgCampaign::publish — a live appeal for a suspended charity is a donate button with
     * nowhere to send the money.
     */
    public function publishCampaign(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $c  = OrgCampaign::find($id);
        $to = '/admin/partner-orgs' . ($c ? '/' . (int) $c->org_id : '');

        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can publish an appeal.';
            return $this->back($res, $to);
        }

        $b = (array) $req->getParsedBody();
        $r = OrgCampaign::publish($id, $this->adminId(), trim((string) ($b['note'] ?? '')));

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'campaign.publish', 'campaign', $id);
        return $this->back($res, $to);
    }

    public function closeCampaign(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $c  = OrgCampaign::find($id);
        $to = '/admin/partner-orgs' . ($c ? '/' . (int) $c->org_id : '');

        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can close an appeal.';
            return $this->back($res, $to);
        }

        $r = OrgCampaign::close($id, $this->adminId());
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'campaign.close', 'campaign', $id);
        return $this->back($res, $to);
    }

    // ──────────────────────────────── create ────────────────────────────────

    public function create(Request $req, Response $res): Response
    {
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can add a partner organisation.';
            return $this->back($res, '/admin/partner-orgs');
        }

        $b    = (array) $req->getParsedBody();
        $name = trim((string) ($b['name'] ?? ''));
        // Same reasoning as OrgCampaign: fold accents, never delete them.
        $slug = \AfricaGates\Support\Slug::make((string) ($b['slug'] ?? '') ?: $name, 120);

        if ($name === '' || $slug === '') {
            $_SESSION['flash_error'] = 'A name and a URL slug are both needed.';
            return $this->back($res, '/admin/partner-orgs');
        }
        if (DB::table('gates_partner_orgs')->where('slug', $slug)->exists()) {
            $_SESSION['flash_error'] = 'That slug is already in use.';
            return $this->back($res, '/admin/partner-orgs');
        }

        $kind   = (string) ($b['kind'] ?? PartnerOrg::KIND_PARTNER);
        $entity = (string) ($b['entity_type'] ?? PartnerOrg::ENTITY_BUSINESS);

        $id = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => $slug, 'name' => $name,
            'kind'          => isset(PartnerOrg::KINDS[$kind]) ? $kind : PartnerOrg::KIND_PARTNER,
            // An individual DONATION partner is not a thing: a body collecting charitable
            // gifts in Nigeria has to be incorporated. Only a vendor can be a person, and
            // forcing that here means the rest of the code never has to check the pair.
            'entity_type'   => ($kind === PartnerOrg::KIND_VENDOR && isset(PartnerOrg::ENTITIES[$entity]))
                                ? $entity : PartnerOrg::ENTITY_BUSINESS,
            'legal_name'    => trim((string) ($b['legal_name'] ?? '')) ?: null,
            // Normalised when it is well formed, kept verbatim when it is not. An admin
            // typing a number is looking at the register and may have a shape this platform
            // does not know; refusing it would be the tool arguing with the source. But when
            // it IS a shape we know, one spelling is stored — `RC/1234567` — so two records
            // carrying the same registration collide in PartnerOrg::cacOnFileElsewhere()
            // instead of looking like two organisations.
            'cac_number'    => PartnerOrg::storableCac((string) ($b['cac_number'] ?? '')),
            'scuml_number'  => trim((string) ($b['scuml_number'] ?? '')) ?: null,
            'description'   => trim((string) ($b['description'] ?? '')) ?: null,
            'contact_name'  => trim((string) ($b['contact_name'] ?? '')) ?: null,
            'contact_email' => trim((string) ($b['contact_email'] ?? '')) ?: null,
            'contact_phone' => trim((string) ($b['contact_phone'] ?? '')) ?: null,
            'status'        => PartnerOrg::STATUS_DRAFT,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record($this->adminId(), 'partner_org.create', 'partner_org', $id);
        $_SESSION['flash_ok'] = 'Created. Attach a settlement account next.';
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    // ──────────────────────── the settlement account ────────────────────────

    /**
     * Resolve the account, create the subaccount and the transfer recipient, store the codes.
     *
     * The account number reaches Paystack and is never written down — see PartnerOrg. The
     * transfer recipient is created in this same request because this is the only moment the
     * number is legitimately in hand.
     */
    public function attachAccount(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can attach a settlement account.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $b   = (array) $req->getParsedBody();
        $fee = max(0.0, min(100.0, (float) ($b['fee_percent'] ?? 0)));

        $r = PartnerOrg::attachSubaccount(
            $this->payments, $id,
            (string) ($b['account_number'] ?? ''),
            (string) ($b['bank_code'] ?? ''),
            $fee,
            (string) ($b['settlement_schedule'] ?? 'auto')
        );

        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['message'];
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $this->audit->record($this->adminId(), 'partner_org.account_attached', 'partner_org', $id);

        // A weak name match is surfaced as a warning rather than buried in the page, because
        // it is the one thing on this screen somebody must not scroll past.
        if ($r['needs_review']) $_SESSION['flash_error'] = $r['message'];
        else                    $_SESSION['flash_ok']    = $r['message'];

        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    // ───────────────────────────── registry checks ──────────────────────────

    /**
     * Record that a human checked a registration number, or ask a configured verifier.
     *
     * "I checked this" is a claim by a named admin with a timestamp, which is exactly what it
     * is and exactly how it is stored. It is not dressed up as verification.
     */
    public function check(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can record a registry check.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $org = PartnerOrg::find($id);
        if (!$org) return $this->back($res, '/admin/partner-orgs');

        $b      = (array) $req->getParsedBody();
        $which  = (string) ($b['which'] ?? '');
        $verdict= (string) ($b['verdict'] ?? '');

        if (!in_array($which, ['cac', 'scuml'], true)) {
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $update = ['checked_by' => $this->adminId(), 'checked_at' => date('Y-m-d H:i:s')];

        if ($verdict === 'api' && $which === 'cac') {
            $v = RegistryCheck::verifyCac((string) ($org->cac_number ?? ''));
            $update['cac_check'] = $v['state'];
            if ($v['name'] !== '') $update['cac_registered_name'] = $v['name'];
            $_SESSION[$v['state'] === RegistryCheck::VERIFIED ? 'flash_ok' : 'flash_error'] =
                $v['state'] === RegistryCheck::VERIFIED
                    ? 'The register returned “' . $v['name'] . '”.'
                    : ($v['message'] !== '' ? $v['message'] : 'The register did not confirm that number.');
        } else {
            $state = in_array($verdict, [RegistryCheck::CONFIRMED, RegistryCheck::REJECTED], true)
                ? $verdict : RegistryCheck::UNCHECKED;
            $update[$which . '_check'] = $state;
            $_SESSION['flash_ok'] = strtoupper($which) . ' marked “' . (RegistryCheck::STATES[$state] ?? $state) . '”.';
        }

        DB::table('gates_partner_orgs')->where('id', $id)->update($update);
        $this->audit->record($this->adminId(), 'partner_org.registry_check', 'partner_org', $id);
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    /**
     * Take a searched result as the organisation's registered identity.
     *
     * ── WHY THIS IS A BUTTON AND NOT A COPY-PASTE ────────────────────────────
     *
     * Because the alternative is a reviewer retyping a name from one half of the screen into
     * the other, and the register's spelling is the one that has to win. "Bright Futures
     * Initiative" against "BRIGHT FUTURE INITIATIVE" is a difference that matters — it is the
     * difference the bank-account name match is later measured against — and it is exactly
     * the kind of difference a human eye smooths over while copying.
     *
     * Recorded as CONFIRMED rather than VERIFIED. A search result a person chose from a list
     * is a person's judgement, which is what `confirmed` means; `verified` is reserved for a
     * verifier that answered on its own. The record has to be able to tell those apart.
     */
    public function adoptRegistry(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can record a registry check.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }
        if (!PartnerOrg::find($id)) return $this->back($res, '/admin/partner-orgs');

        $b    = (array) $req->getParsedBody();
        $name = trim((string) ($b['reg_name'] ?? ''));
        $rc   = trim((string) ($b['rc_number'] ?? ''));

        if ($name === '') {
            $_SESSION['flash_error'] = 'That result carried no registered name.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $update = [
            'cac_registered_name' => mb_substr($name, 0, 200),
            'cac_check'           => RegistryCheck::CONFIRMED,
            'checked_by'          => $this->adminId(),
            'checked_at'          => date('Y-m-d H:i:s'),
        ];
        // The number too, but only when the register gave one — overwriting a number on file
        // with an empty string would erase the thing being checked.
        if ($rc !== '') $update['cac_number'] = mb_substr($rc, 0, 60);

        DB::table('gates_partner_orgs')->where('id', $id)->update($update);
        $this->audit->record($this->adminId(), 'partner_org.registry_adopted', 'partner_org', $id);

        $_SESSION['flash_ok'] = 'Recorded “' . $name . '” from the register, against your name. '
                              . 'Compare it with the bank account name below before approving.';
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    // ──────────────────────────────── documents ─────────────────────────────

    public function upload(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can attach documents.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }
        if (!PartnerOrg::find($id)) return $this->back($res, '/admin/partner-orgs');
        if (!$this->uploads) {
            $_SESSION['flash_error'] = 'Uploads are not available on this server.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $b    = (array) $req->getParsedBody();
        $kind = (string) ($b['kind'] ?? 'other');
        if (!isset(self::DOC_KINDS[$kind])) $kind = 'other';

        $file = $req->getUploadedFiles()['document'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash_error'] = 'Choose a file to upload.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        try {
            // uploadDocument trusts the BYTES rather than the client MIME and verifies a PDF
            // by its magic number — the right call for a file somebody emailed in.
            $r = $this->uploads->uploadDocument($file, 'org-docs', 15, $this->adminId(), 'admin', 'partner_org', $id);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not upload — ' . $e->getMessage();
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $expires = trim((string) ($b['expires_on'] ?? ''));
        DB::table('gates_org_documents')->insert([
            'org_id'        => $id,
            'kind'          => $kind,
            'original_name' => mb_substr((string) $file->getClientFilename(), 0, 250),
            'stored_path'   => (string) $r['path'],
            'mime'          => (string) ($r['mime'] ?? ''),
            'size_bytes'    => (int) ($r['size'] ?? 0),
            // Nullable on purpose: a CAC certificate does not expire and an insurance policy
            // very much does. An expiry that was never given must not read as "expired".
            'expires_on'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? $expires : null,
            'uploaded_by'   => $this->adminId(),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record($this->adminId(), 'partner_org.document', 'partner_org', $id);
        $_SESSION['flash_ok'] = 'Document attached.';
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    // ──────────────────────────────── decisions ─────────────────────────────

    public function approve(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can approve a partner.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $b = (array) $req->getParsedBody();
        $r = PartnerOrg::approve($id, $this->adminId(), trim((string) ($b['note'] ?? '')));

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'partner_org.approve', 'partner_org', $id);
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    public function suspend(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can suspend a partner.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $b = (array) $req->getParsedBody();
        $r = PartnerOrg::suspend($id, trim((string) ($b['reason'] ?? '')));

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'partner_org.suspend', 'partner_org', $id);
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }

    // ────────────────────────── the dashboard login ─────────────────────────

    /**
     * Create a sign-in for the organisation's own staff.
     *
     * The password is shown ONCE, on the next screen, and never stored in readable form or
     * emailed. Emailing a password puts it permanently in two mailboxes and a mail server,
     * for a credential that can request payouts.
     */
    public function addUser(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can create a dashboard login.';
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $b        = (array) $req->getParsedBody();
        // Generated here rather than typed by an admin: a human-chosen password for somebody
        // else's account is one that gets reused, and this one can move money.
        $password = bin2hex(random_bytes(9));

        $r = OrgAuth::createUser(
            $id,
            (string) ($b['email'] ?? ''),
            $password,
            trim((string) ($b['name'] ?? '')),
            (string) ($b['role'] ?? 'owner')
        );

        if (!$r['ok']) {
            $_SESSION['flash_error'] = $r['message'];
            return $this->back($res, '/admin/partner-orgs/' . $id);
        }

        $this->audit->record($this->adminId(), 'partner_org.user_created', 'partner_org', $id);
        $_SESSION['flash_ok'] = 'Login created for ' . trim((string) ($b['email'] ?? ''))
                              . '. Give them this password now — it is not stored and cannot be '
                              . 'shown again: ' . $password;
        return $this->back($res, '/admin/partner-orgs/' . $id);
    }
}
