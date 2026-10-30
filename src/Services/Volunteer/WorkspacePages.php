<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * EACH VOLUNTEER PAGE'S SECTIONS, FROM THE RECORD (consoles v7 handoff §2.3, §2.7, §6.3, §8).
 *
 * The design file draws these pages over typed sample figures. Here every figure is a read:
 * "To Level 3" is Level::of(), a meeting is the person's own invitation, a task is the library's. Where the record has nothing, the page says one sentence and offers
 * the action that starts the work (§8) — never a 0, never an empty card.
 *
 * Sections use the handoff's kinds and nothing else: rows, cards, stats, steps, note, plus the
 * v6 kinds hero, agenda, chart, table and form. A row's action is either a link (`href`) or a
 * small form that posts (`post`), so every control works without script.
 */
final class WorkspacePages
{
    private const EMPTY = 'Nothing is waiting here yet. When something arrives it shows here, oldest first.';

    /** @return array{title:string, kicker?:string, purpose?:string, acts?:list<array>, secs:list<array>, side?:list<array>, noHead?:bool} */
    public static function root(object $v, string $role, string $page, string $tab, array $q = []): array
    {
        return match ($page) {
            'home'     => self::home($v, $role, $q),
            'tasks'    => self::tasks($v, $tab),
            'meetings' => self::meetings($v, $tab, (array) ($q['_old'] ?? [])),
            'hours'    => self::hours($v, $tab),
            'training' => self::training($v, $tab),
            'record'   => self::record($v, $tab),
            'team'     => self::team($v, $tab),
            'requests' => self::requests($v, $tab, $q),
            default    => ['title' => 'Today', 'secs' => []],
        };
    }

    // ── Today ─────────────────────────────────────────────────────────────────────────────

    private static function home(object $v, string $role, array $q): array
    {
        if ($role === 'applicant') return self::application($v, $q);
        if ($role === 'new') return self::gettingStarted($v);

        $secs = [];
        $needs = [];
        foreach (Meetings::mine($v) as $m) if ($m->answer === 'invited') {
            $needs[] = ['t' => $m->organiser_name . ' invited you to ' . $m->title, 'sub' => Workspace::day((string) $m->next) . ' · ' . date('H:i', (int) strtotime((string) $m->next)),
                        'dot' => 'info', 'cta' => ['label' => 'Answer', 'href' => '/volunteer/meetings/' . $m->id]];
        }
        foreach (Training::next($v) as $m) if ($m->tone === 'warn') {
            // "Door steward expires in 5 days" / "10 minutes · keeps Door steward" (handoff: "Safety 2026 expires in 5 days").
            $bits = explode(' · ', (string) $m->reason, 2);
            $needs[] = ['t' => $m->title . ' ' . lcfirst($bits[0]), 'sub' => (int) $m->minutes . ' minutes' . (isset($bits[1]) ? ' · ' . $bits[1] : ''),
                        'dot' => 'warn', 'chip' => ['Due', 'warn'], 'cta' => ['label' => 'Retake', 'href' => '/volunteer/training/take/' . $m->slug]];
        }
        foreach (Requests::from('member', (int) $v->user_id, 'open', 'volunteer') as $r) if ($r->status === 'needs_info') {
            $ask = DB::table('gates_request_events')->where('request_id', $r->id)->where('action', 'ask_info')->orderByDesc('id')->value('note');
            $needs[] = ['t' => Requests::deciderLabel($r) . ' asked about your request', 'sub' => $ask ? '“' . $ask . '”' : (string) $r->title,
                        'dot' => 'info', 'cta' => ['label' => 'Reply', 'href' => '/volunteer/requests/' . $r->id]];
        }
        foreach (Hours::rows($v, 'review', 20) as $h) if (!$h->proof) {
            $needs[] = ['t' => 'Add proof for ' . Level::h((float) $h->hours), 'sub' => $h->what . ' · a photo or link speeds up the check',
                        'dot' => 'info', 'cta' => ['label' => 'Add proof', 'href' => '/volunteer/hours/review']];
        }
        foreach (Outreach::awards($v) as $cid => $award) {
            $due = count(Outreach::nominees($v, $cid, '', 'due'));
            if ($due) $needs[] = ['t' => ($due === 1 ? 'One nominee' : $due . ' nominees') . ' to follow up', 'sub' => $award,
                                  'dot' => 'warn', 'cta' => ['label' => 'Follow up', 'href' => '/volunteer/cycle/' . $cid . '/nominees/due']];
        }
        if ($needs !== []) $secs[] = ['k' => 'rows', 'h' => 'Needs you', 'items' => $needs];

        if ($role === 'lead') {
            $w = Team::waitingHours($v);
            if ($w) $secs[] = ['k' => 'rows', 'h' => 'Your team', 'items' => [['t' => (count($w) === 1 ? 'One report' : count($w) . ' reports') . ' of hours to confirm',
                               'sub' => 'Confirm only what you saw or have proof of', 'dot' => 'warn', 'cta' => ['label' => 'Confirm', 'href' => '/volunteer/team/approvals']]]];
        }

        $cards = [];
        foreach (Workspace::places($v) as $p) {
            $cards[] = ['t' => $p['name'], 'meta' => implode(', ', $p['roles']), 'ini' => Workspace::initials($p['name']),
                        'tone' => $p['kind'] === 'event' ? 'ok' : 'info', 'sub' => self::placeLine($v, $p), 'href' => $p['href']];
        }
        if ($cards) $secs[] = ['k' => 'cards', 'h' => 'Where you help', 'items' => $cards];

        $lv = Level::of($v);
        $stats = [['l' => 'Verified hours', 'v' => Level::h($lv['hours']), 's' => 'All time']];
        if ($lv['next']) $stats[] = ['l' => 'To Level ' . $lv['next']['level'], 'v' => $lv['next']['hours_to'] > 0 ? Level::h($lv['next']['hours_to']) : 'Hours done',
                                     'pct' => $lv['next']['pct'], 's' => implode(' and ', $lv['next']['needs']) ?: 'Nearly there'];
        $secs[] = ['k' => 'stats', 'h' => 'Your record', 'items' => $stats];

        // This week: tasks due and meetings, in the order the week happens.
        $week = [];
        foreach (Tasks::mine($v) as $t) if ($t->due_at && strtotime((string) $t->due_at) < strtotime('+7 days')) {
            $week[] = ['at' => (string) $t->due_at, 'time' => date('D', (int) strtotime((string) $t->due_at)), 't' => $t->title . ' due', 'd' => self::progressWords($t), 'st' => ''];
        }
        foreach (Meetings::mine($v) as $m) if ($m->next && strtotime((string) $m->next) < strtotime('+7 days') && $m->answer !== 'no') {
            $week[] = ['at' => (string) $m->next, 'time' => date('D', (int) strtotime((string) $m->next)), 't' => (string) $m->title, 'd' => date('H:i', (int) strtotime((string) $m->next)), 'st' => 'next'];
        }
        usort($week, static fn ($a, $b) => strcmp($a['at'], $b['at']));
        $side = [];
        if ($week) $side[] = ['k' => 'agenda', 'h' => 'This week', 'items' => $week];
        if (!Tasks::mine($v)) $side[] = ['k' => 'note', 'text' => 'Nothing taken yet. The task library has work you can do from anywhere.', 'tone' => 'mute',
                                         'cta' => ['label' => 'See tasks', 'href' => '/volunteer/tasks/library']];

        return ['title' => 'Today', 'purpose' => self::homeLine($v, $lv), 'secs' => $secs, 'side' => $side];
    }

    private static function application(object $v, array $q): array
    {
        $refs = Volunteers::references((int) $v->id);
        $replied = count(array_filter($refs, static fn ($r) => $r->status === 'replied'));
        $asked = array_values(array_filter($refs, static fn ($r) => $r->status === 'asked'));
        $wantsId = (bool) array_intersect(explode(',', (string) $v->interests), ['door', 'desk']);
        $secs = [['k' => 'steps', 'h' => 'Your application', 'items' => [
            ['t' => 'Applied', 'sub' => Workspace::day($v->applied_at), 'st' => 'done'],
            ['t' => 'References', 'sub' => $replied . ' of ' . max(1, count($refs)) . ' replied', 'st' => $asked ? 'now' : 'done'],
            ['t' => 'ID check', 'sub' => $wantsId ? 'For door and desk roles, before your first event' : 'Only for door, money or data roles', 'st' => ''],
            ['t' => 'Training and first role', 'sub' => 'After approval', 'st' => ''],
        ]]];
        if ($asked) {
            $rows = [];
            foreach ($asked as $r) $rows[] = ['t' => 'Reference from ' . $r->name, 'sub' => 'Asked ' . Workspace::day($r->asked_at), 'chip' => ['Waiting', 'warn'],
                                              'cta' => ['label' => 'Change referee', 'href' => '/volunteer?referee=1']];
            $secs[] = ['k' => 'rows', 'h' => 'Waiting on', 'items' => $rows];
        }
        if (!empty($q['referee'])) {
            $secs[] = ['k' => 'form', 'h' => 'Change referee', 'do' => 'referee', 'submit' => 'Ask them instead', 'groups' => [['label' => 'Who can vouch for you',
                'help' => 'A teacher, an employer, a faith or community leader. Not a relative.', 'fields' => [
                    ['name' => 'ref_name', 'label' => 'Their name', 'type' => 'text', 'required' => true],
                    ['name' => 'ref_contact', 'label' => 'Phone or email', 'type' => 'text', 'required' => true]]]]];
        }
        $secs[] = ['k' => 'note', 'text' => 'Do the training now and you can start the day you’re approved.', 'tone' => 'ok',
                   'cta' => ['label' => 'Start training', 'href' => '/volunteer/training']];
        return ['title' => 'Application', 'purpose' => $asked ? 'Your application is with us. It moves on when your reference replies.' : 'Your application is with the volunteer team.', 'secs' => $secs];
    }

    private static function gettingStarted(object $v): array
    {
        $taken = Tasks::mine($v) !== [];
        $trained = Training::passed($v) !== [];
        $rows = [];
        foreach (array_slice(Tasks::library($v), 0, 3) as $t) {
            $rows[] = ['t' => (string) $t->title, 'sub' => ($t->from_home ? 'From home' : 'In person') . ($t->est_minutes ? ' · about ' . self::minutes((int) $t->est_minutes) : '') . ($t->module_key ? '' : ' · no training needed'),
                       'chip' => $t->trained ? ['Fits you', 'ok'] : ['After training', 'mute'],
                       'cta' => $t->trained ? ['label' => 'Take it', 'post' => ['do' => 'take', 'task' => $t->id]] : ['label' => 'Start training', 'href' => '/volunteer/training/take/' . $t->module_key]];
        }
        $secs = [['k' => 'steps', 'h' => 'Getting started', 'items' => [
            ['t' => 'Code of conduct', 'sub' => 'Done', 'st' => 'done'],
            ['t' => 'Short training', 'sub' => '10 to 15 min', 'st' => $trained ? 'done' : 'now'],
            ['t' => 'A first task', 'sub' => 'One that fits you', 'st' => $taken ? 'done' : ($trained ? 'now' : '')],
        ]]];
        $secs[] = $rows ? ['k' => 'rows', 'h' => 'Good first tasks', 'items' => $rows]
                        : ['k' => 'note', 'text' => 'No tasks are open right now. We tell you when one is posted.', 'tone' => 'mute'];
        $secs[] = ['k' => 'note', 'text' => 'Nothing is expected of you until you pick something. Hours count from your first accepted task.', 'tone' => 'mute'];
        return ['title' => 'Today', 'purpose' => 'Two steps to your first task. Most people finish in a day.', 'secs' => $secs];
    }


