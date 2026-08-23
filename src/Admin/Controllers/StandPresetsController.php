<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\StandPreset;
use AfricaGates\Services\StandType;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The priced stand catalogue: what the organisation offers, saved once.
 *
 * ── WHY THIS IS NOT PART OF THE PER-EVENT STANDS SCREEN ──────────────────────
 *
 * Because it answers a different question. The event screen answers "what does THIS hall
 * have and who applied for it"; this answers "what do we sell, and for how much". Editing
 * the catalogue from inside one event invites the reading that the change is local to that
 * event, which is exactly wrong — and the one thing that IS local, the quota, stays there.
 *
 * A preset is retired rather than deleted. An event's stand type carries a copy of the
 * terms and names the preset it came from, so deleting the row would leave a call describing
 * an offer with no provenance. Retiring takes it off the "add to this event" list and
 * touches nothing already sold.
 */
final class StandPresetsController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }

    public function index(Request $req, Response $res): Response
    {
        // Labels, areas and the per-unit dimensions are computed HERE, not in Twig. Same
        // rule the vote page follows: a template that derives a published term is a second
        // implementation of it, and the two drift. `w`/`d` are the numbers the edit form's
        // boxes need, already in the unit the preset was entered in.
        $rows = [];
        foreach (StandPreset::all(true) as $p) {
            $unit = (string) ($p->unit ?? 'm');
            $rows[] = [
                'row'   => $p,
                'label' => StandPreset::label($p),
                'area'  => StandPreset::areaSqm($p),
                'unit'  => $unit,
                'w'     => StandPreset::dim((int) $p->width_cm, $unit),
                'd'     => StandPreset::dim((int) $p->depth_cm, $unit),
                'step'  => $unit === 'ft' ? '0.5' : '0.05',
            ];
        }

        return $this->view->render($res, 'admin/stand-presets/index.twig', [
            'page_title' => 'Stand presets — Admin',
            'admin_page' => 'stand_presets',
            'rows'       => $rows,
            'live'       => count(array_filter($rows, fn ($r) => (int) $r['row']->is_active === 1)),
            'retired'    => count(array_filter($rows, fn ($r) => (int) $r['row']->is_active !== 1)),
            'categories' => StandType::CATEGORIES,
            'units'      => StandPreset::UNITS,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = StandPreset::save((array) $req->getParsedBody(), $id, $this->adminId());

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) {
            $this->audit->record($this->adminId(), $id > 0 ? 'stand_preset.save' : 'stand_preset.add',
                'stand_preset', (int) $r['id']);
        }

        return $this->back($res);
    }

    public function archive(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = StandPreset::archive($id);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_preset.retire', 'stand_preset', $id);

        return $this->back($res);
    }

    public function restore(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = StandPreset::restore($id);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok']) $this->audit->record($this->adminId(), 'stand_preset.restore', 'stand_preset', $id);

        return $this->back($res);
    }

    private function back(Response $res): Response
    {
        return $res->withHeader('Location', '/admin/stand-presets')->withStatus(302);
    }
}
