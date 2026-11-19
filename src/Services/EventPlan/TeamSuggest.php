<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\AiGateway;

/**
 * THE ASSISTANT UNDERGROUND (owner, 3 Nov 2026: "AI helps underground. It also helps tailor
 * the portals properly for whatever kind of role").
 *
 * Three suggestions, each a SUGGESTION — labelled as one, editable, never applied:
 *
 *   · suggest(): a team structure and roles, with ticks, for an event from its title, size and
 *     plan. The lead sees it as a set of proposal forms already filled in; pressing one is the
 *     lead proposing it, and an owner still decides.
 *   · review(): notes for the owner on one role's ticks, written once at proposal. The fixed
 *     combinations in EventPermissions::RISKY are always said; these only add to them.
 *   · each suggested role's `lead_with`: the pages its portal leads with.
 *
 * Every one works with NO provider (tests assert it): suggest() falls back to the templates
 * below, review() to nothing beyond the fixed flags. Whatever a model answers is kept to the
 * menu — a permission it invents is dropped, never enforced, because nothing can enforce it.
 */
final class TeamSuggest
{
    public const CAP_SUGGEST = 'event.team_suggest';
    public const CAP_REVIEW = 'event.role_review';

    /**
     * Templates by shape of event. Each role: name, team, kind, description, ticks, lead_with.
     * Money is split on purpose — whoever plans spend does not approve it.
     */
    private const TEAMS = [
        'programme' => ['Programme and stage', 'The running order, the stage and everybody on it.'],
        'guests'    => ['Guests and hospitality', 'Guests of honour, seating and the welcome.'],
        'partners'  => ['Partnerships', 'Sponsors and the people who back the night.'],
        'ops'       => ['Operations and safety', 'The door, safety checks and volunteers on the night.'],
        'finance'   => ['Finance', 'The budget and what has been committed and paid.'],
        'comms'     => ['Communications', 'Letters and messages to guests and ticket holders.'],
    ];

    private const ROLES = [
        ['Stage manager', 'programme', 'staff', 'Runs the show on the night and keeps the plan moving.', ['plan.view', 'plan.edit', 'show.run', 'safety.manage'], 'night,plan'],
        ['Hospitality lead', 'guests', 'staff', 'Seats the guests of honour and looks after them on the night.', ['plan.view', 'guests.manage', 'show.run'], 'people,night'],
        ['Sponsorship coordinator', 'partners', 'staff', 'Brings sponsors in and moves them to paid.', ['sponsors.manage', 'money.view', 'reports.view'], 'crm,money'],
        ['Operations and safety officer', 'ops', 'staff', 'The door, the safety checks and the volunteers on the night.', ['plan.view', 'safety.manage', 'door.manage', 'volunteers.checkin'], 'night,people'],
        ['Treasurer', 'finance', 'staff', 'Plans the spend and records what is committed and paid. Approval stays with the lead.', ['money.view', 'money.plan', 'reports.view'], 'money,reports'],
        ['Communications officer', 'comms', 'staff', 'Sends the letters and the approved messages.', ['messages.send', 'reports.view'], 'crm,reports'],
        ['Door volunteer coordinator', 'ops', 'volunteer', 'Checks volunteers in and out at the door.', ['volunteers.checkin', 'show.run'], 'people,night'],
    ];

    public function __construct(private readonly ?AiGateway $gateway = null) {}

