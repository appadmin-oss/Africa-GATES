<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\{OrgAuth, PartnerOrg, RateLimitService, StandApplication, StandCall, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The public call for stands, and the form anybody can fill in.
 *
 * ── A PUBLISHED FORM IS PART OF THE FAIRNESS, NOT A CONVENIENCE ──────────────
 *
 * The allocation rules in §5 of the vendor specification are only worth having if the pool
 * they are applied to was not hand-picked. A fair rule applied to whoever the organiser
 * happened to phone is still whoever the organiser happened to phone. So the call page states
 * the terms — prices, quotas, closing date, what is required — and the form is open to
 * anybody who reads them.
 *
 * ── AND A VENDOR CAN BE A PERSON ─────────────────────────────────────────────
 *
 * The first question the form asks is whether the applicant is a registered business or an
 * individual, and everything after it changes: a person is asked for their own full name and
 * a photo ID; a business is asked for its registered name and its CAC number. The reason is
 * in PartnerOrg — requiring a company registration from a woman with a jollof stall does not
 * raise the standard of the market, it hands every pitch to whoever already has a lawyer.
 *
 * ── WHAT REGISTERING BUYS ────────────────────────────────────────────────────
 *
 * A place in the queue and a dashboard to upload certificates to. Nothing else. The row is a
 * draft, flagged as self-registered, and cannot be offered a stand until somebody has read it.
 */
final class StandApplyController
{
    public function __construct(
        private readonly Twig              $view,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    private function redirect(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    private function eventBySlug(string $slug): ?object
    {
        $slug = trim($slug);
        if ($slug === '') return null;
        try {
            return DB::table('gates_site_events')->where('slug', $slug)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────── the call page ──────────────────────────

    /**
     * What is on offer, on what terms, closing when.
     *
     * Shown even when the call is closed or has not opened. A vendor who arrives a week late
     * is owed the sentence "this closed on the 14th" rather than a 404 that reads as though
     * the whole thing was imaginary — and next year they will know to look earlier.
     */
    public function call(Request $req, Response $res, array $args = []): Response
    {
        $event = $this->eventBySlug((string) ($args['slug'] ?? ''));
        if (!$event) return $this->redirect($res, '/events');

        $call = StandCall::forEvent((int) $event->id);
        if (!$call || (string) $call->status === StandCall::STATUS_DRAFT) {
            // A draft call is not a public fact. Its terms are still being written and
            // publishing half of them is how a quota gets quoted before it is decided.
            return $this->redirect($res, '/events/' . (string) $event->slug);
        }

        return $this->view->render($res, 'pages/stands/call.twig', [
            'page_title' => 'Trade at ' . (string) $event->title,
            'gates_page' => 'stands',
            'has_hero'   => false,
            'event'      => $event,
            'call'       => $call,
            'accepting'  => StandCall::isAccepting($call),
            'capacity'   => StandCall::capacity((int) $event->id),
            'categories' => StandType::CATEGORIES,
            'signed_in'  => OrgAuth::user() !== null,
            'offer_hours'=> StandApplication::OFFER_HOURS,
        ]);
    }

    // ──────────────────────────────── the form ──────────────────────────────

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $event = $this->eventBySlug((string) ($args['slug'] ?? ''));
        if (!$event) return $this->redirect($res, '/events');

        $call = StandCall::forEvent((int) $event->id);
        if (!StandCall::isAccepting($call)) {
            $_SESSION['org_flash_error'] = 'Applications for this event are closed.';
            return $this->redirect($res, '/events/' . (string) $event->slug . '/stands');
        }

        // A signed-in vendor never re-types who they are. The form collapses to the two
        // questions that are actually about this event.
        $user = OrgAuth::user();
        $org  = $user ? PartnerOrg::find((int) $user->org_id) : null;

        return $this->render($res, $event, $call, $org, [], null);
    }

    /**
     * Render the form, keeping whatever the applicant already typed.
     *
     * Every failure path comes back through here with the submitted values, because a
     * validation error that empties a nine-field form is a validation error that loses the
     * application.
     */
    private function render(
        Response $res, object $event, ?object $call, ?object $org, array $old, ?string $error
    ): Response {
        return $this->view->render($res, 'pages/stands/apply.twig', [
            'page_title' => 'Apply for a stand — ' . (string) $event->title,
            'gates_page' => 'stands',
            'has_hero'   => false,
            'lite_page'  => true,
            'event'      => $event,
            'call'       => $call,
            'capacity'   => StandCall::capacity((int) $event->id),
            'categories' => StandType::CATEGORIES,
            'org'        => $org,
            'entities'   => PartnerOrg::ENTITIES,
            'old'        => $old,
            'error'      => $error,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Register if they are new, then apply.
     *
     * The two halves are one request on purpose. Splitting them produces accounts belonging
     * to people who never finished applying — rows nobody will ever review and nobody will
     * ever delete.
     */
    public function submit(Request $req, Response $res, array $args = []): Response
    {
        $event = $this->eventBySlug((string) ($args['slug'] ?? ''));
        if (!$event) return $this->redirect($res, '/events');

        $call = StandCall::forEvent((int) $event->id);
        if (!StandCall::isAccepting($call)) {
            $_SESSION['org_flash_error'] = 'Applications for this event are closed.';
            return $this->redirect($res, '/events/' . (string) $event->slug . '/stands');
        }

        $b    = (array) $req->getParsedBody();
        $user = OrgAuth::user();
        $org  = $user ? PartnerOrg::find((int) $user->org_id) : null;

        $typeId = (int) ($b['stand_type_id'] ?? 0);
        $type   = StandType::find($typeId);
        if (!$type || (int) $type->event_id !== (int) $event->id) {
            return $this->render($res, $event, $call, $org, $b, 'Choose which kind of stand you want.');
        }

        // ── registering, if they are not already somebody ────────────────────
        if (!$user) {
            $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
            // Creating accounts is the expensive half of this endpoint and the only half
            // worth abusing. Throttled per address rather than per form, because a script
            // does not reuse an email.
            if ($this->rateLimit && $ip !== ''
                && !$this->rateLimit->check(hash('sha256', $ip), 'stand_register', 5, 3600)) {
                return $this->render($res, $event, $call, null, $b,
                    'Too many applications have been started from this connection. Try again in an hour.');
            }

            $r = PartnerOrg::registerVendor($b);
            if (!$r['ok']) {
                return $this->render($res, $event, $call, null, $b, $r['message']);
            }

            $user = $r['user'];
            if (!$user) {
                return $this->render($res, $event, $call, null, $b,
                    'The account was created but could not be signed in. Try signing in at /org/login.');
            }
            (new OrgAuth($this->rateLimit))->signIn($user);
            $org = PartnerOrg::find((int) $r['org_id']);
        }

        // ── and applying ────────────────────────────────────────────────────
        $r = StandApplication::submit((int) $user->org_id, $typeId, $b);
        if (!$r['ok']) {
            return $this->render($res, $event, $call, $org, $b, $r['message']);
        }

        // Straight to the dashboard, because what happens next is uploading documents — and
        // an application without them is not complete, which is what the tiebreak in §5.4
        // measures. Saying so here beats discovering it after the closing date.
        $missing = StandApplication::missingDocuments((int) $user->org_id);
        $_SESSION['org_flash_ok'] = $missing === []
            ? 'Application received for ' . (string) $event->title . '. You will hear from us '
              . 'either way, with a reason.'
            : 'Application received. It is not complete until you upload: '
              . implode(', ', $missing) . '. Applications are ranked by when they became '
              . 'complete, so this is worth doing today.';

        return $this->redirect($res, '/org');
    }
}
