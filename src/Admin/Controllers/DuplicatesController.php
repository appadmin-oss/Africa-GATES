<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\IdentityVerdicts;
use AfricaGates\Admin\Services\NomineePeople;
use AfricaGates\Admin\Services\VoterPeople;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\AiGateway;
use AfricaGates\Services\MergeService;
use AfricaGates\Services\MergeSuggestionService;
use AfricaGates\Support\EditionName;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Duplicates and net counts (`/admin/nominees/duplicates`, a sub-page of Nominations).
 *
 * One screen for the question the merge scan and the totals had answered separately and
 * differently: how many PEOPLE are in this edition — nominated, and voting. Same-category
 * duplicates are merged from here (with a preview that runs the merge and rolls it back);
 * one person across several categories is a ruling, not a merge; near matches are ruled on
 * by a person or put to the AI, whose answers are marked as its own and can be overruled.
 *
 * Rulings and merges are integrity decisions: they need Permissions::canManageIntegrity,
 * the gate the merge has always had. Reading the counts needs only the page.
 */
final class DuplicatesController
{
    public function __construct(private readonly Twig $view, private readonly AuditService $audit) {}

    private static function canRule(): bool
    {
        return Permissions::canManageIntegrity((string) ($_SESSION['admin_role'] ?? ''));
    }