    /**
     * @return array{source:string, note:string, teams:list<array{key:string,name:string,purpose:string}>,
     *               roles:list<array{name:string,team:string,kind:string,description:string,perms:list<string>,lead_with:list<string>}>}
     */
    public function suggest(object $event): array
    {
        $fallback = self::template($event);
        $menu = [];
        foreach (EventPermissions::MENU as $k => $m) $menu[] = $k . ' — ' . $m['label'] . ': ' . $m['help'];
        $facts = self::facts($event);
        $r = ($this->gateway ?? new AiGateway())->run(self::CAP_SUGGEST, [
            'system' => 'You help the lead of a live event organise its team. Suggest 2 to 6 teams and 3 to 8 roles. '
                . 'Each role\'s permissions MUST be keys from the menu the user gives, and nothing else. '
                . 'Never put money.plan and money.approve in one role. Never suggest a role that gives other people access. '
                . 'Reply ONLY with JSON: {"teams":[{"key":"short_id","name":"…","purpose":"…"}],'
                . '"roles":[{"name":"…","team":"short_id","kind":"staff|volunteer","description":"…","perms":["menu.key"],"lead_with":["page"]}],"note":"one sentence"}. '
                . 'Pages for lead_with: plan, people, crm, money, night, reports, requests, settings, award, growth.',
            'trusted' => "The permission menu:\n" . implode("\n", $menu),
            'user' => $facts, 'json' => true,
            'schema' => static fn (string $raw) => self::clean($raw),
            'subject_type' => 'event', 'subject_id' => (int) $event->id,
        ]);
        if ($r->ok && is_array($r->value)) return $r->value + ['source' => 'assistant'];
        return $fallback;
    }

    /** @return list<string> the assistant's own concerns about one role — [] when there is no provider */
    public function review(?object $event, string $name, string $description, array $perms, string $kind): array
    {
        if ($perms === []) return [];
        $labels = array_map(static fn ($p) => $p . ' (' . EventPermissions::MENU[$p]['label'] . ', ' . EventPermissions::MENU[$p]['risk'] . ')', $perms);
        $r = ($this->gateway ?? new AiGateway())->run(self::CAP_REVIEW, [
            'system' => 'You check a proposed role at a live event before its owner approves it. Name at most three concrete risks in the '
                . 'combination of permissions — duties that should be split, or access wider than the role\'s description needs. '
                . 'Plain sentences, no more than 25 words each. If there is nothing worth saying, return an empty list. '
                . 'Reply ONLY with JSON: {"concerns":["…"]}.',
            'trusted' => 'Permissions: ' . implode('; ', $labels) . "\nKind: " . $kind . "\nEvent: " . (string) ($event->title ?? ''),
            'user' => 'Role: ' . $name . "\nDescription: " . $description, 'json' => true,
            'schema' => static function (string $raw): ?array {
                $j = json_decode($raw, true);
                if (!is_array($j) || !isset($j['concerns']) || !is_array($j['concerns'])) return null;
                return array_values(array_slice(array_filter(array_map(static fn ($c) => is_string($c) ? mb_substr(trim($c), 0, 200) : '', $j['concerns'])), 0, 3));
            },
            'subject_type' => 'event', 'subject_id' => (int) ($event->id ?? 0),
        ]);
        return $r->ok && is_array($r->value) ? $r->value : [];
    }

    /**
     * The deterministic suggestion: always available, the same for the same event.
     *
     * @return array{source:string, note:string, teams:list<array>, roles:list<array>}
     */
    public static function template(object $event): array
    {
        $title = mb_strtolower((string) $event->title . ' ' . (string) ($event->tagline ?? ''));
        $awards = Scope::awards((int) $event->id) !== [];
        $cap = (int) ($event->capacity ?? 0);
        $ceremony = $awards || preg_match('/award|ceremony|gala|dinner|honour/', $title) === 1;
        $large = $cap >= 500;
        $small = $cap > 0 && $cap < 150;
        $want = $small ? ['programme', 'guests', 'finance'] : ['programme', 'guests', 'partners', 'ops', 'finance'];
        if ($ceremony || $large) $want[] = 'comms';
        $roles = [];
        foreach (self::ROLES as [$name, $team, $kind, $desc, $perms, $lead]) {
            if (!in_array($team, $want, true)) continue;
            if ($kind === 'volunteer' && !$large) continue;
            $roles[] = ['name' => $name, 'team' => $team, 'kind' => $kind, 'description' => $desc, 'perms' => $perms, 'lead_with' => explode(',', $lead)];
        }
        $teams = [];
        foreach ($want as $k) $teams[] = ['key' => $k, 'name' => self::TEAMS[$k][0], 'purpose' => self::TEAMS[$k][1]];
        $shape = $ceremony ? 'an award ceremony' : 'an event';
        $size = $large ? ' for ' . number_format($cap) . ' people' : ($small ? ' of under 150 people' : '');
        return ['source' => 'template', 'note' => 'A starting point for ' . $shape . $size . '. Change any of it before you propose it.', 'teams' => $teams, 'roles' => $roles];
    }

