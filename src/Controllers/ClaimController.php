<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{ClaimNotifier, MergeService, NomineeClaimService, Notifier};
use AfricaGates\Support\ClientIp;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * "Is this you?" — the front door of nominee claiming.
 *
 * docs/CLAIM-FAIRNESS-AND-FRAUD.md §2. Three endpoints: show the page, send a code,
 * confirm it. All three are open to guests, because the population is nominees who have
 * never had an account here — a login wall in front of claiming would gate a page on
 * having the thing the page is meant to give you.
 *
 * ── THIS CONTROLLER DECIDES NOTHING ──────────────────────────────────────────
 *
 * Every judgement lives in {@see NomineeClaimService}: which channels exist, whether
 * one is independent, whether a claim activates or is held. What happens HERE is the
 * two things a service must not do for itself —
 *
 *   1. RESOLVE THE CLAIMANT'S DEVICE. `device_fp` and `ip_hash` are compared against
 *      the nomination's stored columns, which hold sha256 of each, so they are hashed
 *      here and never passed raw. A raw fingerprint would compare unequal to every row
 *      forever: the control would look configured, run on every claim, and catch
 *      nobody.
 *   2. BUILD THE RATE-LIMIT KEY from the session and the network, via {@see ClientIp} —
 *      the same two-dimension fingerprint the money paths use, for the same reason.
 *      A Cloudflare-fronted site collapses every visitor into one IP bucket if the
 *      network is used alone.
 *
 * ── AND IT NEVER ACCEPTS A DESTINATION ───────────────────────────────────────
 *
 * The POST body carries a `channel` KEY, never an address. There is no field here in
 * which a claimant could name where their code should go, which is the property that
 * stops this becoming "claim anybody by typing your own email". See the service.
 */