    /** The latest edition by default — the one whose counts are moving. */
    private static function cycles(): array
    {
        try {
            return DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->orderByDesc('c.year')->orderByDesc('c.id')->get(['c.*', 'p.title as programme'])
                ->map(static fn (object $c): array => ['id' => (int) $c->id, 'label' => (string) $c->programme . ' · ' . EditionName::full($c)])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $cycles = self::cycles();
        $cycleId = (int) ($q['cycle'] ?? 0);
        if (!in_array($cycleId, array_column($cycles, 'id'), true)) $cycleId = (int) ($cycles[0]['id'] ?? 0);
        $tab = in_array($q['tab'] ?? '', ['split', 'across', 'near', 'merged'], true) ? (string) $q['tab'] : 'split';

        $people = $cycleId ? NomineePeople::forCycle($cycleId) : null;
        $voters = $cycleId ? VoterPeople::count($cycleId) : null;

        $split = $across = [];
        foreach ($people['clusters'] ?? [] as $c) {
            if ($c['mergeable']) $split[] = $c; else $across[] = $c;
        }

        // A merge preview, when asked for: the real merge, run and rolled back.
        $preview = null;
        if (!empty($q['keep']) && !empty($q['ids'])) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $q['ids']))));
            $keep = (int) $q['keep'];
            $take = [];
            foreach ((array) ($q['take'] ?? []) as $f => $from) {
                if (in_array($f, MergeService::TAKEABLE, true) && (int) $from > 0) $take[$f] = (int) $from;
            }
            $preview = self::previewFor($keep, $ids, $take);
        }

        return $this->view->render($res, 'admin/nominees/duplicates.twig', [
            'page_title' => 'Duplicates — Admin',
            'admin_page' => 'duplicates',
            'cycles'     => $cycles,
            'cycle_id'   => $cycleId,
            'tab'        => $tab,
            'people'     => $people,
            'voters'     => $voters,
            'split'      => $split,
            'across'     => $across,
            'merged'     => MergeService::recentlyMerged(30),
            'preview'    => $preview,
            'can_rule'   => self::canRule(),
            'ai_ready'   => AiGateway::available('nominee.identity_verify'),
            'ai_waiting' => count(array_filter($people['pairs'] ?? [], static fn (array $p): bool => empty($p['ai']))),
            'base'       => '/admin/nominees/duplicates?cycle=' . $cycleId,
        ]);
    }

    /** Labels for the tables a merge moves rows in, as the preview says them. */
    private const MOVED = [
        'gates_votes' => 'vote', 'gates_judge_criteria_scores' => 'judge mark', 'gates_judge_notes' => 'judge note',
        'gates_vote_milestones' => 'milestone', 'gates_collusion_findings' => 'integrity finding',
        'gates_funnel_events' => 'analytics event', 'gates_otp_tokens' => 'vote code', 'gates_donations' => 'payment',
        'gates_comments' => 'comment', 'gates_cheers' => 'cheer', 'gates_uploads' => 'upload',
        'gates_form_tokens' => 'form link', 'gates_events' => 'event', 'gates_activity' => 'activity item',
        'gates_nominee_claims' => 'profile claim',
    ];

    /**
     * The merge, run and rolled back, in the words the preview prints.
     *
     * @param list<int> $ids
     * @param array<string,int> $take
     * @return array<string,mixed>
     */
    private static function previewFor(int $keep, array $ids, array $take): array
    {
        $before = DB::table('gates_nominees')->whereIn('id', array_merge($ids, [$keep]))->get(['id', 'name', 'vote_count'])->keyBy('id');
        $r = MergeService::preview($keep, $ids, $take);
        $out = $r + ['ids' => $ids, 'take' => $take, 'keep_id' => $keep,
                     'names' => $before->map(static fn (object $n): string => (string) $n->name)->all(),
                     'votes_before' => (int) ($before[$keep]->vote_count ?? 0)];
        if (!$r['ok']) return $out;

        $out['votes_after'] = (int) $r['votes'];
        // The merge rebuilds the survivor's total from the ballot ledger. A tally imported
        // without ballot rows (an edition run before this platform held them) has nothing
        // to rebuild from, so it shrinks — say so BEFORE the merge, in figures.
        $out['votes_listed'] = (int) $before->sum('vote_count');
        $out['votes_lost'] = max(0, $out['votes_listed'] - $out['votes_after']);
        $moves = [];
        foreach ($r['moved'] as $tbl => $n) {
            $moves[] = ['kind' => 'move', 'n' => $n, 'label' => self::MOVED[$tbl] ?? $tbl];
        }
        foreach ($r['dropped'] as $tbl => $n) {
            // A collision: the survivor already holds the same judge's mark on the same
            // criterion, or the same cheer. The survivor's row stands; this one is kept in
            // the journal so an undo puts it back.
            $moves[] = ['kind' => 'drop', 'n' => $n, 'label' => (self::MOVED[$tbl] ?? $tbl) . ($n === 1 ? '' : 's')
                . ' already on the survivor from the same source — the survivor\'s stands; the other is kept for an undo'];
        }
        $out['moves'] = $moves;

        $labels = ['name' => 'Name', 'tagline' => 'Tagline', 'organisation' => 'Organisation', 'country_code' => 'Country',
                   'story' => 'Story', 'photo_path' => 'Photo'];
        $keepRow = DB::table('gates_nominees')->where('id', $keep)->first();
        $adopt = [];
        foreach ($r['survivor'] as $f => $v) {
            if ($v === (string) ($keepRow->{$f} ?? '')) continue;
            $adopt[] = ($labels[$f] ?? $f) . ($f === 'photo_path' ? ' taken from another entry' : ' becomes “' . mb_strimwidth($v, 0, 80, '…') . '”');
        }
        $out['adopt'] = $adopt;
        $out['fields'] = self::fieldChoices($keep, $ids, $take);
        return $out;
    }

    /**
     * The fields where the entries disagree, each with whose value the survivor will carry:
     * the admin's choice, else the survivor's own, else the first entry that has one — the
     * same default MergeService applies, so what is ticked is what will happen.
     *
     * @param list<int> $ids
     * @param array<string,int> $take
     * @return list<array{field:string, label:string, options:list<array{id:int, value:string, on:bool}>}>
     */
    private static function fieldChoices(int $keep, array $ids, array $take): array
    {
        $labels = ['name' => 'Name', 'tagline' => 'Tagline', 'organisation' => 'Organisation', 'country_code' => 'Country',
                   'story' => 'Story', 'photo_path' => 'Photo'];
        $cols = array_values(array_filter(array_keys($labels), static fn (string $c): bool => \AfricaGates\Support\SchemaHas::column('gates_nominees', $c)));
        if (!$cols) return [];
        $all = array_values(array_unique(array_merge([$keep], $ids)));
        $rows = DB::table('gates_nominees')->whereIn('id', $all)->get(array_merge(['id'], $cols))->keyBy('id');
        $out = [];
        foreach ($cols as $f) {
            $opts = [];
            foreach ($all as $id) {
                $v = trim((string) ($rows[$id]->{$f} ?? ''));
                if ($v === '' || in_array($v, array_column($opts, 'full'), true)) continue;
                $opts[] = ['id' => $id, 'full' => $v, 'value' => $f === 'photo_path' ? 'Photo of #' . $id : mb_strimwidth($v, 0, 90, '…')];
            }
            if (count($opts) < 2) continue;                // nothing to choose between
            $own = trim((string) ($rows[$keep]->{$f} ?? ''));
            $chosen = $take[$f] ?? ($own !== '' ? $keep : $opts[0]['id']);
            $out[] = ['field' => $f, 'label' => $labels[$f], 'options' => array_map(
                static fn (array $o): array => ['id' => $o['id'], 'value' => $o['value'], 'on' => $o['id'] === $chosen], $opts)];
        }
        return $out;
    }

    private function back(array $b): string
    {
        $c = (int) ($b['cycle'] ?? 0);
        $t = in_array($b['tab'] ?? '', ['split', 'across', 'near', 'merged'], true) ? '&tab=' . $b['tab'] : '';
        return '/admin/nominees/duplicates' . ($c > 0 ? '?cycle=' . $c . $t : '');
    }

    /** POST …/rule — "same person" or "not the same person" on a set of entries. */
    public function rule(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $back = $this->back($b);
        if (!self::canRule()) {
            $_SESSION['flash_error'] = 'Only an admin can rule on whether two entries are one person.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])))));
        $verdict = (string) ($b['verdict'] ?? '');
        if (count($ids) < 2 || !in_array($verdict, [IdentityVerdicts::SAME, IdentityVerdicts::DIFFERENT, 'forget'], true)) {
            $_SESSION['flash_error'] = 'Choose two entries and a ruling.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $reason = mb_substr(trim((string) ($b['_reason'] ?? '')), 0, 500);
        if ($verdict === 'forget') {
            IdentityVerdicts::forget('nominee', $ids[0], $ids[1]);
            $this->audit->record($admin, 'nominee.identity_forget', 'nominee', $ids[0], ['ids' => $ids]);
            $_SESSION['flash_ok'] = 'Ruling withdrawn. The rules — and the AI, if it is on — decide that pair again.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        IdentityVerdicts::record('nominee', $ids, $verdict, 'human', null, $reason, $admin ?: null);
        // A "different" inside one category is also what the merge scan reads, so it stops
        // proposing the pair there too.
        if ($verdict === IdentityVerdicts::DIFFERENT) {
            $cats = DB::table('gates_nominees')->whereIn('id', $ids)->pluck('category_id')->unique();
            if ($cats->count() === 1) MergeSuggestionService::dismiss($ids, $admin ?: null, $reason);
        }
        $this->audit->record($admin, 'nominee.identity_' . $verdict, 'nominee', $ids[0], ['ids' => $ids]);
        $_SESSION['flash_ok'] = $verdict === IdentityVerdicts::SAME
            ? 'Recorded as one person. They stay separate entries; the people count now counts them once.'
            : 'Recorded as different people. Neither the scan nor the AI will pair them again.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** POST …/ai — put the near matches nobody has ruled on to the AI. */
    public function ai(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $back = $this->back($b + ['tab' => 'near']);
        if (!self::canRule()) {
            $_SESSION['flash_error'] = 'Only an admin can ask the AI to rule on duplicates.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $r = NomineePeople::verifyWithAi((int) ($b['cycle'] ?? 0));
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nominee.identity_ai', 'cycle', (int) ($b['cycle'] ?? 0) ?: null,
            ['asked' => $r['asked'], 'same' => $r['same'], 'different' => $r['different']]);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** POST …/merge — the merge the preview showed, back to this page rather than the referer. */
    public function merge(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $back = $this->back($b + ['tab' => 'split']);
        if (!self::canRule()) {
            $_SESSION['flash_error'] = 'Only an admin can merge nominees (it moves votes and judge scores).';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $take = [];
        foreach ((array) ($b['take'] ?? []) as $f => $from) {
            if (in_array($f, MergeService::TAKEABLE, true) && (int) $from > 0) $take[$f] = (int) $from;
        }
        $r = MergeService::mergeNominees((int) ($b['keep_id'] ?? 0), array_map('intval', (array) ($b['merge_ids'] ?? [])),
            (int) ($_SESSION['admin_id'] ?? 0) ?: null, false, $take);
        if ($r['ok']) {
            $_SESSION['flash_ok'] = sprintf('Merged %d duplicate%s — %s vote%s now count together. Undo it from "Recently merged" at any time.',
                $r['merged'], $r['merged'] === 1 ? '' : 's', number_format($r['votes']), $r['votes'] === 1 ? '' : 's');
        } else {
            $_SESSION['flash_error'] = $r['error'] ?? 'The merge could not be completed.';
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }
}