    /**
     * A model's answer, kept to what this platform can do: unknown permissions and pages dropped,
     * a role left with no permission dropped, money split if it was not, sizes capped.
     */
    public static function clean(string $raw): ?array
    {
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['teams'], $j['roles']) || !is_array($j['teams']) || !is_array($j['roles'])) return null;
        $teams = [];
        foreach (array_slice($j['teams'], 0, 8) as $t) {
            if (!is_array($t) || trim((string) ($t['name'] ?? '')) === '') continue;
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($t['key'] ?? $t['name']))) ?: 't' . count($teams);
            $teams[] = ['key' => $key, 'name' => mb_substr(trim((string) $t['name']), 0, 120), 'purpose' => mb_substr(trim((string) ($t['purpose'] ?? '')), 0, 300)];
        }
        $keys = array_column($teams, 'key');
        $pages = array_keys(EventPages::TABS) + [];
        $pages = array_merge($pages, ['award', 'growth', 'requests']);
        $roles = [];
        foreach (array_slice($j['roles'], 0, 12) as $r) {
            if (!is_array($r) || trim((string) ($r['name'] ?? '')) === '') continue;
            $perms = array_values(array_unique(array_filter(array_map('strval', (array) ($r['perms'] ?? [])), static fn ($p) => isset(EventPermissions::MENU[$p]))));
            if (in_array('money.plan', $perms, true) && in_array('money.approve', $perms, true)) $perms = array_values(array_diff($perms, ['money.approve']));
            if ($perms === []) continue;
            $roles[] = ['name' => mb_substr(trim((string) $r['name']), 0, 120), 'team' => in_array((string) ($r['team'] ?? ''), $keys, true) ? (string) $r['team'] : '',
                        'kind' => ($r['kind'] ?? '') === 'volunteer' ? 'volunteer' : 'staff', 'description' => mb_substr(trim((string) ($r['description'] ?? '')), 0, 300),
                        'perms' => $perms, 'lead_with' => EventPermissions::leadWith($perms, implode(',', array_intersect(array_map('strval', (array) ($r['lead_with'] ?? [])), $pages)))];
        }
        if ($roles === []) return null;
        return ['teams' => $teams, 'roles' => $roles, 'note' => mb_substr(trim((string) ($j['note'] ?? '')), 0, 300)];
    }

    /** What the model is told about the event: nothing about any person. */
    private static function facts(object $event): string
    {
        $id = (int) $event->id;
        $work = count(Plan::work([$id]));
        $lines = [
            'Title: ' . (string) $event->title,
            'Tagline: ' . mb_substr((string) ($event->tagline ?? ''), 0, 200),
            'About: ' . mb_substr(strip_tags((string) ($event->description ?? '')), 0, 600),
            'Date: ' . date('j F Y', (int) strtotime((string) $event->event_date)),
            'Capacity: ' . ((int) ($event->capacity ?? 0) ?: 'not set'),
            'Award ceremony: ' . (Scope::awards($id) ? 'yes' : 'no'),
            'Pieces of work planned: ' . $work,
            'Sponsors so far: ' . count(Scope::sponsors($id)),
        ];
        return implode("\n", $lines);
    }
}
