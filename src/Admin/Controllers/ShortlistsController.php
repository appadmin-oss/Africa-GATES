<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\ShortlistPdf;
use AfricaGates\Services\ShortlistRule;
use AfricaGates\Services\ShortlistService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Shortlisting: set the rule, watch the preview, publish when it is right.
 *
 * ── THE SHAPE OF THIS SCREEN IS THE POINT ────────────────────────────────────
 *
 * The rule and the shortlist are edited in the same place and saved by different buttons,
 * because an organiser tuning a threshold is doing something reversible and an organiser
 * publishing is not. Saving a rule changes a preview. Publishing writes a document with
 * their name on it. Two verbs, two buttons, and the destructive one asks.
 *
 * A single "Save" that did both would mean somebody adjusting a number to see what happens
 * has announced a shortlist, which is the one mistake this feature must not make possible.
 *
 * ── AND WHY PUBLISHING IS NARROWER THAN VIEWING ──────────────────────────────
 *
 * The section is `programmes`, which an EDITOR holds — correctly, because the categories
 * and cycles a shortlist is drawn from are theirs. Publishing is not: it is the moment the
 * field is cut, and it carries the same weight as announcing a result. So the publish and
 * withdraw routes carry their own RoleMiddleware('superadmin','admin'). An editor can see
 * every preview and change every threshold, and cannot make one final.
 */
