<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Support\AdminNav;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\CycleMaterialiser;
use AfricaGates\Services\CpiService;
use AfricaGates\Services\RegistryCheck;
use AfricaGates\Services\RuleEngine;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * THE ADMINISTRATOR'S HANDBOOK — DOCUMENTATION WHERE AN ADMINISTRATOR IS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS A PAGE AND NOT A FILE IN docs/
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no SSH on production. An administrator cannot open a Markdown file in this
 * repository, and neither can the person they hand the console to. `docs/` is for whoever
 * is changing the code; this is for whoever is running the award.
 *
 * Written for that person: what each area is for, what the words mean, which actions are
 * irreversible, and what to do when a screen says something alarming. Not a description of
 * the schema.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY HALF OF IT IS COMPUTED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This codebase's most expensive documented fault is prose outliving the rule it
 * describes: an article promising the community half is "normalised inside each category"
 * survived the change that moved the denominator, a release screen described "the largest
 * organic vote count in the category" for a figure that was neither, and a settings screen
 * explained the default scoring basis using the arithmetic of a different one — 157 points
 * out, on the screen where an operator picks it.
 *
 * A handbook is the same shape of hazard with a wider blast radius, because people are
 * told to trust it. So every structural fact on the page is READ FROM THE CODE rather than
 * typed beside it: the areas and their order come from {@see AdminNav::sections()}, the
 * roles and what each may reach from {@see Permissions::MATRIX}, the scoring weights and
 * the judging quorum from {@see RuleEngine}, the verification states from
 * {@see RegistryCheck}, the announcement grace window from
 * {@see CycleMaterialiser::ANNOUNCE_GRACE_DAYS}.
 *
 * Change a permission and the handbook's permission table changes with it. What is left in
 * prose is judgement — why an action is dangerous, what to do when something looks wrong —
 * and that is the part a person has to write.
 *
 * `HandbookTest` holds the rest: every area is described, every role is listed, and the
 * page names no route that does not exist.
 */
final class HandbookController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');

        return $this->view->render($res, 'admin/handbook.twig', [
            'page_title'  => 'Handbook',
            'admin_page'  => 'handbook',

            // ── THE STRUCTURE, FROM THE STRUCTURE ────────────────────────────
            //
            // Not a copy. The rail is built from this same call, so a section renamed or
            // an area added appears in both places or in neither.
            'areas'       => AdminNav::sections(),

            // Who may reach what. `MATRIX` is the model itself; the labels come from
            // ROLES, whose second element is the sentence a person needs.
            'roles'       => Permissions::ROLES,
            'matrix'      => Permissions::MATRIX,
            'your_role'   => $role,
            'your_label'  => $role !== '' ? Permissions::label($role) : '',

            // ── THE NUMBERS AN ADMINISTRATOR GETS ASKED ABOUT ────────────────
            //
            // Read from the rule engine's defaults, so the handbook cannot quote a
            // weighting the scorer stopped using. `effective()` rather than DEFAULTS
            // because a programme or a cycle may override any of them, and the figure
            // worth publishing is the one in force.
            'rules'       => (new RuleEngine())->effective(null, null),
            'basis_ideal' => CpiService::BASIS_IDEAL,
            'grace_days'  => CycleMaterialiser::ANNOUNCE_GRACE_DAYS,

            // ── THE VERIFICATION STATES, FROM THE SERVICE'S OWN LIST ─────────
            //
            // `RegistryCheck::STATES` is the labels the screens already print. Retyping
            // them here is how a handbook comes to explain a word no screen uses — so the
            // list is read, and only the SENSE of each one is prose. That distinction is
            // the whole design of this page: the vocabulary is computed, the judgement is
            // written.
            'check_states' => RegistryCheck::STATES,
            'check_sense'  => [
                RegistryCheck::UNCHECKED => 'Nobody has asked a register about this one yet. '
                    . 'It is the starting state, not a finding.',
                RegistryCheck::CONFIRMED => 'A person looked it up on the public register and '
                    . 'said it matched. Their name is on it.',
                RegistryCheck::VERIFIED  => 'A configured verifier matched it without a person '
                    . 'in the loop.',
                RegistryCheck::REJECTED  => 'Somebody — or a verifier — said it does NOT match. '
                    . 'Treat this as a reason to stop, not a formatting problem.',
            ],
            'cac_search'   => RegistryCheck::CAC_SEARCH,
        ]);
    }
}
