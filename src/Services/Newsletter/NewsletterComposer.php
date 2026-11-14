<?php
declare(strict_types=1);

namespace AfricaGates\Services\Newsletter;

use AfricaGates\Services\CyclePhase;
use AfricaGates\Services\CyclePolicy;
use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\PublicResults;
use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What an issue says, read off what the site is doing right now.
 *
 * ── NOTHING HERE IS WRITTEN, IT IS ALL LOOKED UP ─────────────────────────────
 *
 * Every line is a fact a public page already states — a cycle's own windows, a released
 * edition, a published event, a live challenge — with a link to the page that states it.
 * So an issue cannot promise a date the award page does not, and an operator approving
 * one is checking a summary, not proof-reading copy somebody typed at midnight.
 *
 * ── THE SAME DEFINITIONS AS THE PAGES IT LINKS TO ────────────────────────────
 *
 *   phase       {@see CyclePolicy::phaseFor()}, computed from the windows, never the
 *               status column, which is a cache;
 *   released    {@see PublicResults::RELEASED} — a results DATE is a promise, not an
 *               announcement, so a cycle three days past its date and still judging is
 *               not "results are out" here any more than it is on /results;
 *   sandbox     {@see DemoSeeder::notSandbox()} on every cycle query. The rehearsal is
 *               seeded with real-looking windows precisely so it shows a usable ballot,
 *               which is exactly the shape this composer selects;
 *   challenges  the statuses `/challenges` lists, and never the prize — a prize is stated
 *               on the challenge's own page, beside its terms.
 *
 * ── ITEM KEYS ARE THE DIFFERENCE BETWEEN NEWS AND REPETITION ────────────────
 *
 * Each item carries a key that names the THING and its STATE (`closing:12`, `result:9`,
 * `event:44`). The issue's fingerprint is those keys, so an issue whose keys are exactly
 * the last sent issue's has nothing to say — and is skipped rather than sent. An item
 * whose key was not in the last issue is marked new.
 */
final class NewsletterComposer
{
    /** "Closing soon" means inside a week. Further out, it is simply open. */
    public const CLOSING_DAYS = 7;
    /** How far ahead events and opening nominations are worth a line. */
    public const AHEAD_DAYS = 45;
    /** Per section. An issue is a summary; the page behind each link is the list. */
    public const PER_SECTION = 6;

    public const SECTIONS = [
        'closing'     => 'Voting closes soon',
        'results'     => 'Results announced',
        'nominations' => 'Nominations open',
        'voting'      => 'Voting open',
        'challenges'  => 'Challenges',
        'events'      => 'Events',
        'soon'        => 'Coming up',
    ];

    /**
     * Compose an issue.
     *
     * @param Carbon      $since results released after this count as news
     * @param list<string> $previousKeys the item keys of the last SENT issue
     * @return array{sections:list<array{key:string,title:string,items:list<array<string,mixed>>}>,
     *               keys:list<string>, fingerprint:string, subject:string, preheader:string}
     */
    public static function compose(Carbon $now, Carbon $since, array $previousKeys = []): array
    {
        $buckets = array_fill_keys(array_keys(self::SECTIONS), []);

        foreach (self::cycles() as $c) self::placeCycle($c, $now, $since, $buckets);
        $buckets['challenges'] = self::challenges($now);
        $buckets['events']     = self::events($now);

        $prev = array_flip($previousKeys);
        $sections = $keys = [];
        foreach (self::SECTIONS as $k => $title) {
            $items = array_slice($buckets[$k], 0, self::PER_SECTION);
            if ($items === []) continue;
            foreach ($items as &$it) {
                $it['new'] = !isset($prev[$it['key']]);
                $keys[] = $it['key'];
            }
            unset($it);
            $sections[] = ['key' => $k, 'title' => $title, 'items' => $items];
        }
        sort($keys);

        [$subject, $preheader] = self::headline($sections);

        return [
            'sections'    => $sections,
            'keys'        => $keys,
            'fingerprint' => hash('sha256', implode('|', $keys)),
            'subject'     => $subject,
            'preheader'   => $preheader,
        ];
    }