final class ShortlistsController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    // ───────────────────────────────── screens ───────────────────────────────

    public function index(Request $req, Response $res): Response
    {
        $cycles = $this->cycles();
        $cycleId = (int) ($req->getQueryParams()['cycle'] ?? 0);
        if ($cycleId === 0 || !isset($cycles[$cycleId])) {
            $cycleId = (int) (array_key_first($cycles) ?? 0);
        }

        return $this->view->render($res, 'admin/shortlists/index.twig', [
            'page_title'   => 'Shortlists — Admin',
            'admin_page'   => 'shortlists',
            'cycles'       => $cycles,
            'cycle_id'     => $cycleId,
            'cycle'        => $cycles[$cycleId] ?? null,
            'rows'         => $cycleId > 0 ? ShortlistService::overview($cycleId) : [],
            'cycle_rule'   => $cycleId > 0 ? ShortlistService::ruleFor($cycleId, null)['rule'] : new ShortlistRule(),
            'modes'        => ShortlistRule::MODES,
            'ties'         => ShortlistRule::TIES,
            'may_publish'  => in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin'], true),
        ]);
    }

    /** One category: the full preview, every nominee, above and below the line. */
    public function category(Request $req, Response $res, array $args): Response
    {
        $cat = $this->category_($args);
        if ($cat === null) return $this->back($res, 'That category does not exist.');

        $p = ShortlistService::preview((int) $cat->cycle_id, (int) $cat->id);
        $live = ShortlistService::published((int) $cat->id);

        return $this->view->render($res, 'admin/shortlists/category.twig', [
            'page_title'  => 'Shortlist · ' . $cat->title . ' — Admin',
            'admin_page'  => 'shortlists',
            'cat'         => $cat,
            'preview'     => $p,
            'rule'        => $p['rule'],
            'scope'       => $p['scope'],
            'published'   => $live,
            'entries'     => $live ? ShortlistService::entries((int) $live->id) : [],
            'modes'       => ShortlistRule::MODES,
            'ties'        => ShortlistRule::TIES,
            'may_publish' => in_array((string) ($_SESSION['admin_role'] ?? ''), ['superadmin', 'admin'], true),
        ]);
    }

    // ───────────────────────────────── writes ────────────────────────────────

    /** Save the cycle default, or one category's override. */
    public function saveRule(Request $req, Response $res, array $args): Response
    {
        $b       = (array) $req->getParsedBody();
        $cycleId = (int) ($args['cycleId'] ?? $b['cycle_id'] ?? 0);
        $catId   = (int) ($b['category_id'] ?? 0) ?: null;

        if ($cycleId <= 0 || !isset($this->cycles()[$cycleId])) {
            return $this->back($res, 'Pick a cycle first.');
        }
        if ($catId !== null && !$this->categoryInCycle($catId, $cycleId)) {
            // A category id from a different cycle would write a rule that never applies,
            // and the screen would show the cycle default while the row sat there unused.
            return $this->back($res, 'That category is not in this cycle.');
        }

        $rule = ShortlistRule::from($b);
        ShortlistService::saveRule($cycleId, $catId, $rule, (int) ($_SESSION['admin_id'] ?? 0));
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shortlist.rule', 'shortlist_rule', $catId ?? $cycleId);

        // Saying what it became, not just that it saved: `from()` clamps, so an organiser
        // who typed 250% needs to see that the stored rule reads 100%.
        $_SESSION['flash_ok'] = 'Rule saved — ' . lcfirst($rule->describe())
                              . ' Nothing is published yet.';

        return $this->to($res, $catId !== null ? "/admin/shortlists/category/{$catId}" : "/admin/shortlists?cycle={$cycleId}");
    }

    /** Drop a category override so it inherits the cycle default again. */
    public function clearRule(Request $req, Response $res, array $args): Response
    {
        $cat = $this->category_($args);
        if ($cat === null) return $this->back($res, 'That category does not exist.');

        ShortlistService::clearRule((int) $cat->cycle_id, (int) $cat->id);
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shortlist.rule.clear', 'shortlist_rule', (int) $cat->id);
        $_SESSION['flash_ok'] = 'Override removed — this category follows the cycle rule again.';

        return $this->to($res, "/admin/shortlists/category/{$cat->id}");
    }

    public function publish(Request $req, Response $res, array $args): Response
    {
        $cat = $this->category_($args);
        if ($cat === null) return $this->back($res, 'That category does not exist.');

        $b = (array) $req->getParsedBody();
        $r = ShortlistService::publish(
            (int) $cat->cycle_id, (int) $cat->id,
            (int) ($_SESSION['admin_id'] ?? 0),
            trim((string) ($b['note'] ?? ''))
        );

        if ($r['ok']) {
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shortlist.publish', 'shortlist', $r['id']);
            $_SESSION['flash_ok'] = $r['message'] . ' The list is now fixed — later votes will not change it.';
        } else {
            $_SESSION['flash_error'] = $r['message'];
        }

        return $this->to($res, "/admin/shortlists/category/{$cat->id}");
    }

    public function withdraw(Request $req, Response $res, array $args): Response
    {
        $cat = $this->category_($args);
        if ($cat === null) return $this->back($res, 'That category does not exist.');

        $live = ShortlistService::published((int) $cat->id);
        if ($live === null) {
            $_SESSION['flash_error'] = 'There is no published shortlist to withdraw.';
        } else {
            ShortlistService::withdraw((int) $live->id, (int) ($_SESSION['admin_id'] ?? 0));
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'shortlist.withdraw', 'shortlist', (int) $live->id);
            $_SESSION['flash_ok'] = 'Shortlist withdrawn. The record of it is kept.';
        }

        return $this->to($res, "/admin/shortlists/category/{$cat->id}");
    }

    // ────────────────────────────────── the PDF ──────────────────────────────

    /**
     * The published shortlist as a document.
     *
     * Only a PUBLISHED one. A "download the preview" button is deliberately absent: a PDF
     * leaves the building, and a PDF of a live cut is a document that was already out of
     * date when it was saved. If an organiser wants it on paper, they publish it first.
     */
    public function pdf(Request $req, Response $res, array $args): Response
    {
        $cat = $this->category_($args);
        if ($cat === null) return $this->back($res, 'That category does not exist.');

        $live = ShortlistService::published((int) $cat->id);
        if ($live === null) {
            return $this->back($res, 'Publish the shortlist first — there is no document until then.');
        }

        $ctx = [
            'category'  => (string) $cat->title,
            'programme' => (string) ($cat->programme ?? 'Africa GATES'),
            'edition'   => (string) ($cat->edition_label ?? ''),
            'year'      => (string) ($cat->year ?? ''),
        ];

        $pdf = ShortlistPdf::render((array) $live, ShortlistService::entries((int) $live->id), $ctx);

        $res->getBody()->write($pdf);

        return $res
            ->withHeader('Content-Type', 'application/pdf')
            // `inline` so it opens in the browser's viewer — an organiser checking a
            // shortlist should not have to find it in a Downloads folder to look at it.
            ->withHeader('Content-Disposition', 'inline; filename="' . ShortlistPdf::filename($ctx) . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    // ───────────────────────────────── helpers ───────────────────────────────

    /** @return array<int,object> cycles, newest first, keyed by id */
    private function cycles(): array
    {
        return DB::table('gates_award_cycles AS cy')
            ->leftJoin('gates_award_programmes AS p', 'p.id', '=', 'cy.programme_id')
            ->orderByDesc('cy.year')->orderByDesc('cy.id')
            ->get(['cy.id', 'cy.year', 'cy.edition_label', 'cy.status', 'p.title AS programme'])
            ->keyBy('id')->all();
    }

    /** The category from the route, with its cycle and programme, or NULL. */
    private function category_(array $args): ?object
    {
        $id = (int) ($args['catId'] ?? 0);
        if ($id <= 0) return null;

        return DB::table('gates_award_categories AS c')
            ->join('gates_award_cycles AS cy', 'cy.id', '=', 'c.cycle_id')
            ->leftJoin('gates_award_programmes AS p', 'p.id', '=', 'cy.programme_id')
            ->where('c.id', $id)
            ->first(['c.id', 'c.title', 'c.slug', 'c.cycle_id',
                     'cy.year', 'cy.edition_label', 'cy.status AS cycle_status', 'p.title AS programme']);
    }

    private function categoryInCycle(int $catId, int $cycleId): bool
    {
        return DB::table('gates_award_categories')
            ->where('id', $catId)->where('cycle_id', $cycleId)->exists();
    }

    private function back(Response $res, string $error): Response
    {
        $_SESSION['flash_error'] = $error;
        return $this->to($res, '/admin/shortlists');
    }

    private function to(Response $res, string $path): Response
    {
        return $res->withHeader('Location', $path)->withStatus(302);
    }
}
