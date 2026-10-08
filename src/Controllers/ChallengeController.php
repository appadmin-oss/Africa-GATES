<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\ChallengeCopy;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The public challenge pages — /challenges and /challenges/{slug}.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE TEMPLATE RENDERS EVERY CHALLENGE, AND EVERY WORD COMES FROM `ChallengeCopy`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "A challenge is data. One template renders all of them." Nothing here composes a
 * sentence: the promise, the rules, the steps, the CTA and the empty states all arrive
 * from `ChallengeCopy::for()`, which is the ported design function. A controller that
 * built one string of its own would be the second place a challenge's terms are
 * written, and the two would disagree the first time a cap changed.
 *
 * ── A DRAFT IS NOT A PAGE ───────────────────────────────────────────────────
 *
 * `draft` and `cancelled` 404. A draft is a page an admin is still writing, with a
 * prize figure that may be a placeholder, and serving it is publishing an offer nobody
 * approved. This is the same rule `PublicResults` arrived at for a judged-but-
 * unreleased cycle: the status is the gate, not a date.
 *
 * ── AND THE SANDBOX CANNOT REACH IT ─────────────────────────────────────────
 *
 * The scoped lists resolve through the category chain, so a challenge scoped to a
 * rehearsal programme shows no awards rather than the sandbox's. A reader that started
 * at an id and filtered on it would have no containment at all, which is how the
 * ballot and claim pages leaked.
 */
final class ChallengeController
{
    public function __construct(private readonly Twig $view) {}

