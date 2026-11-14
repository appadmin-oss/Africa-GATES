<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE AUTOMATIONS, AND WHAT KEEPS THEM HONEST (design plan, Rules and automations: "it may
 * detect freely but only proposes, and it speaks only when evidence, a known cause and a
 * threatened gate justify it").
 *
 *   · SHADOW FIRST (plan AT-11). Every rule an event gets starts in shadow mode: it records
 *     what it WOULD have done, on live data, and does nothing. A lead reads that record and
 *     switches it on. Pause stops one; "pause all" stops every rule at the event at once.
 *   · LEVELS, AND A CEILING ON THEM. Nothing here acts above level 2 (reversible) and nothing
 *     touches money, a deadline, the goal or anybody's access (plan AT-13): those are Changes,
 *     decided by a person. A rule's action is a flag for a lead, a private email, a pipeline
 *     stage only a submitted form can prove, or applying a small change nobody objected to.
 *   · QUIET (plan rules 10–13). The same rule says the same thing about the same subject at
 *     most once a day; a run is capped per rule; silence is checked against the pipeline and
 *     reports before anybody is nudged; and only what a lead must decide reaches Today.
 *   · LOGGED. Every firing, shadow or live, is a row with its subject, what was done and the
 *     evidence (plan AT-12), and the Rules page shows how often each fired.
 *
 * Run by Maintenance every tick (`event-rules`), and on demand from the Rules page.
 */
final class Rules
{
    /** key => [name, level, what it does, why it is here] */
    public const CATALOGUE = [
        'stage_move'  => ['Move a prospect when their nomination arrives', 2, 'A submitted nomination matching a contact moves it to Nomination submitted.', 'Nobody has to log what the form already proves.'],
        'reminder'    => ['Remind the owner of work due soon', 0, 'Work due in two days with nothing heard for three: a private email to its owner, once a day.', 'Private nudges, never a public ranking.'],
        'silent'      => ['Flag a quiet team member', 1, 'Open work, and no report, progress or pipeline move from its owner for four days: a flag to the lead offering to hand it over.', 'Checked against the pipeline before anybody is called silent.'],
        'duplicate'   => ['Flag a person in two places', 2, 'The same email or phone in two contacts, or nominated in two categories: a flag to the lead.', 'One person, one place to count them.'],
        'gate_threat' => ['Warn about a gate at risk', 0, 'A gate due in three days that the count has not reached: a flag to the lead.', 'Rank by threat to a gate.'],
        'behind'      => ['Flag a goal behind plan', 1, 'A goal more than 20% behind where the plan expects it: a flag to the lead to find the cause before changing anything.', 'Diagnose before proposing.'],
        'small_lapse' => ['Apply small changes nobody objected to', 1, 'A small change waits ' . Tiers::LAPSE_HOURS . ' hours for a lead to object, then applies itself.', 'Only small changes may approve themselves.'],
        'digest'      => ['Send the leads a daily digest', 0, 'Once a day at the hour set here: at most three decisions and every goal against plan.', 'One batched message instead of many.'],
    ];
    public const MODES = ['shadow' => 'Shadow', 'active' => 'On', 'paused' => 'Paused'];
    /** Flags that reach a lead's Today. */
    public const FLAGS = ['silent', 'duplicate', 'gate_threat', 'behind'];
    public const PER_RUN = 20;
    public const DIGEST_HOUR = 7;

    public static function ready(): bool
    {
        return Goals::ready() && \AfricaGates\Support\SchemaHas::table('gates_event_rules');
    }

    /** The event's rules, every one of the catalogue present (new ones in shadow). @return array<string,object> */
    public static function forEvent(int $eventId): array
    {
        if (!self::ready()) return [];
        $have = [];
        foreach (DB::table('gates_event_rules')->where('event_id', $eventId)->get()->all() as $r) $have[(string) $r->rkey] = $r;
        $now = date('Y-m-d H:i:s');
        foreach (array_keys(self::CATALOGUE) as $k) {
            if (isset($have[$k])) continue;
            $id = (int) DB::table('gates_event_rules')->insertGetId(['event_id' => $eventId, 'rkey' => $k, 'mode' => 'shadow',
                'params' => $k === 'digest' ? json_encode(['hour' => self::DIGEST_HOUR]) : null, 'review_at' => date('Y-m-d H:i:s', time() + 90 * 86400), 'created_at' => $now]);
            $have[$k] = DB::table('gates_event_rules')->where('id', $id)->first();
        }
        return $have;
    }

    /** Switch one rule (or, with key 'all', pause every one). A lead's call. */
    public static function setMode(int $eventId, string $key, string $mode, int $memberId): array
    {
        if (!Plan::may($memberId, $eventId, 'plan.edit')) return ['ok' => false, 'error' => 'A lead of this event sets its rules.'];
        if (!isset(self::MODES[$mode])) return ['ok' => false, 'error' => 'On, shadow or paused?'];
        self::forEvent($eventId);
        $q = DB::table('gates_event_rules')->where('event_id', $eventId);
        if ($key !== 'all') {
            if (!isset(self::CATALOGUE[$key])) return ['ok' => false, 'error' => 'That is not one of the rules.'];
            $q->where('rkey', $key);
        }
        $q->update(['mode' => $mode, 'owner_id' => $memberId, 'updated_at' => date('Y-m-d H:i:s'), 'review_at' => date('Y-m-d H:i:s', time() + 90 * 86400)]);
        return ['ok' => true, 'event' => $eventId, 'msg' => $key === 'all' ? 'Every rule paused. Nothing acts until you switch one back on.'
            : (self::CATALOGUE[$key][0] . ': ' . mb_strtolower(self::MODES[$mode]) . '.')];
    }

    public static function setDigestHour(int $eventId, int $hour, int $memberId): array
    {
        if (!Plan::may($memberId, $eventId, 'plan.edit')) return ['ok' => false, 'error' => 'A lead of this event sets its rules.'];
        if ($hour < 0 || $hour > 23) return ['ok' => false, 'error' => 'An hour from 0 to 23.'];
        self::forEvent($eventId);
        DB::table('gates_event_rules')->where('event_id', $eventId)->where('rkey', 'digest')->update(['params' => json_encode(['hour' => $hour]), 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => $eventId, 'msg' => 'The digest goes at ' . sprintf('%02d:00', $hour) . '.'];
    }

    // ── running ────────────────────────────────────────────────────────────────────────

    /** Every event worth watching: not more than a day past, with a plan, goals or contacts. @return int rules that fired */
    public static function runAll(): int
    {
        if (!self::ready()) return 0;
        $ids = DB::table('gates_site_events')->where('event_date', '>=', date('Y-m-d H:i:s', time() - 86400))->pluck('id')->map(static fn ($i) => (int) $i)->all();
        $n = 0;
        foreach ($ids as $id) {
            $busy = DB::table('gates_event_goals')->where('event_id', $id)->exists() || DB::table('gates_event_work')->where('event_id', $id)->exists()
                || DB::table('gates_event_contacts')->where('event_id', $id)->exists();
            if ($busy) $n += self::run($id);
        }
        return $n;
    }

    /** One event's rules. @return int how many log rows were written */
    public static function run(int $eventId, ?int $now = null): int
    {
        if (!self::ready()) return 0;
        $now ??= time();
        $written = 0;
        foreach (self::forEvent($eventId) as $key => $rule) {
            if ($rule->mode === 'paused') continue;
            $live = $rule->mode === 'active';
            $count = 0;
            foreach (self::evaluate($key, $eventId, $live, $rule, $now) as [$subject, $did, $evidence]) {
                if ($count >= self::PER_RUN) break;                               // a cap per rule per run
                if (self::said($rule, $subject, $now)) continue;                 // once a day per subject
                DB::table('gates_event_rule_log')->insert(['rule_id' => (int) $rule->id, 'event_id' => $eventId, 'mode' => $live ? 'active' : 'shadow',
                    'subject' => mb_substr($subject, 0, 190), 'did' => mb_substr(($live ? '' : 'Would have: ') . $did, 0, 255),
                    'evidence' => mb_substr($evidence, 0, 255), 'created_at' => date('Y-m-d H:i:s', $now)]);
                $count++;
                $written++;
            }
        }
        return $written;
    }

    private static function said(object $rule, string $subject, int $now): bool
    {
        return DB::table('gates_event_rule_log')->where('rule_id', (int) $rule->id)->where('subject', mb_substr($subject, 0, 190))
            ->where('created_at', '>', date('Y-m-d H:i:s', $now - 86400))->exists();
    }

    /**
     * What one rule finds, and — when it is live — does. Shadow mode passes $act = false and
     * only the description is kept.
     *
     * @return list<array{0:string,1:string,2:string}> subject, what is (or would be) done, evidence
     */
    private static function evaluate(string $key, int $eventId, bool $act, object $rule, int $now): array
    {
        $out = [];
        switch ($key) {
            case 'stage_move':
                // Shadow: name who WOULD move without moving them.
                if ($act) {
                    foreach (Pipeline::signal($eventId) as $c) $out[] = ['contact:' . $c->id, 'Moved ' . $c->name . ' to Nomination submitted', 'Nomination #' . $c->nomination_id . ' carries their contact details'];
                } else {
                    foreach (self::wouldMove($eventId) as [$c, $nid]) $out[] = ['contact:' . $c->id, 'move ' . $c->name . ' to Nomination submitted', 'Nomination #' . $nid . ' carries their contact details'];
                }
                break;
            case 'reminder':
                foreach (Plan::work([$eventId], true) as $w) {
                    if ($w->owner_kind !== Plan::ACTOR || !$w->owner_id || !$w->due_at || $w->status === 'proof') continue;
                    $due = strtotime((string) $w->due_at);
                    if ($due < $now || $due > $now + 2 * 86400) continue;
                    if (self::heardOf((int) $w->id, $now - 3 * 86400)) continue;
                    $who = DB::table('gates_users')->where('id', (int) $w->owner_id)->first(['name', 'email']);
                    if (!$who || !$who->email) continue;
                    // Claimed per work item per day BEFORE it is sent. `said()` in run() only
                    // skips the LOG row, and a reminder changes neither of the things heardOf()
                    // reads, so without the claim this mailed the owner on every tick (96 a day).
                    if ($act && !self::claimOnce('rem:' . $w->id . ':' . date('Y-m-d', $now), (string) $who->email)) continue;
                    $sent = $act && self::sendClaimed('rem:' . $w->id . ':' . date('Y-m-d', $now), (string) $who->email, (string) $who->name, $eventId, 'nudge', [[(string) $w->title, 'due ' . Goals::day((string) $w->due_at)]], []);
                    $out[] = ['work:' . $w->id, ($act ? ($sent ? 'Emailed ' : 'Could not email ') : 'email ') . $who->name . ' a private reminder about “' . $w->title . '”',
                              'Due ' . Goals::day((string) $w->due_at) . '; no report or progress in three days'];
                }
                break;
            case 'silent':
                $owners = [];
                foreach (Plan::work([$eventId], true) as $w) if ($w->owner_kind === Plan::ACTOR && $w->owner_id) $owners[(int) $w->owner_id][] = $w;
                foreach ($owners as $uid => $works) {
                    $since = date('Y-m-d H:i:s', $now - 4 * 86400);
                    $recent = DB::table('gates_event_reports')->where('event_id', $eventId)->where('author_id', $uid)->where('created_at', '>', $since)->exists()
                        || DB::table('gates_event_work')->where('event_id', $eventId)->where('owner_id', $uid)->where('updated_at', '>', $since)->exists()
                        || DB::table('gates_event_contacts')->where('event_id', $eventId)->where('owner_id', $uid)->where('updated_at', '>', $since)->exists();
                    if ($recent) continue;   // plan rule 10: the hard signals first
                    $out[] = ['member:' . $uid, ($act ? 'Flagged' : 'flag') . ' ' . (Plan::name(Plan::ACTOR, $uid) ?: 'a team member') . ' as quiet, with ' . count($works) . ' open',
                              'No report, progress or pipeline move for four days'];
                }
                break;
            case 'duplicate':
                foreach (['email', 'phone'] as $col) {
                    foreach (DB::table('gates_event_contacts')->where('event_id', $eventId)->whereNotNull($col)->groupBy($col)->havingRaw('COUNT(*) > 1')->pluck($col)->all() as $v) {
                        $out[] = ['dup:' . $col . ':' . $v, ($act ? 'Flagged' : 'flag') . ' the same ' . $col . ' in two contacts', (string) $v];
                    }
                }
                $cats = Goals::categoryIds($eventId, 'event', null);
                if ($cats) {
                    foreach (DB::table('gates_nominations')->whereIn('category_id', $cats)->where('status', '!=', 'rejected')->whereNull('merged_into')->whereNotNull('nominee_email')
                                 ->where('nominee_email', '!=', '')->groupBy('nominee_email')->havingRaw('COUNT(DISTINCT category_id) > 1')->pluck('nominee_email')->all() as $e) {
                        $out[] = ['dupnom:' . strtolower((string) $e), ($act ? 'Flagged' : 'flag') . ' a nominee in two categories', (string) $e];
                    }
                }
                break;
            case 'gate_threat':
                foreach (Goals::forEvent($eventId) as $g) foreach (Goals::gates((int) $g->id) as $x) {
                    if ($x->state !== 'threat') continue;
                    $out[] = ['gate:' . $x->id, ($act ? 'Warned' : 'warn') . ' the lead: “' . $x->title . '” of “' . $g->title . '” is due ' . Goals::day((string) $x->due_at),
                              'The count is ' . Goals::fmt((string) $g->metric, (int) $x->value) . ' of ' . Goals::fmt((string) $g->metric, (int) $x->target)];
                }
                break;
            case 'behind':
                foreach (Goals::forEvent($eventId) as $g) {
                    if (!$g->behind) continue;
                    $out[] = ['goal:' . $g->id, ($act ? 'Flagged' : 'flag') . ' “' . $g->title . '” as behind plan', $g->words];
                }
                break;
            case 'small_lapse':
                foreach (Changes::lapse($eventId, $act) as $c) $out[] = ['change:' . $c->id, ($act ? 'Applied' : 'apply') . ': ' . $c->summary, 'No objection in ' . Tiers::LAPSE_HOURS . ' hours'];
                break;
            case 'digest':
                $hour = (int) (json_decode((string) $rule->params, true)['hour'] ?? self::DIGEST_HOUR);
                if ((int) date('G', $now) < $hour) break;
                $subject = 'digest:' . date('Y-m-d', $now);
                if (DB::table('gates_event_rule_log')->where('rule_id', (int) $rule->id)->where('subject', $subject)->exists()) break;
                $items = array_map(static fn ($d) => [(string) $d['work']->title, $d['why']], array_slice(TeamLoop::decisions($eventId), 0, TeamLoop::TOP));
                $goals = array_map(static fn ($g) => [(string) $g->title, $g->words], Goals::forEvent($eventId));
                $sent = 0;
                // Claimed per lead per event per day: the log-row check above is read-then-write,
                // so two overlapping ticks could both pass it and both send.
                $key = 'dig:' . $eventId . ':' . date('Y-m-d', $now);
                foreach (Leads::of($eventId) as $l) {
                    if (!$l->email || !$act || !self::claimOnce($key, (string) $l->email)) continue;
                    if (self::sendClaimed($key, (string) $l->email, (string) $l->name, $eventId, 'digest', $items, $goals)) $sent++;
                }
                $n = count(Leads::of($eventId));
                $out[] = [$subject, $act ? 'Sent the digest to ' . $sent . ' ' . ($sent === 1 ? 'lead' : 'leads') : 'send the digest to ' . $n . ' ' . ($n === 1 ? 'lead' : 'leads'),
                          count($items) . ' to decide, ' . count($goals) . ' goals'];
                break;
        }
        return $out;
    }

    /** @return list<array{0:object,1:int}> contacts the signal would move, without moving them */
    private static function wouldMove(int $eventId): array
    {
        $cats = Goals::categoryIds($eventId, 'event', null);
        if (!$cats) return [];
        $out = [];
        foreach (DB::table('gates_event_contacts')->where('event_id', $eventId)->where('stage', '!=', 'nominated')->get()->all() as $c) {
            $q = DB::table('gates_nominations')->whereIn('category_id', $cats)->where('status', '!=', 'rejected')->whereNull('merged_into');
            $q->where(static function ($w) use ($c) {
                if ($c->email) $w->orWhereRaw('LOWER(nominee_email) = ?', [$c->email]);
                // Ends with the stored ten digits; escaped through Like (CLAUDE.md: the two drivers disagree on LIKE's escape).
                if ($c->phone) $w->orWhereRaw(\AfricaGates\Support\Like::clause('nominee_phone'), ['%' . \AfricaGates\Support\Like::esc((string) $c->phone)]);
            });
            if (!$c->email && !$c->phone) continue;
            if ($nid = $q->value('id')) $out[] = [$c, (int) $nid];
        }
        return $out;
    }

    /** Own this address for this send, or learn another tick already does (BroadcastLog). */
    private static function claimOnce(string $key, string $email): bool
    {
        return BroadcastLog::claim(mb_substr('evrule:' . $key, 0, 60), $email);
    }

    private static function sendClaimed(string $key, string $to, string $name, int $eventId, string $kind, array $items, array $goals): bool
    {
        $ok = self::mail($to, $name, $eventId, $kind, $items, $goals);
        BroadcastLog::settle(mb_substr('evrule:' . $key, 0, 60), $to, $ok);
        return $ok;
    }

    private static function heardOf(int $workId, int $since): bool
    {
        $at = date('Y-m-d H:i:s', $since);
        return DB::table('gates_event_reports')->where('work_id', $workId)->where('created_at', '>', $at)->exists()
            || DB::table('gates_event_work')->where('id', $workId)->where('updated_at', '>', $at)->exists();
    }

    private static function mail(string $to, string $name, int $eventId, string $kind, array $items, array $goals): bool
    {
        $e = Plan::event($eventId);
        try {
            $r = Emails::send(OtpService::boot(), $to, 'event-loop', ['first_name' => trim((string) strtok(trim($name), ' ')) ?: 'there',
                'place' => (string) ($e->title ?? 'your event'), 'kind' => $kind, 'items' => $items, 'goals' => $goals,
                'action_url' => rtrim(SiteUrl::base(), '/') . ($kind === 'digest' ? EventPages::url($eventId) : '/volunteer/event/' . $eventId . '/tasks'),
                'action_label' => $kind === 'digest' ? 'Open the event' : 'Report or finish it']);
            return !empty($r['ok']);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── reading ────────────────────────────────────────────────────────────────────────

    /** @return list<object> the log, newest first, with ->rule (the catalogue name) */
    public static function log(int $eventId, int $limit = 60): array
    {
        if (!self::ready()) return [];
        $names = [];
        foreach (DB::table('gates_event_rules')->where('event_id', $eventId)->get(['id', 'rkey']) as $r) $names[(int) $r->id] = self::CATALOGUE[$r->rkey][0] ?? $r->rkey;
        return array_map(static function ($l) use ($names) { $l->rule = $names[(int) $l->rule_id] ?? 'A rule'; return $l; },
            DB::table('gates_event_rule_log')->where('event_id', $eventId)->orderByDesc('id')->limit($limit)->get()->all());
    }

    /** @return array<int,array{shadow:int,active:int}> rule id => how often it fired in 30 days */
    public static function health(int $eventId): array
    {
        $out = [];
        if (!self::ready()) return $out;
        foreach (DB::table('gates_event_rule_log')->where('event_id', $eventId)->where('created_at', '>', date('Y-m-d H:i:s', time() - 30 * 86400))
                     ->groupBy('rule_id', 'mode')->get([DB::raw('rule_id as r'), DB::raw('mode as m'), DB::raw('COUNT(*) as n')]) as $x) {
            $out[(int) $x->r][(string) $x->m] = (int) $x->n;
        }
        return $out;
    }

    /** Live flags from the last three days that need a lead, for Today. @return list<array{kind:string, work:object, why:string, rank:int}> */
    public static function flags(int $eventId): array
    {
        if (!self::ready()) return [];
        $rules = DB::table('gates_event_rules')->where('event_id', $eventId)->whereIn('rkey', self::FLAGS)->where('mode', 'active')->pluck('rkey', 'id')->all();
        if (!$rules) return [];
        $out = [];
        $seen = [];
        foreach (DB::table('gates_event_rule_log')->whereIn('rule_id', array_keys($rules))->where('mode', 'active')->whereNull('undone_at')
                     ->where('created_at', '>', date('Y-m-d H:i:s', time() - 3 * 86400))->orderByDesc('id')->get()->all() as $l) {
            if (isset($seen[$l->subject])) continue;
            $seen[$l->subject] = true;
            $k = (string) $rules[(int) $l->rule_id];
            $out[] = ['kind' => 'rule', 'work' => (object) ['id' => 0, 'title' => (string) $l->did, 'blocks_night' => 0], 'why' => (string) $l->evidence,
                      'rank' => $k === 'gate_threat' ? 0 : ($k === 'behind' ? 1 : 2), 'log' => (int) $l->id];
        }
        return $out;
    }

    /** A lead clears a flag (it was seen and dealt with): it leaves Today, and the log keeps it. */
    public static function clear(int $logId, int $memberId): array
    {
        $l = self::ready() ? DB::table('gates_event_rule_log')->where('id', $logId)->first() : null;
        if (!$l || !Plan::may($memberId, (int) $l->event_id, 'plan.edit')) return ['ok' => false, 'error' => 'A lead clears these.'];
        DB::table('gates_event_rule_log')->where('id', $logId)->update(['undone_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $l->event_id, 'msg' => 'Cleared. It stays in the rules’ log.'];
    }
}
