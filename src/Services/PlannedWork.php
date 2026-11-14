<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Planned work, as /status announces it (Phase 9, StatusPageV2's "Planned:" banner).
 *
 * ── WHY A SETTING AND NOT A TABLE ────────────────────────────────────────────
 * There is no shell on production, so anything operational is settable from
 * /admin/settings (CLAUDE.md). One window at a time is what an operator announces — "card
 * payments will pause between one and two" — so it is four settings under one prefix, and
 * this class is their ONE reader: the status page, /status.json and the settings form all
 * ask {@see current()}, never `gates_settings` directly (OneResolverPerSettingTest).
 *
 * ── WHEN IT SHOWS ────────────────────────────────────────────────────────────
 * From {@see LEAD_DAYS} before it starts until it ends, then never: a banner an operator
 * has to remember to take down is a banner still up next month, so the END decides it. A
 * window with no title, an unparseable time or an end before its start is not shown —
 * half a notice reads as the platform not knowing its own plans.
 */
final class PlannedWork
{
    public const KEYS = ['title' => 'status_planned_title', 'from' => 'status_planned_from',
                         'to' => 'status_planned_to', 'note' => 'status_planned_note',
                         'close' => 'status_planned_close'];
    public const LEAD_DAYS = 7;
    /**
     * Hours before it starts that the notice is on EVERY public page (SiteNotice), not only
     * /status. A week on /status is right for people who go looking; a week on every page is a
     * banner people stop reading by the day it matters.
     */
    public const SITE_LEAD_HOURS = 72;

    /**
     * @param array<string,string>|null $settings injectable; read from gates_settings otherwise
     * @return array{title:string, from:string, to:string, note:string, minutes:int, started:bool, close:bool, site:bool}|null
     */
    public static function current(?array $settings = null): ?array
    {
        $v = self::stored($settings);
        if ($v['title'] === '' || $v['from'] === '' || $v['to'] === '') return null;
        try {
            $from = Carbon::parse($v['from']);
            $to   = Carbon::parse($v['to']);
        } catch (\Throwable) {
            return null;
        }
        if ($to->lte($from)) return null;
        $now = Carbon::now();
        if ($now->gte($to) || $now->lt($from->copy()->subDays(self::LEAD_DAYS))) return null;

        return [
            'title'   => $v['title'],
            'from'    => $from->toDateTimeString(),
            'to'      => $to->toDateTimeString(),
            'note'    => $v['note'],
            'minutes' => intdiv($to->getTimestamp() - $from->getTimestamp(), 60),
            'started' => $now->gte($from),
            // The operator chose to CLOSE the public site while it runs (MaintenanceGate):
            // visitors get the planned-work page, staff and the machinery keep working.
            'close'   => $v['close'] === '1',
            'site'    => $now->gte($from->copy()->subHours(self::SITE_LEAD_HOURS)),
        ];
    }

    /**
     * What is stored, normalised, for the settings form to show back.
     *
     * @param array<string,string>|null $settings
     * @return array{title:string, from:string, to:string, note:string, close:string}
     */
    public static function stored(?array $settings = null): array
    {
        if ($settings === null) {
            try {
                $settings = DB::table('gates_settings')->whereIn('key_name', array_values(self::KEYS))
                    ->pluck('value', 'key_name')->map(fn ($x) => (string) $x)->all();
            } catch (\Throwable) {
                $settings = [];
            }
        }
        $out = [];
        foreach (self::KEYS as $k => $key) $out[$k] = trim((string) ($settings[$key] ?? ''));
        return $out;
    }

    /**
     * Normalise what the settings form posted, ready to store. A `T`-separated time is
     * stored with a space: SQLite keeps the string verbatim, and a `2026-01-01T09:00`
     * compared against a space-separated clock reads wrong (CLAUDE.md, the stack section).
     *
     * @param array<string,mixed> $posted
     * @return array<string,string> setting key => value
     */
    public static function fromPost(array $posted): array
    {
        $out = [];
        foreach (self::KEYS as $k => $key) {
            $v = trim((string) ($posted[$key] ?? ''));
            // A checkbox posts nothing when unticked, so its absence IS the answer here: the
            // section always posts its title, which is how the caller knows it was submitted.
            if ($k === 'close') { $out[$key] = $v !== '' && $v !== '0' ? '1' : '0'; continue; }
            if (($k === 'from' || $k === 'to') && $v !== '') {
                // Typed in the display zone, stored in UTC — the pair to `when_input` on the
                // form. Parsed as UTC it moved the window an hour on every save.
                $v = \AfricaGates\Support\DisplayTime::toStored(str_replace('T', ' ', $v)) ?? '';
            }
            $out[$key] = mb_substr($v, 0, $k === 'note' ? 400 : 120);
        }
        return $out;
    }
}
