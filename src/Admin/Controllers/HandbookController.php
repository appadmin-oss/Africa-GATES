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
        $rules = (new RuleEngine())->effective(null, null);

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
            'rules'       => $rules,

            // ── AND THE PART OF §5 THAT IS A RULE RATHER THAN A WEIGHT ───────
            //
            // The weights above were read from the engine from the day this page
            // shipped. The SHAPE of the community half was not: §5 stated the `ideal`
            // basis, the edition scope and the linear judge scale as plain fact, in
            // prose, while `RuleEngine` still resolves all four bases, both scopes and
            // both scales — deliberately, because an announced standing has to stay
            // reproducible to the digit.
            //
            // So an operator whose cycle carried `judge_scale = curved` read "No floor,
            // no curve" in the handbook, and one on `community_scope = category` read
            // "in the whole edition — not in their own category". Exactly the fault the
            // docblock above is about, on the one document every role can open and is
            // told to trust.
            //
            // The tell was already here: `basis_ideal` was passed to the template and
            // read by nothing. Somebody meant to write the branch.
            //
            // Resolved through the scorer's own normalisers, not compared as strings
            // here, so a stored typo is described the way it will be SCORED.
            'scoring'     => self::scoring($rules),
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

    /**
     * WHICH SCORING RULES ARE IN FORCE, AND THE WORKED EXAMPLE THEY PRODUCE.
     *
     * Every value here is either read from {@see RuleEngine} or COMPUTED BY THE SCORER.
     * The example in particular: `community` calls {@see CpiService::communityPart()}
     * with the same signature `NomineeScoringService` uses and scales it through
     * {@see CpiService::split()}'s arithmetic, so the two figures the handbook prints
     * are the two figures the platform would award. They cannot go stale, because there
     * is nothing to update — changing the rule changes the example in the same commit.
     *
     * They used to be typed: "one backed by a thousand people scores 293, one backed by
     * two scores 135", four lines under a computed `{{ weight × 1000 }} points`. A
     * programme on `community_weight = 0.30` therefore rendered "300 points" and the
     * 450-point ladder together, on the same screen, and `HandbookTest` stepped over it
     * because it forbids the string `450 points` and the heading reads `450.`.
     *
     * ── WHY THE EXAMPLE IS WITHHELD UNDER TWO OF THE FOUR BASES ──────────────
     *
     * `relative` and `absolute` have no people term at all, so "one backed by a thousand
     * people and one backed by two" scores the pair IDENTICALLY under them — a worked
     * example that answers the reader's question with a tautology. Under those the page
     * says the number of separate supporters does not enter the calculation, which is
     * the fact, and shows no arithmetic. A wrong worked example is worse than none: it
     * is what somebody checks their understanding against.
     *
     * Public so `HandbookTest` renders the page from the SAME payload the controller
     * builds. A render test that assembles its own context is testing a page nobody
     * serves — which is how `basis_ideal` sat in the context, unread, through fourteen
     * passing tests.
     *
     * @param array<string,mixed> $rules
     * @return array<string,mixed>
     */
    public static function scoring(array $rules): array
    {
        $basis = CpiService::basis(isset($rules['community_basis']) ? (string) $rules['community_basis'] : null);
        $scope = CpiService::scope(isset($rules['community_scope']) ? (string) $rules['community_scope'] : null);
        $scale = CpiService::judgeScale(isset($rules['judge_scale']) ? (string) $rules['judge_scale'] : null);

        $cw     = (float) ($rules['community_weight'] ?? 0.45);
        $people = (int) round(CpiService::REACH_PEOPLE_SHARE * 100);

        // The two nominees of the example: the same tally, wildly different backing, on
        // a cohort whose largest tally is that same figure. Held here so both rows are
        // scored against ONE cohort — two calls with different maxima is the shape that
        // produces a pair of numbers nothing could ever have awarded together.
        $tally = 2000;
        $broad = 1000;
        $narrow = 2;

        $points = static fn (int $unique): int => (int) round($cw * CpiService::communityPart(
            $tally, $tally, null, null, $basis, $unique, $broad) * 1000);

        // Only the two bases that HAVE a people term can answer the question the example
        // is asked to answer. See the docblock.
        $hasPeopleTerm = in_array($basis, [CpiService::BASIS_IDEAL, CpiService::BASIS_REACH], true);

        return [
            'basis'        => $basis,
            'is_ideal'     => $basis === CpiService::BASIS_IDEAL,
            'is_reach'     => $basis === CpiService::BASIS_REACH,
            'is_relative'  => $basis === CpiService::BASIS_RELATIVE,
            'is_absolute'  => $basis === CpiService::BASIS_ABSOLUTE,
            'people_pct'   => $people,
            'votes_pct'    => 100 - $people,
            'by_category'  => $scope === CpiService::SCOPE_CATEGORY,
            'curved'       => $scale === CpiService::SCALE_CURVED,
            'half'         => (int) round($cw * 1000),
            'example'      => $hasPeopleTerm ? [
                'tally'  => $tally,
                'broad'  => $broad,
                'narrow' => $narrow,
                'broad_points'  => $points($broad),
                'narrow_points' => $points($narrow),
            ] : null,
        ];
    }
}
