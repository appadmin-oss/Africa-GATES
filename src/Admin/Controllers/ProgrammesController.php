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
            // TINYINT UNSIGNED on production: strict MySQL refuses 256 (1264) where SQLite
            // stores it, so the form's value is clamped to what the column can hold.
            'sort_order'  => max(0, min(255, (int)($b['sort_order'] ?? 0))),
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
        // ── THE TERMS ARE VERSIONED, SO A CHANGED TEXT IS A NEW VERSION ──────
        //
        // The column above is still written (an older reader may hold it), but what the
        // public Terms tab shows and what a voter accepts is `gates_award_terms`. publish()
        // adds a version only when the words changed, so re-saving the form is not a new
        // version; the changelog line is the operator's, or a neutral one. Its own catch: a
        // terms table that is not migrated yet must not cost the operator the rest of the save.
        try {
            \AfricaGates\Services\AwardTerms::publish($id, (string) ($data['terms'] ?? ''),
                (string) ($b['terms_changelog'] ?? ''), (int) ($_SESSION['admin_id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[programmes] terms version not recorded: ' . $e->getMessage());
        }
        $this->bustAwardsCache();
        $_SESSION['flash_ok'] = 'Programme saved.';
        return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
    }

    /**
     * ══════════════════════════════════════════════════════════════════════════
     * THE WORDS ONE AWARD USES ABOUT ITS OWN PEOPLE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Every nomination form on this platform said "nominee". The Carol Awards is for
     * choirs, which are not people; the Incorruptible Awards is for public servants.
     * A form headed *Carol Awards* that asks "What is the nominee's full name?" is
     * asking a choirmaster a question about a person, and the commonest way that goes
     * wrong is not an error — it is somebody typing their own name because the form
     * appeared to be asking for it.
     *
     * {@see \AfricaGates\Support\AwardWording} is the store and the resolver; this is
     * the only way in. Before it existed, the wording column was written by nothing:
     * the reader, the fallbacks and the caps were all complete and there was no form —
     * the shape §18 of the codebase index is about, and the shape `manageUrl()`
     * shipped in over a donor's stop button.
     *
     * A SUB-PAGE, linked from the programme it belongs to rather than added to the
     * rail. The rail is seven headings and a section can carry only one gate, so a new
     * entry is an access decision; this is not one.
     */
    public function wording(Request $req, Response $res, array $args): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $row = DB::table('gates_award_programmes')->where('id', $id)->first();
        if (!$row) {
            $_SESSION['flash_error'] = 'That award could not be found.';
            return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
        }

        // Rejected values survive one render and are then dropped, so a reload of the
        // saved page is the saved page. Keyed to this programme: two awards edited in
        // two tabs otherwise prefill each other, which is the `reg_old` fault the
        // registration form shipped with.
        $old = $_SESSION['award_wording_old'][$id] ?? null;
        unset($_SESSION['award_wording_old'][$id]);

        // A REAL category from this award's own cycle, where it has one. A preview
        // built on "Category name" shows an operator a sentence that will never be
        // printed, and how the sentence reads with their own words in it is the one
        // thing they are on this page to judge.
        $cycle = DB::table('gates_award_cycles')->where('programme_id', $id)
            ->orderByDesc('year')->first();
        $category = $cycle
            ? DB::table('gates_award_categories')->where('cycle_id', $cycle->id)
                ->orderBy('sort_order')->value('title')
            : null;
        $category = is_string($category) && trim($category) !== ''
            ? trim($category) : 'this category';

        // A stand-in first name, because the form puts the nominee's first name in
        // this sentence and "them" is what it prints before anybody has typed.
        $name = 'Ada';

        $wording = \AfricaGates\Support\AwardWording::of($row);

        return $this->view->render($res, 'admin/programmes/wording.twig', [
            'page_title' => 'Wording · ' . (string) $row->title . ' — Admin',
            'admin_page' => 'programmes',
            'row'        => (array) $row,
            // The stored document, complete — every key present, house words where the
            // award has said nothing. The template never writes a `|default()`, which
            // is how a fifth copy of a default gets into a template and takes over
            // silently the day a controller stops passing one.
            'wording'    => $wording,
            'preview_name'     => $name,
            'preview_category' => $category,
            // Built by the resolver, never by the template — the admin's preview and
            // the public form have to show one sentence.
            'preview_reason'   => \AfricaGates\Support\AwardWording::reasonQuestion(
                $wording, $name, $category),
            'old'        => $old,
            'fields'     => \AfricaGates\Support\AwardWording::FIELDS,
            'kinds'      => \AfricaGates\Support\NomineeKind::ALL,
            'kinds_key'  => \AfricaGates\Support\AwardWording::KINDS_KEY,
            'is_custom'  => \AfricaGates\Support\AwardWording::isCustom($row),
        ]);
    }

    /**
     * Store it, or hand the whole attempt back with the reason.
     *
     * The refusal comes from {@see AwardWording::save()} and not from a second set of
     * checks here: a form enforcing one ceiling while the writer enforces another is
     * this codebase's most expensive shape, and the caps are already in `FIELDS`,
     * where the template reads them for `maxlength` too.
     */
    public function wordingSave(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $b  = (array) $req->getParsedBody();

        if (!DB::table('gates_award_programmes')->where('id', $id)->exists()) {
            $_SESSION['flash_error'] = 'That award could not be found.';
            return $res->withHeader('Location', '/admin/programmes')->withStatus(302);
        }

        $input = [];
        foreach (\AfricaGates\Support\AwardWording::FIELDS as $key => $_) {
            $input[$key] = $b[$key] ?? '';
        }
        $input[\AfricaGates\Support\AwardWording::KINDS_KEY] =
            is_array($b[\AfricaGates\Support\AwardWording::KINDS_KEY] ?? null)
                ? $b[\AfricaGates\Support\AwardWording::KINDS_KEY] : [];

        $error = \AfricaGates\Support\AwardWording::save($id, $input);
        if ($error !== null) {
            // Everything they typed travels back. A refusal that empties seven boxes
            // is a refusal somebody answers by giving up.
            $_SESSION['award_wording_old'][$id] = $input;
            $_SESSION['flash_error'] = $error;
            return $res->withHeader('Location', '/admin/programmes/' . $id . '/wording')->withStatus(302);
        }

        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'programme.wording', 'programme', $id);
        // The nomination form reads this on every render through the programme row,
        // and that row is cached with the rest of the award views.
        $this->bustAwardsCache();
        $_SESSION['flash_ok'] = 'Wording saved. The nomination form uses it from now on.';

        return $res->withHeader('Location', '/admin/programmes/' . $id . '/wording')->withStatus(302);
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
        // ── AND THE ONE THE OPERATOR ASKED FOR, IF THEY ASKED ────────────────
        //
        // Past editions used to render as CHIPS YOU CANNOT CLICK: an operator could see
        // that a programme had a 2025 and a 2026, and could only ever edit whichever one
        // the site was running. Last year's dates, label and categories were unreachable
        // from a console on a host with no shell.
        //
        // Checked against the PROGRAMME, because an id in a query string is a claim: a
        // cycle belonging to a different programme must not open here, or the screen would
        // edit one award's dates under another award's heading.
        $wanted = (int) ($req->getQueryParams()['cycle'] ?? 0);
        $picked = $wanted > 0
            ? DB::table('gates_award_cycles')->where('id', $wanted)
                ->where('programme_id', $programmeId)->first()
            : null;

        $cycle = \AfricaGates\Services\BallotGuard::currentCycleForProgramme($programmeId)
            ?? DB::table('gates_award_cycles')->where('programme_id', $programmeId)->orderByDesc('year')->first();
        $cycle = $picked ?? $cycle;
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
            // ── IS THE PUBLIC SITE SAYING THIS RESULT IS LATE, RIGHT NOW? ────
            //
            // The notice is derived — a results date that has passed, a cycle not yet
            // announced — so no screen owns it and an operator could otherwise not tell
            // whether the sentence they are typing is live or filed for later. This is
            // the same call the public page makes, so the two cannot disagree.
            'late'       => $cycle
                            ? \AfricaGates\Services\PublicResults::delayFor((int) $cycle->id)
                            : null,
            // Statuses the transition guard will actually accept, so the
            // dropdown stops offering options that always fail.
            'selectable' => \AfricaGates\Services\CycleService::selectableFrom($cycle->status ?? null),
            // Every edition with what is actually in it — "2025" orients nobody, and
            // "2025 · 5 categories · 41 nominees · archived" tells an operator which one
            // they want before they click it.
            'editions'   => \AfricaGates\Services\CycleEdition::listFor($programmeId),
            // Whether the one on screen is the one the public site is running — the
            // question an operator editing a past edition needs answered loudly.
            'live_cycle_id' => (int) (\AfricaGates\Services\BallotGuard::currentCycleForProgramme($programmeId)->id ?? 0),
            'next_year'  => \AfricaGates\Services\CycleEdition::nextYearFor($programmeId),
            // `all_cycles` was here — every cycle on the programme, queried on every
            // render and read by nothing. `editions` above is the same list through
            // `CycleEdition::listFor()`, and IT is what the Editions panel draws, so
            // the raw copy was a second query answering a question already answered.
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
        // ── AND WHY A RESULT IS LATE, IF IT IS ───────────────────────────────
        //
        // A results date is a promise made in public, and when it passes without the
        // announcement the site says so by itself — the condition is derived, so there is
        // no flag to set or to remember to clear. This is the one part only a person can
        // write: the reason. Left empty, the page states the date it missed and that the
        // award is not decided, and invents neither a cause nor a new date.
        //
        // Through OptionalColumn so a deployment whose migration has not run yet still
        // saves a cycle: an unwritable note must never cost an operator their dates.
        // Which edition this is ("11th Edition"). Typed, because a programme may have run for
        // years before it came here and no count of our rows can know that; blank keeps the
        // stored number (EditionName falls back to the order). Bounded to the column.
        if (\AfricaGates\Support\OptionalColumn::on('gates_award_cycles', 'edition_number')
            && trim((string) ($b['edition_number'] ?? '')) !== '') {
            $data['edition_number'] = max(1, min(999, (int) $b['edition_number']));
        }
        if (\AfricaGates\Support\OptionalColumn::on('gates_award_cycles', 'results_delay_note')) {
            $note = trim((string) ($b['results_delay_note'] ?? ''));
            // Bounded where it is written, not where it renders. This is prose typed under
            // pressure onto a public page, and 1,000 characters is several paragraphs more
            // than a delay needs.
            $data['results_delay_note'] = $note === '' ? null : mb_substr($note, 0, 1000);
        }
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
            // TINYINT UNSIGNED on production — see save() and CycleEdition::copy().
            'sort_order'  => max(0, min(255, (int)($b['sort_order'] ?? 0))),
        ];
        // ── A REFUSED WRITE IS A SENTENCE, NOT A 500 ─────────────────────────
        //
        // This had no catch at all, so anything the database refused — a slug another
        // category in the cycle already holds, an empty title on a NOT NULL column —
        // reached the operator as an error page with the form's contents gone.
        try {
            if ($catId) {
                DB::table('gates_award_categories')->where('id', $catId)->update($data);
            } else {
                $catId = (int)DB::table('gates_award_categories')->insertGetId($data);
            }
        } catch (\Throwable $e) {
            error_log('[programmes] category save: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'That category could not be saved — check its slug is not '
                . 'already used by another category in this cycle, and that it has a title.';
            return $res->withHeader('Location', "/admin/programmes/$programmeId/cycle")->withStatus(302);
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

    // ══ SPONSORSHIP ══════════════════════════════════════════════════════════
    //
    // A SUB-PAGE OF A PROGRAMME, NOT A RAIL ENTRY. The rail is seven headings and a
    // sub-page is linked from the page it belongs under — `/admin/shop/codes` from shop
    // orders, this from the programme it sponsors. That is what keeps the rail scannable,
    // and it needs no new page key or sprite icon.

    /** GET /admin/programmes/{id}/sponsors */
    public function sponsors(Request $req, Response $res, array $args): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $row = (array) DB::table('gates_award_programmes')->where('id', $id)->first();
        if ($row === []) throw new \Slim\Exception\HttpNotFoundException($req);

        // The cycle list, so a sponsorship can be attached to one edition rather than to
        // the programme for ever. Newest first: an operator adding a sponsor is almost
        // always adding one to the edition they are running now.
        $cycles = DB::table('gates_award_cycles')->where('programme_id', $id)
            ->orderByDesc('year')->orderByDesc('id')
            ->get(['id', 'year', 'edition_label', 'status'])->all();

        $current = null;
        foreach ($cycles as $c) {
            if (in_array((string) $c->status, ['nominations','shortlisting','voting','judging','results'], true)) {
                $current = (int) $c->id; break;
            }
        }

        return $this->view->render($res, 'admin/programmes/sponsors.twig', [
            'page_title' => 'Sponsors — ' . ($row['title'] ?? 'Programme'),
            'admin_page' => 'programmes',
            'programme'  => $row,
            'cycles'     => $cycles,
            'sponsors'   => \AfricaGates\Services\ProgrammeSponsor::allFor($id),
            'tiers'      => \AfricaGates\Services\ProgrammeSponsor::TIERS,
            // ── THE CONFLICT AN OPERATOR HAS TO SEE BEFORE PUBLISHING ────────
            //
            // A sponsor who is also in the running. Reported, never blocked: whether it is
            // acceptable is a judgement for a person, and a silent refusal teaches people
            // to stop asking. Scoped to the live cycle, because a sponsor who was also a
            // nominee three editions ago is history rather than a conflict.
            'conflicts'  => \AfricaGates\Services\ProgrammeSponsor::conflicts($id, $current),
        ]);
    }

    /** POST /admin/programmes/{id}/sponsors */
    public function sponsorSave(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $b  = (array) $req->getParsedBody();

        $b['programme_id'] = $id;
        $sponsorId = \AfricaGates\Services\ProgrammeSponsor::save(
            $b, (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        // Audited as a MONEY event, because it is one. Who was named beside an award, and
        // who decided to name them, is the question this record exists to answer years
        // later — see the migration.
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0) ?: null,
            'programme.sponsor.save', 'gates_programme_sponsors', $sponsorId);

        $this->bustAwardsCache();

        return $res->withHeader('Location', '/admin/programmes/' . $id . '/sponsors?saved=1')
                   ->withStatus(302);
    }

    /** POST /admin/programmes/{id}/sponsors/{sponsor}/delete */
    public function sponsorDelete(Request $req, Response $res, array $args): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $sid = (int) ($args['sponsor'] ?? 0);

        // A DRAFT is deleted; anything that has been PUBLISHED is ended instead. A
        // sponsorship that appeared on a public page is a commercial fact somebody may ask
        // about years later, and this codebase's rule is that a record which has been used
        // is retired rather than deleted.
        $row = (array) DB::table(\AfricaGates\Services\ProgrammeSponsor::TABLE)
            ->where('id', $sid)->where('programme_id', $id)->first();

        if ($row !== []) {
            if (($row['status'] ?? '') === \AfricaGates\Services\ProgrammeSponsor::STATUS_DRAFT) {
                DB::table(\AfricaGates\Services\ProgrammeSponsor::TABLE)->where('id', $sid)->delete();
            } else {
                DB::table(\AfricaGates\Services\ProgrammeSponsor::TABLE)->where('id', $sid)
                    ->update(['status' => \AfricaGates\Services\ProgrammeSponsor::STATUS_ENDED]);
            }
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0) ?: null,
                'programme.sponsor.remove', 'gates_programme_sponsors', $sid);
            $this->bustAwardsCache();
        }

        return $res->withHeader('Location', '/admin/programmes/' . $id . '/sponsors')
                   ->withStatus(302);
    }

    /**
     * POST /admin/programmes/{id}/editions — open next year's edition.
     *
     * A separate action from `cycleSave`, deliberately. That form posts the CURRENT
     * cycle's id, so an operator who changed the year on it did not create next year's
     * edition — they RENAMED this year's, taking its nominees, votes and scores with it.
     * Creating an edition is a different intention and gets its own button.
     */
    public function editionOpen(Request $req, Response $res, array $args): Response
    {
        $programmeId = (int) ($args['id'] ?? 0);
        $b = (array) $req->getParsedBody();

        $r = \AfricaGates\Services\CycleEdition::open(
            $programmeId,
            (int) ($b['year'] ?? 0),
            (string) ($b['edition_label'] ?? ''),
            !empty($b['copy_categories']),
            (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        if ($r['ok']) {
            $this->audit->record((int) ($_SESSION['admin_id'] ?? 0) ?: null,
                'programme.edition.open', 'gates_award_cycles', $r['cycle_id']);
            $this->bustAwardsCache();
            $_SESSION['flash'] = $r['message'];
            // Straight into the new edition, because the next thing anybody does is set
            // its dates — and a redirect back to the live cycle would leave them editing
            // the wrong one while being told the new one exists.
            return $res->withHeader('Location',
                "/admin/programmes/{$programmeId}/cycle?cycle=" . $r['cycle_id'])->withStatus(302);
        }

        $_SESSION['flash_error'] = $r['message'];
        return $res->withHeader('Location', "/admin/programmes/{$programmeId}/cycle")
                   ->withStatus(302);
    }
}
