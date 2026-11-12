<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Volunteer\Workspace;

/**
 * THE GOALS AND RULES PAGES of an event in the lead console: what each section the v7 shell
 * draws holds, built from Goals, Planner, Pipeline, Setup, Rules and Changes and nothing else.
 * Every form posts to the console's one act endpoint and every writer re-checks the actor on
 * the row's own event (LeadController), so what is drawn here is convenience, never permission.
 */
final class GoalPages
{
    public static function page(array $me, object $e, string $tab, array $q): array
    {
        $id = (int) $e->id;
        if (!Goals::ready()) return ['title' => 'Goals', 'secs' => [['k' => 'note', 'tone' => 'warn', 'text' => 'The database needs its latest update before goals can be set. Run the pending migrations.']]];
        return match ($tab) {
            'numbers' => self::numbers($id),
            'pipeline' => self::pipeline($me, $id, (string) ($q['stage'] ?? '')),
            'setup' => self::setup($id, (string) ($q['say'] ?? '')),
            default => self::progress($id),
        };
    }

    // ── Goals & gates ──────────────────────────────────────────────────────────────────

    private static function progress(int $id): array
    {
        $goals = Goals::forEvent($id);
        if (!$goals) {
            return ['title' => 'Goals', 'secs' => [['k' => 'note', 'tone' => 'info',
                'text' => 'No goal yet. Say what this event is for in one sentence — how much, of what, by when — and the gates, the numbers and what each person must do are drafted from it.',
                'cta' => ['label' => 'Set a goal', 'href' => EventPages::url($id, 'goals', 'setup'), 'primary' => true]]]];
        }
        $secs = [];
        foreach ($goals as $g) {
            $scope = Goals::scopes($id)[$g->scope_kind === 'event' ? 'event' : $g->scope_kind . ':' . $g->scope_id] ?? '';
            $secs[] = ['k' => 'stats', 'h' => (string) $g->title, 'items' => [
                ['l' => Goals::METRICS[$g->metric][0], 'v' => Goals::fmt((string) $g->metric, $g->value), 's' => 'of ' . Goals::fmt((string) $g->metric, (int) $g->target) . ' · ' . $g->pct . '%'],
                ['l' => 'The plan expects by now', 'v' => $g->expected * 100 >= (int) $g->target ? Goals::fmt((string) $g->metric, $g->expected) : '—',
                 's' => $g->behind ? 'More than 20% behind' : ($g->expected * 100 >= (int) $g->target ? ($g->value >= $g->expected ? 'Ahead of plan' : 'On course') : 'Not started yet')],
                ['l' => 'Deadline', 'v' => Goals::day((string) $g->deadline), 's' => $scope],
            ]];
            if ($g->behind) $secs[] = ['k' => 'note', 'tone' => 'warn', 'text' => 'Behind plan. Find the cause before changing anything: a broken form, too few people on it, a thin pipeline or something it waits on.'];
            $rows = [];
            foreach (Goals::gates((int) $g->id) as $x) {
                $chip = match ($x->state) { 'passed' => ['Passed', 'ok'], 'missed' => ['Missed', 'bad'], 'ready' => ['Reached — sign it off', 'ok'],
                    'late' => ['Due — not reached', 'bad'], 'threat' => ['At risk', 'warn'], default => ['Upcoming', 'mute'] };
                $row = ['t' => (string) $x->title, 'chip' => $chip,
                        'sub' => 'Due ' . Goals::day((string) $x->due_at) . ' · ' . Goals::fmt((string) $g->metric, (int) $x->value) . ' of ' . Goals::fmt((string) $g->metric, (int) $x->target)
                               . ' · signed by ' . ($x->approver === 'owner' ? 'an owner' : 'a lead') . ($x->signed_note ? ' · “' . $x->signed_note . '”' : '')];
                // Signed off once it is reached or its date has come — never weeks ahead, when the
                // only honest answer would be a guess.
                if ($x->status === 'open' && ($x->met || in_array($x->state, ['late'], true))) {
                    $row['form'] = ['id' => 'g' . $x->id, 'do' => 'gate_sign', 'hidden' => ['gate' => $x->id], 'submit' => 'Sign off', 'fields' => $x->met
                        ? [['name' => 'verdict', 'label' => 'Result', 'type' => 'select', 'options' => ['passed' => 'Passed']], ['name' => 'note', 'label' => 'Note', 'placeholder' => 'Note (optional)']]
                        : [['name' => 'verdict', 'label' => 'Result', 'type' => 'select', 'options' => ['missed' => 'Missed']], ['name' => 'note', 'label' => 'What happens next', 'placeholder' => 'What happens next', 'required' => true]]];
                }
                $rows[] = $row;
            }
            if ($rows) $secs[] = ['k' => 'rows', 'h' => 'Gates', 'items' => $rows];
            $steps = Planner::solve($g);
            if ($steps) $secs[] = ['k' => 'rows', 'h' => 'What it takes', 'items' => array_map(static fn ($s) => ['t' => $s['t'] . ': ' . $s['v'], 'sub' => $s['from']], $steps),
                                   'link' => ['label' => 'Change the numbers', 'href' => EventPages::url($id, 'goals', 'numbers')]];
            $secs[] = ['k' => 'form', 'h' => 'Change this goal', 'do' => 'goal_change', 'hidden' => ['goal' => $g->id], 'submit' => 'Ask for the change', 'groups' => [
                ['label' => 'A large change', 'help' => 'Changing a goal’s target or deadline moves every gate and figure under it, so an owner decides it — never the person asking.',
                 'fields' => [['name' => 'target', 'label' => 'New target', 'type' => 'text', 'id' => 'gc_t' . $g->id, 'placeholder' => (string) $g->target],
                              ['name' => 'deadline', 'label' => 'New deadline', 'type' => 'date', 'id' => 'gc_d' . $g->id],
                              ['name' => 'reason', 'label' => 'Why', 'type' => 'text', 'required' => true, 'id' => 'gc_r' . $g->id]]],
            ]];
        }
        return ['title' => 'Goals', 'secs' => $secs, 'acts' => [['label' => 'Set another goal', 'href' => EventPages::url($id, 'goals', 'setup')]]];
    }

