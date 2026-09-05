<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\CacheService;

class ProgrammesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly ?CacheService $cache = null,
    ) {}

    /** Bust the public programmes cache so /nominate, /vote and /awards reflect edits at once. */
    /**
     * Drop every cached view derived from a cycle's phase — not just
     * `awards:active`. Clearing only that one key is why an admin could change
     * a cycle, see "Cycle saved.", and watch /vote keep advertising the old
     * phase for up to ten minutes.
     */
    private function bustAwardsCache(): void { ($this->cache ?? new \AfricaGates\Services\CacheService())->forgetAwardViews(); }

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_award_programmes')->orderBy('sort_order')->get();
        // Attach current cycle
        $progs = $rows->map(function ($p) {
            $cycle = DB::table('gates_award_cycles')->where('programme_id', $p->id)->orderByDesc('year')->first();
            $p->cycle = $cycle ? (array)$cycle : null;
            $p->cycles_count = (int)DB::table('gates_award_cycles')->where('programme_id', $p->id)->count();
            return (array)$p;
        })->all();
        return $this->view->render($res, 'admin/programmes/index.twig', [
            'page_title' => 'Award Programmes — Admin',
            'admin_page' => 'programmes',
            'rows'       => $progs,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $row = $id ? (array)DB::table('gates_award_programmes')->where('id', $id)->first() : [];
        return $this->view->render($res, 'admin/programmes/form.twig', [
            'page_title' => $id ? 'Edit Programme — Admin' : 'New Programme — Admin',
            'admin_page' => 'programmes',
            'row'        => $row,
            'is_new'     => !$id,
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int)($args['id'] ?? 0);
        $b = (array)$req->getParsedBody();
        $data = [
            'slug'        => preg_replace('/[^a-z0-9-]+/i','-', strtolower((string)($b['slug'] ?? ''))),
            'title'       => trim((string)($b['title'] ?? '')),
            'subtitle'    => trim((string)($b['subtitle'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'scope'       => (string)($b['scope'] ?? 'continental'),
            'icon_emoji'  => (string)($b['icon_emoji'] ?? '🏆'),
            'sort_order'  => (int)($b['sort_order'] ?? 0),
            'is_active'   => isset($b['is_active']) ? 1 : 0,
            'terms'       => trim((string)($b['terms'] ?? '')) ?: null,
        ];
        try {
            if ($id) {
                DB::table('gates_award_programmes')->where('id', $id)->update($data);
                $this->audit->record((int)$_SESSION['admin_id'], 'programme.update', 'programme', $id);
            } else {
                $data['created_at'] = Carbon::now()->toDateTimeString();
                $id = (int)DB::table('gates_award_programmes')->insertGetId($data);
                $this->audit->record((int)$_SESSION['admin_id'], 'programme.create', 'programme', $id);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = \AfricaGates\Admin\Support\ActionError::dbMessage($e);
            return $res->withHeader('Location', $id ? '/admin/programmes/' . $id : '/admin/programmes/new')->withStatus(302);
        }
        $this->bustAwardsCache();
        $_SESSION['flash_ok'] = 'Programme saved.';
        return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
    }

    public function cycleEdit(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int)$args['id'];
        $programme = DB::table('gates_award_programmes')->where('id', $programmeId)->first();
        if (!$programme) throw new \Slim\Exception\HttpNotFoundException($req);
        // The cycle the PUBLIC SITE is running, not merely the highest year.
        // Editing by `orderByDesc('year')` meant that with a future cycle seeded
        // the admin edited one cycle while the site ran another — status changes
        // appeared to do nothing.
        $cycle = \AfricaGates\Services\BallotGuard::currentCycleForProgramme($programmeId)
            ?? DB::table('gates_award_cycles')->where('programme_id', $programmeId)->orderByDesc('year')->first();
        $categories = $cycle ? DB::table('gates_award_categories')->where('cycle_id', $cycle->id)->orderBy('sort_order')->get()->map(fn($r)=>(array)$r)->all() : [];

        $phase = $cycle ? \AfricaGates\Services\CyclePolicy::stateFor($cycle) : null;
        $history = [];
        if ($cycle) {
            try {
                $history = DB::table('gates_cycle_transitions')->where('cycle_id', $cycle->id)
                    ->orderByDesc('id')->limit(12)->get()->map(fn($r) => (array) $r)->all();
            } catch (\Throwable $e) { $history = []; }
        }

        return $this->view->render($res, 'admin/programmes/cycle.twig', [
            'page_title' => $programme->title . ' — Cycle',
            'admin_page' => 'programmes',
            'programme'  => (array)$programme,
            'cycle'      => $cycle ? (array)$cycle : null,
            'categories' => $categories,
            // What the platform ACTUALLY thinks, so the admin can trust the
            // automation instead of inferring it from five date fields.
            'phase'      => $phase,
            'history'    => $history,
            // The DISPLAY zone, not Clock::timezone(). This said UTC, and the form
            // stored what was typed verbatim — so an operator in Lagos had to convert
            // every deadline in their head, on the five fields that decide whether a
            // vote counted. Storage is still UTC; the conversion is DisplayTime's job.
            'timezone'   => \AfricaGates\Support\DisplayTime::abbr(),
            // Statuses the transition guard will actually accept, so the
            // dropdown stops offering options that always fail.
            'selectable' => \AfricaGates\Services\CycleService::selectableFrom($cycle->status ?? null),
            'all_cycles' => DB::table('gates_award_cycles')->where('programme_id', $programmeId)
                ->orderByDesc('year')->get()->map(fn($r) => (array) $r)->all(),
        ]);
    }

    public function cycleSave(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int)$args['id'];
        $b = (array)$req->getParsedBody();
        $year = (int)($b['year'] ?? date('Y'));
        // Resolve by ID. Matching on the SUBMITTED year meant that changing the
        // year field silently INSERTED a brand-new cycle with no categories and
        // no nominees — and, because $from was then null, the transition guard
        // waved any starting status through. Creating a cycle is now explicit.
        $cycleId = (int)($b['cycle_id'] ?? 0);
        $cycle = $cycleId > 0
            ? DB::table('gates_award_cycles')->where('id', $cycleId)->where('programme_id', $programmeId)->first()
            : DB::table('gates_award_cycles')->where('programme_id', $programmeId)->where('year', $year)->first();

        // ── THE FIVE DEADLINES, THROUGH THE ONE CONVERTER ───────────────────
        //
        // These went into the database as the browser handed them over:
        // `2026-01-01T09:00`. A `T` separator and no seconds, on the columns that
        // decide whether a vote counted.
        //
        // MySQL normalises a T-separated value on its way into a DATETIME, so
        // production survived it. SQLite — dev, and the whole test harness — stores
        // the string verbatim, where `'2026-01-01T09:00'` sorts AFTER every
        // space-separated stamp of the same day, because 'T' is 0x54 and ' ' is 0x20.
        // A phase comparison that passes every test and rejects real input.
        //
        // The seconds mattered on their own: the template rendered these with
        // `slice(0,16)`, so a close stored at 23:59:59 came back 23:59:00 every time
        // somebody opened this form and pressed save without touching the field.
        //
        // Normalised BEFORE windowError() below, so validation and storage are
        // reading the same values — validating the raw POST and storing the converted
        // one is how a window passes its own ordering check and then breaks it.
        foreach (['nominations_open', 'nominations_close', 'voting_open', 'voting_close', 'results_date'] as $f) {
            $b[$f] = \AfricaGates\Support\DisplayTime::toStored($b[$f] ?? null);
        }

        // Window ordering. Nothing validated these, so it was possible to save a
        // cycle that could never open, or one whose windows overlap.
        if (($err = \AfricaGates\Services\CycleService::windowError($b)) !== null) {
            $_SESSION['flash_error'] = $err;
            return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
        }
        $data = [
            'programme_id'      => $programmeId,
            'year'              => $year,
            'edition_label'     => trim((string)($b['edition_label'] ?? '')),
            'status'            => (string)($b['status'] ?? 'upcoming'),
            'nominations_open'  => $b['nominations_open']  ?: null,
            'nominations_close' => $b['nominations_close'] ?: null,
            'voting_open'       => $b['voting_open']       ?: null,
            'voting_close'      => $b['voting_close']      ?: null,
            'results_date'      => $b['results_date']      ?: null,
        ];
        // Guard manual status transitions so the editor can't produce a cycle
        // state the automated, quorum-checked lifecycle machine never would:
        // no hand-jump to 'results' (winners promote through the date-driven
        // path), no backward regression, no phase-skipping.
        $from = $cycle ? (string)$cycle->status : null;
        $to   = (string)$data['status'];
        if (($err = \AfricaGates\Services\CycleService::manualTransitionError($from, $to)) !== null) {
            $_SESSION['flash_error'] = $err;
            return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
        }
        $cid = 0;
        try {
            if ($cycle) {
                DB::table('gates_award_cycles')->where('id', $cycle->id)->update($data);
                $cid = (int)$cycle->id;
            } else {
                $data['created_at'] = Carbon::now()->toDateTimeString();
                $cid = (int)DB::table('gates_award_cycles')->insertGetId($data);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = \AfricaGates\Admin\Support\ActionError::dbMessage($e);
            return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
        }
        // Record a manual phase change in the same tamper-evident ledger the cron
        // writes to, so gates_cycle_transitions is a complete history (auto + manual).
        if ($from !== null && $from !== $to) {
            try {
                DB::table('gates_cycle_transitions')->insert([
                    'cycle_id'    => $cid,
                    'from_status' => $from,
                    'to_status'   => $to,
                    'reason'      => 'manual: admin cycle editor',
                    'actor'       => 'admin:' . (int)($_SESSION['admin_id'] ?? 0),
                    'created_at'  => Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable $e) { /* ledger insert is best-effort — never block the save */ }
        }
        // Keep the indexed boundary in step immediately, so the divergence sweep
        // and the admin's own view agree with the dates just saved.
        try {
            $fresh = DB::table('gates_award_cycles')->where('id', $cid)->first();
            if ($fresh) {
                DB::table('gates_award_cycles')->where('id', $cid)
                    ->update(['next_boundary_at' => \AfricaGates\Services\CyclePolicy::nextBoundaryFor($fresh)]);
            }
        } catch (\Throwable $e) { /* column may predate the migration */ }

        $this->audit->record((int)$_SESSION['admin_id'], 'cycle.save', 'cycle', $cid);
        $this->bustAwardsCache();
        // A close date with no open date is savable but reaches the one branch
        // where a stale status column can affect authorization, so say so.
        $_SESSION['flash_ok'] = 'Cycle saved.'
            . (\AfricaGates\Services\CycleService::windowWarning($b) ?? '');
        return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
    }

    public function categorySave(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int)$args['id'];
        $b = (array)$req->getParsedBody();
        $cycle = \AfricaGates\Services\BallotGuard::currentCycleForProgramme($programmeId)
            ?? DB::table('gates_award_cycles')->where('programme_id', $programmeId)->orderByDesc('year')->first();
        if (!$cycle) {
            $_SESSION['flash_error'] = 'Create the cycle first.';
            return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
        }
        $catId = (int)($b['id'] ?? 0);
        $data = [
            'cycle_id'    => (int)$cycle->id,
            'slug'        => preg_replace('/[^a-z0-9-]+/i','-', strtolower((string)($b['slug'] ?? ''))),
            'title'       => trim((string)($b['title'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'sort_order'  => (int)($b['sort_order'] ?? 0),
        ];
        if ($catId) {
            DB::table('gates_award_categories')->where('id', $catId)->update($data);
        } else {
            $catId = (int)DB::table('gates_award_categories')->insertGetId($data);
        }
        $this->audit->record((int)$_SESSION['admin_id'], 'category.save', 'category', $catId);
        $this->bustAwardsCache();
        $_SESSION['flash_ok'] = 'Category saved.';
        return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
    }

    public function categoryDelete(Request $req, Response $res, array $args): Response
    {
        $catId = (int)$args['catId'];
        DB::table('gates_award_categories')->where('id', $catId)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'category.delete', 'category', $catId);
        $this->bustAwardsCache();
        $_SESSION['flash_ok'] = 'Category deleted.';
        return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
    }
}