    /** @return list<object> live, non-sandbox cycles with their programme */
    private static function cycles(): array
    {
        try {
            $q = DB::table('gates_award_cycles as cy')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('p.is_active', 1)
                ->where('cy.status', '!=', 'archived');
            DemoSeeder::notSandbox($q, 'cy.programme_id');
            return $q->orderBy('p.sort_order')->orderBy('cy.id')
                ->get(['cy.*', 'p.title as programme', 'p.slug as programme_slug'])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,list<array<string,mixed>>> $b */
    private static function placeCycle(object $c, Carbon $now, Carbon $since, array &$b): void
    {
        $id    = (int) $c->id;
        $name  = self::cycleName($c);
        $slug  = (string) $c->programme_slug;
        $phase = CyclePolicy::phaseFor($c, $now);

        if (in_array((string) $c->status, PublicResults::RELEASED, true)) {
            if (self::releasedSince($c, $since)) {
                $b['results'][] = self::item("result:$id", $name,
                    'The standing is published, with the working behind every placing.',
                    PublicResults::editionUrl($slug, (int) $c->year), 'See the results');
            }
            return;
        }

        if ($phase === CyclePhase::Voting && $c->voting_close) {
            $close   = Carbon::parse((string) $c->voting_close);
            $closing = $close->lte($now->copy()->addDays(self::CLOSING_DAYS));
            $b[$closing ? 'closing' : 'voting'][] = self::item(
                ($closing ? 'closing:' : 'voting:') . $id, $name,
                'Voting closes ' . DisplayTime::showZoned($close) . '.',
                '/vote/' . rawurlencode($slug), 'Vote');
            return;
        }
        if ($phase === CyclePhase::Voting) {
            $b['voting'][] = self::item("voting:$id", $name, 'Voting is open.', '/vote/' . rawurlencode($slug), 'Vote');
            return;
        }

        if ($phase === CyclePhase::Nominations) {
            $line = $c->nominations_close
                ? 'Nominations close ' . DisplayTime::showZoned((string) $c->nominations_close) . '.'
                : 'Nominations are open.';
            $b['nominations'][] = self::item("nominations:$id", $name, $line,
                '/awards/' . rawurlencode($slug), 'Nominate');
            return;
        }

        if ($phase === CyclePhase::Upcoming && $c->nominations_open) {
            $open = Carbon::parse((string) $c->nominations_open);
            if ($open->gt($now) && $open->lte($now->copy()->addDays(self::AHEAD_DAYS))) {
                $b['soon'][] = self::item("soon:$id", $name,
                    'Nominations open ' . DisplayTime::showZoned($open) . '.',
                    '/awards/' . rawurlencode($slug), 'About the award');
            }
        }
    }

    /**
     * Released since the last issue — from the transition ledger where it recorded the
     * announcement, and from the results date only where it holds no row at all (a cycle
     * released before the ledger existed). A row marked `notify = 0` is a release the
     * platform deliberately did not announce, and an issue is an announcement.
     */
    private static function releasedSince(object $c, Carbon $since): bool
    {
        try {
            $q = DB::table('gates_cycle_transitions')->where('cycle_id', (int) $c->id)
                ->where('to_status', 'results');
            $rows = (clone $q)->count();
            if ($rows > 0) {
                if (SchemaHas::column('gates_cycle_transitions', 'notify')) $q->where('notify', 1);
                return $q->where('created_at', '>=', $since->toDateTimeString())->exists();
            }
        } catch (\Throwable) {
            // No ledger table: fall through to the date.
        }
        return $c->results_date !== null
            && Carbon::parse((string) $c->results_date)->gte($since);
    }

    /** @return list<array<string,mixed>> */
    private static function challenges(Carbon $now): array
    {
        $out = [];
        try {
            $rows = DB::table('gates_challenges')->whereIn('status', [E::ST_OPEN, E::ST_UPCOMING])
                ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")->orderBy('ends_at')
                ->limit(self::PER_SECTION)->get();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $ch) {
            $open = (string) $ch->status === E::ST_OPEN;
            if (!$open && $ch->starts_at
                && Carbon::parse((string) $ch->starts_at)->gt($now->copy()->addDays(self::AHEAD_DAYS))) {
                continue;
            }
            $line = $open
                ? ($ch->ends_at ? 'Open until ' . DisplayTime::showZoned((string) $ch->ends_at) . '.' : 'Open now.')
                : ($ch->starts_at ? 'Starts ' . DisplayTime::showZoned((string) $ch->starts_at) . '.' : 'Starting soon.');
            $kicker = trim((string) ($ch->kicker ?? ''));
            $out[] = self::item(($open ? 'challenge:' : 'challenge-soon:') . (int) $ch->id,
                (string) $ch->title, ($kicker !== '' ? $kicker . ' · ' : '') . $line,
                '/challenges/' . rawurlencode((string) $ch->slug), 'Take part');
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function events(Carbon $now): array
    {
        $out = [];
        try {
            $rows = DB::table('gates_site_events')->where('status', 'published')
                ->where('event_date', '>=', $now->toDateTimeString())
                ->where('event_date', '<=', $now->copy()->addDays(self::AHEAD_DAYS)->toDateTimeString())
                ->orderBy('event_date')->limit(self::PER_SECTION)->get();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $e) {
            $venue = trim((string) ($e->venue ?? ''));
            $out[] = self::item('event:' . (int) $e->id, (string) $e->title,
                DisplayTime::showZoned((string) $e->event_date) . ($venue !== '' ? ' · ' . $venue : ''),
                '/events/' . rawurlencode((string) $e->slug), 'Details');
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function item(string $key, string $title, string $line, string $path, string $cta): array
    {
        return ['key' => $key, 'title' => $title, 'line' => $line, 'path' => $path, 'cta' => $cta];
    }

    private static function cycleName(object $c): string
    {
        $label = trim((string) ($c->edition_label ?? ''));
        $prog  = trim((string) $c->programme);
        // An edition labelled with its bare year reads as one name, "… Awards 2026".
        if ($label !== '' && ctype_digit($label)) $label = '';
        if ($label !== '' && !str_contains(mb_strtolower($prog), mb_strtolower($label))) {
            return $prog . ' · ' . $label;
        }
        return $prog . ($label === '' && (int) ($c->year ?? 0) > 0 && !str_contains($prog, (string) $c->year)
            ? ' ' . (int) $c->year : '');
    }

    /**
     * Subject and preheader, from the most time-sensitive thing in the issue.
     *
     * The order is the reader's, not the platform's: a vote that closes on Friday is worth
     * more to them than an event next month, so it leads whenever there is one.
     *
     * @param list<array{key:string,title:string,items:list<array<string,mixed>>}> $sections
     * @return array{0:string,1:string}
     */
    private static function headline(array $sections): array
    {
        if ($sections === []) return ['Africa GATES', ''];

        $lead = $sections[0]['items'][0];
        $subject = match ($sections[0]['key']) {
            'closing'     => 'Voting closes soon: ' . $lead['title'],
            'results'     => 'Results announced: ' . $lead['title'],
            'nominations' => 'Nominations open: ' . $lead['title'],
            'voting'      => 'Voting open: ' . $lead['title'],
            'soon'        => 'Coming up: ' . $lead['title'],
            default       => $lead['title'],
        };

        $rest = [];
        foreach ($sections as $s) {
            foreach ($s['items'] as $it) {
                if ($it['key'] !== $lead['key']) $rest[] = $it['title'];
            }
        }
        $pre = match (true) {
            $rest === []      => $lead['line'],
            count($rest) === 1 => 'Also: ' . $rest[0] . '.',
            default           => 'Also: ' . $rest[0] . ', ' . $rest[1]
                                 . (count($rest) > 2 ? ' and ' . (count($rest) - 2) . ' more.' : '.'),
        };

        return [mb_substr($subject, 0, 200), mb_substr($pre, 0, 250)];
    }
}
