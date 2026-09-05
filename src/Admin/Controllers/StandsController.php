<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\{PartnerOrg, StandApplication, StandCall, StandFloorPlan, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Running a call for vendor stands, and allocating what it brings in.
 *
 * ── THE SCREEN IS BUILT AROUND ONE ARGUMENT ──────────────────────────────────
 *
 * Every stand allocation ends in the same conversation: somebody who did not get a pitch
 * asks why the person next to them did. The only answer that survives that conversation is a
 * rule that was written down before anybody knew who applied, applied the same way to
 * everybody, with a reason recorded against each decision.
 *
 * So the layout is deliberate. The call's terms come first and become read-only the moment it
 * opens. Capacity is shown as a published number with what is left against it, not as a
 * count an organiser can talk themselves past. Eligibility is presented separately from
 * selection, because they are different kinds of "no". And the offer button is simply absent
 * on a full category — the quota is enforced in {@see StandApplication::offer()}, and this
 * screen agrees with it rather than inviting a click that will be refused.
 *
 * ── WHAT IS NOT HERE ─────────────────────────────────────────────────────────
 *
 * There is no "override quota", no "reopen criteria", and no way to delete an application.
 * Each would be one line of code and each would destroy the only thing that makes the rest of
 * this defensible. An organiser who needs a different rule closes the call and opens another
 * one, which is visible, dated and attributable.
 */
final class StandsController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }
    private function role(): string { return (string) ($_SESSION['admin_role'] ?? ''); }

    /**
     * Deciding who trades at an Africa GATES event is an integrity decision.
     *
     * Same gate as approving a partner organisation, and for the same reason: allocating a
     * pitch grants somebody the platform's implicit endorsement in front of a paying public,
     * and refusing one is a decision an applicant may reasonably challenge.
     */
    private function mayDecide(): bool
    {
        return Permissions::canManageIntegrity($this->role());
    }

    private function back(Response $res, int $eventId, string $anchor = ''): Response
    {
        return $res->withHeader('Location', '/admin/events/' . $eventId . '/stands' . $anchor)
                   ->withStatus(302);
    }

    private function event(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    // ──────────────────────────────── the screen ────────────────────────────

    public function index(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        $event   = $this->event($eventId);
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $call     = StandCall::forEvent($eventId);
        $capacity = StandCall::capacity($eventId);

        $q      = $req->getQueryParams();
        $filter = (string) ($q['decision'] ?? '');
        $rows   = $this->applicationRows($call, $filter, (int) ($q['type'] ?? 0));

        return $this->view->render($res, 'admin/stands/index.twig', [
            'page_title'  => 'Vendor stands — ' . (string) $event->title,
            'admin_page'  => 'events',
            'event'       => $event,
            'call'        => $call,
            'accepting'   => StandCall::isAccepting($call),
            'criteria'    => StandCall::criteria($call),
            'types'       => StandType::forEvent($eventId),
            'capacity'    => $capacity,
            'categories'  => StandType::categories(),
            'applications'=> $rows,
            'summary'     => $this->summary($call),
            'decisions'   => StandApplication::DECISIONS,
            'statuses'    => StandCall::STATUSES,
            'filter'      => $filter,
            'filter_type' => (int) ($q['type'] ?? 0),
            'offer_hours' => StandApplication::OFFER_HOURS,
            // The reason typed into a refused decision, so it comes back in its own row's
            // box. One-shot: consumed on render.
            'kept_reason' => $this->keptReason(),
            // Notices the tick has not sent yet. "Recorded" and "emailed" are different
            // facts and an organiser watching for a vendor's reply needs to know which one
            // they are in.
            'notices_pending' => \AfricaGates\Services\StandNotice::pending(),
            'may_decide'  => $this->mayDecide(),
            // ── WHO HAS PAID ─────────────────────────────────────────────
            //
            // The question on the morning of the market, and it had no answer anywhere in
            // the product: accept() said "you will be invoiced" and nothing invoiced
            // anybody. The ledger counts ACCEPTED pitches only — an open offer is a place
            // being held, and counting it as expected income lets this screen report a
            // figure that evaporates when the clock runs out.
            'fees'        => \AfricaGates\Services\StandFee::ledger($eventId),
            // ── SIZES AND THE FLOOR PLAN ─────────────────────────────────
            'sizes'       => StandType::SIZES,
            // The priced catalogue, so an organiser adds "6 × 6 ft — ₦10,000, how many?"
            // rather than retyping a size, a price and a category for every event.
            'presets'     => $presets = \AfricaGates\Services\StandPreset::all(),
            // The preset's size in the unit it was entered in, keyed by id, so the
            // dropdown reads "6 × 6 ft stand · 6 × 6 ft — ₦10,000" and an organiser is
            // not choosing between two names that differ only in a price.
            'preset_size'  => $this->presetSizes($presets),
            'preset_quota' => $this->presetQuota($presets),
            // Which stock size each EXISTING type matches, for its edit form's select.
            // Computed from the stored centimetres rather than remembered, so a row edited
            // by hand into 3 × 3 still opens showing the standard gazebo — and one at 183cm
            // opens on Custom, which is what it is.
            'size_of'      => $this->sizeKeys($capacity),
            'plan'        => $plan = StandFloorPlan::forEvent($eventId),
            // The drawing scales are computed here rather than in the template, because
            // getting them wrong renders a picture the size of a wall and Twig is a poor
            // place to discover that.
            'swatch_scale'=> $this->swatchScale($plan),
            'plan_scale'  => $this->planScale($plan),

            // ── THE THREE CHARTS, AS SPECS ───────────────────────────────────
            //
            // Built here and rendered by templates/partials/viz.twig — the same component
            // the account and partner dashboards use. They were three bespoke SVG macros in
            // an admin-only partial, which is how this page ended up with charts that had no
            // hover worth the name, no keyboard reading and no table behind them.
            'viz_budget' => \AfricaGates\Support\Viz::budget(
                'viz-floor', 'Floor committed against floor available',
                (float) ($plan['committed_sqm'] ?? 0), (float) ($plan['usable_sqm'] ?? 0),
                [
                    'suffix' => ' m²', 'limit_word' => 'sellable',
                    'used_name' => 'Committed by the quotas',
                    'limit_name' => 'Sellable after aisles',
                    'sub' => ($plan['aisle_pct'] ?? 35) . '% of the hall is set aside for aisles '
                           . 'before anything is sold.',
                ]
            ),
            'viz_plan' => \AfricaGates\Support\Viz::plan(
                'viz-hall', 'Indicative block layout', $plan,
                ['sub' => 'Hover or tab a pitch to name it. Use the legend to isolate a category.']
            ),
            'viz_mix' => \AfricaGates\Support\Viz::stack(
                'viz-mix', 'The mix you have published',
                array_map(static fn (array $c): array => [
                    'key'   => 'cat-' . (int) $c['index'],
                    'label' => (string) $c['label'],
                    'value' => (int) $c['quota'],
                    'slot'  => (int) $c['index'],
                ], $plan['categories'] ?? []),
                [
                    'col' => 'Category',
                    'suffix' => ' pitches',
                    'sub' => 'Twelve jewellery stalls and nothing to eat is a shape you can see '
                           . 'here and cannot see in a table. Fixed once the call opens.',
                ]
            ),
        ]);
    }

    /**
     * The reason from a refused decision, once.
     *
     * In the session rather than a query string because it is free text somebody typed
     * about a named business, and a rejection reason in a URL ends up in an access log, a
     * referrer header and the browser history of a shared office machine.
     *
     * @return array<int,string>
     */
    private function keptReason(): array
    {
        $kept = $_SESSION['stand_reason'] ?? null;
        unset($_SESSION['stand_reason']);

        return is_array($kept) ? $kept : [];
    }

    /**
     * Each preset's size label, keyed by id.
     *
     * @param list<object> $presets
     * @return array<int,string>
     */
    private function presetSizes(array $presets): array
    {
        $out = [];
        foreach ($presets as $p) {
            $out[(int) $p->id] = \AfricaGates\Services\StandPreset::label($p);
        }
        return $out;
    }

    /**
     * A sensible starting quota for the catalogue form.
     *
     * The box is `required`, so an empty one is a form that refuses itself on the first
     * press — and the catalogue already records a default per preset. The FIRST preset's
     * is used because that is the one the select opens on; a person choosing another
     * changes the number they were going to change anyway.
     *
     * @param list<object> $presets
     */
    private function presetQuota(array $presets): int
    {
        $first = $presets[0] ?? null;

        return max(1, (int) ($first->default_quota ?? 0) ?: 10);
    }

    /**
     * Existing type id → the stock size key it matches, or 'custom'.
     *
     * @param list<array<string,mixed>> $capacity
     * @return array<int,string>
     */
    private function sizeKeys(array $capacity): array
    {
        $out = [];
        foreach ($capacity as $c) {
            $out[(int) $c['type']->id] = StandType::presetFor($c['type']);
        }
        return $out;
    }

    /**
     * Pixels per metre for the size swatches.
     *
     * ONE scale for every swatch on the page, which is the entire point of drawing them: a
     * 6 × 3 has to look like twice a 3 × 3. Sizing each swatch to fit its own box would make
     * every stand type the same size on screen, which is worse than printing the numbers.
     */
    private function swatchScale(array $plan): float
    {
        $widest = 3.0;
        foreach ($plan['types'] as $r) {
            $widest = max($widest, (int) $r['type']->width_cm / 100);
        }
        return round(min(42.0, 210 / $widest), 2);
    }

    /** Pixels per metre for the hall, so a hall of any size lands about 880px across. */
    private function planScale(array $plan): float
    {
        $w = $plan['floor_w_cm'] / 100;
        if ($w < 1) return 10.0;
        return round(min(30.0, 880 / $w), 2);
    }

    /**
     * Each application with the vendor beside it, and what is missing.
     *
     * The joins happen here rather than in the template because a template that fetches is a
     * template that fetches once per row — and this page routinely lists two hundred.
     *
     * @return array<int,array<string,mixed>>
     */
    private function applicationRows(?object $call, string $filter = '', int $typeId = 0): array
    {
        if (!$call) return [];

        $apps = StandApplication::forCall((int) $call->id);
        if ($apps === []) return [];

        $orgIds  = array_values(array_unique(array_map(static fn($a) => (int) $a->org_id, $apps)));
        $typeIds = array_values(array_unique(array_map(static fn($a) => (int) $a->stand_type_id, $apps)));

        $orgs  = DB::table('gates_partner_orgs')->whereIn('id', $orgIds)->get()->keyBy('id');
        $types = DB::table('gates_stand_types')->whereIn('id', $typeIds)->get()->keyBy('id');

        $out = [];
        foreach ($apps as $a) {
            if ($filter !== '' && (string) $a->decision !== $filter) continue;
            if ($typeId > 0 && (int) $a->stand_type_id !== $typeId) continue;

            $org = $orgs[(int) $a->org_id] ?? null;
            $out[] = [
                'app'        => $a,
                'org'        => $org,
                'type'       => $types[(int) $a->stand_type_id] ?? null,
                'individual' => PartnerOrg::isIndividual($org),
                'missing'    => StandApplication::missingDocuments((int) $a->org_id),
                // Live rather than read off the row: a document that lapsed since the last
                // check makes an application ineligible today whatever the column says.
                'expired'    => (string) $a->eligibility === StandApplication::ELIGIBILITY_FAIL,
            ];
        }
        return $out;
    }

    /**
     * The headline counts, so the top of the page answers "where is this call up to".
     *
     * @return array<string,int>
     */
    private function summary(?object $call): array
    {
        $out = ['total' => 0, 'complete' => 0] + array_fill_keys(array_keys(StandApplication::DECISIONS), 0);
        if (!$call) return $out;

        foreach (StandApplication::forCall((int) $call->id) as $a) {
            $out['total']++;
            if (trim((string) ($a->completed_at ?? '')) !== '') $out['complete']++;
            $d = (string) $a->decision;
            if (isset($out[$d])) $out[$d]++;
        }
        return $out;
    }

    // ──────────────────────────────── the call ──────────────────────────────

    public function saveCall(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can set the terms of a call.';
            return $this->back($res, $eventId);
        }
        if (!$this->event($eventId)) return $res->withHeader('Location', '/admin/events')->withStatus(302);

        $r = StandCall::save($eventId, (array) $req->getParsedBody());
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_call.save', 'event', $eventId);
        return $this->back($res, $eventId);
    }

    /**
     * Publish the call and lock its terms. One way, and the confirmation says so.
     */
    public function openCall(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can open a call for stands.';
            return $this->back($res, $eventId);
        }

        $call = StandCall::forEvent($eventId);
        if (!$call) {
            $_SESSION['flash_error'] = 'Set the terms of the call first.';
            return $this->back($res, $eventId);
        }

        $r = StandCall::open((int) $call->id, $this->adminId());
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_call.open', 'event', $eventId);
        return $this->back($res, $eventId);
    }

    /**
     * Reopen a closed call, on the terms it was published with.
     *
     * Closing used to be a one-way door: a call shut a week early by accident, or one that
     * closed on schedule with four pitches unfilled, had no route back except a SECOND call
     * for the same event — competing with the first on the same page, with its own quotas
     * that the capacity check would then double-count.
     *
     * Reopening does not unlock anything. The criteria snapshot, the quotas and the prices
     * stay exactly as published; the only thing that moves is the closing date, because a
     * call cannot accept an application on Monday while still saying it closed on Friday.
     */
    public function reopenCall(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can reopen a call.';
            return $this->back($res, $eventId);
        }

        $call = StandCall::forEvent($eventId);
        if (!$call) return $this->back($res, $eventId);

        $b = (array) $req->getParsedBody();
        $r = StandCall::reopen((int) $call->id, (string) ($b['closes_at'] ?? ''));

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_call.reopen', 'event', $eventId);
        return $this->back($res, $eventId);
    }

    /**
     * Put one settled application back in front of the panel.
     *
     * Every decision on that table was one-way. An offer that ran out while the trader was
     * at a funeral, a rejection recorded against the wrong row, a waitlisted applicant there
     * is now room for — the only workaround was asking the vendor to apply again, which
     * loses their place in the completeness tiebreak and rewrites their record as though the
     * first application never happened.
     */
    public function reopenApplication(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can reopen an application.';
            return $this->back($res, $eventId);
        }

        $b = (array) $req->getParsedBody();
        $r = StandApplication::reopen((int) ($args['app'] ?? 0), $this->adminId(),
                                      (string) ($b['note'] ?? ''));

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(), 'stand_application.reopen', 'event', $eventId);
        }
        return $this->back($res, $eventId);
    }

    public function closeCall(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can close a call.';
            return $this->back($res, $eventId);
        }

        $call = StandCall::forEvent($eventId);
        if (!$call) return $this->back($res, $eventId);

        $r = StandCall::close((int) $call->id);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_call.close', 'event', $eventId);
        return $this->back($res, $eventId);
    }

    /**
     * Record the hall's measurements.
     *
     * Deliberately NOT routed through saveCall(): the venue is exempt from the lock, because
     * how wide a hall is, is a fact rather than a rule. {@see StandCall::savePlan()} carries
     * the full reasoning, and it is a separate method rather than a flag because a flag is a
     * thing somebody eventually passes from the wrong form.
     */
    public function savePlan(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can record the venue.';
            return $this->back($res, $eventId, '#plan');
        }
        if (!$this->event($eventId)) return $res->withHeader('Location', '/admin/events')->withStatus(302);

        $r = StandCall::savePlan($eventId, (array) $req->getParsedBody());
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_call.venue', 'event', $eventId);
        return $this->back($res, $eventId, '#plan');
    }

    // ───────────────────────────── what is on offer ─────────────────────────

    public function saveType(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can change what is on offer.';
            return $this->back($res, $eventId, '#types');
        }
        if (!$this->event($eventId)) return $res->withHeader('Location', '/admin/events')->withStatus(302);

        $b = (array) $req->getParsedBody();
        $r = StandType::save($eventId, $b, (int) ($b['type_id'] ?? 0));

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_type.save', 'event', $eventId);
        return $this->back($res, $eventId, '#types');
    }

    /**
     * Add a stand type to this event from the priced catalogue.
     *
     * The quota is the only thing asked for, because it is the only thing a catalogue cannot
     * know: how many pitches fit is a fact about a room. Everything else — size, price,
     * deposit, category, power, step-free — is copied from the preset.
     */
    public function applyPreset(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);

        // Guarded like every other write on this page. It was NOT: this was the one action
        // that skipped mayDecide(), so any role that could load the screen could add a
        // priced stand type to a call. The lock on an open call was enforced (StandType::save
        // refuses one), but who may set the terms was not.
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can change what is on offer.';
            return $this->back($res, $eventId, '#types');
        }
        if (!$this->event($eventId)) return $res->withHeader('Location', '/admin/events')->withStatus(302);

        $b = (array) $req->getParsedBody();

        $r = \AfricaGates\Services\StandPreset::applyTo(
            $eventId,
            (int) ($b['preset_id'] ?? 0),
            (int) preg_replace('/[^0-9]/', '', (string) ($b['quota'] ?? '0')),
            $this->adminId()
        );

        $_SESSION[($r['ok'] ?? false) ? 'flash_ok' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(), 'stand.preset.apply', 'event', $eventId);
        }

        // Back to #types, like the form beside it. Redirecting to the top of a screen this
        // long meant the confirmation — when it was rendered at all — was several scrolls
        // above the table it was about, and the page looked as though nothing had happened.
        return $this->back($res, $eventId, '#types');
    }

    public function deleteType(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can remove a stand type.';
            return $this->back($res, $eventId, '#types');
        }

        $type = StandType::find((int) ($args['type'] ?? 0));
        if (!$type || (int) $type->event_id !== $eventId) return $this->back($res, $eventId, '#types');

        $r = StandType::delete((int) $type->id);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_type.delete', 'event', $eventId);
        return $this->back($res, $eventId, '#types');
    }

    // ─────────────────────────────── eligibility ────────────────────────────

    /**
     * Run the objective gate over every application in the call.
     *
     * A bulk action rather than a per-row one, because eligibility carries no judgement:
     * there is nothing to weigh row by row, and making a reviewer click two hundred times is
     * how the check quietly stops being run at all. Selection stays one at a time.
     */
    public function checkAll(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can run the eligibility check.';
            return $this->back($res, $eventId, '#apps');
        }

        $call = StandCall::forEvent($eventId);
        if (!$call) return $this->back($res, $eventId);

        $pass = $fail = 0;
        foreach (StandApplication::forCall((int) $call->id) as $a) {
            // Completeness first: a document that landed since submission may be what turns
            // an incomplete application into one with a tiebreak timestamp.
            StandApplication::refreshCompleteness((int) $a->id);
            $r = StandApplication::checkEligibility((int) $a->id);
            $r['ok'] ? $pass++ : $fail++;
        }

        $this->audit->record($this->adminId(), 'stand_apps.checked', 'event', $eventId);
        $_SESSION['flash_ok'] = 'Checked ' . ($pass + $fail) . ' application(s): ' . $pass
                              . ' eligible, ' . $fail . ' not. Ineligibility is a missing or '
                              . 'lapsed document, not a decision — they can still fix it.';
        return $this->back($res, $eventId, '#apps');
    }

    // ──────────────────────────────── decisions ─────────────────────────────

    /**
     * Offer, waitlist or reject one application.
     *
     * The quota check and the "a rejection needs a reason" rule both live in the service, so
     * this method cannot be the place either gets forgotten. It routes, records and reports.
     */
    public function decide(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        if (!$this->mayDecide()) {
            $_SESSION['flash_error'] = 'Only an admin can decide an application.';
            return $this->back($res, $eventId, '#apps');
        }

        $app = StandApplication::find((int) ($args['app'] ?? 0));
        if (!$app || (int) $app->event_id !== $eventId) {
            $_SESSION['flash_error'] = 'That application does not belong to this event.';
            return $this->back($res, $eventId, '#apps');
        }

        $b        = (array) $req->getParsedBody();
        $decision = (string) ($b['decision'] ?? '');
        $reason   = trim((string) ($b['reason'] ?? ''));

        $r = $decision === StandApplication::DECISION_OFFERED
            ? StandApplication::offer((int) $app->id, $this->adminId(), $reason)
            : StandApplication::decide((int) $app->id, $decision, $this->adminId(), $reason);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(), 'stand_app.' . $decision, 'stand_application', (int) $app->id);
            unset($_SESSION['stand_reason']);
        } else {
            // ── KEEP WHAT THEY TYPED ────────────────────────────────────────
            //
            // A rejection with no reason is refused on purpose, so this branch is REACHED
            // by design and reached most often by somebody who has just written three
            // sentences explaining a decision. Losing them and returning to the top of a
            // two-hundred-row page is the shape of WCAG 3.3.7 Redundant Entry, and in
            // practice it is how a considered reason becomes "not selected".
            if ($reason !== '') $_SESSION['stand_reason'] = [(int) $app->id => $reason];
        }
        return $this->back($res, $eventId, '#apps');
    }

    // ────────────────────────────── the pitch list ──────────────────────────

    /**
     * The allocation sheet, as a file somebody can take to the market.
     *
     * Accepted stands only, with the contact who will actually be standing there and the
     * access requirements that decide where they are placed. This is the artefact the whole
     * screen exists to produce: on the morning, nobody is logging in to check a dashboard.
     */
    public function exportCsv(Request $req, Response $res, array $args = []): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        $event   = $this->event($eventId);
        if (!$event) return $res->withHeader('Location', '/admin/events')->withStatus(302);

        $call = StandCall::forEvent($eventId);
        $rows = $this->applicationRows($call, StandApplication::DECISION_ACCEPTED);

        $out = fopen('php://temp', 'r+');
        // The escape argument is passed explicitly on both calls: PHP 8.4 deprecates leaving
        // it to a default that is about to change, and a CSV whose quoting silently changes
        // under an interpreter upgrade is a spreadsheet that opens wrong on the morning.
        // TWO category columns, named apart. The first is the PITCH's — the organiser's own
        // bucket, what the quota is set against. The second is what the VENDOR said they
        // trade in, which is a different claim and can disagree: a "3m corner pitch" filed
        // under `general` is picked for its size, and two vendors choosing it are not in
        // the same trade. One column called "Category" carrying whichever of those it
        // happened to be is how an allocation sheet gets read wrong on the morning.
        fputcsv($out, ['Stand type', 'Pitch category', 'Trade declared', 'Vendor', 'Trading as',
                       'Kind', 'Contact', 'Phone', 'Email', 'Sells', 'Power', 'Step-free',
                       'Fee (NGN)', 'Accepted'],
                ',', '"', '\\');

        foreach ($rows as $r) {
            $org  = $r['org'];
            $type = $r['type'];
            fputcsv($out, [
                (string) ($type->name ?? ''),
                (string) (StandType::categories()[(string) ($type->category ?? '')] ?? ''),
                // Falls back to the pitch's category for an application submitted before
                // the form asked, marked "(from pitch)" — see StandApplication::trade().
                // A blank cell is a row somebody has to look up on the morning of a
                // market; an unmarked inference is worse, because it reads as a fact the
                // vendor stated.
                StandApplication::trade($r['app'], $type)['label'],
                PartnerOrg::legalNameOf($org),
                (string) ($org->name ?? ''),
                $r['individual'] ? 'Individual' : 'Registered business',
                (string) ($org->contact_name  ?? ''),
                (string) ($org->contact_phone ?? ''),
                (string) ($org->contact_email ?? ''),
                (string) ($r['app']->what_they_sell ?? ''),
                ((int) $r['app']->needs_power     === 1) ? 'yes' : '',
                ((int) $r['app']->needs_step_free === 1) ? 'yes' : '',
                (int) ($type->price_naira ?? 0),
                substr((string) ($r['app']->decided_at ?? ''), 0, 16),
            ], ',', '"', '\\');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $res->getBody()->write($csv);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition',
                'attachment; filename="stands-' . $eventId . '-' . date('Y-m-d') . '.csv"');
    }
}
