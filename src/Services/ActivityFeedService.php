<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Everything that has happened on this platform, as one searchable timeline.
 *
 * The activity a visitor can already see is scattered across seven places: a
 * nominee appears in the registry, a result on the leaderboard, a post on the blog,
 * an event on its own page, a discussion in the community, a phase change nowhere at
 * all. "Did voting open for the music award yet?" and "was the person I nominated
 * approved?" are the two questions people actually ask, and neither had an answer
 * short of visiting several pages and comparing dates.
 *
 * ── FRESHNESS ────────────────────────────────────────────────────────────────
 *
 * A SEARCH is never cached. It reads the tables on every request, so a nominee
 * approved a second ago is findable a second later. That is the whole point of the
 * feature and caching it would quietly break the promise.
 *
 * The UNFILTERED latest-activity view IS cached, for {@see LATEST_TTL} seconds. It is
 * byte-identical for every visitor, so at continental traffic an uncached version
 * would be the same seven queries repeated for every arrival with no different
 * answer. Fifteen seconds is the honest cost of that, stated rather than hidden — and
 * a search, which is the part that has to be live, bypasses it entirely.
 *
 * The load a live search implies is bounded by the caller, not here: a minimum query
 * length, a client-side debounce and a rate limit
 * ({@see \AfricaGates\Controllers\ActivityController}). Every query below is
 * LIMITed and ordered on an indexed column.
 *
 * ── WHAT IS NOT IN IT ────────────────────────────────────────────────────────
 *
 * Individual votes. A public timeline of "someone voted for X at 14:03" is a
 * de-anonymisation surface — cross-referenced with a share link or a social post it
 * identifies who voted for whom — and it is exactly the trace this platform's
 * integrity model depends on NOT publishing. Vote activity appears only as an
 * aggregate on the leaderboard.
 *
 * Nothing pending, rejected or unpublished. Nothing carrying an email address, a
 * phone number or an IP. Every row here is already visible on some public page; this
 * only puts them in one order.
 */
final class ActivityFeedService
{
    /** Cache window for the unfiltered feed. Searches never touch it. */
    public const LATEST_TTL = 15;

    /** Hard ceiling on rows returned, whatever the caller asks for. */
    public const MAX_LIMIT = 60;

    /** Shortest query worth running seven table scans for. */
    public const MIN_QUERY = 2;

    /** Kinds a caller may filter to. The whitelist the AI's answer is checked against. */
    public const KINDS = ['nominee', 'result', 'post', 'event', 'thread', 'profile', 'phase'];

    /**
     * @param bool $interpret Whether to let a model read the query for intent. Off by
     *                        default so every existing caller is unchanged and the
     *                        no-AI behaviour stays the baseline rather than the
     *                        degraded path.
     * @return array{items: list<array>, query:string, live:bool, sources:int, understood:?array}
     */
    public function search(?string $query = null, int $limit = 20, bool $interpret = false): array
    {
        $q     = trim((string) $query);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // A one-character query matches most of the register, so it is treated as no
        // query at all rather than as a very expensive way to get the latest feed.
        if (mb_strlen($q) < self::MIN_QUERY) {
            return $this->latest($limit) + ['query' => '', 'live' => false, 'understood' => null];
        }

        // INTENT FIRST, TEXT ALWAYS. The interpretation only ever ADDS filters; the
        // literal text match runs regardless. So a model that misreads "winners in Ghana"
        // cannot make the search return less than the plain-text version would have —
        // the worst case is that a filter is ignored, not that results vanish.
        $understood = $interpret ? $this->interpret($q) : null;

        $result = $this->collect($understood['terms'] ?? $q, $limit, $understood);

        return $result + ['query' => $q, 'live' => true, 'understood' => $understood];
    }