    // ── The numbers (assumption register) ─────────────────────────────────────────────

    private static function numbers(int $id): array
    {
        $secs = [['k' => 'note', 'tone' => 'mute', 'text' => 'Every figure the plan rests on that nobody has measured. Correct one and every target below it re-solves; the open gates move with it.']];
        foreach (Goals::forEvent($id) as $g) {
            $rows = [];
            foreach (Planner::assumptions((int) $g->id) as $a) {
                $rows[] = ['t' => $a->label . ': ' . self::shown($a), 'chip' => $a->status === 'open' ? ['Assumed', 'warn'] : ['Confirmed', 'ok'],
                           'sub' => $a->source . ' · reopen when: ' . mb_strtolower((string) $a->reopen_when),
                           'form' => ['id' => 'a' . $a->id, 'do' => 'assume_set', 'hidden' => ['assumption' => $a->id], 'submit' => 'Change',
                                      'fields' => [['name' => 'value', 'label' => 'New value', 'value' => (string) $a->value]]]];
            }
            if ($rows) $secs[] = ['k' => 'rows', 'h' => (string) $g->title, 'items' => $rows];
        }
        if (count($secs) === 1) $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing yet: the numbers arrive with the first goal.'];
        return ['title' => 'The numbers', 'secs' => $secs];
    }