    // ── Meetings ──────────────────────────────────────────────────────────────────────────

    /** Meetings this person organised or was invited to (Volunteer\Meetings), and the form to schedule one. */
    private static function meetings(object $v, string $tab, array $old = []): array
    {
        if ($tab === 'new') {
            if (!Meetings::canOrganise($v)) {
                return ['title' => 'Schedule a meeting', 'secs' => [['k' => 'note', 'tone' => 'mute',
                    'text' => Meetings::adult($v) ? 'You can schedule meetings once you have verified hours. Until then, you can join meetings you’re invited to.'
                                                  : 'Your team lead sets up meetings for volunteers under 18. You can join meetings you’re invited to.']]];
            }
            $o = static fn (string $k, string $d = ''): string => mb_substr((string) ($old[$k] ?? $d), 0, 2000);
            $people = [];
            foreach (Meetings::people($v) as $id => $p) $people[$id] = $p['name'] . ' · ' . $p['where'];
            return ['title' => 'Schedule a meeting', 'secs' => [['k' => 'form', 'do' => 'meet', 'submit' => 'Schedule and invite',
                'route' => 'Each person gets an email with the details, and a reminder the day before.',
                'groups' => [
                    ['label' => 'What', 'fields' => [['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'autocomplete' => 'off', 'value' => $o('title')]]],
                    ['label' => 'When', 'fields' => [
                        ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'min' => date('Y-m-d'), 'value' => $o('date')],
                        ['name' => 'time', 'label' => 'Start time', 'type' => 'time', 'required' => true, 'value' => $o('time')],
                        ['name' => 'minutes', 'label' => 'How long', 'type' => 'select', 'options' => Meetings::LENGTHS, 'value' => $o('minutes', '30')],
                        ['name' => 'repeats', 'label' => 'Repeats', 'type' => 'select', 'options' => Meetings::REPEATS, 'value' => $o('repeats', 'once')],
                        ['name' => 'times', 'label' => 'If it repeats, how many times in all', 'type' => 'number', 'min' => 2, 'max' => 12, 'inputmode' => 'numeric', 'value' => $o('times', '4')]]],
                    ['label' => 'Where', 'fields' => [
                        ['name' => 'mode', 'label' => 'Where', 'type' => 'radio', 'required' => true, 'options' => ['online' => 'Online', 'place' => 'In person'], 'value' => $o('mode', 'online')],
                        ['name' => 'link', 'label' => 'Meeting link, if you have one', 'type' => 'url', 'autocomplete' => 'off', 'placeholder' => 'https://meet.google.com/…', 'value' => $o('link')],
                        ['name' => 'location', 'label' => 'If in person, where', 'type' => 'text', 'autocomplete' => 'off', 'value' => $o('location')]],
                     'help' => 'Online with no link: we make a Google Meet link where it’s set up.'],
                    ['label' => 'Who', 'help' => 'People you volunteer with. Up to ' . Meetings::MAX_PEOPLE . '.', 'fields' => [
                        ['name' => 'people', 'label' => 'Invite', 'type' => 'checks', 'options' => $people, 'value' => array_map('intval', (array) ($old['people'] ?? [])),
                         'empty' => 'Nobody yet. People who volunteer where you do show here.']]],
                    ['label' => 'Agenda', 'help' => 'Optional. What you’ll talk about.', 'fields' => [['name' => 'agenda', 'label' => 'Agenda', 'type' => 'textarea', 'value' => $o('agenda')]]],
                ]]]];
        }
        $rows = [];
        foreach (Meetings::mine($v, $tab === 'past') as $m) {
            $at = (string) ($m->next ?? $m->starts_at);
            $chip = match (true) {
                $m->status === 'cancelled' => ['Called off', 'mute'],
                $m->mine => ['You organised it', 'info'],
                $m->answer === 'yes' => ['Going', 'ok'],
                $m->answer === 'no' => ['Not going', 'mute'],
                default => ['Can you come?', 'warn'],
            };
            $rows[] = ['t' => (string) $m->title, 'href' => '/volunteer/meetings/' . $m->id, 'chip' => $tab === 'past' && $m->status !== 'cancelled' ? null : $chip,
                       'sub' => Workspace::day($at) . ' · ' . date('H:i', (int) strtotime($at)) . ' · ' . ($m->mode === 'place' ? (string) $m->location : 'Online')
                              . ($m->repeats !== 'once' ? ' · ' . mb_strtolower(Meetings::REPEATS[$m->repeats]) : '') . ($m->going ? ' · ' . $m->going . ' going' : '')];
        }
        $secs = [$rows ? ['k' => 'rows', 'h' => $tab === 'past' ? 'Past' : 'Coming up', 'items' => $rows]
                       : ['k' => 'note', 'tone' => 'mute', 'text' => $tab === 'past' ? 'Meetings you’ve had show here.' : 'No meetings coming up.',
                          'cta' => $tab === 'past' || !Meetings::canOrganise($v) ? null : ['label' => 'Schedule a meeting', 'href' => '/volunteer/meetings/new']]];
        return ['title' => 'Meetings', 'secs' => $secs];
    }

    /** One meeting, as a record page; null for anybody not invited. */
    public static function meeting(object $v, int $id): ?array
    {
        $m = Meetings::get($v, $id);
        if (!$m) return null;
        $at = (string) ($m->next ?? $m->starts_at);
        $live = $m->status === 'scheduled' && !$m->past;
        $acts = [];
        if ($live && $m->mode === 'online' && $m->meet_url && $m->answer !== 'no') $acts[] = ['label' => 'Join the meeting', 'href' => (string) $m->meet_url, 'primary' => true, 'ext' => true];
        if ($live && !$m->mine) {
            if ($m->answer !== 'yes') $acts[] = ['label' => 'I’m going', 'post' => ['do' => 'meet_answer', 'meeting' => $id, 'answer' => 'yes'], 'primary' => !$acts];
            if ($m->answer !== 'no') $acts[] = ['label' => 'I can’t come', 'post' => ['do' => 'meet_answer', 'meeting' => $id, 'answer' => 'no']];
        }
        if ($live) $acts[] = ['label' => 'Add to my calendar', 'href' => '/volunteer/meetings/' . $id . '.ics'];
        if ($live && $m->mine) $acts[] = ['label' => 'Call it off', 'post' => ['do' => 'meet_cancel', 'meeting' => $id], 'confirm' => 'Call off ' . $m->title . '? Everybody invited is told.'];
        $facts = array_values(array_filter([
            ['When', Workspace::day($at) . ' · ' . Workspace::span($at, date('Y-m-d H:i:s', (int) strtotime($at) + (int) $m->minutes * 60))],
            $m->repeats !== 'once' ? ['Repeats', Meetings::REPEATS[$m->repeats] . ', ' . $m->times . ' times'] : null,
            ['Where', $m->mode === 'place' ? (string) $m->location : 'Online'], ['Organiser', $m->mine ? 'You' : (string) $m->organiser]]));
        $chips = array_values(array_filter([$m->status === 'cancelled' ? ['Called off', 'mute'] : ($m->past ? ['Over', 'mute'] : [Workspace::until($at), 'info']),
            $m->answer === 'yes' ? ['Going', 'ok'] : ($m->answer === 'no' ? ['Not going', 'mute'] : ($m->answer === 'invited' && $live ? ['Can you come?', 'warn'] : null))]));
        $secs = [['k' => 'hero', 'ini' => date('j', (int) strtotime($at)), 'tone' => $live ? 'info' : 'mute', 't' => (string) $m->title, 'sub' => 'Meeting', 'chips' => $chips, 'facts' => $facts, 'acts' => $acts]];
        if ($m->agenda) $secs[] = ['k' => 'note', 'h' => 'Agenda', 'text' => (string) $m->agenda, 'tone' => 'mute', 'pre' => true];
        $words = ['yes' => 'Going', 'no' => 'Not going', 'invited' => 'Not answered yet'];
        $secs[] = ['k' => 'rows', 'h' => 'Who’s invited', 'items' => array_map(static fn ($p) => ['t' => $p->name . ($p->me ? ' (you)' : ''), 'sub' => $words[$p->answer] ?? ''], $m->people)];
        if ($m->mine && strtotime((string) $m->starts_at) <= time()) {
            $secs[] = ['k' => 'form', 'do' => 'meet_notes', 'submit' => 'Save notes', 'hidden' => ['meeting' => $id], 'route' => 'Everybody invited can read them here.',
                       'groups' => [['label' => 'Notes', 'help' => 'What was decided, and who does what next.', 'fields' => [['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => (string) ($m->notes ?? '')]]]]];
        } elseif ($m->notes) {
            $secs[] = ['k' => 'note', 'h' => 'Notes', 'text' => (string) $m->notes, 'tone' => 'mute', 'pre' => true];
        }
        return ['title' => (string) $m->title, 'kicker' => 'Meeting', 'noHead' => true, 'secs' => $secs];
    }

    // ── Tasks ─────────────────────────────────────────────────────────────────────────────

    private static function tasks(object $v, string $tab): array
    {
        $secs = [];
        if ($tab === 'mine') {
            $rows = [];
            foreach (Tasks::mine($v) as $t) {
                $row = ['t' => (string) $t->title, 'sub' => (Tasks::CHECKED_BY[$t->checked_by] ?? '') . ($t->due_at ? ' · due ' . Workspace::day($t->due_at) : ''),
                        'cta' => ['label' => 'Drop', 'post' => ['do' => 'droptask', 'task' => $t->id], 'confirm' => 'Drop this task?']];
                if ($t->target) { $row['pct'] = (int) min(100, round((int) $t->progress * 100 / (int) $t->target)); $row['barT'] = self::progressWords($t); }
                $rows[] = $row;
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'In progress', 'items' => $rows]
                            : ['k' => 'note', 'text' => 'You haven’t taken a task. The library has work you can do from anywhere.', 'tone' => 'mute', 'cta' => ['label' => 'Open the library', 'href' => '/volunteer/tasks/library']];
        } elseif ($tab === 'library') {
            $rows = [];
            foreach (Tasks::library($v) as $t) {
                $rows[] = ['t' => (string) $t->title, 'sub' => trim(($t->from_home ? 'From home · ' : '') . ($t->est_minutes ? 'about ' . self::minutes((int) $t->est_minutes) . ' · ' : '') . (Tasks::CHECKED_BY[$t->checked_by] ?? ''), ' ·'),
                           'chip' => $t->module_key ? ($t->trained ? ['Trained', 'ok'] : ['Needs training', 'mute']) : ($t->from_home ? ['From home', 'ok'] : null),
                           'cta' => $t->trained ? ['label' => 'Take it', 'post' => ['do' => 'take', 'task' => $t->id]] : ['label' => 'Train first', 'href' => '/volunteer/training/take/' . $t->module_key]];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'You can take', 'items' => $rows] : ['k' => 'note', 'text' => 'No tasks are open right now.', 'tone' => 'mute'];
        } else {
            $rows = [];
            foreach (Tasks::mine($v, 'done') as $t) {
                $h = (float) DB::table('gates_vol_hours')->where('ref_kind', 'take')->where('ref_id', $t->take_id)->value('hours');
                $rows[] = ['t' => (string) $t->title, 'sub' => 'Accepted ' . Workspace::day($t->done_at) . ($h > 0 ? ' · ' . Level::h($h) : ''), 'chip' => ['Accepted', 'ok']];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Done', 'items' => $rows] : ['k' => 'note', 'text' => 'Tasks you finish show here once they’re accepted.', 'tone' => 'mute'];
        }
        return ['title' => 'Tasks', 'secs' => $secs];
    }

    // ── Hours ─────────────────────────────────────────────────────────────────────────────

    private static function hours(object $v, string $tab): array
    {
        $sum = Hours::summary($v);
        $secs = [];
        if ($tab === 'summary') {
            $weeks = Hours::weekly($v, 8);
            $secs[] = ['k' => 'chart', 'kpis' => [['l' => 'Verified hours', 'v' => Level::h($sum['verified'])], ['l' => 'This week', 'v' => Level::h($sum['week']), 'd' => 'Your limit is ' . $sum['limit']],
                       ['l' => 'In review', 'v' => Level::h($sum['review'])]],
                       'bars' => array_map(static fn ($w) => ['l' => $w['label'], 'v' => [$w['scan'], $w['task'], $w['manual']]], $weeks),
                       'legend' => ['On site', 'Accepted work', 'Logged by hand']];
            $rows = [];
            foreach (['scan' => 'On site at events', 'task' => 'Accepted task work', 'manual' => 'Logged by you, confirmed by a lead'] as $k => $l) {
                $h = (float) ($sum['by_source'][$k] ?? 0) + ($k === 'scan' ? (float) ($sum['by_source']['signup'] ?? 0) : 0);
                if ($h > 0) $rows[] = ['t' => $l, 'sub' => Level::h($h) . ($k === 'manual' ? '' : ' · automatic'), 'chip' => $k === 'manual' ? ['Confirmed', 'info'] : ['Automatic', 'ok']];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Where they came from', 'items' => $rows]
                            : ['k' => 'note', 'text' => 'Your verified hours show here from your first accepted task.', 'tone' => 'mute'];
        } elseif ($tab === 'review') {
            $rows = [];
            foreach (Hours::rows($v, 'review') as $h) {
                $rows[] = ['t' => (string) $h->what, 'sub' => Workspace::day($h->worked_on) . ' · ' . Level::h((float) $h->hours) . ($h->witness ? ' · seen by ' . $h->witness : ''),
                           'chip' => $h->proof ? ['With a lead', 'info'] : ['Needs proof', 'warn'],
                           'inline' => $h->proof ? null : ['do' => 'proof', 'hour' => $h->id, 'name' => 'proof', 'label' => 'Link to a photo or file', 'placeholder' => 'https://', 'submit' => 'Add proof']];
            }
            foreach (DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'declined')->orderByDesc('confirmed_at')->limit(10)->get() as $h) {
                $rows[] = ['t' => (string) $h->what, 'sub' => 'Not confirmed by ' . $h->confirmed_name . ': “' . $h->reason . '”', 'chip' => ['Declined', 'bad'],
                           'cta' => ['label' => 'Ask for a correction', 'href' => '/volunteer/requests?new=hours&title=' . rawurlencode((string) $h->what)]];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Waiting', 'items' => $rows] : ['k' => 'note', 'text' => 'Nothing is in review.', 'tone' => 'mute'];
        } else {
            $secs[] = ['k' => 'note', 'text' => 'Most hours log themselves: checking in and out at an event, and accepted tasks. Log by hand only for work nobody saw. A lead confirms it, and a photo or link speeds that up.', 'tone' => 'info'];
            $secs[] = ['k' => 'form', 'h' => 'Log hours', 'do' => 'log', 'submit' => 'Send for confirming', 'groups' => [
                ['label' => 'What you did', 'help' => 'Enough for a lead to recognise it.', 'fields' => [['name' => 'what', 'label' => 'What you did', 'type' => 'text', 'required' => true]]],
                ['label' => 'When, and how long', 'help' => 'One day at a time, up to 16 hours.', 'fields' => [
                    ['name' => 'date', 'label' => 'Day', 'type' => 'date', 'required' => true, 'max' => date('Y-m-d')],
                    ['name' => 'hours', 'label' => 'Hours', 'type' => 'number', 'step' => '0.25', 'min' => '0.25', 'max' => '16', 'required' => true]]],
                ['label' => 'Who saw it', 'help' => 'Optional, and it helps.', 'fields' => [
                    ['name' => 'witness', 'label' => 'Who saw it', 'type' => 'text'],
                    ['name' => 'proof', 'label' => 'Link to a photo or file', 'type' => 'url', 'placeholder' => 'https://']]]]];
            $rows = [];
            foreach (Hours::rows($v, 'hand', 30) as $h) {
                $rows[] = ['t' => (string) $h->what, 'sub' => Workspace::day($h->worked_on) . ' · ' . Level::h((float) $h->hours) . ($h->confirmed_name ? ' · ' . $h->confirmed_name : ''),
                           'chip' => match ((string) $h->status) { 'verified' => ['Confirmed', 'ok'], 'declined' => ['Declined', 'bad'], default => ['In review', 'warn'] }];
            }
            if ($rows) $secs[] = ['k' => 'rows', 'h' => 'Logged by hand', 'items' => $rows];
        }
        return ['title' => 'Hours', 'secs' => $secs];
    }

    // ── Training ──────────────────────────────────────────────────────────────────────────

    private static function training(object $v, string $tab): array
    {
        $rows = [];
        if ($tab === 'next') {
            foreach (Training::next($v) as $m) {
                $rows[] = ['t' => (string) $m->title, 'sub' => (int) $m->minutes . ' min · ' . $m->reason, 'chip' => $m->label !== '' ? [$m->label, $m->tone] : null,
                           'cta' => ['label' => 'Start', 'href' => '/volunteer/training/take/' . $m->slug]];
            }
            $sec = $rows ? ['k' => 'rows', 'h' => 'Next for you', 'items' => $rows] : ['k' => 'note', 'text' => 'You’re up to date. New modules show here when they’re written.', 'tone' => 'ok'];
        } else {
            foreach (Training::passed($v) as $p) {
                $lapsed = $p->expires_at && strtotime((string) $p->expires_at) < time();
                $rows[] = ['t' => (string) $p->title, 'sub' => 'Passed ' . Workspace::day($p->passed_at) . ' · ' . (int) $p->score . ' right' . ($p->expires_at ? ' · ' . ($lapsed ? 'lapsed ' : 'until ') . Workspace::day($p->expires_at) : ''),
                           'chip' => $lapsed ? ['Lapsed', 'bad'] : ['Passed', 'ok']];
            }
            $rows[] = ['t' => 'Code of conduct, version ' . $v->conduct_version, 'sub' => 'Accepted ' . Workspace::day($v->conduct_accepted_at), 'chip' => ['Accepted', 'ok']];
            $sec = ['k' => 'rows', 'h' => 'Passed', 'items' => $rows];
        }
        return ['title' => 'Training', 'secs' => [$sec]];
    }

    /** A module: its page, then its check (handoff G4: content, a check, a pass mark, a validity). */
    public static function module(object $v, string $slug): ?array
    {
        $m = Training::module($slug);
        if (!$m) return null;
        $qs = json_decode((string) $m->questions, true) ?: [];
        $fields = [];
        foreach ($qs as $i => $q) {
            $opts = [];
            foreach ($q['options'] as $j => $o) $opts[(string) $j] = (string) $o;
            $fields[] = ['name' => 'a[' . $i . ']', 'label' => ($i + 1) . '. ' . $q['q'], 'type' => 'radio', 'options' => $opts, 'required' => true];
        }
        $secs = [];
        if (trim((string) $m->body) !== '') $secs[] = ['k' => 'article', 'paras' => array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", (string) $m->body) ?: [])))];
        $secs[] = ['k' => 'form', 'h' => 'The check', 'do' => 'train', 'hidden' => ['module' => $slug],
                   'submit' => 'Send my answers', 'groups' => [['label' => sprintf('%d of %d right to pass', min((int) $m->pass_mark, count($qs)), count($qs)),
                   'help' => $m->valid_days ? 'A pass lasts ' . (int) $m->valid_days . ' days.' : 'A pass does not lapse.', 'fields' => $fields]]];
        return ['title' => (string) $m->title, 'kicker' => 'Training', 'purpose' => (int) $m->minutes . ' minutes' . ($m->unlocks ? ' · unlocks ' . self::capList((string) $m->unlocks) : ''), 'secs' => $secs];
    }

    // ── My record ─────────────────────────────────────────────────────────────────────────

    private static function record(object $v, string $tab): array
    {
        $lv = Level::of($v);
        if ($tab === 'overview') {
            $steps = [];
            foreach ([1, 2, 3] as $n) {
                $steps[] = ['t' => 'Level ' . $n, 'sub' => $n < $lv['level'] ? 'Reached' : ($n === $lv['level'] ? 'Now' : ($lv['next'] && $lv['next']['level'] === $n ? implode(' and ', $lv['next']['needs']) : 'After Level ' . ($n - 1))),
                            'st' => $n < $lv['level'] ? 'done' : ($n === $lv['level'] ? 'now' : '')];
            }
            $e = Effect::of($v);
            $stats = [['l' => 'Verified hours', 'v' => Level::h($lv['hours']), 's' => 'All time'],
                      ['l' => 'Last six months', 'v' => Level::h($e['hours']), 's' => $e['weeks'] ? 'In ' . $e['weeks'] . ($e['weeks'] === 1 ? ' week' : ' weeks') . ', about ' . Level::h($e['per_week']) . ' a week' : 'No hours yet'],
                      ['l' => 'Tasks finished', 'v' => (string) $e['done'], 's' => $e['on_time'] !== null ? $e['on_time'] . '% on time' : ''],
                      ['l' => 'Reliability', 'v' => $e['reliability'] !== null ? $e['reliability'] . '%' : '–', 's' => $e['word']]];
            if ($e['roles']) $stats[] = ['l' => 'Turned up', 'v' => $e['shown'] . ' of ' . $e['roles'], 's' => 'Events you had a role at'];
            return ['title' => 'My record', 'secs' => [['k' => 'stats', 'h' => 'How it’s going', 'items' => $stats],
                ['k' => 'note', 'text' => Effect::explain($e) . ($e['reliability'] !== null ? ' Reliability is what you finished or turned up to, out of what you took on.' : ''), 'tone' => 'mute'],
                ['k' => 'steps', 'h' => 'Levels', 'items' => $steps]]];
        }
        if ($tab === 'badges') {
            $rows = [];
            foreach (self::badges($v, $lv) as [$t, $sub]) $rows[] = ['t' => $t, 'sub' => $sub, 'chip' => ['Earned', 'ok']];
            return ['title' => 'My record', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'Badges', 'items' => $rows] : ['k' => 'note', 'text' => 'Your first badge comes with your first accepted task.', 'tone' => 'mute']]];
        }
        return ['title' => 'My record', 'acts' => [['label' => 'Ask for a letter', 'href' => '/volunteer/requests?new=role&title=' . rawurlencode('A reference letter'), 'primary' => true]],
                'secs' => [['k' => 'note', 'text' => 'A reference letter lists only verified hours, roles and checks. You choose who it goes to.', 'tone' => 'mute']]];
    }

    /** Badges are earned from the record, never given. @return list<array{0:string,1:string}> */
    private static function badges(object $v, array $lv): array
    {
        $out = [];
        foreach ([10, 50, 100] as $h) if ($lv['hours'] >= $h) $out[] = [$h . ' verified hours', 'From time on site and accepted work'];
        $first = DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->where('status', 'done')->orderBy('done_at')->value('done_at');
        if ($first) $out[] = ['First task accepted', Workspace::day((string) $first)];
        return $out;
    }

    // ── My team (leads) ───────────────────────────────────────────────────────────────────

    private static function team(object $v, string $tab): array
    {
        $secs = [];
        if ($tab === 'roster') {
            $rows = [];
            foreach (Team::roster($v) as $m) {
                $limit = (int) ($m->max_hours_week ?: Rules::get('vol_week_hours_default'));
                $e = Effect::of($m);
                $rows[] = ['t' => (string) $m->name, 'sub' => 'Level ' . $m->level . ' · ' . Effect::line($e) . ' · ' . ($m->tasks ? $m->tasks . ($m->tasks === 1 ? ' task' : ' tasks') . ' open' : 'no task open')
                               . ' · up to ' . $limit . ' hours a week', 'ini' => Workspace::initials((string) $m->name), 'meta' => Level::h((float) $m->hours),
                           'chip' => Effect::chip($e)];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Your team', 'items' => $rows] : ['k' => 'note', 'text' => 'Your team is everybody holding a volunteer role where you lead. Nobody yet.', 'tone' => 'mute'];
            if ($rows) $secs[] = ['k' => 'note', 'text' => 'Reliability is what each person finished or turned up to in the last six months, out of what they took on. Each limit is the one they set in Availability.', 'tone' => 'mute'];
        } elseif ($tab === 'approvals') {
            $rows = [];
            foreach (Team::waitingHours($v) as $h) {
                $name = (string) ($h->display_name ?: $h->user_name);
                $rows[] = ['t' => $name . ' reported ' . Level::h((float) $h->hours), 'sub' => $h->what . ', ' . Workspace::day($h->worked_on) . ($h->witness ? ' · seen by ' . $h->witness : '') . ($h->proof ? '' : ' · no proof'),
                           'ini' => Workspace::initials($name), 'href' => $h->proof ?: null,
                           'decide' => ['hour' => $h->id]];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'To confirm', 'items' => $rows] : ['k' => 'note', 'text' => 'Nothing to confirm.', 'tone' => 'ok'];
            $secs[] = ['k' => 'note', 'text' => 'Confirm only what you saw or have proof of. Declining needs a reason, and the person sees it.', 'tone' => 'mute'];
        } elseif ($tab === 'requests') {
            $secs[] = self::requestTable(Requests::waitingOn('member', (int) $v->user_id), 'Nothing from your team.', 'Each request shows who asked and why. Declining needs a reason.');
        }
        return ['title' => 'My team', 'secs' => $secs];
    }

    // ── Requests ──────────────────────────────────────────────────────────────────────────

    private static function requests(object $v, string $tab, array $q): array
    {
        $secs = [];
        $new = (string) ($q['new'] ?? '');
        if ($new !== '' || $tab === 'new') $secs[] = self::composer($v, $new, (string) ($q['title'] ?? ''));
        $rows = Requests::from('member', (int) $v->user_id, $tab === 'decided' ? 'decided' : 'open', 'volunteer');
        $secs[] = self::requestTable($rows, $tab === 'decided' ? 'Nothing decided yet.' : 'You have no open requests.',
                                     'A request that needs information from you shows on Today too.');
        return ['title' => 'Requests', 'acts' => $new === '' ? [['label' => 'New request', 'href' => '/volunteer/requests?new=off', 'primary' => true]] : [], 'secs' => $secs];
    }

    /** The composer (§2.6): type chips, the type's fields, the route line, Send. */
    private static function composer(object $v, string $type, string $title): array
    {
        $types = Requests::types('volunteer');
        $t = Requests::type($type, 'volunteer') ?? ($types[0] ?? null);
        $chips = [];
        foreach ($types as $x) $chips[] = ['label' => (string) $x['label'], 'href' => '/volunteer/requests?new=' . $x['key'], 'current' => $t && $x['key'] === $t['key']];
        $fields = [];
        foreach ((array) ($t['fields'] ?? []) as $f) {
            $fields[] = ['name' => $f['key'], 'label' => (string) $f['label'], 'type' => $f['key'] === 'why' ? 'textarea' : 'text',
                         'required' => in_array($f['key'], ['title', 'why'], true), 'value' => $f['key'] === 'title' ? $title : ''];
        }
        // A request for "your lead" is decided by the lead WHERE it happens, so it says where.
        // Only the person's own places are offered; with none, it goes to the volunteer team.
        $places = [];
        foreach (Workspace::places($v) as $p) $places[$p['kind'] . ':' . $p['id']] = $p['name'];
        if ($t && $t['route'] === 'lead' && $places) {
            $fields[] = ['name' => 'place', 'label' => 'Where', 'type' => 'select', 'options' => $places, 'required' => true];
        }
        $route = $t ? 'Goes to ' . ($t['route'] === 'lead' ? ($places ? 'your lead there' : 'the volunteer team') : 'the volunteer team') . ', ' . $t['usually'] . '.' : '';
        return ['k' => 'form', 'h' => 'New request', 'chips' => $chips, 'do' => 'request', 'hidden' => ['type' => (string) ($t['key'] ?? '')],
                'submit' => 'Send request', 'route' => $route, 'groups' => [['label' => (string) ($t['label'] ?? ''), 'help' => (string) ($t['hint'] ?? ''), 'fields' => $fields]]];
    }

    private static function requestTable(array $rows, string $empty, string $foot): array
    {
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        $out = [];
        foreach ($rows as $r) {
            $t = Requests::type((string) $r->type, 'volunteer');
            $out[] = ['href' => '/volunteer/requests/' . $r->id, 'cells' => [['t' => (string) $r->title, 'sub' => (string) ($t['label'] ?? $r->type)],
                      ['t' => Requests::deciderLabel($r)], ['t' => self::ago((string) $r->created_at), 'mono' => true],
                      ['chip' => [Requests::STATUSES[$r->status] ?? $r->status, $tone[$r->status] ?? 'mute']]]];
        }
        return ['k' => 'table', 'cols' => [['l' => 'Request'], ['l' => 'Decides', 't0' => true], ['l' => 'Asked', 'w' => '90px'], ['l' => 'Status', 'w' => '120px']],
                'rows' => $out, 'empty' => $empty, 'foot' => $foot];
    }

    /** One request: facts, activity, and the actions this person may take on it (§2.6 detail). */
    public static function request(object $v, int $id): ?array
    {
        $r = Requests::find($id);
        if (!$r) return null;
        $mine = $r->from_kind === 'member' && (int) $r->from_id === (int) $v->user_id;
        $decides = Requests::canDecide($r, 'member', (int) $v->user_id);
        if (!$mine && !$decides) return null;   // a request is its asker's and its decider's, nobody else's
        $t = Requests::type((string) $r->type, 'volunteer');
        $from = $mine ? 'You' : (string) (DB::table('gates_users')->where('id', $r->from_id)->value('name') ?? 'A volunteer');
        $facts = array_values(array_filter([['Type', (string) ($t['label'] ?? $r->type)], ['From', $from],
            $r->scope_kind ? ['Where', Workspace::scopeName((string) $r->scope_kind, (int) $r->scope_id)] : null,
            $r->amount_minor !== null ? ['Amount', '₦' . number_format($r->amount_minor / 100)] : ($r->amount_text ? ['Amount', (string) $r->amount_text] : null),
            $r->needed_by ? ['Needed by', (string) $r->needed_by] : null, ['Why', (string) $r->why], ['Decides', Requests::deciderLabel($r)]]));
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        $status = $decides && $r->status === 'waiting' ? 'Waiting on you' : (Requests::STATUSES[$r->status] ?? $r->status);
        $secs = [['k' => 'hero', 'ini' => strtoupper(mb_substr((string) $r->type, 0, 2)), 'tone' => 'info', 't' => (string) $r->title, 'sub' => '',
                  'chips' => [[$status, $tone[$r->status] ?? 'mute']], 'facts' => $facts, 'acts' => []]];
        $log = [];
        foreach (Requests::history($id) as $e) {
            $who = $e->actor_kind === 'member' && (int) $e->actor_id === (int) $v->user_id ? 'You' : (string) ($e->actor_name ?? 'Someone');
            $verb = ['asked' => 'asked', 'approve' => 'approved', 'ask_info' => 'asked for information', 'decline' => 'declined', 'add_info' => 'added information', 'withdraw' => 'withdrew it', 'undo' => 'undid the last step', 'remind' => 'sent a reminder', 'sent' => 'sent it'][$e->action] ?? $e->action;
            $log[] = ['time' => date('j M', (int) strtotime((string) $e->created_at)), 't' => $who . ' ' . $verb, 'd' => (string) ($e->note ?? ''), 'st' => 'done'];
        }
        if ($log) $secs[] = ['k' => 'agenda', 'h' => 'Activity', 'items' => $log];
        if ($decides && $r->status === 'waiting') {
            $secs[] = ['k' => 'decide', 'request' => $id, 'opts' => [['approve', 'Approve', true], ['ask_info', 'Ask for info', false], ['decline', 'Decline', false]],
                       'note' => 'Asking for info and declining need a note. They see it.'];
        } elseif ($mine && in_array($r->status, Requests::OPEN, true)) {
            $opts = $r->status === 'needs_info' ? [['add_info', 'Add info', true], ['withdraw', 'Withdraw', false]] : [['withdraw', 'Withdraw', false]];
            $secs[] = ['k' => 'decide', 'request' => $id, 'opts' => $opts, 'note' => $r->status === 'needs_info' ? 'Write what they asked for, then send it back.' : ''];
        } else {
            $secs[] = ['k' => 'note', 'text' => 'This request is decided, so it can’t be changed. Ask again with a new request if something has moved.', 'tone' => 'mute'];
        }
        return ['title' => (string) $r->title, 'kicker' => 'Requests', 'noHead' => true, 'secs' => $secs];
    }

    // ── A place: an award edition or an event ─────────────────────────────────────────────

    public static function place(object $v, array $p, string $page, string $tab, array $q = []): array
    {
        $kind = $p['kind'];
        $id = $p['id'];
        if ($kind === 'cycle' && $page === 'queue') return self::queue($v, $p, $tab, (int) ($q['n'] ?? 0));
        if ($kind === 'cycle' && $page === 'drive') return self::drive($v, $p, $tab);
        if ($kind === 'cycle' && $page === 'nominees') return self::nominees($v, $p, $tab, (int) ($q['n'] ?? 0), $q);
        if ($page === 'guide') return ['title' => 'Role guide', 'secs' => self::guide($p['caps'])];
        $tasks = DB::table('gates_vol_tasks')->where('scope_kind', $kind)->where('scope_id', $id)->where('status', 'open')->orderBy('due_at')->get()->all();
        $taskRows = static function () use ($tasks, $v): array {
            $rows = [];
            $taken = DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->where('status', 'taken')->pluck('task_id')->all();
            foreach ($tasks as $t) {
                $have = in_array((int) $t->id, array_map('intval', $taken), true);
                $rows[] = ['t' => (string) $t->title, 'sub' => trim(($t->due_at ? 'Due ' . Workspace::day($t->due_at) . ' · ' : '') . (Tasks::CHECKED_BY[$t->checked_by] ?? ''), ' ·'),
                           'chip' => $have ? ['Yours', 'ok'] : null, 'cta' => $have ? null : ['label' => 'Take it', 'post' => ['do' => 'take', 'task' => $t->id]]];
            }
            return $rows ? [['k' => 'rows', 'items' => $rows]] : [['k' => 'note', 'text' => 'No tasks here yet.', 'tone' => 'mute']];
        };
        if ($page === 'access') return ['title' => 'Your access', 'secs' => self::access($v, $kind, $id)];
        if ($page === 'tasks') return ['title' => 'Tasks', 'secs' => $taskRows()];

        if ($kind === 'cycle') {
            $c = DB::table('gates_award_cycles')->where('id', $id)->first();
            $steps = [];
            if ($c) {
                $now = date('Y-m-d H:i:s');
                foreach ([['Nominations', $c->nominations_open, $c->nominations_close], ['Voting', $c->voting_open, $c->voting_close], ['Results', $c->results_date, null]] as [$l, $a, $b]) {
                    if (!$a) continue;
                    $st = ($b ?: $a) < $now ? 'done' : ($a <= $now ? 'now' : '');
                    $steps[] = ['t' => $l, 'sub' => Workspace::day($a) . ($b ? ' to ' . Workspace::day($b) : ''), 'st' => $st];
                }
            }
            $secs = [];
            if ($steps) $secs[] = ['k' => 'steps', 'h' => 'Where the award is', 'items' => $steps];
            $part = [];
            $mineHere = (int) DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')->where('k.volunteer_id', $v->id)
                ->where('t.scope_kind', 'cycle')->where('t.scope_id', $id)->whereIn('k.status', ['taken', 'done'])->count();
            if ($mineHere) $part[] = ['l' => 'Your tasks', 'v' => (string) $mineHere];
            if (in_array(NominationChecks::CAPABILITY, $p['caps'], true)) {
                $n = (int) DB::table('gates_vol_nom_checks')->where('volunteer_id', $v->id)->where('cycle_id', $id)->count();
                if ($n) $part[] = ['l' => 'Nominations checked', 'v' => (string) $n];
                $ag = NominationChecks::agreement($v, $id);
                if ($ag) $part[] = ['l' => 'Agreement', 'v' => $ag['pct'] . '%', 's' => 'Of ' . $ag['decided'] . ' a moderator has decided'];
            }
            if (in_array(VoterDrive::CAPABILITY, $p['caps'], true) && ($l = VoterDrive::link($v, $id)) && $l['people'] > 0) {
                $part[] = ['l' => 'People who voted through your link', 'v' => (string) $l['people']];
            }
            if (in_array(Outreach::CAPABILITY, $p['caps'], true) && Outreach::ready()) {
                $n = (int) DB::table('gates_vol_outreach')->where('volunteer_id', $v->id)->where('cycle_id', $id)->count();
                if ($n) $part[] = ['l' => 'Conversations with nominees', 'v' => (string) $n];
            }
            $start = in_array(NominationChecks::CAPABILITY, $p['caps'], true) ? 'Your part starts with your first check.'
                   : (in_array(Outreach::CAPABILITY, $p['caps'], true) ? 'Your part starts with your first conversation with a nominee.'
                   : (in_array(VoterDrive::CAPABILITY, $p['caps'], true) ? 'Your part starts when someone votes through your link.' : 'Your part here starts with a task.'));
            $secs[] = $part ? ['k' => 'stats', 'h' => 'Your part', 'items' => $part] : ['k' => 'note', 'text' => $start, 'tone' => 'mute'];
            $acts = [];
            if (in_array(NominationChecks::CAPABILITY, $p['caps'], true)) $acts[] = ['label' => 'Check nominations', 'href' => $p['href'] . '/queue'];
            if (in_array(VoterDrive::CAPABILITY, $p['caps'], true)) $acts[] = ['label' => 'Get your link', 'href' => $p['href'] . '/drive'];
            if (in_array(Outreach::CAPABILITY, $p['caps'], true)) $acts[] = ['label' => 'Reach nominees', 'href' => $p['href'] . '/nominees'];
            if ($acts) array_unshift($secs, ['k' => 'note', 'text' => 'You help here with ' . mb_strtolower(implode(' and ', $p['roles'])) . '.', 'tone' => 'info', 'cta' => $acts[0]]);
            return ['title' => $p['name'], 'purpose' => self::PURPOSE_FOR($kind, $page), 'secs' => $secs, 'side' => self::access($v, $kind, $id, true)];
        }

        // An event: its briefing, the others who hold a role there, and the night itself.
        $e = DB::table('gates_site_events')->where('id', $id)->first();
        if ($page === 'brief') {
            $secs = [];
            if ($e && trim((string) ($e->access_notes ?? '')) !== '') $secs[] = ['k' => 'note', 'h' => 'From the organisers', 'text' => (string) $e->access_notes, 'tone' => 'info', 'pre' => true];
            $secs[] = $taskRows()[0];
            $secs[] = ['k' => 'note', 'text' => 'Calls go through the platform; numbers stay private.', 'tone' => 'mute'];
            return ['title' => 'Briefing', 'secs' => $secs];
        }
        if ($page === 'team') {
            $rows = [];
            foreach (DB::table('gates_grants as g')->join('gates_volunteers as o', 'o.user_id', '=', 'g.actor_id')
                         ->where('g.actor_kind', 'member')->where('g.scope_kind', 'event')->where('g.scope_id', $id)->where('g.capability', 'like', 'vol.%')
                         ->whereNull('g.revoked_at')->where('g.expires_at', '>', date('Y-m-d H:i:s'))->where('o.id', '!=', $v->id)
                         ->get(['o.*', 'g.capability']) as $o) {
                $n = Volunteers::name($o);
                $rows[$o->id] ??= ['t' => $n, 'sub' => Grants::CAPABILITIES[$o->capability] ?? 'Volunteer', 'ini' => Workspace::initials($n)];
            }
            return ['title' => 'Who’s on', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'Volunteering here with you', 'items' => array_values($rows)]
                                                            : ['k' => 'note', 'text' => 'Nobody else holds a role here yet.', 'tone' => 'mute']]];
        }
        $days = $e ? (int) floor((strtotime(date('Y-m-d', (int) strtotime((string) $e->event_date))) - strtotime(date('Y-m-d'))) / 86400) : null;
        $hero = ['k' => 'hero', 'ini' => $e ? date('j', (int) strtotime((string) $e->event_date)) : 'E', 'tone' => 'ok', 't' => $p['name'],
                 'sub' => $e ? Workspace::day((string) $e->event_date) . ($e->venue ? ' · ' . $e->venue : '') . ($p['roles'] ? '. You’re ' . mb_strtolower(implode(' and ', $p['roles'])) . '.' : '') : '',
                 'chips' => array_values(array_filter([$days !== null && $days >= 0 ? [$days === 0 ? 'Today' : $days . ' ' . ($days === 1 ? 'day' : 'days') . ' to go', 'info'] : null])),
                 'facts' => array_values(array_filter([$e ? ['When', Workspace::day((string) $e->event_date) . ' · ' . date('H:i', (int) strtotime((string) $e->event_date))] : null,
                                                       $e && $e->venue ? ['Where', (string) $e->venue] : null])),
                 'acts' => [['label' => 'Briefing', 'href' => $p['href'] . '/brief', 'primary' => true]]];
        $secs = [$hero];
        // Time on site, in the hero so the one button that matters on the night is the first one:
        // the volunteer's own taps, which a lead confirms; a lead's tap counts at once.
        if (Attendance::ready() && $e) {
            $visit = Attendance::open((int) $v->id, $id);
            if ($visit) {
                $secs[0]['chips'][] = ['Checked in at ' . date('H:i', (int) strtotime((string) $visit->in_at)), 'ok'];
                array_unshift($secs[0]['acts'], ['label' => 'Check out', 'post' => ['do' => 'leave', 'event' => $id], 'primary' => true]);
                $secs[0]['acts'][1]['primary'] = false;
            } elseif (Attendance::isOpenNow($id)) {
                array_unshift($secs[0]['acts'], ['label' => 'Check in', 'post' => ['do' => 'arrive', 'event' => $id], 'primary' => true]);
                $secs[0]['acts'][1]['primary'] = false;
                $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Check in when you arrive and out when you leave. A lead confirms the time, and it counts as your hours.'];
            }
            if (($h = Attendance::hoursAt((int) $v->id, $id)) > 0) $secs[] = ['k' => 'stats', 'items' => [['l' => 'Your time here', 'v' => Level::h($h), 's' => 'Check-in to check-out']]];
        }
        return ['title' => $p['name'], 'noHead' => true, 'secs' => $secs, 'side' => self::access($v, $kind, $id, true)];
    }

    /**
     * Check nominations (§6.2, G5): To check, one nomination at a time, Done and Agreement.
     * Everything drawn comes from NominationChecks, which is masked on the server.
     */
    private static function queue(object $v, array $p, string $tab, int $nid): array
    {
        $id = (int) $p['id'];
        if ($tab === 'done') {
            $rows = [];
            foreach (NominationChecks::done($v, $id) as $r) {
                $rows[] = ['t' => $r['ref'] . ' · ' . $r['name'], 'sub' => 'You: ' . mb_strtolower($r['mine']) . ' · moderator: ' . mb_strtolower($r['theirs']),
                           'chip' => match ($r['outcome']) { 'agreed' => ['Agreed', 'ok'], 'differed' => ['Differed', 'warn'], default => null }];
            }
            return ['title' => 'Check nominations', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'Your checks', 'items' => $rows]
                : ['k' => 'note', 'text' => 'Nothing is waiting here for ' . $p['name'] . '. When something arrives it shows here, oldest first.', 'tone' => 'mute']]];
        }
        if ($tab === 'agree') {
            $ag = NominationChecks::agreement($v, $id);
            $secs = $ag ? [['k' => 'stats', 'items' => [['l' => 'Agreement', 'v' => $ag['pct'] . '%', 'pct' => $ag['pct']],
                                                        ['l' => 'Decided', 'v' => (string) $ag['decided']], ['l' => 'Differed', 'v' => (string) $ag['differed']]]]]
                        : [['k' => 'note', 'text' => 'Agreement shows once a moderator has decided a nomination you checked.', 'tone' => 'mute']];
            $secs[] = ['k' => 'note', 'text' => 'Agreed means the moderator approved one you said looks complete, or asked for more or turned down one you flagged. It never counts against your hours.', 'tone' => 'mute'];
            return ['title' => 'Agreement', 'secs' => $secs];
        }
        // To check: one nomination when one is chosen, otherwise the list.
        if ($nid > 0) {
            $n = NominationChecks::view($v, $id, $nid);
            if ($n === null || $n['checked']) {
                return ['title' => 'Check nominations', 'secs' => [['k' => 'note', 'text' => $n ? 'You’ve checked this one. It’s under Done.' : 'That nomination isn’t waiting for a check any more.', 'tone' => 'mute', 'cta' => ['label' => 'Back to the list', 'href' => $p['href'] . '/queue']]]];
            }
            $secs = [['k' => 'hero', 'ini' => Workspace::initials($n['name']), 'tone' => 'info', 't' => $n['name'],
                      'sub' => trim($n['category'] . ($n['place'] !== '' ? ' · ' . $n['place'] : '') . ' · Submitted ' . $n['when'] . ' · nominator details are hidden', ' ·'),
                      'chips' => [[$n['ref'], 'mute']], 'facts' => [], 'acts' => []]];
            foreach ($n['reasons'] as $r) $secs[] = ['k' => 'article', 'h' => $r['title'] !== '' ? 'Why, for ' . $r['title'] : 'Why', 'paras' => preg_split('/\R{2,}/', $r['reason']) ?: [$r['reason']]];
            $ev = array_map(static fn (array $e): array => ['t' => $e['label'], 'sub' => $e['kind'] === 'File' ? 'A file. A moderator opens it.' : 'Link', 'href' => $e['href']], $n['evidence']);
            $secs[] = $ev ? ['k' => 'rows', 'h' => 'Evidence', 'items' => $ev] : ['k' => 'note', 'text' => 'No evidence was sent with this nomination.', 'tone' => 'warn'];
            if ($n['checks']) $secs[] = ['k' => 'rows', 'h' => 'What the desk found', 'items' => array_map(static fn (array $c): array => ['t' => $c['t'], 'sub' => '', 'dot' => $c['ok'] ? 'ok' : 'warn'], $n['checks'])];
            $secs[] = ['k' => 'form', 'do' => 'nomcheck', 'submit' => 'Send my check', 'hidden' => ['cycle' => $id, 'nomination' => $n['id']],
                       'route' => 'A moderator decides. Your check goes with your name.',
                       'groups' => [['label' => 'Your check', 'help' => 'Check it against the rules, not your view of the nominee.', 'fields' => [
                           ['name' => 'check', 'label' => 'Pick one', 'type' => 'radio', 'required' => true,
                            'options' => ['complete' => 'Looks complete'] + array_combine(array_map(static fn ($k) => 'flag_' . $k, array_keys(NominationChecks::REASONS)), array_values(NominationChecks::REASONS))]]],
                                    ['label' => 'Note for the moderator', 'help' => 'Needed for “Something else”.', 'fields' => [['name' => 'note', 'label' => 'Note', 'type' => 'textarea']]]]];
            return ['title' => $n['name'], 'noHead' => true, 'secs' => $secs];
        }
        $rows = array_map(static fn (array $n): array => ['t' => $n['ref'] . ' · ' . $n['name'], 'sub' => trim($n['category'] . ' · ' . $n['when'], ' ·'),
                                                         'href' => $p['href'] . '/queue?n=' . $n['id'], 'cta' => ['label' => 'Check', 'href' => $p['href'] . '/queue?n=' . $n['id']]],
                          NominationChecks::queue($v, $id));
        return ['title' => 'Check nominations', 'secs' => [
            $rows ? ['k' => 'rows', 'h' => 'Oldest first', 'items' => $rows]
                  : ['k' => 'note', 'text' => 'Nothing is waiting here for ' . $p['name'] . '. When something arrives it shows here, oldest first.', 'tone' => 'mute'],
            ['k' => 'note', 'text' => 'You see the reason and the evidence. Nominator details stay hidden. You recommend; a moderator decides.', 'tone' => 'mute']]];
    }

    /** Voter drive (§6.2, G6): Your link and Where to go. */
    private static function drive(object $v, array $p, string $tab): array
    {
        $id = (int) $p['id'];
        $l = VoterDrive::link($v, $id);
        if (!$l) return ['title' => 'Voter drive', 'secs' => [['k' => 'note', 'text' => 'Your link isn’t ready. Try again in a moment.', 'tone' => 'warn']]];
        $url = rtrim(\AfricaGates\Support\SiteUrl::base(null), '/') . $l['path'];
        $secs = [];
        if ($l['people'] > 0) $secs[] = ['k' => 'stats', 'items' => [['l' => 'People who voted through your link', 'v' => (string) $l['people'], 's' => 'Counted once each, after their vote is verified']]];
        $secs[] = ['k' => 'rows', 'h' => 'Your link', 'items' => [['t' => $url, 'sub' => 'It opens this award’s ballot. It names no nominee.', 'href' => $l['path']]]];
        $secs[] = ['k' => 'note', 'text' => 'A person counts once their vote is verified. You never see who they voted for.', 'tone' => 'mute'];
        return ['title' => 'Voter drive', 'secs' => $secs];
    }

    /**
     * Nominees (Volunteer\Outreach): the award's nominees, follow-ups first, and one nominee at a
     * time with their contact, the team's history, a log form and a one-person email. No
     * selection and no "message all": the owner asked for none.
     */
    private static function nominees(object $v, array $p, string $tab, int $nid, array $q): array
    {
        $id = (int) $p['id'];
        $base = $p['href'] . '/nominees';
        if ($nid > 0) return self::nominee($v, $p, $nid, (array) ($q['_old'] ?? []));
        $find = mb_substr(trim((string) ($q['q'] ?? '')), 0, 80);
        $show = in_array($tab, ['due', 'new'], true) ? $tab : '';
        $rows = [];
        foreach (Outreach::nominees($v, $id, $find, $show) as $n) {
            $last = $n->last;
            $sub = $n->category . ' · ' . ($last ? (Outreach::OUTCOMES[$last->outcome] ?? '') . ', ' . Workspace::day((string) $last->created_at)
                . ($last->follow_up_on && !$n->stopped ? ' · follow up ' . Workspace::day((string) $last->follow_up_on) : '') : 'nobody has reached them yet')
                . ($n->stopped ? '' : ' · ' . self::profileWords($n));
            $rows[] = ['t' => (string) $n->name, 'sub' => $sub, 'href' => $base . '?n=' . $n->id,
                       'chip' => $n->stopped ? ['Asked not to be contacted', 'mute'] : ($n->due ? ['Follow up', 'warn']
                           : ($n->recent ? ['Reached ' . Workspace::day((string) $last->created_at), 'ok'] : ($last ? null : ['Not reached yet', 'info'])))];
        }
        $secs = [];
        if ($find === '' && $show === '' && Outreach::agreed($v, $id)) {
            $cv = Outreach::coverage($v, $id);
            if ($cv['total'] > 0) $secs[] = ['k' => 'stats', 'items' => [
                ['l' => 'Spoken to', 'v' => $cv['reached'] . ' of ' . $cv['total'], 'pct' => (int) round(100 * $cv['reached'] / $cv['total']), 's' => 'By anybody on the team'],
                ['l' => 'Profiles complete', 'v' => $cv['complete'] . ' of ' . $cv['total'], 'pct' => (int) round(100 * $cv['complete'] / $cv['total'])]]];
            $nx = Outreach::next($v, $id);
            if ($nx) $secs[] = ['k' => 'note', 'tone' => 'info', 'text' => 'Best to reach next: ' . $nx->name . ' (' . self::why($nx) . ').',
                                'cta' => ['label' => 'Open', 'href' => $base . '?n=' . $nx->id]];
        }
        $secs[] = ['k' => 'form', 'get' => $base . ($show ? '/' . $show : ''), 'label' => 'Find a nominee', 'submit' => 'Find', 'clear' => $find !== '',
                   'fields' => [['name' => 'q', 'label' => 'Name', 'type' => 'search', 'autocomplete' => 'off', 'value' => $find]]];
        $empty = match (true) {
            $find !== '' => 'Nobody by that name on this award.',
            $show === 'due' => 'No follow-ups due. Well done.',
            $show === 'new' => 'Everybody on this award has been reached at least once.',
            default => 'No nominees on this award yet. They show here once a nomination is approved.',
        };
        $secs[] = $rows ? ['k' => 'rows', 'h' => count($rows) . ' ' . (count($rows) === 1 ? 'nominee' : 'nominees'), 'items' => $rows]
                        : ['k' => 'note', 'text' => $empty, 'tone' => 'mute'];
        $secs = Outreach::agreed($v, $id) ? [...$secs, ...self::outreachTerms($v, $p)] : [...self::outreachTerms($v, $p), ...$secs];
        return ['title' => 'Nominees', 'secs' => $secs];
    }

    /** Where a nominee's profile stands, in a few words for a list row. */
    private static function profileWords(object $n): string
    {
        if (!$n->linked) return 'no account yet';
        return $n->needs ? 'needs ' . Outreach::needsPhrase($n->needs) : 'profile complete';
    }

    /** Why a nominee is the best to reach next, in a few words. */
    private static function why(object $n): string
    {
        return match ((int) $n->rank) {
            0 => 'follow-up due',
            1 => 'nobody has reached them yet',
            default => self::profileWords($n),
        };
    }

    /** The rules on a nominee's details: to agree to, or as the standing reminder once agreed. */
    private static function outreachTerms(object $v, array $p): array
    {
        $rows = array_map(static fn (array $t): array => ['t' => $t[0], 'sub' => $t[1]], Outreach::TERMS);
        if (Outreach::agreed($v, (int) $p['id'])) {
            return [['k' => 'note', 'tone' => 'mute', 'text' => 'Nominees’ details are only for Africa GATES and this award. No personal use, no selling, no sharing.']];
        }
        return [['k' => 'note', 'tone' => 'info', 'text' => 'Before you see any nominee’s phone or email, read and agree to how they may be used.'],
                ['k' => 'rows', 'h' => 'How you may use nominees’ details', 'items' => $rows],
                ['k' => 'form', 'do' => 'outreach_agree', 'hidden' => ['cycle' => (int) $p['id']], 'submit' => 'I agree',
                 'route' => 'We keep a record that you agreed, and when.', 'groups' => []]];
    }

    /** One nominee: who they are, how to reach them, what the team has said, and what you say next. */
    private static function nominee(object $v, array $p, int $nid, array $old): array
    {
        $n = Outreach::nominee($v, $nid);
        if (!$n) return ['title' => 'Nominees', 'secs' => [['k' => 'note', 'text' => 'That nominee isn’t on this award.', 'tone' => 'warn',
                                                            'cta' => ['label' => 'Back to nominees', 'href' => $p['href'] . '/nominees']]]];
        $acts = [];
        if ($n->phones) {
            $d = Outreach::dial((string) $n->phones[0]);
            $acts[] = ['label' => 'Call', 'href' => $d['tel'], 'primary' => true];
            $acts[] = ['label' => 'WhatsApp', 'href' => $d['whatsapp'], 'ext' => true];
        }
        if ($n->emails && !$n->email_today) $acts[] = ['label' => 'Write an email', 'href' => '#write', 'primary' => !$n->phones];
        $secs = [['k' => 'hero', 'ini' => Workspace::initials((string) $n->name), 'tone' => $n->stopped ? 'mute' : 'info', 't' => (string) $n->name,
                  'sub' => $n->category . ' · ' . $n->award,
                  'chips' => array_values(array_filter([$n->stopped ? ['Asked not to be contacted', 'mute'] : null])),
                  'facts' => array_values(array_filter([$n->organisation ? ['Organisation', (string) $n->organisation] : null])), 'acts' => $acts]];

        if (!$n->agreed) {
            return ['title' => (string) $n->name, 'kicker' => 'Nominees', 'noHead' => true, 'secs' => [...$secs, ...self::outreachTerms($v, $p)]];
        }
        // In the order a call goes: a warning if somebody just reached them, what to talk about,
        // what the team has already said (read before the number, not after the third call), how
        // to reach them, and then the outcome.
        if ($n->recent) {
            $h = $n->history[0];
            $secs[] = ['k' => 'note', 'tone' => 'warn', 'text' => $h->by . ' reached them ' . Workspace::day((string) $h->created_at)
                . '. Give them a day or two, unless they asked you to call.'];
        }
        if ($n->stopped) {
            $secs[] = ['k' => 'note', 'tone' => 'warn', 'text' => 'They asked not to be contacted. Nobody on this award reaches out to them again, so their details are hidden.'];
        } else {
            $secs[] = ['k' => 'rows', 'h' => 'What to help with', 'items' => [
                ['t' => $n->linked ? 'Account made' : 'No account yet', 'sub' => $n->linked ? 'They can sign in and add to their profile.' : 'They haven’t opened the link we emailed.',
                 'chip' => $n->linked ? ['Done', 'ok'] : ['To do', 'warn']],
                ['t' => $n->needs ? 'Still needs ' . Outreach::needsPhrase($n->needs) : 'Profile complete', 'sub' => $n->needs ? 'They add it themselves. You never upload for them.' : '',
                 'chip' => $n->needs ? ['To do', 'warn'] : ['Done', 'ok']]]];
        }
        $hist = [];
        foreach ($n->history as $h) {
            $what = $h->body !== null ? 'Email: ' . (string) $h->subject : (Outreach::OUTCOMES[$h->outcome] ?? '') . ' · ' . (Outreach::CHANNELS[$h->channel] ?? '');
            $words = $h->body !== null ? (string) $h->body : (string) ($h->note ?? '');
            $hist[] = ['t' => $what, 'meta' => Workspace::day((string) $h->created_at),
                       'sub' => trim($h->by . ($words !== '' ? ': ' . mb_strimwidth($words, 0, 240, '…') : '')
                              . ($h->follow_up_on ? ' · follow up ' . Workspace::day((string) $h->follow_up_on) : ''))];
        }
        $secs[] = $hist ? ['k' => 'rows', 'h' => 'What the team has said', 'items' => $hist]
                        : ['k' => 'note', 'tone' => 'mute', 'text' => 'Nobody on the team has reached them yet.'];
        if (!$n->stopped) {
            $reach = [];
            foreach ($n->phones as $ph) $reach[] = ['t' => (string) $ph, 'sub' => 'Phone', 'cta' => ['label' => 'Call', 'href' => Outreach::dial((string) $ph)['tel']]];
            foreach ($n->emails as $em) $reach[] = ['t' => (string) $em, 'sub' => 'Email', 'cta' => $n->email_today ? null : ['label' => 'Write', 'href' => '#write']];
            $secs[] = $reach ? ['k' => 'rows', 'h' => 'How to reach them', 'items' => $reach]
                             : ['k' => 'note', 'tone' => 'mute', 'text' => 'No phone or email on file yet. They add them when they finish their profile.'];
            if ($n->phones) {
                $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'Calls and WhatsApp use your own phone, so they show your number.'];
                // After a call, the two commonest answers in one tap each; the form below has the rest.
                $secs[] = ['k' => 'rows', 'h' => 'Just called?', 'items' => [
                    ['t' => 'No answer', 'sub' => 'We remind you in two days.', 'cta' => ['label' => 'Log it', 'post' => ['do' => 'outreach_log', 'nominee' => $n->id, 'channel' => 'call', 'outcome' => 'no_answer']]],
                    ['t' => 'Left a message', 'sub' => 'We remind you in two days.', 'cta' => ['label' => 'Log it', 'post' => ['do' => 'outreach_log', 'nominee' => $n->id, 'channel' => 'call', 'outcome' => 'left_message']]],
                ]];
            }
        }

        if ($n->stopped) return ['title' => (string) $n->name, 'kicker' => 'Nominees', 'noHead' => true, 'secs' => [...$secs, ...self::outreachTerms($v, $p)]];

        $o = static fn (string $k, string $d = ''): string => mb_substr((string) ($old[$k] ?? $d), 0, 3000);
        $secs[] = ['k' => 'form', 'h' => 'Log a conversation', 'do' => 'outreach_log', 'hidden' => ['nominee' => $n->id], 'submit' => 'Log it',
                   'route' => 'Everybody on this award sees it, with your name.',
                   'groups' => [
                       ['label' => 'How', 'fields' => [['name' => 'channel', 'label' => 'How you reached them', 'type' => 'radio', 'required' => true,
                                                         'options' => array_diff_key(Outreach::CHANNELS, ['email' => 1]), 'value' => $o('channel', 'call')]]],
                       ['label' => 'How it went', 'fields' => [['name' => 'outcome', 'label' => 'How it went', 'type' => 'radio', 'required' => true,
                                                                 'options' => Outreach::OUTCOMES, 'value' => $o('outcome')]]],
                       ['label' => 'Note', 'help' => 'What they said, or what they need. Optional.', 'fields' => [['name' => 'note', 'label' => 'Note', 'type' => 'textarea', 'value' => $o('note')]]],
                       ['label' => 'Follow up', 'help' => 'Leave it empty and we pick one: in two days after no answer or a message.',
                        'fields' => [['name' => 'follow_up_on', 'label' => 'Follow up on', 'type' => 'date', 'min' => date('Y-m-d'), 'value' => $o('follow_up_on')]]],
                   ]];
        if ($n->emails) {
            if ($n->email_today) {
                $secs[] = ['k' => 'note', 'tone' => 'mute', 'text' => 'They’ve had an email from the team today. Write again tomorrow, or call.'];
            } else {
                $to = count($n->emails) > 1
                    ? [['name' => 'to', 'label' => 'To', 'type' => 'select', 'options' => array_combine($n->emails, $n->emails), 'value' => $o('to', (string) $n->emails[0])]]
                    : [];
                $secs[] = ['k' => 'form', 'h' => 'Write an email', 'anchor' => 'write', 'do' => 'outreach_email',
                           'hidden' => ['nominee' => $n->id] + (count($n->emails) === 1 ? ['to' => (string) $n->emails[0]] : []), 'submit' => 'Send to ' . $n->name,
                           'route' => 'Sent by Africa GATES and signed with your first name. Your email address goes with it, so they can reply to you. It’s logged here.',
                           'groups' => array_values(array_filter([
                               $to ? ['label' => 'To', 'fields' => $to] : null,
                               ['label' => 'Subject', 'fields' => [['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'autocomplete' => 'off',
                                                                     'value' => $o('subject', 'Your nomination for ' . $p['name'])]]],
                               ['label' => 'Message', 'help' => 'A draft from what their profile needs. Change it to sound like you.',
                                'fields' => [['name' => 'body', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'value' => $o('body', Outreach::draft($v, $n))]]],
                               ['label' => 'Follow up', 'help' => 'Leave it empty and we pick three days from now.',
                                'fields' => [['name' => 'follow_up_on', 'label' => 'Follow up on', 'type' => 'date', 'min' => date('Y-m-d'), 'value' => $o('follow_up_on')]]],
                           ]))];
            }
        }
        return ['title' => (string) $n->name, 'kicker' => 'Nominees', 'noHead' => true, 'secs' => [...$secs, ...self::outreachTerms($v, $p)]];
    }

    /** The rules for a person's roles here (the DC's role guide). */
    private static function guide(array $caps): array
    {
        $rules = [];
        if (in_array(NominationChecks::CAPABILITY, $caps, true)) {
            $rules[] = ['t' => 'Check against the rules, not your view of the nominee', 'sub' => ''];
            $rules[] = ['t' => 'Flag a possible duplicate; never merge', 'sub' => 'A moderator compares the two and decides.'];
            $rules[] = ['t' => 'Don’t contact nominators', 'sub' => 'Their details stay hidden from you on purpose.'];
        }
        if (in_array(VoterDrive::CAPABILITY, $caps, true)) {
            $rules[] = ['t' => 'Never ask for a vote for a named nominee', 'sub' => 'You bring voters, not votes.'];
            $rules[] = ['t' => 'Never take money or phones', 'sub' => 'People verify on their own phone.'];
            $rules[] = ['t' => 'Wear the bib and show your pass', 'sub' => ''];
        }
        if (in_array(Outreach::CAPABILITY, $caps, true)) {
            $rules[] = ['t' => 'One person at a time', 'sub' => 'Never add nominees to a group or a broadcast list.'];
            $rules[] = ['t' => 'Read the history first', 'sub' => 'If someone spoke to them this week, don’t call again.'];
            $rules[] = ['t' => 'Never ask for a vote or money', 'sub' => 'Help them finish their profile and answer questions.'];
            $rules[] = ['t' => 'If they say stop, log it', 'sub' => 'Nobody on the award contacts them again.'];
            $rules[] = ['t' => 'Keep their details here', 'sub' => 'Don’t copy numbers or emails anywhere else.'];
        }
        return $rules ? [['k' => 'rows', 'h' => 'Rules for your role', 'items' => $rules]]
                      : [['k' => 'note', 'text' => 'Your role here is on the night. The briefing has what you need.', 'tone' => 'mute']];
    }

    /** Your access (§6.2): you can see · stays hidden · ends. */
    private static function access(object $v, string $kind, int $id, bool $short = false): array
    {
        $grants = array_values(array_filter(Grants::forActor('member', (int) $v->user_id, 'vol.'), static fn ($g) => $g->scope_kind === $kind && (int) $g->scope_id === $id));
        $see = [['t' => 'Your own tasks and hours here', 'sub' => '']];
        foreach ($grants as $g) {
            $see[] = ['t' => match ((string) $g->capability) {
                'vol.door', 'vol.usher' => 'Pass status at your gate: green or red',
                'vol.nomination_check' => 'Nominations: the reason, the category and the evidence',
                'vol.voter_drive' => 'Your link’s sign-ups, as counts',
                'vol.team_lead' => 'Your team’s work and the hours they log by hand',
                Outreach::CAPABILITY => 'Nominees’ phone numbers and emails, and the team’s conversations with them',
                default => Grants::CAPABILITIES[$g->capability] ?? (string) $g->capability,
            }, 'sub' => ''];
        }
        $reach = (bool) array_filter($grants, static fn ($g) => (string) $g->capability === Outreach::CAPABILITY);
        $hidden = [$reach ? ['t' => 'Nominators’ details, payments, scores and judges’ notes', 'sub' => 'Never sent to a volunteer, whatever the page.']
                          : ['t' => 'Phone numbers, emails, payments, scores and judges’ notes', 'sub' => 'Never sent to a volunteer, whatever the page.']];
        $ends = [];
        foreach ($grants as $g) {
            $by = $g->granted_by ? (string) (DB::table('gates_admins')->where('id', $g->granted_by)->value('name') ?? '') : '';
            $ends[] = ['t' => (Grants::CAPABILITIES[$g->capability] ?? $g->capability) . ' ends ' . Workspace::day((string) $g->expires_at),
                       'sub' => $by !== '' ? 'Granted by ' . $by : ((string) $g->note ?: ''), 'chip' => ['Ends automatically', 'mute']];
        }
        if ($ends === []) $ends[] = ['t' => 'You’re here for your role', 'sub' => 'Access ends when your role does.'];
        if ($short) return [['k' => 'rows', 'h' => 'Your access ends', 'items' => $ends]];
        return [['k' => 'rows', 'h' => 'You can see', 'items' => $see], ['k' => 'rows', 'h' => 'Stays hidden', 'items' => $hidden], ['k' => 'rows', 'h' => 'Ends', 'items' => $ends]];
    }

    // ── Profile and access ────────────────────────────────────────────────────────────────

    public static function me(object $v, object $user, string $page): array
    {
        $secs = match ($page) {
            'profile' => [['k' => 'form', 'do' => 'save_profile', 'submit' => 'Save changes', 'groups' => [
                ['label' => 'Name shown to leads', 'help' => 'Blank uses your account name.', 'fields' => [['name' => 'display_name', 'label' => 'Name', 'type' => 'text', 'value' => (string) ($v->display_name ?? ''), 'placeholder' => (string) $user->name]]],
                ['label' => 'Languages', 'help' => 'Leads pair you with people who share one.', 'fields' => [['name' => 'languages', 'label' => 'Languages', 'type' => 'text', 'value' => (string) ($v->languages ?? '')]]],
                ['label' => 'Area', 'help' => 'Where you usually are. Your lead uses it when planning.', 'fields' => [['name' => 'area', 'label' => 'Area', 'type' => 'text', 'value' => (string) ($v->area ?? '')]]],
                ['label' => 'Emergency contact', 'help' => 'Only your lead on the night sees it.', 'fields' => [['name' => 'emergency_contact', 'label' => 'Name and phone', 'type' => 'text', 'value' => (string) ($v->emergency_contact ?? '')]]]]]],
            'availability' => [['k' => 'form', 'do' => 'save_availability', 'submit' => 'Save changes', 'groups' => [
                ['label' => 'Weekdays', 'fields' => [['name' => 'weekdays', 'label' => 'Weekdays', 'type' => 'segmented', 'value' => (string) ($v->weekdays ?: 'none'), 'options' => ['none' => 'Not weekdays', 'evenings' => 'Evenings', 'any' => 'Any time']]]],
                ['label' => 'Weekends', 'fields' => [['name' => 'weekends', 'label' => 'Weekends', 'type' => 'segmented', 'value' => (string) ($v->weekends ?: 'none'), 'options' => ['none' => 'Not weekends', 'sat' => 'Saturdays', 'sun' => 'Sundays', 'both' => 'Both']]]],
                ['label' => 'Most hours a week', 'help' => 'The weekly line on Hours uses this.', 'fields' => [['name' => 'max_hours_week', 'label' => 'Hours', 'type' => 'number', 'min' => '1', 'max' => '60', 'value' => (string) ($v->max_hours_week ?: Rules::get('vol_week_hours_default'))]]],
                ['label' => 'How far you’ll travel', 'fields' => [['name' => 'travel_km', 'label' => 'Kilometres', 'type' => 'number', 'min' => '0', 'max' => '500', 'value' => (string) ($v->travel_km ?? '')]]],
                ['label' => 'From home', 'fields' => [['name' => 'from_home', 'label' => 'I can take work from home', 'type' => 'switch', 'value' => (string) (int) $v->from_home]]]]],
                ['k' => 'note', 'text' => 'Your lead sees this when planning work and meetings.', 'tone' => 'mute']],
            'notifications' => [['k' => 'rows', 'h' => 'Always on, by email', 'items' => [
                    ['t' => 'Meeting invitations, and when one is called off', 'sub' => 'Only meetings you’re in', 'chip' => ['Always on', 'mute']],
                    ['t' => 'A reminder the day before each meeting', 'sub' => 'When, where and the agenda', 'chip' => ['Always on', 'mute']],
                    ['t' => 'Training about to run out', 'sub' => 'A week before', 'chip' => ['Always on', 'mute']]]],
                ['k' => 'form', 'do' => 'save_notifications', 'submit' => 'Save changes', 'groups' => [
                ['label' => 'New tasks that fit me', 'fields' => [['name' => 'notify_fit', 'label' => 'New tasks that fit me', 'type' => 'segmented', 'value' => (string) ($v->notify_fit ?: 'weekly'), 'options' => ['never' => 'Never', 'weekly' => 'Weekly', 'posted' => 'When posted']]]],
                ['label' => 'Award news', 'fields' => [['name' => 'notify_news', 'label' => 'Tell me about the awards I help on', 'type' => 'switch', 'value' => (string) (int) $v->notify_news]]],
                ['label' => 'How we reach you', 'help' => 'Reminders go by email for now. Your choice here is kept for when other channels open.', 'fields' => [['name' => 'channel', 'label' => 'Channel', 'type' => 'segmented', 'value' => (string) ($v->channel ?: 'email'), 'options' => ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS']]]]]]],
            'access' => self::accessAll($v),
            'privacy' => [['k' => 'rows', 'h' => 'Your data', 'items' => [
                ['t' => 'Download a copy', 'sub' => 'Profile, hours, tasks and training', 'cta' => ['label' => 'Download', 'href' => '/volunteer/me/export.json']],
                ['t' => 'Date of birth', 'sub' => 'Kept to check the minimum age. Nobody else sees it.'],
                ['t' => 'Afrovanguard’s contact list', 'sub' => 'Your name, email and whether you’re applying, active, on a break or have stepped back. Nothing else, and nothing at all if you’re under 18.']]]],
            'leave' => [['k' => 'rows', 'h' => 'Options', 'items' => array_values(array_filter([
                $v->status === 'paused' ? ['t' => 'You’re on a break until ' . Workspace::day($v->paused_until), 'sub' => 'Come back whenever you like.', 'cta' => ['label' => 'Come back now', 'post' => ['do' => 'resume']]]
                                        : ['t' => 'Take a break', 'sub' => 'Pause invites for 2, 4 or 8 weeks. Your tasks stay until you drop them.', 'pause' => true],
                ['t' => 'Step back for good', 'sub' => 'Every role you hold ends now. Your verified record stays yours and can still give references.',
                 'cta' => ['label' => 'Leave', 'post' => ['do' => 'leave'], 'confirm' => 'Step back for good? Every role you hold ends now.', 'danger' => true]]]))]],
            default => [],
        };
        return ['title' => Workspace::ME[$page][0] ?? 'Profile', 'secs' => $secs];
    }

    private static function accessAll(object $v): array
    {
        $rows = [];
        foreach (Grants::forActor('member', (int) $v->user_id, 'vol.') as $g) {
            $rows[] = ['t' => (Grants::CAPABILITIES[$g->capability] ?? $g->capability) . ' · ' . Workspace::scopeName((string) $g->scope_kind, (int) $g->scope_id),
                       'sub' => 'Ends ' . Workspace::day((string) $g->expires_at), 'chip' => ['Active', 'ok']];
        }
        return [$rows ? ['k' => 'rows', 'h' => 'Roles you hold', 'items' => $rows] : ['k' => 'note', 'text' => 'You hold no roles yet. A role comes from the volunteer team or your lead.', 'tone' => 'mute'],
                ['k' => 'note', 'text' => 'Access is given per award or event and ends on its own. You never see the admin console.', 'tone' => 'info']];
    }

    // ── Small words ───────────────────────────────────────────────────────────────────────

    private static function PURPOSE_FOR(string $kind, string $page): string
    {
        return Workspace::PURPOSE[$kind . '.' . $page] ?? '';
    }

    private static function homeLine(object $v, array $lv): string
    {
        $bits = [];
        $tasks = count(Tasks::mine($v));
        if ($tasks) $bits[] = $tasks === 1 ? 'One task on the go' : $tasks . ' tasks on the go';
        if ($lv['next'] && $lv['next']['hours_to'] > 0) $bits[] = 'you’re ' . Level::h($lv['next']['hours_to']) . ' of verified hours from Level ' . $lv['next']['level'];
        return $bits ? ucfirst(implode('. ', $bits)) . '.' : Workspace::PURPOSE['root.home'];
    }

    private static function placeLine(object $v, array $p): string
    {
        return $p['ends'] ? 'Your access ends ' . Workspace::day($p['ends']) . '.' : '';
    }

    private static function progressWords(object $t): string
    {
        return $t->target ? (int) $t->progress . ' of ' . (int) $t->target . ($t->accepted ? ' · ' . (int) $t->accepted . ' accepted' : '') : '';
    }


    private static function capList(string $csv): string
    {
        return implode(' and ', array_map(static fn ($c) => Grants::CAPABILITIES[trim($c)] ?? trim($c), array_filter(explode(',', $csv))));
    }

    private static function minutes(int $m): string
    {
        return $m >= 60 ? Level::h($m / 60) : $m . ' min';
    }


    private static function ago(string $at): string
    {
        $s = max(0, time() - (int) strtotime($at));
        return match (true) { $s < 3600 => max(1, intdiv($s, 60)) . 'm', $s < 86400 => intdiv($s, 3600) . 'h', $s < 7 * 86400 => intdiv($s, 86400) . 'd', default => date('j M', (int) strtotime($at)) };
    }
}
