<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The agenda: sessions as rows, grouped into the day somebody reads.
 *
 * ── WHY THE JSON BLOB WAS NOT ENOUGH ─────────────────────────────────────────
 *
 * `gates_site_events.schedule` is a list of `{time, title, body}` the detail page prints.
 * That is a perfectly good run of show for a two-hour webinar, and it stays — see
 * {@see legacy()} — because an event whose agenda is three lines should not need a second
 * screen to say so.
 *
 * It stops working the moment an event has more than one room. A blob cannot be grouped by
 * day, filtered by track, or ordered by anything other than the order somebody happened to
 * type it in; two parallel sessions at 14:00 read as a contradiction rather than a choice.
 * And nothing can ever be attached to a line in a blob — not a speaker, not a room, not a
 * capacity, which is the next thing an organiser asks for.
 *
 * ── WHAT THE GROUPING IS FOR ─────────────────────────────────────────────────
 *
 * {@see days()} returns sessions grouped by calendar day and sorted within it. That is not
 * presentation dressing: a multi-day conference printed as one flat list is unreadable, and
 * a template that tried to group it would be doing date arithmetic in Twig, where an
 * undated session silently becomes 1 January 1970.
 *
 * Undated sessions are kept, in their own group, at the end. An organiser drafting an agenda
 * types the titles first and the times later, and losing the titles in between would make the
 * editor useless exactly when it is being used.
 */
