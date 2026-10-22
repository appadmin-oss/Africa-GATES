<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Support\ChallengeEnum;
use AfricaGates\Support\EditionName;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Every dated thing the console runs, in one list (AdminConsole v2, README §10b).
 *
 * Home's "This week" and the Calendar page read this and nothing else, so the two can
 * never disagree about what is due. A date is only listed when it is STORED — a cycle's
 * five boundaries, an event's day and its early-bird deadline, a challenge's open, close
 * and draw. Nothing is inferred: a programme with no voting close set has no "voting
 * closes" entry, rather than one on a guessed day, because a calendar entry is a promise
 * somebody plans around.
 *
 * Each entry links to the page where it is managed, and an entry whose page this role
 * cannot open is left out ({@see Permissions::canOpen()}, the guard's own function).
 * Programmes switched off are left out too: the sandbox is one, and its rehearsal dates
 * are not the operator's week.
 */
final class ConsoleCalendar
{
    /** The areas an entry can belong to, which the Calendar page filters on. */
    public const AREAS = ['awards' => 'Awards', 'events' => 'Events', 'challenges' => 'Challenges'];

    /**
     * Entries between two moments, earliest first.
     *
     * @return list<array{at:string, area:string, title:string, sub:string, href:string, tone:string}>
     */
    public static function between(int $from, int $to, string $role): array
    {
        $lo = date('Y-m-d H:i:s', $from);
        $hi = date('Y-m-d H:i:s', $to);
        $out = [];

        foreach (self::cycles($lo, $hi) as $e) $out[] = $e;
        foreach (self::events($lo, $hi) as $e) $out[] = $e;
        foreach (self::challenges($lo, $hi) as $e) $out[] = $e;

        $out = array_values(array_filter($out, static fn (array $e): bool => Permissions::canOpen($role, $e['href'])));
        usort($out, static fn (array $a, array $b): int => [$a['at'], $a['title']] <=> [$b['at'], $b['title']]);
        return $out;
    }

    /** A date column inside the window, normalised to `Y-m-d H:i:s`, or null. */
    private static function within(?string $v, string $lo, string $hi): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        $t = strtotime(str_replace('T', ' ', $v));
        if ($t === false) return null;
        $at = date('Y-m-d H:i:s', $t);
        return ($at >= $lo && $at <= $hi) ? $at : null;
    }

    /** @return list<array<string,string>> */
    private static function cycles(string $lo, string $hi): array
    {
        $out = [];
        try {
            $rows = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)
                ->where('c.status', '!=', 'archived')
                ->get(['c.*', 'p.title as programme']);
        } catch (\Throwable) {
            return [];
        }
        $marks = [
            'nominations_open'  => ['Nominations open', 'green'],
            'nominations_close' => ['Nominations close', 'gold'],
            'voting_open'       => ['Voting opens', 'green'],
            'voting_close'      => ['Voting closes', 'gold'],
            'results_date'      => ['Results due', 'red'],
        ];
        foreach ($rows as $c) {
            foreach ($marks as $col => [$what, $tone]) {
                $at = self::within($c->{$col} ?? null, $lo, $hi);
                if ($at === null) continue;
                $out[] = ['at' => $at, 'area' => 'awards', 'title' => (string) $c->programme . ': ' . lcfirst($what),
                          'sub' => EditionName::full($c), 'href' => '/admin/programmes/' . (int) $c->programme_id, 'tone' => $tone];
            }
        }
        return $out;
    }

    /** @return list<array<string,string>> */
    private static function events(string $lo, string $hi): array
    {
        $out = [];
        try {
            $rows = DB::table('gates_site_events')->where('status', 'published')->get();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $e) {
            $where = trim((string) ($e->venue ?? '') ?: (string) ($e->location ?? ''));
            if ($at = self::within($e->event_date ?? null, $lo, $hi)) {
                $out[] = ['at' => $at, 'area' => 'events', 'title' => (string) $e->title,
                          'sub' => $where !== '' ? $where : 'Event day', 'href' => '/admin/events/' . (int) $e->id, 'tone' => 'green'];
            }
            if ($at = self::within($e->early_bird_deadline ?? null, $lo, $hi)) {
                $out[] = ['at' => $at, 'area' => 'events', 'title' => (string) $e->title . ': early-bird price ends',
                          'sub' => 'Tickets', 'href' => '/admin/events/' . (int) $e->id, 'tone' => 'gold'];
            }
        }
        return $out;
    }

    /** @return list<array<string,string>> */
    private static function challenges(string $lo, string $hi): array
    {
        $out = [];
        try {
            $rows = DB::table('gates_challenges')->whereIn('status', [ChallengeEnum::ST_OPEN, ChallengeEnum::ST_UPCOMING])->get();
        } catch (\Throwable) {
            return [];
        }
        $marks = ['starts_at' => ['opens', 'green'], 'ends_at' => ['closes', 'gold'], 'draw_at' => ['draw', 'red']];
        foreach ($rows as $c) {
            foreach ($marks as $col => [$what, $tone]) {
                $at = self::within($c->{$col} ?? null, $lo, $hi);
                if ($at === null) continue;
                $out[] = ['at' => $at, 'area' => 'challenges', 'title' => (string) $c->title . ': ' . $what,
                          'sub' => 'Challenge', 'href' => '/admin/challenges/' . (int) $c->id, 'tone' => $tone];
            }
        }
        return $out;
    }
}
