<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\{OrgAuth, PartnerOrg, RateLimitService, StandApplication, StandCall, StandPhotos, StandType};
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

        // ── A DRAFT CALL RENDERS THE PAGE WITHOUT THE TERMS ─────────────────
        //
        // A draft call is not a public fact: its terms are still being written and
        // publishing half of them is how a quota gets quoted before it is decided. That
        // invariant is unchanged — nothing below reaches the template, and the template
        // has no prices, quotas or dates to leak.
        //
        // What changed is the response. This used to bounce to the event page with a
        // flash, which reads as though you asked for something that was never there. The
        // page now says the terms are not published yet, says that arriving first gains
        // nothing, and asks for an address — which is the one thing worth doing with the
        // most motivated visitor this page will ever have.
        $published = $call !== null && (string) $call->status !== StandCall::STATUS_DRAFT;

        if (!$published) {
            return $this->view->render($res, 'pages/stands/call.twig', [
                'page_title' => 'Trade at ' . (string) $event->title,
                'gates_page' => 'stands',
                'has_hero'   => false,
                'event'      => $event,
                'published'  => false,
                'accepting'  => false,
                // The exact string the opening notice looks the list up by.
                'notify_source' => \AfricaGates\Services\StandCallNotice::source((string) $event->slug),
                'capacity'   => [],
                'categories' => [],
                'signed_in'  => OrgAuth::user() !== null,
                'offer_hours'=> StandApplication::OFFER_HOURS,
            ]);
        }

        return $this->view->render($res, 'pages/stands/call.twig', [
            'page_title' => 'Trade at ' . (string) $event->title,
            'gates_page' => 'stands',
            'has_hero'   => false,
            'event'      => $event,
            'call'       => $call,
            'published'  => true,
            'accepting'  => StandCall::isAccepting($call),
            'capacity'   => StandCall::capacity((int) $event->id),
            'categories' => StandType::categories(),
            'signed_in'  => OrgAuth::user() !== null,
            'offer_hours'=> StandApplication::OFFER_HOURS,
            // ── THE HALL, PUBLISHED ─────────────────────────────────────────
            //
            // The organiser already has this picture. Publishing it is what makes "2 of 6
            // still open" mean something to somebody deciding whether to spend an evening
            // on the form — a quota is an abstraction until you can see how much room it
            // is competing for.
            //
            // Through Viz like every other chart on this platform, which is what carries
            // the legend, the keyboard readout and the table of figures beneath it. The
            // table opens by default HERE and not in the console: on a public page the
            // numbers are the point, and a drawing captioned "indicative only" is worth
            // less than the figures under it.
            'hall'       => \AfricaGates\Support\Viz::plan(
                'vizHall',
                'Where the stands sit',
                \AfricaGates\Services\StandFloorPlan::forEvent((int) $event->id),
                ['table_open' => true,
                 'sub'   => 'To scale, from the hall as measured',
                 'empty' => 'The hall for this event has not been measured yet, so there is no '
                          . 'layout to show. The quotas above are the whole of the offer either way.']
            ),
        ] + $this->deadline($call));
    }

    /**
     * How long is left, in the words the page uses.
     *
     * ── WHY THE CLOCK ONLY TICKS INSIDE 48 HOURS ────────────────────────────
     *
     * A live hrs/min/sec countdown against a fortnight is manufactured urgency, and on
     * this page it would contradict the sentence directly beneath it: nothing is
     * allocated first-come. Inside the platform's existing `closing_soon` window it is
     * simply true — the deadline really is the thing to act on — so that is the only
     * place it runs.
     *
     * ── AND WHY THE ZONE IS NAMED RATHER THAN ASSUMED ───────────────────────
     *
     * "WEST AFRICA TIME" is the design's string and it is correct for the platform's own
     * timezone. It would be a lie on an installation whose display zone is somewhere
     * else, so anywhere but Lagos it falls back to the abbreviation the rest of the
     * platform prints.
     *
     * @return array{days_left:?int, hours_left:int, closing_soon:bool, closes_iso:string, zone_label:string}
     */
    private function deadline(object $call): array
    {
        $zone = \AfricaGates\Support\DisplayTime::zone();
        $out  = [
            'days_left'    => null,
            'hours_left'   => 0,
            'closing_soon' => false,
            'closes_iso'   => '',
            'zone_label'   => $zone === \AfricaGates\Support\DisplayTime::DEFAULT_ZONE
                ? 'WEST AFRICA TIME'
                : strtoupper(\AfricaGates\Support\DisplayTime::abbr()),
        ];

        $closes = trim((string) ($call->closes_at ?? ''));
        if ($closes === '') return $out;

        try {
            $tz  = new \DateTimeZone($zone);
            $end = new \DateTimeImmutable($closes, $tz);
            $now = new \DateTimeImmutable('now', $tz);
        } catch (\Throwable) {
            return $out;
        }

        $seconds = $end->getTimestamp() - $now->getTimestamp();
        if ($seconds <= 0) return $out;

        $out['days_left']    = (int) ceil($seconds / 86400);
        $out['hours_left']   = (int) ceil($seconds / 3600);
        $out['closing_soon'] = $seconds <= \AfricaGates\Services\CyclePolicy::CLOSING_SOON_SECONDS;
        $out['closes_iso']   = $end->format(\DateTimeInterface::ATOM);

        return $out;
    }

    // ──────────────────────────────── the form ──────────────────────────────

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $event = $this->eventBySlug((string) ($args['slug'] ?? ''));
        if (!$event) return $this->redirect($res, '/events');

        $call = StandCall::forEvent((int) $event->id);

        // ── SAY WHICH KIND OF "NO" THIS IS ──────────────────────────────────
        //
        // All three of these used to be the same sentence — "applications for this event
        // are closed" — written to a session key that NO PUBLIC TEMPLATE RENDERED. So the
        // vendor clicked Apply, landed back on the page they came from, and were told
        // nothing whatsoever. Three different situations, one blank screen.
        if (!$call || (string) $call->status === StandCall::STATUS_DRAFT) {
            $_SESSION['flash_notice'] = 'Stand applications for ' . (string) $event->title
                . ' have not opened yet. The terms will be published here first.';
            return $this->redirect($res, '/events/' . (string) $event->slug);
        }

        if (!StandCall::isAccepting($call)) {
            $_SESSION['flash_error'] = StandCall::whyNotAccepting($call);
            return $this->redirect($res, '/events/' . (string) $event->slug . '/stands');
        }

        // An open call with no stand types is a form whose only required question has no
        // answers. Submitting it can only fail, so it is not shown: the organiser has
        // opened the call before deciding what is on offer, and the vendor needs to come
        // back rather than fight a select with nothing in it.
        if (StandType::forEvent((int) $event->id) === []) {
            $_SESSION['flash_notice'] = 'The stands for ' . (string) $event->title
                . ' are still being laid out, so there is nothing to apply for yet. '
                . 'The sizes and prices appear on this page as soon as they are set.';
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
        Response $res, object $event, ?object $call, ?object $org, array $old, ?string $error,
        string $field = ''
    ): Response {
        return $this->view->render($res, 'pages/stands/apply.twig', [
            'page_title' => 'Apply for a stand — ' . (string) $event->title,
            'gates_page' => 'stands',
            'has_hero'   => false,
            'lite_page'  => true,
            // ── NO BOTTOM BAR ON THE FORM ───────────────────────────────────
            //
            // Signed off with the design. Under 900px the site has no top nav — it has the
            // bottom bar, which on this page would sit directly under the submit button and
            // offer four ways to leave a part-filled form. A four-step task with a docked
            // primary action gets the screen to itself.
            //
            // A MARKER and not `hide_chrome`, deliberately: hide_chrome removes the header
            // as well, and the desktop frame of this design has the header on it. What has
            // to go is the mobile bar, and only under 900px, which is a page CSS rule keyed
            // on this attribute.
            'task_page'  => true,
            'event'      => $event,
            'call'       => $call,
            'capacity'   => StandCall::capacity((int) $event->id),
            'categories' => StandType::categories(),
            'org'        => $org,
            'entities'   => PartnerOrg::ENTITIES,
            // What each route will be asked to upload, resolved from the same list the
            // completeness check reads. Told BEFORE applying rather than in the flash
            // message afterwards: it is the one fact that changes whether somebody starts
            // the form today or goes to find a certificate first, and where applications
            // are otherwise equal the tiebreak is who became complete first.
            'docs'       => [
                PartnerOrg::ENTITY_INDIVIDUAL => PartnerOrg::vendorDocuments(PartnerOrg::ENTITY_INDIVIDUAL),
                PartnerOrg::ENTITY_BUSINESS   => PartnerOrg::vendorDocuments(PartnerOrg::ENTITY_BUSINESS),
            ],
            'old'        => $old,
            // Which input the message is about, so the form can mark it, describe it and
            // focus it. Empty means "no single field" — a closed call, a rate limit — and
            // the banner stands alone, which is the right treatment for those.
            'bad_field'  => $field,
            // The textarea's maxlength, from the same constant the server enforces. Two
            // literals is how a browser lets 3,000 characters through into a column that
            // keeps 2,000.
            'sells_max'  => StandApplication::SELLS_MAX,
            // The photograph rules, from the constants the server enforces rather than
            // typed a second time into the template. The counter on the form is a
            // convenience; StandPhotos is the rule.
            'photo_min'       => StandPhotos::MIN,
            'photo_max'       => StandPhotos::MAX,
            'photo_max_bytes' => 10 * 1024 * 1024,
            'photo_accept'    => 'image/jpeg,image/png,image/webp,image/gif',
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
            // Reached when a call closes between opening the form and submitting it, which
            // is exactly when somebody has just typed nine fields. They are owed the reason
            // and the closing time, not a bare "closed".
            $_SESSION['flash_error'] = StandCall::whyNotAccepting($call);
            return $this->redirect($res, '/events/' . (string) $event->slug . '/stands');
        }

        $b    = (array) $req->getParsedBody();
        $user = OrgAuth::user();
        $org  = $user ? PartnerOrg::find((int) $user->org_id) : null;

        $typeId = (int) ($b['stand_type_id'] ?? 0);
        $type   = StandType::find($typeId);
        if (!$type || (int) $type->event_id !== (int) $event->id) {
            return $this->render($res, $event, $call, $org, $b,
                                 'Choose which kind of stand you want.', 'stand_type_id');
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
                return $this->render($res, $event, $call, null, $b, $r['message'],
                                     (string) ($r['field'] ?? ''));
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
            return $this->render($res, $event, $call, $org, $b, $r['message'],
                                 (string) ($r['field'] ?? ''));
        }

        // ── THE PHOTOGRAPHS THAT RODE ALONG ─────────────────────────────────
        //
        // Staged in the browser and posted with everything else, because the application
        // did not exist until the line above and there was nothing to attach them to.
        //
        // Best-effort, and that is the whole design: the application is already recorded.
        // A rejected photograph must never cost somebody the form they just filled in — it
        // costs them completeness, which they can fix from the dashboard while the call is
        // still open. What they are told is which ones did not make it and why.
        $rejected = $this->storePhotos($req, (int) $r['id'], (int) $user->org_id);

        // Straight to the dashboard, because what happens next is uploading documents — and
        // an application without them is not complete, which is what the tiebreak in §5.4
        // measures. Saying so here beats discovering it after the closing date.
        $missing = StandApplication::missingForCompleteness((int) $r['id']);
        $_SESSION['org_flash_ok'] = $missing === []
            ? 'Application received for ' . (string) $event->title . '. You will hear from us '
              . 'either way, with a reason.'
            : 'Application received. It is not complete until you upload: '
              . implode(', ', $missing) . '. Applications are ranked by when they became '
              . 'complete, so this is worth doing today.';

        if ($rejected !== []) {
            // A separate line, and an error rather than a note. Somebody who watched three
            // thumbnails appear on the form and then reads a cheerful success page would
            // otherwise believe the photographs are on file.
            $_SESSION['org_flash_error'] = count($rejected) === 1
                ? 'One photograph did not save: ' . $rejected[0] . ' Add it again below.'
                : count($rejected) . ' photographs did not save: ' . implode(' ', $rejected)
                  . ' Add them again below.';
        }

        return $this->redirect($res, '/org');
    }

    /**
     * File the photographs that came with the form.
     *
     * @return list<string> what was refused, in words, one sentence each
     */
    private function storePhotos(Request $req, int $applicationId, int $orgId): array
    {
        $files = $req->getUploadedFiles()['photos'] ?? [];
        if (!is_array($files) || $files === [] || $applicationId < 1) return [];

        $uploads  = new UploadService();
        $rejected = [];

        foreach ($files as $f) {
            if (!$f instanceof \Psr\Http\Message\UploadedFileInterface) continue;
            if ($f->getError() === UPLOAD_ERR_NO_FILE) continue;

            $name = trim((string) $f->getClientFilename()) ?: 'a photograph';
            $r    = StandPhotos::add($applicationId, $orgId, $f, $uploads);
            if (!$r['ok']) $rejected[] = $name . ' — ' . rtrim((string) $r['message'], '.') . '.';
        }

        return $rejected;
    }
}