    /** GET /challenges — everything a visitor may take part in. */
    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_challenges')
            ->whereIn('status', [E::ST_OPEN, E::ST_FULL, E::ST_UPCOMING, E::ST_ENDED])
            // Open first, then what is about to start, then what is over. A list that
            // leads with an ended challenge wastes the only screen most people see.
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'upcoming' THEN 1 "
                . "WHEN 'full' THEN 2 ELSE 3 END")
            ->orderBy('ends_at')
            ->limit(60)->get();

        $cards = [];
        foreach ($rows as $c) {
            $row  = (array) $c;
            $copy = ChallengeCopy::for($row, ['claimed' => CS::claimed((int) $c->id)]);

            $cards[] = [
                'slug' => (string) $c->slug, 'title' => (string) $c->title,
                'kicker' => (string) $c->kicker, 'theme' => (string) $c->theme,
                'icon' => (string) ($c->icon ?? ''), 'art' => (string) ($c->art_url ?? ''),
                'state' => $copy['state'], 'state_label' => $copy['state_label'],
                'line' => trim($copy['prize_big'] . ' ' . $copy['prize_unit'])
                          . ' · ' . (int) $c->target . ' ' . $copy['u'],
                'time_left' => $copy['time_left'],
            ];
        }

        return $this->view->render($res, 'pages/challenges/index.twig', [
            'page_title' => 'Challenges — Africa GATES',
            'gates_page' => 'challenges',
            'cards'      => $cards,
        ]);
    }

    /** GET /challenges/{slug} */
    public function show(Request $req, Response $res, array $args): Response
    {
        $c = CS::bySlug((string) ($args['slug'] ?? ''));

        // A draft is a page somebody is still writing; a cancelled one is an offer that
        // was withdrawn. Neither is a public page, and neither is a 410 — the slug may
        // be reused when the admin publishes.
        if (!$c || in_array($c->status, [E::ST_DRAFT, E::ST_CANCELLED], true)) {
            return $res->withStatus(404);
        }

        $userId  = (int) ($_SESSION['user_id'] ?? 0);
        $entry   = $userId > 0 ? CS::entryFor((int) $c->id, $userId) : null;
        $claimed = CS::claimed((int) $c->id);

        $included = $this->included($c);

        // The scopes ARE the award and the categories. The design's fixture carries
        // them as two strings; a real row carries rows in `gates_challenge_scopes`, so
        // without this the "How to take part" step reads "For , in , with every field
        // complete." — which is what the first live render of this page said.
        $copy = ChallengeCopy::for(((array) $c) + $this->scopeWords($included), [
            'claimed'   => $claimed,
            'signed_in' => $userId > 0,
            'mine'      => (int) ($entry->verified ?? 0),
        ]);

        return $this->view->render($res, 'pages/challenges/show.twig', [
            'page_title'  => $c->title . ' — Africa GATES',
            'meta_description' => $copy['promise'],
            'gates_page'  => 'challenges',
            'c'           => $c,
            'copy'        => $copy,
            'entry'       => $entry,
            // The meter's dots. Built here rather than in the template because
            // `range()` over a null cap is a 500 on a draw challenge.
            'spots'       => $copy['has_meter'] && (int) $c->cap > 0
                ? array_map(static fn($i) => $i < $copy['claimed'], range(0, (int) $c->cap - 1))
                : [],
            'winners'     => CS::winners((int) $c->id, 12),
            'included'    => $included,
            // Who runs the award this counts inside. Resolved through the scope chain by
            // the one resolver, never stored on the challenge — see ProgrammeHost.
            'host'        => \AfricaGates\Support\ProgrammeHost::forScopes(
                CS::scopeIds($c, E::SCOPE_CYCLE), CS::scopeIds($c, E::SCOPE_CATEGORY)),
            'others'      => $this->others((int) $c->id),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * What this challenge counts inside — the "Included" section.
     *
     * §3 of the handoff lists it; the design comp does not draw it, so the markup is
     * this codebase's own list idiom rather than a match to something. Recorded as a
     * deviation rather than invented silently.
     *
     * Every lookup walks the category chain, so a scope pointing into an inactive
     * programme resolves to nothing instead of naming the rehearsal.
     */
    private function included(object $c): array
    {
        $out = [];

        foreach (CS::scopeIds($c, E::SCOPE_CYCLE) as $id) {
            $row = DB::table('gates_award_cycles as cy')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('cy.id', $id)->where('p.is_active', 1)
                ->first(['p.title', 'cy.edition_label', 'cy.year', 'p.slug']);

            if ($row) $out[] = ['kind' => 'Award', 'name' => trim($row->title . ' ' . ($row->edition_label ?: $row->year)),
                                'href' => '/awards/' . $row->slug];
        }

        foreach (CS::scopeIds($c, E::SCOPE_CATEGORY) as $id) {
            $row = DB::table('gates_award_categories as ct')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'ct.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('ct.id', $id)->where('p.is_active', 1)
                ->first(['ct.title', 'p.slug']);

            if ($row) $out[] = ['kind' => 'Category', 'name' => (string) $row->title,
                                'href' => '/awards/' . $row->slug];
        }

        foreach (CS::scopeIds($c, E::SCOPE_EVENT) as $id) {
            $row = DB::table('gates_site_events')->where('id', $id)->first(['title', 'slug']);
            if ($row) $out[] = ['kind' => 'Event', 'name' => (string) $row->title,
                                'href' => '/events/' . $row->slug];
        }

        return $out;
    }

    /**
     * The scoped awards and categories as the two phrases the steps need.
     *
     * Read off the SAME resolved list the "Included" section draws, so the sentence and
     * the list can never name different awards — one resolver, not two, which is the
     * rule this codebase keeps relearning.
     *
     * @return array{award:string,cats:string}
     */
    private function scopeWords(array $included): array
    {
        $awards = [];
        $cats   = [];

        foreach ($included as $i) {
            if ($i['kind'] === 'Award')    $awards[] = $i['name'];
            if ($i['kind'] === 'Category') $cats[]   = $i['name'];
        }

        return ['award' => $this->listOf($awards), 'cats' => $this->listOf($cats)];
    }

    /** "a", "a or b", "a, b or c" — an Oxford-less list, as the copy reads elsewhere. */
    private function listOf(array $items): string
    {
        $items = array_values(array_unique(array_filter($items)));

        if ($items === []) return '';
        if (count($items) === 1) return $items[0];

        $last = array_pop($items);

        return implode(', ', $items) . ' or ' . $last;
    }

    /** The other challenges, for the tail of the page. */
    private function others(int $exceptId): array
    {
        $rows = DB::table('gates_challenges')
            ->where('id', '!=', $exceptId)
            ->whereIn('status', [E::ST_OPEN, E::ST_UPCOMING])
            ->orderBy('ends_at')->limit(4)->get();

        $out = [];
        foreach ($rows as $o) {
            $copy = ChallengeCopy::for((array) $o, ['claimed' => CS::claimed((int) $o->id)]);
            $out[] = [
                'slug' => (string) $o->slug, 'title' => (string) $o->title,
                'theme' => (string) $o->theme, 'icon' => (string) ($o->icon ?? ''),
                'state_label' => $copy['state_label'], 'state' => $copy['state'],
                'line' => trim($copy['prize_big'] . ' ' . $copy['prize_unit'])
                          . ' · ' . (int) $o->target . ' ' . $copy['u'],
            ];
        }

        return $out;
    }
}
