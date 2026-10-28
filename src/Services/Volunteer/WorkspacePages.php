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
 * the next shift is the person's next signup, "To Level 3" is Level::of(), the fit reason is
 * the ranking's own words. Where the record has nothing, the page says one sentence and offers
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
            'shifts'   => self::shifts($v, $tab),
            'tasks'    => self::tasks($v, $tab),
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

        $mine = Shifts::mine($v);
        $next = $mine[0] ?? null;
        $secs = [];
        if ($next) {
            $secs[] = ['k' => 'hero', 'ini' => date('j', (int) strtotime((string) $next->starts_at)), 'tone' => 'ok', 't' => (string) $next->title,
                'sub' => 'Your next shift' . ($next->brief ? '. ' . self::firstSentence((string) $next->brief) : '.'),
                'chips' => [[Workspace::day($next->starts_at) . ' · ' . mb_strtolower(Workspace::until($next->starts_at)), 'info'],
                            [$next->time_changed ? 'Time changed' : 'Confirmed', $next->time_changed ? 'warn' : 'ok']],
                'facts' => array_values(array_filter([['Time', Workspace::span($next->starts_at, $next->ends_at)],
                    ['Where', $next->from_home ? 'From home' : ((string) $next->location ?: $next->scope_name)],
                    $next->bring ? ['Bring', (string) $next->bring] : null, $next->lead_name ? ['Lead', (string) $next->lead_name] : null])),
                'acts' => array_values(array_filter([
                    $next->time_changed ? ['label' => 'Accept change', 'post' => ['do' => 'accept', 'shift' => $next->id], 'primary' => true] : null,
                    ['label' => 'See the shift', 'href' => '/volunteer/shifts/' . $next->id, 'primary' => !$next->time_changed],
                    ['label' => 'Can’t make it', 'href' => '/volunteer/requests?new=swap&title=' . rawurlencode((string) $next->title)],
                ]))];
        }

        $needs = [];
        foreach (Shifts::clashes($v) as [$a, $b]) {
            $needs[] = ['t' => 'Your shifts clash on ' . Workspace::day($a->starts_at), 'sub' => $a->title . ' and ' . $b->title . '. Keep one.',
                        'dot' => 'bad', 'cta' => ['label' => 'Sort it', 'href' => '/volunteer/shifts']];
        }
        foreach ($mine as $s) if ($s->time_changed && $s !== $next) {
            $needs[] = ['t' => $s->title . ': the time changed', 'sub' => Workspace::day($s->starts_at) . ' · now ' . Workspace::span($s->starts_at, $s->ends_at),
                        'dot' => 'warn', 'cta' => ['label' => 'Accept change', 'post' => ['do' => 'accept', 'shift' => $s->id]]];
        }
        foreach (Training::next($v) as $m) if ($m->tone === 'warn') {
            // "Door steward expires in 5 days" / "10 minutes · keeps Door steward" (handoff: "Safety 2026 expires in 5 days").
            $bits = explode(' · ', (string) $m->reason, 2);
            $needs[] = ['t' => $m->title . ' ' . lcfirst($bits[0]), 'sub' => (int) $m->minutes . ' minutes' . (isset($bits[1]) ? ' · ' . $bits[1] : ''),
                        'dot' => 'warn', 'chip' => ['Due', 'warn'], 'cta' => ['label' => 'Retake', 'href' => '/volunteer/training/take/' . $m->slug]];
        }
        foreach (Requests::from('member', (int) $v->user_id, 'open') as $r) if ($r->status === 'needs_info') {
            $ask = DB::table('gates_request_events')->where('request_id', $r->id)->where('action', 'ask_info')->orderByDesc('id')->value('note');
            $needs[] = ['t' => Requests::deciderLabel($r) . ' asked about your request', 'sub' => $ask ? '“' . $ask . '”' : (string) $r->title,
                        'dot' => 'info', 'cta' => ['label' => 'Reply', 'href' => '/volunteer/requests/' . $r->id]];
        }
        foreach (Hours::rows($v, 'review', 20) as $h) if (!$h->proof) {
            $needs[] = ['t' => 'Add proof for ' . Level::h((float) $h->hours), 'sub' => $h->what . ' · a photo or link speeds up the check',
                        'dot' => 'info', 'cta' => ['label' => 'Add proof', 'href' => '/volunteer/hours/review']];
        }
        if ($needs !== []) $secs[] = ['k' => 'rows', 'h' => 'Needs you', 'items' => $needs];

        if ($role === 'lead') {
            $team = [];
            $w = Team::waitingHours($v);
            if ($w) $team[] = ['t' => (count($w) === 1 ? 'One report' : count($w) . ' reports') . ' of hours to confirm',
                               'sub' => 'Confirm only what you saw or have proof of', 'dot' => 'warn', 'cta' => ['label' => 'Confirm', 'href' => '/volunteer/team/approvals']];
            foreach (array_slice(Team::gaps($v), 0, 2) as $g) {
                $team[] = ['t' => $g->title . ' is ' . $g->short . ' short for ' . Workspace::day($g->starts_at),
                           'sub' => $g->fits ? 'Best fits: ' . implode(', ', $g->fits) : 'Nobody in your team is free and trained for it yet',
                           'dot' => 'warn', 'cta' => ['label' => 'See gaps', 'href' => '/volunteer/team/gaps']];
            }
            if ($team) $secs[] = ['k' => 'rows', 'h' => 'Your team', 'items' => $team];
        }

        $cards = [];
        foreach (Workspace::places($v) as $p) {
            $cards[] = ['t' => $p['name'], 'meta' => implode(', ', $p['roles']) ?: 'Your shifts', 'ini' => Workspace::initials($p['name']),
                        'tone' => $p['kind'] === 'event' ? 'ok' : 'info', 'sub' => self::placeLine($v, $p), 'href' => $p['href']];
        }
        if ($cards) $secs[] = ['k' => 'cards', 'h' => 'Where you help', 'items' => $cards];

        $lv = Level::of($v);
        $stats = [['l' => 'Verified hours', 'v' => Level::h($lv['hours']), 's' => 'All time']];
        if ($lv['reliability'] !== null) $stats[] = ['l' => 'Shifts kept', 'v' => $lv['reliability'] . '%', 's' => $lv['kept'] . ' of ' . ($lv['kept'] + $lv['missed'])];
        if ($lv['next']) $stats[] = ['l' => 'To Level ' . $lv['next']['level'], 'v' => $lv['next']['hours_to'] > 0 ? Level::h($lv['next']['hours_to']) : 'Hours done',
                                     'pct' => $lv['next']['pct'], 's' => implode(' and ', $lv['next']['needs']) ?: 'Nearly there'];
        $secs[] = ['k' => 'stats', 'h' => 'Your record', 'items' => $stats];

        $sub = self::homeLine($v, $mine, $lv);
        $week = [];
        foreach ($mine as $s) if (strtotime((string) $s->starts_at) < strtotime('+7 days')) {
            $week[] = ['at' => (string) $s->starts_at, 'time' => date('D', (int) strtotime((string) $s->starts_at)), 't' => (string) $s->title, 'd' => Workspace::span($s->starts_at, $s->ends_at), 'st' => 'next'];
        }
        foreach (Tasks::mine($v) as $t) if ($t->due_at && strtotime((string) $t->due_at) < strtotime('+7 days')) {
            $week[] = ['at' => (string) $t->due_at, 'time' => date('D', (int) strtotime((string) $t->due_at)), 't' => $t->title . ' due', 'd' => self::progressWords($t), 'st' => ''];
        }
        usort($week, static fn ($a, $b) => strcmp($a['at'], $b['at']));   // one agenda, in the order the week happens
        $side = [];
        if ($week) $side[] = ['k' => 'agenda', 'h' => 'This week', 'items' => $week];
        if ($next && $next->lead_name) $side[] = ['k' => 'rows', 'h' => 'Your lead', 'items' => [['t' => (string) $next->lead_name, 'sub' => $next->scope_name, 'ini' => Workspace::initials((string) $next->lead_name)]]];
        if (!$next) $side[] = ['k' => 'note', 'text' => 'No shift booked. Fits you lists the ones that suit your time, place and training.', 'tone' => 'mute',
                               'cta' => ['label' => 'See shifts that fit', 'href' => '/volunteer/shifts/fits']];

        return ['title' => 'Today', 'purpose' => $sub, 'noHead' => (bool) $next, 'secs' => $secs, 'side' => $side];
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
            ['t' => 'ID check', 'sub' => $wantsId ? 'For door and desk roles, at your first shift' : 'Only for door, money or data roles', 'st' => ''],
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
        $secs[] = ['k' => 'note', 'text' => 'Do the training now and you can take a shift the day you’re approved.', 'tone' => 'ok',
                   'cta' => ['label' => 'Start training', 'href' => '/volunteer/training']];
        return ['title' => 'Application', 'purpose' => $asked ? 'Your application is with us. It moves on when your reference replies.' : 'Your application is with the volunteer team.', 'secs' => $secs];
    }

    private static function gettingStarted(object $v): array
    {
        $hasShift = Shifts::mine($v) !== [];
        $trained = Training::passed($v) !== [];
        $rows = [];
        foreach (array_slice(Shifts::fits($v), 0, 3) as $s) {
            $rows[] = ['t' => $s->title . ($s->scope_name ? ', ' . $s->scope_name : ''), 'sub' => Workspace::day($s->starts_at) . ' · ' . Workspace::span($s->starts_at, $s->ends_at) . ' · ' . $s->why,
                       'chip' => $s->trained ? ['Fits you', 'ok'] : ['After training', 'mute'],
                       'cta' => $s->trained ? ['label' => 'Join', 'post' => ['do' => 'join', 'shift' => $s->id]] : ['label' => 'Start training', 'href' => '/volunteer/training/take/' . $s->module_key]];
        }
        foreach (array_slice(Tasks::library($v), 0, 3 - count($rows)) as $t) {
            if (!$t->from_home) continue;
            $rows[] = ['t' => (string) $t->title, 'sub' => 'From home' . ($t->est_minutes ? ' · about ' . self::minutes((int) $t->est_minutes) : '') . ($t->module_key ? '' : ' · no training needed'),
                       'chip' => ['From home', 'ok'], 'cta' => ['label' => 'Take it', 'post' => ['do' => 'take', 'task' => $t->id]]];
        }
        $secs = [['k' => 'steps', 'h' => 'Getting started', 'items' => [
            ['t' => 'Code of conduct', 'sub' => 'Done', 'st' => 'done'],
            ['t' => 'Pick a first role', 'sub' => 'Drive, door or desk', 'st' => $hasShift ? 'done' : 'now'],
            ['t' => 'Short training', 'sub' => '10 to 15 min', 'st' => $trained ? 'done' : ($hasShift ? 'now' : '')],
            ['t' => 'First shift', 'sub' => 'One that fits you', 'st' => ''],
        ]]];
        $secs[] = $rows ? ['k' => 'rows', 'h' => 'Good first shifts', 'items' => $rows]
                        : ['k' => 'note', 'text' => 'No shifts are open right now. We tell you when one that fits you is posted.', 'tone' => 'mute'];
        $secs[] = ['k' => 'note', 'text' => 'Nothing is expected of you until you pick something. Hours count from your first shift.', 'tone' => 'mute'];
        return ['title' => 'Today', 'purpose' => 'Two steps to your first shift. Most people finish in a day.', 'secs' => $secs];
    }

    // ── Shifts ────────────────────────────────────────────────────────────────────────────

    private static function shifts(object $v, string $tab): array
    {
        $secs = [];
        if ($tab === 'mine') {
            $clash = [];
            foreach (Shifts::clashes($v) as [$a, $b]) { $clash[(int) $a->id] = $b->title; $clash[(int) $b->id] = $a->title; }
            $rows = [];
            foreach (Shifts::mine($v) as $s) {
                $chip = isset($clash[(int) $s->id]) ? ['Clashes with ' . $clash[(int) $s->id], 'bad'] : ($s->time_changed ? ['Time changed', 'warn'] : ['Confirmed', 'ok']);
                $cta = isset($clash[(int) $s->id]) ? ['label' => 'Drop this one', 'post' => ['do' => 'drop', 'shift' => $s->id], 'confirm' => 'Drop ' . $s->title . '?']
                     : ($s->time_changed ? ['label' => 'Accept change', 'post' => ['do' => 'accept', 'shift' => $s->id]] : null);
                $rows[] = ['t' => (string) $s->title, 'sub' => Workspace::day($s->starts_at) . ' · ' . Workspace::span($s->starts_at, $s->ends_at) . ' · ' . $s->scope_name,
                           'meta' => date('j M', (int) strtotime((string) $s->starts_at)), 'chip' => $chip, 'cta' => $cta, 'href' => '/volunteer/shifts/' . $s->id];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Coming up', 'items' => $rows]
                            : ['k' => 'note', 'text' => 'You have no shifts booked.', 'tone' => 'mute', 'cta' => ['label' => 'See shifts that fit', 'href' => '/volunteer/shifts/fits']];
            $secs[] = ['k' => 'note', 'text' => sprintf('Dropping a shift more than %d hours before is fine. Inside %d hours, tell your lead so they can fill it.',
                       Rules::get('vol_late_drop_hours'), Rules::get('vol_late_drop_hours')), 'tone' => 'mute'];
        } elseif ($tab === 'fits') {
            $rows = [];
            foreach (array_slice(Shifts::fits($v), 0, 25) as $s) {
                $rows[] = ['t' => $s->title . ', ' . $s->scope_name, 'sub' => Workspace::day($s->starts_at) . ' · ' . Workspace::span($s->starts_at, $s->ends_at) . ' · ' . $s->why,
                           'chip' => $s->trained ? ['Fits you', $s->fit >= 70 ? 'ok' : 'mute'] : ['After training', 'mute'],
                           'cta' => $s->trained ? ['label' => 'Join', 'post' => ['do' => 'join', 'shift' => $s->id]]
                                                : ['label' => 'Train first', 'href' => '/volunteer/training/take/' . $s->module_key], 'href' => '/volunteer/shifts/' . $s->id];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Best fits for you', 'items' => $rows] : ['k' => 'note', 'text' => 'No open shifts have room right now.', 'tone' => 'mute'];
            $secs[] = ['k' => 'note', 'text' => 'Ranked by your availability' . self::availWords($v) . ', where you are, your training and the shifts you’ve kept. Change these in Profile and access.',
                       'tone' => 'mute', 'cta' => ['label' => 'Availability', 'href' => '/volunteer/me/availability']];
        } elseif ($tab === 'all') {
            $rows = [];
            foreach (Shifts::open($v) as $s) {
                if ((int) $s->filled >= (int) $s->slots) continue;
                $rows[] = ['t' => $s->title . ', ' . $s->scope_name, 'sub' => Workspace::day($s->starts_at) . ' · ' . $s->filled . ' of ' . $s->slots . ' filled',
                           'pct' => (int) round($s->filled * 100 / max(1, (int) $s->slots)), 'barT' => ((int) $s->slots - (int) $s->filled) . ' ' . (((int) $s->slots - (int) $s->filled) === 1 ? 'spot' : 'spots'),
                           'href' => '/volunteer/shifts/' . $s->id];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Open', 'items' => $rows] : ['k' => 'note', 'text' => 'No shifts are open right now.', 'tone' => 'mute'];
        } else {
            $rows = [];
            foreach (Shifts::past($v) as $s) {
                $st = (string) $s->signup_status;
                $rows[] = ['t' => (string) $s->title, 'sub' => Workspace::day($s->starts_at) . ' · ' . ($st === 'kept' ? Level::h((float) $s->hours) . ' verified' : ($st === 'missed' ? 'not checked in' : 'dropped late')),
                           'chip' => $st === 'kept' ? ['Kept', 'ok'] : ['Missed', 'bad']];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Past', 'items' => $rows] : ['k' => 'note', 'text' => 'Your past shifts show here once you’ve done one.', 'tone' => 'mute'];
        }
        return ['title' => 'Shifts', 'secs' => $secs];
    }

    /** One shift, as a record page (§2.9: Shift for volunteers). */
    public static function shift(object $v, int $id): ?array
    {
        $s = DB::table('gates_vol_shifts')->where('id', $id)->first();
        if (!$s || !in_array($s->status, ['open', 'closed'], true)) return null;
        $mine = DB::table('gates_vol_signups')->where('shift_id', $id)->where('volunteer_id', $v->id)->first();
        $on = $mine && $mine->status === 'on';
        $filled = (int) DB::table('gates_vol_signups')->where('shift_id', $id)->where('status', 'on')->count();
        $scope = Shifts::scopeName((string) $s->scope_kind, (int) $s->scope_id);
        $trained = !$s->module_key || Training::holds((int) $v->id, (string) $s->module_key);
        $acts = [];
        if ($on) {
            $acts[] = ['label' => 'Drop this shift', 'post' => ['do' => 'drop', 'shift' => $id], 'confirm' => 'Drop ' . $s->title . '?'];
            $acts[] = ['label' => 'Ask for a swap', 'href' => '/volunteer/requests?new=swap&title=' . rawurlencode((string) $s->title)];
        } elseif (strtotime((string) $s->starts_at) > time() && $filled < (int) $s->slots) {
            $acts[] = $trained ? ['label' => 'Join', 'post' => ['do' => 'join', 'shift' => $id], 'primary' => true]
                               : ['label' => 'Train first', 'href' => '/volunteer/training/take/' . $s->module_key, 'primary' => true];
        }
        $facts = array_values(array_filter([['When', Workspace::day($s->starts_at) . ' · ' . Workspace::span($s->starts_at, $s->ends_at)],
            ['Where', $s->from_home ? 'From home' : ((string) $s->location ?: $scope)], ['Filled', $filled . ' of ' . $s->slots],
            ['Training', $s->module_key ? (Training::titles()[$s->module_key] ?? $s->module_key) : 'None needed'],
            $s->bring ? ['Bring', (string) $s->bring] : null, $s->lead_name ? ['Lead', (string) $s->lead_name] : null]));
        $secs = [['k' => 'hero', 'ini' => date('j', (int) strtotime((string) $s->starts_at)), 'tone' => $on ? 'ok' : 'info', 't' => (string) $s->title, 'sub' => $scope,
                  'chips' => array_values(array_filter([[Workspace::until($s->starts_at), 'info'], $on ? ['You’re on it', 'ok'] : null])), 'facts' => $facts, 'acts' => $acts]];
        if ($s->brief) $secs[] = ['k' => 'note', 'text' => (string) $s->brief, 'tone' => 'mute'];
        $secs[] = ['k' => 'rows', 'h' => 'Hours', 'items' => [['t' => 'Logged from your check-in and check-out scans', 'sub' => 'No scan? Staff mark who came, from the shift’s own times.']]];
        return ['title' => (string) $s->title, 'kicker' => $scope, 'noHead' => true, 'secs' => $secs];
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
                       'legend' => ['Scans and kept shifts', 'Accepted work', 'Logged by hand']];
            $rows = [];
            foreach (['scan' => 'Door and gate scans', 'signup' => 'Shifts marked attended', 'task' => 'Accepted task work', 'manual' => 'Logged by you, confirmed by a lead'] as $k => $l) {
                $h = (float) ($sum['by_source'][$k] ?? 0);
                if ($h > 0) $rows[] = ['t' => $l, 'sub' => Level::h($h) . ($k === 'manual' ? '' : ' · automatic'), 'chip' => $k === 'manual' ? ['Confirmed', 'info'] : ['Automatic', 'ok']];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Where they came from', 'items' => $rows]
                            : ['k' => 'note', 'text' => 'Your verified hours show here from your first kept shift or accepted task.', 'tone' => 'mute'];
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
            $secs[] = ['k' => 'note', 'text' => 'Most hours log themselves: gate scans, kept shifts, accepted tasks. Log by hand only for work nobody saw. A lead confirms it, and a photo or link speeds that up.', 'tone' => 'info'];
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
            $stats = [['l' => 'Verified hours', 'v' => Level::h($lv['hours'])], ['l' => 'Shifts kept', 'v' => $lv['kept'] . ($lv['kept'] + $lv['missed'] > 0 ? ' of ' . ($lv['kept'] + $lv['missed']) : '')]];
            $done = (int) DB::table('gates_vol_task_takes')->where('volunteer_id', $v->id)->where('status', 'done')->count();
            if ($done) $stats[] = ['l' => 'Tasks accepted', 'v' => (string) $done];
            return ['title' => 'My record', 'secs' => [['k' => 'steps', 'h' => 'Levels', 'items' => $steps], ['k' => 'stats', 'items' => $stats]]];
        }
        if ($tab === 'badges') {
            $rows = [];
            foreach (self::badges($v, $lv) as [$t, $sub]) $rows[] = ['t' => $t, 'sub' => $sub, 'chip' => ['Earned', 'ok']];
            return ['title' => 'My record', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'Badges', 'items' => $rows] : ['k' => 'note', 'text' => 'Your first badge comes with your first kept shift.', 'tone' => 'mute']]];
        }
        return ['title' => 'My record', 'acts' => [['label' => 'Ask for a letter', 'href' => '/volunteer/requests?new=role&title=' . rawurlencode('A reference letter'), 'primary' => true]],
                'secs' => [['k' => 'note', 'text' => 'A reference letter lists only verified hours, roles and checks. You choose who it goes to.', 'tone' => 'mute']]];
    }

    /** Badges are earned from the record, never given. @return list<array{0:string,1:string}> */
    private static function badges(object $v, array $lv): array
    {
        $out = [];
        $kept = DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
            ->where('s.volunteer_id', $v->id)->where('s.status', 'kept')->orderBy('h.starts_at')->get(['h.starts_at', 'h.title'])->all();
        if (isset($kept[0])) $out[] = ['First shift', Workspace::day($kept[0]->starts_at) . ' · ' . $kept[0]->title];
        if (isset($kept[1])) $out[] = ['Second shift', 'You came back. Most people don’t.'];
        foreach ([10, 50, 100] as $h) if ($lv['hours'] >= $h) $out[] = [$h . ' verified hours', 'From scans, kept shifts and accepted work'];
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
                $rows[] = ['t' => (string) $m->name, 'sub' => 'Level ' . $m->level . ' · ' . ($m->next ? 'next: ' . Workspace::day($m->next->starts_at) . ', ' . $m->next->title : 'no shift booked'),
                           'ini' => Workspace::initials((string) $m->name), 'meta' => Level::h((float) $m->hours)];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Your team', 'items' => $rows] : ['k' => 'note', 'text' => 'Your team is everybody on a shift where you lead. Nobody is booked yet.', 'tone' => 'mute'];
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
        } else {
            $rows = [];
            foreach (Team::gaps($v) as $g) {
                $rows[] = ['t' => $g->title . ' · ' . Workspace::day($g->starts_at), 'sub' => $g->short . ' short' . ($g->fits ? ' · best fits: ' . implode(', ', $g->fits) : ''),
                           'pct' => (int) round($g->filled * 100 / max(1, (int) $g->slots)), 'barT' => $g->filled . ' of ' . $g->slots];
            }
            $secs[] = $rows ? ['k' => 'rows', 'h' => 'Unfilled', 'items' => $rows] : ['k' => 'note', 'text' => 'Every shift where you lead is full.', 'tone' => 'ok'];
        }
        return ['title' => 'My team', 'secs' => $secs];
    }

    // ── Requests ──────────────────────────────────────────────────────────────────────────

    private static function requests(object $v, string $tab, array $q): array
    {
        $secs = [];
        $new = (string) ($q['new'] ?? '');
        if ($new !== '' || $tab === 'new') $secs[] = self::composer($v, $new, (string) ($q['title'] ?? ''));
        $rows = Requests::from('member', (int) $v->user_id, $tab === 'decided' ? 'decided' : 'open');
        $secs[] = self::requestTable($rows, $tab === 'decided' ? 'Nothing decided yet.' : 'You have no open requests.',
                                     'A request that needs information from you shows on Today too.');
        return ['title' => 'Requests', 'acts' => $new === '' ? [['label' => 'New request', 'href' => '/volunteer/requests?new=swap', 'primary' => true]] : [], 'secs' => $secs];
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
            $r->scope_kind ? ['Where', Shifts::scopeName((string) $r->scope_kind, (int) $r->scope_id)] : null,
            $r->amount_minor !== null ? ['Amount', '₦' . number_format($r->amount_minor / 100)] : ($r->amount_text ? ['Amount', (string) $r->amount_text] : null),
            $r->needed_by ? ['Needed by', (string) $r->needed_by] : null, ['Why', (string) $r->why], ['Decides', Requests::deciderLabel($r)]]));
        $tone = ['waiting' => 'warn', 'needs_info' => 'info', 'approved' => 'ok', 'declined' => 'bad', 'withdrawn' => 'mute'];
        $status = $decides && $r->status === 'waiting' ? 'Waiting on you' : (Requests::STATUSES[$r->status] ?? $r->status);
        $secs = [['k' => 'hero', 'ini' => strtoupper(mb_substr((string) $r->type, 0, 2)), 'tone' => 'info', 't' => (string) $r->title, 'sub' => '',
                  'chips' => [[$status, $tone[$r->status] ?? 'mute']], 'facts' => $facts, 'acts' => []]];
        $log = [];
        foreach (Requests::history($id) as $e) {
            $who = $e->actor_kind === 'member' && (int) $e->actor_id === (int) $v->user_id ? 'You' : (string) ($e->actor_name ?? 'Someone');
            $verb = ['asked' => 'asked', 'approve' => 'approved', 'ask_info' => 'asked for information', 'decline' => 'declined', 'add_info' => 'added information', 'withdraw' => 'withdrew it', 'undo' => 'undid the last step'][$e->action] ?? $e->action;
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

    public static function place(object $v, array $p, string $page, string $tab): array
    {
        $kind = $p['kind'];
        $id = $p['id'];
        $shifts = array_values(array_filter(Shifts::mine($v), static fn ($s) => $s->scope_kind === $kind && (int) $s->scope_id === $id));
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
            if ($shifts) $part[] = ['l' => 'Your shifts', 'v' => (string) count($shifts)];
            $mineHere = (int) DB::table('gates_vol_task_takes as k')->join('gates_vol_tasks as t', 't.id', '=', 'k.task_id')->where('k.volunteer_id', $v->id)
                ->where('t.scope_kind', 'cycle')->where('t.scope_id', $id)->whereIn('k.status', ['taken', 'done'])->count();
            if ($mineHere) $part[] = ['l' => 'Your tasks', 'v' => (string) $mineHere];
            $secs[] = $part ? ['k' => 'stats', 'h' => 'Your part', 'items' => $part] : ['k' => 'note', 'text' => 'Your part here starts with a task or a shift.', 'tone' => 'mute'];
            return ['title' => $p['name'], 'purpose' => self::PURPOSE_FOR($kind, $page), 'secs' => $secs, 'side' => self::access($v, $kind, $id, true)];
        }

        // An event.
        $e = DB::table('gates_site_events')->where('id', $id)->first();
        if ($page === 'shifts') {
            $rows = [];
            foreach ($shifts as $s) $rows[] = ['t' => (string) $s->title, 'sub' => Workspace::day($s->starts_at) . ' · ' . Workspace::span($s->starts_at, $s->ends_at),
                'chip' => $s->time_changed ? ['Time changed', 'warn'] : ['Confirmed', 'ok'], 'href' => '/volunteer/shifts/' . $s->id,
                'cta' => $s->time_changed ? ['label' => 'Accept', 'post' => ['do' => 'accept', 'shift' => $s->id]] : null];
            return ['title' => 'Your shifts', 'secs' => [$rows ? ['k' => 'rows', 'items' => $rows] : ['k' => 'note', 'text' => 'You have no shifts at this event.', 'tone' => 'mute', 'cta' => ['label' => 'See open shifts', 'href' => '/volunteer/shifts/all']]]];
        }
        if ($page === 'brief') {
            $rows = [];
            foreach ($shifts as $s) {
                $rows[] = ['t' => $s->title . ' · ' . Workspace::span($s->starts_at, $s->ends_at), 'sub' => trim(($s->bring ? 'Bring: ' . $s->bring . '. ' : '') . ($s->lead_name ? 'Your lead: ' . $s->lead_name . '.' : ''))];
                if ($s->brief) $rows[] = ['t' => 'From the organisers', 'sub' => (string) $s->brief];
            }
            $secs = $rows ? [['k' => 'rows', 'h' => 'For your shifts', 'items' => $rows]] : [['k' => 'note', 'text' => 'Your briefing shows here once you’re on a shift.', 'tone' => 'mute']];
            if ($e && trim((string) $e->access_notes) !== '') $secs[] = ['k' => 'note', 'text' => (string) $e->access_notes, 'tone' => 'info'];
            $secs[] = ['k' => 'note', 'text' => 'Calls go through the platform; numbers stay private.', 'tone' => 'mute'];
            return ['title' => 'Briefing', 'secs' => $secs];
        }
        if ($page === 'team') {
            $ids = array_map(static fn ($s) => (int) $s->id, $shifts);
            $rows = [];
            if ($ids) {
                foreach (DB::table('gates_vol_signups as s')->join('gates_volunteers as v', 'v.id', '=', 's.volunteer_id')->whereIn('s.shift_id', $ids)
                             ->where('s.status', 'on')->where('s.volunteer_id', '!=', $v->id)->distinct()->get(['v.*']) as $o) {
                    $n = Volunteers::name($o);
                    $lv = Level::of($o)['level'];
                    $first = !DB::table('gates_vol_signups')->where('volunteer_id', $o->id)->where('status', 'kept')->exists();
                    $rows[] = ['t' => $n, 'sub' => 'Level ' . $lv, 'ini' => Workspace::initials($n), 'chip' => $first ? ['First shift', 'info'] : null];
                }
            }
            return ['title' => 'Who’s on', 'secs' => [$rows ? ['k' => 'rows', 'h' => 'On your shifts with you', 'items' => $rows] : ['k' => 'note', 'text' => 'Nobody else is booked on your shifts yet.', 'tone' => 'mute']]];
        }
        $days = $e ? (int) floor((strtotime(date('Y-m-d', (int) strtotime((string) $e->event_date))) - strtotime(date('Y-m-d'))) / 86400) : null;
        $clash = count(array_filter(Shifts::clashes($v), static fn ($pair) => $pair[0]->scope_kind === 'event' && (int) $pair[0]->scope_id === $id));
        $first = $shifts[0] ?? null;
        $hero = ['k' => 'hero', 'ini' => $e ? date('j', (int) strtotime((string) $e->event_date)) : 'E', 'tone' => 'ok', 't' => $p['name'],
                 'sub' => $e ? Workspace::day((string) $e->event_date) . ($e->venue ? ' · ' . $e->venue : '') . ($p['roles'] ? '. You’re ' . mb_strtolower(implode(' and ', $p['roles'])) . '.' : '') : '',
                 'chips' => array_values(array_filter([$days !== null && $days >= 0 ? [$days === 0 ? 'Today' : $days . ' ' . ($days === 1 ? 'day' : 'days') . ' to go', 'info'] : null,
                                                       $clash ? [$clash . ' clash to sort', 'bad'] : null])),
                 'facts' => array_values(array_filter([$first ? ['Your shift', $first->title . ' · ' . Workspace::span($first->starts_at, $first->ends_at)] : null,
                                                       $first && $first->bring ? ['Bring', (string) $first->bring] : null, $first && $first->lead_name ? ['Your lead', (string) $first->lead_name] : null])),
                 'acts' => $first ? [['label' => 'See your shift', 'href' => '/volunteer/shifts/' . $first->id, 'primary' => true]] : [['label' => 'See open shifts', 'href' => '/volunteer/shifts/all', 'primary' => true]]];
        $secs = [$hero];
        if ($clash) $secs[] = ['k' => 'rows', 'h' => 'For you', 'items' => [['t' => 'Your shifts clash', 'sub' => 'Keep one; the other goes back to the team.', 'dot' => 'bad', 'cta' => ['label' => 'Sort it', 'href' => '/volunteer/shifts']]]];
        return ['title' => $p['name'], 'noHead' => true, 'secs' => $secs, 'side' => self::access($v, $kind, $id, true)];
    }

    /** Your access (§6.2): you can see · stays hidden · ends. */
    private static function access(object $v, string $kind, int $id, bool $short = false): array
    {
        $grants = array_values(array_filter(Grants::forActor('member', (int) $v->user_id, 'vol.'), static fn ($g) => $g->scope_kind === $kind && (int) $g->scope_id === $id));
        $see = [['t' => 'Your own shifts, tasks and hours here', 'sub' => '']];
        foreach ($grants as $g) {
            $see[] = ['t' => match ((string) $g->capability) {
                'vol.door', 'vol.usher' => 'Pass status at your gate: green or red',
                'vol.nomination_check' => 'Nominations: the reason, the category and the evidence',
                'vol.voter_drive' => 'Your link’s sign-ups, as counts',
                'vol.team_lead' => 'Your team’s shifts and the hours they log by hand',
                default => Grants::CAPABILITIES[$g->capability] ?? (string) $g->capability,
            }, 'sub' => ''];
        }
        $hidden = [['t' => 'Phone numbers, emails, payments, scores and judges’ notes', 'sub' => 'Never sent to a volunteer, whatever the page.']];
        $ends = [];
        foreach ($grants as $g) {
            $by = $g->granted_by ? (string) (DB::table('gates_admins')->where('id', $g->granted_by)->value('name') ?? '') : '';
            $ends[] = ['t' => (Grants::CAPABILITIES[$g->capability] ?? $g->capability) . ' ends ' . Workspace::day((string) $g->expires_at),
                       'sub' => $by !== '' ? 'Granted by ' . $by : ((string) $g->note ?: ''), 'chip' => ['Ends automatically', 'mute']];
        }
        if ($ends === []) $ends[] = ['t' => 'You’re here for your shifts', 'sub' => 'Access ends the day after your last shift here.'];
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
                ['label' => 'Area', 'help' => 'Where you usually are. Shift suggestions use it.', 'fields' => [['name' => 'area', 'label' => 'Area', 'type' => 'text', 'value' => (string) ($v->area ?? '')]]],
                ['label' => 'Emergency contact', 'help' => 'Only your lead on the night sees it.', 'fields' => [['name' => 'emergency_contact', 'label' => 'Name and phone', 'type' => 'text', 'value' => (string) ($v->emergency_contact ?? '')]]]]]],
            'availability' => [['k' => 'form', 'do' => 'save_availability', 'submit' => 'Save changes', 'groups' => [
                ['label' => 'Weekdays', 'fields' => [['name' => 'weekdays', 'label' => 'Weekdays', 'type' => 'segmented', 'value' => (string) ($v->weekdays ?: 'none'), 'options' => ['none' => 'Not weekdays', 'evenings' => 'Evenings', 'any' => 'Any time']]]],
                ['label' => 'Weekends', 'fields' => [['name' => 'weekends', 'label' => 'Weekends', 'type' => 'segmented', 'value' => (string) ($v->weekends ?: 'none'), 'options' => ['none' => 'Not weekends', 'sat' => 'Saturdays', 'sun' => 'Sundays', 'both' => 'Both']]]],
                ['label' => 'Most hours a week', 'help' => 'The weekly line on Hours uses this.', 'fields' => [['name' => 'max_hours_week', 'label' => 'Hours', 'type' => 'number', 'min' => '1', 'max' => '60', 'value' => (string) ($v->max_hours_week ?: Rules::get('vol_week_hours_default'))]]],
                ['label' => 'How far you’ll travel', 'fields' => [['name' => 'travel_km', 'label' => 'Kilometres', 'type' => 'number', 'min' => '0', 'max' => '500', 'value' => (string) ($v->travel_km ?? '')]]],
                ['label' => 'From home', 'fields' => [['name' => 'from_home', 'label' => 'I can take work from home', 'type' => 'switch', 'value' => (string) (int) $v->from_home]]]]],
                ['k' => 'note', 'text' => 'Shift suggestions and your lead’s invites use this.', 'tone' => 'mute']],
            'notifications' => [['k' => 'rows', 'h' => 'Always on', 'items' => [['t' => 'Changes to my shifts', 'sub' => 'A moved time or a cancelled shift', 'chip' => ['Always on', 'mute']]]],
                ['k' => 'form', 'do' => 'save_notifications', 'submit' => 'Save changes', 'groups' => [
                ['label' => 'Shifts that fit me', 'fields' => [['name' => 'notify_fit', 'label' => 'Shifts that fit me', 'type' => 'segmented', 'value' => (string) ($v->notify_fit ?: 'weekly'), 'options' => ['never' => 'Never', 'weekly' => 'Weekly', 'posted' => 'When posted']]]],
                ['label' => 'Award news', 'fields' => [['name' => 'notify_news', 'label' => 'Tell me about the awards I help on', 'type' => 'switch', 'value' => (string) (int) $v->notify_news]]],
                ['label' => 'How we reach you', 'fields' => [['name' => 'channel', 'label' => 'Channel', 'type' => 'segmented', 'value' => (string) ($v->channel ?: 'email'), 'options' => ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS']]]]]]],
            'access' => self::accessAll($v),
            'privacy' => [['k' => 'rows', 'h' => 'Your data', 'items' => [
                ['t' => 'Download a copy', 'sub' => 'Profile, hours, shifts, tasks and training', 'cta' => ['label' => 'Download', 'href' => '/volunteer/me/export.json']],
                ['t' => 'Date of birth', 'sub' => 'Kept to check the minimum age. Nobody else sees it.']]]],
            'leave' => [['k' => 'rows', 'h' => 'Options', 'items' => array_values(array_filter([
                $v->status === 'paused' ? ['t' => 'You’re on a break until ' . Workspace::day($v->paused_until), 'sub' => 'Come back whenever you like.', 'cta' => ['label' => 'Come back now', 'post' => ['do' => 'resume']]]
                                        : ['t' => 'Take a break', 'sub' => 'Pause invites for 2, 4 or 8 weeks. Your shifts stay until you drop them.', 'pause' => true],
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
            $rows[] = ['t' => (Grants::CAPABILITIES[$g->capability] ?? $g->capability) . ' · ' . Shifts::scopeName((string) $g->scope_kind, (int) $g->scope_id),
                       'sub' => 'Ends ' . Workspace::day((string) $g->expires_at), 'chip' => ['Active', 'ok']];
        }
        return [$rows ? ['k' => 'rows', 'h' => 'Roles you hold', 'items' => $rows] : ['k' => 'note', 'text' => 'You hold no roles yet. A role comes with a shift or from the volunteer team.', 'tone' => 'mute'],
                ['k' => 'note', 'text' => 'Access is given per award or event and ends on its own. You never see the admin console.', 'tone' => 'info']];
    }

    // ── Small words ───────────────────────────────────────────────────────────────────────

    private static function PURPOSE_FOR(string $kind, string $page): string
    {
        return Workspace::PURPOSE[$kind . '.' . $page] ?? '';
    }

    private static function homeLine(object $v, array $mine, array $lv): string
    {
        $bits = [];
        $week = count(array_filter($mine, static fn ($s) => strtotime((string) $s->starts_at) < strtotime('+7 days')));
        if ($week) $bits[] = $week === 1 ? 'One shift this week' : $week . ' shifts this week';
        if ($lv['next'] && $lv['next']['hours_to'] > 0) $bits[] = 'you’re ' . Level::h($lv['next']['hours_to']) . ' of verified hours from Level ' . $lv['next']['level'];
        return $bits ? ucfirst(implode('. ', $bits)) . '.' : Workspace::PURPOSE['root.home'];
    }

    private static function placeLine(object $v, array $p): string
    {
        $mine = array_values(array_filter(Shifts::mine($v), static fn ($s) => $s->scope_kind === $p['kind'] && (int) $s->scope_id === $p['id']));
        if ($mine) return 'Your shift: ' . $mine[0]->title . ', ' . Workspace::day($mine[0]->starts_at) . ' · ' . Workspace::span($mine[0]->starts_at, $mine[0]->ends_at) . '.';
        return $p['ends'] ? 'Your access ends ' . Workspace::day($p['ends']) . '.' : '';
    }

    private static function progressWords(object $t): string
    {
        return $t->target ? (int) $t->progress . ' of ' . (int) $t->target . ($t->accepted ? ' · ' . (int) $t->accepted . ' accepted' : '') : '';
    }

    private static function availWords(object $v): string
    {
        $w = array_filter([['evenings' => 'weekday evenings', 'any' => 'weekdays'][(string) $v->weekdays] ?? null,
                           ['sat' => 'Saturdays', 'sun' => 'Sundays', 'both' => 'weekends'][(string) $v->weekends] ?? null]);
        return $w ? ' (' . implode(', ', $w) . ')' : '';
    }

    private static function capList(string $csv): string
    {
        return implode(' and ', array_map(static fn ($c) => Grants::CAPABILITIES[trim($c)] ?? trim($c), array_filter(explode(',', $csv))));
    }

    private static function minutes(int $m): string
    {
        return $m >= 60 ? Level::h($m / 60) : $m . ' min';
    }

    private static function firstSentence(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        return preg_match('/^.*?[.!?](\s|$)/u', $s, $m) ? trim($m[0]) : mb_substr($s, 0, 140);
    }

    private static function ago(string $at): string
    {
        $s = max(0, time() - (int) strtotime($at));
        return match (true) { $s < 3600 => max(1, intdiv($s, 60)) . 'm', $s < 86400 => intdiv($s, 3600) . 'h', $s < 7 * 86400 => intdiv($s, 86400) . 'd', default => date('j M', (int) strtotime($at)) };
    }
}