    /** May this member decide this change? The same rule Changes::decide holds, for drawing only. */
    private static function mayDecide(object $c, int $memberId): bool
    {
        $eventId = (int) $c->event_id;
        if ($c->tier !== 'large') return Plan::may($memberId, $eventId, 'plan.edit');
        if ((int) $c->proposed_by === $memberId) return false;
        if (Teams::isOwner($memberId, $eventId)) return true;
        $money = ($c->subject_kind === 'goal' && \Illuminate\Database\Capsule\Manager::table('gates_event_goals')->where('id', (int) $c->subject_id)->value('metric') === 'revenue')
              || ($c->subject_kind === 'gate' && \Illuminate\Database\Capsule\Manager::table('gates_event_gates')->where('id', (int) $c->subject_id)->value('approver') === 'owner');
        return $money && \AfricaGates\Services\Grants::allows(Plan::ACTOR, $memberId, 'event.finance', 'event', $eventId);
    }

    private static function shown(object $a): string
    {
        return match ((string) $a->unit) {
            'naira' => '₦' . number_format((int) $a->value), 'percent' => $a->value . '%', 'percent list' => implode(' · ', array_map(static fn ($p) => $p . '%', explode(',', (string) $a->value))),
            'count' => number_format((int) $a->value), default => (string) $a->value,
        };
    }

    // ── Pipeline ─────────────────────────────────────────────────────────────────────

    private static function pipeline(array $me, int $id, string $stage): array
    {
        Pipeline::signal($id);   // the stage only a submitted form can prove is read fresh on every view
        $counts = Pipeline::counts($id);
        $secs = [['k' => 'stats', 'items' => array_map(static fn ($k) => ['l' => Pipeline::STAGES[$k], 'v' => (string) ($counts[$k] ?? 0)], array_keys(Pipeline::STAGES))]];
        $owners = ['' => 'Me'];
        foreach (Plan::team($id) as $p) if ($p['kind'] === Plan::ACTOR) $owners[$p['key']] = $p['name'] !== '' ? $p['name'] : $p['role'];
        $rows = [];
        foreach (Pipeline::list($id, $me['id'], $stage) as $c) {
            $mine = (int) $c->owner_id === $me['id'];
            $row = ['t' => (string) $c->name, 'ini' => Workspace::initials((string) $c->name),
                    'sub' => implode(' · ', array_filter([Pipeline::STAGES[$c->stage] ?? $c->stage, $mine ? 'yours' : (Plan::name(Plan::ACTOR, (int) $c->owner_id) ?: ''),
                        $c->reach ? Pipeline::REACH[(int) $c->reach] . ' (private)' : '', $c->nomination_id ? 'nomination #' . $c->nomination_id : '', (string) ($c->note ?? '')])),
                    'chip' => $c->stage === 'nominated' ? ['Submitted', 'ok'] : ($c->stage === 'declined' ? ['Declined', 'mute'] : null)];
            if ($c->stage !== 'nominated') {
                $row['form'] = ['id' => 'c' . $c->id, 'do' => 'contact_stage', 'hidden' => ['contact' => $c->id], 'submit' => 'Move',
                                'fields' => [['name' => 'stage', 'label' => 'Stage', 'type' => 'select', 'value' => (string) $c->stage,
                                              'options' => array_intersect_key(Pipeline::STAGES, array_flip(Pipeline::MANUAL))]]];
            }
            $rows[] = $row;
        }
        $filter = ['' => 'Every stage'] + Pipeline::STAGES;
        $secs[] = $rows ? ['k' => 'rows', 'h' => $stage !== '' ? Pipeline::STAGES[$stage] ?? 'Contacts' : 'Everybody in the pipeline', 'items' => $rows,
                           'link' => $stage !== '' ? ['label' => 'Show every stage', 'href' => EventPages::url($id, 'goals', 'pipeline')] : null]
                        : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nobody in the pipeline yet. Add the people the team means to ask, or paste a list from a sheet.'];
        unset($filter);
        $scopes = Goals::scopes($id);
        $secs[] = ['k' => 'form', 'h' => 'Add a prospect', 'do' => 'contact_add', 'hidden' => ['event' => $id], 'submit' => 'Add', 'groups' => [
            ['label' => 'Who', 'help' => 'Their email or phone is how the pipeline knows when their nomination arrives. A person already here is refused, with where they are.',
             'fields' => [['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'id' => 'ca_n'], ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'id' => 'ca_e'],
                          ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'id' => 'ca_p'], ['name' => 'scope', 'label' => 'For', 'type' => 'select', 'options' => $scopes, 'id' => 'ca_s'],
                          ['name' => 'owner', 'label' => 'Working them', 'type' => 'select', 'options' => $owners, 'id' => 'ca_o']]],
        ]];
        $secs[] = ['k' => 'form', 'h' => 'Paste a list', 'do' => 'contact_import', 'hidden' => ['event' => $id], 'submit' => 'Add them all', 'groups' => [
            ['label' => 'One person a line', 'help' => 'Name first, then their email and phone, separated by commas or tabs — the way a sheet pastes. Anybody already here is skipped and counted.',
             'fields' => [['name' => 'list', 'label' => 'The list', 'type' => 'textarea', 'required' => true, 'id' => 'ci_l'],
                          ['name' => 'scope', 'label' => 'For', 'type' => 'select', 'options' => $scopes, 'id' => 'ci_s'], ['name' => 'owner', 'label' => 'Working them', 'type' => 'select', 'options' => $owners, 'id' => 'ci_o']]],
        ]];
        return ['title' => 'Pipeline', 'secs' => $secs];
    }

