<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\ChallengeAdmin;
use AfricaGates\Services\ChallengeCopy;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The challenge builder and its queues — /admin/challenges.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE PAGE PER QUESTION AN OPERATOR ACTUALLY ARRIVES WITH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * §5 asks for a five-step builder and four queues. The five steps are one FORM with
 * five fieldsets rather than five pages: a wizard that loses its answers between steps
 * is the commonest way a long form is abandoned, and every field here is independent —
 * there is nothing on step 4 that cannot be filled before step 2.
 *
 * The queues are the other half and they are the half that gets used daily:
 * verification, entries, payouts and the draw. Each one exists because somebody has a
 * question — "what is waiting on me", "who is cheating", "who have I paid", "who won".
 *
 * ── WHAT THE ASSISTANT MAY TOUCH ────────────────────────────────────────────
 *
 * Drafting copy, and that is all. It lands in the form for the operator to read and
 * change; it writes no row, decides no qualification, no standing, no draw, no
 * disqualification and no payout. The hard line is in `ChallengeAdmin::draft()` and in
 * the capability's own declaration, and it is restated here because this is the screen
 * where somebody would be tempted to widen it.
 *
 * ── AND THE PREVIEW IS THE REAL GENERATOR ───────────────────────────────────
 *
 * The live preview calls `ChallengeCopy::for()` — the same function the public page
 * uses. A preview rendered by a second, friendlier code path is a preview that lies,
 * and this codebase has the rule written down: a screen that publishes a value and a
 * screen that acts on it are the pair most likely to disagree.
 */
