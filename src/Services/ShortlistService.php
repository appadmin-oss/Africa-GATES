<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Computing a shortlist from the vote tally, and freezing one when an organiser says so.
 *
 * ── PREVIEW AND PUBLISH ARE DIFFERENT OPERATIONS ─────────────────────────────
 *
 * {@see preview()} is live: it reads the tally as it stands and shows where the line falls
 * right now. It is safe to call on every page load and it announces nothing.
 *
 * {@see publish()} copies that answer into `gates_shortlist_entries` and stops. From then
 * on the shortlist is a document with a date and an author on it, and the arrival of ten
 * thousand more votes does not change a name on it.
 *
 * Keeping these apart is the whole design. A live shortlist cannot be published, because
 * anything you tell a nominee about it is false by the time they read it. A frozen one
 * cannot be tuned, because tuning it after publication is rewriting an announcement.
 *
 * ── THE CUT IS COMPUTED IN PHP, NOT IN SQL ───────────────────────────────────
 *
 * `LIMIT 10` cannot express "and everybody level with the tenth". Expressing it in SQL
 * means a window function (`DENSE_RANK`), which MySQL 8 has and the SQLite build on some
 * shared hosts does not — and this platform runs on both. So the ordered counts are read
 * and the boundary is found here: one pass, no engine-specific SQL, and the tie group is
 * identifiable, which is what {@see ShortlistRule::$tieMode} needs and what the published
 * entry's `tied_at_cut` flag records.
 *
 * Categories are tens of nominees, not millions. Reading the column and cutting it in PHP
 * is the cheap option as well as the portable one.
 */
final class ShortlistService
{
    // ───────────────────────────────── rules ─────────────────────────────────

    /**
     * The rule in force for a category: its own if it has one, otherwise the cycle default,
     * otherwise the built-in default.
     *
     * @return array{rule:ShortlistRule,scope:string}
     */
    public static function ruleFor(int $cycleId, ?int $categoryId): array
    {
        if ($categoryId !== null) {
            $own = DB::table('gates_shortlist_rules')
                ->where('cycle_id', $cycleId)->where('category_id', $categoryId)->first();
            if ($own) return ['rule' => ShortlistRule::from($own), 'scope' => 'category'];
        }

        $cycle = DB::table('gates_shortlist_rules')
            ->where('cycle_id', $cycleId)->whereNull('category_id')->first();
        if ($cycle) return ['rule' => ShortlistRule::from($cycle), 'scope' => 'cycle'];

        return ['rule' => new ShortlistRule(), 'scope' => 'default'];
    }

    /** Save (or replace) the rule for one scope. `$categoryId === null` sets the cycle default. */
    public static function saveRule(int $cycleId, ?int $categoryId, ShortlistRule $rule, int $adminId): void
    {
        $row = $rule->toArray() + [
            'cycle_id'   => $cycleId,
            'category_id' => $categoryId,
            'updated_at' => Carbon::now()->toDateTimeString(),
            'updated_by' => $adminId ?: null,
        ];

        // upsert() would be neater but its SQLite path needs the unique index to be
        // declared on the columns, and the cycle-level default's index is PARTIAL
        // (`WHERE category_id IS NOT NULL` cannot cover a NULL scope). So: look, then write.
        $q = DB::table('gates_shortlist_rules')->where('cycle_id', $cycleId);
        $categoryId === null ? $q->whereNull('category_id') : $q->where('category_id', $categoryId);

        $existing = $q->value('id');
        if ($existing) {
            DB::table('gates_shortlist_rules')->where('id', $existing)->update($row);
        } else {
            DB::table('gates_shortlist_rules')->insert($row);
        }
    }

    /** Drop a category's override so it falls back to the cycle default. */
    public static function clearRule(int $cycleId, int $categoryId): void
    {
        DB::table('gates_shortlist_rules')
            ->where('cycle_id', $cycleId)->where('category_id', $categoryId)->delete();
    }

    // ──────────────────────────────── preview ────────────────────────────────

    /**
     * Where the line falls in one category, right now.
     *
     * @return array{
     *   rule:ShortlistRule, scope:string, rows:list<array<string,mixed>>,
     *   considered:int, in:int, cut:?int, ties:int, warnings:list<string>
     * }
     */
    public static function preview(int $cycleId, int $categoryId): array
    {
        ['rule' => $rule, 'scope' => $scope] = self::ruleFor($cycleId, $categoryId);

        return ['rule' => $rule, 'scope' => $scope] + self::apply($rule, self::candidates($categoryId));
    }