    // ── Set a goal (the conversation) ──────────────────────────────────────────────────

    private static function setup(int $id, string $said): array
    {
        $secs = [['k' => 'form', 'h' => 'What is this event for?', 'do' => 'goal_describe', 'hidden' => ['event' => $id], 'submit' => 'Read it', 'groups' => [
            ['label' => 'In plain words', 'help' => 'How much or how many, of what, by when — and the award, if it is one. For example: “₦15M from paid votes by 6 December at ₦200 a vote”, or “60 nominees for the Teaching Award by 15 November”.',
             'fields' => [['name' => 'say', 'label' => 'The goal', 'type' => 'textarea', 'required' => true, 'value' => $said, 'id' => 'gs_say']]],
        ]]];
        if ($said === '') return ['title' => 'Set a goal', 'secs' => $secs];
        $r = Setup::read($id, $said);
        $secs[] = ['k' => 'note', 'tone' => $r['found'] ? 'info' : 'warn', 'text' => $r['found'] ? 'I read: ' . implode(', ', $r['found']) . '.' : 'I could not find a figure or a date in that, so a few questions instead.'];
        $groups = [];
        // Each missing piece is a card of a few answers, and says why it is asked.
        $groups[] = in_array('metric', $r['ask'], true)
            ? ['label' => 'What should be counted?', 'help' => 'Progress is read from the platform, never typed, so the goal has to be something it counts.',
               'fields' => [['name' => 'metric', 'label' => 'Counts', 'type' => 'segmented', 'value' => 'revenue',
                             'options' => array_map(static fn ($m) => $m[0], array_intersect_key(Goals::METRICS, array_flip(['revenue', 'nominees', 'nominations', 'tickets'])))],
                            ['name' => 'metric_other', 'label' => 'Or something else', 'type' => 'select', 'options' => ['' => 'Something else…'] + array_map(static fn ($m) => $m[0], Goals::METRICS), 'id' => 'gs_mo']]]
            : ['label' => 'Counts', 'fields' => [['name' => 'metric', 'label' => 'Counts', 'type' => 'select', 'value' => (string) $r['metric'], 'options' => array_map(static fn ($m) => $m[0], Goals::METRICS), 'id' => 'gs_m']]];
        $groups[] = ['label' => in_array('target', $r['ask'], true) ? 'How much, or how many?' : 'Target',
                     'help' => in_array('target', $r['ask'], true) ? 'Without a number nothing can be planned backwards from it.' : '',
                     'fields' => [['name' => 'target', 'label' => 'Target', 'type' => 'text', 'required' => true, 'value' => $r['target'] ? number_format((int) $r['target']) : '', 'placeholder' => '15M, 60, 500', 'id' => 'gs_t']]];
        $dates = Setup::dates($id);
        $groups[] = in_array('deadline', $r['ask'], true) && $dates
            ? ['label' => 'By when?', 'help' => 'The gates are dated back from it.',
               'fields' => [['name' => 'deadline', 'label' => 'By', 'type' => 'segmented', 'options' => $dates, 'value' => (string) array_key_first($dates)],
                            ['name' => 'deadline_other', 'label' => 'Or another date', 'type' => 'date', 'id' => 'gs_do']]]
            : ['label' => 'By when', 'fields' => [['name' => 'deadline', 'label' => 'Deadline', 'type' => 'date', 'required' => true, 'value' => (string) ($r['deadline'] ?? ''), 'id' => 'gs_d']]];
        $scopes = Goals::scopes($id);
        if (count($scopes) > 1) {
            $groups[] = ['label' => in_array('scope', $r['ask'], true) ? 'For which award?' : 'For', 'help' => in_array('scope', $r['ask'], true) ? 'Each award can be a goal of its own, so a slow one shows instead of hiding in the total.' : '',
                         'fields' => [['name' => 'scope', 'label' => 'For', 'type' => 'select', 'options' => $scopes, 'value' => $r['scope'], 'id' => 'gs_s']]];
        }
        if ($r['price']) $groups[] = ['label' => 'Assumed', 'fields' => [['name' => 'assume[price]', 'label' => 'Price of one vote (₦)', 'type' => 'text', 'value' => (string) $r['price'], 'id' => 'gs_ap']]];
        $groups[] = ['label' => 'Its name', 'fields' => [['name' => 'title', 'label' => 'Call it', 'type' => 'text', 'value' => $r['title'], 'id' => 'gs_title']]];
        $secs[] = ['k' => 'form', 'h' => 'Check it, then set it', 'do' => 'goal_create', 'hidden' => ['event' => $id], 'submit' => 'Set the goal', 'groups' => $groups];
        return ['title' => 'Set a goal', 'secs' => $secs];
    }