final class EventAgenda
{
    /**
     * Every published session for an event, in reading order.
     *
     * @return list<array<string,mixed>>
     */
    public static function sessions(int $eventId, bool $includeDrafts = false): array
    {
        try {
            $q = DB::table('gates_event_sessions')->where('event_id', $eventId);
            if (!$includeDrafts) $q->where('is_published', 1);
            $rows = $q->orderBy('sort_order')->orderBy('starts_at')->orderBy('id')->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(static function ($s): array {
            $starts = trim((string) ($s->starts_at ?? ''));
            $ends   = trim((string) ($s->ends_at ?? ''));
            return [
                'id'          => (int) $s->id,
                'title'       => (string) $s->title,
                'description' => (string) ($s->description ?? ''),
                'starts_at'   => $starts !== '' ? $starts : null,
                'ends_at'     => $ends !== '' ? $ends : null,
                'room'        => trim((string) ($s->room ?? '')),
                'track'       => trim((string) ($s->track ?? '')),
                'speakers'    => self::speakers($s),
                'published'   => (int) ($s->is_published ?? 1) === 1,
                'sort_order'  => (int) ($s->sort_order ?? 0),
                'when'        => self::when($starts, $ends),
            ];
        })->all();
    }

    /**
     * The same sessions, grouped into days.
     *
     * @return list<array{key:string, label:string, sessions:list<array<string,mixed>>}>
     */
    public static function days(int $eventId, bool $includeDrafts = false): array
    {
        $groups = [];
        foreach (self::sessions($eventId, $includeDrafts) as $s) {
            // An undated session groups under '' and is sorted to the end below, rather than
            // being dropped: an organiser types the titles first and the times later.
            $key = $s['starts_at'] !== null ? substr((string) $s['starts_at'], 0, 10) : '';
            $groups[$key][] = $s;
        }

        $keys = array_keys($groups);
        sort($keys);
        // '' sorts first by string comparison and belongs last, because "we have not said
        // when yet" is a footnote to a schedule, not its opening.
        if (($i = array_search('', $keys, true)) !== false) {
            unset($keys[$i]);
            $keys[] = '';
        }

        $out = [];
        foreach ($keys as $key) {
            $out[] = [
                'key'      => (string) $key,
                'label'    => $key === '' ? 'Timings to be confirmed' : self::dayLabel((string) $key),
                'sessions' => $groups[$key],
            ];
        }
        return $out;
    }

    /** The distinct tracks in use, so a filter can offer exactly the ones that exist. */
    public static function tracks(int $eventId): array
    {
        $seen = [];
        foreach (self::sessions($eventId) as $s) {
            if ($s['track'] !== '' && !in_array($s['track'], $seen, true)) $seen[] = $s['track'];
        }
        return $seen;
    }

    /**
     * The old JSON run of show, for events that never used sessions.
     *
     * Read only when there are no session rows, so an organiser who moves to sessions does
     * not see their agenda twice — and one who never does keeps the page they had.
     *
     * @return list<array<string,mixed>>
     */
    public static function legacy(?object $event): array
    {
        if ($event === null) return [];
        $raw = json_decode((string) ($event->schedule ?? '[]'), true);
        return is_array($raw) ? array_values($raw) : [];
    }

    /** @return list<string> */
    private static function speakers(object $s): array
    {
        $raw = trim((string) ($s->speakers ?? ''));
        if ($raw === '') return [];
        // Stored as a comma-separated line because that is how an organiser types it. A JSON
        // array would be more correct and would also mean a form field nobody can fill in
        // without knowing what a JSON array is.
        return array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', $raw)
        ), static fn (string $p): bool => $p !== ''));
    }

    private static function when(string $starts, string $ends): string
    {
        if ($starts === '') return '';
        try {
            $a = Carbon::parse($starts);
            if ($ends === '') return $a->format('H:i');
            $b = Carbon::parse($ends);
            // Same day: one date, two times. Across days (an overnight session, a two-day
            // workshop) both dates, or the reader cannot tell which day it ends on.
            return $a->format('Y-m-d') === $b->format('Y-m-d')
                ? $a->format('H:i') . ' – ' . $b->format('H:i')
                : $a->format('j M H:i') . ' – ' . $b->format('j M H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function dayLabel(string $ymd): string
    {
        try { return Carbon::parse($ymd)->format('l j F Y'); }
        catch (\Throwable) { return $ymd; }
    }

    // ══ the organiser's side ═════════════════════════════════════════════════

    /**
     * Replace an event's sessions with what the editor posted.
     *
     * Upsert by id rather than delete-and-reinsert: the ids are what a future feature attaches
     * to (a per-session capacity, a check-in, a recording), and reissuing them on every save
     * would silently reassign whatever had been attached.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{saved:int, removed:int}
     */
    public static function save(int $eventId, array $rows): array
    {
        $now  = Carbon::now()->toDateTimeString();
        $kept = [];
        $saved = 0;

        foreach ($rows as $i => $r) {
            $title = trim((string) ($r['title'] ?? ''));
            // A blank title is an empty row from the editor, not a session. Skipped silently
            // because the editor always renders one spare.
            if ($title === '') continue;

            $data = [
                'event_id'    => $eventId,
                'title'       => mb_substr($title, 0, 200),
                'description' => trim((string) ($r['description'] ?? '')) ?: null,
                'starts_at'   => self::stamp($r['starts_at'] ?? null),
                'ends_at'     => self::stamp($r['ends_at'] ?? null),
                'room'        => mb_substr(trim((string) ($r['room'] ?? '')), 0, 120) ?: null,
                'track'       => mb_substr(trim((string) ($r['track'] ?? '')), 0, 80) ?: null,
                'speakers'    => mb_substr(trim((string) ($r['speakers'] ?? '')), 0, 500) ?: null,
                'sort_order'  => (int) ($r['sort_order'] ?? ($i * 10)),
                'is_published'=> !empty($r['is_published']) ? 1 : 0,
                'updated_at'  => $now,
            ];

            $id = (int) ($r['id'] ?? 0);
            try {
                if ($id > 0 && DB::table('gates_event_sessions')
                        ->where('id', $id)->where('event_id', $eventId)->exists()) {
                    DB::table('gates_event_sessions')->where('id', $id)->update($data);
                } else {
                    $id = (int) DB::table('gates_event_sessions')
                        ->insertGetId($data + ['created_at' => $now]);
                }
                $kept[] = $id;
                $saved++;
            } catch (\Throwable $e) {
                error_log('[agenda] could not save a session for event ' . $eventId . ': ' . $e->getMessage());
            }
        }

        // Anything not in the post is gone. Deleted rather than unpublished, because the
        // editor shows unpublished rows too — an organiser who removes a row and still sees
        // it would remove it again.
        $removed = 0;
        try {
            $q = DB::table('gates_event_sessions')->where('event_id', $eventId);
            if ($kept !== []) $q->whereNotIn('id', $kept);
            $removed = (int) $q->delete();
        } catch (\Throwable) {}

        return ['saved' => $saved, 'removed' => $removed];
    }

    /** A datetime-local value from a form, or null. Never a half-parsed guess. */
    private static function stamp(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') return null;
        try { return Carbon::parse(str_replace('T', ' ', $s))->toDateTimeString(); }
        catch (\Throwable) { return null; }
    }
}