    /**
     * Read a plain-English query for intent: which kinds, which country, how recent.
     *
     * WHY THIS IS SAFE TO ADD. Every field the model returns is validated against a
     * whitelist in this file before it reaches a query — the same discipline
     * `AiFilterService` already applies to the admin filter parser. `kind` must be one
     * of {@see KINDS}; `country` must be two letters; `days` is clamped. The model
     * cannot introduce a table, a column, a value or an operator the code did not
     * already allow, so the worst a bad answer can do is filter to something the user
     * did not ask for — visibly, because the page states what it understood and offers
     * a link to search the literal text instead.
     *
     * WHAT IT IS FOR. "winners in Ghana" and "who joined this week" are how people
     * actually ask, and a LIKE across seven tables answers neither: "winners" is a
     * status, "this week" is a range, "Ghana" is a column. Without interpretation the
     * first returns nothing and the second returns every row containing the word "week".
     *
     * Returns null when unavailable, out of budget, or unparseable — and the caller
     * then behaves exactly as it did before this method existed.
     *
     * @return array{kinds:list<string>, country:?string, days:?int, terms:string, note:string}|null
     */
    private function interpret(string $q): ?array
    {
        $r = (new AiGateway())->run('search.interpret', [
            'system' => 'You turn a search query about an African awards platform into filters. '
                . 'Reply ONLY with JSON: {"kinds":[...],"country":"XX"|null,"days":<int>|null,"terms":"...","note":"..."}. '
                . 'kinds may ONLY contain: ' . implode(', ', self::KINDS) . '. '
                . 'Use "result" for winners or runners-up, "phase" for voting or nominations opening or closing, '
                . '"profile" for people who joined, "nominee" for people entered into a category. '
                . 'country is an ISO 3166-1 alpha-2 code or null. days is a lookback window or null. '
                . 'terms is the remaining words to match literally, and may be an empty string. '
                . 'note is a short plain-English restatement of what you understood, for the user to read. '
                . 'Omit any field you are unsure of. Never guess a country from a name.',
            'trusted' => 'Return filters for the query that follows.',
            'user'    => $q,
            'json'    => true,
            'schema'  => static function (string $raw): ?array {
                $j = json_decode($raw, true);
                if (!is_array($j)) return null;

                // Whitelist, not sanitise. An unrecognised kind is DROPPED rather than
                // mapped to a guess, because a wrong filter silently narrows a search.
                $kinds = [];
                foreach ((array) ($j['kinds'] ?? []) as $k) {
                    $k = strtolower(trim((string) $k));
                    if (in_array($k, self::KINDS, true)) $kinds[] = $k;
                }
                $kinds = array_values(array_unique($kinds));

                $country = null;
                if (is_string($j['country'] ?? null) && preg_match('/^[A-Za-z]{2}$/', trim($j['country']))) {
                    $country = strtoupper(trim($j['country']));
                }

                $days = null;
                if (isset($j['days']) && is_numeric($j['days'])) {
                    // Clamped. An unbounded lookback is just "everything" with extra steps.
                    $days = max(1, min(730, (int) $j['days']));
                }

                $terms = trim((string) ($j['terms'] ?? ''));
                $note  = mb_substr(trim(strip_tags((string) ($j['note'] ?? ''))), 0, 160);

                // Nothing usable understood is not a schema failure — it is a plain-text
                // search, which is the correct answer for a plain-text query.
                if ($kinds === [] && $country === null && $days === null) return null;

                return ['kinds' => $kinds, 'country' => $country, 'days' => $days, 'terms' => $terms, 'note' => $note];
            },
        ]);

        return ($r->ok && is_array($r->value)) ? $r->value : null;
    }

    /** The unfiltered feed. Identical for everyone, so briefly cached. */
    private function latest(int $limit): array
    {
        $cache = new CacheService();
        try {
            return $cache->remember(
                'activity:latest:' . $limit,
                self::LATEST_TTL,
                fn (): array => $this->collect(null, $limit),
                ['registry', 'leaderboard'],
            );
        } catch (\Throwable) {
            // A cache failure must not take the page down — it is an optimisation.
            return $this->collect(null, $limit);
        }
    }