    // ── Rules ────────────────────────────────────────────────────────────────────────

    public static function rules(array $me, object $e, string $tab): array
    {
        $id = (int) $e->id;
        if (!Rules::ready()) return ['title' => 'Rules', 'secs' => [['k' => 'note', 'tone' => 'warn', 'text' => 'The database needs its latest update first.']]];
        if ($tab === 'log') {
            $rows = array_map(static fn ($l) => ['t' => $l->did, 'dot' => $l->mode === 'active' ? 'info' : null, 'chip' => $l->mode === 'shadow' ? ['Shadow', 'mute'] : null,
                'sub' => $l->rule . ' · ' . $l->evidence . ($l->undone_at ? ' · cleared' : ''), 'meta' => TeamLoop::when((string) $l->created_at),
                'cta' => $l->mode === 'active' && !$l->undone_at && in_array($l->subject !== '' ? explode(':', (string) $l->subject)[0] : '', ['member', 'dup', 'dupnom', 'gate', 'goal'], true)
                    ? ['label' => 'Clear', 'post' => ['do' => 'rule_clear', 'log' => $l->id]] : null], Rules::log($id));
            return ['title' => 'What the rules did', 'secs' => [$rows ? ['k' => 'rows', 'items' => $rows]
                : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing yet. Rules look every quarter of an hour; a rule in shadow writes here what it would have done.']],
                'acts' => [['label' => 'Look now', 'post' => ['do' => 'rules_run', 'event' => $id]]]];
        }
        if ($tab === 'changes') {
            $secs = [];
            $rows = [];
            foreach (Changes::pending($id) as $c) {
                $mayDecide = self::mayDecide($c, $me['id']);
                $rows[] = ['t' => (string) $c->summary, 'chip' => [Tiers::LABELS[$c->tier] . ' change', $c->tier === 'large' ? 'warn' : 'mute'],
                    'sub' => implode(' · ', array_filter(['asked by ' . (Plan::name(Plan::ACTOR, (int) $c->proposed_by) ?: 'a lead'),
                        $c->reason ? '“' . $c->reason . '”' : '', $c->lapse_at ? 'applies itself ' . Goals::day((string) $c->lapse_at) . ' ' . date('H:i', (int) strtotime((string) $c->lapse_at)) : '',
                        $mayDecide ? '' : 'waiting for ' . ($c->tier === 'large' ? 'an owner' . ((int) $c->proposed_by === $me['id'] ? ' — you asked for it' : '') : 'a lead')])),
                    'form' => $mayDecide ? ['id' => 'cd' . $c->id, 'do' => 'change_decide', 'hidden' => ['change' => $c->id], 'submit' => 'Decide', 'fields' => [
                        ['name' => 'verdict', 'label' => 'Decision', 'type' => 'select', 'options' => ['approve' => 'Approve', 'reject' => $c->tier === 'small' ? 'Object' : 'Reject']],
                        ['name' => 'note', 'label' => 'Why (needed to reject)', 'placeholder' => 'Why (needed to reject)']]] : null];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Waiting for a decision', 'box' => true, 'items' => $rows] : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nothing waiting.'];
            $done = Changes::decided($id);
            if ($done) $secs[] = ['k' => 'rows', 'h' => 'Decided', 'items' => array_map(static fn ($c) => ['t' => (string) $c->summary,
                'chip' => [ucfirst((string) $c->status), $c->status === 'rejected' ? 'mute' : 'ok'], 'sub' => Tiers::LABELS[$c->tier] . ' · ' . Goals::day((string) $c->decided_at) . ($c->decided_note ? ' · “' . $c->decided_note . '”' : '')], $done)];
            return ['title' => 'Changes', 'secs' => $secs];
        }
        $health = Rules::health($id);
        $rows = [];
        foreach (Rules::forEvent($id) as $k => $r) {
            [$name, $level, $what, $why] = Rules::CATALOGUE[$k];
            $h = $health[(int) $r->id] ?? [];
            $fired = ($h['active'] ?? 0) + ($h['shadow'] ?? 0);
            $rows[] = ['t' => $name, 'chip' => [$r->mode === 'active' ? 'On' : ($r->mode === 'paused' ? 'Paused' : 'Shadow'), $r->mode === 'active' ? 'ok' : 'mute'],
                       'sub' => $what . ($k === 'digest' ? ' Sent at ' . sprintf('%02d:00', (int) (json_decode((string) $r->params, true)['hour'] ?? Rules::DIGEST_HOUR)) . '.' : '')
                              . ($fired > 0 ? ' · ' . ($r->mode === 'shadow' ? 'would have acted ' : 'acted ') . $fired . ' ' . ($fired === 1 ? 'time' : 'times') . ' this month' : ''),
                       'form' => ['id' => 'r' . $r->id, 'do' => 'rule_mode', 'hidden' => ['event' => $id, 'rule' => $k], 'submit' => 'Set',
                                  'fields' => [['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => Rules::MODES, 'value' => (string) $r->mode]]]];
        }
        return ['title' => 'Rules', 'secs' => [
            ['k' => 'note', 'tone' => 'info', 'text' => 'Every rule starts in Shadow: it writes down what it would have done, under What they did, and does nothing. When that looks right, set it to On. None of them can touch money, a deadline, a goal or anybody’s access.'],
            ['k' => 'rows', 'items' => $rows],
            ['k' => 'form', 'h' => 'The digest’s hour', 'do' => 'digest_hour', 'hidden' => ['event' => $id], 'submit' => 'Save', 'groups' => [
                ['label' => 'Daily, at', 'fields' => [['name' => 'hour', 'label' => 'Hour (0–23)', 'type' => 'number', 'min' => 0, 'max' => 23,
                    'value' => (string) (json_decode((string) (Rules::forEvent($id)['digest']->params ?? ''), true)['hour'] ?? Rules::DIGEST_HOUR), 'id' => 'dg_h']]]]],
        ], 'acts' => [['label' => 'Pause every rule', 'post' => ['do' => 'rule_mode', 'event' => $id, 'rule' => 'all', 'mode' => 'paused'], 'danger' => true]]];
    }
}