final class ClaimController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?NomineeClaimService $claims = null,
    ) {}

    /**
     * The claim page for one nominee.
     *
     * Renders for a page that cannot currently be claimed too, rather than 404ing: an
     * already-claimed page has to say so and name the route to a person, which is the
     * §7.6 promise that a claim is never the end of the road.
     */
    public function page(Request $req, Response $res, array $args = []): Response
    {
        $nomineeId = (int) ($args['id'] ?? 0);
        $nominee   = $this->nominee($nomineeId);

        if ($nominee === null) {
            return $this->view->render($res->withStatus(404), 'pages/nominee-claim.twig', [
                'page_title'       => 'Page not found — Africa GATES',
                'meta_description' => 'Claim an Africa GATES nominee page.',
                'gates_page'       => 'awards',
                'has_hero'         => false,
                'nominee'          => null,
                'channels'         => [],
                'already'          => null,
                'support_email'    => Notifier::supportEmail(),
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        [$deviceFp, $ipHash] = $this->fingerprints($req);

        return $this->view->render($res, 'pages/nominee-claim.twig', [
            'page_title'       => 'Claim the page for ' . $nominee->name . ' — Africa GATES',
            'meta_description' => 'Confirm a code sent to a contact on the nomination to claim this page.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'nominee'          => ['id' => (int) $nominee->id, 'name' => (string) $nominee->name],
            'channels'         => $this->claims?->channels($nomineeId, $deviceFp, $ipHash) ?? [],
            'already'          => $this->activeReference($nomineeId),
            'support_email'    => Notifier::supportEmail(),
        // noindex: this URL is an invitation to prove an identity, and it has no
        // business in a search result next to the nominee's public profile.
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow')
          ->withHeader('Cache-Control', 'no-store, private');
    }

    /** Send a code to a channel already on the nomination. */
    public function send(Request $req, Response $res, array $args = []): Response
    {
        if ($this->claims === null) {
            return $this->json($res, ['ok' => false, 'message' => 'Claiming is not available right now.'], 503);
        }

        $body = (array) ($req->getParsedBody() ?? []);
        [$deviceFp, $ipHash] = $this->fingerprints($req);

        $out = $this->claims->start(
            (int) ($args['id'] ?? 0),
            (string) ($body['channel'] ?? ''),
            $deviceFp,
            $ipHash,
            ClientIp::fingerprint(ClientIp::browser($req), 'claim'),
        );

        // 200 even for a refusal. Everything this endpoint returns is a message meant to
        // be read by the person who asked, and an HTTP error code invites the browser and
        // any proxy in between to replace it with their own.
        return $this->json($res, $out);
    }

    /** Confirm a code, and settle as active or held. */
    public function confirm(Request $req, Response $res, array $args = []): Response
    {
        if ($this->claims === null) {
            return $this->json($res, ['ok' => false, 'message' => 'Claiming is not available right now.'], 503);
        }

        $body = (array) ($req->getParsedBody() ?? []);
        [$deviceFp, $ipHash] = $this->fingerprints($req);

        return $this->json($res, $this->claims->confirm(
            (int) ($body['claim_id'] ?? 0),
            (string) ($body['code'] ?? ''),
            $deviceFp,
            $ipHash,
            ClientIp::fingerprint(ClientIp::browser($req), 'claim'),
        ));
    }

    // ── the two things the service cannot do for itself ─────────────────────

    /**
     * The claimant's device and network, hashed the way the nomination stored them.
     *
     * `gates_nominations.device_fp` holds sha256 of the browser token and `ip_hash`
     * sha256 of the address, so both are hashed here. The browser token itself is the
     * session-backed one {@see ClientIp::browser()} already maintains — a first-party
     * value the claimant cannot inherit from anybody else, unlike the NAT-shared IP.
     *
     * @return array{0:string,1:string}
     */
    private function fingerprints(Request $req): array
    {
        $ip = ClientIp::from($req);
        return [
            hash('sha256', ClientIp::browser($req)),
            $ip !== '' ? hash('sha256', $ip) : '',
        ];
    }

    // ── reads ────────────────────────────────────────────────────────────────

    private function nominee(int $nomineeId): ?object
    {
        if ($nomineeId < 1) return null;
        try {
            return MergeService::notMerged(
                DB::table('gates_nominees')->where('id', $nomineeId)->where('status', 'approved')
            )->first(['id', 'name']);
        } catch (\Throwable) {
            return null;
        }
    }

    // ══ dispute: "this was not me" ═══════════════════════════════════════════

    /**
     * GET /claim/dispute/{token} — the confirm page.
     *
     * ── WHY THIS IS NOT THE ACTION ───────────────────────────────────────────
     *
     * A one-click freeze URL is exactly what the notification wants, and putting the
     * freeze on the GET would break claiming in a way nobody could diagnose. Gmail,
     * Outlook, Microsoft Defender for Office and every other link-safety scanner FETCH
     * the URLs in a message before a human sees it. The freeze would fire automatically
     * on a large share of honest claims, and the request in the log would look like an
     * ordinary visitor arriving from an email.
     *
     * So GET renders a page with one button. Possession of the token authorises; the
     * button establishes that a person meant it.
     *
     * Never 404s on an unknown token: somebody who taps an old link deserves the support
     * address rather than a not-found page, and a 404 would confirm which tokens are
     * real to anybody enumerating them.
     */
    public function disputePage(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $claim = \AfricaGates\Services\ClaimDispute::preview($token);

        return $this->view->render($res, 'pages/claim-dispute.twig', [
            'page_title'       => 'Stop a claim — Africa GATES',
            'meta_description' => 'Freeze a claim on an Africa GATES nominee page.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            // Never indexed and never cached: a one-time security action reached from a
            // message, naming a nominee.
            'robots'           => 'noindex, nofollow',
            'token'            => $token,
            'claim'            => $claim,
            'support_email'    => Notifier::supportEmail(),
            'done'             => null,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow')
          ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * POST /claim/dispute/{token} — freeze it.
     *
     * Renders the same template with an outcome rather than redirecting, so the result
     * survives a reader who has no cookies (a link opened in a mail client's own browser
     * frequently does not) — a flash message would be lost exactly there.
     */
    public function disputeFreeze(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $note  = (string) ($body['note'] ?? '');

        $before = \AfricaGates\Services\ClaimDispute::preview($token);
        // The masked channel that objected is NOT taken from the request — there is no
        // field in which a disputer names themselves, and inventing one would let a
        // stranger with a leaked token put words in a nominee's mouth in the audit trail.
        // What we can say honestly is which claim's link was used.
        $r = \AfricaGates\Services\ClaimDispute::freeze($token, $note, 'the dispute link we sent');

        return $this->view->render($res, 'pages/claim-dispute.twig', [
            'page_title'       => 'Claim frozen — Africa GATES',
            'meta_description' => 'Freeze a claim on an Africa GATES nominee page.',
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'robots'           => 'noindex, nofollow',
            'token'            => $token,
            'claim'            => $before,
            'support_email'    => Notifier::supportEmail(),
            'done'             => $r,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow')
          ->withHeader('Cache-Control', 'no-store, private');
    }

    /** The reference of the claim already holding this page, for the page to quote. */
    private function activeReference(int $nomineeId): ?string
    {
        try {
            $row = DB::table('gates_nominee_claims')
                ->where('nominee_id', $nomineeId)->where('status', 'active')->first();
            return $row === null
                ? null
                : ClaimNotifier::reference((int) $row->id, $row);
        } catch (\Throwable) {
            return null;
        }
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store, private')
                   ->withStatus($status);
    }
}