    /**
     * Read every source, merge, sort by time, truncate.
     *
     * Each source is wrapped: a missing table or column on an older install returns
     * nothing from that source instead of 500-ing the page, matching how
     * PulseController already guards its queries. `sources` reports how many
     * answered, so "no activity" and "six of seven sources are unavailable" are
     * distinguishable.
     */
    private function collect(?string $q, int $limit, ?array $understood = null): array
    {
        $q = ($q === null || trim($q) === '') ? null : trim($q);

        $sources = [
            'nominee' => fn (): array => $this->nominees($q, $limit),
            'result'  => fn (): array => $this->results($q, $limit),
            'post'    => fn (): array => $this->posts($q, $limit),
            'event'   => fn (): array => $this->events($q, $limit),
            'thread'  => fn (): array => $this->threads($q, $limit),
            'profile' => fn (): array => $this->profiles($q, $limit),
            'phase'   => fn (): array => $this->transitions($q, $limit),
        ];

        // An interpreted `kinds` narrows WHICH SOURCES RUN, which is where the speed-up
        // is: "winners in Ghana" reads two tables instead of seven. `sources` still
        // counts only what was asked for, so a narrowed search does not look like six
        // unavailable sources.
        $wanted = $understood['kinds'] ?? [];
        if ($wanted !== []) {
            $sources = array_intersect_key($sources, array_flip($wanted));
        }

        $items = [];
        $ok    = 0;
        foreach ($sources as $source) {
            try {
                foreach ($source() as $item) $items[] = $item;
                $ok++;
            } catch (\Throwable) {
                // source unavailable on this install
            }
        }

        // Post-filters. Applied in PHP rather than pushed into seven queries because the
        // sources do not share a country column or a timestamp column name, and a filter
        // that silently did not apply to three of them would be worse than a slower one
        // that applies to all.
        $items = $this->applyFilters($items, $understood);

        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return ['items' => array_slice($items, 0, $limit), 'sources' => $ok];
    }