final class ChallengesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The list
    // ══════════════════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_challenges')->orderByDesc('id')->limit(100)->get();

        $list = [];
        foreach ($rows as $c) {
            $claimed = CS::claimed((int) $c->id);
            $copy    = ChallengeCopy::for((array) $c, ['claimed' => $claimed]);

            $list[] = [
                'id' => (int) $c->id, 'slug' => (string) $c->slug, 'title' => (string) $c->title,
                'status' => (string) $c->status, 'state_label' => $copy['state_label'],
                'prize' => trim($copy['prize_big'] . ' ' . $copy['prize_unit']),
                'entries' => (int) DB::table('gates_challenge_entries')
                    ->where('challenge_id', $c->id)->count(),
                'claimed' => $claimed, 'cap' => (int) ($c->cap ?? 0),
                // Shown on the row, so an operator can see what is stopping a draft
                // without opening it. A list that hides the blocker is a list you have
                // to click through one at a time.
                'blockers' => $c->status === E::ST_DRAFT ? count(ChallengeAdmin::blockers((int) $c->id)) : 0,
                'awaiting' => $this->awaitingCount((int) $c->id),
            ];
        }

        return $this->view->render($res, 'admin/challenges/index.twig', [
            'admin_page' => 'challenges', 'topbar_title' => 'Challenges',
            'challenges' => $list,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The builder
    // ══════════════════════════════════════════════════════════════════════════

    public function form(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $c  = $id > 0 ? CS::find($id) : null;

        if ($id > 0 && !$c) return $res->withStatus(404);

        $live = $c && $c->status !== E::ST_DRAFT;

        return $this->view->render($res, 'admin/challenges/form.twig', [
            'admin_page' => 'challenges',
            'topbar_title' => $c ? 'Edit challenge' : 'New challenge',
            'c' => $c,
            'scopes' => $c ? $this->scopesOf((int) $c->id) : [],
            'blockers' => $c ? ChallengeAdmin::blockers((int) $c->id) : [],
            // `live` alone. `LOCKED` used to travel with it and no template read it —
            // the form states the lock in prose beside each field, which is what an
            // operator can act on; a list of column names is not.
            'live' => $live,
            'preview' => $c ? ChallengeCopy::for(((array) $c) + $this->scopeWords((int) $c->id)) : null,
            'cycles' => $this->cycleChoices(),
            'categories' => $this->categoryChoices(),
            'events' => $this->eventChoices(),
            'actions' => E::ACTIONS, 'modes' => E::MODES,
            'prize_types' => E::PRIZE_TYPES, 'themes' => E::THEMES,
            'ai_on' => \AfricaGates\Services\AiGateway::available('challenge.draft'),
            'draft' => $_SESSION['challenge_draft'] ?? null,
            // Decoded here: the column holds JSON and Twig has no decoder, so a
            // template doing it would need a filter nobody else wants.
            'extra_rules_text' => $c ? implode("\n", self::rulesOf($c)) : '',
        ]);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        $b  = (array) $req->getParsedBody();
        $id = (int) ($args['id'] ?? 0);

        if ($id < 1) {
            $r = ChallengeAdmin::create($b, $this->adminId());
            if (!$r['ok']) {
                $_SESSION['flash_error'] = match ($r['code']) {
                    'SLUG_TAKEN' => 'A challenge already uses that web address. Change the title or the slug.',
                    'NO_TITLE'   => 'Give it a title first.',
                    default      => 'That could not be saved.',
                };

                return $this->back($res, '/admin/challenges/new');
            }

            $id = $r['id'];
            $this->audit?->record($this->adminId(), 'challenge.create', 'challenge', $id,
                ['title' => (string) ($b['title'] ?? '')]);
        } else {
            $r = ChallengeAdmin::update($id, $b);

            if (!$r['ok']) {
                $_SESSION['flash_error'] = $r['code'] === 'LOCKED'
                    ? 'That challenge is published, so its rules are fixed: '
                      . implode(', ', $r['locked']) . '. People have read the terms it went '
                      . 'out with — start a new challenge instead.'
                    : ($r['code'] === 'ENDS_EARLIER'
                        ? 'A closing date can be moved later but never earlier. Bringing it '
                          . 'forward takes time away from everybody part-way through.'
                        : 'That could not be saved.');

                return $this->back($res, '/admin/challenges/' . $id);
            }

            $this->audit?->record($this->adminId(), 'challenge.update', 'challenge', $id);
        }

        // Scopes travel with the form. Refused once published, which `setScopes()`
        // enforces rather than this screen deciding it twice.
        if (isset($b['scope'])) {
            ChallengeAdmin::setScopes($id, $this->parseScopes((array) $b['scope']));
        }

        $_SESSION['flash_ok'] = 'Saved.';

        return $this->back($res, '/admin/challenges/' . $id);
    }

    public function publish(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = ChallengeAdmin::publish($id, $this->adminId());

        if (!$r['ok']) {
            $_SESSION['flash_error'] = 'Not yet: ' . implode(' ', $r['blockers']);
        } else {
            $_SESSION['flash_ok'] = 'Published. The rules are now fixed.';
            $this->audit?->record($this->adminId(), 'challenge.publish', 'challenge', $id,
                ['status' => $r['status']]);
        }

        return $this->back($res, '/admin/challenges/' . $id);
    }

    public function cancel(Request $req, Response $res, array $args): Response
    {
        $id     = (int) ($args['id'] ?? 0);
        $reason = (string) (((array) $req->getParsedBody())['reason'] ?? '');
        $r      = ChallengeAdmin::cancel($id, $reason);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? 'Cancelled. The page is down and the entries are kept.'
            : 'Say why it is being cancelled — whoever asks later will need it.';

        if ($r['ok']) $this->audit?->record($this->adminId(), 'challenge.cancel', 'challenge', $id,
            ['reason' => mb_substr(trim($reason), 0, 300)]);

        return $this->back($res, '/admin/challenges/' . $id);
    }

    /** POST — ask the assistant for words, and put them in the form. */
    public function draft(Request $req, Response $res, array $args): Response
    {
        $b  = (array) $req->getParsedBody();
        $id = (int) ($args['id'] ?? 0);

        $names = [];
        foreach ($this->parseScopes((array) ($b['scope'] ?? [])) as $s) {
            $names[] = $this->scopeName($s['scope_type'], $s['scope_id']);
        }

        $d = ChallengeAdmin::draft([
            'action' => $b['action'] ?? '', 'target' => $b['target'] ?? 1,
            'mode' => $b['mode'] ?? '', 'scope_names' => array_filter($names),
            'hint' => $b['hint'] ?? '',
        ]);

        // Into the SESSION and back to the form, never into the row. The operator reads
        // it beside what they wrote and decides; nothing it said is saved until they
        // press save like any other edit.
        $_SESSION['challenge_draft'] = $d ?: null;
        if (!$d) $_SESSION['flash_error'] = 'The assistant could not draft anything just now. '
            . 'The form works without it.';

        return $this->back($res, $id > 0 ? '/admin/challenges/' . $id : '/admin/challenges/new');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The queues
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Everything waiting on somebody, for one challenge.
     *
     * Four lists on one page rather than four pages: an operator working a challenge
     * moves between them constantly — a disqualification changes the payout list, a
     * verification changes the entries list — and four tabs would mean reloading to
     * see the consequence of what they just did.
     */
    public function queues(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $c  = CS::find($id);
        if (!$c) return $res->withStatus(404);

        $entries = DB::table('gates_challenge_entries as e')
            ->where('e.challenge_id', $id)
            ->leftJoin('gates_users as u', 'u.id', '=', 'e.user_id')
            ->orderByRaw('e.standing IS NULL, e.standing')
            ->orderByDesc('e.verified')
            ->limit(500)
            ->get(['e.*', 'u.name', 'u.email']);

        $rows = [];
        foreach ($entries as $e) {
            $rows[] = [
                'id' => (int) $e->id, 'who' => (string) ($e->name ?? '—'),
                'email' => (string) ($e->email ?? ''),
                'verified' => (int) $e->verified, 'checking' => (int) $e->checking,
                'needs' => (int) $e->needs_details,
                'standing' => $e->standing !== null ? (int) $e->standing : null,
                'status' => (string) $e->status, 'payout' => (string) $e->payout_status,
                'payout_ref' => (string) ($e->payout_ref ?? ''),
                'qualified_at' => (string) ($e->qualified_at ?? ''),
                // Cross-entry duplicates are a REVIEW signal, never a refusal: two
                // neighbours nominating the same teacher is the system working.
                'flags' => $this->duplicateFlags($id, (int) $e->id),
            ];
        }

        return $this->view->render($res, 'admin/challenges/queues.twig', [
            'admin_page' => 'challenges', 'topbar_title' => $c->title,
            'c' => $c, 'rows' => $rows,
            'awaiting' => $this->awaitingNominations($id),
            'is_draw' => (string) $c->mode === E::MODE_DRAW,
            'drawn' => !empty($c->draw_seed),
        ]);
    }

    public function disqualify(Request $req, Response $res, array $args): Response
    {
        $b = (array) $req->getParsedBody();
        $r = CS::disqualify((int) ($args['entry'] ?? 0), (string) ($b['reason'] ?? ''), $this->adminId());

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? 'Removed, and everybody behind them moved up.'
            : 'A disqualification needs a reason — an appeal will ask for it.';

        if ($r['ok']) $this->audit?->record($this->adminId(), 'challenge.disqualify',
            'challenge_entry', (int) ($args['entry'] ?? 0),
            ['reason' => mb_substr(trim((string) ($b['reason'] ?? '')), 0, 300)]);

        return $this->back($res, '/admin/challenges/' . (int) ($args['id'] ?? 0) . '/queues');
    }

    /**
     * Mark a prize paid, with its reference.
     *
     * The reference is required, because "paid" with nothing to look up is a claim
     * nobody can check — and this is the record somebody will be asked to produce.
     */
    public function markPaid(Request $req, Response $res, array $args): Response
    {
        $b   = (array) $req->getParsedBody();
        $ref = trim((string) ($b['ref'] ?? ''));
        $id  = (int) ($args['id'] ?? 0);

        if ($ref === '') {
            $_SESSION['flash_error'] = 'Put the transfer reference in. "Paid" with nothing to '
                . 'look up is not a record anybody can check.';

            return $this->back($res, '/admin/challenges/' . $id . '/queues');
        }

        DB::table('gates_challenge_entries')->where('id', (int) ($args['entry'] ?? 0))
            ->update(['payout_status' => E::PAY_PAID, 'payout_ref' => mb_substr($ref, 0, 120),
                      'payout_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit?->record($this->adminId(), 'challenge.payout', 'challenge_entry',
            (int) ($args['entry'] ?? 0), ['ref' => mb_substr($ref, 0, 120)]);

        $_SESSION['flash_ok'] = 'Marked paid.';

        return $this->back($res, '/admin/challenges/' . $id . '/queues');
    }

    public function draw(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = CS::draw($id);

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? 'Drawn. The seed is stored and the same seed always gives the same winners.'
            : 'That is not a draw challenge.';

        if ($r['ok']) $this->audit?->record($this->adminId(), 'challenge.draw', 'challenge', $id,
            ['seed' => (string) ($r['seed'] ?? ''), 'winners' => count($r['winners'] ?? [])]);

        return $this->back($res, '/admin/challenges/' . $id . '/queues');
    }

    /** CSV of the entries, for the reconciliation somebody does in a spreadsheet. */
    public function export(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        $rows = DB::table('gates_challenge_entries as e')
            ->where('e.challenge_id', $id)
            ->leftJoin('gates_users as u', 'u.id', '=', 'e.user_id')
            ->orderByRaw('e.standing IS NULL, e.standing')
            ->get(['e.id', 'u.name', 'u.email', 'e.verified', 'e.standing', 'e.status',
                   'e.payout_status', 'e.payout_ref', 'e.qualified_at']);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['entry', 'name', 'email', 'verified', 'place', 'status', 'payout', 'reference', 'qualified_at']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->id, $r->name, $r->email, $r->verified, $r->standing,
                           $r->status, $r->payout_status, $r->payout_ref, $r->qualified_at]);
        }
        rewind($out);

        $res->getBody()->write((string) stream_get_contents($out));
        fclose($out);

        $this->audit?->record($this->adminId(), 'challenge.export', 'challenge', $id);

        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="challenge-' . $id . '-entries.csv"');
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** The appended rules as lines, whatever shape the column is in. */
    private static function rulesOf(object $c): array
    {
        $raw = $c->extra_rules ?? null;
        if (is_array($raw)) return array_values($raw);
        if (!is_string($raw) || trim($raw) === '') return [];

        try {
            $d = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

            return is_array($d) ? array_values(array_map('strval', $d)) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{scope_type:string,scope_id:int}> */
    private function parseScopes(array $raw): array
    {
        $out = [];

        foreach ($raw as $line) {
            // "award_cycle:12" — one field, so a form can post a multi-select of mixed
            // kinds without three parallel arrays that can fall out of step.
            $parts = explode(':', (string) $line, 2);
            if (count($parts) !== 2) continue;
            $out[] = ['scope_type' => $parts[0], 'scope_id' => (int) $parts[1]];
        }

        return $out;
    }

    private function scopesOf(int $id): array
    {
        return DB::table('gates_challenge_scopes')->where('challenge_id', $id)
            ->get()->map(static fn($r) => $r->scope_type . ':' . $r->scope_id)->all();
    }

    private function scopeName(string $type, int $id): string
    {
        return match ($type) {
            E::SCOPE_CYCLE => (string) (DB::table('gates_award_cycles as cy')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('cy.id', $id)->value('p.title') ?? ''),
            E::SCOPE_CATEGORY => (string) (DB::table('gates_award_categories')
                ->where('id', $id)->value('title') ?? ''),
            E::SCOPE_EVENT => (string) (DB::table('gates_site_events')
                ->where('id', $id)->value('title') ?? ''),
            default => '',
        };
    }

    /** The award and category names for the preview, as the public page builds them. */
    private function scopeWords(int $id): array
    {
        $awards = []; $cats = [];

        foreach (DB::table('gates_challenge_scopes')->where('challenge_id', $id)->get() as $s) {
            $n = $this->scopeName((string) $s->scope_type, (int) $s->scope_id);
            if ($n === '') continue;
            if ($s->scope_type === E::SCOPE_CYCLE) $awards[] = $n;
            if ($s->scope_type === E::SCOPE_CATEGORY) $cats[] = $n;
        }

        $join = static function (array $x): string {
            $x = array_values(array_unique($x));
            if ($x === []) return '';
            if (count($x) === 1) return $x[0];
            $last = array_pop($x);

            return implode(', ', $x) . ' or ' . $last;
        };

        return ['award' => $join($awards), 'cats' => $join($cats)];
    }

    private function cycleChoices(): array
    {
        return DB::table('gates_award_cycles as cy')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            // Active programmes only: the sandbox lives in an inactive one, and an
            // operator scoping a real challenge to the rehearsal is a page of demo data
            // behind a cash prize.
            ->where('p.is_active', 1)
            ->orderByDesc('cy.year')->limit(80)
            ->get(['cy.id', 'p.title', 'cy.year', 'cy.edition_label'])
            ->map(static fn($r) => ['id' => (int) $r->id,
                'label' => trim($r->title . ' ' . ($r->edition_label ?: $r->year))])->all();
    }

    private function categoryChoices(): array
    {
        return DB::table('gates_award_categories as ct')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'ct.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('p.is_active', 1)
            ->orderBy('p.title')->orderBy('ct.sort_order')->limit(300)
            ->get(['ct.id', 'ct.title', 'p.title as programme'])
            ->map(static fn($r) => ['id' => (int) $r->id,
                'label' => $r->programme . ' — ' . $r->title])->all();
    }

    private function eventChoices(): array
    {
        if (!\AfricaGates\Support\SchemaHas::table('gates_site_events')) return [];

        return DB::table('gates_site_events')->where('status', 'published')
            ->orderByDesc('event_date')->limit(80)
            ->get(['id', 'title'])
            ->map(static fn($r) => ['id' => (int) $r->id, 'label' => (string) $r->title])->all();
    }

    /** Nominations in this challenge still waiting on us or on the nominee. */
    private function awaitingNominations(int $id): array
    {
        return DB::table('gates_nominations as n')
            ->join('gates_challenge_entries as e', 'e.id', '=', 'n.challenge_entry_id')
            ->where('e.challenge_id', $id)
            ->whereIn('n.status', ['pending', 'submitted', 'checking'])
            ->orderBy('n.created_at')->limit(200)
            ->get(['n.id', 'n.nominee_name', 'n.status', 'n.nominee_confirmed_at',
                   'n.confirm_sends', 'n.created_at'])
            ->map(static fn($r) => (array) $r)->all();
    }

    private function awaitingCount(int $id): int
    {
        return (int) DB::table('gates_nominations as n')
            ->join('gates_challenge_entries as e', 'e.id', '=', 'n.challenge_entry_id')
            ->where('e.challenge_id', $id)
            ->whereIn('n.status', ['pending', 'submitted', 'checking'])
            ->count();
    }

    /** How many of this entry's nominees appear in somebody else's entry too. */
    private function duplicateFlags(int $challengeId, int $entryId): int
    {
        $hashes = DB::table('gates_nominations')->where('challenge_entry_id', $entryId)
            ->whereNotNull('nominee_identity_hash')->pluck('nominee_identity_hash')->all();

        $n = 0;
        foreach ($hashes as $h) {
            if (CS::crossEntryDuplicates($challengeId, (string) $h, $entryId) > 0) $n++;
        }

        return $n;
    }
}
