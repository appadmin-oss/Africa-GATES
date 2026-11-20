<?php
declare(strict_types=1);
namespace AfricaGates\Services\Shell;

use AfricaGates\Services\EventPlan\EventPages;
use AfricaGates\Services\EventPlan\LeadPages;
use AfricaGates\Services\EventPlan\Plan;
use AfricaGates\Services\EventPlan\Scope;
use AfricaGates\Services\Requests;
use AfricaGates\Services\Volunteer\Meetings;
use AfricaGates\Services\Volunteer\Training;
use AfricaGates\Services\Volunteer\Workspace;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE BELL: UPDATES, GROUPED BY WHAT THEY ASK OF YOU (handoff §2.4; §5 "Bell = Updates: May
 * affect the night (red), then Waiting on others. There is no production pill").
 *
 * Every update is DERIVED from a record on each request — a late blocker, a request asking for
 * information, a meeting to answer — never a stored notification. So nothing has to be
 * marked read to stop being true, and nothing lingers after the thing it describes is fixed:
 * the update goes when its cause does. The count on the bell is the count of things that need
 * the person (the first groups), never of what merely waits on somebody else, and it is never
 * drawn as 0.
 *
 * @phpstan-type Group array{h:string, tone:string, items:list<array{t:string, sub:string, href:string}>}
 */
final class Updates
{
    /** @return array{n:int, groups:list<array>} */
    public static function forLead(array $me, array $events): array
    {
        $ids = LeadPages::ids($events);
        $byId = [];
        foreach ($events as $e) $byId[(int) $e->id] = $e;
        $night = [];
        foreach (Plan::blockers($ids) as $w) {
            $night[] = ['t' => $w->title . ' is late', 'sub' => $byId[(int) $w->event_id]->title . ($w->blocks_note ? ' · ' . $w->blocks_note . ' waits on it' : ''),
                        'href' => EventPages::url((int) $w->event_id, 'plan')];
        }
        foreach (Plan::risks($ids) as $r) {
            if ($r->level !== 'high') continue;
            $night[] = ['t' => (string) $r->title, 'sub' => $byId[(int) $r->event_id]->title . ' · high risk', 'href' => EventPages::url((int) $r->event_id, 'plan', 'risks')];
        }
        foreach ($events as $e) {
            $days = (strtotime((string) $e->event_date) - time()) / 86400;
            if ($days < 0 || $days > 3) continue;
            $open = count(array_filter(Scope::checks((int) $e->id), static fn ($c) => $c->done_at === null));
            if ($open > 0) $night[] = ['t' => $open . ($open === 1 ? ' safety check' : ' safety checks') . ' not signed off', 'sub' => (string) $e->title,
                                       'href' => EventPages::url((int) $e->id, 'night', 'safety')];
        }
        $you = [];
        foreach (Requests::from(Plan::ACTOR, $me['id'], 'open', 'lead') as $r) {
            if ($r->status === 'needs_info') $you[] = ['t' => Requests::deciderLabel($r) . ' asked about “' . $r->title . '”', 'sub' => 'Add the information and it goes back to them',
                                                       'href' => LeadPages::BASE . '/requests?r=' . $r->id];
        }
        $limit = Plan::limitMinor();
        foreach (Plan::lines($ids) as $l) {
            if ($l->status === 'proposed' && (int) $l->estimate_minor <= $limit) {
                $you[] = ['t' => 'Approve ' . $l->title, 'sub' => $byId[(int) $l->event_id]->title . ' · ' . Plan::naira((int) $l->estimate_minor), 'href' => EventPages::url((int) $l->event_id, 'money')];
            }
        }
        $others = [];
        foreach (Requests::from(Plan::ACTOR, $me['id'], 'open', 'lead') as $r) {
            if ($r->status === 'waiting') $others[] = ['t' => (string) $r->title, 'sub' => 'With ' . Requests::deciderLabel($r) . ' since ' . date('j M', (int) strtotime((string) $r->created_at)),
                                                       'href' => LeadPages::BASE . '/requests?r=' . $r->id];
        }
        return self::shape([['May affect the night', 'bad', $night], ['Waiting on you', 'warn', $you], ['Waiting on others', 'mute', $others]], 2);
    }

    /** @return array{n:int, groups:list<array>} */
    public static function forVolunteer(object $v): array
    {
        $you = [];
        foreach (Requests::from('member', (int) $v->user_id, 'open', 'volunteer') as $r) {
            if ($r->status === 'needs_info') $you[] = ['t' => Requests::deciderLabel($r) . ' asked about “' . $r->title . '”', 'sub' => 'Add the information and it goes back to them',
                                                       'href' => '/volunteer/requests/' . $r->id];
        }
        $soon = [];
        foreach (Meetings::mine($v) as $m) {
            if ($m->answer === 'invited') $you[] = ['t' => $m->organiser_name . ' invited you to ' . $m->title, 'sub' => 'Say whether you can come', 'href' => '/volunteer/meetings/' . $m->id];
            $h = $m->next ? (strtotime((string) $m->next) - time()) / 3600 : -1;
            if ($h >= 0 && $h <= 48 && $m->answer !== 'no') $soon[] = ['t' => (string) $m->title, 'sub' => Workspace::until((string) $m->next) . ', ' . date('H:i', (int) strtotime((string) $m->next)),
                                                                        'href' => '/volunteer/meetings/' . $m->id];
        }
        foreach (Training::passed($v) as $p) {
            if (!$p->expires_at) continue;
            $d = (strtotime((string) $p->expires_at) - time()) / 86400;
            if ($d >= 0 && $d <= 14) $you[] = ['t' => $p->title . ' expires ' . Workspace::until((string) $p->expires_at), 'sub' => 'Take it again to keep the roles it unlocks',
                                               'href' => '/volunteer/training/take/' . $p->slug];
        }
        // A hand-logged hour a lead turned down says why, so it is an update until the next one.
        try {
            foreach (DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('status', 'declined')
                ->where('confirmed_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))->limit(5)->get() as $h) {
                $you[] = ['t' => 'Hours not confirmed: ' . $h->what, 'sub' => (string) ($h->reason ?? ''), 'href' => '/volunteer/hours/review'];
            }
        } catch (\Throwable) {
            // A column this install lacks is no update, not an error on every page.
        }
        return self::shape([['Needs you', 'warn', $you], ['Coming up', 'mute', $soon]], 1);
    }

    /**
     * @param list<array{0:string,1:string,2:list<array>}> $groups
     * @param int $counted how many of the first groups count towards the number on the bell
     */
    private static function shape(array $groups, int $counted): array
    {
        $out = [];
        $n = 0;
        foreach ($groups as $i => [$h, $tone, $items]) {
            if ($items === []) continue;
            if ($i < $counted) $n += count($items);
            $out[] = ['h' => $h, 'tone' => $tone, 'items' => array_slice($items, 0, 8)];
        }
        return ['n' => $n, 'groups' => $out];
    }
}