    /**
     * Country and recency, applied uniformly.
     *
     * The country test is against the item's own text, which is where the code is: a
     * nominee's country is rendered into `detail`, and a profile's too. That is a weaker
     * match than a column comparison and it is stated as such rather than dressed up —
     * the alternative is threading a country column through seven differently-shaped
     * queries, three of which do not have one.
     *
     * @param list<array> $items
     * @return list<array>
     */
    private function applyFilters(array $items, ?array $understood): array
    {
        if ($understood === null) return $items;

        $country = $understood['country'] ?? null;
        $days    = $understood['days'] ?? null;
        if ($country === null && $days === null) return $items;

        $cutoff = $days !== null ? Carbon::now()->subDays($days)->toDateTimeString() : null;

        return array_values(array_filter($items, static function (array $i) use ($country, $cutoff): bool {
            if ($cutoff !== null && ($i['at'] ?? '') !== '' && $i['at'] < $cutoff) return false;
            if ($country !== null) {
                // Word-boundary match on the two-letter code, so "NG" does not match
                // "NGO" or the "ng" inside a name.
                if (!preg_match('/\b' . preg_quote($country, '/') . '\b/i', (string) ($i['detail'] ?? ''))) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Apply a text filter across the given columns, or nothing when there is no query.
     *
     * `LIKE %term%` is not indexable, which is why MIN_QUERY and the LIMIT exist. It
     * is the right trade for this size of table and it is honest about what it costs;
     * a full-text index across seven tables would be a schema change on three files
     * (MySQL, SQLite parity, and a migration) for a search over at most a few tens of
     * thousands of rows.
     *
     * The term is bound as a parameter and the column list is a literal in this file —
     * never anything a caller supplies.
     *
     * AN EXPLICIT `ESCAPE` CLAUSE, because the drivers do not agree without one.
     * `%` and `_` are LIKE metacharacters, so an unescaped query of `%` matches every
     * row in seven tables — a one-character way past the minimum-length guard to dump
     * the timeline. Escaping them with a backslash is the obvious fix and it is
     * half-broken: MySQL treats `\` as the default escape character, SQLite does not.
     * Measured on both:
     *
     *     query "100%"   MySQL 1 row (correct)   SQLite 0 rows
     *     query "A_B"    MySQL 1 row (correct)   SQLite 0 rows
     *
     * So on SQLite a search for any name containing a percent sign or an underscore
     * silently returned nothing, and only the MySQL half of the guard actually worked
     * as escaping rather than as accidental over-blocking.
     *
     * `LIKE ? ESCAPE '!'` is identical on both, and `!` sidesteps the backslash
     * question entirely — it is not special to either driver, to PHP string literals,
     * or to the query builder.
     */
    private const LIKE_ESCAPE = '!';

    private function filter(mixed $builder, ?string $q, array $columns): mixed
    {
        if ($q === null || $q === '') return $builder;

        $e    = self::LIKE_ESCAPE;
        // The escape character itself must be escaped first, or a query containing
        // "!" would consume the character after it.
        $term = str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $q);
        $like = '%' . $term . '%';

        return $builder->where(function ($w) use ($columns, $like, $e) {
            foreach ($columns as $i => $col) {
                // The column is a literal from the call sites in this file; the term is
                // bound. `$e` is a class constant, not input.
                $sql = "{$col} LIKE ? ESCAPE '{$e}'";
                $i === 0 ? $w->whereRaw($sql, [$like]) : $w->orWhereRaw($sql, [$like]);
            }
        });
    }

    /** Nominees entered into the register. */
    private function nominees(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->whereNull('n.merged_into'),
            $q,
            ['n.name', 'n.tagline', 'c.title'],
        )
            ->orderByDesc('n.nominated_at')->limit($limit)
            ->get(['n.id', 'n.name', 'n.tagline', 'n.nominated_at', 'c.title as category'])->all();

        return array_map(fn (object $r): array => $this->item(
            kind:   'nominee',
            label:  'Nominee',
            title:  (string) $r->name,
            detail: trim(((string) ($r->category ?? '')) . (($r->tagline ?? '') !== '' ? ' · ' . $r->tagline : ''), ' ·'),
            url:    '/registry',
            at:     (string) ($r->nominated_at ?? ''),
        ), $rows);
    }

    /** Winners and runners-up — the outcome people come back for. */
    private function results(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.status', ['winner', 'runner_up'])
                ->whereNull('n.merged_into'),
            $q,
            ['n.name', 'c.title'],
        )
            ->orderByDesc('n.nominated_at')->limit($limit)
            ->get(['n.id', 'n.name', 'n.status', 'n.nominated_at', 'c.title as category'])->all();

        return array_map(fn (object $r): array => $this->item(
            kind:   'result',
            label:  $r->status === 'winner' ? 'Winner' : 'Runner-up',
            title:  (string) $r->name,
            detail: (string) ($r->category ?? ''),
            url:    '/leaderboard',
            at:     (string) ($r->nominated_at ?? ''),
        ), $rows);
    }

    private function posts(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_posts')->where('status', 'published'),
            $q,
            ['title', 'excerpt', 'tag'],
        )->orderByDesc('published_at')->limit($limit)
            ->get(['id', 'slug', 'title', 'excerpt', 'tag', 'published_at'])->all();

        return array_map(fn (object $r): array => $this->item(
            kind:   'post',
            label:  'Story',
            title:  (string) $r->title,
            detail: (string) ($r->excerpt ?? ''),
            url:    '/blog/' . ($r->slug ?? ''),
            at:     (string) ($r->published_at ?? ''),
        ), $rows);
    }

    private function events(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_site_events')->where('status', 'published'),
            $q,
            ['title', 'tagline', 'location', 'venue'],
        )->orderByDesc('event_date')->limit($limit)
            ->get(['id', 'slug', 'title', 'tagline', 'location', 'event_date', 'created_at'])->all();

        return array_map(function (object $r): array {
            $upcoming = ((string) ($r->event_date ?? '')) > Carbon::now()->toDateTimeString();
            return $this->item(
                kind:   'event',
                label:  $upcoming ? 'Upcoming event' : 'Event',
                title:  (string) $r->title,
                detail: trim(((string) ($r->location ?? '')) . (($r->tagline ?? '') !== '' ? ' · ' . $r->tagline : ''), ' ·'),
                url:    '/events/' . ($r->slug ?? ''),
                // Ordered by when it was ANNOUNCED, not when it happens — a timeline
                // sorted by event_date would put a conference next year above a
                // nominee approved this morning.
                at:     (string) ($r->created_at ?? $r->event_date ?? ''),
            );
        }, $rows);
    }

    private function threads(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_threads')->where('status', 'approved'),
            $q,
            ['title', 'author_name'],
        )->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'slug', 'title', 'author_name', 'reply_count', 'created_at'])->all();

        return array_map(fn (object $r): array => $this->item(
            kind:   'thread',
            label:  'Discussion',
            title:  (string) $r->title,
            // The author's display name is already on the thread page. No email hash,
            // no user id.
            detail: trim('by ' . ((string) ($r->author_name ?? 'a member'))
                . ((int) ($r->reply_count ?? 0) > 0 ? ' · ' . (int) $r->reply_count . ' replies' : '')),
            url:    '/community/' . ($r->slug ?? ''),
            at:     (string) ($r->created_at ?? ''),
        ), $rows);
    }

    private function profiles(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_profiles')->where('status', 'approved')->whereNull('merged_into'),
            $q,
            // Deliberately NOT email, phone, or any handle: those are contact details,
            // and a search box is not a directory lookup for them.
            ['display_name', 'category', 'location_city', 'country_code'],
        )->orderByDesc('registered_at')->limit($limit)
            ->get(['id', 'slug', 'display_name', 'category', 'location_city', 'country_code', 'registered_at'])->all();

        return array_map(fn (object $r): array => $this->item(
            kind:   'profile',
            label:  'Joined',
            title:  (string) $r->display_name,
            detail: trim(((string) ($r->category ?? '')) . ' · '
                . trim(((string) ($r->location_city ?? '')) . ' ' . ((string) ($r->country_code ?? ''))), ' ·'),
            url:    '/registry/' . ($r->slug ?? ''),
            at:     (string) ($r->registered_at ?? ''),
        ), $rows);
    }

    /**
     * Phase changes — the activity nothing else surfaces.
     *
     * "Has voting opened?" is the single most asked question about an awards cycle and
     * the answer lived only in a ledger table read by the scheduler. Sourced from
     * the transitions ledger rather than computed from the cycle's current phase, so
     * the timeline shows when it actually changed.
     */
    private function transitions(?string $q, int $limit): array
    {
        $rows = $this->filter(
            DB::table('gates_cycle_transitions as t')
                ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 't.cycle_id')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id'),
            $q,
            ['p.title', 't.to_status', 'cy.edition_label'],
        )
            ->orderByDesc('t.observed_at')->limit($limit)
            ->get(['t.id', 't.to_status', 't.observed_at', 'p.title as programme', 'cy.year', 'cy.edition_label'])->all();

        return array_map(fn (object $r): array => $this->item(
            kind:   'phase',
            label:  self::phaseLabel((string) $r->to_status),
            title:  trim(((string) ($r->programme ?? 'Award cycle')) . ' ' . ((string) ($r->year ?? ''))),
            detail: (string) ($r->edition_label ?? ''),
            url:    '/awards',
            at:     (string) ($r->observed_at ?? ''),
        ), $rows);
    }

    /** Phase name a visitor recognises, not the internal status token. */
    private static function phaseLabel(string $status): string
    {
        return [
            'upcoming'     => 'Cycle announced',
            'nominations'  => 'Nominations opened',
            'shortlisting' => 'Shortlisting began',
            'voting'        => 'Voting opened',
            'judging'      => 'Judging began',
            'results'      => 'Results published',
            'closed'       => 'Cycle closed',
        ][$status] ?? 'Cycle updated';
    }

    /**
     * One timeline entry.
     *
     * `at` is the sort key and stays machine-readable; `at_label` is what a reader
     * sees. Both are produced here so a template can never format a date one way on
     * one page and another way on the next.
     */
    private function item(string $kind, string $label, string $title, string $detail, string $url, string $at): array
    {
        return [
            'kind'     => $kind,
            'label'    => $label,
            'title'    => $title,
            'detail'   => mb_substr(trim($detail), 0, 160),
            'url'      => $url,
            'at'       => $at,
            'at_label' => self::relative($at),
        ];
    }

    /**
     * "4 minutes ago" / "3 days ago" / an absolute date beyond a month.
     *
     * Relative up to a month because that is the window where "how recent is this?"
     * is the question; beyond it the actual date is more useful than "47 days ago".
     */
    private static function relative(string $at): string
    {
        if (trim($at) === '') return '';
        try { $t = Carbon::parse($at); } catch (\Throwable) { return ''; }
        $secs = Carbon::now()->getTimestamp() - $t->getTimestamp();
        if ($secs < 0)     return $t->format('j M Y');   // scheduled in the future
        if ($secs < 60)    return 'just now';
        if ($secs < 3600)  return ($m = intdiv($secs, 60)) . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        if ($secs < 86400) return ($h = intdiv($secs, 3600)) . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        if ($secs < 2592000) return ($d = intdiv($secs, 86400)) . ' day' . ($d === 1 ? '' : 's') . ' ago';
        return $t->format('j M Y');
    }
}