    /**
     * Everyone eligible to be shortlisted in a category.
     *
     * Approved only — a pending nomination is not in the running — and merged duplicates
     * excluded, because a nominee who was merged into another would otherwise appear twice
     * on the list under two spellings of their name, which is precisely the thing the merge
     * existed to fix.
     *
     * @return list<array<string,mixed>>
     */
    public static function candidates(int $categoryId): array
    {
        $q = DB::table('gates_nominees')
            ->where('category_id', $categoryId)
            ->where('status', '!=', 'pending');

        return MergeService::notMerged($q)
            // Name is the SECOND key, and it is here to make the read deterministic:
            // without it two nominees on equal votes swap places between page loads and an
            // organiser cannot tell whether the list changed or the sort did.
            ->orderByDesc('vote_count')->orderBy('name')
            ->get(['id', 'name', 'country_code', 'organisation', 'tagline',
                   'photo_path', 'vote_count', 'organic_vote_count', 'profile_id'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * The cut itself: pure, so it can be tested without a database.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array{rows:list<array<string,mixed>>,considered:int,in:int,cut:?int,ties:int,warnings:list<string>}
     */
    public static function apply(ShortlistRule $rule, array $rows): array
    {
        $col = $rule->column();

        // Re-sort on the counting column. `candidates()` orders by `vote_count`, and a rule
        // set to organic-only is asking a different question — a nominee with 400 purchased
        // votes and 3 organic ones must not arrive at the top of an organic-only cut.
        usort($rows, fn ($a, $b) => ((int) $b[$col] <=> (int) $a[$col])
                                    ?: strcasecmp((string) $a['name'], (string) $b['name']));

        $total = count($rows);
        $take  = $rule->take($total);
        $floor = $rule->floor();

        // The count sitting exactly ON the line. NULL when the rule draws no line by
        // position, or when the field is smaller than the number to be taken — in which
        // case there is no boundary to be level with and everyone above the floor advances.
        $cut = ($take !== null && $total > 0 && $take < $total)
            ? (int) $rows[$take - 1][$col]
            : null;

        $ties = 0;
        $in   = 0;
        foreach ($rows as $i => $r) {
            $n = (int) $r[$col];

            $byPosition = $cut === null
                ? true                                    // no line, or the field fits inside it
                : ($rule->tieMode === 'include' ? $n >= $cut : $n > $cut);

            $tied = $cut !== null && $n === $cut;
            $ok   = $byPosition && $n >= $floor;

            $rows[$i]['count']  = $n;
            $rows[$i]['rank']   = $i + 1;
            $rows[$i]['in']     = $ok;
            $rows[$i]['tied']   = $tied;
            // Why this nominee is not on the list. Shown in the preview so an organiser
            // can see the difference between "ranked too low" and "the floor caught them",
            // which are two very different reasons to reconsider a threshold.
            $rows[$i]['reason'] = $ok ? '' : (!$byPosition ? 'below the cut' : 'under the minimum');

            if ($tied) $ties++;
            if ($ok)   $in++;
        }

        $warnings = $rule->warnings();
        if ($total > 0 && $in === 0) {
            $warnings[] = 'This rule shortlists nobody in this category.';
        }
        if ($ties > 1 && $rule->tieMode === 'exclude') {
            $warnings[] = "{$ties} nominees are level on {$cut} votes at the cut and all of them "
                        . 'are excluded by this rule.';
        }

        return ['rows' => $rows, 'considered' => $total, 'in' => $in,
                'cut' => $cut, 'ties' => $ties, 'warnings' => array_values(array_unique($warnings))];
    }

    // ──────────────────────────────── publish ────────────────────────────────

    /**
     * Freeze the current preview for one category as a published shortlist.
     *
     * Republishing supersedes: the previous row is marked `superseded` rather than deleted,
     * because a nominee who was told they were shortlisted and then was not is exactly the
     * situation somebody will need the history for.
     *
     * @return array{ok:bool,message:string,id:int,count:int}
     */
    public static function publish(int $cycleId, int $categoryId, int $adminId, string $note = ''): array
    {
        $p = self::preview($cycleId, $categoryId);
        $keep = array_values(array_filter($p['rows'], fn ($r) => $r['in']));

        // Refusing to publish an empty shortlist. Not a clamp for tidiness: publishing zero
        // names announces that no nominee in this category qualified, which is a statement
        // an organiser should have to make deliberately by other means, not something they
        // arrive at by leaving a threshold too high.
        if ($keep === []) {
            return ['ok' => false, 'id' => 0, 'count' => 0,
                    'message' => 'That rule selects nobody in this category, so there is nothing to publish. '
                               . 'Lower the threshold or the minimum first.'];
        }

        $now = Carbon::now()->toDateTimeString();

        return DB::transaction(function () use ($cycleId, $categoryId, $adminId, $note, $p, $keep, $now) {
            DB::table('gates_shortlists')
                ->where('category_id', $categoryId)->where('status', 'published')
                ->update(['status' => 'superseded', 'withdrawn_at' => $now, 'withdrawn_by' => $adminId ?: null]);

            $id = (int) DB::table('gates_shortlists')->insertGetId([
                'cycle_id'     => $cycleId,
                'category_id'  => $categoryId,
                'rule_json'    => json_encode($p['rule']->toArray(), JSON_UNESCAPED_SLASHES),
                'rule_text'    => $p['rule']->describe(),
                'entry_count'  => count($keep),
                'considered'   => $p['considered'],
                'status'       => 'published',
                'published_at' => $now,
                'published_by' => $adminId ?: null,
                'note'         => $note !== '' ? mb_substr($note, 0, 400) : null,
            ]);

            $rank = 0;
            foreach ($keep as $r) {
                DB::table('gates_shortlist_entries')->insert([
                    'shortlist_id'       => $id,
                    'nominee_id'         => (int) $r['id'],
                    'rank_no'            => ++$rank,
                    'vote_count'         => (int) $r['vote_count'],
                    'organic_vote_count' => (int) $r['organic_vote_count'],
                    'nominee_name'       => (string) $r['name'],
                    'country_code'       => $r['country_code'] ?: null,
                    'tied_at_cut'        => (int) (bool) $r['tied'],
                ]);
            }

            return ['ok' => true, 'id' => $id, 'count' => $rank,
                    'message' => $rank . ' nominee' . ($rank === 1 ? '' : 's') . ' shortlisted.'];
        });
    }

    /** Withdraw a published shortlist. The entries stay; only its status changes. */
    public static function withdraw(int $shortlistId, int $adminId): bool
    {
        return DB::table('gates_shortlists')->where('id', $shortlistId)->where('status', 'published')
            ->update(['status'       => 'withdrawn',
                      'withdrawn_at' => Carbon::now()->toDateTimeString(),
                      'withdrawn_by' => $adminId ?: null]) > 0;
    }

    /** The live published shortlist for a category, or NULL. */
    public static function published(int $categoryId): ?object
    {
        return DB::table('gates_shortlists')
            ->where('category_id', $categoryId)->where('status', 'published')
            ->orderByDesc('id')->first();
    }

    /** @return list<array<string,mixed>> the frozen entries, in published order */
    public static function entries(int $shortlistId): array
    {
        return DB::table('gates_shortlist_entries AS e')
            ->leftJoin('gates_nominees AS n', 'n.id', '=', 'e.nominee_id')
            ->where('e.shortlist_id', $shortlistId)
            ->orderBy('e.rank_no')
            ->get(['e.rank_no', 'e.nominee_id', 'e.vote_count', 'e.organic_vote_count',
                   'e.nominee_name', 'e.country_code', 'e.tied_at_cut',
                   'n.tagline', 'n.organisation', 'n.photo_path', 'n.profile_id'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Is this nominee on a published shortlist? One query, for the badge on a nominee card.
     */
    public static function isShortlisted(int $nomineeId): bool
    {
        return DB::table('gates_shortlist_entries AS e')
            ->join('gates_shortlists AS s', 's.id', '=', 'e.shortlist_id')
            ->where('e.nominee_id', $nomineeId)->where('s.status', 'published')
            ->exists();
    }

    /**
     * Every nominee id on a published shortlist within a cycle, for marking a whole list of
     * cards without a query per row.
     *
     * @return array<int,true>
     */
    public static function shortlistedIn(int $cycleId): array
    {
        $ids = DB::table('gates_shortlist_entries AS e')
            ->join('gates_shortlists AS s', 's.id', '=', 'e.shortlist_id')
            ->where('s.cycle_id', $cycleId)->where('s.status', 'published')
            ->pluck('e.nominee_id')->all();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Every category in a cycle with its shortlist state, for the admin index.
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(int $cycleId): array
    {
        $cats = DB::table('gates_award_categories')
            ->where('cycle_id', $cycleId)->orderBy('sort_order')->orderBy('title')
            ->get(['id', 'title', 'slug'])->map(fn ($r) => (array) $r)->all();

        $out = [];
        foreach ($cats as $c) {
            $p    = self::preview($cycleId, (int) $c['id']);
            $live = self::published((int) $c['id']);

            $out[] = $c + [
                'rule'       => $p['rule'],
                'scope'      => $p['scope'],
                'considered' => $p['considered'],
                'would_take' => $p['in'],
                'ties'       => $p['ties'],
                'warnings'   => $p['warnings'],
                'published'  => $live,
                // The number that makes an organiser look: the published list and the rule
                // no longer agree, because votes kept arriving after publication. Not an
                // error — the snapshot is meant to hold — but it is worth surfacing.
                'drifted'    => $live !== null && (int) $live->entry_count !== $p['in'],
            ];
        }

        return $out;
    }
}
